<?php
/**
 * Plugin Name: BrikPanel: WooCommerce Admin Dashboard Theme
 * Description: Beautiful and modern Shopify-style WooCommerce admin panel & dashboard, fully free, forever.
 * Version: 3.1.28
 * Author: Brksoft
 * Author URI: https://brksoft.com/
 * Text Domain: brikpanel
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 * Network: true
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
**/

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// CONSTANTS
// =============================================================================
define('BRIKPANEL_VERSION', '3.1.28');
define('BRIKPANEL_PATH', plugin_dir_path(__FILE__));
define('BRIKPANEL_URL', plugin_dir_url(__FILE__));
define('BRIKPANEL_BASENAME', plugin_basename(__FILE__));

// =============================================================================
// NETWORK ACCESS RULES (multisite-only Super Admin gate)
//
// Loaded *before* the WooCommerce dependency guard below so the Network Admin
// settings page stays reachable even on networks whose main site does not
// have WooCommerce active. The module is a no-op on single-site installs.
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-network-access.php';

// =============================================================================
// WOOCOMMERCE DEPENDENCY GUARD (multisite-critical)
// =============================================================================
/**
 * Bail completely when WooCommerce is not active on the current site.
 *
 * Why this matters: on multisite, BrikPanel can be Network-Activated while
 * WooCommerce is only active on a subset of subsites (per-site activation,
 * or per-site deactivation after a network rollout). Without this guard,
 * every BrikPanel module runs on those WC-less subsites and fatal-errors on
 * the first call into a WC class (e.g. `WC_Order_Query`), white-screening
 * the subsite admin. The `Requires Plugins: woocommerce` header only gates
 * the activation action, not execution on subsites where WC was later
 * deactivated.
 *
 * We use `is_plugin_active()` rather than `class_exists( 'WooCommerce' )`
 * because plugins load alphabetically — at this point WC's main class is
 * not yet defined, but its `active_plugins` option entry already is.
 * `is_plugin_active()` already covers the network-active case internally.
 */
if ( ! function_exists( 'is_plugin_active' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-warning brikpanel-notice"><p><strong>BrikPanel:</strong> '
            . esc_html__( 'WooCommerce is not active on this site, so BrikPanel features are disabled here. Activate WooCommerce to enable BrikPanel.', 'brikpanel' )
            . '</p></div>';
    } );
    return;
}

// =============================================================================
// SEO PLUGIN COMPATIBILITY BOOTSTRAP (must run before plugins_loaded listeners)
// =============================================================================
/**
 * When the current request targets our simplified product editor page,
 * masquerade as the native `/wp-admin/post.php?post=X&action=edit` flow so
 * third-party SEO plugins (Yoast, Rank Math, AIOSEO, SEOPress) bootstrap
 * their metabox / Screen classes with the correct post + screen context.
 *
 * Rank Math evaluates `$pagenow === 'post.php'` synchronously inside its
 * Metabox bootstrap on `admin_init`; spoofing later (e.g. in our own
 * admin_enqueue_scripts callback) is too late. We override the bare minimum
 * — only on our page — so other admin screens stay untouched.
 *
 * Runs at plugins_loaded priority 0, before any plugin has registered its
 * own listeners, but late enough for $_GET / $_POST to be populated.
 */
add_action('plugins_loaded', function () {
    if (!is_admin()) {
        return;
    }
    $page   = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $action = isset($_POST['action']) ? sanitize_key($_POST['action']) : '';
    $is_editor_page = ($page === 'brikpanel-product-editor');
    $is_editor_save = (defined('DOING_AJAX') && DOING_AJAX && $action === 'brikpanel_save_product');
    if (!$is_editor_page && !$is_editor_save) {
        return;
    }

    // SEO plugins gate their metabox registration on $pagenow === 'post.php'.
    $GLOBALS['pagenow'] = 'post.php';

    // Yoast SEO — its should_load_meta_boxes() filter needs to be true.
    add_filter('wpseo_always_register_metaboxes_on_admin', '__return_true');

    if ($is_editor_page) {
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($product_id) {
            $_GET['post']       = $product_id;
            $_REQUEST['post']   = $product_id;
            $_GET['action']     = 'edit';
            $_REQUEST['action'] = 'edit';
            $_GET['post_type']  = 'product';
        }
    }

    if ($is_editor_save && !empty($_POST['product_id'])) {
        $pid = absint($_POST['product_id']);
        // Yoast's save_postdata() bails if $_POST['ID'] !== $post_id.
        $_POST['ID']        = $pid;
        $_REQUEST['ID']     = $pid;
        $_POST['post_ID']   = $pid;
        $_POST['post_type'] = 'product';
    }
}, 0);

// =============================================================================
// WOOCOMMERCE HPOS COMPATIBILITY
// =============================================================================
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// =============================================================================
// CUSTOM ORDER STATUSES (must register globally, not just in admin)
// =============================================================================
add_action('init', function () {
    register_post_status('wc-return-draft', [
        'label'                     => _x('Return Draft', 'Order status', 'brikpanel'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Return Draft <span class="count">(%s)</span>',
            'Return Draft <span class="count">(%s)</span>',
            'brikpanel'
        ),
    ]);

    register_post_status('wc-change', [
        'label'                     => _x('Change', 'Order status', 'brikpanel'),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop(
            'Change <span class="count">(%s)</span>',
            'Change <span class="count">(%s)</span>',
            'brikpanel'
        ),
    ]);
});

add_filter('wc_order_statuses', function ($statuses) {
    // Other plugins (e.g. BrikMarket order migration) call wc_get_order_statuses()
    // on plugins_loaded — before init runs and our textdomain is loaded. Calling
    // _x() then triggers a WP 6.7 "_load_textdomain_just_in_time was called
    // incorrectly" notice, so fall back to untranslated labels until init.
    if (did_action('init')) {
        $statuses['wc-return-draft'] = _x('Return Draft', 'Order status', 'brikpanel');
        $statuses['wc-change']       = _x('Change', 'Order status', 'brikpanel');
    } else {
        $statuses['wc-return-draft'] = 'Return Draft';
        $statuses['wc-change']       = 'Change';
    }
    return $statuses;
});

// =============================================================================
// USER-DEFINED CUSTOM ORDER STATUSES (settings UI + global registration)
//
// Loaded outside brikpanel_init_admin() so the statuses register on the front
// end too (checkout/programmatic orders), not only inside wp-admin.
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/order-statuses/brikpanel-order-statuses.php';

// =============================================================================
// LOAD TEXT DOMAIN
// =============================================================================
function brikpanel_load_textdomain() {
    load_plugin_textdomain('brikpanel', false, dirname(BRIKPANEL_BASENAME) . '/languages');
}
add_action('init', 'brikpanel_load_textdomain', 1);

// =============================================================================
// ADMIN SIDE FILES - Load on init (same timing as 1.4.0)
// =============================================================================
function brikpanel_init_admin() {
    if (!is_admin()) {
        return;
    }

    // Desktop Mode compatibility — loaded first so the gate helper
    // (brikpanel_is_desktop_mode) exists before the top bar / navigation
    // modules below consult it at render time.
    require_once BRIKPANEL_PATH . 'includes/brikpanel-desktop-mode-compat.php';

    // Front-end files (for admin)
    require_once BRIKPANEL_PATH . 'includes/brikpanel-cache-clear.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard-section-order.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-dashboard-topbar.php';
    require_once BRIKPANEL_PATH . 'front-end/dashboard/brikpanel-topbar-items.php';
    require_once BRIKPANEL_PATH . 'front-end/master-switch/brikpanel-master-switch.php';
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        require_once BRIKPANEL_PATH . 'front-end/navigation/brikpanel-navigation.php';
    }
    // Sidebar customizer (settings UI + render-time application). Loaded even
    // when modern navigation is off so admins can pre-configure the layout
    // before flipping the toggle.
    require_once BRIKPANEL_PATH . 'front-end/navigation/brikpanel-nav-customizer.php';
    require_once BRIKPANEL_PATH . 'front-end/search/brikpanel-search.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders.php';
    require_once BRIKPANEL_PATH . 'front-end/orders/brikpanel-orders-stats.php';
    require_once BRIKPANEL_PATH . 'front-end/currency/brikpanel-currency-settings.php';
    require_once BRIKPANEL_PATH . 'front-end/order/brikpanel-order-fields.php';
    require_once BRIKPANEL_PATH . 'front-end/import-export/brikpanel-import-export.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-section-order.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-qe-order.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-product-editor.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-products-list.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-category-enhancements.php';
    require_once BRIKPANEL_PATH . 'front-end/coupons/brikpanel-coupons.php';
    require_once BRIKPANEL_PATH . 'front-end/segments/brikpanel-segments.php';
    require_once BRIKPANEL_PATH . 'front-end/customer-analytics/brikpanel-customer-analytics.php';
    require_once BRIKPANEL_PATH . 'front-end/expenses/brikpanel-expenses.php';
    require_once BRIKPANEL_PATH . 'front-end/vendors/brikpanel-vendors.php';

    // Back-end files
    require_once BRIKPANEL_PATH . 'back-end/total-sales/brikpanel-total-sales.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-total-orders.php';
    require_once BRIKPANEL_PATH . 'back-end/order-value/brikpanel-order-value.php';
    require_once BRIKPANEL_PATH . 'back-end/order-rates/brikpanel-order-rates.php';
}
add_action('init', 'brikpanel_init_admin');

// =============================================================================
// SUPPRESS THIRD-PARTY ADMIN NOTICES (opt-out via settings)
// =============================================================================
/**
 * Hide admin notices from other plugins/themes while keeping BrikPanel's own
 * notices visible. Controlled by the `brikpanel_hide_foreign_notices` option,
 * defaulting to enabled.
 *
 * Implementation: captures the admin_notices / all_admin_notices output in
 * an output buffer, then filters out anything whose rendered markup does not
 * include the `brikpanel-notice` class. BrikPanel must mark its own notices
 * with that class to stay visible.
 *
 * Additionally injects CSS to hide any leftover notice markup that bypasses
 * the output-buffer hook (e.g. notices rendered via admin_head or printed
 * inside .wrap after the hook closes).
 */
function brikpanel_suppress_foreign_notices() {
    if (!is_admin()) {
        return;
    }
    if (get_option('brikpanel_hide_foreign_notices', 'yes') !== 'yes') {
        return;
    }

    // Opt-in: also pull red error notices into the bell. Off by default — error
    // notices usually mean something is broken, so they stay on screen unless
    // the store owner deliberately turns this on.
    $hide_errors = get_option('brikpanel_hide_error_notices', 'no') === 'yes';

    // Notices *are* suppressed on our own WC settings tab too. The tab
    // replaces WooCommerce's generic "Your settings have been saved" with
    // a branded `.brikpanel-notice` variant (registered below) so the
    // admin still gets save confirmation without seeing every other
    // plugin's marketing banners bleed through.

    // Foreign notice markup is not discarded — it is collected here and then
    // tucked behind a small, collapsed toggle so the admin can still reveal it
    // on demand (hidden by default, shown on click). Shared by reference across
    // every notices hook flush so a single toggle gathers them all.
    $collected = '';
    $count     = 0;

    $capture = function () {
        ob_start();
    };
    $flush = function () use (&$collected, &$count, $hide_errors) {
        $html = ob_get_clean();
        if (is_string($html) && $html !== '') {
            // Separate foreign notice blocks from BrikPanel's own while
            // preserving correct DOM nesting. The naive .*?</div> regex broke
            // when notices contained nested <div> elements (e.g. wp-fail2ban
            // promotional banners) — it captured only up to the first inner
            // </div>, leaving orphaned closing tags that collapsed parent
            // containers (#wpbody-content, #wpbody, #wpcontent) and pushed the
            // page .wrap out of the normal hierarchy.
            //
            // Approach: find each notice opening tag, then count nested
            // <div>…</div> pairs to locate the *matching* closing tag before
            // deciding what to do with the whole block.
            //
            // The class match accepts every flavour WordPress core and plugins
            // emit: notice-success/error/warning/info, the legacy `updated` /
            // `error` containers, and the core update nag (`update-nag`, which
            // older WP prints without a `notice` class). Both single- and
            // double-quoted `class=` attributes are handled.
            $opener = '#<div\b[^>]*\bclass=(["\'])[^"\']*\b(?:notice|updated|error|update-nag)\b[^"\']*\1[^>]*>#is';
            $offset = 0;
            $out    = '';
            while (preg_match($opener, $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $m[0][1];
                // Emit everything before this notice as-is.
                $out .= substr($html, $offset, $start - $offset);

                // Walk forward from the end of the opening tag, counting nested
                // <div> opens against </div> closes until we reach depth 0.
                $pos   = $start + strlen($m[0][0]);
                $depth = 1;
                while ($depth > 0 && preg_match('#<(/?)div\b[^>]*>#i', $html, $tag, PREG_OFFSET_CAPTURE, $pos)) {
                    $pos = $tag[0][1] + strlen($tag[0][0]);
                    if ($tag[1][0] === '/') {
                        $depth--;
                    } else {
                        $depth++;
                    }
                }
                // $pos is now right after the matching </div>.
                $block    = substr($html, $start, $pos - $start);
                $open_tag = $m[0][0];

                // WordPress prints JS-controlled "control" notices on the
                // admin_notices hook that are not real messages: the
                // autosave/heartbeat "Connection lost. Saving has been
                // disabled…" banner (#lost-connection-notice) and the
                // local-storage warning (#local-storage-notice). They ship
                // hidden and core JS shows or re-hides them exactly when
                // relevant. Any notice still carrying the `hidden` class is in
                // the same boat. Leave them untouched and in place — collecting
                // or restyling them would either bury a genuine "saving is
                // disabled" warning or surface it when nothing is wrong.
                $is_control_notice =
                    preg_match('#\bid=(["\'])(?:lost-connection-notice|local-storage-notice)\1#i', $open_tag)
                    || preg_match('#\bclass=(["\'])[^"\']*\bhidden\b[^"\']*\1#i', $open_tag);

                // Error notices (the red ones) flag something genuinely broken,
                // so they stay on screen instead of being tucked behind the
                // bell. Matched on the exact `notice-error` (modern) or legacy
                // `error` class token, not a loose substring.
                $is_error_notice = false;
                if (!$hide_errors && preg_match('#\bclass=(["\'])([^"\']*)\1#i', $open_tag, $cm)) {
                    $classes = preg_split('#\s+#', trim($cm[2]));
                    $is_error_notice = in_array('notice-error', $classes, true)
                        || in_array('error', $classes, true);
                }

                // Empty notice shells (no text and no media/controls) carry
                // nothing to show — collecting them would render a blank row in
                // the bell or the non-topbar reveal box. WordPress adds the
                // dismiss button client-side, so server markup is genuinely
                // empty here. Leave them in place for whatever script may fill
                // them, exactly like control notices.
                $is_empty_notice = false;
                if (!$is_control_notice && !$is_error_notice
                    && function_exists('wp_strip_all_tags')) {
                    $is_empty_notice = trim(wp_strip_all_tags($block)) === ''
                        && !preg_match('#<(?:img|svg|input|select|textarea|button)\b#i', $block)
                        && !preg_match('#<a\b[^>]*\shref=#i', $block);
                }

                if ($is_control_notice || $is_error_notice || $is_empty_notice
                    || strpos($block, 'brikpanel-notice') !== false) {
                    // Error/control/empty notices and BrikPanel's own notices
                    // stay inline and exactly where WordPress put them.
                    $out .= $block;
                } else {
                    // Everything else is stashed behind the reveal toggle.
                    // WordPress core's common.js hoists every `div.notice`
                    // (except `.inline`/`.below-h2`) to just after
                    // `.wp-header-end` on DOM ready — which would yank these
                    // back out of our box. Tag the outer notice tag with
                    // `inline` so core leaves it nested where we put it. The
                    // `notice` class itself is kept intact, so dismiss buttons
                    // and the plugin's own scripts keep working.
                    $marked   = preg_replace('#\bclass=(["\'])([^"\']*)\1#i', 'class=$1$2 inline$1', $open_tag, 1);
                    $collected .= $marked . substr($block, strlen($open_tag));
                    $count++;
                }

                $offset = $pos;
            }
            // Append any remaining content after the last notice.
            $out .= substr($html, $offset);
            echo $out;
        }

        // all_admin_notices always fires last on every admin page, so it is the
        // single, reliable point to render the collected notices once.
        if (current_action() === 'all_admin_notices') {
            brikpanel_render_hidden_notices_box($collected, $count);
            $collected = '';
            $count     = 0;
        }
    };

    // On multisite Network Admin we deliberately leave notices alone — core
    // surfaces critical super-admin alerts there (pending site requests, core
    // updates, network health) and silencing them via our brikpanel-notice
    // class filter would hide messages the super admin actually needs.
    $hooks = is_network_admin()
        ? []
        : ['admin_notices', 'all_admin_notices', 'user_admin_notices'];
    foreach ($hooks as $hook) {
        add_action($hook, $capture, -PHP_INT_MAX);
        add_action($hook, $flush, PHP_INT_MAX);
    }

    // Belt and suspenders: any notice markup that slips past the output
    // buffer (e.g. printed inside .wrap after the hook) is hidden via CSS.
    // Skip on Network Admin so core super-admin alerts stay visible.
    if ( ! is_network_admin() ) {
        add_action('admin_head', function () use ($hide_errors) {
            // Guards on this blanket hide:
            //  - `:not(.hidden)` / `:not(#lost-connection-notice)` /
            //    `:not(#local-storage-notice)` keep WordPress's JS-controlled
            //    control notices visible when core reveals them (e.g. the
            //    autosave/heartbeat "Connection lost" warning).
            //  - error notices (the red ones) are NOT hidden by default — the
            //    `.error` selectors are gone and `.notice` carries
            //    `:not(.notice-error)` — so urgent "something is broken" messages
            //    stay on screen, mirroring the server-side skip. When the store
            //    owner opts in ($hide_errors), those guards are dropped and the
            //    legacy `.error` direct-child selectors are added back so error
            //    notices are hidden too (the matching `lost-connection-notice`
            //    control banner is still spared).
            $err_guard = $hide_errors ? '' : ':not(.notice-error)';
            $err_lines = $hide_errors ? '
                .wp-admin #wpbody-content > .error:not(.brikpanel-notice):not(.hidden):not(#lost-connection-notice),
                .wp-admin .wrap > .error:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden):not(#lost-connection-notice),' : '';
            echo '<style>
                .wp-admin #wpbody-content > .notice:not(.brikpanel-notice):not(.hidden)' . $err_guard . ':not(#lost-connection-notice):not(#local-storage-notice),
                .wp-admin #wpbody-content > .updated:not(.brikpanel-notice):not(.hidden),' . $err_lines . '
                .wp-admin .wrap > .notice:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden)' . $err_guard . ':not(#lost-connection-notice):not(#local-storage-notice),
                .wp-admin .wrap > .updated:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden) {
                    display: none !important;
                }
            </style>';
        }, 9999);
    }
}
add_action('admin_init', 'brikpanel_suppress_foreign_notices');

/**
 * Render the small "hidden notices" reveal toggle plus the suppressed
 * third-party notice markup tucked inside it.
 *
 * Primary surface: the BrikPanel topbar. When the topbar is present
 * (body.brikpanel-has-topbar), its script relocates these notices into a
 * fixed "hidden notices" button + panel and this inline box is hidden via CSS
 * (no flash). See front-end/topbar/brikpanel-topbar.{php,css,js}.
 *
 * Fallback surface: on admin screens without the topbar (block editor,
 * customizer, etc.) this renders as a self-contained <details>/<summary>
 * element — collapsed by default (notices hidden), expanded on click, zero
 * JavaScript — so the suppressed notices stay reachable everywhere. The
 * wrapper carries the `brikpanel-notice` class so the CSS fallback never hides
 * it, and the foreign notices live nested inside it (not as direct children of
 * #wpbody-content/.wrap) so the same fallback leaves them visible once opened.
 *
 * @param string $notices_html Concatenated foreign notice markup.
 * @param int    $count        Number of foreign notices collected.
 */
function brikpanel_render_hidden_notices_box($notices_html, $count) {
    static $rendered = false;
    if ($rendered || $count < 1 || $notices_html === '') {
        return;
    }
    $rendered = true;

    $label = sprintf(
        /* translators: %d: number of hidden third-party admin notices */
        _n('%d hidden notice', '%d hidden notices', $count, 'brikpanel'),
        $count
    );
    $title = esc_attr__('Other plugins and themes posted these notices. BrikPanel keeps them hidden, click to show.', 'brikpanel');

    $bell = '<svg class="brikpanel-fn-bell" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
    $chevron = '<svg class="brikpanel-fn-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>';

    echo '<style>
        /* On topbar screens the topbar owns this UI — hide the inline
           fallback up front so it never flashes before the script relocates
           the notices into the topbar panel. */
        body.brikpanel-has-topbar .brikpanel-foreign-notices { display: none !important; }
        .brikpanel-foreign-notices { margin: 10px 0 4px; }
        .brikpanel-foreign-notices > summary {
            display: inline-flex; align-items: center; gap: .4rem;
            list-style: none; cursor: pointer; -webkit-user-select: none; user-select: none;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.8125rem; font-weight: 550; line-height: 1; color: #616161;
            background: #fff; border: 1px solid #e3e3e3; border-radius: .5rem;
            padding: .45rem .7rem; box-shadow: 0 1px 0 rgba(0,0,0,.05);
            transition: background .15s ease, color .15s ease, border-color .15s ease;
        }
        .brikpanel-foreign-notices > summary::-webkit-details-marker { display: none; }
        .brikpanel-foreign-notices > summary:hover { background: #f7f7f7; color: #303030; }
        .brikpanel-foreign-notices > summary:focus-visible { outline: none; border-color: #303030; box-shadow: 0 0 0 1px #303030; }
        .brikpanel-foreign-notices .brikpanel-fn-bell { flex: 0 0 auto; opacity: .8; }
        .brikpanel-foreign-notices .brikpanel-fn-chevron { flex: 0 0 auto; opacity: .65; transition: transform .2s ease; }
        .brikpanel-foreign-notices[open] > summary { color: #303030; }
        .brikpanel-foreign-notices[open] > summary .brikpanel-fn-chevron { transform: rotate(180deg); }
        .brikpanel-foreign-notices .brikpanel-fn-list { margin-top: .6rem; }
        .brikpanel-foreign-notices .brikpanel-fn-list > .notice,
        .brikpanel-foreign-notices .brikpanel-fn-list > .updated,
        .brikpanel-foreign-notices .brikpanel-fn-list > .error,
        .brikpanel-foreign-notices .brikpanel-fn-list > .update-nag { margin-left: 0; margin-right: 0; }
    </style>';

    echo '<details id="brikpanel-foreign-notices-holder" class="brikpanel-notice brikpanel-foreign-notices">';
    echo '<summary title="' . $title . '">' . $bell . '<span>' . esc_html($label) . '</span>' . $chevron . '</summary>';
    echo '<div class="brikpanel-fn-list">' . $notices_html . '</div>';
    echo '</details>';
}

// =============================================================================
// LOGIN PAGE CUSTOMIZATION
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/login/brikpanel-login.php';

// =============================================================================
// APPEARANCE CUSTOMIZATION (font + accent color)
// Loaded globally so the override CSS applies to both wp-admin and the
// modern login page. Registers its own hooks (settings field, admin_head,
// login_head, admin_enqueue_scripts).
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/appearance/brikpanel-appearance.php';

// =============================================================================
// FRONT-END & GENERAL FILES
// =============================================================================
function brikpanel_init_other() {
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-variation-gallery.php';
    require_once BRIKPANEL_PATH . 'front-end/products/brikpanel-short-description.php';
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        require_once BRIKPANEL_PATH . 'front-end/brikpanel-site-submenu.php';
    }
    require_once BRIKPANEL_PATH . 'front-end/sound/brikpanel-sound.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-conversion-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-product-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-checkout-count.php';
    require_once BRIKPANEL_PATH . 'back-end/conversion-count/brikpanel-add-to-cart-count.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-add-to-cart/brikpanel-most-add-to-cart.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-sale/brikpanel-most-sale.php';
    require_once BRIKPANEL_PATH . 'back-end/most-count/most-view/brikpanel-most-view.php';
    require_once BRIKPANEL_PATH . 'back-end/live/brikpanel-live.php';
}
add_action('init', 'brikpanel_init_other');

// =============================================================================
// WELCOME / FEATURE SHOWCASE POPUP
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/welcome/brikpanel-welcome.php';

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-helpers.php';
require_once BRIKPANEL_PATH . 'includes/brikpanel-currency.php';
require_once BRIKPANEL_PATH . 'includes/brikpanel-profit.php';

// =============================================================================
// ACCESS CONTROL — per-user / per-role interface gate
//
// Loaded before `init` so its `pre_option_*` neutralization filters are in
// place by the time brikpanel_init_admin() / brikpanel_init_other() read the
// interface toggles to decide which modules to load.
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-access-control.php';

// =============================================================================
// THIRD-PARTY COMPATIBILITY: ASE (Admin and Site Enhancements) bridge
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-ase-bridge.php';

// =============================================================================
// ENQUEUE SCRIPTS & STYLES
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-enqueue.php';

// =============================================================================
// DEVELOPER HOOKS API (public actions/filters + docs modal)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-hooks-api.php';

// =============================================================================
// REVIEW REQUEST NOTICES (50 completed orders)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-review-notices.php';

// =============================================================================
// BRIKMENTOR EARLY-ACCESS CAPTURE (100 / 200 completed orders)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/brikpanel-early-access.php';

// =============================================================================
// CRON / BACKGROUND JOBS (Action Scheduler wrapper + admin page)
// =============================================================================
require_once BRIKPANEL_PATH . 'includes/cron/brikpanel-cron.php';
require_once BRIKPANEL_PATH . 'includes/cron/customer-analytics-jobs.php';

// =============================================================================
// BRIKCONTROL — Store Health panel (loaded outside the is_admin gate so the
// Action Scheduler worker can resolve registered handlers when running over
// WP-Cron / CLI). Admin menu + AJAX hooks self-gate to admin context.
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/brikpanel-brikcontrol.php';

// =============================================================================
// GOOGLE SHEETS — must load outside is_admin so:
//   1. Action Scheduler workers (WP-Cron / CLI context) can resolve the sync
//      handlers (real-time order flush, bulk export, reports snapshot,
//      customers snapshot).
//   2. The `woocommerce_new_order` hook fires inside a non-admin checkout
//      request — the order-sync class must be instantiated by then or new
//      orders silently bypass the realtime queue.
// Admin menu, asset enqueue, AJAX endpoints all self-gate to admin context.
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/google-sheets/brikpanel-google-sheets.php';

// =============================================================================
// AD PLATFORMS — Google Ads + Meta Ads daily-spend integration.
//
// Loaded outside the is_admin gate (like Google Sheets) because Action
// Scheduler workers running under WP-Cron / CLI need the sync + backfill
// handlers registered to dispatch the daily pull. Admin menu, AJAX
// endpoints, and asset enqueue all self-gate to admin context inside the
// module classes.
// =============================================================================
require_once BRIKPANEL_PATH . 'front-end/ad-platforms/brikpanel-ad-platforms.php';

// =============================================================================
// STORE SUMMARY (on-demand Markdown digest, triggered from dashboard "Copy
// everything" button — no cron, generated on click only)
// =============================================================================
if ( is_admin() ) {
    require_once BRIKPANEL_PATH . 'includes/brikpanel-store-summary.php';
}

// =============================================================================
// DATABASE TABLE CREATION
// =============================================================================
function brikpanel_create_table() {
    global $wpdb;

    $visitors_table       = $wpdb->prefix . "brikpanel_visitors";
    $cart_tracking_table  = $wpdb->prefix . "brikpanel_cart_tracking";
    $visited_pages_table  = $wpdb->prefix . "brikpanel_visited_pages";
    $charset_collate = $wpdb->get_charset_collate();

    $sql_visitors = "CREATE TABLE $visitors_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        date_column DATE NOT NULL,
        visitor_count INT DEFAULT 0,
        product_count INT DEFAULT 0,
        add_to_cart_count INT DEFAULT 0,
        checkout_count INT DEFAULT 0,
        mobile_count INT DEFAULT 0,
        tablet_count INT DEFAULT 0,
        desktop_count INT DEFAULT 0,
        KEY idx_date (date_column)
    ) $charset_collate;";

    $sql_cart_tracking = "CREATE TABLE $cart_tracking_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT(20) UNSIGNED NOT NULL,
        cart_count INT DEFAULT 0,
        date_column DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY product_id (product_id),
        KEY idx_date (date_column),
        KEY idx_product_date (product_id, date_column)
    ) $charset_collate;";

    $sql_visited_pages = "CREATE TABLE $visited_pages_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT(20) UNSIGNED NOT NULL,
        visit_count INT DEFAULT 0,
        date_column DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY page_id (page_id),
        KEY idx_date (date_column),
        KEY idx_page_date (page_id, date_column)
    ) $charset_collate;";

    $expenses_table = $wpdb->prefix . "brikpanel_expenses";
    $sql_expenses = "CREATE TABLE $expenses_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT '',
        description TEXT,
        amount DECIMAL(20,4) NOT NULL DEFAULT 0,
        recurring VARCHAR(20) NOT NULL DEFAULT 'none',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_date (expense_date),
        KEY idx_category (category)
    ) $charset_collate;";

    // Cohort retention — monthly cohort × period_offset matrix (populated nightly)
    $cohort_table = $wpdb->prefix . "brikpanel_cohort_retention";
    $sql_cohort = "CREATE TABLE $cohort_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cohort_month DATE NOT NULL,
        period_offset TINYINT UNSIGNED NOT NULL,
        cohort_size INT UNSIGNED NOT NULL DEFAULT 0,
        retained_customers INT UNSIGNED NOT NULL DEFAULT 0,
        retention_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cohort_period (cohort_month, period_offset),
        KEY idx_cohort_month (cohort_month)
    ) $charset_collate;";

    // Customer metrics — precomputed per-customer LTV + RFM (populated nightly by Action Scheduler)
    $customer_metrics_table = $wpdb->prefix . "brikpanel_customer_metrics";
    $sql_customer_metrics = "CREATE TABLE $customer_metrics_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        customer_key VARCHAR(191) NOT NULL,
        user_id BIGINT(20) UNSIGNED DEFAULT 0,
        customer_email VARCHAR(190) NOT NULL DEFAULT '',
        customer_phone VARCHAR(40) NOT NULL DEFAULT '',
        first_order_date DATETIME NULL DEFAULT NULL,
        last_order_date DATETIME NULL DEFAULT NULL,
        order_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_spent DECIMAL(20,4) NOT NULL DEFAULT 0,
        aov DECIMAL(20,4) NOT NULL DEFAULT 0,
        recency_days INT UNSIGNED NULL DEFAULT NULL,
        r_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        f_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        m_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        rfm_segment VARCHAR(40) NULL DEFAULT NULL,
        computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_customer_key (customer_key),
        KEY idx_user_id (user_id),
        KEY idx_total_spent (total_spent),
        KEY idx_rfm_segment (rfm_segment),
        KEY idx_last_order (last_order_date)
    ) $charset_collate;";

    // Vendors (suppliers / procurement)
    $vendors_table = $wpdb->prefix . "brikpanel_vendors";
    $sql_vendors = "CREATE TABLE $vendors_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL DEFAULT '',
        contact_name VARCHAR(190) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(60) NOT NULL DEFAULT '',
        website VARCHAR(255) NOT NULL DEFAULT '',
        address TEXT,
        tax_id VARCHAR(60) NOT NULL DEFAULT '',
        default_lead_time_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        default_shipping_fee DECIMAL(20,4) NOT NULL DEFAULT 0,
        notes TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_active (is_active),
        KEY idx_name (name)
    ) $charset_collate;";

    // Stock orders (purchase orders to vendors)
    $stock_orders_table = $wpdb->prefix . "brikpanel_stock_orders";
    $sql_stock_orders = "CREATE TABLE $stock_orders_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        vendor_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        reference VARCHAR(40) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        order_date DATE NULL DEFAULT NULL,
        expected_date DATE NULL DEFAULT NULL,
        received_date DATE NULL DEFAULT NULL,
        subtotal DECIMAL(20,4) NOT NULL DEFAULT 0,
        shipping_fee DECIMAL(20,4) NOT NULL DEFAULT 0,
        tax DECIMAL(20,4) NOT NULL DEFAULT 0,
        total DECIMAL(20,4) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT '',
        notes TEXT,
        created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_reference (reference),
        KEY idx_vendor (vendor_id),
        KEY idx_status (status),
        KEY idx_order_date (order_date),
        KEY idx_received_date (received_date)
    ) $charset_collate;";

    // Stock order line items
    $stock_order_items_table = $wpdb->prefix . "brikpanel_stock_order_items";
    $sql_stock_order_items = "CREATE TABLE $stock_order_items_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT(20) UNSIGNED NOT NULL,
        product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL DEFAULT '',
        sku VARCHAR(100) NOT NULL DEFAULT '',
        qty_ordered DECIMAL(20,4) NOT NULL DEFAULT 0,
        qty_received DECIMAL(20,4) NOT NULL DEFAULT 0,
        unit_cost DECIMAL(20,4) NOT NULL DEFAULT 0,
        line_total DECIMAL(20,4) NOT NULL DEFAULT 0,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_order_id (order_id),
        KEY idx_product (product_id),
        KEY idx_variation (variation_id)
    ) $charset_collate;";

    // Ad spend — daily totals per (platform, account). Populated by the
    // Action Scheduler daily sync from Google Ads + Meta Ads APIs.
    // raw_data is a JSON column reserved for the BrikMentor AI addon, which
    // will later want to ask "why did spend spike yesterday?" — keeping the
    // upstream payload around costs little and unblocks that feature.
    $ad_spend_table = $wpdb->prefix . "brikpanel_ad_spend";
    $sql_ad_spend = "CREATE TABLE $ad_spend_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        date DATE NOT NULL,
        platform VARCHAR(20) NOT NULL DEFAULT '',
        account_id VARCHAR(64) NOT NULL DEFAULT '',
        spend_amount DECIMAL(20,4) NOT NULL DEFAULT 0,
        spend_currency VARCHAR(8) NOT NULL DEFAULT '',
        impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        raw_data LONGTEXT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_date_platform_account (date, platform, account_id),
        KEY idx_platform_date (platform, date),
        KEY idx_date (date)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_visitors);
    dbDelta($sql_cart_tracking);
    dbDelta($sql_visited_pages);
    dbDelta($sql_expenses);
    dbDelta($sql_customer_metrics);
    dbDelta($sql_cohort);
    dbDelta($sql_vendors);
    dbDelta($sql_stock_orders);
    dbDelta($sql_stock_order_items);
    dbDelta($sql_ad_spend);
}

/**
 * Enable WooCommerce's "Cost of Goods Sold" feature by default on activation.
 *
 * COGS ships disabled in WooCommerce (enabled_by_default => false). BrikPanel
 * surfaces COGS data throughout its analytics, so we flip the flag to 'yes' on
 * first activation. A one-shot marker (brikpanel_cogs_default_applied) ensures
 * we only apply this default once — if the user later disables COGS in WC
 * settings, we won't re-enable it on reactivation.
 */
function brikpanel_enable_cogs_default() {
    if (get_option('brikpanel_cogs_default_applied') === 'yes') {
        return;
    }
    update_option('woocommerce_feature_cost_of_goods_sold_enabled', 'yes');
    update_option('brikpanel_cogs_default_applied', 'yes');
}

/**
 * Run the per-site bootstrap work (create tables, set defaults, stamp
 * db_version). Called from both single-site activation and the per-blog loop
 * during network activation, as well as from `wp_initialize_site` when a new
 * subsite is created on a network where BrikPanel is already active.
 */
function brikpanel_provision_site() {
    brikpanel_create_table();
    brikpanel_enable_cogs_default();
    update_option('brikpanel_db_version', BRIKPANEL_VERSION);
}

/**
 * Activation handler. Network-aware: when the super admin clicks
 * "Network Activate", loops through every existing subsite and provisions
 * each one so its tables, defaults and db_version stamp are in place
 * immediately — without waiting for someone to first visit the subsite.
 *
 * Without this loop, the bare `register_activation_hook` callback would only
 * fire in the network admin context (blog_id = main site), leaving subsites
 * 2..N with missing tables until plugins_loaded → brikpanel_maybe_upgrade_db
 * back-fills them on first request. That back-fill still works as a safety
 * net (e.g. for sites added before this code shipped), but it left a window
 * where front-end tracking inserts and Action Scheduler handlers ran against
 * non-existent tables.
 *
 * @param bool $network_wide True when activating across the whole network.
 */
function brikpanel_activate($network_wide = false) {
    if (is_multisite() && $network_wide) {
        $site_ids = get_sites([
            'fields'                 => 'ids',
            'number'                 => 0,
            'update_site_cache'      => false,
            'update_site_meta_cache' => false,
        ]);
        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            brikpanel_provision_site();
            restore_current_blog();
        }
        return;
    }
    brikpanel_provision_site();
}
register_activation_hook(__FILE__, 'brikpanel_activate');

/**
 * Provision newly-created subsites on networks where BrikPanel is
 * network-active. Without this hook, a subsite created via
 * Network Admin → Sites → Add New would only get its tables when an admin
 * first opened a wp-admin page on it (via the plugins_loaded safety net),
 * which left the front-end tracking and cron jobs running blind in the
 * meantime.
 *
 * `wp_initialize_site` fires after the new site's tables have been created
 * and the database is ready to accept dbDelta. We skip if the plugin is not
 * network-active to avoid provisioning sites where the super admin chose
 * per-site activation only.
 *
 * @param WP_Site $new_site The newly created site object.
 */
function brikpanel_on_new_subsite($new_site) {
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_plugin_active_for_network(BRIKPANEL_BASENAME)) {
        return;
    }
    switch_to_blog((int) $new_site->blog_id);
    brikpanel_provision_site();
    restore_current_blog();
}
add_action('wp_initialize_site', 'brikpanel_on_new_subsite', 99);

/**
 * Drop a subsite's BrikPanel tables when the subsite itself is deleted.
 * Without this, the custom tables would linger as orphans after
 * `wpmu_delete_blog` finishes, since WordPress only drops core tables and
 * those registered through the `wpmu_drop_tables` filter.
 *
 * Using the filter rather than `wp_uninitialize_site` is the documented path
 * because core hands us the in-progress drop list — by appending our table
 * names we get the same prefix-aware DROP that core uses for wp_posts etc.
 *
 * @param string[] $tables  Tables core will drop.
 * @param int      $blog_id ID of the blog being deleted.
 * @return string[]
 */
function brikpanel_drop_subsite_tables($tables, $blog_id) {
    global $wpdb;
    $prefix = $wpdb->get_blog_prefix((int) $blog_id);
    foreach ([
        'brikpanel_visitors',
        'brikpanel_cart_tracking',
        'brikpanel_visited_pages',
        'brikpanel_expenses',
        'brikpanel_cohort_retention',
        'brikpanel_customer_metrics',
        'brikpanel_vendors',
        'brikpanel_stock_orders',
        'brikpanel_stock_order_items',
        'brikpanel_ad_spend',
    ] as $name) {
        $tables[] = $prefix . $name;
    }
    return $tables;
}
add_filter('wpmu_drop_tables', 'brikpanel_drop_subsite_tables', 10, 2);

/**
 * Safety net: also run the COGS default once on `plugins_loaded` so existing
 * BrikPanel installs (upgraded from a version that pre-dates this default)
 * pick up the change without requiring a manual deactivate/reactivate. The
 * one-shot flag still gates this — runs exactly once per site.
 */
add_action('plugins_loaded', 'brikpanel_enable_cogs_default', 20);

/**
 * Run table creation on plugin upgrade so existing installs pick up new
 * tables without requiring a manual deactivate/reactivate. dbDelta is
 * idempotent — re-running on every version bump only emits ALTERs when
 * the schema actually drifted.
 *
 * On version transitions that introduce the customer_metrics table, also
 * enqueue an async Action Scheduler job so the user sees populated
 * analytics immediately rather than having to wait for the nightly cron.
 */
function brikpanel_maybe_upgrade_db() {
    $stored = get_option('brikpanel_db_version');
    if ($stored === BRIKPANEL_VERSION) {
        return;
    }
    brikpanel_create_table();
    brikpanel_backfill_native_cogs();
    update_option('brikpanel_db_version', BRIKPANEL_VERSION);

    // Trigger an immediate first computation of customer metrics + cohort
    // retention. Both handlers are idempotent (UPSERT keyed on unique cols),
    // so kicking them off async is safe even if the recurring jobs also
    // run soon after.
    if (class_exists('Brikpanel_Cron')) {
        add_action('init', function () {
            if (Brikpanel_Cron::is_available()) {
                Brikpanel_Cron::enqueue_async('brikpanel_recompute_customer_metrics', [], ['unique' => true]);
                Brikpanel_Cron::enqueue_async('brikpanel_recompute_cohort_retention', [], ['unique' => true]);
            }
        }, 25);
    }
}
add_action('plugins_loaded', 'brikpanel_maybe_upgrade_db', 5);

/**
 * One-time backfill: copy WooCommerce's native Cost of Goods Sold values into
 * BrikPanel's own `_brikpanel_cogs` meta for products and variations that have
 * a native cost on file but were never saved through BrikPanel.
 *
 * BrikPanel reads cost exclusively from `_brikpanel_cogs` on the dashboard,
 * the product list and Quick Edit; the live mirror in
 * brikpanel_mirror_wc_native_cogs() keeps future native edits in step, but
 * costs entered before this release (or via WooCommerce's own product screen
 * while running an older BrikPanel) need a single catch-up pass so historical
 * data shows up immediately instead of only after the next product save.
 *
 * Runs once, guarded by its own option so a manual re-edit is never undone:
 * the WHERE clause only touches rows that have NO `_brikpanel_cogs` yet, and
 * the post_type join keeps order-level `_cogs_total_value` out of product meta.
 */
function brikpanel_backfill_native_cogs() {
    if ( get_option( 'brikpanel_native_cogs_backfilled' ) === 'yes' ) {
        return;
    }

    global $wpdb;

    // Capture the rows we are about to backfill so their per-post meta cache
    // can be invalidated afterwards (a raw INSERT bypasses WordPress's own
    // cache busting that update_post_meta() would have triggered).
    $target_ids = $wpdb->get_col(
        "SELECT wc.post_id
         FROM {$wpdb->postmeta} wc
         INNER JOIN {$wpdb->posts} p
                 ON p.ID = wc.post_id
                AND p.post_type IN ('product', 'product_variation')
         LEFT JOIN {$wpdb->postmeta} bp
                ON bp.post_id = wc.post_id
               AND bp.meta_key = '_brikpanel_cogs'
         WHERE wc.meta_key = '_cogs_total_value'
           AND wc.meta_value <> ''
           AND bp.meta_id IS NULL"
    );

    if ( empty( $target_ids ) ) {
        update_option( 'brikpanel_native_cogs_backfilled', 'yes', false );
        return;
    }

    $wpdb->query(
        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
         SELECT wc.post_id, '_brikpanel_cogs', wc.meta_value
         FROM {$wpdb->postmeta} wc
         INNER JOIN {$wpdb->posts} p
                 ON p.ID = wc.post_id
                AND p.post_type IN ('product', 'product_variation')
         LEFT JOIN {$wpdb->postmeta} bp
                ON bp.post_id = wc.post_id
               AND bp.meta_key = '_brikpanel_cogs'
         WHERE wc.meta_key = '_cogs_total_value'
           AND wc.meta_value <> ''
           AND bp.meta_id IS NULL"
    );

    // Drop the stale per-post meta cache for each backfilled product and bump
    // the shared data cache version so the dashboard recomputes COGS at once.
    foreach ( $target_ids as $pid ) {
        wp_cache_delete( (int) $pid, 'post_meta' );
    }
    if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
        brikpanel_bust_data_caches();
    }

    update_option( 'brikpanel_native_cogs_backfilled', 'yes', false );
}


// =============================================================================
// ONE-TIME MIGRATION — heal variable products stuck "out of stock"
// =============================================================================
/**
 * Older saves (and products imported or last touched in the native WC editor)
 * can leave a variable product's *parent* managing stock (`_manage_stock = yes`)
 * with a quantity of 0, which pins the parent to `outofstock`. Because
 * WC_Product_Variable::sync() trusts a parent that manages its own stock, the
 * whole product keeps reporting out of stock even when its variations carry
 * real stock. BrikPanel never manages stock at the parent level for variable
 * products (the inventory card is hidden — each variation owns its stock), so
 * this state is always a bug.
 *
 * This walks every variable product whose parent is managing stock AND is
 * marked out of stock AND has at least one in-stock variation — the exact
 * contradiction — clears the parent's stock management and re-syncs so the
 * status is derived from the variations. Stores that deliberately use
 * parent-level stock and are genuinely out of stock (no in-stock child) are
 * left untouched. Cursor-paginated and rescheduled in batches so large
 * catalogs never hit a timeout.
 */
function brikpanel_fix_variable_parent_stock() {
    if (get_option('brikpanel_var_stock_fix_done') === '1') {
        return;
    }
    if (!function_exists('wc_get_product') || !class_exists('WC_Product_Variable')) {
        // WooCommerce not ready yet — try again on a later load.
        return;
    }

    global $wpdb;
    $batch    = 200;
    $last_id  = (int) get_option('brikpanel_var_stock_fix_cursor', 0);

    // Candidate parents: product_type = variable, parent manages stock, parent
    // currently out of stock. Cursor on ID keeps each run bounded and ordered.
    $candidate_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
           INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
           INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'variable'
           INNER JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = '_manage_stock'  AND mm.meta_value = 'yes'
           INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_stock_status' AND ms.meta_value = 'outofstock'
          WHERE p.post_type = 'product'
            AND p.ID > %d
          ORDER BY p.ID ASC
          LIMIT %d",
        $last_id,
        $batch
    ));

    if (empty($candidate_ids)) {
        update_option('brikpanel_var_stock_fix_done', '1', true);
        delete_option('brikpanel_var_stock_fix_cursor');
        return;
    }

    foreach ($candidate_ids as $pid) {
        $pid     = (int) $pid;
        $product = wc_get_product($pid);
        if (!$product || !$product->is_type('variable')) {
            continue;
        }

        // Only heal the genuine contradiction: parent says out of stock but a
        // variation is actually in stock. Otherwise leave the product alone.
        $has_instock_child = false;
        foreach ($product->get_children() as $cid) {
            $child = wc_get_product($cid);
            if ($child && $child->is_in_stock()) {
                $has_instock_child = true;
                break;
            }
        }
        if (!$has_instock_child) {
            continue;
        }

        $product->set_manage_stock(false);
        $product->save();
        WC_Product_Variable::sync($product);
    }

    // Advance the cursor and continue on the next load if a full batch came
    // back (more rows may remain); otherwise we have reached the end.
    $new_cursor = (int) end($candidate_ids);
    update_option('brikpanel_var_stock_fix_cursor', $new_cursor, true);

    if (count($candidate_ids) < $batch) {
        update_option('brikpanel_var_stock_fix_done', '1', true);
        delete_option('brikpanel_var_stock_fix_cursor');
    } elseif (!wp_next_scheduled('brikpanel_fix_variable_parent_stock_event')) {
        wp_schedule_single_event(time() + 30, 'brikpanel_fix_variable_parent_stock_event');
    }
}
add_action('brikpanel_fix_variable_parent_stock_event', 'brikpanel_fix_variable_parent_stock');
// Kick off (or resume) the migration the next time an admin page loads (or when
// the rescheduled cron event fires). Front-end visitors never trigger it, so a
// large first-run batch can't slow a storefront pageview. The done-flag guard
// makes this a no-op on every request after it completes.
add_action('admin_init', 'brikpanel_fix_variable_parent_stock');


// =============================================================================
// PLUGIN ACTION LINKS — add "Settings" next to the Deactivate link
// =============================================================================
add_filter('plugin_action_links_' . BRIKPANEL_BASENAME, function ($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=brikpanel');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'brikpanel') . '</a>';
    $links[] = $settings_link;
    return $links;
});

// =============================================================================
// CONFLICT WARNING
// =============================================================================
function brik82ad_show_conflict_warning() {
    $free_plugin = 'brikpanel-admin-panel-dashboard-for-woocommerce/brikpanel-admin-panel-dashboard-for-woocommerce.php';

    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    // is_plugin_active() only checks per-blog activation. On multisite the
    // free plugin can also be Network-Activated, which is_plugin_active() does
    // not detect — without is_plugin_active_for_network() we'd miss the
    // conflict and the user would see two BrikPanel menus colliding silently.
    $conflict = is_plugin_active($free_plugin)
        || ( is_multisite() && is_plugin_active_for_network($free_plugin) );

    if ( $conflict ) {
        echo '<div class="notice notice-error brikpanel-notice" style="border-left-color:#dc3232 !important;">
<p><strong>Warning:</strong> Both <code>BrikPanel Free</code> and <code>BrikPanel Premium</code> versions are active. Please leave only one active to avoid conflicts.</p></div>';
    }
}
add_action('admin_notices', 'brik82ad_show_conflict_warning');
