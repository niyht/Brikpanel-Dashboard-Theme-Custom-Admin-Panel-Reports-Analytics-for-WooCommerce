<?php

if(!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Translation-friendly tab title
add_filter('woocommerce_settings_tabs_array', function ($settings) {
    $settings['brikpanel'] = __('BrikPanel', 'brikpanel');
    return $settings;
}, 50);

/**
 * Enumerate registered WordPress dashboard widgets.
 *
 * Triggers wp_dashboard_setup outside of load-index.php so we can list widget
 * IDs in the settings UI and re-render them inside the BrikPanel dashboard.
 * Returns [ widget_id => [ title, context, priority, callback, args ] ].
 *
 * @return array
 */
function brikpanel_collect_dashboard_widgets() {
    static $cache = null;
    if ( $cache !== null ) {
        return $cache;
    }

    // This function may run in two contexts:
    //   1. Settings page RENDER (woocommerce_settings_tabs_brikpanel) — inside
    //      wp-admin, after admin_init. `wp-admin/includes/admin.php` has been
    //      loaded, which provides template.php / misc.php / screen.php but
    //      NOT dashboard.php (it is normally only loaded by wp-admin/index.php).
    //   2. Settings page SAVE (woocommerce_update_options_brikpanel) — fires
    //      during `wp_loaded`, BEFORE wp-admin/admin.php has loaded any of its
    //      includes. None of the dashboard/screen/template helpers are
    //      defined. We bail out because WC's multiselect save handler does
    //      not validate posted values against the options list, so returning
    //      an empty list is safe. Do NOT static-cache this empty result — the
    //      render path must still be able to enumerate on the next call.
    if ( ! function_exists( 'add_meta_box' )
        || ! function_exists( 'wp_check_php_version' )
        || ! function_exists( 'set_current_screen' )
        || ! class_exists( 'WP_Screen' ) ) {
        return [];
    }

    // dashboard.php holds wp_dashboard_setup() and wp_add_dashboard_widget(),
    // and is normally only required by wp-admin/index.php. Require it on
    // demand so the render path can enumerate widgets on any admin page.
    if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
        require_once ABSPATH . 'wp-admin/includes/dashboard.php';
    }

    $original_screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

    set_current_screen( 'dashboard' );

    global $wp_meta_boxes;
    $snapshot = isset( $wp_meta_boxes['dashboard'] ) ? $wp_meta_boxes['dashboard'] : null;
    $wp_meta_boxes['dashboard'] = [];

    wp_dashboard_setup();

    $widgets = [];
    if ( ! empty( $wp_meta_boxes['dashboard'] ) ) {
        foreach ( $wp_meta_boxes['dashboard'] as $context => $priorities ) {
            foreach ( (array) $priorities as $priority => $boxes ) {
                foreach ( (array) $boxes as $id => $box ) {
                    if ( empty( $box ) || empty( $box['title'] ) || empty( $box['callback'] ) ) {
                        continue;
                    }
                    $widgets[ $id ] = [
                        'title'    => wp_strip_all_tags( $box['title'] ),
                        'context'  => $context,
                        'priority' => $priority,
                        'callback' => $box['callback'],
                        'args'     => isset( $box['args'] ) ? $box['args'] : null,
                    ];
                }
            }
        }
    }

    // Restore previous state so we don't pollute the normal dashboard render.
    if ( $snapshot !== null ) {
        $wp_meta_boxes['dashboard'] = $snapshot;
    } else {
        unset( $wp_meta_boxes['dashboard'] );
    }
    if ( $original_screen instanceof WP_Screen ) {
        set_current_screen( $original_screen );
    }

    $cache = $widgets;
    return $widgets;
}

/**
 * Enumerate metaboxes registered on the product edit screen by 3rd-party
 * plugins (Yoast SEO, Rank Math, AIOSEO, SEOPress, ACF, etc.). Returns a map
 * of [metabox_id => readable_title] suitable for a multiselect option.
 *
 * The function spoofs `$current_screen` to `product`, runs the normal
 * `add_meta_boxes` action chain, collects the resulting $wp_meta_boxes tree,
 * and filters out well-known WP/WooCommerce core boxes so the user only sees
 * opt-in 3rd-party additions.
 *
 * @return array<string,string>
 */
/**
 * Register taxonomy metaboxes for the `product` post type, mirroring the
 * logic WordPress core runs inside wp-admin/edit-form-advanced.php.
 *
 * Core registers one metabox per object-taxonomy directly (not via the
 * `add_meta_boxes` action), so a screen that only fires `add_meta_boxes`
 * — like our spoofed metabox enumeration and our custom product editor —
 * never sees these registrations. Call this after the `add_meta_boxes`
 * action has fired and before reading $wp_meta_boxes.
 *
 * Built-in `product_cat` and `product_tag` are skipped because BrikPanel
 * renders them in dedicated cards.
 *
 * @param WP_Post|object $post Probe or real product post.
 */
function brikpanel_register_product_taxonomy_metaboxes($post) {
    if (!$post) {
        return;
    }
    $taxonomies = get_object_taxonomies('product', 'objects');
    foreach ($taxonomies as $tax_name => $taxonomy) {
        if (empty($taxonomy->show_ui) || false === $taxonomy->meta_box_cb) {
            continue;
        }
        if (in_array($tax_name, ['product_cat', 'product_tag'], true)) {
            continue;
        }
        // Brand has its own first-class editor section; skip its auto-metabox
        // so the admin never sees a duplicate "Brands" box in the picker.
        if (function_exists('brikpanel_pe_brand_taxonomy') && $tax_name === brikpanel_pe_brand_taxonomy() && brikpanel_pe_brand_taxonomy() !== '') {
            continue;
        }
        $label = isset($taxonomy->labels->name) ? $taxonomy->labels->name : $tax_name;
        $box_id = is_taxonomy_hierarchical($tax_name)
            ? $tax_name . 'div'
            : 'tagsdiv-' . $tax_name;
        add_meta_box(
            $box_id,
            $label,
            $taxonomy->meta_box_cb,
            null,
            'side',
            'core',
            [
                'taxonomy'               => $tax_name,
                '__back_compat_meta_box' => true,
            ]
        );
    }
}

function brikpanel_collect_product_metaboxes() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    // Bail gracefully during wp_loaded saves — wp-admin includes are not yet
    // available at that hook, and WC's multiselect save path does not need
    // the options list to validate posted values.
    if (!function_exists('add_meta_box')
        || !function_exists('wp_check_php_version')
        || !function_exists('set_current_screen')
        || !class_exists('WP_Screen')) {
        return [];
    }

    // Yoast + similar gatekeepers check pagenow, so the filters from the
    // product editor class only fire if we hijack the request early. Since
    // this runs during settings page render (not save), we temporarily
    // spoof screen + fake a product post to get an accurate list.
    $saved_screen    = function_exists('get_current_screen') ? get_current_screen() : null;
    $saved_post      = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
    $saved_post_type = isset($GLOBALS['post_type']) ? $GLOBALS['post_type'] : null;

    set_current_screen('product');

    // Pick any real product so 3rd-party plugins get a post they can inspect.
    $probe = get_posts([
        'post_type'        => 'product',
        'posts_per_page'   => 1,
        'post_status'      => 'any',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ]);
    $probe_post = !empty($probe) ? $probe[0] : null;
    if (!$probe_post) {
        // Fallback: stub post object so add_meta_boxes has something to chew on.
        $probe_post = (object) ['ID' => 0, 'post_type' => 'product', 'post_status' => 'publish'];
    }
    $GLOBALS['post']      = $probe_post;
    $GLOBALS['post_type'] = 'product';

    // Snapshot existing metabox registrations so our enumeration does not
    // bleed into the real product edit screen later in this request.
    global $wp_meta_boxes;
    $snapshot = isset($wp_meta_boxes['product']) ? $wp_meta_boxes['product'] : null;
    $wp_meta_boxes['product'] = [];

    // Temporarily lie about pagenow so SEO plugin Metabox classes that check
    // `$pagenow === 'post.php'` during bootstrap register their hooks. This
    // is scoped to this function call only.
    $saved_pagenow         = isset($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : null;
    $GLOBALS['pagenow']    = 'post.php';

    // Force Yoast to register its metabox even though our pagenow is admin.php
    $yoast_filter_added = false;
    if (!has_filter('wpseo_always_register_metaboxes_on_admin', '__return_true')) {
        add_filter('wpseo_always_register_metaboxes_on_admin', '__return_true');
        $yoast_filter_added = true;
    }

    // Ensure Yoast's metabox class is instantiated if it was skipped earlier.
    if (class_exists('WPSEO_Metabox') && empty($GLOBALS['wpseo_metabox'])) {
        $GLOBALS['wpseo_metabox'] = new WPSEO_Metabox();
    }

    // Rank Math — instantiate its metabox class directly and call hooks().
    // Its Screen::load_screen() checks $pagenow, which we've just spoofed.
    if (class_exists('\\RankMath\\Admin\\Metabox\\Metabox')) {
        try {
            $rm_metabox = new \RankMath\Admin\Metabox\Metabox();
            if (method_exists($rm_metabox, 'hooks')) {
                $rm_metabox->hooks();
            }
        } catch (\Throwable $e) { /* skip */ }
    }

    // All in One SEO — its metabox is registered via add_meta_boxes hook,
    // typically without a pagenow gate, so the default do_action call below
    // picks it up without extra bootstrapping.

    // SEOPress — similar, registers via add_meta_boxes with screen check
    // inside callback. Nothing extra needed here.

    // ACF only hooks add_meta_boxes during load-post.php / load-post-new.php,
    // which never fires on the BrikPanel settings page. Bridge it explicitly
    // so ACF field groups targeting `product` become pickable here.
    if (function_exists('brikpanel_bootstrap_acf_post_metaboxes')) {
        brikpanel_bootstrap_acf_post_metaboxes($probe_post, 'product');
    }

    do_action('add_meta_boxes', 'product', $probe_post);
    do_action('add_meta_boxes_product', $probe_post);

    // WP core registers taxonomy metaboxes inside edit-form-advanced.php (which
    // we never include), *not* via the add_meta_boxes action. That means any
    // custom taxonomy attached to `product` (e.g. Orderable's Product Labels)
    // is invisible to our enumeration unless we replicate that registration
    // here with the same id/context/priority pattern as core. The default
    // callbacks (post_categories_meta_box / post_tags_meta_box) live in
    // wp-admin/includes/meta-boxes.php — include it so is_callable checks and
    // later rendering succeed.
    if (!function_exists('post_categories_meta_box')) {
        require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
    }
    brikpanel_register_product_taxonomy_metaboxes($probe_post);

    $known_core = [
        'woocommerce-product-data', 'woocommerce-product-images',
        'postcustom', 'postexcerpt', 'submitdiv', 'postimagediv',
        'slugdiv', 'commentsdiv', 'commentstatusdiv',
        'tagsdiv-product_tag', 'product_catdiv', 'authordiv',
        'revisionsdiv', 'pageparentdiv', 'trackbacksdiv', 'formatdiv',
    ];

    $out = [];
    if (!empty($wp_meta_boxes['product'])) {
        foreach ($wp_meta_boxes['product'] as $context => $priorities) {
            foreach ((array) $priorities as $priority => $boxes) {
                foreach ((array) $boxes as $id => $box) {
                    if (empty($box) || empty($box['title']) || in_array($id, $known_core, true)) {
                        continue;
                    }
                    $out[$id] = wp_strip_all_tags($box['title']);
                }
            }
        }
    }

    // Restore state.
    if ($snapshot !== null) {
        $wp_meta_boxes['product'] = $snapshot;
    } else {
        unset($wp_meta_boxes['product']);
    }
    if ($yoast_filter_added) {
        remove_filter('wpseo_always_register_metaboxes_on_admin', '__return_true');
    }
    if ($saved_screen instanceof WP_Screen) {
        set_current_screen($saved_screen);
    }
    $GLOBALS['post']      = $saved_post;
    $GLOBALS['post_type'] = $saved_post_type;
    if ($saved_pagenow !== null) {
        $GLOBALS['pagenow'] = $saved_pagenow;
    }

    $cache = $out;
    return $out;
}

// Settings page fields
function brikpanel_settings_fields() {
    $dashboard_widget_options = [];
    foreach ( brikpanel_collect_dashboard_widgets() as $widget_id => $widget ) {
        $dashboard_widget_options[ $widget_id ] = $widget['title'];
    }

    $product_metabox_options = brikpanel_collect_product_metaboxes();

    // Every registered order status (core + custom statuses added by
    // shipment-tracking / returns plugins), keyed exactly as WooCommerce
    // stores them ('wc-…'), so the merchant can map them into the
    // valid-sales and refund buckets the analytics read.
    $order_status_options = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];

    $wc_product_data_sections = class_exists('Brikpanel_Product_Editor')
        ? Brikpanel_Product_Editor::collect_wc_product_data_sections()
        : [];
    $wc_variation_sections = class_exists('Brikpanel_Product_Editor')
        ? Brikpanel_Product_Editor::collect_wc_variation_sections()
        : [];

    $fields = [
        [
            'name' => __('Navigation', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_navigation_title',
        ],
        [
            'name'    => __('Modern navigation', 'brikpanel'),
            'id'      => 'brikpanel_modern_navigation',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WordPress admin menu with a modern, clean sidebar navigation', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Style the default menu', 'brikpanel'),
            'id'      => 'brikpanel_native_menu_styled',
            'type'    => 'checkbox',
            'desc'    => __('When Modern navigation is off, give the standard WordPress admin menu the BrikPanel look. Turn this off to keep the original WordPress menu unchanged.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'brikpanel_nav_customizer',
            'id'   => 'brikpanel_nav_customizer_field',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_navigation_title',
        ],
        [
            'name' => __('Orders List', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_orders_title',
        ],
        [
            'name'    => __('Enhanced orders page', 'brikpanel'),
            'id'      => 'brikpanel_orders_enhancements',
            'type'    => 'checkbox',
            'desc'    => __('Enhance the orders list page with modern search, filters, overview section, and styling', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_orders_title',
        ],
        [
            'name' => __('Order Edit Page', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_order_edit_title',
        ],
        [
            'name'    => __('Modern order edit', 'brikpanel'),
            'id'      => 'brikpanel_modern_order_edit',
            'type'    => 'checkbox',
            'desc'    => __('Clean up the order edit page — hide unnecessary metaboxes and apply modern styling', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_order_edit_title',
        ],
        [
            'name' => __('Products', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_products_title',
        ],
        [
            'name'    => __('Simplified product editor', 'brikpanel'),
            'id'      => 'brikpanel_simple_product_editor',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WooCommerce product editor with a simplified, easy-to-use interface', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Product type selector', 'brikpanel'),
            'id'      => 'brikpanel_enable_product_types',
            'type'    => 'checkbox',
            'desc'    => __('Show a product type dropdown in the simplified editor so you can create subscription, variable subscription, bundle and other types registered by third-party plugins. When off, only simple and variable products can be created. Auto-enabled if a subscription/bookings/bundles plugin is active.', 'brikpanel'),
            'default' => function_exists('brikpanel_has_custom_product_types') && brikpanel_has_custom_product_types() ? 'yes' : 'no',
        ],
        [
            'name'     => __('Visible editor sections', 'brikpanel'),
            'id'       => 'brikpanel_pe_visible_sections_field',
            'type'     => 'brikpanel_section_order',
            'desc'     => __('Toggle a section to show or hide it. Use the arrows to reorder — the product editor renders sections in the order shown here.', 'brikpanel'),
        ],
        [
            'name'    => __('Auto-include ACF field groups', 'brikpanel'),
            'id'      => 'brikpanel_pe_acf_auto',
            'type'    => 'checkbox',
            'desc'    => __('Automatically render Advanced Custom Fields field groups whose Location Rules target products. When off, ACF field groups only appear if added below.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'     => __('Third-party plugin metaboxes', 'brikpanel'),
            'id'       => 'brikpanel_pe_selected_metaboxes',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Pick individual metaboxes registered by other plugins (Yoast SEO, Rank Math, All in One SEO, SEOPress, custom post types, etc.) to bring into the BrikPanel product editor. Only the ones selected here will render. ACF field groups are handled automatically by the setting above and do not need to be picked here.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $product_metabox_options,
            'default'  => [],
        ],
        [
            'name'     => __('Additional product data sections', 'brikpanel'),
            'id'       => 'brikpanel_pe_wc_tabs_selected',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Pick which sections from WooCommerce\'s native "Product data" tabs (General, Inventory, Shipping, and custom tabs added by Subscriptions, Memberships, Bookings, swatches, SEO plugins…) should appear as "Additional product data" inside the BrikPanel editor. Leave empty to hide all — hidden by default.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $wc_product_data_sections,
            'default'  => [],
        ],
        [
            'name'     => __('Additional product data position', 'brikpanel'),
            'id'       => 'brikpanel_pe_wc_tabs_position',
            'type'     => 'select',
            'desc'     => __('Where the "Additional product data" card appears inside the BrikPanel product editor.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => [
                'top'    => __('Top — right under the product name', 'brikpanel'),
                'middle' => __('Middle — between pricing and inventory', 'brikpanel'),
                'bottom' => __('Bottom — end of the editor', 'brikpanel'),
            ],
            'default'  => 'middle',
        ],
        [
            'name'     => __('Additional variation data sections', 'brikpanel'),
            'id'       => 'brikpanel_pe_wc_variation_sections',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Pick which per-variation fields added by third-party plugins (pricing extensions, subscriptions, inventory add-ons…) should appear inside each variation row. Leave empty to hide all — hidden by default.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $wc_variation_sections,
            'default'  => [],
        ],
        [
            'name'    => __('Modern products list', 'brikpanel'),
            'id'      => 'brikpanel_modern_products_list',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default products list with a modern, AJAX-powered interface with inline editing', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Quick Edit drawer fields', 'brikpanel'),
            'id'      => 'brikpanel_qe_field_order',
            'type'    => 'brikpanel_qe_field_order',
            'desc'    => __('Pick which fields appear in the Quick Edit drawer and the order they render in. Toggle a field to show or hide it; use the arrows to reorder. "Simple only" fields are skipped automatically when editing variable products. The featured-product star toggle also controls the matching icons in the products list and the product editor header.', 'brikpanel'),
        ],
        [
            'name'    => __('Open product in new tab', 'brikpanel'),
            'id'      => 'brikpanel_open_edit_in_new_tab',
            'type'    => 'checkbox',
            'desc'    => __('When enabled, clicking a product name in the products list opens the edit page in a new browser tab.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'              => __('Products per page', 'brikpanel'),
            'id'                => 'brikpanel_products_per_page',
            'type'              => 'number',
            'desc'              => __('Number of products to display per page in the products list', 'brikpanel'),
            'default'           => '20',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '5',
                'max'  => '100',
                'step' => '1',
            ],
        ],
        [
            'name'              => __('Categories per page', 'brikpanel'),
            'id'                => 'brikpanel_categories_per_page',
            'type'              => 'number',
            'desc'              => __('Number of categories to display per page', 'brikpanel'),
            'default'           => '20',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '5',
                'max'  => '200',
                'step' => '1',
            ],
        ],
        [
            'name'    => __('Backorder notification option', 'brikpanel'),
            'id'      => 'brikpanel_pe_backorder_notify',
            'type'    => 'checkbox',
            'desc'    => __('Reveal a "Notify customer" choice under the stock status when "On backorder" is selected. Lets you allow backorders silently or with an order-note alert. Applies to simple products and variations.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Multiple images per variation', 'brikpanel'),
            'id'      => 'brikpanel_variation_gallery_enabled',
            'type'    => 'checkbox',
            'desc'    => __('Allow each variation to have its own image gallery (multiple images), and swap the product gallery on the storefront when a variation is selected. When off, each variation is limited to a single image — the WooCommerce default. Existing extra variation images are kept in the database and will reappear if you re-enable this option.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_products_title',
        ],
        [
            'name' => __('Dashboard', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_dashboard_title',
        ],
        [
            'name'    => __('Modern dashboard', 'brikpanel'),
            'id'      => 'brikpanel_modern_dashboard',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WordPress dashboard with a modern, Shopify-inspired analytics dashboard', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Dashboard top bar', 'brikpanel'),
            'id'      => 'brikpanel_dashboard_topbar',
            'type'    => 'checkbox',
            'desc'    => __('Hide the WordPress admin bar on the BrikPanel dashboard and replace it with a BrikPanel-styled e-commerce top bar featuring live revenue, live visitors, global search, quick-create actions, and order notifications.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name' => __('Top bar items', 'brikpanel'),
            'id'   => 'brikpanel_topbar_items_field',
            'type' => 'brikpanel_topbar_items',
            'desc' => __('Choose which controls appear in the BrikPanel top bar. Turn a control off to hide it from the bar.', 'brikpanel'),
        ],
        [
            'name'     => __('Include WordPress dashboard widgets', 'brikpanel'),
            'id'       => 'brikpanel_dashboard_wp_widgets',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Pick which WordPress dashboard widgets (e.g. At a Glance, Activity, Fail2Ban log) should also appear inside the BrikPanel dashboard, styled to match. Widgets are arranged in a responsive 3-column grid.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $dashboard_widget_options,
            'default'  => [],
        ],
        [
            'name'     => __('Dashboard sections', 'brikpanel'),
            'id'       => 'brikpanel_dashboard_sections',
            'type'     => 'brikpanel_dashboard_section_order',
            'desc'     => __('Toggle a section to show or hide it. Use the arrows to reorder — the dashboard renders sections in the order shown here. Hidden sections skip rendering entirely, so their data is not fetched.', 'brikpanel'),
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_dashboard_title',
        ],
        [
            'name' => __('Analytics', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_analytics_title',
            'desc' => __('Decide which order statuses your analytics count. This drives every figure: Revenue, Orders, Average Order Value, Profit, Customer Lifetime Value and the order-rate breakdown. If you use a shipment-tracking or returns plugin that adds custom statuses (for example "Partially shipped", "Delivered" or "Partially refunded"), add them here so those orders stop being left out of your numbers.', 'brikpanel'),
        ],
        [
            'name'     => __('Statuses counted as valid sales', 'brikpanel'),
            'id'       => 'brikpanel_paid_statuses',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Orders in these statuses are counted as real, completed sales across Revenue, Orders, AOV, Profit and Customer Lifetime Value. Defaults to Processing and Completed, which is what WooCommerce itself treats as paid. Leave empty to keep the default.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $order_status_options,
            'default'  => brikpanel_default_paid_statuses(),
        ],
        [
            'name'     => __('Statuses counted as refunds', 'brikpanel'),
            'id'       => 'brikpanel_refunded_statuses',
            'type'     => 'multiselect',
            'class'    => 'wc-enhanced-select',
            'desc'     => __('Orders in these statuses are treated as refunds in the returns and refund-rate figures, and still count toward Customer Lifetime Value (the customer did buy). Defaults to the WooCommerce Refunded status. Leave empty to keep the default.', 'brikpanel'),
            'desc_tip' => true,
            'options'  => $order_status_options,
            'default'  => brikpanel_default_refunded_statuses(),
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_analytics_title',
        ],
        [
            'name' => __('Coupons', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_coupons_title',
            'desc' => __('Modern coupons page settings, plus optional usage restriction fields for power users migrating from the default WooCommerce editor.', 'brikpanel'),
        ],
        [
            'name'    => __('Modern coupons page', 'brikpanel'),
            'id'      => 'brikpanel_modern_coupons',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default coupons list with a modern, AJAX-powered interface', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Restrict to specific products', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_products',
            'type'    => 'checkbox',
            'desc'    => __('Show a "Products" picker so you can limit the coupon to a hand-picked product list.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Exclude specific products', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_exclude_products',
            'type'    => 'checkbox',
            'desc'    => __('Show an "Exclude products" picker so you can disallow specific products even when the rest of the cart qualifies.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Restrict to product categories', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_categories',
            'type'    => 'checkbox',
            'desc'    => __('Show a "Product categories" picker so the coupon only applies to items in the selected categories.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Exclude product categories', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_exclude_categories',
            'type'    => 'checkbox',
            'desc'    => __('Show an "Exclude categories" picker so the coupon never applies to items in the selected categories.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Allowed email addresses', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_emails',
            'type'    => 'checkbox',
            'desc'    => __('Show an "Allowed emails" field that whitelists which billing email addresses can use the coupon.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'name'    => __('Limit usage to X items', 'brikpanel'),
            'id'      => 'brikpanel_coupons_show_limit_items',
            'type'    => 'checkbox',
            'desc'    => __('Show a "Limit usage to X items" field that caps how many matching items the discount applies to per cart.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_coupons_title',
        ],
        [
            'name' => __('Login Page', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_login_title',
        ],
        [
            'name'    => __('Modern login page', 'brikpanel'),
            'id'      => 'brikpanel_modern_login',
            'type'    => 'checkbox',
            'desc'    => __('Replace the default WordPress login page with a clean, modern design and AJAX-powered authentication', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Hide "Powered by WordPress" credit', 'brikpanel'),
            'id'      => 'brikpanel_login_hide_footer_credit',
            'type'    => 'checkbox',
            'desc'    => __('Remove the "— Powered by WordPress" text below the login card', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Force native login submission', 'brikpanel'),
            'id'      => 'brikpanel_login_force_native',
            'type'    => 'checkbox',
            'desc'    => __('Recommended. Submits the login form to wp-login.php natively while keeping the modern design, so any 2FA, SSO, or custom authentication plugin (Wordfence Login Security, Two Factor, WP 2FA, miniOrange, Solid Security, Duo, Rublon, etc.) works as expected. Disable only if you rely on the AJAX login endpoint and are certain no authentication plugin alters the login flow.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_login_title',
        ],
        [
            'name' => __('Admin notices', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_notices_title',
        ],
        [
            'name'    => __('Hide third-party admin notices', 'brikpanel'),
            'id'      => 'brikpanel_hide_foreign_notices',
            'type'    => 'checkbox',
            'desc'    => __('Suppress admin notices from other plugins and themes. BrikPanel\'s own notices always remain visible. Turn this off to restore the default WordPress behaviour.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Also hide error notices', 'brikpanel'),
            'id'      => 'brikpanel_hide_error_notices',
            'type'    => 'checkbox',
            'desc'    => __('By default red error notices stay on screen, since they usually mean something is broken. Enable this to tuck them into the notifications bell too. Only applies while "Hide third-party admin notices" is on.', 'brikpanel'),
            'default' => 'no',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_notices_title',
        ],
        [
            'name' => __('Order notifications', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_order_notify_title',
            'desc' => __('Real-time alerts when a new paid order arrives while you have the WordPress admin open.', 'brikpanel'),
        ],
        [
            'name'    => __('Show new order popup', 'brikpanel'),
            'id'      => 'brikpanel_order_notify_popup',
            'type'    => 'checkbox',
            'desc'    => __('Display a slide-in card in the corner of the admin whenever a new paid order arrives.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Play notification sound', 'brikpanel'),
            'id'      => 'brikpanel_order_notify_sound',
            'type'    => 'checkbox',
            'desc'    => __('Play a short chime to alert you of new paid orders. Some browsers require an interaction with the page before audio can play automatically.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'    => __('Confetti celebration', 'brikpanel'),
            'id'      => 'brikpanel_order_notify_confetti',
            'type'    => 'checkbox',
            'desc'    => __('Briefly burst confetti across the screen to celebrate each new order.', 'brikpanel'),
            'default' => 'yes',
        ],
        [
            'name'              => __('Sound volume', 'brikpanel'),
            'id'                => 'brikpanel_order_notify_volume',
            'type'              => 'number',
            'desc'              => __('Volume of the new-order chime, from 0 (silent) to 100 (loud).', 'brikpanel'),
            'default'           => '70',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '0',
                'max'  => '100',
                'step' => '1',
            ],
        ],
        [
            'name'              => __('Polling interval (seconds)', 'brikpanel'),
            'id'                => 'brikpanel_order_notify_interval',
            'type'              => 'number',
            'desc'              => __('How often the admin checks for new orders. Lower values feel more instant; higher values reduce server load. Min 10, max 300.', 'brikpanel'),
            'default'           => '30',
            'css'               => 'width: 80px;',
            'custom_attributes' => [
                'min'  => '10',
                'max'  => '300',
                'step' => '1',
            ],
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_order_notify_title',
        ],
        [
            'name' => __('Developers', 'brikpanel'),
            'type' => 'title',
            'id'   => 'brk_developers_title',
            'desc' => __('Build on top of BrikPanel with public hooks and filters. The docs below are generated from the plugin source.', 'brikpanel'),
        ],
        [
            'type' => 'brikpanel_dev_docs',
            'id'   => 'brikpanel_dev_docs_field',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_developers_title',
        ],
    ];

    /**
     * Filter the settings fields shown on the BrikPanel WC settings tab.
     *
     * @param array $fields WC settings field definitions.
     */
    return apply_filters('brikpanel_settings_fields', $fields);
}

/**
 * Sub-section list shown at the top of the BrikPanel WC settings tab.
 *
 * Splits the long single-page settings into a Shopify-style sub-nav so
 * admins land on a focused page (one topic at a time). The empty-string
 * key is the default section ("General"). Third parties can append their
 * own section via the standard WC `woocommerce_get_sections_<tab>` filter.
 *
 * @return array<string,string> Map of section id => label.
 */
function brikpanel_settings_get_sections() {
    return apply_filters( 'woocommerce_get_sections_brikpanel', [
        ''              => __( 'General', 'brikpanel' ),
        'navigation'    => __( 'Navigation', 'brikpanel' ),
        'dashboard'     => __( 'Dashboard', 'brikpanel' ),
        'analytics'     => __( 'Analytics', 'brikpanel' ),
        'products'      => __( 'Products', 'brikpanel' ),
        'orders'        => __( 'Orders', 'brikpanel' ),
        'coupons'       => __( 'Coupons', 'brikpanel' ),
        'login'         => __( 'Login page', 'brikpanel' ),
        'notifications' => __( 'Notifications', 'brikpanel' ),
        'import-export' => __( 'Import / Export', 'brikpanel' ),
        'developers'    => __( 'Developers', 'brikpanel' ),
    ] );
}

/**
 * Resolve the current section from `?section=…`, falling back to General
 * when the value is missing or unknown.
 */
function brikpanel_settings_get_current_section() {
    $section  = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
    $sections = brikpanel_settings_get_sections();
    return isset( $sections[ $section ] ) ? $section : '';
}

/**
 * Map every settings-field title id to the section it belongs to. Titles
 * not listed here (e.g. those added by third-party plugins through
 * `brikpanel_settings_fields`) default to the General section.
 *
 * @param string $title_id Title field id (e.g. 'brk_dashboard_title').
 * @return string Section id (empty string for General).
 */
function brikpanel_settings_section_for_title( $title_id ) {
    $map = apply_filters( 'brikpanel_settings_title_section_map', [
        'brk_navigation_title'   => 'navigation',
        'brk_orders_title'       => 'orders',
        'brk_order_edit_title'   => 'orders',
        'brk_products_title'     => 'products',
        'brk_dashboard_title'    => 'dashboard',
        'brk_analytics_title'    => 'analytics',
        'brk_coupons_title'      => 'coupons',
        'brk_login_title'        => 'login',
        'brk_notices_title'      => '',
        'brk_order_notify_title' => 'notifications',
        'brk_developers_title'   => 'developers',
        // Note: brk_appearance_title is mapped to the dedicated 'appearance'
        // section by the Appearance module; the Google Sheets / Ad Platforms
        // modules map their titles to the shared 'integrations' section. They
        // intentionally no longer fall back to General.
    ] );
    return isset( $map[ $title_id ] ) ? (string) $map[ $title_id ] : '';
}

/**
 * Group the flat section list into labelled buckets for the vertical
 * sub-nav. Each group is `id => [ 'label' => string, 'sections' => string[] ]`
 * where `sections` lists section ids (see `brikpanel_settings_get_sections()`)
 * in display order. A group whose label is an empty string renders its links
 * without a heading (used for the top-level "General" landing).
 *
 * Sections that exist but are not claimed by any group (e.g. a brand-new
 * third-party section) are collected into a trailing "More" group by the
 * renderer, so nothing can silently disappear from the nav.
 *
 * @return array<string,array{label:string,sections:string[]}>
 */
function brikpanel_settings_get_section_groups() {
    return apply_filters( 'brikpanel_settings_section_groups', [
        'general'      => [
            'label'    => '',
            'sections' => [ '' ],
        ],
        'interface'    => [
            'label'    => __( 'Interface', 'brikpanel' ),
            'sections' => [ 'navigation', 'dashboard', 'appearance', 'login' ],
        ],
        'store'        => [
            'label'    => __( 'Store', 'brikpanel' ),
            'sections' => [ 'products', 'orders', 'coupons', 'analytics', 'notifications', 'search', 'vendors', 'store-health' ],
        ],
        'integrations' => [
            'label'    => __( 'Integrations', 'brikpanel' ),
            'sections' => [ 'integrations' ],
        ],
        'system'       => [
            'label'    => __( 'System', 'brikpanel' ),
            'sections' => [ 'access', 'import-export', 'developers' ],
        ],
    ] );
}

/**
 * Slice the full settings field list down to the rows that belong to a
 * given section. Section membership is derived from the closest preceding
 * title-row id (see `brikpanel_settings_section_for_title()`); rows that
 * appear outside any title/sectionend block are skipped.
 *
 * @param string $section Section id ('' for General).
 * @return array
 */
function brikpanel_settings_fields_for_section( $section ) {
    $all     = brikpanel_settings_fields();
    $out     = [];
    $current = null;
    foreach ( $all as $field ) {
        if ( isset( $field['type'] ) && $field['type'] === 'title' && ! empty( $field['id'] ) ) {
            $current = brikpanel_settings_section_for_title( $field['id'] );
        }
        if ( $current === $section ) {
            $out[] = $field;
        }
        if ( isset( $field['type'] ) && $field['type'] === 'sectionend' ) {
            $current = null;
        }
    }
    return $out;
}

/**
 * Inline SVG icon for a settings section (16px, stroke = currentColor so it
 * follows the nav link's text color). Unknown ids — e.g. a brand-new
 * third-party section in the "More" group — get a neutral dot so every row
 * stays aligned. Filterable so modules can register their own glyph.
 *
 * @param string $id Section id ('' for General).
 * @return string SVG markup (static, build-time trusted strings only).
 */
function brikpanel_settings_section_icon( $id ) {
    $paths = apply_filters( 'brikpanel_settings_section_icons', [
        ''              => '<line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/>',
        'navigation'    => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'dashboard'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'appearance'    => '<path d="M9.06 11.9l8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.07"/><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"/>',
        'login'         => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'products'      => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'orders'        => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        'coupons'       => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="m15 9-6 6"/><path d="M9 9h.01"/><path d="M15 15h.01"/>',
        'analytics'     => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'notifications' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'search'        => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'vendors'       => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        'store-health'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'integrations'  => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'access'        => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'import-export' => '<path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="m21 8-4-4-4 4"/><path d="M17 4v16"/>',
        'developers'    => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    ] );
    $inner = isset( $paths[ $id ] ) ? $paths[ $id ] : '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="1"/>';
    return '<svg class="bp-nav-ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

/**
 * Build the flat search index the sidebar search box filters client-side.
 * One entry per section (so "import" finds the Import / Export page) plus
 * one entry per individual settings field, with the field's option id as the
 * jump anchor and a lowercased slice of its description for keyword recall.
 *
 * @return array<int,array{label:string,section:string,sectionLabel:string,anchor:string,k:string}>
 */
function brikpanel_settings_build_search_index() {
    $sections = brikpanel_settings_get_sections();
    $index    = [];
    foreach ( $sections as $id => $label ) {
        $index[] = [
            'label'        => $label,
            'section'      => (string) $id,
            'sectionLabel' => '',
            'anchor'       => '',
            'k'            => '',
        ];
    }
    $current = null;
    foreach ( brikpanel_settings_fields() as $field ) {
        if ( isset( $field['type'] ) && $field['type'] === 'title' && ! empty( $field['id'] ) ) {
            $current = brikpanel_settings_section_for_title( $field['id'] );
        }
        if ( isset( $field['type'] ) && $field['type'] === 'sectionend' ) {
            $current = null;
            continue;
        }
        if ( $current === null || ! isset( $field['type'] ) || $field['type'] === 'title' ) {
            continue;
        }
        $name  = isset( $field['name'] ) ? $field['name'] : ( isset( $field['title'] ) ? $field['title'] : '' );
        $label = wp_strip_all_tags( (string) $name );
        if ( $label === '' || empty( $field['id'] ) ) {
            continue;
        }
        $desc = isset( $field['desc'] ) ? wp_strip_all_tags( (string) $field['desc'] ) : '';
        $desc = function_exists( 'mb_strtolower' ) ? mb_strtolower( mb_substr( $desc, 0, 160 ) ) : strtolower( substr( $desc, 0, 160 ) );

        $index[] = [
            'label'        => $label,
            'section'      => (string) $current,
            'sectionLabel' => isset( $sections[ $current ] ) ? $sections[ $current ] : '',
            'anchor'       => (string) $field['id'],
            'k'            => $desc,
        ];
    }
    return apply_filters( 'brikpanel_settings_search_index', $index );
}

/**
 * Render a single sub-nav link for one section: icon + label + optional
 * badge (modules register badges via the `brikpanel_settings_nav_badges`
 * filter, e.g. "Beta" on Integrations).
 */
function brikpanel_settings_render_section_link( $id, $label, $current_section, $badges = [] ) {
    $url     = admin_url( 'admin.php?page=wc-settings&tab=brikpanel' . ( $id !== '' ? '&section=' . sanitize_title( $id ) : '' ) );
    $current = ( (string) $current_section === (string) $id );
    $badge   = isset( $badges[ $id ] ) ? '<span class="bp-nav-badge">' . esc_html( $badges[ $id ] ) . '</span>' : '';
    printf(
        '<li><a href="%s" class="bp-nav-link%s"%s>%s<span class="bp-nav-text">%s</span>%s</a></li>',
        esc_url( $url ),
        $current ? ' current' : '',
        $current ? ' aria-current="page"' : '',
        brikpanel_settings_section_icon( $id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static inline SVG built above.
        esc_html( $label ),
        $badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — label escaped above.
    );
}

/**
 * Render the section sub-nav as a grouped vertical sidebar (Shopify-style)
 * with a search box on top. Sections are bucketed by
 * `brikpanel_settings_get_section_groups()`; any registered section not
 * claimed by a group falls into a trailing "More" group so third-party
 * sections never vanish from the nav. While the user types in the search
 * box, the group list is swapped for a flat result list (see the inline JS
 * on this screen).
 */
function brikpanel_settings_render_section_nav( $current_section ) {
    $sections = brikpanel_settings_get_sections();
    if ( empty( $sections ) ) {
        return;
    }
    $groups  = brikpanel_settings_get_section_groups();
    $badges  = apply_filters( 'brikpanel_settings_nav_badges', [] );
    $claimed = [];

    echo '<nav class="brikpanel-settings-sidebar brikpanel-settings-sections" aria-label="' . esc_attr__( 'BrikPanel settings sections', 'brikpanel' ) . '">';

    // Search box. No `name` attribute: the input lives inside WC's #mainform
    // and must never be serialized into the settings POST. The
    // `wc-settings-prevent-change-event` class is WC's official opt-out from
    // its dirty-form tracking — without it, typing a query arms the
    // "changes you made will be lost" beforeunload guard.
    echo '<div class="bp-nav-search wc-settings-prevent-change-event">';
    echo brikpanel_settings_section_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static inline SVG.
    echo '<input type="search" id="brikpanel-settings-search" placeholder="' . esc_attr__( 'Search settings', 'brikpanel' ) . '"'
        . ' autocomplete="off" spellcheck="false"'
        . ' data-base="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=brikpanel' ) ) . '"'
        . ' aria-label="' . esc_attr__( 'Search settings', 'brikpanel' ) . '" />';
    echo '</div>';
    echo '<ul class="bp-nav-results" id="brikpanel-settings-search-results" hidden></ul>';
    echo '<p class="bp-nav-results-empty" id="brikpanel-settings-search-empty" hidden>' . esc_html__( 'No settings found.', 'brikpanel' ) . '</p>';

    echo '<div class="bp-nav-groups" id="brikpanel-settings-nav-groups">';
    foreach ( $groups as $group ) {
        // Keep only the group's sections that actually exist this request.
        $ids = array_values( array_filter(
            isset( $group['sections'] ) ? (array) $group['sections'] : [],
            static function ( $id ) use ( $sections ) {
                return array_key_exists( $id, $sections );
            }
        ) );
        if ( empty( $ids ) ) {
            continue;
        }
        echo '<div class="bp-nav-group">';
        if ( ! empty( $group['label'] ) ) {
            echo '<div class="bp-nav-group__label">' . esc_html( $group['label'] ) . '</div>';
        }
        echo '<ul class="bp-nav-list">';
        foreach ( $ids as $id ) {
            brikpanel_settings_render_section_link( $id, $sections[ $id ], $current_section, $badges );
            $claimed[ $id ] = true;
        }
        echo '</ul></div>';
    }

    // Any registered section a group did not claim (e.g. a brand-new
    // third-party section) is surfaced under a "More" heading.
    $leftover = array_diff_key( $sections, $claimed );
    if ( ! empty( $leftover ) ) {
        echo '<div class="bp-nav-group">';
        echo '<div class="bp-nav-group__label">' . esc_html__( 'More', 'brikpanel' ) . '</div>';
        echo '<ul class="bp-nav-list">';
        foreach ( $leftover as $id => $label ) {
            brikpanel_settings_render_section_link( $id, $label, $current_section, $badges );
        }
        echo '</ul></div>';
    }
    echo '</div>'; // .bp-nav-groups

    echo '</nav>';

    // Search index for the sidebar box — JSON, parsed by the inline JS.
    // wp_json_encode escapes forward slashes, so "</script>" can't occur.
    echo '<script type="application/json" id="brikpanel-settings-search-index">'
        . wp_json_encode( brikpanel_settings_build_search_index() )
        . '</script>';
}

// Tab content — section nav + only the current section's fields. The full
// `brikpanel_settings_fields()` list is still walked once so the slicer can
// honour 3rd-party fields injected via the public filter.
add_action( 'woocommerce_settings_tabs_brikpanel', function () {
    $current = brikpanel_settings_get_current_section();
    echo '<div class="brikpanel-settings-layout">';
    brikpanel_settings_render_section_nav( $current );
    echo '<div class="brikpanel-settings-section-body" data-section="' . esc_attr( $current ) . '">';

    // The Import/Export section is non-standard — it does not save to options
    // via the usual form-table flow. Hand off rendering to its own module so
    // it can use whatever markup it needs without fighting WC field types.
    if ( $current === 'import-export' && function_exists( 'brikpanel_import_export_render_section' ) ) {
        brikpanel_import_export_render_section();
        echo '</div></div>';
        return;
    }

    // Capture the standard WC field output so each title + description +
    // form-table block can be wrapped into a single visual card. The
    // alternative — emitting cards manually field-by-field — would force us
    // to replicate every WC field type renderer (and miss third-party ones
    // injected via `brikpanel_settings_fields`). Output buffering keeps the
    // wrapper purely cosmetic.
    ob_start();
    woocommerce_admin_fields( brikpanel_settings_fields_for_section( $current ) );
    $html = ob_get_clean();

    // Wrap `<h2>…</h2><p>…</p>*<table class="form-table">…</table>` blocks.
    // The middle group greedily captures everything between the heading and
    // the form-table — that covers the wpautop-wrapped description plus any
    // stray markup a third-party title renderer might emit.
    $html = preg_replace_callback(
        '#(<h2\b[^>]*>.*?</h2>)(.*?)(<table\b[^>]*?\bform-table\b[^>]*>.*?</table>)#s',
        function ( $m ) {
            $title = $m[1];
            $desc  = trim( $m[2] );
            $table = $m[3];

            $header = '<header class="bp-settings-card__header">' . $title;
            if ( $desc !== '' ) {
                $header .= '<div class="bp-settings-card__desc">' . $desc . '</div>';
            }
            $header .= '</header>';

            return '<section class="bp-settings-card">' . $header . $table . '</section>';
        },
        $html
    );

    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — content was produced by woocommerce_admin_fields, which already escapes.
    echo '</div></div>';
} );

// Update tab settings — only the current section's fields are persisted, so
// posting from one section never overwrites unrelated section options with
// their defaults.
add_action( 'woocommerce_update_options_brikpanel', function () {
    $current = brikpanel_settings_get_current_section();
    woocommerce_update_options( brikpanel_settings_fields_for_section( $current ) );
    // Flag a branded "settings saved" toast for the next page load.
    set_transient( 'brikpanel_settings_saved_' . get_current_user_id(), 1, 30 );
} );

/**
 * BrikPanel-branded styling for the WC settings tab. Loaded inline only on
 * `wc-settings&tab=brikpanel` so it costs nothing on every other admin
 * screen and never has to compete with the WC core stylesheet's cascade.
 */
add_action( 'admin_head', function () {
    if ( ! is_admin() ) {
        return;
    }
    if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
        return;
    }
    if ( sanitize_key( $_GET['page'] ) !== 'wc-settings' || sanitize_key( $_GET['tab'] ) !== 'brikpanel' ) {
        return;
    }
    ?>
    <style id="brikpanel-settings-style">
    /* ------------------------------------------------------------------
     * Canvas
     * ------------------------------------------------------------------ */
    /* Guard against any stray element (a WC tab-bar overflow, a sort marker)
       giving <html> a few px of horizontal scroll — clip it so the panel can
       never shift sideways on phones. */
    html { overflow-x: clip; }
    /* WooCommerce's admin.css paints `body.woocommerce_page_wc-settings
       #wpbody-content { background:#fff }`, flooding the whole content area
       white. Our canvas is the grey body and our .wrap lives inside an
       18/22/40px margin frame, so that white leaks through as a strip around
       the panel (most visibly above the settings tab bar). Drop it back to
       transparent so the grey canvas reads uniformly. */
    body.woocommerce_page_wc-settings #wpbody-content { background: transparent; }
    #wpbody-content .wrap {
        margin: 18px 22px 40px 22px;
        padding-left: 0;
    }
    /* WC's admin.css adds a 30px inline padding to #mainform to push the
       settings panel away from the horizontal tab bar's bottom border
       (it lands on padding-right in current WC, left in RTL). We rebuild
       the layout with cards and our own pill subnav, so we zero BOTH sides
       — otherwise the panel is shoved off-centre (a 30px gap on one side)
       which reads as asymmetric on narrow screens. */
    #wpbody-content .wrap form#mainform {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #303030;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
    /* WooCommerce's top settings tab bar (General | Products | … | BrikPanel)
       is content-width and overflows the viewport on phones. Cap it to the
       container and let the tabs scroll horizontally instead of pushing the
       whole page sideways. */
    #wpbody-content .wrap .nav-tab-wrapper {
        max-width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
        flex-wrap: nowrap;
        white-space: nowrap;
    }

    /* ------------------------------------------------------------------
     * Two-column layout — grouped vertical sub-nav + card stack
     * ------------------------------------------------------------------ */
    #mainform .brikpanel-settings-layout {
        display: flex;
        align-items: flex-start;
        gap: 28px;
        margin-top: .5rem;
    }

    /* ------------------------------------------------------------------
     * Grouped vertical sidebar (Shopify-style sections nav)
     * ------------------------------------------------------------------ */
    #mainform .brikpanel-settings-sidebar {
        flex: 0 0 232px;
        width: 232px;
        align-self: flex-start;
        position: sticky;
        top: 50px; /* WP admin bar (32px) + breathing room */
        max-height: calc(100vh - 70px);
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #d6d6d6 transparent;
        padding: 0;
        /* WooCommerce core ships `#mainform nav { margin: 0 -30px 24px }` for
           its own section nav; our sidebar is a <nav> too, so override the
           bleed explicitly (its selector outranks ours without !important). */
        margin: 0 !important;
    }
    #mainform .bp-nav-group { margin: 0 0 1.1rem; }
    #mainform .bp-nav-group:last-child { margin-bottom: 0; }
    #mainform .bp-nav-group__label {
        font-size: .6875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #8a8a8a;
        padding: 0 .65rem;
        margin: 0 0 .35rem;
    }
    #mainform .bp-nav-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    #mainform .bp-nav-list li { margin: 0; padding: 0; }
    #mainform .bp-nav-list li a.bp-nav-link {
        display: flex;
        align-items: center;
        gap: .55rem;
        padding: .4rem .65rem;
        border-radius: .45rem;
        font-size: .8125rem;
        font-weight: 500;
        color: #4a4a4a;
        text-decoration: none;
        line-height: 1.4;
        border: 1px solid transparent;
        transition: background-color .12s ease, color .12s ease;
    }
    #mainform .bp-nav-link svg.bp-nav-ico {
        flex: 0 0 16px;
        color: #8a8a8a;
        transition: color .12s ease;
    }
    #mainform .bp-nav-link:hover svg.bp-nav-ico,
    #mainform .bp-nav-link:focus svg.bp-nav-ico { color: #303030; }
    #mainform .bp-nav-link.current svg.bp-nav-ico { color: #ffffff; }
    #mainform .bp-nav-text {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    #mainform .bp-nav-badge {
        flex: 0 0 auto;
        font-size: .5625rem;
        font-weight: 600;
        letter-spacing: .05em;
        text-transform: uppercase;
        line-height: 1.5;
        padding: 0 .35rem;
        border-radius: 999px;
        background: #f1f1f1;
        color: #616161;
        border: 1px solid #e3e3e3;
    }
    #mainform .bp-nav-link.current .bp-nav-badge {
        background: rgba(255,255,255,.14);
        color: #ffffff;
        border-color: rgba(255,255,255,.25);
    }

    /* Sidebar search box */
    #mainform .bp-nav-search { position: relative; margin: 0 0 .9rem; }
    #mainform .bp-nav-search > svg.bp-nav-ico {
        position: absolute;
        left: .6rem;
        top: 50%;
        transform: translateY(-50%);
        color: #8a8a8a;
        pointer-events: none;
    }
    #mainform .bp-nav-search input[type="search"] {
        width: 100%;
        box-sizing: border-box;
        margin: 0;
        padding: .45rem .6rem .45rem 2rem;
        background: #ffffff;
        border: 1px solid #d6d6d6;
        border-radius: .5rem;
        font-size: .8125rem;
        color: #303030;
        line-height: 1.4;
        min-height: 34px;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
        transition: border-color .12s ease, box-shadow .12s ease;
    }
    #mainform .bp-nav-search input[type="search"]::placeholder { color: #8a8a8a; }
    #mainform .bp-nav-search input[type="search"]:focus {
        border-color: #303030;
        box-shadow: 0 0 0 1px #303030;
        outline: none;
    }

    /* Search result list (replaces the group list while typing) */
    #mainform .bp-nav-results {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 1px;
    }
    #mainform .bp-nav-results li { margin: 0; padding: 0; }
    #mainform .bp-nav-result {
        display: block;
        padding: .45rem .65rem;
        border-radius: .45rem;
        text-decoration: none;
        transition: background-color .12s ease;
    }
    #mainform .bp-nav-result:hover,
    #mainform .bp-nav-result:focus {
        background: #ececec;
        box-shadow: none;
        outline: none;
    }
    #mainform .bp-nav-result__label {
        display: block;
        font-size: .8125rem;
        font-weight: 500;
        color: #303030;
        line-height: 1.35;
    }
    #mainform .bp-nav-result__section {
        display: block;
        font-size: .6875rem;
        color: #8a8a8a;
        margin-top: 1px;
    }
    #mainform .bp-nav-results-empty {
        font-size: .8125rem;
        color: #8a8a8a;
        padding: .45rem .65rem;
        margin: 0;
    }

    /* Flash highlight on the row a search result jumped to */
    @keyframes bpJumpFlash {
        0%, 55% { background-color: #e7e7e7; }
        100%    { background-color: transparent; }
    }
    .bp-settings-card tr.bp-jump-flash > th,
    .bp-settings-card tr.bp-jump-flash > td {
        animation: bpJumpFlash 2.4s ease;
    }
    .bp-settings-card.bp-jump-flash { animation: bpJumpFlash 2.4s ease; }
    #mainform .bp-nav-list li a.bp-nav-link:hover,
    #mainform .bp-nav-list li a.bp-nav-link:focus {
        background: #ececec !important;
        color: #303030 !important;
        box-shadow: none;
        outline: none;
    }
    #mainform .bp-nav-list li a.bp-nav-link.current {
        background: #303030 !important;
        color: #ffffff !important;
        font-weight: 550;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1);
    }
    #mainform .bp-nav-list li a.bp-nav-link.current:hover,
    #mainform .bp-nav-list li a.bp-nav-link.current:focus {
        background: #1a1a1a !important;
        color: #ffffff !important;
    }

    /* ------------------------------------------------------------------
     * Section body — vertical stack of cards (right column)
     * ------------------------------------------------------------------ */
    .brikpanel-settings-section-body {
        flex: 1 1 auto;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: .75rem;
        max-width: 760px;
    }

    /* ------------------------------------------------------------------
     * Card — title + description + fields, all in one container
     * ------------------------------------------------------------------ */
    .bp-settings-card {
        background: #ffffff;
        border: 1px solid #e3e3e3;
        border-radius: .75rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        /* `overflow: visible` so absolutely-positioned popups inside a card
           (e.g. the WC Iris color picker on the Appearance section) can
           extend past the card's rounded bottom edge instead of being
           clipped. Form-table rows have transparent backgrounds, so
           dropping the clip doesn't expose any corner artefacts. */
        overflow: visible;
    }
    /* Make sure the picker layers above following cards / the save bar. */
    .bp-settings-card .iris-picker {
        z-index: 1000;
    }
    .bp-settings-card__header {
        padding: 1rem 1.5rem .75rem;
    }
    .bp-settings-card__header h2 {
        margin: 0 !important;
        padding: 0 !important;
        font-size: .9375rem !important;
        font-weight: 600 !important;
        color: #303030 !important;
        background: transparent !important;
        border: 0 !important;
        letter-spacing: -.005em;
        line-height: 1.4;
    }
    .bp-settings-card__desc {
        margin-top: .25rem;
    }
    .bp-settings-card__desc p {
        margin: 0 !important;
        color: #616161;
        font-size: .8125rem;
        line-height: 1.55;
        max-width: 720px;
    }
    .bp-settings-card__desc p + p {
        margin-top: .35rem !important;
    }

    /* Stray h2/p WC may emit outside our wrapper (e.g. third-party plugins
       not following the title->table convention) — render them as plain
       headings rather than reproducing the legacy "floating title" bug. */
    .brikpanel-settings-section-body > h2 {
        margin: .5rem 0 .25rem !important;
        padding: 0 !important;
        font-size: .9375rem !important;
        font-weight: 600 !important;
        color: #303030 !important;
        background: transparent !important;
        border: 0 !important;
    }
    .brikpanel-settings-section-body > p {
        margin: 0 !important;
        color: #616161;
        font-size: .8125rem;
        line-height: 1.55;
        max-width: 720px;
    }

    /* ------------------------------------------------------------------
     * Form-table inside a card — rows with even spacing & dividers
     * ------------------------------------------------------------------ */
    .bp-settings-card .form-table {
        margin: 0 !important;
        background: transparent !important;
        border: 0 !important;
        box-shadow: none !important;
        padding: 0 !important;
        border-collapse: collapse;
        width: 100%;
    }
    .bp-settings-card .form-table tr > th,
    .bp-settings-card .form-table tr > td {
        border-top: 1px solid #f1f1f1;
    }
    .bp-settings-card .form-table th {
        padding: .9rem 1.25rem .9rem 1.5rem !important;
        width: 240px;
        font-size: .8125rem;
        font-weight: 600;
        color: #303030;
        vertical-align: top;
        line-height: 1.5;
    }
    .bp-settings-card .form-table td {
        padding: .9rem 1.5rem .9rem 0 !important;
        font-size: .8125rem;
        color: #303030;
        line-height: 1.5;
        vertical-align: top;
    }
    .bp-settings-card .form-table .description {
        color: #616161;
        font-size: .75rem;
        line-height: 1.55;
        margin: .4rem 0 0 !important;
        font-style: normal;
        max-width: 640px;
        display: block;
    }
    /* Stretch single-line text/select inputs to fill the field column.
       Numbers and other inputs with explicit inline `width:` keep theirs. */
    .bp-settings-card .form-table td > input[type="text"]:not([style*="width"]):not(.colorpick):not(.select2-search__field),
    .bp-settings-card .form-table td > input[type="email"]:not([style*="width"]),
    .bp-settings-card .form-table td > input[type="url"]:not([style*="width"]),
    .bp-settings-card .form-table td > input[type="password"]:not([style*="width"]),
    .bp-settings-card .form-table td > select:not([style*="width"]),
    .bp-settings-card .form-table td > textarea:not([style*="width"]) {
        width: 100% !important;
        max-width: 540px;
    }
    .bp-settings-card .form-table fieldset {
        margin: 0;
        padding: 0;
        border: 0;
    }
    .bp-settings-card .form-table fieldset > label {
        line-height: 1.5;
    }

    /* ------------------------------------------------------------------
     * Form fields — inputs, selects, textareas, checkboxes
     * ------------------------------------------------------------------ */
    .bp-settings-card .form-table input[type="text"]:not(.select2-search__field),
    .bp-settings-card .form-table input[type="number"],
    .bp-settings-card .form-table input[type="email"],
    .bp-settings-card .form-table input[type="url"],
    .bp-settings-card .form-table input[type="password"],
    .bp-settings-card .form-table select,
    .bp-settings-card .form-table textarea {
        border: 1px solid #c4c4c4 !important;
        border-radius: .5rem !important;
        padding: .5rem .75rem !important;
        font-size: .875rem !important;
        color: #303030 !important;
        background: #ffffff !important;
        box-shadow: none !important;
        min-height: 36px;
        line-height: 1.4;
        transition: border-color .12s ease, box-shadow .12s ease;
    }
    .bp-settings-card .form-table input[type="text"]:not(.select2-search__field):focus,
    .bp-settings-card .form-table input[type="number"]:focus,
    .bp-settings-card .form-table input[type="email"]:focus,
    .bp-settings-card .form-table input[type="url"]:focus,
    .bp-settings-card .form-table input[type="password"]:focus,
    .bp-settings-card .form-table select:focus,
    .bp-settings-card .form-table textarea:focus {
        border-color: #303030 !important;
        box-shadow: 0 0 0 1px #303030 !important;
        outline: none !important;
    }
    .bp-settings-card .form-table input[type="checkbox"] {
        appearance: none;
        -webkit-appearance: none;
        width: 18px;
        height: 18px;
        margin: 1px .55rem 0 0;
        padding: 0;
        border: 1.5px solid #8a8a8a;
        border-radius: 4px;
        background: #ffffff;
        cursor: pointer;
        vertical-align: text-top;
        transition: background-color .12s, border-color .12s;
        position: relative;
        flex-shrink: 0;
    }
    .bp-settings-card .form-table input[type="checkbox"]:hover {
        border-color: #303030;
    }
    .bp-settings-card .form-table input[type="checkbox"]:checked {
        background-color: #303030;
        border-color: #303030;
    }
    .bp-settings-card .form-table input[type="checkbox"]:checked::after {
        content: '';
        position: absolute;
        left: 4px;
        top: 0px;
        width: 6px;
        height: 11px;
        border-right: 2px solid #fff;
        border-bottom: 2px solid #fff;
        transform: rotate(45deg);
    }
    .bp-settings-card .form-table input[type="checkbox"]:focus-visible {
        box-shadow: 0 0 0 2px rgba(48,48,48,.25);
        outline: none;
    }
    /* Checkbox + inline label rows — keep label text aligned with the box. */
    .bp-settings-card .form-table fieldset > label {
        display: inline-flex;
        align-items: flex-start;
        gap: 0;
    }

    /* ------------------------------------------------------------------
     * Select2 (wc-enhanced-select)
     * ------------------------------------------------------------------ */
    .bp-settings-card .select2-container {
        width: 100% !important;
        max-width: 540px;
    }
    .bp-settings-card .select2-container--default .select2-selection--multiple,
    .bp-settings-card .select2-container--default .select2-selection--single {
        border: 1px solid #c4c4c4 !important;
        border-radius: .5rem !important;
        min-height: 36px !important;
        padding: 2px 8px !important;
        background: #ffffff !important;
        box-shadow: none !important;
    }
    .bp-settings-card .select2-container--default .select2-selection--single {
        line-height: 32px;
    }
    .bp-settings-card .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 32px !important;
        padding-left: 0 !important;
        padding-right: 24px !important;
        color: #303030 !important;
        font-size: .875rem !important;
    }
    .bp-settings-card .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 34px !important;
        right: 6px !important;
    }
    .bp-settings-card .select2-container--default.select2-container--focus .select2-selection--multiple,
    .bp-settings-card .select2-container--default.select2-container--focus .select2-selection--single,
    .bp-settings-card .select2-container--default.select2-container--open .select2-selection--multiple,
    .bp-settings-card .select2-container--default.select2-container--open .select2-selection--single {
        border-color: #303030 !important;
        box-shadow: 0 0 0 1px #303030 !important;
    }
    .bp-settings-card .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background: #f4f4f4 !important;
        border: 1px solid #e3e3e3 !important;
        border-radius: 4px !important;
        color: #303030 !important;
        font-size: .75rem !important;
        padding: 3px 8px !important;
        margin: 3px 4px 3px 0 !important;
        line-height: 1.3;
    }
    .bp-settings-card .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #8a8a8a !important;
        margin-right: 4px !important;
    }
    .bp-settings-card .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
        color: #d72c0d !important;
    }
    /* Select2 search input must NOT inherit the bordered text-input look —
       it lives inside the selection box and select2 sets its width inline
       to fit the placeholder. Strip border/padding/min-height so it acts
       as a transparent inline editor. */
    .bp-settings-card .select2-search__field,
    .bp-settings-card .select2-search--inline .select2-search__field {
        border: 0 !important;
        border-radius: 0 !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin-top: 4px !important;
        font-size: .8125rem !important;
        color: #303030 !important;
        min-height: 26px !important;
        outline: none !important;
    }
    /* Empty multi-select with no chips: the search field is auto-sized to
       its placeholder by select2 (often width: 0.75em). Force it to flex
       across the remaining row so the field reads as a real input. */
    .bp-settings-card .select2-selection--multiple .select2-search--inline {
        flex: 1 1 auto;
        min-width: 60px;
    }

    /* ------------------------------------------------------------------
     * Help-tip ("?" icon)
     * ------------------------------------------------------------------ */
    /* WC's `body.woocommerce_page_wc-settings .form-table label` ships
       at (0,3,1) and forces `display: block`. Bump our specificity with
       `#mainform` so the label can flow as inline-flex. */
    #mainform .bp-settings-card .form-table th > label {
        position: static !important;
        display: inline-flex !important;
        align-items: center;
        gap: .4rem;
        line-height: 1.4;
    }
    .bp-settings-card .form-table .woocommerce-help-tip {
        position: static !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        vertical-align: middle;
        width: 16px !important;
        height: 16px !important;
        line-height: 1 !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 1.5px solid #c4c4c4;
        border-radius: 50%;
        background: #ffffff;
        color: #8a8a8a !important;
        font-size: 10px !important;
        font-weight: 700 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        text-decoration: none;
        cursor: help;
        flex-shrink: 0;
        transform: translateY(1px);
        transition: color .15s, border-color .15s, background .15s;
    }
    .bp-settings-card .form-table .woocommerce-help-tip::before {
        content: "?" !important;
        display: block;
        width: auto;
        height: auto;
        font-family: inherit !important;
        font-size: 10px !important;
        font-weight: 700 !important;
        line-height: 1 !important;
        color: inherit !important;
        background: none !important;
        margin: 0 !important;
        padding: 0 !important;
        text-shadow: none !important;
        speak: none;
    }
    .bp-settings-card .form-table .woocommerce-help-tip:hover,
    .bp-settings-card .form-table .woocommerce-help-tip:focus {
        background: #303030;
        border-color: #303030;
        color: #ffffff !important;
        outline: none;
    }

    /* tippy.js / jquery tipTip popup launched by the help-tip. */
    body > #tiptip_holder #tiptip_content {
        background: #1a1a1a !important;
        color: #ffffff !important;
        font-size: .75rem !important;
        line-height: 1.5 !important;
        padding: .5rem .75rem !important;
        border-radius: .5rem !important;
        box-shadow: 0 4px 16px rgba(0,0,0,.18) !important;
        text-shadow: none !important;
        max-width: 280px;
    }
    body > #tiptip_holder #tiptip_arrow,
    body > #tiptip_holder #tiptip_arrow_inner {
        border-top-color: #1a1a1a !important;
        border-bottom-color: #1a1a1a !important;
    }

    /* ------------------------------------------------------------------
     * Color picker (Iris)
     * ------------------------------------------------------------------ */
    .bp-settings-card .form-table .colorpickpreview {
        display: inline-block;
        vertical-align: middle;
        width: 36px !important;
        height: 36px !important;
        margin-right: .5rem !important;
        border: 1px solid #e3e3e3 !important;
        border-radius: .5rem !important;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.5);
    }
    .bp-settings-card .form-table input.colorpick {
        width: 9rem !important;
        font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace !important;
        font-size: .8125rem !important;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .bp-settings-card .form-table .iris-picker {
        margin-top: .65rem;
        border-radius: .5rem;
        border: 1px solid #e3e3e3 !important;
        box-shadow: 0 4px 14px rgba(0,0,0,.08);
    }

    /* ------------------------------------------------------------------
     * Save button
     * ------------------------------------------------------------------ */
    /* Sticky save bar, aligned under the card column (sidebar 232px + 28px
       gap = 260px offset). The gradient covers content scrolling underneath
       while fading into the page background at the top. The left offset is
       reset on narrow screens where the layout stacks. */
    #wpbody-content .wrap form#mainform p.submit {
        position: sticky;
        bottom: 0;
        z-index: 60;
        margin: .75rem 0 0 260px !important;
        padding: .8rem 0 .9rem !important;
        max-width: 760px;
        background: linear-gradient(to top, #f1f1f1 70%, rgba(241,241,241,0));
    }
    #wpbody-content .wrap form#mainform p.submit .button-primary,
    #wpbody-content .wrap form#mainform p.submit button.is-primary,
    #wpbody-content .wrap form#mainform p.submit input[type="submit"].button-primary,
    #wpbody-content .wrap form#mainform p.submit .woocommerce-save-button {
        background: #303030 !important;
        background-color: #303030 !important;
        border: 1px solid #303030 !important;
        color: #ffffff !important;
        border-radius: .5rem !important;
        padding: .55rem 1.1rem !important;
        height: auto !important;
        line-height: 1.2 !important;
        min-height: 0 !important;
        font-size: .8125rem !important;
        font-weight: 550 !important;
        letter-spacing: .005em;
        text-shadow: none !important;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1) !important;
        transition: background-color .12s ease;
    }
    #wpbody-content .wrap form#mainform p.submit .button-primary:hover,
    #wpbody-content .wrap form#mainform p.submit button.is-primary:hover,
    #wpbody-content .wrap form#mainform p.submit .woocommerce-save-button:hover,
    #wpbody-content .wrap form#mainform p.submit .button-primary:focus,
    #wpbody-content .wrap form#mainform p.submit button.is-primary:focus,
    #wpbody-content .wrap form#mainform p.submit .woocommerce-save-button:focus {
        background: #1a1a1a !important;
        background-color: #1a1a1a !important;
        border-color: #1a1a1a !important;
        color: #ffffff !important;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1), 0 0 0 2px rgba(48,48,48,.2) !important;
    }
    /* Disabled state — without this, WC's `disabled` attribute leaves the
       button visually identical to the active one, so users see what looks
       like a working button and click it expecting it to save. */
    #wpbody-content .wrap form#mainform p.submit .button-primary:disabled,
    #wpbody-content .wrap form#mainform p.submit button.is-primary:disabled,
    #wpbody-content .wrap form#mainform p.submit input[type="submit"].button-primary:disabled,
    #wpbody-content .wrap form#mainform p.submit .woocommerce-save-button:disabled,
    #wpbody-content .wrap form#mainform p.submit .button-primary[disabled],
    #wpbody-content .wrap form#mainform p.submit button.is-primary[disabled],
    #wpbody-content .wrap form#mainform p.submit .woocommerce-save-button[disabled] {
        background: #b5b5b5 !important;
        background-color: #b5b5b5 !important;
        border-color: #b5b5b5 !important;
        color: #ffffff !important;
        cursor: not-allowed !important;
        box-shadow: none !important;
        opacity: 1 !important;
    }

    /* ------------------------------------------------------------------
     * Branded save toast
     * ------------------------------------------------------------------ */
    .brikpanel-notice.notice-success {
        border-left-color: #1a8917 !important;
        background: #e4f5e1;
        color: #1a6b15;
        border-radius: .5rem;
    }

    /* ------------------------------------------------------------------
     * Responsive
     * ------------------------------------------------------------------ */
    @media (max-width: 880px) {
        /* Stack the two-column layout: nav becomes wrapped pill rows above
           the cards, and the save bar drops its desktop left offset. */
        #mainform .brikpanel-settings-layout {
            flex-direction: column;
            gap: 1rem;
        }
        #mainform .brikpanel-settings-sidebar {
            position: static;
            flex-basis: auto;
            width: auto;
            max-width: 100%;
            max-height: none;
            overflow: visible;
        }
        #mainform .bp-nav-group { margin-bottom: .65rem; }
        #mainform .bp-nav-list {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 4px;
        }
        #mainform .bp-nav-search { max-width: 480px; }
        #wpbody-content .wrap form#mainform p.submit {
            margin-left: 0 !important;
        }
        .bp-settings-card__header {
            padding: .9rem 1.1rem .65rem;
        }
        .bp-settings-card .form-table th,
        .bp-settings-card .form-table td {
            display: block;
            width: auto;
            border-top: 0 !important;
        }
        .bp-settings-card .form-table th {
            padding: 1rem 1.1rem .25rem !important;
        }
        .bp-settings-card .form-table td {
            padding: 0 1.1rem 1rem !important;
        }
        .bp-settings-card .form-table tr + tr th {
            border-top: 1px solid #f1f1f1 !important;
        }
    }
    </style>
    <script>
    /* Always allow saving on the BrikPanel tab. WC 10+ ships the submit
       button with `disabled` and only flips it on after the form goes dirty,
       which is confusing on a settings screen — users expect a save button
       to always be clickable. Re-saving with no changes is a no-op for
       update_option(), so this has no side effects. */
    (function () {
        function enableSaveBtn() {
            document.querySelectorAll('.woocommerce-save-button').forEach(function (btn) {
                btn.removeAttribute('disabled');
                btn.disabled = false;
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enableSaveBtn);
        } else {
            enableSaveBtn();
        }
    })();

    /* Sidebar settings search. Filters the PHP-built JSON index client-side;
       while a query is active the group nav is swapped for a flat result
       list. Result links carry a `#bp-jump=<option id>` fragment — on arrival
       the target row is scrolled into view and flashed. All user-facing text
       is rendered by PHP (placeholder + empty state); this script only moves
       existing DOM around. */
    (function () {
        /* This block runs from admin_head, before the sidebar exists in the
           body — defer the actual wiring until the DOM is parsed. */
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSettingsSearch);
        } else {
            initSettingsSearch();
        }

        function initSettingsSearch() {
        var input   = document.getElementById('brikpanel-settings-search');
        var groups  = document.getElementById('brikpanel-settings-nav-groups');
        var results = document.getElementById('brikpanel-settings-search-results');
        var empty   = document.getElementById('brikpanel-settings-search-empty');
        var idxEl   = document.getElementById('brikpanel-settings-search-index');
        if (!input || !groups || !results || !empty || !idxEl) { return; }
        var index = [];
        try { index = JSON.parse(idxEl.textContent) || []; } catch (e) { return; }
        var base = input.getAttribute('data-base') || '';

        /* WC's dirty-form tracking is opted out via the
           `wc-settings-prevent-change-event` class on the search wrapper —
           WC binds change/input handlers directly to every input at ready,
           so stopping event propagation here would not be enough. */

        function urlFor(item) {
            var url = base + (item.section ? '&section=' + encodeURIComponent(item.section) : '');
            if (item.anchor) { url += '#bp-jump=' + encodeURIComponent(item.anchor); }
            return url;
        }

        function render() {
            var q = input.value.trim().toLowerCase();
            results.textContent = '';
            if (!q) {
                results.hidden = true;
                empty.hidden = true;
                groups.hidden = false;
                return;
            }
            var matches = index.filter(function (it) {
                return it.label.toLowerCase().indexOf(q) !== -1
                    || (it.k && it.k.indexOf(q) !== -1)
                    || (it.sectionLabel && it.sectionLabel.toLowerCase().indexOf(q) !== -1);
            }).slice(0, 30);
            matches.forEach(function (it) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'bp-nav-result';
                a.href = urlFor(it);
                var label = document.createElement('span');
                label.className = 'bp-nav-result__label';
                label.textContent = it.label;
                a.appendChild(label);
                if (it.anchor && it.sectionLabel) {
                    var sec = document.createElement('span');
                    sec.className = 'bp-nav-result__section';
                    sec.textContent = it.sectionLabel;
                    a.appendChild(sec);
                }
                li.appendChild(a);
                results.appendChild(li);
            });
            groups.hidden = true;
            results.hidden = matches.length === 0;
            empty.hidden = matches.length !== 0;
        }

        input.addEventListener('input', render);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                /* Inside #mainform — Enter must never submit the settings form. */
                e.preventDefault();
                var first = results.querySelector('a');
                if (first && !results.hidden) { window.location.href = first.href; }
            } else if (e.key === 'Escape' && input.value) {
                e.stopPropagation();
                input.value = '';
                render();
            }
        });

        function jumpToHashTarget() {
            var m = window.location.hash.match(/^#bp-jump=(.+)$/);
            if (!m) { return; }
            var el = document.getElementById(decodeURIComponent(m[1]));
            if (!el) { return; }
            var row = el.closest('tr') || el.closest('.bp-settings-card') || el;
            /* Same-section jumps arrive via hashchange: restore the nav. */
            if (input.value) { input.value = ''; render(); }
            window.setTimeout(function () {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.remove('bp-jump-flash');
                void row.offsetWidth; /* restart the animation on repeat jumps */
                row.classList.add('bp-jump-flash');
            }, 120);
        }
        window.addEventListener('hashchange', jumpToHashTarget);
        jumpToHashTarget();
        }
    })();
    </script>
    <?php
}, 20 );

// Suppress WooCommerce's native "Your settings have been saved." banner on
// our settings tab. The `.updated.inline` notice is printed by
// WC_Admin_Settings::show_messages() from a private static array, not via
// the admin_notices hook — the foreign-notice suppressor therefore can't
// intercept it. Reflection is the least invasive way to clear it while
// leaving notices on other tabs untouched.
add_action('woocommerce_settings_saved', function () {
    if (!isset($_GET['tab']) || sanitize_key($_GET['tab']) !== 'brikpanel') return;
    if (!class_exists('WC_Admin_Settings')) return;
    try {
        $ref = new ReflectionProperty('WC_Admin_Settings', 'messages');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    } catch (ReflectionException $e) {
        // Leave WC's message visible if reflection is disabled.
    }
});

// Render the branded save confirmation inside the BrikPanel settings tab.
// The `brikpanel-notice` class whitelists the element in the foreign-notice
// suppressor so this is the only admin notice that survives on this screen.
add_action('admin_notices', function () {
    if (!is_admin()) return;
    if (!isset($_GET['page'], $_GET['tab'])) return;
    if (sanitize_key($_GET['page']) !== 'wc-settings') return;
    if (sanitize_key($_GET['tab']) !== 'brikpanel') return;

    $key = 'brikpanel_settings_saved_' . get_current_user_id();
    if (!get_transient($key)) return;
    delete_transient($key);

    echo '<div class="notice notice-success brikpanel-notice is-dismissible"><p>'
        . esc_html__('BrikPanel settings saved.', 'brikpanel')
        . '</p></div>';
});

// Custom order statuses are registered in the main brikpanel.php file
// so they are available everywhere (not just admin context).

// ── Additional Columns ────────────────────────────────────────────────
$is_hpos = get_option('woocommerce_custom_orders_table_enabled') === 'yes';

if ($is_hpos) {
    add_filter('manage_woocommerce_page_wc-orders_columns', 'brikpanel_set_order_columns', 20);
    add_action('manage_woocommerce_page_wc-orders_custom_column', 'brikpanel_fill_order_column', 20, 2);
} else {
    add_filter('manage_edit-shop_order_columns', 'brikpanel_set_order_columns', 20);
    add_action('manage_shop_order_posts_custom_column', 'brikpanel_fill_order_column_legacy', 20, 2);
}

function brikpanel_set_order_columns($columns) {
    $columns['payment_method'] = __('Payment Method', 'brikpanel');
    $columns['order_items']    = __('Items', 'brikpanel');
    $columns['tax_total']      = __('Tax Total', 'brikpanel');
    return $columns;
}

function brikpanel_fill_order_column($column, $order) {
    brikpanel_fill_order_column_content($column, $order);
}

function brikpanel_fill_order_column_legacy($column, $post_id) {
    $order = wc_get_order($post_id);
    if (!$order) return;
    brikpanel_fill_order_column_content($column, $order);
}

function brikpanel_fill_order_column_content($column, $order) {
    switch ($column) {
        case 'payment_method':
            echo esc_html($order->get_payment_method_title() ?? '—');
            break;

        case 'order_items':
            foreach ($order->get_items() as $item) {
                echo esc_html($item->get_name() ?? '') . ' x ' . esc_html($item->get_quantity()) . '<br>';
            }
            break;

        case 'tax_total':
            echo wp_kses_post( wc_price( $order->get_total_tax() ) );
            break;
    }
}

// ── Filter orders by shipping method ──────────────────────────────────
// WooCommerce ships no built-in way to filter the orders list by the
// shipping method a customer chose. We add a dropdown (works on both the
// HPOS orders screen and the legacy posts-based screen) that narrows the
// list to orders containing a matching shipping line.
//
// Shipping data lives in the order items tables, not in order meta, so the
// filter resolves matching order IDs from `woocommerce_order_items` and
// constrains the query with `post__in` — a single arg understood by both
// the HPOS query and WP_Query. We match on the shipping line *name*
// (order_item_name) because it is always populated and human readable,
// unlike `method_id` which can be empty on imported/legacy orders.
if (get_option('brikpanel_orders_enhancements', 'yes') !== 'no') {

    if (get_option('woocommerce_custom_orders_table_enabled') === 'yes') {
        // HPOS orders screen.
        add_action('woocommerce_order_list_table_restrict_manage_orders', 'brikpanel_render_shipping_method_filter', 20, 2);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', 'brikpanel_apply_shipping_method_filter_hpos');
        add_filter('woocommerce_shop_order_list_table_order_count', 'brikpanel_shipping_filter_order_count', 10, 2);
    } else {
        // Legacy posts-based orders screen.
        add_action('restrict_manage_posts', 'brikpanel_render_shipping_method_filter_legacy');
        add_action('pre_get_posts', 'brikpanel_apply_shipping_method_filter_legacy');
    }

    // Keep the cached method list fresh when orders change.
    add_action('woocommerce_checkout_order_processed', 'brikpanel_flush_shipping_method_names_cache');
    add_action('woocommerce_process_shop_order_meta', 'brikpanel_flush_shipping_method_names_cache');
}

/**
 * Distinct shipping line names found across all orders.
 *
 * Cached in a transient because the underlying DISTINCT scan over the order
 * items table is not free on large stores; the list rarely changes.
 *
 * @return string[]
 */
function brikpanel_get_shipping_method_names() {
    $cached = get_transient('brikpanel_shipping_method_names');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;
    $names = $wpdb->get_col(
        "SELECT DISTINCT order_item_name
         FROM {$wpdb->prefix}woocommerce_order_items
         WHERE order_item_type = 'shipping' AND order_item_name <> ''
         ORDER BY order_item_name ASC
         LIMIT 200"
    );
    $names = is_array($names) ? array_map('strval', $names) : array();

    set_transient('brikpanel_shipping_method_names', $names, 6 * HOUR_IN_SECONDS);
    return $names;
}

/**
 * Clear the cached shipping method name list.
 */
function brikpanel_flush_shipping_method_names_cache() {
    delete_transient('brikpanel_shipping_method_names');
}

/**
 * Order IDs that contain a shipping line with the given name.
 *
 * @param string $name Shipping line name to match.
 * @return int[]
 */
function brikpanel_get_order_ids_by_shipping_method($name) {
    static $memo = array();
    if (isset($memo[$name])) {
        return $memo[$name];
    }

    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT order_id
         FROM {$wpdb->prefix}woocommerce_order_items
         WHERE order_item_type = 'shipping' AND order_item_name = %s",
        $name
    ));
    $memo[$name] = array_map('absint', (array) $ids);
    return $memo[$name];
}

/**
 * Read and sanitize the selected shipping method from the request.
 *
 * Read-only listing filter submitted over GET (like WooCommerce's own date
 * and customer filters), so no nonce is required; the value is sanitized and
 * always bound via $wpdb->prepare downstream.
 *
 * @return string Empty string when no method is selected.
 */
function brikpanel_get_selected_shipping_method() {
    if (empty($_GET['brikpanel_shipping_method'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return '';
    }
    return wc_clean(wp_unslash($_GET['brikpanel_shipping_method'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Render the shipping method dropdown markup.
 *
 * @param string $selected Currently selected method name.
 */
function brikpanel_render_shipping_method_filter_select($selected) {
    $names = brikpanel_get_shipping_method_names();

    // Nothing to choose between with fewer than two methods.
    if (count($names) < 2) {
        return;
    }

    echo '<select name="brikpanel_shipping_method" id="brikpanel_shipping_method" class="brikpanel-shipping-method-select">';
    echo '<option value="">' . esc_html__('All shipping methods', 'brikpanel') . '</option>';
    foreach ($names as $name) {
        printf(
            '<option value="%1$s"%2$s>%3$s</option>',
            esc_attr($name),
            selected($selected, $name, false),
            esc_html($name)
        );
    }
    echo '</select>';
}

/**
 * Render the dropdown on the HPOS orders screen.
 *
 * @param string $order_type Order type being listed.
 * @param string $which      Table nav location ('top'|'bottom').
 */
function brikpanel_render_shipping_method_filter($order_type = '', $which = '') {
    if ('shop_order' !== $order_type || 'bottom' === $which) {
        return;
    }
    brikpanel_render_shipping_method_filter_select(brikpanel_get_selected_shipping_method());
}

/**
 * Render the dropdown on the legacy posts-based orders screen.
 *
 * @param string $post_type Current post type.
 */
function brikpanel_render_shipping_method_filter_legacy($post_type = '') {
    if ('shop_order' !== $post_type) {
        return;
    }
    brikpanel_render_shipping_method_filter_select(brikpanel_get_selected_shipping_method());
}

/**
 * Constrain the HPOS orders query to the selected shipping method.
 *
 * @param array $args wc_get_orders() arguments.
 * @return array
 */
function brikpanel_apply_shipping_method_filter_hpos($args) {
    $name = brikpanel_get_selected_shipping_method();
    if ('' === $name) {
        return $args;
    }

    $ids = brikpanel_get_order_ids_by_shipping_method($name);
    if (empty($ids)) {
        $ids = array(0); // No matches: force an empty result set.
    }

    // Respect any IDs an earlier filter (e.g. search) already constrained to.
    if (!empty($args['post__in'])) {
        $ids = array_values(array_intersect(array_map('absint', (array) $args['post__in']), $ids));
        if (empty($ids)) {
            $ids = array(0);
        }
    }

    $args['post__in'] = $ids;
    return $args;
}

/**
 * Correct the HPOS list table order counts while the shipping filter is active.
 *
 * The HPOS list table derives its total, pagination and status tab counts from
 * cached per-status totals (OrderUtil::get_count_for_type) rather than from the
 * filtered query. Our shipping filter is injected as `post__in`, which those
 * cached totals ignore. Hooking the count filter recomputes each requested
 * status bucket against the matching orders so the total, the page count and
 * every status tab reflect the selected shipping method.
 *
 * @param int             $count  Cached order count for $status.
 * @param string|string[] $status Status slug(s) being counted.
 * @return int
 */
function brikpanel_shipping_filter_order_count($count, $status) {
    if (empty($_GET['brikpanel_shipping_method'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return $count;
    }

    $name = brikpanel_get_selected_shipping_method();
    if ('' === $name) {
        return $count;
    }

    $ids = brikpanel_get_order_ids_by_shipping_method($name);
    if (empty($ids)) {
        return 0;
    }

    $matched = wc_get_orders(array(
        'type'     => 'shop_order',
        'status'   => array_map('strval', (array) $status),
        'post__in' => $ids,
        'limit'    => -1,
        'return'   => 'ids',
    ));

    return count($matched);
}

/**
 * Constrain the legacy posts-based orders query to the selected shipping method.
 *
 * @param WP_Query $query Main admin query.
 */
function brikpanel_apply_shipping_method_filter_legacy($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ('shop_order' !== $query->get('post_type')) {
        return;
    }

    $name = brikpanel_get_selected_shipping_method();
    if ('' === $name) {
        return;
    }

    $ids = brikpanel_get_order_ids_by_shipping_method($name);
    if (empty($ids)) {
        $ids = array(0);
    }

    $existing = $query->get('post__in');
    if (!empty($existing)) {
        $ids = array_values(array_intersect(array_map('absint', (array) $existing), $ids));
        if (empty($ids)) {
            $ids = array(0);
        }
    }

    $query->set('post__in', $ids);
}

// ── AJAX: Inline Order Status Change ──────────────────────────────────
add_action('wp_ajax_brikpanel_change_order_status', function () {
    check_ajax_referer('brikpanel_order_status_nonce', '_ajax_nonce');

    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
    }

    $order_id   = absint($_POST['order_id'] ?? 0);
    $new_status = sanitize_key($_POST['new_status'] ?? '');

    if (!$order_id || !$new_status) {
        wp_send_json_error(['message' => __('Invalid request.', 'brikpanel')]);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => __('Order not found.', 'brikpanel')]);
    }

    $valid_statuses = array_keys(wc_get_order_statuses());
    $new_status_key = (strpos($new_status, 'wc-') === 0) ? $new_status : 'wc-' . $new_status;
    if (!in_array($new_status_key, $valid_statuses)) {
        wp_send_json_error(['message' => __('Invalid status.', 'brikpanel')]);
    }

    $slug = str_replace('wc-', '', $new_status_key);
    $order->update_status($slug);

    $statuses = wc_get_order_statuses();
    $label    = $statuses[$new_status_key] ?? $slug;

    wp_send_json_success([
        'status' => $slug,
        'label'  => $label,
    ]);
});

