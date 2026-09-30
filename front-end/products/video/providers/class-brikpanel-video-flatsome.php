<?php
/**
 * Flatsome: one product video per product.
 *
 * Flatsome keeps its extra product fields in one PRODUCT meta,
 * `wc_productdata_options`, as [0 => [field id => value]]: layout block,
 * top/bottom content, bubble, custom tab, and the product video
 * (`_product_video` link, `_product_video_size` "WxH", `_product_video_placement`
 * '' = lightbox button, 'tab' = a Video tab). Its fields sit in two legacy
 * product-data panels (`ux_product_layout_tab`, `ux_extra_tab`).
 *
 * Its save handler (WC_Product_Data_Fields::product_save_data, on
 * woocommerce_process_product_meta) rebuilds ALL of those fields from $_POST with
 * no isset() check and no nonce, so every BrikPanel save that did not carry
 * the panels set all ten fields to null. The guard detaches the handler when
 * neither panel is on the form, and otherwise fills the missing panel's fields
 * with their stored values.
 *
 * Source: themes/flatsome/inc/classes/class-wc-product-data-fields.php,
 * inc/woocommerce/structure-wc-product-page-fields.php, structure-wc-product-page.php
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_Flatsome extends Brikpanel_Video_Provider {

    const META = 'wc_productdata_options';

    /** Field types Flatsome always posts when its panel is on the page. */
    const ALWAYS_POSTED = ['text', 'textarea', 'select', 'number', 'hidden'];

    public function id() {
        return 'flatsome';
    }

    public function label() {
        return __('Flatsome', 'brikpanel');
    }

    /** Flatsome's field manager (a global object the theme creates). */
    private static function fields_object() {
        $obj = isset($GLOBALS['wc_cpdf']) ? $GLOBALS['wc_cpdf'] : null;
        return (is_object($obj) && method_exists($obj, 'wc_cpdf_fields') && method_exists($obj, 'product_save_data')) ? $obj : null;
    }

    /**
     * Flatsome's fields per panel: [panel id => [field id => type]].
     *
     * @return array
     */
    private static function panels() {
        $obj = self::fields_object();
        if (! $obj) {
            return [];
        }
        $fields = $obj->wc_cpdf_fields();
        $panels = [];
        foreach ((array) $fields as $panel => $list) {
            foreach ((array) $list as $field) {
                if (is_array($field) && ! empty($field['id'])) {
                    $panels[(string) $panel][(string) $field['id']] = isset($field['type']) ? (string) $field['type'] : 'text';
                }
            }
        }
        return $panels;
    }

    public function detected() {
        return self::fields_object() !== null;
    }

    public function active() {
        if (! $this->detected() || get_template() !== 'flatsome') {
            return false;
        }
        foreach (self::panels() as $fields) {
            if (isset($fields['_product_video'])) {
                return true;
            }
        }
        return false;
    }

    public function kind() {
        return 'product';
    }

    public function sources() {
        return ['youtube', 'vimeo', 'file', 'url'];
    }

    public function file_exts() {
        return ['mp4', 'webm'];
    }

    public function options() {
        return [
            [
                'key'     => 'placement',
                'type'    => 'choice',
                'label'   => 'video_opt_placement',
                'default' => '',
                'choices' => [
                    ['value' => '', 'label' => 'video_opt_placement_lightbox'],
                    ['value' => 'tab', 'label' => 'video_opt_placement_tab'],
                ],
            ],
            [
                'key'         => 'size',
                'type'        => 'text',
                'label'       => 'video_opt_size',
                'placeholder' => '900x900',
                'default'     => '',
                // Flatsome divides by the first number, so zero is refused.
                'pattern'     => '^[1-9][0-9]{1,3}x[1-9][0-9]{1,3}$',
                'error'       => 'invalid_size',
                'show_if'     => ['key' => 'placement', 'eq' => ''],
            ],
        ];
    }

    private static function row($product_id) {
        $meta = get_post_meta((int) $product_id, self::META, true);
        $meta = maybe_unserialize($meta);
        return (is_array($meta) && isset($meta[0]) && is_array($meta[0])) ? $meta[0] : [];
    }

    public function read_product($product_id) {
        $row   = self::row($product_id);
        $video = Brikpanel_Video::blank($this);

        $video['options']['placement'] = (isset($row['_product_video_placement']) && $row['_product_video_placement'] === 'tab') ? 'tab' : '';
        $video['options']['size']      = isset($row['_product_video_size']) && is_string($row['_product_video_size']) ? $row['_product_video_size'] : '';

        $link = isset($row['_product_video']) && is_string($row['_product_video']) ? trim($row['_product_video']) : '';
        if ($link !== '') {
            $video = Brikpanel_Video::from_link($video, $link, $this);
        }
        return $video;
    }

    public function write_product($product_id, $video) {
        $product_id = (int) $product_id;
        $meta       = maybe_unserialize(get_post_meta($product_id, self::META, true));
        $meta       = is_array($meta) ? $meta : [];
        $row        = (isset($meta[0]) && is_array($meta[0])) ? $meta[0] : [];

        foreach ($this->values($video) as $key => $value) {
            $row[$key] = $value;
        }
        $meta[0] = $row;
        update_post_meta($product_id, self::META, $meta);
    }

    /**
     * The three Flatsome fields for a video (null = removed).
     *
     * @param array|null $video Normalized video.
     * @return array
     */
    private function values($video) {
        if ($video === null || empty($video['has'])) {
            // Without a video a "Video" tab would render empty, so the
            // placement goes back to the lightbox default too.
            return [
                '_product_video'           => '',
                '_product_video_placement' => '',
            ];
        }
        $placement = $video['options']['placement'] === 'tab' ? 'tab' : '';
        return [
            '_product_video'           => Brikpanel_Video::link_of($video),
            '_product_video_size'      => (string) $video['options']['size'],
            '_product_video_placement' => $placement,
        ];
    }

    public function mirror($product_id, array $video) {
        $row = self::row($product_id);
        return [
            '_product_video'           => isset($row['_product_video']) ? (string) $row['_product_video'] : '',
            '_product_video_size'      => isset($row['_product_video_size']) ? (string) $row['_product_video_size'] : '',
            '_product_video_placement' => isset($row['_product_video_placement']) ? (string) $row['_product_video_placement'] : '',
        ];
    }

    public function guard_needed() {
        return true;
    }

    public function guard($product_id) {
        $obj    = self::fields_object();
        $panels = self::panels();
        if (! $obj || ! $panels) {
            return;
        }

        $missing = [];
        $any     = false;
        foreach ($panels as $panel => $fields) {
            $always = array_keys(array_filter($fields, static function ($type) {
                return in_array($type, self::ALWAYS_POSTED, true);
            }));
            if (Brikpanel_Video::box_rendered($panel, $always)) {
                $any = true;
            } else {
                $missing[$panel] = array_keys($fields);
            }
        }

        $change = Brikpanel_Video::pending_product_change($this);

        if (! $any) {
            // None of Flatsome's fields are on this form: its handler would
            // store null over every one of them. The product video, if changed,
            // is written after the save instead.
            remove_action('woocommerce_process_product_meta', [$obj, 'product_save_data'], 10);
            return;
        }

        // Part of Flatsome's form is here: the handler runs, and gets the
        // missing panel's fields at their stored values.
        $row = self::row($product_id);
        foreach ($missing as $ids) {
            foreach ($ids as $id) {
                if (! array_key_exists($id, $_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    $stored = array_key_exists($id, $row) ? $row[$id] : '';
                    Brikpanel_Video::set_post($id, wp_slash(is_scalar($stored) ? (string) $stored : ''));
                }
            }
        }
        // A product video changed in BrikPanel wins over the stale values its
        // panel still carries on the page.
        if ($change !== null) {
            foreach ($this->values($change['video']) as $key => $value) {
                Brikpanel_Video::set_post($key, wp_slash($value));
            }
        }
    }

    public function guard_fail_safe() {
        $obj = self::fields_object();
        if ($obj) {
            remove_action('woocommerce_process_product_meta', [$obj, 'product_save_data'], 10);
        }
    }
}
