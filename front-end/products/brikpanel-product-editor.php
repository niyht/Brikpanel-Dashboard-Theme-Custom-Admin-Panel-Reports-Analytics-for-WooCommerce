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
        //                handled by the screen spoof inside build_third_party_metabox_cards()
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

        // The Global Unique ID (GTIN/UPC/EAN/ISBN) column ships hidden by
        // default; users can reveal it from the "Screen Options" panel and
        // WordPress remembers that choice per user.
        add_filter('default_hidden_columns', [$this, 'default_hidden_columns'], 10, 2);

        // AJAX
        add_action('wp_ajax_brikpanel_save_product', [$this, 'ajax_save_product']);
        add_action('wp_ajax_brikpanel_add_category', [$this, 'ajax_add_category']);
        add_action('wp_ajax_brikpanel_add_brand', [$this, 'ajax_add_brand']);
        add_action('wp_ajax_brikpanel_upload_image', [$this, 'ajax_upload_image']);
        add_action('wp_ajax_brikpanel_pe_search_products', [$this, 'ajax_search_products']);
        // Renders the per-variation 3rd-party field structure for variations
        // that do not exist in the DB yet, so the "More fields" expander works
        // BEFORE the product's first save (empty values; they persist on save
        // via the row-index loop mapping).
        add_action('wp_ajax_brikpanel_pe_preview_variation_fields', [$this, 'ajax_preview_variation_fields']);
        // SEO analysis re-run. The legacy `…_surerank_analyze` action name is
        // kept for back-compat (the client still posts it); the handler now
        // renders whichever unified analyzer is active (SureRank or SmartCrawl).
        add_action('wp_ajax_brikpanel_pe_surerank_analyze', [$this, 'ajax_seo_analyze']);
        add_action('wp_ajax_brikpanel_pe_seo_analyze', [$this, 'ajax_seo_analyze']);
    }

    /**
     * Re-run the active unified SEO analyzer (SureRank or SmartCrawl) for a
     * product and return the refreshed panel HTML. Fired by the "Re-analyze"
     * button and right after a save so the checks reflect the latest
     * title/description/keyword/content.
     *
     * The client always sends the current (possibly unsaved) SEO field values
     * + description so the checks track what the user is typing without a save:
     *  - SureRank reads them through its own filters (surerank_prep_post_meta /
     *    surerank_post_analyzer_content), wired up here and torn down right
     *    after the render.
     *  - SmartCrawl receives them as an $overrides array that
     *    render_smartcrawl_analysis() applies through its own filters.
     */
    public function ajax_seo_analyze() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }
        $pid = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (!$pid) {
            wp_send_json_error(['message' => __('Invalid product.', 'brikpanel')]);
        }

        $analyzer = self::get_unified_seo_analyzer();
        if ($analyzer === null) {
            wp_send_json_success(['html' => '']);
        }

        // Sanitize the shared field values once.
        $seo_title    = isset($_POST['seo_title'])       ? sanitize_text_field(wp_unslash($_POST['seo_title']))       : null;
        $seo_desc     = isset($_POST['seo_description'])  ? sanitize_textarea_field(wp_unslash($_POST['seo_description'])) : null;
        $seo_focus_kw = isset($_POST['seo_focus_kw'])     ? sanitize_text_field(wp_unslash($_POST['seo_focus_kw']))     : null;
        $seo_canon    = isset($_POST['seo_canonical'])    ? esc_url_raw(wp_unslash($_POST['seo_canonical']))           : null;
        $content      = isset($_POST['content'])          ? wp_kses_post(wp_unslash($_POST['content']))               : null;

        if ($analyzer['slug'] === 'smartcrawl') {
            $overrides = [];
            if ($seo_title !== null)    { $overrides['title']         = $seo_title; }
            if ($seo_desc !== null)     { $overrides['description']   = $seo_desc; }
            if ($seo_canon !== null)    { $overrides['canonical']     = $seo_canon; }
            if ($seo_focus_kw !== null) { $overrides['focus_keyword'] = $seo_focus_kw; }
            if ($content !== null)      { $overrides['content']       = $content; }
            wp_send_json_success(['html' => self::render_smartcrawl_analysis($pid, $overrides)]);
        }

        // SureRank: feed live values through its public filters. Only non-empty
        // fields override; an empty field keeps SureRank's default/template
        // behaviour, exactly as an empty field is saved.
        $sr_map = [
            'page_title'      => $seo_title,
            'page_description' => $seo_desc,
            'focus_keyword'   => $seo_focus_kw,
            'canonical_url'   => $seo_canon,
        ];
        $overrides = [];
        foreach ($sr_map as $meta_key => $val) {
            if ($val !== null && $val !== '') {
                $overrides[$meta_key] = $val;
            }
        }

        $meta_filter = null;
        if (!empty($overrides)) {
            $meta_filter = static function ($meta) use ($overrides) {
                if (is_array($meta)) {
                    foreach ($overrides as $k => $v) {
                        $meta[$k] = $v;
                    }
                }
                return $meta;
            };
            add_filter('surerank_prep_post_meta', $meta_filter, 99);
        }

        $content_filter = null;
        if ($content !== null) {
            $content_filter = static function () use ($content) {
                return $content;
            };
            add_filter('surerank_post_analyzer_content', $content_filter, 99);
        }

        $html = self::render_surerank_analysis($pid);

        if ($meta_filter) {
            remove_filter('surerank_prep_post_meta', $meta_filter, 99);
        }
        if ($content_filter) {
            remove_filter('surerank_post_analyzer_content', $content_filter, 99);
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Lightweight product search for the Linked products (upsell/cross-sell)
     * picker. Returns up to 20 matches as {id, text} pairs.
     */
    public function ajax_search_products() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
        }
        $term    = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $exclude = isset($_GET['exclude']) ? absint($_GET['exclude']) : 0;
        if (strlen($term) < 2) {
            wp_send_json_success(['results' => []]);
        }
        $ids = wc_get_products([
            'status'  => ['publish', 'private', 'draft'],
            'limit'   => 20,
            'return'  => 'ids',
            's'       => $term,
            'exclude' => $exclude ? [$exclude] : [],
            'orderby' => 'relevance',
        ]);
        $results = [];
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            $sku = $p->get_sku();
            $results[] = [
                'id'   => (int) $pid,
                'text' => $p->get_name() . ($sku !== '' ? ' (' . $sku . ')' : ''),
            ];
        }
        wp_send_json_success(['results' => $results]);
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
            if (get_option('brikpanel_variation_gallery_enabled', 'yes') !== 'yes') {
                $classes .= ' brikpanel-pe-no-var-gallery';
            }
        }
        return $classes;
    }

    // =========================================================================
    // RENDER EDITOR PAGE
    // =========================================================================

    /**
     * Resolve the "Back to products" target.
     *
     * When the user opened this editor from a filtered/searched products list,
     * that list URL is passed along as `bpl_return`. To avoid any open-redirect
     * surface the raw value is never trusted: only the whitelisted bpl_ filter
     * params are extracted and re-attached to a fixed, locally-rebuilt list URL.
     * With no return value (or no recognizable filters) it falls back to the
     * plain product list.
     */
    private function get_back_url() {
        $default = admin_url('edit.php?post_type=product');

        if (empty($_GET['bpl_return'])) {
            return $default;
        }

        $parts = wp_parse_url(wp_unslash($_GET['bpl_return']));
        if (empty($parts['query'])) {
            return $default;
        }

        parse_str($parts['query'], $q);

        $clean = [];
        if (isset($q['bpl_s']))        { $clean['bpl_s']        = sanitize_text_field($q['bpl_s']); }
        if (isset($q['bpl_status']))   { $clean['bpl_status']   = sanitize_key($q['bpl_status']); }
        if (isset($q['bpl_cat']))      { $clean['bpl_cat']      = absint($q['bpl_cat']); }
        if (isset($q['bpl_stock']))    { $clean['bpl_stock']    = sanitize_key($q['bpl_stock']); }
        if (isset($q['bpl_type']))     { $clean['bpl_type']     = sanitize_key($q['bpl_type']); }
        if (isset($q['bpl_featured'])) { $clean['bpl_featured'] = sanitize_key($q['bpl_featured']); }
        if (isset($q['bpl_sort']))     { $clean['bpl_sort']     = sanitize_key($q['bpl_sort']); }
        if (isset($q['bpl_paged']))    { $clean['bpl_paged']    = absint($q['bpl_paged']); }

        if (empty($clean)) {
            return $default;
        }

        // Always rebuild onto the canonical list page; edit.php?post_type=product
        // would strip these params via redirect_default_list().
        $clean['page'] = 'brikpanel-products';
        return add_query_arg($clean, admin_url('admin.php'));
    }

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
        $back_url    = $this->get_back_url();

        // Rich-text toolbar — built once and reused by the Short description
        // and Product description editors (JS scopes commands to the closest
        // [data-editor-field] so no per-field ids are needed). Buttons map to
        // document.execCommand. The block-format control is a custom dropdown
        // (not a native <select>): a native select steals the contenteditable
        // selection when its popup opens, which made headings unreliable.
        // These are real <button>s inside the toolbar, so the global
        // mousedown-preventDefault keeps the caret/selection intact — the
        // exact mechanism that makes Bold/Italic reliable.
        ob_start(); ?>
        <div class="brikpanel-pe-editor-toolbar">
            <div class="brikpanel-pe-fmt">
                <button type="button" class="brikpanel-pe-fmt-trigger" data-cmd="formatMenu" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Text style', 'brikpanel'); ?>">
                    <span class="brikpanel-pe-fmt-label"><?php esc_html_e('Paragraph', 'brikpanel'); ?></span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="brikpanel-pe-fmt-menu" role="menu">
                    <?php
                    // The per-item visual weight/size hint lives in CSS (keyed
                    // off data-format) so the rendered label stays plain text —
                    // the JS reads .text() to mirror it onto the trigger.
                    $pe_fmt_check = '<svg class="brikpanel-pe-fmt-check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
                    ?>
                    <button type="button" class="brikpanel-pe-fmt-item is-active" data-format="p" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Paragraph', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="h1" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Heading 1', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="h2" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Heading 2', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="h3" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Heading 3', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="h4" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Heading 4', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="h5" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Heading 5', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                    <button type="button" class="brikpanel-pe-fmt-item" data-format="blockquote" role="menuitem"><span class="brikpanel-pe-fmt-name"><?php esc_html_e('Quote', 'brikpanel'); ?></span><?php echo $pe_fmt_check; ?></button>
                </div>
            </div>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="bold" title="<?php esc_attr_e('Bold', 'brikpanel'); ?>"><strong>B</strong></button>
            <button type="button" data-cmd="italic" title="<?php esc_attr_e('Italic', 'brikpanel'); ?>"><em>I</em></button>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="insertUnorderedList" title="<?php esc_attr_e('Bullet list', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><circle cx="2" cy="4" r="1.5"/><circle cx="2" cy="8" r="1.5"/><circle cx="2" cy="12" r="1.5"/><rect x="5" y="3" width="10" height="2" rx="1"/><rect x="5" y="7" width="10" height="2" rx="1"/><rect x="5" y="11" width="10" height="2" rx="1"/></svg>
            </button>
            <button type="button" data-cmd="insertOrderedList" title="<?php esc_attr_e('Numbered list', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
            </button>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="justifyLeft" title="<?php esc_attr_e('Align left', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
            </button>
            <button type="button" data-cmd="justifyCenter" title="<?php esc_attr_e('Align center', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            </button>
            <button type="button" data-cmd="justifyRight" title="<?php esc_attr_e('Align right', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="9" y1="12" x2="21" y2="12"/><line x1="6" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="brikpanel-pe-colorpick">
                <button type="button" class="brikpanel-pe-color-trigger" data-cmd="colorMenu" aria-haspopup="true" aria-expanded="false" title="<?php esc_attr_e('Text color', 'brikpanel'); ?>">
                    <span class="brikpanel-pe-color-a" aria-hidden="true">A</span>
                </button>
                <div class="brikpanel-pe-colorpick-menu" role="menu">
                    <?php
                    // Curated monochrome-friendly palette; the first swatch is the
                    // default body text colour so it doubles as "reset to normal".
                    $pe_text_colors = array(
                        '#303030', '#616161', '#8a8a8a', '#ffffff',
                        '#d72c0d', '#b45309', '#1a8917', '#0b7285',
                        '#1971c2', '#6741d9', '#c2255c', '#000000',
                    );
                    foreach ($pe_text_colors as $pe_color) :
                        ?>
                        <button type="button" class="brikpanel-pe-color-swatch" data-color="<?php echo esc_attr($pe_color); ?>" style="background:<?php echo esc_attr($pe_color); ?>" title="<?php echo esc_attr($pe_color); ?>" role="menuitem"></button>
                        <?php
                    endforeach;
                    ?>
                </div>
            </div>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="createLink" title="<?php esc_attr_e('Insert link', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </button>
            <button type="button" data-cmd="unlink" title="<?php esc_attr_e('Remove link', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18.84 12.25 1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="8" y1="2" x2="8" y2="5"/><line x1="2" y1="8" x2="5" y2="8"/><line x1="16" y1="19" x2="16" y2="22"/><line x1="19" y1="16" x2="22" y2="16"/></svg>
            </button>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="image" title="<?php esc_attr_e('Insert image', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            </button>
            <span class="brikpanel-pe-editor-divider" aria-hidden="true"></span>
            <button type="button" data-cmd="removeFormat" title="<?php esc_attr_e('Clear formatting', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4 8 20"/><line x1="15" y1="15" x2="20" y2="20"/><line x1="20" y1="15" x2="15" y2="20"/></svg>
            </button>
            <button type="button" data-cmd="html" class="brikpanel-pe-html-toggle" title="<?php esc_attr_e('HTML source', 'brikpanel'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
            </button>
        </div>
        <?php $pe_editor_toolbar = ob_get_clean();

        // Tax classes: '' is always WooCommerce's "Standard" rate. Variations
        // additionally expose "Same as parent" (stored as the literal
        // `parent`). Built once and reused by the Tax card + variation column.
        $tax_class_options = ['' => __('Standard', 'brikpanel')];
        foreach (WC_Tax::get_tax_classes() as $tc_name) {
            $tax_class_options[sanitize_title($tc_name)] = $tc_name;
        }
        $tax_class_options_variation = ['parent' => __('Same as parent', 'brikpanel')] + $tax_class_options;

        // Shipping classes: '' is "No shipping class" for simple/parent
        // products. Variations relabel '' as "Same as parent" because a
        // variation with no shipping class inherits the parent's (WC core
        // behaviour). Built once, reused by the Shipping class card + the
        // per-variation column.
        $shipping_class_options = ['' => __('No shipping class', 'brikpanel')];
        $shipping_class_terms   = get_terms(['taxonomy' => 'product_shipping_class', 'hide_empty' => false]);
        if (!is_wp_error($shipping_class_terms)) {
            foreach ($shipping_class_terms as $sc_term) {
                $shipping_class_options[$sc_term->slug] = $sc_term->name;
            }
        }
        $shipping_class_options_variation = ['' => __('Same as parent', 'brikpanel')]
            + array_diff_key($shipping_class_options, ['' => true]);

        $categories  = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        // Brand taxonomy — native WC `product_brand` (9.6+) or a third-party
        // brand plugin. Resolved once here and reused by the brand card render
        // + the "add new brand" inline form. Empty string when the install has
        // no brand taxonomy, in which case the brand section never renders.
        $brand_taxonomy   = brikpanel_pe_brand_taxonomy();
        $brand_hierarchical = $brand_taxonomy !== '' && is_taxonomy_hierarchical($brand_taxonomy);
        $brands = [];
        if ($brand_taxonomy !== '') {
            $brands = get_terms(['taxonomy' => $brand_taxonomy, 'hide_empty' => false]);
            if (is_wp_error($brands)) {
                $brands = [];
            }
        }

        // Treat auto-drafts as "new" in the UI — WP creates the row on first
        // visit so our metabox compat layer has something to attach to, but
        // the user hasn't committed yet.
        $is_auto_draft = $product && $product->get_status() === 'auto-draft';
        $is_edit     = !empty($product) && !$is_auto_draft;
        $page_title  = $is_edit ? __('Edit product', 'brikpanel') : __('Add product', 'brikpanel');

        // A product is "live" once it has been committed to a visible status
        // (publish/private). Brand-new and auto-draft products are not live, so
        // silent auto-save stays off for them — the user hasn't published yet.
        $is_live     = $is_edit && in_array($data['status'], ['publish', 'private'], true);

        // COGS: gated on the "Cost of goods" entry in the section visibility
        // picker. WC 9.5+ native COGS is still consumed under the hood when
        // available; otherwise the save handler falls back to the
        // BrikPanel-native _brikpanel_cogs meta key so profit tracking works
        // on any WooCommerce version. Hiding the section here also strips the
        // per-variation COGS column.
        $cogs_enabled    = in_array('cogs', $visible, true);
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

        // Vendor integration — gated on a settings toggle. Resolved up front
        // so both the JS payload below and the COGS card render later can
        // read the same values without re-querying.
        $bp_vendor_field_on = class_exists('Brikpanel_Vendor_Product_Editor') && Brikpanel_Vendor_Product_Editor::vendor_field_enabled();
        $bp_vendor_options  = $bp_vendor_field_on ? Brikpanel_Vendor_Product_Editor::active_options() : [];
        $bp_current_vendor  = $bp_vendor_field_on && $product_id ? (int) get_post_meta($product_id, '_brikpanel_vendor_id', true) : 0;
        $bp_current_v_sku   = $bp_vendor_field_on && $product_id ? (string) get_post_meta($product_id, '_brikpanel_vendor_sku', true) : '';

        // Featured-product star — opt-in via the Quick Edit field panel. The
        // star sits in the editor header (next to the status dropdown). When
        // enabled the products list page also renders a clickable star at the
        // start of each row and the quick-edit drawer header.
        $featured_star_on = function_exists('brikpanel_qe_is_field_visible')
            ? brikpanel_qe_is_field_visible('featured')
            : false;

        // Backorder "Notify customer" sub-option — opt-in via settings.
        // When on, selecting "On backorder" reveals a radio group letting
        // the merchant pick between silent backorders and ones that flag
        // an order note for the customer (WC's `notify` value).
        $backorder_notify_on = get_option('brikpanel_pe_backorder_notify', 'no') === 'yes';

        // JS data for existing product
        $js_data = wp_json_encode([
            'id'                => $product_id,
            'is_variable'       => $data['is_variable'],
            'product_type'      => $product_type_current,
            'type_selector'     => $product_type_selector_enabled,
            'attributes'        => $data['attributes'],
            'non_variation_attributes' => $data['non_variation_attributes'],
            'variations'        => $data['variations'],
            'gallery'           => $data['gallery'],
            'global_attributes' => $global_attributes,
            'downloads'         => $data['downloads'],
            'is_downloadable'   => $data['is_downloadable'],
            'is_virtual'        => !empty($data['is_virtual']),
            'tags'              => $data['tags'],
            'all_tags'          => array_values($all_tags),
            'cogs_enabled'      => $cogs_enabled,
            'gtin_enabled'      => in_array('gtin', $visible, true),
            'tax_enabled'       => in_array('tax', $visible, true),
            'tax_class_options' => $tax_class_options_variation,
            'shipping_class_enabled' => in_array('shipping_class', $visible, true),
            'shipping_class_options' => $shipping_class_options_variation,
            'upsells'           => $data['upsells'],
            'cross_sells'       => $data['cross_sells'],
            'variation_extras'  => $variation_extras,
            // Empty array when the toggle is off so the JS dropdown renderer
            // skips the column without extra checks.
            'vendor_field_enabled' => $bp_vendor_field_on,
            'vendor_options'       => $bp_vendor_field_on ? array_map(static fn($k, $v) => ['id' => (int) $k, 'name' => (string) $v], array_keys($bp_vendor_options), $bp_vendor_options) : [],
            'parent_vendor_id'     => $bp_vendor_field_on ? (int) $bp_current_vendor : 0,
            'backorder_notify'     => $backorder_notify_on,
            // Parent backorder value — only used when the sub-option is on.
            // For variable products the parent has no real backorder state,
            // so JS simply hides the parent radio in that case.
            'parent_backorders'    => (string) $data['backorders'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        // HEX flags above neutralise any literal `</script>`, `&`, quotes that
        // could otherwise break out of the inline <script> below. The payload
        // carries raw 3rd-party variation HTML (`variation_extras`) and
        // attribute/term names that may originate from imports or other
        // plugins, so escaping here is the stored-XSS guard for this admin page.

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
            <input type="hidden" id="bpe-product-id" value="<?php echo esc_attr($product_id); ?>" data-live="<?php echo $is_live ? '1' : '0'; ?>">

            <!-- Header -->
            <div class="brikpanel-pe-header">
                <div class="brikpanel-pe-header-left">
                    <a href="<?php echo esc_url($back_url); ?>" class="brikpanel-pe-back">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?php esc_html_e('Products', 'brikpanel'); ?>
                    </a>
                    <h1><?php echo esc_html($page_title); ?></h1>
                </div>
                <div class="brikpanel-pe-header-right">
                    <?php
                    // Secondary navigation actions (View product / Duplicate /
                    // Add new). On desktop they render inline; on mobile they
                    // collapse behind the "More actions" overflow menu (CSS +
                    // initHeaderOverflow()) so the sticky header stays compact.
                    // The overflow wrapper is only emitted for an existing
                    // product, where at least the Duplicate action is available.
                    ?>
                    <?php if ($is_edit) : ?>
                    <div class="brikpanel-pe-header-overflow" id="bpe-header-overflow">
                        <button type="button" class="brikpanel-pe-overflow-trigger" id="bpe-overflow-trigger" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e('More actions', 'brikpanel'); ?>" title="<?php esc_attr_e('More actions', 'brikpanel'); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                        </button>
                        <div class="brikpanel-pe-overflow-menu" role="menu">
                            <?php if ($is_live) : ?>
                            <a href="<?php echo esc_url(get_permalink($product_id)); ?>" class="brikpanel-pe-btn secondary" id="bpe-view-product" role="menuitem" target="_blank"><?php esc_html_e('View product', 'brikpanel'); ?></a>
                            <?php endif; ?>
                            <button type="button" class="brikpanel-pe-btn secondary" id="bpe-duplicate" role="menuitem" data-id="<?php echo esc_attr($product_id); ?>"><?php esc_html_e('Duplicate', 'brikpanel'); ?></button>
                            <?php if ($is_live) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=brikpanel-product-editor')); ?>" class="brikpanel-pe-btn secondary" id="bpe-add-new" role="menuitem">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add new', 'brikpanel'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
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
                    <?php if ($featured_star_on) :
                        $is_featured = !empty($data['is_featured']);
                        $star_label_on  = __('Featured', 'brikpanel');
                        $star_label_off = __('Mark as featured', 'brikpanel');
                    ?>
                    <button type="button"
                            class="brikpanel-pe-featured-star<?php echo $is_featured ? ' is-on' : ''; ?>"
                            id="bpe-featured-star"
                            aria-pressed="<?php echo $is_featured ? 'true' : 'false'; ?>"
                            title="<?php echo esc_attr($is_featured ? $star_label_on : $star_label_off); ?>"
                            data-label-on="<?php echo esc_attr($star_label_on); ?>"
                            data-label-off="<?php echo esc_attr($star_label_off); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="<?php echo $is_featured ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                        </svg>
                        <span class="screen-reader-text"><?php echo esc_html($is_featured ? $star_label_on : $star_label_off); ?></span>
                    </button>
                    <input type="hidden" id="bpe-is-featured" name="is_featured" value="<?php echo $is_featured ? '1' : '0'; ?>">
                    <?php endif; ?>
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
                        <?php
                        // Existing live (or password-protected) product → Update.
                        // Brand-new product with the default Published status →
                        // Publish (clicking actually publishes).
                        // Anything else (Draft / Private new product) → Save.
                        if (($is_edit && in_array($data['status'], ['publish', 'private'], true)) || $is_password) {
                            esc_html_e('Update', 'brikpanel');
                        } elseif (!$is_edit && $data['status'] === 'publish') {
                            esc_html_e('Publish', 'brikpanel');
                        } else {
                            esc_html_e('Save', 'brikpanel');
                        }
                        ?>
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

                // Capture each built-in section into its own output buffer.
                // We render them at the bottom of this block in the order
                // configured in settings (brikpanel_pe_section_order), so the
                // admin's chosen layout takes effect without us reshuffling
                // any of the per-section markup below.
                $section_html = [];
                ?>

                <?php if (in_array('images', $visible, true)) : ob_start(); ?>
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
                <?php $section_html['images'] = ob_get_clean(); endif; ?>

                <?php if (in_array('slug', $visible, true)) : ob_start();
                    // Build a read-only URL prefix (e.g. https://shop.com/product/)
                    // so the merchant sees exactly what the slug appends to. When
                    // the product permalink base contains a dynamic token like
                    // %product_cat% an exact prefix can't be shown, so fall back
                    // to a bare input with just the help text.
                    $bpe_perm       = function_exists('wc_get_permalink_structure') ? wc_get_permalink_structure() : array();
                    $bpe_base_slug  = isset($bpe_perm['product_rewrite_slug']) ? $bpe_perm['product_rewrite_slug'] : 'product';
                    $bpe_url_prefix = (strpos((string) $bpe_base_slug, '%') === false)
                        ? trailingslashit(home_url('/' . ltrim((string) $bpe_base_slug, '/')))
                        : '';
                ?>
                <!-- Permalink / URL slug (opt-in section) -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-slug"><?php esc_html_e('Permalink (URL slug)', 'brikpanel'); ?></label>
                        <?php if ($bpe_url_prefix !== '') : ?>
                        <div class="brikpanel-pe-input-group">
                            <span class="brikpanel-pe-input-prefix brikpanel-pe-slug-prefix"><?php echo esc_html($bpe_url_prefix); ?></span>
                            <input type="text" id="bpe-slug" value="<?php echo esc_attr($data['slug']); ?>" placeholder="<?php esc_attr_e('auto-generated-from-name', 'brikpanel'); ?>" spellcheck="false">
                        </div>
                        <?php else : ?>
                        <input type="text" id="bpe-slug" value="<?php echo esc_attr($data['slug']); ?>" placeholder="<?php esc_attr_e('auto-generated-from-name', 'brikpanel'); ?>" spellcheck="false">
                        <?php endif; ?>
                        <p class="brikpanel-pe-help-text"><?php esc_html_e('The last part of the product URL. Leave blank to generate it automatically from the product name.', 'brikpanel'); ?></p>
                    </div>
                </div>
                <?php $section_html['slug'] = ob_get_clean(); endif; ?>

                <?php
                $bpe_show_var  = in_array('variations', $visible, true);
                $bpe_show_attr = in_array('attributes', $visible, true);
                if ($bpe_show_var || $bpe_show_attr) : ob_start(); ?>
                <!-- Variations & attributes — one unified attribute editor with a
                     top "Variable product" toggle (WooCommerce-native model).
                     Each attribute row carries a "Use for variations" switch:
                     switched-on rows build the variation table, switched-off rows
                     stay as plain product specs (Brand, Material…). A variable
                     product can carry BOTH at once; a simple product treats every
                     attribute as a spec. The card is always visible. -->
                <div class="brikpanel-pe-card<?php echo $data['is_variable'] ? ' bpe-variable-on' : ''; ?>" id="bpe-var-card">

                    <?php if ($bpe_show_var) : ?>
                    <div class="brikpanel-pe-var-head" id="bpe-var-toggle-row">
                        <div class="brikpanel-pe-toggle-text">
                            <span><?php esc_html_e('Variable product', 'brikpanel'); ?></span>
                            <small><?php esc_html_e('Has options buyers choose from, like size or color.', 'brikpanel'); ?></small>
                        </div>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-var-toggle" <?php checked($data['is_variable']); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <?php else : ?>
                    <?php // Variations hidden by the admin: keep the flag present
                          // (always simple) so the save path stays consistent. ?>
                    <input type="checkbox" id="bpe-var-toggle" hidden style="display:none">
                    <?php endif; ?>

                    <div class="brikpanel-pe-attr-editor">
                        <label class="brikpanel-pe-attr-label"><?php echo esc_html(_x('Attributes', 'product editor attribute section heading', 'brikpanel')); ?></label>
                        <p class="brikpanel-pe-help-text brikpanel-pe-attr-help-var" style="margin-top:0;<?php echo $data['is_variable'] ? '' : 'display:none'; ?>"><?php esc_html_e('Add attributes, then switch on “Use for variations” for the ones buyers pick (Size, Color). Others stay as specs shown on the product page.', 'brikpanel'); ?></p>
                        <p class="brikpanel-pe-help-text brikpanel-pe-attr-help-simple" style="margin-top:0;<?php echo $data['is_variable'] ? 'display:none' : ''; ?>"><?php esc_html_e('Specs that appear on the product page (e.g. Brand, Material).', 'brikpanel'); ?></p>

                        <?php if ($bpe_show_var) : ?>
                        <!-- Quick-start template cards — shown only while Variable is
                             on and no attributes exist yet. JS toggles them against
                             #bpe-var-attr-controls (see updateVarStartView). -->
                        <div class="brikpanel-pe-var-templates" id="bpe-var-templates" style="display:none">
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
                        <?php endif; ?>

                        <div id="bpe-var-attr-controls">
                            <div class="brikpanel-pe-attr-add">
                                <?php if (!empty($global_attributes)) : ?>
                                <!-- Searchable attribute picker (custom popover dropdown):
                                     a styled trigger that opens a search box + scrollable
                                     option list. Replaces the plain native <select>; the
                                     list is driven entirely from data-* attributes so the
                                     JS reuses the same add-attribute path. -->
                                <div class="brikpanel-pe-combo" id="bpe-attr-combo">
                                    <button type="button" class="brikpanel-pe-combo-trigger" id="bpe-attr-combo-trigger" aria-haspopup="listbox" aria-expanded="false">
                                        <span class="brikpanel-pe-combo-value"><?php esc_html_e('Select existing attribute…', 'brikpanel'); ?></span>
                                        <svg class="brikpanel-pe-combo-chevron" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <div class="brikpanel-pe-combo-panel" id="bpe-attr-combo-panel">
                                        <div class="brikpanel-pe-combo-search">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                                            <input type="text" id="bpe-attr-combo-search" placeholder="<?php esc_attr_e('Search attributes…', 'brikpanel'); ?>" autocomplete="off">
                                        </div>
                                        <ul class="brikpanel-pe-combo-list" id="bpe-attr-combo-list" role="listbox">
                                            <?php foreach ($global_attributes as $ga) : ?>
                                            <li class="brikpanel-pe-combo-option" role="option" data-value="<?php echo esc_attr($ga['name']); ?>" data-taxonomy="<?php echo esc_attr($ga['taxonomy']); ?>"><?php echo esc_html($ga['name']); ?></li>
                                            <?php endforeach; ?>
                                            <li class="brikpanel-pe-combo-empty" style="display:none"><?php esc_html_e('No matching attributes', 'brikpanel'); ?></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="brikpanel-pe-attr-divider"><span><?php esc_html_e('or create new', 'brikpanel'); ?></span></div>
                                <?php endif; ?>

                                <div class="brikpanel-pe-attr-inputgroup">
                                    <input type="text" id="bpe-attr-new-name" placeholder="<?php esc_attr_e('Attribute name (e.g.: Size, Brand)', 'brikpanel'); ?>">
                                    <button type="button" id="bpe-attr-add"><?php esc_html_e('Add', 'brikpanel'); ?></button>
                                </div>

                                <?php if ($bpe_show_var) : ?>
                                <div class="brikpanel-pe-attr-quickrow">
                                    <span class="brikpanel-pe-attr-quicklabel"><?php esc_html_e('Quick add:', 'brikpanel'); ?></span>
                                    <button type="button" class="brikpanel-pe-chip-btn brikpanel-pe-attr-quick" id="bpe-attr-quick-sizecolor"><?php esc_html_e('+ Size & Color', 'brikpanel'); ?></button>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div id="bpe-attr-list"></div>
                        </div>
                    </div>

                    <?php if ($bpe_show_var) : ?>
                    <!-- Variation builder: visible only while "Variable product" is on -->
                    <div class="brikpanel-pe-var-build" id="bpe-var-build"<?php echo $data['is_variable'] ? '' : ' style="display:none"'; ?>>
                        <button type="button" class="brikpanel-pe-btn primary" id="bpe-generate-vars"><?php esc_html_e('Generate variations', 'brikpanel'); ?></button>
                        <span class="brikpanel-pe-var-stale-hint" id="bpe-var-stale-hint" hidden><?php esc_html_e('Attributes changed since you generated. Click Generate variations to refresh the list.', 'brikpanel'); ?></span>

                        <div class="brikpanel-pe-var-table-section" id="bpe-var-table-section" style="display:none">
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
                                            <th class="var-track-col" title="<?php esc_attr_e('Track stock quantity for this variation', 'brikpanel'); ?>"><?php esc_html_e('Track', 'brikpanel'); ?></th>
                                            <th><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                            <th><?php esc_html_e('Status', 'brikpanel'); ?></th>
                                            <?php if ($cogs_enabled) : ?><th><?php esc_html_e('COGS', 'brikpanel'); ?></th><?php endif; ?>
                                            <?php if ($bp_vendor_field_on) : ?><th class="bpe-var-vendor-th"><?php esc_html_e('Supplier', 'brikpanel'); ?></th><?php endif; ?>
                                            <th><?php esc_html_e('SKU', 'brikpanel'); ?></th>
                                            <?php if (in_array('gtin', $visible, true)) : ?><th><?php esc_html_e('GTIN', 'brikpanel'); ?></th><?php endif; ?>
                                            <?php if (in_array('tax', $visible, true)) : ?><th><?php esc_html_e('Tax class', 'brikpanel'); ?></th><?php endif; ?>
                                            <?php if (in_array('shipping_class', $visible, true)) : ?><th><?php esc_html_e('Shipping class', 'brikpanel'); ?></th><?php endif; ?>
                                            <th><?php esc_html_e('Image', 'brikpanel'); ?></th>
                                            <th class="var-delete-col" aria-hidden="true"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="bpe-var-table-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; // $bpe_show_var ?>
                </div>
                <?php
                // Emit the combined card exactly once, at the slot of whichever
                // section the admin kept visible (prefer the variations slot;
                // fall back to the attributes slot when variations are hidden).
                $bpe_combined = ob_get_clean();
                if ($bpe_show_var) {
                    $section_html['variations'] = $bpe_combined;
                } elseif ($bpe_show_attr) {
                    $section_html['attributes'] = $bpe_combined;
                }
                endif; ?>

                <?php if (in_array('pricing', $visible, true)) : ob_start(); ?>
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
                            <input type="text" id="bpe-sale-from" value="<?php echo esc_attr($data['sale_from']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD (optional)', 'brikpanel'); ?>" autocomplete="off">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-to"><?php esc_html_e('Sale end date', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-sale-to" value="<?php echo esc_attr($data['sale_to']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD (optional)', 'brikpanel'); ?>" autocomplete="off">
                        </div>
                    </div>
                    <p class="brikpanel-pe-help-text"><?php esc_html_e('Schedule your sale in advance — leave the dates empty to start it immediately or keep it running indefinitely.', 'brikpanel'); ?></p>
                </div>
                <?php $section_html['pricing'] = ob_get_clean(); endif; ?>

                <?php
                // Cost of goods + Vendor are visually one card: vendor sits to
                // the right of cost so admins can answer both questions
                // ("how much does this cost me?" + "from whom?") in one
                // glance. The four `$bp_vendor_*` variables are resolved at
                // the top of render_page() so this block just consumes them.
                ?>
                <?php if ($cogs_enabled) : ob_start(); ?>
                <!-- Cost of Goods + Vendor -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-row<?php echo $bp_vendor_field_on ? ' brikpanel-pe-row-cogs-vendor' : ''; ?>">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-cogs"><?php esc_html_e('Cost of goods', 'brikpanel'); ?></label>
                            <div class="brikpanel-pe-input-group">
                                <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                <input type="text" id="bpe-cogs" value="<?php echo esc_attr($cogs_val); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-price="1">
                            </div>
                        </div>
                        <?php if ($bp_vendor_field_on) : ?>
                        <div class="brikpanel-pe-field brikpanel-pe-field-vendor">
                            <label for="bpe-vendor">
                                <?php esc_html_e('Supplier', 'brikpanel'); ?>
                            </label>
                            <select id="bpe-vendor" class="brikpanel-pe-select brikpanel-pe-vendor-select" name="bp_vendor_id" data-vendor-current="<?php echo esc_attr((string) $bp_current_vendor); ?>">
                                <option value="0"><?php esc_html_e('— None —', 'brikpanel'); ?></option>
                                <?php foreach ($bp_vendor_options as $v_id => $v_name) : ?>
                                    <option value="<?php echo esc_attr((string) $v_id); ?>" <?php selected($bp_current_vendor, $v_id); ?>><?php echo esc_html($v_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($bp_vendor_field_on) : ?>
                    <div class="brikpanel-pe-row brikpanel-pe-row-cogs-vendor brikpanel-pe-vendor-sku-row" <?php echo $bp_current_vendor === 0 ? 'hidden' : ''; ?>>
                        <div class="brikpanel-pe-field"><!-- spacer to keep alignment with COGS column --></div>
                        <div class="brikpanel-pe-field brikpanel-pe-field-vendor">
                            <label for="bpe-vendor-sku">
                                <?php esc_html_e('Supplier SKU', 'brikpanel'); ?>
                                <span class="brikpanel-pe-tooltip" data-tip="<?php esc_attr_e('The supplier\'s product code. Optional — used by stock orders to match this product on incoming POs.', 'brikpanel'); ?>">?</span>
                            </label>
                            <input type="text" id="bpe-vendor-sku" name="bp_vendor_sku" value="<?php echo esc_attr($bp_current_v_sku); ?>" placeholder="<?php esc_attr_e('Optional', 'brikpanel'); ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                    <p class="brikpanel-pe-help-text">
                        <?php
                        if ($bp_vendor_field_on) {
                            esc_html_e('What this product costs you, and where you buy it from. Used in profit calculations and stock orders.', 'brikpanel');
                        } else {
                            esc_html_e('The cost you pay for this product. Used to calculate profit margins.', 'brikpanel');
                        }
                        ?>
                    </p>
                </div>
                <?php $section_html['cogs'] = ob_get_clean(); endif; ?>

                <?php if (in_array('inventory', $visible, true)) : ob_start(); ?>
                <!-- Inventory (hidden when the product has variations — each
                     variation carries its own stock + SKU) -->
                <?php $bp_manage = !empty($data['manage_stock']); ?>
                <div class="brikpanel-pe-card" id="bpe-inventory-card">
                    <!-- Track quantity (manage stock). When on, the merchant
                         enters a quantity and WooCommerce derives the stock
                         status; when off, the status is picked directly. This
                         mirrors WooCommerce's native Inventory tab and avoids
                         the "managed + qty 0 can't be in stock" trap. -->
                    <div class="brikpanel-pe-toggle-row" id="bpe-manage-stock-row">
                        <span class="brikpanel-pe-toggle-label">
                            <?php esc_html_e('Track quantity', 'brikpanel'); ?>
                            <small class="brikpanel-pe-toggle-help"><?php esc_html_e('Manage stock at product level. Turn off to simply mark the product in or out of stock.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-manage-stock" <?php checked($bp_manage); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-row brikpanel-pe-row-3">
                        <div class="brikpanel-pe-field" id="bpe-stock-qty-field"<?php echo $bp_manage ? '' : ' style="display:none"'; ?>>
                            <label for="bpe-stock"><?php esc_html_e('Stock quantity', 'brikpanel'); ?></label>
                            <input type="number" id="bpe-stock" value="<?php echo esc_attr($data['stock_quantity']); ?>" min="0" placeholder="0">
                        </div>
                        <div class="brikpanel-pe-field" id="bpe-backorders-field"<?php echo $bp_manage ? '' : ' style="display:none"'; ?>>
                            <label for="bpe-backorders"><?php esc_html_e('Allow backorders?', 'brikpanel'); ?></label>
                            <select id="bpe-backorders" class="brikpanel-pe-select">
                                <option value="no" <?php selected($data['backorders'], 'no'); ?>><?php esc_html_e('Do not allow', 'brikpanel'); ?></option>
                                <?php if ($backorder_notify_on) : ?>
                                <option value="notify" <?php selected($data['backorders'], 'notify'); ?>><?php esc_html_e('Allow, but notify customer', 'brikpanel'); ?></option>
                                <?php endif; ?>
                                <option value="yes" <?php selected($data['backorders'], 'yes'); ?>><?php esc_html_e('Allow', 'brikpanel'); ?></option>
                            </select>
                        </div>
                        <div class="brikpanel-pe-field" id="bpe-stock-status-field"<?php echo $bp_manage ? ' style="display:none"' : ''; ?>>
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
                    <?php if ($backorder_notify_on) :
                        // The radio is "yes" when the saved value is anything
                        // other than `notify` so legacy products (and the
                        // common case) stay on the silent option.
                        $bp_backorder_value = $data['backorders'] === 'notify' ? 'notify' : 'yes';
                    ?>
                    <!-- Backorder notification — only meaningful while the
                         stock status is "On backorder", so JS toggles the
                         `open` class on this collapse based on the select. -->
                    <div class="brikpanel-pe-collapse brikpanel-pe-backorder-collapse" id="bpe-backorder-notify">
                        <div>
                            <div class="brikpanel-pe-field brikpanel-pe-backorder-field">
                                <label><?php esc_html_e('Backorder behavior', 'brikpanel'); ?></label>
                                <div class="brikpanel-pe-radio-group">
                                    <label class="brikpanel-pe-radio">
                                        <input type="radio" name="backorders" value="yes" <?php checked($bp_backorder_value, 'yes'); ?>>
                                        <span><?php esc_html_e('Allow without notification', 'brikpanel'); ?></span>
                                    </label>
                                    <label class="brikpanel-pe-radio">
                                        <input type="radio" name="backorders" value="notify" <?php checked($bp_backorder_value, 'notify'); ?>>
                                        <span><?php esc_html_e('Allow and notify customer', 'brikpanel'); ?></span>
                                    </label>
                                </div>
                                <p class="brikpanel-pe-help-text"><?php esc_html_e('Notification adds a customer-visible note to backorder line items at checkout.', 'brikpanel'); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $section_html['inventory'] = ob_get_clean(); endif; ?>

                <?php if (in_array('gtin', $visible, true)) : ob_start(); ?>
                <!-- GTIN / Barcode (opt-in via settings; hidden for variable
                     products, which carry a GTIN per variation instead). -->
                <div class="brikpanel-pe-card" id="bpe-gtin-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-gtin">
                            <?php esc_html_e('GTIN, UPC, EAN, or ISBN', 'brikpanel'); ?>
                            <span class="brikpanel-pe-tooltip" data-tip="<?php esc_attr_e('The global product barcode. Used by Google Shopping and marketplace feeds. Leave empty if you do not have one.', 'brikpanel'); ?>">?</span>
                        </label>
                        <input type="text" id="bpe-gtin" value="<?php echo esc_attr($data['global_unique_id']); ?>" placeholder="<?php esc_attr_e('e.g.: 0012345678905', 'brikpanel'); ?>" inputmode="numeric" autocomplete="off">
                    </div>
                </div>
                <?php $section_html['gtin'] = ob_get_clean(); endif; ?>

                <?php if (in_array('tax', $visible, true)) : ob_start(); ?>
                <!-- Tax (status & class) — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-tax-card">
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-tax-status"><?php esc_html_e('Tax status', 'brikpanel'); ?></label>
                            <select id="bpe-tax-status" class="brikpanel-pe-select">
                                <option value="taxable" <?php selected($data['tax_status'], 'taxable'); ?>><?php esc_html_e('Taxable', 'brikpanel'); ?></option>
                                <option value="shipping" <?php selected($data['tax_status'], 'shipping'); ?>><?php esc_html_e('Shipping only', 'brikpanel'); ?></option>
                                <option value="none" <?php selected($data['tax_status'], 'none'); ?>><?php esc_html_e('None', 'brikpanel'); ?></option>
                            </select>
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-tax-class"><?php esc_html_e('Tax class', 'brikpanel'); ?></label>
                            <select id="bpe-tax-class" class="brikpanel-pe-select">
                                <?php foreach ($tax_class_options as $tc_slug => $tc_label) : ?>
                                    <option value="<?php echo esc_attr($tc_slug); ?>" <?php selected($data['tax_class'], $tc_slug); ?>><?php echo esc_html($tc_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="brikpanel-pe-help-text"><?php esc_html_e('Variable products: each variation can override the tax class from the variations table.', 'brikpanel'); ?></p>
                </div>
                <?php $section_html['tax'] = ob_get_clean(); endif; ?>

                <?php if (in_array('sold_individually', $visible, true)) : ob_start(); ?>
                <!-- Sold individually — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-sold-individually-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <?php esc_html_e('Sold individually', 'brikpanel'); ?>
                            <small class="brikpanel-pe-toggle-help"><?php esc_html_e('Limit purchases to one of this product per order.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-sold-individually" <?php checked(!empty($data['sold_individually'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                </div>
                <?php $section_html['sold_individually'] = ob_get_clean(); endif; ?>

                <?php if (in_array('linked', $visible, true)) : ob_start(); ?>
                <!-- Linked products (upsells & cross-sells) — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-linked-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-upsells-search">
                            <?php esc_html_e('Upsells', 'brikpanel'); ?>
                            <span class="brikpanel-pe-tooltip" data-tip="<?php esc_attr_e('Products promoted on the product page as a higher-value alternative.', 'brikpanel'); ?>">?</span>
                        </label>
                        <div class="brikpanel-pe-linked" data-field="upsells">
                            <div class="brikpanel-pe-linked-chips" id="bpe-upsells-chips"></div>
                            <input type="text" id="bpe-upsells-search" class="brikpanel-pe-linked-search" placeholder="<?php esc_attr_e('Search products...', 'brikpanel'); ?>" autocomplete="off">
                            <div class="brikpanel-pe-linked-results" hidden></div>
                        </div>
                    </div>
                    <div class="brikpanel-pe-field">
                        <label for="bpe-crosssells-search">
                            <?php esc_html_e('Cross-sells', 'brikpanel'); ?>
                            <span class="brikpanel-pe-tooltip" data-tip="<?php esc_attr_e('Products promoted in the cart alongside this one.', 'brikpanel'); ?>">?</span>
                        </label>
                        <div class="brikpanel-pe-linked" data-field="cross_sells">
                            <div class="brikpanel-pe-linked-chips" id="bpe-crosssells-chips"></div>
                            <input type="text" id="bpe-crosssells-search" class="brikpanel-pe-linked-search" placeholder="<?php esc_attr_e('Search products...', 'brikpanel'); ?>" autocomplete="off">
                            <div class="brikpanel-pe-linked-results" hidden></div>
                        </div>
                    </div>
                </div>
                <?php $section_html['linked'] = ob_get_clean(); endif; ?>

                <?php if (in_array('advanced', $visible, true)) : ob_start(); ?>
                <!-- Advanced (purchase note, reviews, menu order) — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-advanced-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-purchase-note"><?php esc_html_e('Purchase note', 'brikpanel'); ?></label>
                        <textarea id="bpe-purchase-note" rows="3" placeholder="<?php esc_attr_e('Note sent to the customer after they buy this product.', 'brikpanel'); ?>"><?php echo esc_textarea($data['purchase_note']); ?></textarea>
                    </div>
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <?php esc_html_e('Enable reviews', 'brikpanel'); ?>
                            <small class="brikpanel-pe-toggle-help"><?php esc_html_e('Allow customers to leave reviews on this product.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-reviews-allowed" <?php checked(!empty($data['reviews_allowed'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-field">
                        <label for="bpe-menu-order"><?php esc_html_e('Menu order', 'brikpanel'); ?></label>
                        <input type="number" id="bpe-menu-order" value="<?php echo esc_attr($data['menu_order']); ?>" step="1" placeholder="0">
                    </div>
                </div>
                <?php $section_html['advanced'] = ob_get_clean(); endif; ?>

                <?php if (in_array('category', $visible, true)) : ob_start(); ?>
                <!-- Category -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Category', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-cat-wrap">
                        <input type="text" class="brikpanel-pe-cat-search" id="bpe-cat-search" placeholder="<?php esc_attr_e('Search categories...', 'brikpanel'); ?>">
                        <div class="brikpanel-pe-cat-list">
                            <?php $this->render_category_checklist($categories, $data['category_ids']); ?>
                        </div>
                    </div>
                    <?php
                    // "Primary category" — SEOPress, Yoast and Rank Math each
                    // normally inject this selector into the native Categories
                    // panel via a JS bundle that never runs on the BrikPanel editor
                    // page, so the field would be missing here for all of them.
                    // Surface one shared control, populated from the currently
                    // selected categories (kept in sync client-side as the
                    // checklist changes), and mirror the choice to every active
                    // plugin on save. Works for simple and variable products alike
                    // — the value is product-level post meta.
                    $bpe_pc_plugins = self::primary_category_seo_plugins();
                    if (!empty($bpe_pc_plugins)) :
                        $bpe_pc_badge = implode(' · ', array_column($bpe_pc_plugins, 'label'));
                        $bpe_sel_cat_names = [];
                        foreach ($categories as $bpe_cat) {
                            if (in_array($bpe_cat->term_id, $data['category_ids'], true)) {
                                $bpe_sel_cat_names[(int) $bpe_cat->term_id] = $bpe_cat->name;
                            }
                        }
                    ?>
                    <div class="brikpanel-pe-field brikpanel-pe-primary-cat-field" style="margin-top:0.75rem;">
                        <label for="bpe-seo-primary-cat">
                            <?php esc_html_e('Primary category', 'brikpanel'); ?>
                            <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($bpe_pc_badge); ?></span>
                        </label>
                        <select id="bpe-seo-primary-cat" class="brikpanel-pe-select">
                            <option value="none"><?php esc_html_e('None (will disable this feature)', 'brikpanel'); ?></option>
                            <?php foreach ($bpe_sel_cat_names as $bpe_cid => $bpe_cname) : ?>
                                <option value="<?php echo esc_attr($bpe_cid); ?>" <?php selected((string) $data['primary_cat'], (string) $bpe_cid); ?>><?php echo esc_html($bpe_cname); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="brikpanel-pe-help-text"><?php esc_html_e('The category used in the %category% permalink and breadcrumbs when a product has several categories.', 'brikpanel'); ?></p>
                    </div>
                    <?php endif; ?>
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
                <?php $section_html['category'] = ob_get_clean(); endif; ?>

                <?php if ($brand_taxonomy !== '' && in_array('brand', $visible, true)) : ob_start(); ?>
                <!-- Brand -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Brand', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-cat-wrap">
                        <input type="text" class="brikpanel-pe-cat-search" id="bpe-brand-search" placeholder="<?php esc_attr_e('Search brands...', 'brikpanel'); ?>">
                        <div class="brikpanel-pe-cat-list" id="bpe-brand-list">
                            <?php $this->render_category_checklist($brands, $data['brand_ids'], 0, 0, 'brand_ids[]'); ?>
                        </div>
                    </div>
                    <?php if (current_user_can('manage_product_terms')) : ?>
                    <a href="#" id="bpe-add-brand-toggle" class="brikpanel-pe-link"><?php esc_html_e('+ Add new brand', 'brikpanel'); ?></a>
                    <div class="brikpanel-pe-collapse" id="bpe-new-brand-section">
                        <div>
                            <div class="brikpanel-pe-inline-form">
                                <input type="text" id="bpe-new-brand-name" placeholder="<?php esc_attr_e('Brand name', 'brikpanel'); ?>">
                                <?php if ($brand_hierarchical) : ?>
                                <select id="bpe-new-brand-parent">
                                    <option value="0"><?php esc_html_e('— No parent —', 'brikpanel'); ?></option>
                                    <?php $this->render_category_parent_options($brands); ?>
                                </select>
                                <?php endif; ?>
                                <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-brand-btn"><?php esc_html_e('Add', 'brikpanel'); ?></button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $section_html['brand'] = ob_get_clean(); endif; ?>

                <?php if (in_array('tags', $visible, true)) : ob_start(); ?>
                <!-- Tags -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Tags', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-tag-input-wrap" id="bpe-tags-wrap">
                        <input type="text" id="bpe-tag-input" placeholder="<?php esc_attr_e('Type and press Enter to add a tag...', 'brikpanel'); ?>" autocomplete="off">
                    </div>
                    <div class="brikpanel-pe-tag-suggestions" id="bpe-tag-suggestions"></div>
                </div>
                <?php $section_html['tags'] = ob_get_clean(); endif; ?>

                <?php if (in_array('digital', $visible, true)) : ob_start(); ?>
                <!-- Virtual & Digital product type -->
                <div class="brikpanel-pe-card" id="bpe-virtual-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <?php esc_html_e('Virtual (no shipping)', 'brikpanel'); ?>
                            <small class="brikpanel-pe-toggle-help"><?php esc_html_e('Service or intangible product — no physical shipping.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-virtual-toggle" <?php checked(!empty($data['is_virtual'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <?php esc_html_e('Digital (downloadable)', 'brikpanel'); ?>
                            <small class="brikpanel-pe-toggle-help"><?php esc_html_e('Customers receive download links after purchase.', 'brikpanel'); ?></small>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-digital-toggle" <?php checked($data['is_downloadable']); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-collapse <?php echo $data['is_downloadable'] ? 'open' : ''; ?>" id="bpe-digital-section">
                        <div>
                            <div class="brikpanel-pe-downloads" id="bpe-downloads-list"></div>
                            <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-download">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add downloadable file', 'brikpanel'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php $section_html['digital'] = ob_get_clean(); endif; ?>

                <?php if (in_array('short_desc', $visible, true)) : ob_start(); ?>
                <!-- Short Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field" data-editor-field="short-desc">
                        <label><?php esc_html_e('Short description', 'brikpanel'); ?></label>
                        <?php echo $pe_editor_toolbar; ?>
                        <div class="brikpanel-pe-editor" id="bpe-short-desc" contenteditable="true" data-placeholder="<?php esc_attr_e('Brief product summary...', 'brikpanel'); ?>"><?php echo wp_kses_post($data['short_description']); ?></div>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-short-desc-source" spellcheck="false" hidden><?php echo esc_textarea($data['short_description']); ?></textarea>
                    </div>
                </div>
                <?php $section_html['short_desc'] = ob_get_clean(); endif; ?>

                <?php if (in_array('description', $visible, true)) : ob_start(); ?>
                <!-- Description -->
                <div class="brikpanel-pe-card">
                    <div class="brikpanel-pe-field" data-editor-field="description">
                        <label><?php esc_html_e('Product description', 'brikpanel'); ?></label>
                        <?php echo $pe_editor_toolbar; ?>
                        <div class="brikpanel-pe-editor" id="bpe-description" contenteditable="true"><?php echo wp_kses_post($data['description']); ?></div>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-description-source" spellcheck="false" hidden><?php echo esc_textarea($data['description']); ?></textarea>
                    </div>
                </div>
                <?php $section_html['description'] = ob_get_clean(); endif; ?>

                <?php if (in_array('weight', $visible, true)) : ob_start(); ?>
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
                <?php $section_html['weight'] = ob_get_clean(); endif; ?>

                <?php if (in_array('dimensions', $visible, true)) : ob_start(); ?>
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
                <?php $section_html['dimensions'] = ob_get_clean(); endif; ?>

                <?php if (in_array('shipping_class', $visible, true)) : ob_start(); ?>
                <!-- Shipping class — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-shipping-class-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-shipping-class"><?php esc_html_e('Shipping class', 'brikpanel'); ?></label>
                        <select id="bpe-shipping-class" class="brikpanel-pe-select">
                            <?php foreach ($shipping_class_options as $sc_slug => $sc_label) : ?>
                                <option value="<?php echo esc_attr($sc_slug); ?>" <?php selected($data['shipping_class'], $sc_slug); ?>><?php echo esc_html($sc_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="brikpanel-pe-help-text"><?php esc_html_e('Shipping classes let certain shipping methods group similar products together. Variable products: each variation can override this from the variations table.', 'brikpanel'); ?></p>
                </div>
                <?php $section_html['shipping_class'] = ob_get_clean(); endif; ?>

                <?php if (in_array('seo', $visible, true)) : ob_start();
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
                    // The metabox picker now lives inside the "Visible editor
                    // sections" list as `mb:<id>` slugs. If the admin placed the
                    // active SEO plugin's own metabox there explicitly, honour
                    // that and skip the unified SEO card so it never doubles up.
                    $manual_seo_ids = [];
                    foreach ($visible as $vslug) {
                        if (strpos($vslug, 'mb:') === 0) {
                            $manual_seo_ids[] = substr($vslug, 3);
                        }
                    }
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
                     One SEO, SEOPress, SureRank and SmartCrawl meta keys at
                     once. Shown when none of the four inline-metabox plugins is
                     active. SureRank and SmartCrawl also land here (their
                     editors are React popups with no inline metabox) and, when
                     active, drive the read path + the live analysis panel. -->
                <?php $unified_analyzer = self::get_unified_seo_analyzer(); ?>
                <div class="brikpanel-pe-card brikpanel-pe-seo-card">
                    <label>
                        <?php esc_html_e('SEO', 'brikpanel'); ?>
                        <?php if ($unified_analyzer !== null) : ?>
                        <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($unified_analyzer['label']); ?></span>
                        <?php endif; ?>
                    </label>
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
                    <p class="brikpanel-pe-help-text">
                        <?php if ($unified_analyzer !== null && $unified_analyzer['slug'] !== 'seopress') :
                            /* translators: %s: active SEO plugin name (SureRank or SmartCrawl) */
                            printf(esc_html__('These fields are saved to %s, plus Yoast SEO, Rank Math, All in One SEO and SEOPress, so switching SEO plugins never loses your work.', 'brikpanel'), esc_html($unified_analyzer['label']));
                        else : ?>
                        <?php esc_html_e('These fields are saved to Yoast SEO, Rank Math, All in One SEO and SEOPress simultaneously, so switching SEO plugins never loses your work.', 'brikpanel'); ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($unified_analyzer !== null) :
                        $sr_analysis_html = self::render_unified_seo_analysis((int) $product_id);
                        if ($sr_analysis_html !== '') : ?>
                    <div class="brikpanel-pe-sr-analysis" id="bpe-surerank-analysis">
                        <div class="brikpanel-pe-sr-head">
                            <span class="brikpanel-pe-sr-head-title">
                                <?php esc_html_e('SEO analysis', 'brikpanel'); ?>
                                <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($unified_analyzer['label']); ?></span>
                            </span>
                            <button type="button" class="brikpanel-pe-btn secondary brikpanel-pe-sr-rerun" id="bpe-surerank-rerun"><?php esc_html_e('Re-analyze', 'brikpanel'); ?></button>
                        </div>
                        <div class="brikpanel-pe-sr-body" id="bpe-surerank-body">
                            <?php echo $sr_analysis_html; // builder escapes every field ?>
                        </div>
                    </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                    <?php endif; ?>
                <?php $section_html['seo'] = ob_get_clean(); endif; ?>

                <?php // Product attributes (specs) are no longer a standalone
                      // card — they live as the third box inside the Variations &
                      // attributes card above (Step 1 → "Attributes"), emitted at
                      // the 'variations' (or, if that is hidden, 'attributes')
                      // slot. See $bpe_combined routing earlier in this template. ?>

                <?php
                // -------------------------------------------------------------
                // Third-party plugin metaboxes.
                // The picker stores them as `mb:<id>` slugs interleaved with
                // native sections, so each renders inline at the exact position
                // the admin placed it. ACF-auto + WPML boxes are folded in
                // automatically and rendered as a trailing group. Everything is
                // produced up-front in ONE screen-spoof pass for speed; the loop
                // below just echoes the pre-built card at each slug's slot.
                // Runs for brand-new products (product_id = 0) too, so users can
                // prefill marketplace / SEO fields before the first save.
                // -------------------------------------------------------------
                $picked_mb_ids = [];
                foreach ($visible as $vslug) {
                    if (strpos($vslug, 'mb:') === 0) {
                        $picked_mb_ids[] = substr($vslug, 3);
                    }
                }
                // When the active SEO plugin's metabox is already surfaced in
                // the SEO card above, drop its IDs from the inline picks so it
                // never renders twice.
                if (in_array('seo', $visible, true) && !empty($active_seo)) {
                    $picked_mb_ids = array_values(array_diff($picked_mb_ids, $active_seo['metabox_ids']));
                }
                // ACF groups only ever render while "Auto-include ACF field
                // groups" is on. The picker can persist `mb:acf-…` placements,
                // so a since-disabled toggle could otherwise still surface them
                // via the picks here — strip them to honour the master switch.
                if (get_option('brikpanel_pe_acf_auto', 'yes') !== 'yes') {
                    $picked_mb_ids = array_values(array_filter($picked_mb_ids, function ($mb_id) {
                        return strpos($mb_id, 'acf-') !== 0;
                    }));
                }

                // Auto-included boxes that are not part of the picker order:
                // ACF field groups whose Location Rules resolve to this product,
                // and WPML's native "Language" box (hooked to admin_head, so the
                // picker never lists it). These render as a trailing group —
                // UNLESS the admin has positioned them in the section picker, in
                // which case they render at their chosen slot via $picked_mb_ids
                // (and are removed from this trailing set by the array_diff).
                $auto_mb_ids = [];
                if (function_exists('brikpanel_resolve_auto_acf_metabox_ids')) {
                    $acf_auto = brikpanel_resolve_auto_acf_metabox_ids((int) $product_id, 'product');
                    // Honor an explicit hide from the section picker: an ACF group
                    // present in the saved section order but absent from the visible
                    // set was deliberately switched off, so it must not reappear in
                    // the trailing group. Groups the admin never touched (not in the
                    // saved order) keep showing automatically.
                    $order_slugs = function_exists('brikpanel_pe_get_section_order')
                        ? brikpanel_pe_get_section_order()
                        : [];
                    if (!empty($order_slugs)) {
                        $acf_auto = array_filter($acf_auto, function ($acf_id) use ($order_slugs, $visible) {
                            $slug = 'mb:' . $acf_id;
                            return !(in_array($slug, $order_slugs, true) && !in_array($slug, $visible, true));
                        });
                    }
                    $auto_mb_ids = array_merge($auto_mb_ids, array_values($acf_auto));
                }
                if (self::wpml_translation_box_available()) {
                    $auto_mb_ids[] = 'icl_div';
                }
                $auto_mb_ids = array_values(array_diff(array_unique($auto_mb_ids), $picked_mb_ids));

                $all_mb_ids = array_values(array_unique(array_merge($picked_mb_ids, $auto_mb_ids)));
                $mb_cards   = !empty($all_mb_ids)
                    ? $this->build_third_party_metabox_cards((int) $product_id, $all_mb_ids)
                    : ['html' => [], 'acf' => false];
                $mb_emitted = [];

                // Emit each captured section in the admin-configured order.
                // Two fixed anchors are still injected mid-flight:
                //   - The "Additional product data" card with $wc_extras_position
                //     'middle' renders right after pricing — matching the
                //     historical "between pricing and inventory" placement.
                //   - The developer middle-slot boxes render right after seo.
                // The Cost of goods card is now a regular section (slug `cogs`)
                // and obeys the picker's order/visibility like any other.
                // `mb:<id>` slugs echo their pre-built third-party card inline.
                foreach ($visible as $rendered_slug) {
                    if (isset($section_html[$rendered_slug]) && $section_html[$rendered_slug] !== '') {
                        echo $section_html[$rendered_slug];
                    } elseif (strpos($rendered_slug, 'mb:') === 0) {
                        $mb_id = substr($rendered_slug, 3);
                        if (!empty($mb_cards['html'][$mb_id]) && empty($mb_emitted[$mb_id])) {
                            echo $mb_cards['html'][$mb_id];
                            $mb_emitted[$mb_id] = true;
                        }
                    }
                    if ($rendered_slug === 'pricing') {
                        if ($wc_extras_position === 'middle') {
                            echo $wc_extras_card;
                        }
                    }
                    if ($rendered_slug === 'seo' && function_exists('brikpanel_render_editor_boxes')) {
                        brikpanel_render_editor_boxes('middle', (int) $product_id, $product);
                    }
                }
                // If wc_extras 'middle' was selected but pricing is hidden,
                // fall back to rendering it at the end of the ordered list so
                // it never silently vanishes.
                if ($wc_extras_position === 'middle' && !in_array('pricing', $visible, true)) {
                    echo $wc_extras_card;
                }
                // If developer middle boxes are registered but seo is hidden,
                // emit them at the end of the ordered list so the slot does
                // not silently disappear.
                if (!in_array('seo', $visible, true) && function_exists('brikpanel_render_editor_boxes')) {
                    brikpanel_render_editor_boxes('middle', (int) $product_id, $product);
                }

                // Trailing metaboxes: auto-included ACF/WPML boxes, plus a
                // safety net for any picked box whose slug somehow fell out of
                // the visible order (e.g. a stale entry) so nothing vanishes.
                foreach (array_merge($auto_mb_ids, $picked_mb_ids) as $mb_id) {
                    if (!empty($mb_cards['html'][$mb_id]) && empty($mb_emitted[$mb_id])) {
                        echo $mb_cards['html'][$mb_id];
                        $mb_emitted[$mb_id] = true;
                    }
                }
                // ACF needs its hidden form-data block once on the page whenever
                // any ACF metabox (inline or trailing) was rendered, so the save
                // endpoint can forward a verifiable acf payload.
                if (!empty($mb_cards['acf']) && function_exists('brikpanel_pe_emit_acf_form_data')) {
                    brikpanel_pe_emit_acf_form_data((int) $product_id);
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
        $default = function_exists('brikpanel_pe_section_default_visible')
            ? brikpanel_pe_section_default_visible()
            : ['images', 'pricing', 'inventory', 'category', 'tags', 'short_desc', 'description', 'digital', 'weight', 'dimensions', 'seo', 'attributes', 'variations'];

        // Helper returns the visible slugs already in the admin-configured
        // display order so the template can iterate without sorting.
        if (function_exists('brikpanel_pe_get_visible_sections_ordered')) {
            $visible = brikpanel_pe_get_visible_sections_ordered();
        } else {
            $visible = get_option('brikpanel_pe_visible_sections');
            if ($visible === false || !is_array($visible)) {
                $visible = $default;
            }
        }
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        /**
         * Filter which built-in BrikPanel editor sections are rendered.
         *
         * @param array $visible    Section slugs currently set to render, in display order.
         * @param int   $product_id Product ID being edited (0 for new).
         */
        $visible = apply_filters('brikpanel_editor_visible_sections', $visible, $product_id);
        return is_array($visible) ? array_values($visible) : $default;
    }

    /**
     * Is WPML's native product "Language" box available to surface?
     *
     * WPML registers its translation box (id `icl_div`, title "Language") on the
     * `admin_head` hook — never on `add_meta_boxes` — so BrikPanel's metabox
     * picker can't discover it and product translations are unreachable from the
     * editor. When WPML is set up and `product` is a translatable type we fold
     * the box in automatically (see render_page) and bridge its registration in
     * build_third_party_metabox_cards(), matching the always-present Language box on
     * WordPress' own product screen. Works for simple and variable products
     * alike — the box keys off the product post, not its type.
     */
    public static function wpml_translation_box_available() {
        global $sitepress;
        if (!defined('ICL_SITEPRESS_VERSION') || !is_object($sitepress)) {
            return false;
        }
        if (!method_exists($sitepress, 'post_edit_language_options')) {
            return false;
        }
        if (method_exists($sitepress, 'get_setting') && !$sitepress->get_setting('setup_complete', false)) {
            return false;
        }
        if (method_exists($sitepress, 'is_translated_post_type') && !$sitepress->is_translated_post_type('product')) {
            return false;
        }
        return true;
    }

    /**
     * Flatten a $wp_meta_boxes screen tree to a flat [id => box] map, keeping
     * only boxes whose id is in $keep_ids.
     *
     * Iterates EVERY context and priority actually present rather than the WP
     * core trio (normal/side/advanced) + standard priorities, because
     * add_meta_box() accepts arbitrary context/priority strings and plugins use
     * custom ones (e.g. ACF's "High (after title)" position registers under the
     * `acf_after_title` context). A hardcoded list silently dropped such boxes
     * from the editor. Filtering by $keep_ids means scanning extra contexts can
     * never surface anything the caller didn't ask for, so this is always safe.
     *
     * @param mixed    $screen_boxes The $wp_meta_boxes[$screen] tree (context => priority => id => box).
     * @param string[] $keep_ids     Metabox IDs to keep.
     * @return array<string,array> Map of id => box, in discovery order.
     */
    private function flatten_selected_meta_boxes($screen_boxes, array $keep_ids) {
        $flat = [];
        if (!is_array($screen_boxes) || empty($keep_ids)) {
            return $flat;
        }
        foreach ($screen_boxes as $priorities) {
            if (!is_array($priorities)) {
                continue;
            }
            foreach ($priorities as $boxes) {
                if (!is_array($boxes)) {
                    continue;
                }
                foreach ($boxes as $id => $box) {
                    if (!$box || !in_array($id, $keep_ids, true)) {
                        continue;
                    }
                    $flat[$id] = $box;
                }
            }
        }
        return $flat;
    }

    /**
     * Build standalone cards for a set of third-party product metaboxes.
     *
     * Returns ['html' => [id => card_html], 'acf' => bool]. Each metabox is
     * wrapped in its own BrikPanel card so the picker can interleave it with
     * native sections at any position — the caller echoes each card at the slot
     * the admin placed it. A single screen + globals spoof covers the whole
     * batch (each SEO/ACF plugin gates its registration on the product edit
     * screen context, hence the spoof). The `acf` flag tells the caller whether
     * to emit ACF's hidden form-data block once for the page.
     *
     * @param int      $product_id   Post ID being edited.
     * @param string[] $selected_ids Metabox IDs to render, in desired order.
     * @return array{html:array<string,string>,acf:bool}
     */
    private function build_third_party_metabox_cards($product_id, array $selected_ids) {
        $result = ['html' => [], 'acf' => false];
        $post = get_post($product_id);
        if (!$post || empty($selected_ids)) {
            return $result;
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

        // WPML registers its "Language" translation box on `admin_head`, which
        // never fires on this custom page. Call its registrar directly inside the
        // spoofed product context so the box lands in $wp_meta_boxes['product']
        // next to the add_meta_boxes plugins below and can be rendered like any
        // other selected box. Guarded so it is a no-op unless WPML is active and
        // the box was folded into $selected_ids by render_page().
        if (in_array('icl_div', $selected_ids, true) && self::wpml_translation_box_available()) {
            global $sitepress;
            try {
                $sitepress->post_edit_language_options();
            } catch (\Throwable $e) {
                // The Language box is non-essential chrome; never let a WPML
                // internal error abort the whole editor render.
            }
        }

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
        // Scan EVERY context/priority actually registered rather than a fixed
        // list: `add_meta_box()` accepts any context or priority string, and
        // plugins use custom ones (ACF's "High (after title)" position registers
        // under `acf_after_title`; others could invent their own). A hardcoded
        // ['normal','side','advanced'] list silently dropped those boxes. Since
        // we only ever keep IDs that are in $selected_ids, scanning extra
        // contexts can never pull in anything unwanted.
        $flat = $this->flatten_selected_meta_boxes(
            isset($wp_meta_boxes['product']) ? $wp_meta_boxes['product'] : [],
            $selected_ids
        );

        // Preserve the order the admin picked in settings.
        $ordered = [];
        foreach ($selected_ids as $sid) {
            if (isset($flat[$sid])) {
                $ordered[$sid] = $flat[$sid];
            }
        }

        // Build one self-contained card per metabox so the caller can drop each
        // at its own slot in the editor order. `.brikpanel-pe-metaboxes-wrap`
        // keeps the save-time JS that scoops `:input[name]` values working, so
        // every field forwards with the BrikPanel save AJAX payload — exactly as
        // it did when these boxes shared a single grouped card.
        foreach ($ordered as $id => $box) {
            $ob_base = ob_get_level();
            ob_start();
            echo '<div class="brikpanel-pe-card brikpanel-pe-metaboxes-wrap brikpanel-pe-metabox-card">';
            echo '<div class="postbox brikpanel-pe-metabox" id="' . esc_attr($id) . '">';
            echo '<div class="postbox-header"><h2 class="hndle"><span>' . esc_html($box['title']) . '</span></h2></div>';
            echo '<div class="inside brikpanel-pe-metabox-content">';
            try {
                call_user_func($box['callback'], $post, $box);
            } catch (\Throwable $e) {
                echo '<p class="brikpanel-pe-help-text">' . esc_html__('Metabox failed to load.', 'brikpanel') . '</p>';
            }
            // A 3rd-party metabox callback may leave an output buffer open (some
            // wrap fields in ob_start() and close it on a hook we never fire);
            // fold any such buffer back into ours so it can't swallow the page.
            while (ob_get_level() > $ob_base + 1) { ob_end_flush(); }
            while (ob_get_level() < $ob_base + 1) { ob_start(); }
            echo '</div></div></div>';
            $result['html'][$id] = ob_get_clean();
            // An ACF field-group box carries the `acf-` id prefix; flag it so the
            // caller emits ACF's hidden form-data block once for the page.
            if (strpos($id, 'acf-') === 0) {
                $result['acf'] = true;
            }
        }

        // Restore state so our own page doesn't leak the spoofed context.
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

        return $result;
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
            // SEOPress 9.9+ replaced its classic `seopress_cpt` metabox with a
            // React "universal metabox" that only renders inside post.php via
            // its own JS bundle and saves over REST. Inside the BrikPanel
            // editor it registers nothing but an empty mount node
            // (`seopress_metabox_opener`), so the inline-metabox path would
            // show "did not register its SEO fields" with no editable inputs.
            // In that mode route SEOPress through BrikPanel's unified SEO
            // fields instead (see get_unified_seo_analyzer()); only the legacy
            // classic metabox is still rendered inline.
            if (!self::seopress_uses_universal_metabox()) {
                $detected = [
                    'slug'        => 'seopress',
                    'label'       => __('SEOPress', 'brikpanel'),
                    'metabox_ids' => ['seopress_cpt', 'seopress_content_analysis'],
                ];
            }
        }

        return apply_filters('brikpanel_pe_active_seo_plugin', $detected);
    }

    /**
     * Active SEO plugins that expose a per-product "primary category" for the
     * product_cat taxonomy, mapped to the post meta key each one stores it in.
     *
     * Every one of these plugins injects its own primary-category selector into
     * the native WordPress Categories panel via a JS bundle that only runs on
     * post.php / post-new.php — never on the BrikPanel editor page — so the
     * control is missing here for all of them. BrikPanel surfaces ONE primary
     * category control and mirrors the choice into every active plugin's meta
     * key, mirroring the unified-SEO-fields philosophy (write everywhere so
     * switching SEO plugins never loses the data). AIOSEO is intentionally
     * excluded: its primary term is a Pro-only feature stored in a custom DB
     * table, not post meta, so it cannot be written the same safe way.
     *
     * @return array<string,array{key:string,label:string}>
     */
    public static function primary_category_seo_plugins() {
        $map = [];
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
            $map['seopress'] = ['key' => '_seopress_robots_primary_cat', 'label' => 'SEOPress'];
        }
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Metabox')) {
            $map['yoast'] = ['key' => '_yoast_wpseo_primary_product_cat', 'label' => 'Yoast SEO'];
        }
        if (class_exists('RankMath') || function_exists('rank_math') || defined('RANK_MATH_VERSION')) {
            $map['rank_math'] = ['key' => 'rank_math_primary_product_cat', 'label' => 'Rank Math'];
        }
        return apply_filters('brikpanel_pe_primary_category_plugins', $map);
    }

    /**
     * Whether SEOPress is active in its modern "universal metabox" mode — the
     * React SEO panel introduced in 9.9 that mounts only on the native
     * post.php editor and saves over REST. In that mode SEOPress does NOT
     * register the legacy inline `seopress_cpt` metabox (it registers an empty
     * `seopress_metabox_opener` mount node instead), so BrikPanel cannot render
     * it inside its own SEO card and routes SEOPress through the unified SEO
     * fields instead. Returns false for pre-9.9 SEOPress (classic metabox
     * renders inline fine) and when SEOPress isn't active at all.
     *
     * @return bool
     */
    public static function seopress_uses_universal_metabox() {
        if (!defined('SEOPRESS_VERSION') && !function_exists('seopress_get_service')) {
            return false;
        }
        // The universal metabox arrived in 9.9 with this class; before that the
        // classic metabox is the only one, and it renders inline correctly.
        if (!class_exists('SEOPress\\Actions\\Admin\\ModuleMetabox')) {
            return false;
        }
        // SEOPress only falls back to the classic `seopress_cpt` metabox when
        // EnqueueModuleMetabox::canEnqueue() is false; otherwise it ships the
        // React mount node we can't render inline. Mirror that exact switch.
        try {
            if (function_exists('seopress_get_service')) {
                $svc = seopress_get_service('EnqueueModuleMetabox');
                if (is_object($svc) && method_exists($svc, 'canEnqueue')) {
                    return (bool) $svc->canEnqueue();
                }
            }
        } catch (\Throwable $e) {
            // Service unavailable — fall through to the version default below.
        }
        // 9.9+ is present but its state can't be probed: the universal metabox
        // is the default, and the unified-fields path reads/writes the same
        // `_seopress_*` meta keys, so assuming universal is always data-safe.
        return true;
    }

    /**
     * Detect a SEO plugin whose editor UI cannot be rendered inline (it lives
     * in a React popup / JS-mounted container), so BrikPanel routes it through
     * its own unified SEO fields. Currently SureRank, SEOPress (9.9+ universal
     * metabox mode) and SmartCrawl (WPMU DEV SEO). SureRank and SmartCrawl
     * also get a native-equivalent analysis panel; SEOPress's content analysis
     * is a separate React/REST surface, so it shows the fields only.
     *
     * Only meaningful when get_active_seo_plugin() returned null — if one of
     * the inline-metabox plugins is active it owns the SEO card and these are
     * not consulted. When several are somehow active, precedence is
     * SureRank → SEOPress → SmartCrawl (this must match the read-path order in
     * get_product_data() so the badge, the source-of-truth meta and the help
     * text all agree).
     *
     * @return array{slug:string,label:string}|null
     */
    public static function get_unified_seo_analyzer() {
        $detected = null;
        if (defined('SURERANK_VERSION')) {
            $detected = ['slug' => 'surerank', 'label' => __('SureRank', 'brikpanel')];
        } elseif (self::seopress_uses_universal_metabox()) {
            $detected = ['slug' => 'seopress', 'label' => __('SEOPress', 'brikpanel')];
        } elseif (defined('SMARTCRAWL_VERSION')) {
            $detected = ['slug' => 'smartcrawl', 'label' => __('SmartCrawl', 'brikpanel')];
        }
        return apply_filters('brikpanel_pe_unified_seo_analyzer', $detected);
    }

    /**
     * Render whichever unified-analyzer panel is active (SureRank or
     * SmartCrawl) for the given product, in BrikPanel's design. Returns '' when
     * none is active or there's nothing to show. Single entry point shared by
     * the initial SEO-card render and the AJAX re-analyze handler.
     *
     * @param int   $pid
     * @param array $overrides Live field values (see the per-analyzer methods).
     * @return string
     */
    public static function render_unified_seo_analysis($pid, array $overrides = []) {
        $analyzer = self::get_unified_seo_analyzer();
        if ($analyzer === null) {
            return '';
        }
        if ($analyzer['slug'] === 'smartcrawl') {
            return self::render_smartcrawl_analysis($pid, $overrides);
        }
        if ($analyzer['slug'] === 'surerank') {
            // SureRank reads live values through its own filters, wired up in
            // ajax_seo_analyze(); the initial render needs no overrides.
            return self::render_surerank_analysis($pid);
        }
        // SEOPress (universal metabox): routed through the unified fields, but
        // its content analysis is a separate React/REST surface we can't render
        // inline — show the fields only, no analysis panel.
        return '';
    }

    /**
     * Run SureRank's SEO analysis ("Analyze" panel) for a product and return
     * its checks grouped for display — Page checks + Keyword checks, each
     * bucketed by status. Returns null when SureRank isn't active, exposes no
     * analyzer, or produces nothing displayable.
     *
     * SureRank's editor surfaces these as an "Analyze" tab; because BrikPanel
     * routes SureRank through its own unified SEO fields (SureRank's UI is a
     * React popup that can't render inline), the analysis would otherwise be
     * missing. We call SureRank's own analyzer so the results read identically
     * to the plugin's, then render them in BrikPanel's design. The analysis
     * reflects the *saved* product, so callers re-run after a save.
     *
     * @param int $pid
     * @return array{page:array,keyword:array,summary:array}|null
     */
    public static function get_surerank_analysis($pid) {
        if (!defined('SURERANK_VERSION')) {
            return null;
        }
        $post = get_post($pid);
        if (!$post) {
            return null;
        }

        // SureRank exposes its analyzer through this filter; it returns an
        // array of checks keyed by id (or a WP_Error). Guard with try/catch so
        // a change there can never fatal the editor.
        try {
            $checks = apply_filters('surerank_run_post_seo_checks', $pid, $post);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($checks) || empty($checks)) {
            return null;
        }

        // Consolidate the four keyword checks into one "no focus keyword"
        // suggestion exactly as SureRank's REST layer does, so our panel reads
        // the same as the plugin's own Analyze tab.
        $kw_keys = ['keyword_in_title', 'keyword_in_description', 'keyword_in_url', 'keyword_in_content'];
        $all_kw_present = true;
        foreach ($kw_keys as $k) {
            if (!isset($checks[$k])) { $all_kw_present = false; break; }
        }
        if ($all_kw_present) {
            $all_suggestion = true;
            foreach ($kw_keys as $k) {
                if (($checks[$k]['status'] ?? '') !== 'suggestion') { $all_suggestion = false; break; }
            }
            if ($all_suggestion) {
                foreach ($kw_keys as $k) { unset($checks[$k]); }
                $checks['keyword_checks'] = [
                    'status'  => 'suggestion',
                    'message' => __('No focus keyword set. Add one to analyze the title, description, URL and content.', 'brikpanel'),
                    'type'    => 'keyword',
                ];
            }
        }

        $valid_status = ['error', 'warning', 'suggestion', 'success'];
        $groups  = ['page' => [], 'keyword' => []];
        $summary = ['error' => 0, 'warning' => 0, 'suggestion' => 0, 'success' => 0];

        foreach ($checks as $id => $c) {
            if (!is_array($c)) {
                continue;
            }
            $status = isset($c['status']) ? (string) $c['status'] : '';
            $type   = isset($c['type'])   ? (string) $c['type']   : '';
            // Skip data-only entries (e.g. all_links, an empty image_alt_text)
            // that carry no status/type — SureRank's UI doesn't list them.
            if (!in_array($status, $valid_status, true) || !isset($groups[$type])) {
                continue;
            }
            $message = isset($c['message']) ? trim((string) $c['message']) : '';
            if ($message === '') {
                continue;
            }
            $groups[$type][] = [
                'id'      => (string) $id,
                'status'  => $status,
                'message' => $message,
            ];
            $summary[$status]++;
        }

        if (empty($groups['page']) && empty($groups['keyword'])) {
            return null;
        }

        // Order each group: errors, then warnings, suggestions, passed.
        $order  = array_flip($valid_status);
        $sorter = static function ($a, $b) use ($order) {
            return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
        };
        usort($groups['page'], $sorter);
        usort($groups['keyword'], $sorter);

        return ['page' => $groups['page'], 'keyword' => $groups['keyword'], 'summary' => $summary];
    }

    /**
     * Build the HTML for the SureRank analysis panel shown inside the SEO
     * card. Returns '' when there's nothing to show. Rendered server-side so
     * markup + i18n live in one place; the "Re-analyze" button re-fetches this
     * same HTML over AJAX (see ajax_seo_analyze()).
     *
     * @param int $pid
     * @return string
     */
    public static function render_surerank_analysis($pid) {
        $analysis = self::get_surerank_analysis($pid);
        if ($analysis === null) {
            return '';
        }

        $status_meta = [
            'error'      => ['label' => __('Critical', 'brikpanel'),   'cls' => 'error'],
            'warning'    => ['label' => __('Warning', 'brikpanel'),    'cls' => 'warning'],
            'suggestion' => ['label' => __('Suggestion', 'brikpanel'), 'cls' => 'suggestion'],
            'success'    => ['label' => __('Passed', 'brikpanel'),     'cls' => 'success'],
        ];

        $render_group = static function ($title, $items) use ($status_meta) {
            if (empty($items)) {
                return '';
            }
            $html  = '<div class="brikpanel-pe-sr-group">';
            $html .= '<div class="brikpanel-pe-sr-group-title">' . esc_html($title) . '</div>';
            foreach ($items as $item) {
                $meta = $status_meta[$item['status']];
                $html .= '<div class="brikpanel-pe-sr-check brikpanel-pe-sr-check--' . esc_attr($meta['cls']) . '">';
                $html .= '<span class="brikpanel-pe-sr-dot" aria-hidden="true"></span>';
                $html .= '<span class="brikpanel-pe-sr-msg">' . esc_html($item['message']) . '</span>';
                $html .= '<span class="brikpanel-pe-sr-tag">' . esc_html($meta['label']) . '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
            return $html;
        };

        $s = $analysis['summary'];
        ob_start();
        ?>
        <div class="brikpanel-pe-sr-summary">
            <?php if ($s['error'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill error"><?php
                    /* translators: %d: number of critical SEO issues */
                    echo esc_html(sprintf(_n('%d critical', '%d critical', $s['error'], 'brikpanel'), $s['error'])); ?></span>
            <?php endif; ?>
            <?php if ($s['warning'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill warning"><?php
                    /* translators: %d: number of SEO warnings */
                    echo esc_html(sprintf(_n('%d warning', '%d warnings', $s['warning'], 'brikpanel'), $s['warning'])); ?></span>
            <?php endif; ?>
            <?php if ($s['suggestion'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill suggestion"><?php
                    /* translators: %d: number of SEO suggestions */
                    echo esc_html(sprintf(_n('%d suggestion', '%d suggestions', $s['suggestion'], 'brikpanel'), $s['suggestion'])); ?></span>
            <?php endif; ?>
            <span class="brikpanel-pe-sr-pill success"><?php
                /* translators: %d: number of passed SEO checks */
                echo esc_html(sprintf(_n('%d passed', '%d passed', $s['success'], 'brikpanel'), $s['success'])); ?></span>
        </div>
        <?php
        echo $render_group(__('Page checks', 'brikpanel'), $analysis['page']);
        echo $render_group(__('Keyword checks', 'brikpanel'), $analysis['keyword']);
        return ob_get_clean();
    }

    /**
     * Human-readable label + grouping for each SmartCrawl (WPMU DEV SEO) post
     * check id. Keys are SmartCrawl's internal check ids (see
     * SmartCrawl\Checks::$checks); `kw` flags a check that only makes sense
     * once a focus keyword is set, so those collapse into a single
     * "add a focus keyword" suggestion when none is present — mirroring how
     * SmartCrawl's own analyzer reads. Unknown/extra check ids fall back to a
     * generic label so a future SmartCrawl release never renders blank rows.
     *
     * @return array<string,array{group:string,label:string,kw:bool}>
     */
    private static function smartcrawl_check_map() {
        return [
            // Title / meta-description / slug — derived from the post + meta.
            'title_length'             => ['group' => 'page', 'label' => __('SEO title length is within the recommended range', 'brikpanel'), 'kw' => false],
            'metadesc_length'          => ['group' => 'page', 'label' => __('Meta description length is within the recommended range', 'brikpanel'), 'kw' => false],
            'metadesc_handcraft'       => ['group' => 'page', 'label' => __('Meta description is hand-written, not auto-generated', 'brikpanel'), 'kw' => false],
            'slug_underscores'         => ['group' => 'page', 'label' => __('URL slug uses hyphens instead of underscores', 'brikpanel'), 'kw' => false],
            'title_keywords'           => ['group' => 'page', 'label' => __('Focus keyword appears in the SEO title', 'brikpanel'), 'kw' => true],
            'metadesc_keywords'        => ['group' => 'page', 'label' => __('Focus keyword appears in the meta description', 'brikpanel'), 'kw' => true],
            'slug_keywords'            => ['group' => 'page', 'label' => __('Focus keyword appears in the URL slug', 'brikpanel'), 'kw' => true],
            'focus_stopwords'          => ['group' => 'page', 'label' => __('Focus keyword does not consist only of stop words', 'brikpanel'), 'kw' => true],
            'keywords_used'            => ['group' => 'page', 'label' => __('Focus keyword has not been used on another product', 'brikpanel'), 'kw' => true],
            'title_secondary_keywords' => ['group' => 'page', 'label' => __('Secondary keyword appears in the SEO title', 'brikpanel'), 'kw' => true],
            // Rendered-content checks.
            'content_length'           => ['group' => 'content', 'label' => __('Content is long enough', 'brikpanel'), 'kw' => false],
            'links_count'              => ['group' => 'content', 'label' => __('Content contains at least one link', 'brikpanel'), 'kw' => false],
            'nofollow_links'           => ['group' => 'content', 'label' => __('Outbound links are followed where appropriate', 'brikpanel'), 'kw' => false],
            'keyword_density'          => ['group' => 'content', 'label' => __('Focus keyword density is within the recommended range', 'brikpanel'), 'kw' => true],
            'para_keywords'            => ['group' => 'content', 'label' => __('Focus keyword appears in the first paragraph', 'brikpanel'), 'kw' => true],
            'subheadings_keywords'     => ['group' => 'content', 'label' => __('Focus keyword appears in a subheading', 'brikpanel'), 'kw' => true],
            'imgalts_keywords'         => ['group' => 'content', 'label' => __('Focus keyword appears in an image alt text', 'brikpanel'), 'kw' => true],
            'bolded_keyword'           => ['group' => 'content', 'label' => __('Focus keyword is emphasized somewhere in the content', 'brikpanel'), 'kw' => true],
        ];
    }

    /**
     * Run SmartCrawl's (WPMU DEV SEO) own SEO analyzer for a product and
     * return its checks grouped for display plus the percentage score
     * SmartCrawl itself shows. Returns null when SmartCrawl isn't active or
     * the analyzer is unavailable.
     *
     * SmartCrawl's editor metabox is a React app mounted into an empty
     * container (#wds-metabox-container) and its scripts only load on the
     * native post-edit screen, so it can't be rendered inline inside the
     * BrikPanel editor. Instead BrikPanel surfaces SmartCrawl through its own
     * unified SEO fields and calls SmartCrawl's analyzer
     * (\SmartCrawl\Checks::apply) directly so the merchant still gets the same
     * dynamic score and check list, rendered in BrikPanel's design.
     *
     * For live analysis (the merchant is typing, nothing saved yet) the caller
     * passes the current field values + content as $overrides; we feed the
     * content through SmartCrawl's `wds-checks-subject-endpoint` filter (which
     * also avoids an HTTP self-request to render the post) and the meta fields
     * through a scoped `get_post_metadata` read-filter. Everything is torn
     * down before returning so nothing leaks to other requests.
     *
     * @param int   $pid
     * @param array $overrides Optional: focus_keyword, title, description,
     *                         canonical, content (rendered HTML).
     * @return array{score:int,page:array,content:array,summary:array}|null
     */
    public static function get_smartcrawl_analysis($pid, array $overrides = []) {
        if (!defined('SMARTCRAWL_VERSION') || !class_exists('\\SmartCrawl\\Checks')) {
            return null;
        }
        $post = get_post($pid);
        if (!$post) {
            return null;
        }

        // Focus keyword: live value if supplied, otherwise the saved primary
        // keyword (SmartCrawl stores focus keywords comma-separated; the first
        // is primary).
        if (array_key_exists('focus_keyword', $overrides)) {
            $keyword = trim((string) $overrides['focus_keyword']);
        } else {
            $saved_kw = (string) get_post_meta($pid, '_wds_focus-keywords', true);
            $parts    = array_filter(array_map('trim', explode(',', $saved_kw)));
            $keyword  = $parts ? reset($parts) : '';
        }
        $has_kw = ($keyword !== '');

        // Content for the rendered-content checks. Always inject it through
        // SmartCrawl's endpoint-subject filter so the analyzer never fires an
        // HTTP request back to the site (slow, and unavailable for unsaved
        // edits). When the caller did not supply live content, fall back to
        // the saved product description run through the_content so the initial
        // page-load analysis still reflects what will be published.
        if (array_key_exists('content', $overrides)) {
            $content = (string) $overrides['content'];
        } else {
            $content = apply_filters('the_content', (string) $post->post_content);
        }

        // Scoped meta read-filter for the live title / description / canonical
        // overrides. Returns a single-element array because get_post_metadata
        // expects the pre-filtered meta shape ($single is applied downstream).
        $meta_overrides = [];
        if (array_key_exists('title', $overrides) && $overrides['title'] !== '') {
            $meta_overrides['_wds_title'] = (string) $overrides['title'];
        }
        if (array_key_exists('description', $overrides) && $overrides['description'] !== '') {
            $meta_overrides['_wds_metadesc'] = (string) $overrides['description'];
        }
        if (array_key_exists('canonical', $overrides) && $overrides['canonical'] !== '') {
            $meta_overrides['_wds_canonical'] = (string) $overrides['canonical'];
        }
        if ($has_kw) {
            // Keep the entity's primary-keyword lookups consistent with the
            // keyword we hand to apply().
            $meta_overrides['_wds_focus-keywords'] = $keyword;
        }

        $content_filter = static function () use ($content) {
            return $content;
        };
        add_filter('wds-checks-subject-endpoint', $content_filter, 99);

        $meta_filter = null;
        if (!empty($meta_overrides)) {
            $meta_filter = static function ($value, $object_id, $meta_key) use ($pid, $meta_overrides) {
                if ((int) $object_id === (int) $pid && isset($meta_overrides[$meta_key])) {
                    return [$meta_overrides[$meta_key]];
                }
                return $value;
            };
            add_filter('get_post_metadata', $meta_filter, 99, 3);
        }

        // SmartCrawl caches resolved post entities; clear so our overrides are
        // picked up rather than a stale entity from earlier this request.
        if (class_exists('\\SmartCrawl\\Cache\\Post_Cache') && method_exists('\\SmartCrawl\\Cache\\Post_Cache', 'get')) {
            try {
                $cache = \SmartCrawl\Cache\Post_Cache::get();
                if (method_exists($cache, 'purge')) {
                    $cache->purge();
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $score   = 0;
        $applied = [];
        try {
            $checks  = \SmartCrawl\Checks::apply($pid, false, $keyword, true);
            $score   = (int) $checks->get_percentage();
            $applied = (array) $checks->get_applied_checks();
        } catch (\Throwable $e) {
            $applied = [];
        }

        remove_filter('wds-checks-subject-endpoint', $content_filter, 99);
        if ($meta_filter) {
            remove_filter('get_post_metadata', $meta_filter, 99);
        }

        if (empty($applied)) {
            return null;
        }

        $map     = self::smartcrawl_check_map();
        $groups  = ['page' => [], 'content' => []];
        $summary = ['warning' => 0, 'suggestion' => 0, 'success' => 0];

        foreach ($applied as $id => $info) {
            // 'focus' is SmartCrawl's "is a focus keyword set" check — it is
            // redundant with BrikPanel's own focus-keyword field and the
            // "no focus keyword" suggestion below, so never list it.
            if ($id === 'focus') {
                continue;
            }
            if (!isset($map[$id])) {
                // Unknown check id from a newer SmartCrawl build: show it with
                // a generic label so the panel never silently drops results.
                $map[$id] = ['group' => 'content', 'label' => ucwords(str_replace('_', ' ', (string) $id)), 'kw' => false];
            }
            $def = $map[$id];
            if (!empty($info['ignored'])) {
                continue; // Respect checks the merchant ignored in SmartCrawl.
            }
            // Collapse every keyword-dependent check into one suggestion when
            // no focus keyword is set — they would all "fail" for the same
            // reason and just add noise otherwise.
            if (!$has_kw && $def['kw']) {
                continue;
            }
            $passed = !empty($info['status']);
            $status = $passed ? 'success' : 'warning';
            $groups[$def['group']][] = [
                'status'  => $status,
                'message' => $def['label'],
            ];
            $summary[$status]++;
        }

        if (!$has_kw) {
            $groups['page'][] = [
                'status'  => 'suggestion',
                'message' => __('No focus keyword set. Add one to check the title, description, URL and content against it.', 'brikpanel'),
            ];
            $summary['suggestion']++;
        }

        if (empty($groups['page']) && empty($groups['content'])) {
            return null;
        }

        // Fail/suggest first within each group, passed last.
        $order  = ['warning' => 0, 'suggestion' => 1, 'success' => 2];
        $sorter = static function ($a, $b) use ($order) {
            return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
        };
        usort($groups['page'], $sorter);
        usort($groups['content'], $sorter);

        return [
            'score'   => max(0, min(100, $score)),
            'page'    => $groups['page'],
            'content' => $groups['content'],
            'summary' => $summary,
        ];
    }

    /**
     * Build the HTML for the SmartCrawl analysis panel shown inside the SEO
     * card: a circular score gauge plus the grouped check list, all in
     * BrikPanel's design. Returns '' when there's nothing to show. The
     * "Re-analyze" button and post-save refresh re-fetch this same markup over
     * AJAX (see ajax_seo_analyze()).
     *
     * @param int   $pid
     * @param array $overrides See get_smartcrawl_analysis().
     * @return string
     */
    public static function render_smartcrawl_analysis($pid, array $overrides = []) {
        $analysis = self::get_smartcrawl_analysis($pid, $overrides);
        if ($analysis === null) {
            return '';
        }

        $status_meta = [
            'warning'    => ['label' => __('Needs work', 'brikpanel'),  'cls' => 'warning'],
            'suggestion' => ['label' => __('Suggestion', 'brikpanel'),  'cls' => 'suggestion'],
            'success'    => ['label' => __('Passed', 'brikpanel'),      'cls' => 'success'],
        ];

        $render_group = static function ($title, $items) use ($status_meta) {
            if (empty($items)) {
                return '';
            }
            $html  = '<div class="brikpanel-pe-sr-group">';
            $html .= '<div class="brikpanel-pe-sr-group-title">' . esc_html($title) . '</div>';
            foreach ($items as $item) {
                $meta = $status_meta[$item['status']] ?? $status_meta['suggestion'];
                $html .= '<div class="brikpanel-pe-sr-check brikpanel-pe-sr-check--' . esc_attr($meta['cls']) . '">';
                $html .= '<span class="brikpanel-pe-sr-dot" aria-hidden="true"></span>';
                $html .= '<span class="brikpanel-pe-sr-msg">' . esc_html($item['message']) . '</span>';
                $html .= '<span class="brikpanel-pe-sr-tag">' . esc_html($meta['label']) . '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';
            return $html;
        };

        $score = (int) $analysis['score'];
        // Gauge colour band — red < 50, amber < 80, green otherwise. Uses the
        // monochrome-plus-status palette (no bright accents).
        if ($score < 50) {
            $score_cls = 'low';
        } elseif ($score < 80) {
            $score_cls = 'mid';
        } else {
            $score_cls = 'high';
        }
        $s = $analysis['summary'];

        ob_start();
        ?>
        <div class="brikpanel-pe-sr-score brikpanel-pe-sr-score--<?php echo esc_attr($score_cls); ?>" style="--bpe-score: <?php echo esc_attr($score); ?>;">
            <div class="brikpanel-pe-sr-score-ring" role="img" aria-label="<?php
                /* translators: %d: SEO score out of 100 */
                echo esc_attr(sprintf(__('SEO score: %d out of 100', 'brikpanel'), $score)); ?>">
                <span class="brikpanel-pe-sr-score-num"><?php echo esc_html($score); ?></span>
            </div>
            <div class="brikpanel-pe-sr-score-text">
                <strong><?php esc_html_e('SEO score', 'brikpanel'); ?></strong>
                <span><?php
                    /* translators: %d: number of passed SEO checks */
                    echo esc_html(sprintf(_n('%d check passed', '%d checks passed', $s['success'], 'brikpanel'), $s['success'])); ?></span>
            </div>
        </div>
        <div class="brikpanel-pe-sr-summary">
            <?php if ($s['warning'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill warning"><?php
                    /* translators: %d: number of SEO checks that need work */
                    echo esc_html(sprintf(_n('%d to improve', '%d to improve', $s['warning'], 'brikpanel'), $s['warning'])); ?></span>
            <?php endif; ?>
            <?php if ($s['suggestion'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill suggestion"><?php
                    /* translators: %d: number of SEO suggestions */
                    echo esc_html(sprintf(_n('%d suggestion', '%d suggestions', $s['suggestion'], 'brikpanel'), $s['suggestion'])); ?></span>
            <?php endif; ?>
            <span class="brikpanel-pe-sr-pill success"><?php
                /* translators: %d: number of passed SEO checks */
                echo esc_html(sprintf(_n('%d passed', '%d passed', $s['success'], 'brikpanel'), $s['success'])); ?></span>
        </div>
        <?php
        echo $render_group(__('Page checks', 'brikpanel'), $analysis['page']);
        echo $render_group(__('Content checks', 'brikpanel'), $analysis['content']);
        return ob_get_clean();
    }

    /**
     * Render the active SEO plugin's native metabox(es) inline inside the
     * BrikPanel SEO card.
     *
     * Same screen + globals spoof that build_third_party_metabox_cards() uses,
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
        // Scan every registered context/priority (not a fixed list) so a SEO
        // plugin registering under a custom context is still picked up; the
        // metabox_ids filter keeps the result tight regardless.
        $render_queue = $this->flatten_selected_meta_boxes(
            isset($wp_meta_boxes['product']) ? $wp_meta_boxes['product'] : [],
            $active_seo['metabox_ids']
        );

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
            $ob_base = ob_get_level();
            ob_start();
            echo '<div class="brikpanel-pe-seo-plugin-box postbox" id="' . esc_attr($id) . '">';
            echo '<div class="inside">';
            try {
                call_user_func($box['callback'], $post, $box);
            } catch (\Throwable $e) {
                echo '<p class="brikpanel-pe-help-text">' . esc_html__('SEO metabox failed to load.', 'brikpanel') . '</p>';
            }
            // Fold any output buffer the metabox callback left open back into
            // ours so an unbalanced ob_start() can't corrupt the page (see the
            // metabox card loop in build_third_party_metabox_cards()).
            while (ob_get_level() > $ob_base + 1) { ob_end_flush(); }
            while (ob_get_level() < $ob_base + 1) { ob_start(); }
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

        // Yoast WooCommerce SEO compatibility shim.
        //
        // Yoast SEO: WooCommerce ships an analysis collector
        // (yoastseo-woo-identifiers) that runs on every Yoast analysis pass
        // and unconditionally calls:
        //     document.querySelector("select#product-type").value
        //     document.querySelector("input#_sku")           (null-guarded)
        //     #yoast_identifier_*                             (null-guarded)
        // Those nodes only exist on WooCommerce's *native* product editor.
        // Inside the BrikPanel editor `select#product-type` is absent, so the
        // collector throws "Cannot read properties of null (reading 'value')"
        // on every keystroke, which aborts Yoast's analysis pipeline (the
        // visible "Yoast SEO WooCommerce errors out" symptom, and the cascade
        // behind the SyntaxError / `$(...).pointer` reports on some setups).
        //
        // Emit the exact hidden nodes the collector reads, mirrored from the
        // real product, so Yoast WooCommerce analysis runs cleanly for BOTH
        // simple and variable products instead of crashing. Done before the
        // empty-boxes early return so the message-only path is covered too.
        if ($active_seo['slug'] === 'yoast' && defined('WPSEO_WOO_VERSION')) {
            $wc_compat_product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
            if ($wc_compat_product) {
                $compat_type = (string) $wc_compat_product->get_type();
                $compat_sku  = (string) $wc_compat_product->get_sku();
                $type_keys   = function_exists('wc_get_product_types')
                    ? array_keys((array) wc_get_product_types())
                    : ['simple', 'variable', 'grouped', 'external'];
                if (!in_array($compat_type, $type_keys, true) && $compat_type !== '') {
                    $type_keys[] = $compat_type;
                }
                echo '<div class="brikpanel-pe-yoast-woo-compat" aria-hidden="true" style="display:none">';
                echo '<select id="product-type">';
                foreach ($type_keys as $tk) {
                    echo '<option value="' . esc_attr($tk) . '"' . selected($compat_type, $tk, false) . '>'
                        . esc_html($tk) . '</option>';
                }
                echo '</select>';
                echo '<input type="hidden" id="_sku" value="' . esc_attr($compat_sku) . '">';
                echo '</div>';
                // Keep the shim's product type in sync if the admin flips the
                // BrikPanel type selector, so Yoast re-analyses against the
                // correct (simple vs variable) ruleset without a reload.
                wp_print_inline_script_tag(
                    '(function(){var s=document.getElementById("bpe-product-type"),'
                    . 't=document.getElementById("product-type");if(!s||!t)return;'
                    . 's.addEventListener("change",function(){t.value=s.value;'
                    . 'try{if(window.YoastSEO&&YoastSEO.app&&YoastSEO.app.refresh)YoastSEO.app.refresh();}catch(e){}});})();'
                );
            }
        }

        // Live analysis bridge — native editor field scaffold.
        //
        // Every supported SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress)
        // collects the content it analyses from the *native* post editor DOM:
        //     #title    — post title          (Yoast/RM/AIOSEO/SEOPress)
        //     #content  — post body / TinyMCE  (word count, links, images, …)
        //     #excerpt  — WooCommerce short description (Yoast Woo product desc)
        //     #editable-post-name-full / #post_name — slug (keyphrase-in-slug)
        // None of those exist in the BrikPanel editor, so the analysers run
        // against empty input — that is why scores stay stuck on "write a
        // short description / text contains 0 words" even after the user has
        // filled the BrikPanel fields. Emit the scaffold here, seeded from the
        // real product, and let the JS bridge mirror the BrikPanel fields into
        // it live (see syncSeoNativeFields() in brikpanel-product-editor.js).
        // The nodes carry no `name` attribute, so they never reach the save
        // payload and cannot collide with BrikPanel's own form handling.
        $bridge_slug = $post->post_name !== '' ? $post->post_name : sanitize_title($post->post_title);
        echo '<div class="brikpanel-pe-seo-native-bridge" aria-hidden="true" '
            . 'style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">';
        echo '<input type="text" id="title" tabindex="-1" value="' . esc_attr($post->post_title) . '">';
        echo '<textarea id="content" tabindex="-1">' . esc_textarea($post->post_content) . '</textarea>';
        echo '<textarea id="excerpt" tabindex="-1">' . esc_textarea($post->post_excerpt) . '</textarea>';
        echo '<input type="text" id="post_name" tabindex="-1" value="' . esc_attr($bridge_slug) . '">';
        echo '<span id="editable-post-name-full">' . esc_html($bridge_slug) . '</span>';
        echo '</div>';

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
     * Replace every <script>…</script> block with a unique HTML-comment
     * placeholder before the markup is handed to DOMDocument, and restore them
     * verbatim afterwards (see brikpanel_pe_restore_scripts()).
     *
     * libxml's HTML parser mishandles inline scripts inside a product-data
     * panel: it reads <script type="text/html"> templates as raw text, gets
     * confused by the `</…>` and moustache markup they contain, and silently
     * eats the script *and every sibling that follows it* — including the
     * executable `jQuery('#…').someWidget(config)` call that wires the panel
     * up. The fields then render but every control is dead ("click but nothing
     * happens"). Shielding the scripts lets DOMDocument parse only inert markup,
     * so ids/visibility can be processed without destroying the JavaScript.
     *
     * @param string $html
     * @param array  $store Receives [placeholder => original script HTML].
     * @return string
     */
    private static function shield_scripts($html, &$store) {
        $store = [];
        $out = preg_replace_callback('#<script\b[^>]*>.*?</script\s*>#is', function ($m) use (&$store) {
            $token = '<!--brikpanel-script-' . count($store) . '-->';
            $store[$token] = $m[0];
            return $token;
        }, $html);
        return $out === null ? $html : $out;
    }

    /** Restore scripts shielded by brikpanel_pe_shield_scripts(). */
    private static function restore_scripts($html, $store) {
        return empty($store) ? $html : strtr($html, $store);
    }

    /**
     * Re-instantiate third-party product-data tab controllers that only
     * register their `woocommerce_process_product_meta` save handler on a
     * NON-ajax admin request.
     *
     * Pektsekye's "Product Options" / "Dependent Custom Options" family wires
     * the option <fields> in the browser and persists them from an admin
     * controller they boot only when `!DOING_AJAX` (their is_request() treats
     * every ajax call as a front-end request). BrikPanel saves over
     * admin-ajax, so that controller never loads and the options the merchant
     * just edited are silently discarded. We detect the controller by this
     * author's fixed file + class convention and instantiate it so its save
     * hook is attached before we fire the meta hooks. Idempotent per request.
     */
    private static function boot_thirdparty_ajax_save_handlers() {
        static $done = false;
        if ($done) return;
        $done = true;

        // Collect active plugin entry files (single-site + network) so we can
        // find this author's controller without hard-coding a plugin slug.
        $plugin_files = [];
        if (function_exists('wp_get_active_and_valid_plugins')) {
            $plugin_files = array_merge($plugin_files, wp_get_active_and_valid_plugins());
        }
        if (is_multisite() && function_exists('wp_get_active_network_plugins')) {
            $plugin_files = array_merge($plugin_files, wp_get_active_network_plugins());
        }

        $rel = 'Block/Adminhtml/Product/Edit/Tab/CustomOptions.php';
        foreach (array_unique($plugin_files) as $plugin_file) {
            $candidate = dirname($plugin_file) . '/' . $rel;
            if (is_readable($candidate)) {
                include_once $candidate;
            }
        }

        // Instantiate any controller that exposes a save_options() handler.
        // During an ajax save it was never booted, so a single fresh instance
        // simply (re)attaches the hook — no risk of double-saving.
        $suffix = '_Block_Adminhtml_Product_Edit_Tab_CustomOptions';
        foreach (get_declared_classes() as $cls) {
            if (substr($cls, -strlen($suffix)) === $suffix
                && method_exists($cls, 'save_options')) {
                try { new $cls(); } catch (\Throwable $e) { /* ignore */ }
            }
        }
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
                // Buffer-safe: a 3rd-party plugin hooked here may assume a
                // fully-saved product (Germanized fatals on get_manufacturer on
                // an empty shop) or leave an output buffer open (Sales Countdown
                // Timer) — capture_isolated_hook() swallows both safely.
                $section .= self::capture_isolated_hook($hook);
            }
            if ($section !== '') {
                $out['core:' . sanitize_key($label)] = $label;
            }
        }

        // Custom panels registered on woocommerce_product_data_panels.
        // Apply the tabs filter (inside collect_custom_tab_meta) FIRST so any
        // plugin that lazily registers its panel action from within the tabs
        // filter is wired up before the has_action() check below.
        $tab_meta = self::collect_custom_tab_meta();
        $target_to_label = $tab_meta['labels'];
        if (has_action('woocommerce_product_data_panels')) {
            // Buffer-safe fire (see capture_isolated_hook): a custom panel may
            // assume a saved product OR leave an output buffer open; neither may
            // take down the settings page.
            $panels_html = self::capture_isolated_hook('woocommerce_product_data_panels');

            if ($panels_html !== '') {
                $core_targets = self::core_panel_targets();
                $skip_ids = ['marketplace_suggestions'];
                // Shield <script> blocks so libxml does not eat panels that
                // follow a script-heavy panel while we enumerate ids.
                $shield = [];
                $panels_html = self::shield_scripts($panels_html, $shield);
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
                            // Keep this list in sync with capture_wc_product_data_fields():
                            // include `button` so JS-driven builders that render only a
                            // trigger button server-side (e.g. Pektsekye "Add Option")
                            // are still offered in the settings selector.
                            foreach (['input', 'select', 'textarea', 'button'] as $tag) {
                                if ($node->getElementsByTagName($tag)->length > 0) { $has_controls = true; break; }
                            }
                            // A panel whose id is a registered tab target (declared via
                            // the `woocommerce_product_data_tabs` filter or the legacy
                            // panel-tabs action) is a legitimate custom tab even when its
                            // server-side body is empty — e.g. AcoWebs "Custom Product
                            // Addons" (`wcpa_product-meta-tab`) renders only a React mount
                            // point (`<div id="wcpa_product_meta">`) and hydrates it in
                            // JS. The control scan never sees a field for those, so trust
                            // the tab registration instead and still offer the section.
                            $is_registered_tab = isset($target_to_label[$id]);
                            if (!$has_controls && !$is_registered_tab) continue;
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
     * Harvest custom product-data tab metadata from BOTH tab-registration APIs.
     *
     * Modern plugins (Subscriptions, Bookings, swatches…) register their tab
     * through the `woocommerce_product_data_tabs` filter, which carries an
     * array with `label`/`target` we can read directly. Older plugins — e.g.
     * "Estimated Delivery Date Per Product For WooCommerce" — still use the
     * legacy `woocommerce_product_write_panel_tabs` ACTION, echoing raw
     * `<li><a href="#panel_id">Label</a></li>` markup with no array metadata.
     * Their panels were still detected (we scan the rendered panel HTML), but
     * with no label source the section fell back to an ugly id-derived name
     * ("Rpesp product data"). Parse that legacy markup too so the section
     * reads with the plugin's real tab title ("Product Est Date").
     *
     * Must be called inside the product-context spoof (so plugins evaluate
     * their tab hooks) and AFTER the tabs filter is needed — it applies the
     * filter itself. The modern filter wins; legacy only fills gaps.
     *
     * @return array{labels: array<string,string>, keys: array<string,string>}
     */
    private static function collect_custom_tab_meta() {
        $labels = [];
        $keys   = [];

        $tabs_meta = apply_filters('woocommerce_product_data_tabs', []);
        if (is_array($tabs_meta)) {
            foreach ($tabs_meta as $key => $tab) {
                $target = isset($tab['target']) ? (string) $tab['target'] : (string) $key;
                $labels[$target] = isset($tab['label']) ? (string) $tab['label'] : ucfirst((string) $key);
                $keys[$target]   = (string) $key;
            }
        }

        if (has_action('woocommerce_product_write_panel_tabs')) {
            // Buffer-safe fire — a plugin (e.g. Sales Countdown Timer) may open
            // an output buffer on this hook and close it on a *different* later
            // hook we never fire here; capture_isolated_hook() neutralises that.
            $tabs_html = self::capture_isolated_hook('woocommerce_product_write_panel_tabs');
            if ($tabs_html !== '' && preg_match_all('/<a\b[^>]*href\s*=\s*["\']#([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $tabs_html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $hit) {
                    $target = trim($hit[1]);
                    if ($target === '' || isset($labels[$target])) continue;
                    $label = trim(html_entity_decode(wp_strip_all_tags($hit[2]), ENT_QUOTES));
                    if ($label === '') continue;
                    $labels[$target] = $label;
                    $keys[$target]   = $target;
                }
            }
        }

        return ['labels' => $labels, 'keys' => $keys];
    }

    /**
     * Fire a foreign WooCommerce product/variation hook during field
     * enumeration and return everything it echoed, while guaranteeing the
     * output-buffer stack is left exactly as deep as it was found.
     *
     * Why this exists: some third-party plugins open an output buffer on one
     * product-data hook and close it on a *different*, later hook, relying on
     * WooCommerce firing the whole pair during a normal product-edit render.
     * Sales Countdown Timer (VillaTheme) is the canonical case — it calls
     * ob_start() on `woocommerce_product_write_panel_tabs` and the matching
     * ob_get_clean() on `woocommerce_product_options_pricing`. BrikPanel fires
     * these hooks individually to discover which fields a plugin adds, so only
     * one half of such a pair runs: the plugin's lone ob_start() leaks an
     * output buffer, or its lone ob_get_clean() swallows one of ours. A single
     * unbalanced buffer then absorbs and reorders the rest of the admin page —
     * dropping jQuery from <head> and white-screening the WooCommerce settings
     * tab with a "jQuery is not defined" cascade.
     *
     * We record the buffer depth, open our own capture buffer plus one
     * sacrificial guard buffer (so a single stray ob_get_clean() eats the guard
     * instead of ours), fire the hook, then unwind back to the recorded depth.
     * This neutralises an imbalance in EITHER direction, no matter how many
     * buffers a callback opens or closes, and never returns with the stack
     * shallower than it started (which would corrupt the surrounding page).
     *
     * @param string $hook Action hook to fire.
     * @param array  $args Positional args passed to the hook callbacks.
     * @return string Trimmed concatenation of everything the hook echoed.
     */
    private static function capture_isolated_hook($hook, array $args = []) {
        $base = ob_get_level();
        ob_start(); // capture buffer
        ob_start(); // sacrificial guard — absorbs a stray ob_get_clean() whose
                    // paired opener never ran in this isolated fire
        try {
            do_action_ref_array($hook, $args);
        } catch (\Throwable $e) {
            // A callback assuming a fully-saved product context must never break
            // enumeration; its fields are non-essential to the selector.
        }
        // A callback may have closed more buffers than it opened — re-pad first
        // so we never unwind below (or return shallower than) where we started.
        while (ob_get_level() < $base) {
            ob_start();
        }
        // Unwind our buffers (and any a callback left open), innermost first.
        // The outer buffer holds output echoed before any nested ob_start(), so
        // prepend each layer to keep echo order intact.
        $html = '';
        while (ob_get_level() > $base) {
            $html = ob_get_clean() . $html;
        }
        return trim($html);
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
     * Auto-surface a multi-currency plugin's per-currency price fields in the
     * editor when that plugin is in "fixed price" mode, even if the merchant
     * has not ticked the Pricing section manually. Those fields are rendered
     * by the plugin on WooCommerce's native price hooks, which BrikPanel's
     * field-capture only fires for explicitly-selected sections — so without
     * this a CURCY (fixed-price) user would see no place to enter per-currency
     * prices. Purely additive: it never removes the merchant's own selections,
     * and only acts when the plugin is active AND in fixed-price mode.
     *
     * @param string[] $selected Section keys already chosen in settings.
     * @param string   $context  'variation' or 'product'.
     * @return string[] Possibly-augmented section keys.
     */
    private static function augment_sections_for_multicurrency( array $selected, $context ) {
        if ( ! class_exists( 'WOOMULTI_CURRENCY_Data' ) ) {
            return $selected;
        }
        $data = WOOMULTI_CURRENCY_Data::get_ins();
        if ( ! is_object( $data ) || ! method_exists( $data, 'check_fixed_price' ) || ! $data->check_fixed_price() ) {
            return $selected;
        }
        /**
         * Allow disabling the automatic surfacing of multi-currency per-currency
         * price fields in the product editor.
         *
         * @param bool $enabled Default true.
         */
        if ( ! apply_filters( 'brikpanel_pe_auto_multicurrency_pricing', true ) ) {
            return $selected;
        }

        // Use a dedicated synthetic key (NOT the native section key) so the
        // capture path renders ONLY the multi-currency plugin's own fields in
        // isolation. Injecting the whole `core:general` / `varhook:…pricing`
        // group here used to drag every other plugin's General-tab fields into
        // "Additional product data" even when the merchant had every section
        // switched off — surfacing the per-currency prices must never do that.
        $key = ( 'variation' === $context ) ? 'curcy:variation_pricing' : 'curcy:product_pricing';
        if ( ! in_array( $key, $selected, true ) ) {
            $selected[] = $key;
        }
        return $selected;
    }

    /**
     * Render ONLY the multi-currency plugin's own field output on a given WC
     * product-data hook, isolating it from every other plugin that also hooks
     * there. We snapshot the hook's callbacks, keep only those whose class is
     * the CURCY admin class, fire the hook, then restore the originals — so
     * auto-surfacing per-currency prices never drags in unrelated fields that
     * other plugins attach to the same native hook.
     *
     * @param string $hook WC product-data action hook to fire.
     * @param array  $args Positional args to pass (variation hooks need them).
     * @return string Captured HTML (empty when CURCY has nothing on the hook).
     */
    private static function render_isolated_multicurrency_fields( $hook, array $args = array() ) {
        global $wp_filter;
        if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
            return '';
        }
        $hook_obj = $wp_filter[ $hook ];
        $saved    = $hook_obj->callbacks;

        $kept = array();
        foreach ( $saved as $priority => $list ) {
            foreach ( $list as $id => $cb ) {
                $fn  = isset( $cb['function'] ) ? $cb['function'] : null;
                $cls = '';
                if ( is_array( $fn ) ) {
                    $cls = is_object( $fn[0] ) ? get_class( $fn[0] ) : ( is_string( $fn[0] ) ? $fn[0] : '' );
                } elseif ( is_string( $fn ) && strpos( $fn, '::' ) !== false ) {
                    $cls = substr( $fn, 0, strpos( $fn, '::' ) );
                }
                if ( $cls !== '' && stripos( $cls, 'WOOMULTI_CURRENCY' ) !== false ) {
                    $kept[ $priority ][ $id ] = $cb;
                }
            }
        }
        if ( empty( $kept ) ) {
            return '';
        }

        $hook_obj->callbacks = $kept;
        ob_start();
        try {
            do_action_ref_array( $hook, $args );
        } catch ( \Throwable $e ) {
            // A misbehaving render must not abort the editor — just skip it.
        }
        $html = trim( ob_get_clean() );

        // Always restore the full callback set, even if rendering threw.
        $hook_obj->callbacks = $saved;
        return $html;
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
        $selected = self::augment_sections_for_multicurrency($selected, 'variation');
        if (empty($selected) || empty($variations)) return [];

        if (!function_exists('woocommerce_wp_text_input')) {
            include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
        }

        $selected_hooks = [];
        foreach (self::variation_field_hooks() as $hook => $_) {
            if (in_array('varhook:' . $hook, $selected, true)) $selected_hooks[] = $hook;
        }
        // Multi-currency auto-surface (fixed mode): render only CURCY's own
        // per-variation price inputs, isolated from the rest of the variation
        // pricing hook — unless the merchant already picked that whole section.
        $curcy_var = in_array('curcy:variation_pricing', $selected, true)
            && !in_array('woocommerce_variation_options_pricing', $selected_hooks, true);
        if (empty($selected_hooks) && !$curcy_var) return [];

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
                // Buffer-safe fire — variation hooks have the same unbalanced
                // output-buffer risk as the product-level ones (Sales Countdown
                // Timer wraps woocommerce_variation_options too).
                $html .= self::capture_isolated_hook($hook, [$loop, $variation_data, $variation_post]);
            }
            if ($curcy_var) {
                $html .= self::render_isolated_multicurrency_fields(
                    'woocommerce_variation_options_pricing',
                    [$loop, $variation_data, $variation_post]
                );
            }
            if ($html !== '') {
                $out[$variation_post->ID] = $html;
            }
        }

        // Restore globals
        $post = $orig[0]; $thepostid = $orig[1]; $product_object = $orig[2];

        return $out;
    }

    /**
     * Render the per-variation 3rd-party field structure for variations that
     * have NOT been persisted yet (a brand-new product, or rows just generated
     * in the wizard). Returns `[ loop_index => html ]`.
     *
     * No database row exists for these variations, so we render against ONE
     * real but temporary variation parented to the product, then delete it.
     * A real (empty) variation is required because many plugins load the
     * variation by ID and call methods on it — e.g. WooCommerce Multi Currency
     * does `wc_get_product($variation->ID)->get_meta(...)`, which would fatal on
     * a fake ID 0. Its meta is empty, so the fields render empty — exactly what
     * a brand-new variation should show. Field names depend only on $loop, and
     * $loop equals the variation's row position, which is the same index the
     * save handler ($loop_index in save_variations) feeds to
     * `woocommerce_save_product_variation`; so whatever the user types persists
     * to the matching variation once the product is saved.
     *
     * Each hook fires inside try/catch (same guard the metabox enumeration
     * uses) so one misbehaving plugin can never fatal the request — it just
     * contributes no preview for that hook.
     *
     * @param WC_Product $product Parent variable product (the auto-draft).
     * @param int[]      $loops   Zero-based row indices to render.
     * @return array<int, string>
     */
    private function preview_wc_variation_fields($product, array $loops) {
        $selected = (array) get_option('brikpanel_pe_wc_variation_sections', []);
        $selected = self::augment_sections_for_multicurrency($selected, 'variation');
        if (empty($selected) || empty($loops)) return [];

        if (!function_exists('woocommerce_wp_text_input')) {
            include_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
        }

        $selected_hooks = [];
        foreach (self::variation_field_hooks() as $hook => $_) {
            if (in_array('varhook:' . $hook, $selected, true) && has_action($hook)) {
                $selected_hooks[] = $hook;
            }
        }
        // Multi-currency auto-surface (fixed mode): isolated CURCY price inputs,
        // unless the whole variation pricing section is already selected.
        $curcy_var = in_array('curcy:variation_pricing', $selected, true)
            && !in_array('woocommerce_variation_options_pricing', $selected_hooks, true);
        if (empty($selected_hooks) && !$curcy_var) return [];

        // Spin up a throwaway variation so plugin callbacks have a real object
        // to load. Deleted in the finally block no matter what.
        // Belt-and-suspenders: sweep any scratch variation a previous request
        // may have left behind if a hard fatal (OOM/timeout) ever bypassed the
        // finally-block below. The `_brikpanel_preview_temp` marker guarantees
        // we only ever touch our own throwaway rows, never a real variation.
        $this->purge_preview_temp_variations($product->get_id());

        $temp = new WC_Product_Variation();
        $temp->set_parent_id($product->get_id());
        $temp->set_status('private');
        $temp->add_meta_data('_brikpanel_preview_temp', 1, true);
        $temp_id = $temp->save();
        if (!$temp_id) return [];
        $temp_post = get_post($temp_id);

        global $post, $thepostid, $product_object;
        $orig = [$post, $thepostid ?? null, $product_object ?? null];

        $out = [];
        try {
            foreach ($loops as $loop) {
                $loop = (int) $loop;
                $post           = $temp_post;
                $thepostid      = $temp_id;
                $product_object = wc_get_product($temp_id);

                $html = '';
                foreach ($selected_hooks as $hook) {
                    // Buffer-safe fire (see capture_isolated_hook): swallows both
                    // a fatal against a fresh variation and an unbalanced buffer.
                    $html .= self::capture_isolated_hook($hook, [$loop, [], $temp_post]);
                }
                if ($curcy_var) {
                    $html .= self::render_isolated_multicurrency_fields(
                        'woocommerce_variation_options_pricing',
                        [$loop, [], $temp_post]
                    );
                }
                if ($html !== '') {
                    $out[$loop] = $html;
                }
            }
        } finally {
            $post = $orig[0]; $thepostid = $orig[1]; $product_object = $orig[2];
            wp_delete_post($temp_id, true);
        }

        return $out;
    }

    /**
     * Force-delete any leftover preview scratch variations under a product.
     * Only rows carrying our `_brikpanel_preview_temp` marker are removed, so a
     * real variation can never be caught by this sweep.
     *
     * @param int $parent_id Parent product ID.
     */
    private function purge_preview_temp_variations($parent_id) {
        $stale = get_posts([
            'post_type'        => 'product_variation',
            'post_parent'      => (int) $parent_id,
            'post_status'      => 'any',
            'numberposts'      => 50,
            'fields'           => 'ids',
            'meta_key'         => '_brikpanel_preview_temp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);
        foreach ($stale as $sid) {
            wp_delete_post($sid, true);
        }
    }

    /**
     * AJAX: return preview 3rd-party variation-field HTML keyed by row index
     * for not-yet-saved variations. Lets the editor show the "More fields"
     * expander before the first save.
     */
    public function ajax_preview_variation_fields() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $count      = (int) ($_POST['count'] ?? 0);
        // Guard against pathological payloads — variation grids never run this
        // large, and unbounded rendering would be a soft DoS vector.
        $count = max(0, min(200, $count));

        if (!$product_id || $count < 1) {
            wp_send_json_success(['extras' => []]);
        }

        // Per-object capability: don't render (and momentarily create) scratch
        // variations against a product the caller can't edit.
        if (!current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_success(['extras' => []]);
        }

        $extras = $this->preview_wc_variation_fields($product, range(0, $count - 1));
        wp_send_json_success(['extras' => $extras]);
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
        $selected = self::augment_sections_for_multicurrency($selected, 'product');
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

        // Multi-currency per-currency price fields — auto-surfaced in fixed
        // mode via the synthetic `curcy:product_pricing` key. Rendered in
        // isolation so ONLY the CURCY inputs appear, never the rest of the
        // General tab that other plugins also hook.
        if (in_array('curcy:product_pricing', $selected, true)) {
            $curcy_html = self::render_isolated_multicurrency_fields('woocommerce_product_options_pricing');
            if ($curcy_html !== '') {
                $output .= '<div class="brikpanel-pe-wc-tab-group" data-tab="multicurrency">'
                    . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html__('Multi-currency prices', 'brikpanel') . '</h4>'
                    . $curcy_html
                    . '</div>';
            }
        }

        // Core sub-hook sections — keyed `core:<label_slug>`.
        foreach ($core_sub_hooks as $label => $hooks) {
            $key = 'core:' . sanitize_key($label);
            if (!in_array($key, $selected, true)) continue;

            $section = '';
            foreach ($hooks as $hook) {
                if (!has_action($hook)) continue;
                // Buffer-safe fire (see capture_isolated_hook): never let a
                // misbehaving 3rd-party field render abort the editor or leak an
                // output buffer that corrupts the page.
                $html = self::capture_isolated_hook($hook);
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
        //
        // Apply the tabs filter FIRST, before checking/firing the panels hook.
        // Some plugins (e.g. Product Catalog Feed Pro / WPWOOF) register their
        // `woocommerce_product_data_panels` callback lazily from inside the
        // `woocommerce_product_data_tabs` filter — so the panel action does not
        // exist until that filter has run. Firing the filter here makes those
        // lazy panels available before has_action()/do_action() below, matching
        // the order WooCommerce core uses when it renders the product data box.
        // (collect_wc_product_data_sections() already applies the filter before
        // its own do_action, which is why the selector listed the tab while the
        // render path used to come up empty.)
        $tab_meta = self::collect_custom_tab_meta();
        if (has_action('woocommerce_product_data_panels')) {
            // Buffer-safe fire — see capture_isolated_hook().
            $panels_html = self::capture_isolated_hook('woocommerce_product_data_panels');

            if ($panels_html !== '') {
                $target_to_label = $tab_meta['labels'];
                $target_to_key   = $tab_meta['keys'];

                // Shield <script> blocks (inline init + JS templates) so libxml
                // does not corrupt or drop them; restored on the output below.
                $script_store = [];
                $panels_html  = self::shield_scripts($panels_html, $script_store);

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
                            // Mirror collect_wc_product_data_sections(): a panel backed by
                            // a registered tab (e.g. AcoWebs `wcpa_product-meta-tab`, a JS
                            // mount point with no server-rendered control) is still a real
                            // section. Render it so its builder can hydrate client-side.
                            $is_registered_tab = isset($target_to_label[$id]);
                            if (!$has_controls && !$is_registered_tab) continue;

                            // Preserve the panel wrapper itself (its id, classes and
                            // data-* attributes) rather than unwrapping it. Many custom
                            // option plugins mount their builder / bind their click
                            // handlers / init sortables onto the panel root by id
                            // (`#<panel_id>`) or by the `.woocommerce_options_panel`
                            // class. Dropping that wrapper let the fields render but
                            // left every control inert ("click but nothing happens").
                            //
                            // WooCommerce hides inactive panels with an inline
                            // `display:none`; strip it (and tag the node) so the panel
                            // is visible inside our card.
                            $style = $node->getAttribute('style');
                            if ($style !== '') {
                                $style = trim(preg_replace('/display\s*:\s*none\s*;?/i', '', $style));
                                if ($style === '') {
                                    $node->removeAttribute('style');
                                } else {
                                    $node->setAttribute('style', $style);
                                }
                            }
                            $node->setAttribute('class', trim($node->getAttribute('class') . ' brikpanel-pe-wc-panel'));

                            $panel_html = self::restore_scripts(trim($dom->saveHTML($node)), $script_store);
                            if ($panel_html === '') continue;

                            $label = $target_to_label[$id] ?? ucfirst(str_replace('_', ' ', $id));

                            // Some plugins lazy-render a panel's saved content only
                            // when their WooCommerce tab link is clicked (e.g.
                            // Pektsekye binds `.{key}_tab` click -> loadOptions).
                            // BrikPanel has no WC tab nav, so those plugins would
                            // show an empty builder even when options exist. Emit a
                            // hidden, WC-shaped tab-nav node carrying the same
                            // classes the plugin listens on; the editor JS clicks it
                            // on load to trigger the lazy render. (Our panel stays
                            // visible via .brikpanel-pe-wc-panel regardless.)
                            $panel_tab_key = $target_to_key[$id] ?? $id;
                            $tab_nav = '<ul class="product_data_tabs brikpanel-pe-wc-tabsim" aria-hidden="true">'
                                . '<li class="' . esc_attr($panel_tab_key) . '_options ' . esc_attr($panel_tab_key) . '_tab">'
                                . '<a href="#' . esc_attr($id) . '" tabindex="-1"><span>' . esc_html($label) . '</span></a>'
                                . '</li></ul>';

                            $output .= '<div class="brikpanel-pe-wc-tab-group" data-tab="' . esc_attr($label) . '">'
                                . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html($label) . '</h4>'
                                . $tab_nav
                                . $panel_html
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
            'slug'              => '',
            'regular_price'     => '',
            'sale_price'        => '',
            'stock_quantity'    => '',
            'sku'               => '',
            'global_unique_id'  => '',
            'short_description' => '',
            'description'       => '',
            'weight'            => '',
            'length'            => '',
            'width'             => '',
            'height'            => '',
            'category_ids'      => [],
            'brand_ids'         => [],
            'status'            => 'publish',
            'is_variable'       => false,
            'product_type'      => 'simple',
            'attributes'        => [],
            'non_variation_attributes' => [],
            'variations'        => [],
            'gallery'           => [],
            'seo_title'         => '',
            'seo_description'   => '',
            'is_downloadable'   => false,
            'is_virtual'        => false,
            'downloads'         => [],
            'tags'              => [],
            'stock_status'      => 'instock',
            'backorders'        => 'no',
            'manage_stock'      => false,
            'cogs_value'        => '',
            'sale_from'         => '',
            'sale_to'           => '',
            'seo_focus_kw'      => '',
            'seo_canonical'     => '',
            'seo_noindex'       => false,
            'primary_cat'       => '',
            'post_password'     => '',
            'catalog_visibility' => 'visible',
            'is_featured'       => false,
            // Opt-in WC-core sections (off by default; toggled from settings).
            'tax_status'        => 'taxable',
            'tax_class'         => '',
            'shipping_class'    => '',
            'sold_individually' => false,
            'upsells'           => [],
            'cross_sells'       => [],
            'purchase_note'     => '',
            'reviews_allowed'   => true,
            'menu_order'        => 0,
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
        $attributes_data           = [];
        $non_variation_attributes  = [];
        $variations_data           = [];
        $is_variable               = $product->is_type('variable');

        // Build a normalized {name, values, taxonomy} record for any
        // WC_Product_Attribute, resolving taxonomy term slugs/ids to display
        // names so the editor renders consistently for custom and global
        // attributes.
        $build_attr_record = function ($attr) use ($product) {
            $attr_name = $attr->get_name();
            $is_tax = $attr->is_taxonomy() || (is_string($attr_name) && strpos($attr_name, 'pa_') === 0 && taxonomy_exists($attr_name));

            if ($is_tax) {
                $taxonomy = $attr_name;
                $values = [];
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

            return [
                'name'     => $display_name,
                'values'   => $values,
                'taxonomy' => $taxonomy,
            ];
        };

        // Always collect non-variation (spec-style) attributes — Brand,
        // Material, Country of origin, etc. — so the new "Product attributes"
        // card can edit them on both simple and variable products. WC's own
        // get_attributes() sort respects each attribute's position, so DOM
        // order matches the storefront order.
        foreach ($product->get_attributes() as $attr) {
            if ($attr->get_variation()) continue;
            $non_variation_attributes[] = $build_attr_record($attr);
        }

        if ($is_variable) {
            // Surface only variation attributes for the variations wizard;
            // spec-style non-variation attributes are handled by the
            // dedicated "Product attributes" card above.
            foreach ($product->get_attributes() as $attr) {
                if (!$attr->get_variation()) continue;
                $attributes_data[] = $build_attr_record($attr);
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
                if (get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes') {
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
                }

                // Variation shipping class. WC_Product_Variation::get_shipping_class()
                // falls back to the PARENT's slug even in 'edit' context when the
                // variation inherits (own id 0), so it cannot tell "inherits parent"
                // apart from "explicitly set to the same class the parent happens to
                // use". Read the variation's OWN id instead: 0 → '' ("Same as
                // parent"), otherwise resolve that id to its slug. Without this an
                // inheriting variation shows the parent's class and gets silently
                // pinned to it on the next save, breaking follow-the-parent.
                $var_ship_own_id = (int) $variation->get_shipping_class_id('edit');
                $var_ship_class  = '';
                if ($var_ship_own_id > 0) {
                    $var_ship_term = get_term($var_ship_own_id, 'product_shipping_class');
                    if ($var_ship_term && !is_wp_error($var_ship_term)) {
                        $var_ship_class = $var_ship_term->slug;
                    }
                }

                $variations_data[] = [
                    'id'             => $variation->get_id(),
                    'attributes'     => $var_attrs,
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    // Per-variation stock management flag. Drives the row's
                    // "Track" checkbox; when off the variation uses a direct
                    // in/out/backorder status instead of a tracked quantity.
                    'manage_stock'   => (bool) $variation->get_manage_stock(),
                    'sku'            => $variation->get_sku() ?? '',
                    'global_unique_id' => $variation->get_global_unique_id() ?? '',
                    'tax_class'      => (string) $variation->get_tax_class('edit'),
                    'shipping_class' => $var_ship_class,
                    'images'         => $var_images,
                    'cogs_value'     => method_exists($variation, 'get_cogs_value') ? ($variation->get_cogs_value() ?? get_post_meta($variation->get_id(), '_brikpanel_cogs', true)) : get_post_meta($variation->get_id(), '_brikpanel_cogs', true),
                    'stock_status'   => $variation->get_stock_status() ?: 'instock',
                    // WC stores three backorder values: no / yes / notify.
                    // Pass the raw value so the editor can roundtrip it
                    // when the "Notify customer" sub-control is enabled.
                    'backorders'     => $variation->get_backorders() ?: 'no',
                    'sale_from'      => $variation->get_date_on_sale_from() ? $variation->get_date_on_sale_from()->date('Y-m-d') : '',
                    'sale_to'        => $variation->get_date_on_sale_to()   ? $variation->get_date_on_sale_to()->date('Y-m-d')   : '',
                    // Per-variation vendor override. 0 means "inherit from
                    // parent" — the JS renders that as a "(parent)" option.
                    'vendor_id'      => (int) get_post_meta($variation->get_id(), '_brikpanel_vendor_id', true),
                    'vendor_sku'     => (string) get_post_meta($variation->get_id(), '_brikpanel_vendor_sku', true),
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

        // SureRank stores its per-post SEO across two meta keys: page title,
        // meta description, canonical URL and focus keyword are sub-keys of a
        // single serialized array `surerank_settings_general`, while the
        // noindex flag is the separate scalar `surerank_settings_post_no_index`
        // ('yes' = noindex). Its editor is a React popup with no inline-
        // renderable metabox, so BrikPanel surfaces these through its own
        // unified SEO fields instead. When SureRank is active it is the source
        // of truth: read it exclusively (skip the loop below) so stale meta a
        // previously used plugin left behind can't shadow it, and so empty
        // values stay empty — SureRank falls back to its site-wide template
        // for empty sub-keys, which matches the "leave empty to use default"
        // field hint.
        $surerank_active = defined('SURERANK_VERSION');
        if ($surerank_active) {
            $sr_general = get_post_meta($pid, 'surerank_settings_general', true);
            if (is_array($sr_general)) {
                $seo_title     = (string) ($sr_general['page_title'] ?? '');
                $seo_desc      = (string) ($sr_general['page_description'] ?? '');
                $seo_focus_kw  = (string) ($sr_general['focus_keyword'] ?? '');
                $seo_canonical = (string) ($sr_general['canonical_url'] ?? '');
            }
            $seo_noindex = ((string) get_post_meta($pid, 'surerank_settings_post_no_index', true) === 'yes');
        }

        // SmartCrawl (WPMU DEV SEO) stores its per-post SEO in `_wds_*` meta
        // keys. Like SureRank its editor is a React popup with no inline
        // metabox, so BrikPanel surfaces it through the unified fields. When
        // SmartCrawl is the active analyzer (and SureRank is not) it is the
        // source of truth: read it exclusively so stale meta a previously used
        // plugin left behind can't shadow it. Focus keywords are stored
        // comma-separated; show the primary (first) one. (See the matching
        // precedence in get_unified_seo_analyzer().)
        // SEOPress 9.9+ "universal metabox" mode: like SureRank/SmartCrawl its
        // editor is a React surface with no inline-renderable metabox, so
        // BrikPanel surfaces it through the unified fields and treats its
        // `_seopress_*` meta as the source of truth — read it exclusively so
        // stale meta a previously used plugin left behind can't shadow it.
        // Precedence below SureRank, above SmartCrawl, matching the order in
        // get_unified_seo_analyzer().
        $seopress_unified_active = !$surerank_active && self::seopress_uses_universal_metabox();
        if ($seopress_unified_active) {
            $seo_title     = (string) get_post_meta($pid, '_seopress_titles_title', true);
            $seo_desc      = (string) get_post_meta($pid, '_seopress_titles_desc', true);
            $seo_focus_kw  = (string) get_post_meta($pid, '_seopress_analysis_target_kw', true);
            $seo_canonical = (string) get_post_meta($pid, '_seopress_robots_canonical', true);
            $seo_noindex   = ((string) get_post_meta($pid, '_seopress_robots_index', true) === 'yes');
        }

        $smartcrawl_active = !$surerank_active && !$seopress_unified_active && defined('SMARTCRAWL_VERSION');
        if ($smartcrawl_active) {
            $seo_title     = (string) get_post_meta($pid, '_wds_title', true);
            $seo_desc      = (string) get_post_meta($pid, '_wds_metadesc', true);
            $seo_canonical = (string) get_post_meta($pid, '_wds_canonical', true);
            $raw_kw        = (string) get_post_meta($pid, '_wds_focus-keywords', true);
            $kw_parts      = array_filter(array_map('trim', explode(',', $raw_kw)));
            $seo_focus_kw  = $kw_parts ? (string) reset($kw_parts) : '';
            $seo_noindex   = (bool) get_post_meta($pid, '_wds_meta-robots-noindex', true);
        }

        $unified_owns_seo = $surerank_active || $seopress_unified_active || $smartcrawl_active;

        if (!$unified_owns_seo) foreach ($seo_sources as $src) {
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
        if (!$unified_owns_seo && function_exists('aioseo') && class_exists('\\AIOSEO\\Plugin\\Common\\Models\\Post')) {
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

        // Linked products → {id, text} pairs so the picker can render chips
        // without a second round-trip.
        $linked_pairs = static function ($ids) {
            $out = [];
            foreach ((array) $ids as $lid) {
                $lp = wc_get_product((int) $lid);
                if (!$lp) continue;
                $sku = $lp->get_sku();
                $out[] = [
                    'id'   => (int) $lid,
                    'text' => $lp->get_name() . ($sku !== '' ? ' (' . $sku . ')' : ''),
                ];
            }
            return $out;
        };

        return [
            'name'              => $name,
            // Permalink slug (post_name). Empty for brand-new products until WP
            // derives one from the title on first publish; the editor leaves the
            // field blank and shows the auto-from-title hint in that case.
            'slug'              => $product->get_slug() ?? '',
            'regular_price'     => $regular_price,
            'sale_price'        => $sale_price,
            'stock_quantity'    => $stock_qty,
            'sku'               => $product->get_sku() ?? '',
            'global_unique_id'  => $product->get_global_unique_id() ?? '',
            'short_description' => $product->get_short_description() ?? '',
            'description'       => $product->get_description() ?? '',
            'weight'            => $product->get_weight() ?? '',
            'length'            => $product->get_length() ?? '',
            'width'             => $product->get_width() ?? '',
            'height'            => $product->get_height() ?? '',
            'category_ids'      => $product->get_category_ids(),
            'brand_ids'         => $this->get_product_brand_ids($product),
            // Auto-drafts are an internal transition state — surface them as
            // "publish" so the dropdown defaults to Published for brand-new
            // products. The hidden input still submits a real WP status that
            // the save path accepts.
            'status'            => ($product->get_status() === 'auto-draft') ? 'publish' : $product->get_status(),
            'is_variable'       => $is_variable,
            'product_type'      => (string) $product->get_type(),
            'attributes'        => $attributes_data,
            'non_variation_attributes' => $non_variation_attributes,
            'variations'        => $variations_data,
            'seo_title'         => $seo_title,
            'seo_description'   => $seo_desc,
            'seo_focus_kw'      => $seo_focus_kw,
            'seo_canonical'     => $seo_canonical,
            'seo_noindex'       => $seo_noindex,
            // "Primary category" — the product_cat used in %category% permalinks
            // + breadcrumbs. SEOPress, Yoast and Rank Math each inject their own
            // selector into the native Categories panel via JS that never runs on
            // the BrikPanel editor page, so we surface + save one shared control
            // inside the Category card and mirror it to every active plugin. Read
            // the value from the first active plugin that has one (any is fine
            // since we keep them in sync). Works for simple + variable products.
            'primary_cat' => (function () use ($pid) {
                foreach (self::primary_category_seo_plugins() as $plugin) {
                    $v = (string) get_post_meta($pid, $plugin['key'], true);
                    if ($v !== '' && $v !== '0') {
                        return $v;
                    }
                }
                return '';
            })(),
            // WC's variable product data store does not persist parent-level
            // `_virtual` / `_downloadable` meta — they're always read as false
            // on the parent. Derive the toggle state from the variations so
            // re-opening a variable product surfaces the real per-variation
            // state in the editor.
            'is_downloadable'   => $is_variable ? $this->variations_all_have_flag($product, 'is_downloadable') : $product->is_downloadable(),
            'is_virtual'        => $is_variable ? $this->variations_all_have_flag($product, 'is_virtual')      : $product->is_virtual(),
            'downloads'         => $downloads,
            'gallery'           => $gallery,
            'tags'              => wp_get_object_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
            'stock_status'      => $product->get_stock_status() ?: 'instock',
            // Whether WC stock management is enabled at product level. The
            // editor's "Track quantity" toggle reads this; when off the
            // merchant picks an in/out/backorder status directly instead of
            // a quantity (mirrors WooCommerce's native Inventory tab).
            'manage_stock'      => (bool) $product->get_manage_stock(),
            // Same as variations — keep the raw `no/yes/notify` so the
            // editor's optional "Notify customer" radio survives a save.
            'backorders'        => $product->get_backorders() ?: 'no',
            'cogs_value'        => method_exists($product, 'get_cogs_value') ? ($product->get_cogs_value() ?? get_post_meta($product->get_id(), '_brikpanel_cogs', true)) : get_post_meta($product->get_id(), '_brikpanel_cogs', true),
            'sale_from'         => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : '',
            'sale_to'           => $product->get_date_on_sale_to()   ? $product->get_date_on_sale_to()->date('Y-m-d')   : '',
            'post_password'     => get_post_field('post_password', $product->get_id()),
            'catalog_visibility' => $product->get_catalog_visibility() ?: 'visible',
            'is_featured'       => $product->is_featured(),
            'tax_status'        => $product->get_tax_status() ?: 'taxable',
            'tax_class'         => (string) $product->get_tax_class('edit'),
            'shipping_class'    => (string) $product->get_shipping_class('edit'),
            'sold_individually' => $product->is_sold_individually(),
            'upsells'           => $linked_pairs($product->get_upsell_ids()),
            'cross_sells'       => $linked_pairs($product->get_cross_sell_ids()),
            'purchase_note'     => $product->get_purchase_note() ?? '',
            'reviews_allowed'   => (bool) $product->get_reviews_allowed(),
            'menu_order'        => (int) $product->get_menu_order(),
        ];
    }

    /**
     * Returns true only when every variation of a variable product reports
     * the requested flag (`is_virtual` or `is_downloadable`). Used to derive
     * the apparent parent-level toggle state because WC does not persist
     * these flags on variable parents.
     *
     * @param WC_Product $product Variable parent product.
     * @param string     $method  Method name to invoke on each variation.
     */
    private function variations_all_have_flag($product, $method) {
        $children = $product->get_children();
        if (empty($children)) {
            return false;
        }
        foreach ($children as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child || !$child->$method()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolve the brand term IDs assigned to a product, across whichever brand
     * taxonomy this install uses. Returns an empty array when no brand
     * taxonomy is registered or the product has no brand assigned.
     *
     * @param WC_Product $product
     * @return int[]
     */
    private function get_product_brand_ids($product) {
        $brand_taxonomy = brikpanel_pe_brand_taxonomy();
        if ($brand_taxonomy === '' || !$product) {
            return [];
        }
        $ids = wp_get_object_terms($product->get_id(), $brand_taxonomy, ['fields' => 'ids']);
        if (is_wp_error($ids)) {
            return [];
        }
        return array_map('intval', $ids);
    }

    /**
     * Copy custom taxonomy term relationships (brand, manufacturer, and any
     * third-party product taxonomy) from one product to another.
     *
     * WC_Product::save() already restores the taxonomies it stores on the
     * object itself — product_cat, product_tag, product_shipping_class, and
     * attribute (pa_*) taxonomies — so a PHP `clone` + save() carries those.
     * It does NOT carry raw term relationships like product_brand, which live
     * only as object-term links on the post. Without this, duplicating a
     * product silently drops its brand. We skip the WC-managed set to avoid
     * redundant writes and copy everything else verbatim.
     *
     * @param int $from_id Source product ID.
     * @param int $to_id   Destination product ID.
     */
    private function copy_product_taxonomies($from_id, $to_id) {
        $managed = ['product_cat', 'product_tag', 'product_shipping_class'];
        if (function_exists('wc_get_attribute_taxonomy_names')) {
            $managed = array_merge($managed, wc_get_attribute_taxonomy_names());
        }
        foreach (get_object_taxonomies('product') as $taxonomy) {
            if (in_array($taxonomy, $managed, true)) {
                continue;
            }
            $terms = wp_get_object_terms($from_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            wp_set_object_terms($to_id, array_map('intval', $terms), $taxonomy);
        }
    }

    private function render_category_checklist($categories, $selected_ids, $parent = 0, $depth = 0, $input_name = 'category_ids[]') {
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
            echo '<label><input type="checkbox" name="' . esc_attr($input_name) . '" value="' . esc_attr($cat->term_id) . '"' . $checked . '> ' . esc_html($cat->name) . '</label>';
            $this->render_category_checklist($categories, $selected_ids, $cat->term_id, $depth + 1, $input_name);
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

    /**
     * Discard any buffered output (PHP notices, DB errors echoed by other
     * plugins, debug output…) before sending the JSON envelope. Without this,
     * sites running with WP_DEBUG_DISPLAY=on or pre-existing plugin issues
     * (e.g. WP Rocket querying a missing table) emit HTML that prepends our
     * JSON response — jQuery's `dataType: 'json'` parse then fails and the
     * editor's done() callback (which injects Duplicate / View / Add new and
     * flips the button text to "Update") never runs.
     */
    private function send_clean_json($success, $payload) {
        while (@ob_end_clean()) {}
        if ($success) {
            wp_send_json_success($payload);
        } else {
            wp_send_json_error($payload);
        }
    }

    public function ajax_save_product() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        // Buffer everything emitted between here and our wp_send_json_* call so
        // stray output from other hooks can't contaminate the JSON response.
        // send_clean_json() drops the buffer immediately before sending.
        ob_start();

        $product_id  = intval($_POST['product_id'] ?? 0);

        // Per-object authorization. The generic `edit_products` check above is
        // not enough for author/vendor-scoped roles: core's map_meta_cap gates
        // editing of an existing product behind `edit_product, $id` (ownership
        // + published-state). Without this, a user holding `edit_products` but
        // only author-scoped capabilities could POST an arbitrary product_id
        // they don't own and overwrite it (including wiping its variations).
        if ($product_id && !current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

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
                $this->send_clean_json(false, ['message' => __('Product not found.', 'brikpanel')]);
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
            $this->send_clean_json(false, ['message' => __('Product name is required.', 'brikpanel')]);
        }
        $product->set_name($name);
        $product->set_status($status);

        // Catalog visibility
        $catalog_vis = sanitize_key($_POST['catalog_visibility'] ?? 'visible');
        if (!in_array($catalog_vis, ['visible', 'catalog', 'search', 'hidden'], true)) {
            $catalog_vis = 'visible';
        }
        $product->set_catalog_visibility($catalog_vis);

        // Featured flag — backed by the `featured` term in the
        // `product_visibility` taxonomy. Applies to simple AND variable
        // parent products (variations don't carry their own featured state).
        // Only writes when the key is present so saves from contexts where
        // the star UI is hidden (setting off) leave the existing flag intact.
        if (array_key_exists('is_featured', $_POST)) {
            $product->set_featured(!empty($_POST['is_featured']));
        }

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

        // Stock. The "Track quantity" toggle is the explicit source of truth
        // for manage_stock — we no longer infer it from whether a quantity
        // was typed. WC supports three backorder values: no / yes / notify.
        $manage_stock = !empty($_POST['manage_stock']);
        $stock = isset($_POST['stock_quantity']) && $_POST['stock_quantity'] !== '' ? intval($_POST['stock_quantity']) : 0;
        $stock_status = sanitize_key($_POST['stock_status'] ?? 'instock');
        if (!in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $stock_status = 'instock';
        }
        $backorders_raw = sanitize_key($_POST['backorders'] ?? '');
        if (!$is_variable) {
            if ($manage_stock) {
                // Tracking on: merchant sets quantity + backorders, WC derives
                // the stock status from them (qty>0 → in stock, qty<=0 with
                // backorders → on backorder, else out of stock). We do NOT
                // force a status here, which is exactly what eliminates the
                // "managed + qty 0 stuck out of stock" trap.
                $backorders_value = in_array($backorders_raw, ['yes', 'notify'], true) ? $backorders_raw : 'no';
                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock);
                $product->set_backorders($backorders_value);
            } else {
                // Tracking off: status is chosen directly. Backorders only
                // carries meaning while the status is "On backorder".
                $backorders_value = ($stock_status === 'onbackorder')
                    ? (in_array($backorders_raw, ['yes', 'notify'], true) ? $backorders_raw : 'yes')
                    : 'no';
                $product->set_manage_stock(false);
                $product->set_stock_status($stock_status);
                $product->set_backorders($backorders_value);
            }
        }

        // SKU
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        try {
            $product->set_sku($sku);
        } catch (\Exception $e) {
            // SKU might be duplicate — continue without it
        }

        // Global Unique ID (GTIN/UPC/EAN/ISBN). Only touch it when the field
        // was actually submitted — the section is opt-in, so a missing key
        // means "leave the stored value alone" rather than "clear it".
        if (array_key_exists('global_unique_id', $_POST)) {
            $gtin = sanitize_text_field(wp_unslash($_POST['global_unique_id']));
            try {
                $product->set_global_unique_id($gtin);
            } catch (\Exception $e) {
                // Value might be a duplicate or invalid — continue without it
            }
        }

        // Opt-in WC-core sections. Each is guarded by array_key_exists so a
        // disabled section (key absent from the request) never overwrites the
        // stored value.
        if (array_key_exists('tax_status', $_POST)) {
            $ts = sanitize_text_field(wp_unslash($_POST['tax_status']));
            if (in_array($ts, ['taxable', 'shipping', 'none'], true)) {
                $product->set_tax_status($ts);
            }
        }
        if (array_key_exists('tax_class', $_POST)) {
            $product->set_tax_class(sanitize_text_field(wp_unslash($_POST['tax_class'])));
        }
        if (array_key_exists('shipping_class', $_POST)) {
            // WC has no set_shipping_class() setter — only set_shipping_class_id().
            // Resolve the submitted slug to its term id; empty slug → 0 (none).
            $sc_slug = sanitize_title(wp_unslash($_POST['shipping_class']));
            $sc_term = $sc_slug !== '' ? get_term_by('slug', $sc_slug, 'product_shipping_class') : false;
            $product->set_shipping_class_id($sc_term ? (int) $sc_term->term_id : 0);
        }
        if (array_key_exists('sold_individually', $_POST)) {
            $product->set_sold_individually(!empty($_POST['sold_individually']) && $_POST['sold_individually'] !== '0');
        }
        if (array_key_exists('upsell_ids', $_POST)) {
            $up = json_decode(wp_unslash($_POST['upsell_ids']), true);
            $product->set_upsell_ids(is_array($up) ? array_values(array_unique(array_filter(array_map('absint', $up)))) : []);
        }
        if (array_key_exists('cross_sell_ids', $_POST)) {
            $cs = json_decode(wp_unslash($_POST['cross_sell_ids']), true);
            $product->set_cross_sell_ids(is_array($cs) ? array_values(array_unique(array_filter(array_map('absint', $cs)))) : []);
        }
        if (array_key_exists('purchase_note', $_POST)) {
            $product->set_purchase_note(sanitize_textarea_field(wp_unslash($_POST['purchase_note'])));
        }
        if (array_key_exists('reviews_allowed', $_POST)) {
            $product->set_reviews_allowed(!empty($_POST['reviews_allowed']) && $_POST['reviews_allowed'] !== '0');
        }
        if (array_key_exists('menu_order', $_POST)) {
            $product->set_menu_order((int) $_POST['menu_order']);
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

        // Brand — assigned after WC_Product::save() further down, since
        // wp_set_object_terms() needs the (possibly newly-created) product ID.
        // We only stage the parsed IDs here; the actual write happens alongside
        // the tag assignment below. The `brand_ids` field is only posted when
        // the brand section is visible, so an omitted field means "leave brand
        // untouched" rather than "clear all brands".
        $brand_taxonomy = brikpanel_pe_brand_taxonomy();
        $brand_ids      = null;
        if ($brand_taxonomy !== '' && isset($_POST['brand_ids'])) {
            $brand_ids_raw = sanitize_text_field(wp_unslash($_POST['brand_ids']));
            $brand_ids = $brand_ids_raw
                ? array_values(array_unique(array_filter(array_map('intval', explode(',', $brand_ids_raw)))))
                : [];
        }

        // Product type flags — Virtual and Downloadable are independent.
        // A downloadable physical product (book + bonus PDF) needs Digital=on, Virtual=off.
        // A service/yoga session needs Virtual=on, Digital=off.
        // A standard eBook needs both on. WC stores both flags on the parent;
        // for variable products we also propagate to every variation below so
        // the storefront (which reads per-variation flags) stays consistent.
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
            $product->set_download_limit(-1); // unlimited
            $product->set_download_expiry(-1); // never expires
        } else {
            $product->set_downloads([]);
        }

        // COGS — saved to _brikpanel_cogs for BrikPanel reports (and mirrored
        // to WC 9.5+ native COGS when available). Only touched when the COGS
        // section is currently visible; the JS omits the field when hidden,
        // so absence here means "the editor never rendered this control" and
        // we must leave any existing cost on file alone. Otherwise turning
        // the section off would silently wipe every product's cost on its
        // next save.
        if ( array_key_exists( 'cogs_value', $_POST ) ) {
            $cogs_raw     = sanitize_text_field( $_POST['cogs_value'] );
            $cogs_decimal = $cogs_raw !== '' ? wc_format_decimal( $cogs_raw ) : '';
            if ( $cogs_decimal !== '' ) {
                update_post_meta( $product->get_id(), '_brikpanel_cogs', $cogs_decimal );
            } else {
                delete_post_meta( $product->get_id(), '_brikpanel_cogs' );
            }
            if ( method_exists( $product, 'set_cogs_value' ) ) {
                $product->set_cogs_value( $cogs_decimal !== '' ? $cogs_decimal : null );
            }
        }

        // Vendor — only persisted when the editor field is enabled. Otherwise
        // we leave any existing vendor meta alone (toggling the feature off
        // shouldn't wipe historical assignments). The receive flow on the
        // Stock Orders side keeps stamping vendor regardless of this toggle.
        if ( class_exists( 'Brikpanel_Vendor_Product_Editor' ) && Brikpanel_Vendor_Product_Editor::vendor_field_enabled() && isset( $_POST['bp_vendor_id'] ) ) {
            Brikpanel_Vendor_Product_Editor::persist_meta(
                $product->get_id(),
                (int) wp_unslash( $_POST['bp_vendor_id'] ),
                isset( $_POST['bp_vendor_sku'] ) ? wp_unslash( $_POST['bp_vendor_sku'] ) : ''
            );
        }

        // Save parent product
        $product->save();
        $saved_id = $product->get_id();

        // "Primary category" — mirrored into every active SEO plugin's own meta
        // key (SEOPress / Yoast / Rank Math). Saved in our own pipeline because
        // each plugin's native save hook lives on the Categories metabox, which
        // never fires on the BrikPanel editor page. Only a currently-assigned
        // category is a valid choice; anything else (or "none"/empty) clears the
        // meta, matching the plugins' own behaviour. Identical for simple and
        // variable products — it is product-level post meta.
        $primary_cat_plugins = self::primary_category_seo_plugins();
        if (!empty($primary_cat_plugins) && array_key_exists('primary_cat', $_POST)) {
            $primary_cat = absint($_POST['primary_cat']);
            $primary_cat_valid = ($primary_cat > 0 && in_array($primary_cat, $cat_ids, true));
            foreach ($primary_cat_plugins as $plugin) {
                if ($primary_cat_valid) {
                    update_post_meta($saved_id, $plugin['key'], (string) $primary_cat);
                } else {
                    delete_post_meta($saved_id, $plugin['key']);
                }
            }
        }

        // Post password — must be set via wp_update_post after the WC save
        // because WC's CRUD does not manage post_password.
        $post_update_args = [
            'ID'            => $saved_id,
            'post_password' => $post_password,
        ];

        // Permalink slug — opt-in editor section. Only act when the client sent
        // the key (the section is rendered); otherwise leave the stored slug
        // untouched. A non-empty value is sanitised and applied; an empty value
        // regenerates the slug from the product title. wp_update_post() runs
        // wp_unique_post_slug() so duplicates are de-conflicted automatically.
        if (isset($_POST['slug'])) {
            $requested_slug = sanitize_title(wp_unslash($_POST['slug']));
            if ($requested_slug === '') {
                $requested_slug = sanitize_title((string) get_post_field('post_title', $saved_id));
            }
            if ($requested_slug !== '') {
                $post_update_args['post_name'] = $requested_slug;
            }
        }

        wp_update_post($post_update_args);

        // Stock status workaround is now applied AFTER the secondary
        // $refreshed->save() further down so WC core's validate_props()
        // (which auto-syncs stock_status from quantity during save) cannot
        // undo it. See the matching block right after `$refreshed->save()`.

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

            // SureRank — title/description/canonical/focus keyword are sub-keys
            // of the serialized `surerank_settings_general` array; merge into
            // any existing array so SureRank's other sub-keys (separator,
            // auto-description, OG image, etc.) survive. Empty values are
            // written through as '' — SureRank falls back to its site-wide
            // template for empty sub-keys. The noindex flag is a separate
            // scalar: 'yes' = noindex, 'no' = force index (its per-post default).
            $sr_general = get_post_meta($saved_id, 'surerank_settings_general', true);
            if (!is_array($sr_general)) {
                $sr_general = [];
            }
            $sr_general['page_title']       = $seo_title;
            $sr_general['page_description'] = $seo_desc;
            $sr_general['canonical_url']    = $seo_canonical;
            $sr_general['focus_keyword']    = $seo_focus_kw;
            update_post_meta($saved_id, 'surerank_settings_general', $sr_general);
            update_post_meta($saved_id, 'surerank_settings_post_no_index', $seo_noindex ? 'yes' : 'no');

            // SmartCrawl (WPMU DEV SEO) — flat `_wds_*` meta keys. Focus
            // keywords are stored comma-separated; we write the single primary
            // keyword. The noindex flag is a scalar '1' = noindex (the key is
            // simply absent/empty when the product should be indexed, matching
            // SmartCrawl's own save). An empty title/description is written
            // through so SmartCrawl falls back to its site-wide template.
            update_post_meta($saved_id, '_wds_title', $seo_title);
            update_post_meta($saved_id, '_wds_metadesc', $seo_desc);
            update_post_meta($saved_id, '_wds_canonical', $seo_canonical);
            update_post_meta($saved_id, '_wds_focus-keywords', $seo_focus_kw);
            if ($seo_noindex) {
                update_post_meta($saved_id, '_wds_meta-robots-noindex', '1');
            } else {
                delete_post_meta($saved_id, '_wds_meta-robots-noindex');
            }
        }

        // Tags
        $tag_names_raw = sanitize_text_field(wp_unslash($_POST['tag_names'] ?? ''));
        if ($tag_names_raw !== '') {
            $tags = array_filter(array_map('trim', explode(',', $tag_names_raw)));
            wp_set_object_terms($saved_id, $tags, 'product_tag');
        } else {
            wp_set_object_terms($saved_id, [], 'product_tag');
        }

        // Brand assignment (staged above). Capability-checked against the brand
        // taxonomy's own assign_terms cap so we never write terms the user is
        // not allowed to. Works identically for simple and variable products —
        // brand lives on the parent post for both.
        if ($brand_taxonomy !== '' && $brand_ids !== null) {
            $brand_tax_obj = get_taxonomy($brand_taxonomy);
            $brand_cap     = $brand_tax_obj ? ($brand_tax_obj->cap->assign_terms ?? 'edit_products') : 'edit_products';
            if (current_user_can($brand_cap)) {
                wp_set_object_terms($saved_id, $brand_ids, $brand_taxonomy);
            }
        }

        // Process custom-taxonomy assignments posted from third-party metaboxes
        // (e.g. Orderable's Product Labels). WP core normally handles this in
        // edit_post() / wp_insert_post() based on $_POST['tax_input'], but
        // since our save path uses WC_Product::save() we must replicate it.
        if (!empty($_POST['tax_input']) && is_array($_POST['tax_input'])) {
            foreach ($_POST['tax_input'] as $raw_tax => $raw_terms) {
                $tax = sanitize_key($raw_tax);
                if ($tax === '' || in_array($tax, ['product_cat', 'product_tag'], true) || ($brand_taxonomy !== '' && $tax === $brand_taxonomy)) {
                    // product_cat + product_tag + brand are saved explicitly
                    // above; skip to avoid double-writes (a third-party brand
                    // metabox could otherwise re-post the same taxonomy).
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
        // Attach save handlers that third-party tab plugins only register on
        // non-ajax admin requests (e.g. Pektsekye Product Options).
        self::boot_thirdparty_ajax_save_handlers();
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

        // WC core's validate_props() auto-syncs stock_status from quantity
        // every time save() is called (stock > 0 forces "instock"), which
        // overrides the user's explicit choice — and the secondary
        // $refreshed->save() above re-runs it. Write the meta directly here,
        // *after* every WC save has finished, so the requested status sticks.
        // Only relevant when stock tracking is OFF: when it is on we let WC
        // derive the status from quantity + backorders on purpose.
        if (!$is_variable && empty($_POST['manage_stock']) && isset($_POST['stock_status'])) {
            $requested_status = sanitize_key($_POST['stock_status']);
            if (in_array($requested_status, ['instock', 'outofstock', 'onbackorder'], true)
                && get_post_meta($saved_id, '_stock_status', true) !== $requested_status) {
                update_post_meta($saved_id, '_stock_status', $requested_status);
                wp_cache_delete('product-' . $saved_id, 'products');
                clean_post_cache($saved_id);
                do_action('woocommerce_product_set_stock_status', $saved_id, $requested_status, wc_get_product($saved_id));
            }
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

        // Reload a fresh product instance before the attribute/variation branch
        // saves it a second time. The parent was already persisted above
        // (correct DB state, including any emptied array props). Re-saving the
        // ORIGINAL $product object here is unsafe: WC_Data::apply_changes() runs
        // array_replace_recursive(), which cannot empty an array prop in memory,
        // so a prop the user cleared (gallery, categories, up/cross-sells) still
        // reports its OLD value on the stale object. WC then re-writes that old
        // value on the second save whenever the meta row was deleted (it is, for
        // an emptied gallery) — the deleted image reappears on reload. A fresh
        // instance reads the just-persisted, correct state from the DB, so the
        // attribute/variation save only touches what it should.
        $reloaded = $saved_id ? wc_get_product($saved_id) : null;
        if ($reloaded) {
            $product = $reloaded;
        }

        // Handle variable product attributes and variations
        if ($is_variable) {
            $this->save_variations($product, $_POST);
        } else {
            // Simple-product path: persist the new "Product attributes" card
            // (non-variation specs). When the section was hidden in settings,
            // parse_… returns null and we leave existing attributes alone.
            $non_var_attrs = $this->parse_non_variation_attributes_input($_POST, $saved_id);
            if ($non_var_attrs !== null) {
                foreach ($non_var_attrs as $i => $a) {
                    $a->set_position($i);
                }
                $product->set_attributes($non_var_attrs);
                $product->save();

                // Clear term relationships for any pa_* taxonomy the user
                // removed from the spec list, so it stops appearing in that
                // attribute's archive/filter queries.
                $kept_taxonomies = [];
                foreach ($non_var_attrs as $a) {
                    if ($a->is_taxonomy()) {
                        $kept_taxonomies[] = $a->get_name();
                    }
                }
                $this->clear_removed_attribute_terms($saved_id, $kept_taxonomies);
            }
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

        $response = [
            'product_id' => $saved_id,
            'permalink'  => get_permalink($saved_id),
            // Hand back the final slug so the editor can reflect any value WP
            // de-duplicated (e.g. appended -2) or auto-generated from the title.
            'slug'       => get_post_field('post_name', $saved_id),
            'message'    => __('Product saved!', 'brikpanel'),
        ];

        // Variable products: hand back the freshly-persisted variation list and
        // any per-variation 3rd-party fields so the JS can adopt the new
        // variation IDs and surface the "More fields" expander without a full
        // page reload. Without this, variations created during the save (a brand
        // new product, or rows added to an existing one) keep id=0 client-side
        // and their expander never appears until the page is reloaded.
        if ($final_product && $final_product->is_type('variable')) {
            $fresh = $this->get_product_data($final_product);
            $variations = isset($fresh['variations']) ? $fresh['variations'] : [];
            $variation_ids = array_map(static function ($v) {
                return isset($v['id']) ? (int) $v['id'] : 0;
            }, $variations);
            $response['variations']       = $variations;
            $response['variation_extras'] = $variation_ids
                ? $this->capture_wc_variation_fields($final_product, $variation_ids)
                : [];
        }

        $this->send_clean_json(true, $response);
    }

    /**
     * Parse the POSTed `non_variation_attributes` JSON blob into an array of
     * WC_Product_Attribute objects flagged as non-variation (spec-style).
     *
     * Returns null when the field was not posted at all — that signals
     * "section disabled / not rendered, leave existing attributes alone" so
     * legacy callers don't accidentally wipe out attributes set elsewhere.
     * Returns an empty array when the user explicitly cleared every spec.
     *
     * @param array $post_data  Raw $_POST payload (already nonce-checked).
     * @param int   $product_id Product ID for taxonomy term assignment.
     * @return WC_Product_Attribute[]|null
     */
    /**
     * Whether the current user may create new product attribute terms
     * (wp_insert_term into a pa_* taxonomy). Shop managers / admins have this;
     * a custom role granting only product editing should not silently spawn
     * site-wide terms as a side effect of saving a product.
     */
    private function can_create_product_terms() {
        return current_user_can('manage_product_terms') || current_user_can('assign_product_terms');
    }

    /**
     * Resolve a term name to a term ID within $taxonomy, creating the term only
     * when the current user is allowed to. Returns the term ID, or 0 when the
     * term is absent and cannot be created (value is then silently skipped).
     */
    private function resolve_or_create_term($term_name, $taxonomy) {
        $term = get_term_by('name', $term_name, $taxonomy);
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($term_name), $taxonomy);
        }
        if ($term) {
            return (int) $term->term_id;
        }
        if (!$this->can_create_product_terms()) {
            return 0;
        }
        $inserted = wp_insert_term($term_name, $taxonomy);
        if (!is_wp_error($inserted) && isset($inserted['term_id'])) {
            return (int) $inserted['term_id'];
        }
        return 0;
    }

    /**
     * Clear product-attribute term relationships for any pa_* taxonomy that is
     * no longer present in the saved attribute set. Without this, deleting a
     * taxonomy attribute row leaves the old term-product relationships behind,
     * so the product keeps showing up in that attribute's archive/filter.
     *
     * @param int      $product_id      Saved product ID.
     * @param string[] $kept_taxonomies pa_* taxonomies that should remain.
     */
    private function clear_removed_attribute_terms($product_id, array $kept_taxonomies) {
        if (!$product_id || !function_exists('wc_get_attribute_taxonomy_names')) {
            return;
        }
        $attr_taxonomies = wc_get_attribute_taxonomy_names();
        if (empty($attr_taxonomies)) {
            return;
        }
        $assigned = wp_get_object_terms($product_id, $attr_taxonomies, ['fields' => 'all']);
        if (is_wp_error($assigned) || empty($assigned)) {
            return;
        }
        $current = [];
        foreach ($assigned as $term) {
            $current[$term->taxonomy] = true;
        }
        foreach (array_keys($current) as $tax) {
            if (!in_array($tax, $kept_taxonomies, true)) {
                wp_set_object_terms($product_id, [], $tax);
            }
        }
    }

    private function parse_non_variation_attributes_input($post_data, $product_id) {
        if (!isset($post_data['non_variation_attributes'])) {
            return null;
        }
        $data = json_decode(wp_unslash($post_data['non_variation_attributes']), true);
        if (!is_array($data)) {
            return [];
        }

        $attributes = [];
        $seen_names = [];

        foreach ($data as $attr_data) {
            $name     = sanitize_text_field($attr_data['name'] ?? '');
            $values   = array_map('sanitize_text_field', (array) ($attr_data['values'] ?? []));
            $taxonomy = sanitize_text_field($attr_data['taxonomy'] ?? '');

            if (empty($name) || empty($values)) continue;

            // De-dupe by lowercase name so the same attribute can't sneak
            // through twice from a malformed payload.
            $key = strtolower($taxonomy ?: $name);
            if (isset($seen_names[$key])) continue;
            $seen_names[$key] = true;

            $attribute = new WC_Product_Attribute();

            if ($taxonomy && taxonomy_exists($taxonomy)) {
                // Global taxonomy attribute — same code path as variation
                // attributes, but flagged as variation=false so WC stores it
                // as a spec rather than a variation axis.
                $attribute_id = (int) wc_attribute_taxonomy_id_by_name(str_replace('pa_', '', $taxonomy));
                $term_ids = [];
                foreach ($values as $term_name) {
                    $tid = $this->resolve_or_create_term($term_name, $taxonomy);
                    if ($tid) {
                        $term_ids[] = $tid;
                    }
                }
                if ($product_id) {
                    wp_set_object_terms($product_id, $term_ids, $taxonomy);
                }
                $attribute->set_id($attribute_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
            } else {
                $attribute->set_name($name);
                $attribute->set_options($values);
            }

            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $attributes[] = $attribute;
        }

        return $attributes;
    }

    /**
     * Synonyms used to detect Color / Size attributes by name across English
     * and Turkish stores. Comparison is always done lowercase.
     */
    private function size_color_synonyms() {
        return [
            'color' => ['color', 'colour', 'renk'],
            'size'  => ['size', 'beden'],
        ];
    }

    /**
     * Decide whether the given attribute name matches Color or Size by
     * synonym. Returns 'color', 'size', or '' (no match).
     */
    private function detect_size_color_role($name) {
        $needle = strtolower(trim((string) $name));
        if ($needle === '') return '';
        foreach ($this->size_color_synonyms() as $role => $synonyms) {
            if (in_array($needle, $synonyms, true)) return $role;
        }
        return '';
    }

    /**
     * Find a global WC attribute taxonomy whose slug, label, or full taxonomy
     * key matches one of the synonyms for the given role. Returns the
     * taxonomy name (e.g. 'pa_color') or '' if not found.
     */
    private function find_global_taxonomy_for_role($role) {
        $synonyms_map = $this->size_color_synonyms();
        if (!isset($synonyms_map[$role])) return '';
        $synonyms = $synonyms_map[$role];

        foreach (wc_get_attribute_taxonomies() as $attr) {
            $slug  = strtolower((string) $attr->attribute_name);
            $label = strtolower((string) ($attr->attribute_label ?: $attr->attribute_name));
            $tax   = strtolower(wc_attribute_taxonomy_name($attr->attribute_name));
            if (in_array($slug, $synonyms, true)
                || in_array($label, $synonyms, true)
                || $tax === 'pa_' . $role
            ) {
                return wc_attribute_taxonomy_name($attr->attribute_name);
            }
        }
        return '';
    }

    /**
     * Create a new global WC attribute for `color` or `size`, register the
     * taxonomy for the current request so wp_insert_term() / taxonomy_exists()
     * work immediately, and return the taxonomy name (e.g. `pa_color`).
     * Returns '' on failure.
     */
    private function create_global_size_color_taxonomy($role, $display_label) {
        if ($role !== 'color' && $role !== 'size') return '';

        // Creating a brand-new global attribute taxonomy is a store-management
        // action. Gate it so a product-only editor can't spawn site-wide
        // taxonomies; promotion is simply skipped and the attribute stays
        // custom for users without the capability.
        if (!current_user_can('manage_woocommerce')) return '';

        $slug = sanitize_title($role);
        $label = trim((string) $display_label) !== '' ? (string) $display_label : ucfirst($role);

        $attribute_id = wc_create_attribute([
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($attribute_id) || !$attribute_id) return '';

        $taxonomy = wc_attribute_taxonomy_name($slug);

        // Register the taxonomy for the rest of this request — WC normally
        // does this on the `init` hook from cached attribute list. After a
        // mid-request creation that cache is stale, so register manually.
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy(
                $taxonomy,
                apply_filters('woocommerce_taxonomy_objects_' . $taxonomy, ['product']),
                apply_filters('woocommerce_taxonomy_args_' . $taxonomy, [
                    'labels'             => ['name' => $label],
                    'hierarchical'       => true,
                    'show_ui'            => false,
                    'query_var'          => true,
                    'rewrite'            => false,
                    'sort'               => false,
                    'public'             => false,
                    'show_in_nav_menus'  => false,
                    'capabilities'       => [
                        'manage_terms' => 'manage_product_terms',
                        'edit_terms'   => 'edit_product_terms',
                        'delete_terms' => 'delete_product_terms',
                        'assign_terms' => 'assign_product_terms',
                    ],
                ])
            );
        }

        delete_transient('wc_attribute_taxonomies');

        return $taxonomy;
    }

    private function save_variations($product, $post_data) {
        $attributes_json = isset($post_data['attributes']) ? wp_unslash($post_data['attributes']) : '[]';
        $variations_json = isset($post_data['variations']) ? wp_unslash($post_data['variations']) : '[]';

        $attributes_data = json_decode($attributes_json, true);
        $variations_data = json_decode($variations_json, true);

        if (!is_array($attributes_data) || !is_array($variations_data)) {
            return;
        }

        // Hard ceiling on the number of variations processed in a single save.
        // Real catalogs stay far below this; an abnormal/tampered payload would
        // otherwise run an unbounded per-variation save() loop (soft DoS). We
        // bail before mutating anything so a rejected payload leaves the product
        // untouched. Raise via filter for genuinely huge catalogs.
        $max_variations = (int) apply_filters('brikpanel_max_variations', 1000);
        if ($max_variations > 0 && count($variations_data) > $max_variations) {
            return;
        }

        // Determine the non-variation (spec) attributes for the merged save:
        //   - If the new "Product attributes" section was posted, use its
        //     contents (the user is editing specs explicitly).
        //   - Otherwise preserve whatever non-variation attributes WC already
        //     has so we don't silently drop them when the section is hidden.
        $posted_non_variation = $this->parse_non_variation_attributes_input($post_data, $product->get_id());
        if ($posted_non_variation !== null) {
            $existing_non_variation = $posted_non_variation;
        } else {
            $existing_non_variation = [];
            foreach ($product->get_attributes() as $existing_attr) {
                if (!$existing_attr->get_variation()) {
                    $existing_non_variation[] = $existing_attr;
                }
            }
        }

        // Build WC attributes from submitted data. Order = insertion index =
        // position, so the sequence the user arranged in the UI is preserved.
        $wc_attributes = [];
        $position = 0;
        // De-dupe variation attribute rows by lowercase taxonomy/name so a
        // malformed payload (or two rows that slugify the same) can't add the
        // same axis twice and clobber its own term assignment.
        $seen_var_names = [];

        // Status gate for Color/Size promotion. We never create global
        // attribute taxonomies or terms while saving a draft — only when the
        // product is going live. `password` is a virtual status that the
        // outer handler converts to publish + post_password (see ~line 2233).
        $current_status = sanitize_key($post_data['status'] ?? 'draft');
        $promote_size_color = in_array($current_status, ['publish', 'private', 'password'], true);

        // Maps the variation's old custom slug (e.g. `color`) to the new
        // taxonomy (`pa_color`) once an attribute is promoted. We rewrite
        // variation attribute keys with this map below so existing variations
        // re-edited from the editor stay linked to the right attribute.
        $slug_remap = [];

        foreach ($attributes_data as $attr_data) {
            $name   = sanitize_text_field($attr_data['name'] ?? '');
            $values = array_map('sanitize_text_field', (array) ($attr_data['values'] ?? []));
            $taxonomy = sanitize_text_field($attr_data['taxonomy'] ?? '');

            if (empty($name) || empty($values)) continue;

            // Skip a second row for the same axis. Keyed by taxonomy when set,
            // otherwise by the slugified name so "Size" and "Size " collapse.
            $dedupe_key = $taxonomy !== '' ? strtolower($taxonomy) : sanitize_title($name);
            if ($dedupe_key === '' || isset($seen_var_names[$dedupe_key])) continue;
            $seen_var_names[$dedupe_key] = true;

            // Promote custom Color/Size attributes to real global taxonomies
            // when the product is going live. Detection is name-based using
            // a small EN+TR synonym map, mirroring the Beden+Renk template.
            // Drafts skip promotion entirely so half-baked attributes don't
            // leak into the site-wide attribute list.
            if (empty($taxonomy) && $promote_size_color) {
                $role = $this->detect_size_color_role($name);
                if ($role !== '') {
                    $matched = $this->find_global_taxonomy_for_role($role);
                    if ($matched === '') {
                        $matched = $this->create_global_size_color_taxonomy($role, $name);
                    }
                    if ($matched !== '' && taxonomy_exists($matched)) {
                        $old_slug = sanitize_title($name);
                        if ($old_slug !== '' && $old_slug !== $matched) {
                            $slug_remap[$old_slug] = $matched;
                        }
                        $taxonomy = $matched;
                    }
                }
            }

            $attribute = new WC_Product_Attribute();

            if ($taxonomy && taxonomy_exists($taxonomy)) {
                // Global taxonomy attribute — resolve term names to term IDs
                // and wire up id/name so WC stores it as a real taxonomy
                // attribute rather than silently degrading to a custom one.
                $attribute_id = (int) wc_attribute_taxonomy_id_by_name(str_replace('pa_', '', $taxonomy));
                $term_ids = [];
                foreach ($values as $term_name) {
                    $tid = $this->resolve_or_create_term($term_name, $taxonomy);
                    if ($tid) {
                        $term_ids[] = $tid;
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

        // Drop term relationships for any pa_* taxonomy the user removed from
        // the attribute set (both variation axes and preserved specs are in
        // $wc_attributes), so a deleted taxonomy attribute no longer leaves the
        // product hanging in that attribute's archive/filter queries.
        $kept_taxonomies = [];
        foreach ($wc_attributes as $kept_attr) {
            if ($kept_attr->is_taxonomy()) {
                $kept_taxonomies[] = $kept_attr->get_name();
            }
        }
        $this->clear_removed_attribute_terms($product->get_id(), $kept_taxonomies);

        // Parent product type flags. We propagate Virtual + Downloadable to
        // every variation below so the storefront (which reads per-variation
        // flags for shipping / download delivery) stays in sync with the
        // editor's parent-level toggle.
        $parent_is_downloadable = !empty($post_data['is_downloadable']);
        $parent_is_virtual      = !empty($post_data['is_virtual']) || $parent_is_downloadable;
        $parent_download_objects = [];
        if ($parent_is_downloadable) {
            $downloads_json = isset($post_data['downloads']) ? wp_unslash($post_data['downloads']) : '[]';
            $downloads_data = json_decode($downloads_json, true);
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
                    $parent_download_objects[] = $download;
                }
            }
        }

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
                    // Promoted custom attributes change key from `color` to
                    // `pa_color` (etc.) — JS sent the old slug, rewrite it
                    // here so the variation stays attached to the parent.
                    if (isset($slug_remap[$key])) {
                        $key = $slug_remap[$key];
                    }
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

            // Stock. Each variation carries its own "Track" checkbox — the
            // explicit source of truth for manage_stock, mirroring the
            // simple-product toggle. We no longer force every variation to
            // manage stock.
            $var_manage = !empty($var_data['manage_stock']);
            $var_stock = isset($var_data['stock_quantity']) && $var_data['stock_quantity'] !== '' ? intval($var_data['stock_quantity']) : 0;
            $var_stock_status = sanitize_key($var_data['stock_status'] ?? 'instock');
            if (!in_array($var_stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
                $var_stock_status = 'instock';
            }
            $var_backorders_raw = isset($var_data['backorders']) ? sanitize_key($var_data['backorders']) : '';
            if ($var_manage) {
                // Tracking on: quantity + backorders, status derived by WC.
                $var_backorders_value = in_array($var_backorders_raw, ['yes', 'notify'], true) ? $var_backorders_raw : 'no';
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($var_stock);
                $variation->set_backorders($var_backorders_value);
            } else {
                // Tracking off: direct in/out/backorder status. Leave the
                // stored quantity untouched so re-enabling tracking later
                // restores it (matches WooCommerce's native behavior).
                $var_backorders_value = ($var_stock_status === 'onbackorder')
                    ? (in_array($var_backorders_raw, ['yes', 'notify'], true) ? $var_backorders_raw : 'yes')
                    : 'no';
                $variation->set_manage_stock(false);
                $variation->set_stock_status($var_stock_status);
                $variation->set_backorders($var_backorders_value);
            }

            // SKU
            $var_sku = sanitize_text_field($var_data['sku'] ?? '');
            try {
                $variation->set_sku($var_sku);
            } catch (\Exception $e) {
                // Ignore duplicate SKU
            }

            // Global Unique ID (GTIN/UPC/EAN/ISBN) per variation. Opt-in
            // section: only write when the key was submitted so leaving the
            // section disabled never wipes existing variation barcodes.
            if (array_key_exists('global_unique_id', $var_data)) {
                $var_gtin = sanitize_text_field($var_data['global_unique_id']);
                try {
                    $variation->set_global_unique_id($var_gtin);
                } catch (\Exception $e) {
                    // Ignore duplicate / invalid value
                }
            }

            // Per-variation tax class. Opt-in: only write when submitted.
            // The literal `parent` means "inherit the parent's tax class".
            if (array_key_exists('tax_class', $var_data)) {
                $variation->set_tax_class(sanitize_text_field($var_data['tax_class']));
            }

            // Per-variation shipping class. Opt-in: only write when submitted.
            // Empty slug → id 0, which makes the variation inherit the parent
            // ("Same as parent" in the UI) — WC core behaviour.
            if (array_key_exists('shipping_class', $var_data)) {
                $vsc_slug = sanitize_title($var_data['shipping_class']);
                $vsc_term = $vsc_slug !== '' ? get_term_by('slug', $vsc_slug, 'product_shipping_class') : false;
                $variation->set_shipping_class_id($vsc_term ? (int) $vsc_term->term_id : 0);
            }

            // COGS per variation — only touched when the COGS column rendered
            // for this save (JS omits the key entirely when the section is
            // hidden in settings). Otherwise toggling the section off would
            // silently wipe every variation's cost on the parent's next save.
            if ( array_key_exists( 'cogs_value', $var_data ) ) {
                $var_cogs_raw     = sanitize_text_field( $var_data['cogs_value'] );
                $var_cogs_decimal = $var_cogs_raw !== '' ? wc_format_decimal( $var_cogs_raw ) : '';
                if ( $var_cogs_decimal !== '' ) {
                    update_post_meta( $variation->get_id(), '_brikpanel_cogs', $var_cogs_decimal );
                } else {
                    delete_post_meta( $variation->get_id(), '_brikpanel_cogs' );
                }
                if ( method_exists( $variation, 'set_cogs_value' ) ) {
                    $variation->set_cogs_value( $var_cogs_decimal !== '' ? $var_cogs_decimal : null );
                }
            }

            // Per-variation vendor override. 0 (or unset) means "inherit
            // parent" — persist_meta() handles the delete branch.
            if ( class_exists( 'Brikpanel_Vendor_Product_Editor' ) && Brikpanel_Vendor_Product_Editor::vendor_field_enabled() ) {
                Brikpanel_Vendor_Product_Editor::persist_meta(
                    $variation->get_id(),
                    isset( $var_data['vendor_id'] ) ? (int) $var_data['vendor_id'] : 0,
                    isset( $var_data['vendor_sku'] ) ? (string) $var_data['vendor_sku'] : ''
                );
            }

            // Propagate parent's Virtual / Downloadable flags to each variation.
            // The storefront reads per-variation flags (shipping calc, download
            // delivery) so the parent prop alone is not enough — without this,
            // a variable "yoga session" still asks for shipping at checkout.
            $variation->set_virtual($parent_is_virtual);
            $variation->set_downloadable($parent_is_downloadable);
            if ($parent_is_downloadable) {
                $variation->set_downloads($parent_download_objects);
                $variation->set_download_limit(-1);
                $variation->set_download_expiry(-1);
            } else {
                $variation->set_downloads([]);
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
            // When the variation gallery toggle is off, leave the meta untouched so any
            // previously-saved extra images are preserved (and reappear if re-enabled)
            // — the client only sends the single thumbnail in that mode anyway.
            $var_id_for_meta = $variation->get_id();
            if ($var_id_for_meta && get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes') {
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
            // Only when tracking is OFF for this variation; when it is on we
            // intentionally let WC derive the status from quantity.
            $vid = $variation->get_id();
            if (!$var_manage && $vid && get_post_meta($vid, '_stock_status', true) !== $var_stock_status) {
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

        // Force the `product_type` term back to `variable`. WC's
        // update_version_and_type() writes the term from $product->get_type()
        // during parent save() above — when the parent was just promoted
        // from simple (the editor flips it inside ajax_save_product, not the
        // user re-loading the form), an in-flight save_post listener can
        // reload the product through wc_get_product() (factory → cached
        // `simple` type) and the next ->save() flips the term back to
        // simple. The variations stay on disk but the storefront treats the
        // parent as a simple product and no variation selector renders.
        // Clear the WC type cache first so subsequent reads pick up the
        // restored term, then pass the in-memory instance into ::sync() so
        // it doesn't re-hit the (still-poisoned) factory cache itself.
        $product_id = $product->get_id();
        wp_set_object_terms($product_id, 'variable', 'product_type', false);
        wp_cache_delete(
            WC_Cache_Helper::get_cache_prefix('product_' . $product_id) . '_type_' . $product_id,
            'products'
        );
        clean_post_cache($product_id);

        // A variable product never manages stock at the parent level in
        // BrikPanel — the inventory card is hidden for variable products and
        // every variation carries its own explicit stock (managed quantity or
        // a direct in/out status). A leftover `_manage_stock = yes` on the
        // parent (from a prior simple→variable conversion, the native WC
        // editor, or an import) makes WC_Product_Variable::sync() keep the
        // parent's *own* stock status instead of deriving it from the
        // children — so a parent stuck at qty 0 / outofstock would report the
        // whole product out of stock even when its variations are in stock.
        // Clear parent-level management before the sync so the status is
        // always derived from the variations.
        $variable_for_sync = $product instanceof WC_Product_Variable
            ? $product
            : new WC_Product_Variable($product_id);
        if ($variable_for_sync->get_manage_stock()) {
            $variable_for_sync->set_manage_stock(false);
            $variable_for_sync->save();
        }
        WC_Product_Variable::sync($variable_for_sync);
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
    // AJAX: ADD BRAND
    // =========================================================================

    public function ajax_add_brand() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        $brand_taxonomy = brikpanel_pe_brand_taxonomy();
        if ($brand_taxonomy === '') {
            wp_send_json_error(['message' => __('Brands are not available on this site.', 'brikpanel')]);
        }

        $tax_obj    = get_taxonomy($brand_taxonomy);
        $manage_cap = $tax_obj ? ($tax_obj->cap->manage_terms ?? 'manage_product_terms') : 'manage_product_terms';
        if (!current_user_can($manage_cap)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $parent = is_taxonomy_hierarchical($brand_taxonomy) ? intval($_POST['parent'] ?? 0) : 0;

        if (empty($name)) {
            wp_send_json_error(['message' => __('Brand name is required.', 'brikpanel')]);
        }

        $result = wp_insert_term($name, $brand_taxonomy, ['parent' => $parent]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $term_id = intval($result['term_id']);

        // Re-render the full checklist + parent dropdown so the client DOM
        // mirrors the server render exactly. The new term is auto-checked and
        // the client's current selection is preserved across the swap.
        $selected_ids_raw = isset($_POST['selected_ids']) ? (array) $_POST['selected_ids'] : [];
        $selected_ids = array_values(array_unique(array_filter(array_map('intval', $selected_ids_raw))));
        $selected_ids[] = $term_id;

        $brands = get_terms(['taxonomy' => $brand_taxonomy, 'hide_empty' => false]);
        if (is_wp_error($brands)) {
            $brands = [];
        }

        ob_start();
        $this->render_category_checklist($brands, $selected_ids, 0, 0, 'brand_ids[]');
        $checklist_html = ob_get_clean();

        $options_html = '';
        if (is_taxonomy_hierarchical($brand_taxonomy)) {
            ob_start();
            $this->render_category_parent_options($brands);
            $options_html = ob_get_clean();
        }

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

        // Carry over brand + any other custom taxonomy term relationships that
        // the WC_Product object does not store (and therefore clone+save drops).
        $this->copy_product_taxonomies($product_id, $duplicate->get_id());

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
            'cb'                    => $columns['cb'] ?? '<input type="checkbox" />',
            'thumb'                 => $columns['thumb'] ?? __('Image', 'brikpanel'),
            'name'                  => $columns['name'] ?? __('Product', 'brikpanel'),
            'sku'                   => $columns['sku'] ?? __('SKU', 'brikpanel'),
            'bpe_global_unique_id'  => __('GTIN', 'brikpanel'),
            'price'                 => $columns['price'] ?? __('Price', 'brikpanel'),
            'bpe_stock'             => __('Stock', 'brikpanel'),
            'product_cat'           => $columns['product_cat'] ?? __('Category', 'brikpanel'),
            'product_status'        => __('Status', 'brikpanel'),
        ];
    }

    /**
     * Hide the GTIN column by default. It stays available in the list-table
     * "Screen Options" panel so users can opt in, and WordPress persists that
     * preference per user.
     *
     * @param string[]  $hidden Column IDs hidden by default.
     * @param WP_Screen $screen Current screen.
     * @return string[]
     */
    public function default_hidden_columns($hidden, $screen) {
        if ($screen && isset($screen->id) && $screen->id === 'edit-product') {
            $hidden[] = 'bpe_global_unique_id';
        }
        return $hidden;
    }

    public function render_column($column, $post_id) {
        if ($column === 'product_status') {
            $status = get_post_status($post_id);
            $label  = $status === 'publish' ? __('Published', 'brikpanel') : __('Draft', 'brikpanel');
            $class  = $status === 'publish' ? 'published' : 'draft';
            echo '<span class="brikpanel-pe-list-status brikpanel-pe-list-status--' . esc_attr($class) . '">' . esc_html($label) . '</span>';
        }

        if ($column === 'bpe_global_unique_id') {
            $product = wc_get_product($post_id);
            if (!$product) {
                echo '<span class="brikpanel-pe-gid-empty">&mdash;</span>';
                return;
            }

            // Parent-level value covers simple products and variable products
            // whose GTIN is set on the parent.
            $value = (string) $product->get_global_unique_id();

            if ($value === '' && $product->is_type('variable')) {
                // GTIN is usually stored per variation. Collect the distinct
                // non-empty values so the column stays meaningful for
                // variable products too.
                $found = [];
                foreach ($product->get_children() as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation) {
                        continue;
                    }
                    $vid = trim((string) $variation->get_global_unique_id());
                    if ($vid !== '') {
                        $found[$vid] = true;
                    }
                }
                $found = array_keys($found);

                if (count($found) === 1) {
                    echo esc_html($found[0]);
                } elseif (count($found) > 1) {
                    printf(
                        '<span class="brikpanel-pe-gid-multi" title="%s">%s</span>',
                        esc_attr(implode(', ', $found)),
                        esc_html(sprintf(
                            /* translators: %d: number of distinct variation GTINs. */
                            _n('%d GTIN', '%d GTINs', count($found), 'brikpanel'),
                            count($found)
                        ))
                    );
                } else {
                    echo '<span class="brikpanel-pe-gid-empty">&mdash;</span>';
                }
                return;
            }

            if ($value !== '') {
                echo esc_html($value);
            } else {
                echo '<span class="brikpanel-pe-gid-empty">&mdash;</span>';
            }
            return;
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
