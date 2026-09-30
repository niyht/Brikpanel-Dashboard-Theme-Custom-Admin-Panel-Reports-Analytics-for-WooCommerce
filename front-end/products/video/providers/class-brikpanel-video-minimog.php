<?php
/**
 * Minimog: videos on product images.
 *
 * Minimog stores the video on the IMAGE (attachment) in two meta keys:
 * `minimog_product_attachment_type` ('video', '360' or '') and
 * `minimog_product_video` (any oEmbed link, or a link to an .mp4 file; its media
 * picker stores the file URL, not the id). The gallery shows a play icon and
 * opens the video in lightGallery. Variation galleries use the same slide
 * builder, so a video on a variation image plays too.
 *
 * Source: themes/minimog/framework/woocommerce/product-thumb-media.php,
 * framework/class-woo.php
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_Minimog extends Brikpanel_Video_Provider {

    const TYPE = 'minimog_product_attachment_type';
    const URL  = 'minimog_product_video';

    public function id() {
        return 'minimog';
    }

    public function label() {
        return __('Minimog', 'brikpanel');
    }

    public function detected() {
        return class_exists('Minimog\Woo\Product_Thumb_Media', false);
    }

    public function storage() {
        return 'attachment';
    }

    public function slots() {
        return ['featured', 'gallery', 'variation'];
    }

    public function sources() {
        return ['youtube', 'vimeo', 'file', 'url'];
    }

    public function file_exts() {
        // Minimog treats a link as a file only when it contains "mp4".
        return ['mp4'];
    }

    public function notes() {
        return [
            ['key' => 'video_note_shared', 'when' => 'always'],
            ['key' => 'video_note_minimog_variation', 'when' => 'variation'],
        ];
    }

    public function read_images($product_id, array $att_ids) {
        $out = [];
        foreach ($att_ids as $id) {
            $id    = (int) $id;
            $video = Brikpanel_Video::blank($this);
            $type  = (string) get_post_meta($id, self::TYPE, true);
            $url   = trim((string) get_post_meta($id, self::URL, true));
            if ($type === 'video' && $url !== '') {
                $video = Brikpanel_Video::from_link($video, $url, $this);
            } elseif ($type === '360') {
                $video['note'] = 'video_note_360';
            }
            $out[$id] = $video;
        }
        return $out;
    }

    public function write_images($product_id, array $changes) {
        foreach ($changes as $id => $video) {
            $id = (int) $id;
            if (! $id || get_post_type($id) !== 'attachment') {
                continue;
            }
            if ($video === null) {
                // Only undo what a video set: a 360° image keeps its type.
                if ((string) get_post_meta($id, self::TYPE, true) === 'video') {
                    delete_post_meta($id, self::TYPE);
                }
                delete_post_meta($id, self::URL);
                continue;
            }
            $link = Brikpanel_Video::link_of($video);
            if ($link === '') {
                continue;
            }
            update_post_meta($id, self::URL, $link);
            update_post_meta($id, self::TYPE, 'video');
        }
    }
}
