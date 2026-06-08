<?php
/**
 * BrikPanel - AJAX Coupons Management
 *
 * Replaces the default WooCommerce coupons list with a modern,
 * fully AJAX-powered interface with drawer editing.
 *
 * @package BrikPanel
 * @since 1.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brikpanel_Coupons {

    public function __construct() {
        if (get_option('brikpanel_modern_coupons', 'yes') !== 'yes') {
            return;
        }

        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'redirect_default_list']);

        // AJAX endpoints
        add_action('wp_ajax_brikpanel_fetch_coupons', [$this, 'ajax_fetch_coupons']);
        add_action('wp_ajax_brikpanel_save_coupon', [$this, 'ajax_save_coupon']);
        add_action('wp_ajax_brikpanel_delete_coupon', [$this, 'ajax_delete_coupon']);
        add_action('wp_ajax_brikpanel_toggle_coupon_status', [$this, 'ajax_toggle_coupon_status']);
        add_action('wp_ajax_brikpanel_duplicate_coupon', [$this, 'ajax_duplicate_coupon']);
        add_action('wp_ajax_brikpanel_coupons_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_brikpanel_coupons_search_categories', [$this, 'ajax_search_categories']);
    }

    /**
     * Returns which optional usage-restriction fields the store owner has
     * enabled from BrikPanel → Settings → Coupons. Used to render the drawer
     * conditionally and to gate the corresponding save-paths so a disabled
     * field cannot be tampered with via the AJAX endpoint.
     *
     * @return array<string,bool>
     */
    public static function enabled_fields() {
        return [
            'products'           => get_option('brikpanel_coupons_show_products', 'no') === 'yes',
            'exclude_products'   => get_option('brikpanel_coupons_show_exclude_products', 'no') === 'yes',
            'categories'         => get_option('brikpanel_coupons_show_categories', 'no') === 'yes',
            'exclude_categories' => get_option('brikpanel_coupons_show_exclude_categories', 'no') === 'yes',
            'emails'             => get_option('brikpanel_coupons_show_emails', 'no') === 'yes',
            'limit_items'        => get_option('brikpanel_coupons_show_limit_items', 'no') === 'yes',
        ];
    }

    // =========================================================================
    // PAGE REGISTRATION & REDIRECT
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __('Coupons', 'brikpanel'),
            '',
            'manage_woocommerce',
            'brikpanel-coupons',
            [$this, 'render_page']
        );

        if ($hook) {
            add_action('load-' . $hook, function () {
                global $title;
                $title = __('Coupons', 'brikpanel');
            });
        }
    }

    public function redirect_default_list() {
        global $pagenow;

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Redirect from edit.php?post_type=shop_coupon
        if ($pagenow === 'edit.php' && isset($_GET['post_type']) && sanitize_key($_GET['post_type']) === 'shop_coupon') {
            if (!empty($_GET['action']) || !empty($_GET['action2']) || !empty($_GET['page']) || !empty($_GET['taxonomy'])) {
                return;
            }
            wp_safe_redirect(admin_url('admin.php?page=brikpanel-coupons'));
            exit;
        }

        // Redirect from post-new.php?post_type=shop_coupon
        if ($pagenow === 'post-new.php' && isset($_GET['post_type']) && sanitize_key($_GET['post_type']) === 'shop_coupon') {
            wp_safe_redirect(admin_url('admin.php?page=brikpanel-coupons'));
            exit;
        }
    }

    // =========================================================================
    // RENDER PAGE
    // =========================================================================

    public function render_page() {
        $currency = get_woocommerce_currency_symbol();
        $fields   = self::enabled_fields();

        // Count coupons by status
        $counts  = wp_count_posts('shop_coupon');
        $publish = isset($counts->publish) ? (int) $counts->publish : 0;
        $draft   = isset($counts->draft) ? (int) $counts->draft : 0;
        $trash   = isset($counts->trash) ? (int) $counts->trash : 0;
        $all     = $publish + $draft;
        ?>
        <div class="wrap">
        <div class="brikpanel-cp" id="brikpanel-coupons-list">

            <!-- Header -->
            <div class="brikpanel-cp-header">
                <div class="brikpanel-cp-header-left">
                    <h1><?php esc_html_e('Coupons', 'brikpanel'); ?></h1>
                    <span class="brikpanel-cp-count" id="bpc-total-count"><?php echo esc_html($all); ?></span>
                </div>
                <div class="brikpanel-cp-header-right">
                    <div class="brikpanel-cp-search-wrap">
                        <svg class="brikpanel-cp-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" id="bpc-search" class="brikpanel-cp-search" placeholder="<?php esc_attr_e('Search coupons...', 'brikpanel'); ?>">
                    </div>
                    <button type="button" class="brikpanel-cp-btn primary" id="bpc-add-new">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php esc_html_e('Add coupon', 'brikpanel'); ?>
                    </button>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="brikpanel-cp-filters">
                <div class="brikpanel-cp-tabs">
                    <button class="brikpanel-cp-tab active" data-status="any">
                        <?php esc_html_e('All', 'brikpanel'); ?>
                        <span class="brikpanel-cp-tab-count" data-count="all"><?php echo esc_html($all); ?></span>
                    </button>
                    <button class="brikpanel-cp-tab" data-status="publish">
                        <?php esc_html_e('Published', 'brikpanel'); ?>
                        <span class="brikpanel-cp-tab-count" data-count="publish"><?php echo esc_html($publish); ?></span>
                    </button>
                    <button class="brikpanel-cp-tab" data-status="draft">
                        <?php esc_html_e('Draft', 'brikpanel'); ?>
                        <span class="brikpanel-cp-tab-count" data-count="draft"><?php echo esc_html($draft); ?></span>
                    </button>
                    <?php if ($trash > 0) : ?>
                    <button class="brikpanel-cp-tab" data-status="trash">
                        <?php esc_html_e('Trash', 'brikpanel'); ?>
                        <span class="brikpanel-cp-tab-count" data-count="trash"><?php echo esc_html($trash); ?></span>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="brikpanel-cp-filter-group">
                    <select id="bpc-sort" class="brikpanel-cp-select">
                        <option value="date-desc"><?php esc_html_e('Newest first', 'brikpanel'); ?></option>
                        <option value="date-asc"><?php esc_html_e('Oldest first', 'brikpanel'); ?></option>
                        <option value="title-asc"><?php esc_html_e('Code A-Z', 'brikpanel'); ?></option>
                        <option value="title-desc"><?php esc_html_e('Code Z-A', 'brikpanel'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Bulk Actions Bar (hidden by default) -->
            <div class="brikpanel-cp-bulk-bar" id="bpc-bulk-bar" style="display:none;">
                <div class="brikpanel-cp-bulk-left">
                    <span id="bpc-selected-count">0</span> <?php esc_html_e('selected', 'brikpanel'); ?>
                    <button type="button" class="brikpanel-cp-bulk-link" id="bpc-select-all-btn"><?php esc_html_e('Select all', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-cp-bulk-link" id="bpc-deselect-all-btn"><?php esc_html_e('Deselect all', 'brikpanel'); ?></button>
                </div>
                <div class="brikpanel-cp-bulk-right">
                    <button type="button" class="brikpanel-cp-btn secondary small" id="bpc-bulk-publish"><?php esc_html_e('Publish', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-cp-btn secondary small" id="bpc-bulk-draft"><?php esc_html_e('Set as draft', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-cp-btn danger small" id="bpc-bulk-trash"><?php esc_html_e('Move to trash', 'brikpanel'); ?></button>
                </div>
            </div>

            <!-- Coupons Table -->
            <div class="brikpanel-cp-card">
                <div class="brikpanel-cp-table-wrap">
                    <table class="brikpanel-cp-table" id="bpc-table">
                        <thead>
                            <tr>
                                <th class="brikpanel-cp-th-check">
                                    <input type="checkbox" id="bpc-check-all" class="brikpanel-cp-checkbox">
                                </th>
                                <th class="brikpanel-cp-th-code"><?php esc_html_e('Code', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-type"><?php esc_html_e('Type', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-amount"><?php esc_html_e('Amount', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-desc"><?php esc_html_e('Description', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-usage"><?php esc_html_e('Usage / Limit', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-revenue"><?php esc_html_e('Revenue', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-expiry"><?php esc_html_e('Expiry date', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-status"><?php esc_html_e('Status', 'brikpanel'); ?></th>
                                <th class="brikpanel-cp-th-actions"></th>
                            </tr>
                        </thead>
                        <tbody id="bpc-table-body">
                            <tr class="brikpanel-cp-loading-row">
                                <td colspan="9">
                                    <div class="brikpanel-cp-spinner"></div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="brikpanel-cp-pagination" id="bpc-pagination"></div>
            </div>

            <!-- Drawer Overlay -->
            <div class="brikpanel-cp-drawer-overlay" id="bpc-drawer-overlay"></div>

            <!-- Coupon Drawer (Edit / Create) -->
            <div class="brikpanel-cp-drawer" id="bpc-drawer">
                <div class="brikpanel-cp-drawer-header">
                    <h3 id="bpc-drawer-title"><?php esc_html_e('Edit coupon', 'brikpanel'); ?></h3>
                    <button type="button" class="brikpanel-cp-drawer-close" id="bpc-drawer-close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="brikpanel-cp-drawer-body">
                    <input type="hidden" id="bpc-coupon-id" value="">

                    <!-- General Section -->
                    <div class="brikpanel-cp-drawer-section">
                        <h4 class="brikpanel-cp-drawer-section-title"><?php esc_html_e('General', 'brikpanel'); ?></h4>

                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-code"><?php esc_html_e('Coupon code', 'brikpanel'); ?></label>
                            <div class="brikpanel-cp-code-row">
                                <input type="text" id="bpc-code" placeholder="<?php esc_attr_e('e.g. SUMMER2024', 'brikpanel'); ?>">
                                <button type="button" class="brikpanel-cp-btn secondary small" id="bpc-generate-code">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                                    <?php esc_html_e('Generate', 'brikpanel'); ?>
                                </button>
                            </div>
                        </div>

                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-description"><?php esc_html_e('Description', 'brikpanel'); ?></label>
                            <textarea id="bpc-description" rows="2" placeholder="<?php esc_attr_e('Optional description for internal use', 'brikpanel'); ?>"></textarea>
                        </div>

                        <div class="brikpanel-cp-qe-row">
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-discount-type"><?php esc_html_e('Discount type', 'brikpanel'); ?></label>
                                <select id="bpc-discount-type" class="brikpanel-cp-select full">
                                    <option value="percent"><?php esc_html_e('Percentage discount', 'brikpanel'); ?></option>
                                    <option value="fixed_cart"><?php esc_html_e('Fixed cart discount', 'brikpanel'); ?></option>
                                    <option value="fixed_product"><?php esc_html_e('Fixed product discount', 'brikpanel'); ?></option>
                                </select>
                            </div>
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-amount"><?php esc_html_e('Amount', 'brikpanel'); ?></label>
                                <div class="brikpanel-cp-input-group" id="bpc-amount-group">
                                    <span class="brikpanel-cp-input-prefix" id="bpc-amount-prefix">%</span>
                                    <input type="text" id="bpc-amount" placeholder="0">
                                </div>
                            </div>
                        </div>

                        <div class="brikpanel-cp-qe-field">
                            <label class="brikpanel-cp-toggle-label">
                                <span><?php esc_html_e('Free shipping', 'brikpanel'); ?></span>
                                <span class="brikpanel-cp-toggle" id="bpc-free-shipping-toggle">
                                    <input type="checkbox" id="bpc-free-shipping">
                                    <span class="brikpanel-cp-toggle-slider"></span>
                                </span>
                            </label>
                        </div>

                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-expiry"><?php esc_html_e('Expiry date', 'brikpanel'); ?></label>
                            <input type="date" id="bpc-expiry">
                        </div>
                    </div>

                    <!-- Usage Restrictions Section -->
                    <div class="brikpanel-cp-drawer-section">
                        <h4 class="brikpanel-cp-drawer-section-title"><?php esc_html_e('Usage restrictions', 'brikpanel'); ?></h4>

                        <div class="brikpanel-cp-qe-row">
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-min-spend"><?php esc_html_e('Minimum spend', 'brikpanel'); ?></label>
                                <div class="brikpanel-cp-input-group">
                                    <span class="brikpanel-cp-input-prefix"><?php echo esc_html($currency); ?></span>
                                    <input type="text" id="bpc-min-spend" placeholder="<?php esc_attr_e('No minimum', 'brikpanel'); ?>">
                                </div>
                            </div>
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-max-spend"><?php esc_html_e('Maximum spend', 'brikpanel'); ?></label>
                                <div class="brikpanel-cp-input-group">
                                    <span class="brikpanel-cp-input-prefix"><?php echo esc_html($currency); ?></span>
                                    <input type="text" id="bpc-max-spend" placeholder="<?php esc_attr_e('No maximum', 'brikpanel'); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="brikpanel-cp-qe-field">
                            <label class="brikpanel-cp-toggle-label">
                                <span><?php esc_html_e('Individual use only', 'brikpanel'); ?></span>
                                <span class="brikpanel-cp-toggle">
                                    <input type="checkbox" id="bpc-individual-use">
                                    <span class="brikpanel-cp-toggle-slider"></span>
                                </span>
                            </label>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Cannot be used with other coupons', 'brikpanel'); ?></span>
                        </div>

                        <div class="brikpanel-cp-qe-field">
                            <label class="brikpanel-cp-toggle-label">
                                <span><?php esc_html_e('Exclude sale items', 'brikpanel'); ?></span>
                                <span class="brikpanel-cp-toggle">
                                    <input type="checkbox" id="bpc-exclude-sale">
                                    <span class="brikpanel-cp-toggle-slider"></span>
                                </span>
                            </label>
                        </div>

                        <?php if ($fields['products']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-products-search"><?php esc_html_e('Products', 'brikpanel'); ?></label>
                            <div class="brikpanel-cp-token-field" data-token-target="products">
                                <div class="brikpanel-cp-tokens" id="bpc-products-tokens"></div>
                                <div class="brikpanel-cp-token-input-wrap">
                                    <input type="text" id="bpc-products-search" class="brikpanel-cp-token-input" autocomplete="off" placeholder="<?php esc_attr_e('Search products by name or SKU…', 'brikpanel'); ?>">
                                    <div class="brikpanel-cp-token-suggestions" id="bpc-products-suggestions" hidden></div>
                                </div>
                            </div>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Limit the coupon to these products. Leave empty to allow all products.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($fields['exclude_products']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-exclude-products-search"><?php esc_html_e('Exclude products', 'brikpanel'); ?></label>
                            <div class="brikpanel-cp-token-field" data-token-target="exclude_products">
                                <div class="brikpanel-cp-tokens" id="bpc-exclude-products-tokens"></div>
                                <div class="brikpanel-cp-token-input-wrap">
                                    <input type="text" id="bpc-exclude-products-search" class="brikpanel-cp-token-input" autocomplete="off" placeholder="<?php esc_attr_e('Search products to exclude…', 'brikpanel'); ?>">
                                    <div class="brikpanel-cp-token-suggestions" id="bpc-exclude-products-suggestions" hidden></div>
                                </div>
                            </div>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Coupon will not apply when the cart contains these products.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($fields['categories']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-categories-search"><?php esc_html_e('Product categories', 'brikpanel'); ?></label>
                            <div class="brikpanel-cp-token-field" data-token-target="categories">
                                <div class="brikpanel-cp-tokens" id="bpc-categories-tokens"></div>
                                <div class="brikpanel-cp-token-input-wrap">
                                    <input type="text" id="bpc-categories-search" class="brikpanel-cp-token-input" autocomplete="off" placeholder="<?php esc_attr_e('Search categories…', 'brikpanel'); ?>">
                                    <div class="brikpanel-cp-token-suggestions" id="bpc-categories-suggestions" hidden></div>
                                </div>
                            </div>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Coupon applies only to products in these categories. Leave empty to allow all categories.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($fields['exclude_categories']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-exclude-categories-search"><?php esc_html_e('Exclude categories', 'brikpanel'); ?></label>
                            <div class="brikpanel-cp-token-field" data-token-target="exclude_categories">
                                <div class="brikpanel-cp-tokens" id="bpc-exclude-categories-tokens"></div>
                                <div class="brikpanel-cp-token-input-wrap">
                                    <input type="text" id="bpc-exclude-categories-search" class="brikpanel-cp-token-input" autocomplete="off" placeholder="<?php esc_attr_e('Search categories to exclude…', 'brikpanel'); ?>">
                                    <div class="brikpanel-cp-token-suggestions" id="bpc-exclude-categories-suggestions" hidden></div>
                                </div>
                            </div>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Coupon will not apply to products in these categories.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($fields['emails']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-allowed-emails"><?php esc_html_e('Allowed emails', 'brikpanel'); ?></label>
                            <textarea id="bpc-allowed-emails" rows="2" placeholder="<?php esc_attr_e('e.g. jane@example.com, *@team.com', 'brikpanel'); ?>"></textarea>
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Comma- or newline-separated. Wildcards like *@example.com are supported.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Usage Limits Section -->
                    <div class="brikpanel-cp-drawer-section">
                        <h4 class="brikpanel-cp-drawer-section-title"><?php esc_html_e('Usage limits', 'brikpanel'); ?></h4>

                        <div class="brikpanel-cp-qe-row">
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-usage-limit"><?php esc_html_e('Usage limit per coupon', 'brikpanel'); ?></label>
                                <input type="number" id="bpc-usage-limit" min="0" placeholder="<?php esc_attr_e('Unlimited', 'brikpanel'); ?>">
                            </div>
                            <div class="brikpanel-cp-qe-field">
                                <label for="bpc-usage-limit-user"><?php esc_html_e('Usage limit per user', 'brikpanel'); ?></label>
                                <input type="number" id="bpc-usage-limit-user" min="0" placeholder="<?php esc_attr_e('Unlimited', 'brikpanel'); ?>">
                            </div>
                        </div>

                        <?php if ($fields['limit_items']) : ?>
                        <div class="brikpanel-cp-qe-field">
                            <label for="bpc-limit-items"><?php esc_html_e('Limit usage to X items', 'brikpanel'); ?></label>
                            <input type="number" id="bpc-limit-items" min="0" placeholder="<?php esc_attr_e('All matching items', 'brikpanel'); ?>">
                            <span class="brikpanel-cp-field-hint"><?php esc_html_e('Caps the number of matching items the discount applies to per cart. Only used with product or category restrictions.', 'brikpanel'); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="brikpanel-cp-qe-field" id="bpc-usage-count-field" style="display:none;">
                            <label><?php esc_html_e('Usage count', 'brikpanel'); ?></label>
                            <span class="brikpanel-cp-usage-count-display" id="bpc-usage-count-display">0</span>
                        </div>
                    </div>
                </div>

                <div class="brikpanel-cp-drawer-footer">
                    <button type="button" class="brikpanel-cp-btn secondary" id="bpc-drawer-cancel"><?php esc_html_e('Cancel', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-cp-btn primary" id="bpc-drawer-save"><?php esc_html_e('Save changes', 'brikpanel'); ?></button>
                </div>
            </div>

            <!-- Toast Container -->
            <div class="brikpanel-cp-toast-container" id="bpc-toast-container"></div>

        </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: FETCH COUPONS
    // =========================================================================

    public function ajax_fetch_coupons() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = max(1, min(100, intval($_POST['per_page'] ?? 20)));
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $status   = sanitize_key($_POST['status'] ?? 'any');
        $sort     = sanitize_text_field($_POST['sort'] ?? 'date-desc');

        // Parse sort
        $sort_parts = explode('-', $sort);
        $orderby = $sort_parts[0] ?? 'date';
        $order   = strtoupper($sort_parts[1] ?? 'DESC');

        if (!in_array($orderby, ['date', 'title'], true)) {
            $orderby = 'date';
        }
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $statuses = ['publish', 'draft'];
        if ($status === 'publish') {
            $statuses = ['publish'];
        } elseif ($status === 'draft') {
            $statuses = ['draft'];
        } elseif ($status === 'trash') {
            $statuses = ['trash'];
        }

        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => $statuses,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $query   = new WP_Query($args);
        $coupons = [];

        // Resolve once per request: third-party (ASE etc.) extra columns
        // contributed via manage_{post_type}_posts_columns filter.
        $extra_columns = class_exists('Brikpanel_ASE_Bridge')
            ? Brikpanel_ASE_Bridge::get_extra_columns('shop_coupon')
            : [];

        // First pass: build coupon objects and collect codes for batch revenue query.
        $coupon_objects = [];
        $coupon_codes   = [];
        foreach ($query->posts as $post) {
            $coupon                      = new WC_Coupon($post->ID);
            $coupon_objects[$post->ID]   = $coupon;
            $coupon_codes[]              = $coupon->get_code();
        }

        // Single DB query for all coupon revenues on this page.
        $revenue_map = $this->get_coupons_revenue_batch($coupon_codes);

        foreach ($query->posts as $post) {
            $coupon = $coupon_objects[$post->ID];

            $expiry_ts   = $coupon->get_date_expires();
            $expiry_date = $expiry_ts ? $expiry_ts->date('Y-m-d') : '';

            $usage_limit     = $coupon->get_usage_limit();
            $usage_count     = $coupon->get_usage_count();
            $coupon_revenue  = $revenue_map[ strtolower( $coupon->get_code() ) ] ?? 0.0;

            $extra_cells = [];
            if ($extra_columns) {
                foreach ($extra_columns as $col_id => $col_label) {
                    $extra_cells[$col_id] = Brikpanel_ASE_Bridge::render_cell('shop_coupon', $col_id, $post->ID);
                }
            }

            $extra_actions = class_exists('Brikpanel_ASE_Bridge')
                ? Brikpanel_ASE_Bridge::get_row_actions($post)
                : [];

            $product_ids          = array_map('intval', (array) $coupon->get_product_ids());
            $excluded_product_ids = array_map('intval', (array) $coupon->get_excluded_product_ids());
            $category_ids         = array_map('intval', (array) $coupon->get_product_categories());
            $excluded_category_ids = array_map('intval', (array) $coupon->get_excluded_product_categories());
            $email_restrictions   = (array) $coupon->get_email_restrictions();
            $limit_items          = $coupon->get_limit_usage_to_x_items();

            $coupons[] = [
                'id'                       => $post->ID,
                'code'                     => $coupon->get_code() ?? '',
                'description'              => $coupon->get_description() ?? '',
                'discount_type'            => $coupon->get_discount_type(),
                'amount'                   => $coupon->get_amount(),
                'free_shipping'            => $coupon->get_free_shipping() ? 'yes' : 'no',
                'expiry_date'              => $expiry_date,
                'minimum_amount'           => $coupon->get_minimum_amount(),
                'maximum_amount'           => $coupon->get_maximum_amount(),
                'individual_use'           => $coupon->get_individual_use() ? 'yes' : 'no',
                'exclude_sale_items'       => $coupon->get_exclude_sale_items() ? 'yes' : 'no',
                'usage_limit'              => $usage_limit ? $usage_limit : '',
                'usage_limit_per_user'     => $coupon->get_usage_limit_per_user() ? $coupon->get_usage_limit_per_user() : '',
                'usage_count'              => $usage_count,
                'revenue'                  => $coupon_revenue,
                'revenue_formatted'        => $coupon_revenue > 0 ? wc_price($coupon_revenue) : '',
                'status'                   => $post->post_status,
                'product_ids'              => $product_ids,
                'product_labels'           => $this->build_product_labels($product_ids),
                'excluded_product_ids'     => $excluded_product_ids,
                'excluded_product_labels'  => $this->build_product_labels($excluded_product_ids),
                'category_ids'             => $category_ids,
                'category_labels'          => $this->build_category_labels($category_ids),
                'excluded_category_ids'    => $excluded_category_ids,
                'excluded_category_labels' => $this->build_category_labels($excluded_category_ids),
                'email_restrictions'       => implode(', ', $email_restrictions),
                'limit_usage_to_x_items'   => $limit_items ? (int) $limit_items : '',
                'extra_cells'              => (object) $extra_cells,
                'extra_actions'            => $extra_actions,
            ];
        }

        // Counts
        $all_counts = wp_count_posts('shop_coupon');

        wp_send_json_success([
            'coupons'       => $coupons,
            'total'         => (int) $query->found_posts,
            'pages'         => (int) $query->max_num_pages,
            'extra_columns' => (object) $extra_columns,
            'counts'        => [
                'all'     => (int) (($all_counts->publish ?? 0) + ($all_counts->draft ?? 0)),
                'publish' => (int) ($all_counts->publish ?? 0),
                'draft'   => (int) ($all_counts->draft ?? 0),
                'trash'   => (int) ($all_counts->trash ?? 0),
            ],
        ]);
    }

    // =========================================================================
    // REVENUE BATCH QUERY
    // =========================================================================

    /**
     * Returns total order revenue (wc-processing + wc-completed) per coupon code.
     * Uses a single JOIN query so N coupons on a page = 1 DB hit, not N.
     *
     * @param string[] $coupon_codes  Raw coupon codes (case-insensitive match).
     * @return array<string,float>    Keys are lowercase coupon codes.
     */
    private function get_coupons_revenue_batch( array $coupon_codes ): array {
        if ( empty( $coupon_codes ) ) {
            return [];
        }

        global $wpdb;

        $lower_codes  = array_map( 'strtolower', $coupon_codes );
        $placeholders = implode( ',', array_fill( 0, count( $lower_codes ), '%s' ) );
        $hpos         = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

        if ( $hpos ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT LOWER(oi.order_item_name) AS code, SUM(o.total_amount) AS revenue
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
                 WHERE oi.order_item_type = 'coupon'
                 AND LOWER(oi.order_item_name) IN ($placeholders)
                 AND o.status IN ('wc-processing', 'wc-completed')
                 GROUP BY LOWER(oi.order_item_name)",
                ...$lower_codes
            ) );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT LOWER(oi.order_item_name) AS code, SUM(pm.meta_value) AS revenue
                 FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}posts p ON p.ID = oi.order_id
                 INNER JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = oi.order_id AND pm.meta_key = '_order_total'
                 WHERE oi.order_item_type = 'coupon'
                 AND LOWER(oi.order_item_name) IN ($placeholders)
                 AND p.post_status IN ('wc-processing', 'wc-completed')
                 GROUP BY LOWER(oi.order_item_name)",
                ...$lower_codes
            ) );
        }

        $map = [];
        foreach ( $rows as $row ) {
            $map[ $row->code ] = (float) $row->revenue;
        }

        return $map;
    }

    // =========================================================================
    // AJAX: SAVE COUPON (Create or Update)
    // =========================================================================

    public function ajax_save_coupon() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        $code      = sanitize_text_field($_POST['code'] ?? '');

        if (empty($code)) {
            wp_send_json_error(['message' => __('Coupon code is required.', 'brikpanel')]);
        }

        // Check for duplicate coupon code (only for new coupons or code changes)
        if ($coupon_id > 0) {
            $coupon = new WC_Coupon($coupon_id);
        } else {
            $coupon = new WC_Coupon();
        }

        // Check duplicate code
        $existing = wc_get_coupon_id_by_code($code);
        if ($existing && $existing !== $coupon_id) {
            wp_send_json_error(['message' => __('A coupon with this code already exists.', 'brikpanel')]);
        }

        $coupon->set_code($code);
        $coupon->set_description(sanitize_textarea_field($_POST['description'] ?? ''));

        $discount_type = sanitize_key($_POST['discount_type'] ?? 'percent');
        if (!in_array($discount_type, ['percent', 'fixed_cart', 'fixed_product'], true)) {
            $discount_type = 'percent';
        }
        $coupon->set_discount_type($discount_type);

        $amount = floatval($_POST['amount'] ?? 0);
        $coupon->set_amount($amount);

        $free_shipping = sanitize_key($_POST['free_shipping'] ?? 'no');
        $coupon->set_free_shipping($free_shipping === 'yes');

        // Expiry date
        $expiry = sanitize_text_field($_POST['expiry_date'] ?? '');
        if ($expiry) {
            $coupon->set_date_expires(strtotime($expiry . ' 23:59:59'));
        } else {
            $coupon->set_date_expires(null);
        }

        // Usage restrictions
        $min_amount = sanitize_text_field($_POST['minimum_amount'] ?? '');
        $coupon->set_minimum_amount($min_amount !== '' ? floatval($min_amount) : '');

        $max_amount = sanitize_text_field($_POST['maximum_amount'] ?? '');
        $coupon->set_maximum_amount($max_amount !== '' ? floatval($max_amount) : '');

        $individual_use = sanitize_key($_POST['individual_use'] ?? 'no');
        $coupon->set_individual_use($individual_use === 'yes');

        $exclude_sale = sanitize_key($_POST['exclude_sale_items'] ?? 'no');
        $coupon->set_exclude_sale_items($exclude_sale === 'yes');

        // Usage limits
        $usage_limit = sanitize_text_field($_POST['usage_limit'] ?? '');
        $coupon->set_usage_limit($usage_limit !== '' ? intval($usage_limit) : 0);

        $usage_limit_user = sanitize_text_field($_POST['usage_limit_per_user'] ?? '');
        $coupon->set_usage_limit_per_user($usage_limit_user !== '' ? intval($usage_limit_user) : 0);

        // Optional advanced restriction fields. We only touch each field when:
        //   1) the matching setting is enabled (a disabled field cannot be
        //      tampered with via a forged POST), AND
        //   2) the key is explicitly present in the payload (so inline edits
        //      from the table — which only send "amount" — do not wipe out
        //      the rest of the restriction config).
        $enabled = self::enabled_fields();

        if ($enabled['products'] && array_key_exists('product_ids', $_POST)) {
            $coupon->set_product_ids($this->sanitize_id_list($_POST['product_ids']));
        }
        if ($enabled['exclude_products'] && array_key_exists('excluded_product_ids', $_POST)) {
            $coupon->set_excluded_product_ids($this->sanitize_id_list($_POST['excluded_product_ids']));
        }
        if ($enabled['categories'] && array_key_exists('category_ids', $_POST)) {
            $coupon->set_product_categories($this->sanitize_id_list($_POST['category_ids']));
        }
        if ($enabled['exclude_categories'] && array_key_exists('excluded_category_ids', $_POST)) {
            $coupon->set_excluded_product_categories($this->sanitize_id_list($_POST['excluded_category_ids']));
        }
        if ($enabled['emails'] && array_key_exists('email_restrictions', $_POST)) {
            $raw_emails = wp_unslash($_POST['email_restrictions']);
            $coupon->set_email_restrictions($this->sanitize_email_list($raw_emails));
        }
        if ($enabled['limit_items'] && array_key_exists('limit_usage_to_x_items', $_POST)) {
            $limit_items = sanitize_text_field($_POST['limit_usage_to_x_items']);
            $coupon->set_limit_usage_to_x_items($limit_items !== '' ? intval($limit_items) : '');
        }

        // Set status - default publish for new coupons
        if ($coupon_id === 0) {
            $coupon->set_status('publish');
        }

        $coupon->save();

        wp_send_json_success([
            'message'   => $coupon_id > 0 ? __('Coupon updated!', 'brikpanel') : __('Coupon created!', 'brikpanel'),
            'coupon_id' => $coupon->get_id(),
        ]);
    }

    // =========================================================================
    // AJAX: DELETE COUPON
    // =========================================================================

    public function ajax_delete_coupon() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        $permanent = sanitize_key($_POST['permanent'] ?? 'no') === 'yes';

        if (!$coupon_id) {
            wp_send_json_error(['message' => __('Invalid coupon ID.', 'brikpanel')]);
        }

        $post = get_post($coupon_id);
        if (!$post || $post->post_type !== 'shop_coupon') {
            wp_send_json_error(['message' => __('Coupon not found.', 'brikpanel')]);
        }

        if ($permanent) {
            wp_delete_post($coupon_id, true);
            wp_send_json_success(['message' => __('Coupon permanently deleted.', 'brikpanel')]);
        } else {
            wp_trash_post($coupon_id);
            wp_send_json_success(['message' => __('Coupon moved to trash.', 'brikpanel')]);
        }
    }

    // =========================================================================
    // AJAX: TOGGLE COUPON STATUS
    // =========================================================================

    public function ajax_toggle_coupon_status() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        if (!$coupon_id) {
            wp_send_json_error(['message' => __('Invalid coupon ID.', 'brikpanel')]);
        }

        $post = get_post($coupon_id);
        if (!$post || $post->post_type !== 'shop_coupon') {
            wp_send_json_error(['message' => __('Coupon not found.', 'brikpanel')]);
        }

        $new_status = ($post->post_status === 'publish') ? 'draft' : 'publish';

        wp_update_post([
            'ID'          => $coupon_id,
            'post_status' => $new_status,
        ]);

        $label = $new_status === 'publish' ? __('Published', 'brikpanel') : __('Draft', 'brikpanel');

        wp_send_json_success([
            'status'  => $new_status,
            'message' => sprintf(
                /* translators: %s: status label */
                __('Coupon status changed to %s.', 'brikpanel'),
                $label
            ),
        ]);
    }

    // =========================================================================
    // AJAX: DUPLICATE COUPON
    // =========================================================================

    public function ajax_duplicate_coupon() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $coupon_id = intval($_POST['coupon_id'] ?? 0);
        if (!$coupon_id) {
            wp_send_json_error(['message' => __('Invalid coupon ID.', 'brikpanel')]);
        }

        $original = new WC_Coupon($coupon_id);
        if (!$original->get_id()) {
            wp_send_json_error(['message' => __('Coupon not found.', 'brikpanel')]);
        }

        $new_coupon = new WC_Coupon();

        // Generate unique code
        $base_code = $original->get_code();
        $new_code  = $base_code . '-copy';
        $suffix    = 1;
        while (wc_get_coupon_id_by_code($new_code)) {
            $suffix++;
            $new_code = $base_code . '-copy-' . $suffix;
        }

        $new_coupon->set_code($new_code);
        $new_coupon->set_description($original->get_description());
        $new_coupon->set_discount_type($original->get_discount_type());
        $new_coupon->set_amount($original->get_amount());
        $new_coupon->set_free_shipping($original->get_free_shipping());
        $new_coupon->set_date_expires($original->get_date_expires() ? $original->get_date_expires()->getTimestamp() : null);
        $new_coupon->set_minimum_amount($original->get_minimum_amount());
        $new_coupon->set_maximum_amount($original->get_maximum_amount());
        $new_coupon->set_individual_use($original->get_individual_use());
        $new_coupon->set_exclude_sale_items($original->get_exclude_sale_items());
        $new_coupon->set_usage_limit($original->get_usage_limit());
        $new_coupon->set_usage_limit_per_user($original->get_usage_limit_per_user());
        $new_coupon->set_product_ids($original->get_product_ids());
        $new_coupon->set_excluded_product_ids($original->get_excluded_product_ids());
        $new_coupon->set_product_categories($original->get_product_categories());
        $new_coupon->set_excluded_product_categories($original->get_excluded_product_categories());
        $new_coupon->set_email_restrictions($original->get_email_restrictions());
        $new_coupon->set_limit_usage_to_x_items($original->get_limit_usage_to_x_items());
        $new_coupon->set_status('draft');

        $new_coupon->save();

        wp_send_json_success([
            'message'   => __('Coupon duplicated!', 'brikpanel'),
            'coupon_id' => $new_coupon->get_id(),
        ]);
    }

    // =========================================================================
    // AJAX: SEARCH PRODUCTS (for advanced restriction fields)
    // =========================================================================

    public function ajax_search_products() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $term = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (strlen($term) < 2) {
            wp_send_json_success(['items' => []]);
        }

        // Use WooCommerce's data store search when available so SKU lookups
        // work like the native product search; fall back to WP_Query.
        $ids = [];
        if (function_exists('wc_get_container')) {
            $data_store = WC_Data_Store::load('product');
            if (method_exists($data_store, 'search_products')) {
                $ids = $data_store->search_products($term, '', false, false, 20);
                $ids = array_filter(array_map('intval', $ids));
            }
        }

        if (empty($ids)) {
            $query = new WP_Query([
                'post_type'      => ['product'],
                'post_status'    => 'publish',
                's'              => $term,
                'posts_per_page' => 20,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ]);
            $ids = $query->posts;
        }

        $items = [];
        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $items[] = [
                'value' => (int) $pid,
                'label' => $this->format_product_label($product),
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    // =========================================================================
    // AJAX: SEARCH CATEGORIES
    // =========================================================================

    public function ajax_search_categories() {
        check_ajax_referer('brikpanel_coupons_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $term = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        if (strlen($term) < 1) {
            wp_send_json_success(['items' => []]);
        }

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'name__like' => $term,
            'number'     => 30,
        ]);

        if (is_wp_error($terms)) {
            wp_send_json_success(['items' => []]);
        }

        $items = [];
        foreach ($terms as $t) {
            $items[] = [
                'value' => (int) $t->term_id,
                'label' => $t->name,
            ];
        }

        wp_send_json_success(['items' => $items]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function sanitize_id_list($raw) {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[] = $v;
            }
        }
        return array_values(array_unique($ids));
    }

    private function sanitize_email_list($raw) {
        if (!is_string($raw)) {
            return [];
        }
        $parts = preg_split('/[\s,]+/', $raw);
        $emails = [];
        foreach ($parts as $email) {
            $email = trim(strtolower($email));
            if ($email === '') {
                continue;
            }
            // Allow wildcard emails like *@example.com (WooCommerce supports them).
            if (strpos($email, '*') !== false) {
                if (preg_match('/^[a-z0-9._%+\-\*]+@[a-z0-9.\-\*]+\.[a-z*]{2,}$/i', $email)) {
                    $emails[] = $email;
                }
                continue;
            }
            if (is_email($email)) {
                $emails[] = sanitize_email($email);
            }
        }
        return array_values(array_unique(array_filter($emails)));
    }

    private function build_product_labels(array $ids) {
        if (empty($ids)) {
            return [];
        }
        $labels = [];
        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                // Product was deleted — still expose the ID so it can be removed
                // from the coupon without losing the rest of the selection.
                $labels[] = [
                    'value' => (int) $pid,
                    /* translators: %d: product ID */
                    'label' => sprintf(__('(deleted product #%d)', 'brikpanel'), (int) $pid),
                ];
                continue;
            }
            $labels[] = [
                'value' => (int) $pid,
                'label' => $this->format_product_label($product),
            ];
        }
        return $labels;
    }

    private function build_category_labels(array $ids) {
        if (empty($ids)) {
            return [];
        }
        $labels = [];
        foreach ($ids as $tid) {
            $term = get_term((int) $tid, 'product_cat');
            if (!$term || is_wp_error($term)) {
                $labels[] = [
                    'value' => (int) $tid,
                    /* translators: %d: category ID */
                    'label' => sprintf(__('(deleted category #%d)', 'brikpanel'), (int) $tid),
                ];
                continue;
            }
            $labels[] = [
                'value' => (int) $tid,
                'label' => $term->name,
            ];
        }
        return $labels;
    }

    private function format_product_label($product) {
        $name = $product->get_name();
        $sku  = $product->get_sku();
        if ($sku) {
            return $name . ' (' . $sku . ')';
        }
        return $name . ' (#' . $product->get_id() . ')';
    }
}

new Brikpanel_Coupons();
