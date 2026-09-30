<?php
/**
 * CommerceKit (Shoptimizer's companion plugin): product gallery videos.
 *
 * With its product gallery module on, CommerceKit replaces the theme's gallery
 * and keeps one PRODUCT meta, `commercekit_wc_video_gallery`, mapping a gallery
 * image's attachment id to "URL::1" (autoplay) or "URL::0". Its admin screen
 * only offers videos on gallery images, not on the featured image.
 *
 * Its save handler (commercegurus_save_product_video_gallery on
 * woocommerce_process_product_meta) runs whenever its nonce is posted, and the
 * nonce lives in a product-data panel BrikPanel can show, while the per-image
 * inputs come from a hook BrikPanel never renders. So a BrikPanel save with that
 * panel on the page rebuilt the map from nothing and wiped every video. The
 * guard keeps that handler from running unless its own inputs were posted.
 *
 * Source: plugins/commercegurus-commercekit/includes/commercegurus-video-gallery-functions.php
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_CommerceKit extends Brikpanel_Video_Provider {

    const META     = 'commercekit_wc_video_gallery';
    const HANDLER  = 'commercegurus_save_product_video_gallery';
    const POST_KEY = 'commercekit_wc_video_gallery';

    public function id() {
        return 'commercekit';
    }

    public function label() {
        return __('CommerceKit', 'brikpanel');
    }

    /**
     * The video functions only load while a CommerceKit gallery module is on,
     * which is exactly when its gallery (and its videos) show on the product page.
     */
    public function detected() {
        return function_exists(self::HANDLER);
    }

    /**
     * Below the themes. CommerceKit swaps the gallery through WooCommerce's
     * standard product-page hooks, which Shoptimizer (its own theme) and
     * standard themes run, but a theme that builds its own product page keeps
     * its own gallery: on WoodMart with CommerceKit's gallery module on, the
     * product page still showed WoodMart's gallery (checked 2026-09-25). A theme
     * with its own video feature therefore owns the videos.
     */
    public function priority() {
        return 40;
    }

    public function slots() {
        return ['gallery'];
    }

    public function sources() {
        return ['youtube', 'vimeo', 'file', 'url'];
    }

    public function file_exts() {
        return ['mp4', 'webm'];
    }

    public function keeps_youtube_shorts() {
        return true;
    }

    public function keeps_vimeo_hash() {
        return true;
    }

    private function default_autoplay() {
        $settings = get_option('commercekit', []);
        if (is_array($settings) && array_key_exists('pdp_video_autoplay', $settings)) {
            return (int) $settings['pdp_video_autoplay'] === 1;
        }
        return true;
    }

    public function options() {
        return [
            [
                'key'     => 'autoplay',
                'type'    => 'switch',
                'label'   => 'video_opt_autoplay',
                'default' => $this->default_autoplay(),
            ],
        ];
    }

    public function notes() {
        return [['key' => 'video_note_gallery_only', 'when' => 'featured']];
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
            if (isset($map[$id]) && is_string($map[$id]) && trim($map[$id]) !== '') {
                $raw  = trim($map[$id]);
                $flag = null;
                $pos  = strrpos($raw, '::');
                if ($pos !== false) {
                    $flag = substr($raw, $pos + 2);
                    $raw  = substr($raw, 0, $pos);
                }
                if ($flag === '1' || $flag === '0') {
                    $video['options']['autoplay'] = $flag === '1';
                }
                $video = Brikpanel_Video::from_link($video, $raw, $this);
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
            $link = Brikpanel_Video::link_of($video);
            if ($link === '') {
                continue;
            }
            $map[$id] = $link . '::' . (! empty($video['options']['autoplay']) ? '1' : '0');
        }
        if ($map) {
            update_post_meta($product_id, self::META, $map);
        } else {
            delete_post_meta($product_id, self::META);
        }
    }

    public function guard_needed() {
        return true;
    }

    public function guard($product_id) {
        // Its inputs never travel with a BrikPanel save; without them its
        // handler would store an empty map over the real one.
        if (! array_key_exists(self::POST_KEY, $_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            remove_action('woocommerce_process_product_meta', self::HANDLER, 10);
        }
    }

    public function guard_fail_safe() {
        remove_action('woocommerce_process_product_meta', self::HANDLER, 10);
    }
}
