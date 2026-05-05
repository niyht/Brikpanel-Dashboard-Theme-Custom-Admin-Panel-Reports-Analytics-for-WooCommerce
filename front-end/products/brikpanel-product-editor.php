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
        // Always register the page slug so WordPress doesn't throw a permission error
        // when someone navigates to the URL while the editor is disabled.
        add_action('admin_menu', [$this, 'register_page']);

        // Duplicate + inline edit are used by the BrikPanel products list, which
        // is shown even when the simplified editor is disabled. Register them
        // unconditionally so those buttons keep working.
        add_action('wp_ajax_brikpanel_duplicate_product', [$this, 'ajax_duplicate_product']);
        add_action('wp_ajax_brikpanel_inline_edit', [$this, 'ajax_inline_edit']);

        if (get_option('brikpanel_simple_product_editor', 'yes') !== 'yes') {
            add_action('admin_init', [$this, 'handle_disabled_redirects']);
            return;
        }
        add_action('admin_init', [$this, 'handle_redirects']);
        add_filter('get_edit_post_link', [$this, 'modify_edit_link'], 10, 2);
        add_filter('admin_body_class', [$this, 'add_body_class']);

        // When our custom product editor page is the current request, lie to
        // SEO plugins early so they register their metaboxes as if we were on
        // the native post-edit screen. Each plugin has its own gatekeeper:
        //  - Yoast SEO:  `wpseo_always_register_metaboxes_on_admin` filter
        //  - Rank Math:  relies on `get_current_screen()->id === 'product'`,
        //                handled by the screen spoof inside render_third_party_metaboxes()
        //  - AIOSEO:     hooks into `add_meta_boxes_{post_type}` — works natively
        //  - SEOPress:   `seopress_metabox_seo_post_types` / screen check
        // This runs before any plugin can evaluate its own conditions because
        // plugin constructors typically read `$_GET` inside `init`/`admin_init`.
        $requested_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($requested_page === 'brikpanel-product-editor'
            || (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action'])
                && in_array(sanitize_key($_POST['action']), ['brikpanel_save_product'], true))) {
            add_filter('wpseo_always_register_metaboxes_on_admin', '__return_true');
        }

        // Product list columns
        add_filter('manage_edit-product_columns', [$this, 'set_columns'], 99);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);

        // AJAX
        add_action('wp_ajax_brikpanel_save_product', [$this, 'ajax_save_product']);
        add_action('wp_ajax_brikpanel_add_category', [$this, 'ajax_add_category']);
        add_action('wp_ajax_brikpanel_upload_image', [$this, 'ajax_upload_image']);
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

        // If we're already on our custom editor page, never redirect — the
        // SEO-plugin compatibility bootstrap spoofs $_GET['post'] + $pagenow
        // on this request, which would otherwise retrigger the post.php path
        // below and cause an infinite redirect loop.
        if (isset($_GET['page']) && sanitize_key($_GET['page']) === 'brikpanel-product-editor') {
            // "Add new product" visits land here without a product_id. Mirror
            // WP's native /wp-admin/post-new.php flow by creating an auto-draft
            // up front — this gives third-party metaboxes a real post row to
            // attach to, matches the assumptions marketplace / SEO plugins are
            // written against, and prevents the first save from having to
            // special-case the create path. WP already cleans stale
            // auto-drafts after a week via its built-in cron.
            if (empty($_GET['product_id']) && !isset($_REQUEST['action'])
                && function_exists('get_default_post_to_edit') && current_user_can('edit_products')) {
                $draft = get_default_post_to_edit('product', true);
                if ($draft && !is_wp_error($draft) && !empty($draft->ID)) {
                    wp_safe_redirect(admin_url('admin.php?page=brikpanel-product-editor&product_id=' . (int) $draft->ID));
                    exit;
                }
            }
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

    /**
     * When the simplified editor is disabled, redirect any leftover BrikPanel
     * editor URLs straight to the default WooCommerce product edit screen
     * so the user never sees a "not allowed" error.
     */
    public function handle_disabled_redirects() {
        if (!isset($_GET['page']) || sanitize_key($_GET['page']) !== 'brikpanel-product-editor') {
            return;
        }
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        if ($product_id) {
            wp_safe_redirect(admin_url('post.php?post=' . $product_id . '&action=edit'));
        } else {
            wp_safe_redirect(admin_url('post-new.php?post_type=product'));
        }
        exit;
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
        $visible     = $this->get_visible_sections();
        $dim_unit    = get_option('woocommerce_dimension_unit', 'cm');
        $categories  = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        // Treat auto-drafts as "new" in the UI — WP creates the row on first
        // visit so our metabox compat layer has something to attach to, but
        // the user hasn't committed yet.
        $is_auto_draft = $product && $product->get_status() === 'auto-draft';
        $is_edit     = !empty($product) && !$is_auto_draft;
        $page_title  = $is_edit ? __('Edit product', 'brikpanel') : __('Add product', 'brikpanel');

        // COGS: always available in BrikPanel regardless of WC feature flag.
        // WC 9.5+ native COGS is used when available; otherwise falls back to
        // the BrikPanel-native _brikpanel_cogs meta key so profit tracking
        // works on any WooCommerce version.
        $cogs_enabled    = true;
        $cogs_wc_native  = get_option('woocommerce_feature_cost_of_goods_sold_enabled') === 'yes';

        // Product type selector — shows a dropdown of all registered product
        // types (including subscription / variable-subscription / bundle / etc.
        // registered by 3rd-party plugins) when the admin has opted in. The
        // default auto-flips based on whether any such plugin is active.
        $product_type_selector_enabled = function_exists('brikpanel_product_type_selector_enabled')
            && brikpanel_product_type_selector_enabled();
        $product_type_options = $product_type_selector_enabled && function_exists('brikpanel_editor_product_types')
            ? brikpanel_editor_product_types()
            : [];
        $product_type_current = (string) ($data['product_type'] ?: 'simple');
        // Existing product whose type was registered by a plugin that's no
        // longer active: still show the current value as an option so we
        // don't silently coerce on save.
        if ($product_type_selector_enabled && $product_type_current !== '' && !isset($product_type_options[$product_type_current])) {
            $product_type_options[$product_type_current] = ucwords(str_replace(['-', '_'], ' ', $product_type_current));
        }

        // Format prices for display
        $reg_price  = $data['regular_price'] !== '' ? str_replace('.', $decimal_sep, $data['regular_price']) : '';
        $sale_price = $data['sale_price'] !== '' ? str_replace('.', $decimal_sep, $data['sale_price']) : '';
        $weight_val = $data['weight'] !== '' ? str_replace('.', $decimal_sep, $data['weight']) : '';
        $cogs_val = '';
        if ($data['cogs_value'] !== '' && $data['cogs_value'] !== null) {
            $cogs_val = str_replace('.', $decimal_sep, (string) $data['cogs_value']);
        }

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

        // All existing product tags for autocomplete
        $all_tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'fields' => 'names']);
        if (is_wp_error($all_tags)) {
            $all_tags = [];
        }

        // Per-variation third-party fields (if any plugin added any, and admin
        // opted in via brikpanel_pe_wc_variation_sections). The HTML preserves
        // original `name="field[<loop>]"` attributes so the save handler can
        // re-post them under the same indices.
        $variation_extras = [];
        if (!empty($data['variations']) && $product && $product->is_type('variable')) {
            $variation_ids = array_map(function ($v) { return isset($v['id']) ? (int) $v['id'] : 0; }, $data['variations']);
            $variation_extras = $this->capture_wc_variation_fields($product, $variation_ids);
        }

        // JS data for existing product
        $js_data = wp_json_encode([
            'id'                => $product_id,
            'is_variable'       => $data['is_variable'],
            'product_type'      => $product_type_current,
            'type_selector'     => $product_type_selector_enabled,
            'attributes'        => $data['attributes'],
            'variations'        => $data['variations'],
            'gallery'           => $data['gallery'],
            'global_attributes' => $global_attributes,
            'downloads'         => $data['downloads'],
            'is_downloadable'   => $data['is_downloadable'],
            'tags'              => $data['tags'],
            'all_tags'          => array_values($all_tags),
            'cogs_enabled'      => $cogs_enabled,
            'variation_extras'  => $variation_extras,
        ]);

        // Pre-compute the "Additional product data" card once so we can echo
        // it at the top, middle, or bottom of the editor based on the
        // `brikpanel_pe_wc_tabs_position` setting. Middle = default, matches
        // the historical layout.
        $wc_extras_card     = '';
        $wc_extras_position = get_option('brikpanel_pe_wc_tabs_position', 'middle');
        if (!in_array($wc_extras_position, ['top', 'middle', 'bottom'], true)) {
            $wc_extras_position = 'middle';
        }
        if ($product_id) {
            $wc_extras = $this->capture_wc_product_data_fields($product_id, $product);
            if ($wc_extras !== '') {
                $wc_extras_card = '<div class="brikpanel-pe-card brikpanel-pe-wc-fields">'
                    . '<label>' . esc_html__('Additional product data', 'brikpanel') . '</label>'
                    . '<div class="brikpanel-pe-wc-fields-content">' . $wc_extras . '</div>'
                    . '</div>';
            }
        }
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
                    <?php $is_live = $is_edit && (in_array($data['status'], ['publish', 'private'], true)); ?>
                    <?php if ($is_live) : ?>
                        <a href="<?php echo esc_url(get_permalink($product_id)); ?>" class="brikpanel-pe-btn secondary" id="bpe-view-product" target="_blank"><?php esc_html_e('View product', 'brikpanel'); ?></a>
                    <?php endif; ?>
                    <?php if ($is_edit) : ?>
                        <button type="button" class="brikpanel-pe-btn secondary" id="bpe-duplicate" data-id="<?php echo esc_attr($product_id); ?>"><?php esc_html_e('Duplicate', 'brikpanel'); ?></button>
                    <?php endif; ?>
                    <?php if ($is_live) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=brikpanel-product-editor')); ?>" class="brikpanel-pe-btn secondary" id="bpe-add-new">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php esc_html_e('Add new', 'brikpanel'); ?>
                    </a>
                    <?php endif; ?>
                    <?php
                    // Password-protected is not a real WP status — it's "publish"
                    // with a non-empty post_password.  We use a virtual "password"
                    // value in the dropdown and map it back on save.
                    $effective_status = $data['status'];
                    $is_password = ($data['status'] === 'publish' && $data['post_password'] !== '');
                    if ($is_password) {
                        $effective_status = 'password';
                    }
                    ?>
                    <div class="brikpanel-pe-status-wrap" data-status="<?php echo esc_attr($effective_status); ?>">
                        <button type="button" class="brikpanel-pe-status-trigger" id="bpe-status-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="brikpanel-pe-status-dot" aria-hidden="true"></span>
                            <span class="brikpanel-pe-status-trigger-label">
                                <?php
                                $labels = array(
                                    'publish'  => __('Published', 'brikpanel'),
                                    'draft'    => __('Draft', 'brikpanel'),
                                    'private'  => __('Private', 'brikpanel'),
                                    'password' => __('Password protected', 'brikpanel'),
                                );
                                echo esc_html( isset($labels[$effective_status]) ? $labels[$effective_status] : __('Draft', 'brikpanel') );
                                ?>
                            </span>
                            <svg class="brikpanel-pe-status-chevron" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <input type="hidden" id="bpe-status" value="<?php echo esc_attr($effective_status); ?>">
                        <ul class="brikpanel-pe-status-menu" role="listbox" aria-labelledby="bpe-status-trigger">
                            <li role="option" data-value="publish" class="<?php echo $effective_status === 'publish' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="publish"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Published', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visible to everyone on the storefront', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <li role="option" data-value="draft" class="<?php echo $effective_status === 'draft' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="draft"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Draft', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Not visible on the storefront', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <li role="option" data-value="private" class="<?php echo $effective_status === 'private' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="private"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Private', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visible only to admins and editors', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <li role="option" data-value="password" class="<?php echo $effective_status === 'password' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="password"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Password protected', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visitors must enter a password to view', 'brikpanel'); ?></small>
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div class="brikpanel-pe-password-inline <?php echo $is_password ? 'is-visible' : ''; ?>" id="bpe-password-wrap">
                        <input type="text" id="bpe-post-password" value="<?php echo esc_attr($data['post_password']); ?>" placeholder="<?php esc_attr_e('Password...', 'brikpanel'); ?>">
                    </div>
                    <!-- Catalog Visibility (mini dropdown) -->
                    <?php
                    $cv_labels = [
                        'visible' => __('Shop & search', 'brikpanel'),
                        'catalog' => __('Shop only', 'brikpanel'),
                        'search'  => __('Search only', 'brikpanel'),
                        'hidden'  => __('Hidden', 'brikpanel'),
                    ];
                    $cv_val = $data['catalog_visibility'];
                    ?>
                    <div class="brikpanel-pe-catvis-wrap" id="bpe-catvis-wrap">
                        <button type="button" class="brikpanel-pe-catvis-trigger" id="bpe-catvis-trigger" aria-haspopup="listbox" aria-expanded="false">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <span class="brikpanel-pe-catvis-label"><?php echo esc_html($cv_labels[$cv_val] ?? $cv_labels['visible']); ?></span>
                            <svg class="brikpanel-pe-catvis-chevron" width="10" height="10" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <input type="hidden" id="bpe-catalog-visibility" name="catalog_visibility" value="<?php echo esc_attr($cv_val); ?>">
                        <ul class="brikpanel-pe-catvis-menu" role="listbox" id="bpe-catvis-menu">
                            <li class="brikpanel-pe-catvis-menu-title"><?php esc_html_e('Catalog visibility', 'brikpanel'); ?></li>
                            <?php foreach ($cv_labels as $val => $label) : ?>
                            <li role="option" data-value="<?php echo esc_attr($val); ?>" class="<?php echo $cv_val === $val ? 'is-active' : ''; ?>">
                                <?php echo esc_html($label); ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" class="brikpanel-pe-btn primary" id="bpe-publish">
                        <?php echo $is_edit && in_array($data['status'], ['publish', 'private'], true) || $is_password ? esc_html__('Update', 'brikpanel') : esc_html__('Save', 'brikpanel'); ?>
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
                    <?php if ($product_type_selector_enabled && !empty($product_type_options)) : ?>
                    <div class="brikpanel-pe-field brikpanel-pe-type-field">
                        <label for="bpe-product-type"><?php esc_html_e('Product type', 'brikpanel'); ?></label>
                        <select id="bpe-product-type" class="brikpanel-pe-select">
                            <?php foreach ($product_type_options as $type_key => $type_label) : ?>
                                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($product_type_current, $type_key); ?>><?php echo esc_html($type_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="brikpanel-pe-help-text"><?php esc_html_e('Pick how this product is sold. Third-party plugins (subscriptions, bookings, bundles) register their own types here.', 'brikpanel'); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($wc_extras_position === 'top') echo $wc_extras_card; ?>

                <?php
                // Developer-registered cards, top slot. See the
                // `brikpanel_product_editor_boxes` filter.
                if (function_exists('brikpanel_render_editor_boxes')) {
                    brikpanel_render_editor_boxes('top', (int) $product_id, $product);
                }
                ?>

                <?php if (in_array('images', $visible, true)) : ?>
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
                <?php endif; ?>

                <?php if (in_array('pricing', $visible, true)) : ?>
                <!-- Pricing (hidden when the product has variations — each
                     variation carries its own price/sale schedule) -->
                <div class="brikpanel-pe-card" id="bpe-pricing-card">
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-price"><?php esc_html_e('Price', 'brikpanel'); ?></label>
                            <div class="brikpanel-pe-input-group">
                                <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                <input type="text" id="bpe-price" value="<?php echo esc_attr($reg_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-required="1" data-price="1">
                            </div>
                            <div class="brikpanel-pe-field-error"></div>
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-price"><?php esc_html_e('Sale price', 'brikpanel'); ?></label>
                            <div class="brikpanel-pe-input-group">
                                <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                <input type="text" id="bpe-sale-price" value="<?php echo esc_attr($sale_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-price="1">
                            </div>
                        </div>
                    </div>

                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-from"><?php esc_html_e('Sale start date', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-sale-from" value="<?php echo esc_attr($data['sale_from']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD — optional', 'brikpanel'); ?>" autocomplete="off">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-to"><?php esc_html_e('Sale end date', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-sale-to" value="<?php echo esc_attr($data['sale_to']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD — optional', 'brikpanel'); ?>" autocomplete="off">
                        </div>
                    </div>
                    <p class="brikpanel-pe-help-text"><?php esc_html_e('Schedule your sale in advance — leave the dates empty to start it immediately or keep it running indefinitely.', 'brikpanel'); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($cogs_enabled) : ?>
                <!-- Cost of Goods -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-cogs"><?php esc_html_e('Cost of goods', 'brikpanel'); ?></label>
                        <div class="brikpanel-pe-input-group">
                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                            <input type="text" id="bpe-cogs" value="<?php echo esc_attr($cogs_val); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-price="1">
                        </div>
                        <p class="brikpanel-pe-help-text"><?php esc_html_e('The cost you pay for this product. Used to calculate profit margins.', 'brikpanel'); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                // Render the "Additional product data" card at the position
                // the admin picked in settings. `middle` is the default and
                // keeps the historical layout.
                if ($wc_extras_position === 'middle') {
                    echo $wc_extras_card;
                }
                ?>

                <?php if (in_array('inventory', $visible, true)) : ?>
                <!-- Inventory (hidden when the product has variations — each
                     variation carries its own stock + SKU) -->
                <div class="brikpanel-pe-card" id="bpe-inventory-card">
                    <div class="brikpanel-pe-row brikpanel-pe-row-3">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-stock"><?php esc_html_e('Stock quantity', 'brikpanel'); ?></label>
                            <input type="number" id="bpe-stock" value="<?php echo esc_attr($data['stock_quantity']); ?>" min="0" placeholder="0">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-stock-status"><?php esc_html_e('Stock status', 'brikpanel'); ?></label>
                            <select id="bpe-stock-status" class="brikpanel-pe-select">
                                <option value="instock" <?php selected($data['stock_status'], 'instock'); ?>><?php esc_html_e('In stock', 'brikpanel'); ?></option>
                                <option value="outofstock" <?php selected($data['stock_status'], 'outofstock'); ?>><?php esc_html_e('Out of stock', 'brikpanel'); ?></option>
                                <option value="onbackorder" <?php selected($data['stock_status'], 'onbackorder'); ?>><?php esc_html_e('On backorder', 'brikpanel'); ?></option>
                            </select>
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
                <?php endif; ?>

                <?php if (in_array('category', $visible, true)) : ?>
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
                <?php endif; ?>

                <?php if (in_array('tags', $visible, true)) : ?>
                <!-- Tags -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Tags', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-tag-input-wrap" id="bpe-tags-wrap">
                        <input type="text" id="bpe-tag-input" placeholder="<?php esc_attr_e('Type and press Enter to add a tag...', 'brikpanel'); ?>" autocomplete="off">
                    </div>
                    <div class="brikpanel-pe-tag-suggestions" id="bpe-tag-suggestions"></div>
                </div>
                <?php endif; ?>

                <?php if (in_array('digital', $visible, true)) : ?>
                <!-- Digital Product -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span><?php esc_html_e('Digital product (downloadable)', 'brikpanel'); ?></span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-digital-toggle" <?php checked($data['is_downloadable']); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo $data['is_downloadable'] ? 'open' : ''; ?>" id="bpe-digital-section">
                        <div>
                            <p class="brikpanel-pe-help-text"><?php esc_html_e('Customers will receive a download link after purchase. No physical shipping needed.', 'brikpanel'); ?></p>
                            <div class="brikpanel-pe-downloads" id="bpe-downloads-list"></div>
                            <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-download">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add downloadable file', 'brikpanel'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array('short_desc', $visible, true)) : ?>
                <!-- Short Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field" data-editor-field="short-desc">
                        <label><?php esc_html_e('Short description', 'brikpanel'); ?></label>
                        <div class="brikpanel-pe-editor-toolbar">
                            <button type="button" data-cmd="bold" title="<?php esc_attr_e('Bold', 'brikpanel'); ?>"><strong>B</strong></button>
                            <button type="button" data-cmd="italic" title="<?php esc_attr_e('Italic', 'brikpanel'); ?>"><em>I</em></button>
                            <button type="button" data-cmd="insertUnorderedList" title="<?php esc_attr_e('Bullet list', 'brikpanel'); ?>">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="2" cy="4" r="1.5"/><circle cx="2" cy="8" r="1.5"/><circle cx="2" cy="12" r="1.5"/><rect x="5" y="3" width="10" height="2" rx="1"/><rect x="5" y="7" width="10" height="2" rx="1"/><rect x="5" y="11" width="10" height="2" rx="1"/></svg>
                            </button>
                            <button type="button" data-cmd="html" class="brikpanel-pe-html-toggle" title="<?php esc_attr_e('HTML source', 'brikpanel'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </button>
                        </div>
                        <div class="brikpanel-pe-editor" id="bpe-short-desc" contenteditable="true" data-placeholder="<?php esc_attr_e('Brief product summary...', 'brikpanel'); ?>"><?php echo wp_kses_post($data['short_description']); ?></div>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-short-desc-source" spellcheck="false" hidden><?php echo esc_textarea($data['short_description']); ?></textarea>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array('description', $visible, true)) : ?>
                <!-- Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field" data-editor-field="description">
                        <label><?php esc_html_e('Product description', 'brikpanel'); ?></label>
                        <div class="brikpanel-pe-editor-toolbar">
                            <button type="button" data-cmd="bold" title="<?php esc_attr_e('Bold', 'brikpanel'); ?>"><strong>B</strong></button>
                            <button type="button" data-cmd="italic" title="<?php esc_attr_e('Italic', 'brikpanel'); ?>"><em>I</em></button>
                            <button type="button" data-cmd="insertUnorderedList" title="<?php esc_attr_e('Bullet list', 'brikpanel'); ?>">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="2" cy="4" r="1.5"/><circle cx="2" cy="8" r="1.5"/><circle cx="2" cy="12" r="1.5"/><rect x="5" y="3" width="10" height="2" rx="1"/><rect x="5" y="7" width="10" height="2" rx="1"/><rect x="5" y="11" width="10" height="2" rx="1"/></svg>
                            </button>
                            <button type="button" data-cmd="html" class="brikpanel-pe-html-toggle" title="<?php esc_attr_e('HTML source', 'brikpanel'); ?>">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                            </button>
                        </div>
                        <div class="brikpanel-pe-editor" id="bpe-description" contenteditable="true"><?php echo wp_kses_post($data['description']); ?></div>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-description-source" spellcheck="false" hidden><?php echo esc_textarea($data['description']); ?></textarea>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array('weight', $visible, true)) : ?>
                <!-- Shipping Weight -->
                <div class="brikpanel-pe-card" id="bpe-weight-card">
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
                <?php endif; ?>

                <?php if (in_array('dimensions', $visible, true)) : ?>
                <!-- Shipping Dimensions -->
                <div class="brikpanel-pe-card" id="bpe-dims-card">
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
                <?php endif; ?>

                <?php if (in_array('seo', $visible, true)) :
                    // When a supported SEO plugin is active, surface its full
                    // native metabox inside the BrikPanel SEO card so users
                    // keep every feature (schema, social, redirects, analysis,
                    // readability score, etc.) the plugin ships with. When
                    // no plugin is active, fall back to a unified 5-field form
                    // whose values write to all four SEO plugins at once —
                    // future-proofs the data whichever plugin is installed
                    // later. The admin can also hand-pick the SEO metabox
                    // in the "Additional fields" settings; in that case we
                    // skip the SEO card entirely to avoid rendering twice.
                    $active_seo = self::get_active_seo_plugin();
                    $manual_seo_ids = (array) get_option('brikpanel_pe_selected_metaboxes', []);
                    $seo_in_manual = false;
                    if ($active_seo) {
                        foreach ($active_seo['metabox_ids'] as $mid) {
                            if (in_array($mid, $manual_seo_ids, true)) { $seo_in_manual = true; break; }
                        }
                    }
                    if ($active_seo && !$seo_in_manual) : ?>
                <!-- SEO — rendered by active plugin (<?php echo esc_html($active_seo['label']); ?>) -->
                <div class="brikpanel-pe-card brikpanel-pe-seo-card brikpanel-pe-seo-card--plugin brikpanel-pe-metaboxes-wrap" data-seo-plugin="<?php echo esc_attr($active_seo['slug']); ?>">
                    <label>
                        <?php esc_html_e('SEO', 'brikpanel'); ?>
                        <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($active_seo['label']); ?></span>
                    </label>
                    <div class="brikpanel-pe-seo-preview" id="bpe-seo-preview">
                        <span class="brikpanel-pe-seo-preview-title" id="bpe-seo-preview-title"><?php echo esc_html($data['seo_title'] ?: $data['name'] ?: __('Product title', 'brikpanel')); ?></span>
                        <span class="brikpanel-pe-seo-preview-url"><?php echo esc_html($is_edit ? get_permalink($product_id) : home_url('/product/...')); ?></span>
                        <span class="brikpanel-pe-seo-preview-desc" id="bpe-seo-preview-desc"><?php echo esc_html($data['seo_description'] ?: mb_substr(wp_strip_all_tags($data['short_description'] ?: $data['description']), 0, 160)); ?></span>
                    </div>
                    <div class="brikpanel-pe-seo-plugin-inner">
                        <?php $this->render_seo_plugin_metaboxes((int) $product_id, $active_seo); ?>
                    </div>
                </div>
                    <?php else : ?>
                <!-- SEO — unified fields that save to Yoast, Rank Math, All in
                     One SEO and SEOPress meta keys at once. Shown when none of
                     those plugins are active. -->
                <div class="brikpanel-pe-card brikpanel-pe-seo-card">
                    <label><?php esc_html_e('SEO', 'brikpanel'); ?></label>
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
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-seo-focus-kw"><?php esc_html_e('Focus keyword', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-seo-focus-kw" value="<?php echo esc_attr($data['seo_focus_kw']); ?>" placeholder="<?php esc_attr_e('e.g. cotton t-shirt', 'brikpanel'); ?>">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-seo-canonical"><?php esc_html_e('Canonical URL', 'brikpanel'); ?></label>
                            <input type="url" id="bpe-seo-canonical" value="<?php echo esc_url($data['seo_canonical']); ?>" placeholder="<?php esc_attr_e('Defaults to product permalink', 'brikpanel'); ?>">
                        </div>
                    </div>
                    <div class="brikpanel-pe-toggle-row">
                        <span>
                            <strong><?php esc_html_e('Hide from search engines', 'brikpanel'); ?></strong>
                            <small style="display:block;color:#616161;font-weight:400;"><?php esc_html_e('Adds noindex — the product stays accessible but search engines will not list it.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-seo-noindex" <?php checked(!empty($data['seo_noindex'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <p class="brikpanel-pe-help-text"><?php esc_html_e('These fields are saved to Yoast SEO, Rank Math, All in One SEO and SEOPress simultaneously, so switching SEO plugins never loses your work.', 'brikpanel'); ?></p>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                // Developer-registered cards, middle slot.
                if (function_exists('brikpanel_render_editor_boxes')) {
                    brikpanel_render_editor_boxes('middle', (int) $product_id, $product);
                }
                ?>

                <?php if (in_array('variations', $visible, true)) : ?>
                <!-- Variations -->
                <div class="brikpanel-pe-card" id="bpe-var-card">
                    <div class="brikpanel-pe-toggle-row" id="bpe-var-toggle-row"<?php echo $product_type_selector_enabled ? ' style="display:none"' : ''; ?>>
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
                                        <label><?php esc_html_e('Set all sale prices:', 'brikpanel'); ?></label>
                                        <div class="brikpanel-pe-input-group small">
                                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                            <input type="text" id="bpe-bulk-sale-price" data-price="1">
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
                                                <th class="var-expand-col" aria-hidden="true"></th>
                                                <th><?php esc_html_e('Variation', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Price', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Sale Price', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Sale start', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Sale end', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                                <th><?php esc_html_e('Status', 'brikpanel'); ?></th>
                                                <?php if ($cogs_enabled) : ?><th><?php esc_html_e('COGS', 'brikpanel'); ?></th><?php endif; ?>
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
                <?php endif; ?>

                <?php
                // Third-party metaboxes — now driven by an explicit picker
                // instead of a catch-all "show all" toggle. Also runs for
                // brand-new products (product_id = 0) so users can prefill
                // marketplace / SEO fields before the first save.
                //
                // When an active SEO plugin's metabox is already being
                // surfaced inside the SEO card above, strip its IDs from the
                // hand-picked list so it does not render twice.
                $selected_metaboxes = (array) get_option('brikpanel_pe_selected_metaboxes', []);
                if (in_array('seo', $visible, true) && !empty($active_seo)) {
                    $selected_metaboxes = array_values(array_diff($selected_metaboxes, $active_seo['metabox_ids']));
                }
                if (!empty($selected_metaboxes)) {
                    $this->render_third_party_metaboxes((int) $product_id, $selected_metaboxes);
                }

                if ($wc_extras_position === 'bottom') echo $wc_extras_card;

                // Developer-registered cards, bottom slot.
                if (function_exists('brikpanel_render_editor_boxes')) {
                    brikpanel_render_editor_boxes('bottom', (int) $product_id, $product);
                }
                ?>

            </div><!-- .brikpanel-pe-content -->
        </div><!-- .brikpanel-pe -->
        </div><!-- .wrap -->

        <script>var brikpanelProductData = <?php echo $js_data; ?>;</script>
        <?php
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function get_visible_sections() {
        $default = ['images', 'pricing', 'inventory', 'category', 'tags', 'short_desc', 'description', 'digital', 'weight', 'dimensions', 'seo', 'variations'];
        $visible = get_option('brikpanel_pe_visible_sections');
        if ($visible === false || !is_array($visible)) {
            $visible = $default;
        }
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        /**
         * Filter which built-in BrikPanel editor sections are rendered.
         *
         * @param array $visible    Section slugs currently set to render.
         * @param int   $product_id Product ID being edited (0 for new).
         */
        $visible = apply_filters('brikpanel_editor_visible_sections', $visible, $product_id);
        return is_array($visible) ? $visible : $default;
    }

    /**
     * Render a curated list of 3rd-party product metaboxes inside the simple
     * editor. Only the metabox IDs passed in $selected_ids are emitted.
     *
     * To satisfy SEO plugins that check `get_current_screen()->id === 'product'`
     * (Yoast, Rank Math, AIOSEO, SEOPress) we temporarily swap `$current_screen`
     * to the product edit screen around the callback invocation. This lets
     * them read the expected context so their JS/data layer initialises
     * correctly. Globals are restored immediately afterwards.
     *
     * @param int      $product_id    Post ID being edited.
     * @param string[] $selected_ids  Metabox IDs the admin picked in settings.
     */
    private function render_third_party_metaboxes($product_id, array $selected_ids) {
        $post = get_post($product_id);
        if (!$post || empty($selected_ids)) {
            return;
        }

        // Spoof the edit-product screen + post globals so third-party plugins
        // hooked to `add_meta_boxes` / `admin_head-post.php` see the context
        // they expect. Without this Yoast skips registration entirely.
        global $current_screen, $post_type, $typenow, $pagenow, $wp_meta_boxes;
        $saved = [
            'screen'    => $current_screen,
            'post'      => isset($GLOBALS['post']) ? $GLOBALS['post'] : null,
            'post_type' => isset($GLOBALS['post_type']) ? $GLOBALS['post_type'] : null,
            'typenow'   => isset($GLOBALS['typenow']) ? $GLOBALS['typenow'] : null,
            'pagenow'   => isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : null,
            'boxes'     => isset($wp_meta_boxes['product']) ? $wp_meta_boxes['product'] : null,
        ];
        set_current_screen('product');
        $GLOBALS['post']      = $post;
        $GLOBALS['post_type'] = 'product';
        $GLOBALS['typenow']   = 'product';
        $GLOBALS['pagenow']   = 'post.php';
        $wp_meta_boxes['product'] = [];

        // Ensure Yoast has its metabox class hooked up.
        if (class_exists('WPSEO_Metabox') && empty($GLOBALS['wpseo_metabox'])) {
            $GLOBALS['wpseo_metabox'] = new WPSEO_Metabox();
        }

        // Rank Math: instantiate and call hooks() so its add_meta_boxes
        // listener gets registered. Its Screen::load_screen() checks
        // $pagenow which we've spoofed to 'post.php' above.
        if (class_exists('\\RankMath\\Admin\\Metabox\\Metabox')) {
            try {
                $rm_metabox = new \RankMath\Admin\Metabox\Metabox();
                if (method_exists($rm_metabox, 'hooks')) {
                    $rm_metabox->hooks();
                }
            } catch (\Throwable $e) { /* skip */ }
        }

        // ACF only hooks add_meta_boxes during load-post.php / load-post-new.php,
        // which never fires on the BrikPanel product editor page. Without this
        // bridge every ACF field group targeting `product` is silently dropped.
        if (function_exists('brikpanel_bootstrap_acf_post_metaboxes')) {
            brikpanel_bootstrap_acf_post_metaboxes($post, 'product');
        }

        do_action('add_meta_boxes', 'product', $post);
        do_action('add_meta_boxes_product', $post);

        // Register taxonomy metaboxes the same way wp-admin/edit-form-advanced.php
        // does, so Orderable's Product Labels and similar custom taxonomies
        // attached to `product` join $wp_meta_boxes alongside the plugins that
        // self-register via add_meta_boxes. Their default callbacks live in
        // wp-admin/includes/meta-boxes.php, which our custom admin page never
        // includes — pull it in so the callbacks are resolvable.
        if (!function_exists('post_categories_meta_box')) {
            require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
        }
        if (function_exists('brikpanel_register_product_taxonomy_metaboxes')) {
            brikpanel_register_product_taxonomy_metaboxes($post);
        }

        // Flatten the tree and keep only the user-selected IDs.
        $flat = [];
        foreach (['normal', 'side', 'advanced'] as $context) {
            foreach (['high', 'core', 'default', 'low'] as $priority) {
                if (empty($wp_meta_boxes['product'][$context][$priority])) {
                    continue;
                }
                foreach ($wp_meta_boxes['product'][$context][$priority] as $id => $box) {
                    if (!$box || !in_array($id, $selected_ids, true)) {
                        continue;
                    }
                    $flat[$id] = $box;
                }
            }
        }

        // Preserve the order the admin picked in settings.
        $ordered = [];
        foreach ($selected_ids as $sid) {
            if (isset($flat[$sid])) {
                $ordered[$sid] = $flat[$sid];
            }
        }

        $boxes_html = '';
        foreach ($ordered as $id => $box) {
            ob_start();
            echo '<div class="postbox brikpanel-pe-metabox" id="' . esc_attr($id) . '">';
            echo '<div class="postbox-header"><h2 class="hndle"><span>' . esc_html($box['title']) . '</span></h2></div>';
            echo '<div class="inside brikpanel-pe-metabox-content">';
            try {
                call_user_func($box['callback'], $post, $box);
            } catch (\Throwable $e) {
                echo '<p class="brikpanel-pe-help-text">' . esc_html__('Metabox failed to load.', 'brikpanel') . '</p>';
            }
            echo '</div></div>';
            $boxes_html .= ob_get_clean();
        }

        // Restore state before echoing output so our own page doesn't leak.
        if ($saved['boxes'] !== null) {
            $wp_meta_boxes['product'] = $saved['boxes'];
        } else {
            unset($wp_meta_boxes['product']);
        }
        if ($saved['screen'] instanceof WP_Screen) {
            $current_screen = $saved['screen'];
        }
        $GLOBALS['post']      = $saved['post'];
        $GLOBALS['post_type'] = $saved['post_type'];
        $GLOBALS['typenow']   = $saved['typenow'];
        $GLOBALS['pagenow']   = $saved['pagenow'];

        if ($boxes_html) {
            echo '<div class="brikpanel-pe-card brikpanel-pe-metaboxes-wrap">';
            echo '<label>' . esc_html__('Additional fields', 'brikpanel') . '</label>';
            // Emit ACF's hidden form-data block (_acf_nonce, _acf_post_id,
            // _acf_screen, _acf_changed) so that when the BrikPanel save
            // endpoint forwards this subtree's inputs, ACF's save_post handler
            // can verify the `post` nonce and run acf_save_post() against the
            // correct target post. Without this block ACF silently drops the
            // entire `$_POST['acf']` payload on save.
            if (function_exists('acf_form_data')) {
                acf_form_data([
                    'screen'  => 'post',
                    'post_id' => (int) $product_id,
                ]);
            }
            echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-1"><div id="postbox-container-2" class="postbox-container"><div id="normal-sortables" class="meta-box-sortables ui-sortable">';
            echo $boxes_html;
            echo '</div></div></div></div>';
            echo '</div>';
        }
    }

    /**
     * Detect which of the four supported SEO plugins is active and the
     * metabox IDs it registers on the native product edit screen.
     *
     * Returns:
     *   [
     *     'slug'         => 'yoast'|'rank_math'|'aioseo'|'seopress',
     *     'label'        => human-readable plugin name,
     *     'metabox_ids'  => ['wpseo_meta', ...],
     *   ]
     * or null when none of them is active.
     *
     * Detection is by capability/function/class — not by the plugins-list
     * option — so it works even when the plugin is loaded via a must-use
     * loader or symlinked. Filter `brikpanel_pe_active_seo_plugin` lets
     * site owners override the result (e.g. to force a specific plugin
     * when several are active simultaneously).
     */
    public static function get_active_seo_plugin() {
        $detected = null;

        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Metabox')) {
            $detected = [
                'slug'        => 'yoast',
                'label'       => __('Yoast SEO', 'brikpanel'),
                'metabox_ids' => ['wpseo_meta'],
            ];
        } elseif (class_exists('RankMath') || function_exists('rank_math') || defined('RANK_MATH_VERSION')) {
            $detected = [
                'slug'        => 'rank_math',
                'label'       => __('Rank Math SEO', 'brikpanel'),
                'metabox_ids' => ['rank_math_metabox', 'rank_math_metabox_link_suggestions'],
            ];
        } elseif (function_exists('aioseo') || defined('AIOSEO_FILE') || defined('AIOSEO_VERSION')) {
            $detected = [
                'slug'        => 'aioseo',
                'label'       => __('All in One SEO', 'brikpanel'),
                'metabox_ids' => ['aioseo-settings'],
            ];
        } elseif (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
            $detected = [
                'slug'        => 'seopress',
                'label'       => __('SEOPress', 'brikpanel'),
                'metabox_ids' => ['seopress_cpt', 'seopress_content_analysis'],
            ];
        }

        return apply_filters('brikpanel_pe_active_seo_plugin', $detected);
    }

    /**
     * Render the active SEO plugin's native metabox(es) inline inside the
     * BrikPanel SEO card.
     *
     * Same screen + globals spoof that render_third_party_metaboxes() uses,
     * but wraps each box in a lighter template (no postbox chrome, no
     * "Additional fields" grouping) so the metabox blends into the SEO
     * card. Output markup is emitted inside `.brikpanel-pe-metaboxes-wrap`
     * (set by the caller on the wrapping card), so the save-time JS that
     * scoops `:input[name]` values already picks up the plugin fields and
     * forwards them with the BrikPanel save AJAX payload.
     *
     * Empty-product safe: an auto-draft is created elsewhere before the
     * page renders, so third-party metaboxes always get a real post row
     * to read meta from.
     *
     * @param int   $product_id
     * @param array $active_seo  Output of get_active_seo_plugin().
     */
    private function render_seo_plugin_metaboxes($product_id, array $active_seo) {
        $post = get_post($product_id);
        if (!$post) {
            return;
        }

        // Screen spoof: third-party SEO plugins gate their metabox
        // registration + JS bundle on `pagenow === 'post.php'` and
        // `get_current_screen()->id === 'product'`. Without this, Yoast's
        // metabox class short-circuits and Rank Math's Screen class
        // construction returns null for its internal `$this->screen`.
        global $current_screen, $post_type, $typenow, $pagenow, $wp_meta_boxes;
        $saved = [
            'screen'    => $current_screen,
            'post'      => isset($GLOBALS['post']) ? $GLOBALS['post'] : null,
            'post_type' => isset($GLOBALS['post_type']) ? $GLOBALS['post_type'] : null,
            'typenow'   => isset($GLOBALS['typenow']) ? $GLOBALS['typenow'] : null,
            'pagenow'   => isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : null,
            'boxes'     => isset($wp_meta_boxes['product']) ? $wp_meta_boxes['product'] : null,
        ];
        set_current_screen('product');
        $GLOBALS['post']      = $post;
        $GLOBALS['post_type'] = 'product';
        $GLOBALS['typenow']   = 'product';
        $GLOBALS['pagenow']   = 'post.php';
        $wp_meta_boxes['product'] = [];

        // Yoast — its WPSEO_Metabox class self-registers on construction.
        if ($active_seo['slug'] === 'yoast' && class_exists('WPSEO_Metabox') && empty($GLOBALS['wpseo_metabox'])) {
            $GLOBALS['wpseo_metabox'] = new WPSEO_Metabox();
        }

        // Rank Math — its Metabox::hooks() early-returns when the current
        // screen isn't a post-edit page. We've spoofed pagenow above so
        // instantiating + calling hooks() now registers add_main_metabox().
        if ($active_seo['slug'] === 'rank_math' && class_exists('\\RankMath\\Admin\\Metabox\\Metabox')) {
            try {
                $rm_metabox = new \RankMath\Admin\Metabox\Metabox();
                if (method_exists($rm_metabox, 'hooks')) {
                    $rm_metabox->hooks();
                }
            } catch (\Throwable $e) { /* skip */ }
        }

        do_action('add_meta_boxes', 'product', $post);
        do_action('add_meta_boxes_product', $post);

        // Collect the boxes the plugin registered, keeping only the IDs we
        // expect so stray unrelated metaboxes never slip into the SEO card.
        $render_queue = [];
        foreach (['normal', 'side', 'advanced'] as $context) {
            foreach (['high', 'core', 'default', 'low'] as $priority) {
                if (empty($wp_meta_boxes['product'][$context][$priority])) {
                    continue;
                }
                foreach ($wp_meta_boxes['product'][$context][$priority] as $id => $box) {
                    if (!$box || !in_array($id, $active_seo['metabox_ids'], true)) {
                        continue;
                    }
                    $render_queue[$id] = $box;
                }
            }
        }

        // Preserve the order declared in get_active_seo_plugin() so the
        // primary metabox (the big one) always renders before secondary
        // boxes like Rank Math's link-suggestions panel.
        $ordered = [];
        foreach ($active_seo['metabox_ids'] as $mid) {
            if (isset($render_queue[$mid])) {
                $ordered[$mid] = $render_queue[$mid];
            }
        }

        $boxes_html = '';
        foreach ($ordered as $id => $box) {
            ob_start();
            echo '<div class="brikpanel-pe-seo-plugin-box postbox" id="' . esc_attr($id) . '">';
            echo '<div class="inside">';
            try {
                call_user_func($box['callback'], $post, $box);
            } catch (\Throwable $e) {
                echo '<p class="brikpanel-pe-help-text">' . esc_html__('SEO metabox failed to load.', 'brikpanel') . '</p>';
            }
            echo '</div></div>';
            $boxes_html .= ob_get_clean();
        }

        // Restore globals before echoing — keeps the page render pristine
        // for anything that runs after this helper returns.
        if ($saved['boxes'] !== null) {
            $wp_meta_boxes['product'] = $saved['boxes'];
        } else {
            unset($wp_meta_boxes['product']);
        }
        if ($saved['screen'] instanceof WP_Screen) {
            $current_screen = $saved['screen'];
        }
        $GLOBALS['post']      = $saved['post'];
        $GLOBALS['post_type'] = $saved['post_type'];
        $GLOBALS['typenow']   = $saved['typenow'];
        $GLOBALS['pagenow']   = $saved['pagenow'];

        if ($boxes_html === '') {
            // The plugin is active but refused to register its metabox —
            // usually because the post type is disabled in its settings.
            // Fall back to a helpful message rather than silently printing
            // nothing, which would look like a broken page.
            echo '<p class="brikpanel-pe-help-text">'
                . esc_html(sprintf(
                    /* translators: %s: SEO plugin name */
                    __('%s is installed but did not register its SEO fields for products. Enable SEO for the Product post type in the plugin settings.', 'brikpanel'),
                    $active_seo['label']
                ))
                . '</p>';
            return;
        }

        echo '<div id="poststuff" class="brikpanel-pe-seo-plugin-poststuff"><div id="normal-sortables" class="meta-box-sortables ui-sortable">';
        echo $boxes_html;
        echo '</div></div>';
    }

    /**
     * Enumerate available "Additional product data" sections without emitting
     * their HTML — used by the settings page to populate the multiselect.
     *
     * Returns a keyed array: [section_key => human_label]. Section keys:
     *   - `core:<label_slug>`  — fields that 3rd-party plugins inject into a
     *     native WC product-data tab (General, Inventory, …). We keep the
     *     native tab's label so the selector reads naturally.
     *   - `tab:<panel_id>`     — a standalone custom tab registered via
     *     `woocommerce_product_data_tabs`, keyed by its panel target id.
     *
     * Safe to call during settings render (multi-call cached).
     */
    public static function collect_wc_product_data_sections() {
        static $cache = null;
        if ($cache !== null) return $cache;

        // Bail gracefully when called from the WC settings SAVE path
        // (woocommerce_update_options_brikpanel → brikpanel_settings_fields).
        // That hook fires during `wp_loaded`, BEFORE wp-admin/admin.php has
        // required wp-admin/includes/admin.php — so screen.php,
        // class-wp-screen.php and convert_to_screen() are not yet defined.
        // Firing `woocommerce_product_data_panels` here causes themes/plugins
        // that instantiate a WP_List_Table in that hook (e.g. Woodmart's
        // Frequently Bought Together) to fatal with
        //   "Call to undefined function convert_to_screen()".
        // The save handler (woocommerce_update_options) only needs field ids
        // and types to process $_POST — it never reads the options list — so
        // returning an empty map here is safe. Do NOT assign to $cache: the
        // render path on the post-save redirect must still enumerate fully.
        if (!function_exists('convert_to_screen')
            || !function_exists('set_current_screen')
            || !class_exists('WP_Screen')) {
            return [];
        }

        if (!function_exists('woocommerce_wp_text_input')) {
            if (defined('WC_ABSPATH')) {
                include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
            }
        }

        $core_sub_hooks = self::core_product_data_sub_hooks();
        $out = [];

        // Spoof product context briefly so plugins evaluate their hooks
        // correctly (many read $_GET['page']/$pagenow inside their boot).
        global $post, $thepostid, $product_object, $current_screen, $pagenow;
        $orig = [
            'post' => $post, 'tp' => $thepostid ?? null, 'po' => $product_object ?? null,
            'cs' => $current_screen, 'pn' => $pagenow ?? null,
        ];
        $probe = get_posts([
            'post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
            'no_found_rows' => true, 'suppress_filters' => true,
        ]);
        if (!empty($probe)) {
            $post = $probe[0];
            $thepostid = $post->ID;
            $product_object = wc_get_product($post->ID);
        }
        if (function_exists('set_current_screen')) set_current_screen('product');
        $GLOBALS['pagenow'] = 'post.php';

        // Core tab groups — include if any registered hook returns non-empty HTML.
        foreach ($core_sub_hooks as $label => $hooks) {
            $section = '';
            foreach ($hooks as $hook) {
                if (!has_action($hook)) continue;
                ob_start(); do_action($hook); $section .= trim(ob_get_clean());
            }
            if ($section !== '') {
                $out['core:' . sanitize_key($label)] = $label;
            }
        }

        // Custom panels registered on woocommerce_product_data_panels.
        if (has_action('woocommerce_product_data_panels')) {
            $tabs_meta = apply_filters('woocommerce_product_data_tabs', []);
            $target_to_label = [];
            if (is_array($tabs_meta)) {
                foreach ($tabs_meta as $key => $tab) {
                    $target = isset($tab['target']) ? (string) $tab['target'] : (string) $key;
                    $target_to_label[$target] = isset($tab['label']) ? (string) $tab['label'] : ucfirst((string) $key);
                }
            }
            ob_start(); do_action('woocommerce_product_data_panels');
            $panels_html = trim(ob_get_clean());

            if ($panels_html !== '') {
                $core_targets = self::core_panel_targets();
                $skip_ids = ['marketplace_suggestions'];
                $dom = new \DOMDocument();
                $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="bpe-wrap">' . $panels_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                if ($loaded) {
                    $wrap = $dom->getElementById('bpe-wrap');
                    if ($wrap) {
                        foreach (iterator_to_array($wrap->childNodes) as $node) {
                            if (!($node instanceof \DOMElement)) continue;
                            if (!in_array(strtolower($node->tagName), ['div', 'section'], true)) continue;
                            $id = $node->getAttribute('id');
                            if ($id === '' || in_array($id, $core_targets, true) || in_array($id, $skip_ids, true)) continue;
                            $has_controls = false;
                            foreach (['input', 'select', 'textarea'] as $tag) {
                                if ($node->getElementsByTagName($tag)->length > 0) { $has_controls = true; break; }
                            }
                            if (!$has_controls) continue;
                            $label = $target_to_label[$id] ?? ucfirst(str_replace('_', ' ', $id));
                            $out['tab:' . $id] = $label;
                        }
                    }
                }
            }
        }

        // Restore globals
        $post = $orig['post']; $thepostid = $orig['tp']; $product_object = $orig['po'];
        if ($orig['cs'] instanceof \WP_Screen) $current_screen = $orig['cs'];
        if ($orig['pn'] !== null) $GLOBALS['pagenow'] = $orig['pn'];

        $cache = $out;
        return $out;
    }

    /** Core WC product-data tab targets (panel id's BrikPanel already renders natively). */
    private static function core_panel_targets() {
        return [
            'general_product_data', 'inventory_product_data', 'shipping_product_data',
            'linked_product_data', 'product_attributes', 'variable_product_options',
            'advanced_product_data',
        ];
    }

    /**
     * WC-emitted hooks that render per-variation 3rd-party fields.
     * Each action receives ($loop_index, $variation_data, $variation_post).
     */
    private static function variation_field_hooks() {
        return [
            'woocommerce_variation_options'                   => __('Variation options', 'brikpanel'),
            'woocommerce_variation_options_pricing'           => __('Pricing', 'brikpanel'),
            'woocommerce_variation_options_inventory'         => __('Inventory', 'brikpanel'),
            'woocommerce_variation_options_dimensions'        => __('Shipping & dimensions', 'brikpanel'),
            'woocommerce_variation_options_download'          => __('Downloadable', 'brikpanel'),
            'woocommerce_variation_options_tax'               => __('Tax', 'brikpanel'),
            'woocommerce_product_after_variable_attributes'   => __('After attributes', 'brikpanel'),
        ];
    }

    /**
     * Enumerate which per-variation hooks actually have listeners attached.
     * Used by the settings page so admins only see sections a plugin extended.
     */
    public static function collect_wc_variation_sections() {
        static $cache = null;
        if ($cache !== null) return $cache;

        $out = [];
        foreach (self::variation_field_hooks() as $hook => $label) {
            if (has_action($hook)) {
                $out['varhook:' . $hook] = $label;
            }
        }
        $cache = $out;
        return $out;
    }

    /**
     * Capture per-variation 3rd-party fields for a product.
     *
     * Returns `[ variation_id => html ]` keyed by variation post ID. The HTML
     * preserves the original `name="field[{$loop}]"` attributes that WC and
     * plugins use — at save time we re-emit those into $_POST with the same
     * loop index so `woocommerce_save_product_variation` handlers see the
     * values they expect.
     *
     * @param WC_Product $product   Parent variable product.
     * @param array      $variations Ordered list of variation posts/ids.
     * @return array<int, string>
     */
    private function capture_wc_variation_fields($product, $variations) {
        $selected = (array) get_option('brikpanel_pe_wc_variation_sections', []);
        if (empty($selected) || empty($variations)) return [];

        if (!function_exists('woocommerce_wp_text_input')) {
            include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
        }

        $selected_hooks = [];
        foreach (self::variation_field_hooks() as $hook => $_) {
            if (in_array('varhook:' . $hook, $selected, true)) $selected_hooks[] = $hook;
        }
        if (empty($selected_hooks)) return [];

        global $post, $thepostid, $product_object;
        $orig = [$post, $thepostid ?? null, $product_object ?? null];

        $out = [];
        foreach ($variations as $loop => $variation) {
            $variation_post = get_post($variation);
            if (!$variation_post) continue;

            // Mirror what WC's Meta_Box_Product_Data_Variations::output_variations()
            // sets up before firing these hooks — $post + globals must match
            // the variation being rendered so plugins read correct meta.
            $post = $variation_post;
            $thepostid = $variation_post->ID;
            $product_object = wc_get_product($variation_post->ID);
            $variation_data = array_map(function ($v) {
                return is_array($v) ? $v[0] ?? '' : $v;
            }, get_post_meta($variation_post->ID));

            $html = '';
            foreach ($selected_hooks as $hook) {
                if (!has_action($hook)) continue;
                ob_start();
                do_action($hook, $loop, $variation_data, $variation_post);
                $html .= trim(ob_get_clean());
            }
            if ($html !== '') {
                $out[$variation_post->ID] = $html;
            }
        }

        // Restore globals
        $post = $orig[0]; $thepostid = $orig[1]; $product_object = $orig[2];

        return $out;
    }

    /** Core sub-hooks grouped by native tab label — 3rd parties inject into these. */
    private static function core_product_data_sub_hooks() {
        return [
            __('General', 'brikpanel')         => [
                'woocommerce_product_options_general_product_data',
                'woocommerce_product_options_pricing',
                'woocommerce_product_options_sku',
            ],
            __('Inventory', 'brikpanel')       => ['woocommerce_product_options_inventory_product_data'],
            __('Shipping', 'brikpanel')        => ['woocommerce_product_options_shipping_product_data'],
            __('Linked Products', 'brikpanel') => [
                'woocommerce_product_options_grouping',
                'woocommerce_product_options_related',
            ],
            __('Attributes', 'brikpanel')      => ['woocommerce_product_options_attributes'],
            __('Advanced', 'brikpanel')        => [
                'woocommerce_product_options_advanced',
                'woocommerce_product_options_reviews',
            ],
        ];
    }

    /**
     * Render selected "Additional product data" sections for a product.
     *
     * Sections come from `brikpanel_pe_wc_tabs_selected`; if the option is
     * empty, nothing is emitted (default-off behaviour requested by admins).
     */
    private function capture_wc_product_data_fields($product_id, $product) {
        $selected = (array) get_option('brikpanel_pe_wc_tabs_selected', []);
        if (empty($selected)) return '';

        if (!function_exists('woocommerce_wp_text_input')) {
            include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
        }

        global $post, $thepostid, $product_object;
        $orig_post    = $post;
        $orig_postid  = $thepostid ?? null;
        $orig_prodobj = $product_object ?? null;

        $post            = get_post($product_id);
        $thepostid       = $product_id;
        $product_object  = $product;

        $core_targets   = self::core_panel_targets();
        $core_sub_hooks = self::core_product_data_sub_hooks();
        $output = '';

        // Core sub-hook sections — keyed `core:<label_slug>`.
        foreach ($core_sub_hooks as $label => $hooks) {
            $key = 'core:' . sanitize_key($label);
            if (!in_array($key, $selected, true)) continue;

            $section = '';
            foreach ($hooks as $hook) {
                if (!has_action($hook)) continue;
                ob_start();
                do_action($hook);
                $html = trim(ob_get_clean());
                if ($html !== '') $section .= $html;
            }
            if ($section !== '') {
                $output .= '<div class="brikpanel-pe-wc-tab-group" data-tab="' . esc_attr($label) . '">'
                    . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html($label) . '</h4>'
                    . $section
                    . '</div>';
            }
        }

        // Custom 3rd-party panels — keyed `tab:<panel_id>`.
        if (has_action('woocommerce_product_data_panels')) {
            ob_start();
            do_action('woocommerce_product_data_panels');
            $panels_html = trim(ob_get_clean());

            if ($panels_html !== '') {
                $tabs_meta = apply_filters('woocommerce_product_data_tabs', []);
                $target_to_label = [];
                if (is_array($tabs_meta)) {
                    foreach ($tabs_meta as $key => $tab) {
                        $target = isset($tab['target']) ? (string) $tab['target'] : (string) $key;
                        $target_to_label[$target] = isset($tab['label']) ? (string) $tab['label'] : ucfirst((string) $key);
                    }
                }

                $dom = new \DOMDocument();
                $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="bpe-wrap">' . $panels_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

                if ($loaded) {
                    $wrap = $dom->getElementById('bpe-wrap');
                    if ($wrap) {
                        $skip_ids = ['marketplace_suggestions'];
                        foreach (iterator_to_array($wrap->childNodes) as $node) {
                            if (!($node instanceof \DOMElement)) continue;
                            if (!in_array(strtolower($node->tagName), ['div', 'section'], true)) continue;

                            $id = $node->getAttribute('id');
                            if ($id === '' || in_array($id, $core_targets, true) || in_array($id, $skip_ids, true)) continue;

                            $tab_key = 'tab:' . $id;
                            if (!in_array($tab_key, $selected, true)) continue;

                            $has_controls = false;
                            foreach (['input', 'select', 'textarea', 'button'] as $tag) {
                                if ($node->getElementsByTagName($tag)->length > 0) { $has_controls = true; break; }
                            }
                            if (!$has_controls) continue;

                            $inner = '';
                            foreach ($node->childNodes as $child) {
                                $inner .= $dom->saveHTML($child);
                            }
                            if (trim($inner) === '') continue;

                            $label = $target_to_label[$id] ?? ucfirst(str_replace('_', ' ', $id));
                            $output .= '<div class="brikpanel-pe-wc-tab-group" data-tab="' . esc_attr($label) . '">'
                                . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html($label) . '</h4>'
                                . $inner
                                . '</div>';
                        }
                    }
                }
            }
        }

        // Restore globals
        $post           = $orig_post;
        $thepostid      = $orig_postid;
        $product_object = $orig_prodobj;

        return $output;
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
            'status'            => 'publish',
            'is_variable'       => false,
            'product_type'      => 'simple',
            'attributes'        => [],
            'variations'        => [],
            'gallery'           => [],
            'seo_title'         => '',
            'seo_description'   => '',
            'is_downloadable'   => false,
            'downloads'         => [],
            'tags'              => [],
            'stock_status'      => 'instock',
            'cogs_value'        => '',
            'sale_from'         => '',
            'sale_to'           => '',
            'seo_focus_kw'      => '',
            'seo_canonical'     => '',
            'seo_noindex'       => false,
            'post_password'     => '',
            'catalog_visibility' => 'visible',
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
            // Surface only variation attributes in the editor; non-variation
            // attributes (product specs like "Brand", "Material") are managed
            // elsewhere and would be wrongly flipped to variation=true on save
            // if we loaded them here. Position is respected by WC's own
            // get_attributes() sort, so DOM order = position order.
            foreach ($product->get_attributes() as $attr) {
                if (!$attr->get_variation()) continue;

                $attr_name = $attr->get_name();
                // Some legacy products store the taxonomy slug as the name
                // without setting the attribute id. Treat anything whose name
                // resolves to a real `pa_*` taxonomy as a taxonomy attribute.
                $is_tax = $attr->is_taxonomy() || (is_string($attr_name) && strpos($attr_name, 'pa_') === 0 && taxonomy_exists($attr_name));

                if ($is_tax) {
                    $taxonomy = $attr_name;
                    $values = [];
                    // get_terms against the attached product first so pre-saved
                    // relationships survive; fall back to get_options() when
                    // terms are stored as ids/slugs on the attribute itself.
                    $assigned_terms = wp_get_post_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
                    if (!is_wp_error($assigned_terms) && !empty($assigned_terms)) {
                        foreach ($assigned_terms as $term) {
                            $values[] = $term->name;
                        }
                    } else {
                        foreach ((array) $attr->get_options() as $opt) {
                            $term = is_numeric($opt) ? get_term((int) $opt, $taxonomy) : get_term_by('slug', $opt, $taxonomy);
                            $values[] = ($term && !is_wp_error($term)) ? $term->name : $opt;
                        }
                    }
                    $display_name = wc_attribute_label($taxonomy);
                } else {
                    $taxonomy = '';
                    $display_name = $attr_name;
                    $values = $attr->get_options();
                }

                $attributes_data[] = [
                    'name'     => $display_name,
                    'values'   => $values,
                    'taxonomy' => $taxonomy,
                ];
            }

            foreach ($product->get_children() as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) continue;

                // WC stores taxonomy variation attributes as term slugs, but
                // the editor compares against term NAMES (what the tag group
                // displays). Translate slug → name for taxonomy keys so the
                // variation table row matches the selected attribute value.
                $var_attrs = [];
                foreach ($variation->get_attributes() as $key => $val) {
                    if (strpos($key, 'pa_') === 0 && $val !== '' && taxonomy_exists($key)) {
                        $term = get_term_by('slug', $val, $key);
                        $var_attrs[$key] = $term ? $term->name : $val;
                    } else {
                        $var_attrs[$key] = $val;
                    }
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
                    'cogs_value'     => method_exists($variation, 'get_cogs_value') ? ($variation->get_cogs_value() ?? get_post_meta($variation->get_id(), '_brikpanel_cogs', true)) : get_post_meta($variation->get_id(), '_brikpanel_cogs', true),
                    'stock_status'   => $variation->get_stock_status() ?: 'instock',
                    'sale_from'      => $variation->get_date_on_sale_from() ? $variation->get_date_on_sale_from()->date('Y-m-d') : '',
                    'sale_to'        => $variation->get_date_on_sale_to()   ? $variation->get_date_on_sale_to()->date('Y-m-d')   : '',
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

        // Downloadable files
        $downloads = [];
        if ($product->is_downloadable()) {
            foreach ($product->get_downloads() as $download) {
                $downloads[] = [
                    'id'   => $download->get_id(),
                    'name' => $download->get_name(),
                    'file' => $download->get_file(),
                ];
            }
        }

        // SEO data — read from whichever plugin has populated meta.
        // Priority: Yoast → Rank Math → AIOSEO (_aioseo_*) → SEOPress.
        $pid = $product->get_id();
        $seo_title     = '';
        $seo_desc      = '';
        $seo_focus_kw  = '';
        $seo_canonical = '';
        $seo_noindex   = false;
        $seo_sources = [
            [
                'title'     => '_yoast_wpseo_title',
                'desc'      => '_yoast_wpseo_metadesc',
                'focus_kw'  => '_yoast_wpseo_focuskw',
                'canonical' => '_yoast_wpseo_canonical',
                'noindex'   => '_yoast_wpseo_meta-robots-noindex', // '1' = noindex
                'noindex_value' => '1',
            ],
            [
                'title'     => 'rank_math_title',
                'desc'      => 'rank_math_description',
                'focus_kw'  => 'rank_math_focus_keyword',
                'canonical' => 'rank_math_canonical_url',
                'noindex'   => 'rank_math_robots', // array containing 'noindex'
                'noindex_is_array' => true,
            ],
            [
                'title'     => '_aioseo_title',
                'desc'      => '_aioseo_description',
                'focus_kw'  => '_aioseo_keyphrases',
                'canonical' => '_aioseo_canonical_url',
                'noindex'   => '_aioseo_robots_noindex',
                'noindex_value' => '1',
            ],
            [
                'title'     => '_seopress_titles_title',
                'desc'      => '_seopress_titles_desc',
                'focus_kw'  => '_seopress_analysis_target_kw',
                'canonical' => '_seopress_robots_canonical',
                'noindex'   => '_seopress_robots_index', // 'yes' = noindex
                'noindex_value' => 'yes',
            ],
        ];
        foreach ($seo_sources as $src) {
            if ($seo_title === '')     $seo_title     = (string) get_post_meta($pid, $src['title'], true);
            if ($seo_desc === '')      $seo_desc      = (string) get_post_meta($pid, $src['desc'], true);
            if ($seo_focus_kw === '')  $seo_focus_kw  = (string) get_post_meta($pid, $src['focus_kw'], true);
            if ($seo_canonical === '') $seo_canonical = (string) get_post_meta($pid, $src['canonical'], true);
            if ($seo_noindex === false) {
                $raw = get_post_meta($pid, $src['noindex'], true);
                if (!empty($src['noindex_is_array'])) {
                    $seo_noindex = is_array($raw) && in_array('noindex', $raw, true);
                } else {
                    $seo_noindex = ((string) $raw === $src['noindex_value']);
                }
            }
        }

        // AIOSEO stores data in its own custom table, not post meta.
        if (function_exists('aioseo') && class_exists('\\AIOSEO\\Plugin\\Common\\Models\\Post')) {
            try {
                $aio_post = \AIOSEO\Plugin\Common\Models\Post::getPost($pid);
                if ($aio_post && !empty($aio_post->id)) {
                    if ($seo_title === '')     $seo_title     = (string) $aio_post->title;
                    if ($seo_desc === '')      $seo_desc      = (string) $aio_post->description;
                    if ($seo_canonical === '') $seo_canonical = (string) $aio_post->canonical_url;
                    if ($seo_noindex === false && !$aio_post->robots_default && $aio_post->robots_noindex) {
                        $seo_noindex = true;
                    }
                    if ($seo_focus_kw === '' && !empty($aio_post->keyphrases)) {
                        $kp = json_decode($aio_post->keyphrases);
                        if (is_object($kp) && !empty($kp->focus->keyphrase)) {
                            $seo_focus_kw = (string) $kp->focus->keyphrase;
                        }
                    }
                }
            } catch (\Throwable $e) { /* skip */ }
        }

        // WP's auto-draft creation stamps post_title = "AUTO-DRAFT" — we
        // treat that as empty so the field doesn't show it literally.
        $name = $product->get_name() ?? '';
        if ($name === 'AUTO-DRAFT' && $product->get_status() === 'auto-draft') {
            $name = '';
        }

        return [
            'name'              => $name,
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
            // Auto-drafts are an internal transition state — surface them as
            // "draft" so the status dropdown highlights the correct option
            // and the hidden input submits a value the save path accepts.
            'status'            => ($product->get_status() === 'auto-draft') ? 'draft' : $product->get_status(),
            'is_variable'       => $is_variable,
            'product_type'      => (string) $product->get_type(),
            'attributes'        => $attributes_data,
            'variations'        => $variations_data,
            'seo_title'         => $seo_title,
            'seo_description'   => $seo_desc,
            'seo_focus_kw'      => $seo_focus_kw,
            'seo_canonical'     => $seo_canonical,
            'seo_noindex'       => $seo_noindex,
            'is_downloadable'   => $product->is_downloadable(),
            'downloads'         => $downloads,
            'gallery'           => $gallery,
            'tags'              => wp_get_object_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
            'stock_status'      => $product->get_stock_status() ?: 'instock',
            'cogs_value'        => method_exists($product, 'get_cogs_value') ? ($product->get_cogs_value() ?? get_post_meta($product->get_id(), '_brikpanel_cogs', true)) : get_post_meta($product->get_id(), '_brikpanel_cogs', true),
            'sale_from'         => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : '',
            'sale_to'           => $product->get_date_on_sale_to()   ? $product->get_date_on_sale_to()->date('Y-m-d')   : '',
            'post_password'     => get_post_field('post_password', $product->get_id()),
            'catalog_visibility' => $product->get_catalog_visibility() ?: 'visible',
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

        /**
         * Fires before the BrikPanel editor persists a product.
         *
         * Listeners can validate or mutate posted data, or short-circuit the
         * save by calling wp_send_json_error().
         *
         * @param int   $product_id Product ID being saved (0 for new products).
         * @param array $post_data  Raw $_POST payload.
         */
        do_action('brikpanel_before_product_save', $product_id, $_POST);

        $is_variable = !empty($_POST['is_variable']);
        $status      = sanitize_key($_POST['status'] ?? 'draft');
        $post_password = sanitize_text_field($_POST['post_password'] ?? '');

        // Product type resolution:
        //   1. If the JS posted `product_type` (selector is on), validate against
        //      `wc_get_product_types()` and use it.
        //   2. Else derive from the legacy `is_variable` flag.
        // Any plugin-registered type (subscription, variable-subscription,
        // booking, bundle, …) flows through this path unchanged.
        $posted_type = isset($_POST['product_type']) ? sanitize_key($_POST['product_type']) : '';
        $valid_types = function_exists('wc_get_product_types') ? array_keys((array) wc_get_product_types()) : ['simple', 'variable'];
        // BrikPanel's simplified editor has first-class UI only for the base
        // types and for `variable-*`-family types — grouped/external are core
        // types that share the product post type but need different forms,
        // so coerce them back to simple rather than silently breaking.
        if (in_array($posted_type, ['grouped', 'external'], true)) {
            $posted_type = '';
        }
        $product_type = in_array($posted_type, $valid_types, true)
            ? $posted_type
            : ($is_variable ? 'variable' : 'simple');
        $treat_as_variable = function_exists('brikpanel_is_variable_product_type')
            ? brikpanel_is_variable_product_type($product_type)
            : ($product_type === 'variable');
        // Keep $is_variable aligned with the resolved product type so the
        // downstream pricing/variation branches behave consistently.
        $is_variable = $treat_as_variable;

        // "password" is a virtual status — the real WP status is "publish"
        // with a non-empty post_password.
        if ($status === 'password') {
            $status = 'publish';
            // Require a password when saving as password-protected
            if ($post_password === '') {
                $post_password = wp_generate_password(8, false);
            }
        } else {
            // Clear password when switching away from password-protected
            $post_password = '';
        }

        if (!in_array($status, ['draft', 'publish', 'private'], true)) {
            $status = 'draft';
        }

        // If editing existing product, handle type changes
        if ($product_id) {
            $existing = wc_get_product($product_id);
            if (!$existing) {
                wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
            }

            // Any variable-family → non-variable transition: wipe orphan variations.
            $was_variable = function_exists('brikpanel_is_variable_product_type')
                ? brikpanel_is_variable_product_type($existing->get_type())
                : $existing->is_type('variable');
            if ($was_variable && !$treat_as_variable) {
                foreach ($existing->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if ($child) $child->delete(true);
                }
            }
        }

        // Instantiate the correct WC_Product_* subclass for the resolved
        // type. WC_Product_Factory::get_product_classname() honours the
        // `woocommerce_product_class` filter plugins use to register their
        // own classes (e.g. WC_Product_Subscription).
        $classname = class_exists('WC_Product_Factory')
            ? WC_Product_Factory::get_product_classname($product_id, $product_type)
            : ($treat_as_variable ? 'WC_Product_Variable' : 'WC_Product_Simple');
        if (!class_exists($classname)) {
            $classname = $treat_as_variable ? 'WC_Product_Variable' : 'WC_Product_Simple';
        }
        $product = $product_id ? new $classname($product_id) : new $classname();

        // WC_Product::save() persists the `product_type` taxonomy term via
        // the data store, but only for types it recognises natively. Force-
        // set the term after save() so plugin-registered types always end
        // up on the object. We do it here as a pre-save safety net too — if
        // the product already exists and the type changes, the term needs
        // to flip *before* downstream hooks fire their own type-aware logic.
        if ($product_id) {
            wp_set_object_terms($product_id, $product_type, 'product_type', false);
        }

        // Basic data
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if (empty($name)) {
            wp_send_json_error(['message' => __('Product name is required.', 'brikpanel')]);
        }
        $product->set_name($name);
        $product->set_status($status);

        // Catalog visibility
        $catalog_vis = sanitize_key($_POST['catalog_visibility'] ?? 'visible');
        if (!in_array($catalog_vis, ['visible', 'catalog', 'search', 'hidden'], true)) {
            $catalog_vis = 'visible';
        }
        $product->set_catalog_visibility($catalog_vis);

        $product->set_short_description(wp_kses_post(wp_unslash($_POST['short_description'] ?? '')));
        $product->set_description(wp_kses_post(wp_unslash($_POST['description'] ?? '')));

        // Price (simple products only)
        if (!$is_variable) {
            $product->set_regular_price(wc_format_decimal(sanitize_text_field($_POST['regular_price'] ?? '')));
            $sale = sanitize_text_field($_POST['sale_price'] ?? '');
            $product->set_sale_price($sale !== '' ? wc_format_decimal($sale) : '');

            // Sale schedule (YYYY-MM-DD). Empty or invalid string clears the date.
            $sale_from_raw = sanitize_text_field($_POST['sale_from'] ?? '');
            $sale_to_raw   = sanitize_text_field($_POST['sale_to'] ?? '');
            $sale_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_from_raw) ? $sale_from_raw : '';
            $sale_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_to_raw)   ? $sale_to_raw   : '';
            $product->set_date_on_sale_from($sale_from !== '' ? $sale_from : null);
            $product->set_date_on_sale_to($sale_to   !== '' ? $sale_to   : null);
        }

        // Stock
        $stock = isset($_POST['stock_quantity']) && $_POST['stock_quantity'] !== '' ? intval($_POST['stock_quantity']) : null;
        $stock_status = sanitize_key($_POST['stock_status'] ?? 'instock');
        if (!in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $stock_status = 'instock';
        }
        if (!$is_variable && $stock !== null) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock_status);
            $product->set_backorders($stock_status === 'onbackorder' ? 'yes' : 'no');
        } elseif (!$is_variable) {
            $product->set_manage_stock(false);
            $product->set_stock_status($stock_status);
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

        // Downloadable / Digital product
        $is_downloadable = !empty($_POST['is_downloadable']);
        $product->set_downloadable($is_downloadable);
        if ($is_downloadable) {
            $product->set_virtual(true);
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
            $product->set_download_limit(-1); // unlimited
            $product->set_download_expiry(-1); // never expires
        } else {
            $product->set_virtual(false);
            $product->set_downloads([]);
        }

        // COGS — always saved to _brikpanel_cogs for BrikPanel reports.
        // Also writes to WC native COGS (9.5+) when available.
        $cogs_raw = sanitize_text_field( $_POST['cogs_value'] ?? '' );
        $cogs_decimal = $cogs_raw !== '' ? wc_format_decimal( $cogs_raw ) : '';
        if ( $cogs_decimal !== '' ) {
            update_post_meta( $product->get_id(), '_brikpanel_cogs', $cogs_decimal );
        } else {
            delete_post_meta( $product->get_id(), '_brikpanel_cogs' );
        }
        if ( method_exists( $product, 'set_cogs_value' ) ) {
            $product->set_cogs_value( $cogs_decimal !== '' ? $cogs_decimal : null );
        }

        // Save parent product
        $product->save();
        $saved_id = $product->get_id();

        // Post password — must be set via wp_update_post after the WC save
        // because WC's CRUD does not manage post_password.
        wp_update_post([
            'ID'            => $saved_id,
            'post_password' => $post_password,
        ]);

        // WC core's validate_props() auto-syncs stock_status from quantity
        // during save() (stock > 0 forces "instock"), overriding the user's
        // explicit choice. Write the meta directly to keep the requested
        // status and notify listeners.
        if (!$is_variable && isset($_POST['stock_status'])) {
            $requested_status = sanitize_key($_POST['stock_status']);
            if (in_array($requested_status, ['instock', 'outofstock', 'onbackorder'], true)
                && get_post_meta($saved_id, '_stock_status', true) !== $requested_status) {
                update_post_meta($saved_id, '_stock_status', $requested_status);
                wp_cache_delete('product-' . $saved_id, 'products');
                clean_post_cache($saved_id);
                do_action('woocommerce_product_set_stock_status', $saved_id, $requested_status, wc_get_product($saved_id));
            }
        }

        // Rank Math — forwarded from the client via `bpe_rm_*` fields because
        // its React metabox stores user edits in a wp.data store and only
        // persists them via a REST call tied to the classic post-form
        // submit. We're an AJAX handler, so that save never fires; mirror
        // the values into the meta keys Rank Math reads on the frontend.
        if (!empty($_POST['bpe_rm_active'])) {
            $rm_map = [
                'bpe_rm_title'                => 'rank_math_title',
                'bpe_rm_description'          => 'rank_math_description',
                'bpe_rm_canonical_url'        => 'rank_math_canonical_url',
                'bpe_rm_breadcrumb_title'     => 'rank_math_breadcrumb_title',
                'bpe_rm_focus_keyword'        => 'rank_math_focus_keyword',
                'bpe_rm_pillar_content'       => 'rank_math_pillar_content',
                'bpe_rm_facebook_title'       => 'rank_math_facebook_title',
                'bpe_rm_facebook_description' => 'rank_math_facebook_description',
                'bpe_rm_facebook_image'       => 'rank_math_facebook_image',
                'bpe_rm_facebook_image_id'    => 'rank_math_facebook_image_id',
                'bpe_rm_twitter_title'        => 'rank_math_twitter_title',
                'bpe_rm_twitter_description'  => 'rank_math_twitter_description',
                'bpe_rm_twitter_image'        => 'rank_math_twitter_image',
                'bpe_rm_twitter_image_id'     => 'rank_math_twitter_image_id',
                'bpe_rm_twitter_use_facebook' => 'rank_math_twitter_use_facebook',
                'bpe_rm_twitter_card_type'    => 'rank_math_twitter_card_type',
            ];
            foreach ($rm_map as $post_key => $meta_key) {
                if (!isset($_POST[$post_key])) continue;
                $val = wp_unslash($_POST[$post_key]);
                if (in_array($post_key, ['bpe_rm_canonical_url', 'bpe_rm_facebook_image', 'bpe_rm_twitter_image'], true)) {
                    $val = esc_url_raw($val);
                } elseif (in_array($post_key, ['bpe_rm_description', 'bpe_rm_facebook_description', 'bpe_rm_twitter_description'], true)) {
                    $val = sanitize_textarea_field($val);
                } else {
                    $val = sanitize_text_field($val);
                }
                update_post_meta($saved_id, $meta_key, $val);
            }
            if (isset($_POST['bpe_rm_robots'])) {
                $robots = json_decode(wp_unslash($_POST['bpe_rm_robots']), true);
                if (is_array($robots)) {
                    $clean = array_values(array_filter(array_map('sanitize_key', $robots)));
                    update_post_meta($saved_id, 'rank_math_robots', $clean);
                }
            }
            if (isset($_POST['bpe_rm_advanced_robots'])) {
                $adv = json_decode(wp_unslash($_POST['bpe_rm_advanced_robots']), true);
                if (is_array($adv)) {
                    $clean = [];
                    foreach ($adv as $k => $v) {
                        $clean[sanitize_key($k)] = is_numeric($v) ? (int) $v : sanitize_text_field((string) $v);
                    }
                    update_post_meta($saved_id, 'rank_math_advanced_robots', $clean);
                }
            }
        }

        // AIOSEO — the Vue app mirrors all edits into the hidden
        // `#aioseo-post-settings` input as a single JSON payload, which we
        // forward as `aioseo_post_settings`. AIOSEO's own save_post listener
        // (fired below via do_action('save_post_product')) reads it from
        // $_POST and persists to the AIOSEO posts table.
        if (!empty($_POST['aioseo_post_settings']) && !isset($_POST['aioseoPostSettings'])) {
            // AIOSEO's frontend code posts the payload under a CamelCase
            // key on classic editor submit. Re-emit both names so whichever
            // the current AIOSEO version reads will find it.
            $_POST['aioseoPostSettings'] = wp_unslash($_POST['aioseo_post_settings']);
        }

        // SEO meta
        //
        // When one of the four supported SEO plugins is active its own native
        // metabox was rendered inside the SEO card — its fields + nonces are
        // already in $_POST, and its save hook fires later in this handler
        // (see do_action('save_post'/'wp_insert_post') below). Don't blank
        // its meta keys here or we'd clobber values that the plugin's save
        // then has no reason to restore (e.g. unchanged schema settings).
        //
        // When no supported plugin is active we fall back to writing the
        // BrikPanel unified fields to all four meta-key layouts so switching
        // SEO plugins later never loses the data.
        $active_seo_save = self::get_active_seo_plugin();
        if ($active_seo_save === null) {
            $seo_title     = sanitize_text_field(wp_unslash($_POST['seo_title'] ?? ''));
            $seo_desc      = sanitize_textarea_field(wp_unslash($_POST['seo_description'] ?? ''));
            $seo_focus_kw  = sanitize_text_field(wp_unslash($_POST['seo_focus_kw'] ?? ''));
            $seo_canonical = esc_url_raw(wp_unslash($_POST['seo_canonical'] ?? ''));
            $seo_noindex   = !empty($_POST['seo_noindex']);

            // Yoast SEO
            update_post_meta($saved_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($saved_id, '_yoast_wpseo_metadesc', $seo_desc);
            update_post_meta($saved_id, '_yoast_wpseo_focuskw', $seo_focus_kw);
            update_post_meta($saved_id, '_yoast_wpseo_canonical', $seo_canonical);
            update_post_meta($saved_id, '_yoast_wpseo_meta-robots-noindex', $seo_noindex ? '1' : '0');

            // Rank Math
            update_post_meta($saved_id, 'rank_math_title', $seo_title);
            update_post_meta($saved_id, 'rank_math_description', $seo_desc);
            update_post_meta($saved_id, 'rank_math_focus_keyword', $seo_focus_kw);
            update_post_meta($saved_id, 'rank_math_canonical_url', $seo_canonical);
            $rm_robots = (array) get_post_meta($saved_id, 'rank_math_robots', true);
            $rm_robots = array_values(array_filter($rm_robots, function ($v) { return $v !== 'noindex'; }));
            if ($seo_noindex) {
                $rm_robots[] = 'noindex';
            }
            update_post_meta($saved_id, 'rank_math_robots', $rm_robots);

            // SEOPress (meta-key based)
            update_post_meta($saved_id, '_seopress_titles_title', $seo_title);
            update_post_meta($saved_id, '_seopress_titles_desc', $seo_desc);
            update_post_meta($saved_id, '_seopress_analysis_target_kw', $seo_focus_kw);
            update_post_meta($saved_id, '_seopress_robots_canonical', $seo_canonical);
            update_post_meta($saved_id, '_seopress_robots_index', $seo_noindex ? 'yes' : '');
        }

        // Tags
        $tag_names_raw = sanitize_text_field(wp_unslash($_POST['tag_names'] ?? ''));
        if ($tag_names_raw !== '') {
            $tags = array_filter(array_map('trim', explode(',', $tag_names_raw)));
            wp_set_object_terms($saved_id, $tags, 'product_tag');
        } else {
            wp_set_object_terms($saved_id, [], 'product_tag');
        }

        // Process custom-taxonomy assignments posted from third-party metaboxes
        // (e.g. Orderable's Product Labels). WP core normally handles this in
        // edit_post() / wp_insert_post() based on $_POST['tax_input'], but
        // since our save path uses WC_Product::save() we must replicate it.
        if (!empty($_POST['tax_input']) && is_array($_POST['tax_input'])) {
            foreach ($_POST['tax_input'] as $raw_tax => $raw_terms) {
                $tax = sanitize_key($raw_tax);
                if ($tax === '' || in_array($tax, ['product_cat', 'product_tag'], true)) {
                    // product_cat + product_tag are saved explicitly above;
                    // skip to avoid double-writes.
                    continue;
                }
                $taxonomy_obj = get_taxonomy($tax);
                if (!$taxonomy_obj) {
                    continue;
                }
                $cap = $taxonomy_obj->cap->assign_terms ?? 'edit_posts';
                if (!current_user_can($cap)) {
                    continue;
                }
                if (is_taxonomy_hierarchical($tax)) {
                    // Hierarchical taxonomies post an array of term IDs.
                    $term_ids = array_values(array_unique(array_filter(
                        array_map('intval', (array) $raw_terms)
                    )));
                    wp_set_object_terms($saved_id, $term_ids, $tax);
                } else {
                    // Flat taxonomies post either a comma-separated string or
                    // an array of term names/IDs.
                    if (!is_array($raw_terms)) {
                        $raw_terms = explode(',', (string) $raw_terms);
                    }
                    $names = [];
                    foreach ($raw_terms as $name) {
                        $name = trim(sanitize_text_field(wp_unslash((string) $name)));
                        if ($name !== '') {
                            $names[] = $name;
                        }
                    }
                    wp_set_object_terms($saved_id, $names, $tax);
                }
            }
        }

        // Forward third-party metabox data via save_post hooks.
        // SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) hook into
        // `save_post_product` / `save_post` / `wp_insert_post` and read their
        // fields from $_POST + verify a nonce. We already forward the metabox
        // form inputs in the JS payload, so $_POST contains the raw field
        // values and the plugin nonces.
        //
        // We additionally:
        //  - Spoof screen + globals so `get_current_screen()` / `pagenow`
        //    evaluate as if we were on the native post edit screen.
        //  - Set `$_POST['ID']` to the saved product id — Yoast's
        //    save_postdata() bails out if `$_POST['ID']` does not match.
        //  - Set `$_POST['post_ID']` and `$_POST['post_type']` which other
        //    plugins check.
        global $current_screen, $post_type, $typenow, $pagenow;
        $saved_ctx = [
            'screen'    => $current_screen,
            'post'      => isset($GLOBALS['post']) ? $GLOBALS['post'] : null,
            'post_type' => $post_type,
            'typenow'   => isset($GLOBALS['typenow']) ? $GLOBALS['typenow'] : null,
            'pagenow'   => isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : null,
            'post_id'   => isset($_POST['ID']) ? $_POST['ID'] : null,
            'post_pid'  => isset($_POST['post_ID']) ? $_POST['post_ID'] : null,
            'post_pt'   => isset($_POST['post_type']) ? $_POST['post_type'] : null,
        ];
        set_current_screen('product');
        $post_obj = get_post($saved_id);
        $GLOBALS['post']      = $post_obj;
        $GLOBALS['post_type'] = 'product';
        $GLOBALS['typenow']   = 'product';
        $GLOBALS['pagenow']   = 'post.php';
        $_POST['ID']        = $saved_id;
        $_POST['post_ID']   = $saved_id;
        $_POST['post_type'] = 'product';
        // WC's native post.php form posts the product type under the
        // dash-key `product-type` (WC_Meta_Box_Product_Data's <select>).
        // Subscription/booking/bundle plugins gate their save handlers on
        // this exact key (e.g. WC_Subscriptions_Admin::save_subscription_meta
        // bails unless `$_POST['product-type'] === 'subscription'`). BrikPanel
        // posts the same value under `product_type` — mirror it into the
        // WC-native key so those handlers recognise the save.
        $_POST['product-type']    = $product_type;
        $_REQUEST['product-type'] = $product_type;

        // Ensure 3rd-party metabox classes exist so save hooks fire.
        if (class_exists('WPSEO_Metabox') && empty($GLOBALS['wpseo_metabox'])) {
            $GLOBALS['wpseo_metabox'] = new WPSEO_Metabox();
        }
        // AIOSEO skips registering save_post during AJAX (see PostSettings
        // constructor's `wp_doing_ajax()` early-return). Bind the save
        // handler manually so the JSON blob we forwarded persists to the
        // AIOSEO posts table.
        if (function_exists('aioseo') && isset(aioseo()->postSettings)
            && method_exists(aioseo()->postSettings, 'saveSettingsMetabox')
            && !has_action('save_post', [aioseo()->postSettings, 'saveSettingsMetabox'])) {
            add_action('save_post', [aioseo()->postSettings, 'saveSettingsMetabox']);
        }

        do_action('save_post', $saved_id, $post_obj, true);
        do_action('save_post_product', $saved_id, $post_obj, true);
        do_action('edit_post', $saved_id, $post_obj);
        do_action('edit_post_product', $saved_id, $post_obj);
        // Yoast's WPSEO_Metabox::save_postdata() is hooked to wp_insert_post
        // (not save_post). WC_Product::save() already fires wp_insert_post
        // internally, but that happens before our globals/$_POST spoof, so
        // Yoast bails. Re-dispatch the hook now that everything is in place.
        do_action('wp_insert_post', $saved_id, $post_obj, true);

        // WooCommerce Product Data tab extensions (Subscriptions, Memberships,
        // Bookings, shipping add-ons, SEO Product Boxes, etc.) register their
        // save handlers on these WC-specific hooks rather than save_post. Fire
        // them explicitly so fields captured by capture_wc_product_data_fields()
        // round-trip through the 3rd-party plugin's own save pipeline.
        $post_type_key = 'simple';
        if ($product && method_exists($product, 'get_type')) {
            $type = $product->get_type();
            if ($type) $post_type_key = $type;
        }
        // WooCommerce registers WC_Meta_Box_Product_Data::save and
        // WC_Meta_Box_Product_Images::save on woocommerce_process_product_meta
        // at priority 10/20. Those handlers read the *full* WC metabox form
        // from $_POST and reset any property whose field is missing — which
        // would wipe our downloadable/virtual/images/etc. since we post a
        // condensed payload. Unhook them for the duration of the hook so only
        // third-party extensions (which only read their own $_POST fields) run.
        $_wc_data_save = ['WC_Meta_Box_Product_Data', 'save'];
        $_wc_img_save  = ['WC_Meta_Box_Product_Images', 'save'];
        $_removed = [
            'data'   => remove_action('woocommerce_process_product_meta', $_wc_data_save, 10),
            'images' => remove_action('woocommerce_process_product_meta', $_wc_img_save, 20),
        ];
        do_action('woocommerce_process_product_meta', $saved_id, $post_obj);
        if ($_removed['data'])   add_action('woocommerce_process_product_meta', $_wc_data_save, 10, 2);
        if ($_removed['images']) add_action('woocommerce_process_product_meta', $_wc_img_save, 20, 2);
        do_action('woocommerce_process_product_meta_' . $post_type_key, $saved_id);
        // `woocommerce_admin_process_product_object` lets plugins mutate the
        // WC_Product instance itself before the caller persists it. We fetch
        // a fresh product, let listeners mutate, then re-save.
        $refreshed = wc_get_product($saved_id);
        if ($refreshed) {
            do_action('woocommerce_admin_process_product_object', $refreshed);
            $refreshed->save();
        }

        // Restore context
        if ($saved_ctx['screen'] instanceof WP_Screen) {
            $current_screen = $saved_ctx['screen'];
        }
        $GLOBALS['post']      = $saved_ctx['post'];
        $GLOBALS['post_type'] = $saved_ctx['post_type'];
        $GLOBALS['typenow']   = $saved_ctx['typenow'];
        $GLOBALS['pagenow']   = $saved_ctx['pagenow'];
        if ($saved_ctx['post_id'] !== null) {
            $_POST['ID'] = $saved_ctx['post_id'];
        } else {
            unset($_POST['ID']);
        }
        if ($saved_ctx['post_pid'] !== null) {
            $_POST['post_ID'] = $saved_ctx['post_pid'];
        } else {
            unset($_POST['post_ID']);
        }
        if ($saved_ctx['post_pt'] !== null) {
            $_POST['post_type'] = $saved_ctx['post_pt'];
        } else {
            unset($_POST['post_type']);
        }

        // Handle variable product attributes and variations
        if ($is_variable) {
            $this->save_variations($product, $_POST);
        }

        /**
         * Fires after the BrikPanel editor has fully persisted a product,
         * including its variations. Hook in here to sync to external systems,
         * write audit logs, or flush caches.
         *
         * @param int        $saved_id  Saved product ID.
         * @param WC_Product $product   Fresh WC_Product instance (after save).
         * @param array      $post_data Raw $_POST payload used for the save.
         */
        $final_product = wc_get_product($saved_id);
        if ($final_product) {
            do_action('brikpanel_after_product_save', $saved_id, $final_product, $_POST);
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

        // Preserve non-variation attributes (e.g. spec-style "Brand",
        // "Material") that the BrikPanel editor doesn't expose. Without this
        // merge, saving a product from BrikPanel would silently drop every
        // non-variation attribute that WC stores on the product.
        $existing_non_variation = [];
        $existing_variation_keys = [];
        foreach ($product->get_attributes() as $existing_attr) {
            if ($existing_attr->get_variation()) {
                $existing_variation_keys[] = $existing_attr->is_taxonomy()
                    ? $existing_attr->get_name()
                    : strtolower($existing_attr->get_name());
            } else {
                $existing_non_variation[] = $existing_attr;
            }
        }

        // Build WC attributes from submitted data. Order = insertion index =
        // position, so the sequence the user arranged in the UI is preserved.
        $wc_attributes = [];
        $position = 0;

        foreach ($attributes_data as $attr_data) {
            $name   = sanitize_text_field($attr_data['name'] ?? '');
            $values = array_map('sanitize_text_field', (array) ($attr_data['values'] ?? []));
            $taxonomy = sanitize_text_field($attr_data['taxonomy'] ?? '');

            if (empty($name) || empty($values)) continue;

            $attribute = new WC_Product_Attribute();

            if ($taxonomy && taxonomy_exists($taxonomy)) {
                // Global taxonomy attribute — resolve term names to term IDs
                // and wire up id/name so WC stores it as a real taxonomy
                // attribute rather than silently degrading to a custom one.
                $attribute_id = (int) wc_attribute_taxonomy_id_by_name(str_replace('pa_', '', $taxonomy));
                $term_ids = [];
                foreach ($values as $term_name) {
                    $term = get_term_by('name', $term_name, $taxonomy);
                    if (!$term) {
                        $term = get_term_by('slug', sanitize_title($term_name), $taxonomy);
                    }
                    if (!$term) {
                        $inserted = wp_insert_term($term_name, $taxonomy);
                        if (!is_wp_error($inserted) && isset($inserted['term_id'])) {
                            $term_ids[] = (int) $inserted['term_id'];
                        }
                    } else {
                        $term_ids[] = (int) $term->term_id;
                    }
                }
                wp_set_object_terms($product->get_id(), $term_ids, $taxonomy);
                $attribute->set_id($attribute_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
            } else {
                $attribute->set_name($name);
                $attribute->set_options($values);
            }

            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(true);

            $wc_attributes[] = $attribute;
        }

        // Append preserved non-variation attributes after variation ones —
        // positions are renumbered so WC's position-sort stays stable.
        foreach ($existing_non_variation as $preserved) {
            $preserved->set_position($position++);
            $wc_attributes[] = $preserved;
        }

        $product->set_attributes($wc_attributes);
        $product->save();

        // Track submitted variation IDs. `$loop_index` mirrors WC's own
        // variation-metabox loop counter — 3rd-party per-variation save
        // handlers read `$_POST['field_name'][$loop]` so the index here must
        // match the one used when the fields were originally rendered.
        $submitted_ids = [];
        $loop_index = -1;

        foreach ($variations_data as $var_data) {
            $loop_index++;
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

            // Set attributes. For taxonomy attributes (`pa_*`) the variation
            // must store the term SLUG, not the display name — WC's frontend
            // lookup compares against slugs. For custom (non-taxonomy) attrs
            // we keep the sanitized display value so the variation table and
            // frontend render identically.
            $var_attrs = [];
            if (isset($var_data['attributes']) && is_array($var_data['attributes'])) {
                foreach ($var_data['attributes'] as $key => $val) {
                    $key = sanitize_text_field($key);
                    $val = sanitize_text_field($val);
                    if (strpos($key, 'pa_') === 0 && taxonomy_exists($key)) {
                        $term = get_term_by('name', $val, $key);
                        if (!$term) {
                            $term = get_term_by('slug', sanitize_title($val), $key);
                        }
                        $var_attrs[$key] = $term ? $term->slug : sanitize_title($val);
                    } else {
                        $var_attrs[sanitize_title($key)] = $val;
                    }
                }
            }
            $variation->set_attributes($var_attrs);

            // Price
            $var_price = isset($var_data['regular_price']) ? wc_format_decimal(sanitize_text_field($var_data['regular_price'])) : '';
            $variation->set_regular_price($var_price);

            $var_sale = isset($var_data['sale_price']) && $var_data['sale_price'] !== '' ? wc_format_decimal(sanitize_text_field($var_data['sale_price'])) : '';
            $variation->set_sale_price($var_sale);

            // Sale schedule dates per variation
            $var_sale_from_raw = isset($var_data['sale_from']) ? sanitize_text_field($var_data['sale_from']) : '';
            $var_sale_to_raw   = isset($var_data['sale_to'])   ? sanitize_text_field($var_data['sale_to'])   : '';
            $var_sale_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $var_sale_from_raw) ? $var_sale_from_raw : '';
            $var_sale_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $var_sale_to_raw)   ? $var_sale_to_raw   : '';
            $variation->set_date_on_sale_from($var_sale_from !== '' ? $var_sale_from : null);
            $variation->set_date_on_sale_to($var_sale_to     !== '' ? $var_sale_to   : null);

            // Stock
            $var_stock = isset($var_data['stock_quantity']) && $var_data['stock_quantity'] !== '' ? intval($var_data['stock_quantity']) : 0;
            $var_stock_status = sanitize_key($var_data['stock_status'] ?? 'instock');
            if (!in_array($var_stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                $var_stock_status = 'instock';
            }
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity($var_stock);
            $variation->set_stock_status($var_stock_status);
            $variation->set_backorders($var_stock_status === 'onbackorder' ? 'yes' : 'no');

            // SKU
            $var_sku = sanitize_text_field($var_data['sku'] ?? '');
            try {
                $variation->set_sku($var_sku);
            } catch (\Exception $e) {
                // Ignore duplicate SKU
            }

            // COGS per variation — always save to _brikpanel_cogs, also WC native if available.
            $var_cogs_raw     = isset( $var_data['cogs_value'] ) ? sanitize_text_field( $var_data['cogs_value'] ) : '';
            $var_cogs_decimal = $var_cogs_raw !== '' ? wc_format_decimal( $var_cogs_raw ) : '';
            if ( $var_cogs_decimal !== '' ) {
                update_post_meta( $variation->get_id(), '_brikpanel_cogs', $var_cogs_decimal );
            } else {
                delete_post_meta( $variation->get_id(), '_brikpanel_cogs' );
            }
            if ( method_exists( $variation, 'set_cogs_value' ) ) {
                $variation->set_cogs_value( $var_cogs_decimal !== '' ? $var_cogs_decimal : null );
            }

            // Images: first = WC thumbnail, rest = custom gallery meta.
            // Filter out any non-image attachments the client may have sent
            // (defensive: the media picker is image-only, but validating here
            // avoids persisting broken references if the client is tampered).
            $var_image_ids = [];
            if (isset($var_data['image_ids']) && is_array($var_data['image_ids'])) {
                foreach ($var_data['image_ids'] as $aid) {
                    $aid = intval($aid);
                    if ($aid > 0 && wp_attachment_is_image($aid)) {
                        $var_image_ids[] = $aid;
                    }
                }
            }
            $variation->set_image_id(!empty($var_image_ids) ? $var_image_ids[0] : 0);

            $variation->save();

            // Gallery meta must be written after save() so new variations have a real ID.
            $var_id_for_meta = $variation->get_id();
            if ($var_id_for_meta) {
                $gallery_ids_for_var = array_slice($var_image_ids, 1);
                if (!empty($gallery_ids_for_var)) {
                    update_post_meta($var_id_for_meta, '_brikpanel_variation_gallery', $gallery_ids_for_var);
                } else {
                    delete_post_meta($var_id_for_meta, '_brikpanel_variation_gallery');
                }
            }

            // WC core's validate_props() resets stock_status back to "instock"
            // whenever stock_qty > 0 during save(). Re-apply the explicit
            // choice via direct meta write — same pattern as simple products.
            $vid = $variation->get_id();
            if ($vid && get_post_meta($vid, '_stock_status', true) !== $var_stock_status) {
                update_post_meta($vid, '_stock_status', $var_stock_status);
                wp_cache_delete('product-' . $vid, 'products');
                clean_post_cache($vid);
                do_action('woocommerce_product_set_stock_status', $vid, $var_stock_status, wc_get_product($vid));
            }

            $submitted_ids[] = $vid;

            // Let 3rd-party plugins persist their per-variation fields. $_POST
            // already has the flattened bracketed values (the client shipped
            // them as `field[<loop>]=...`), so the plugin's handler reads
            // `$_POST['field'][$loop_index]` the same way it does inside WC's
            // native variation metabox save.
            if ($vid) {
                do_action('woocommerce_save_product_variation', $vid, $loop_index);
            }
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

        $term_id = intval($result['term_id']);

        // Re-render the full checklist + parent dropdown so the client
        // DOM mirrors the server render exactly — hierarchical ordering,
        // depth classes, and `—` indent prefixes. The newly created term
        // is auto-checked. We trust the client's current selection list
        // so in-page toggles aren't lost.
        $selected_ids_raw = isset($_POST['selected_ids']) ? (array) $_POST['selected_ids'] : [];
        $selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids_raw))));
        $selected_ids[] = $term_id;

        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        ob_start();
        $this->render_category_checklist($categories, $selected_ids);
        $checklist_html = ob_get_clean();

        ob_start();
        $this->render_category_parent_options($categories);
        $options_html = ob_get_clean();

        wp_send_json_success([
            'term_id'        => $term_id,
            'name'           => $name,
            'parent'         => $parent,
            'checklist_html' => $checklist_html,
            'options_html'   => $options_html,
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
