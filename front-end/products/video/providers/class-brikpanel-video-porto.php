<?php
/**
 * Porto (with Porto Functionality): one product video per product.
 *
 * Porto keeps its product settings in PRODUCT meta, one key per field, grouped
 * in five metaboxes. The video ("Porto Video Thumbnail" box) uses
 * `porto_video_source` (mp4|youtube|vimeo|shortcode, '' = legacy library video),
 * `porto_video_youtube`, `porto_video_vimeo`, `porto_product_video_thumbnails`
 * (a video attachment id), `porto_video_sh_type` (popup|slide) and
 * `porto_video_pos` (1-7|last, for a slide). Without a thumbnail image the
 * theme uses the product's featured image.
 *
 * Its save handler (porto_save_product_meta_values on save_post) runs on the
 * product edit screen, which BrikPanel's save presents to other plugins, and
 * DELETES every field of all five boxes that is not in $_POST. So every
 * BrikPanel save removed the product's Porto layout, sidebars, tabs, custom CSS
 * and video. The guard detaches the handler when none of the boxes is on the
 * form, and otherwise fills the missing boxes' fields with their stored values.
 *
 * Source: plugins/porto-functionality/meta_boxes/product.php, meta_boxes.php,
 * themes/porto/inc/lib/video-thumbnail/init.php
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_Porto extends Brikpanel_Video_Provider {

    const HANDLER = 'porto_save_product_meta_values';

    /** Field types Porto always posts when its box is on the page. */
    const ALWAYS_POSTED = ['text', 'textarea', 'editor', 'select', 'upload', 'video', 'attach'];

    /** Keys this editor writes for a video. */
    const OWNED = ['porto_video_source', 'porto_video_youtube', 'porto_video_vimeo', 'porto_product_video_thumbnails', 'porto_video_sh_type', 'porto_video_pos'];

    public function id() {
        return 'porto';
    }

    public function label() {
        return __('Porto', 'brikpanel');
    }

    public function detected() {
        return get_template() === 'porto' && function_exists(self::HANDLER);
    }

    public function active() {
        return $this->detected() && function_exists('porto_product_video_meta_box');
    }

    public function kind() {
        return 'product';
    }

    public function sources() {
        return ['youtube', 'vimeo', 'file'];
    }

    public function file_exts() {
        // Played through WordPress's [video] shortcode.
        return ['mp4', 'm4v', 'webm', 'ogv'];
    }

    public function options() {
        $positions = [];
        foreach (['1', '2', '3', '4', '5', '6', '7'] as $n) {
            $positions[] = ['value' => $n, 'text' => $n];
        }
        $positions[] = ['value' => 'last', 'label' => 'video_opt_position_last'];
        return [
            [
                'key'     => 'display',
                'type'    => 'choice',
                'label'   => 'video_opt_display',
                'default' => 'popup',
                'choices' => [
                    ['value' => 'popup', 'label' => 'video_opt_display_popup'],
                    ['value' => 'slide', 'label' => 'video_opt_display_slide'],
                ],
            ],
            [
                'key'     => 'position',
                'type'    => 'choice',
                'label'   => 'video_opt_position',
                'default' => '1',
                'choices' => $positions,
                'show_if' => ['key' => 'display', 'eq' => 'slide'],
            ],
        ];
    }

    /**
     * Porto's fields per metabox: [box id => [field name => type]], read from
     * Porto's own definitions so new fields are covered.
     *
     * @return array
     */
    private static function groups() {
        $sources = [
            'product-meta-box'     => 'porto_product_meta_fields',
            'view-meta-box'        => 'porto_product_view_meta_fields',
            'skin-meta-box'        => 'porto_product_skin_meta_fields',
            'video-meta-box'       => 'porto_product_video_meta_box',
            'product-360-meta-box' => 'porto_product_360_degree',
        ];
        $groups = [];
        foreach ($sources as $box => $fn) {
            if (! function_exists($fn)) {
                continue;
            }
            // The two box renderers print unless told not to.
            $fields = in_array($fn, ['porto_product_video_meta_box', 'porto_product_360_degree'], true) ? $fn(false) : $fn();
            foreach ((array) $fields as $field) {
                if (is_array($field) && ! empty($field['name'])) {
                    $groups[$box][(string) $field['name']] = isset($field['type']) ? (string) $field['type'] : 'text';
                }
            }
        }
        return $groups;
    }

    public function read_product($product_id) {
        $product_id = (int) $product_id;
        $video      = Brikpanel_Video::blank($this);

        $display  = (string) get_post_meta($product_id, 'porto_video_sh_type', true);
        $position = (string) get_post_meta($product_id, 'porto_video_pos', true);
        $video['options']['display']  = $display === 'slide' ? 'slide' : 'popup';
        $video['options']['position'] = in_array($position, ['1', '2', '3', '4', '5', '6', '7', 'last'], true) ? $position : '1';

        $source = (string) get_post_meta($product_id, 'porto_video_source', true);
        if ($source === '' || $source === 'mp4') {
            $rows  = get_post_meta($product_id, 'porto_product_video_thumbnails');
            $first = ! empty($rows) ? (int) strtok((string) reset($rows), ',') : 0;
            if ($first && wp_attachment_is('video', $first)) {
                return Brikpanel_Video::from_file($video, $first);
            }
            if ($source === '') {
                $code = trim((string) get_post_meta($product_id, 'porto_product_video_thumbnail_shortcode', true));
                if ($code !== '') {
                    $video['has']    = true;
                    $video['source'] = 'other';
                    $video['other']  = $code;
                }
            }
            return $video;
        }
        if ($source === 'youtube' || $source === 'vimeo') {
            $link = trim((string) get_post_meta($product_id, 'porto_video_' . $source, true));
            if ($link === '') {
                return $video;
            }
            $ok = $source === 'youtube' ? Brikpanel_Video::youtube_ref($link) : Brikpanel_Video::vimeo_ref($link);
            $video['has'] = true;
            if ($ok) {
                $video['source'] = $source;
                $video[$source]  = $link;
            } else {
                $video['source'] = 'other';
                $video['other']  = $link;
            }
            return $video;
        }
        if ($source === 'shortcode') {
            $code = trim((string) get_post_meta($product_id, 'porto_product_video_thumbnail_shortcode', true));
            if ($code !== '') {
                $video['has']    = true;
                $video['source'] = 'other';
                $video['other']  = $code;
            }
        }
        return $video;
    }

    /**
     * The owned fields for a video: value, or null to delete.
     *
     * @param array|null $video Normalized video (null = remove).
     * @return array
     */
    private function values($video) {
        if ($video === null || empty($video['has'])) {
            return array_merge(
                array_fill_keys(['porto_video_source', 'porto_video_youtube', 'porto_video_vimeo', 'porto_product_video_thumbnails'], null),
                ['porto_product_video_thumbnail_shortcode' => null]
            );
        }
        $out = [
            'porto_video_sh_type' => $video['options']['display'] === 'slide' ? 'slide' : 'popup',
            'porto_video_pos'     => (string) $video['options']['position'],
        ];
        switch ($video['source']) {
            case 'youtube':
                $out += ['porto_video_source' => 'youtube', 'porto_video_youtube' => $video['youtube'], 'porto_video_vimeo' => null, 'porto_product_video_thumbnails' => null];
                break;
            case 'vimeo':
                $out += ['porto_video_source' => 'vimeo', 'porto_video_vimeo' => $video['vimeo'], 'porto_video_youtube' => null, 'porto_product_video_thumbnails' => null];
                break;
            case 'file':
                $out += ['porto_video_source' => 'mp4', 'porto_product_video_thumbnails' => (string) (int) $video['file_id'], 'porto_video_youtube' => null, 'porto_video_vimeo' => null];
                break;
            // 'other': the stored source is left exactly as it is.
        }
        return $out;
    }

    public function write_product($product_id, $video) {
        $product_id = (int) $product_id;
        foreach ($this->values($video) as $key => $value) {
            if ($value === null || $value === '') {
                delete_post_meta($product_id, $key);
            } else {
                update_post_meta($product_id, $key, $value);
            }
        }
    }

    public function mirror($product_id, array $video) {
        $out = [];
        foreach (self::OWNED as $key) {
            $out[$key] = (string) get_post_meta((int) $product_id, $key, true);
        }
        return $out;
    }

    public function guard_needed() {
        return true;
    }

    public function guard($product_id) {
        $groups = self::groups();
        if (! $groups || ! has_action('save_post', self::HANDLER)) {
            return;
        }

        $missing = [];
        $any     = false;
        foreach ($groups as $box => $fields) {
            $always = array_keys(array_filter($fields, static function ($type) {
                return in_array($type, self::ALWAYS_POSTED, true);
            }));
            if (Brikpanel_Video::box_rendered($box, $always)) {
                $any = true;
            } else {
                $missing[$box] = array_keys($fields);
            }
        }

        $change = Brikpanel_Video::pending_product_change($this);

        if (! $any) {
            // No Porto box on this form: its handler would delete every field.
            // The product video, if changed, is written after the save instead.
            remove_action('save_post', self::HANDLER, 10);
            return;
        }

        foreach ($missing as $names) {
            foreach ($names as $name) {
                if (! array_key_exists($name, $_POST) && metadata_exists('post', $product_id, $name)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                    Brikpanel_Video::set_post($name, wp_slash((string) get_post_meta($product_id, $name, true)));
                }
            }
        }
        if ($change !== null) {
            foreach ($this->values($change['video']) as $key => $value) {
                if ($value === null) {
                    Brikpanel_Video::unset_post($key);
                } else {
                    Brikpanel_Video::set_post($key, wp_slash($value));
                }
            }
        }
    }

    public function guard_fail_safe() {
        remove_action('save_post', self::HANDLER, 10);
    }
}
