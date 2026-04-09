<?php
/**
 * BrikPanel - Simplified Product Editor
 *
 * Replaces WooCommerce's default product editor with a clean,
 * simplified interface designed for non-technical SMB users.
 *
 * @package BrikPanel
 * @since 1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Brikpanel_Product_Editor {

    public function __construct() {
        if (get_option('brikpanel_simple_product_editor', 'yes') !== 'yes') {
            return;
        }

        add_action('admin_menu', [$this, 'register_page']);
        add_action('admin_init', [$this, 'handle_redirects']);
        add_filter('get_edit_post_link', [$this, 'modify_edit_link'], 10, 2);
        add_filter('admin_body_class', [$this, 'add_body_class']);

        // Product list columns
        add_filter('manage_edit-product_columns', [$this, 'set_columns'], 99);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);

        // AJAX
        add_action('wp_ajax_brikpanel_save_product', [$this, 'ajax_save_product']);
        add_action('wp_ajax_brikpanel_add_category', [$this, 'ajax_add_category']);
        add_action('wp_ajax_brikpanel_upload_image', [$this, 'ajax_upload_image']);
        add_action('wp_ajax_brikpanel_duplicate_product', [$this, 'ajax_duplicate_product']);
        add_action('wp_ajax_brikpanel_inline_edit', [$this, 'ajax_inline_edit']);
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __('Product Editor', 'brikpanel'),
            '',
            'edit_products',
            'brikpanel-product-editor',
            [$this, 'render_page']
        );

        // Set page title before admin-header.php runs (prevents strip_tags null warning)
        if ($hook) {
            add_action('load-' . $hook, function () {
                global $title;
                $title = __('Product Editor', 'brikpanel');
            });
        }
    }

    public function handle_redirects() {
        global $pagenow;

        if (!current_user_can('edit_products')) {
            return;
        }

        if ($pagenow === 'post-new.php' && isset($_GET['post_type']) && sanitize_key($_GET['post_type']) === 'product') {
            wp_safe_redirect(admin_url('admin.php?page=brikpanel-product-editor'));
            exit;
        }

        if ($pagenow === 'post.php' && isset($_GET['post']) && isset($_GET['action']) && sanitize_key($_GET['action']) === 'edit') {
            $post_id = intval($_GET['post']);
            if ($post_id && get_post_type($post_id) === 'product') {
                wp_safe_redirect(admin_url('admin.php?page=brikpanel-product-editor&product_id=' . $post_id));
                exit;
            }
        }
    }

    public function modify_edit_link($link, $post_id) {
        if (get_post_type($post_id) === 'product') {
            return admin_url('admin.php?page=brikpanel-product-editor&product_id=' . intval($post_id));
        }
        return $link;
    }

    /**
     * Suppress PHP 8.1 deprecation warnings from WordPress core on our page.
     * These come from wp-includes/functions.php passing null to strpos/str_replace
     * during menu traversal — a known WP core issue, not from our plugin.
     */
    public function add_body_class($classes) {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'admin_page_brikpanel-product-editor') {
            $classes .= ' brikpanel-product-editor-page';
        }
        return $classes;
    }

    // =========================================================================
    // RENDER EDITOR PAGE
    // =========================================================================

    public function render_page() {
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        $product    = $product_id ? wc_get_product($product_id) : null;

        if ($product_id && !$product) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Product not found.', 'brikpanel') . '</p></div></div>';
            return;
        }

        $data        = $this->get_product_data($product);
        $currency    = get_woocommerce_currency_symbol();
        $decimal_sep = wc_get_price_decimal_separator();
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        $dim_unit    = get_option('woocommerce_dimension_unit', 'cm');
        $categories  = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $is_edit     = !empty($product);
        $page_title  = $is_edit ? __('Edit product', 'brikpanel') : __('Add product', 'brikpanel');

        // Format prices for display
        $reg_price  = $data['regular_price'] !== '' ? str_replace('.', $decimal_sep, $data['regular_price']) : '';
        $sale_price = $data['sale_price'] !== '' ? str_replace('.', $decimal_sep, $data['sale_price']) : '';
        $weight_val = $data['weight'] !== '' ? str_replace('.', $decimal_sep, $data['weight']) : '';

        // Global WC attributes for custom variation mode
        $global_attributes = [];
        foreach (wc_get_attribute_taxonomies() as $attr) {
            $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
            $term_names = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $term_names[] = $term->name;
                }
            }
            $global_attributes[] = [
                'id'       => (int) $attr->attribute_id,
                'name'     => $attr->attribute_label ?: $attr->attribute_name,
                'slug'     => $attr->attribute_name,
                'taxonomy' => $taxonomy,
                'terms'    => $term_names,
            ];
        }

        // JS data for existing product
        $js_data = wp_json_encode([
            'id'                => $product_id,
            'is_variable'       => $data['is_variable'],
            'attributes'        => $data['attributes'],
            'variations'        => $data['variations'],
            'gallery'           => $data['gallery'],
            'global_attributes' => $global_attributes,
        ]);
        ?>
        <div class="wrap">
        <div class="brikpanel-pe">
            <input type="hidden" id="bpe-product-id" value="<?php echo esc_attr($product_id); ?>">

            <!-- Header -->
            <div class="brikpanel-pe-header">
                <div class="brikpanel-pe-header-left">
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="brikpanel-pe-back">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php esc_html_e('Products', 'brikpanel'); ?>
                    </a>
                    <h1><?php echo esc_html($page_title); ?></h1>
                </div>
                <div class="brikpanel-pe-header-right">
                    <?php if ($is_edit && $data['status'] === 'publish') : ?>
                        <a href="<?php echo esc_url(get_permalink($product_id)); ?>" class="brikpanel-pe-btn secondary" id="bpe-view-product" target="_blank"><?php esc_html_e('View product', 'brikpanel'); ?></a>
                    <?php endif; ?>
                    <?php if ($is_edit) : ?>
                        <button type="button" class="brikpanel-pe-btn secondary" id="bpe-duplicate" data-id="<?php echo esc_attr($product_id); ?>"><?php esc_html_e('Duplicate', 'brikpanel'); ?></button>
                    <?php endif; ?>
                    <button type="button" class="brikpanel-pe-btn secondary" id="bpe-save-draft"><?php esc_html_e('Save draft', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-pe-btn primary" id="bpe-publish">
                        <?php echo $is_edit && $data['status'] === 'publish' ? esc_html__('Update', 'brikpanel') : esc_html__('Publish', 'brikpanel'); ?>
                    </button>
                </div>
            </div>

            <!-- Content -->
            <div class="brikpanel-pe-content">

                <!-- Product Name -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-name"><?php esc_html_e('Product name', 'brikpanel'); ?></label>
                        <input type="text" id="bpe-name" value="<?php echo esc_attr($data['name']); ?>" placeholder="<?php esc_attr_e('E.g.: Cotton White T-Shirt - M Size', 'brikpanel'); ?>" data-required="1">
                        <div class="brikpanel-pe-field-error"></div>
                    </div>
                </div>

                <!-- Product Images -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Product images', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-images">
                        <div class="brikpanel-pe-dropzone" id="bpe-dropzone">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#8a8a8a" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                            <p><?php esc_html_e('Drag & drop images here', 'brikpanel'); ?></p>
                            <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-images"><?php esc_html_e('Add images', 'brikpanel'); ?></button>
                        </div>
                        <div class="brikpanel-pe-gallery" id="bpe-gallery"></div>
                    </div>
                </div>

                <!-- Pricing -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-price"><?php esc_html_e('Price', 'brikpanel'); ?></label>
                        <div class="brikpanel-pe-input-group">
                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                            <input type="text" id="bpe-price" value="<?php echo esc_attr($reg_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-required="1" data-price="1">
                        </div>
                        <div class="brikpanel-pe-field-error"></div>
                    </div>

                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('Sale price', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-sale-toggle" <?php checked(!empty($sale_price)); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo !empty($sale_price) ? 'open' : ''; ?>" id="bpe-sale-section">
                        <div>
                            <div class="brikpanel-pe-field">
                                <div class="brikpanel-pe-input-group">
                                    <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                    <input type="text" id="bpe-sale-price" value="<?php echo esc_attr($sale_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-price="1">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-stock"><?php esc_html_e('Stock quantity', 'brikpanel'); ?></label>
                            <input type="number" id="bpe-stock" value="<?php echo esc_attr($data['stock_quantity']); ?>" min="0" placeholder="0">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sku">
                                <?php esc_html_e('SKU', 'brikpanel'); ?>
                                <span class="brikpanel-pe-tooltip" data-tip="<?php esc_attr_e('Important for marketplace synchronization. Should match your Trendyol/Hepsiburada product code.', 'brikpanel'); ?>">?</span>
                            </label>
                            <input type="text" id="bpe-sku" value="<?php echo esc_attr($data['sku']); ?>" placeholder="<?php esc_attr_e('e.g.: TSH-001', 'brikpanel'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Category -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Category', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-cat-wrap">
                        <input type="text" class="brikpanel-pe-cat-search" id="bpe-cat-search" placeholder="<?php esc_attr_e('Search categories...', 'brikpanel'); ?>">
                        <div class="brikpanel-pe-cat-list">
                            <?php $this->render_category_checklist($categories, $data['category_ids']); ?>
                        </div>
                    </div>
                    <a href="#" id="bpe-add-cat-toggle" class="brikpanel-pe-link"><?php esc_html_e('+ Add new category', 'brikpanel'); ?></a>
                    <div class="brikpanel-pe-collapse" id="bpe-new-cat-section">
                        <div>
                            <div class="brikpanel-pe-inline-form">
                                <input type="text" id="bpe-new-cat-name" placeholder="<?php esc_attr_e('Category name', 'brikpanel'); ?>">
                                <select id="bpe-new-cat-parent">
                                    <option value="0"><?php esc_html_e('— No parent —', 'brikpanel'); ?></option>
                                    <?php $this->render_category_parent_options($categories); ?>
                                </select>
                                <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-cat-btn"><?php esc_html_e('Add', 'brikpanel'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Short Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-short-desc"><?php esc_html_e('Short description', 'brikpanel'); ?></label>
                        <textarea id="bpe-short-desc" rows="3" maxlength="500" placeholder="<?php esc_attr_e('Brief product summary...', 'brikpanel'); ?>"><?php echo esc_textarea($data['short_description']); ?></textarea>
                        <div class="brikpanel-pe-char-count"><span id="bpe-short-desc-count"><?php echo strlen($data['short_description']); ?></span>/500</div>
                    </div>
                </div>

                <!-- Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label><?php esc_html_e('Product description', 'brikpanel'); ?></label>
                        <div class="brikpanel-pe-editor-toolbar">
                            <button type="button" data-cmd="bold" title="<?php esc_attr_e('Bold', 'brikpanel'); ?>"><strong>B</strong></button>
                            <button type="button" data-cmd="italic" title="<?php esc_attr_e('Italic', 'brikpanel'); ?>"><em>I</em></button>
                            <button type="button" data-cmd="insertUnorderedList" title="<?php esc_attr_e('Bullet list', 'brikpanel'); ?>">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="2" cy="4" r="1.5"/><circle cx="2" cy="8" r="1.5"/><circle cx="2" cy="12" r="1.5"/><rect x="5" y="3" width="10" height="2" rx="1"/><rect x="5" y="7" width="10" height="2" rx="1"/><rect x="5" y="11" width="10" height="2" rx="1"/></svg>
                            </button>
                        </div>
                        <div class="brikpanel-pe-editor" id="bpe-description" contenteditable="true"><?php echo wp_kses_post($data['description']); ?></div>
                    </div>
                </div>

                <!-- Shipping Weight -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('Shipping weight', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-weight-toggle" <?php checked(!empty($weight_val)); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo !empty($weight_val) ? 'open' : ''; ?>" id="bpe-weight-section">
                        <div>
                            <div class="brikpanel-pe-field">
                                <div class="brikpanel-pe-input-group">
                                    <input type="text" id="bpe-weight" value="<?php echo esc_attr($weight_val); ?>" placeholder="0.0">
                                    <span class="brikpanel-pe-input-suffix"><?php echo esc_html($weight_unit); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shipping Dimensions -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('Shipping dimensions', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-dims-toggle" <?php checked(!empty($data['length']) || !empty($data['width']) || !empty($data['height'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo (!empty($data['length']) || !empty($data['width']) || !empty($data['height'])) ? 'open' : ''; ?>" id="bpe-dims-section">
                        <div>
                            <div class="brikpanel-pe-row brikpanel-pe-row-3">
                                <div class="brikpanel-pe-field">
                                    <label for="bpe-length"><?php esc_html_e('Length', 'brikpanel'); ?></label>
                                    <div class="brikpanel-pe-input-group">
                                        <input type="text" id="bpe-length" value="<?php echo esc_attr($data['length']); ?>" placeholder="0">
                                        <span class="brikpanel-pe-input-suffix"><?php echo esc_html($dim_unit); ?></span>
                                    </div>
                                </div>
                                <div class="brikpanel-pe-field">
                                    <label for="bpe-width"><?php esc_html_e('Width', 'brikpanel'); ?></label>
                                    <div class="brikpanel-pe-input-group">
                                        <input type="text" id="bpe-width" value="<?php echo esc_attr($data['width']); ?>" placeholder="0">
                                        <span class="brikpanel-pe-input-suffix"><?php echo esc_html($dim_unit); ?></span>
                                    </div>
                                </div>
                                <div class="brikpanel-pe-field">
                                    <label for="bpe-height"><?php esc_html_e('Height', 'brikpanel'); ?></label>
                                    <div class="brikpanel-pe-input-group">
                                        <input type="text" id="bpe-height" value="<?php echo esc_attr($data['height']); ?>" placeholder="0">
                                        <span class="brikpanel-pe-input-suffix"><?php echo esc_html($dim_unit); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SEO -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('SEO settings', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-seo-toggle" <?php checked(!empty($data['seo_title']) || !empty($data['seo_description'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo (!empty($data['seo_title']) || !empty($data['seo_description'])) ? 'open' : ''; ?>" id="bpe-seo-section">
                        <div>
                            <div class="brikpanel-pe-seo-preview" id="bpe-seo-preview">
                                <span class="brikpanel-pe-seo-preview-title" id="bpe-seo-preview-title"><?php echo esc_html($data['seo_title'] ?: $data['name'] ?: __('Product title', 'brikpanel')); ?></span>
                                <span class="brikpanel-pe-seo-preview-url"><?php echo esc_html($is_edit ? get_permalink($product_id) : home_url('/product/...')); ?></span>
                                <span class="brikpanel-pe-seo-preview-desc" id="bpe-seo-preview-desc"><?php echo esc_html($data['seo_description'] ?: mb_substr(wp_strip_all_tags($data['short_description'] ?: $data['description']), 0, 160)); ?></span>
                            </div>
                            <div class="brikpanel-pe-field">
                                <label for="bpe-seo-title"><?php esc_html_e('SEO title', 'brikpanel'); ?></label>
                                <input type="text" id="bpe-seo-title" value="<?php echo esc_attr($data['seo_title']); ?>" placeholder="<?php esc_attr_e('Leave empty to use product name', 'brikpanel'); ?>" maxlength="70">
                                <div class="brikpanel-pe-char-count"><span id="bpe-seo-title-count"><?php echo strlen($data['seo_title']); ?></span>/70</div>
                            </div>
                            <div class="brikpanel-pe-field">
                                <label for="bpe-seo-desc"><?php esc_html_e('Meta description', 'brikpanel'); ?></label>
                                <textarea id="bpe-seo-desc" rows="2" maxlength="160" placeholder="<?php esc_attr_e('Brief description for search engines...', 'brikpanel'); ?>"><?php echo esc_textarea($data['seo_description']); ?></textarea>
                                <div class="brikpanel-pe-char-count"><span id="bpe-seo-desc-count"><?php echo strlen($data['seo_description']); ?></span>/160</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Variations -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('Does this product have sizes/colors?', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-var-toggle" <?php checked($data['is_variable']); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>

                    <div class="brikpanel-pe-collapse <?php echo $data['is_variable'] ? 'open' : ''; ?>" id="bpe-var-section">
                        <div class="brikpanel-pe-var-wizard">

                            <!-- Step 1: Template Selection -->
                            <div class="brikpanel-pe-var-step" data-step="1">
                                <h3><?php esc_html_e('Choose variation type', 'brikpanel'); ?></h3>
                                <div class="brikpanel-pe-var-templates">
                                    <div class="brikpanel-pe-var-template" data-template="size-color">
                                        <div class="brikpanel-pe-var-template-icon">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="9" height="9" rx="2"/><circle cx="17" cy="17" r="5"/><circle cx="17" cy="17" r="2" fill="#e74c3c" stroke="none"/></svg>
                                        </div>
                                        <strong><?php esc_html_e('Size + Color', 'brikpanel'); ?></strong>
                                        <small><?php esc_html_e('Size and color combinations', 'brikpanel'); ?></small>
                                    </div>
                                    <div class="brikpanel-pe-var-template" data-template="custom">
                                        <div class="brikpanel-pe-var-template-icon">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        </div>
                                        <strong><?php esc_html_e('Custom', 'brikpanel'); ?></strong>
                                        <small><?php esc_html_e('Use existing or create new attributes', 'brikpanel'); ?></small>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: Enter Values -->
                            <div class="brikpanel-pe-var-step" data-step="2" style="display:none">
                                <div class="brikpanel-pe-var-step-header">
                                    <button type="button" class="brikpanel-pe-btn-text" id="bpe-var-back">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <?php esc_html_e('Back', 'brikpanel'); ?>
                                    </button>
                                    <h3><?php esc_html_e('Enter values', 'brikpanel'); ?></h3>
                                </div>
                                <div id="bpe-var-attributes"></div>
                                <button type="button" class="brikpanel-pe-btn primary" id="bpe-generate-vars"><?php esc_html_e('Generate variations', 'brikpanel'); ?></button>
                            </div>

                            <!-- Step 3: Variation Table -->
                            <div class="brikpanel-pe-var-step" data-step="3" style="display:none">
                                <div class="brikpanel-pe-var-step-header">
                                    <button type="button" class="brikpanel-pe-btn-text" id="bpe-var-back-2">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <?php esc_html_e('Back', 'brikpanel'); ?>
                                    </button>
                                    <h3><?php esc_html_e('Variations', 'brikpanel'); ?></h3>
                                </div>
                                <div class="brikpanel-pe-var-bulk">
                                    <div class="brikpanel-pe-var-bulk-item">
                                        <label><?php esc_html_e('Set all prices:', 'brikpanel'); ?></label>
                                        <div class="brikpanel-pe-input-group small">
                                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                            <input type="text" id="bpe-bulk-price" data-price="1">
                                        </div>
                                    </div>
                                    <div class="brikpanel-pe-var-bulk-item">
                                        <label><?php esc_html_e('Set all stocks:', 'brikpanel'); ?></label>
                                        <input type="number" id="bpe-bulk-stock" class="brikpanel-pe-input small" min="0">
                                    </div>
                                    <button type="button" class="brikpanel-pe-btn primary small" id="bpe-apply-bulk"><?php esc_html_e('Apply', 'brikpanel'); ?></button>
                                </div>
                                <div class="brikpanel-pe-var-table-wrap">
                                    <table class="brikpanel-pe-var-table" id="bpe-var-table">
                                        <thead>
                                            <tr>
                                                <th><?php esc_html_e('Variation', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Price', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Sale Price', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('SKU', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Image', 'brikpanel'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody id="bpe-var-table-body"></tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div><!-- .brikpanel-pe-content -->
        </div><!-- .brikpanel-pe -->
        </div><!-- .wrap -->

        <script>var brikpanelProductData = <?php echo $js_data; ?>;</script>
        <?php
    }

    // =========================================================================
    // DATA HELPERS
    // =========================================================================

    private function get_product_data($product) {
        $defaults = [
            'name'              => '',
            'regular_price'     => '',
            'sale_price'        => '',
            'stock_quantity'    => '',
            'sku'               => '',
            'short_description' => '',
            'description'       => '',
            'weight'            => '',
            'length'            => '',
            'width'             => '',
            'height'            => '',
            'category_ids'      => [],
            'status'            => 'draft',
            'is_variable'       => false,
            'attributes'        => [],
            'variations'        => [],
            'gallery'           => [],
            'seo_title'         => '',
            'seo_description'   => '',
        ];

        if (!$product) {
            return $defaults;
        }

        // Gallery data
        $gallery = [];
        $image_id = $product->get_image_id();
        if ($image_id) {
            $gallery[] = [
                'id'  => (int) $image_id,
                'url' => wp_get_attachment_image_url($image_id, 'thumbnail'),
            ];
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $gallery[] = [
                'id'  => (int) $gid,
                'url' => wp_get_attachment_image_url($gid, 'thumbnail'),
            ];
        }

        // Attributes + variations for variable products
        $attributes_data = [];
        $variations_data = [];
        $is_variable     = $product->is_type('variable');

        if ($is_variable) {
            foreach ($product->get_attributes() as $attr) {
                $attributes_data[] = [
                    'name'   => $attr->get_name(),
                    'values' => $attr->get_options(),
                ];
            }

            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) continue;

                $var_attrs = [];
                foreach ($variation->get_attributes() as $key => $val) {
                    $var_attrs[$key] = $val;
                }

                // Variation images: thumbnail + custom gallery
                $var_images = [];
                $var_image_id = $variation->get_image_id();
                if ($var_image_id) {
                    $var_images[] = [
                        'id'  => (int) $var_image_id,
                        'url' => wp_get_attachment_image_url($var_image_id, 'thumbnail'),
                    ];
                }
                $var_gallery = get_post_meta($variation->get_id(), '_brikpanel_variation_gallery', true);
                if (!empty($var_gallery) && is_array($var_gallery)) {
                    foreach ($var_gallery as $gid) {
                        $gid = (int) $gid;
                        if ($gid) {
                            $var_images[] = [
                                'id'  => $gid,
                                'url' => wp_get_attachment_image_url($gid, 'thumbnail'),
                            ];
                        }
                    }
                }

                $variations_data[] = [
                    'id'             => $variation->get_id(),
                    'attributes'     => $var_attrs,
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'sku'            => $variation->get_sku() ?? '',
                    'images'         => $var_images,
                ];
            }
        }

        // For variable products, derive price/stock from variations
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $stock_qty     = $product->get_stock_quantity();

        if ($is_variable && $regular_price === '') {
            $prices = $product->get_variation_prices(true);
            if (!empty($prices['regular_price'])) {
                $regular_price = min($prices['regular_price']);
            }
        }
        if ($is_variable && $stock_qty === null) {
            $total_stock = 0;
            foreach ($product->get_children() as $child_id) {
                $child = wc_get_product($child_id);
                if ($child && $child->get_manage_stock()) {
                    $total_stock += (int) $child->get_stock_quantity();
                }
            }
            $stock_qty = $total_stock;
        }

        // SEO data — compatible with Yoast and Rank Math
        $pid = $product->get_id();
        $seo_title = '';
        $seo_desc  = '';
        if (get_post_meta($pid, '_yoast_wpseo_title', true)) {
            $seo_title = get_post_meta($pid, '_yoast_wpseo_title', true);
            $seo_desc  = get_post_meta($pid, '_yoast_wpseo_metadesc', true);
        } elseif (get_post_meta($pid, 'rank_math_title', true)) {
            $seo_title = get_post_meta($pid, 'rank_math_title', true);
            $seo_desc  = get_post_meta($pid, 'rank_math_description', true);
        }

        return [
            'name'              => $product->get_name() ?? '',
            'regular_price'     => $regular_price,
            'sale_price'        => $sale_price,
            'stock_quantity'    => $stock_qty,
            'sku'               => $product->get_sku() ?? '',
            'short_description' => $product->get_short_description() ?? '',
            'description'       => $product->get_description() ?? '',
            'weight'            => $product->get_weight() ?? '',
            'length'            => $product->get_length() ?? '',
            'width'             => $product->get_width() ?? '',
            'height'            => $product->get_height() ?? '',
            'category_ids'      => $product->get_category_ids(),
            'status'            => $product->get_status(),
            'is_variable'       => $is_variable,
            'attributes'        => $attributes_data,
            'variations'        => $variations_data,
            'seo_title'         => $seo_title,
            'seo_description'   => $seo_desc,
            'gallery'           => $gallery,
        ];
    }

    private function render_category_checklist($categories, $selected_ids, $parent = 0, $depth = 0) {
        $children = [];
        foreach ($categories as $cat) {
            if ($cat->parent === $parent) {
                $children[] = $cat;
            }
        }

        if (empty($children)) return;

        $class = $parent === 0 ? 'brikpanel-pe-cat-tree' : 'brikpanel-pe-cat-children';
        echo '<ul class="' . esc_attr($class) . '">';

        foreach ($children as $cat) {
            $checked = in_array($cat->term_id, $selected_ids, true) ? ' checked' : '';
            echo '<li data-name="' . esc_attr(mb_strtolower($cat->name)) . '" class="brikpanel-pe-cat-depth-' . esc_attr($depth) . '">';
            echo '<label><input type="checkbox" name="category_ids[]" value="' . esc_attr($cat->term_id) . '"' . $checked . '> ' . esc_html($cat->name) . '</label>';
            $this->render_category_checklist($categories, $selected_ids, $cat->term_id, $depth + 1);
            echo '</li>';
        }

        echo '</ul>';
    }

    private function render_category_parent_options($categories, $parent = 0, $depth = 0) {
        foreach ($categories as $cat) {
            if ($cat->parent !== $parent) continue;
            $indent = str_repeat('— ', $depth);
            echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($indent . $cat->name) . '</option>';
            $this->render_category_parent_options($categories, $cat->term_id, $depth + 1);
        }
    }

    // =========================================================================
    // AJAX: SAVE PRODUCT
    // =========================================================================

    public function ajax_save_product() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id  = intval($_POST['product_id'] ?? 0);
        $is_variable = !empty($_POST['is_variable']);
        $status      = sanitize_key($_POST['status'] ?? 'draft');

        if (!in_array($status, ['draft', 'publish'], true)) {
            $status = 'draft';
        }

        // If editing existing product, handle type changes
        if ($product_id) {
            $existing = wc_get_product($product_id);
            if (!$existing) {
                wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
            }

            // Variable to simple: clean up variations
            if ($existing->is_type('variable') && !$is_variable) {
                foreach ($existing->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if ($child) $child->delete(true);
                }
            }
        }

        // Create product with correct type
        if ($is_variable) {
            $product = $product_id ? new WC_Product_Variable($product_id) : new WC_Product_Variable();
        } else {
            $product = $product_id ? new WC_Product_Simple($product_id) : new WC_Product_Simple();
        }

        // Basic data
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if (empty($name)) {
            wp_send_json_error(['message' => __('Product name is required.', 'brikpanel')]);
        }
        $product->set_name($name);
        $product->set_status($status);
        $product->set_short_description(sanitize_textarea_field(wp_unslash($_POST['short_description'] ?? '')));
        $product->set_description(wp_kses_post(wp_unslash($_POST['description'] ?? '')));

        // Price (simple products only)
        if (!$is_variable) {
            $product->set_regular_price(wc_format_decimal(sanitize_text_field($_POST['regular_price'] ?? '')));
            $sale = sanitize_text_field($_POST['sale_price'] ?? '');
            $product->set_sale_price($sale !== '' ? wc_format_decimal($sale) : '');
        }

        // Stock
        $stock = isset($_POST['stock_quantity']) && $_POST['stock_quantity'] !== '' ? intval($_POST['stock_quantity']) : null;
        if (!$is_variable && $stock !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
            $product->set_backorders('no');
        } elseif (!$is_variable) {
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
        }

        // SKU
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        try {
            $product->set_sku($sku);
        } catch (\Exception $e) {
            // SKU might be duplicate — continue without it
        }

        // Weight
        $weight = sanitize_text_field($_POST['weight'] ?? '');
        $product->set_weight($weight !== '' ? wc_format_decimal($weight) : '');

        // Dimensions
        $length = sanitize_text_field($_POST['length'] ?? '');
        $width  = sanitize_text_field($_POST['width'] ?? '');
        $height = sanitize_text_field($_POST['height'] ?? '');
        $product->set_length($length !== '' ? wc_format_decimal($length) : '');
        $product->set_width($width !== '' ? wc_format_decimal($width) : '');
        $product->set_height($height !== '' ? wc_format_decimal($height) : '');

        // Images
        $image_id = intval($_POST['image_id'] ?? 0);
        $product->set_image_id($image_id);

        $gallery_ids_raw = sanitize_text_field($_POST['gallery_ids'] ?? '');
        $gallery_ids = $gallery_ids_raw ? array_map('intval', explode(',', $gallery_ids_raw)) : [];
        $product->set_gallery_image_ids($gallery_ids);

        // Categories
        $cat_ids_raw = sanitize_text_field($_POST['category_ids'] ?? '');
        $cat_ids = $cat_ids_raw ? array_map('intval', explode(',', $cat_ids_raw)) : [];
        $product->set_category_ids($cat_ids);

        // Save parent product
        $product->save();
        $saved_id = $product->get_id();

        // SEO meta — save to both Yoast and Rank Math keys for compatibility
        $seo_title = sanitize_text_field(wp_unslash($_POST['seo_title'] ?? ''));
        $seo_desc  = sanitize_textarea_field(wp_unslash($_POST['seo_description'] ?? ''));
        update_post_meta($saved_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($saved_id, '_yoast_wpseo_metadesc', $seo_desc);
        update_post_meta($saved_id, 'rank_math_title', $seo_title);
        update_post_meta($saved_id, 'rank_math_description', $seo_desc);

        // Handle variable product attributes and variations
        if ($is_variable) {
            $this->save_variations($product, $_POST);
        }

        wp_send_json_success([
            'product_id' => $saved_id,
            'permalink'  => get_permalink($saved_id),
            'message'    => __('Product saved!', 'brikpanel'),
        ]);
    }

    private function save_variations($product, $post_data) {
        $attributes_json = isset($post_data['attributes']) ? wp_unslash($post_data['attributes']) : '[]';
        $variations_json = isset($post_data['variations']) ? wp_unslash($post_data['variations']) : '[]';

        $attributes_data = json_decode($attributes_json, true);
        $variations_data = json_decode($variations_json, true);

        if (!is_array($attributes_data) || !is_array($variations_data)) {
            return;
        }

        // Build WC attributes
        $wc_attributes = [];
        $position = 0;

        foreach ($attributes_data as $attr_data) {
            $name   = sanitize_text_field($attr_data['name'] ?? '');
            $values = array_map('sanitize_text_field', (array) ($attr_data['values'] ?? []));

            if (empty($name) || empty($values)) continue;

            $attribute = new WC_Product_Attribute();
            $attribute->set_name($name);
            $attribute->set_options($values);
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $wc_attributes[] = $attribute;
        }

        $product->set_attributes($wc_attributes);
        $product->save();

        // Track submitted variation IDs
        $submitted_ids = [];

        foreach ($variations_data as $var_data) {
            $var_id = intval($var_data['id'] ?? 0);

            if ($var_id) {
                $variation = wc_get_product($var_id);
                if (!$variation || !$variation->is_type('variation')) {
                    $variation = new WC_Product_Variation();
                    $variation->set_parent_id($product->get_id());
                }
            } else {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product->get_id());
            }

            // Set attributes
            $var_attrs = [];
            if (isset($var_data['attributes']) && is_array($var_data['attributes'])) {
                foreach ($var_data['attributes'] as $key => $val) {
                    $var_attrs[sanitize_title($key)] = sanitize_text_field($val);
                }
            }
            $variation->set_attributes($var_attrs);

            // Price
            $var_price = isset($var_data['regular_price']) ? wc_format_decimal(sanitize_text_field($var_data['regular_price'])) : '';
            $variation->set_regular_price($var_price);

            $var_sale = isset($var_data['sale_price']) && $var_data['sale_price'] !== '' ? wc_format_decimal(sanitize_text_field($var_data['sale_price'])) : '';
            $variation->set_sale_price($var_sale);

            // Stock
            $var_stock = isset($var_data['stock_quantity']) && $var_data['stock_quantity'] !== '' ? intval($var_data['stock_quantity']) : 0;
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($var_stock);
            $variation->set_stock_status($var_stock > 0 ? 'instock' : 'outofstock');
            $variation->set_backorders('no');

            // SKU
            $var_sku = sanitize_text_field($var_data['sku'] ?? '');
            try {
                $variation->set_sku($var_sku);
            } catch (\Exception $e) {
                // Ignore duplicate SKU
            }

            // Images: first = WC thumbnail, rest = custom gallery meta
            $var_image_ids = [];
            if (isset($var_data['image_ids']) && is_array($var_data['image_ids'])) {
                $var_image_ids = array_map('intval', $var_data['image_ids']);
            }
            if (!empty($var_image_ids)) {
                $variation->set_image_id($var_image_ids[0]);
                $gallery_ids_for_var = array_slice($var_image_ids, 1);
                update_post_meta($variation->get_id() ?: 0, '_brikpanel_variation_gallery', $gallery_ids_for_var);
            } else {
                $variation->set_image_id(0);
                delete_post_meta($variation->get_id() ?: 0, '_brikpanel_variation_gallery');
            }

            $variation->save();
            // Save gallery meta after save (in case of new variation)
            if (!empty($var_image_ids) && count($var_image_ids) > 1) {
                update_post_meta($variation->get_id(), '_brikpanel_variation_gallery', array_slice($var_image_ids, 1));
            }
            $submitted_ids[] = $variation->get_id();
        }

        // Delete removed variations
        foreach ($product->get_children() as $child_id) {
            if (!in_array($child_id, $submitted_ids, true)) {
                $child = wc_get_product($child_id);
                if ($child) $child->delete(true);
            }
        }

        // Sync variable product data
        WC_Product_Variable::sync($product->get_id());
    }

    // =========================================================================
    // AJAX: ADD CATEGORY
    // =========================================================================

    public function ajax_add_category() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('manage_product_terms')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $parent = intval($_POST['parent'] ?? 0);

        if (empty($name)) {
            wp_send_json_error(['message' => __('Category name is required.', 'brikpanel')]);
        }

        $result = wp_insert_term($name, 'product_cat', ['parent' => $parent]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'term_id' => $result['term_id'],
            'name'    => $name,
            'parent'  => $parent,
        ]);
    }

    // =========================================================================
    // AJAX: UPLOAD IMAGE
    // =========================================================================

    public function ajax_upload_image() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('No file uploaded.', 'brikpanel')]);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('file', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        wp_send_json_success([
            'id'  => $attachment_id,
            'url' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        ]);
    }

    // =========================================================================
    // AJAX: DUPLICATE PRODUCT
    // =========================================================================

    public function ajax_duplicate_product() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
        }

        $duplicate = clone $product;
        $duplicate->set_id(0);
        $duplicate->set_name(($product->get_name() ?? '') . ' (' . __('Copy', 'brikpanel') . ')');
        $duplicate->set_status('draft');
        $duplicate->set_slug('');
        try {
            $duplicate->set_sku('');
        } catch (\Exception $e) {}
        $duplicate->save();

        // Copy SEO meta
        foreach (['_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description'] as $key) {
            $val = get_post_meta($product_id, $key, true);
            if ($val) update_post_meta($duplicate->get_id(), $key, $val);
        }

        // For variable products, duplicate variations
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) continue;

                $new_var = clone $variation;
                $new_var->set_id(0);
                $new_var->set_parent_id($duplicate->get_id());
                try {
                    $new_var->set_sku('');
                } catch (\Exception $e) {}
                $new_var->save();
            }
            WC_Product_Variable::sync($duplicate->get_id());
        }

        wp_send_json_success([
            'product_id' => $duplicate->get_id(),
            'message'    => __('Product duplicated!', 'brikpanel'),
        ]);
    }

    // =========================================================================
    // AJAX: INLINE EDIT (PRODUCT LIST)
    // =========================================================================

    public function ajax_inline_edit() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        $field      = sanitize_key($_POST['field'] ?? '');
        $value      = sanitize_text_field($_POST['value'] ?? '');

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
        }

        switch ($field) {
            case 'price':
                $product->set_regular_price(wc_format_decimal($value));
                break;
            case 'stock':
                $product->set_manage_stock(true);
                $product->set_stock_quantity(intval($value));
                $product->set_stock_status(intval($value) > 0 ? 'instock' : 'outofstock');
                break;
            default:
                wp_send_json_error(['message' => __('Invalid field.', 'brikpanel')]);
        }

        $product->save();
        wp_send_json_success(['message' => __('Updated', 'brikpanel')]);
    }

    // =========================================================================
    // PRODUCT LIST COLUMNS
    // =========================================================================

    public function set_columns($columns) {
        return [
            'cb'             => $columns['cb'] ?? '<input type="checkbox" />',
            'thumb'          => $columns['thumb'] ?? __('Image', 'brikpanel'),
            'name'           => $columns['name'] ?? __('Product', 'brikpanel'),
            'sku'            => $columns['sku'] ?? __('SKU', 'brikpanel'),
            'price'          => $columns['price'] ?? __('Price', 'brikpanel'),
            'bpe_stock'      => __('Stock', 'brikpanel'),
            'product_cat'    => $columns['product_cat'] ?? __('Category', 'brikpanel'),
            'product_status' => __('Status', 'brikpanel'),
        ];
    }

    public function render_column($column, $post_id) {
        if ($column === 'product_status') {
            $status = get_post_status($post_id);
            $label  = $status === 'publish' ? __('Published', 'brikpanel') : __('Draft', 'brikpanel');
            $class  = $status === 'publish' ? 'published' : 'draft';
            echo '<span class="brikpanel-pe-list-status brikpanel-pe-list-status--' . esc_attr($class) . '">' . esc_html($label) . '</span>';
        }

        if ($column === 'bpe_stock') {
            $product = wc_get_product($post_id);
            if (!$product) return;

            $stock = $product->get_stock_quantity();
            if ($product->is_type('variable')) {
                $stock = 0;
                foreach ($product->get_children() as $cid) {
                    $v = wc_get_product($cid);
                    if ($v && $v->get_manage_stock()) $stock += (int) $v->get_stock_quantity();
                }
            }

            if ($stock !== null && $stock !== '') {
                $warn = '';
                if ((int) $stock === 0) {
                    $warn = ' brikpanel-pe-stock-out';
                } elseif ((int) $stock <= 5) {
                    $warn = ' brikpanel-pe-stock-low';
                }
                echo '<span class="brikpanel-pe-stock-badge' . $warn . '" data-product-id="' . esc_attr($post_id) . '" data-field="stock" data-value="' . esc_attr($stock) . '">' . esc_html($stock) . '</span>';
            } else {
                echo '<span class="brikpanel-pe-stock-badge">—</span>';
            }
        }
    }
}

new Brikpanel_Product_Editor();
