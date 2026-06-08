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
        'brk_coupons_title'      => 'coupons',
        'brk_login_title'        => 'login',
        'brk_notices_title'      => '',
        'brk_order_notify_title' => 'notifications',
        'brk_appearance_title'   => '',
        'brk_developers_title'   => 'developers',
    ] );
    return isset( $map[ $title_id ] ) ? (string) $map[ $title_id ] : '';
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
 * Render the section sub-nav as the standard WP `subsubsub` list, mirroring
 * the markup WC uses for `WC_Settings_Page` subclasses so third-party CSS
 * (and our own brand overrides) can hook the same selectors.
 */
function brikpanel_settings_render_section_nav( $current_section ) {
    $sections = brikpanel_settings_get_sections();
    if ( empty( $sections ) ) {
        return;
    }
    $keys = array_keys( $sections );
    echo '<ul class="subsubsub brikpanel-settings-sections">';
    foreach ( $sections as $id => $label ) {
        $url   = admin_url( 'admin.php?page=wc-settings&tab=brikpanel' . ( $id !== '' ? '&section=' . sanitize_title( $id ) : '' ) );
        $class = ( (string) $current_section === (string) $id ) ? 'current' : '';
        $sep   = ( end( $keys ) === $id ) ? '' : '|';
        echo '<li><a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a> ' . esc_html( $sep ) . ' </li>';
    }
    echo '</ul><br class="clear" />';
}

// Tab content — section nav + only the current section's fields. The full
// `brikpanel_settings_fields()` list is still walked once so the slicer can
// honour 3rd-party fields injected via the public filter.
add_action( 'woocommerce_settings_tabs_brikpanel', function () {
    $current = brikpanel_settings_get_current_section();
    brikpanel_settings_render_section_nav( $current );
    echo '<div class="brikpanel-settings-section-body" data-section="' . esc_attr( $current ) . '">';

    // The Import/Export section is non-standard — it does not save to options
    // via the usual form-table flow. Hand off rendering to its own module so
    // it can use whatever markup it needs without fighting WC field types.
    if ( $current === 'import-export' && function_exists( 'brikpanel_import_export_render_section' ) ) {
        brikpanel_import_export_render_section();
        echo '</div>';
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
    echo '</div>';
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
     * Sub-tab navigation (Shopify-style pill bar)
     * ------------------------------------------------------------------ */
    #mainform .brikpanel-settings-sections {
        display: inline-flex;
        flex-wrap: wrap;
        gap: 2px;
        margin: .25rem 0 1rem !important;
        padding: 4px;
        background: #ffffff;
        border: 1px solid #e3e3e3;
        border-radius: .65rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    #mainform .brikpanel-settings-sections li {
        margin: 0 !important;
        padding: 0 !important;
        color: transparent !important;
        font-size: 0;
        line-height: 1;
    }
    #mainform .brikpanel-settings-sections li a {
        display: inline-flex !important;
        align-items: center;
        padding: .5rem .9rem !important;
        border-radius: .45rem;
        font-size: .8125rem;
        font-weight: 550;
        color: #616161;
        text-decoration: none;
        line-height: 1.2;
        background: transparent;
        border: 1px solid transparent;
        margin: 0 !important;
        transition: background-color .15s ease, color .15s ease;
    }
    #mainform .brikpanel-settings-sections li a:hover,
    #mainform .brikpanel-settings-sections li a:focus {
        background: #f4f4f4 !important;
        color: #303030 !important;
        box-shadow: none;
        outline: none;
    }
    #mainform .brikpanel-settings-sections li a.current {
        background: #303030 !important;
        color: #ffffff !important;
        border-color: #303030;
        box-shadow: inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1);
    }
    #mainform .brikpanel-settings-sections li a.current:hover,
    #mainform .brikpanel-settings-sections li a.current:focus {
        background: #1a1a1a !important;
        color: #ffffff !important;
    }

    /* ------------------------------------------------------------------
     * Section body — vertical stack of cards
     * ------------------------------------------------------------------ */
    .brikpanel-settings-section-body {
        display: flex;
        flex-direction: column;
        gap: .75rem;
        max-width: 980px;
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
    #wpbody-content .wrap form#mainform p.submit {
        margin: 1.25rem 0 0 !important;
        padding: 0 !important;
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

