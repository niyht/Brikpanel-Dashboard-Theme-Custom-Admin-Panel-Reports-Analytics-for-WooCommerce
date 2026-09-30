<?php
/**
 * WoodMart: product gallery video.
 *
 * WoodMart keeps one settings array per gallery image in a single PRODUCT meta,
 * `woodmart_wc_video_gallery`, keyed by the image's attachment id (featured
 * image included). Its classic-screen popup writes every key as a string; this
 * provider writes the same shape. Its own save handler
 * (Main::save_product_gallery on woocommerce_process_product_meta) returns early
 * when its POST key is missing, which a BrikPanel save never sends, so no guard
 * is needed. The storefront does not play gallery videos on variation images.
 *
 * Source: themes/woodmart/inc/integrations/woocommerce/modules/product-gallery-video/class-main.php
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_WoodMart extends Brikpanel_Video_Provider {

    const META = 'woodmart_wc_video_gallery';

    /** WoodMart's own defaults (Main::$default_settings). */
    const DEFAULTS = [
        'video_type'       => 'mp4',
        'upload_video_id'  => '',
        'upload_video_url' => '',
        'youtube_url'      => '',
        'vimeo_url'        => '',
        'autoplay'         => '0',
        'video_size'       => 'contain',
        'video_control'    => 'theme',
        'hide_gallery_img' => '0',
        'hide_information' => '0',
        'audio_status'     => 'unmute',
    ];

    public function id() {
        return 'woodmart';
    }

    public function label() {
        return __('WoodMart', 'brikpanel');
    }

    public function detected() {
        return class_exists('XTS\Modules\Product_Gallery_Video\Main', false);
    }

    public function active() {
        if (! $this->detected()) {
            return false;
        }
        return function_exists('woodmart_get_opt')
            ? (bool) woodmart_get_opt('single_product_main_gallery_video', true)
            : true;
    }

    public function sources() {
        return ['youtube', 'vimeo', 'file'];
    }

    public function file_exts() {
        return ['mp4', 'm4v', 'webm', 'ogv'];
    }

    public function keeps_youtube_shorts() {
        return true;
    }

    public function options() {
        return [
            [
                'key'     => 'video_control',
                'type'    => 'choice',
                'label'   => 'video_opt_player',
                'default' => 'theme',
                'choices' => [
                    ['value' => 'theme', 'label' => 'video_opt_player_theme'],
                    ['value' => 'native', 'label' => 'video_opt_player_native'],
                ],
            ],
            [
                'key'     => 'video_size',
                'type'    => 'choice',
                'label'   => 'video_opt_fit',
                'default' => 'contain',
                'choices' => [
                    ['value' => 'contain', 'label' => 'video_opt_fit_contain'],
                    ['value' => 'cover', 'label' => 'video_opt_fit_cover'],
                ],
                'show_if' => ['key' => 'video_control', 'eq' => 'theme'],
            ],
            [
                'key'     => 'hide_gallery_img',
                'type'    => 'switch',
                'label'   => 'video_opt_hide_image',
                'default' => false,
                'show_if' => ['key' => 'video_control', 'eq' => 'native'],
            ],
            [
                'key'     => 'autoplay',
                'type'    => 'switch',
                'label'   => 'video_opt_autoplay',
                'default' => false,
            ],
            [
                'key'     => 'audio_status',
                'type'    => 'choice',
                'label'   => 'video_opt_sound',
                'default' => 'unmute',
                'choices' => [
                    ['value' => 'unmute', 'label' => 'video_opt_sound_on'],
                    ['value' => 'mute', 'label' => 'video_opt_sound_off'],
                ],
                'show_if' => ['key' => 'autoplay', 'eq' => false],
            ],
            [
                'key'     => 'hide_information',
                'type'    => 'switch',
                'label'   => 'video_opt_hide_overlay',
                'default' => false,
            ],
        ];
    }

    private function map($product_id) {
        $map = get_post_meta((int) $product_id, self::META, true);
        return is_array($map) ? $map : [];
    }

    public function read_images($product_id, array $att_ids) {
        $map = $this->map($product_id);
        $out = [];
        foreach ($att_ids as $id) {
            $id    = (int) $id;
            $video = Brikpanel_Video::blank($this);
            if (! empty($map[$id]) && is_array($map[$id])) {
                $s = array_merge(self::DEFAULTS, $map[$id]);

                $video['options'] = [
                    'video_control'    => $s['video_control'] === 'native' ? 'native' : 'theme',
                    'video_size'       => $s['video_size'] === 'cover' ? 'cover' : 'contain',
                    'hide_gallery_img' => (string) $s['hide_gallery_img'] === '1',
                    'autoplay'         => (string) $s['autoplay'] === '1',
                    'audio_status'     => $s['audio_status'] === 'mute' ? 'mute' : 'unmute',
                    'hide_information' => (string) $s['hide_information'] === '1',
                ];

                // The same test WoodMart runs before it shows a video
                // (Main::check_is_available_video).
                $type = (string) $s['video_type'];
                if ($type === 'mp4' && ! empty($s['upload_video_id']) && wp_attachment_is('video', (int) $s['upload_video_id'])) {
                    $video           = Brikpanel_Video::from_file($video, (int) $s['upload_video_id']);
                    $video['source'] = 'file';
                } elseif ($type === 'youtube' && (stripos((string) $s['youtube_url'], 'youtu.be/') !== false || stripos((string) $s['youtube_url'], 'youtube.com/') !== false)) {
                    $video['has']     = true;
                    $video['source']  = 'youtube';
                    $video['youtube'] = (string) $s['youtube_url'];
                } elseif ($type === 'vimeo' && stripos((string) $s['vimeo_url'], 'vimeo.com/') !== false) {
                    $video['has']    = true;
                    $video['source'] = 'vimeo';
                    $video['vimeo']  = (string) $s['vimeo_url'];
                }
            }
            $out[$id] = $video;
        }
        return $out;
    }

    public function write_images($product_id, array $changes) {
        $product_id = (int) $product_id;
        $map        = $this->map($product_id);
        foreach ($changes as $id => $video) {
            $id = (int) $id;
            if ($video === null) {
                unset($map[$id]);
                continue;
            }
            // Merge onto the stored entry so keys a newer WoodMart adds survive.
            $entry = array_merge(self::DEFAULTS, isset($map[$id]) && is_array($map[$id]) ? $map[$id] : []);
            $o     = $video['options'];

            $entry['video_control']    = $o['video_control'] === 'native' ? 'native' : 'theme';
            $entry['video_size']       = $o['video_size'] === 'cover' ? 'cover' : 'contain';
            $entry['hide_gallery_img'] = ! empty($o['hide_gallery_img']) ? '1' : '0';
            $entry['autoplay']         = ! empty($o['autoplay']) ? '1' : '0';
            $entry['audio_status']     = $o['audio_status'] === 'mute' ? 'mute' : 'unmute';
            $entry['hide_information'] = ! empty($o['hide_information']) ? '1' : '0';

            switch ($video['source']) {
                case 'youtube':
                    $entry['video_type']  = 'youtube';
                    $entry['youtube_url'] = $video['youtube'];
                    break;
                case 'vimeo':
                    $entry['video_type'] = 'vimeo';
                    $entry['vimeo_url']  = $video['vimeo'];
                    break;
                case 'file':
                    // WoodMart plays upload_video_url as it is, so both are needed.
                    $entry['video_type']       = 'mp4';
                    $entry['upload_video_id']  = (string) (int) $video['file_id'];
                    $entry['upload_video_url'] = (string) $video['file_url'];
                    break;
                default:
                    continue 2;
            }
            $map[$id] = array_map('strval', $entry);
        }
        if ($map) {
            update_post_meta($product_id, self::META, $map);
        } else {
            delete_post_meta($product_id, self::META);
        }
    }
}
