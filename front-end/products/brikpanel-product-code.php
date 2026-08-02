<?php
/**
 * Integration: "Product Code for WooCommerce" (by Artios Media).
 *
 * That plugin stores a merchant's internal product identifier (used as an
 * alternative to WooCommerce's GTIN) in two post-meta keys on the product
 * *and* on each variation:
 *   - _product_code          (primary code)
 *   - _product_code_second   (optional secondary code)
 *
 * The plugin adds its fields to WooCommerce's native "Product data" tabs and
 * variation rows, and BrikPanel now surfaces and saves that whole class of
 * third-party fields automatically (see the "Auto-show fields other plugins
 * add" setting and the general save-nonce fix in the product editor). So this
 * file no longer needs to render or save the editor fields itself.
 *
 * What it still adds is the one thing the generic mechanism does not: an
 * opt-in "Product Code" column on the modern products list, mirroring the
 * native GTIN column, including a compact "N codes" badge for variable
 * products whose codes live on the variations. It only appears when the
 * Product Code plugin is active, and honours the column title configured
 * there.
 *
 * @package BrikPanel
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Primary product-code meta key. Falls back to the plugin's literal constant
 * value if the plugin defined it under a different name in a future release.
 *
 * @return string
 */
function brikpanel_pcfw_key() {
    return defined('PRODUCT_CODE_FIELD_NAME') ? PRODUCT_CODE_FIELD_NAME : '_product_code';
}

/**
 * Is the "Product Code for WooCommerce" plugin active?
 *
 * Detected by its main-file constant, which the plugin defines on load, so it
 * is reliably present by the time any of our list hooks fire. Cached per
 * request.
 *
 * @return bool
 */
function brikpanel_pcfw_active() {
    static $active = null;
    if ($active === null) {
        $active = defined('PRODUCT_CODE_FIELD_NAME') || defined('PRODUCT_CODE_VERSION');
    }
    return $active;
}

/**
 * Short label for the products-list column header / Columns popover, honouring
 * the merchant's configured column title.
 *
 * @return string
 */
function brikpanel_pcfw_column_label() {
    $txt = trim((string) get_option('product_code_quik_edit_text', ''));
    return $txt !== '' ? $txt : __('Product Code', 'brikpanel');
}

/* -------------------------------------------------------------------------
 * Products list - opt-in "Product Code" column
 * ---------------------------------------------------------------------- */

add_filter('brikpanel_products_columns', 'brikpanel_pcfw_register_column', 20, 2);

/**
 * Register the Product Code column in the modern products list.
 *
 * Inserted right after the GTIN column so the two identifiers sit together.
 * Default off, merchants opt in from the Columns popover.
 *
 * @param array $cols    Column definitions.
 * @param int   $user_id Current user ID.
 * @return array
 */
function brikpanel_pcfw_register_column($cols, $user_id) {
    if (!brikpanel_pcfw_active()) {
        return $cols;
    }
    $def = ['label' => brikpanel_pcfw_column_label(), 'default' => false];

    // Place immediately after the GTIN column when present, else append.
    $out = [];
    foreach ($cols as $id => $val) {
        $out[$id] = $val;
        if ($id === 'global_unique_id') {
            $out['product_code'] = $def;
        }
    }
    if (!isset($out['product_code'])) {
        $out['product_code'] = $def;
    }
    return $out;
}

/**
 * Compute the Product Code column display for a product.
 *
 * Simple products show their own code. Variable products carry a code per
 * variation, so we always aggregate the distinct variation codes into a single
 * value or an "N codes" badge whose tooltip lists them, mirroring the native
 * GTIN column. We deliberately read the variations directly rather than the
 * parent: the Product Code plugin also mirrors each variation code onto the
 * parent (as repeated meta rows on save_post), but that copy can lag the real
 * variation values by one save, so the children are the authoritative source.
 *
 * @param WC_Product $product
 * @return array{value:string,tooltip:string,multi:bool}
 */
function brikpanel_pcfw_product_code_display($product) {
    if (!($product instanceof WC_Product)) {
        return ['value' => '', 'tooltip' => '', 'multi' => false];
    }
    $key = brikpanel_pcfw_key();

    if ($product->is_type('variable')) {
        $found = [];
        foreach ($product->get_children() as $cid) {
            $vcode = trim((string) get_post_meta($cid, $key, true));
            if ($vcode !== '') {
                $found[$vcode] = true;
            }
        }
        $found = array_keys($found);

        if (count($found) === 1) {
            return ['value' => $found[0], 'tooltip' => '', 'multi' => false];
        }
        if (count($found) > 1) {
            return [
                'value'   => sprintf(
                    /* translators: %d: number of distinct variation product codes. */
                    _n('%d code', '%d codes', count($found), 'brikpanel'),
                    count($found)
                ),
                'tooltip' => implode(', ', $found),
                'multi'   => true,
            ];
        }
        return ['value' => '', 'tooltip' => '', 'multi' => false];
    }

    $value = trim((string) get_post_meta($product->get_id(), $key, true));
    return ['value' => $value, 'tooltip' => '', 'multi' => false];
}
