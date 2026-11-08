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

    /**
     * Upper bound on how many attribute-term `order` rows a single product save
     * may write. Ordering an attribute is a site-wide operation, and the first
     * save of a never-ordered attribute has to number its whole term list; on a
     * taxonomy with thousands of terms that would stall the save, so
     * sync_attribute_term_order() gives up instead. Variation axes ("Size",
     * "Pack Size", "Colour") sit far below this.
     */
    const MAX_TERM_ORDER_WRITES = 500;

    /**
     * The product the editor page is rendering its "Additional product data"
     * card for, set only while capture_wc_product_data_fields() runs.
     *
     * Section discovery (collect_wc_product_data_sections(), reached through
     * augment_sections_auto()) used to fire the product-data hooks against a
     * probe product first, and the card render then fired them a second time
     * for the real one. A plugin that prints part of its panel through
     * require_once / include_once (Measurement Price Calculator's Pricing
     * Table) only produces that part on the FIRST fire, so the card showed an
     * empty table and the next save wiped every pricing rule. While this is
     * set, discovery first fires the hooks for the edited product (the render
     * reuses that output), then probes exactly as before.
     *
     * @var WC_Product|null
     */
    private static $render_product = null;

    /**
     * Captured hook output for $render_product, keyed by hook + shape + id.
     *
     * @var array<string, string|string[]>
     */
    private static $render_hook_memo = [];

    /**
     * What the last capture_wc_product_data_fields() call put in the card:
     * whether any section is on screen, the ids of every third-party panel
     * rendered (shown, kept hidden or folded), the picked ones that arrived with
     * no field to post (a script on WooCommerce's screen fills them), and the
     * sections for the folded "Fields from your other plugins" box with how many
     * of them this product's type shows.
     *
     * @var array{any_visible: bool, panels: string[], empty_panels: string[], fold_html: string, fold_visible: int, fold_hold: bool, fold_found: bool}
     */
    private static $capture_info = ['any_visible' => false, 'panels' => [], 'empty_panels' => [], 'fold_html' => '', 'fold_visible' => 0, 'fold_hold' => false, 'fold_found' => false];

    /**
     * User-facing, non-fatal warnings collected during a single save request
     * (e.g. a SKU or GTIN that WooCommerce rejected as duplicate/invalid).
     * Surfaced in the AJAX response so the editor can tell the merchant *why*
     * a value did not persist instead of showing a bare "Product saved!".
     *
     * @var string[]
     */
    private $save_warnings = [];

    /**
     * Global attribute taxonomies this save request created from scratch
     * (see promote_attribute_to_global()). Non-empty only when the merchant
     * typed a brand-new attribute name and it was promoted, which is what
     * tells the AJAX response to hand back a refreshed global-attribute list.
     *
     * @var string[]
     */
    private $promoted_attributes = [];

    /**
     * How many global attribute taxonomies this save request has created.
     * Capped so a malformed or tampered payload cannot spawn a taxonomy per
     * attribute row in a single request.
     *
     * @var int
     */
    private $promoted_this_save = 0;

    /**
     * Axis keys the client posted, mapped to the taxonomy they were promoted
     * to during this save (e.g. `kleur` => `pa_kleur`). Handed back in the AJAX
     * response so the editor can re-point its attribute rows without a reload;
     * pairing on the client's OWN key is exact, where pairing on the attribute
     * label is not (binding to an existing global can change the label).
     *
     * @var array<string,string>
     */
    private $promoted_axis_map = [];

    /**
     * Every axis key the client posted, mapped to the key the axis was saved
     * under (WooCommerce's own, brikpanel_attr_key()). Sent back as
     * `axis_keys` so the editor re-keys rows whose key it could only guess:
     * a name typed outside ASCII, punctuation that sanitize_title() drops,
     * a transliteration plugin. Pairs on the client's own key, like
     * promoted_axis_map.
     *
     * @var array<string,string>
     */
    private $axis_key_map = [];

    /**
     * The status this save writes, after the publish permission and the
     * schedule rules ('' outside a save). save_variations() reads it: a
     * "publish" from someone who may only send products in for review is
     * saved as pending and must not create site-wide attributes.
     *
     * @var string
     */
    private $save_status = '';

    /**
     * The product's own Virtual flag as stored before this save wrote anything
     * (null outside a save, or for a product that had no row yet). A new
     * variation takes it when the "Digital product" section is hidden and no
     * existing variation stays to follow (see save_variations()).
     *
     * @var bool|null
     */
    private $save_parent_virtual = null;

    /**
     * The options other plugins add next to Virtual and Downloadable, keyed by
     * product id, read once per request (see plugin_type_options()).
     *
     * @var array<int, array<string, array>>
     */
    private static $plugin_type_options_memo = [];

    /**
     * Those options as this save received them, id => on, for the ones the
     * editor showed (empty outside a save). Checked against the stored values
     * once every save handler has run (plugin_type_options_after_save()).
     *
     * @var array<string, bool>
     */
    private $plugin_opts_sent = [];

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
        add_filter('redirect_post_location', [$this, 'keep_native_editor_after_save'], 10, 2);
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
        // Bulk "Clear all" for the variation list. Deletes immediately instead
        // of waiting for a save, so the merchant can start a matrix over
        // without clicking through every row.
        add_action('wp_ajax_brikpanel_pe_clear_variations', [$this, 'ajax_clear_variations']);
        // SEO analysis re-run. The legacy `…_surerank_analyze` action name is
        // kept for back-compat (the client still posts it); the handler now
        // renders whichever unified analyzer is active (SureRank or SmartCrawl).
        add_action('wp_ajax_brikpanel_pe_surerank_analyze', [$this, 'ajax_seo_analyze']);
        add_action('wp_ajax_brikpanel_pe_seo_analyze', [$this, 'ajax_seo_analyze']);
        add_action('wp_ajax_brikpanel_pe_enable_thirdparty_fields', [$this, 'ajax_enable_thirdparty_fields']);
        add_action('wp_ajax_brikpanel_pe_disable_thirdparty_fields', [$this, 'ajax_disable_thirdparty_fields']);
        add_action('wp_ajax_brikpanel_pe_fold_state', [$this, 'ajax_fold_state']);
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
    /**
     * Turn on "Auto-show fields other plugins add" from the "Always show these
     * fields" link at the end of the folded "Fields from your other plugins"
     * box. Writing the same option the settings screen writes keeps one source
     * of truth, and turning it back off is the existing, documented undo.
     *
     * `manage_woocommerce` rather than `edit_products`: this changes a
     * store-wide setting, so it needs the capability that owns the settings
     * screen, not the one that owns a product.
     */
    public function ajax_enable_thirdparty_fields() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }
        // Restore the WHOLE state the hide button parked, not just the picked
        // list: a store showing its fields through the auto toggle and a store
        // showing a hand-picked list are two different "on" states, and putting
        // back only one of them turns "show" into a silent settings change.
        $parked = get_option(self::TABS_PARKED_OPTION, []);
        $auto     = (is_array($parked) && isset($parked['auto'])) ? (string) $parked['auto'] : '';
        $selected = (is_array($parked) && isset($parked['selected'])) ? (array) $parked['selected'] : [];

        // A parked state that would show nothing is not worth restoring (it
        // cannot normally happen — hiding is only reachable while something is
        // on — but a hand-edited option must not leave the button inert).
        if ('yes' === $auto || !empty($selected)) {
            update_option('brikpanel_pe_wc_tabs_auto', 'yes' === $auto ? 'yes' : 'no');
            update_option('brikpanel_pe_wc_tabs_selected', $selected);
        } else {
            update_option('brikpanel_pe_wc_tabs_auto', 'yes');
        }
        delete_option(self::TABS_PARKED_OPTION);
        wp_send_json_success();
    }

    /**
     * The undo for the above.
     *
     * The state is parked, not thrown away: hiding must be as reversible as
     * showing, and a hand-picked list would otherwise be gone the moment
     * someone pressed a button labelled "hide". Parked unconditionally on every
     * hide, so it always describes the most recent "on" state rather than an
     * older one the merchant has since changed in the settings screen.
     */
    public function ajax_disable_thirdparty_fields() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }
        $auto     = (string) get_option('brikpanel_pe_wc_tabs_auto', 'no');
        $selected = (array) get_option('brikpanel_pe_wc_tabs_selected', []);

        // Only park a state that actually shows something. Hiding what is
        // already hidden is unreachable from the UI (the button is gone, and
        // it is disabled while its request is in flight) but a repeated call
        // must not overwrite a good parked state with an empty one and cost
        // the merchant the list they had.
        if ('yes' === $auto || !empty($selected)) {
            // Not autoloaded: internal state that only these two endpoints
            // read, and an autoloaded row is paid for on every front-end hit.
            update_option(self::TABS_PARKED_OPTION, [
                'auto'     => $auto,
                'selected' => $selected,
            ], false);
        }

        update_option('brikpanel_pe_wc_tabs_selected', []);
        update_option('brikpanel_pe_wc_tabs_auto', 'no');
        wp_send_json_success();
    }

    /**
     * Remembers which folding cards (the SEO card, another plugin's box) a
     * person opened. Opened once, a card stays open for that person on every
     * product; closed again, it folds again. Per person, in user meta: one
     * shop manager's habits do not decide another's screen.
     */
    public function ajax_fold_state() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }
        $box = isset($_POST['box']) ? sanitize_text_field(wp_unslash($_POST['box'])) : '';
        if (!self::is_fold_box_id($box)) {
            wp_send_json_error();
        }
        $open = isset($_POST['open']) && '1' === (string) wp_unslash($_POST['open']);
        $ids  = array_values(array_diff(array_keys(self::open_boxes(true)), [$box]));
        if ($open) {
            $ids[] = $box;
        }
        // The newest hundred: a store does not have more boxes than that, and
        // a box from a plugin removed since must not keep the list growing.
        update_user_meta(get_current_user_id(), self::OPEN_BOXES_META, array_slice($ids, -100));
        wp_send_json_success();
    }

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
     * Product search for the Linked products (upsell/cross-sell) picker.
     * Returns matches as {id, text} pairs.
     *
     * Runs through WooCommerce's OWN product search data store — the same call
     * WC_AJAX::json_search_products makes for the native "Linked Products" tab —
     * so this picker returns exactly what the native WooCommerce admin returns.
     *
     * The previous implementation used wc_get_products( 's' => … ), which maps
     * onto WP_Query's post search: title, excerpt and content only. That silently
     * dropped every SKU and GTIN match (searching a SKU like "502" found nothing)
     * and never surfaced variations, so the picker returned far fewer results
     * than the native field for the same term.
     *
     * Matching WC also means inheriting its filters: `woocommerce_json_search_limit`
     * for the result count and `woocommerce_search_products_post_statuses` for
     * which statuses are searchable.
     */
    public function ajax_search_products() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')], 403);
        }
        $term    = isset($_GET['q']) ? wc_clean(wp_unslash($_GET['q'])) : '';
        $exclude = isset($_GET['exclude']) ? absint($_GET['exclude']) : 0;
        if (strlen($term) < 2) {
            wp_send_json_success(['results' => []]);
        }

        // The "Products in this group" picker asks with `children=1`: like
        // WooCommerce's own grouped products field it offers no variations, and
        // it leaves out other groups too (a group inside a group only shows a
        // "View products" button on the shop page).
        $for_group = !empty($_GET['children']);

        /** This filter is documented in WooCommerce: includes/class-wc-ajax.php */
        $limit = absint(apply_filters('woocommerce_json_search_limit', 30));

        // `true` = include variations, mirroring the native upsell/cross-sell
        // fields, which use the woocommerce_json_search_products_and_variations
        // endpoint so a single variation can be linked as well.
        $ids = WC_Data_Store::load('product')->search_products(
            $term,
            '',
            !$for_group,
            false,
            $limit,
            [],
            $exclude ? [$exclude] : []
        );

        $results = [];
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if (!$p) continue;
            if ($for_group && $p->is_type(['variation', 'grouped'])) {
                continue;
            }
            // Same readability gate WC applies to its own search results, so a
            // shop manager never sees a product they may not read.
            if (function_exists('wc_products_array_filter_readable') && !wc_products_array_filter_readable($p)) {
                continue;
            }
            $sku = $p->get_sku();
            $results[] = [
                'id'   => (int) $pid,
                'text' => brikpanel_plain_label($p->get_name()) . ($sku !== '' ? ' (' . $sku . ')' : ''),
            ];
        }
        wp_send_json_success(['results' => $results]);
    }

    // =========================================================================
    // ADMIN PAGE
    // =========================================================================

    /**
     * Registers the editor under WordPress's Products menu, as a row only access
     * plugins see (includes/brikpanel-screen-menu.php). The editor always runs
     * under its old screen name, admin_page_brikpanel-product-editor: the SEO
     * bootstrap in brikpanel.php makes WordPress look it up as post.php, and the
     * helper keeps that name registered.
     */
    public function register_page() {
        $hooks = function_exists('brikpanel_add_screen_page')
            ? brikpanel_add_screen_page('edit.php?post_type=product', __('Product Editor', 'brikpanel'), 'edit_products', 'brikpanel-product-editor', [$this, 'render_page'])
            : array_filter([add_submenu_page('', __('Product Editor', 'brikpanel'), '', 'edit_products', 'brikpanel-product-editor', [$this, 'render_page'])]);

        // Set page title before admin-header.php runs (prevents strip_tags null warning)
        foreach ($hooks as $hook) {
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
                    // "New products are virtual" (Products settings). The editor
                    // reads the Virtual switch from the product, so the draft
                    // itself starts virtual; switching it off before the first
                    // save makes the product physical as usual.
                    if ($this->new_product_starts_virtual()) {
                        update_post_meta((int) $draft->ID, '_virtual', 'yes');
                    }
                    wp_safe_redirect(admin_url('admin.php?page=brikpanel-product-editor&product_id=' . (int) $draft->ID));
                    exit;
                }
            }
            return;
        }

        // Per-request escape hatch. `&brikpanel=0` on a native product URL opens
        // the WooCommerce editor this once; nothing is stored, so the next
        // product still opens in BrikPanel. It is what the "Open in WooCommerce"
        // note links to (native_edit_url()), and without it that note has no
        // reachable destination at all on a store where everyone works in the
        // BrikPanel interface. No nonce: this only chooses which screen renders,
        // changes no state, and every reachable target is already gated by the
        // edit_products check above. Placed AFTER the BrikPanel-page branch so
        // the flag can never suppress the auto-draft creation that "Add new
        // product" depends on.
        if (self::native_editor_requested()) {
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
     * URL that opens a product in the native WooCommerce editor, once.
     *
     * Two things stand between a merchant and that screen: modify_edit_link()
     * above rewrites every product edit link to the BrikPanel editor, and
     * handle_redirects() sends the raw post.php URL back here anyway. The
     * `brikpanel=0` flag is honoured by handle_redirects() for that single
     * request only, nothing is stored, so the next product still opens in
     * BrikPanel, which is what a merchant who lives in this interface expects.
     *
     * @param int $product_id Product post ID.
     * @return string Admin URL.
     */
    private static function native_edit_url($product_id) {
        return admin_url('post.php?post=' . (int) $product_id . '&action=edit&brikpanel=0');
    }

    /**
     * The small line icon of a product type button (and the "More" arrow).
     *
     * @param string $type simple, variable, grouped, external or more.
     */
    private static function type_choice_icon($type) {
        echo '<svg class="brikpanel-pe-type-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
        switch ($type) {
            case 'simple':
                echo '<path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>';
                break;
            case 'variable':
                echo '<path d="M12 3 2 8l10 5 10-5-10-5Z"/><path d="m2 16 10 5 10-5"/><path d="m2 12 10 5 10-5"/>';
                break;
            case 'grouped':
                echo '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>';
                break;
            case 'external':
                echo '<path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>';
                break;
            default:
                echo '<path d="m6 9 6 6 6-6"/>';
        }
        echo '</svg>';
    }

    /**
     * The products a group may hold, from the editor's JSON list.
     *
     * Keeps the order the merchant set and drops what WooCommerce's own
     * picker never offers: the group itself, variations, other groups,
     * trashed or missing products and repeats.
     *
     * @param mixed $raw      JSON array of product IDs, as posted.
     * @param int   $group_id The group being saved.
     * @return int[]
     */
    private static function sanitize_group_children($raw, $group_id) {
        $ids = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $id = is_scalar($id) ? absint($id) : 0;
            if (!$id || $id === (int) $group_id || isset($out[$id])) {
                continue;
            }
            $child = wc_get_product($id);
            if (!$child || $child->is_type(['variation', 'grouped']) || 'trash' === $child->get_status()) {
                continue;
            }
            $out[$id] = $id;
            if (count($out) >= 200) {
                break;
            }
        }
        return array_values($out);
    }

    /**
     * Whether this request explicitly asked for the native editor.
     *
     * `is_scalar()` before any string function: `?brikpanel[]=0` hands an array
     * to sanitize_key(), which is a TypeError on PHP 8 and would take down
     * admin_init for the whole dashboard.
     *
     * @return bool
     */
    private static function native_editor_requested() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selection.
        $flag = isset($_GET['brikpanel']) ? wp_unslash($_GET['brikpanel']) : null;
        return is_scalar($flag) && sanitize_key((string) $flag) === '0';
    }

    /**
     * Keep the native editor after a save made from it.
     *
     * Once the classic editor saves, wp-admin/post.php builds its redirect from
     * get_edit_post_link(), which modify_edit_link() above rewrites to the
     * BrikPanel editor. So a merchant who deliberately opened the WooCommerce
     * editor gets thrown out of it at the exact moment they press Update, and
     * the type-specific settings they went there for scroll past unconfirmed.
     *
     * The submitted form carries `_wp_http_referer`, which still holds the
     * `brikpanel=0` flag, so when it is there we rebuild the native target and
     * keep WordPress's own notice argument (`message=1`…) intact. Drop the flag
     * from the address bar and the next load returns to BrikPanel as usual.
     *
     * @param string $location Redirect target.
     * @param int    $post_id  Post being saved.
     * @return string
     */
    public function keep_native_editor_after_save($location, $post_id) {
        if (get_post_type($post_id) !== 'product') {
            return $location;
        }
        $referer = wp_get_referer();
        if (!$referer) {
            return $location;
        }
        $ref_query = (string) wp_parse_url($referer, PHP_URL_QUERY);
        if ($ref_query === '') {
            return $location;
        }
        $ref_args = [];
        wp_parse_str($ref_query, $ref_args);
        $flag = isset($ref_args['brikpanel']) ? $ref_args['brikpanel'] : null;
        if (!is_scalar($flag) || sanitize_key((string) $flag) !== '0') {
            return $location;
        }

        // Carry over whatever WordPress put on the redirect (message, revision…)
        // except the routing args, which are what sent the user to BrikPanel.
        $carry = [];
        $query = (string) wp_parse_url($location, PHP_URL_QUERY);
        if ($query !== '') {
            wp_parse_str($query, $carry);
        }
        unset($carry['page'], $carry['product_id'], $carry['post'], $carry['action'], $carry['brikpanel']);

        $native = self::native_edit_url($post_id);
        return $carry ? add_query_arg($carry, $native) : $native;
    }

    /**
     * Suppress PHP 8.1 deprecation warnings from WordPress core on our page.
     * These come from wp-includes/functions.php passing null to strpos/str_replace
     * during menu traversal — a known WP core issue, not from our plugin.
     */
    public function add_body_class($classes) {
        $screen = get_current_screen();
        // Compared by its end: the screen sits under the Products menu and keeps
        // its old name too (includes/brikpanel-screen-menu.php).
        if ($screen && str_ends_with((string) $screen->id, '_page_brikpanel-product-editor')) {
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
        // The list itself, not WordPress's address that redirects to it: one
        // load less, and a phone's back slide knows the page it leads to
        // (brikpanel_print_page_transition_script()).
        $wp_list = admin_url('edit.php?post_type=product');
        $default = function_exists('brikpanel_module_url')
            ? brikpanel_module_url('brikpanel-products', [], $wp_list)
            : $wp_list;

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

        // Switched-off sections the folded "Hidden sections" box at the end
        // of the page carries (hidden_box_sections()). They are built like
        // the shown ones ($bpe_build) and printed in the box, never in the
        // section order; every other use of $visible stays as it was.
        $bpe_held  = $this->hidden_box_sections((int) $product_id);
        $bpe_build = array_merge($visible, array_keys($bpe_held));

        // WPML: a translation takes its prices from the original product, which
        // WooCommerce Multilingual copies to it. Its price boxes (and its
        // variations') open locked, with a line and a link to the original.
        $bpe_lock_original = ($product_id && function_exists('brikpanel_wpml_original_of')) ? brikpanel_wpml_original_of($product_id) : 0;
        $bpe_lock_note     = $bpe_lock_original ? brikpanel_wpml_price_lock_note($bpe_lock_original) : null;

        // Who owns the SEO surface on this page. Resolved once, up here,
        // because two places downstream must agree on the answer: the SEO card
        // and the third-party metabox pass that decides which `mb:` picks to
        // render. When they disagreed, an admin who hand-placed Rank Math's
        // metabox got BrikPanel's generic fields in the SEO card AND no Rank
        // Math anywhere, because the metabox pass stripped it as "already
        // shown above".
        $active_seo     = self::get_active_seo_plugin();
        $manual_mb_ids  = [];
        foreach ($visible as $vslug) {
            if (strpos($vslug, 'mb:') === 0) {
                $manual_mb_ids[] = substr($vslug, 3);
            }
        }
        // True when the admin explicitly placed the active plugin's own SEO
        // metabox in the "Visible editor sections" list. That pick wins: the
        // metabox renders at its chosen slot and the SEO card stands down
        // entirely, so there is exactly one SEO surface on the page.
        $seo_in_manual = false;
        if ($active_seo) {
            foreach ($active_seo['metabox_ids'] as $mid) {
                if (in_array($mid, $manual_mb_ids, true)) {
                    $seo_in_manual = true;
                    break;
                }
            }
        }

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
                $shipping_class_options[$sc_term->slug] = brikpanel_plain_name($sc_term->name);
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

        // Someone who may edit but not publish products gets what WordPress's
        // own editor gives them (post_submit_meta_box()): Draft and Pending
        // review plus the product's current live status, no publish date, and
        // "Submit for review" on the button. The save enforces the same rule.
        $can_publish = brikpanel_user_can_publish_products();

        // COGS: gated on the "Cost of goods" entry in the section visibility
        // picker. WC 9.5+ native COGS is still consumed under the hood when
        // available; otherwise the save handler falls back to the
        // BrikPanel-native _brikpanel_cogs meta key so profit tracking works
        // on any WooCommerce version. Hiding the section here also strips the
        // per-variation COGS column.
        $cogs_enabled    = in_array('cogs', $visible, true);
        $cogs_wc_native  = get_option('woocommerce_feature_cost_of_goods_sold_enabled') === 'yes';

        // Product type: every registered type (subscription, bundle and the
        // like from other plugins too), chosen under the product name. Always
        // on the page since 3.3.43; until then a setting hid it, and a store
        // without it could not make a grouped or external product here.
        $product_type_options = function_exists('brikpanel_editor_product_types')
            ? brikpanel_editor_product_types()
            : [];
        $product_type_selector_enabled = !empty($product_type_options);
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
        $global_attributes = $this->build_global_attributes_payload();

        // All existing product tags for autocomplete
        $all_tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'fields' => 'names']);
        if (is_wp_error($all_tags)) {
            $all_tags = [];
        }
        // Same form as the product's own tags below, so the autocomplete
        // dedupe compares like with like.
        $all_tags = array_map('brikpanel_term_ref', $all_tags);

        // Per-variation third-party fields (if any plugin added any, and admin
        // opted in via brikpanel_pe_wc_variation_sections). The HTML preserves
        // original `name="field[<loop>]"` attributes so the save handler can
        // re-post them under the same indices.
        $variation_extras = [];
        if (!empty($data['variations']) && $product && $product->is_type('variable')) {
            $variation_ids = array_map(function ($v) { return isset($v['id']) ? (int) $v['id'] : 0; }, $data['variations']);
            $variation_extras = $this->capture_wc_variation_fields($product, $variation_ids);
            /**
             * Filter the per-variation extras HTML for existing variations.
             * Runs regardless of which native sections are enabled so an
             * integration can surface its own per-variation fields
             * unconditionally. Keyed by variation ID; $variations is the
             * ordered ID list (position = save loop index).
             *
             * @param array<int,string> $variation_extras [variation_id => html]
             * @param WC_Product         $product          Parent variable product.
             * @param array              $variation_ids    Ordered variation IDs.
             * @param string             $context          'saved'.
             */
            $variation_extras = apply_filters('brikpanel_pe_variation_extras', $variation_extras, $product, $variation_ids, 'saved');
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

        // Scheduled publishing — on by default, toggleable in settings. Adds a
        // "Scheduled" entry to the status menu plus an inline date/time picker so a
        // product can go live automatically at a future moment (WordPress "future"
        // post status + WP-Cron). We also surface the option when the product is
        // ALREADY scheduled, so a merchant who disables the setting later can still
        // see and edit an existing schedule instead of it silently reading "Draft".
        $scheduling_on = (get_option('brikpanel_pe_enable_scheduling', 'yes') === 'yes')
            || ($data['status'] === 'future');

        // "Require a price to publish" (on by default). Off lets a store publish
        // a product with no price, which WooCommerce's own editor allows: the
        // product shows in the store but cannot be added to the cart. Drafts and
        // variations never needed a price; this only marks the simple price box.
        $require_price = get_option('brikpanel_pe_require_price', 'yes') === 'yes';

        // Backorder "Notify customer" sub-option — opt-in via settings.
        // When on, selecting "On backorder" reveals a radio group letting
        // the merchant pick between silent backorders and ones that flag
        // an order note for the customer (WC's `notify` value).
        $backorder_notify_on = get_option('brikpanel_pe_backorder_notify', 'no') === 'yes';

        // Product videos in the active theme's or plugin's storage, null when
        // none of them plays videos (see Brikpanel_Video). Read once, used by
        // the JS data and by the product video row in the images card.
        $bpe_video_payload = class_exists('Brikpanel_Video') ? Brikpanel_Video::payload_for_editor($product_id) : null;

        // JS data for existing product
        $js_data = wp_json_encode([
            'id'                => $product_id,
            'is_variable'       => $data['is_variable'],
            'variation_count'   => isset($data['variation_count']) ? (int) $data['variation_count'] : 0,
            'product_type'      => $product_type_current,
            // WooCommerce's name for the stored type ("Grouped product"): the
            // phone header's subtitle when no type dropdown is on the page.
            'type_label'        => function_exists('wc_get_product_types') ? (string) (wc_get_product_types()[$product_type_current] ?? '') : '',
            'type_selector'     => $product_type_selector_enabled,
            'attributes'        => $data['attributes'],
            'non_variation_attributes' => $data['non_variation_attributes'],
            'variations'        => $data['variations'],
            'gallery'           => $data['gallery'],
            'videos'            => $bpe_video_payload,
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
            'children'          => $data['children'],
            'variation_extras'  => $variation_extras,
            // Empty array when the toggle is off so the JS dropdown renderer
            // skips the column without extra checks.
            'vendor_field_enabled' => $bp_vendor_field_on,
            'vendor_options'       => $bp_vendor_field_on ? array_map(static fn($k, $v) => ['id' => (int) $k, 'name' => (string) $v], array_keys($bp_vendor_options), $bp_vendor_options) : [],
            'parent_vendor_id'     => $bp_vendor_field_on ? (int) $bp_current_vendor : 0,
            // The variations' grey hint when the cost card (which holds the
            // main product's code box) is switched off.
            'parent_vendor_sku'    => $bp_vendor_field_on ? $bp_current_v_sku : '',
            'backorder_notify'     => $backorder_notify_on,
            // Parent backorder value — only used when the sub-option is on.
            // For variable products the parent has no real backorder state,
            // so JS simply hides the parent radio in that case.
            'parent_backorders'    => (string) $data['backorders'],
            // WooCommerce's shared stock: the variations sell from the
            // product's one quantity. The variation rows read this, so it is
            // known even when the Inventory card is switched off in settings.
            'shared_stock'         => !empty($data['shared_stock']),
            // "Pack sizes": rows that sell from the shared stock show a box
            // for the units one sale takes (a 5-pack takes 5).
            'pack_sizes'           => !empty($data['pack_sizes']),
            // The product's own SKU: the grey hint in a variation SKU box
            // that has none of its own (the variation then uses this one).
            'parent_sku'           => (string) $data['sku'],
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
        $bpe_native_attrs   = '';
        $wc_extras_position = get_option('brikpanel_pe_wc_tabs_position', 'middle');
        if (!in_array($wc_extras_position, ['top', 'middle', 'bottom'], true)) {
            $wc_extras_position = 'middle';
        }
        if ($product_id) {
            // With the setting off, the fields other plugins add come in the
            // folded "Fields from your other plugins" box instead of staying
            // off the page (owner, November 2026: closed until clicked, like
            // a theme's own box). For the people the card before it was for,
            // the ones who may change store settings. A store known to have no
            // such fields skips the capture (thirdparty_fold_known_empty()).
            $bpe_tp_fold = self::thirdparty_fold_wanted();
            $wc_extras  = $this->capture_wc_product_data_fields($product_id, $product, $bpe_tp_fold);
            $wc_capture = self::$capture_info;
            $bpe_tp_box = '';
            if ($bpe_tp_fold) {
                self::thirdparty_fold_remember(!empty($wc_capture['fold_found']));
                if ($wc_capture['fold_html'] !== '') {
                    $bpe_tp_box = self::thirdparty_fold_box((string) $wc_capture['fold_html'], (int) $wc_capture['fold_visible']);
                }
            }
            // Blocksy's swatches editor reads the product's attributes out of
            // WooCommerce's Attributes panel, which this editor replaces: give
            // it the hidden rows it looks for (native_attribute_mirror_rows()).
            /**
             * Whether to print the hidden copy of the product's variation
             * attributes that WooCommerce-panel readers like Blocksy's swatches
             * editor look for.
             *
             * @param bool       $print   Default: Blocksy's swatches panel is in the card.
             * @param string[]   $panels  Third-party panel ids in the card.
             * @param WC_Product $product Product being edited.
             */
            if (apply_filters('brikpanel_pe_native_attribute_mirror', in_array('ct_product_variation_swatches', $wc_capture['panels'], true), $wc_capture['panels'], $product)) {
                $bpe_native_attrs = self::native_attribute_mirror($product);
            }
            if ($wc_extras !== '' || $bpe_tp_box !== '') {
                // Recreate the native editor's ancestor chain around the
                // captured third-party panels:
                //
                //   form#post > #woocommerce-product-data > .inside >
                //     .panel-wrap.product_data > (panels)
                //
                // Plugins that add a custom product-data panel bind their JS
                // handlers (repeater "+ Add" buttons, remove-row, sortables,
                // WC core's own meta-boxes-product.js) by DELEGATION on one of
                // those ancestors — most on `#woocommerce-product-data`, some
                // on `#post` or `.product_data`. Inside the BrikPanel card
                // none of them existed, so the delegated handlers never fired
                // and the buttons looked dead ("+ Add does nothing").
                //
                // Safety: nothing else on this page carries these ids (the
                // native metabox/form are not rendered here — `#post` is a div,
                // scripts using `$('form#post')` simply don't match), and WC's
                // type-based show/hide never runs because there is no
                // `select#product-type` to trigger it, so no field gets hidden.
                //
                // Layout: WooCommerce admin.css scopes STRUCTURAL rules under
                // `#woocommerce-product-data` (panels float:left/right at 80%
                // width beside a 20% tab sidebar). Our card stacks panels
                // full-width, so those rules are neutralised in
                // brikpanel-product-editor.css (see the "datawrap" reset) —
                // without that reset the group titles scatter around the
                // floated panels (reported on an RTL store after 3.2.90).
                // WooCommerce's own product form ships a `woocommerce_meta_nonce`
                // field (action `woocommerce_save_data`). Many product-data-tab
                // plugins verify it in their `woocommerce_process_product_meta`
                // save handler before persisting, and crucially they often read
                // it with filter_input(INPUT_POST), which ignores any runtime
                // $_POST changes, so it MUST travel in the real request. Emitting
                // it here, inside the collected `.brikpanel-pe-wc-fields`
                // container, lets the JS save collector forward it verbatim so
                // those handlers recognise the save as authentic and persist.
                //
                // Emitted whenever this card renders (auto mode OR the merchant
                // manually picked a section). That is safe because the card only
                // renders when there is third-party content to save, and every
                // core-tab section that carries third-party fields is ALSO
                // rendered here (hidden when not picked, see
                // capture_wc_product_data_fields) so a "save my field, or delete
                // it when absent" handler always finds its field in the payload
                // and never wipes it. When the card does not render (auto off and
                // nothing picked) the nonce is absent, so no third-party handler
                // runs at all. The same holds when the card is only the folded
                // box: the editor script leaves the whole card, nonce included,
                // out of the save until the box is opened (data-bpe-hold below).
                $wc_meta_nonce_field = wp_nonce_field('woocommerce_save_data', 'woocommerce_meta_nonce', false, false);

                // Labels of WooCommerce's own General / Inventory / Shipping
                // fields, which this editor replaces with its own. Product-data
                // plugins rewrite them to show a unit, and read them without a
                // null check: Measurement Price Calculator runs
                // `$('label[for="_regular_price"]').html().replace(...)`, so on
                // this page it threw, aborted its whole admin script and left
                // every calculator option unhidden and inert. Labels only, never
                // inputs, so nothing extra is posted and no price can be blanked.
                // The editor JS mirrors any unit a plugin writes here onto the
                // matching BrikPanel field (data-base is the untouched unit).
                $bp_bridge_currency = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
                $bp_bridge_weight   = (string) get_option('woocommerce_weight_unit', '');
                $bp_bridge_labels   = [
                    /* translators: %s: currency symbol */
                    '_regular_price' => ['bpe-price', $bp_bridge_currency, sprintf(__('Regular price (%s)', 'brikpanel'), $bp_bridge_currency)],
                    /* translators: %s: currency symbol */
                    '_sale_price'    => ['bpe-sale-price', $bp_bridge_currency, sprintf(__('Sale price (%s)', 'brikpanel'), $bp_bridge_currency)],
                    '_stock'         => ['bpe-stock', '', __('Stock quantity', 'brikpanel')],
                    /* translators: %s: weight unit */
                    '_weight'        => ['bpe-weight', $bp_bridge_weight, sprintf(__('Weight (%s)', 'brikpanel'), $bp_bridge_weight)],
                ];
                $wc_native_label_bridge = '<div class="brikpanel-pe-native-bridge" hidden aria-hidden="true">';
                foreach ($bp_bridge_labels as $bp_bridge_for => $bp_bridge) {
                    $wc_native_label_bridge .= '<p class="form-field ' . esc_attr($bp_bridge_for) . '_field">'
                        . '<label for="' . esc_attr($bp_bridge_for) . '" data-target="' . esc_attr($bp_bridge[0]) . '" data-base="' . esc_attr($bp_bridge[1]) . '">'
                        . esc_html($bp_bridge[2]) . '</label></p>';
                }
                $wc_native_label_bridge .= '</div>';
                // Deliberately NOT `brikpanel-pe-card-wide`: the embedded panels
                // force every control to fill its card, so widening this one
                // turns a price box into a 1200px-long input.
                // The "on" half of the show/hide control. Offered whenever the
                // fields are on screen because of either setting, not just the
                // auto one: a merchant who hand-picked sections needs the same
                // way out, and their list is parked rather than deleted so
                // showing again restores exactly what they chose.
                //
                // Not shown when the only thing in this card is a section that
                // is surfaced regardless of the settings (the multi-currency
                // prices), because there is nothing a hide button could turn off.
                //
                // Capability-gated: the editor itself only needs `edit_products`,
                // but this control writes a store-wide setting and its endpoint
                // demands `manage_woocommerce`. Rendering it for someone who
                // cannot use it would be a button that only ever answers
                // "Permission denied".
                $wc_extras_hide = (current_user_can('manage_woocommerce')
                        && (get_option('brikpanel_pe_wc_tabs_auto', 'no') === 'yes'
                            || !empty((array) get_option('brikpanel_pe_wc_tabs_selected', []))))
                    ? '<button type="button" class="brikpanel-pe-btn secondary small brikpanel-pe-wc-fields__hide">'
                        . esc_html__('Hide these fields', 'brikpanel') . '</button>'
                    : '';

                // Nothing to show (every section hidden for this product type,
                // or only kept for its values): the card stays on the page so
                // its fields travel with the save, but out of sight. The
                // editor JS shows it again when a type change reveals a
                // section. Inline display, not [hidden]: the phone layout reads
                // a card's style.display to decide whether to list it.
                //
                // With the folded box the box is the card's title line: the
                // fields another plugin adds are in the same card, inside the
                // same ancestor chain, so their scripts find what they look
                // for. When the box is all the card holds and it is closed, the
                // editor script leaves the card out of the save
                // (data-bpe-hold): a store with the setting off saves exactly as
                // it did before the box, until someone opens it.
                $bpe_tp_shown = $wc_capture['any_visible'] || ($bpe_tp_box !== '' && $wc_capture['fold_visible'] > 0);
                $bpe_tp_held  = $bpe_tp_box !== '' && !empty($wc_capture['fold_hold']);
                $wc_extras_card = '<div class="brikpanel-pe-card brikpanel-pe-wc-fields' . ($bpe_tp_box !== '' ? ' brikpanel-pe-wc-fields--fold' : '') . '"'
                    . ($bpe_tp_shown ? '' : ' style="display:none"')
                    . ($bpe_tp_held ? ' data-bpe-hold="1"' : '') . '>'
                    . ($bpe_tp_box === ''
                        ? '<div class="brikpanel-pe-wc-fields-head">'
                            . '<label>' . esc_html__('Additional product data', 'brikpanel') . '</label>'
                            . $wc_extras_hide
                            . '</div>'
                        : '')
                    . '<div class="brikpanel-pe-wc-fields-content">'
                    . $wc_meta_nonce_field
                    . $wc_native_label_bridge
                    . '<div id="post" class="brikpanel-pe-wc-postsim">'
                    . '<div id="woocommerce-product-data" class="brikpanel-pe-wc-datawrap">'
                    . '<div class="inside"><div class="panel-wrap product_data">' . $wc_extras . $bpe_tp_box . '</div></div>'
                    . '</div>'
                    . '</div>'
                    . '</div>'
                    . '</div>';
            }
        }
        ?>
        <?php
        // Opt-in wide layout. Off by default: the centred column is the design,
        // and a merchant who wants the variations table and the descriptions to
        // spread across a big monitor asks for it in settings. The class is the
        // only switch — with it absent the widescreen CSS matches nothing.
        $bpe_widescreen = get_option('brikpanel_pe_widescreen', 'no') === 'yes';
        ?>
        <div class="wrap">
        <div class="brikpanel-pe<?php echo $bpe_widescreen ? ' brikpanel-pe-widescreen' : ''; ?>">
            <?php
            // data-new: a brand-new product (still WordPress's auto-draft). A phone
            // keeps such a product on the device until it is saved, and offers it
            // back the next time a new product is opened (device draft, 3.3.48).
            ?>
            <input type="hidden" id="bpe-product-id" value="<?php echo esc_attr($product_id); ?>" data-live="<?php echo $is_live ? '1' : '0'; ?>" data-new="<?php echo $is_auto_draft ? '1' : '0'; ?>">

            <?php
            // The header gives way in its own priority order, measured on the
            // real width instead of guessed from breakpoints (field test B10,
            // CLAUDE.md "Başlık satırı kuralı"). The title and the primary
            // button never give way. First View product / Duplicate / Add new
            // fold behind "More actions", then the header takes two rows (title
            // row, action row), and only then does each row drop text on its
            // own: the title row the word after the back arrow, then the date;
            // the action row the visibility label, then the end of the status
            // label, then a second line that never leaves one button alone.
            // front-end/shared/brikpanel-fit-row.js reads this and picks the
            // first level that fits.
            $bpe_header_fit = [
                'title'  => 'h1',
                'lines'  => ['', '.brikpanel-pe-header-left', '.brikpanel-pe-header-right'],
                'levels' => [
                    '',
                    'is-fold',
                    [
                        'cls'   => 'is-fold is-two-rows',
                        'lines' => [
                            ['sel' => '.brikpanel-pe-header-left', 'steps' => ['', 'is-bare-back', 'is-bare-back is-tight-date']],
                            // The short status shrinks to fill the row, so it is
                            // not asked to leave room spare (fit-row.js).
                            ['sel' => '.brikpanel-pe-header-right', 'steps' => ['', 'is-tight-vis', ['cls' => 'is-tight-vis is-short-status', 'spare' => false], ['cls' => 'is-tight-vis', 'max' => 2]]],
                        ],
                    ],
                ],
            ];
            ?>
            <?php
            // The editor's settings (WooCommerce > Settings > BrikPanel >
            // Products), one tap from the header (owner, November 2026). For
            // people who may change them; a saved product rides along, so the
            // settings page offers the way back to it.
            $bpe_settings_url = '';
            if (current_user_can('manage_woocommerce')) {
                $bpe_settings_args = ['page' => 'wc-settings', 'tab' => 'brikpanel', 'section' => 'products'];
                if ($is_edit) {
                    $bpe_settings_args['bp_back'] = (int) $product_id;
                }
                $bpe_settings_url = add_query_arg($bpe_settings_args, admin_url('admin.php'));
            }
            ?>
            <!-- Header -->
            <div class="brikpanel-pe-header" id="bpe-header" data-bp-fit-row="<?php echo esc_attr(wp_json_encode($bpe_header_fit)); ?>">
                <?php
                // Start fitting as soon as the header opens. The page arrives in
                // pieces (the server's output buffer), and the browser paints
                // what it has: started here, the helper refits each piece before
                // it is painted. The helper is printed in <head>
                // (brikpanel_fit_row_dep()); without it the editor script falls
                // back to the breakpoint layout.
                wp_print_inline_script_tag('if(window.brikpanelFitRow){window.brikpanelFitRow.auto(document.getElementById("bpe-header"));}');
                ?>
                <div class="brikpanel-pe-header-left">
                    <a href="<?php echo esc_url($back_url); ?>" class="brikpanel-pe-back" data-bp-vt="back">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="brikpanel-pe-back-label"><?php esc_html_e('Products', 'brikpanel'); ?></span>
                    </a>
                    <?php
                    // The title and the editor's settings gear are one item: the
                    // gear sits close after the screen's name, as this screen's
                    // settings (owner, November 2026: first in the row it stood
                    // before the way back to Products, with too much room).
                    ?>
                    <div class="brikpanel-pe-heading">
                        <h1><?php echo esc_html($page_title); ?></h1>
                        <?php if ($bpe_settings_url !== '') : ?>
                        <a href="<?php echo esc_url($bpe_settings_url); ?>" class="brikpanel-pe-settings-link" id="bpe-settings-link" aria-label="<?php esc_attr_e('Product editor settings', 'brikpanel'); ?>" title="<?php esc_attr_e('Product editor settings', 'brikpanel'); ?>">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php
                    // Publish-date control — always available, sits right after the
                    // page title. Lets the merchant view and edit the product's
                    // publish date (WordPress post_date) for any status: backdate or
                    // correct a live product, or, with the "Scheduled" status, pick a
                    // future go-live moment. The single datetime input
                    // (#bpe-schedule-date) is shared with the scheduling flow so the
                    // save payload stays unified. A brand-new or auto-draft product
                    // has no committed date yet, so the label reads "Immediately"
                    // until the merchant picks one.
                    //
                    // Not for someone who may not publish: WordPress gives them no
                    // date either ("Contributors don't get to choose the date of
                    // publish", post_submit_meta_box()), and the save keeps the
                    // stored one. Every script that reads the control checks that
                    // it is on the page.
                    if ($can_publish) :
                    $pubdate_ts = 0;
                    if ($data['post_date'] !== '') {
                        $pd = date_create($data['post_date'], wp_timezone());
                        if ($pd) { $pubdate_ts = $pd->getTimestamp(); }
                    }
                    // The store's short date (F→M, l→D) and time format on the
                    // store's clock, in the viewer's language. The editor script
                    // writes the very same text at init and when the picker
                    // changes (formatPubDateLabel() through brikpanelFormat), so
                    // the header is measured once with its final label. The full
                    // date format ("September 22, 2026 12:49 pm") took the header
                    // to two rows (field test B10); the browser's language wrote
                    // "22 Eyl 2026" on an English store (field test E2).
                    $pubdate_label = $pubdate_ts
                        ? wp_date(brikpanel_short_date_format() . ' ' . brikpanel_time_format(), $pubdate_ts)
                        : __('Immediately', 'brikpanel');
                    ?>
                    <div class="brikpanel-pe-pubdate-wrap" id="bpe-pubdate-wrap">
                        <button type="button" class="brikpanel-pe-pubdate-trigger" id="bpe-pubdate-trigger" aria-haspopup="dialog" aria-expanded="false" title="<?php esc_attr_e('Publish date', 'brikpanel'); ?>">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span class="brikpanel-pe-pubdate-label" id="bpe-pubdate-label"><?php echo esc_html($pubdate_label); ?></span>
                            <svg class="brikpanel-pe-pubdate-chevron" width="10" height="10" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="brikpanel-pe-pubdate-pop" id="bpe-pubdate-pop" role="dialog" aria-label="<?php esc_attr_e('Publish date', 'brikpanel'); ?>">
                            <label for="bpe-schedule-date" class="brikpanel-pe-pubdate-poplabel"><?php esc_html_e('Publish date', 'brikpanel'); ?></label>
                            <input type="datetime-local" id="bpe-schedule-date" value="<?php echo esc_attr($data['post_date']); ?>" aria-label="<?php esc_attr_e('Publish date and time', 'brikpanel'); ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="brikpanel-pe-header-right">
                    <?php
                    // Secondary actions (View product / Duplicate / Add new). They
                    // sit inline while the header has room and fold behind the
                    // "More actions" trigger when it does not (the fit levels
                    // above), a disclosure button with aria-expanded in both
                    // states. Printed for every product and hidden until they
                    // apply: a new product's first save only fills in the id and
                    // the link and shows them (saveProduct() in the editor JS).
                    // A new product has no id yet, even though an auto-draft
                    // backs it, so Duplicate's id stays empty until that save.
                    ?>
                    <div class="brikpanel-pe-header-overflow" id="bpe-header-overflow"<?php echo $is_edit ? '' : ' hidden'; ?>>
                        <button type="button" class="brikpanel-pe-overflow-trigger" id="bpe-overflow-trigger" aria-expanded="false" aria-controls="bpe-overflow-menu" aria-label="<?php esc_attr_e('More actions', 'brikpanel'); ?>" title="<?php esc_attr_e('More actions', 'brikpanel'); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                        </button>
                        <div class="brikpanel-pe-overflow-menu" id="bpe-overflow-menu">
                            <a<?php echo $is_live ? ' href="' . esc_url(get_permalink($product_id)) . '"' : ' hidden'; ?> class="brikpanel-pe-btn secondary" id="bpe-view-product" target="_blank" rel="noopener"><?php esc_html_e('View product', 'brikpanel'); ?></a>
                            <button type="button" class="brikpanel-pe-btn secondary" id="bpe-duplicate" data-id="<?php echo $is_edit ? esc_attr($product_id) : ''; ?>"><?php esc_html_e('Duplicate', 'brikpanel'); ?></button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=brikpanel-product-editor')); ?>" class="brikpanel-pe-btn secondary" id="bpe-add-new" data-bp-vt="forward"<?php echo $is_live ? '' : ' hidden'; ?>>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add new', 'brikpanel'); ?>
                            </a>
                        </div>
                    </div>
                    <?php
                    // Password-protected is not a real WP status — it's "publish"
                    // with a non-empty post_password.  We use a virtual "password"
                    // value in the dropdown and map it back on save.
                    $effective_status = $data['status'];
                    $is_password = ($data['status'] === 'publish' && $data['post_password'] !== '');
                    if ($is_password) {
                        $effective_status = 'password';
                    }
                    // A status that publishes is offered to someone who may not
                    // publish only when it is the product's current one, as in
                    // WordPress's own status dropdown.
                    $bpe_status_offered = static function ($value) use ($can_publish, $effective_status) {
                        return $can_publish || $value === $effective_status;
                    };
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
                                    'future'   => __('Scheduled', 'brikpanel'),
                                    'draft'    => __('Draft', 'brikpanel'),
                                    'pending'  => brikpanel_product_status_label('pending'),
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
                            <?php if ($bpe_status_offered('publish')) : ?>
                            <li role="option" data-value="publish" class="<?php echo $effective_status === 'publish' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="publish"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Published', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visible to everyone on the storefront', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php endif; ?>
                            <?php if ($scheduling_on && $bpe_status_offered('future')) : ?>
                            <li role="option" data-value="future" class="<?php echo $effective_status === 'future' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="future"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Scheduled', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Publishes automatically on a future date', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php endif; ?>
                            <li role="option" data-value="draft" class="<?php echo $effective_status === 'draft' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="draft"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Draft', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Not visible on the storefront', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php
                            // "Pending review": the product waits for someone who may
                            // publish. Listed only where it means something (the product
                            // already waits, or the person may not publish), so a store
                            // that never sends products in for review sees no new choice.
                            if (!$can_publish || 'pending' === $effective_status) : ?>
                            <li role="option" data-value="pending" class="<?php echo $effective_status === 'pending' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="pending"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php echo esc_html(brikpanel_product_status_label('pending')); ?></strong>
                                    <small><?php esc_html_e('Waiting for a manager to publish it', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php endif; ?>
                            <?php if ($bpe_status_offered('private')) : ?>
                            <li role="option" data-value="private" class="<?php echo $effective_status === 'private' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="private"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Private', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visible only to admins and editors', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php endif; ?>
                            <?php if ($bpe_status_offered('password')) : ?>
                            <li role="option" data-value="password" class="<?php echo $effective_status === 'password' ? 'is-active' : ''; ?>">
                                <span class="brikpanel-pe-status-dot" data-status="password"></span>
                                <span class="brikpanel-pe-status-option-text">
                                    <strong><?php esc_html_e('Password protected', 'brikpanel'); ?></strong>
                                    <small><?php esc_html_e('Visitors must enter a password to view', 'brikpanel'); ?></small>
                                </span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="brikpanel-pe-password-inline <?php echo $is_password ? 'is-visible' : ''; ?>" id="bpe-password-wrap">
                        <?php // Read-only for someone who may not publish: the save keeps the stored password (WordPress drops theirs too). ?>
                        <input type="text" id="bpe-post-password" value="<?php echo esc_attr($data['post_password']); ?>" placeholder="<?php esc_attr_e('Password...', 'brikpanel'); ?>"<?php echo $can_publish ? '' : ' readonly'; ?>>
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
                        <button type="button" class="brikpanel-pe-catvis-trigger" id="bpe-catvis-trigger" aria-haspopup="listbox" aria-expanded="false" title="<?php esc_attr_e('Catalog visibility', 'brikpanel'); ?>">
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
                    <?php
                    // Every text this button can show, so the header is fitted
                    // for the widest: "Saving..." on each save and autosave must
                    // never fold or unfold the header while it lasts.
                    $bpe_publish_labels = [
                        __('Publish', 'brikpanel'),
                        __('Update', 'brikpanel'),
                        __('Save', 'brikpanel'),
                        __('Schedule', 'brikpanel'),
                        __('Saving...', 'brikpanel'),
                    ];
                    // Only those who may not publish ever see it: for everyone
                    // else the longer label would fold the header earlier.
                    if (!$can_publish) {
                        $bpe_publish_labels[] = __('Submit for review', 'brikpanel');
                    }
                    ?>
                    <?php
                    // Existing live (or password-protected) product → Update.
                    // Brand-new product with the default Published status →
                    // Publish (clicking actually publishes).
                    // Anything else (Draft / Private new product) → Save.
                    // Someone who may not publish: "Submit for review" on Pending
                    // review (updatePublishLabel() in the editor script follows
                    // the same rule when the status changes).
                    // The phone's bottom bar prints the same label.
                    if ($effective_status === 'future') {
                        $bpe_publish_label = __('Schedule', 'brikpanel');
                    } elseif (!$can_publish) {
                        if ('pending' === $effective_status) {
                            $bpe_publish_label = __('Submit for review', 'brikpanel');
                        } elseif (in_array($effective_status, ['publish', 'private', 'password'], true)) {
                            $bpe_publish_label = __('Update', 'brikpanel');
                        } else {
                            $bpe_publish_label = __('Save', 'brikpanel');
                        }
                    } elseif (($is_edit && in_array($data['status'], ['publish', 'private'], true)) || $is_password) {
                        $bpe_publish_label = __('Update', 'brikpanel');
                    } elseif (!$is_edit && $data['status'] === 'publish') {
                        $bpe_publish_label = __('Publish', 'brikpanel');
                    } else {
                        $bpe_publish_label = __('Save', 'brikpanel');
                    }
                    ?>
                    <button type="button" class="brikpanel-pe-btn primary" id="bpe-publish" data-bp-fit-labels="<?php echo esc_attr(wp_json_encode($bpe_publish_labels)); ?>">
                        <?php echo esc_html($bpe_publish_label); ?>
                    </button>
                </div>
            </div>
            <?php
            // The whole header is here now: fit it once more, at once. The date
            // label already holds its final text (the server prints what
            // formatPubDateLabel() writes), so the level chosen here stays.
            wp_print_inline_script_tag(
                '(function(){'
                . 'var h=document.getElementById("bpe-header");'
                . 'if(window.brikpanelFitRow&&h){var c=window.brikpanelFitRow.get(h)||window.brikpanelFitRow.auto(h);if(c){c.refit();}}'
                . '})();'
            );

            // Phones (782px and narrower) hide the header above and show this
            // bar instead, as an app does: back, the product's name and kind in
            // the middle, "..." for View product, Duplicate and Add new. The
            // status and the save button sit in a bar at the bottom of the page
            // (after the content). Both drive the header's own controls
            // (brikpanel-product-editor.js, "PHONE"), so a save is the same save.
            // Wider screens never show either bar. One heading at a time: the
            // header's on wider screens, this hidden one on phones.
            $bpe_ph_types = function_exists('wc_get_product_types') ? (array) wc_get_product_types() : [];
            $bpe_ph_vars  = isset($data['variation_count']) ? (int) $data['variation_count'] : 0;
            if (!empty($data['is_variable'])) {
                $bpe_ph_sub = __('Variable product', 'brikpanel');
                if ($is_edit && $bpe_ph_vars > 0) {
                    /* translators: %s: number of variations on the product. */
                    $bpe_ph_sub .= ' · ' . sprintf(_n('%s variation', '%s variations', $bpe_ph_vars, 'brikpanel'), brikpanel_number($bpe_ph_vars));
                }
            } elseif ($product_type_current === 'simple' || !isset($bpe_ph_types[$product_type_current])) {
                $bpe_ph_sub = __('Simple product', 'brikpanel');
            } else {
                $bpe_ph_sub = (string) $bpe_ph_types[$product_type_current];
            }
            $bpe_ph_name = ($is_edit && $data['name'] !== '') ? $data['name'] : __('Add product', 'brikpanel');
            ?>
            <div class="brikpanel-pe-ph-appbar" id="bpe-ph-appbar">
                <h1 class="screen-reader-text" id="bpe-ph-h1"><?php echo esc_html($page_title); ?></h1>
                <a href="<?php echo esc_url($back_url); ?>" class="brikpanel-pe-ph-iconbtn" id="bpe-ph-back" data-bp-vt="back" aria-label="<?php esc_attr_e('Products', 'brikpanel'); ?>">
                    <svg width="22" height="22" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>
                </a>
                <div class="brikpanel-pe-ph-heading">
                    <span class="brikpanel-pe-ph-name" id="bpe-ph-name" dir="auto"><?php echo esc_html($bpe_ph_name); ?></span>
                    <span class="brikpanel-pe-ph-sub" id="bpe-ph-sub" dir="auto"><?php echo esc_html($bpe_ph_sub); ?></span>
                </div>
                <button type="button" class="brikpanel-pe-ph-iconbtn" id="bpe-ph-more" aria-haspopup="dialog" aria-label="<?php esc_attr_e('More actions', 'brikpanel'); ?>"<?php echo ($is_edit || $bpe_settings_url !== '') ? '' : ' hidden'; ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                </button>
            </div>

            <?php
            // Product types whose own product-data panel this editor cannot show
            // (Product Bundles, Composite Products, Bookings…). Their fields are
            // left completely untouched on save — see unrendered_type_panels() —
            // so tell the merchant where those settings live instead of leaving
            // them wondering why the section is missing.
            //
            // The other plugins' types with a settings box of their own, for the
            // price box below and for the editor script, which follows a type
            // change made on the page (a new product made a bundle). After the
            // field capture: it knows which picked boxes came empty.
            $bpe_type_guard        = self::type_guard_lists($product, array_keys($product_type_options));
            $bpe_unrendered_panels = $is_edit ? self::unrendered_type_panels($product) : [];
            $bpe_thrower_paths     = $is_edit ? self::unrepresented_owner_paths($product) : [];
            $bpe_unrepresented     = !empty($bpe_unrendered_panels) || !empty($bpe_thrower_paths)
                || in_array($product_type_current, $bpe_type_guard['unrepresented'], true);
            // Deliberately NOT get_edit_post_link(): our own modify_edit_link()
            // filter rewrites every product edit link to this very page, so the
            // note would link the merchant back to where they already are. The
            // `brikpanel=0` flag is what makes the native URL survive
            // handle_redirects() for this one request. A new product has its
            // draft already, so the note can point there too.
            $bpe_native_edit_url   = $product_id ? self::native_edit_url($product_id) : '';

            // Hidden attribute rows for Blocksy's swatches editor. Ahead of the
            // card so they are parsed before Blocksy looks for them, and outside
            // every container the save collects, so they are never posted.
            echo $bpe_native_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piece by piece in native_attribute_mirror_rows().

            // Device draft (3.3.48): a product this phone kept but never saved.
            // The editor script fills and shows it, on a phone only and only when
            // there is one to offer; outside the content so the phone's section
            // pages never move it.
            ?>
            <div class="brikpanel-pe-unsaved" id="bpe-unsaved" role="region" aria-labelledby="bpe-unsaved-title" hidden>
                <div class="brikpanel-pe-unsaved-text">
                    <strong class="brikpanel-pe-unsaved-title" id="bpe-unsaved-title"><?php esc_html_e('Unsaved product', 'brikpanel'); ?></strong>
                    <span class="brikpanel-pe-unsaved-meta" id="bpe-unsaved-meta"></span>
                </div>
                <div class="brikpanel-pe-unsaved-actions">
                    <button type="button" class="brikpanel-btn brikpanel-btn--primary" id="bpe-unsaved-restore"><?php esc_html_e('Restore', 'brikpanel'); ?></button>
                    <button type="button" class="brikpanel-btn brikpanel-btn--secondary" id="bpe-unsaved-discard"><?php esc_html_e('Discard', 'brikpanel'); ?></button>
                </div>
            </div>

            <!-- Content -->
            <div class="brikpanel-pe-content">
            <?php // Two columns, WordPress-style: long-form content flows down the
            // main column, short setting cards down a narrow side column. Each
            // column flows on its own, which is the whole point — an earlier
            // revision split the SAME list into two equal halves row by row, so
            // whichever cards happened to be adjacent got married (Permalink
            // beside Product images) and every odd card left the other half of
            // its row empty.
            //
            // Emitted ONLY when the widescreen setting is on. Splitting the list
            // into two wrappers necessarily puts the side cards after the main
            // ones in source order, and below the layout's breakpoint the
            // wrappers collapse to `display:contents` — so with the setting OFF
            // this markup would quietly reorder the single column, moving Price
            // and Category below the SEO card. Merchants who never touch the
            // setting must get byte-identical markup, so they do.
            //
            // With the setting ON and a window under the breakpoint, the side
            // column stacks below the main one. That is what every main+sidebar
            // layout does when it runs out of room, WordPress's own post editor
            // included. ?>
            <?php if ($bpe_widescreen) : ?><div class="brikpanel-pe-col-main"><?php endif; ?>

                <?php
                // Notices (only red errors are shown on this screen, see the CSS)
                // go here, on top of the card column: without a marker WordPress
                // put them in the sticky title bar, next to the title. Inside the
                // main column, not before it, so the widescreen grid never gets
                // them as an extra cell.
                brikpanel_header_end();
                ?>

                <?php
                // On the page whenever some type would need it: the script shows it
                // when the merchant picks such a type and hides it again.
                if ($bpe_unrepresented || $bpe_type_guard['unrepresented']) : ?>
                <div class="brikpanel-pe-typenote" id="bpe-typenote"<?php echo $bpe_unrepresented ? ' data-shown-for="' . esc_attr($product_type_current) . '"' : ' hidden'; ?>>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span>
                        <?php esc_html_e('Some settings for this product type can only be edited in the WooCommerce product editor. They are left untouched when you save here.', 'brikpanel'); ?>
                        <?php if ($bpe_native_edit_url) : ?>
                        <a href="<?php echo esc_url($bpe_native_edit_url); ?>"><?php esc_html_e('Open in WooCommerce', 'brikpanel'); ?></a>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <!-- Product Name -->
                <div class="brikpanel-pe-card" data-bpe-section="name">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-name"><?php esc_html_e('Product name', 'brikpanel'); ?></label>
                        <input type="text" id="bpe-name" value="<?php echo esc_attr($data['name']); ?>" placeholder="<?php esc_attr_e('E.g.: Cotton White T-Shirt - M Size', 'brikpanel'); ?>" data-required="1">
                        <div class="brikpanel-pe-field-error"></div>
                    </div>
                    <?php if ($product_type_selector_enabled) :
                        // How the product is sold: the four core types as one row
                        // of buttons, and a line under it saying what the chosen
                        // one is for. Types other plugins register (subscriptions,
                        // bundles, bookings) sit in a "More" list at the end. The
                        // hidden select is what the editor script reads and the
                        // save posts; the buttons only drive it.
                        $bpe_core_types = array_intersect_key(
                            [
                                'simple'   => [_x('Simple', 'product type choice', 'brikpanel'), __('One item with its own price and stock.', 'brikpanel')],
                                'variable' => [_x('Variable', 'product type choice', 'brikpanel'), __('Options buyers choose from, like size or color.', 'brikpanel')],
                                'grouped'  => [_x('Grouped', 'product type choice', 'brikpanel'), __('Several products sold together on one page.', 'brikpanel')],
                                'external' => [_x('External', 'product type choice', 'brikpanel'), __('Sold on another site: the button opens your link.', 'brikpanel')],
                            ],
                            $product_type_options
                        );
                        $bpe_more_types = array_diff_key($product_type_options, $bpe_core_types);
                        $bpe_more_on    = isset($bpe_more_types[$product_type_current]);
                        $bpe_more_hint  = __('A product type added by another plugin.', 'brikpanel');
                        $bpe_type_hint  = isset($bpe_core_types[$product_type_current]) ? $bpe_core_types[$product_type_current][1] : ($bpe_more_on ? $bpe_more_hint : '');
                    ?>
                    <div class="brikpanel-pe-field brikpanel-pe-type-field">
                        <span class="brikpanel-pe-type-label" id="bpe-type-label"><?php esc_html_e('Product type', 'brikpanel'); ?></span>
                        <div class="brikpanel-pe-type-switch" role="radiogroup" aria-labelledby="bpe-type-label" aria-describedby="bpe-type-hint" style="--bpe-type-n:<?php echo (int) (count($bpe_core_types) + ($bpe_more_types ? 1 : 0)); ?>">
                            <?php foreach ($bpe_core_types as $bpe_type_key => $bpe_type) : ?>
                            <label class="brikpanel-pe-type-option">
                                <input type="radio" name="bpe_type_choice" value="<?php echo esc_attr($bpe_type_key); ?>" data-hint="<?php echo esc_attr($bpe_type[1]); ?>"<?php checked($product_type_current, $bpe_type_key); ?>>
                                <span class="brikpanel-pe-type-face"><?php self::type_choice_icon($bpe_type_key); ?><span><?php echo esc_html($bpe_type[0]); ?></span></span>
                            </label>
                            <?php endforeach; ?>
                            <?php if ($bpe_more_types) : ?>
                            <div class="brikpanel-pe-type-option brikpanel-pe-type-more<?php echo $bpe_more_on ? ' is-on' : ''; ?>">
                                <select id="bpe-type-more" aria-label="<?php esc_attr_e('More product types', 'brikpanel'); ?>" data-hint="<?php echo esc_attr($bpe_more_hint); ?>">
                                    <option value=""><?php echo esc_html_x('More', 'more product types', 'brikpanel'); ?></option>
                                    <?php foreach ($bpe_more_types as $bpe_type_key => $bpe_type_label) : ?>
                                    <option value="<?php echo esc_attr($bpe_type_key); ?>"<?php selected($product_type_current, $bpe_type_key); ?>><?php echo esc_html($bpe_type_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="brikpanel-pe-type-face" aria-hidden="true"><span class="brikpanel-pe-type-more-text"><?php echo esc_html($bpe_more_on ? $bpe_more_types[$product_type_current] : _x('More', 'more product types', 'brikpanel')); ?></span><?php self::type_choice_icon('more'); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <p class="brikpanel-pe-help-text brikpanel-pe-type-hint" id="bpe-type-hint"><?php echo esc_html($bpe_type_hint); ?></p>
                        <select id="bpe-product-type" hidden aria-hidden="true" tabindex="-1">
                            <?php foreach ($product_type_options as $type_key => $type_label) : ?>
                                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($product_type_current, $type_key); ?>><?php echo esc_html($type_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Virtual and Digital, and the options other plugins add
                    // beside them, sit under the product type: they say what
                    // kind of product this is, which is where WooCommerce's own
                    // box shows them too, next to its type select. They used to
                    // fill a card of their own near the end of the page. Still
                    // the "Digital product" entry of the sections list: with it
                    // switched off nothing renders here, and the save leaves the
                    // stored flags alone (it sends them only while the switches
                    // are on the page).
                    if (in_array('digital', $visible, true)) :
                        // The options other plugins put next to Virtual and
                        // Downloadable in WooCommerce's own product box
                        // (Germanized's "Service", "Subscription", "For Tutor"…),
                        // one switch each, posted the way WooCommerce posts them
                        // so each plugin's own save handler stores them (see
                        // plugin_type_options()). Like WooCommerce, a switch
                        // shows only for the product types its classes allow; the
                        // editor script applies the same rule live. Behind the
                        // "Show other plugins' product options" setting; whatever
                        // is not shown keeps its value on save
                        // (keep_unshown_plugin_type_options()).
                        $bpe_plugin_opts = get_option('brikpanel_pe_plugin_type_options', 'yes') === 'yes'
                            ? self::plugin_type_options($product instanceof WC_Product ? $product : null)
                            : [];
                        $bpe_po_rows      = '';
                        $bpe_po_any_shown = false;
                        if ($bpe_plugin_opts) {
                            // The type and flags the editor script reads on load
                            // (wcGateContext()): the type select when it is on the
                            // page, otherwise the variations switch.
                            $bpe_po_type = $product_type_current;
                            if (!($product_type_selector_enabled && !empty($product_type_options)) && in_array('variations', $visible, true)) {
                                $bpe_po_type = !empty($data['is_variable'])
                                    ? 'variable'
                                    : (brikpanel_is_variable_product_type($bpe_po_type) ? 'simple' : $bpe_po_type);
                            }
                            $bpe_po_flags = !brikpanel_is_variable_product_type($bpe_po_type) && !in_array($bpe_po_type, ['grouped', 'external'], true);
                            $bpe_po_ctx   = [
                                'type'         => $bpe_po_type,
                                'downloadable' => $bpe_po_flags && !empty($data['is_downloadable']),
                                'virtual'      => $bpe_po_flags && (!empty($data['is_virtual']) || !empty($data['is_downloadable'])),
                            ];
                            $bpe_po_stored_type = $product instanceof WC_Product ? (string) $product->get_type() : '';
                            foreach ($bpe_plugin_opts as $bpe_po) {
                                // A product type this editor cannot show (Bookings,
                                // Bundles…): its own options stay with WooCommerce's
                                // editor, like the rest of its settings (the note at
                                // the top says so), and keep their values here.
                                if ($bpe_unrepresented && $bpe_po_stored_type !== '' && in_array('show_if_' . $bpe_po_stored_type, $bpe_po['gate'], true)) {
                                    continue;
                                }
                                $bpe_po_shown     = !$bpe_po['gate'] || self::wc_tab_shown_for($bpe_po['gate'], $bpe_po_ctx);
                                $bpe_po_any_shown = $bpe_po_any_shown || $bpe_po_shown;
                                $bpe_po_rows .= '<div class="brikpanel-pe-typeopt brikpanel-pe-plugin-opt"'
                                    . ($bpe_po['gate'] ? ' data-wc-gate="' . esc_attr(implode(' ', $bpe_po['gate'])) . '"' : '')
                                    . ($bpe_po_shown ? '' : ' hidden') . '>'
                                    . '<label class="brikpanel-pe-typeopt-switch">'
                                    . '<input type="checkbox" id="' . esc_attr($bpe_po['id']) . '" name="' . esc_attr($bpe_po['id']) . '"'
                                    . ' data-product-type-option-id="' . esc_attr($bpe_po['id']) . '"'
                                    . checked(self::plugin_type_option_on($product instanceof WC_Product ? $product : null, $bpe_po['key'], $bpe_po), true, false) . '>'
                                    . '<span class="brikpanel-pe-typeopt-slider" aria-hidden="true"></span>'
                                    . '<span class="brikpanel-pe-typeopt-text">' . esc_html($bpe_po['label']) . '</span>'
                                    . '</label>'
                                    . ($bpe_po['help'] !== '' ? self::help_tip('bpe-tip-opt' . sanitize_html_class($bpe_po['id']), $bpe_po['help']) : '')
                                    . '</div>';
                            }
                        }
                    ?>
                    <div class="brikpanel-pe-typeopts" id="bpe-typeopts"<?php echo in_array($product_type_current, ['grouped', 'external'], true) ? ' style="display:none"' : ''; ?>>
                        <div class="brikpanel-pe-typeopts-row">
                            <div class="brikpanel-pe-typeopt">
                                <label class="brikpanel-pe-typeopt-switch">
                                    <input type="checkbox" id="bpe-virtual-toggle" <?php checked(!empty($data['is_virtual'])); ?>>
                                    <span class="brikpanel-pe-typeopt-slider" aria-hidden="true"></span>
                                    <span class="brikpanel-pe-typeopt-text"><?php esc_html_e('Virtual (no shipping)', 'brikpanel'); ?></span>
                                </label>
                                <?php echo self::help_tip('bpe-tip-virtual', __('Service or intangible product, no physical shipping.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?>
                            </div>
                            <div class="brikpanel-pe-typeopt">
                                <label class="brikpanel-pe-typeopt-switch">
                                    <input type="checkbox" id="bpe-digital-toggle" <?php checked($data['is_downloadable']); ?>>
                                    <span class="brikpanel-pe-typeopt-slider" aria-hidden="true"></span>
                                    <span class="brikpanel-pe-typeopt-text"><?php esc_html_e('Digital (downloadable)', 'brikpanel'); ?></span>
                                </label>
                                <?php echo self::help_tip('bpe-tip-digital', __('Customers receive download links after purchase.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?>
                            </div>
                            <?php if ($bpe_po_rows !== '') : ?>
                            <div class="brikpanel-pe-typeopts-plugins" id="bpe-plugin-opts"<?php echo $bpe_po_any_shown ? '' : ' hidden'; ?>><?php echo $bpe_po_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every piece escaped above. ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="brikpanel-pe-collapse <?php echo $data['is_downloadable'] ? 'open' : ''; ?>" id="bpe-digital-section">
                            <div>
                                <div class="brikpanel-pe-downloads" id="bpe-downloads-list"></div>
                                <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-add-download">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    <?php esc_html_e('Add downloadable file', 'brikpanel'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php
                // Grouped and external products get their own card, like the
                // name card: not a section in the settings list. Printed when
                // the type choice can switch to one of them or the product
                // already is one; the script shows the one that matches.
                $bpe_type_cards = $product_type_selector_enabled
                    || in_array($product_type_current, ['grouped', 'external'], true);
                if ($bpe_type_cards) : ?>
                <!-- Products in this group (grouped products) -->
                <div class="brikpanel-pe-card" id="bpe-group-card"<?php echo $product_type_current === 'grouped' ? '' : ' style="display:none"'; ?>>
                    <div class="brikpanel-pe-field">
                        <label for="bpe-children-search"><?php esc_html_e('Products in this group', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-group', __('Shoppers see these products on one page, in this order, each with its own quantity box. The group takes its price from them.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                        <div class="brikpanel-pe-linked brikpanel-pe-group-picker" data-field="children">
                            <div class="brikpanel-pe-linked-chips" id="bpe-children-chips"></div>
                            <input type="text" id="bpe-children-search" class="brikpanel-pe-linked-search" placeholder="<?php esc_attr_e('Search products...', 'brikpanel'); ?>" autocomplete="off">
                            <div class="brikpanel-pe-linked-results" hidden></div>
                        </div>
                    </div>
                </div>

                <!-- Product link (external / affiliate products) -->
                <div class="brikpanel-pe-card" id="bpe-external-card"<?php echo $product_type_current === 'external' ? '' : ' style="display:none"'; ?>>
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-product-url"><?php esc_html_e('Product URL', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-external', __('Shoppers buy this product on the linked site: the button on the product page opens this link.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                            <input type="url" id="bpe-product-url" dir="ltr" value="<?php echo esc_attr($data['product_url']); ?>" placeholder="https://" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-button-text"><?php esc_html_e('Button text', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-button-text" value="<?php echo esc_attr($data['button_text']); ?>" placeholder="<?php esc_attr_e('Buy product', 'brikpanel'); ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
                    <?php
                    // One video per product (Flatsome, Porto): a row under the
                    // images. Themes with a video per image get a button on
                    // each image instead (drawn by the JS).
                    if (is_array($bpe_video_payload) && is_array($bpe_video_payload['product'] ?? null)) :
                        $bpe_pv         = $bpe_video_payload['product'];
                        $bpe_pv_summary = Brikpanel_Video::summary($bpe_pv);
                        ?>
                    <div class="brikpanel-pe-prodvideo" id="bpe-prodvideo">
                        <span class="brikpanel-pe-prodvideo-icon<?php echo $bpe_pv_summary !== '' ? ' is-active' : ''; ?>" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span>
                        <span class="brikpanel-pe-prodvideo-info">
                            <span class="brikpanel-pe-prodvideo-label"><?php esc_html_e('Product video', 'brikpanel'); ?></span>
                            <span class="brikpanel-pe-prodvideo-meta" id="bpe-prodvideo-meta"><?php echo esc_html($bpe_pv_summary !== '' ? $bpe_pv_summary : __('No video yet', 'brikpanel')); ?></span>
                        </span>
                        <button type="button" class="brikpanel-pe-btn secondary small" id="bpe-prodvideo-edit"><?php echo $bpe_pv_summary !== '' ? esc_html__('Edit video', 'brikpanel') : esc_html__('Add video', 'brikpanel'); ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $section_html['images'] = ob_get_clean(); endif; ?>

                <?php if (in_array('slug', $bpe_build, true)) : ob_start();
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
                        <label for="bpe-slug"><?php esc_html_e('Permalink (URL slug)', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-slug', __('The last part of the product URL. Leave blank to generate it automatically from the product name.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                        <?php if ($bpe_url_prefix !== '') : ?>
                        <?php // brikpanel-pe-slug-group opts this group OUT of the
                        // widescreen short-field cap: the read-only prefix is a whole
                        // store URL, so squeezing the pair into a price-sized box
                        // ellipsises the prefix and leaves ~140px for the slug. ?>
                        <div class="brikpanel-pe-input-group brikpanel-pe-slug-group">
                            <span class="brikpanel-pe-input-prefix brikpanel-pe-slug-prefix"><?php echo esc_html($bpe_url_prefix); ?></span>
                            <input type="text" id="bpe-slug" value="<?php echo esc_attr($data['slug']); ?>" placeholder="<?php esc_attr_e('auto-generated-from-name', 'brikpanel'); ?>" spellcheck="false">
                        </div>
                        <?php else : ?>
                        <input type="text" id="bpe-slug" value="<?php echo esc_attr($data['slug']); ?>" placeholder="<?php esc_attr_e('auto-generated-from-name', 'brikpanel'); ?>" spellcheck="false">
                        <?php endif; ?>
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
                <div class="brikpanel-pe-card brikpanel-pe-card-wide<?php echo $data['is_variable'] ? ' bpe-variable-on' : ''; ?><?php echo !empty($data['shared_stock']) ? ' bpe-var-shared-on' : ''; ?><?php echo !empty($data['pack_sizes']) ? ' bpe-var-pack-on' : ''; ?>" id="bpe-var-card">

                    <?php if ($bpe_show_var && $product_type_selector_enabled) : ?>
                    <?php // The type buttons under the product name decide whether
                          // the product is variable; a "Variable product" switch here
                          // said the same thing a second time, with the same words.
                          // The checkbox stays as their hidden mirror: every show/hide
                          // rule of the card and the save read it
                          // (syncProductType() in the editor script keeps it in step). ?>
                    <?php // Inline display too: wp-admin's forms.css gives every checkbox
                          // display:inline-block, which beats the [hidden] rule (B3). ?>
                    <input type="checkbox" id="bpe-var-toggle" hidden style="display:none" <?php checked($data['is_variable']); ?>>
                    <?php elseif ($bpe_show_var) : ?>
                    <div class="brikpanel-pe-var-head" id="bpe-var-toggle-row"<?php echo in_array($product_type_current, ['grouped', 'external'], true) ? ' style="display:none"' : ''; ?>>
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
                          // (always simple) so the save path stays consistent.
                          // `data-section-hidden` tells the payload builder that this
                          // unchecked box is NOT a merchant decision — the control was
                          // never rendered — so it must omit `is_variable` entirely
                          // rather than assert "simple". Asserting it converted every
                          // variable product to simple on save and hard-deleted all of
                          // its variations. ?>
                    <input type="checkbox" id="bpe-var-toggle" data-section-hidden="1" hidden style="display:none">
                    <?php endif; ?>

                    <div class="brikpanel-pe-attr-editor">
                        <?php // The explanation behind "?" (help_tip()), in both versions:
                              // the editor script shows the one that fits the type
                              // (.brikpanel-pe-attr-help-var / -simple). ?>
                        <label class="brikpanel-pe-attr-label"><?php echo esc_html(_x('Attributes', 'product editor attribute section heading', 'brikpanel')); ?><span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-attributes">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-attributes" role="tooltip"><span class="brikpanel-pe-attr-help-var"<?php echo $data['is_variable'] ? '' : ' style="display:none"'; ?>><?php esc_html_e('Add attributes, then switch on “Use for variations” for the ones buyers pick (Size, Color). Others stay as specs shown on the product page.', 'brikpanel'); ?></span><span class="brikpanel-pe-attr-help-simple"<?php echo $data['is_variable'] ? ' style="display:none"' : ''; ?>><?php esc_html_e('Specs that appear on the product page (e.g. Brand, Material).', 'brikpanel'); ?></span></span></span></label>

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
                            <div id="bpe-attr-list"></div>

                            <?php // Adding an attribute: one button under the list. The picker,
                                  // "or create new" and the quick add open behind it and close
                                  // again once an attribute is added, the way the phone layout
                                  // opens them as a sheet (desktop only, see the CSS). ?>
                            <button type="button" class="brikpanel-pe-btn secondary small brikpanel-pe-attr-open" id="bpe-attr-open" aria-expanded="false" aria-controls="bpe-attr-new">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php esc_html_e('Add attribute', 'brikpanel'); ?>
                            </button>
                            <div class="brikpanel-pe-attr-add" id="bpe-attr-new">
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
                        </div>
                    </div>

                    <?php if ($bpe_show_var) : ?>
                    <!-- Variation builder: visible only while "Variable product" is on -->
                    <div class="brikpanel-pe-var-build" id="bpe-var-build"<?php echo $data['is_variable'] ? '' : ' style="display:none"'; ?>>
                        <?php // One flex row for the build actions. "Clear all" is pushed to
                              // the far end on purpose: it is the only destructive control
                              // here, and distance is what keeps it from being hit while
                              // aiming for "Add manually". JS owns its visibility because
                              // the condition (at least one variation on screen) changes on
                              // every table render, not only on page load. ?>
                        <div class="brikpanel-pe-var-build-actions">
                            <button type="button" class="brikpanel-pe-btn primary" id="bpe-generate-vars"><?php esc_html_e('Generate variations', 'brikpanel'); ?></button>
                            <button type="button" class="brikpanel-pe-btn secondary" id="bpe-add-variation" title="<?php esc_attr_e('Add a single variation and pick its combination yourself', 'brikpanel'); ?>"><?php esc_html_e('Add manually', 'brikpanel'); ?></button>
                            <span class="brikpanel-pe-var-stale-hint" id="bpe-var-stale-hint" hidden><?php esc_html_e('Attributes changed since you generated. Click Generate variations to refresh the list.', 'brikpanel'); ?></span>
                            <?php // Short label: the strip already reads as variation actions, and
                                  // "Clear all variations" would be its widest item by far. The
                                  // tooltip and the aria-label carry the full meaning. ?>
                            <button type="button" class="brikpanel-pe-btn secondary brikpanel-pe-btn-danger" id="bpe-clear-vars" hidden aria-label="<?php esc_attr_e('Clear all variations', 'brikpanel'); ?>" title="<?php esc_attr_e('Delete every variation of this product. The product stays a variable product and its attributes are kept.', 'brikpanel'); ?>"><?php echo esc_html(_x('Clear all', 'button that deletes every variation of the product', 'brikpanel')); ?></button>
                        </div>

                        <div class="brikpanel-pe-var-table-section" id="bpe-var-table-section" style="display:none">
                            <!-- Variation tools panel: ONE compact toolbar strip. Bulk
                                 edits, sorting and the storefront "Default Form Values"
                                 sit side by side on a single control-tall line, told
                                 apart by spacing. The micro-headings and the label-over-
                                 input stacking are deliberately gone: at this card width
                                 they cost more than three lines of height, and every
                                 control already names itself ("Price", "Sort variations…",
                                 "No default Color…"). Tooltips + aria-labels carry the
                                 same names to assistive tech. The last group is filled by
                                 JS so it always mirrors the current variation attributes. -->
                            <?php
                            // The three text boxes are sized from their OWN translated
                            // placeholder, not from a pixel guess: "Price/Sale/Stock" fit a
                            // 62px box, "Скидка"/"İndirim" do not, and a fixed width clips
                            // them mid-word. PHP is the only place that knows the string, so
                            // it hands the character count to CSS, which turns it into a width
                            // (the CSS factor is above 1ch on purpose: `ch` measures "0", and
                            // Cyrillic or Greek glyphs are wider than that). Clamped so one
                            // very long translation cannot eat the strip.
                            $bpe_ph_price = __('Price', 'brikpanel');
                            $bpe_ph_sale  = _x('Sale', 'short placeholder for the bulk sale price field', 'brikpanel');
                            $bpe_ph_stock = __('Stock', 'brikpanel');
                            $bpe_ph_unlimited = _x('Unlimited', 'stock quantity that is not counted', 'brikpanel');
                            // With shared stock the pressed button means "every variation
                            // sells from the product's quantity", and the box says so.
                            $bpe_ph_shared = _x('Shared', 'stock that variations take from the product', 'brikpanel');
                            $bpe_bulk_inf_label = !empty($data['shared_stock'])
                                ? __('Use the shared stock for every variation', 'brikpanel')
                                : __('Unlimited stock for every variation', 'brikpanel');
                            $bpe_ph_w = static function ($text) {
                                $len = brikpanel_strlen($text);
                                return max(4, min(12, (int) $len));
                            };
                            ?>
                            <div class="brikpanel-pe-var-bulk">
                                <div class="brikpanel-pe-var-bulk-group">
                                    <div class="brikpanel-pe-var-bulk-fields">
                                        <div class="brikpanel-pe-input-group small brikpanel-pe-var-bulk-item" style="--bpe-ph:<?php echo (int) $bpe_ph_w($bpe_ph_price); ?>">
                                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                            <input type="text" id="bpe-bulk-price" data-price="1" placeholder="<?php echo esc_attr($bpe_ph_price); ?>" aria-label="<?php esc_attr_e('Price', 'brikpanel'); ?>" title="<?php esc_attr_e('Price for every variation', 'brikpanel'); ?>">
                                        </div>
                                        <div class="brikpanel-pe-input-group small brikpanel-pe-var-bulk-item" style="--bpe-ph:<?php echo (int) $bpe_ph_w($bpe_ph_sale); ?>">
                                            <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                            <?php // Short placeholder: "Sale Price" in full overruns this box in
                                                  // longer languages. The aria-label and the tooltip still carry
                                                  // the full name. ?>
                                            <input type="text" id="bpe-bulk-sale-price" data-price="1" placeholder="<?php echo esc_attr($bpe_ph_sale); ?>" aria-label="<?php esc_attr_e('Sale price', 'brikpanel'); ?>" title="<?php esc_attr_e('Sale price for every variation', 'brikpanel'); ?>">
                                        </div>
                                        <?php // ∞ mirrors the one on every row: pressed, Apply makes every
                                              // variation Unlimited. Pressing it swaps the "Stock"
                                              // placeholder for "Unlimited", so the box is sized for the
                                              // longer of the two. ?>
                                        <div class="brikpanel-pe-input-group small brikpanel-pe-var-bulk-item brikpanel-pe-var-bulk-stock" style="--bpe-ph:<?php echo (int) max($bpe_ph_w($bpe_ph_stock), $bpe_ph_w($bpe_ph_unlimited), $bpe_ph_w($bpe_ph_shared)); ?>">
                                            <input type="number" id="bpe-bulk-stock" min="0" placeholder="<?php echo esc_attr($bpe_ph_stock); ?>" data-placeholder="<?php echo esc_attr($bpe_ph_stock); ?>" aria-label="<?php esc_attr_e('Stock', 'brikpanel'); ?>" title="<?php esc_attr_e('Stock quantity for every variation', 'brikpanel'); ?>">
                                            <button type="button" class="brikpanel-pe-inf-btn" id="bpe-bulk-stock-inf" aria-pressed="false" aria-label="<?php echo esc_attr($bpe_bulk_inf_label); ?>" title="<?php echo esc_attr($bpe_bulk_inf_label); ?>"><svg class="bpe-ico-inf" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 12c-2-2.67-4-4-6-4a4 4 0 1 0 0 8c2 0 4-1.33 6-4Zm0 0c2 2.67 4 4 6 4a4 4 0 0 0 0-8c-2 0-4 1.33-6 4Z"/></svg><svg class="bpe-ico-link" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
                                        </div>
                                        <select id="bpe-bulk-active" class="brikpanel-pe-select small brikpanel-pe-var-bulk-item" aria-label="<?php esc_attr_e('Active', 'brikpanel'); ?>" title="<?php esc_attr_e('Active state for every variation', 'brikpanel'); ?>">
                                            <option value=""><?php esc_html_e('No change', 'brikpanel'); ?></option>
                                            <option value="1"><?php esc_html_e('Set active', 'brikpanel'); ?></option>
                                            <option value="0"><?php esc_html_e('Set inactive', 'brikpanel'); ?></option>
                                        </select>
                                        <button type="button" class="brikpanel-pe-btn primary small" id="bpe-apply-bulk" title="<?php esc_attr_e('Apply to all variations', 'brikpanel'); ?>"><?php esc_html_e('Apply', 'brikpanel'); ?></button>
                                    </div>
                                </div>
                                <?php // Sorting and defaults share ONE flex item on purpose. With two
                                      // separate items, flexbox packs greedily: on a card too narrow
                                      // for all three groups it would keep Sort on line 1 and drop
                                      // only the Defaults button, which reads like a mistake. Kept
                                      // together they either both fit beside the bulk edits or both
                                      // move to a tidy second row, and that decision follows the
                                      // translated widths by itself, with no breakpoint to guess. ?>
                                <div class="brikpanel-pe-var-tools-right">
                                <?php // The select's own placeholder ("Sort variations…") names the
                                      // control, so no heading is repeated beside it, and picking a
                                      // mode sorts right away instead of arming a second button —
                                      // that button was pure width on a one-line strip, and the sort
                                      // is only a reorder that Save has to confirm anyway. The select
                                      // snaps back to its placeholder so it keeps reading as an
                                      // action menu. Dragging is discoverable from the row handle's
                                      // own tooltip, and the toast after a reorder says the order is
                                      // kept on save — no help text needed here. ?>
                                <div class="brikpanel-pe-var-bulk-group brikpanel-pe-var-sort">
                                    <div class="brikpanel-pe-var-bulk-fields">
                                        <select id="bpe-var-sort" class="brikpanel-pe-select small" aria-label="<?php esc_attr_e('Sort variations', 'brikpanel'); ?>" title="<?php esc_attr_e('Sort variations', 'brikpanel'); ?>">
                                            <option value=""><?php esc_html_e('Sort variations…', 'brikpanel'); ?></option>
                                            <option value="attribute"><?php esc_html_e('Attribute order', 'brikpanel'); ?></option>
                                            <option value="name-asc"><?php esc_html_e('Name A to Z', 'brikpanel'); ?></option>
                                            <option value="name-desc"><?php esc_html_e('Name Z to A', 'brikpanel'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <?php // Default Form Values collapses behind one button: it holds one
                                      // select per variation axis, so inline it would be the one group
                                      // whose width grows with the product and would push the strip
                                      // onto a second line. The button carries a dot while any default
                                      // is set, so the collapsed state still reports itself. ?>
                                <div class="brikpanel-pe-var-defaults" id="bpe-var-defaults" style="display:none">
                                    <button type="button" class="brikpanel-pe-btn secondary small brikpanel-pe-var-defaults-toggle" id="bpe-var-defaults-toggle" aria-expanded="false" aria-controls="bpe-var-defaults-pop" aria-label="<?php esc_attr_e('Default form values', 'brikpanel'); ?>" title="<?php esc_attr_e('Choose which options are pre-selected on the product page. Leave blank for no default.', 'brikpanel'); ?>">
                                        <span class="brikpanel-pe-var-defaults-dot" aria-hidden="true"></span>
                                        <?php // Short label on purpose: the strip is one line and the full
                                              // name would be its widest item by far. The popover title, the
                                              // tooltip and the aria-label all say "Default Form Values". ?>
                                        <?php echo esc_html(_x('Defaults', 'variation tools button that opens the Default Form Values picker', 'brikpanel')); ?>
                                        <svg width="10" height="10" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M3 5l3 3 3-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                    </button>
                                    <div class="brikpanel-pe-var-defaults-pop" id="bpe-var-defaults-pop" hidden>
                                        <span class="brikpanel-pe-var-defaults-title"><?php esc_html_e('Default form values', 'brikpanel'); ?></span>
                                        <p class="brikpanel-pe-var-defaults-help"><?php esc_html_e('Choose which options are pre-selected on the product page. Leave blank for no default.', 'brikpanel'); ?></p>
                                        <div class="brikpanel-pe-var-defaults-row" id="bpe-var-defaults-row"></div>
                                    </div>
                                </div>
                                </div><!-- /.brikpanel-pe-var-tools-right -->
                            </div>
                            <?php // Field test B6: the row keeps only what is edited on every
                                  // variation (image, active, name, price, sale price, stock,
                                  // cost). The sale schedule, SKU and the optional fields (GTIN,
                                  // tax class, shipping class, supplier) live in the row's
                                  // details panel behind ▾. Up to 18 columns used to be crammed
                                  // into a ~770px card, and the overflow hid COGS, SKU, Image and
                                  // Delete in a scroll box nobody noticed. When even the slim row
                                  // does not fit (tablet, phone, long translations) the rows turn
                                  // into cards: front-end/shared/brikpanel-fit-table.js. ?>
                            <?php if ($bpe_lock_note) : ?>
                            <p class="brikpanel-pe-lock-note"><?php echo esc_html($bpe_lock_note['text']); ?> <a href="<?php echo esc_url($bpe_lock_note['url']); ?>"><?php echo esc_html($bpe_lock_note['link']); ?></a></p>
                            <?php endif; ?>
                            <div class="brikpanel-pe-var-table-wrap">
                                <table class="brikpanel-pe-var-table" id="bpe-var-table">
                                    <thead>
                                        <tr>
                                            <th class="var-drag-col" aria-hidden="true"></th>
                                            <th class="var-expand-col"><button type="button" class="var-expand-all" id="bpe-var-expand-all" aria-expanded="false" aria-label="<?php esc_attr_e('Show all details', 'brikpanel'); ?>" title="<?php esc_attr_e('Show all details', 'brikpanel'); ?>"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg></button></th>
                                            <th><?php esc_html_e('Variation', 'brikpanel'); ?></th>
                                            <th><?php esc_html_e('Price', 'brikpanel'); ?></th>
                                            <th><?php esc_html_e('Sale price', 'brikpanel'); ?></th>
                                            <th><?php esc_html_e('Stock', 'brikpanel'); ?></th>
                                            <?php if ($cogs_enabled) : ?><th><?php esc_html_e('COGS', 'brikpanel'); ?></th><?php endif; ?>
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
                <?php // The sale's start and end dates wait behind "Schedule the
                      // sale" while both are empty, as in WooCommerce's own box:
                      // most sales have none. The fields stay in the page, so the
                      // save reads them as always; a product that has dates opens
                      // with them showing. ?>
                <div class="brikpanel-pe-card<?php echo ($data['sale_from'] === '' && $data['sale_to'] === '') ? ' is-sale-folded' : ''; ?>" id="bpe-pricing-card">
                    <div class="brikpanel-pe-row">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-price"><?php esc_html_e('Price', 'brikpanel'); ?></label>
                            <div class="brikpanel-pe-input-group">
                                <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                <?php
                                // A bundle's price is its plugin's business (worked out
                                // from its contents, or a fixed price set on purpose):
                                // never required here. The script follows type changes.
                                $bpe_price_required = $require_price && !$bpe_lock_original && !in_array($product_type_current, $bpe_type_guard['own_panel'], true);
                                ?>
                                <input type="text" id="bpe-price" value="<?php echo esc_attr($reg_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00"<?php echo $bpe_price_required ? ' data-required="1"' : ''; ?> data-price="1"<?php echo $bpe_lock_original ? ' disabled' : ''; ?>>
                            </div>
                            <div class="brikpanel-pe-field-error"></div>
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-price"><?php esc_html_e('Sale price', 'brikpanel'); ?></label>
                            <div class="brikpanel-pe-input-group">
                                <span class="brikpanel-pe-input-prefix"><?php echo esc_html($currency); ?></span>
                                <input type="text" id="bpe-sale-price" value="<?php echo esc_attr($sale_price); ?>" placeholder="0<?php echo esc_attr($decimal_sep); ?>00" data-price="1"<?php echo $bpe_lock_original ? ' disabled' : ''; ?>>
                            </div>
                            <button type="button" class="brikpanel-pe-link brikpanel-pe-sale-schedule" id="bpe-sale-schedule" aria-controls="bpe-sale-dates" aria-expanded="false"<?php echo $bpe_lock_original ? ' disabled' : ''; ?>><?php esc_html_e('Schedule the sale', 'brikpanel'); ?></button>
                        </div>
                    </div>

                    <div class="brikpanel-pe-row brikpanel-pe-sale-dates" id="bpe-sale-dates">
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-from"><?php esc_html_e('Sale start date', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-sale-dates', __('Schedule your sale in advance. Leave the dates empty to start it immediately or keep it running indefinitely.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                            <input type="text" id="bpe-sale-from" value="<?php echo esc_attr($data['sale_from']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD (optional)', 'brikpanel'); ?>" autocomplete="off"<?php echo $bpe_lock_original ? ' disabled' : ''; ?>>
                        </div>
                        <div class="brikpanel-pe-field">
                            <label for="bpe-sale-to"><?php esc_html_e('Sale end date', 'brikpanel'); ?></label>
                            <input type="text" id="bpe-sale-to" value="<?php echo esc_attr($data['sale_to']); ?>" placeholder="<?php esc_attr_e('YYYY-MM-DD (optional)', 'brikpanel'); ?>" autocomplete="off"<?php echo $bpe_lock_original ? ' disabled' : ''; ?>>
                        </div>
                    </div>
                    <?php if ($bpe_lock_note) : ?>
                    <p class="brikpanel-pe-lock-note"><?php echo esc_html($bpe_lock_note['text']); ?> <a href="<?php echo esc_url($bpe_lock_note['url']); ?>"><?php echo esc_html($bpe_lock_note['link']); ?></a></p>
                    <?php endif; ?>
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
                            <label for="bpe-cogs"><?php esc_html_e('Cost of goods', 'brikpanel'); ?><?php
                                echo self::help_tip('bpe-tip-cogs', $bp_vendor_field_on // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip().
                                    ? __('What this product costs you, and where you buy it from. Used in profit calculations and stock orders.', 'brikpanel')
                                    : __('The cost you pay for this product. Used to calculate profit margins.', 'brikpanel'));
                            ?></label>
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
                                <option value="0"><?php esc_html_e('None', 'brikpanel'); ?></option>
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
                                <span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-vendor-sku">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-vendor-sku" role="tooltip"><?php esc_html_e('The supplier\'s product code. Optional; stock orders use it to match this product on incoming POs.', 'brikpanel'); ?></span></span>
                            </label>
                            <input type="text" id="bpe-vendor-sku" name="bp_vendor_sku" value="<?php echo esc_attr($bp_current_v_sku); ?>" placeholder="<?php esc_attr_e('Optional', 'brikpanel'); ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $section_html['cogs'] = ob_get_clean(); endif; ?>

                <?php if (in_array('inventory', $visible, true)) : ob_start(); ?>
                <!-- Inventory. A simple product tracks its quantity here. A
                     variable product shows the "Shared stock" switch instead
                     (WooCommerce's product-level stock the variations sell
                     from) and its own SKU; each variation keeps its own stock
                     and SKU in the variation table. -->
                <?php
                $bp_manage = !empty($data['manage_stock']);
                $bp_shared = !empty($data['shared_stock']);
                // A variable product whose variation rows are switched off in
                // settings: without the rows on the page, the shared switch
                // would decide stock for variations nobody can see, so the
                // card shows the SKU only (the JS keeps it that way).
                $bp_inv_sku_only = !empty($data['is_variable']) && !in_array('variations', $visible, true);
                $bp_inv_var_mode = !empty($data['is_variable']) && !$bp_inv_sku_only;
                $bp_inv_on       = $bp_inv_sku_only ? false : ($bp_inv_var_mode ? $bp_shared : $bp_manage);
                ?>
                <div class="brikpanel-pe-card" id="bpe-inventory-card"<?php echo $bp_inv_sku_only ? ' data-bpe-sku-only="1"' : ''; ?>>
                    <!-- Track quantity (manage stock). When on, the merchant
                         enters a quantity and WooCommerce derives the stock
                         status; when off, the status is picked directly. This
                         mirrors WooCommerce's native Inventory tab and avoids
                         the "managed + qty 0 can't be in stock" trap. -->
                    <div class="brikpanel-pe-toggle-row" id="bpe-manage-stock-row"<?php echo ($bp_inv_var_mode || $bp_inv_sku_only) ? ' hidden' : ''; ?>>
                        <span class="brikpanel-pe-toggle-label">
                            <span><span id="bpe-manage-stock-title"><?php esc_html_e('Track quantity', 'brikpanel'); ?></span><?php echo self::help_tip('bpe-manage-stock-help', __('Manage stock at product level. Turn off to simply mark the product in or out of stock.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></span>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-manage-stock" aria-labelledby="bpe-manage-stock-title" aria-describedby="bpe-manage-stock-help" <?php checked($bp_manage); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <!-- Shared stock (variable products): every variation that
                         does not count its own stock sells from this one
                         quantity. A switch of its own, so turning a product
                         simple and back never mixes it up with "Track quantity". -->
                    <div class="brikpanel-pe-toggle-row" id="bpe-shared-stock-row"<?php echo $bp_inv_var_mode ? '' : ' hidden'; ?>>
                        <span class="brikpanel-pe-toggle-label">
                            <span><span id="bpe-shared-stock-title"><?php esc_html_e('Shared stock', 'brikpanel'); ?></span><?php echo self::help_tip('bpe-shared-stock-help', __('All variations sell from one quantity instead of each having its own.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></span>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-shared-stock" aria-labelledby="bpe-shared-stock-title" aria-describedby="bpe-shared-stock-help" <?php checked($bp_shared); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <!-- Pack sizes (only with shared stock): each variation
                         can take more than one unit of the shared quantity,
                         a 5-pack takes 5. Off by default; on, every row that
                         sells from the shared stock gets a box for its units
                         (includes/brikpanel-pack-sizes.php does the counting). -->
                    <div class="brikpanel-pe-toggle-row" id="bpe-pack-sizes-row"<?php echo ($bp_inv_var_mode && $bp_shared) ? '' : ' hidden'; ?>>
                        <span class="brikpanel-pe-toggle-label">
                            <span><span id="bpe-pack-sizes-title"><?php esc_html_e('Pack sizes', 'brikpanel'); ?></span><?php echo self::help_tip('bpe-pack-sizes-help', __('Set how many units each variation takes from the shared stock, like 5 for a 5-pack.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></span>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-pack-sizes" aria-labelledby="bpe-pack-sizes-title" aria-describedby="bpe-pack-sizes-help" <?php checked(!empty($data['pack_sizes'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-row brikpanel-pe-row-3">
                        <div class="brikpanel-pe-field" id="bpe-stock-qty-field"<?php echo $bp_inv_on ? '' : ' style="display:none"'; ?>>
                            <label for="bpe-stock"><?php esc_html_e('Stock quantity', 'brikpanel'); ?></label>
                            <input type="number" id="bpe-stock" value="<?php echo esc_attr($data['stock_quantity']); ?>" data-original="<?php echo esc_attr($data['stock_quantity']); ?>" min="0" placeholder="0">
                        </div>
                        <div class="brikpanel-pe-field" id="bpe-backorders-field"<?php echo $bp_inv_on ? '' : ' style="display:none"'; ?>>
                            <label for="bpe-backorders"><?php esc_html_e('Allow backorders?', 'brikpanel'); ?></label>
                            <select id="bpe-backorders" class="brikpanel-pe-select">
                                <option value="no" <?php selected($data['backorders'], 'no'); ?>><?php esc_html_e('Do not allow', 'brikpanel'); ?></option>
                                <?php // A stored "notify" is offered even with the option off,
                                      // or the first save would quietly turn it into "Do not allow". ?>
                                <?php if ($backorder_notify_on || 'notify' === $data['backorders']) : ?>
                                <option value="notify" <?php selected($data['backorders'], 'notify'); ?>><?php esc_html_e('Allow, but notify customer', 'brikpanel'); ?></option>
                                <?php endif; ?>
                                <option value="yes" <?php selected($data['backorders'], 'yes'); ?>><?php esc_html_e('Allow', 'brikpanel'); ?></option>
                            </select>
                        </div>
                        <div class="brikpanel-pe-field" id="bpe-stock-status-field"<?php echo ($bp_inv_on || $bp_inv_var_mode || $bp_inv_sku_only) ? ' style="display:none"' : ''; ?>>
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
                                <span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-sku">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-sku" role="tooltip"><?php esc_html_e('Important for marketplace synchronization. Should match your Trendyol/Hepsiburada product code.', 'brikpanel'); ?></span></span>
                            </label>
                            <input type="text" id="bpe-sku" value="<?php echo esc_attr($data['sku']); ?>" placeholder="<?php esc_attr_e('e.g.: TSH-001', 'brikpanel'); ?>">
                            <?php // Variable products: gives every variation without a SKU of
                                  // its own one built from this SKU and its options. New
                                  // variations no longer get one automatically; until then they
                                  // use the product's SKU, as in WooCommerce. ?>
                            <button type="button" id="bpe-gen-var-skus" class="brikpanel-btn brikpanel-btn--secondary bpe-gen-var-skus" hidden><?php esc_html_e('Generate variation SKUs', 'brikpanel'); ?></button>
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
                                <label><?php esc_html_e('Backorder behavior', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-backorder', __('Notification adds a customer-visible note to backorder line items at checkout.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
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
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php $section_html['inventory'] = ob_get_clean(); endif; ?>

                <?php if (in_array('gtin', $bpe_build, true)) : ob_start(); ?>
                <!-- GTIN / Barcode (opt-in via settings; hidden for variable
                     products, which carry a GTIN per variation instead). -->
                <div class="brikpanel-pe-card" id="bpe-gtin-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-gtin">
                            <?php esc_html_e('GTIN, UPC, EAN, or ISBN', 'brikpanel'); ?>
                            <span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-gtin">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-gtin" role="tooltip"><?php esc_html_e('The global product barcode. Used by Google Shopping and marketplace feeds. Leave empty if you do not have one.', 'brikpanel'); ?></span></span>
                        </label>
                        <input type="text" id="bpe-gtin" value="<?php echo esc_attr($data['global_unique_id']); ?>" placeholder="<?php esc_attr_e('e.g.: 0012345678905', 'brikpanel'); ?>" inputmode="numeric" autocomplete="off">
                    </div>
                </div>
                <?php $section_html['gtin'] = ob_get_clean(); endif; ?>

                <?php if (in_array('tax', $bpe_build, true)) : ob_start(); ?>
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
                            <label for="bpe-tax-class"><?php esc_html_e('Tax class', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-tax-class', __('Variable products: each variation can override the tax class from the variations table.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                            <select id="bpe-tax-class" class="brikpanel-pe-select">
                                <?php foreach ($tax_class_options as $tc_slug => $tc_label) : ?>
                                    <option value="<?php echo esc_attr($tc_slug); ?>" <?php selected($data['tax_class'], $tc_slug); ?>><?php echo esc_html($tc_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <?php $section_html['tax'] = ob_get_clean(); endif; ?>

                <?php if (in_array('sold_individually', $bpe_build, true)) : ob_start(); ?>
                <!-- Sold individually — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-sold-individually-card">
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <span><?php esc_html_e('Sold individually', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-sold-individually', __('Limit purchases to one of this product per order.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></span>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-sold-individually" <?php checked(!empty($data['sold_individually'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                </div>
                <?php $section_html['sold_individually'] = ob_get_clean(); endif; ?>

                <?php if (in_array('linked', $bpe_build, true)) : ob_start(); ?>
                <!-- Linked products (upsells & cross-sells) — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-linked-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-upsells-search">
                            <?php esc_html_e('Upsells', 'brikpanel'); ?>
                            <span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-upsells">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-upsells" role="tooltip"><?php esc_html_e('Products promoted on the product page as a higher-value alternative.', 'brikpanel'); ?></span></span>
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
                            <span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="<?php esc_attr_e('More information', 'brikpanel'); ?>" aria-describedby="bpe-tip-crosssells">?<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="bpe-tip-crosssells" role="tooltip"><?php esc_html_e('Products promoted in the cart alongside this one.', 'brikpanel'); ?></span></span>
                        </label>
                        <div class="brikpanel-pe-linked" data-field="cross_sells">
                            <div class="brikpanel-pe-linked-chips" id="bpe-crosssells-chips"></div>
                            <input type="text" id="bpe-crosssells-search" class="brikpanel-pe-linked-search" placeholder="<?php esc_attr_e('Search products...', 'brikpanel'); ?>" autocomplete="off">
                            <div class="brikpanel-pe-linked-results" hidden></div>
                        </div>
                    </div>
                </div>
                <?php $section_html['linked'] = ob_get_clean(); endif; ?>

                <?php if (in_array('advanced', $bpe_build, true)) : ob_start(); ?>
                <!-- Advanced (purchase note, reviews, menu order) — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-advanced-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-purchase-note"><?php esc_html_e('Purchase note', 'brikpanel'); ?></label>
                        <textarea id="bpe-purchase-note" rows="3" placeholder="<?php esc_attr_e('Note sent to the customer after they buy this product.', 'brikpanel'); ?>"><?php echo esc_textarea($data['purchase_note']); ?></textarea>
                    </div>
                    <div class="brikpanel-pe-toggle-row">
                        <span class="brikpanel-pe-toggle-label">
                            <span><?php esc_html_e('Enable reviews', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-reviews', __('Allow customers to leave reviews on this product.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></span>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-reviews-allowed" <?php checked(!empty($data['reviews_allowed'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <div class="brikpanel-pe-field">
                        <label for="bpe-menu-order"><?php esc_html_e('Menu order', 'brikpanel'); ?></label>
                        <input type="number" id="bpe-menu-order" value="<?php echo esc_attr($data['menu_order']); ?>" data-original="<?php echo esc_attr($data['menu_order']); ?>" step="1" placeholder="0">
                    </div>
                </div>
                <?php $section_html['advanced'] = ob_get_clean(); endif; ?>

                <?php if (in_array('category', $visible, true)) : ob_start(); ?>
                <!-- Category -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Category', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-cat-wrap">
                        <input type="text" class="brikpanel-pe-cat-search" id="bpe-cat-search" placeholder="<?php esc_attr_e('Search categories...', 'brikpanel'); ?>">
                        <div class="brikpanel-pe-cat-list" id="bpe-cat-list">
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
                            <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($bpe_pc_badge); ?></span><?php echo self::help_tip('bpe-tip-primary-cat', __('The category used in the %category% permalink and breadcrumbs when a product has several categories.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?>
                        </label>
                        <select id="bpe-seo-primary-cat" class="brikpanel-pe-select">
                            <option value="none"><?php esc_html_e('None (will disable this feature)', 'brikpanel'); ?></option>
                            <?php foreach ($bpe_sel_cat_names as $bpe_cid => $bpe_cname) : ?>
                                <option value="<?php echo esc_attr($bpe_cid); ?>" <?php selected((string) $data['primary_cat'], (string) $bpe_cid); ?>><?php echo esc_html($bpe_cname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <a href="#" id="bpe-add-cat-toggle" class="brikpanel-pe-link"><?php esc_html_e('+ Add new category', 'brikpanel'); ?></a>
                    <div class="brikpanel-pe-collapse" id="bpe-new-cat-section">
                        <div>
                            <div class="brikpanel-pe-inline-form">
                                <input type="text" id="bpe-new-cat-name" placeholder="<?php esc_attr_e('Category name', 'brikpanel'); ?>">
                                <select id="bpe-new-cat-parent">
                                    <option value="0"><?php esc_html_e('No parent', 'brikpanel'); ?></option>
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
                            <?php $this->render_category_checklist($brands, $data['brand_ids'], 0, 0, 'brand_ids[]', __('No brands found.', 'brikpanel')); ?>
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
                                    <option value="0"><?php esc_html_e('No parent', 'brikpanel'); ?></option>
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

                <?php if (in_array('tags', $bpe_build, true)) : ob_start(); ?>
                <!-- Tags -->
                <div class="brikpanel-pe-card">
                    <label><?php esc_html_e('Tags', 'brikpanel'); ?></label>
                    <div class="brikpanel-pe-tag-input-wrap" id="bpe-tags-wrap">
                        <input type="text" id="bpe-tag-input" placeholder="<?php esc_attr_e('Type and press Enter, or paste a comma-separated list...', 'brikpanel'); ?>" autocomplete="off">
                    </div>
                    <div class="brikpanel-pe-tag-suggestions" id="bpe-tag-suggestions"></div>
                </div>
                <?php $section_html['tags'] = ob_get_clean(); endif; ?>

                <?php // Virtual and Digital (the "Digital product" section) render under
                      // the product type in the name card above; this slot stays empty. ?>

                <?php if (in_array('short_desc', $visible, true)) : ob_start(); ?>
                <!-- Short Description -->
                <?php $pe_short_desc_html = self::editor_display_html($data['short_description']); ?>
                <div class="brikpanel-pe-card brikpanel-pe-card-wide">
                    <div class="brikpanel-pe-field" data-editor-field="short-desc">
                        <label><?php esc_html_e('Short description', 'brikpanel'); ?></label>
                        <?php echo $pe_editor_toolbar; ?>
                        <div class="brikpanel-pe-editor" id="bpe-short-desc" contenteditable="true" data-placeholder="<?php esc_attr_e('Brief product summary...', 'brikpanel'); ?>"><?php echo wp_kses_post($pe_short_desc_html); ?></div>
                        <?php /* Seeded with the value exactly as stored, not the paragraph-normalised
                                 one: JS keeps it as the pristine copy so an untouched field is saved
                                 back byte-for-byte instead of being rewritten just by opening it. */ ?>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-short-desc-source" spellcheck="false" hidden><?php echo esc_textarea($data['short_description']); ?></textarea>
                    </div>
                </div>
                <?php $section_html['short_desc'] = ob_get_clean(); endif; ?>

                <?php if (in_array('description', $visible, true)) : ob_start(); ?>
                <!-- Description -->
                <?php $pe_desc_html = self::editor_display_html($data['description']); ?>
                <div class="brikpanel-pe-card brikpanel-pe-card-wide">
                    <div class="brikpanel-pe-field" data-editor-field="description">
                        <label><?php esc_html_e('Product description', 'brikpanel'); ?></label>
                        <?php echo $pe_editor_toolbar; ?>
                        <div class="brikpanel-pe-editor" id="bpe-description" contenteditable="true"><?php echo wp_kses_post($pe_desc_html); ?></div>
                        <?php /* See the short description above: seeded with the stored value so an
                                 untouched description is never rewritten by a save. */ ?>
                        <textarea class="brikpanel-pe-editor-source" id="bpe-description-source" spellcheck="false" hidden><?php echo esc_textarea($data['description']); ?></textarea>
                    </div>
                </div>
                <?php $section_html['description'] = ob_get_clean(); endif; ?>

                <?php if (in_array('weight', $bpe_build, true)) : ob_start(); ?>
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

                <?php if (in_array('dimensions', $bpe_build, true)) : ob_start(); ?>
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

                <?php if (in_array('shipping_class', $bpe_build, true)) : ob_start(); ?>
                <!-- Shipping class — opt-in via settings. -->
                <div class="brikpanel-pe-card" id="bpe-shipping-class-card">
                    <div class="brikpanel-pe-field">
                        <label for="bpe-shipping-class"><?php esc_html_e('Shipping class', 'brikpanel'); ?><?php echo self::help_tip('bpe-tip-shipping-class', __('Shipping classes let certain shipping methods group similar products together. Variable products: each variation can override this from the variations table.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?></label>
                        <select id="bpe-shipping-class" class="brikpanel-pe-select">
                            <?php foreach ($shipping_class_options as $sc_slug => $sc_label) : ?>
                                <option value="<?php echo esc_attr($sc_slug); ?>" <?php selected($data['shipping_class'], $sc_slug); ?>><?php echo esc_html($sc_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php $section_html['shipping_class'] = ob_get_clean(); endif; ?>

                <?php // The SEO card has three outcomes, decided by $active_seo
                      // and $seo_in_manual (both resolved at the top of
                      // render_page()):
                      //   plugin active, not hand-placed → its full native
                      //     metabox inside the card, so users keep every
                      //     feature it ships with (schema, social, redirects,
                      //     analysis, readability score)
                      //   no plugin active → a unified 5-field form that writes
                      //     to every supported plugin's meta keys at once, so
                      //     the data survives whichever one is installed later
                      //   plugin hand-placed in the section picker → nothing at
                      //     all, because the metabox renders at its chosen slot
                      //     and a second SEO surface here would only disagree
                      //     with it
                if (in_array('seo', $visible, true) && !$seo_in_manual) : ob_start();
                    if ($active_seo) : ?>
                <!-- SEO — rendered by active plugin (<?php echo esc_html($active_seo['label']); ?>) -->
                <?php // SEOPress's panel is a three-column layout (tab sidebar,
                      // form, Google preview) and is the one SEO surface here
                      // that genuinely benefits from a wider card, so it opts
                      // into widescreen the same way the variations table and
                      // the description editors do. Inert unless the merchant
                      // already switched Widescreen on (default off) and is on
                      // a wide enough viewport, so this adds no setting and
                      // changes nothing for anyone else. Deliberately gated on
                      // seopress_owns_seo_card() rather than $active_seo: the
                      // Yoast / Rank Math / All in One SEO cards are short-field
                      // forms that read worse over long lines.
                      //
                      // The marker used to be `brikpanel-pe-card-wide`, back when
                      // widescreen widened only opt-in cards. Every card takes the
                      // full column now, so the question this answers has changed:
                      // it is no longer "should this card be wide" but "does this
                      // panel bring its own wide layout". SEOPress does — three
                      // columns tuned in 3.2.92 — so its body is left alone, while
                      // the short-field panels keep the narrower measure they were
                      // designed and tested at. Same condition, honest name.
                      $bpe_seo_card_classes = 'brikpanel-pe-card brikpanel-pe-seo-card brikpanel-pe-seo-card--plugin brikpanel-pe-metaboxes-wrap';
                      if (self::seopress_owns_seo_card()) { $bpe_seo_card_classes .= ' brikpanel-pe-seo-panel-native'; } ?>
                <?php // Folded to its title line and the search preview until the
                      // person opens it, as Shopify's "Search engine listing" card
                      // does; open once, it stays open for them (open_boxes()).
                      // Desktop only: a phone shows the card as a page anyway.
                      $bpe_seo_open = isset(self::open_boxes()['seo']); ?>
                <div class="<?php echo esc_attr($bpe_seo_card_classes . ' brikpanel-pe-foldcard' . ($bpe_seo_open ? '' : ' is-folded')); ?>" data-seo-plugin="<?php echo esc_attr($active_seo['slug']); ?>" data-bpe-fold="seo">
                    <div class="brikpanel-pe-foldhead">
                        <label>
                            <?php esc_html_e('SEO', 'brikpanel'); ?>
                            <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($active_seo['label']); ?></span>
                        </label>
                        <?php echo self::fold_button('bpe-seo-body', $bpe_seo_open); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fold_button(). ?>
                    </div>
                    <?php // SEOPress's universal panel draws its own "Google
                          // Snippet Preview", with the title/description
                          // templates resolved server-side. Ours on top of it
                          // would be a second, worse copy: it reads the raw
                          // stored meta, and when SEOPress's own key is still
                          // empty the shared fallback chain fills it from
                          // whichever other SEO plugin left a value behind,
                          // so the strip could show a Rank Math template
                          // (`%title% %sep% %sitename%`) above a SEOPress
                          // panel. One preview, owned by whoever draws it.
                    if (!self::seopress_owns_seo_card()) : ?>
                    <div class="brikpanel-pe-seo-preview" id="bpe-seo-preview">
                        <span class="brikpanel-pe-seo-preview-title" id="bpe-seo-preview-title"><?php echo esc_html($data['seo_title'] ?: $data['name'] ?: __('Product title', 'brikpanel')); ?></span>
                        <span class="brikpanel-pe-seo-preview-url"><?php echo esc_html($is_edit ? get_permalink($product_id) : home_url('/product/...')); ?></span>
                        <span class="brikpanel-pe-seo-preview-desc" id="bpe-seo-preview-desc"><?php echo esc_html($data['seo_description'] ?: brikpanel_substr(wp_strip_all_tags($data['short_description'] ?: $data['description']), 0, 160)); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="brikpanel-pe-seo-plugin-inner brikpanel-pe-foldbody" id="bpe-seo-body">
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
                <?php $bpe_seo_open = isset(self::open_boxes()['seo']); ?>
                <div class="brikpanel-pe-card brikpanel-pe-seo-card brikpanel-pe-foldcard<?php echo $bpe_seo_open ? '' : ' is-folded'; ?>" data-bpe-fold="seo">
                    <div class="brikpanel-pe-foldhead">
                        <label>
                            <?php esc_html_e('SEO', 'brikpanel'); ?>
                            <?php if ($unified_analyzer !== null) : ?>
                            <span class="brikpanel-pe-seo-plugin-badge"><?php echo esc_html($unified_analyzer['label']); ?></span>
                            <?php endif; ?>
                        </label>
                        <?php echo self::fold_button('bpe-seo-body', $bpe_seo_open); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fold_button(). ?>
                    </div>
                    <div class="brikpanel-pe-seo-preview" id="bpe-seo-preview">
                        <span class="brikpanel-pe-seo-preview-title" id="bpe-seo-preview-title"><?php echo esc_html($data['seo_title'] ?: $data['name'] ?: __('Product title', 'brikpanel')); ?></span>
                        <span class="brikpanel-pe-seo-preview-url"><?php echo esc_html($is_edit ? get_permalink($product_id) : home_url('/product/...')); ?></span>
                        <span class="brikpanel-pe-seo-preview-desc" id="bpe-seo-preview-desc"><?php echo esc_html($data['seo_description'] ?: brikpanel_substr(wp_strip_all_tags($data['short_description'] ?: $data['description']), 0, 160)); ?></span>
                    </div>
                    <div class="brikpanel-pe-foldbody" id="bpe-seo-body">
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
                            <strong><?php esc_html_e('Hide from search engines', 'brikpanel'); ?></strong><?php echo self::help_tip('bpe-tip-noindex', __('Adds noindex: the product stays accessible but search engines will not list it.', 'brikpanel')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in help_tip(). ?>
                        </span>
                        <label class="brikpanel-pe-switch">
                            <input type="checkbox" id="bpe-seo-noindex" <?php checked(!empty($data['seo_noindex'])); ?>>
                            <span class="brikpanel-pe-slider"></span>
                        </label>
                    </div>
                    <p class="brikpanel-pe-help-text">
                        <?php if ($unified_analyzer !== null) :
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
                    </div><!-- /#bpe-seo-body -->
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
                $picked_mb_ids = $manual_mb_ids;
                // Drop the active SEO plugin's metabox IDs only when the SEO
                // card above actually rendered them — i.e. the card was shown
                // AND the admin did not hand-place the metabox. Stripping them
                // unconditionally was what made a hand-placed pick vanish: the
                // card stood down for it, then this removed it from the picks,
                // so Rank Math rendered nowhere at all.
                if (in_array('seo', $visible, true) && !empty($active_seo) && !$seo_in_manual) {
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

                // Hand-placing the SEO plugin's own metabox stands the SEO card
                // down, and the card is what normally emits the scaffold the
                // analysers read. Without it the plugin grades an empty product:
                // no title, body, short description or slug, so every keyword
                // and length check fails and the score reads near zero on a page
                // that is actually fine. The scaffold is clipped out of view, so
                // emitting it here costs nothing visually, and the helper's own
                // guard keeps it to one copy per page.
                // Yoast's own metabox reaches this route the same way, and the
                // Yoast WooCommerce collector it feeds crashes without the
                // hidden #product-type node the SEO card normally emits — see
                // render_yoast_woo_compat_shim(). Both helpers guard themselves,
                // so calling them here can never double up with the card.
                if ($seo_in_manual) {
                    $seo_manual_post = get_post($product_id);
                    self::render_yoast_woo_compat_shim($seo_manual_post);
                    self::render_seo_native_bridge($seo_manual_post);
                }

                // Emit each captured section in the admin-configured order.
                // Two fixed anchors are still injected mid-flight:
                //   - The "Additional product data" card with $wc_extras_position
                //     'middle' renders right after pricing — matching the
                //     historical "between pricing and inventory" placement.
                //   - The developer middle-slot boxes render right after seo.
                // The Cost of goods card is now a regular section (slug `cogs`)
                // and obeys the picker's order/visibility like any other.
                // `mb:<id>` slugs echo their pre-built third-party card inline.
                // Cards that hold a handful of short settings go to the side
                // column; everything else — the title, the permalink under it,
                // images, variations, both description editors, the SEO card and
                // any embedded third-party panel — stays in the main column,
                // because their content is what asked for the width in the first
                // place. The merchant's own order is preserved WITHIN each
                // column: this splits the list, it never reorders it.
                $bpe_side_slugs = [
                    'pricing', 'cogs', 'inventory', 'gtin', 'tax', 'sold_individually',
                    'category', 'brand', 'tags', 'digital', 'weight', 'dimensions',
                    'shipping_class', 'linked', 'advanced',
                ];
                // Two pairs of short cards share one card when they stand next to
                // each other in the merchant's order (the default order has
                // both): the price with the cost of goods, one row of three
                // boxes; the category with the brand, side by side. Each keeps
                // its own section card inside the pair, so the sections list,
                // the editor script and the phone layout, which moves category
                // and brand to a page of their own, see the same cards as
                // before. On a phone the pair is no box at all (display:
                // contents), and the cards look as they always did.
                $bpe_order   = array_values($visible);
                $bpe_pair_of = ['pricing' => 'cogs', 'cogs' => 'pricing', 'category' => 'brand', 'brand' => 'category'];
                $bpe_paired  = [];
                $bpe_side_html = '';
                foreach ($bpe_order as $bpe_i => $rendered_slug) {
                    if (isset($bpe_paired[$rendered_slug])) {
                        continue; // Already out, with its pair.
                    }
                    $bpe_emitted_now = [$rendered_slug];
                    if (isset($section_html[$rendered_slug]) && $section_html[$rendered_slug] !== '') {
                        $bpe_marked = self::mark_section($section_html[$rendered_slug], $rendered_slug);
                        $bpe_next   = isset($bpe_order[$bpe_i + 1]) ? $bpe_order[$bpe_i + 1] : '';
                        if (isset($bpe_pair_of[$rendered_slug]) && $bpe_pair_of[$rendered_slug] === $bpe_next
                            && isset($section_html[$bpe_next]) && $section_html[$bpe_next] !== '') {
                            $bpe_is_price = in_array($rendered_slug, ['pricing', 'cogs'], true);
                            // With the supplier beside the cost the price and the
                            // cost each fill a row of their own (is-stacked).
                            $bpe_marked = '<div class="brikpanel-pe-pair brikpanel-pe-pair--' . ($bpe_is_price ? 'price' : 'org') . ($bpe_is_price && $bp_vendor_field_on ? ' is-stacked' : '') . '">'
                                . $bpe_marked . self::mark_section($section_html[$bpe_next], $bpe_next) . '</div>';
                            $bpe_paired[$bpe_next] = true;
                            $bpe_emitted_now[]     = $bpe_next;
                        }
                        if ($bpe_widescreen && in_array($rendered_slug, $bpe_side_slugs, true)) {
                            $bpe_side_html .= $bpe_marked;
                        } else {
                            echo $bpe_marked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered section markup, escaped at build time.
                        }
                    } elseif (strpos($rendered_slug, 'mb:') === 0) {
                        $mb_id = substr($rendered_slug, 3);
                        if (!empty($mb_cards['html'][$mb_id]) && empty($mb_emitted[$mb_id])) {
                            echo $mb_cards['html'][$mb_id];
                            $mb_emitted[$mb_id] = true;
                        }
                    }
                    if (in_array('pricing', $bpe_emitted_now, true)) {
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

                // Last on the page: the switched-off sections, folded.
                $this->render_hidden_box($section_html, $bpe_held, (int) $product_id);
                ?>
            <?php if ($bpe_widescreen) : ?>
            </div><!-- .brikpanel-pe-col-main -->
            <?php // Kept even when empty: an empty flex column takes no space, and
            // dropping it would make the grid track collapse differently
            // depending on which sections the merchant enabled. ?>
            <div class="brikpanel-pe-col-side"><?php echo $bpe_side_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered section markup, escaped at build time. ?></div>
            <?php endif; ?>

            </div><!-- .brikpanel-pe-content -->

            <?php
            // Phones only (the app bar above says why): the status opens a sheet
            // with the status, password, publish date, catalog visibility and
            // featured switch; the save button presses #bpe-publish.
            $bpe_ph_status_label = isset($labels[$effective_status]) ? $labels[$effective_status] : __('Draft', 'brikpanel');
            ?>
            <div class="brikpanel-pe-ph-bar" id="bpe-ph-bar">
                <button type="button" class="brikpanel-btn brikpanel-btn--secondary brikpanel-pe-ph-status" id="bpe-ph-status" aria-haspopup="dialog">
                    <span class="brikpanel-pe-ph-dot" data-status="<?php echo esc_attr($effective_status); ?>" aria-hidden="true"></span>
                    <span class="brikpanel-pe-ph-status-label" id="bpe-ph-status-label"><?php echo esc_html($bpe_ph_status_label); ?></span>
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 8 4 4 4-4"/></svg>
                </button>
                <button type="button" class="brikpanel-btn brikpanel-btn--primary brikpanel-pe-ph-save" id="bpe-ph-save">
                    <span id="bpe-ph-save-label"><?php echo esc_html($bpe_publish_label); ?></span>
                    <span class="brikpanel-pe-ph-dirty" aria-hidden="true"></span>
                </button>
            </div>
        </div><!-- .brikpanel-pe -->
        </div><!-- .wrap -->

        <?php
        // Worked out after the field capture (see $bpe_type_guard), so added to
        // the data here rather than where it is first built.
        $bpe_type_guard_js = wp_json_encode([
            'own_panel'     => isset($bpe_type_guard['own_panel']) ? array_values($bpe_type_guard['own_panel']) : [],
            'unrepresented' => isset($bpe_type_guard['unrepresented']) ? array_values($bpe_type_guard['unrepresented']) : [],
            'require_price' => $require_price,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <script>var brikpanelProductData = <?php echo $js_data; ?>;brikpanelProductData.type_guard = <?php echo $bpe_type_guard_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with the HEX flags, as $js_data. ?>;</script>
        <?php
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Gives a section's card its slug (data-bpe-section) on its first tag, so
     * the phone layout can tell the cards apart and gather the rarely used ones
     * into pages (brikpanel-product-editor.js, "PHONE"). Wider screens never
     * read it. A leading HTML comment is skipped: it starts with `<!`.
     *
     * @param string $html Pre-rendered section markup.
     * @param string $slug Section slug.
     * @return string
     */
    private static function mark_section($html, $slug) {
        return (string) preg_replace('/<([a-z][a-z0-9]*)\b/i', '<$1 data-bpe-section="' . esc_attr($slug) . '"', (string) $html, 1);
    }

    /**
     * A folding card's id: `seo` for the SEO card, `mb:<metabox id>` for
     * another plugin's box (the same names the sections list uses), `plugins`
     * for the "Fields from your other plugins" box and `hidden` for the
     * "Hidden sections" box.
     *
     * @param mixed $id
     * @return bool
     */
    private static function is_fold_box_id($id) {
        return is_string($id) && (bool) preg_match('/^(seo|plugins|hidden|mb:[A-Za-z0-9_.:-]{1,150})$/', $id);
    }

    /**
     * The folding cards the current person keeps open, as a set.
     *
     * @param bool $fresh Read again instead of the copy from earlier in the request.
     * @return array<string,true>
     */
    private static function open_boxes($fresh = false) {
        static $memo = null;
        if ($memo !== null && !$fresh) {
            return $memo;
        }
        $memo = [];
        foreach ((array) get_user_meta(get_current_user_id(), self::OPEN_BOXES_META, true) as $id) {
            if (self::is_fold_box_id($id)) {
                $memo[$id] = true;
            }
        }
        return $memo;
    }

    /**
     * The button on a folding card's title line: "Edit" while folded, "Close"
     * while open (the editor script swaps the two from the data attributes).
     *
     * @param string $controls Id of the part it opens.
     * @param bool   $open     Starts open.
     * @return string
     */
    private static function fold_button($controls, $open) {
        return '<button type="button" class="brikpanel-pe-btn secondary small brikpanel-pe-foldbtn" aria-expanded="' . ($open ? 'true' : 'false') . '"'
            . ' aria-controls="' . esc_attr($controls) . '" data-open-label="' . esc_attr__('Close', 'brikpanel') . '" data-closed-label="' . esc_attr__('Edit', 'brikpanel') . '">'
            . esc_html($open ? __('Close', 'brikpanel') : __('Edit', 'brikpanel')) . '</button>';
    }

    /**
     * The "?" beside a label that opens a short explanation (the shared bubble,
     * front-end/shared/brikpanel-tip.js). Cards used to carry these sentences
     * as grey lines under their fields: nine of them on a simple product, the
     * same words on every product. Behind "?" the card shows its fields first
     * and the explanation stays one hover or tap away. The bubble keeps the id
     * a control's aria-describedby may point at.
     *
     * @param string $id   Unique id of the bubble.
     * @param string $text Plain text; escaped here.
     * @return string
     */
    private static function help_tip($id, $text) {
        return '<span class="brikpanel-pe-tooltip" data-bp-tip="top" tabindex="0" role="button" aria-expanded="false" aria-label="' . esc_attr__('More information', 'brikpanel') . '" aria-describedby="' . esc_attr($id) . '">?'
            . '<span class="brikpanel-pe-tooltip-tip brikpanel-tip" id="' . esc_attr($id) . '" role="tooltip">' . esc_html($text) . '</span></span>';
    }

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
     * Whether a new product opens with "Virtual (no shipping)" switched on:
     * the "New products are virtual" setting (WooCommerce → Settings →
     * BrikPanel → Products) is on and the editor shows the switch. With the
     * "Digital product" section hidden there is no switch to turn it off on a
     * product that ships, so the setting stays out of the way.
     *
     * Called only while a new product is being opened, never on every admin
     * request: the setting is read here and nowhere else.
     *
     * @return bool
     */
    private function new_product_starts_virtual() {
        return 'yes' === get_option('brikpanel_pe_new_products_virtual', 'no')
            && in_array('digital', $this->get_visible_sections(), true);
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
        // Before any add_meta_boxes fires: this surface renders metaboxes, so
        // the spoofed screen must not claim to be a block editor. See
        // brikpanel_mark_screen_classic().
        brikpanel_mark_screen_classic();
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
        // Each box starts as its title line on wider screens, a click opens it,
        // and the boxes a person opened stay open for them (open_boxes()).
        // Folded is WordPress's own closed metabox (the contents display:none),
        // which every plugin is built to live with: its scripts run as usual
        // and its fields still post. A phone shows the box as a page instead.
        $open_boxes = self::open_boxes();
        foreach ($ordered as $id => $box) {
            $ob_base = ob_get_level();
            ob_start();
            $fold_id = 'mb:' . $id;
            $is_open = isset($open_boxes[$fold_id]) || !self::is_fold_box_id($fold_id);
            echo '<div class="brikpanel-pe-card brikpanel-pe-metaboxes-wrap brikpanel-pe-metabox-card brikpanel-pe-foldcard' . ($is_open ? '' : ' is-folded') . '" data-bpe-fold="' . esc_attr($fold_id) . '">';
            echo '<div class="postbox brikpanel-pe-metabox" id="' . esc_attr($id) . '">';
            echo '<div class="postbox-header"><h2 class="hndle"><button type="button" class="brikpanel-pe-foldtoggle" aria-expanded="' . ($is_open ? 'true' : 'false') . '" aria-controls="' . esc_attr('bpe-fold-' . $id) . '">'
                . '<span>' . esc_html($box['title']) . '</span>'
                . '<svg class="brikpanel-pe-foldchev" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . '</button></h2></div>';
            echo '<div class="inside brikpanel-pe-metabox-content" id="' . esc_attr('bpe-fold-' . $id) . '">';
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
            // React "universal metabox". Its `seopress_metabox_opener` box body
            // is nothing but a mount node, `<div
            // id="seopress-js-module-seo-metabox-embed">`, that `metaboxe.js`
            // renders the whole panel into (Title & description, Social,
            // Advanced, Redirections, Content analysis and, on SEOPress Pro
            // with the AI toggle on, the "generate with AI" buttons).
            //
            // Rendering that empty div is exactly right: the bundle is already
            // enqueued by the editor's post.php spoof (see the $auto_seo merge
            // in includes/brikpanel-enqueue.php) and mounts into the node on
            // DOMContentLoaded, so SEOPress draws the same panel it draws in
            // the native editor. Both of its save routes work here too: its
            // own per-tab Save button PUTs straight to the REST API, and the
            // hidden mirror inputs it renders alongside are scooped up by the
            // `.brikpanel-pe-metaboxes-wrap :input[name]` collector and land in
            // SEOPress's own save_post handler when BrikPanel's Save is used.
            //
            // BrikPanel used to route this mode through its unified SEO fields
            // instead. That was data-correct but it is a reimplementation, so
            // everything SEOPress puts inside its own panel went missing: the
            // AI buttons a wp.org user reported, plus Social, Redirections and
            // content analysis. Only pre-9.9 installs still use the classic ids.
            $detected = [
                'slug'        => 'seopress',
                'label'       => __('SEOPress', 'brikpanel'),
                'metabox_ids' => self::seopress_uses_universal_metabox()
                    ? ['seopress_metabox_opener']
                    : ['seopress_cpt', 'seopress_content_analysis'],
            ];
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
        // SEOPress in universal-metabox mode is the one exception to "write
        // everywhere": its own panel is rendered inside the SEO card and ships
        // a primary-category field in its Advanced tab, writing this very key.
        // Two live controls over one key means one of them silently loses:
        // BrikPanel's, being the later write, would always win and quietly
        // discard what the merchant just picked in the panel they were looking
        // at. Whoever renders the control reads and writes it (same rule the
        // SEO card itself follows), so BrikPanel stands down here and its own
        // selector hides when that leaves nothing to mirror into.
        if ((defined('SEOPRESS_VERSION') || function_exists('seopress_get_service'))
            && !self::seopress_owns_seo_card()) {
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
     * Whether SEOPress is active in its modern "universal metabox" mode, the
     * React SEO panel introduced in 9.9. In that mode SEOPress does NOT
     * register the legacy inline `seopress_cpt` metabox; it registers
     * `seopress_metabox_opener`, whose whole body is the mount node
     * `<div id="seopress-js-module-seo-metabox-embed">` that its `metaboxe.js`
     * bundle renders the panel into. BrikPanel renders that box (see
     * get_active_seo_plugin()), which is what makes the real SEOPress panel,
     * Social, Redirections, content analysis and the Pro AI buttons — appear
     * inside the SEO card instead of a reimplementation of it.
     *
     * Callers use this to pick between the two metabox id sets and to decide
     * who owns the primary category. Returns false for pre-9.9 SEOPress (the
     * classic metabox renders inline fine) and when SEOPress isn't active.
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
        // is the default, and the mount node we render for it is inert when the
        // bundle never loads, so assuming universal is always safe.
        return true;
    }

    /**
     * Whether SEOPress's own React panel is the SEO surface on this render,
     * i.e. SEOPress is the *active* plugin (nothing earlier in the detection
     * chain beat it) AND it is in universal-metabox mode.
     *
     * This is the predicate for "SEOPress owns it, BrikPanel stands down", and
     * it is deliberately stricter than seopress_uses_universal_metabox() alone.
     * With, say, Yoast active alongside SEOPress, Yoast wins the SEO card and
     * SEOPress's panel is never rendered, so BrikPanel must keep drawing its
     * own controls and keep mirroring into SEOPress's meta keys, exactly as
     * before. Only when SEOPress is the one on screen does it take over.
     *
     * Memoized: three render paths ask, and the answer reaches through
     * SEOPress's canEnqueue() each time.
     *
     * @return bool
     */
    public static function seopress_owns_seo_card() {
        static $answer = null;
        static $running = false;
        if ($answer !== null) {
            return $answer;
        }
        // Re-entrancy guard. get_active_seo_plugin() ends in the
        // `brikpanel_pe_active_seo_plugin` filter, and a site that answers that
        // filter by asking one of the callers below (primary_category_seo_plugins(),
        // the SEO card, the analysis bridge) would recurse forever. Answering
        // "no" mid-recursion is the safe direction: BrikPanel keeps its own
        // controls, which can duplicate a control but can never hide or drop one.
        if ($running) {
            return false;
        }
        $running = true;
        try {
            if (!self::seopress_uses_universal_metabox()) {
                $answer = false;
            } else {
                $active = self::get_active_seo_plugin();
                $answer = is_array($active) && isset($active['slug']) && $active['slug'] === 'seopress';
            }
        } catch (\Throwable $e) {
            $answer = false;
        }
        $running = false;
        return $answer;
    }

    /**
     * Detach SEOPress's Classic-editor save fallback for the duration of a
     * BrikPanel save, returning a handle to run it once, deliberately, for the
     * parent product.
     *
     * SEOPress 9.9+ hooks `ModuleMetabox::saveClassicEditorMetaFallback()` on
     * `save_post` to persist the hidden mirror inputs its React panel renders
     * alongside every tab. That handler has NO post-type and no ownership
     * check: it writes whatever `$_POST` carries onto whatever post id the
     * `save_post` it is handling happens to be for.
     *
     * On the native editor that is harmless, because variations are written in
     * their own separate ajax requests. In this editor the parent AND every
     * variation are written inside ONE request with one `$_POST`, so each
     * WC_Product_Variation::save() would stamp the parent's `_seopress_*`
     * values — meta title, meta description, canonical, robots, redirections,
     * the social keys — onto every variation post. A ten-variation product
     * would come out of a single save with eleven copies of the parent's SEO.
     *
     * The instance is anonymous: SEOPress\Core\Kernel::handleHooksPlugin()
     * does `$class = new $class(); $class->hooks();` and drops the reference,
     * and ModuleMetabox is not in the service container, so the hook registry
     * is the only handle on it. Match by class + method rather than by object
     * so a future refactor that wraps the callback still gets caught.
     *
     * @return array|null ['callback' => callable, 'priority' => int] or null.
     */
    private static function detach_seopress_classic_save() {
        global $wp_filter;
        if (empty($wp_filter['save_post']) || !is_object($wp_filter['save_post'])
            || empty($wp_filter['save_post']->callbacks)) {
            return null;
        }
        foreach ($wp_filter['save_post']->callbacks as $priority => $callbacks) {
            if (!is_array($callbacks)) {
                continue;
            }
            foreach ($callbacks as $cb) {
                if (empty($cb['function']) || !is_array($cb['function']) || count($cb['function']) !== 2) {
                    continue;
                }
                list($object, $method) = $cb['function'];
                if (!is_object($object) || $method !== 'saveClassicEditorMetaFallback') {
                    continue;
                }
                if (!($object instanceof \SEOPress\Actions\Admin\ModuleMetabox)) {
                    continue;
                }
                remove_action('save_post', $cb['function'], $priority);
                return ['callback' => $cb['function'], 'priority' => (int) $priority];
            }
        }
        return null;
    }

    /**
     * Detect a SEO plugin whose editor UI cannot be rendered inline (it lives
     * in a React popup with no metabox to call), so BrikPanel routes it
     * through its own unified SEO fields and gives it a native-equivalent
     * analysis panel. Currently SureRank and SmartCrawl (WPMU DEV SEO).
     *
     * SEOPress used to be in this list. It is not any more: since 3.2.91 its
     * universal metabox is rendered for real inside the SEO card (it does
     * expose a metabox, `seopress_metabox_opener`, whose body is the React
     * mount node), so get_active_seo_plugin() claims it and this must not
     * also claim it, or the card and the read path would disagree about who
     * owns the fields.
     *
     * Only meaningful when get_active_seo_plugin() returned null: if one of
     * the inline-metabox plugins is active it owns the SEO card and these are
     * not consulted. When both are somehow active, precedence is SureRank
     * before SmartCrawl (this must match the read-path order in
     * get_product_data() so the badge, the source-of-truth meta and the help
     * text all agree).
     *
     * @return array{slug:string,label:string}|null
     */
    public static function get_unified_seo_analyzer() {
        $detected = null;
        if (defined('SURERANK_VERSION')) {
            $detected = ['slug' => 'surerank', 'label' => __('SureRank', 'brikpanel')];
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
        // Unreachable today: get_unified_seo_analyzer() only ever returns
        // SureRank or SmartCrawl. Kept so a future analyzer added there without
        // a branch here degrades to "no panel" rather than a fatal.
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
                <span class="brikpanel-pe-sr-pill is-error"><?php
                    /* translators: %d: number of critical SEO issues */
                    echo esc_html(sprintf(_n('%d critical', '%d critical', $s['error'], 'brikpanel'), $s['error'])); ?></span>
            <?php endif; ?>
            <?php if ($s['warning'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill is-warning"><?php
                    /* translators: %d: number of SEO warnings */
                    echo esc_html(sprintf(_n('%d warning', '%d warnings', $s['warning'], 'brikpanel'), $s['warning'])); ?></span>
            <?php endif; ?>
            <?php if ($s['suggestion'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill is-suggestion"><?php
                    /* translators: %d: number of SEO suggestions */
                    echo esc_html(sprintf(_n('%d suggestion', '%d suggestions', $s['suggestion'], 'brikpanel'), $s['suggestion'])); ?></span>
            <?php endif; ?>
            <span class="brikpanel-pe-sr-pill is-success"><?php
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
                <span class="brikpanel-pe-sr-pill is-warning"><?php
                    /* translators: %d: number of SEO checks that need work */
                    echo esc_html(sprintf(_n('%d to improve', '%d to improve', $s['warning'], 'brikpanel'), $s['warning'])); ?></span>
            <?php endif; ?>
            <?php if ($s['suggestion'] > 0) : ?>
                <span class="brikpanel-pe-sr-pill is-suggestion"><?php
                    /* translators: %d: number of SEO suggestions */
                    echo esc_html(sprintf(_n('%d suggestion', '%d suggestions', $s['suggestion'], 'brikpanel'), $s['suggestion'])); ?></span>
            <?php endif; ?>
            <span class="brikpanel-pe-sr-pill is-success"><?php
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
     * Live analysis bridge — native editor field scaffold.
     *
     * Every supported SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress)
     * collects the content it analyses from the *native* post editor DOM:
     *     #title    — post title          (Yoast/RM/AIOSEO/SEOPress)
     *     #content  — post body / TinyMCE  (word count, links, images, …)
     *     #excerpt  — WooCommerce short description (Yoast Woo product desc)
     *     #editable-post-name-full / #post_name — slug (keyphrase-in-slug)
     * None of those exist in the BrikPanel editor, so the analysers run
     * against empty input — that is why scores stay stuck on "write a
     * short description / text contains 0 words" even after the user has
     * filled the BrikPanel fields. Emit the scaffold seeded from the real
     * product and let the JS bridge mirror the BrikPanel fields into it live
     * (see initSeoAnalysisBridge() in brikpanel-product-editor.js).
     * The nodes carry no `name` attribute, so they never reach the save
     * payload and cannot collide with BrikPanel's own form handling.
     *
     * Every route that puts an SEO plugin's metabox on the page has to call
     * this, not just the SEO card: an admin who hand-places the metabox from
     * the section picker gets it through build_third_party_metabox_cards()
     * instead, and without the scaffold the plugin grades an empty product —
     * every keyword and content check fails while the score reads as if the
     * page were badly written. The ids are unique per document, so the
     * static guard makes a second call a no-op rather than emitting
     * duplicates that the analysers would read at random.
     *
     * @param WP_Post $post
     */
    private static function render_seo_native_bridge($post) {
        static $printed = false;
        if ($printed || !$post instanceof \WP_Post) {
            return;
        }
        // SEOPress 9.9+ is the one supported plugin that reads NOTHING from the
        // editor DOM: its snippet preview round-trips through
        // `preview-title-description-metas`, and its content analysis fetches
        // the product's rendered front-end page and POSTs that. So the scaffold
        // would buy it nothing, and it costs something real. SEOPress's own
        // meta-title field is `id="title"`, exactly the id the scaffold claims,
        // and the scaffold is printed first; the panel's `<label for="title">`
        // then resolves to our clipped, invisible input, so clicking the Title
        // label in the SEO card focused a hidden node off-screen. Emit nothing
        // and let SEOPress own the id.
        if (self::seopress_owns_seo_card()) {
            return;
        }
        $printed = true;

        $bridge_slug = $post->post_name !== '' ? $post->post_name : sanitize_title($post->post_title);
        echo '<div class="brikpanel-pe-seo-native-bridge" aria-hidden="true" '
            . 'style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">';
        echo '<input type="text" id="title" tabindex="-1" value="' . esc_attr($post->post_title) . '">';
        echo '<textarea id="content" tabindex="-1">' . esc_textarea($post->post_content) . '</textarea>';
        echo '<textarea id="excerpt" tabindex="-1">' . esc_textarea($post->post_excerpt) . '</textarea>';
        echo '<input type="text" id="post_name" tabindex="-1" value="' . esc_attr($bridge_slug) . '">';
        echo '<span id="editable-post-name-full">' . esc_html($bridge_slug) . '</span>';
        echo '</div>';
    }

    /**
     * Yoast WooCommerce SEO compatibility shim.
     *
     * Yoast SEO: WooCommerce ships an analysis collector
     * (yoastseo-woo-identifiers) that runs on every Yoast analysis pass and
     * unconditionally calls:
     *     document.querySelector("select#product-type").value
     *     document.querySelector("input#_sku")           (null-guarded)
     *     #yoast_identifier_*                             (null-guarded)
     * Those nodes only exist on WooCommerce's *native* product editor. Inside
     * the BrikPanel editor `select#product-type` is absent, so the collector
     * throws "Cannot read properties of null (reading 'value')" from inside
     * YoastSEO.analysis.collectData on every pass, which aborts the whole
     * pipeline: the score badge never updates and "Analysis results" stays
     * empty, i.e. the analysis reads as permanently stuck (the cascade behind
     * the SyntaxError / `$(...).pointer` reports on some setups too).
     *
     * Emit the exact hidden nodes the collector reads, mirrored from the real
     * product, so Yoast WooCommerce analysis runs cleanly for BOTH simple and
     * variable products instead of crashing.
     *
     * Every route that puts Yoast's metabox on the page has to call this, not
     * just the SEO card — the same rule render_seo_native_bridge() documents.
     * An admin who hand-places `wpseo_meta` from the section picker gets the
     * box through build_third_party_metabox_cards() instead, and that route
     * shipped without the shim: with Yoast WooCommerce SEO active it threw on
     * every analysis pass while the SEO-card route was fine. The ids are
     * unique per document, so the static guard makes a second call a no-op
     * rather than emitting a duplicate #product-type the collector would then
     * read at random.
     *
     * @param WP_Post $post
     */
    private static function render_yoast_woo_compat_shim($post) {
        static $printed = false;
        if ($printed || !$post instanceof \WP_Post) {
            return;
        }
        if (!defined('WPSEO_WOO_VERSION')) {
            return;
        }
        $active_seo = self::get_active_seo_plugin();
        if (empty($active_seo['slug']) || $active_seo['slug'] !== 'yoast') {
            return;
        }
        $compat_product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;
        if (!$compat_product) {
            return;
        }
        $printed = true;

        $compat_type = (string) $compat_product->get_type();
        $compat_sku  = (string) $compat_product->get_sku();
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

    /**
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
        // Before any add_meta_boxes fires: this surface renders metaboxes, so
        // the spoofed screen must not claim to be a block editor. See
        // brikpanel_mark_screen_classic().
        brikpanel_mark_screen_classic();
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

        // Yoast WooCommerce SEO compatibility shim. Emitted before the
        // empty-boxes early return below so the message-only path is covered
        // too. See render_yoast_woo_compat_shim() for why it is required.
        self::render_yoast_woo_compat_shim($post);

        self::render_seo_native_bridge($post);

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
    /**
     * Pull the human field labels out of a captured chunk of product-data HTML,
     * so a section option can be labelled by the fields it actually contains.
     *
     * @param string $html Rendered field HTML for one section.
     * @return string[] De-duplicated field label texts, in order.
     */
    private static function extract_field_labels($html) {
        if (!is_string($html) || $html === '') {
            return [];
        }
        $labels = [];
        if (preg_match_all('/<label\b[^>]*>(.*?)<\/label>/is', $html, $m)) {
            foreach ($m[1] as $raw) {
                // Drop nested markup (help-tip spans, etc.), collapse whitespace,
                // and trim trailing tooltip/colon glyphs.
                $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw)));
                $text = trim($text, " \t\n\r\0\x0B?:");
                if ($text !== '' && !in_array($text, $labels, true)) {
                    $labels[] = $text;
                }
            }
        }
        return $labels;
    }

    /**
     * Decorate a native tab label with the plugin field names it contains,
     * e.g. "Inventory (Product Code)". Falls back to the bare label when no
     * field labels can be parsed.
     *
     * @param string $base Native tab label.
     * @param string $html Captured field HTML for the section.
     * @return string
     */
    private static function section_label_with_fields($base, $html) {
        $fields = self::extract_field_labels($html);
        if (empty($fields)) {
            return $base;
        }
        $shown  = array_slice($fields, 0, 4);
        $suffix = implode(', ', $shown);
        if (count($fields) > 4) {
            /* translators: appended after a truncated list of field names */
            $suffix .= ', ' . __('…', 'brikpanel');
        }
        return $base . ' (' . $suffix . ')';
    }

    /** Where a hand-picked section list is parked while the fields are hidden. */
    const TABS_PARKED_OPTION = 'brikpanel_pe_wc_tabs_parked';

    /** User meta: the folding cards this person keeps open (ajax_fold_state()). */
    const OPEN_BOXES_META = 'brikpanel_pe_open_boxes';

    /**
     * Whether the store shows other plugins' product-data fields through the
     * settings ("Auto-show fields other plugins add", or sections picked in
     * "Additional product data sections"). Reads the stored options, not the
     * runtime selection: a section added at runtime regardless of the settings
     * (the multi-currency prices) is not the merchant turning this on.
     *
     * @return bool
     */
    private static function thirdparty_setting_on() {
        return get_option('brikpanel_pe_wc_tabs_auto', 'no') === 'yes'
            || !empty((array) get_option('brikpanel_pe_wc_tabs_selected', []));
    }

    /**
     * The "Fields from your other plugins" box: with the setting off, the
     * fields other plugins add to this product, folded to their title line
     * like another plugin's own box (owner, November 2026). A click opens it
     * and each person's choice is kept (open_boxes()). It stands where the
     * "Additional product data" card stands, inside the same card and ancestor
     * chain, so the plugins' scripts work in it as they do in that card.
     *
     * It took the place of a card that offered a store-wide switch ("Show these
     * fields"); that switch lives on as the link at the end of the box, so a
     * store that wants the fields open for everyone, and the hand-picked list a
     * "Hide these fields" press parked, are still one click away.
     *
     * @param string $groups_html Captured sections (capture_wc_product_data_fields(), fold_html).
     * @param int    $count       Sections shown for this product's type.
     * @return string Markup, the sections as captured.
     */
    private static function thirdparty_fold_box($groups_html, $count) {
        $open = isset(self::open_boxes()['plugins']);
        return '<div class="brikpanel-pe-tpfold brikpanel-pe-foldcard' . ($open ? '' : ' is-folded') . '" data-bpe-fold="plugins">'
            . '<div class="brikpanel-pe-tpfold-head">'
            . self::fold_toggle_html('bpe-fold-plugins', __('Fields from your other plugins', 'brikpanel'), $count, $open)
            . '</div>'
            . '<div class="brikpanel-pe-foldbody brikpanel-pe-tpfold-body" id="bpe-fold-plugins">'
            . $groups_html
            . '<p class="brikpanel-pe-foldfoot"><button type="button" class="brikpanel-pe-foldlink brikpanel-pe-tpfold__always">'
            . esc_html__('Always show these fields', 'brikpanel') . '</button></p>'
            . '</div>'
            . '</div>';
    }

    /**
     * The switched-off sections the "Hidden sections" box carries:
     * `slug => ['short' => …, 'full' => …]`, in the settings order.
     *
     * Only the small sections this editor ships switched off
     * (brikpanel_pe_section_default_hidden(): permalink, barcode, tax, weight,
     * dimensions, tags, shipping class, sold individually, linked products,
     * advanced). A main section a store switched off itself (images, prices,
     * stock, descriptions, variations, SEO…) stays off: those are the page's
     * own frame, and the link at the end of the box leads to the settings list
     * where it is turned back on.
     *
     * For people who may change store settings, the same people the box's
     * settings link and the button before it were for: a store that keeps
     * sections off for its staff keeps them off.
     *
     * @param int $product_id Product being edited (0 for a new one).
     * @return array<string, array{short: string, full: string}>
     */
    private function hidden_box_sections($product_id) {
        if (!current_user_can('manage_woocommerce') || !function_exists('brikpanel_pe_section_default_hidden')) {
            return [];
        }
        return array_intersect_key(
            $this->suggested_hidden_sections((int) $product_id),
            array_flip(brikpanel_pe_section_default_hidden())
        );
    }

    /**
     * The "Hidden sections" box under the last section: the switched-off
     * sections, folded to their title line like another plugin's box, to edit
     * here without turning them on (owner, November 2026: whoever wants to edit
     * without dealing with the settings can). A click opens it, and each
     * person's choice is kept (open_boxes()).
     *
     * The editor's own sections ship partly switched off to keep the page
     * short, and nothing on the page said so: a store looking for shipping
     * weight or linked products thought they were broken (wp.org sizulku,
     * November 2026, after the tags and permalink requests).
     *
     * While it stays folded the editor script leaves its fields out of every
     * save (`data-bpe-hold`), so a product saves exactly as it did before the
     * box existed; opened once, they save like any shown section. The link at
     * its end opens the settings list where sections are turned on for good.
     *
     * Follows the "Suggest hidden sections in the product editor" setting (on
     * by default).
     *
     * @param array<string,string> $section_html Built section markup, by slug.
     * @param array                $held         hidden_box_sections().
     * @param int                  $product_id   Product being edited, for the way back from the settings.
     * @return void Prints the box, or nothing when there is nothing to offer.
     */
    private function render_hidden_box(array $section_html, array $held, $product_id) {
        $cards = '';
        $count = 0;
        foreach ($held as $slug => $label) {
            if (isset($section_html[$slug]) && $section_html[$slug] !== '') {
                // Its short name, for the box's summary on a phone.
                $name   = ' data-bpe-name="' . esc_attr($label['short']) . '"';
                $cards .= (string) preg_replace_callback('/<([a-z][a-z0-9]*)\b/i', static function ($m) use ($name) {
                    return '<' . $m[1] . $name;
                }, self::mark_section($section_html[$slug], $slug), 1);
                $count++;
            }
        }
        if (!$count) {
            return;
        }
        $open = isset(self::open_boxes()['hidden']);
        $url  = add_query_arg(
            [
                'page'    => 'wc-settings',
                'tab'     => 'brikpanel',
                'section' => 'products',
                'bp_back' => (int) $product_id,
            ],
            admin_url('admin.php')
        ) . '#bp-jump-brikpanel_pe_visible_sections_field';

        $html = '<div class="brikpanel-pe-card brikpanel-pe-foldcard brikpanel-pe-hiddenbox' . ($open ? '' : ' is-folded') . '" data-bpe-fold="hidden"' . ($open ? '' : ' data-bpe-hold="1"') . '>'
            . '<div class="brikpanel-pe-hiddenbox__head">' . self::fold_toggle_html('bpe-fold-hidden', __('Hidden sections', 'brikpanel'), $count, $open) . '</div>'
            . '<div class="brikpanel-pe-foldbody brikpanel-pe-hiddenbox__body" id="bpe-fold-hidden">'
            . $cards
            . '<p class="brikpanel-pe-foldfoot"><a class="brikpanel-pe-foldlink" href="' . esc_url($url) . '">' . esc_html__('Choose which sections show', 'brikpanel') . '</a></p>'
            . '</div>'
            . '</div>';
        // Escaped as printed: the sections are this editor's own fields, so
        // an allow-list of form markup keeps every one of them intact.
        echo wp_kses($html, self::section_kses());
    }

    /**
     * The title line of a folding box built here ("Fields from your other
     * plugins", "Hidden sections"): one button with the name, how many parts
     * are inside and the chevron, like another plugin's box.
     *
     * @param string $controls Id of the part it opens.
     * @param string $title    Box name, not escaped yet.
     * @param int    $count    Parts inside (0: no count).
     * @param bool   $open     Starts open.
     * @return string Escaped markup.
     */
    private static function fold_toggle_html($controls, $title, $count, $open) {
        $num = function_exists('brikpanel_number') ? brikpanel_number((int) $count) : (string) (int) $count;
        return '<button type="button" class="brikpanel-pe-foldtoggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . esc_attr($controls) . '">'
            . '<span class="brikpanel-pe-foldname"><span class="brikpanel-pe-foldtitle">' . esc_html($title) . '</span>'
            . ($count > 0 ? '<span class="brikpanel-pe-foldcount">' . esc_html($num) . '</span>' : '')
            . '</span>'
            . '<svg class="brikpanel-pe-foldchev" width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
            . '</button>';
    }

    /**
     * Allowed markup for render_hidden_box(): the form parts this editor's own
     * small sections are made of, with the attributes they use.
     *
     * @return array<string, array<string, bool>>
     */
    private static function section_kses() {
        static $tags = null;
        if ($tags !== null) {
            return $tags;
        }
        $common = [
            'class' => true, 'id' => true, 'style' => true, 'hidden' => true, 'title' => true, 'dir' => true,
            'role' => true, 'tabindex' => true, 'data-*' => true,
            'aria-label' => true, 'aria-hidden' => true, 'aria-expanded' => true, 'aria-controls' => true,
            'aria-describedby' => true, 'aria-haspopup' => true, 'aria-live' => true,
        ];
        $field = $common + [
            'name' => true, 'value' => true, 'placeholder' => true, 'disabled' => true, 'readonly' => true,
            'required' => true, 'autocomplete' => true, 'spellcheck' => true, 'inputmode' => true, 'maxlength' => true,
        ];
        $svg = [
            'class' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true,
            'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true,
            'focusable' => true, 'd' => true,
        ];
        $tags = [
            'div'      => $common,
            'p'        => $common,
            'span'     => $common,
            'small'    => $common,
            'strong'   => $common,
            'em'       => $common,
            'br'       => [],
            'a'        => $common + ['href' => true, 'target' => true, 'rel' => true],
            'label'    => $common + ['for' => true],
            'button'   => $common + ['type' => true, 'disabled' => true],
            'input'    => $field + ['type' => true, 'checked' => true, 'step' => true, 'min' => true, 'max' => true],
            'select'   => $field + ['multiple' => true],
            'option'   => $common + ['value' => true, 'selected' => true, 'disabled' => true],
            'optgroup' => $common + ['label' => true],
            'textarea' => $field + ['rows' => true, 'cols' => true],
            'svg'      => $svg,
            'path'     => $svg,
        ];
        return $tags;
    }

    /**
     * BrikPanel's own editor sections that are switched off: `slug =>
     * ['short' => …, 'full' => …]`, in the order the settings list shows them.
     * hidden_box_sections() picks the ones the "Hidden sections" box carries.
     *
     * The short name is the settings label without its closing note in
     * brackets ("Linked products (upsells & cross-sells)" becomes "Linked
     * products"), so no new names need translating. Plugin boxes (`mb:`) are
     * not counted: the box is about this editor's own sections.
     *
     * A section a developer filter keeps off (`brikpanel_editor_visible_sections`)
     * is not counted either: switching it on in the settings would still show
     * nothing.
     *
     * @param int $product_id Product being edited.
     * @return array<string, array{short: string, full: string}>
     */
    private function suggested_hidden_sections($product_id) {
        if ('yes' !== get_option('brikpanel_pe_suggest_hidden_sections', 'yes')
            || !function_exists('brikpanel_pe_section_options')
            || !function_exists('brikpanel_pe_get_section_order')
            || !function_exists('brikpanel_pe_get_visible_sections_ordered')) {
            return [];
        }

        $labels     = brikpanel_pe_section_options();
        $shown      = $this->get_visible_sections();
        $switched   = brikpanel_pe_get_visible_sections_ordered();
        $candidates = [];
        foreach (brikpanel_pe_get_section_order() as $slug) {
            if (isset($labels[$slug]) && !in_array($slug, $shown, true) && !in_array($slug, $switched, true)) {
                $candidates[] = $slug;
            }
        }
        if (!$candidates) {
            return [];
        }

        // One dry run of the same filter get_visible_sections() applies, with
        // every candidate switched on, keeps the ones that would really show.
        /** This filter is documented in get_visible_sections(). */
        $would_show = apply_filters('brikpanel_editor_visible_sections', array_merge($switched, $candidates), $product_id);
        $would_show = is_array($would_show) ? $would_show : [];

        $out = [];
        foreach ($candidates as $slug) {
            if (!in_array($slug, $would_show, true)) {
                continue;
            }
            $full  = (string) $labels[$slug];
            $short = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $full));
            $out[$slug] = ['short' => $short !== '' ? $short : $full, 'full' => $full];
        }
        return $out;
    }

    /**
     * Fingerprint of everything that can change the answer to "does this store
     * have product-data fields from other plugins?": the active plugins decide
     * which exist, the BrikPanel version which hooks are read
     * (core_product_data_sub_hooks()). A change to either is a new key, so a
     * stale answer is never read rather than having to be deleted.
     *
     * @return string
     */
    private static function thirdparty_fold_fingerprint() {
        $plugins = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $plugins = array_merge($plugins, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        sort($plugins);
        return substr(md5(implode('|', $plugins) . '|' . (defined('BRIKPANEL_VERSION') ? BRIKPANEL_VERSION : '')), 0, 12);
    }

    /**
     * Whether an earlier editor load found no field from another plugin to
     * fold, so this one can skip the capture: most stores have none, and the
     * capture fires every product-data hook. Learnt from the capture itself
     * (thirdparty_fold_remember()), never from a probe beforehand: a plugin that
     * prints part of its panel through require_once (Measurement Price
     * Calculator's pricing table) only prints it on the first fire, and a probe
     * before the capture would leave that part out of the box and let the next
     * save wipe it (see $render_product).
     *
     * @return bool
     */
    private static function thirdparty_fold_known_empty() {
        return 'no' === get_transient('bpe_tp_fold_' . self::thirdparty_fold_fingerprint());
    }

    /**
     * Stores what a capture found, for thirdparty_fold_known_empty().
     *
     * @param bool $found Whether the store showed any section to fold, also
     *                    one this product's type leaves out.
     * @return void
     */
    private static function thirdparty_fold_remember($found) {
        $key  = 'bpe_tp_fold_' . self::thirdparty_fold_fingerprint();
        $want = $found ? 'yes' : 'no';
        if (get_transient($key) !== $want) {
            set_transient($key, $want, DAY_IN_SECONDS);
        }
    }

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
        // Probe against a NON-variable (simple) product. Many plugins render
        // their product-data-tab field only for simple products (WooCommerce
        // itself hides the simple SKU/inventory/pricing fields on variable
        // products in favour of per-variation ones, and add-ons follow suit, e.g.
        // Product Code for WooCommerce prints its Inventory field only when
        // !is_type('variable')). Probing with whatever product happened to be
        // most recent meant a variable product would make those sections vanish
        // from the picker entirely, so the field could not be found or enabled.
        // Prefer a simple product; fall back to any product only if the store has
        // none (in which case those simple-only fields are irrelevant anyway).
        $probe_args = [
            'post_type' => 'product', 'posts_per_page' => 1, 'post_status' => 'any',
            'no_found_rows' => true, 'suppress_filters' => true,
        ];
        if (function_exists('set_current_screen')) set_current_screen('product');
        $GLOBALS['pagenow'] = 'post.php';

        // Inside the editor render (see $render_product), fire the hooks for the
        // product being edited BEFORE the probe does. A plugin that prints part
        // of its panel through require_once only prints it on the first fire, so
        // that fire must be the real product's; the card then reuses this output.
        // Discovery below still probes as before, so the set of sections offered
        // does not change.
        if (self::$render_product) {
            $post           = get_post(self::$render_product->get_id());
            $thepostid      = $post ? $post->ID : 0;
            $product_object = self::$render_product;
            if ($post) {
                self::collect_custom_tab_meta();
                foreach ($core_sub_hooks as $hooks) {
                    foreach ($hooks as $hook) {
                        if (has_action($hook)) self::capture_core_subhook($hook);
                    }
                }
                if (has_action('woocommerce_product_data_panels')) {
                    self::render_hook_capture('woocommerce_product_data_panels', true);
                }
            }
        }

        $probe = get_posts($probe_args + [
            'tax_query' => [[
                'taxonomy' => 'product_type',
                'field'    => 'slug',
                'terms'    => ['variable', 'variable-subscription', 'grouped', 'external'],
                'operator' => 'NOT IN',
            ]],
        ]);
        if (empty($probe)) {
            $probe = get_posts($probe_args);
        }
        if (!empty($probe)) {
            $post = $probe[0];
            $thepostid = $post->ID;
            $product_object = wc_get_product($post->ID);
        }

        // Core tab groups — include if any registered hook returns non-empty HTML.
        foreach ($core_sub_hooks as $label => $hooks) {
            $section = '';
            foreach ($hooks as $hook) {
                if (!has_action($hook)) continue;
                // Buffer-safe: a 3rd-party plugin hooked here may assume a
                // fully-saved product (Germanized fatals on get_manufacturer on
                // an empty shop) or leave an output buffer open (Sales Countdown
                // Timer) — capture_isolated_hook() swallows both safely.
                // WooCommerce's own fields are left out (capture_core_subhook).
                $section .= self::capture_core_subhook($hook, false);
            }
            if ($section !== '') {
                // Append the names of the fields a plugin added to this native
                // tab (e.g. "Inventory (Product Code)"), so a merchant can find a
                // specific plugin's field by name in the settings picker instead
                // of having to know which WooCommerce tab it hooks into.
                $out['core:' . sanitize_key($label)] = self::section_label_with_fields($label, $section);
            }
        }

        // Custom panels registered on woocommerce_product_data_panels.
        // Apply the tabs filter (inside collect_custom_tab_meta) FIRST so any
        // plugin that lazily registers its panel action from within the tabs
        // filter is wired up before the has_action() check below.
        $tab_meta = self::collect_custom_tab_meta();
        $target_to_label = $tab_meta['labels'];
        if (has_action('woocommerce_product_data_panels')) {
            // Buffer-safe, per-callback fire (see capture_hook_chunks): a custom
            // panel may assume a saved product OR leave an output buffer open;
            // neither may take down the settings page, and one plugin fataling
            // must not hide every plugin registered after it.
            foreach (self::capture_hook_chunks('woocommerce_product_data_panels') as $panels_html) {
                $core_targets = self::core_panel_targets();
                $skip_ids = ['marketplace_suggestions'];
                // Shield <script> blocks so libxml does not eat panels that
                // follow a script-heavy panel while we enumerate ids.
                $shield = [];
                $panels_html = self::shield_scripts($panels_html, $shield);
                $dom = new \DOMDocument();
                $loaded = self::load_panels_dom($dom, $panels_html);
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
     * The `class` entry each tab carries is harvested too: WooCommerce uses it
     * to gate a tab to a product type (`show_if_bundle`, `show_if_composite`,
     * `hide_if_variable`…), and unrendered_type_panels() reads exactly that to
     * decide whether the editor can represent a given product at all.
     *
     * `throwers` lists the plugin paths whose tab callback fataled while we
     * enumerated; unrepresented_owner_paths() turns that into a save-hook mute.
     *
     * @return array{labels: array<string,string>, keys: array<string,string>, classes: array<string,string[]>, throwers: string[]}
     */
    private static function collect_custom_tab_meta() {
        $labels   = [];
        $keys     = [];
        $classes  = [];
        $throwers = [];

        // Throwable-safe: the render path fires this filter with a real product
        // object, but the settings-page enumeration fires it in a spoofed
        // product context. A tab callback that resolves the product from its own
        // source (not our spoofed global $product_object) may get null and
        // dereference it — WooCommerce Composite Products' product_data_tabs
        // callback fatals with "Call to a member function get_id() on null".
        //
        // Catching it is not enough on its own: PHP abandons the whole
        // apply_filters() call, so the tabs every OTHER plugin already
        // contributed die with it and the callbacks queued behind it never run.
        // The result was an empty tab list, which reads exactly like "this store
        // has no third-party product-data tabs": the settings section picker
        // came up blank and unrendered_type_panels() below could never find the
        // `show_if_{type}` panel it exists to find, so the data-loss guard was
        // dead on every store running Composite Products.
        //
        // One combined fire stays the primary path: it is what plugins are
        // written against (a callback may legitimately decorate or remove an
        // entry another callback added, which a per-callback pass cannot
        // reproduce), and on a healthy store it costs exactly what it did
        // before. Only when it fatals do we re-run the filter one callback at a
        // time to salvage the surviving tabs and to learn WHICH plugin fataled.
        $isolated = false;
        try {
            $tabs_meta = apply_filters('woocommerce_product_data_tabs', []);
        } catch (\Throwable $e) {
            $isolated = true;
        }
        if ($isolated) {
            $chunks    = self::filter_chunks('woocommerce_product_data_tabs', []);
            $throwers  = $chunks['throwers'];
            $tabs_meta = [];
            foreach ($chunks['results'] as $chunk) {
                if (is_array($chunk)) $tabs_meta = array_replace($tabs_meta, $chunk);
            }
        }
        if (is_array($tabs_meta)) {
            foreach ($tabs_meta as $key => $tab) {
                if (!is_array($tab)) continue;
                $target = isset($tab['target']) ? (string) $tab['target'] : (string) $key;
                $labels[$target] = isset($tab['label']) ? (string) $tab['label'] : ucfirst((string) $key);
                $keys[$target]   = (string) $key;
                // `class` is an array in WooCommerce core and in every add-on we
                // know of, but a string is legal too — normalise both.
                $tab_class = isset($tab['class']) ? $tab['class'] : [];
                if (is_string($tab_class)) {
                    $tab_class = preg_split('/\s+/', trim($tab_class), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                $classes[$target] = is_array($tab_class) ? array_map('strval', $tab_class) : [];
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
                    // Legacy markup carries no type gating we can read.
                    if (!isset($classes[$target])) $classes[$target] = [];
                }
            }
        }

        return ['labels' => $labels, 'keys' => $keys, 'classes' => $classes, 'throwers' => $throwers];
    }

    /**
     * Product-data panels that belong to THIS product's type but that the
     * editor did not render.
     *
     * Why this exists: BrikPanel's simplified editor renders its own form plus
     * whichever third-party sections the merchant picked in
     * WooCommerce ▸ Settings ▸ BrikPanel. A panel that WooCommerce gates to a
     * single product type (`show_if_bundle`, `show_if_composite`,
     * `show_if_booking`…) can be absent from that form while its plugin still
     * listens on the save hooks. Those listeners routinely read their fields as
     * "present means keep, absent means the merchant cleared it" — so firing a
     * save hook for a form that never contained the fields DELETES the data.
     * WooCommerce Product Bundles is the reported case: saving a bundle emptied
     * `bundled_data_items` and reset the bundle's layout/group-mode/cart flags,
     * because `WC_PB_Meta_Box_Product_Data::process_bundle_data()` takes its
     * empty branch when `$_POST['bundle_data']` is missing. Composite Products
     * and Bookings are built the same way.
     *
     * Detection is by tab class rather than by a hardcoded list of "unsupported
     * types": a type list would also silence plugins that render no panel of
     * their own but legitimately use the save hook (WooCommerce Subscriptions
     * puts its fields in the native General panel, which BrikPanel does render
     * and forward). Reading the gate the panel itself declares keeps the guard
     * exactly as wide as the gap.
     *
     * A non-empty return means "the form the merchant just submitted cannot
     * represent this product" — the caller skips the object save hook and the
     * editor shows a note pointing at the native WooCommerce editor.
     *
     * @param WC_Product|null $product Product being rendered or saved.
     * @return string[] Panel target ids (e.g. `bundled_product_data`), empty when the form is complete.
     */
    /**
     * Tab metadata for the two type guards below, enumerated once per request.
     *
     * unrendered_type_panels() and unrepresented_owner_paths() read two halves
     * of the same answer and are always called back to back, in the same product
     * context. The other callers of collect_custom_tab_meta() (the settings
     * picker, the editor's field capture) deliberately keep their own fires,
     * each runs inside a different product spoof and a shared snapshot would
     * change what they see.
     *
     * @return array{labels: array<string,string>, keys: array<string,string>, classes: array<string,string[]>, throwers: string[]}
     */
    private static function guard_tab_meta() {
        static $meta = null;
        if ($meta === null) {
            $meta = self::collect_custom_tab_meta();
        }
        return $meta;
    }

    private static function unrendered_type_panels($product) {
        if (!$product || !method_exists($product, 'get_type')) return [];

        $type = (string) $product->get_type();
        if ($type === '' || self::is_native_editor_type($type)) return [];

        // Same request, same answer: the render path and the save path both ask,
        // and each answer costs one `woocommerce_product_data_tabs` fire.
        static $cache = [];
        if (isset($cache[$type])) return $cache[$type];

        $unrendered = [];
        try {
            $candidates = self::type_panel_candidates($type);

            // Resolve the merchant's section list only when a gated panel exists.
            // augment_sections_auto() can enumerate every panel in the store, and
            // no product type should pay for that just to be told it has nothing
            // gated to it.
            if ($candidates) {
                $selected = self::picked_sections();

                foreach ($candidates as $target) {
                    // Rendered by the editor → its fields are in the POST, so the
                    // listener sees a complete form and must run as usual.
                    if (in_array('tab:' . $target, $selected, true)) continue;
                    $unrendered[] = $target;
                }
            }
        } catch (\Throwable $e) {
            // Never let tab enumeration break a save or a page render: fall back
            // to the pre-existing behaviour (fire the hook, render no note).
            $unrendered = [];
        }

        /**
         * Filter the product-data panels BrikPanel considers unrepresentable
         * for the given product.
         *
         * Returning an empty array restores the legacy behaviour of always
         * firing `woocommerce_admin_process_product_object`.
         *
         * @param string[]   $unrendered Panel target ids.
         * @param WC_Product $product    Product being saved or rendered.
         * @param string     $type       Product type slug.
         */
        $unrendered = (array) apply_filters('brikpanel_pe_unrendered_type_panels', $unrendered, $product, $type);

        $cache[$type] = $unrendered;
        return $unrendered;
    }

    /**
     * Types this editor has a first-class form for. They are never
     * "unrepresented", whatever panels plugins gate to them: Variation Gallery
     * and Variation Swatches both register `show_if_variable` panels, and
     * treating those as a gap would silence the save hooks on every variable
     * product in the store. Their per-variation data is saved on the variation
     * hooks BrikPanel already dispatches.
     *
     * @param string $type Product type slug.
     * @return bool
     */
    private static function is_native_editor_type($type) {
        return in_array($type, ['simple', 'variable', 'grouped', 'external'], true)
            || (function_exists('brikpanel_is_variable_product_type') && brikpanel_is_variable_product_type($type));
    }

    /**
     * The panels another plugin gates to one product type: its own settings
     * box for that type (`show_if_{type}`).
     *
     * WooCommerce's own tabs are left out. A plugin may add its type's class to
     * one of them (Product Bundles: `$tabs['inventory']['class'][] =
     * 'show_if_bundle'`), and enumerating the tabs filter on an empty array turns
     * that into a bare entry whose target is the tab's key. That is no panel of
     * the plugin's own, it can never be picked or posted, and counting it would
     * mark the type unrepresentable for good: a subscription plugin doing the
     * same would be held off every save of its products.
     *
     * @param string $type Product type slug.
     * @return string[] Panel target ids, empty for the editor's own types.
     */
    private static function type_panel_candidates($type) {
        $type = (string) $type;
        if ($type === '' || self::is_native_editor_type($type)) return [];

        static $cache = [];
        if (isset($cache[$type])) return $cache[$type];

        $core = array_merge(
            self::core_panel_targets(),
            ['general', 'inventory', 'shipping', 'linked_product', 'attribute', 'variations', 'advanced']
        );
        $gate       = 'show_if_' . $type;
        $candidates = [];
        try {
            foreach ((array) self::guard_tab_meta()['classes'] as $target => $tab_classes) {
                $target = (string) $target;
                if ($target === '' || in_array($target, $core, true)) continue;
                if (in_array($gate, (array) $tab_classes, true)) $candidates[] = $target;
            }
        } catch (\Throwable $e) {
            $candidates = [];
        }
        $cache[$type] = $candidates;
        return $candidates;
    }

    /**
     * The product-data sections on the editor's form: the ones picked in
     * settings plus the ones surfaced automatically. Resolved once a request;
     * augment_sections_auto() can enumerate every panel in the store.
     *
     * @return string[] Section keys (`tab:{target}`, `core:{label}`…).
     */
    private static function picked_sections() {
        static $picked = null;
        if ($picked === null) {
            $picked = (array) get_option('brikpanel_pe_wc_tabs_selected', []);
            $picked = self::augment_sections_for_multicurrency($picked, 'product');
            $picked = self::augment_sections_auto($picked, 'product');
        }
        return $picked;
    }

    /**
     * Panels the submitted form really carried fields in, as the editor script
     * reports them (`bp_filled_panels`). A picked panel can still reach the page
     * empty: Easy Product Bundles prints an empty div that its own script fills,
     * and that script only loads on WooCommerce's screen. Such a panel posts
     * nothing, so its plugin must be treated as if the panel were not there.
     *
     * Read inside ajax_save_product(), after its nonce and capability check.
     *
     * @return string[]|null Panel ids, null when the request did not say (the
     *                       answer from the picked sections alone then stands).
     */
    private static function posted_filled_panels() {
        if (!isset($_POST['bp_filled_panels']) || !is_string($_POST['bp_filled_panels'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see the docblock.
            return null;
        }
        $ids = json_decode(wp_unslash($_POST['bp_filled_panels']), true, 2); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ids are compared, never output.
        if (!is_array($ids)) return null;
        $out = [];
        foreach (array_slice($ids, 0, 300) as $id) {
            if (is_string($id) && $id !== '' && strlen($id) <= 200) $out[] = $id;
        }
        return $out;
    }

    /**
     * Run $fn in the product context WooCommerce gives its product-data hooks
     * ($post, $thepostid, $product_object, the product screen), the way the
     * render path and the save dispatch below do. Restored afterwards.
     *
     * @param WC_Product $product
     * @param callable   $fn
     * @return mixed What $fn returns.
     */
    private static function in_product_context($product, callable $fn) {
        global $post, $thepostid, $product_object, $current_screen, $pagenow;
        $orig = [
            'post' => $post, 'tp' => $thepostid ?? null, 'po' => $product_object ?? null,
            'cs' => $current_screen, 'pn' => $pagenow ?? null,
        ];
        $post           = get_post($product->get_id());
        $thepostid      = $product->get_id();
        $product_object = $product;
        if (function_exists('set_current_screen')) set_current_screen('product');
        $pagenow = 'post.php';
        try {
            return $fn();
        } finally {
            $post           = $orig['post'];
            $thepostid      = $orig['tp'];
            $product_object = $orig['po'];
            $current_screen = $orig['cs'];
            $pagenow        = $orig['pn'];
        }
    }

    /**
     * Which plugins this save keeps off WooCommerce's product save hooks.
     *
     * `panels`: this type's own panels the submitted form did not carry, either
     * not picked (unrendered_type_panels()) or picked but posted empty
     * (posted_filled_panels()). Their plugins read a missing field as "cleared",
     * on `woocommerce_process_product_meta_{type}` (Easy Product Bundles, WPC
     * Product Bundles: one save emptied the bundle) as much as on the object
     * hook (Product Bundles, Composite). `owners`: the plugins those panels
     * belong to. `throwers`: unrepresented_owner_paths(), kept for the object
     * hook only, as before.
     *
     * @param WC_Product|null $product Product being saved, with its new type.
     * @return array{panels: string[], owners: string[], throwers: string[]}
     */
    private static function save_hook_guard($product) {
        $guard = ['panels' => [], 'owners' => [], 'throwers' => []];
        if (!$product || !method_exists($product, 'get_type') || !$product->get_id()) return $guard;
        if (self::is_native_editor_type((string) $product->get_type())) return $guard;

        try {
            return self::in_product_context($product, static function () use ($product, $guard) {
                $type   = (string) $product->get_type();
                $panels = self::unrendered_type_panels($product);
                $filled = self::posted_filled_panels();
                if ($filled !== null) {
                    $picked = self::picked_sections();
                    foreach (self::type_panel_candidates($type) as $target) {
                        if (in_array($target, $panels, true) || in_array($target, $filled, true)) continue;
                        if (in_array('tab:' . $target, $picked, true)) $panels[] = $target;
                    }
                }
                $guard['panels']   = array_values(array_unique($panels));
                $guard['owners']   = $guard['panels'] ? self::tab_owner_paths($guard['panels']) : [];
                $guard['throwers'] = self::unrepresented_owner_paths($product);
                return $guard;
            });
        } catch (\Throwable $e) {
            // Never let the guard break a save: fall back to firing as before.
            return $guard;
        }
    }

    /**
     * The other plugins' product types, as the editor script needs them to
     * follow a type change made on the page (a new product turned into a
     * bundle): `own_panel` have a settings box of their own, so their price is
     * the plugin's business and not required here; `unrepresented` have that box
     * missing or empty on this form, so the "settings live in WooCommerce" note
     * shows for them. Called on the render path after the field capture.
     *
     * @param WC_Product|null $product   Product being edited.
     * @param string[]        $types     Registered type slugs.
     * @return array{own_panel: string[], unrepresented: string[]}
     */
    private static function type_guard_lists($product, array $types) {
        $lists = ['own_panel' => [], 'unrepresented' => []];
        $types = array_values(array_filter(array_map('strval', $types), static function ($t) {
            return $t !== '' && !self::is_native_editor_type($t);
        }));
        if (!$types || !($product instanceof WC_Product) || !$product->get_id()) return $lists;

        try {
            return self::in_product_context($product, static function () use ($types, $lists) {
                $empty = isset(self::$capture_info['empty_panels']) ? (array) self::$capture_info['empty_panels'] : [];
                $picked = null;
                foreach ($types as $type) {
                    $candidates = self::type_panel_candidates($type);
                    if (!$candidates) continue;
                    $lists['own_panel'][] = $type;
                    if ($picked === null) $picked = self::picked_sections();
                    foreach ($candidates as $target) {
                        if (!in_array('tab:' . $target, $picked, true) || in_array($target, $empty, true)) {
                            $lists['unrepresented'][] = $type;
                            break;
                        }
                    }
                }
                return $lists;
            });
        } catch (\Throwable $e) {
            return $lists;
        }
    }

    /**
     * Whether a captured panel holds a field the merchant could fill: a named
     * input, select or textarea that is not hidden. A nonce or a hidden value
     * alone does not count, the same rule as the editor script's
     * filledThirdPartyPanels().
     *
     * @param \DOMElement $node Panel root.
     * @return bool
     */
    private static function panel_has_named_field(\DOMElement $node) {
        foreach (['input', 'select', 'textarea'] as $tag) {
            foreach ($node->getElementsByTagName($tag) as $field) {
                if (!($field instanceof \DOMElement) || trim($field->getAttribute('name')) === '') continue;
                if ($tag === 'input' && strtolower($field->getAttribute('type')) === 'hidden') continue;
                return true;
            }
        }
        return false;
    }

    /**
     * Take the callbacks declared by $paths off $hooks; put them back with
     * reattach_callbacks(). Detached in place through remove_action(), see
     * do_action_excluding_paths() for why a rebuilt WP_Hook is not used.
     *
     * @param string[] $hooks
     * @param string[] $paths Plugin dirs/files whose callbacks come off.
     * @return array<int, array{hook: string, function: mixed, priority: int, accepted_args: int}>
     */
    private static function detach_callbacks(array $hooks, array $paths) {
        global $wp_filter;
        $detached = [];
        if (!$paths) return $detached;
        foreach ($hooks as $hook) {
            if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook])
                || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $priority => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (!isset($entry['function'])) continue;
                    $owner = self::callback_owner_path($entry['function']);
                    if ($owner === '' || !in_array($owner, $paths, true)) continue;
                    $detached[] = [
                        'hook'          => $hook,
                        'function'      => $entry['function'],
                        'priority'      => (int) $priority,
                        'accepted_args' => isset($entry['accepted_args']) ? (int) $entry['accepted_args'] : 1,
                    ];
                }
            }
        }
        foreach ($detached as $cb) {
            remove_action($cb['hook'], $cb['function'], $cb['priority']);
        }
        return $detached;
    }

    /**
     * @param array<int, array{hook: string, function: mixed, priority: int, accepted_args: int}> $detached From detach_callbacks().
     * @return void
     */
    private static function reattach_callbacks(array $detached) {
        foreach ($detached as $cb) {
            add_action($cb['hook'], $cb['function'], $cb['priority'], $cb['accepted_args']);
        }
    }

    /**
     * Plugins that cannot describe their own product-data tab in BrikPanel's
     * context, and so must be kept off this product's save hook.
     *
     * unrendered_type_panels() finds the gap by reading the `show_if_{type}`
     * class a plugin declares on its tab. That only works for plugins that
     * successfully declare one. A plugin whose `woocommerce_product_data_tabs`
     * callback FATALS here declares nothing at all, and is therefore invisible
     * to that check while being the most certain case of all: a callback that
     * cannot survive being asked "what tabs do you add?" outside its own metabox
     * has definitively not rendered a single field into the form the merchant
     * just submitted. WooCommerce Composite Products is exactly this, it
     * dereferences a `$composite_product_object` global that only its own
     * metabox sets, and it wipes a composite's contents on
     * `woocommerce_admin_process_product_object` for the same "field absent
     * means the merchant cleared it" reason Product Bundles does.
     *
     * Scope is deliberately narrow. Types the editor has a first-class form for
     * return early, exactly as unrendered_type_panels() does, so simple and
     * variable products are never affected however badly a third-party callback
     * misbehaves. On the remaining types only the fataling plugin's own
     * callbacks are held back; every other listener saves as usual.
     *
     * @param WC_Product|null $product Product being rendered or saved.
     * @return string[] Plugin directories / files to mute, empty when there are none.
     */
    private static function unrepresented_owner_paths($product) {
        if (!$product || !method_exists($product, 'get_type')) return [];

        $type = (string) $product->get_type();
        if ($type === '' || self::is_native_editor_type($type)) return [];

        static $cache = [];
        if (isset($cache[$type])) return $cache[$type];

        $paths = [];
        try {
            $tab_meta = self::guard_tab_meta();
            $paths    = isset($tab_meta['throwers']) ? (array) $tab_meta['throwers'] : [];

            // Never hold back WooCommerce itself or BrikPanel. Whatever made a
            // callback in one of those fatal, silencing the platform the save
            // runs on (or ourselves) is a worse outcome than the empty form
            // this guard exists to protect against, and neither of them is the
            // "plugin whose panel we could not render" the mute is meant for.
            $never_mute = array_filter([
                defined('WC_PLUGIN_FILE') ? untrailingslashit(dirname(WC_PLUGIN_FILE)) : '',
                defined('BRIKPANEL_PATH') ? untrailingslashit(BRIKPANEL_PATH) : '',
            ]);
            if ($never_mute) {
                $paths = array_values(array_diff($paths, $never_mute));
            }
        } catch (\Throwable $e) {
            // Never let enumeration break a save or a page render.
            $paths = [];
        }

        /**
         * Filter the plugins BrikPanel mutes because they could not describe
         * their own product-data tab for this product.
         *
         * Returning an empty array restores the legacy behaviour of letting
         * every listener run on `woocommerce_admin_process_product_object`.
         *
         * @param string[]   $paths   Plugin directories / files.
         * @param WC_Product $product Product being saved or rendered.
         * @param string     $type    Product type slug.
         */
        $paths = (array) apply_filters('brikpanel_pe_unrepresented_owner_paths', $paths, $product, $type);

        $cache[$type] = $paths;
        return $paths;
    }

    /**
     * Absolute paths owning the given product-data tab targets.
     *
     * Used to narrow the save-hook guard: only the plugin whose panel the editor
     * could not render has to be kept quiet, so every other listener on
     * `woocommerce_admin_process_product_object` (Germanized, brand/SEO/ERP
     * add-ons…) keeps saving its fields normally on a bundle or composite
     * product. Attribution works by firing `woocommerce_product_data_tabs` one
     * callback at a time and asking which callback introduced the target, then
     * resolving that callback's file to its plugin directory.
     *
     * Returning an empty array means "could not attribute" — the caller then
     * falls back to skipping the hook entirely, which is the safe direction.
     *
     * @param string[] $targets Panel target ids.
     * @return string[] Plugin directories / files owning them.
     */
    private static function tab_owner_paths(array $targets) {
        global $wp_filter;

        $hook  = 'woocommerce_product_data_tabs';
        $paths = [];
        if (!$targets || empty($wp_filter[$hook]) || !is_object($wp_filter[$hook])
            || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
            return $paths;
        }

        $original = $wp_filter[$hook];
        try {
            foreach ($original->callbacks as $priority => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (!isset($entry['function'])) continue;
                    $solo = new \WP_Hook();
                    $solo->add_filter($hook, $entry['function'], $priority, isset($entry['accepted_args']) ? (int) $entry['accepted_args'] : 1);
                    $wp_filter[$hook] = $solo;
                    try {
                        $tabs = apply_filters($hook, []);
                    } catch (\Throwable $e) {
                        continue;
                    }
                    if (!is_array($tabs)) continue;
                    foreach ($tabs as $key => $tab) {
                        $target = (is_array($tab) && isset($tab['target'])) ? (string) $tab['target'] : (string) $key;
                        if (!in_array($target, $targets, true)) continue;
                        $owner = self::callback_owner_path($entry['function']);
                        if ($owner !== '') $paths[] = $owner;
                    }
                }
            }
        } catch (\Throwable $e) {
            $paths = [];
        } finally {
            $wp_filter[$hook] = $original;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Fire an action, leaving out the callbacks declared by given paths.
     *
     * Only the excluded callbacks are detached, through the normal
     * remove_action()/add_action() API, and put back afterwards. Swapping in a
     * rebuilt WP_Hook object would work too, but it would also silently discard
     * any hook change a listener makes while it runs (plugins that unhook
     * themselves after processing a product do exactly that). Detaching in
     * place keeps every other listener's behaviour byte-for-byte identical to a
     * normal WordPress dispatch. Re-attaching runs in `finally`, so even a
     * listener that dies cannot leave the hook permanently short of callbacks.
     * A detached callback comes back at the end of its priority group rather
     * than its original slot; the hook fires once per save request, so that
     * ordering only ever applies to callbacks that were held back anyway.
     *
     * @param string   $hook      Action to fire.
     * @param array    $args      Positional args.
     * @param string[] $skip_paths Plugin dirs/files whose callbacks must not run.
     * @return void
     */
    private static function do_action_excluding_paths($hook, array $args, array $skip_paths) {
        // The owner has no listener on this hook: nothing is held back.
        $detached = self::detach_callbacks([$hook], $skip_paths);
        try {
            do_action_ref_array($hook, $args);
        } finally {
            self::reattach_callbacks($detached);
        }
    }

    /**
     * Resolve a callback to the plugin directory (or file) that declares it.
     *
     * @param callable|mixed $function Callback from a WP_Hook entry.
     * @return string Absolute path, '' when it cannot be determined.
     */
    private static function callback_owner_path($function) {
        $file = self::callback_file($function);
        if ($file === '') return '';

        // Collapse to the plugin root so every file of that plugin matches.
        foreach ([defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '', defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : ''] as $base) {
            if ($base === '' || strpos($file, $base . '/') !== 0) continue;
            $rest = substr($file, strlen($base) + 1);
            $slash = strpos($rest, '/');
            return $slash === false ? $file : $base . '/' . substr($rest, 0, $slash);
        }
        return $file;
    }

    /**
     * The file that declares a hook callback.
     *
     * @param callable|mixed $function Callback from a WP_Hook entry.
     * @return string Absolute path, '' when it cannot be determined.
     */
    private static function callback_file($function) {
        try {
            if (is_string($function) && function_exists($function)) {
                $ref = new \ReflectionFunction($function);
            } elseif ($function instanceof \Closure) {
                $ref = new \ReflectionFunction($function);
            } elseif (is_array($function) && count($function) === 2) {
                $ref = new \ReflectionMethod(is_object($function[0]) ? get_class($function[0]) : $function[0], $function[1]);
            } elseif (is_object($function) && method_exists($function, '__invoke')) {
                $ref = new \ReflectionMethod($function, '__invoke');
            } else {
                return '';
            }
            return (string) $ref->getFileName();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Fire one of WooCommerce's own product-data hooks (the lists in
     * core_product_data_sub_hooks()) for capture, leaving WooCommerce's own
     * callbacks on it out.
     *
     * What these hooks print is offered as "fields from your other plugins".
     * WooCommerce prints a field of its own on one of them, the
     * stock-notification checkbox on `woocommerce_product_options_stock_status`,
     * and counted as another plugin's it would put the discovery card on every
     * store. So WooCommerce's callbacks are detached for the fire and put back
     * right after, in `finally`; every other plugin's callback runs exactly as
     * WooCommerce would run it.
     *
     * @param string $hook   Core product-data hook.
     * @param bool   $render True: render_hook_capture() (the product being
     *                       edited, fired once). False: capture_isolated_hook()
     *                       (the settings picker's probe product).
     * @return string Captured HTML.
     */
    private static function capture_core_subhook($hook, $render = true) {
        $detached = self::detach_woocommerce_callbacks($hook);
        try {
            return $render ? self::render_hook_capture($hook) : self::capture_isolated_hook($hook);
        } finally {
            self::reattach_callbacks($detached);
        }
    }

    /**
     * Take WooCommerce's own callbacks off a hook, for capture_core_subhook().
     *
     * Matched on the real path of the declaring file, so a WooCommerce folder
     * reached through a symlink still counts as WooCommerce.
     *
     * @param string $hook Action hook.
     * @return array<int, array{hook: string, function: mixed, priority: int, accepted_args: int}> For reattach_callbacks().
     */
    private static function detach_woocommerce_callbacks($hook) {
        global $wp_filter;
        static $wc_dir = null;
        if ($wc_dir === null) {
            $wc_dir = defined('WC_PLUGIN_FILE') ? (string) realpath(dirname(WC_PLUGIN_FILE)) : '';
        }
        $detached = [];
        if ($wc_dir === '' || empty($wp_filter[$hook]) || !is_object($wp_filter[$hook])
            || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
            return $detached;
        }
        foreach ($wp_filter[$hook]->callbacks as $priority => $entries) {
            if (!is_array($entries)) continue;
            foreach ($entries as $entry) {
                if (!isset($entry['function'])) continue;
                $file = self::callback_file($entry['function']);
                $real = $file !== '' ? realpath($file) : false;
                if ($real === false || strpos($real, $wc_dir . DIRECTORY_SEPARATOR) !== 0) continue;
                $detached[] = [
                    'hook'          => $hook,
                    'function'      => $entry['function'],
                    'priority'      => (int) $priority,
                    'accepted_args' => isset($entry['accepted_args']) ? (int) $entry['accepted_args'] : 1,
                ];
            }
        }
        foreach ($detached as $cb) {
            remove_action($cb['hook'], $cb['function'], $cb['priority']);
        }
        return $detached;
    }

    /**
     * capture_isolated_hook() / capture_hook_chunks() for the product-data
     * hooks, fired at most once per product while the editor renders.
     *
     * See $render_product. Outside the editor render (settings picker, orders
     * screen) this is a plain pass-through.
     *
     * @param string $hook   Argument-less product-data action hook.
     * @param bool   $chunks True for per-callback chunks, false for one string.
     * @return string|string[]
     */
    private static function render_hook_capture($hook, $chunks = false) {
        $id = self::$render_product ? (int) self::$render_product->get_id() : 0;
        if (!$id || (int) ($GLOBALS['thepostid'] ?? 0) !== $id) {
            return $chunks ? self::capture_hook_chunks($hook) : self::capture_isolated_hook($hook);
        }
        $key = $hook . '|' . ($chunks ? 'chunks' : 'html') . '|' . $id;
        if (!array_key_exists($key, self::$render_hook_memo)) {
            self::$render_hook_memo[$key] = $chunks
                ? self::capture_hook_chunks($hook)
                : self::capture_isolated_hook($hook);
        }
        return self::$render_hook_memo[$key];
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
            // CURCY's inputs print only in their own block, never twice.
            self::without_multicurrency_callbacks($hook, static function () use ($hook, $args) {
                do_action_ref_array($hook, $args);
            });
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
     * Fire a hook one callback at a time and return what each echoed, separately.
     *
     * capture_isolated_hook() already swallows a Throwable, but it fires the
     * whole hook with a single do_action(): the first callback that fatals ends
     * that dispatch, so every plugin registered AFTER it never runs. On
     * `woocommerce_product_data_panels` that is visible damage — a panel that
     * dereferences a global only set for its own product type (WooCommerce
     * Product Bundles does exactly this) took every later plugin's panel out of
     * the settings picker and out of the editor with it.
     *
     * Callbacks are invoked through do_action() on a temporarily narrowed hook
     * rather than called directly, so current_filter()/doing_action() and the
     * priority order stay exactly as WooCommerce would present them. This is
     * the same snapshot/restore technique as
     * render_isolated_multicurrency_fields().
     *
     * The chunks are returned one per callback instead of concatenated because
     * a callback that dies mid-tag leaves unbalanced HTML: parsed together, the
     * next plugin's panel is swallowed as a CHILD of that unterminated element
     * and disappears just as surely as it did before. Callers parse each chunk
     * on its own, so one plugin's broken markup stays that plugin's problem.
     *
     * @param string $hook Action hook to fire.
     * @param array  $args Positional args passed to the hook callbacks.
     * @return string[] Non-empty output chunks, in hook order.
     */
    private static function capture_hook_chunks($hook, array $args = []) {
        global $wp_filter;

        if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook])
            || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
            $single = self::capture_isolated_hook($hook, $args);
            return $single === '' ? [] : [$single];
        }

        $original  = $wp_filter[$hook];
        $callbacks = $original->callbacks;
        $chunks    = [];

        try {
            foreach ($callbacks as $priority => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (!isset($entry['function'])) continue;
                    // Build the one-callback hook through add_filter() rather
                    // than by assigning ->callbacks: WP_Hook keeps a parallel
                    // ->priorities list that apply_filters() iterates, and a
                    // hand-built object leaves it empty (PHP warnings, nothing
                    // runs).
                    $solo = new \WP_Hook();
                    $solo->add_filter(
                        $hook,
                        $entry['function'],
                        $priority,
                        isset($entry['accepted_args']) ? (int) $entry['accepted_args'] : 1
                    );
                    $wp_filter[$hook] = $solo;

                    $piece = self::capture_isolated_hook($hook, $args);
                    if ($piece !== '') $chunks[] = $piece;
                }
            }
        } finally {
            // Always hand the hook back exactly as we found it — a leaked
            // single-callback hook would silence every other plugin for the
            // rest of the request.
            $wp_filter[$hook] = $original;
        }

        return $chunks;
    }

    /**
     * Apply a filter one callback at a time and return each callback's result
     * separately, plus the owners of the callbacks that fataled.
     *
     * The filter twin of capture_hook_chunks(). A single apply_filters() runs
     * every callback inside one PHP call, so a Throwable from ANY of them
     * abandons that call entirely: the contributions of the callbacks that
     * already ran are lost with it, and the callbacks registered after it never
     * run. On `woocommerce_product_data_tabs` that is not cosmetic, it is the
     * difference between BrikPanel knowing a product type has a panel it cannot
     * render and BrikPanel believing the store has no third-party tabs at all.
     * WooCommerce Composite Products fatals on that filter in any context but
     * its own metabox (its `$composite_product_object` global is never set for
     * us), which silently disabled the whole unrendered-panel guard, and
     * emptied the settings section picker, on every store that has it.
     *
     * Callbacks are dispatched through apply_filters() on a temporarily
     * narrowed hook rather than called directly, so current_filter() and the
     * priority order stay exactly as WordPress would present them.
     *
     * Each callback receives $initial, NOT the accumulated value: results are
     * returned separately for the caller to merge. A callback that decorates or
     * removes another plugin's entry therefore cannot do so here, which is why
     * callers should treat this as the degraded path and prefer one combined
     * fire while that still works.
     *
     * @param string $hook    Filter hook to apply.
     * @param mixed  $initial Value handed to every callback.
     * @return array{results: array[], throwers: string[]} Per-callback results in hook order, and the plugin paths that fataled.
     */
    private static function filter_chunks($hook, $initial = []) {
        global $wp_filter;

        if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook])
            || !isset($wp_filter[$hook]->callbacks) || !is_array($wp_filter[$hook]->callbacks)) {
            try {
                return ['results' => [apply_filters($hook, $initial)], 'throwers' => []];
            } catch (\Throwable $e) {
                return ['results' => [], 'throwers' => []];
            }
        }

        $original  = $wp_filter[$hook];
        $callbacks = $original->callbacks;
        $results   = [];
        $throwers  = [];

        try {
            foreach ($callbacks as $priority => $entries) {
                if (!is_array($entries)) continue;
                foreach ($entries as $entry) {
                    if (!isset($entry['function'])) continue;
                    // Built through add_filter() rather than by assigning
                    // ->callbacks: WP_Hook keeps a parallel ->priorities list
                    // that apply_filters() iterates, and a hand-built object
                    // leaves it empty (PHP warnings, nothing runs).
                    $solo = new \WP_Hook();
                    $solo->add_filter(
                        $hook,
                        $entry['function'],
                        $priority,
                        isset($entry['accepted_args']) ? (int) $entry['accepted_args'] : 1
                    );
                    $wp_filter[$hook] = $solo;

                    try {
                        $results[] = apply_filters($hook, $initial);
                    } catch (\Throwable $e) {
                        // This plugin cannot answer in BrikPanel's context. Its
                        // own UI is therefore unrenderable here too, record the
                        // owner so the save path can keep it away from a form it
                        // never contributed a single field to.
                        $owner = self::callback_owner_path($entry['function']);
                        if ($owner !== '') $throwers[] = $owner;
                    }
                }
            }
        } finally {
            // Always hand the hook back exactly as we found it, a leaked
            // single-callback hook would silence every other plugin for the
            // rest of the request.
            $wp_filter[$hook] = $original;
        }

        return ['results' => $results, 'throwers' => array_values(array_unique($throwers))];
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
        if ( ! self::multicurrency_autosurface_active() ) {
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
     * Auto-surface every product-data / variation section that a third-party
     * plugin has actually added fields to, without the merchant having to pick
     * them in settings.
     *
     * The section-collectors (collect_wc_product_data_sections /
     * collect_wc_variation_sections) already return ONLY the sections that
     * contain real third-party content (they gate on has_action() and on the
     * hook echoing a control), and the render path already isolates and
     * buffer-guards each one. So "automatic" is simply: merge every available
     * section key into the selected list. Core-only tabs never appear (their
     * hooks have no listeners), so this never duplicates BrikPanel's own native
     * fields; it only pulls in what other plugins bolted on.
     *
     * Gated (for the product-data context) by the `brikpanel_pe_wc_tabs_auto`
     * option (default off) and a code filter so a store can enable it globally
     * or per-context. The variation context is never gated (see below).
     *
     * @param string[] $selected Section keys already chosen in settings.
     * @param string   $context  'product' or 'variation'.
     * @return string[] Possibly-augmented section keys.
     */
    private static function augment_sections_auto( array $selected, $context ) {
        // Per-variation sections are ALWAYS surfaced, regardless of the toggle.
        // BrikPanel fires woocommerce_save_product_variation for every variation
        // on every save, and many per-variation field handlers (internal product
        // codes, etc.) DELETE their meta when their input is absent from the
        // post. So the fields must always be captured and forwarded, or a save
        // would wipe them. The toggle instead controls whether they are shown to
        // the merchant (see the variation-row renderer): when off they are still
        // present in the form as hidden inputs carrying their current values, so
        // the save round-trips them unchanged. Product-data tab sections stay
        // gated by the toggle; they save through woocommerce_process_product_meta,
        // which BrikPanel only authenticates (nonce) when the toggle is on, so
        // with it off no such handler runs and nothing is wiped.
        if ( 'variation' !== $context && get_option( 'brikpanel_pe_wc_tabs_auto', 'no' ) !== 'yes' ) {
            return $selected;
        }
        /**
         * Toggle automatic surfacing of third-party product-editor fields.
         *
         * @param bool   $enabled Default true.
         * @param string $context 'product' or 'variation'.
         */
        if ( ! apply_filters( 'brikpanel_pe_auto_surface_thirdparty', true, $context ) ) {
            return $selected;
        }
        $available = ( 'variation' === $context )
            ? self::collect_wc_variation_sections()
            : self::collect_wc_product_data_sections();
        if ( ! is_array( $available ) ) {
            return $selected;
        }
        foreach ( array_keys( $available ) as $key ) {
            if ( ! in_array( $key, $selected, true ) ) {
                $selected[] = $key;
            }
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
                if ( self::is_multicurrency_callback( $cb ) ) {
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
     * Whether CURCY is in fixed-price mode and its per-currency price fields
     * are auto-surfaced in their own "Multi-currency prices" block.
     *
     * @return bool
     */
    /**
     * Whether the multi-currency price block alone puts the "Additional product
     * data" card on the page (CURCY in fixed-price mode). The asset bootstrap
     * asks: that card also carries every other plugin's panel, hidden, and
     * those panels need their scripts.
     *
     * @return bool
     */
    public static function multicurrency_card_active() {
        return self::multicurrency_autosurface_active();
    }

    /**
     * Whether this editor load puts the folded "Fields from your other
     * plugins" box on the page: the person may change store settings, the
     * setting that shows those fields is off, and the store is not known to
     * have none. The asset bootstrap asks too (brikpanel-enqueue.php): the
     * box carries other plugins' panels, and those panels need their scripts
     * as much as in the open card.
     *
     * @return bool
     */
    public static function thirdparty_fold_wanted() {
        return current_user_can('manage_woocommerce')
            && !self::thirdparty_setting_on()
            && !self::thirdparty_fold_known_empty();
    }

    private static function multicurrency_autosurface_active() {
        if ( ! class_exists( 'WOOMULTI_CURRENCY_Data' ) ) {
            return false;
        }
        $data = WOOMULTI_CURRENCY_Data::get_ins();
        if ( ! is_object( $data ) || ! method_exists( $data, 'check_fixed_price' ) || ! $data->check_fixed_price() ) {
            return false;
        }
        /**
         * Allow disabling the automatic surfacing of multi-currency per-currency
         * price fields in the product editor.
         *
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters( 'brikpanel_pe_auto_multicurrency_pricing', true );
    }

    /**
     * Whether a registered hook callback belongs to CURCY.
     *
     * @param array $cb One $wp_filter callback entry.
     * @return bool
     */
    private static function is_multicurrency_callback( $cb ) {
        $fn  = is_array( $cb ) && isset( $cb['function'] ) ? $cb['function'] : null;
        $cls = '';
        if ( is_array( $fn ) && isset( $fn[0] ) ) {
            $cls = is_object( $fn[0] ) ? get_class( $fn[0] ) : ( is_string( $fn[0] ) ? $fn[0] : '' );
        } elseif ( is_string( $fn ) && strpos( $fn, '::' ) !== false ) {
            $cls = substr( $fn, 0, strpos( $fn, '::' ) );
        }
        return $cls !== '' && stripos( $cls, 'WOOMULTI_CURRENCY' ) !== false;
    }

    /**
     * Whether CURCY saves its product-level price fields for this product type.
     *
     * CURCY only saves them on `woocommerce_process_product_meta_<type>` for
     * the types it lists (simple, external, …, never variable). On any other
     * type the fields are dead inputs, and WooCommerce hides them natively
     * (the pricing block is show_if_simple), so the editor must not show them.
     *
     * @param string $type Product type slug.
     * @return bool
     */
    private static function multicurrency_saves_product_type( $type ) {
        global $wp_filter;
        $hook = 'woocommerce_process_product_meta_' . sanitize_key( (string) $type );
        if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
            return false;
        }
        foreach ( $wp_filter[ $hook ]->callbacks as $list ) {
            foreach ( $list as $cb ) {
                if ( self::is_multicurrency_callback( $cb ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Run $fn with CURCY's own callbacks detached from $hook.
     *
     * While the per-currency prices are auto-surfaced in their own block,
     * every other capture of CURCY's render hooks (the General section, the
     * variation pricing section, the settings picker) must leave them out, or
     * the same inputs print twice. Two inputs with one name make the save
     * collector keep the second, untouched copy, so the typed price was lost.
     *
     * @param string   $hook Action hook about to be fired.
     * @param callable $fn   Capture to run.
     * @return mixed Whatever $fn returns.
     */
    private static function without_multicurrency_callbacks( $hook, callable $fn ) {
        global $wp_filter;
        if ( ! in_array( $hook, array( 'woocommerce_product_options_pricing', 'woocommerce_variation_options_pricing' ), true )
            || empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] )
            || ! self::multicurrency_autosurface_active() ) {
            return $fn();
        }
        $hook_obj = $wp_filter[ $hook ];
        $saved    = $hook_obj->callbacks;
        $kept     = array();
        $removed  = false;
        foreach ( $saved as $priority => $list ) {
            foreach ( $list as $id => $cb ) {
                if ( self::is_multicurrency_callback( $cb ) ) {
                    $removed = true;
                    continue;
                }
                $kept[ $priority ][ $id ] = $cb;
            }
        }
        if ( ! $removed ) {
            return $fn();
        }
        $hook_obj->callbacks = $kept;
        try {
            return $fn();
        } finally {
            $hook_obj->callbacks = $saved;
        }
    }

    /**
     * Give CURCY's save handlers the request shape they expect, run $fn, then
     * put $_POST back.
     *
     * CURCY checks its own `_wmc_nonce` with a different action for the
     * product (simple) and the variation save. WooCommerce sends them in two
     * separate requests; this editor sends one form, so one of the two values
     * always lost and that save was skipped. The request is already verified
     * (editor nonce + edit_product), so a fresh nonce for the matching action
     * is minted, and only when CURCY's fields were actually in the form.
     *
     * CURCY also keeps a sale price only when WooCommerce's native sale field
     * is posted non-empty (`_sale_price`, `variable_sale_price[<loop>]`) and
     * reads the native sale end date; this editor posts those under its own
     * names, so they are mirrored here. Without it the per-currency sale price
     * was wiped on every save.
     *
     * @param string   $context 'product' or 'variation'.
     * @param array    $native  Native $_POST keys to mirror => value.
     * @param callable $fn      Dispatch to run.
     * @return void
     */
    private static function with_multicurrency_save_context( $context, array $native, callable $fn ) {
        if ( ! class_exists( 'WOOMULTI_CURRENCY_Data' ) || ! isset( $_POST['_wmc_nonce'] ) ) {
            $fn();
            return;
        }
        $action = ( 'variation' === $context ) ? 'wmc_save_variable_product_currency' : 'wmc_save_simple_product_currency';
        $keys   = array_merge( array( '_wmc_nonce' ), array_keys( $native ) );
        $saved  = array();
        foreach ( $keys as $key ) {
            $saved[ $key ] = array_key_exists( $key, $_POST ) ? $_POST[ $key ] : null;
        }
        $_POST['_wmc_nonce'] = wp_create_nonce( $action );
        foreach ( $native as $key => $value ) {
            if ( ! array_key_exists( $key, $_POST ) ) {
                $_POST[ $key ] = $value;
            }
        }
        try {
            $fn();
        } finally {
            foreach ( $saved as $key => $value ) {
                if ( null === $value ) {
                    unset( $_POST[ $key ] );
                } else {
                    $_POST[ $key ] = $value;
                }
            }
        }
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
        $selected = self::augment_sections_auto($selected, 'variation');
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
        // pricing hook. The pricing section itself leaves them out (see
        // without_multicurrency_callbacks()), so they print exactly once.
        $curcy_var = in_array('curcy:variation_pricing', $selected, true);
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
            // Exactly WooCommerce's shape (WC_AJAX::load_variations()): meta
            // values stay arrays, attributes are plain strings. Flattening the
            // meta to scalars broke callbacks written against that contract:
            // Measurement Price Calculator runs current() on a meta value, which
            // is a TypeError on a string, so its Minimum Price field vanished
            // from every variation that had one saved.
            $variation_data = array_merge(
                (array) get_post_custom($variation_post->ID),
                (array) wc_get_product_variation_attributes($variation_post->ID)
            );

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
        $selected = self::augment_sections_auto($selected, 'variation');
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
        // printed once (the pricing section leaves them out).
        $curcy_var = in_array('curcy:variation_pricing', $selected, true);
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

        $loops  = range(0, $count - 1);
        $extras = $this->preview_wc_variation_fields($product, $loops);
        /**
         * Filter the per-variation extras HTML for not-yet-saved (preview)
         * variation rows. Keyed by zero-based loop index.
         *
         * @param array<int,string> $extras  [loop => html]
         * @param WC_Product         $product Parent variable product.
         * @param array              $loops   Zero-based row indices.
         * @param string             $context 'preview'.
         */
        $extras = apply_filters('brikpanel_pe_variation_extras_preview', $extras, $product, $loops, 'preview');
        wp_send_json_success(['extras' => $extras]);
    }

    /**
     * AJAX: delete every variation of a product in one go.
     *
     * Unlike the per-row delete (which only edits the client-side list and
     * lets the next save reconcile it), this removes the variations from the
     * database right away — that is what makes it usable on a matrix of
     * hundreds of rows, where a full round-trip payload is the slow part.
     *
     * The parent deliberately stays a VARIABLE product and keeps its
     * attributes: clearing the list is "start this matrix over", not "this is
     * a simple product now". Concretely that means never calling
     * set_attributes() here — save_variations() only rewrites the parent's
     * attributes because it is handed a fresh `attributes` payload, and there
     * is none in this request.
     */
    public function ajax_clear_variations() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            // Product was never saved — there is nothing on disk to delete and
            // the client already cleared its own list.
            wp_send_json_success(['deleted' => 0, 'remaining' => 0, 'done' => true]);
        }

        // Per-object capability. `edit_products` alone is author-scoped for
        // some roles, so without this a user could POST an arbitrary product
        // id and wipe a matrix they have no business touching.
        if (!current_user_can('edit_product', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found.', 'brikpanel')]);
        }

        $is_variable = function_exists('brikpanel_is_variable_product_type')
            ? brikpanel_is_variable_product_type($product->get_type())
            : $product->is_type('variable');
        if (!$is_variable) {
            wp_send_json_success(['deleted' => 0, 'remaining' => 0, 'done' => true]);
        }

        // Query the children directly instead of using get_children(). WC's
        // read_children() only returns `publish` and `private` variations, so
        // a row a third party pushed to `draft` would survive the sweep and
        // leave the "cleared" product with invisible children that reappear
        // on the storefront the moment something republishes them.
        $child_ids = get_posts([
            'post_type'        => 'product_variation',
            'post_parent'      => $product_id,
            'post_status'      => 'any',
            'numberposts'      => -1,
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        // Delete in bounded batches. Each ->delete(true) fires the full WC hook
        // chain, so a matrix of a few thousand variations would otherwise run
        // past max_execution_time and die halfway through with no answer for
        // the client. The caller re-posts while `remaining` is above zero, so
        // a big clear is several short requests instead of one that may never
        // return. Both bounds matter: the count keeps a fast store responsive,
        // the wall clock covers a slow one where 100 deletes already take too
        // long. Filterable for stores that would rather tune it.
        $batch_limit   = (int) apply_filters('brikpanel_pe_clear_variations_batch', 100, $product_id);
        $time_limit    = (float) apply_filters('brikpanel_pe_clear_variations_seconds', 10.0, $product_id);
        $batch_limit   = max(1, $batch_limit);
        $started       = microtime(true);
        $total         = count($child_ids);

        $deleted = 0;
        foreach ($child_ids as $child_id) {
            if ($deleted >= $batch_limit || (microtime(true) - $started) >= $time_limit) {
                break;
            }
            // Delete through the WC object so woocommerce_delete_product_variation
            // fires and the SKU lookup guard prunes the deferred sync rows for
            // us. wp_delete_post() is only the fallback for a row the product
            // factory refuses to build (corrupt type meta).
            $child = wc_get_product($child_id);
            if ($child) {
                $child->delete(true);
            } else {
                wp_delete_post($child_id, true);
            }
            $deleted++;
        }

        $remaining = max(0, $total - $deleted);
        if ($remaining > 0) {
            // More to go. Skip the cleanup tail — syncing a parent that still
            // has children half-removed would only be undone by the next
            // batch, and the client is about to call straight back.
            wp_send_json_success([
                'deleted'   => $deleted,
                'remaining' => $remaining,
                'done'      => false,
            ]);
        }

        // Same cleanup tail save_variations() runs. An in-flight save_post
        // listener can reload the parent through the factory and flip the
        // product_type term back to `simple`, so reassert the term and drop
        // the type cache before anything reads it again.
        wp_set_object_terms($product_id, 'variable', 'product_type', false);
        wp_cache_delete(
            WC_Cache_Helper::get_cache_prefix('product_' . $product_id) . '_type_' . $product_id,
            'products'
        );
        clean_post_cache($product_id);

        // With every variation gone, product-level stock would make sync()
        // keep the product's own status instead of deriving it (out of stock)
        // from the empty list. A product that used shared stock gets it back
        // with its next save: the editor still holds the "Shared stock"
        // switch and quantity, and the rows it adds follow them.
        $variable = $product instanceof WC_Product_Variable
            ? $product
            : new WC_Product_Variable($product_id);
        if ($variable->get_manage_stock()) {
            $variable->set_manage_stock(false);
            $variable->save();
        }
        WC_Product_Variable::sync($variable);
        wc_delete_product_transients($product_id);

        /**
         * Fires after every variation of a product was removed by the editor's
         * "Clear all" action. The parent is still a variable product.
         *
         * @param int   $product_id Parent product ID.
         * @param int[] $child_ids  IDs of the variations that were deleted.
         */
        do_action('brikpanel_pe_variations_cleared', $product_id, $child_ids);

        wp_send_json_success([
            'deleted'   => $deleted,
            'remaining' => 0,
            'done'      => true,
        ]);
    }

    /**
     * Core sub-hooks grouped by native tab label — 3rd parties inject into these.
     *
     * Every hook WooCommerce fires inside a native tab belongs here: a plugin
     * field on a hook missing from this list is never rendered, and its
     * "save or clear when absent" handler then wipes the value on every save
     * (Measurement Price Calculator's Area / Volume on
     * `woocommerce_product_options_dimensions`).
     *
     * Always fired through capture_core_subhook(), which leaves WooCommerce's
     * own callbacks out. `woocommerce_product_options_stock_status` used to be
     * left off this list because WooCommerce prints its stock-notification
     * checkbox there, and skipping the hook hid every plugin field printed in
     * the same spot, under Stock status: AgenWebsite Multi Warehouse's
     * per-warehouse stock box could not be shown or even offered (wp.org
     * sizulku, November 2026).
     */
    private static function core_product_data_sub_hooks() {
        return [
            __('General', 'brikpanel')         => [
                'woocommerce_product_options_general_product_data',
                'woocommerce_product_options_external',
                'woocommerce_product_options_pricing',
                'woocommerce_product_options_downloads',
                'woocommerce_product_options_tax',
                'woocommerce_product_options_sku',
            ],
            __('Inventory', 'brikpanel')       => [
                'woocommerce_product_options_global_unique_id',
                'woocommerce_product_options_stock',
                'woocommerce_product_options_stock_fields',
                'woocommerce_product_options_stock_status',
                'woocommerce_product_options_sold_individually',
                'woocommerce_product_options_inventory_product_data',
            ],
            __('Shipping', 'brikpanel')        => [
                'woocommerce_product_options_dimensions',
                'woocommerce_product_options_shipping',
                'woocommerce_product_options_shipping_product_data',
            ],
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
     * Render the "Additional product data" sections for a product.
     *
     * Sections come from `brikpanel_pe_wc_tabs_selected` (plus the automatic
     * ones); if there are none, nothing is emitted (default-off behaviour
     * requested by admins). When the card renders, the sections that were not
     * picked are on the page too, hidden, so their values survive the save.
     * With $fold (the setting is off, see render_page()) the sections the store
     * does not show go to the "Fields from your other plugins" box instead
     * (self::$capture_info['fold_html']). self::$capture_info says what ended
     * up in the card.
     *
     * @param int             $product_id Product being edited.
     * @param WC_Product|null $product    The product.
     * @param bool            $fold       Collect the unshown sections for the box.
     * @return string The card's shown (and kept-only) sections.
     */
    private function capture_wc_product_data_fields($product_id, $product, $fold = false) {
        // Section discovery below must fire the product-data hooks for THIS
        // product, once, and hand the output to the render (see $render_product).
        self::$render_product = ($product instanceof WC_Product && (int) $product->get_id() === (int) $product_id) ? $product : null;

        $selected = (array) get_option('brikpanel_pe_wc_tabs_selected', []);
        $selected = self::augment_sections_for_multicurrency($selected, 'product');
        $selected = self::augment_sections_auto($selected, 'product');
        // Folded: every section the store does not show goes in the box. When
        // the card goes out with the save anyway (a section shown regardless of
        // the setting: the multi-currency prices), a folded section still
        // travels as a kept-only copy until the box is opened ($fold_keep), as
        // it did before the box; otherwise the editor script holds the whole
        // card back until then (render_page()).
        $fold      = (bool) $fold;
        $fold_shut = $fold && !isset(self::open_boxes()['plugins']);
        $fold_keep = $fold_shut && !empty($selected);
        $fold_out  = '';
        $fold_seen = 0;
        // Any section another plugin has here, also one this product's type
        // leaves out: what thirdparty_fold_remember() learns about the store.
        $fold_found = false;
        self::$capture_info = ['any_visible' => false, 'panels' => [], 'empty_panels' => [], 'fold_html' => '', 'fold_visible' => 0, 'fold_hold' => false, 'fold_found' => false];
        if (empty($selected) && !$fold) {
            self::$render_product = null;
            return '';
        }

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
        // Whether any section ends up on screen. When none does (a simple
        // product whose only picked section is Blocksy's variable-only Swatches)
        // the card is printed hidden: its fields still have to travel.
        $any_visible = false;
        $visibility  = self::wc_tab_visibility_context($product);

        // Multi-currency per-currency price fields — auto-surfaced in fixed
        // mode via the synthetic `curcy:product_pricing` key. Rendered in
        // isolation so ONLY the CURCY inputs appear, never the rest of the
        // General tab that other plugins also hook.
        if (in_array('curcy:product_pricing', $selected, true)
            && self::multicurrency_saves_product_type($product instanceof WC_Product ? $product->get_type() : 'simple')) {
            $curcy_html = self::render_isolated_multicurrency_fields('woocommerce_product_options_pricing');
            if ($curcy_html !== '') {
                $any_visible = true;
                $output .= '<div class="brikpanel-pe-wc-tab-group" data-tab="multicurrency">'
                    . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html__('Multi-currency prices', 'brikpanel') . '</h4>'
                    . $curcy_html
                    . '</div>';
            }
        }

        // Core sub-hook sections — keyed `core:<label_slug>`.
        //
        // We render EVERY core section that carries third-party content, then
        // show the ones in $selected and keep the rest hidden. The hidden ones
        // still forward their current values on save: BrikPanel emits the
        // WooCommerce save nonce whenever this card renders, which fires every
        // process_product_meta handler, and a "save my field, or delete it when
        // absent" handler for a section the merchant did not pick would wipe its
        // data if that field were missing from the post. Forwarding it hidden
        // makes the save round-trip it unchanged. So the picker controls what is
        // shown; nothing a plugin stores is ever silently dropped.
        foreach ($core_sub_hooks as $label => $hooks) {
            $key = 'core:' . sanitize_key($label);

            $section = '';
            foreach ($hooks as $hook) {
                if (!has_action($hook)) continue;
                // Buffer-safe fire (see capture_isolated_hook): never let a
                // misbehaving 3rd-party field render abort the editor or leak an
                // output buffer that corrupts the page. WooCommerce's own
                // fields are left out (capture_core_subhook).
                $html = self::capture_core_subhook($hook);
                if ($html !== '') $section .= $html;
            }
            if ($section !== '') {
                $is_picked = in_array($key, $selected, true);
                if ($is_picked) {
                    $any_visible = true;
                }
                // `data-bp-unpicked` marks a section that is on the page only so
                // its values round-trip: the editor JS neither counts its events
                // as edits nor lets its copy of a field win over a shown one.
                // A folded one (`data-bpe-folded`) is shown once its box opens.
                if ($is_picked) {
                    $hidden_attr = '';
                } elseif ($fold) {
                    $hidden_attr = ' data-bpe-folded="1"' . ($fold_keep ? ' data-bp-unpicked="1"' : '');
                    $fold_seen++;
                } else {
                    $hidden_attr = ' style="display:none" aria-hidden="true" data-bp-unpicked="1"';
                }
                $group_html = '<div class="brikpanel-pe-wc-tab-group" data-tab="' . esc_attr($label) . '"' . $hidden_attr . '>'
                    . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html($label) . '</h4>'
                    . $section
                    . '</div>';
                if (!$is_picked && $fold) {
                    $fold_out .= $group_html;
                } else {
                    $output .= $group_html;
                }
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
        // Panels gated to a product type this editor cannot represent (Product
        // Bundles, Composite…): never put on the page unpicked, see below.
        $unrendered     = self::unrendered_type_panels($product);
        $seen_panel_ids = [];
        $empty_panels   = [];
        if (has_action('woocommerce_product_data_panels')) {
            // Buffer-safe, per-callback fire — see capture_hook_chunks(). Each
            // plugin's output is parsed on its own so a panel that dies mid-tag
            // cannot swallow the panels rendered after it.
            //
            // One panel id is rendered once. A plugin that registers its panel
            // callback lazily from inside the `woocommerce_product_data_tabs`
            // filter gets registered again every time that filter runs, and on a
            // store where collect_custom_tab_meta() has to fall back to its
            // per-callback pass the filter runs twice. Two identical panels mean
            // two sets of inputs with the same names, so keep the first.
            foreach (self::render_hook_capture('woocommerce_product_data_panels', true) as $panels_html) {
                $target_to_label = $tab_meta['labels'];
                $target_to_key   = $tab_meta['keys'];

                // Shield <script> blocks (inline init + JS templates) so libxml
                // does not corrupt or drop them; restored on the output below.
                $script_store = [];
                $panels_html  = self::shield_scripts($panels_html, $script_store);

                $dom = new \DOMDocument();
                $loaded = self::load_panels_dom($dom, $panels_html);

                if ($loaded) {
                    $wrap = $dom->getElementById('bpe-wrap');
                    if ($wrap) {
                        $skip_ids = ['marketplace_suggestions'];
                        foreach (iterator_to_array($wrap->childNodes) as $node) {
                            if (!($node instanceof \DOMElement)) continue;
                            if (!in_array(strtolower($node->tagName), ['div', 'section'], true)) continue;

                            $id = $node->getAttribute('id');
                            if ($id === '' || in_array($id, $core_targets, true) || in_array($id, $skip_ids, true)) continue;
                            if (isset($seen_panel_ids[$id])) continue;

                            // Every panel goes on the page, the ones the merchant did
                            // not pick hidden. This card carries WooCommerce's save
                            // nonce, and plugins that verify it treat a field missing
                            // from the post as cleared: Blocksy deleted a product's
                            // swatches when only its "Thank you Page" section was
                            // picked, and the thank-you page when only "Swatches"
                            // was. A hidden copy carries the stored values through
                            // the save unchanged, the way WooCommerce's own form
                            // carries every tab it is not showing.
                            //
                            // Panels gated to a product type this editor cannot
                            // represent stay out: unrendered_type_panels() keeps
                            // those plugins off the save hooks instead.
                            $tab_key   = 'tab:' . $id;
                            $is_picked = in_array($tab_key, $selected, true);
                            if (!$is_picked && in_array($id, $unrendered, true)) {
                                $fold_found = $fold_found || $fold;
                                continue;
                            }

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

                            // A picked panel with no named field the merchant could
                            // fill posts nothing (Easy Product Bundles' box is an
                            // empty div its script fills on WooCommerce's screen
                            // only). Remembered for the "settings live in
                            // WooCommerce" note; the save learns the same from the
                            // editor script (bp_filled_panels).
                            if ($is_picked && !self::panel_has_named_field($node)) {
                                $empty_panels[$id] = true;
                            }

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
                            $seen_panel_ids[$id] = true;

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

                            if (!$is_picked && $fold) {
                                // Folded: shown when its box opens, for the
                                // product types its tab is for (as below).
                                $gate        = self::wc_tab_gate_classes($tab_meta['classes'][$id] ?? []);
                                $group_attrs = ' data-bpe-folded="1"' . ($fold_keep ? ' data-bp-unpicked="1"' : '')
                                    . ($gate ? ' data-wc-tab-class="' . esc_attr(implode(' ', $gate)) . '"' : '');
                                if ($gate && !self::wc_tab_shown_for($gate, $visibility)) {
                                    $group_attrs .= ' hidden';
                                } else {
                                    $fold_seen++;
                                }
                            } elseif (!$is_picked) {
                                $group_attrs = ' style="display:none" aria-hidden="true" data-bp-unpicked="1"';
                            } else {
                                // WooCommerce shows a plugin's tab only for the
                                // product types its `show_if_*` / `hide_if_*`
                                // classes allow (Blocksy's Swatches: variable
                                // products only). The section follows the same
                                // rule; the editor JS re-applies it when the type
                                // changes (initThirdPartyTabTypes).
                                $gate        = self::wc_tab_gate_classes($tab_meta['classes'][$id] ?? []);
                                $group_attrs = $gate ? ' data-wc-tab-class="' . esc_attr(implode(' ', $gate)) . '"' : '';
                                if ($gate && !self::wc_tab_shown_for($gate, $visibility)) {
                                    $group_attrs .= ' hidden';
                                } else {
                                    $any_visible = true;
                                }
                            }

                            $group_html = '<div class="brikpanel-pe-wc-tab-group" data-tab="' . esc_attr($label) . '"' . $group_attrs . '>'
                                . '<h4 class="brikpanel-pe-wc-tab-title">' . esc_html($label) . '</h4>'
                                . $tab_nav
                                . $panel_html
                                . '</div>';
                            if (!$is_picked && $fold) {
                                $fold_out .= $group_html;
                            } else {
                                $output .= $group_html;
                            }
                        }
                    }
                }
            }
        }

        self::$capture_info = [
            'any_visible'  => $any_visible,
            'panels'       => array_keys($seen_panel_ids),
            'empty_panels' => array_keys($empty_panels),
            'fold_html'    => $fold_out,
            'fold_visible' => $fold_seen,
            // Without the box nothing would be on the page: the editor script
            // holds the card back until the box is opened.
            'fold_hold'    => $fold_shut && empty($selected) && $fold_out !== '',
            'fold_found'   => $fold_found || $fold_out !== '',
        ];

        // Restore globals
        $post           = $orig_post;
        $thepostid      = $orig_postid;
        $product_object = $orig_prodobj;
        self::$render_product   = null;
        self::$render_hook_memo = [];

        return $output;
    }

    /**
     * Parse captured panel markup into $dom, inside a `#bpe-wrap` div. Other
     * plugins' panels are seldom valid HTML (an id printed twice, a bare "&"),
     * and libxml reports every slip as a PHP warning; reading them from its own
     * error list instead keeps a store's error log free of another plugin's
     * markup. The parse itself is the same.
     *
     * @param \DOMDocument $dom  Empty document.
     * @param string       $html Captured panels.
     * @return bool Whether it parsed.
     */
    private static function load_panels_dom(\DOMDocument $dom, $html) {
        $prev = libxml_use_internal_errors(true);
        try {
            return $dom->loadHTML('<?xml encoding="UTF-8"><div id="bpe-wrap">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * The `show_if_*` / `hide_if_*` classes of a product-data tab: the part of
     * its class list WooCommerce reads to show or hide it per product type.
     *
     * @param mixed $classes Tab classes as harvested by collect_custom_tab_meta().
     * @return string[]
     */
    private static function wc_tab_gate_classes($classes) {
        $gate = [];
        foreach ((array) $classes as $class) {
            $class = sanitize_html_class((string) $class);
            if (strpos($class, 'show_if_') === 0 || strpos($class, 'hide_if_') === 0) {
                $gate[] = $class;
            }
        }
        return array_values(array_unique($gate));
    }

    /**
     * The product type and flags WooCommerce's tab rules read, for the product
     * as stored. Virtual and downloadable only count for types that carry them
     * on the parent: WooCommerce clears both on variable, grouped and external
     * products, and this editor's switches mean "every variation" there.
     *
     * @param WC_Product|null $product Product being rendered.
     * @return array{type: string, virtual: bool, downloadable: bool}
     */
    private static function wc_tab_visibility_context($product) {
        $type = ($product instanceof WC_Product) ? (string) $product->get_type() : '';
        if ($type === '') {
            $type = 'simple';
        }
        $flags = !in_array($type, ['variable', 'grouped', 'external'], true)
            && !(function_exists('brikpanel_is_variable_product_type') && brikpanel_is_variable_product_type($type));
        $downloadable = $flags && $product instanceof WC_Product && $product->is_downloadable();
        // The editor's Digital switch turns Virtual on with it, so it counts as both.
        $virtual = $flags && $product instanceof WC_Product && ($product->is_virtual() || $downloadable);
        return ['type' => $type, 'virtual' => $virtual, 'downloadable' => $downloadable];
    }

    /**
     * WooCommerce's show_and_hide_panels() rule for one tab: a tab with
     * `show_if_*` classes shows only when one of them matches the product type
     * (or virtual / downloadable), and any matching `hide_if_*` class hides it.
     * The editor JS applies the same rule live (initThirdPartyTabTypes); keep
     * the two in step.
     *
     * @param string[] $gate    Classes from wc_tab_gate_classes().
     * @param array    $context From wc_tab_visibility_context().
     * @return bool
     */
    private static function wc_tab_shown_for(array $gate, array $context) {
        $type    = (string) $context['type'];
        $virtual = !empty($context['virtual']);
        $digital = !empty($context['downloadable']);
        $shows   = array_filter($gate, static function ($c) { return strpos($c, 'show_if_') === 0; });
        $shown   = true;
        if ($shows) {
            $shown = in_array('show_if_' . $type, $shows, true)
                || ($virtual && in_array('show_if_virtual', $shows, true))
                || ($digital && in_array('show_if_downloadable', $shows, true));
        }
        if (in_array('hide_if_' . $type, $gate, true)
            || ($virtual && in_array('hide_if_virtual', $gate, true))
            || ($digital && in_array('hide_if_downloadable', $gate, true))) {
            $shown = false;
        }
        return $shown;
    }

    /**
     * The options other plugins put next to "Virtual" and "Downloadable" in
     * WooCommerce's own product box, through the `product_type_options`
     * filter: Germanized's "Service", YITH's and WP Swings' "Subscription",
     * Tutor LMS's "For Tutor", Bookings' "Has persons"… WooCommerce prints
     * each one as a checkbox named after its `id`, and its plugin saves it
     * from its own save handler: present means on, absent means off.
     *
     * Virtual and Downloadable are left out, this editor has its own switches
     * for them. An id must look like the meta keys WooCommerce reads (an
     * underscore, then letters, digits, `_` or `-`): it becomes an HTML id, a
     * field name and a `$_POST` key, and none of this editor's own fields
     * starts with an underscore, so an option can never stand in for one.
     *
     * The filter runs in the product context WooCommerce gives it ($post,
     * $thepostid, $product_object, as in capture_wc_product_data_fields()):
     * Product Bundles reads them in its callback. One combined fire is the
     * normal path; when a callback throws, the filter runs again one callback
     * at a time and the others are kept (same rule as collect_custom_tab_meta()).
     *
     * @param WC_Product|null $product Product being edited, null for none yet.
     * @return array<string, array{id: string, key: string, label: string, help: string, gate: string[], default: string}> Keyed by id, in filter order.
     */
    private static function plugin_type_options($product) {
        $memo_key = ($product instanceof WC_Product) ? (int) $product->get_id() : 0;
        if (isset(self::$plugin_type_options_memo[$memo_key])) {
            return self::$plugin_type_options_memo[$memo_key];
        }

        $defaults = function_exists('wc_get_default_product_type_options')
            ? (array) wc_get_default_product_type_options()
            : ['virtual' => ['id' => '_virtual'], 'downloadable' => ['id' => '_downloadable']];

        global $post, $thepostid, $product_object;
        $orig_post    = $post;
        $orig_postid  = $thepostid ?? null;
        $orig_prodobj = $product_object ?? null;
        if ($memo_key) {
            $post           = get_post($memo_key);
            $thepostid      = $memo_key;
            $product_object = $product;
        }
        try {
            $raw = apply_filters('product_type_options', $defaults);
        } catch (\Throwable $e) {
            $raw = [];
            foreach (self::filter_chunks('product_type_options', $defaults)['results'] as $chunk) {
                if (is_array($chunk)) {
                    $raw = array_replace($raw, $chunk);
                }
            }
        } finally {
            $post           = $orig_post;
            $thepostid      = $orig_postid;
            $product_object = $orig_prodobj;
        }

        $options = [];
        foreach (is_array($raw) ? $raw : [] as $key => $opt) {
            $key = (string) $key;
            if ($key === 'virtual' || $key === 'downloadable' || !is_array($opt)
                || !isset($opt['id']) || !is_string($opt['id'])) {
                continue;
            }
            $id = $opt['id'];
            if (isset($options[$id]) || $id === '_virtual' || $id === '_downloadable'
                || !preg_match('/^_[A-Za-z0-9_-]{1,100}$/', $id)) {
                continue;
            }
            $label = isset($opt['label']) && is_scalar($opt['label']) ? trim(wp_strip_all_tags((string) $opt['label'])) : '';
            if ($label === '') {
                $label = ucfirst(trim(str_replace(['_', '-'], ' ', $key)));
            }
            $wrapper = isset($opt['wrapper_class']) && is_scalar($opt['wrapper_class']) ? trim((string) $opt['wrapper_class']) : '';
            $options[$id] = [
                'id'      => $id,
                'key'     => $key,
                'label'   => $label,
                'help'    => isset($opt['description']) && is_scalar($opt['description']) ? trim(wp_strip_all_tags((string) $opt['description'])) : '',
                // WooCommerce shows or hides the box by these classes, like a tab.
                'gate'    => self::wc_tab_gate_classes($wrapper === '' ? [] : (preg_split('/\s+/', $wrapper) ?: [])),
                'default' => isset($opt['default']) && is_scalar($opt['default']) ? (string) $opt['default'] : 'no',
            ];
        }

        return self::$plugin_type_options_memo[$memo_key] = $options;
    }

    /**
     * Whether a plugin_type_options() option is on for a product, read the way
     * WooCommerce's product box reads it (html-product-data-panel.php): while
     * the product has a `_{key}` meta, its `is_{key}()` getter if it has one,
     * else that meta being "yes"; before that, the option's default.
     *
     * @param WC_Product|null $product Product, null for one not saved yet.
     * @param string          $key     The option's key in the filter.
     * @param array           $opt     The option, from plugin_type_options().
     * @return bool
     */
    private static function plugin_type_option_on($product, $key, array $opt) {
        $id = ($product instanceof WC_Product) ? (int) $product->get_id() : 0;
        if ($id && metadata_exists('post', $id, '_' . $key)) {
            if (is_callable([$product, 'is_' . $key])) {
                try {
                    return (bool) $product->{'is_' . $key}();
                } catch (\Throwable $e) {
                    // A getter that wants arguments: read the meta like any other.
                }
            }
            return 'yes' === get_post_meta($id, '_' . $key, true);
        }
        return 'yes' === ($opt['default'] ?? 'no');
    }

    /**
     * Keeps the options other plugins add next to Virtual and Downloadable
     * that this save's form did not show.
     *
     * Their plugins read them like WooCommerce's own form posts a checkbox:
     * present means on, absent means off (Germanized and YITH Subscription
     * write "no", Tutor LMS deletes its flag). This editor runs those save
     * handlers on every save, so an option it did not put on the form (the
     * setting is off, the "Digital product" section is hidden, the page was
     * opened before the option existed) was switched off by any save, even an
     * untouched one: on 3.3.37 one plain save turned off 8 of 10 such flags,
     * on a simple and on a variable product.
     *
     * `bpe_plugin_opts` lists the ones the form showed, which arrive the way
     * the merchant left them. Every other one that is on is posted as on, here,
     * before the first post write: its handler may run from save_post as well
     * as from WooCommerce's hooks. Only keys this request does not carry are
     * written, and only ids plugin_type_options() accepted.
     *
     * @param int $product_id Product being saved, 0 for a new one.
     * @return void
     */
    private function keep_unshown_plugin_type_options($product_id) {
        $this->plugin_opts_sent = [];
        $product = $product_id ? wc_get_product($product_id) : null;
        $product = $product instanceof WC_Product ? $product : null;
        $options = self::plugin_type_options($product);
        if (empty($options)) {
            return;
        }

        $shown = [];
        if (isset($_POST['bpe_plugin_opts']) && is_string($_POST['bpe_plugin_opts'])) {
            foreach (explode(',', sanitize_text_field(wp_unslash($_POST['bpe_plugin_opts']))) as $shown_id) {
                $shown_id = trim($shown_id);
                if (isset($options[$shown_id])) {
                    $shown[$shown_id] = true;
                }
            }
        }

        foreach ($options as $id => $opt) {
            if (isset($shown[$id])) {
                $this->plugin_opts_sent[$id] = array_key_exists($id, $_POST);
                continue;
            }
            if (!array_key_exists($id, $_POST) && self::plugin_type_option_on($product, $opt['key'], $opt)) {
                $_POST[$id]    = 'on';
                $_REQUEST[$id] = 'on';
            }
        }
    }

    /**
     * After a save: the options the form showed, as stored now, plus a warning
     * for each one whose plugin did not take the merchant's change.
     *
     * A plugin can refuse a save that does not come from its own screen (WP
     * Swings Subscriptions saves "Subscription" only with a nonce its own panel
     * prints). The switch would then show a value the product does not have,
     * so the editor gets the stored values back and the merchant is told
     * which option did not change. Only options on screen for the saved
     * product type count: one its own rule hides (a simple-only option on a
     * product just made variable) may be reset by its plugin on purpose.
     *
     * @param int $saved_id Saved product id.
     * @return array<string, bool> id => on as stored, for the editor.
     */
    private function plugin_type_options_after_save($saved_id) {
        if (empty($this->plugin_opts_sent)) {
            return [];
        }
        wp_cache_delete($saved_id, 'post_meta');
        $product = wc_get_product($saved_id);
        if (!$product) {
            return [];
        }
        $options = self::plugin_type_options($product);
        $context = self::wc_tab_visibility_context($product);
        $stored  = [];
        foreach ($this->plugin_opts_sent as $id => $sent) {
            if (!isset($options[$id])) {
                continue;
            }
            $opt         = $options[$id];
            $on          = self::plugin_type_option_on($product, $opt['key'], $opt);
            $stored[$id] = $on;
            if ($on !== $sent && (!$opt['gate'] || self::wc_tab_shown_for($opt['gate'], $context))) {
                $this->save_warnings[] = sprintf(
                    /* translators: %s: name of an option another plugin adds next to "Virtual", e.g. "Subscription" */
                    __('"%s" was not saved: the plugin that adds this option did not accept the change here. You can change it in the WooCommerce product editor.', 'brikpanel'),
                    $opt['label']
                );
            }
        }
        return $stored;
    }

    /**
     * Blocksy's swatches editor (Blocksy Companion Pro) lists a product's
     * attributes by reading WooCommerce's own Attributes panel out of the page:
     * every `[name*=attribute_values]` control inside a
     * `.woocommerce_attribute_data` box whose "Used for variations" box is
     * ticked, its label in `.attribute_name` and its taxonomy on the nearest
     * `[data-taxonomy]`. This editor replaces that panel with its own attribute
     * editor, so Blocksy found nothing and offered no swatches at all.
     *
     * These are hidden rows carrying just that much, for the attributes that
     * drive variations. A global attribute lists its terms by id (Blocksy keys
     * each swatch by term id) in a multiple select with every option selected,
     * because Blocksy drops the swatches of unselected terms on the next edit.
     * A custom attribute gets a text box with its name and WooCommerce's
     * `a | b` value list. Nothing else of WooCommerce's markup is copied, so its
     * own attribute scripts never act on these rows.
     *
     * @param WC_Product|null $product Product being edited.
     * @return string Rows markup, empty when no attribute drives variations.
     */
    private static function native_attribute_mirror_rows($product) {
        if (!$product instanceof WC_Product) {
            return '';
        }
        $rows = '';
        $i    = 0;
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_variation()) {
                continue;
            }
            if ($attribute->is_taxonomy()) {
                $taxonomy = $attribute->get_name();
                if (!taxonomy_exists($taxonomy)) {
                    continue;
                }
                $options = '';
                foreach ((array) $attribute->get_terms() as $term) {
                    if (!$term instanceof WP_Term) {
                        continue;
                    }
                    $options .= '<option value="' . esc_attr((string) $term->term_id) . '" selected>'
                        . esc_html(apply_filters('woocommerce_product_attribute_term_name', $term->name, $term))
                        . '</option>';
                }
                $name_cell  = '<strong>' . esc_html(wc_attribute_label($taxonomy)) . '</strong>';
                $value_cell = '<select multiple name="bpe_native_attribute_values[' . $i . '][]">' . $options . '</select>';
            } else {
                $taxonomy   = '';
                $name_cell  = '<input type="text" value="' . esc_attr($attribute->get_name()) . '">';
                $value_cell = '<textarea name="bpe_native_attribute_values[' . $i . ']">'
                    . esc_textarea(wc_implode_text_attributes($attribute->get_options())) . '</textarea>';
            }
            $rows .= '<div data-taxonomy="' . esc_attr($taxonomy) . '"><div class="woocommerce_attribute_data"><table><tbody><tr>'
                . '<td class="attribute_name">' . $name_cell . '</td><td>' . $value_cell . '</td>'
                . '</tr></tbody></table><input type="checkbox" class="woocommerce_attribute_used_for_variations" checked></div></div>';
            $i++;
        }
        return $rows;
    }

    /**
     * The hidden attribute rows for Blocksy's swatches editor in their box, with
     * a hash of the rows so the editor can tell after a save whether they changed.
     * Printed outside every container the save collects, so it is never posted.
     *
     * @param WC_Product|null $product Product being edited.
     * @return string
     */
    private static function native_attribute_mirror($product) {
        $rows = self::native_attribute_mirror_rows($product);
        return '<div id="bpe-native-attrs" class="brikpanel-pe-native-attrs" hidden aria-hidden="true" data-hash="'
            . esc_attr(md5($rows)) . '">' . $rows . '</div>';
    }

    // =========================================================================
    // DATA HELPERS
    // =========================================================================

    /**
     * Prepare stored description HTML for the rich-text editable.
     *
     * Descriptions written before BrikPanel (classic editor, WP-CLI, importers)
     * are stored as plain text whose paragraphs are only blank lines: WordPress
     * turns those into <p> at render time through wpautop(). A contenteditable
     * has no such render step — it collapses the newlines, so every paragraph
     * runs together on one line and the browser then emits <div> wrappers when
     * the user presses Enter to fix it.
     *
     * Promoting those blank lines to real paragraphs up front is exactly what
     * the classic TinyMCE editor does, so the editable shows what the shop
     * shows and Enter splits a real block instead of inventing one.
     *
     * @param string $html Stored description.
     * @return string Editor-ready HTML.
     */
    private static function editor_display_html($html) {
        $html = (string) $html;

        // No newline means the value is already a single HTML run (BrikPanel
        // output) — nothing for wpautop to do, so skip the work.
        if ($html === '' || strpos($html, "\n") === false) {
            return $html;
        }

        // Block-editor content already states its own paragraphs; wpautop()
        // would wrap the `<!-- wp:… -->` delimiters themselves and leave empty
        // paragraphs behind on the storefront. Hand it through untouched.
        if (function_exists('has_blocks') && has_blocks($html)) {
            return $html;
        }

        return wpautop($html);
    }

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
            'status'            => brikpanel_user_can_publish_products() ? 'publish' : 'pending',
            'is_variable'       => false,
            'variation_count'   => 0,
            'product_type'      => 'simple',
            'attributes'        => [],
            'non_variation_attributes' => [],
            'variations'        => [],
            'gallery'           => [],
            'seo_title'         => '',
            'seo_description'   => '',
            'is_downloadable'   => false,
            // A new product with no draft row behind it (its creation failed):
            // same start as one that has a draft, see handle_redirects().
            'is_virtual'        => $this->new_product_starts_virtual(),
            'downloads'         => [],
            'tags'              => [],
            'stock_status'      => 'instock',
            'backorders'        => 'no',
            'manage_stock'      => false,
            'shared_stock'      => false,
            'pack_sizes'        => false,
            'cogs_value'        => '',
            'sale_from'         => '',
            'sale_to'           => '',
            'seo_focus_kw'      => '',
            'seo_canonical'     => '',
            'seo_noindex'       => false,
            'primary_cat'       => '',
            'post_password'     => '',
            // Scheduled publish date/time, formatted for a datetime-local input
            // ("Y-m-d\TH:i"). Empty for new products — the picker defaults to a
            // near-future moment client-side when the merchant chooses "Scheduled".
            'post_date'         => '',
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
            // Grouped products: the products in the group ({id, text}).
            // External products: the link and its button text.
            'children'          => [],
            'product_url'       => '',
            'button_text'       => '',
        ];

        if (!$product) {
            return $defaults;
        }

        // Gallery data. Product videos travel separately (js_data.videos), in
        // the shape of whichever theme or plugin plays them.
        $gallery = [];
        $image_id = $product->get_image_id();
        // Alt text travels with each image: the merchant edits it here (it is
        // the image's own text, saved by save_image_alts()), and the SEO
        // analysers assess the featured image the way they do on the native
        // editor, where they read it out of the featured-image metabox we do
        // not render. `editable`: whether this person may change that image,
        // the same rule as WordPress's own media window.
        // `url_m`: the medium size, for the phone layout, which draws the
        // first image (the featured one) as a large tile.
        if ($image_id) {
            $gallery[] = [
                'id'       => (int) $image_id,
                'url'      => wp_get_attachment_image_url($image_id, 'thumbnail'),
                'url_m'    => wp_get_attachment_image_url($image_id, 'medium'),
                'alt'      => (string) get_post_meta((int) $image_id, '_wp_attachment_image_alt', true),
                'editable' => current_user_can('edit_post', (int) $image_id),
            ];
        }
        foreach ($product->get_gallery_image_ids() as $gid) {
            $gallery[] = [
                'id'       => (int) $gid,
                'url'      => wp_get_attachment_image_url($gid, 'thumbnail'),
                'url_m'    => wp_get_attachment_image_url($gid, 'medium'),
                'alt'      => (string) get_post_meta((int) $gid, '_wp_attachment_image_alt', true),
                'editable' => current_user_can('edit_post', (int) $gid),
            ];
        }

        // Attributes + variations for variable products
        $attributes_data           = [];
        $non_variation_attributes  = [];
        $variations_data           = [];
        $is_variable               = $product->is_type('variable');
        // Whether any variation leaves its stock to the product (WooCommerce's
        // shared, product-level stock). Read below with the variations.
        $any_follows_parent        = false;

        // Build a normalized {name, values, taxonomy, visible} record for any
        // WC_Product_Attribute, resolving taxonomy term slugs/ids to display
        // names so the editor renders consistently for custom and global
        // attributes.
        $build_attr_record = function ($attr) use ($product) {
            $attr_name = $attr->get_name();
            $is_tax = $attr->is_taxonomy() || (is_string($attr_name) && strpos($attr_name, 'pa_') === 0 && taxonomy_exists($attr_name));

            if ($is_tax) {
                $taxonomy = $attr_name;
                $values = [];
                // Term names go to the browser through brikpanel_term_ref()
                // ("Black &amp; White" shows as "Black & White"). The JS matches
                // values, defaults, suggestions and existing variations by
                // name, so every producer (here, the default and variation
                // rows below, build_global_attributes_payload()) must use the
                // same form, or saves drop defaults and duplicate variations.
                $assigned_terms = wp_get_post_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
                if (!is_wp_error($assigned_terms) && !empty($assigned_terms)) {
                    foreach ($assigned_terms as $term) {
                        $values[] = brikpanel_term_ref($term->name);
                    }
                } else {
                    foreach ((array) $attr->get_options() as $opt) {
                        $term = is_numeric($opt) ? get_term((int) $opt, $taxonomy) : get_term_by('slug', $opt, $taxonomy);
                        $values[] = ($term && !is_wp_error($term)) ? brikpanel_term_ref($term->name) : $opt;
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
                // The key WooCommerce stores this attribute under, which is the
                // key every variation below uses. The editor keys its rows with
                // it instead of deriving one: its own slug comes out empty for a
                // name with no Latin letters ("Размер"), which dropped the
                // attribute on save, and differs from WooCommerce's for a
                // non-ASCII taxonomy (`pa_вид` is keyed `pa_%d0%b2%d0%b8%d0%b4`).
                'key'      => brikpanel_attr_key($attr_name),
                // WooCommerce's own "Visible on the product page" flag: it
                // decides whether the attribute shows up in the storefront's
                // Additional information table. The editor used to ignore it
                // entirely and force it back on with every save, so a merchant
                // who had hidden an attribute in the WooCommerce screen silently
                // lost that choice. Carry it to the client so the per-row switch
                // reflects the stored value.
                'visible'  => (bool) $attr->get_visible(),
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
            // dedicated "Product attributes" card above. Each record also
            // carries its current "Default Form Values" selection (WC's
            // _default_attributes) resolved to a display NAME so the editor's
            // dropdown can pre-select it — taxonomy defaults are stored as term
            // slugs, so translate slug -> name here to match the UI's names.
            $default_attributes = $product->get_default_attributes('edit');
            foreach ($product->get_attributes() as $attr) {
                if (!$attr->get_variation()) continue;
                $record = $build_attr_record($attr);
                $attr_name = $attr->get_name();
                $is_tax = $attr->is_taxonomy() || (is_string($attr_name) && strpos($attr_name, 'pa_') === 0 && taxonomy_exists($attr_name));
                // WC keys default_attributes by sanitize_title() of the
                // attribute's name, taxonomy or label alike. Releases up to
                // 3.3.34 wrote a taxonomy's default under the raw taxonomy name,
                // which differs for a non-ASCII one, so that spelling is read too.
                $def_key = $record['key'];
                if (isset($default_attributes[$def_key])) {
                    $def_raw = $default_attributes[$def_key];
                } elseif ($is_tax && isset($default_attributes[$attr_name])) {
                    $def_raw = $default_attributes[$attr_name];
                } else {
                    $def_raw = '';
                }
                $def_name = '';
                if ($def_raw !== '') {
                    if ($is_tax) {
                        $def_term = get_term_by('slug', $def_raw, $attr_name);
                        $def_name = ($def_term && !is_wp_error($def_term)) ? brikpanel_term_ref($def_term->name) : $def_raw;
                    } else {
                        $def_name = $def_raw;
                    }
                }
                $record['default'] = $def_name;
                $attributes_data[] = $record;
            }

            foreach (brikpanel_variation_ids($product) as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) continue;

                // WC stores taxonomy variation attributes as term slugs, but
                // the editor compares against term NAMES (what the tag group
                // displays). Translate slug → name for taxonomy keys so the
                // variation table row matches the selected attribute value.
                //
                // Read the stored meta rather than $variation->get_attributes():
                // that accessor drops any value whose parent axis lost its
                // "used for variations" flag or its option list, so a product
                // rewritten by an importer or ERP reports no attributes at all
                // while the values are still on disk. The editor showed blank
                // rows, and saving that emptiness back deleted them for good.
                // The key of a non-ASCII taxonomy is its encoded name, so the
                // taxonomy is looked up through brikpanel_attr_taxonomy_from_key();
                // the editor showed such values as "%d0%ba…" slugs before.
                $var_attrs = [];
                foreach ($this->read_variation_attribute_meta($variation->get_id()) as $key => $val) {
                    $val_tax = $val !== '' ? brikpanel_attr_taxonomy_from_key($key) : '';
                    if ($val_tax !== '') {
                        $term = get_term_by('slug', $val, $val_tax);
                        $var_attrs[$key] = $term ? brikpanel_term_ref($term->name) : $val;
                    } else {
                        $var_attrs[$key] = $val;
                    }
                }

                // Variation images: thumbnail + custom gallery.
                // Read the variation's OWN image in 'edit' context: WC_Product_Variation
                // ::get_image_id() falls back to the PARENT product's featured image in
                // the default 'view' context when the variation has none. Without 'edit',
                // clearing a variation image (own id -> 0) would silently show the parent
                // image again on reload, so the "Remove image" control looked like it
                // never saved. Same reasoning as the shipping-class read below.
                // Each image carries its alt text and whether this person may
                // change it, as the product's own images do (see $gallery).
                $var_images = [];
                $var_image_id = $variation->get_image_id('edit');
                if ($var_image_id) {
                    $var_images[] = [
                        'id'       => (int) $var_image_id,
                        'url'      => wp_get_attachment_image_url($var_image_id, 'thumbnail'),
                        'alt'      => (string) get_post_meta((int) $var_image_id, '_wp_attachment_image_alt', true),
                        'editable' => current_user_can('edit_post', (int) $var_image_id),
                    ];
                }
                if (get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes') {
                    $var_gallery = get_post_meta($variation->get_id(), '_brikpanel_variation_gallery', true);
                    if (!empty($var_gallery) && is_array($var_gallery)) {
                        foreach ($var_gallery as $gid) {
                            $gid = (int) $gid;
                            if ($gid) {
                                $var_images[] = [
                                    'id'       => $gid,
                                    'url'      => wp_get_attachment_image_url($gid, 'thumbnail'),
                                    'alt'      => (string) get_post_meta($gid, '_wp_attachment_image_alt', true),
                                    'editable' => current_user_can('edit_post', $gid),
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

                // Stock, backorders and SKU are read in 'edit' context, the
                // variation's OWN values. In 'view' context a variation that
                // leaves its stock to the product (WooCommerce's shared,
                // product-level stock) reports get_manage_stock() === 'parent'
                // plus the product's quantity, backorder rule and SKU. The
                // editor took those for the variation's own, showed the shared
                // 40 in every row and saved it back into each of them (7 × 40),
                // and posted the product's SKU into every row, which WooCommerce
                // rejects as a duplicate on each save. Whether such a row counts
                // nothing (Unlimited) or follows the shared stock is decided by
                // the product (shared_stock below), exactly as in WooCommerce.
                $var_manage = (true === $variation->get_manage_stock('edit'));
                if (!$var_manage) {
                    $any_follows_parent = true;
                }

                $variations_data[] = [
                    'id'             => $variation->get_id(),
                    // WC stores the "Active/Enabled" state as the variation's
                    // post_status: 'publish' = active (purchasable, shown in
                    // store), anything else ('private'/'draft') = disabled. We
                    // mirror WC's own checkbox, which is checked only for
                    // 'publish', so a variation left inactive by an import or a
                    // third-party plugin shows up here honestly as "off".
                    'enabled'        => ($variation->get_status() === 'publish'),
                    'attributes'     => $var_attrs,
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    // Only a counted row has a quantity of its own. A row that
                    // follows the product keeps an old _stock value WooCommerce
                    // never clears; sending it would bring that stale number
                    // back the moment the row is given its own count again.
                    'stock_quantity' => $var_manage ? $variation->get_stock_quantity('edit') : null,
                    // Per-variation stock management flag: true only when the
                    // variation counts its own stock.
                    'manage_stock'   => $var_manage,
                    'sku'            => (string) $variation->get_sku('edit'),
                    'global_unique_id' => brikpanel_wc_gtin($variation),
                    'tax_class'      => (string) $variation->get_tax_class('edit'),
                    'shipping_class' => $var_ship_class,
                    'images'         => $var_images,
                    'cogs_value'     => brikpanel_product_cogs_raw($variation->get_id()),
                    'stock_status'   => $variation->get_stock_status() ?: 'instock',
                    // WC stores three backorder values: no / yes / notify.
                    // Pass the raw value so the editor can roundtrip it
                    // when the "Notify customer" sub-control is enabled.
                    'backorders'     => $variation->get_backorders('edit') ?: 'no',
                    'sale_from'      => $variation->get_date_on_sale_from() ? $variation->get_date_on_sale_from()->date('Y-m-d') : '',
                    'sale_to'        => $variation->get_date_on_sale_to()   ? $variation->get_date_on_sale_to()->date('Y-m-d')   : '',
                    // Per-variation vendor override. 0 means "inherit from
                    // parent" — the JS renders that as a "(parent)" option.
                    'vendor_id'      => (int) get_post_meta($variation->get_id(), '_brikpanel_vendor_id', true),
                    'vendor_sku'     => (string) get_post_meta($variation->get_id(), '_brikpanel_vendor_sku', true),
                    // Units one sale takes from the shared stock (1 = a single
                    // unit). Stored as typed; only counted while the product's
                    // "Pack sizes" switch is on and the row follows the shared stock.
                    'pack_units'     => class_exists('Brikpanel_Pack_Sizes')
                        ? Brikpanel_Pack_Sizes::sanitize_units($variation->get_meta(Brikpanel_Pack_Sizes::UNITS_META, true))
                        : 1,
                ];
            }
        }

        // For variable products, derive the price from the variations. The
        // stock box shows the product's OWN quantity: on a variable product
        // that is the shared stock its variations sell from. It used to fall
        // back to the sum of the variations' stock, which only made sense while
        // the Inventory card stayed hidden for variable products.
        //
        // A type another plugin adds (a bundle, a composite) may work its price
        // out from its contents when it is shown. The price box shows what is
        // stored instead, as WooCommerce's own editor does: a shown total that
        // the save wrote back became the stored price, and Bopo sells a bundle
        // with a stored price at that fixed price.
        $price_context = self::is_native_editor_type((string) $product->get_type()) ? 'view' : 'edit';
        $regular_price = $product->get_regular_price($price_context);
        $sale_price    = $product->get_sale_price($price_context);
        $stock_qty     = $product->get_stock_quantity('edit');

        if ($is_variable && $regular_price === '') {
            $prices = $product->get_variation_prices(true);
            if (!empty($prices['regular_price'])) {
                $regular_price = min($prices['regular_price']);
            }
        }

        // WooCommerce's shared stock: the product counts stock and its
        // variations sell from that one quantity. On when the product tracks
        // stock and either has no variations yet or at least one of them
        // (published or switched off) leaves its stock to the product. A
        // product that tracks stock while every variation counts its own is a
        // leftover (the 3.2.17 trap) and shows as off; saving clears it.
        $shared_stock = $is_variable
            && true === $product->get_manage_stock('edit')
            && (empty($variations_data) || $any_follows_parent);

        // Virtual / Digital switches and the downloadable files. A variable
        // product keeps them on its variations: WooCommerce never counts the
        // parent as virtual or downloadable, so the switches show what every
        // variation has and the one file list shows the files they all share.
        // When their files differ the list opens empty, and the save leaves
        // each variation's own files in place (see save_variations()). Without
        // the shared list here every save of a downloadable variable product
        // sent an empty list back and deleted all of its files.
        $variation_flags = $is_variable
            ? $this->variation_flags_summary(brikpanel_variation_ids($product))
            : null;
        if ($is_variable) {
            $file_objects = ($variation_flags['downloadable'] && null !== $variation_flags['downloads'])
                ? $variation_flags['downloads']
                : [];
        } else {
            $file_objects = $product->is_downloadable() ? $product->get_downloads() : [];
        }
        $downloads = [];
        foreach ($file_objects as $download) {
            $downloads[] = [
                'id'   => $download->get_id(),
                'name' => $download->get_name(),
                'file' => $download->get_file(),
            ];
        }

        // SEO data — read from whichever plugin has populated meta, first
        // non-empty wins. The installed plugin answers first (see the hoist
        // below); the rest stay behind it as a fallback chain so a store that
        // switched plugins still sees its old data.
        $pid = $product->get_id();
        $seo_title     = '';
        $seo_desc      = '';
        $seo_focus_kw  = '';
        $seo_canonical = '';
        $seo_noindex   = false;
        $seo_sources = [
            'yoast' => [
                'title'     => '_yoast_wpseo_title',
                'desc'      => '_yoast_wpseo_metadesc',
                'focus_kw'  => '_yoast_wpseo_focuskw',
                'canonical' => '_yoast_wpseo_canonical',
                'noindex'   => '_yoast_wpseo_meta-robots-noindex', // '1' = noindex
                'noindex_value' => '1',
            ],
            'rank_math' => [
                'title'     => 'rank_math_title',
                'desc'      => 'rank_math_description',
                'focus_kw'  => 'rank_math_focus_keyword',
                'canonical' => 'rank_math_canonical_url',
                'noindex'   => 'rank_math_robots', // array containing 'noindex'
                'noindex_is_array' => true,
            ],
            'aioseo' => [
                'title'     => '_aioseo_title',
                'desc'      => '_aioseo_description',
                'focus_kw'  => '_aioseo_keyphrases',
                'canonical' => '_aioseo_canonical_url',
                'noindex'   => '_aioseo_robots_noindex',
                'noindex_value' => '1',
            ],
            'seopress' => [
                'title'     => '_seopress_titles_title',
                'desc'      => '_seopress_titles_desc',
                'focus_kw'  => '_seopress_analysis_target_kw',
                'canonical' => '_seopress_robots_canonical',
                'noindex'   => '_seopress_robots_index', // 'yes' = noindex
                'noindex_value' => 'yes',
            ],
        ];

        // Hoist the installed plugin to the front. The chain is first-non-empty
        // wins, and it used to be Yoast-first no matter what — so on a store
        // migrated from Yoast to Rank Math the leftover `_yoast_wpseo_*` rows
        // beat the live `rank_math_*` ones, and the Google preview, the unified
        // fields and the noindex toggle all showed stale text the merchant had
        // no way to correct from here. Whoever is actually installed answers
        // first; everyone else keeps their old relative order behind them.
        $active_seo_read = self::get_active_seo_plugin();
        $active_seo_slug = $active_seo_read ? $active_seo_read['slug'] : '';
        if ($active_seo_slug === '') {
            $unified_read    = self::get_unified_seo_analyzer();
            $active_seo_slug = $unified_read ? $unified_read['slug'] : '';
        }
        // SureRank and SmartCrawl have no entry here — they own the read path
        // outright below via $unified_owns_seo — so the isset() guard skips
        // them. `+` on string keys keeps the first occurrence and drops the
        // duplicate, making this an exact reorder.
        if ($active_seo_slug !== '' && isset($seo_sources[$active_seo_slug])) {
            $seo_sources = [$active_seo_slug => $seo_sources[$active_seo_slug]] + $seo_sources;
        }
        $seo_sources = array_values($seo_sources);

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
        // …but only when no inline-metabox plugin is active. Whoever owns the
        // visible SEO surface owns the read: if Yoast / Rank Math / AIOSEO /
        // classic SEOPress is installed, the SEO card shows THAT plugin's own
        // metabox, and letting a popup-only plugin answer the read here made
        // the Google preview above the metabox contradict the metabox itself.
        $unified_may_own = ($active_seo_read === null);

        $surerank_active = $unified_may_own && defined('SURERANK_VERSION');
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
        // SEOPress has no clause here: whenever it is active it renders its
        // own panel inside the SEO card, so get_active_seo_plugin() returns it
        // and $unified_may_own is false. Its meta is still hoisted to the front
        // of $seo_sources below by $active_seo_slug.
        $smartcrawl_active = $unified_may_own && !$surerank_active && defined('SMARTCRAWL_VERSION');
        if ($smartcrawl_active) {
            $seo_title     = (string) get_post_meta($pid, '_wds_title', true);
            $seo_desc      = (string) get_post_meta($pid, '_wds_metadesc', true);
            $seo_canonical = (string) get_post_meta($pid, '_wds_canonical', true);
            $raw_kw        = (string) get_post_meta($pid, '_wds_focus-keywords', true);
            $kw_parts      = array_filter(array_map('trim', explode(',', $raw_kw)));
            $seo_focus_kw  = $kw_parts ? (string) reset($kw_parts) : '';
            $seo_noindex   = (bool) get_post_meta($pid, '_wds_meta-robots-noindex', true);
        }

        $unified_owns_seo = $surerank_active || $smartcrawl_active;

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
                    'text' => brikpanel_plain_label($lp->get_name()) . ($sku !== '' ? ' (' . $sku . ')' : ''),
                ];
            }
            return $out;
        };

        // Tags are posted back by name, so they use the same form as the
        // autocomplete list ($all_tags) and the attribute values.
        $tag_names = wp_get_object_terms($product->get_id(), 'product_tag', ['fields' => 'names']);
        $tag_names = is_wp_error($tag_names) ? [] : array_map('brikpanel_term_ref', $tag_names);

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
            'global_unique_id'  => brikpanel_wc_gtin($product),
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
            // products, or "pending" for someone who may only send products in
            // for review. The hidden input still submits a real WP status that
            // the save path accepts.
            'status'            => ($product->get_status() === 'auto-draft')
                ? (brikpanel_user_can_publish_products() ? 'publish' : 'pending')
                : $product->get_status(),
            'is_variable'       => $is_variable,
            // How many variations the product ACTUALLY has in the database, which
            // is not always how many rows the table renders: WooCommerce hides a
            // variation whose parent axis lost its "used for variations" flag or
            // its option list, so an imported product can load with children but
            // an empty table. The editor warns before a variable -> simple
            // conversion deletes them, and that warning has to count the real
            // children or it would silently say "0" on exactly the products that
            // stand to lose the most.
            'variation_count'   => count(brikpanel_variation_ids($product)),
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
            // The list is empty, and this returns '', when the only SEO plugin
            // is SEOPress drawing its own panel: it owns the control then, and
            // the Category card renders none. See primary_category_seo_plugins().
            'primary_cat' => (function () use ($pid) {
                foreach (self::primary_category_seo_plugins() as $plugin) {
                    $v = (string) get_post_meta($pid, $plugin['key'], true);
                    if ($v !== '' && $v !== '0') {
                        return $v;
                    }
                }
                return '';
            })(),
            // WC_Product_Variable reports Virtual and Downloadable as false
            // whatever is stored, so for a variable product the switches come
            // from its variations (see $variation_flags above). One with no
            // variations yet shows its own stored Virtual flag, which is where
            // "New products are virtual" leaves it: otherwise saving a variable
            // product before adding its variations would turn the switch off.
            'is_downloadable'   => $is_variable ? $variation_flags['downloadable'] : $product->is_downloadable(),
            'is_virtual'        => $is_variable
                ? (brikpanel_variation_ids($product) ? $variation_flags['virtual'] : 'yes' === get_post_meta($pid, '_virtual', true))
                : $product->is_virtual(),
            'downloads'         => $downloads,
            'gallery'           => $gallery,
            'tags'              => $tag_names,
            'stock_status'      => $product->get_stock_status() ?: 'instock',
            // Whether WC stock management is enabled at product level. The
            // editor's "Track quantity" toggle reads this; when off the
            // merchant picks an in/out/backorder status directly instead of
            // a quantity (mirrors WooCommerce's native Inventory tab).
            'manage_stock'      => (bool) $product->get_manage_stock(),
            // Variable products only: the variations sell from the product's
            // one quantity (see $shared_stock above). Drives the "Shared stock"
            // switch and the "Shared" word in the variation rows.
            'shared_stock'      => $shared_stock,
            // The "Pack sizes" switch under "Shared stock" (variable products).
            'pack_sizes'        => $is_variable && class_exists('Brikpanel_Pack_Sizes') && Brikpanel_Pack_Sizes::switched_on($pid),
            // Same as variations — keep the raw `no/yes/notify` so the
            // editor's optional "Notify customer" radio survives a save.
            'backorders'        => $product->get_backorders('edit') ?: 'no',
            'cogs_value'        => brikpanel_product_cogs_raw($product->get_id()),
            'sale_from'         => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : '',
            'sale_to'           => $product->get_date_on_sale_to()   ? $product->get_date_on_sale_to()->date('Y-m-d')   : '',
            'post_password'     => get_post_field('post_password', $product->get_id()),
            // Local publish date/time for the datetime-local picker. Emitted for
            // every committed product so the header's "Publish date" control can
            // show and edit the real WordPress post_date (backdate a live product,
            // correct a date, or — for a "future" product — the moment it goes
            // live). Auto-drafts are treated as new (empty) so the control reads
            // "Immediately" instead of the placeholder row's creation time.
            // get_post_field returns the site-local datetime.
            'post_date'         => (function () use ($product) {
                if ($product->get_status() === 'auto-draft') {
                    return '';
                }
                $raw = get_post_field('post_date', $product->get_id());
                $ts  = $raw ? strtotime($raw) : false;
                return $ts ? gmdate('Y-m-d\TH:i', $ts) : '';
            })(),
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
            'children'          => $product->is_type('grouped') ? $linked_pairs($product->get_children('edit')) : [],
            'product_url'       => $product->is_type('external') ? (string) $product->get_product_url('edit') : '',
            'button_text'       => $product->is_type('external') ? (string) $product->get_button_text('edit') : '',
        ];
    }

    /**
     * What the given variations have in common, the way the editor's single
     * Virtual and Digital switches and its one file list show a variable
     * product: `virtual` / `downloadable` are true only when every variation
     * has them, and `downloads` is the file list they all share (null when
     * the lists differ or not all of them are downloadable). An empty list of
     * IDs, or one that is not a variation, answers false / false / null.
     * One pass, reading the variations from the meta cache when primed.
     *
     * @param int[] $child_ids Variation IDs.
     * @return array{virtual: bool, downloadable: bool, downloads: WC_Product_Download[]|null}
     */
    private function variation_flags_summary(array $child_ids) {
        $none = ['virtual' => false, 'downloadable' => false, 'downloads' => null];
        if (empty($child_ids)) {
            return $none;
        }
        $all_virtual      = true;
        $all_downloadable = true;
        $shared           = null;
        $shared_files     = null;
        foreach ($child_ids as $child_id) {
            $child = wc_get_product((int) $child_id);
            if (!$child || !$child->is_type('variation')) {
                return $none;
            }
            $all_virtual = $all_virtual && $child->is_virtual();
            if (!$all_downloadable) {
                continue;
            }
            if (!$child->is_downloadable()) {
                $all_downloadable = false;
                continue;
            }
            $downloads = array_values($child->get_downloads());
            $files     = array_map(static function ($download) {
                return (string) $download->get_file();
            }, $downloads);
            sort($files);
            if (null === $shared_files) {
                $shared_files = $files;
                $shared       = $downloads;
            } elseif ($files !== $shared_files) {
                $shared = null;
            }
        }
        return [
            'virtual'      => $all_virtual,
            'downloadable' => $all_downloadable,
            'downloads'    => $all_downloadable ? $shared : null,
        ];
    }

    /**
     * A downloadable file's identity for comparing lists: the URL the way the
     * save cleans a posted one (esc_url_raw()), or the stored text when it is
     * not a URL (a server path or a storage shortcode).
     *
     * @param string $file Stored or posted file.
     * @return string
     */
    private function download_file_key($file) {
        $file = trim((string) $file);
        $url  = esc_url_raw($file);
        return $url !== '' ? $url : $file;
    }

    /**
     * Whether a posted file list is the list the editor showed: same files,
     * same names, any order. Names are compared the way the save cleans a
     * posted one, so a list sent back untouched reads as unchanged.
     *
     * @param WC_Product_Download[] $posted Posted list.
     * @param WC_Product_Download[] $shown  List the editor opened with.
     * @return bool
     */
    private function same_download_files(array $posted, array $shown) {
        $signature = function (array $downloads) {
            $out = [];
            foreach ($downloads as $download) {
                if ($download instanceof WC_Product_Download) {
                    $out[] = $this->download_file_key($download->get_file()) . "\0" . sanitize_text_field((string) $download->get_name());
                }
            }
            sort($out);
            return $out;
        };
        return $signature($posted) === $signature($shown);
    }

    /**
     * One variation's download objects from the editor's file list.
     *
     * A file the variation already has keeps the variation's own object, so
     * its download ID stays: WooCommerce gives each variation its own IDs and
     * a buyer's download link points at that ID. A file it lacks is a copy
     * with a new ID, the way WooCommerce's own editor adds one. With $merge
     * every file the variation has stays and only missing ones are added.
     * Always copies: one object shared by several variations is not safe.
     *
     * @param WC_Product_Download[] $listed Files from the editor's list.
     * @param mixed                 $own    The variation's current downloads.
     * @param bool                  $merge  Keep the variation's own files too.
     * @return WC_Product_Download[]
     */
    private function download_copies(array $listed, $own, $merge) {
        $own_by_file = [];
        $out = [];
        foreach ((is_array($own) ? $own : []) as $own_download) {
            if (!$own_download instanceof WC_Product_Download) {
                continue;
            }
            $own_by_file[$this->download_file_key($own_download->get_file())] = $own_download;
            if ($merge) {
                $out[] = clone $own_download;
            }
        }
        foreach ($listed as $download) {
            if (!$download instanceof WC_Product_Download) {
                continue;
            }
            $file_key = $this->download_file_key($download->get_file());
            if (isset($own_by_file[$file_key])) {
                if ($merge) {
                    continue;
                }
                $copy = clone $own_by_file[$file_key];
                $copy->set_name($download->get_name());
            } else {
                $copy = clone $download;
                $copy->set_id(wp_generate_uuid4());
            }
            $out[] = $copy;
        }
        return $out;
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

    /**
     * Draws the term checklist used by both the Category and the Brand box.
     *
     * @param iterable $categories   Flat term list, any order.
     * @param array    $selected_ids Term ids to tick.
     * @param int      $parent       Term id to start from, 0 for the root.
     * @param int      $depth        Nesting depth, 0 at the root.
     * @param string   $input_name   Checkbox `name`, so the Brand box can post
     *                               under its own key.
     * @param string   $empty_text   Shown when the taxonomy has no terms at
     *                               all. Passed in because the Brand box uses
     *                               the same renderer and must not say
     *                               "categories".
     */
    private function render_category_checklist($categories, $selected_ids, $parent = 0, $depth = 0, $input_name = 'category_ids[]', $empty_text = '') {
        if ($empty_text === '') {
            $empty_text = __('No categories found.', 'brikpanel');
        }

        // Normalise the ticked ids ONCE, into a set we can probe with isset().
        // Doing it per node (an array_map + in_array inside the loop) would put
        // a second quadratic back in exactly the renderer we just took one out
        // of. The int cast matters: post meta and third-party filters both hand
        // these back as numeric strings, and a strict in_array against them
        // silently unticks every box.
        $selected = array_fill_keys(array_map('intval', (array) $selected_ids), true);

        $this->render_category_branch(
            brikpanel_index_terms_by_parent($categories),
            $selected,
            (int) $parent,
            (int) $depth,
            $input_name,
            $empty_text
        );
    }

    /**
     * One level of the checklist above, plus its descendants.
     *
     * @param array  $by_parent parent term id => child terms.
     * @param array  $selected  Ticked term ids as a set: int id => true.
     * @param int    $parent    Term id whose children to draw.
     * @param int    $depth        Nesting depth, 0 at the root.
     * @param string $input_name   Checkbox `name`.
     * @param string $empty_text   Shown at the root when there are no terms.
     */
    private function render_category_branch(array $by_parent, array $selected, $parent, $depth, $input_name, $empty_text) {
        if (empty($by_parent[$parent])) {
            // Only the root says so out loud. A leaf simply has no children,
            // which is not worth a line of text; an empty box with nothing in
            // it at all reads as broken.
            if ($depth === 0) {
                echo '<p class="brikpanel-pe-cat-empty">' . esc_html($empty_text) . '</p>';
            }
            return;
        }

        $class = $parent === 0 ? 'brikpanel-pe-cat-tree' : 'brikpanel-pe-cat-children';
        echo '<ul class="' . esc_attr($class) . '">';

        foreach ($by_parent[$parent] as $cat) {
            $checked = isset($selected[(int) $cat->term_id]) ? ' checked' : '';
            echo '<li data-name="' . esc_attr(brikpanel_strtolower($cat->name)) . '" class="brikpanel-pe-cat-depth-' . esc_attr($depth) . '">';
            echo '<label><input type="checkbox" name="' . esc_attr($input_name) . '" value="' . esc_attr($cat->term_id) . '"' . $checked . '> ' . esc_html($cat->name) . '</label>';
            $this->render_category_branch($by_parent, $selected, (int) $cat->term_id, $depth + 1, $input_name, $empty_text);
            echo '</li>';
        }

        echo '</ul>';
    }

    /**
     * Indented <option> list for the "add new category" parent picker.
     *
     * @param iterable $categories Flat term list.
     * @param int      $parent     Term id to start from, 0 for the root.
     * @param int      $depth      Nesting depth, 0 at the root.
     */
    private function render_category_parent_options($categories, $parent = 0, $depth = 0) {
        $this->render_category_parent_branch(
            brikpanel_index_terms_by_parent($categories),
            (int) $parent,
            (int) $depth
        );
    }

    /**
     * One level of the parent picker above, plus its descendants.
     *
     * @param array $by_parent parent term id => child terms.
     * @param int   $parent    Term id whose children to draw.
     * @param int   $depth     Nesting depth, 0 at the root.
     */
    private function render_category_parent_branch(array $by_parent, $parent, $depth) {
        if (empty($by_parent[$parent])) {
            return;
        }

        foreach ($by_parent[$parent] as $cat) {
            $indent = str_repeat('— ', $depth);
            echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($indent . $cat->name) . '</option>';
            $this->render_category_parent_branch($by_parent, (int) $cat->term_id, $depth + 1);
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

    // Alt texts the editor sends with a save (save_image_alts()): the most one
    // save carries. The editor holds back anything past these for the next save.
    const ALT_MAX_BYTES   = 65536;
    const ALT_MAX_ENTRIES = 500;

    /**
     * Every image the product shows: the featured image, the gallery and, on a
     * variable product, each variation's image plus its extra images when
     * variation galleries are on (as get_product_data() reads them). Read in
     * 'edit' context after the save, so it is the set now stored.
     *
     * @param WC_Product $product Product.
     * @return array<int, true> Attachment ids as keys.
     */
    private function product_image_ids($product) {
        $ids      = [];
        $featured = (int) $product->get_image_id('edit');
        if ($featured) {
            $ids[$featured] = true;
        }
        foreach ((array) $product->get_gallery_image_ids('edit') as $gid) {
            $gid = (int) $gid;
            if ($gid) {
                $ids[$gid] = true;
            }
        }
        if ($product->is_type('variable')) {
            $children = brikpanel_variation_ids($product);
            if ($children) {
                update_meta_cache('post', $children);
                $galleries = get_option('brikpanel_variation_gallery_enabled', 'yes') === 'yes';
                foreach ($children as $child) {
                    $thumb = (int) get_post_meta($child, '_thumbnail_id', true);
                    if ($thumb) {
                        $ids[$thumb] = true;
                    }
                    $extra = $galleries ? get_post_meta($child, '_brikpanel_variation_gallery', true) : [];
                    foreach (is_array($extra) ? $extra : [] as $gid) {
                        $gid = (int) $gid;
                        if ($gid) {
                            $ids[$gid] = true;
                        }
                    }
                }
            }
        }
        return $ids;
    }

    /**
     * Writes the alt texts the editor sent (`bpe_image_alts`, a JSON object of
     * attachment id => text) onto those images. WordPress keeps one alt text
     * per image, so this is the image's own text wherever it is shown. The
     * rules are those of WordPress's media window (wp_ajax_save_attachment()):
     * the person must be allowed to edit that image, and the text is cleaned
     * with wp_strip_all_tags(). Images the saved product does not show are left
     * alone, since the editor only edits its own. Never through
     * wp_update_post(): this request's $_POST carries the product's fields,
     * and save_post handlers would write them onto the image.
     *
     * @param int $product_id Saved product.
     * @return array<int, string>|null Stored text of every sent image that is
     *                                 an attachment; null when none was sent.
     */
    private function save_image_alts($product_id) {
        if (!isset($_POST['bpe_image_alts'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ajax_save_product() verified the nonce.
            return null;
        }
        $out = [];
        $raw = $_POST['bpe_image_alts']; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every value is checked below.
        if (!is_string($raw)) {
            return $out;
        }
        $json = wp_unslash($raw);
        if (strlen($json) > self::ALT_MAX_BYTES) {
            $this->save_warnings[] = self::alt_reason_message('too_large');
            return $out;
        }
        // An object of id => text and nothing deeper: a list or a nested value
        // is not something the editor sends.
        $changes = json_decode($json, false, 2);
        if (!is_object($changes)) {
            return $out;
        }
        $changes = get_object_vars($changes);
        if (count($changes) > self::ALT_MAX_ENTRIES) {
            $this->save_warnings[] = self::alt_reason_message('too_large');
            return $out;
        }
        // Keys are image ids written plainly: "-12" or "12abc" is not 12.
        $wanted = [];
        foreach ($changes as $key => $text) {
            $key = (string) $key;
            if (is_string($text) && preg_match('/^[1-9][0-9]{0,18}$/', $key)) {
                $wanted[(int) $key] = $text;
            }
        }
        if (!$wanted) {
            return $out;
        }
        _prime_post_caches(array_keys($wanted), false, true);
        $product = wc_get_product($product_id);
        $own     = $product ? $this->product_image_ids($product) : [];
        $reasons = [];
        foreach ($wanted as $id => $text) {
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                continue;
            }
            $stored = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
            if (!isset($own[$id]) || !wp_attachment_is_image($post)) {
                $reasons['not_in_product'] = true;
            } elseif (!current_user_can('edit_post', $id)) {
                $reasons['locked'] = true;
            } else {
                $alt = wp_strip_all_tags($text, true);
                if ($alt !== $stored) {
                    update_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt));
                    $stored = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
                }
            }
            $out[$id] = $stored;
        }
        foreach (array_keys($reasons) as $code) {
            $this->save_warnings[] = self::alt_reason_message($code);
        }
        return $out;
    }

    /**
     * A save warning for alt text that was not stored.
     *
     * @param string $code not_in_product, locked, too_large or failed.
     * @return string
     */
    private static function alt_reason_message($code) {
        switch ($code) {
            case 'not_in_product':
                return __('An alt text was not saved because its image is no longer part of this product.', 'brikpanel');
            case 'locked':
                return __('An alt text was not saved because you are not allowed to edit that image.', 'brikpanel');
            case 'too_large':
                return __('Alt text changes were not saved because too many were sent at once. Save again with fewer changes.', 'brikpanel');
        }
        return __('The product was saved, but its alt text changes could not be stored. Try saving again.', 'brikpanel');
    }

    public function ajax_save_product() {
        check_ajax_referer('brikpanel_product_editor_nonce', 'security');

        // Fresh per-request warning bucket (duplicate SKU/GTIN etc.).
        $this->save_warnings = [];

        // Fresh per-request attribute-promotion state.
        $this->promoted_attributes = [];
        $this->promoted_this_save  = 0;
        $this->promoted_axis_map   = [];
        $this->axis_key_map        = [];
        $this->save_status         = '';
        $this->save_parent_virtual = null;
        $this->plugin_opts_sent    = [];

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        // Buffer everything emitted between here and our wp_send_json_* call so
        // stray output from other hooks can't contaminate the JSON response.
        // send_clean_json() drops the buffer immediately before sending.
        ob_start();

        // A third-party plugin hooking into the product save pipeline (media
        // offload, SEO, ERP sync, image processing…) can let an uncaught
        // exception bubble up from deep inside WooCommerce's save. Left
        // unhandled that surfaces as a bare HTTP 500 with WordPress's generic
        // "critical error" HTML page — the editor can then only report a vague
        // failure and the merchant has no hint that a *different* plugin is at
        // fault. Catch any Throwable from the save body and return a clean JSON
        // error instead, so the editor shows an actionable toast. Catching the
        // exception here (before it becomes a fatal) also keeps WordPress's own
        // shutdown fatal-handler from firing and corrupting the response.
        try {

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

        // WooCommerce registers WC_Meta_Box_Product_Data::save and
        // WC_Meta_Box_Product_Images::save on woocommerce_process_product_meta
        // (priority 10/20). Both read the FULL native product form and reset any
        // property whose field is absent from $_POST — upsells, cross-sells, the
        // image gallery, downloads, sale dates — which would wipe exactly the
        // values BrikPanel posts in its own condensed field names.
        //
        // They must therefore stay unhooked across EVERY
        // woocommerce_process_product_meta dispatch this request can trigger:
        //
        //   1. WC_Admin_Meta_Boxes::save_meta_boxes, hooked on save_post at
        //      priority 1. It fires on the very FIRST post write of this request
        //      — WC_Product::save() for a new product, and wp_update_post() for
        //      the post password / slug / schedule — because BrikPanel already
        //      populates $_POST['post_ID'] + the woocommerce_meta_nonce at
        //      plugins_loaded (see brikpanel.php) so third-party tab fields and
        //      SEO metaboxes recognise the save. That happens LONG before the
        //      deliberate save_post dispatch further down, which is why the
        //      unhook lives here at the very top of the save rather than next to
        //      that dispatch (bug: linked products + gallery silently reverted
        //      on every save once the "Additional product data" card was shown).
        //   2. our own explicit dispatch further down;
        //   3. the follow-up $refreshed->save().
        //
        // They are re-added right after $refreshed->save(); by then every
        // product-meta dispatch of this request is done. If the save aborts
        // early the request ends anyway, so the removal cannot leak.
        $_wc_data_save = ['WC_Meta_Box_Product_Data', 'save'];
        $_wc_img_save  = ['WC_Meta_Box_Product_Images', 'save'];
        $_removed = [
            'data'   => remove_action('woocommerce_process_product_meta', $_wc_data_save, 10),
            'images' => remove_action('woocommerce_process_product_meta', $_wc_img_save, 20),
        ];

        // Same class of problem, different plugin: SEOPress's Classic-editor
        // save fallback writes the posted `_seopress_*` values onto whatever
        // post the save_post it is handling is for, including this request's
        // variations. Detached here (before the FIRST post write, exactly like
        // the WC handlers above) and re-run once for the parent inside the
        // deliberate save_post dispatch further down. Never re-added within the
        // request; if the save aborts early the request ends anyway, so the
        // removal cannot leak into another one.
        $_seopress_classic_save = self::detach_seopress_classic_save();

        // Third case of the same rule, from SEOPress Pro. It adds two fields to
        // WooCommerce's Inventory panel (`sp_wc_barcode_field`,
        // `sp_wc_barcode_type_field`) and saves them on
        // woocommerce_process_product_meta with NO isset() guard:
        //
        //     $v = isset($_POST['sp_wc_barcode_field']) ? sanitize(...) : '';
        //     $product->update_meta_data('sp_wc_barcode_field', $v); $product->save();
        //
        // so an absent field is written as an empty string. BrikPanel fires
        // that action explicitly, and those fields only reach $_POST while the
        // "Additional product data" card is on the page. A merchant who uses
        // that card's own "Hide these fields" button therefore loses the
        // product's GTIN on every later save (measured: gtin13 / 1234567890128
        // blanked to '' by a single save).
        //
        // Detach per field, and only when the field really is missing, so a
        // merchant who CAN see the input still saves and clears it normally.
        // Same doctrine as the WC core handlers above: a form that never showed
        // a field must not be able to clear it.
        //
        // Measurement Price Calculator has the same shape, only larger: its
        // measurement handler rebuilds the whole `_wc_price_calculator` config
        // and the pricing-rule table from $_POST (no isset on anything), and its
        // dimensions handler writes `_area` / `_volume` straight from $_POST. With
        // the card hidden, one save reset the calculator and emptied the rules.
        foreach ([
            'sp_wc_barcode_field'           => 'seopress_save_wc_barcode_field',
            'sp_wc_barcode_type_field'      => 'seopress_save_wc_barcode_type_field',
            '_measurement_price_calculator' => 'wc_measurement_price_calculator_process_product_meta_measurement',
            '_area'                         => 'wc_measurement_price_calculator_process_product_meta',
        ] as $bp_sp_field => $bp_sp_callback) {
            if (!array_key_exists($bp_sp_field, $_POST)
                && has_action('woocommerce_process_product_meta', $bp_sp_callback)) {
                remove_action('woocommerce_process_product_meta', $bp_sp_callback);
            }
        }

        // Same doctrine for themes whose product fields are rebuilt from $_POST
        // on every save: Flatsome (all ten `wc_productdata_options` fields went
        // null), Porto (every Porto product field deleted) and CommerceKit
        // (the gallery-video map emptied). Their guards live with the product
        // video providers, which also parse this save's product video here,
        // before the first post write fires save_post.
        if (class_exists('Brikpanel_Video')) {
            Brikpanel_Video::begin_save($product_id);
        }

        // And for the options other plugins add next to Virtual and
        // Downloadable (Germanized's "Service", "Subscription", "For Tutor"…):
        // one this form did not show is posted with the value the product
        // already has, before the first post write, so no save switches it off.
        // A plugin failing here must not cost the merchant the whole save.
        try {
            $this->keep_unshown_plugin_type_options($product_id);
        } catch (\Throwable $e) {
            $this->plugin_opts_sent = [];
        }

        $is_variable = !empty($_POST['is_variable']);
        // Absent is NOT the same as 0. The client omits this key entirely when
        // the variations section is switched off in settings, because the
        // "Variable product" toggle it would come from is not on the page — see
        // the `data-section-hidden` note in the form markup. Treating that
        // absence as "the merchant chose simple" converted every variable
        // product to simple on save and hard-deleted all of its variations.
        $is_variable_posted = array_key_exists('is_variable', $_POST);
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
        // Grouped and external products have their own cards in this editor
        // ("Products in this group", "Product link"). Until 3.3.43 a posted
        // grouped/external type was blanked here and every save turned such a
        // product into a simple one: a group lost its products on the shop
        // page, an affiliate product became sellable in the store.
        $product_type = in_array($posted_type, $valid_types, true)
            ? $posted_type
            : ($is_variable ? 'variable' : 'simple');
        // No type in the payload (the type selector is switched off) must never
        // mean "convert this product to simple". A store running bundles,
        // composites or bookings with the selector off would otherwise have the
        // product's type rewritten on every save from this editor, which breaks
        // the product far more thoroughly than any single field. Keep whatever
        // the product already is unless the merchant actually chose a type, and
        // let the variable branches follow from that.
        if ($posted_type === '' && $product_id) {
            $existing_type = '';
            $existing_for_type = wc_get_product($product_id);
            if ($existing_for_type && method_exists($existing_for_type, 'get_type')) {
                $existing_type = (string) $existing_for_type->get_type();
            }
            // Every type but simple and variable is protected here: plugin types
            // and the core grouped and external types. Simple and variable are
            // exactly what the legacy `is_variable` checkbox exists to switch
            // between, so a simple ⇄ variable conversion must still go through
            // untouched when the selector is off. The checkbox is not shown for
            // a grouped or external product, so its "0" says nothing about them.
            if ($existing_type !== '' && $existing_type !== $product_type
                && in_array($existing_type, $valid_types, true)
                && !in_array($existing_type, ['simple', 'variable'], true)) {
                $product_type = $existing_type;
            }
            // …unless the merchant was never shown the toggle at all. With the
            // variations section switched off there is no control that could
            // express "variable", so the request carries no opinion about the
            // type and the product must keep the one it already has — including
            // the core `variable`, which the clause above deliberately excludes.
            if (!$is_variable_posted && $existing_type !== '' && in_array($existing_type, $valid_types, true)) {
                $product_type = $existing_type;
            }
        }
        $treat_as_variable = function_exists('brikpanel_is_variable_product_type')
            ? brikpanel_is_variable_product_type($product_type)
            : ($product_type === 'variable');
        // Keep $is_variable aligned with the resolved product type so the
        // downstream pricing/variation branches behave consistently.
        $is_variable = $treat_as_variable;

        // Someone who may edit but not publish products gets WordPress's own
        // rule (brikpanel_product_status_for_user()): publishing or scheduling
        // becomes "pending", sent in for review. Applied before the password
        // and schedule steps, so each of them can only keep a status the rule
        // allowed. People who may publish are not affected.
        $can_publish = brikpanel_user_can_publish_products();
        if (!$can_publish) {
            $status = brikpanel_product_status_for_user($status, $product_id);
        }

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

        // WordPress drops a password posted by someone who may not publish:
        // the stored one stays, whatever the status.
        if (!$can_publish) {
            $post_password = $product_id ? (string) get_post_field('post_password', $product_id) : '';
        }

        // Scheduled publishing (opt-in). "future" is a real WP status where the
        // publish is deferred to a chosen moment via WP-Cron. Gate on the setting
        // so a disabled feature can never leave a product stuck unpublished; a
        // product already scheduled is allowed through so an existing schedule
        // stays editable even after the setting is turned off. When the chosen
        // date is missing or not in the future we fall back to publishing now,
        // matching WordPress core's own behaviour.
        // Parse the single publish-date field once. The datetime-local value is
        // site-local; interpret it in the site timezone and keep the UTC stamp.
        if ($can_publish) {
            $raw_date = sanitize_text_field(wp_unslash($_POST['publish_date'] ?? ''));
        } else {
            // No date of their own for someone who may not publish (the editor
            // shows them no date control, like WordPress): the stored date is
            // used, so the steps below leave it, and a schedule a store manager
            // set, as they are. Not an empty date: that would publish a
            // scheduled product at once.
            $stored_date = $product_id && 'auto-draft' !== get_post_status($product_id)
                ? (string) get_post_field('post_date', $product_id)
                : '';
            $raw_date = ('' !== $stored_date && 0 !== strpos($stored_date, '0000-00-00')) ? $stored_date : '';
        }
        $chosen_ts  = 0;
        if ($raw_date !== '') {
            $dt = date_create($raw_date, wp_timezone());
            if ($dt) {
                $chosen_ts = $dt->getTimestamp();
            }
        }
        $scheduling_enabled = (get_option('brikpanel_pe_enable_scheduling', 'yes') === 'yes');

        $schedule_gmt = 0; // UTC stamp the product should go live at   (0 = none)
        $post_date_ts = 0; // explicit post_date for a live/draft status (0 = leave)

        if ($status === 'future') {
            // Explicit "Scheduled" status. Gate on the setting so a disabled
            // feature can never leave a product stuck unpublished; a product that
            // is already scheduled stays editable even after the setting is off.
            $already_future = $product_id && get_post_status($product_id) === 'future';
            if (!$scheduling_enabled && !$already_future) {
                $status = 'publish';
            } else {
                $schedule_gmt = $chosen_ts;
                // A missing or past date is not a real schedule → publish now.
                if ($schedule_gmt <= (time() + 30)) {
                    $status       = 'publish';
                    $schedule_gmt = 0;
                }
            }
        }

        // A publish date chosen against a live/draft status. This is what makes
        // the publish-date control work for ordinary products: backdate or correct
        // a published product, or set any date on a draft/private one.
        //
        // The datetime-local field only carries minute precision, so re-saving an
        // untouched product would strip the original seconds and nudge the stored
        // time on every save. Skip when the chosen minute already matches the
        // product's current publish minute — that's an unchanged date.
        if ($status !== 'future' && $chosen_ts > 0 && $product_id) {
            $existing_raw = get_post_field('post_date', $product_id);
            $existing_dt  = $existing_raw ? date_create($existing_raw, wp_timezone()) : false;
            if ($existing_dt
                && intdiv($existing_dt->getTimestamp(), 60) === intdiv($chosen_ts, 60)) {
                $chosen_ts = 0; // unchanged — leave the existing post_date intact
            }
        }
        if ($status !== 'future' && $chosen_ts > 0) {
            if ($status === 'publish' && $chosen_ts > (time() + 30)) {
                // A future date on a "Published" product is really a schedule
                // request. Honour it when scheduling is enabled; otherwise clamp
                // to now so the product can never silently vanish into a future
                // (unpublished) state — mirroring WordPress core's intent.
                if ($scheduling_enabled) {
                    $status       = 'future';
                    $schedule_gmt = $chosen_ts;
                } else {
                    $post_date_ts = time();
                }
            } else {
                // Backdated / corrected publish date, or any date on a
                // draft/private product (not public, so a future date is harmless).
                $post_date_ts = $chosen_ts;
            }
        }

        // "pending" (Pending review) is kept: a plain Save on a product that
        // waits for review used to turn it into a draft.
        if (!in_array($status, ['draft', 'pending', 'publish', 'private', 'future'], true)) {
            $status = 'draft';
        }
        $this->save_status = $status;

        // Variations orphaned by a variable -> simple conversion. They are
        // collected here but NOT deleted until the parent has actually been
        // saved (see "Delete the variations orphaned by a type change" below).
        // `delete(true)` is a permanent, un-trashable removal, and this handler
        // can still bail out after this point — on an empty product name, or on
        // any Throwable a third-party hook raises during the save. Deleting up
        // front meant those merchants were told "the product could not be
        // saved" while every variation had already been destroyed.
        $orphaned_variation_ids = [];

        // If editing existing product, handle type changes
        if ($product_id) {
            $existing = wc_get_product($product_id);
            if (!$existing) {
                $this->send_clean_json(false, ['message' => __('Product not found.', 'brikpanel')]);
            }

            // The stored Virtual flag, read before anything below writes: other
            // plugins' save handlers run during the parent save, and
            // WooCommerce stores a variable parent's flag as "no" the moment
            // anything touches it. Read raw because WC_Product_Variable
            // reports false whatever is stored.
            $this->save_parent_virtual = 'yes' === get_post_meta($product_id, '_virtual', true);

            // Any variable-family → non-variable transition: wipe orphan variations.
            $was_variable = function_exists('brikpanel_is_variable_product_type')
                ? brikpanel_is_variable_product_type($existing->get_type())
                : $existing->is_type('variable');
            if ($was_variable && !$treat_as_variable) {
                // …but only when the editor actually had those variations in
                // hand. The client derives "simple" from an empty variation
                // table, and the table can be empty for reasons that have
                // nothing to do with the merchant's intent: WooCommerce hides a
                // variation whose parent axis lost its "used for variations"
                // flag or its option list, so a product rewritten by an
                // importer or ERP loads with zero rows even though it has
                // children. Deleting them here was silent, permanent, and not
                // what anybody asked for.
                //
                // `variations_loaded` is how many rows the editor rendered.
                // Zero rows against a product that still has children means the
                // request carries no opinion about the type — keep what the
                // product already is. An older client omits the key entirely,
                // which keeps the previous behaviour.
                //
                // This only ever second-guesses the INFERRED type. When the
                // product-type selector is on the page, `$posted_type` carries
                // a type the merchant picked by hand, and overriding that would
                // make the control silently not work — a worse bug than the one
                // being fixed. An explicit choice always wins.
                $existing_children = brikpanel_variation_ids($existing);
                $variations_loaded = array_key_exists('variations_loaded', $_POST)
                    ? (int) $_POST['variations_loaded']
                    : -1;
                if ($posted_type === '' && $variations_loaded === 0 && !empty($existing_children)) {
                    $product_type      = $existing->get_type();
                    $treat_as_variable = true;
                    $is_variable       = true;
                } else {
                    $orphaned_variation_ids = $existing_children;
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

        // WooCommerce's own editor hides price, stock, shipping, cross-sells and
        // the virtual/downloadable flags for these two types; the writes below
        // follow the same rules. An external product even refuses stock
        // management with a WC_Data_Exception, which used to fail the save.
        $is_grouped  = 'grouped' === $product_type;
        $is_external = 'external' === $product_type;

        // Basic data
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if (empty($name)) {
            $this->send_clean_json(false, ['message' => __('Product name is required.', 'brikpanel')]);
        }

        // WC_Product::save() persists the `product_type` taxonomy term via
        // the data store, but only for types it recognises natively. Force-
        // set the term after save() so plugin-registered types always end
        // up on the object. We do it here as a pre-save safety net too — if
        // the product already exists and the type changes, the term needs
        // to flip *before* downstream hooks fire their own type-aware logic.
        // It sits *below* the product-name check on purpose: a save that
        // answers with an error must not have flipped the product's type on
        // its way out. Everything between here and $product->save() is
        // setter calls on the in-memory object, so the term still lands
        // before the first hook runs.
        //
        // $type_term_rollback remembers what the term was, because "before the
        // first hook runs" also means before a hook can THROW. The catch at the
        // bottom of this handler tells the merchant the product was not saved;
        // if the save really did not complete, the type must not have silently
        // changed underneath that message either.
        $type_term_rollback = null;
        if ($product_id) {
            $previous_type = $existing instanceof WC_Product ? $existing->get_type() : '';
            if ($previous_type !== '' && $previous_type !== $product_type) {
                $type_term_rollback = $previous_type;
            }
            wp_set_object_terms($product_id, $product_type, 'product_type', false);
        }
        $product->set_name($name);
        $product->set_status($status);

        // Scheduled publishing: pin the product's created/publish date to the
        // chosen future moment so WordPress defers going live (status "future")
        // and schedules the WP-Cron publish. WC's data store writes
        // post_date/post_date_gmt from this WC_DateTime (numeric = UTC), and the
        // resulting `future` transition schedules `publish_future_post`.
        if ($status === 'future' && $schedule_gmt > 0) {
            $product->set_date_created($schedule_gmt);
        } elseif ($post_date_ts > 0) {
            // Explicit publish date for a live/draft product (backdate or correct).
            // WC's data store writes post_date/post_date_gmt from this WC_DateTime.
            $product->set_date_created($post_date_ts);
        } elseif ($product_id && $status !== 'future' && get_post_status($product_id) === 'future') {
            // Un-scheduling: a product leaving "future" for publish/draft/private
            // still carries its future-dated post_date, which WordPress would
            // bounce straight back to "future". Reset the created date to now so
            // the new status takes effect immediately.
            $product->set_date_created(time());
        }

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

        // Both description fields are opt-in sections. A store that hides them
        // sends no key at all, and an absent key must leave the stored copy
        // alone — defaulting to '' here wiped the description of every product
        // saved while the section was switched off.
        if (isset($_POST['short_description'])) {
            $product->set_short_description(wp_kses_post(wp_unslash($_POST['short_description'])));
        }
        if (isset($_POST['description'])) {
            $product->set_description(wp_kses_post(wp_unslash($_POST['description'])));
        }

        // Price (simple and external products). A group has no price of its
        // own: WooCommerce syncs it from the products in the group. A product
        // that has just become a group drops the price it had as a simple one.
        //
        // The price keys come only while the price box is on the page. A store
        // that hides the Pricing section sends none, and an absent key used to
        // read as "no price": every save cleared the product's price.
        //
        // A WPML translation takes its price from the original product (WooCommerce
        // Multilingual copies it there): the editor shows the boxes locked, and a
        // price posted anyway is ignored. Its variations follow the same rule in
        // save_variations().
        $bpe_price_locked = function_exists('brikpanel_wpml_original_of') && brikpanel_wpml_original_of($product_id);
        if ($is_grouped) {
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
        } elseif (!$is_variable && !$bpe_price_locked && array_key_exists('regular_price', $_POST)) {
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
        //
        // Every key is written only when the editor sent it: with the
        // Inventory card switched off in settings none of its controls are on
        // the page, and an absent key used to read as "tracking off, in stock,
        // no SKU" (field test B6, the same rule as GTIN and COGS below).
        $manage_stock = !empty($_POST['manage_stock']);
        $stock = isset($_POST['stock_quantity']) && $_POST['stock_quantity'] !== '' ? intval($_POST['stock_quantity']) : 0;
        $stock_status = sanitize_key($_POST['stock_status'] ?? 'instock');
        if (!in_array($stock_status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $stock_status = 'instock';
        }
        $backorders_raw = sanitize_key($_POST['backorders'] ?? '');
        // Orders keep selling while the editor is open, and the editor (its
        // 60-second autosave too) used to write back the quantity the page
        // opened with, raising the stock again. The box's opening value comes
        // along: an unchanged box leaves the stored quantity alone, as
        // WooCommerce's own editor does with _original_stock.
        $keep_stored_qty = brikpanel_stock_box_unchanged($product, $stock, $_POST['stock_quantity_original'] ?? null);
        if ($is_variable) {
            // WooCommerce's shared stock: the product counts one quantity
            // that every variation without a count of its own sells from.
            // Sent only while the "Shared stock" switch is on the page with
            // the variation rows; otherwise the product's setting stays as it
            // is (save_variations() still clears a leftover nobody follows).
            if (isset($_POST['shared_stock'])) {
                if (!empty($_POST['shared_stock'])) {
                    $product->set_manage_stock(true);
                    if (!$keep_stored_qty) {
                        $product->set_stock_quantity($stock);
                    }
                    $product->set_backorders(in_array($backorders_raw, ['yes', 'notify'], true) ? $backorders_raw : 'no');
                } else {
                    $product->set_manage_stock(false);
                }
            }
            // "Pack sizes" under "Shared stock". Sent only while the switch is
            // on the page. Off removes the flag; the variations keep their
            // pack sizes, so switching it on again brings them back.
            if (isset($_POST['pack_sizes']) && class_exists('Brikpanel_Pack_Sizes')) {
                if (!empty($_POST['pack_sizes'])) {
                    $product->update_meta_data(Brikpanel_Pack_Sizes::SWITCH_META, 'yes');
                } else {
                    $product->delete_meta_data(Brikpanel_Pack_Sizes::SWITCH_META);
                }
            }
        } elseif ($is_external) {
            // External products are never stock managed: WooCommerce throws on
            // anything but "not tracked, in stock, no backorders". Writing those
            // three also cleans up a product that was simple a moment ago.
            $product->set_manage_stock(false);
            $product->set_stock_status('instock');
            $product->set_backorders('no');
        } elseif (!$is_grouped && (isset($_POST['manage_stock']) || isset($_POST['shared_stock']))) {
            // A group's stock status comes from its products, so nothing is
            // written for one.
            // A product with the variation switch on but no variations yet
            // saves as simple; the switch on the page was "Shared stock", and
            // no status box was on the page to read.
            $from_shared_switch = !isset($_POST['manage_stock']);
            if ($from_shared_switch) {
                $manage_stock = !empty($_POST['shared_stock']);
            }
            if (!$manage_stock && $from_shared_switch) {
                $product->set_manage_stock(false);
            } elseif ($manage_stock) {
                // Tracking on: merchant sets quantity + backorders, WC derives
                // the stock status from them (qty>0 → in stock, qty<=0 with
                // backorders → on backorder, else out of stock). We do NOT
                // force a status here, which is exactly what eliminates the
                // "managed + qty 0 stuck out of stock" trap.
                $backorders_value = in_array($backorders_raw, ['yes', 'notify'], true) ? $backorders_raw : 'no';
                $product->set_manage_stock(true);
                if (!$keep_stored_qty) {
                    $product->set_stock_quantity($stock);
                }
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

        // SKU. Only when the box was on the page (see the stock note above).
        if (isset($_POST['sku']) && is_scalar($_POST['sku'])) {
            $sku = sanitize_text_field(wp_unslash($_POST['sku']));
            try {
                $product->set_sku($sku);
            } catch (\Exception $e) {
                // WooCommerce throws WC_Data_Exception here when the SKU is a
                // duplicate (or otherwise invalid). The rest of the product still
                // saves fine, so we keep going — but silently swallowing this left
                // the merchant with a phantom "Product saved!" and no SKU, unable
                // to tell why. Surface the reason so they can pick a unique value.
                if ($sku !== '') {
                    $this->save_warnings[] = sprintf(
                        /* translators: %s: the SKU the merchant tried to save */
                        __('The SKU "%s" was not saved because it is already used by another product. SKUs must be unique.', 'brikpanel'),
                        $sku
                    );
                }
            }
        }

        // Global Unique ID (GTIN/UPC/EAN/ISBN). Only touch it when the field
        // was actually submitted — the section is opt-in, so a missing key
        // means "leave the stored value alone" rather than "clear it".
        if (array_key_exists('global_unique_id', $_POST)) {
            $gtin = sanitize_text_field(wp_unslash($_POST['global_unique_id']));
            try {
                brikpanel_wc_set_gtin($product, $gtin);
            } catch (\Throwable $e) {
                // Duplicate/invalid GTIN — save the rest but tell the merchant
                // why the barcode did not stick (same rationale as SKU above).
                if ($gtin !== '') {
                    $this->save_warnings[] = sprintf(
                        /* translators: %s: the GTIN/UPC/EAN/ISBN the merchant tried to save */
                        __('The GTIN "%s" was not saved because it is already used by another product or is invalid.', 'brikpanel'),
                        $gtin
                    );
                }
            }
        }

        // Opt-in WC-core sections. Each is guarded by array_key_exists so a
        // disabled section (key absent from the request) never overwrites the
        // stored value. Grouped and external products skip what WooCommerce's
        // own editor does not show for them (tax for a group; shipping, sold
        // individually, cross-sells and the purchase note for both).
        $bpe_ships = !$is_grouped && !$is_external;
        if (!$is_grouped && array_key_exists('tax_status', $_POST)) {
            $ts = sanitize_text_field(wp_unslash($_POST['tax_status']));
            if (in_array($ts, ['taxable', 'shipping', 'none'], true)) {
                $product->set_tax_status($ts);
            }
        }
        if (!$is_grouped && array_key_exists('tax_class', $_POST)) {
            $product->set_tax_class(sanitize_text_field(wp_unslash($_POST['tax_class'])));
        }
        if ($bpe_ships && array_key_exists('shipping_class', $_POST)) {
            // WC has no set_shipping_class() setter — only set_shipping_class_id().
            // Resolve the submitted slug to its term id; empty slug → 0 (none).
            $sc_slug = sanitize_title(wp_unslash($_POST['shipping_class']));
            $sc_term = $sc_slug !== '' ? get_term_by('slug', $sc_slug, 'product_shipping_class') : false;
            $product->set_shipping_class_id($sc_term ? (int) $sc_term->term_id : 0);
        }
        if ($bpe_ships && array_key_exists('sold_individually', $_POST)) {
            $product->set_sold_individually(!empty($_POST['sold_individually']) && $_POST['sold_individually'] !== '0');
        }
        if (array_key_exists('upsell_ids', $_POST)) {
            $up = json_decode(wp_unslash($_POST['upsell_ids']), true);
            $product->set_upsell_ids(is_array($up) ? array_values(array_unique(array_filter(array_map('absint', $up)))) : []);
        }
        if ($bpe_ships && array_key_exists('cross_sell_ids', $_POST)) {
            $cs = json_decode(wp_unslash($_POST['cross_sell_ids']), true);
            $product->set_cross_sell_ids(is_array($cs) ? array_values(array_unique(array_filter(array_map('absint', $cs)))) : []);
        }
        if ($bpe_ships && array_key_exists('purchase_note', $_POST)) {
            $product->set_purchase_note(sanitize_textarea_field(wp_unslash($_POST['purchase_note'])));
        }
        if (array_key_exists('reviews_allowed', $_POST)) {
            $product->set_reviews_allowed(!empty($_POST['reviews_allowed']) && $_POST['reviews_allowed'] !== '0');
        }
        // Menu order is written only when the merchant changed the box: the
        // products list's Sort mode and new products placed on top of a custom
        // order (includes/brikpanel-product-order.php) change it while this page
        // stays open, and saving the number the page opened with undid them.
        if (array_key_exists('menu_order', $_POST)) {
            $bpe_menu_order = (int) $_POST['menu_order'];
            $bpe_menu_was   = isset($_POST['menu_order_original']) && '' !== $_POST['menu_order_original']
                ? (int) $_POST['menu_order_original']
                : null;
            if (null === $bpe_menu_was || $bpe_menu_order !== $bpe_menu_was) {
                $product->set_menu_order($bpe_menu_order);
            }
        }

        // Weight and dimensions: written only when the editor sent them. Their
        // sections are opt-in and hidden by default, and a missing key used to
        // mean '' here, which wiped every product's weight and dimensions on
        // save. An empty value that IS sent still clears (toggle switched off).
        if ($bpe_ships && array_key_exists('weight', $_POST)) {
            $weight = sanitize_text_field(wp_unslash($_POST['weight']));
            $product->set_weight($weight !== '' ? wc_format_decimal($weight) : '');
        }
        foreach (['length', 'width', 'height'] as $bp_dim) {
            if ($bpe_ships && array_key_exists($bp_dim, $_POST)) {
                $bp_dim_val = sanitize_text_field(wp_unslash($_POST[$bp_dim]));
                $product->{'set_' . $bp_dim}($bp_dim_val !== '' ? wc_format_decimal($bp_dim_val) : '');
            }
        }

        // Images
        //
        // An absent key means "the editor cannot speak for the images", not
        // "the merchant removed them". The two are very different: the browser
        // only sends these once the gallery has been hydrated from the saved
        // product, so a save that fires before that (or after a script error
        // stopped hydration) would otherwise read as "delete every image" and
        // silently strip the product's featured image and gallery. Removing
        // them on purpose still works — that posts an explicit empty value.
        if (isset($_POST['image_id'])) {
            $product->set_image_id(intval($_POST['image_id']));
        }

        if (isset($_POST['gallery_ids'])) {
            $gallery_ids_raw = sanitize_text_field($_POST['gallery_ids']);
            $gallery_ids = $gallery_ids_raw ? array_map('intval', explode(',', $gallery_ids_raw)) : [];
            $product->set_gallery_image_ids($gallery_ids);
        }

        // Product videos are written at the end of the save, once the images
        // and variations are stored (Brikpanel_Video::apply_changes()), and
        // only for images the saved product really has.

        // Categories, when the editor sent them: it does while the Category
        // section is on the form. With the section switched off in the
        // settings the key is absent and the product keeps its categories;
        // reading the absence as "none" moved every saved product to
        // Uncategorized (November 2026). A new product saved without any
        // still gets WooCommerce's default category, as before.
        if (isset($_POST['category_ids'])) {
            $cat_ids_raw = sanitize_text_field(wp_unslash($_POST['category_ids']));
            $cat_ids = $cat_ids_raw ? array_map('intval', explode(',', $cat_ids_raw)) : [];
            $product->set_category_ids($cat_ids);
        }

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
        //
        // Written only when the editor sent them, which it does while the
        // "Digital product" section is on screen. With the section hidden the
        // switches are not there to read, and taking the missing keys as "off"
        // turned every virtual product physical (shipping charged again) and
        // deleted its download files on each save.
        //
        // Grouped and external products are neither (WooCommerce's editor
        // unticks both when the type switches to one of them). The download
        // list is left as it is, so switching back by mistake loses nothing.
        if ($is_grouped || $is_external) {
            $product->set_virtual(false);
            $product->set_downloadable(false);
        } elseif (array_key_exists('is_virtual', $_POST) || array_key_exists('is_downloadable', $_POST)) {
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
        }

        // COGS — saved to _brikpanel_cogs for BrikPanel reports (and mirrored
        // to WC 9.5+ native COGS when available). Only touched when the COGS
        // section is currently visible; the JS omits the field when hidden,
        // so absence here means "the editor never rendered this control" and
        // we must leave any existing cost on file alone. Otherwise turning
        // the section off would silently wipe every product's cost on its
        // next save.
        $cogs_submitted = null;
        // A group has no cost of its own (its products have theirs).
        if ( ! $is_grouped && array_key_exists( 'cogs_value', $_POST ) ) {
            $cogs_raw       = sanitize_text_field( $_POST['cogs_value'] );
            $cogs_decimal   = brikpanel_set_product_cogs_raw( $product->get_id(), $cogs_raw );
            $cogs_submitted = $cogs_decimal;
            // Feature off, the setter stores nothing and writes to the error log.
            if ( brikpanel_wc_cogs_enabled( $product ) ) {
                $product->set_cogs_value( $cogs_decimal !== '' ? $cogs_decimal : null );
            }
            // A cost plugin's own input rides along in this submission (we
            // render WooCommerce's Product data panels inline) carrying the
            // value it rendered with, not what the merchant just typed. Give
            // it the real number so its save handler stores that instead of
            // overwriting the cost with a stale 0. See the re-assert after
            // $refreshed->save() for the plugins we don't know by name.
            brikpanel_cogs_sync_posted_third_party_inputs( $cogs_decimal );
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

        // The products in a group, and an external product's link and button.
        // WooCommerce's own handler for these is unhooked during this save
        // (see the top of the handler), so they are written here. Each key is
        // sent only while its card is on the page.
        if ($is_grouped && array_key_exists('grouped_ids', $_POST)) {
            $product->set_children(self::sanitize_group_children(wp_unslash($_POST['grouped_ids']), (int) $product_id));
        }
        if ($is_external) {
            if (array_key_exists('product_url', $_POST) && is_scalar($_POST['product_url'])) {
                $product->set_product_url(esc_url_raw(trim(wp_unslash($_POST['product_url']))));
            }
            if (array_key_exists('button_text', $_POST) && is_scalar($_POST['button_text'])) {
                $product->set_button_text(wc_clean(wp_unslash($_POST['button_text'])));
            }
        }

        // A plugin whose own settings box for this product type is not on the
        // form (not picked, or posted empty) comes off WooCommerce's product
        // save hooks for the rest of this save. Its listeners read a missing
        // field as "cleared": one save from here emptied an Easy Product Bundles
        // or WPC Product Bundles bundle, on `woocommerce_process_product_meta_
        // {type}`. Taken off before the first post write, because that write
        // already fires `woocommerce_process_product_meta` through
        // WC_Admin_Meta_Boxes::save_meta_boxes() (see the unhook at the top of
        // this handler), and put back next to WooCommerce's own handlers.
        $bp_type_guard    = self::save_hook_guard($product);
        $bp_type_detached = self::detach_callbacks([
            'woocommerce_process_product_meta',
            'woocommerce_process_product_meta_' . (string) $product->get_type(),
            'woocommerce_admin_process_product_object',
        ], $bp_type_guard['owners']);

        // Save parent product
        $product->save();
        $saved_id = $product->get_id();
        $type_term_rollback = null;

        // A group's price comes from its products. WooCommerce re-syncs it when
        // the product list changes; a product that just became a group (or a
        // group whose list did not change) still carries the price it had
        // before, so sync it every time.
        if ($is_grouped && class_exists('WC_Product_Grouped') && method_exists('WC_Product_Grouped', 'sync')) {
            WC_Product_Grouped::sync($product, false);
        }

        // Delete the variations orphaned by a type change. This runs *after*
        // the parent is persisted, so a request that answers with an error has
        // changed nothing: the removal is permanent (`force_delete`, no trash)
        // and every earlier bail-out — the empty-name check and the Throwable
        // catch that reports "another plugin raised an error" — would otherwise
        // have destroyed them and then told the merchant nothing was saved.
        // The editor also confirms before the conversion; this is the second
        // half of that guarantee.
        foreach ($orphaned_variation_ids as $child_id) {
            $child = wc_get_product($child_id);
            if ($child) $child->delete(true);
        }

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

        // Scheduled publishing — force the "future" status + date through
        // wp_update_post as well. On the FIRST save of a brand-new product (which
        // starts life as an `auto-draft`), WooCommerce's create path does not keep
        // a future post_date, so the product would publish immediately even though
        // we set the date on the object. Re-asserting it here (with edit_date so
        // WP honours the explicit date) makes scheduling stick on the very first
        // save too, and the resulting `future` transition schedules the WP-Cron
        // publish. Only added when actually scheduling, so normal saves are
        // untouched.
        if ($status === 'future' && $schedule_gmt > 0) {
            $post_update_args['post_status']   = 'future';
            $post_update_args['post_date']     = get_date_from_gmt(gmdate('Y-m-d H:i:s', $schedule_gmt));
            $post_update_args['post_date_gmt'] = gmdate('Y-m-d H:i:s', $schedule_gmt);
            $post_update_args['edit_date']     = true;
        }

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
            // SEO score. Kept out of $rm_map because it needs a range check
            // rather than sanitize_text_field: when Rank Math's analyser never
            // ran in this session (module off, no focus keyword, content not
            // collected) its store still reports 0, and writing that would wipe
            // a good score the merchant earned in the native editor. Only a
            // real score overwrites; 0 leaves the stored value alone, which
            // matches Rank Math's own column — it renders "N/A" for a product
            // with no focus keyword whatever the meta holds.
            if (isset($_POST['bpe_rm_seo_score'])) {
                $rm_score = (int) wp_unslash($_POST['bpe_rm_seo_score']);
                if ($rm_score > 0 && $rm_score <= 100) {
                    update_post_meta($saved_id, 'rank_math_seo_score', $rm_score);
                }
            }
            if (isset($_POST['bpe_rm_dont_show_seo_score'])) {
                $rm_show = sanitize_key(wp_unslash($_POST['bpe_rm_dont_show_seo_score']));
                if (in_array($rm_show, ['on', 'off'], true)) {
                    update_post_meta($saved_id, 'rank_math_dont_show_seo_score', $rm_show);
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

        // Tags. Each one is sanitized on its own: on the joined string
        // "<5kg,>10kg", sanitize_text_field() saw a tag and the two merged
        // into a single "10kg".
        $tag_names_raw = isset($_POST['tag_names']) && is_scalar($_POST['tag_names']) ? (string) wp_unslash($_POST['tag_names']) : '';
        if ($tag_names_raw !== '') {
            $tags = array_values(array_filter(array_map('sanitize_text_field', explode(',', $tag_names_raw)), 'strlen'));
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

        // WC core's own product-data / product-images save handlers were
        // unhooked at the very top of this method (see the long note there);
        // they stay unhooked until right after $refreshed->save() below.

        // WooCommerce Product Data tab extensions (Subscriptions, Memberships,
        // Bookings, shipping add-ons, internal product codes, SEO Product Boxes,
        // etc.) register their save handlers on woocommerce_process_product_meta
        // rather than save_post. Dispatch it explicitly so fields captured by
        // capture_wc_product_data_fields() round-trip through the plugin's own
        // save pipeline.
        //
        // This dispatch runs BEFORE save_post on purpose: on a native WooCommerce
        // save, process_product_meta fires from save_post priority 1, i.e.
        // BEFORE most save_post listeners. Some plugins pair a
        // process_product_meta handler (which clears a value when its field is
        // absent, e.g. on a variable product that carries the field per
        // variation) with a save_post handler that rebuilds it. Firing here,
        // ahead of save_post, preserves that "clear then rebuild" ordering so
        // such derived data survives; firing after save_post would clear it
        // again after the rebuild.
        $post_type_key = 'simple';
        if ($product && method_exists($product, 'get_type')) {
            $type = $product->get_type();
            if ($type) $post_type_key = $type;
        }
        // Attach save handlers that third-party tab plugins only register on
        // non-ajax admin requests (e.g. Pektsekye Product Options).
        self::boot_thirdparty_ajax_save_handlers();
        // All guarded: these dispatches run other plugins' save handlers, and
        // one of them calling wp_die() (typically re-checking the native
        // metabox nonce we don't carry) would otherwise end the request here,
        // before variations and the rest of the save. See
        // dispatch_foreign_hooks().
        // CURCY reads WooCommerce's native sale keys (see
        // with_multicurrency_save_context()). With no price keys in this save
        // (the price box was not on the page) they mirror what is stored: CURCY
        // empties its per-currency sale prices when the native sale price is
        // empty, on bundle types too (woosb, bundle, composite).
        if (array_key_exists('regular_price', $_POST)) {
            $curcy_native = [
                '_sale_price'          => wc_format_decimal(sanitize_text_field(wp_unslash($_POST['sale_price'] ?? ''))),
                '_sale_price_dates_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', sanitize_text_field(wp_unslash($_POST['sale_to'] ?? ''))) ? sanitize_text_field(wp_unslash($_POST['sale_to'])) : '',
            ];
        } else {
            $bp_sale_to   = $product->get_date_on_sale_to('edit');
            $curcy_native = [
                '_sale_price'          => (string) $product->get_sale_price('edit'),
                '_sale_price_dates_to' => $bp_sale_to ? $bp_sale_to->date('Y-m-d') : '',
            ];
        }
        // A type panel no owner could be found for: its own type hook stays
        // quiet, like the object hook below (losing a save is recoverable,
        // losing a bundle's contents is not).
        $bp_skip_type_hook = !empty($bp_type_guard['panels']) && empty($bp_type_guard['owners']);
        $this->dispatch_foreign_hooks(
            function () use ($saved_id, $post_obj, $post_type_key, $_seopress_classic_save, $curcy_native, $bp_skip_type_hook) {
                do_action('woocommerce_process_product_meta', $saved_id, $post_obj);
                if (!$bp_skip_type_hook) {
                    self::with_multicurrency_save_context('product', $curcy_native, static function () use ($post_type_key, $saved_id) {
                        do_action('woocommerce_process_product_meta_' . $post_type_key, $saved_id);
                    });
                }

                // SEOPress's Classic save fallback, detached at the top of this
                // save so it could not follow save_post onto the variations.
                // Run it exactly once, for the parent, from inside this same
                // wp_die-guarded dispatch.
                if ($_seopress_classic_save && is_callable($_seopress_classic_save['callback'])) {
                    call_user_func($_seopress_classic_save['callback'], $saved_id, $post_obj);
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
            },
            'product meta dispatch'
        );

        // `woocommerce_admin_process_product_object` lets plugins mutate the
        // WC_Product instance itself before the caller persists it. We fetch
        // a fresh product, let listeners mutate, then re-save.
        //
        // …with one exception. Listeners on this hook read their own fields out
        // of $_POST and treat a missing field as "the merchant cleared it". That
        // is correct for a form that contained the field, and destructive for one
        // that never rendered it. When the product's type owns a product-data
        // panel this editor cannot show (see unrendered_type_panels() — Product
        // Bundles, Composite Products, Bookings…), the submitted form is not a
        // representation of that product, so we stay silent instead of handing
        // those listeners an empty form to act on. Skipping the hook can only
        // leave third-party data untouched; firing it can delete it.
        //
        // The silence is kept as narrow as it can be: only the plugin that owns
        // the missing panel is held back, so everything else on the hook
        // (Germanized, brand/SEO/ERP add-ons…) still processes a bundle exactly
        // as it processes a simple product. When the owner cannot be identified
        // we fall back to skipping the whole hook, because losing a save is
        // recoverable and losing a bundle's contents is not.
        //
        // Second source of the same gap: a plugin whose tab callback FATALS in
        // our context (unrepresented_owner_paths(), Composite Products) never
        // declares a panel for us to miss, so the class-based check above cannot
        // see it. Its own product type would otherwise be left unguarded by the
        // very listener that empties it. Both sources feed one mute list.
        //
        // Both were worked out once, before the first post write
        // (save_hook_guard()); the panel owners are already off this hook.
        $refreshed = wc_get_product($saved_id);
        if ($refreshed) {
            $muted_paths   = array_values(array_unique(array_merge($bp_type_guard['owners'], $bp_type_guard['throwers'])));
            $unrepresented = !empty($bp_type_guard['panels']) || !empty($bp_type_guard['throwers']);
            if (!$unrepresented || !empty($muted_paths)) {
                $this->dispatch_foreign_hooks(
                    static function () use ($refreshed, $muted_paths) {
                        if (empty($muted_paths)) {
                            do_action('woocommerce_admin_process_product_object', $refreshed);
                            return;
                        }
                        self::do_action_excluding_paths(
                            'woocommerce_admin_process_product_object',
                            [$refreshed],
                            $muted_paths
                        );
                    },
                    'woocommerce_admin_process_product_object'
                );
            }
            $refreshed->save();
        }
        // Every product-meta dispatch inside the spoof window is done; restore
        // WC core's data/image save handlers (see the unhook before save_post)
        // and the plugins save_hook_guard() took off.
        if (!empty($_removed['data']))   add_action('woocommerce_process_product_meta', $_wc_data_save, 10, 2);
        if (!empty($_removed['images'])) add_action('woocommerce_process_product_meta', $_wc_img_save, 20, 2);
        self::reattach_callbacks($bp_type_detached);

        // Cost of goods, re-asserted *after* every save handler has run.
        // Cost plugins hook the product save at the very end of the chain
        // (WPFactory uses save_post_product at PHP_INT_MAX - 2) and write
        // their own cost key from the input they injected into WooCommerce's
        // Product data panel. We already corrected that input above, but a
        // plugin we don't know by name would still land the last write and
        // silently replace the merchant's number — its key is read first, so
        // the typed value would be gone on the next page load. Writing the
        // whole key set here, last, makes BrikPanel's field authoritative
        // whatever else is installed. Same reasoning as the stock_status
        // workaround below.
        if ($cogs_submitted !== null) {
            brikpanel_set_product_cogs_raw($saved_id, $cogs_submitted);
        }

        // WC core's validate_props() auto-syncs stock_status from quantity
        // every time save() is called (stock > 0 forces "instock"), which
        // overrides the user's explicit choice — and the secondary
        // $refreshed->save() above re-runs it. Write the meta directly here,
        // *after* every WC save has finished, so the requested status sticks.
        // Only relevant when stock tracking is OFF: when it is on we let WC
        // derive the status from quantity + backorders on purpose. Never for a
        // group (its status comes from its products) or an external product
        // (always in stock in WooCommerce).
        if (!$is_variable && !$is_grouped && !$is_external && empty($_POST['manage_stock']) && isset($_POST['stock_status'])) {
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
        // Every product-level save hook has fired: put back the $_POST values
        // the theme guards filled in (see Brikpanel_Video::begin_save()).
        if (class_exists('Brikpanel_Video')) {
            Brikpanel_Video::end_dispatch();
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

        // Product videos go in last, once the images and variations are stored,
        // so no other save handler of this request can overwrite them and only
        // images the saved product really has are accepted. A video problem is
        // a warning; it never fails the product save.
        $video_state = null;
        if (class_exists('Brikpanel_Video')) {
            try {
                $video_state = Brikpanel_Video::apply_changes($saved_id, $this->save_warnings);
            } catch (\Throwable $e) {
                $this->save_warnings[] = __('The product was saved, but its video changes could not be stored. Try saving again.', 'brikpanel');
            }
        }

        // Alt texts go in at the same point and by the same rule: once the
        // images and variations are stored, only for images the saved product
        // has, and a problem is a warning that never fails the product save.
        $alt_state = null;
        try {
            $alt_state = $this->save_image_alts($saved_id);
        } catch (\Throwable $e) {
            $this->save_warnings[] = self::alt_reason_message('failed');
        }

        // The other plugins' options the form showed, as their plugins stored
        // them, once every save handler has run; a warning for any change a
        // plugin did not take (plugin_type_options_after_save()).
        $plugin_opts_state = [];
        try {
            $plugin_opts_state = $this->plugin_type_options_after_save($saved_id);
        } catch (\Throwable $e) {
            $plugin_opts_state = [];
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
            // Authoritative variation count after this save. The editor warns
            // before a variable -> simple conversion and names the number; that
            // number came from the page load, so without this it goes stale the
            // moment anything changes it — after "Clear all" the warning would
            // still promise to destroy variations that are already gone.
            'variation_count' => $final_product ? count(brikpanel_variation_ids($final_product)) : 0,
            // The status as saved, which can differ from the one asked for:
            // "pending" for someone who may not publish, "future" for a date
            // ahead. The editor's live flag, button and status control follow
            // it. "password" is the menu's name for a protected, published one.
            'status'     => self::saved_status_for_editor($saved_id),
        ];

        // The videos as stored now, so the editor shows what the theme will
        // play rather than what it sent.
        if ($video_state !== null) {
            $response['videos'] = $video_state;
        }

        // The alt text now stored for every image the editor sent one for,
        // cleaned the way WordPress cleans it, so the editor shows that.
        if ($alt_state !== null) {
            $response['image_alts'] = (object) $alt_state;
        }

        // The other plugins' options as stored now, so a switch whose plugin
        // refused the change goes back to what the product really has.
        if ($plugin_opts_state) {
            $response['plugin_opts'] = (object) $plugin_opts_state;
        }

        // Non-fatal issues (duplicate SKU/GTIN…): the product saved, but some
        // values were rejected. Hand them back so the editor can tell the
        // merchant exactly what did not persist instead of a bare success.
        if (!empty($this->save_warnings)) {
            $response['warnings'] = array_values(array_unique($this->save_warnings));
        }

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
            // The attribute rows as persisted. An attribute the merchant typed
            // as a plain name may have just become a real taxonomy, and the
            // client is still holding the pre-promotion row: its axis key would
            // stay `kleur` while every variation above now says `pa_kleur`.
            // Handing back the saved rows lets the editor adopt the taxonomy
            // (and the canonical term names) without a page reload.
            $response['attributes'] = isset($fresh['attributes']) ? $fresh['attributes'] : [];
            // Which posted axis keys became which taxonomy. Nothing else in the
            // response can express this: an attribute that bound to an existing
            // global may come back under a different label than the merchant
            // typed, so the editor cannot pair the rows up by name.
            if (!empty($this->promoted_axis_map)) {
                $response['promoted_axes'] = $this->promoted_axis_map;
            }
            // Which key every posted axis was saved under. Most are unchanged;
            // the editor re-keys the ones that differ, or a name typed outside
            // ASCII (or with punctuation sanitize_title() drops) keeps a key
            // the returned variations no longer use.
            if (!empty($this->axis_key_map)) {
                $response['axis_keys'] = $this->axis_key_map;
            }
            // Only when a taxonomy was actually created: the client needs the
            // refreshed list so the new attribute's term typeahead is populated.
            // Skipped otherwise, since rebuilding it costs a get_terms() per
            // attribute and nothing changed.
            if (!empty($this->promoted_attributes)) {
                $response['global_attributes'] = $this->build_global_attributes_payload();
            }
            $fresh_extras = $variation_ids
                ? $this->capture_wc_variation_fields($final_product, $variation_ids)
                : [];
            // Same extras filter as the initial render so integrations surface
            // their per-variation fields on freshly generated/saved rows too.
            $response['variation_extras'] = apply_filters('brikpanel_pe_variation_extras', $fresh_extras, $final_product, $variation_ids, 'saved');
            // Whether the variations now sell from the product's shared stock:
            // the rows read it when the Inventory card is not on the page.
            $response['shared_stock'] = !empty($fresh['shared_stock']);
        }

        // The stored stock after the save, for the Inventory card: an
        // unchanged box kept the stored quantity (orders may have lowered it
        // since the page opened), so the box has to show that number now.
        if ($final_product) {
            $response['stock'] = [
                'manage' => true === $final_product->get_manage_stock('edit'),
                'qty'    => $final_product->get_stock_quantity('edit'),
            ];
        }

        // Blocksy's swatches editor works from the hidden attribute rows
        // (native_attribute_mirror_rows()). Values created by this save only
        // have term ids now, so hand back fresh rows; the editor swaps them in
        // when the hash changed and asks Blocksy to read them again. Asked for
        // only by a page that carries the rows. Outside the variable branch:
        // variable-like types (variable subscriptions) vary too.
        if (!empty($_POST['native_attr_mirror']) && $final_product) {
            $mirror_rows = self::native_attribute_mirror_rows($final_product);
            $response['native_attr_mirror'] = ['html' => $mirror_rows, 'hash' => md5($mirror_rows)];
        }

        // WPML: an original's new price, stock and variations reach its
        // translations, as after a save on WooCommerce's own edit screen.
        if (!empty($saved_id) && function_exists('brikpanel_wpml_sync_translations')) {
            brikpanel_wpml_sync_translations($saved_id);
        }

        $this->send_clean_json(true, $response);

        } catch (\Throwable $e) {
            // A hook fired during the save threw. The product may have been
            // partially persisted by WooCommerce before the throw, but there is
            // nothing safe we can add here — surface a clean, translatable error
            // so the editor reports it instead of a raw 500, and log the detail
            // for the site owner to diagnose which plugin is responsible.
            //
            // One thing we CAN undo: the product_type term is written before the
            // parent is saved, so a throw in between would leave the product
            // reading as a different type than it is while the merchant is told
            // nothing was saved. Put it back. (The variations a conversion drops
            // are deleted only after the save succeeds, so they are still here.)
            if (!empty($type_term_rollback) && !empty($product_id)) {
                wp_set_object_terms($product_id, $type_term_rollback, 'product_type', false);
            }
            if (function_exists('error_log')) {
                error_log('[BrikPanel] Product save aborted by an exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
            $this->send_clean_json(false, [
                'message' => __('The product could not be saved because another plugin raised an error during the save. Please disable or update that plugin (for example your media offload or SEO plugin) and try again.', 'brikpanel'),
            ]);
        }
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
     * Persist the merchant's value order for a global attribute so the
     * storefront dropdown actually follows it.
     *
     * WooCommerce never reads a product's own option order for a taxonomy
     * attribute. wc_dropdown_variation_attribute_options() re-sorts through
     * wc_get_product_terms(), and for an attribute set to "Custom ordering"
     * wc_change_pre_get_terms() turns that into `meta_value_num` over the
     * per-term `order` meta, with wc_terms_clauses() appending `, t.name` as
     * the tie-break (see woocommerce/includes/wc-term-functions.php:82-115).
     *
     * We never wrote that meta, so every term created here sat at 0, every row
     * tied, and the tie-break decided: a merchant who typed 6, 12, 24, 50, 100
     * got "Pack of 100, Pack of 12, Pack of 24, Pack of 50, Pack of 6" on the
     * product page. Writing the order closes that gap.
     *
     * Term order is site-wide (one attribute, one order, every product using
     * it), exactly as WooCommerce's own Products > Attributes > Configure terms
     * screen behaves. So this deliberately does NOT number the product's own
     * terms 1..N: two products sharing an attribute would then fight, each save
     * jumbling the other's order. Instead the product's terms are permuted
     * *within the slots they already occupy* in the taxonomy's current order,
     * which leaves every other term exactly where it was.
     *
     * @param string $taxonomy         pa_* taxonomy.
     * @param int[]  $ordered_term_ids Term IDs in the order the merchant arranged.
     */
    private function sync_attribute_term_order($taxonomy, array $ordered_term_ids) {
        if (!is_string($taxonomy) || $taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return;
        }
        if (!function_exists('wc_set_term_order') || !function_exists('taxonomy_is_product_attribute')) {
            return;
        }
        // Product attribute taxonomies ONLY. `order` is a shared meta key:
        // WooCommerce sorts product_cat through the very same key (see
        // woocommerce_sortable_taxonomies), so a malformed payload naming a
        // non-attribute taxonomy must never be able to reshuffle the store's
        // category order as a side effect of saving a product.
        if (!taxonomy_is_product_attribute($taxonomy)) {
            return;
        }
        // Reordering terms edits site-wide taxonomy data. Term *creation* is
        // already gated by can_create_product_terms(); this is the matching
        // gate for editing, and it is the capability the attribute taxonomies
        // register for `edit_terms`.
        if (!current_user_can('edit_product_terms')) {
            return;
        }

        $ordered_term_ids = array_values(array_unique(array_filter(array_map('intval', $ordered_term_ids))));
        if (count($ordered_term_ids) < 2) {
            // One value carries no order.
            return;
        }

        // The taxonomy's CURRENT effective order. get_terms() runs through
        // WooCommerce's own ordering filters, so this comes back in exactly the
        // order the storefront uses today.
        $all_ids = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);
        if (is_wp_error($all_ids) || !is_array($all_ids) || count($all_ids) < 2) {
            return;
        }
        $all_ids = array_map('intval', $all_ids);

        $mine = array_values(array_intersect($ordered_term_ids, $all_ids));
        if (count($mine) < 2) {
            return;
        }

        // Slot-preserving permutation: only the rows this product owns move,
        // and only into positions this product's terms already held.
        $slots = [];
        foreach ($all_ids as $pos => $tid) {
            if (in_array($tid, $mine, true)) {
                $slots[] = $pos;
            }
        }
        $new_order = $all_ids;
        foreach ($slots as $i => $pos) {
            $new_order[$pos] = $mine[$i];
        }

        // Nothing to do when the taxonomy already presents this product's
        // values in the posted order. Bailing here matters: writing the order
        // means numbering the taxonomy's WHOLE term list (an unnumbered term
        // reads as 0 and would jump to the front), so without this guard every
        // save of every product would rewrite dozens of term rows just to
        // restate an order that was already correct. It would also freeze the
        // alphabetical fallback that as-yet-unordered terms rely on, changing
        // where a future term lands. Only a genuine reorder pays that cost.
        if ($new_order === $all_ids) {
            return;
        }

        // Work out the writes before making any, so a pathologically large
        // taxonomy can bail out before touching the database.
        update_termmeta_cache($all_ids);
        $writes = [];
        foreach ($new_order as $i => $tid) {
            $want = $i + 1;
            $have = get_term_meta($tid, 'order', true);
            if ($have !== '' && (int) $have === $want) {
                continue;
            }
            $writes[$tid] = $want;
        }
        if (empty($writes)) {
            return;
        }

        // An attribute that has never been ordered needs its whole term list
        // numbered once. On a huge taxonomy (a Brand list with thousands of
        // terms) that would turn one product save into thousands of meta
        // writes, so leave it alone rather than stall the save. Ordinary
        // variation axes are far below this.
        if (count($writes) > self::MAX_TERM_ORDER_WRITES) {
            return;
        }

        foreach ($writes as $tid => $want) {
            wc_set_term_order($tid, $want, $taxonomy);
        }
        clean_term_cache(array_keys($writes), $taxonomy);
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

            // De-dupe by WooCommerce's key so the same attribute can't sneak
            // through twice from a malformed payload. strtolower() missed
            // "Цвет" / "цвет", which WooCommerce stores under one key, so the
            // later row silently replaced the earlier one.
            $key = brikpanel_attr_key($taxonomy ?: $name);
            if ($key === '' || isset($seen_names[$key])) continue;
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
                // Same contract as the variation axes: a spec attribute's value
                // order drives the storefront's Additional information table,
                // and WooCommerce reads that order off the terms.
                $this->sync_attribute_term_order($taxonomy, $term_ids);
                $attribute->set_id($attribute_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
            } else {
                $attribute->set_name($name);
                $attribute->set_options($values);
            }

            // WooCommerce's "Visible on the product page" flag, now driven by
            // the per-row switch in the attribute editor. A payload without the
            // key comes from a client older than this build (or a third party
            // posting the section), so it keeps the previous behaviour of
            // showing the attribute rather than silently hiding it.
            $attribute->set_visible(!isset($attr_data['visible']) || (bool) $attr_data['visible']);
            $attribute->set_variation(false);

            $attributes[] = $attribute;
        }

        return $attributes;
    }

    /**
     * Every global WC attribute with its terms, in the shape the editor's
     * attribute picker and term typeahead consume.
     *
     * Used both when rendering the page and, after a save that created a new
     * global attribute, to hand the client a refreshed list so the freshly
     * promoted attribute's terms are available without a reload.
     *
     * @return array[]
     */
    private function build_global_attributes_payload() {
        $global_attributes = [];
        foreach (wc_get_attribute_taxonomies() as $attr) {
            $taxonomy = wc_attribute_taxonomy_name($attr->attribute_name);
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
            $term_names = [];
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    // Same form as $build_attr_record() values (see there).
                    $term_names[] = brikpanel_term_ref($term->name);
                }
            }
            $global_attributes[] = [
                'id'       => (int) $attr->attribute_id,
                'name'     => $attr->attribute_label ?: $attr->attribute_name,
                'slug'     => $attr->attribute_name,
                'taxonomy' => $taxonomy,
                // WooCommerce's key for the attribute (see $build_attr_record):
                // a row picked from this list is keyed with it.
                'key'      => brikpanel_attr_key($taxonomy),
                'terms'    => $term_names,
                // Only "Custom ordering" (menu_order) makes the storefront honour
                // the per-term order we write on save. An attribute someone set
                // to sort by name/number ignores it, so the editor tells the
                // merchant instead of letting a drag silently do nothing.
                'orderby'  => $attr->attribute_orderby ?: 'menu_order',
            ];
        }
        return $global_attributes;
    }

    /**
     * Find an existing global WC attribute matching a merchant-typed name.
     *
     * Matching is case-insensitive across three axes, so the same attribute is
     * found however the merchant spells it: the attribute's own slug, its
     * display label, and the slug WooCommerce itself would derive from the
     * typed name. That last axis is what makes "Kleur " (trailing space) or
     * "Kleur!" resolve to an existing `pa_kleur` instead of being treated as a
     * brand-new attribute.
     *
     * Deliberately NOT capability-gated: binding a typed name to an attribute
     * the store already owns is the exact same outcome the editor's attribute
     * picker already produces for any user who can edit products.
     *
     * @param string $name Merchant-typed attribute name.
     * @return string Taxonomy name (e.g. `pa_kleur`), or '' when not found.
     */
    private function find_global_attribute_by_name($name) {
        $needle = brikpanel_strtolower(trim((string) $name));
        if ($needle === '') {
            return '';
        }
        // WooCommerce derives an attribute's slug with this exact function, so
        // comparing against it is what keeps us in step with wc_create_attribute().
        $derived = wc_sanitize_taxonomy_name($name);

        foreach (wc_get_attribute_taxonomies() as $attr) {
            $slug  = (string) $attr->attribute_name;
            $label = (string) ($attr->attribute_label ?: $attr->attribute_name);
            $slug  = brikpanel_strtolower($slug);
            $label = brikpanel_strtolower($label);
            if ($slug === $needle || $label === $needle || ($derived !== '' && $slug === $derived)) {
                return wc_attribute_taxonomy_name($attr->attribute_name);
            }
        }
        return '';
    }

    /**
     * Decide whether a merchant-typed name may become a BRAND-NEW global
     * attribute, and return the slug it would use.
     *
     * Every rejection here mirrors a failure mode of wc_create_attribute(), so
     * we never call that function expecting a WP_Error, plus a few guardrails
     * of our own. A rejected name is not an error: the caller simply leaves the
     * attribute custom, which is exactly what the merchant typed.
     *
     * @param string $name Merchant-typed attribute name.
     * @return string Slug without the `pa_` prefix, or '' to skip promotion.
     */
    private function validate_new_global_attribute_slug($name) {
        // Creating a site-wide taxonomy is a store-management action.
        if (!current_user_can('manage_woocommerce')) {
            return '';
        }
        // Creating the taxonomy without being able to create its terms would
        // leave the attribute with ZERO values and every variation pointing at
        // a term that does not exist. Staying custom keeps the data intact.
        if (!$this->can_create_product_terms()) {
            return '';
        }

        $label = trim((string) $name);
        $len   = brikpanel_strlen($label);
        // A single character is a typo, not an axis worth a site-wide taxonomy.
        if ($len < 2) {
            return '';
        }

        $slug = wc_sanitize_taxonomy_name($label);
        // A name made only of punctuation sanitizes away to nothing.
        if ($slug === '') {
            return '';
        }
        // WooCommerce measures this limit in BYTES, so match it exactly rather
        // than being stricter (a character count) and rejecting names WC would accept.
        if (strlen($slug) > 28) {
            return '';
        }
        if (function_exists('wc_check_if_attribute_name_is_reserved') && wc_check_if_attribute_name_is_reserved($slug)) {
            return '';
        }
        // Already a registered taxonomy. Either find_global_attribute_by_name()
        // has already bound to it, or it is a taxonomy WooCommerce does not
        // know about (registered by a third party, or its attribute row was
        // deleted). In that second case wc_attribute_taxonomy_id_by_name()
        // returns 0, and an attribute with id 0 but a `pa_` name is not a
        // taxonomy attribute at all — the product would store a custom
        // attribute literally named "pa_kleur". Staying custom is correct.
        if (taxonomy_exists(wc_attribute_taxonomy_name($slug))) {
            return '';
        }
        // "2024" or "42" is an attribute VALUE someone typed in the wrong box.
        if (ctype_digit($slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * Create a global WC attribute for an arbitrary merchant-typed name,
     * register the taxonomy for the current request so wp_insert_term() /
     * taxonomy_exists() work immediately, and return the taxonomy name
     * (e.g. `pa_kleur`). Returns '' on any failure.
     *
     * @param string $display_label Merchant-typed attribute name.
     * @return string
     */
    private function create_global_attribute_taxonomy($display_label) {
        $slug = $this->validate_new_global_attribute_slug($display_label);
        if ($slug === '') {
            return '';
        }
        $label = trim((string) $display_label);

        $attribute_id = wc_create_attribute([
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        // Belt and braces: validate_new_global_attribute_slug() already ruled
        // out every documented failure mode, so this only fires on a DB error
        // or a third-party veto. Log it so a site owner asking "why did my
        // attribute not become global" has something concrete to look at.
        if (is_wp_error($attribute_id) || !$attribute_id) {
            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[BrikPanel] Could not create the global attribute "%s": %s',
                    $label,
                    is_wp_error($attribute_id) ? $attribute_id->get_error_message() : 'unknown error'
                ));
            }
            return '';
        }

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

        // Redundant since WooCommerce clears both the transient AND the
        // `woocommerce-attributes` object-cache group inside wc_create_attribute(),
        // but harmless and it documents the requirement: without a fresh list,
        // wc_attribute_taxonomy_id_by_name() below would return 0.
        delete_transient('wc_attribute_taxonomies');

        // register_taxonomy() is not enough on its own. wc_attribute_label()
        // resolves a label through taxonomy_is_product_attribute(), which tests
        // the `$wc_product_attributes` global — and WooCommerce fills that once,
        // on `init`, from the attribute list as it was then. A taxonomy created
        // mid-request is missing from it, so wc_attribute_label('pa_kleur')
        // falls through and returns the raw taxonomy key. Everything reading a
        // label later in this request (the attribute rows echoed back in the
        // save response, most visibly) would then show "pa_kleur" where the
        // merchant typed "Kleur". Register the row the same way WooCommerce's
        // own WC_Post_Types::register_taxonomies() does.
        $row = null;
        foreach (wc_get_attribute_taxonomies() as $candidate) {
            if ((int) $candidate->attribute_id === (int) $attribute_id) {
                $row = $candidate;
                break;
            }
        }
        if ($row) {
            $row->attribute_public = absint(isset($row->attribute_public) ? $row->attribute_public : 0);
            if (!isset($GLOBALS['wc_product_attributes']) || !is_array($GLOBALS['wc_product_attributes'])) {
                $GLOBALS['wc_product_attributes'] = [];
            }
            $GLOBALS['wc_product_attributes'][$taxonomy] = $row;
        }

        return $taxonomy;
    }

    /**
     * Turn a merchant-typed attribute name into a global attribute taxonomy:
     * bind to one the store already has, or create it.
     *
     * Returns '' to mean "leave this attribute custom" — for a missing
     * capability, a name WooCommerce would reject, or the per-save creation
     * ceiling. Never surfaces an error: a product save must not fail because
     * an attribute could not be promoted.
     *
     * @param string $name Merchant-typed attribute name.
     * @return string Taxonomy name (e.g. `pa_kleur`), or ''.
     */
    private function promote_attribute_to_global($name) {
        // Only ever promote to a slug that survives WordPress's own
        // normalisation unchanged. wc_sanitize_taxonomy_name() keeps non-Latin
        // characters raw while sanitize_title() percent-encodes them, and
        // WooCommerce uses BOTH: a taxonomy like `pa_renk-🔴` gets its
        // variation meta written under the raw spelling and read back under
        // the encoded one, so every variation reports an empty value even
        // though the database holds the right one. An attribute left custom
        // keeps working, so that is the safer answer for these names.
        $derived = wc_sanitize_taxonomy_name($name);
        if ($derived === '' || sanitize_title($derived) !== $derived) {
            return '';
        }

        $matched = $this->find_global_attribute_by_name($name);
        if ($matched !== '') {
            // The same rule applies to an attribute we merely bind to: its own
            // slug can be one the store created by hand, and matching happens
            // on the label too, so a safe name can still point at an unsafe
            // taxonomy.
            $matched_slug = (string) substr($matched, 3);
            if (sanitize_title($matched_slug) !== $matched_slug) {
                return '';
            }
            return taxonomy_exists($matched) ? $matched : '';
        }

        /**
         * Ceiling on how many global attributes a single save may create. Real
         * products use a handful of axes; a tampered payload would otherwise
         * spawn one site-wide taxonomy per row.
         *
         * @param int $max Maximum new global attributes per save request.
         */
        $max_new = (int) apply_filters('brikpanel_pe_max_new_global_attributes', 3);
        if ($max_new > 0 && $this->promoted_this_save >= $max_new) {
            return '';
        }

        $created = $this->create_global_attribute_taxonomy($name);
        if ($created !== '' && taxonomy_exists($created)) {
            $this->promoted_this_save++;
            $this->promoted_attributes[] = $created;
            return $created;
        }
        return '';
    }

    /**
     * Run third-party save hooks without letting one of them abort the request.
     *
     * Plugins that render their own fields inside WooCommerce's native product
     * or variation metabox commonly re-verify that metabox's nonce from their
     * save handler — WooCommerce Product Variation Gallery, for example, calls
     * `check_ajax_referer()` inside `woocommerce_save_product_variation`. Our
     * editor posts its own nonce, not theirs, so that check fails and the
     * plugin calls `wp_die()`.
     *
     * `wp_die()` is not a Throwable, so the try/catch that wraps the save body
     * cannot see it: the request simply ends. Dispatching per-variation hooks
     * inside a loop makes that especially damaging — the first variation is
     * written, the request dies on its hook, and every later variation is
     * silently left unsaved while the editor shows no error.
     *
     * For the duration of the dispatch we swap the wp_die handlers for ones
     * that throw, so a bailing third party loses only its own field write. By
     * this point everything BrikPanel owns is already persisted; the plugin
     * that bailed is the only thing that misses out, and it is logged.
     *
     * @param callable $dispatch Closure performing the do_action() calls.
     * @param string   $context  Short label used in the error log.
     * @return void
     */
    private function dispatch_foreign_hooks(callable $dispatch, $context = '') {
        $to_exception = static function () {
            return static function ($message = '', $title = '', $args = []) {
                throw new \RuntimeException(
                    'wp_die() from a third-party save handler: '
                    . ( is_scalar($message) ? (string) $message : gettype($message) )
                );
            };
        };

        add_filter('wp_die_ajax_handler', $to_exception, PHP_INT_MAX);
        add_filter('wp_die_handler',      $to_exception, PHP_INT_MAX);

        try {
            $dispatch();
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[BrikPanel] Third-party handler aborted during %s: %s',
                $context !== '' ? $context : 'product save',
                $e->getMessage()
            ));
        } finally {
            remove_filter('wp_die_ajax_handler', $to_exception, PHP_INT_MAX);
            remove_filter('wp_die_handler',      $to_exception, PHP_INT_MAX);
        }
    }

    /**
     * A variation's attribute values exactly as they are stored.
     *
     * WC_Product_Variation::get_attributes() is not a plain read. It goes
     * through wc_get_product_variation_attributes(), which cross-checks every
     * value against the PARENT's attribute list and silently drops anything
     * whose parent axis lost its "used for variations" flag or its option
     * list. An importer or ERP push that rewrites the parent that way makes
     * WooCommerce report "no attributes" for rows that are still on disk —
     * which is what the merchant sees as "Any Color / Any Size".
     *
     * At that point the data is still recoverable, and reading the meta keeps
     * it that way. Reading through the object instead would hand the save path
     * a false emptiness, and WC_Product_Variation_Data_Store_CPT::
     * update_attributes() deletes every `attribute_*` row it is not given —
     * turning a repairable import glitch into permanent loss.
     *
     * Keys come back in WooCommerce's own form (brikpanel_attr_key()). Rows
     * stored under another spelling of the same axis, such as the raw
     * `attribute_pa_вид` that releases up to 3.3.34 wrote for a non-ASCII
     * taxonomy (WooCommerce reads `attribute_pa_%d0%b2…` and never saw them),
     * are folded into it; when both spellings exist the one WooCommerce reads
     * wins. The next save that writes the variation's attributes stores the
     * value under WooCommerce's key and drops the other row.
     *
     * @param int $variation_id Variation post ID.
     * @return array<string,string> Axis key (no `attribute_` prefix) => stored value.
     */
    private function read_variation_attribute_meta($variation_id) {
        $variation_id = (int) $variation_id;
        if ($variation_id <= 0) {
            return [];
        }
        $stored = [];
        $other_spelling = [];
        foreach ((array) get_post_meta($variation_id) as $meta_key => $meta_values) {
            if (strpos($meta_key, 'attribute_') !== 0) {
                continue;
            }
            $suffix = substr($meta_key, strlen('attribute_'));
            if ($suffix === '' || $suffix === false) {
                continue;
            }
            $axis_key = brikpanel_attr_key($suffix);
            if ($axis_key === '') {
                continue;
            }
            $value = is_array($meta_values) ? reset($meta_values) : $meta_values;
            $value = is_scalar($value) ? (string) $value : '';
            if ($axis_key === $suffix) {
                $stored[$axis_key] = $value;
            } elseif (!isset($other_spelling[$axis_key])) {
                $other_spelling[$axis_key] = $value;
            }
        }
        return $stored + $other_spelling;
    }

    /**
     * Measurement Price Calculator's per-variation fields (Area, Volume,
     * Minimum Price).
     *
     * The plugin does not save them on woocommerce_save_product_variation but on
     * woocommerce_ajax_save_product_variations, walking the native form's
     * `variable_post_id[<loop>]` list and bailing unless `variable_sku` was
     * posted. BrikPanel's form has neither, so these fields never saved. That
     * hook is not fired generically here: other listeners on it read the whole
     * native variation form and would clear what this form does not carry.
     *
     * The handler deletes a value whose input is missing, so any loop the form
     * did not render (a field that failed to print) is filled from the stored
     * meta first: a form that never showed a field must not clear it.
     *
     * @param array<int,int> $loop_to_variation Submitted loop index => variation ID.
     */
    private function save_measurement_calculator_variation_fields(array $loop_to_variation) {
        if (!$loop_to_variation || !function_exists('wc_measurement_price_calculator_process_product_meta_variable')) {
            return;
        }

        $fields = [
            'variable_area'      => '_area',
            'variable_volume'    => '_volume',
            'variable_min_price' => '_wc_measurement_price_calculator_min_price',
        ];
        $saved_post = [];
        foreach (array_merge(array_keys($fields), ['variable_post_id', 'variable_sku']) as $key) {
            $saved_post[$key] = array_key_exists($key, $_POST) ? $_POST[$key] : null;
        }

        foreach ($fields as $key => $meta_key) {
            $posted = isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
            foreach ($loop_to_variation as $loop => $vid) {
                if (!array_key_exists($loop, $posted)) {
                    $posted[$loop] = (string) get_post_meta($vid, $meta_key, true);
                }
            }
            $_POST[$key] = $posted;
        }
        $_POST['variable_post_id'] = $loop_to_variation;
        if (!isset($_POST['variable_sku'])) {
            $_POST['variable_sku'] = array_fill_keys(array_keys($loop_to_variation), '');
        }

        $this->dispatch_foreign_hooks(
            static function () {
                wc_measurement_price_calculator_process_product_meta_variable(0);
            },
            'Measurement Price Calculator variation fields'
        );

        foreach ($saved_post as $key => $value) {
            if ($value === null) {
                unset($_POST[$key]);
            } else {
                $_POST[$key] = $value;
            }
        }
    }

    private function save_variations($product, $post_data) {
        // The client posts `variations` whenever the table is on the page, even
        // when it is empty. An absent key therefore means the editor never
        // rendered the variation table at all (the section is switched off in
        // settings), which is not a request to delete anything — and the
        // "delete removed variations" sweep further down would otherwise wipe
        // every child of the product. Follow the same "section not posted →
        // leave it alone" convention the description/GTIN fields use.
        if (!isset($post_data['variations'])) {
            return;
        }
        $attributes_json = isset($post_data['attributes']) ? wp_unslash($post_data['attributes']) : '[]';
        $variations_json = wp_unslash($post_data['variations']);

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

        // A WPML translation's variations take their prices and sale dates from
        // the original product's (WooCommerce Multilingual copies them): the
        // editor shows those boxes locked, and posted values are left unsaved.
        $price_locked = function_exists('brikpanel_wpml_original_of') && brikpanel_wpml_original_of($product->get_id());

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

        // "Default Form Values" — the option pre-selected on the storefront.
        // Built alongside the attributes below (so promotions/taxonomy resolve
        // once) and written via set_default_attributes() before the parent save.
        // Rebuilt fresh every save: an all-blank selection clears prior defaults.
        $default_attributes = [];

        // Whether a typed-in attribute becomes a real global attribute. Two
        // gates, both deliberate:
        //   - the merchant's setting (see WooCommerce → Settings → BrikPanel →
        //     Products). Default on: global attributes are what swatch themes,
        //     layered nav and attribute filters read, so most stores want them.
        //   - status. We never create site-wide taxonomies or terms while
        //     saving a draft, only when the product is going live. WooCommerce
        //     has no bulk delete for attributes, so every one created from a
        //     half-finished draft is a manual cleanup later. `password` is a
        //     virtual status the outer handler converts to publish +
        //     post_password (see ~line 2233).
        // The status this save actually writes counts too: a "publish" from
        // someone who may only send products in for review is saved as
        // pending, which is not going live (ajax_save_product() sets it).
        $current_status = sanitize_key($post_data['status'] ?? 'draft');
        $auto_global = get_option('brikpanel_pe_auto_global_attributes', 'yes') === 'yes'
            && in_array($current_status, ['publish', 'private', 'password'], true)
            && ('' === $this->save_status || in_array($this->save_status, ['publish', 'private', 'future'], true));

        // Maps the variation's old custom slug (e.g. `kleur`) to the new
        // taxonomy (`pa_kleur`) once an attribute is promoted. We rewrite
        // variation attribute keys with this map below so existing variations
        // re-edited from the editor stay linked to the right attribute.
        $slug_remap = [];

        // Each posted attribute row's spellings of its axis key (the client's
        // key verbatim and cleaned, the name for a typed attribute) mapped to
        // the key the axis is saved under. Feeds $axis_alias below.
        $posted_axis_aliases = [];

        // The product's own option lists and its children's stored values, both
        // read before anything is written. They are the evidence used below to
        // tell a merchant who cleared an attribute's tags apart from an import
        // that emptied them behind the merchant's back — see the block that
        // handles an axis arriving with no values.
        // Every axis is keyed the way WooCommerce keys it (brikpanel_attr_key():
        // `pa_%d0%b2%d0%b8%d0%b4` for the taxonomy `pa_вид`), which is also how
        // the variations' meta and the children's values below are keyed. The
        // raw taxonomy name only goes to taxonomy functions.
        $stored_attribute_options = [];
        foreach ($product->get_attributes() as $stored_attr) {
            $stored_attr_key = brikpanel_attr_key($stored_attr->get_name());
            if ($stored_attr_key !== '') {
                $stored_attribute_options[$stored_attr_key] = (array) $stored_attr->get_options();
            }
        }
        $child_axis_values = [];
        $existing_child_ids = brikpanel_variation_ids($product);
        // One query for the whole set instead of one per child: the reads below
        // and the per-variation reads in the save loop then all come from cache.
        if (!empty($existing_child_ids)) {
            update_meta_cache('post', $existing_child_ids);
        }
        foreach ($existing_child_ids as $existing_child_id) {
            foreach ($this->read_variation_attribute_meta($existing_child_id) as $child_axis => $child_value) {
                if ($child_value === '') {
                    continue;
                }
                $child_axis_values[$child_axis][$child_value] = true;
            }
        }

        foreach ($attributes_data as $attr_data) {
            $name   = sanitize_text_field($attr_data['name'] ?? '');
            $values = array_map('sanitize_text_field', (array) ($attr_data['values'] ?? []));
            $taxonomy = sanitize_text_field($attr_data['taxonomy'] ?? '');

            if (empty($name)) continue;

            if (empty($values)) {
                // An axis arrives with no values for two opposite reasons.
                //
                // The merchant may have cleared its tags in this editor. Then
                // the option list stored on the product is still populated, and
                // dropping the axis is exactly what was asked for — the old
                // behaviour, kept.
                //
                // Or an importer / ERP push may have emptied the parent's
                // option list while every variation kept its value. WooCommerce
                // then hides those values (see read_variation_attribute_meta())
                // and the editor can only render an axis with no tags. Dropping
                // it here would take the axis off the product as well, deleting
                // its last record of what the variations are — the product ends
                // up variable, with children, and no attributes at all.
                //
                // Tell them apart by what is on disk: an empty stored list plus
                // children that still use the axis is the import case, and the
                // option list gets rebuilt from those children.
                //
                // The product must already carry the attribute for this to be a
                // repair at all. Without that check, typing a NEW attribute name
                // and saving before adding any values would pick up whatever
                // leftover `attribute_<name>` rows an earlier, deleted attribute
                // of the same name had left on the variations — resurrecting a
                // value nobody chose, which is the exact failure 3.2.44 closed.
                $probe_key = brikpanel_attr_key($taxonomy !== '' ? $taxonomy : $name);
                $client_key = brikpanel_attr_key_clean($attr_data['key'] ?? '');
                if (!array_key_exists($probe_key, $stored_attribute_options)) continue;
                $stored_options = array_filter(
                    $stored_attribute_options[$probe_key],
                    function ($opt) { return $opt !== '' && $opt !== null; }
                );
                $recovered = [];
                if (empty($stored_options)) {
                    foreach ([$probe_key, $client_key] as $axis_probe) {
                        if ($axis_probe !== '' && !empty($child_axis_values[$axis_probe])) {
                            $recovered = array_keys($child_axis_values[$axis_probe]);
                            break;
                        }
                    }
                }
                if (empty($recovered)) continue;
                // Stored taxonomy values are term slugs; the rest of this loop
                // works in display names, so translate before feeding them in.
                if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                    $recovered = array_map(function ($slug) use ($taxonomy) {
                        $term = get_term_by('slug', $slug, $taxonomy);
                        return ($term && !is_wp_error($term)) ? $term->name : $slug;
                    }, $recovered);
                }
                $values = array_map('sanitize_text_field', $recovered);
            }

            // Skip a second row for the same axis. Keyed by taxonomy when set,
            // otherwise by the name, both the way WooCommerce keys them, so
            // "Size" and "Size " collapse.
            $dedupe_key = brikpanel_attr_key($taxonomy !== '' ? $taxonomy : $name);
            if ($dedupe_key === '' || isset($seen_var_names[$dedupe_key])) continue;
            $seen_var_names[$dedupe_key] = true;

            // The key this row's variations were posted under: whatever the
            // client derived (its own slug, a raw taxonomy name, WooCommerce's
            // key), kept verbatim so the reply can tell the client which key
            // the axis was saved under (axis_keys).
            $posted_key = (isset($attr_data['key']) && is_scalar($attr_data['key']) && strlen((string) $attr_data['key']) <= 600)
                ? (string) $attr_data['key']
                : '';
            $client_key = brikpanel_attr_key_clean($posted_key);
            $was_promoted = false;

            // Promote a typed-in attribute to a real global taxonomy: bind to
            // one the store already has, or create it. Only reached when the
            // merchant typed a new name — picking an existing attribute from
            // the editor's picker already arrives with `taxonomy` filled in.
            if (empty($taxonomy) && $auto_global) {
                /**
                 * Veto auto-promotion of one specific attribute name.
                 *
                 * @param bool       $promote Whether to promote this attribute.
                 * @param string     $name    Merchant-typed attribute name.
                 * @param WC_Product $product Product being saved.
                 */
                if (apply_filters('brikpanel_pe_promote_attribute', true, $name, $product)) {
                    $matched = $this->promote_attribute_to_global($name);
                    // Two rows can resolve to the SAME attribute. On a store
                    // whose attribute label and slug disagree (slug `colour`,
                    // label `Kleur`), typing either spelling binds to it, and
                    // the dedupe above cannot see that because it keys on the
                    // typed names, which differ. So can a row picked from the
                    // combo plus a typed one that resolves to the same place.
                    // WC keys attributes by name, so letting both through means
                    // the second silently takes the first's slot: its values are
                    // lost and every variation collapses onto one axis. Leave
                    // the later row custom instead — both axes survive, nothing
                    // is overwritten, and the product still says what the
                    // merchant typed.
                    if ($matched !== '' && isset($seen_var_names[brikpanel_attr_key($matched)])) {
                        $matched = '';
                    }
                    if ($matched !== '') {
                        $seen_var_names[brikpanel_attr_key($matched)] = true;
                        // Register every spelling of the old axis key. The
                        // client derives it with its own slugify(), which
                        // disagrees with sanitize_title() outside ASCII:
                        // remove_accents() turns "Größe" into `grose` while
                        // the JS produces `gro-e`. Keying on only one of them
                        // would leave every variation of such an attribute
                        // unmatched, and the axis would be dropped below.
                        $old_keys = array_unique(array_filter([
                            brikpanel_attr_key($name),
                            $client_key,
                            $posted_key,
                        ], 'strlen'));
                        foreach ($old_keys as $old_key) {
                            if ($old_key !== $matched) {
                                $slug_remap[$old_key] = brikpanel_attr_key($matched);
                            }
                            // Same map, but it outlives this function: the AJAX
                            // response uses it to tell the editor which of its
                            // rows just became a taxonomy.
                            $this->promoted_axis_map[$old_key] = $matched;
                        }
                        $taxonomy = $matched;
                        $was_promoted = true;
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
                // The row order the merchant arranged is the storefront's
                // dropdown order. WooCommerce keeps that on the terms, not on
                // the product, so it has to be written through.
                $this->sync_attribute_term_order($taxonomy, $term_ids);
                $attribute->set_id($attribute_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options($term_ids);
            } else {
                $attribute->set_name($name);
                $attribute->set_options($values);
            }

            $attribute->set_position($position++);
            // Same contract as the spec attributes above. A variation axis may
            // legitimately be hidden from the Additional information table while
            // still driving the variation dropdowns — that is WooCommerce's own
            // behaviour, and forcing it back on here used to undo the merchant's
            // choice on every save.
            $attribute->set_visible(!isset($attr_data['visible']) || (bool) $attr_data['visible']);
            $attribute->set_variation(true);

            $wc_attributes[] = $attribute;

            // The key this axis is saved under, and the spellings of it the
            // client may have used for its variations: its own key verbatim,
            // that key cleaned, and for an attribute typed by name the name
            // WooCommerce-keyed (a promoted one maps from its old custom key
            // to the taxonomy). A row picked as a taxonomy adds no name
            // spelling: its name is a label that can equal another axis.
            $axis_canonical = brikpanel_attr_key($attribute->get_name());
            $record_spellings = [$posted_key, $client_key];
            if (!$attribute->is_taxonomy() || $was_promoted) {
                $record_spellings[] = brikpanel_attr_key($name);
            }
            foreach ($record_spellings as $spelling) {
                if ($spelling !== '' && !isset($posted_axis_aliases[$spelling])) {
                    $posted_axis_aliases[$spelling] = $axis_canonical;
                }
            }
            if ($posted_key !== '' && $axis_canonical !== '') {
                $this->axis_key_map[$posted_key] = $axis_canonical;
            }

            // Capture this axis's default selection. JS sends a display NAME;
            // store the term SLUG for taxonomy axes (what WC's frontend default
            // lookup compares against) and the raw value for custom ones, keyed
            // exactly like WC core (brikpanel_attr_key() of the taxonomy or the
            // label) and using the post-promotion $taxonomy so a just-promoted
            // custom Color maps to pa_color, not the old slug. The slug lookup
            // reads the value as posted: sanitize_text_field() deletes the
            // `%xx` octets an encoded slug is made of.
            $default_raw = (isset($attr_data['default']) && is_scalar($attr_data['default'])) ? (string) $attr_data['default'] : '';
            $default_val = sanitize_text_field($default_raw);
            if ($default_raw !== '' && $axis_canonical !== '') {
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $def_term = $default_val !== '' ? get_term_by('name', $default_val, $taxonomy) : false;
                    if (!$def_term) {
                        $def_term = get_term_by('slug', sanitize_title($default_raw), $taxonomy);
                    }
                    $default_attributes[$axis_canonical] = $def_term ? $def_term->slug : sanitize_title($default_raw);
                } elseif ($default_val !== '') {
                    $default_attributes[$axis_canonical] = $default_val;
                }
            }
        }

        // Append preserved non-variation attributes after variation ones —
        // positions are renumbered so WC's position-sort stays stable.
        //
        // A spec must never land on a key a variation axis already holds.
        // WC_Product::set_attributes() keys by name, so a spec sharing a key
        // would replace the axis and, because it is flagged variation=false,
        // silently stop the product from varying on it. Reachable whenever a
        // typed axis resolves to the same taxonomy a spec row was picked from.
        // The variation axis wins: it is the one the variation rows depend on.
        $taken_attribute_keys = [];
        foreach ($wc_attributes as $axis_attr) {
            $taken_attribute_keys[brikpanel_attr_key($axis_attr->get_name())] = true;
        }
        foreach ($existing_non_variation as $preserved) {
            $preserved_key = brikpanel_attr_key($preserved->get_name());
            if ($preserved_key === '' || isset($taken_attribute_keys[$preserved_key])) {
                continue;
            }
            $taken_attribute_keys[$preserved_key] = true;
            $preserved->set_position($position++);
            $wc_attributes[] = $preserved;
        }

        $product->set_attributes($wc_attributes);
        $product->save();

        // WooCommerce's shared stock, as ajax_save_product() just stored it:
        // the product counts one quantity and every variation that counts no
        // stock of its own sells from it. Such a row must not get a manual
        // stock status: WooCommerce copies the product's status down to it,
        // and writing "instock" first fired a false back-in-stock event.
        $parent_shares = (true === $product->get_manage_stock('edit'));
        // What the rows below actually do, rather than the children read
        // before them (that list still has rows about to be deleted and
        // lacks the new ones): drives the leftover check after the loop.
        $saved_rows       = 0;
        $any_row_follows  = false;

        // The axis keys a variation is allowed to carry, keyed exactly the way
        // the per-variation loop below normalises them. Removing an attribute
        // row does not rebuild the variation table, so the client keeps posting
        // the axis it no longer shows — without this the variation kept an
        // `attribute_<deleted>` meta row forever, dead data that would silently
        // start matching again if an attribute of that name were ever re-added.
        // Left empty (so the filter is a no-op) when no variation axis survived,
        // rather than stripping every attribute off every variation.
        $allowed_axis_keys = [];
        // WooCommerce's key of each axis => its taxonomy ('' for a custom one),
        // for the term lookups below: the key of `pa_вид` is not a taxonomy name.
        $axis_taxonomy = [];
        foreach ($wc_attributes as $axis_attr) {
            if (!$axis_attr->get_variation()) {
                continue;
            }
            $axis_key = brikpanel_attr_key($axis_attr->get_name());
            if ($axis_key === '') {
                continue;
            }
            $allowed_axis_keys[$axis_key] = true;
            $axis_taxonomy[$axis_key] = ($axis_attr->is_taxonomy() && taxonomy_exists($axis_attr->get_name()))
                ? $axis_attr->get_name()
                : '';
        }

        // Every spelling a posted variation may use for an axis, mapped to the
        // key the axis is saved under. The client keys its rows its own way
        // (a slug of the typed name, a raw taxonomy name such as `pa_вид`,
        // WooCommerce's key when the server gave it one), and a variation it
        // generated carries that key while one it loaded carries WooCommerce's.
        // First match wins, in this order: the axes' own keys, then each
        // posted row's spellings (a promoted row's old key points at its new
        // taxonomy, like $slug_remap), then the raw taxonomy names.
        $axis_alias = [];
        foreach ($allowed_axis_keys as $axis_key => $unused) {
            $axis_alias[$axis_key] = $axis_key;
        }
        foreach ($posted_axis_aliases as $spelling => $axis_key) {
            if (!isset($axis_alias[$spelling])) {
                $axis_alias[$spelling] = $axis_key;
            }
        }
        foreach ($axis_taxonomy as $axis_key => $axis_tax) {
            if ($axis_tax !== '' && !isset($axis_alias[$axis_tax])) {
                $axis_alias[$axis_tax] = $axis_key;
            }
        }
        $resolve_axis_key = static function ($raw_key) use ($axis_alias) {
            $raw_key = is_scalar($raw_key) ? (string) $raw_key : '';
            if ($raw_key === '' || strlen($raw_key) > 600) {
                return '';
            }
            if (isset($axis_alias[$raw_key])) {
                return $axis_alias[$raw_key];
            }
            $key = brikpanel_attr_key($raw_key);
            return $axis_alias[$key] ?? $key;
        };

        // Every attribute the product still has, axis or spec. The filter above
        // answers "may the payload write this key"; this answers the different
        // question "does this key still mean something on this product", which
        // is what decides whether a stored value the payload never mentioned is
        // dead weight or data worth keeping. An attribute the merchant actually
        // deleted is in neither list, so the phantom-meta sweep still works.
        $known_axis_keys = $allowed_axis_keys;
        foreach ($wc_attributes as $known_attr) {
            $known_key = brikpanel_attr_key($known_attr->get_name());
            if ($known_key !== '') {
                $known_axis_keys[$known_key] = true;
            }
        }

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
        //
        // Only when the editor sent them. With the "Digital product" section
        // hidden the switches are not on screen and the keys are absent: each
        // variation keeps its own flags and files, and a new variation takes
        // what the variations that stay have in common ($new_row_flags,
        // resolved on the first new row).
        $flags_sent = array_key_exists('is_virtual', $post_data) || array_key_exists('is_downloadable', $post_data);
        $parent_is_downloadable = !empty($post_data['is_downloadable']);
        $parent_is_virtual      = !empty($post_data['is_virtual']) || $parent_is_downloadable;
        $parent_download_objects = [];
        $new_row_flags = null;
        if ($flags_sent && $parent_is_downloadable) {
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
        // What the switches and the file list showed for the variations that
        // exist: get_product_data() draws them from variation_flags_summary(),
        // so a switch is on only when EVERY variation has the flag and the list
        // holds the files they all share (it opens empty when theirs differ).
        // A posted switch that still says what it showed was not touched and
        // is not written onto the variations. On a product whose variations
        // differ ("Paperback" ships, "E-book" downloads) both switches open
        // off, and a plain Update used to turn every variation physical and
        // delete the e-book's files. Digital on locks Virtual on in the editor
        // (syncVirtualLock()), so Virtual reads as shown when it equals "all
        // virtual, or all downloadable". A switch the merchant flipped still
        // applies to every variation, and so does an edited file list.
        $shown_flags = ($flags_sent && !empty($existing_child_ids))
            ? $this->variation_flags_summary($existing_child_ids)
            : null;
        if (null !== $shown_flags) {
            $apply_virtual      = $parent_is_virtual !== ($shown_flags['virtual'] || $shown_flags['downloadable']);
            $apply_downloadable = $parent_is_downloadable !== $shown_flags['downloadable'];
            // null: the list opened empty, because the variations' files differ
            // or not all of them are downloadable.
            $shown_files   = $shown_flags['downloadable'] ? $shown_flags['downloads'] : null;
            $files_changed = $parent_is_downloadable
                && !$this->same_download_files($parent_download_objects, (array) $shown_files);
        } else {
            $apply_virtual = $apply_downloadable = $flags_sent;
            $shown_files   = null;
            $files_changed = $flags_sent && $parent_is_downloadable;
        }

        // Track submitted variation IDs. `$loop_index` mirrors WC's own
        // variation-metabox loop counter — 3rd-party per-variation save
        // handlers read `$_POST['field_name'][$loop]` so the index here must
        // match the one used when the fields were originally rendered.
        $submitted_ids = [];
        $loop_to_variation = [];
        $loop_index = -1;

        foreach ($variations_data as $var_data) {
            $loop_index++;
            $var_id = intval($var_data['id'] ?? 0);

            if ($var_id) {
                $variation = wc_get_product($var_id);
                // A variation of ANOTHER product is not edited from here: the
                // capability check covered this product only. It is answered
                // like an id that is not a variation at all, with a new row.
                if (!$variation || !$variation->is_type('variation')
                    || (int) $variation->get_parent_id() !== (int) $product->get_id()) {
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
            // Axes the payload actually had an opinion about. An axis in here
            // was decided by the merchant — including a deliberate empty value,
            // which is WooCommerce's "Any …". An axis NOT in here was never
            // shown to them, so its stored value is carried forward below
            // instead of being taken as a request to erase it.
            $posted_axis_keys = [];
            // Read from the RESOLVED variation, not from the posted id. A
            // payload can name an id that is not a variation of this product;
            // the block above answers that with a brand-new variation, and
            // carrying meta over from whatever that id pointed at would attach
            // a foreign post's values to it.
            $resolved_var_id  = (int) $variation->get_id();
            $stored_var_attrs = ($resolved_var_id > 0 && (int) $variation->get_parent_id() === (int) $product->get_id())
                ? $this->read_variation_attribute_meta($resolved_var_id)
                : [];
            if (isset($var_data['attributes']) && is_array($var_data['attributes'])) {
                // Which axes this row posted under the key they are saved
                // under. When a row carries one axis twice (a loaded variation
                // still holding an old spelling next to WooCommerce's), the
                // value under WooCommerce's spelling wins.
                $posted_as_saved_key = [];
                foreach ($var_data['attributes'] as $key => $val) {
                    // Resolved before anything is cleaned: sanitize_text_field()
                    // deletes the `%xx` octets a non-ASCII key and term slug are
                    // made of (`pa_%d0%b2…` became `pa_`, its slug ''), which is
                    // how every value of such an attribute was lost on save. The
                    // alias map also folds a promoted attribute's old key onto
                    // its taxonomy (what $slug_remap did here) and the client's
                    // own spellings onto WooCommerce's key.
                    $raw_key  = is_scalar($key) ? (string) $key : '';
                    $axis_key = $resolve_axis_key($raw_key);
                    if ($axis_key === '') {
                        continue;
                    }
                    $as_saved_key = ($raw_key === $axis_key);
                    if (isset($posted_axis_keys[$axis_key]) && !$as_saved_key && !empty($posted_as_saved_key[$axis_key])) {
                        continue;
                    }
                    $posted_axis_keys[$axis_key] = true;
                    // Axis no longer on the product — see $allowed_axis_keys.
                    if (!empty($allowed_axis_keys) && !isset($allowed_axis_keys[$axis_key])) {
                        continue;
                    }
                    $raw_val  = is_scalar($val) ? (string) $val : '';
                    $axis_tax = $axis_taxonomy[$axis_key] ?? brikpanel_attr_taxonomy_from_key($axis_key);
                    if ($axis_tax !== '') {
                        // The editor sends a term name for a variation it
                        // generated and the stored slug for one it loaded; the
                        // slug is looked up as posted, so an encoded one is found.
                        $value = '';
                        if ($raw_val !== '') {
                            $val_text = sanitize_text_field($raw_val);
                            $term = $val_text !== '' ? get_term_by('name', $val_text, $axis_tax) : false;
                            if (!$term) {
                                $term = get_term_by('slug', sanitize_title($raw_val), $axis_tax);
                            }
                            $value = $term ? $term->slug : sanitize_title($raw_val);
                        }
                    } else {
                        $value = sanitize_text_field($raw_val);
                    }
                    $var_attrs[$axis_key] = $value;
                    $posted_as_saved_key[$axis_key] = $as_saved_key;
                }
            }

            // Carry forward every stored value the payload said nothing about.
            //
            // set_attributes() is not a merge: WC's variation data store
            // deletes every `attribute_*` row it is not handed. Until now this
            // loop wrote whatever the editor had in hand and let WooCommerce
            // delete the rest, which is fine while the editor sees everything —
            // and destructive the moment it cannot. A parent axis that lost its
            // "used for variations" flag or its option list (a routine ERP
            // re-push) makes WooCommerce hide the values from the editor, and
            // the next save then deleted the only copy left. Every other field
            // in this loop already follows the opposite rule: absent from the
            // payload means leave it alone.
            //
            // A key is only allowed to disappear when it belongs to no
            // attribute the product still has — the phantom `attribute_<gone>`
            // rows a removed attribute used to leave behind.
            foreach ($stored_var_attrs as $stored_key => $stored_val) {
                if ($stored_val === '' || isset($var_attrs[$stored_key]) || isset($posted_axis_keys[$stored_key])) {
                    continue;
                }
                // A key this save points at another axis (a promoted
                // attribute's old custom key): its value went in under that one.
                if (isset($slug_remap[$stored_key])
                    || (isset($axis_alias[$stored_key]) && $axis_alias[$stored_key] !== $stored_key)) {
                    continue;
                }
                if (!empty($known_axis_keys) && !isset($known_axis_keys[$stored_key])) {
                    continue;
                }
                $var_attrs[$stored_key] = $stored_val;
            }
            $variation->set_attributes($var_attrs);

            // "Active" toggle → WC's per-variation enabled state, stored as the
            // variation post_status ('publish' = active, 'private' = disabled/
            // hidden from the store). BrikPanel historically never wrote this,
            // relying on WC's default. Two problems flowed from that: (1) a
            // variation disabled anywhere else — classic editor, a CSV import,
            // or a third-party plugin filtering woocommerce_new_product_variation_data
            // to 'draft' — could never be reactivated from this dashboard; and
            // (2) such plugins made new variations inactive by default. Writing
            // the status explicitly makes BrikPanel authoritative: it overrides
            // any foreign default and guarantees variations are Active unless
            // the merchant deliberately turns one off. A missing key (older
            // client) is treated as active — the safe, non-destructive default.
            $var_enabled = !array_key_exists('enabled', $var_data) || !empty($var_data['enabled']);
            $variation->set_status($var_enabled ? 'publish' : 'private');

            // Price (a WPML translation's comes from its original, see above)
            if (!$price_locked) {
                $var_price = isset($var_data['regular_price']) ? wc_format_decimal(sanitize_text_field($var_data['regular_price'])) : '';
                $variation->set_regular_price($var_price);

                $var_sale = isset($var_data['sale_price']) && $var_data['sale_price'] !== '' ? wc_format_decimal(sanitize_text_field($var_data['sale_price'])) : '';
                $variation->set_sale_price($var_sale);
            }

            // Sale schedule dates per variation. Written only when the payload
            // carries the key: the dates live in the row's details panel, and a
            // missing key has to leave a stored schedule alone, not clear it.
            // An empty value that IS sent still clears (the date's × button).
            if ($price_locked) {
                unset($var_data['sale_from'], $var_data['sale_to']);
            }
            if (array_key_exists('sale_from', $var_data)) {
                $var_sale_from_raw = sanitize_text_field((string) $var_data['sale_from']);
                $var_sale_from     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $var_sale_from_raw) ? $var_sale_from_raw : '';
                $variation->set_date_on_sale_from($var_sale_from !== '' ? $var_sale_from : null);
            }
            if (array_key_exists('sale_to', $var_data)) {
                $var_sale_to_raw = sanitize_text_field((string) $var_data['sale_to']);
                $var_sale_to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $var_sale_to_raw) ? $var_sale_to_raw : '';
                $variation->set_date_on_sale_to($var_sale_to !== '' ? $var_sale_to : null);
            }

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
                // An unchanged box keeps the stored quantity: orders placed
                // while the editor was open have already lowered it.
                $var_keep_qty = brikpanel_stock_box_unchanged($variation, $var_stock, $var_data['stock_original'] ?? null);
                $var_backorders_value = in_array($var_backorders_raw, ['yes', 'notify'], true) ? $var_backorders_raw : 'no';
                $variation->set_manage_stock(true);
                if (!$var_keep_qty) {
                    $variation->set_stock_quantity($var_stock);
                }
                $variation->set_backorders($var_backorders_value);
            } elseif ($parent_shares) {
                // The row sells from the product's shared stock. Its status is
                // the product's, which WooCommerce copies down when the product
                // saves; a backorder rule of its own has no meaning either.
                $variation->set_manage_stock(false);
                $variation->set_backorders('no');
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

            // Pack size: the units one sale takes from the shared stock. Only
            // a submitted key is written; 1 (or empty) removes it. Kept while
            // the row counts its own stock or the switch is off, it is simply
            // not counted then.
            if (array_key_exists('pack_units', $var_data) && class_exists('Brikpanel_Pack_Sizes')) {
                $var_pack_units = Brikpanel_Pack_Sizes::sanitize_units($var_data['pack_units']);
                if ($var_pack_units > 1) {
                    $variation->update_meta_data(Brikpanel_Pack_Sizes::UNITS_META, $var_pack_units);
                } else {
                    $variation->delete_meta_data(Brikpanel_Pack_Sizes::UNITS_META);
                }
            }

            // SKU. Same rule as the dates: it sits in the details panel now, so
            // only a submitted key is written and a missing one keeps the SKU.
            if (array_key_exists('sku', $var_data)) {
                $var_sku = sanitize_text_field((string) $var_data['sku']);
                try {
                    $variation->set_sku($var_sku);
                } catch (\Exception $e) {
                    // Duplicate/invalid variation SKU — persist the rest of the
                    // variation but report why the SKU was dropped (mirrors the
                    // simple-product path so variable products behave the same).
                    if ($var_sku !== '') {
                        $this->save_warnings[] = sprintf(
                            /* translators: %s: the variation SKU the merchant tried to save */
                            __('The variation SKU "%s" was not saved because it is already used by another product. SKUs must be unique.', 'brikpanel'),
                            $var_sku
                        );
                    }
                }
            }

            // Global Unique ID (GTIN/UPC/EAN/ISBN) per variation. Opt-in
            // section: only write when the key was submitted so leaving the
            // section disabled never wipes existing variation barcodes.
            if (array_key_exists('global_unique_id', $var_data)) {
                $var_gtin = sanitize_text_field($var_data['global_unique_id']);
                try {
                    brikpanel_wc_set_gtin($variation, $var_gtin);
                } catch (\Throwable $e) {
                    // Duplicate/invalid variation GTIN — keep the rest, warn why.
                    if ($var_gtin !== '') {
                        $this->save_warnings[] = sprintf(
                            /* translators: %s: the variation GTIN/UPC/EAN/ISBN the merchant tried to save */
                            __('The variation GTIN "%s" was not saved because it is already used by another product or is invalid.', 'brikpanel'),
                            $var_gtin
                        );
                    }
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
            $var_cogs_submitted = null;
            if ( array_key_exists( 'cogs_value', $var_data ) ) {
                $var_cogs_raw       = sanitize_text_field( $var_data['cogs_value'] );
                $var_cogs_decimal   = brikpanel_set_product_cogs_raw( $variation->get_id(), $var_cogs_raw );
                $var_cogs_submitted = $var_cogs_decimal;
                if ( brikpanel_wc_cogs_enabled( $variation ) ) {
                    $variation->set_cogs_value( $var_cogs_decimal !== '' ? $var_cogs_decimal : null );
                }
                // A cost plugin's per-variation input is re-emitted into $_POST
                // for the woocommerce_save_product_variation dispatch below,
                // carrying the value it rendered with rather than what the
                // merchant typed in our column. Correct it first.
                brikpanel_cogs_sync_posted_third_party_inputs( $var_cogs_decimal, $loop_index );
            }

            // Per-variation vendor override. 0 means "inherit parent" and
            // persist_meta() handles that delete branch. A missing key used to
            // mean 0 as well, which deleted every variation's supplier on any
            // save that did not carry the field; now it leaves it alone.
            if ( array_key_exists( 'vendor_id', $var_data ) && class_exists( 'Brikpanel_Vendor_Product_Editor' ) && Brikpanel_Vendor_Product_Editor::vendor_field_enabled() ) {
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
            $is_new_row = 0 === (int) $variation->get_id();
            if ($flags_sent && ($is_new_row || null === $shown_flags)) {
                // A row the editor showed nothing for: the switches and the
                // list speak for it, as they always did.
                $variation->set_virtual($parent_is_virtual);
                $variation->set_downloadable($parent_is_downloadable);
                if (!$parent_is_downloadable) {
                    $variation->set_downloads([]);
                } else {
                    $variation->set_downloads($this->download_copies($parent_download_objects, [], false));
                    $variation->set_download_limit(-1);
                    $variation->set_download_expiry(-1);
                }
            } elseif ($flags_sent) {
                // An existing row: only what the merchant changed (see
                // $shown_flags above).
                if ($apply_virtual) {
                    $variation->set_virtual($parent_is_virtual);
                }
                $was_downloadable = (bool) $variation->get_downloadable('edit');
                if ($apply_downloadable && !$parent_is_downloadable) {
                    $variation->set_downloadable(false);
                    $variation->set_downloads([]);
                } elseif ($parent_is_downloadable && ($apply_downloadable || $files_changed)) {
                    $variation->set_downloadable(true);
                    if ($files_changed) {
                        // An edited shared list replaces the variation's files
                        // but keeps the variation's own copy of every file it
                        // already had: that copy's download ID is what its
                        // buyers' download links point at, and handing every
                        // variation the first one's IDs broke them. A list that
                        // opened empty (files differ) adds to each one's own.
                        $variation->set_downloads($this->download_copies(
                            $parent_download_objects,
                            $variation->get_downloads('edit'),
                            null === $shown_files
                        ));
                    }
                    // Unlimited downloads only for a row that becomes
                    // downloadable now; a limit set before is the merchant's.
                    if (!$was_downloadable) {
                        $variation->set_download_limit(-1);
                        $variation->set_download_expiry(-1);
                    }
                }
            } elseif ($is_new_row) {
                if (null === $new_row_flags) {
                    // The variations that stay: posted with their ID and still
                    // children of this product (a row left out is deleted below).
                    $posted_ids = [];
                    foreach ($variations_data as $posted_row) {
                        $posted_id = is_array($posted_row) ? intval($posted_row['id'] ?? 0) : 0;
                        if ($posted_id > 0) {
                            $posted_ids[] = $posted_id;
                        }
                    }
                    $staying = array_values(array_intersect($existing_child_ids, $posted_ids));
                    $new_row_flags = $staying
                        ? $this->variation_flags_summary($staying)
                        : [
                            // None to follow (a brand-new product, or one just
                            // turned variable): the product's own flag, as
                            // stored before this save wrote anything.
                            'virtual'      => null !== $this->save_parent_virtual
                                ? $this->save_parent_virtual
                                : 'yes' === get_post_meta($product->get_id(), '_virtual', true),
                            'downloadable' => false,
                            'downloads'    => null,
                        ];
                }
                $variation->set_virtual($new_row_flags['virtual']);
                if ($new_row_flags['downloadable'] && null !== $new_row_flags['downloads']) {
                    $variation->set_downloadable(true);
                    $variation->set_downloads($this->download_copies($new_row_flags['downloads'], [], false));
                    $variation->set_download_limit(-1);
                    $variation->set_download_expiry(-1);
                }
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

            // Persist the row order the merchant arranged in the table.
            // WC_Product_Variable_Data_Store_CPT::read_children() reads children
            // with `menu_order ASC, ID ASC`, so leaving this unset parked every
            // variation at 0 and the list silently collapsed to creation order —
            // there was no way to sort variations at all, and a newly generated
            // one jumped ahead of a hand-sorted list because 0 sorts first.
            // $loop_index is the submitted position, which is exactly what the
            // table shows. Writing it on every save is free when nothing moved:
            // WC only touches wp_posts when a post-level prop actually changed,
            // and an identical value registers no change at all.
            $variation->set_menu_order($loop_index);

            $variation->save();

            // Enforce the intended Active state at the DB level. WC runs a new
            // variation's post_status through the woocommerce_new_product_variation_data
            // filter on insert, which some third-party plugins hook to force new
            // variations to 'draft' — the exact cause of variations landing
            // "inactive by default". set_status() loses that race on the first
            // insert (the object reports 'publish' while the row is 'draft'), so
            // reconcile against the stored status and correct only on mismatch.
            // This keeps it a no-op in the normal case and never fires an extra
            // write unless a foreign default actually diverged from the toggle.
            $desired_var_status = $var_enabled ? 'publish' : 'private';
            $saved_var_id = $variation->get_id();
            if ($saved_var_id && get_post_status($saved_var_id) !== $desired_var_status) {
                wp_update_post(['ID' => $saved_var_id, 'post_status' => $desired_var_status]);
                clean_post_cache($saved_var_id);
            }

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
            // intentionally let WC derive the status from quantity. A row
            // that follows the shared stock has no status of its own.
            $vid = $variation->get_id();
            if (!$var_manage && !$parent_shares && $vid && get_post_meta($vid, '_stock_status', true) !== $var_stock_status) {
                update_post_meta($vid, '_stock_status', $var_stock_status);
                wp_cache_delete('product-' . $vid, 'products');
                clean_post_cache($vid);
                do_action('woocommerce_product_set_stock_status', $vid, $var_stock_status, wc_get_product($vid));
            }

            if ($vid) {
                $saved_rows++;
                if (!$var_manage) {
                    $any_row_follows = true;
                }
            }
            $submitted_ids[] = $vid;
            if ($vid) $loop_to_variation[$loop_index] = $vid;

            // Let 3rd-party plugins persist their per-variation fields. $_POST
            // already has the flattened bracketed values (the client shipped
            // them as `field[<loop>]=...`), so the plugin's handler reads
            // `$_POST['field'][$loop_index]` the same way it does inside WC's
            // native variation metabox save.
            if ($vid) {
                // Guarded: a plugin bailing out here must not take the rest of
                // the variation loop down with it. See dispatch_foreign_hooks().
                $curcy_native = [
                    'variable_sale_price'          => [$loop_index => $var_sale],
                    'variable_sale_price_dates_to' => [$loop_index => $var_sale_to],
                ];
                $this->dispatch_foreign_hooks(
                    static function () use ($vid, $loop_index, $curcy_native) {
                        self::with_multicurrency_save_context('variation', $curcy_native, static function () use ($vid, $loop_index) {
                            do_action('woocommerce_save_product_variation', $vid, $loop_index);
                        });
                    },
                    'woocommerce_save_product_variation (variation ' . $vid . ')'
                );

                // Re-assert the cost last: cost plugins persist their own key
                // from this dispatch, and theirs is read first, so without this
                // a plugin whose variation input we don't know by name would
                // replace the merchant's number with whatever it rendered with.
                if ($var_cogs_submitted !== null) {
                    brikpanel_set_product_cogs_raw($vid, $var_cogs_submitted);
                }
            }
        }

        $this->save_measurement_calculator_variation_fields($loop_to_variation);

        // Delete removed variations
        foreach (brikpanel_variation_ids($product) as $child_id) {
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

        // Product-level stock on a variable product is WooCommerce's shared
        // stock: the variations that count nothing of their own sell from it,
        // and the "Shared stock" switch keeps it (this used to clear it on
        // every save, which turned 40 shared into 40 per size). It is a
        // leftover only when NO saved row follows it (from a simple→variable
        // conversion, the native WC editor or an import): then
        // WC_Product_Variable::sync() keeps the product's own status instead
        // of deriving it from the variations, and a product stuck at qty 0
        // reports out of stock while its variations have stock (3.2.17).
        // Clear that one before the sync. Decided from the rows this save
        // wrote, not from the children read earlier (that list still holds
        // deleted rows and misses new ones); with no rows the product keeps
        // its shared stock for the variations it will get.
        $variable_for_sync = $product instanceof WC_Product_Variable
            ? $product
            : new WC_Product_Variable($product_id);
        if (true === $variable_for_sync->get_manage_stock('edit') && $saved_rows > 0 && !$any_row_follows) {
            $variable_for_sync->set_manage_stock(false);
            $variable_for_sync->save();
        }
        WC_Product_Variable::sync($variable_for_sync);

        // Persist the storefront "Default Form Values" selection AFTER sync().
        // WC_Product_Variable::sync() saves the in-memory parent, which reverts
        // default_attributes to whatever value the object was loaded with — so
        // writing it earlier is silently undone on a change/clear. Writing it
        // here, as the final save, makes it authoritative; a later fresh-load
        // sync (WC's deferred shutdown sync from the variation saves) then reads
        // it from disk and preserves it. An empty array clears a prior default.
        $variable_for_sync->set_default_attributes($default_attributes);
        $variable_for_sync->save();
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
        $this->render_category_checklist($brands, $selected_ids, 0, 0, 'brand_ids[]', __('No brands found.', 'brikpanel'));
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

        // Same image fields as the page data (get_product_data()): WordPress
        // may already have filled the alt text from the photo's own caption
        // data, and someone allowed to upload is not always allowed to edit.
        wp_send_json_success([
            'id'       => $attachment_id,
            'url'      => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
            'url_m'    => wp_get_attachment_image_url($attachment_id, 'medium'),
            'alt'      => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
            'editable' => current_user_can('edit_post', $attachment_id),
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
        // Its own context: "Copy" is also a button ("Kopieren", "Kopyala"), and the name takes the noun.
        $duplicate->set_name(($product->get_name() ?? '') . ' (' . _x('Copy', 'suffix of a duplicated product name', 'brikpanel') . ')');
        $duplicate->set_status('draft');
        $duplicate->set_slug('');
        $this->reset_copy_identity($duplicate);
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
            foreach (brikpanel_variation_ids($product) as $child_id) {
                $variation = wc_get_product($child_id);
                if (!$variation) continue;

                $new_var = clone $variation;
                $new_var->set_id(0);
                $new_var->set_parent_id($duplicate->get_id());
                $this->reset_copy_identity($new_var);
                $new_var->save();
            }
            WC_Product_Variable::sync($duplicate->get_id());
        }

        wp_send_json_success([
            'product_id' => $duplicate->get_id(),
            'message'    => __('Product duplicated!', 'brikpanel'),
        ]);
    }

    /**
     * A copy starts as a new product, the way WooCommerce's own Duplicate
     * makes one. No SKU and no GTIN: both must be unique, so WooCommerce
     * refused the original's GTIN on every save of the copy. The SKU stays
     * empty instead of WooCommerce's "-1" suffix, as BrikPanel copies always
     * started. No creation date, so it gets today's. And for the product
     * itself no stars, review count or sales: a copy that kept them showed
     * "5 stars (3 customer reviews)" above "There are no reviews yet."
     *
     * @param WC_Product $copy Product or variation clone, not saved yet.
     */
    private function reset_copy_identity($copy) {
        try {
            $copy->set_sku('');
        } catch (\Exception $e) {}
        try {
            brikpanel_wc_set_gtin($copy, '');
        } catch (\Exception $e) {}
        $copy->set_date_created(null);
        if (!$copy->is_type('variation')) {
            $copy->set_total_sales(0);
            $copy->set_rating_counts(0);
            $copy->set_average_rating(0);
            $copy->set_review_count(0);
        }
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

        // This product, not just products in general: a role that may edit its
        // own products must not reprice or restock someone else's.
        if (!current_user_can('edit_post', $product_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'brikpanel')]);
        }

        switch ($field) {
            case 'price':
                // A WPML translation takes its price from the original product.
                if (function_exists('brikpanel_wpml_original_of') && brikpanel_wpml_original_of($product_id)) {
                    $note = function_exists('brikpanel_wpml_price_lock_note') ? brikpanel_wpml_price_lock_note() : ['text' => __('Invalid field.', 'brikpanel')];
                    wp_send_json_error(['message' => $note['text']]);
                }
                $product->set_regular_price(wc_format_decimal($value));
                break;
            case 'stock':
                if ($product->is_type('variable')) {
                    // A variable product has one quantity to edit only when
                    // it is WooCommerce's shared stock and every variation for
                    // sale sells from it. Switching tracking on otherwise made
                    // the sum of the variations' own stock the product's stock,
                    // the leftover that pins a product out of stock (3.2.17).
                    $summary = brikpanel_variable_stock_summary($product);
                    if (true !== $product->get_manage_stock('edit') || !$summary['uses_shared'] || $summary['own_count'] > 0) {
                        wp_send_json_error(['message' => __('Invalid field.', 'brikpanel')]);
                    }
                    $product->set_stock_quantity(intval($value));
                    break;
                }
                $product->set_manage_stock(true);
                $product->set_stock_quantity(intval($value));
                $product->set_stock_status(intval($value) > 0 ? 'instock' : 'outofstock');
                break;
            default:
                wp_send_json_error(['message' => __('Invalid field.', 'brikpanel')]);
        }

        $product->save();
        // WPML: an original's new price or stock reaches its translations.
        if (function_exists('brikpanel_wpml_sync_translations')) {
            brikpanel_wpml_sync_translations($product_id);
        }
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

    /**
     * A saved product's status in the editor's terms: "password" for a
     * published product with a password, as the status menu names it.
     *
     * @param int $product_id Product ID.
     * @return string
     */
    private static function saved_status_for_editor($product_id) {
        $status = (string) get_post_status($product_id);
        if ('publish' === $status && '' !== (string) get_post_field('post_password', $product_id)) {
            return 'password';
        }
        return $status;
    }

    public function render_column($column, $post_id) {
        // The Stock and GTIN cells read each row's variations: all of the
        // page's rows in one query, on the first cell.
        static $variations_primed = false;
        if (!$variations_primed && ($column === 'bpe_stock' || $column === 'bpe_global_unique_id')) {
            $variations_primed = true;
            global $wp_query;
            if ($wp_query instanceof WP_Query && !empty($wp_query->posts)) {
                brikpanel_prime_variation_ids(array_map(static function ($p) {
                    return is_object($p) ? (int) $p->ID : (int) $p;
                }, $wp_query->posts));
            }
        }
        if ($column === 'product_status') {
            // Every status by its own name: this column used to call anything
            // that was not published a draft (pending, private, scheduled).
            $status = (string) get_post_status($post_id);
            $label  = brikpanel_product_status_label($status);
            $class  = $status === 'publish' ? 'published' : sanitize_html_class($status);
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
            $value = brikpanel_wc_gtin($product);

            if ($value === '' && $product->is_type('variable')) {
                // GTIN is usually stored per variation. Collect the distinct
                // non-empty values so the column stays meaningful for
                // variable products too.
                // Each child's GTIN is read straight from post meta instead of
                // hydrating a WC_Product per variation. It is the same key
                // WooCommerce stores the prop in, so the answer is identical on
                // every version, and it saves a full product load per variation
                // on every row of the list.
                $found = [];
                foreach (brikpanel_variation_ids($product) as $child_id) {
                    $vid = trim(brikpanel_wc_gtin($child_id));
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
                // The product's shared stock counts once, however many
                // variations sell from it (it used to show 7 × 40 = 280),
                // and a variable product that counts nothing shows "—", not 0.
                $stock = brikpanel_variable_stock_summary($product)['qty'];
            }

            if ($stock !== null && $stock !== '') {
                $warn = '';
                // "Low" is WooCommerce's own low stock threshold, as on every
                // other BrikPanel screen.
                $low = (int) get_option('woocommerce_notify_low_stock_amount', 2);
                if ((int) $stock <= 0) {
                    $warn = ' brikpanel-pe-stock-out';
                } elseif ((int) $stock <= $low) {
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

/**
 * Tell Import / Export about the product editor's parked-tabs buffer.
 *
 * Internal, not portable: it is the undo state of one click on one site ("what
 * the third-party fields looked like before they were hidden"), and it is
 * deleted the moment that undo is used. Carrying it to another store would
 * offer to restore a state that store was never in.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter('brikpanel_exportable_option_keys', 'brikpanel_pe_register_internal_export_keys');
function brikpanel_pe_register_internal_export_keys($map) {
    $map[Brikpanel_Product_Editor::TABS_PARKED_OPTION] = ['class' => 'internal'];
    return $map;
}
