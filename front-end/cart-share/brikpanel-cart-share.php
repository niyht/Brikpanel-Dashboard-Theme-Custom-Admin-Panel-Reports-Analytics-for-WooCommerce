<?php
/**
 * BrikPanel - Cart Share
 *
 * Lets a merchant build a cart of products in the admin and hand the customer a
 * single link that drops those exact items into their cart, and lets a customer
 * share their own cart from the storefront cart page. Useful for stores that
 * take orders over Instagram / WhatsApp / Facebook: pick the requested items,
 * copy the link, send it, the customer opens it and checks out.
 *
 * Link format (query var `bp-cart`): a comma-separated list of items. Each item
 * is one of:
 *   - "123"          simple product, quantity 1
 *   - "123:2"        simple product, quantity 2
 *   - "123:2:456"    variable product 123, quantity 2, variation id 456
 * The handler is deliberately additive (it merges into whatever is already in
 * the cart) and validates every product against WooCommerce before adding, so a
 * stale or unpurchasable id is skipped rather than fatal.
 *
 * @package BrikPanel
 * @since 3.2.26
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Cart_Share {

    /** Query variable that carries the shared cart payload. */
    const QUERY_VAR = 'bp-cart';

    public function __construct() {
        // Settings tab wiring is registered unconditionally so the on/off toggle
        // is always reachable, even while the feature itself is switched off.
        add_filter( 'woocommerce_get_sections_brikpanel',   [ $this, 'settings_section' ] );
        add_filter( 'brikpanel_settings_section_groups',    [ $this, 'settings_group' ] );
        add_filter( 'brikpanel_settings_title_section_map', [ $this, 'settings_title_map' ] );
        add_filter( 'brikpanel_settings_section_icons',     [ $this, 'settings_icon' ] );
        add_filter( 'brikpanel_settings_fields',            [ $this, 'settings_fields' ] );

        if ( ! self::is_enabled() ) {
            return;
        }

        // Front-of-store: turn an incoming ?bp-cart=… link into a real cart.
        add_action( 'wp_loaded', [ $this, 'handle_share_link' ], 20 );

        // Admin builder page + its data endpoints.
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'wp_ajax_brikpanel_cartshare_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_brikpanel_cartshare_get_variations',  [ $this, 'ajax_get_variations' ] );

        // Storefront "Share cart" button (guests included).
        if ( self::frontend_button_enabled() ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
            add_action( 'wp_ajax_brikpanel_cartshare_current',        [ $this, 'ajax_current_cart_link' ] );
            add_action( 'wp_ajax_nopriv_brikpanel_cartshare_current', [ $this, 'ajax_current_cart_link' ] );
        }
    }

    // =========================================================================
    // STATE
    // =========================================================================

    /** Master gate for the whole feature (defaults on; master-switch aware via the option). */
    public static function is_enabled() {
        return get_option( 'brikpanel_cart_share_enabled', 'yes' ) !== 'no';
    }

    /** Whether the storefront cart page shows a "Share cart" button. */
    public static function frontend_button_enabled() {
        return get_option( 'brikpanel_cart_share_frontend_button', 'yes' ) !== 'no';
    }

    // =========================================================================
    // LINK ENCODE / DECODE
    // =========================================================================

    /**
     * Build a shareable URL from a list of normalized items.
     *
     * @param array<int,array{product_id:int,quantity:int,variation_id:int}> $items
     * @return string Absolute URL, or '' when there is nothing to share.
     */
    public static function build_link( array $items ) {
        $tokens = [];
        foreach ( $items as $item ) {
            $pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            $qty = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
            $vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
            if ( $pid <= 0 ) {
                continue;
            }
            if ( $vid > 0 ) {
                $tokens[] = $pid . ':' . $qty . ':' . $vid;
            } elseif ( $qty > 1 ) {
                $tokens[] = $pid . ':' . $qty;
            } else {
                $tokens[] = (string) $pid;
            }
        }

        if ( empty( $tokens ) ) {
            return '';
        }

        // Tokens are only digits, ":" and ","; all URL-safe, so we build the
        // query by hand to keep the link human-readable (add_query_arg would
        // percent-encode the separators into an unreadable string).
        return home_url( '/' ) . '?' . self::QUERY_VAR . '=' . implode( ',', $tokens );
    }

    /**
     * Parse the raw `bp-cart` payload into normalized items. Anything malformed
     * is dropped silently; the caller re-validates each id against WooCommerce.
     *
     * @param string $raw
     * @return array<int,array{product_id:int,quantity:int,variation_id:int}>
     */
    public static function parse_tokens( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return [];
        }

        $items = [];
        foreach ( explode( ',', $raw ) as $chunk ) {
            $chunk = trim( $chunk );
            if ( $chunk === '' ) {
                continue;
            }
            $parts = explode( ':', $chunk );
            $pid   = isset( $parts[0] ) ? (int) $parts[0] : 0;
            $qty   = isset( $parts[1] ) ? (int) $parts[1] : 1;
            $vid   = isset( $parts[2] ) ? (int) $parts[2] : 0;
            if ( $pid <= 0 ) {
                continue;
            }
            $items[] = [
                'product_id'   => $pid,
                'quantity'     => max( 1, $qty ),
                'variation_id' => max( 0, $vid ),
            ];
        }

        // Hard cap so a hand-crafted URL cannot loop the cart endlessly.
        return array_slice( $items, 0, 100 );
    }

    /**
     * Build a share link that reproduces whatever is in the current session cart.
     *
     * @return string
     */
    public static function build_link_from_cart() {
        if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
            return '';
        }

        $items = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $items[] = [
                'product_id'   => (int) $cart_item['product_id'],
                'quantity'     => (int) $cart_item['quantity'],
                'variation_id' => (int) $cart_item['variation_id'],
            ];
        }

        return self::build_link( $items );
    }

    // =========================================================================
    // FRONT-OF-STORE: consume an incoming share link
    // =========================================================================

    public function handle_share_link() {
        if ( is_admin() ) {
            return;
        }
        if ( ! isset( $_GET[ self::QUERY_VAR ] ) || $_GET[ self::QUERY_VAR ] === '' ) {
            return;
        }
        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        // Cart is normally loaded by WooCommerce on wp_loaded; make sure it is
        // present even on requests where it has not been spun up yet.
        if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }
        if ( is_null( WC()->cart ) ) {
            return;
        }

        $items = self::parse_tokens( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
        if ( empty( $items ) ) {
            wp_safe_redirect( wc_get_cart_url() );
            exit;
        }

        $added_qty = 0;
        $skipped   = 0;

        foreach ( $items as $item ) {
            $product = wc_get_product( $item['product_id'] );
            if ( ! $product ) {
                $skipped++;
                continue;
            }

            $variation_id  = 0;
            $variation_atts = [];

            if ( $product->is_type( 'variable' ) ) {
                // A variable product cannot be added without picking a variation.
                if ( $item['variation_id'] > 0 ) {
                    $variation = wc_get_product( $item['variation_id'] );
                    if ( $variation
                        && $variation->is_type( 'variation' )
                        && (int) $variation->get_parent_id() === (int) $product->get_id() ) {
                        $variation_id   = (int) $item['variation_id'];
                        $variation_atts = $variation->get_variation_attributes();
                    } else {
                        $skipped++;
                        continue;
                    }
                } else {
                    $skipped++;
                    continue;
                }
            }

            // WooCommerce validates purchasable / stock / sold-individually here
            // and pushes its own notice on failure; we only tally the result.
            $result = WC()->cart->add_to_cart(
                $item['product_id'],
                $item['quantity'],
                $variation_id,
                $variation_atts
            );

            if ( $result ) {
                $added_qty += $item['quantity'];
            } else {
                $skipped++;
            }
        }

        // A notice is stored in the WC session and read back on the cart page.
        // When nothing was added the cart stays empty, and WooCommerce would not
        // otherwise persist the session across our redirect, dropping the notice.
        // Force the session cookie so the message survives to the cart page.
        if ( ( $added_qty > 0 || $skipped > 0 ) && ! is_null( WC()->session ) ) {
            WC()->session->set_customer_session_cookie( true );
        }

        if ( $added_qty > 0 ) {
            wc_add_notice(
                sprintf(
                    /* translators: %d: number of items added to the cart */
                    _n(
                        '%d item was added to your cart from a shared link.',
                        '%d items were added to your cart from a shared link.',
                        $added_qty,
                        'brikpanel'
                    ),
                    $added_qty
                ),
                'success'
            );
        } elseif ( $skipped > 0 ) {
            wc_add_notice(
                __( 'The shared link could not add any products to your cart. They may be out of stock or no longer available.', 'brikpanel' ),
                'error'
            );
        }

        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // =========================================================================
    // ADMIN BUILDER PAGE
    // =========================================================================

    public function register_page() {
        $hook = add_submenu_page(
            '',
            __( 'Cart share', 'brikpanel' ),
            '',
            'manage_woocommerce',
            'brikpanel-cart-share',
            [ $this, 'render_page' ]
        );

        if ( $hook ) {
            add_action( 'load-' . $hook, function () {
                global $title;
                $title = __( 'Cart share', 'brikpanel' );
            } );
        }
    }

    public function render_page() {
        ?>
        <div class="wrap">
        <div class="brikpanel-cs" id="brikpanel-cart-share">

            <div class="brikpanel-cs-header">
                <div class="brikpanel-cs-header-left">
                    <h1><?php esc_html_e( 'Cart share', 'brikpanel' ); ?></h1>
                    <p class="brikpanel-cs-subtitle"><?php esc_html_e( 'Pick the products a customer asked for, then send them one link that fills their cart.', 'brikpanel' ); ?></p>
                </div>
            </div>

            <div class="brikpanel-cs-grid">

                <!-- Builder -->
                <div class="brikpanel-cs-card">
                    <h2 class="brikpanel-cs-card-title"><?php esc_html_e( 'Products', 'brikpanel' ); ?></h2>

                    <div class="brikpanel-cs-search-field">
                        <input type="text" id="bpcs-search" class="brikpanel-cs-search" autocomplete="off" placeholder="<?php esc_attr_e( 'Search products by name or SKU…', 'brikpanel' ); ?>">
                        <div class="brikpanel-cs-suggestions" id="bpcs-suggestions" hidden></div>
                    </div>

                    <div class="brikpanel-cs-items" id="bpcs-items">
                        <div class="brikpanel-cs-empty" id="bpcs-empty">
                            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                            <span><?php esc_html_e( 'No products yet. Search above to add the items your customer requested.', 'brikpanel' ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Link -->
                <div class="brikpanel-cs-card brikpanel-cs-link-card">
                    <h2 class="brikpanel-cs-card-title"><?php esc_html_e( 'Share link', 'brikpanel' ); ?></h2>

                    <div class="brikpanel-cs-link-row">
                        <input type="text" id="bpcs-link" class="brikpanel-cs-link-input" readonly placeholder="<?php esc_attr_e( 'Your link appears here once you add a product.', 'brikpanel' ); ?>">
                        <button type="button" class="brikpanel-cs-btn primary" id="bpcs-copy" disabled>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                            <span><?php esc_html_e( 'Copy', 'brikpanel' ); ?></span>
                        </button>
                    </div>

                    <div class="brikpanel-cs-share-actions">
                        <a href="#" class="brikpanel-cs-btn secondary" id="bpcs-whatsapp" target="_blank" rel="noopener noreferrer" hidden>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91a9.87 9.87 0 0 0-2.9-7A9.87 9.87 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.4c0-4.54 3.7-8.23 8.24-8.23a8.2 8.2 0 0 1 5.82 2.42 8.2 8.2 0 0 1 2.41 5.82c0 4.54-3.69 8.24-8.23 8.24zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.16.04-.31-.02-.43-.06-.12-.56-1.35-.77-1.85-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.23.25-.86.84-.86 2.05 0 1.21.88 2.38 1 2.54.12.16 1.73 2.64 4.19 3.7.59.25 1.04.4 1.4.51.59.19 1.12.16 1.54.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29z"/></svg>
                            <span><?php esc_html_e( 'Share on WhatsApp', 'brikpanel' ); ?></span>
                        </a>
                        <button type="button" class="brikpanel-cs-btn secondary" id="bpcs-native-share" hidden>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                            <span><?php esc_html_e( 'Share', 'brikpanel' ); ?></span>
                        </button>
                    </div>

                    <p class="brikpanel-cs-note is-warning" id="bpcs-incomplete-note" hidden><?php esc_html_e( 'Pick a variation for every variable product so it can be included in the link.', 'brikpanel' ); ?></p>

                    <p class="brikpanel-cs-hint"><?php esc_html_e( 'When the customer opens the link, these products land in their cart and they go straight to checkout.', 'brikpanel' ); ?></p>
                </div>

            </div>

            <div class="brikpanel-cs-toast-container" id="bpcs-toast-container"></div>
        </div>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: product search (name / SKU), mirrors the coupons picker
    // =========================================================================

    public function ajax_search_products() {
        check_ajax_referer( 'brikpanel_cartshare_admin', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ] );
        }

        $term = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [ 'items' => [] ] );
        }

        $ids = [];
        if ( class_exists( 'WC_Data_Store' ) ) {
            $data_store = WC_Data_Store::load( 'product' );
            if ( method_exists( $data_store, 'search_products' ) ) {
                $ids = $data_store->search_products( $term, '', false, false, 20 );
                $ids = array_filter( array_map( 'intval', $ids ) );
            }
        }

        if ( empty( $ids ) ) {
            $query = new WP_Query( [
                'post_type'      => [ 'product' ],
                'post_status'    => 'publish',
                's'              => $term,
                'posts_per_page' => 20,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
            $ids = $query->posts;
        }

        $items = [];
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }
            // Only parents / simple products surface in search; variations are
            // reached through the per-row variation picker.
            if ( $product->is_type( 'variation' ) ) {
                continue;
            }
            if ( ! $product->is_purchasable() && ! $product->is_type( 'variable' ) ) {
                continue;
            }

            $items[] = [
                'id'         => (int) $product->get_id(),
                'label'      => $this->format_product_label( $product ),
                'type'       => $product->get_type(),
                'is_variable' => $product->is_type( 'variable' ),
                // Decode the currency HTML entity (e.g. &#36;) so the JS can set
                // it via textContent without showing the raw entity.
                'price'      => html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' ),
            ];
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    // =========================================================================
    // AJAX: variations of a variable product for the per-row picker
    // =========================================================================

    public function ajax_get_variations() {
        check_ajax_referer( 'brikpanel_cartshare_admin', 'security' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ] );
        }

        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        $product    = $product_id ? wc_get_product( $product_id ) : null;

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            wp_send_json_error( [ 'message' => __( 'This product has no variations.', 'brikpanel' ) ] );
        }

        $variations = [];
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                continue;
            }

            $attrs = [];
            foreach ( $variation->get_variation_attributes() as $key => $value ) {
                if ( $value === '' ) {
                    // "Any …" attribute — cannot build a deterministic link, skip.
                    continue 2;
                }
                $taxonomy = str_replace( 'attribute_', '', $key );
                $term     = get_term_by( 'slug', $value, $taxonomy );
                $attrs[]  = $term && ! is_wp_error( $term ) ? $term->name : $value;
            }

            $variations[] = [
                'id'    => (int) $variation_id,
                'label' => implode( ' / ', $attrs ),
                'price' => html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $variation ) ) ), ENT_QUOTES, 'UTF-8' ),
            ];
        }

        wp_send_json_success( [ 'variations' => $variations ] );
    }

    // =========================================================================
    // AJAX: current session cart -> share link (storefront button)
    // =========================================================================

    public function ajax_current_cart_link() {
        check_ajax_referer( 'brikpanel_cartshare_pub', 'security' );

        if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }

        $link  = self::build_link_from_cart();
        $count = ( ! is_null( WC()->cart ) ) ? (int) WC()->cart->get_cart_contents_count() : 0;

        wp_send_json_success( [
            'link'  => $link,
            'count' => $count,
        ] );
    }

    // =========================================================================
    // STOREFRONT ENQUEUE
    // =========================================================================

    public function enqueue_frontend() {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
            return;
        }

        $dir = plugin_dir_path( __FILE__ );
        $url = plugin_dir_url( __FILE__ );

        wp_enqueue_style(
            'brikpanel_cartshare_front',
            $url . 'cart-share.css',
            [],
            file_exists( $dir . 'cart-share.css' ) ? (string) filemtime( $dir . 'cart-share.css' ) : BRIKPANEL_VERSION
        );

        wp_enqueue_script(
            'brikpanel_cartshare_front',
            $url . 'cart-share.js',
            [],
            file_exists( $dir . 'cart-share.js' ) ? (string) filemtime( $dir . 'cart-share.js' ) : BRIKPANEL_VERSION,
            true
        );

        wp_localize_script( 'brikpanel_cartshare_front', 'brikpanelCartShare', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'brikpanel_cartshare_pub' ),
            'initialLink'  => self::build_link_from_cart(),
            'whatsappBase' => 'https://wa.me/?text=',
            'i18n'         => [
                'button'      => __( 'Share cart', 'brikpanel' ),
                'title'       => __( 'Share this cart', 'brikpanel' ),
                'description' => __( 'Anyone who opens this link gets these items in their cart.', 'brikpanel' ),
                'copy'        => __( 'Copy', 'brikpanel' ),
                'copied'      => __( 'Copied!', 'brikpanel' ),
                'whatsapp'    => __( 'WhatsApp', 'brikpanel' ),
                'share'       => __( 'Share', 'brikpanel' ),
                'close'       => __( 'Close', 'brikpanel' ),
                'empty'       => __( 'Your cart is empty, so there is nothing to share yet.', 'brikpanel' ),
                'shareText'   => __( 'Here is my cart:', 'brikpanel' ),
            ],
        ] );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function format_product_label( $product ) {
        $name = $product->get_name();
        $sku  = $product->get_sku();
        if ( $sku ) {
            return $name . ' (' . $sku . ')';
        }
        return $name . ' (#' . $product->get_id() . ')';
    }

    // =========================================================================
    // SETTINGS TAB WIRING
    // =========================================================================

    public function settings_section( $sections ) {
        $sections['cart-share'] = __( 'Cart share', 'brikpanel' );
        return $sections;
    }

    public function settings_group( $groups ) {
        if ( isset( $groups['store']['sections'] ) && is_array( $groups['store']['sections'] ) ) {
            // Sit next to the coupons entry — both are "give the customer a link" tools.
            $pos = array_search( 'coupons', $groups['store']['sections'], true );
            if ( false === $pos ) {
                $groups['store']['sections'][] = 'cart-share';
            } else {
                array_splice( $groups['store']['sections'], $pos + 1, 0, 'cart-share' );
            }
        }
        return $groups;
    }

    public function settings_title_map( $map ) {
        $map['brk_cart_share_title'] = 'cart-share';
        return $map;
    }

    public function settings_icon( $paths ) {
        // i18n-ignore: inline SVG path data, not user-facing text
        $paths['cart-share'] = '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>';
        return $paths;
    }

    public function settings_fields( $fields ) {
        $fields[] = [
            'title' => __( 'Cart share', 'brikpanel' ),
            'type'  => 'title',
            'id'    => 'brk_cart_share_title',
            'desc'  => __( 'Build a cart of products in the admin and hand a customer one link that fills their cart, and let customers share their own cart from the store. Great for orders that come in over Instagram, WhatsApp or Facebook.', 'brikpanel' ),
        ];
        $fields[] = [
            'title'   => __( 'Cart share', 'brikpanel' ),
            'desc'    => __( 'Enable shareable cart links and the admin cart builder', 'brikpanel' ),
            'id'      => 'brikpanel_cart_share_enabled',
            'type'    => 'checkbox',
            'default' => 'yes',
        ];
        $fields[] = [
            'title'    => __( 'Storefront share button', 'brikpanel' ),
            'desc'     => __( 'Show a "Share cart" button on the store cart page so customers can share their own cart', 'brikpanel' ),
            'desc_tip' => __( 'The button appears on the cart page and copies a link that reproduces the current cart.', 'brikpanel' ),
            'id'       => 'brikpanel_cart_share_frontend_button',
            'type'     => 'checkbox',
            'default'  => 'yes',
        ];
        $fields[] = [
            'type' => 'sectionend',
            'id'   => 'brk_cart_share_title',
        ];
        return $fields;
    }
}

new Brikpanel_Cart_Share();
