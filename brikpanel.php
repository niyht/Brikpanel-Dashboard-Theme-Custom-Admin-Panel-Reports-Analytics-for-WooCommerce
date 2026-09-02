<?php
/**
 * Plugin Name: BrikPanel: WooCommerce Admin Dashboard Theme
 * Description: Beautiful and modern Shopify-style WooCommerce admin panel & dashboard, fully free, forever.
 * Version: 3.2.93
 * Author: Brksoft
 * Author URI: https://brksoft.com/
 * Text Domain: brikpanel
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * WC requires at least: 4.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
**/

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// CONSTANTS
// =============================================================================
define('BRIKPANEL_VERSION', '3.2.93');
define('BRIKPANEL_PATH', plugin_dir_path(__FILE__));
define('BRIKPANEL_URL', plugin_dir_url(__FILE__));
define('BRIKPANEL_BASENAME', plugin_basename(__FILE__));

// =============================================================================
// RESILIENT MODULE LOADER
//
// Every BrikPanel module below is pulled in with brikpanel_require() instead of
// a bare `require_once`. A bare require of a missing file throws an uncaught
// E_ERROR from wp-settings.php, which white-screens the ENTIRE site (front end
// and wp-admin alike), leaving the merchant locked out. That is exactly what a
// partial/interrupted plugin update or an over-eager security scanner (which can
// quarantine a single .php file) triggers: one absent module kills everything.
//
// brikpanel_require() checks the file exists and is readable first. When a
// module is missing it is skipped, the path is logged, and a single dismissible
// admin notice tells the merchant to reinstall. The site stays up and the
// remaining modules keep working. Standard require_once dedup still applies
// (tracked globally regardless of call scope); these module files only define
// functions and register hooks at top level, so the wrapper does not alter their
// runtime behaviour.
// =============================================================================
function brikpanel_require($relative_path) {
    $full_path = BRIKPANEL_PATH . $relative_path;

    if (is_readable($full_path)) {
        require_once $full_path;
        return true;
    }

    // Record the missing module so the admin notice can list it, and leave a
    // trail in the error log for support. The notice is wired up only once.
    $GLOBALS['brikpanel_missing_modules'][] = $relative_path;

    if (function_exists('error_log')) {
        error_log('BrikPanel: required module missing or unreadable: ' . $full_path);
    }

    if (!has_action('admin_notices', 'brikpanel_missing_modules_notice')) {
        add_action('admin_notices', 'brikpanel_missing_modules_notice');
        add_action('network_admin_notices', 'brikpanel_missing_modules_notice');
    }

    return false;
}

/**
 * Admin notice shown when one or more BrikPanel modules could not be loaded.
 * Points the merchant at the fix (reinstall) without exposing a fatal error.
 */
function brikpanel_missing_modules_notice() {
    if (!current_user_can('activate_plugins')) {
        return;
    }

    $missing = isset($GLOBALS['brikpanel_missing_modules']) ? array_unique($GLOBALS['brikpanel_missing_modules']) : array();
    if (empty($missing)) {
        return;
    }

    echo '<div class="notice notice-error brikpanel-notice"><p><strong>'
        . esc_html__('BrikPanel could not load all of its files.', 'brikpanel')
        . '</strong> '
        . esc_html__('Some plugin files are missing, most likely from an interrupted update or a security scan. BrikPanel is running in a limited mode. Please reinstall the plugin (deactivate, delete, then install the latest version again) to restore full functionality. Your settings and data are kept.', 'brikpanel')
        . '</p><p><code>'
        . implode('</code>, <code>', array_map('esc_html', $missing))
        . '</code></p></div>';
}

// =============================================================================
// MULTIBYTE-SAFE STRING HELPERS
//
// First module in, before anything that could touch a term name, a product
// title or an admin label. `mbstring` is an optional PHP extension that
// WordPress and WooCommerce both run without, so a bare mb_* call is a fatal
// waiting for the first store on a build that lacks it. See the file header
// for the wp.org report this closes.
// =============================================================================
brikpanel_require('includes/brikpanel-str.php');

// =============================================================================
// NETWORK ACCESS RULES (multisite-only Super Admin gate)
//
// Loaded *before* the WooCommerce dependency guard below so the Network Admin
// settings page stays reachable even on networks whose main site does not
// have WooCommerce active. The module is a no-op on single-site installs.
// =============================================================================
brikpanel_require('includes/brikpanel-network-access.php');

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
// OPTION CACHE PRIMING (must run before the first module require below)
// =============================================================================
/**
 * Collapse BrikPanel's per-request option reads into a single query.
 *
 * This has to happen here, at file scope, and not on a hook: the modules
 * required further down read their gate options WHILE BEING INCLUDED, long
 * before plugins_loaded fires. It sits after the WooCommerce guard above so a
 * WC-less multisite subsite (which returns at that guard) never pays for it.
 *
 * See includes/brikpanel-option-prime.php for what is primed and why.
 */
brikpanel_require('includes/brikpanel-option-prime.php');
if (function_exists('brikpanel_prime_option_caches')) {
    brikpanel_prime_option_caches();
}

/**
 * Safety net for brikpanel_update_option().
 *
 * brikpanel_require() deliberately does NOT fatal when a module file is
 * missing — it logs, shows an admin notice and lets the rest of the plugin
 * keep working. The two entry points above respect that with function_exists()
 * guards, but the thirteen brikpanel_update_option() call sites scattered
 * across the migrations do not, and the first of them runs on plugins_loaded
 * during an ordinary admin pageview. Without this fallback a half-finished
 * upload of one file turns the soft-fail design into a white screen.
 *
 * The fallback keeps BrikPanel's default of autoload=off, which is the safe
 * direction: the worst case is a marker costing one query per request until
 * the next version bump re-asserts the policy.
 */
if (!function_exists('brikpanel_update_option')) {
    function brikpanel_update_option($option, $value) {
        return update_option($option, $value, false);
    }
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
// CUSTOM ORDER STATUSES
//
// The two legacy BrikPanel statuses — "Return Draft" (return-draft) and
// "Change" (change) — used to be hard-coded here and registered on every
// install with no way to switch them off. They are now folded into the
// editable custom-status system (WooCommerce > Settings > BrikPanel > Order
// statuses) by a one-time migration, so merchants can recolour, keep or remove
// them like any status they create themselves. Both the post-status
// registration and the wc_order_statuses filter now live in
// front-end/order-statuses/brikpanel-order-statuses.php, driven entirely by the
// brikpanel_custom_order_statuses option (see brikpanel_cos_migrate_legacy_statuses).
// =============================================================================

// =============================================================================
// USER-DEFINED CUSTOM ORDER STATUSES (settings UI + global registration)
//
// Loaded outside brikpanel_init_admin() so the statuses register on the front
// end too (checkout/programmatic orders), not only inside wp-admin.
// =============================================================================
brikpanel_require('front-end/order-statuses/brikpanel-order-statuses.php');

// Status-change emails: a configurable email per custom status (recipients,
// subject, heading, body with {placeholders}), sent through WooCommerce's own
// email templates. Loaded globally so the sender fires on every request where an
// order status can change (checkout, cron, REST); only its settings UI is
// admin-gated. See front-end/order-statuses/brikpanel-status-emails.php.
brikpanel_require('front-end/order-statuses/brikpanel-status-emails.php');

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
    brikpanel_require('includes/brikpanel-desktop-mode-compat.php');

    // Front-end files (for admin)
    brikpanel_require('includes/brikpanel-cache-clear.php');
    brikpanel_require('includes/brikpanel-remove-help.php');
    brikpanel_require('front-end/dashboard/brikpanel-dashboard.php');
    brikpanel_require('front-end/dashboard/brikpanel-dashboard-section-order.php');
    brikpanel_require('front-end/dashboard/brikpanel-dashboard-topbar.php');
    brikpanel_require('front-end/dashboard/brikpanel-topbar-items.php');
    brikpanel_require('front-end/dashboard/brikpanel-dashboard-widget-access.php');
    brikpanel_require('front-end/master-switch/brikpanel-master-switch.php');
    // Pure sidebar-icon helpers — loaded unconditionally because the navigation
    // customizer (below) needs them even when the modern navigation module is
    // skipped. Must come before both requires so either can rely on them.
    brikpanel_require('front-end/navigation/brikpanel-nav-icon-helpers.php');
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        brikpanel_require('front-end/navigation/brikpanel-navigation.php');
    }
    // Sidebar customizer (settings UI + render-time application). Loaded even
    // when modern navigation is off so admins can pre-configure the layout
    // before flipping the toggle.
    brikpanel_require('front-end/navigation/brikpanel-nav-customizer.php');
    // Command palette: the module file is required outside this gate (see
    // below) so its per-user index cleanup hooks exist on WP-CLI / REST user
    // deletions too. The class itself still only boots on admin requests.
    brikpanel_require('front-end/orders/brikpanel-orders.php');
    // BrikMentor launch surfaces (promo FAB, dashboard/settings CTAs) live in
    // includes/brikpanel-brikmentor-promo.php + includes/brikpanel-early-access.php.
    // Purchase is a plain link to the brksoft.com relay checkout; the plugin is
    // downloaded and installed by the merchant from the relay's own welcome page,
    // never pushed from inside wp-admin (keeps BrikPanel within wp.org Guideline 8).
    brikpanel_require('front-end/orders/brikpanel-order-whatsapp.php');
    brikpanel_require('front-end/orders/brikpanel-orders-stats.php');
    brikpanel_require('front-end/currency/brikpanel-currency-settings.php');
    brikpanel_require('front-end/order/brikpanel-order-fields.php');
    brikpanel_require('front-end/order/brikpanel-order-shipping-cost.php');
    brikpanel_require('front-end/import-export/brikpanel-import-export.php');
    brikpanel_require('front-end/products/brikpanel-section-order.php');
    brikpanel_require('front-end/products/brikpanel-qe-order.php');
    brikpanel_require('front-end/products/brikpanel-blocksy-video.php');
    brikpanel_require('front-end/products/brikpanel-product-editor.php');
    brikpanel_require('front-end/products/brikpanel-products-list.php');
    brikpanel_require('front-end/products/brikpanel-product-code.php');
    brikpanel_require('front-end/products/brikpanel-category-enhancements.php');
    brikpanel_require('front-end/coupons/brikpanel-coupons.php');
    brikpanel_require('front-end/segments/brikpanel-segments.php');
    brikpanel_require('front-end/customer-analytics/brikpanel-customer-analytics.php');
    brikpanel_require('front-end/expenses/brikpanel-expenses.php');
    brikpanel_require('front-end/vendors/brikpanel-vendors.php');

    // Back-end files
    brikpanel_require('back-end/total-sales/brikpanel-total-sales.php');
    brikpanel_require('back-end/conversion-count/brikpanel-total-orders.php');
    brikpanel_require('back-end/order-value/brikpanel-order-value.php');
    brikpanel_require('back-end/order-rates/brikpanel-order-rates.php');
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
                    || preg_match('#\bclass=(["\'])[^"\']*\bhidden\b[^"\']*\1#i', $open_tag)
                    // WordPress/WooCommerce first-party "updated" confirmation
                    // (id="message", e.g. "Order updated.") — keep it inline.
                    || preg_match('#\bid=(["\'])message\1#i', $open_tag);

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
            // WordPress/WooCommerce print their own first-party "updated"
            // confirmation bar with id="message" (e.g. "Order updated.", "Post
            // updated.") as a direct child of `.wrap` — the action feedback the
            // user is waiting for after saving. Never hide it, on any screen.
            $msg_guard = ':not(#message)';
            $err_lines = $hide_errors ? '
                .wp-admin #wpbody-content > .error:not(.brikpanel-notice):not(.hidden):not(#lost-connection-notice)' . $msg_guard . ',
                .wp-admin .wrap > .error:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden):not(#lost-connection-notice)' . $msg_guard . ',' : '';
            echo '<style>
                .wp-admin #wpbody-content > .notice:not(.brikpanel-notice):not(.hidden)' . $err_guard . $msg_guard . ':not(#lost-connection-notice):not(#local-storage-notice),
                .wp-admin #wpbody-content > .updated:not(.brikpanel-notice):not(.hidden)' . $msg_guard . ',' . $err_lines . '
                .wp-admin .wrap > .notice:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden)' . $err_guard . $msg_guard . ':not(#lost-connection-notice):not(#local-storage-notice),
                .wp-admin .wrap > .updated:not(.brikpanel-notice):not(.inline):not(.below-h2):not(.hidden)' . $msg_guard . ' {
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
        /* These two glyphs are outline SVGs drawn with the `fill="none"
           stroke="currentColor"` presentation attributes, and they render deep
           inside the page body where another plugins screen stylesheet is most
           likely to carry a blanket `svg`/`svg path` rule. Presentation
           attributes lose to any author CSS, so restate the drawing model here
           or the bell silently paints itself away on that one screen. Mirrors
           the guard in front-end/topbar/brikpanel-topbar.css. */
        .brikpanel-foreign-notices svg[fill="none"] { fill: none !important; }
        .brikpanel-foreign-notices svg[stroke="currentColor"] { stroke: currentColor !important; }
        .brikpanel-foreign-notices svg[fill="none"] *:not([fill]) { fill: none !important; }
        .brikpanel-foreign-notices svg[stroke="currentColor"] *:not([stroke]) { stroke: currentColor !important; }
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
brikpanel_require('front-end/login/brikpanel-login.php');

// =============================================================================
// APPEARANCE CUSTOMIZATION (font + accent color)
// Loaded globally so the override CSS applies to both wp-admin and the
// modern login page. Registers its own hooks (settings field, admin_head,
// login_head, admin_enqueue_scripts).
// =============================================================================
brikpanel_require('front-end/appearance/brikpanel-appearance.php');

// =============================================================================
// FRONT-END & GENERAL FILES
// =============================================================================
function brikpanel_init_other() {
    brikpanel_require('front-end/products/brikpanel-variation-gallery.php');
    brikpanel_require('front-end/products/brikpanel-short-description.php');
    brikpanel_require('front-end/products/brikpanel-description-lightbox.php');
    if ( get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no' ) {
        brikpanel_require('front-end/brikpanel-site-submenu.php');
    }
    brikpanel_require('front-end/sound/brikpanel-sound.php');
    brikpanel_require('back-end/conversion-count/brikpanel-conversion-count.php');
    brikpanel_require('back-end/conversion-count/brikpanel-product-count.php');
    brikpanel_require('back-end/conversion-count/brikpanel-checkout-count.php');
    brikpanel_require('back-end/conversion-count/brikpanel-add-to-cart-count.php');
    brikpanel_require('back-end/most-count/most-add-to-cart/brikpanel-most-add-to-cart.php');
    brikpanel_require('back-end/most-count/most-sale/brikpanel-most-sale.php');
    brikpanel_require('back-end/most-count/most-view/brikpanel-most-view.php');
    brikpanel_require('back-end/live/brikpanel-live.php');
    brikpanel_require('back-end/tracking/brikpanel-unified-tracker.php');
}
add_action('init', 'brikpanel_init_other');

// =============================================================================
// WELCOME / FEATURE SHOWCASE POPUP
// =============================================================================
brikpanel_require('front-end/welcome/brikpanel-welcome.php');

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================
brikpanel_require('includes/brikpanel-helpers.php');
brikpanel_require('includes/brikpanel-currency.php');
brikpanel_require('includes/brikpanel-profit.php');

// =============================================================================
// WOOCOMMERCE PRODUCT LOOKUP GUARD
//
// Loaded at file scope, before `init`, for two reasons. Its `deleted_post`
// listener has to be registered before anything can delete a product, and cron,
// WP-CLI and the REST API all delete on hooks that fire long before
// brikpanel_init_admin() runs. And brikpanel_sku_guard_track_ids() has to exist
// by the time the products list module (which calls it from fast delete) is
// required on `init`.
// =============================================================================
brikpanel_require('includes/brikpanel-sku-lookup-guard.php');

// =============================================================================
// COOKIE-CONSENT GATE FOR STOREFRONT TRACKING
//
// Loaded at file scope, before `init`, for the same reason as the bot filter
// below: the storefront counters that ask it (add-to-cart, checkout) run on
// very early hooks, and the WP Consent API compatibility declaration has to
// be in place before Site Health reads it.
// =============================================================================
brikpanel_require('includes/brikpanel-consent.php');

// Safety net for the one case brikpanel_require() cannot cover on its own.
// Every storefront counter now asks brikpanel_frontend_tracking_allowed() in
// the codebase's defensive `function_exists( … ) && ! …()` form, which opens
// the gate when the function is missing. That is the right default for an
// optional module, but this module also carries the MASTER switch decision:
// if the file above ever failed to load, "Visitor tracking: off" would
// silently stop being honoured on eight of the nine gates. So when the real
// implementation is absent, stand in a minimal one that still respects the
// master switch and simply skips the consent layer.
if ( ! function_exists( 'brikpanel_frontend_tracking_allowed' ) ) {
    /**
     * Fallback gate used only when includes/brikpanel-consent.php is missing.
     *
     * @param string $context Unused here; kept for signature compatibility.
     * @return bool
     */
    function brikpanel_frontend_tracking_allowed( $context = '' ) {
        return ! function_exists( 'brikpanel_frontend_tracking_enabled' )
            || brikpanel_frontend_tracking_enabled();
    }
}

// =============================================================================
// BOT / CRAWLER FILTER FOR STOREFRONT ANALYTICS
//
// Loaded at file scope, before `init`, because the storefront counters that
// call it (add-to-cart, checkout, cart abandonment) can fire on very early
// hooks. Shared by every tracker so there is exactly one list to maintain.
// =============================================================================
brikpanel_require('includes/brikpanel-bot-filter.php');

// =============================================================================
// ACCESS CONTROL — per-user / per-role interface gate
//
// Loaded before `init` so its `pre_option_*` neutralization filters are in
// place by the time brikpanel_init_admin() / brikpanel_init_other() read the
// interface toggles to decide which modules to load.
// =============================================================================
brikpanel_require('includes/brikpanel-access-control.php');

// =============================================================================
// THIRD-PARTY COMPATIBILITY: ASE (Admin and Site Enhancements) bridge
// =============================================================================
brikpanel_require('includes/brikpanel-ase-bridge.php');

// =============================================================================
// THIRD-PARTY COMPATIBILITY: Admin Columns bridge
// =============================================================================
brikpanel_require('includes/brikpanel-admin-columns-bridge.php');

// =============================================================================
// ENQUEUE SCRIPTS & STYLES
// =============================================================================
brikpanel_require('includes/brikpanel-enqueue.php');

// =============================================================================
// FOREIGN ASSET ISOLATION
// Keep other plugins' (and the theme's) render-blocking payloads off BrikPanel's
// own full-screen app pages, where they serve no purpose and only slow the page.
// =============================================================================
brikpanel_require('includes/brikpanel-asset-isolation.php');

// =============================================================================
// DEVELOPER HOOKS API (public actions/filters + docs modal)
// =============================================================================
brikpanel_require('includes/brikpanel-hooks-api.php');

// =============================================================================
// REVIEW REQUEST NOTICES (50 completed orders)
// =============================================================================
brikpanel_require('includes/brikpanel-review-notices.php');

// =============================================================================
// BRIKMENTOR EARLY-ACCESS CAPTURE (100 / 200 completed orders)
// =============================================================================
brikpanel_require('includes/brikpanel-early-access.php');

// =============================================================================
// BRIKMENTOR LAUNCH SURFACES (behind the brikpanel_brikmentor_live flag,
// default off; also flips the early-access waitlist into launch CTAs)
// =============================================================================
brikpanel_require('includes/brikpanel-brikmentor-promo.php');

// =============================================================================
// CRON / BACKGROUND JOBS (Action Scheduler wrapper + admin page)
// =============================================================================
brikpanel_require('includes/cron/brikpanel-cron.php');
brikpanel_require('includes/cron/customer-analytics-jobs.php');

// =============================================================================
// BRIKCONTROL — Store Health panel (loaded outside the is_admin gate so the
// Action Scheduler worker can resolve registered handlers when running over
// WP-Cron / CLI). Admin menu + AJAX hooks self-gate to admin context.
// =============================================================================
brikpanel_require('front-end/brikcontrol/brikpanel-brikcontrol.php');

// =============================================================================
// COMMAND PALETTE — loaded outside the is_admin gate so the per-user
// navigation-index cleanup hooks (delete_user, wpmu_delete_user,
// remove_user_from_blog, wp_delete_site) are registered on WP-CLI and REST
// requests too. Those rows have no TTL, so a deletion path that misses them
// leaks ~30 KB per user forever. The palette class itself self-gates to
// is_admin() at the bottom of that file.
// =============================================================================
brikpanel_require('front-end/search/brikpanel-search.php');

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
brikpanel_require('front-end/google-sheets/brikpanel-google-sheets.php');

// =============================================================================
// AD PLATFORMS — Google Ads + Meta Ads daily-spend integration.
//
// Loaded outside the is_admin gate (like Google Sheets) because Action
// Scheduler workers running under WP-Cron / CLI need the sync + backfill
// handlers registered to dispatch the daily pull. Admin menu, AJAX
// endpoints, and asset enqueue all self-gate to admin context inside the
// module classes.
// =============================================================================
brikpanel_require('front-end/ad-platforms/brikpanel-ad-platforms.php');

// =============================================================================
// CART ABANDONMENT — checkout/popup email capture with cart snapshots.
//
// Loaded outside the is_admin gate because everything interesting happens on
// front-end requests: the public capture AJAX endpoint, the
// woocommerce_cart_updated snapshot mirror, and the order-recovery hooks that
// fire during checkout. The admin list page, exports and settings fields all
// self-gate to admin context inside the class.
// =============================================================================
brikpanel_require('front-end/cart-abandonment/brikpanel-cart-abandonment.php');

// =============================================================================
// CART SHARE — shareable cart links + admin cart builder.
//
// Loaded outside the is_admin gate because the link consumer (wp_loaded), the
// storefront "Share cart" button (wp_enqueue_scripts) and its public AJAX all
// run on front-end requests. The admin builder page, its search/variation AJAX
// and the settings fields self-gate to admin context inside the class.
// =============================================================================
brikpanel_require('front-end/cart-share/brikpanel-cart-share.php');

// =============================================================================
// STORE SUMMARY (on-demand Markdown digest, triggered from dashboard "Copy
// everything" button — no cron, generated on click only)
// =============================================================================
if ( is_admin() ) {
    brikpanel_require('includes/brikpanel-store-summary.php');
}

// =============================================================================
// DATABASE TABLE CREATION
// =============================================================================
function brikpanel_create_table() {
    global $wpdb;

    $visitors_table       = $wpdb->prefix . "brikpanel_visitors";
    $cart_tracking_table  = $wpdb->prefix . "brikpanel_cart_tracking";
    $visited_pages_table  = $wpdb->prefix . "brikpanel_visited_pages";
    $referrers_table      = $wpdb->prefix . "brikpanel_referrers";
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

    // object_type (3.2.41) separates a post ID from a term ID sharing the same
    // number. Before it, archive views had nowhere to go and were credited to
    // whichever product the theme's loop stopped on. Existing rows take the
    // 'post' default, which is what they always were.
    //
    // Deliberately NOT indexed: it holds two values, so it is useless as a
    // leading column, and the recorder's lookup already resolves to a single
    // row through idx_page_date with object_type applied as a filter on it.
    // An extra index here would only cost write throughput on every view.
    $sql_visited_pages = "CREATE TABLE $visited_pages_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        page_id BIGINT(20) UNSIGNED NOT NULL,
        object_type VARCHAR(20) NOT NULL DEFAULT 'post',
        visit_count INT DEFAULT 0,
        date_column DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY page_id (page_id),
        KEY idx_date (date_column),
        KEY idx_page_date (page_id, date_column)
    ) $charset_collate;";

    // Daily traffic-source roll-up: one row per (day, channel, host) with a hit
    // counter. Powers the dashboard "Traffic Sources" card (channel bars + top
    // referrers). host = '' for direct / campaign-without-referrer rows. The
    // UNIQUE key lets the tracker upsert atomically (INSERT ... ON DUPLICATE KEY).
    $sql_referrers = "CREATE TABLE $referrers_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        date_column DATE NOT NULL,
        channel VARCHAR(20) NOT NULL DEFAULT 'direct',
        host VARCHAR(190) NOT NULL DEFAULT '',
        hits INT DEFAULT 0,
        UNIQUE KEY uniq_day_channel_host (date_column, channel, host),
        KEY idx_date (date_column),
        KEY idx_channel_date (channel, date_column)
    ) $charset_collate;";

    $expenses_table = $wpdb->prefix . "brikpanel_expenses";
    // recurring_parent links an auto-generated occurrence back to its recurring
    // template (0 = a standalone entry or the template itself). The materialiser
    // turns one "monthly/weekly/yearly" template into concrete dated rows so the
    // profit aggregation (which just sums rows by date) stays unchanged.
    // Naming, because the pair reads oddly: `category` is the legacy column and
    // is what the UI calls the expense "Title" ("Rent", "Salaries"). The later
    // `parent_category` is the optional grouping ABOVE it ("Operations"), which
    // is what the UI calls "Category". Rows written before it existed keep ''
    // and render exactly as they always did — a flat line in the breakdown.
    // `scope` only ever carries a value on kind='per_order' rows and answers
    // "which orders is this charged on": '' (every order), 'free_shipping', or
    // 'shipping_class:<term_id>'. One opaque token rather than a type/value pair
    // because the value space is closed and tiny, and it is already exactly what
    // the "Applies to" <select> emits.
    $sql_expenses = "CREATE TABLE $expenses_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT '',
        parent_category VARCHAR(100) NOT NULL DEFAULT '',
        description TEXT,
        amount DECIMAL(20,4) NOT NULL DEFAULT 0,
        recurring VARCHAR(20) NOT NULL DEFAULT 'none',
        recurring_parent BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        kind VARCHAR(10) NOT NULL DEFAULT 'fixed',
        scope VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_date (expense_date),
        KEY idx_category (category),
        KEY idx_parent_category (parent_category),
        KEY idx_recurring_parent (recurring_parent)
    ) $charset_collate;";

    // One row per occurrence the merchant removed from a recurring expense.
    // Kept OUT of brikpanel_expenses deliberately: an in-table tombstone would
    // have to be excluded from every unfiltered SUM of that table, and both
    // "delete the children and rebuild" paths (saving an expense, or the Google
    // Sheets sync updating one) would wipe it — so editing only a template's
    // amount would resurrect an occurrence the merchant had deleted.
    // tpl_created_at pins each skip to the exact template that owned the id:
    // MariaDB does not persist AUTO_INCREMENT across a restart, so a recycled
    // id must never inherit another expense's skipped dates.
    $expense_skips_table = $wpdb->prefix . "brikpanel_expense_skips";
    $sql_expense_skips = "CREATE TABLE $expense_skips_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        template_id BIGINT(20) UNSIGNED NOT NULL,
        skip_date DATE NOT NULL,
        tpl_created_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_template_date (template_id, skip_date),
        KEY idx_template (template_id)
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
        KEY idx_customer_email (customer_email),
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

    // Abandoned carts — one row per (browser, email) capture from checkout /
    // popup / logged-in account, with a JSON cart snapshot. Lifecycle:
    // active → abandoned (no activity for N minutes) → recovered (order
    // placed). Rows are deduped in code on (visitor_id, email, non-recovered)
    // so a recovered row stays as history when the same browser starts a new
    // cart. See front-end/cart-abandonment/.
    // idx_cart_total backs the "highest / lowest cart value" sort on the list
    // screen, so hunting the big abandoned carts does not filesort the table.
    $abandoned_carts_table = $wpdb->prefix . "brikpanel_abandoned_carts";
    $sql_abandoned_carts = "CREATE TABLE $abandoned_carts_table (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        visitor_id VARCHAR(64) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        phone VARCHAR(40) NOT NULL DEFAULT '',
        phone_country VARCHAR(2) NOT NULL DEFAULT '',
        user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'checkout',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        cart_items LONGTEXT NULL,
        item_count INT UNSIGNED NOT NULL DEFAULT 0,
        cart_total DECIMAL(20,4) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT '',
        order_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        abandoned_at DATETIME NULL DEFAULT NULL,
        recovered_at DATETIME NULL DEFAULT NULL,
        announced_sig CHAR(32) NULL DEFAULT NULL,
        announced_at DATETIME NULL DEFAULT NULL,
        KEY idx_visitor_email (visitor_id, email),
        KEY idx_email (email),
        KEY idx_status_updated (status, updated_at),
        KEY idx_created (created_at),
        KEY idx_cart_total (cart_total)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_visitors);
    dbDelta($sql_cart_tracking);
    dbDelta($sql_visited_pages);
    dbDelta($sql_referrers);
    dbDelta($sql_expenses);
    dbDelta($sql_expense_skips);
    dbDelta($sql_customer_metrics);
    dbDelta($sql_cohort);
    dbDelta($sql_vendors);
    dbDelta($sql_stock_orders);
    dbDelta($sql_stock_order_items);
    dbDelta($sql_ad_spend);
    dbDelta($sql_abandoned_carts);

    // Stamp the moment the recurring-expense engine became available. Only
    // expense templates created at or after this point are auto-materialised
    // into per-period rows, so pre-existing "monthly"-tagged rows (which were
    // historically just a cosmetic label and only ever counted once on their
    // own date) keep aggregating exactly as before — no retroactive change.
    if ( false === get_option( 'brikpanel_recurring_engine_since', false ) ) {
        add_option( 'brikpanel_recurring_engine_since', gmdate( 'Y-m-d H:i:s' ), '', false );
    }
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
 * Turn "Subtract shipping cost from Net profit" on by default.
 *
 * The setting originally shipped off because switching it on moves every
 * store's Net profit. In practice that caution had the opposite effect: an
 * order shipped free deducts nothing for the courier, so Net profit was
 * reported HIGHER than it really is, and almost nobody ever found the switch.
 * On by default is the honest number.
 *
 * Only ever writes when the option has NO row at all. A store already holding
 * 'no' decided (or had it decided by saving the Analytics section) and is left
 * exactly as it is. The one-shot marker makes this run once per site whatever
 * route brought it here, so a merchant who turns the feature back off can never
 * have that undone by a later upgrade or a reactivation.
 *
 * Mirrors brikpanel_enable_cogs_default() above.
 */
function brikpanel_enable_shipping_cost_default() {
    if (get_option('brikpanel_shipping_cost_default_applied') === 'yes') {
        return;
    }
    // add_option(), not update_option(): it is a no-op when a row already
    // exists, which IS the "an existing 'no' keeps 'no'" rule, in one query with
    // no read-then-write race. It also fires
    // add_option_brikpanel_shipping_cost_enabled, already wired to
    // brikpanel_bust_data_caches() in includes/brikpanel-profit.php, so the
    // stale _sc0 dashboard payloads and bp_rev_* transients are invalidated BY
    // THE WRITE ITSELF. Autoloaded, matching what WooCommerce's own checkbox
    // handler writes.
    add_option('brikpanel_shipping_cost_enabled', 'yes', '', true);
    update_option('brikpanel_shipping_cost_default_applied', 'yes', false);
}

/**
 * Turn "Count payment processing fees as an expense" on by default.
 *
 * The fee Stripe/PayPal deducted is already sitting on the order; leaving it
 * unread reports Net profit HIGHER than it really is. Off by default would mean
 * the number stays wrong for everyone who never finds the switch, which is the
 * same mistake shipping cost made above.
 *
 * Same one-shot mechanics as brikpanel_enable_shipping_cost_default(): add_option()
 * is a no-op when a row exists, so a merchant who turned this off keeps it off,
 * and the marker means neither an upgrade nor a reactivation can undo that
 * decision. add_option_brikpanel_payment_fees_enabled is wired to
 * brikpanel_bust_data_caches() in includes/brikpanel-profit.php, so the stale
 * _pf0 dashboard payloads are invalidated by the write itself.
 */
function brikpanel_enable_payment_fees_default() {
    if (get_option('brikpanel_payment_fees_default_applied') === 'yes') {
        return;
    }
    add_option('brikpanel_payment_fees_enabled', 'yes', '', true);
    update_option('brikpanel_payment_fees_default_applied', 'yes', false);
}

/**
 * Run the per-site bootstrap work (create tables, set defaults, stamp
 * db_version). Called from both single-site activation and the per-blog loop
 * during network activation, as well as from `wp_initialize_site` when a new
 * subsite is created on a network where BrikPanel is already active.
 */
function brikpanel_provision_site() {
    // A truly fresh install has no db_version stamped yet; an existing site
    // being reactivated already does. We capture this before stamping below.
    $is_fresh_install = ( false === get_option('brikpanel_db_version') );

    brikpanel_create_table();
    brikpanel_enable_cogs_default();
    brikpanel_enable_shipping_cost_default();
    brikpanel_enable_payment_fees_default();
    // Stores that already had WooCommerce's native Cost of Goods populated
    // before installing BrikPanel need the catch-up pass here too: stamping
    // db_version below makes brikpanel_maybe_upgrade_db() return early on
    // every later request, so activation is the only place a fresh install
    // would ever reach the backfill.
    brikpanel_backfill_native_cogs();
    brikpanel_unify_cogs_to_native();
    update_option('brikpanel_db_version', BRIKPANEL_VERSION);

    // Fresh installs start with a clean status list: pre-set the one-time
    // legacy-status migration flag so brikpanel_cos_migrate_legacy_statuses()
    // never seeds the old "Return Draft" / "Change" pair. Existing installs
    // (flag absent) still receive them once on upgrade for backward compat,
    // and can then remove them from the Order statuses settings screen.
    if ($is_fresh_install) {
        update_option('brikpanel_cos_legacy_migrated', 1);
    }
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
        'brikpanel_expense_skips',
        'brikpanel_cohort_retention',
        'brikpanel_customer_metrics',
        'brikpanel_vendors',
        'brikpanel_stock_orders',
        'brikpanel_stock_order_items',
        'brikpanel_ad_spend',
        'brikpanel_abandoned_carts',
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
 * Same safety net for the shipping-cost default. This is the path that reaches
 * existing installs on upgrade; brikpanel_maybe_upgrade_db() (priority 5) is
 * the wrong host because it early-returns as soon as db_version matches, which
 * is the state of every store that has already run the upgrade.
 *
 * Deliberately not gated on is_admin(): the marker is one autoloaded read once
 * written, and a cron or REST request applying it first is perfectly fine.
 */
add_action('plugins_loaded', 'brikpanel_enable_shipping_cost_default', 20);

/**
 * Safety net for the native COGS backfill. The version-transition path in
 * brikpanel_maybe_upgrade_db() is skipped whenever brikpanel_db_version
 * already equals the current version — which is exactly the state a fresh
 * install lands in, and the state of any site whose activation predates the
 * provisioning call above. Those stores show Cost of Goods £0.00 even though
 * WooCommerce's own `_cogs_total_value` is populated on hundreds of products.
 *
 * Gating on the one-shot marker option (rather than the version transition)
 * makes this run exactly once per site, whatever route brought it here.
 * Admin-only so no front-end request ever pays for the guard or the pass.
 */
function brikpanel_backfill_native_cogs_safety_net() {
    if (!is_admin() || wp_doing_ajax()) {
        return;
    }
    brikpanel_backfill_native_cogs();
    brikpanel_unify_cogs_to_native();
}
add_action('plugins_loaded', 'brikpanel_backfill_native_cogs_safety_net', 20);

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
    brikpanel_unify_cogs_to_native();
    // Existing installs never run brikpanel_provision_site() again, so a new
    // default that must reach them has to be seeded here too. The one-shot
    // marker inside makes running it from both call sites harmless.
    brikpanel_enable_payment_fees_default();
    brikpanel_cartab_dedupe_recovery_credit();
    // Re-assert autoload on the write-once markers. Idempotent, and running it
    // on every version bump means a call site that drifts one back to
    // autoload=off self-corrects on the next release instead of silently
    // costing a query per request forever.
    if (function_exists('brikpanel_apply_option_autoload_policy')) {
        brikpanel_apply_option_autoload_policy();
    }
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
// Priority 6: after the upgrade pass above has had its chance to create the
// table, but registered separately so the version gate inside that function
// cannot swallow the repair. See brikpanel_cartab_repair_failed_recoveries().
add_action('plugins_loaded', 'brikpanel_cartab_repair_failed_recoveries', 6);
// Priority 7: after the revert pass above, which puts rows back to 'abandoned'
// on its own terms. Running first would make this one judge rows that pass is
// about to move anyway.
add_action('plugins_loaded', 'brikpanel_cartab_repair_zeroed_rows', 7);

/**
 * One-time correction: leave one credited cart row per recovering order.
 *
 * Until this release, an order stamped its id on *every* open cart row that
 * matched the shopper — a popup signup, a checkout capture and an older
 * abandoned cart could all be credited with the same single sale. The
 * Abandoned Carts stat cards counted each of those rows and added every one of
 * their cart totals to the recovered value, so both figures ran high on any
 * store where shoppers were captured more than once.
 *
 * mark_recovered() no longer does that. This pass applies the same rule to the
 * rows already on disk: the best row per order keeps the credit, its siblings
 * drop to order_id = 0. Nothing is deleted and no status changes, so the rows
 * stay in the list (badged "Converted") and outreach stays cancelled.
 *
 * Runs once, guarded by brikpanel_cartab_credit_dedupe_done.
 */
function brikpanel_cartab_dedupe_recovery_credit() {
    if (get_option('brikpanel_cartab_credit_dedupe_done') === '1') {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return; // Cart abandonment tables not installed yet; nothing to correct.
    }

    // Same precedence mark_recovered() now uses when choosing which row to
    // credit: a cart with items beats a bare email signup, one that really was
    // abandoned beats one that never was, most recent wins among equals.
    $rows = $wpdb->get_results(
        "SELECT id, order_id FROM {$table}
         WHERE status = 'recovered' AND order_id > 0
         ORDER BY order_id ASC,
                  (item_count > 0) DESC,
                  (abandoned_at IS NOT NULL) DESC,
                  updated_at DESC,
                  id DESC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );

    $keeper = [];
    $losers = [];
    foreach ((array) $rows as $row) {
        $order_id = (int) $row->order_id;
        if (isset($keeper[$order_id])) {
            $losers[] = (int) $row->id;   // already kept the best row for this order
            continue;
        }
        $keeper[$order_id] = true;
    }

    // Chunked so a store with a long history does not build one enormous
    // statement; the id list is integer-cast, never interpolated user input.
    foreach (array_chunk($losers, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $wpdb->query("UPDATE {$table} SET order_id = 0 WHERE id IN ({$in})"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    update_option('brikpanel_cartab_credit_dedupe_done', '1', true);
}

/**
 * One-time correction: take the recovery back off carts whose order never
 * became a sale.
 *
 * Until this release the two checkout hooks marked a cart recovered the instant
 * WooCommerce wrote the order row — which happens *before* the gateway is asked
 * for anything, while the order is still `pending`. A declined payment
 * therefore left the cart permanently stamped "Recovered", inflated the
 * recovered count and value, and (because upsert() only dedupes against open
 * rows) let the shopper's next capture ping insert a second row for the very
 * same cart. Stores saw one customer holding a "Recovered" and an "Abandoned"
 * row minutes apart, at the same total.
 *
 * mark_recovered() now runs only on a status transition that represents a sale,
 * and unmark_recovered() undoes one when the order stops being a sale. This
 * pass applies the same rule to the rows already on disk: every cart credited
 * to an order that is failed, cancelled, drafted, binned or gone is re-opened
 * (back to `abandoned` when it had been abandoned, otherwise `active`), the
 * uncredited siblings closed in the same sweep go with it, and any resulting
 * duplicate open rows for one shopper collapse into one.
 *
 * Historical rows fire no hooks: re-queuing months of outreach on upgrade would
 * be worse than the wrong number it fixes.
 *
 * Deliberately NOT called from brikpanel_maybe_upgrade_db(): that function
 * returns on its first line when brikpanel_db_version already matches
 * BRIKPANEL_VERSION, so a store updating to a build that ships this repair
 * without a version bump would never run it. Hanging a data repair off a
 * version number it does not control is how a fix silently does nothing on the
 * one site that needed it. It carries its own guard instead and runs on
 * plugins_loaded; once done, the cost is a single autoloaded option read.
 *
 * The guard is versioned rather than a boolean, so an improved pass re-runs on
 * stores where an earlier one already finished. Re-running is harmless: a row
 * that is no longer 'recovered' no longer matches.
 *
 * A summary lands in brikpanel_cartab_failed_recovery_repair_stats for support.
 */
define('BRIKPANEL_CARTAB_REPAIR_PASS', '5');

function brikpanel_cartab_repair_failed_recoveries() {
    if (get_option('brikpanel_cartab_failed_recovery_repair_done') === BRIKPANEL_CARTAB_REPAIR_PASS) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        // No table means no legacy rows to correct, now or later: when this
        // plugin does create it, it creates it empty. Mark the pass done rather
        // than leaving a SHOW TABLES on every request forever.
        update_option('brikpanel_cartab_failed_recovery_repair_done', BRIKPANEL_CARTAB_REPAIR_PASS, true);
        return;
    }

    // No early return when this comes back empty: the second pass below works on
    // rows that carry no order id at all, and on most stores those are the
    // majority of the damage.
    $credited = (array) $wpdb->get_results(
        "SELECT id, order_id, email, visitor_id, recovered_at
           FROM {$table}
          WHERE status = 'recovered' AND order_id > 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );

    // Statuses that mean the order never was a sale, in the wc- prefixed form
    // the order tables actually store. This is the same judgement the live
    // recovery now makes (Brikpanel_Cart_Abandonment::non_sale_statuses()),
    // applied backwards.
    //
    // 'wc-pending' carries most of the damage and is easy to leave out: it is
    // the status every checkout order is born with, so a shopper who was sent
    // to a bank page and never came back leaves an order sitting in 'pending'
    // forever — no failure was ever recorded, yet the cart was stamped
    // recovered the moment checkout was submitted. Repairing only 'wc-failed'
    // fixes the orders whose gateway bothered to report the decline and leaves
    // every silent drop-off behind.
    //
    // 'wc-refunded' is NOT here: that order really was a sale before it was
    // refunded, so the cart genuinely converted.
    $bad = ['wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft', 'trash', 'auto-draft'];

    $order_ids = array_values(array_unique(array_map(static function ($row) {
        return (int) $row->order_id;
    }, $credited)));

    // Read order statuses straight from the tables. Two reasons for the raw SQL:
    // wc_get_orders() ignores post__in/include under HPOS and would hand back the
    // whole shop, and this pass runs at plugins_loaded priority 5.
    //
    // Both tables are consulted rather than picking one via an HPOS check,
    // because the failure mode of guessing wrong is not a wrong number, it is
    // reverting every recovery in the store: an HPOS-only order looks deleted
    // when you read wp_posts. Whichever table knows the order answers, HPOS
    // first (it is the source of truth wherever it is on, and a synced wp_posts
    // copy can lag).
    $hpos_table = $wpdb->prefix . 'wc_orders';
    $sources    = [];
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->posts)) === $wpdb->posts) {
        $sources[] = [$wpdb->posts, 'post_status', "AND post_type IN ('shop_order','shop_order_placehold')"];
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table)) === $hpos_table) {
        $sources[] = [$hpos_table, 'status', ''];   // last wins on conflict
    }

    // An order the lookup cannot see is treated as deleted, so being unable to
    // read the order tables at all would re-open every recovery in the store.
    // Bail without touching a row and without burning the guard; the next
    // request tries again.
    if (!$sources) {
        return;
    }

    $statuses = [];
    foreach ($sources as list($table_name, $status_col, $extra)) {
        foreach (array_chunk($order_ids, 500) as $chunk) {
            $in    = implode(',', array_map('intval', $chunk));
            $found = $wpdb->get_results(
                "SELECT ID AS id, {$status_col} AS status FROM {$table_name} WHERE ID IN ({$in}) {$extra}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
            foreach ((array) $found as $row) {
                $statuses[(int) $row->id] = (string) $row->status;
            }
        }
    }

    // Collect the credited rows to re-open, plus the uncredited siblings that
    // were closed in the same sweep — they all share one recovered_at stamp.
    $reopen  = [];
    $touched = [];
    foreach ($credited as $row) {
        $order_id = (int) $row->order_id;
        $status   = $statuses[$order_id] ?? null;
        // null = the order row is gone entirely.
        if ($status !== null && !in_array($status, $bad, true)) {
            continue;
        }

        $reopen[]  = (int) $row->id;
        $touched[] = ['email' => (string) $row->email, 'visitor_id' => (string) $row->visitor_id];

        if (empty($row->recovered_at) || $row->recovered_at === '0000-00-00 00:00:00') {
            continue;
        }
        if ((string) $row->email !== '') {
            $reopen = array_merge($reopen, (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE email = %s AND status = 'recovered' AND order_id = 0 AND recovered_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $row->email,
                $row->recovered_at
            )));
        }
        if ((string) $row->visitor_id !== '') {
            $reopen = array_merge($reopen, (array) $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE visitor_id = %s AND status = 'recovered' AND order_id = 0 AND recovered_at = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $row->visitor_id,
                $row->recovered_at
            )));
        }
    }

    // ---------------------------------------------------------------------
    // Second pass: the uncredited rows.
    //
    // Everything above works back from an order id. Most wrongly-recovered rows
    // do not have one: mark_recovered() stamps the order on a single row and
    // writes order_id = 0 on every other row it closes, and the older
    // brikpanel_cartab_dedupe_recovery_credit() pass zeroed still more. On a
    // real store those outnumber the credited ones (88 of 144 on the site this
    // was diagnosed against), and 86 of them had no credited row left sharing
    // their recovered_at, so the sibling lookup above can never reach them.
    // They are the rows that show as "Converted" for a customer who never
    // bought anything.
    //
    // With no order id to judge, the row is judged by its owner instead: did
    // that owner actually buy something around the time the row was closed? The
    // window is the store's own recovery window, the same rule mark_recovered()
    // applies live.
    //
    // "Owner" has to mean what it means to mark_recovered(), which closes rows
    // by email OR by browser id. Asking about the email alone re-opens every row
    // a shopper owns under a second address — and the commonest second address
    // is a half-typed one, captured while they were still writing the first
    // (see Brikpanel_Cart_Abandonment::supersede_same_shopper_row()). Those
    // addresses never place an order, so an email-only test reverts exactly the
    // rows belonging to people who DID buy, and the sweep then restamps them as
    // freshly abandoned. Two further signals answer for them:
    //
    //   1. the browser id, against the _brikpanel_cartab_vid meta checkout
    //      stamps on the order;
    //   2. a sibling row — another row closed by the same sweep (same browser
    //      id AND the same recovered_at) whose own email did buy. This needs no
    //      order meta at all, so it reaches orders placed before that meta
    //      existed, which is where most of the damage sits.
    //
    // Any one of the three is enough to leave the row closed. Rows with no
    // email are left alone rather than guessed at.
    //
    // Walked in batches by id rather than read in one go. A busy store can hold
    // six figures of recovered rows, and holding them — plus a sweep map over
    // them — costs tens of megabytes that land on whichever request happens to
    // run the pass, typically the first admin page load after an update. Each
    // batch below carries only its own rows and the lookups they need.
    $already    = array_flip(array_map('intval', $reopen));
    $has_hpos   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table)) === $hpos_table;
    // wp_postmeta has no index on meta_value, so the legacy lookup scans every
    // _billing_email row it holds. Worth one cheap probe to find out whether
    // this store has any post-table orders at all: on an HPOS store that never
    // kept the legacy copies the answer is none, and the scan is skipped
    // outright rather than repeated per batch.
    $has_legacy = (bool) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold') LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );
    $meta_table    = $wpdb->prefix . 'wc_orders_meta';
    $has_hpos_meta = $has_hpos
        && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $meta_table)) === $meta_table;

    // prime-ignore: unreachable once brikpanel_cartab_failed_recovery_repair_done matches the pass, and that marker is primed + autoloaded.
    $window = max(1, (int) get_option('brikpanel_cartab_recovery_window_days', 7)) * DAY_IN_SECONDS;

    // A DATETIME column read back as a timestamp, or 0 when there is nothing
    // there. The emptiness has to be tested BEFORE strtotime() sees it:
    // strtotime(' UTC') is not false, it is the current time, so feeding an
    // unstamped row straight through would silently judge it against today
    // instead of leaving it alone.
    $stamp_of = static function ($value) {
        $value = trim((string) $value);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return 0;
        }
        return (int) strtotime($value . ' UTC');
    };

    // Did anyone this row could belong to buy within the window of the moment
    // the row was closed?
    $bought = static function ($stamps, $ts) use ($window) {
        foreach ((array) $stamps as $sale_ts) {
            if (abs($sale_ts - $ts) <= $window) {
                return true;
            }
        }
        return false;
    };

    // Sales by email, for a bounded list of addresses. One batched lookup per
    // source, never one query per row.
    $lookup_sales_by_email = static function (array $emails) use ($wpdb, $hpos_table, $has_hpos, $has_legacy, $bad, $stamp_of) {
        $out = [];
        foreach (array_chunk(array_values(array_unique($emails)), 200) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = [];
            if ($has_hpos) {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT billing_email AS k, status, date_created_gmt AS created
                       FROM {$hpos_table}
                      WHERE billing_email IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $chunk
                )));
            }
            if ($has_legacy) {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT pm.meta_value AS k, p.post_status AS status, p.post_date_gmt AS created
                       FROM {$wpdb->postmeta} pm
                       JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                      WHERE pm.meta_key = '_billing_email'
                        AND pm.meta_value IN ({$ph})
                        AND p.post_type IN ('shop_order','shop_order_placehold')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $chunk
                )));
            }
            foreach ($rows as $row) {
                // "Not a non-sale" rather than a paid whitelist, so a store's
                // custom order statuses keep counting as the sales they are.
                if (in_array((string) $row->status, $bad, true)) {
                    continue;
                }
                $ts = $stamp_of($row->created);
                if ($ts) {
                    $out[strtolower((string) $row->k)][] = $ts;
                }
            }
        }
        return $out;
    };

    // Signal 1: sales by browser id, keyed on the meta checkout stamps on the
    // order. Both meta tables are filtered by meta_key first, the indexed
    // column in each.
    $lookup_sales_by_visitor = static function (array $vids) use ($wpdb, $hpos_table, $meta_table, $has_hpos_meta, $has_legacy, $bad, $stamp_of) {
        $out = [];
        foreach (array_chunk(array_values(array_unique($vids)), 200) as $chunk) {
            $ph   = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = [];
            if ($has_hpos_meta) {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT m.meta_value AS k, o.status AS status, o.date_created_gmt AS created
                       FROM {$meta_table} m
                       JOIN {$hpos_table} o ON o.id = m.order_id
                      WHERE m.meta_key = '_brikpanel_cartab_vid'
                        AND m.meta_value IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $chunk
                )));
            }
            if ($has_legacy) {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT pm.meta_value AS k, p.post_status AS status, p.post_date_gmt AS created
                       FROM {$wpdb->postmeta} pm
                       JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                      WHERE pm.meta_key = '_brikpanel_cartab_vid'
                        AND pm.meta_value IN ({$ph})
                        AND p.post_type IN ('shop_order','shop_order_placehold')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                    $chunk
                )));
            }
            foreach ($rows as $row) {
                if (in_array((string) $row->status, $bad, true)) {
                    continue;
                }
                $ts = $stamp_of($row->created);
                if ($ts) {
                    $out[(string) $row->k][] = $ts;
                }
            }
        }
        return $out;
    };

    // The UPDATE that acts on $reopen runs after this walk, so the predicate
    // stays true for rows not yet visited and the cursor cannot skip any.
    $last_id = 0;
    while (true) {
        $batch = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, visitor_id, recovered_at
               FROM {$table}
              WHERE status = 'recovered' AND order_id = 0 AND email <> '' AND id > %d
              ORDER BY id ASC
              LIMIT 1000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $last_id
        ));
        if (!$batch) {
            break;
        }
        $last_id = (int) $batch[count($batch) - 1]->id;

        $uncredited = array_values(array_filter($batch, static function ($row) use ($already) {
            return !isset($already[(int) $row->id]);
        }));
        unset($batch);
        if (!$uncredited) {
            continue;
        }

        // Signal 2: the rows every sweep in this batch closed. Fetched by
        // visitor id (the leftmost column of idx_visitor_email) and matched on
        // recovered_at in PHP, so the map never outgrows the batch.
        $vids = [];
        foreach ($uncredited as $row) {
            if ((string) $row->visitor_id !== '') {
                $vids[(string) $row->visitor_id] = true;
            }
        }
        $sweeps = [];
        $emails = [];
        foreach ($uncredited as $row) {
            $emails[] = strtolower((string) $row->email);
        }
        foreach (array_chunk(array_keys($vids), 200) as $chunk) {
            $ph      = implode(',', array_fill(0, count($chunk), '%s'));
            $sibs    = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, email, visitor_id, recovered_at, order_id
                   FROM {$table}
                  WHERE status = 'recovered' AND visitor_id IN ({$ph})", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $chunk
            ));
            foreach ($sibs as $sib) {
                if (empty($sib->recovered_at)) {
                    continue;
                }
                $sweeps[(string) $sib->visitor_id . "\0" . (string) $sib->recovered_at][] = $sib;
                $emails[] = strtolower((string) $sib->email);
            }
        }

        $sales_by_email   = $lookup_sales_by_email(array_filter($emails));
        $sales_by_visitor = $lookup_sales_by_visitor(array_keys($vids));
        unset($emails, $vids);

        foreach ($uncredited as $row) {
            $ts = $stamp_of($row->recovered_at);
            if (!$ts) {
                continue; // No stamp to reason about; leave it be.
            }

            // Its own address.
            if ($bought($sales_by_email[strtolower((string) $row->email)] ?? [], $ts)) {
                continue;
            }
            // The browser it was captured in.
            if ($bought($sales_by_visitor[(string) $row->visitor_id] ?? [], $ts)) {
                continue;
            }
            // A sibling closed by the same sweep. A credited sibling is proof on
            // its own: the pass above already judged its order and left it
            // alone, so it stands for a sale that really happened.
            $sig     = (string) $row->visitor_id . "\0" . (string) $row->recovered_at;
            $settled = false;
            foreach ($sweeps[$sig] ?? [] as $sibling) {
                if ((int) $sibling->id === (int) $row->id) {
                    continue;
                }
                if ((int) $sibling->order_id > 0 && !isset($already[(int) $sibling->id])) {
                    $settled = true;
                    break;
                }
                if ($bought($sales_by_email[strtolower((string) $sibling->email)] ?? [], $ts)) {
                    $settled = true;
                    break;
                }
            }
            if ($settled) {
                continue;
            }

            $reopen[]  = (int) $row->id;
            $touched[] = ['email' => (string) $row->email, 'visitor_id' => (string) $row->visitor_id];
        }

        unset($uncredited, $sweeps, $sales_by_email, $sales_by_visitor);
    }

    // No early return when there is nothing to re-open: the repurchased-row pass
    // at the end is independent of this one, and on a store that has already had
    // its recoveries corrected it is the only pass left with work to do.
    $reopen = array_values(array_unique(array_map('intval', $reopen)));

    $now = current_time('mysql', true);

    // Re-open the row without making an old cart look new. Writing NOW into
    // updated_at restarts its abandonment clock, so flip_abandoned() picks it up
    // an hour later, stamps abandoned_at with today and announces a cart that
    // was really abandoned weeks ago — a follow-up mail on a repair, and a "last
    // activity" the shopper never had. recovered_at is the moment the sweep
    // closed the row, which is the closest thing on file to their last real
    // activity, so the row goes back to that and carries its own abandonment
    // date from it. It lands in the list already abandoned, at its true date,
    // and the sweep never has to touch it.
    // MySQL and MariaDB apply SET left to right, with later clauses reading the
    // values already assigned. Every clause below is written to give the same
    // answer either way rather than depending on that: COALESCE(abandoned_at,
    // recovered_at) is unchanged by having already been assigned that exact
    // value, and recovered_at is cleared last, after both clauses that read it.
    foreach (array_chunk($reopen, 500) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        $wpdb->query(
            "UPDATE {$table}
                SET abandoned_at = COALESCE(abandoned_at, recovered_at),
                    updated_at   = COALESCE(recovered_at, updated_at),
                    status       = CASE WHEN COALESCE(abandoned_at, recovered_at) IS NOT NULL THEN 'abandoned' ELSE 'active' END,
                    order_id     = 0,
                    recovered_at = NULL
              WHERE id IN ({$in})" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    // Re-opening resurrects the duplicate the old behaviour created: the row the
    // shopper's retry inserted while the first one sat wrongly "recovered".
    // Keep the best per shopper, on the same precedence mark_recovered() uses.
    $merged = 0;
    $seen   = [];
    foreach ($touched as $key) {
        $sig = $key['visitor_id'] . "\0" . $key['email'];
        if (isset($seen[$sig])) {
            continue;
        }
        $seen[$sig] = true;

        $owned = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE visitor_id = %s AND email = %s AND status IN ('active','abandoned')
              ORDER BY (item_count > 0) DESC, (abandoned_at IS NOT NULL) DESC, updated_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $key['visitor_id'],
            $key['email']
        )));
        if (count($owned) < 2) {
            continue;
        }
        array_shift($owned);
        $wpdb->query('DELETE FROM ' . $table . ' WHERE id IN (' . implode(',', $owned) . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $merged += count($owned);
    }

    // Typos first, while the ghosts are still open rows. Left until after
    // close_repurchased_rows() they get badged "Converted" on the way past and
    // stop looking like the open keystrokes they are, so they survive the sweep
    // that exists to remove them.
    $typos     = brikpanel_cartab_merge_typo_rows($table);
    $converted = brikpanel_cartab_close_repurchased_rows($table, $now);

    update_option('brikpanel_cartab_failed_recovery_repair_stats', [
        'reverted'    => count($reopen),
        'merged'      => $merged,
        'converted'   => $converted,
        'typo_merged' => $typos,
        'ran_at'      => $now,
    ], false);
    update_option('brikpanel_cartab_failed_recovery_repair_done', BRIKPANEL_CARTAB_REPAIR_PASS, true);
}

/**
 * One-time correction: put the abandoned carts a stale mirror blanked back
 * where they belong.
 *
 * Until this release Brikpanel_Cart_Abandonment::mirror_cart() wrote an empty
 * snapshot over ANY open row whenever the browser turned up with an empty cart
 * — an expired WooCommerce session, a cart cleared by a third party, an order
 * placed under an identity recovery could not tie back. On a row that had
 * already been announced abandoned that was fatal three times over: the only
 * copy of the cart was overwritten, the row dropped out of flip_abandoned()'s
 * reach for good (it wants item_count > 0), and it started reading as "Email
 * only" — a bare signup that never had a cart — while its own abandoned_at
 * said the opposite.
 *
 * The cart contents cannot come back; nobody else kept them. What can be put
 * right is the label, and the row still carries the fact it needs: it WAS
 * abandoned. Moving it back to 'abandoned' is one UPDATE and needs no change to
 * the status derivation, the list filters, the stat-card grouping or a single
 * shipped translation.
 *
 * Deliberately narrow. `abandoned_at IS NOT NULL` is the whole point: without
 * it this would relabel every genuine email-only signup in the store as an
 * abandoned cart. Rows a shopper truly emptied after being announced are caught
 * too and cannot be told apart after the fact — but abandoned_at says the row
 * was abandoned, so of the two labels available that is the honest one.
 *
 * Fires no hooks, for the same reason the repair above does not: re-announcing
 * historical carts on upgrade would mail people who left months ago.
 *
 * Carries its own guard rather than riding brikpanel_maybe_upgrade_db(), for
 * the reason spelled out on that function: a repair gated on a version number
 * it does not control silently does nothing on a build that ships without a
 * bump.
 */
define('BRIKPANEL_CARTAB_ZEROED_PASS', '1');

function brikpanel_cartab_repair_zeroed_rows() {
    if (get_option('brikpanel_cartab_zeroed_repair_done') === BRIKPANEL_CARTAB_ZEROED_PASS) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'brikpanel_abandoned_carts';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        // Nothing to correct now or later: this plugin creates that table empty.
        update_option('brikpanel_cartab_zeroed_repair_done', BRIKPANEL_CARTAB_ZEROED_PASS, true);
        return;
    }

    $moved = (int) $wpdb->query(
        "UPDATE {$table}
            SET status = 'abandoned'
          WHERE status = 'active'
            AND item_count = 0
            AND abandoned_at IS NOT NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );

    update_option('brikpanel_cartab_zeroed_repair_stats', [
        'moved'  => $moved,
        'ran_at' => current_time('mysql', true),
    ], false);
    update_option('brikpanel_cartab_zeroed_repair_done', BRIKPANEL_CARTAB_ZEROED_PASS, true);
}

/**
 * Collapse the rows a shopper opened while still typing their address.
 *
 * Brikpanel_Cart_Abandonment::supersede_same_shopper_row() stops these being
 * created from now on; this clears the ones already on disk. Same test it
 * applies live, run backwards over the open rows: one browser, one local part,
 * two captures minutes apart is one shopper correcting one address, so the best
 * row keeps the address they finished on and the keystrokes behind it go.
 *
 * The rows removed here are not records of anything — nobody was ever reachable
 * at "…@gmail.co", it was on screen for three seconds. Left alone they inflate
 * the abandoned count and sit in the outreach queue waiting to hard-bounce.
 *
 * Only checkout rows merge, for the coupon reason spelled out in the live guard.
 *
 * @param string $table Cart abandonment table name.
 * @return int Rows removed.
 */
function brikpanel_cartab_merge_typo_rows($table) {
    global $wpdb;

    $window = (int) apply_filters('brikpanel_cartab_typo_window', 15 * MINUTE_IN_SECONDS);
    if ($window < 1) {
        return 0;
    }

    // Only shoppers who hold several checkout rows under one local part — on any
    // real store a short list, and it keeps the per-group scan below bounded.
    // Recovered rows count towards the group: where an earlier pass already
    // reverted a ghost, the row carrying the truth is the recovered one beside
    // it, and a group read as open-only would never see it.
    $keys = (array) $wpdb->get_results(
        "SELECT visitor_id, SUBSTRING_INDEX(email, '@', 1) AS local
           FROM {$table}
          WHERE source = 'checkout' AND visitor_id <> '' AND email <> ''
          GROUP BY visitor_id, local
         HAVING COUNT(DISTINCT email) > 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );
    if (!$keys) {
        return 0;
    }

    // Empty first, then strtotime: strtotime(' UTC') answers with the current
    // time rather than false, which would read an unstamped row as "created
    // now" and drop it into whatever window it is being compared against.
    $stamp = static function ($row) {
        $value = trim((string) $row->created_at);
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return 0;
        }
        return (int) strtotime($value . ' UTC');
    };

    $removed = 0;
    foreach ($keys as $key) {
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, status, order_id, created_at FROM {$table}
              WHERE visitor_id = %s AND SUBSTRING_INDEX(email, '@', 1) = %s
                AND source = 'checkout'
              ORDER BY (item_count > 0) DESC, (abandoned_at IS NOT NULL) DESC, updated_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $key->visitor_id,
            $key->local
        ));
        if (count($rows) < 2) {
            continue;
        }

        $open      = [];
        $recovered = [];
        foreach ($rows as $row) {
            if ('recovered' === (string) $row->status) {
                $recovered[] = $row;
                continue;
            }
            // An open row should never carry an order id — unmark_recovered()
            // zeroes it on the way back out — but a row that claims a sale is
            // the last thing to delete on a guess, so it is never a candidate.
            if ((int) $row->order_id > 0) {
                continue;
            }
            $open[] = $row;
        }

        // The anchor is the row that holds the truth: a converted one when the
        // shopper turned out to buy, otherwise the best open row.
        $anchor = $recovered ? $recovered[0] : array_shift($open);
        if (!$anchor) {
            continue;
        }

        $drop = [];
        foreach ($open as $row) {
            // Same address is not a typo — two rows under one address are an
            // ordinary duplicate, and merge_open_duplicates() owns that case.
            if (strtolower((string) $row->email) === strtolower((string) $anchor->email)) {
                continue;
            }
            // Outside the window this is a shopper genuinely reusing one local
            // part across inboxes weeks apart, not a correction. Leave it.
            if (abs($stamp($row) - $stamp($anchor)) > $window) {
                continue;
            }
            // Against a converted anchor the test is directional: a correction
            // is always typed BEFORE the address that ends up on the order, so
            // only earlier rows can be keystrokes. A row opened after the sale
            // is a new cart the shopper started, which is a different question
            // and one close_repurchased_rows() already answers.
            if ($recovered && $stamp($row) > $stamp($anchor)) {
                continue;
            }
            $drop[] = $row;
        }
        if (!$drop) {
            continue;
        }

        // With no converted row to defer to, the surviving open row takes the
        // address from the LAST capture — the one the shopper settled on. The
        // anchor was picked for carrying the cart, which is a different question.
        if (!$recovered) {
            $final = $anchor;
            foreach ($drop as $row) {
                if ($stamp($row) > $stamp($final)) {
                    $final = $row;
                }
            }
            if ((string) $final->email !== (string) $anchor->email) {
                $wpdb->update($table, ['email' => (string) $final->email], ['id' => (int) $anchor->id]);
            }
        }

        $ids = array_map(static function ($row) {
            return (int) $row->id;
        }, $drop);
        $wpdb->query('DELETE FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $removed += count($ids);
    }

    return $removed;
}

/**
 * Close the open rows that are really carts the shopper had already paid for.
 *
 * Brikpanel_Cart_Abandonment::just_repurchased() stops these being created from
 * now on, but it cannot help with the ones already on disk: recovery closes
 * every row a shopper owns, so seconds after an order the shopper owns no OPEN
 * row, and the next cart mirror opened a brand new one for a cart that had just
 * been paid for. With an off-site or iframe gateway (PayTR, any 3-D Secure
 * redirect) the order completes in a server-side callback, so the customer's
 * session — and the persistent cart WooCommerce keeps for logged-in customers —
 * still held the items. An hour later the row was sitting in the list as
 * "Abandoned": the one row a follow-up tool would email to a paying customer.
 *
 * These are marked recovered-but-uncredited rather than deleted, so the list
 * still shows what happened (badged "Converted") while they stop counting as
 * abandoned and stop being outreach targets. recovered_at is copied from the
 * row they duplicate, which also groups them with that sale for
 * unmark_recovered() should the order later fall through.
 *
 * Same test as the live guard: identical item count, total and currency, and
 * the row must have been opened inside the repurchase window after the
 * recovery. Empty rows are left alone — they carry no cart and never reach a
 * follow-up.
 *
 * @param string $table Cart abandonment table name.
 * @param string $now   GMT timestamp for updated_at.
 * @return int Rows closed.
 */
function brikpanel_cartab_close_repurchased_rows($table, $now) {
    global $wpdb;

    $window = (int) apply_filters('brikpanel_cartab_repurchase_window', 6 * HOUR_IN_SECONDS);
    if ($window < 1) {
        return 0;
    }

    $open = $wpdb->get_results(
        "SELECT id, email, item_count, cart_total, currency, created_at
           FROM {$table}
          WHERE status IN ('active','abandoned') AND item_count > 0 AND email <> ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    );
    if (!$open) {
        return 0;
    }

    $closed = 0;
    foreach ((array) $open as $row) {
        $created = strtotime((string) $row->created_at . ' UTC');
        if (!$created) {
            continue;
        }

        // The recovered row this one duplicates: same shopper, same cart, closed
        // shortly BEFORE this row was opened.
        $match = $wpdb->get_var($wpdb->prepare(
            "SELECT recovered_at FROM {$table}
              WHERE email = %s AND status = 'recovered'
                AND item_count = %d AND cart_total = %f AND currency = %s
                AND recovered_at IS NOT NULL
                AND recovered_at <= %s
                AND recovered_at >= %s
              ORDER BY recovered_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $row->email,
            (int) $row->item_count,
            (float) $row->cart_total,
            (string) $row->currency,
            gmdate('Y-m-d H:i:s', $created),
            gmdate('Y-m-d H:i:s', $created - $window)
        ));
        if (!$match) {
            continue;
        }

        $wpdb->update(
            $table,
            [
                'status'       => 'recovered',
                'order_id'     => 0,
                'recovered_at' => $match,
                'updated_at'   => $now,
            ],
            ['id' => (int) $row->id]
        );
        $closed++;
    }

    return $closed;
}

/**
 * One-time backfill: copy WooCommerce's native Cost of Goods Sold values into
 * BrikPanel's own `_brikpanel_cogs` meta for products and variations that have
 * a native cost on file but were never saved through BrikPanel.
 *
 * The live mirror in brikpanel_mirror_cogs_meta() keeps future native edits in
 * step, but costs entered before this release (or via WooCommerce's own product
 * screen while running an older BrikPanel) need a single catch-up pass so
 * historical data shows up immediately instead of only after the next product
 * save. (Reads no longer depend on this pass — brikpanel_cogs_meta_keys() walks
 * every known cost key — but keeping the two in sync stays worthwhile.)
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
        brikpanel_update_option( 'brikpanel_native_cogs_backfilled', 'yes' );
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

    brikpanel_update_option( 'brikpanel_native_cogs_backfilled', 'yes' );
}

/**
 * One-time unification (reverse direction of the backfill above): copy costs
 * that only exist on BrikPanel's legacy `_brikpanel_cogs` meta into
 * WooCommerce's native `_cogs_total_value`.
 *
 * WooCommerce's native field is the source of truth for every cost read
 * (dashboard profit, product list, Sheets, Quick Edit) with the legacy key as
 * fallback. Costs saved by older BrikPanel versions while the WC COGS feature
 * was unavailable or off exist only on the legacy key; this pass promotes
 * them so native-first reads — and any other plugin reading WC's field — see
 * the complete picture. Rows where native already has a value are never
 * touched (native wins by definition), so re-running is always safe; the
 * marker option just avoids paying for the no-op query on every request.
 */
function brikpanel_unify_cogs_to_native() {
    if ( get_option( 'brikpanel_cogs_unified_native' ) === 'yes' ) {
        return;
    }

    global $wpdb;

    $target_ids = $wpdb->get_col(
        "SELECT bp.post_id
         FROM {$wpdb->postmeta} bp
         INNER JOIN {$wpdb->posts} p
                 ON p.ID = bp.post_id
                AND p.post_type IN ('product', 'product_variation')
         LEFT JOIN {$wpdb->postmeta} wc
                ON wc.post_id = bp.post_id
               AND wc.meta_key = '_cogs_total_value'
         WHERE bp.meta_key = '_brikpanel_cogs'
           AND bp.meta_value <> ''
           AND (wc.meta_id IS NULL OR wc.meta_value = '')"
    );

    if ( empty( $target_ids ) ) {
        brikpanel_update_option( 'brikpanel_cogs_unified_native', 'yes' );
        return;
    }

    // Promote rows with no native meta at all.
    $wpdb->query(
        "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
         SELECT bp.post_id, '_cogs_total_value', bp.meta_value
         FROM {$wpdb->postmeta} bp
         INNER JOIN {$wpdb->posts} p
                 ON p.ID = bp.post_id
                AND p.post_type IN ('product', 'product_variation')
         LEFT JOIN {$wpdb->postmeta} wc
                ON wc.post_id = bp.post_id
               AND wc.meta_key = '_cogs_total_value'
         WHERE bp.meta_key = '_brikpanel_cogs'
           AND bp.meta_value <> ''
           AND wc.meta_id IS NULL"
    );

    // Heal the rare native row that exists but was left as an empty string.
    $wpdb->query(
        "UPDATE {$wpdb->postmeta} wc
         INNER JOIN {$wpdb->posts} p
                 ON p.ID = wc.post_id
                AND p.post_type IN ('product', 'product_variation')
         INNER JOIN {$wpdb->postmeta} bp
                 ON bp.post_id = wc.post_id
                AND bp.meta_key = '_brikpanel_cogs'
                AND bp.meta_value <> ''
         SET wc.meta_value = bp.meta_value
         WHERE wc.meta_key = '_cogs_total_value'
           AND wc.meta_value = ''"
    );

    foreach ( $target_ids as $pid ) {
        wp_cache_delete( (int) $pid, 'post_meta' );
    }
    if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
        brikpanel_bust_data_caches();
    }

    brikpanel_update_option( 'brikpanel_cogs_unified_native', 'yes' );
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
 *
 * v2 (3.2.17): the first pass only caught parents ALREADY pinned to
 * `outofstock`, so a leftover `_manage_stock = yes` with an empty `_stock`
 * on a parent still showing "instock" slipped through — a time bomb: the
 * first completed order (POS, storefront, anywhere) triggers WooCommerce's
 * deferred parent sync, the parent saves itself, and WC derives `outofstock`
 * from the parent's own empty quantity even though every variation still has
 * stock. v2 also heals that case: parent manages stock with NO quantity while
 * at least one variation manages its own stock → the parent flag is a
 * meaningless leftover, clear it. Parents with a real quantity (deliberate
 * parent-level stock, variations inheriting) are still left untouched.
 */
function brikpanel_fix_variable_parent_stock() {
    if (get_option('brikpanel_var_stock_fix2_done') === '1') {
        return;
    }
    if (!function_exists('wc_get_product') || !class_exists('WC_Product_Variable')) {
        // WooCommerce not ready yet — try again on a later load.
        return;
    }

    global $wpdb;
    $batch    = 200;
    $last_id  = (int) get_option('brikpanel_var_stock_fix2_cursor', 0);

    // Candidate parents: product_type = variable, parent manages stock, AND is
    // either already pinned out of stock (v1 contradiction) or carries no
    // quantity at all (v2 leftover — flips OOS on the first completed order).
    // Cursor on ID keeps each run bounded and ordered.
    $candidate_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
           INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
           INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'variable'
           INNER JOIN {$wpdb->postmeta} mm ON mm.post_id = p.ID AND mm.meta_key = '_manage_stock'  AND mm.meta_value = 'yes'
           LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_stock_status'
           LEFT JOIN {$wpdb->postmeta} mq ON mq.post_id = p.ID AND mq.meta_key = '_stock'
          WHERE p.post_type = 'product'
            AND p.ID > %d
            AND (ms.meta_value = 'outofstock' OR mq.meta_value IS NULL OR mq.meta_value = '')
          ORDER BY p.ID ASC
          LIMIT %d",
        $last_id,
        $batch
    ));

    if (empty($candidate_ids)) {
        update_option('brikpanel_var_stock_fix2_done', '1', true);
        delete_option('brikpanel_var_stock_fix2_cursor');
        delete_option('brikpanel_var_stock_fix_done');
        delete_option('brikpanel_var_stock_fix_cursor');
        return;
    }

    foreach ($candidate_ids as $pid) {
        $pid     = (int) $pid;
        $product = wc_get_product($pid);
        if (!$product || !$product->is_type('variable')) {
            continue;
        }

        // Inspect the variations once: does any manage its own stock, and is
        // any currently in stock?
        $has_self_managed_child = false;
        $has_instock_child      = false;
        foreach ($product->get_children() as $cid) {
            $child = wc_get_product($cid);
            if (!$child) {
                continue;
            }
            // Variations return a BOOL here (true = manages its own stock;
            // 'parent' appears only in view context when inheriting).
            if (true === $child->get_manage_stock('edit')) {
                $has_self_managed_child = true;
            }
            if ($child->is_in_stock()) {
                $has_instock_child = true;
            }
            if ($has_self_managed_child && $has_instock_child) {
                break;
            }
        }

        $parent_qty = $product->get_stock_quantity('edit');

        // Heal when either:
        //  (a) v1 contradiction — parent pinned out of stock while a variation
        //      is actually in stock; or
        //  (b) v2 leftover — parent "manages" stock with NO quantity while at
        //      least one variation manages its own stock, so the parent flag
        //      can only ever do damage (WC derives outofstock from qty 0/null
        //      on the next save, e.g. WooCommerce's deferred sync after any
        //      completed order).
        // Deliberate parent-level stock (real quantity, variations inheriting)
        // never matches either branch and is left alone.
        $is_v1_contradiction = ('outofstock' === $product->get_stock_status('edit') && $has_instock_child);
        $is_v2_leftover      = (('' === $parent_qty || null === $parent_qty) && $has_self_managed_child);

        if (!$is_v1_contradiction && !$is_v2_leftover) {
            continue;
        }

        $product->set_manage_stock(false);
        $product->save();
        WC_Product_Variable::sync($product);
    }

    // Advance the cursor and continue on the next load if a full batch came
    // back (more rows may remain); otherwise we have reached the end.
    $new_cursor = (int) end($candidate_ids);
    update_option('brikpanel_var_stock_fix2_cursor', $new_cursor, true);

    if (count($candidate_ids) < $batch) {
        update_option('brikpanel_var_stock_fix2_done', '1', true);
        delete_option('brikpanel_var_stock_fix2_cursor');
        delete_option('brikpanel_var_stock_fix_done');
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
