<?php
/**
 * Blocksy (with Blocksy Companion Pro): videos on product images.
 *
 * Blocksy stores the video on the IMAGE (attachment) inside the serialized
 * `blocksy_post_meta_options` array, using `media_video_*` keys; very old
 * versions used a plain URL in `blocksy_media_video`. The theme's reader
 * (inc/components/media/video.php, blocksy_get_video_data) resolves both, but
 * the gallery only shows the video when Companion Pro provides `base_pro`
 * (inc/components/media/full.php), so the editor offers videos only then.
 *
 * Because the video lives on the image, it follows the image everywhere,
 * variation images included (Blocksy re-renders the gallery for a variation).
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

class Brikpanel_Video_Blocksy extends Brikpanel_Video_Provider {

    const META   = 'blocksy_post_meta_options';
    const LEGACY = 'blocksy_media_video';

    /** Every key Blocksy reads for a video; removing a video clears these. */
    const VIDEO_KEYS = [
        'media_video_source',
        'media_video_upload',
        'media_video_youtube_url',
        'media_video_vimeo_url',
        'media_video_event',
        'media_video_autoplay',
        'media_video_hover_revert',
        'media_video_loop',
        'media_video_player',
        'media_video_size',
    ];

    public function id() {
        return 'blocksy';
    }

    public function label() {
        return __('Blocksy', 'brikpanel');
    }

    public function detected() {
        $theme    = wp_get_theme();
        $detected = $theme->get_template() === 'blocksy'
            || $theme->get_stylesheet() === 'blocksy'
            || function_exists('blocksy_get_video_data');
        /**
         * Override Blocksy detection (e.g. a white-label fork that keeps the
         * same meta under another slug).
         *
         * @param bool $detected
         */
        return (bool) apply_filters('brikpanel_blocksy_video_active', $detected);
    }

    public function active() {
        return $this->detected() && self::has_pro();
    }

    /**
     * Whether Companion Pro provides the gallery video feature.
     *
     * @return bool
     */
    private static function has_pro() {
        try {
            if (function_exists('blocksy_manager')) {
                $manager = blocksy_manager();
                if (is_object($manager) && isset($manager->companion) && is_object($manager->companion) && is_callable([$manager->companion, 'has'])) {
                    if ($manager->companion->has('base_pro')) {
                        return true;
                    }
                }
            }
            if (function_exists('blocksy_companion_site_has_feature')) {
                return (bool) blocksy_companion_site_has_feature('base_pro');
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
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
        // Blocksy plays these in a <video> tag; anything else goes to oEmbed.
        return ['mp4', 'mov'];
    }

    public function keeps_youtube_shorts() {
        return true;
    }

    public function keeps_vimeo_hash() {
        return true;
    }

    public function options() {
        return [
            [
                'key'     => 'event',
                'type'    => 'choice',
                'label'   => 'video_playback',
                'default' => 'click',
                'choices' => [
                    ['value' => 'click', 'label' => 'video_on_click'],
                    ['value' => 'autoplay', 'label' => 'video_autoplay'],
                    ['value' => 'hover', 'label' => 'video_on_hover'],
                ],
            ],
            [
                'key'     => 'hover_revert',
                'type'    => 'switch',
                'label'   => 'video_opt_hover_revert',
                'default' => true,
                'show_if' => ['key' => 'event', 'eq' => 'hover'],
            ],
            [
                'key'     => 'loop',
                'type'    => 'switch',
                'label'   => 'video_loop',
                'default' => false,
            ],
            [
                'key'     => 'player',
                'type'    => 'switch',
                'label'   => 'video_simple_player',
                'default' => false,
            ],
            [
                'key'     => 'size',
                'type'    => 'choice',
                'label'   => 'video_opt_fit',
                'default' => 'contain',
                'choices' => [
                    ['value' => 'contain', 'label' => 'video_opt_fit_contain'],
                    ['value' => 'cover', 'label' => 'video_opt_fit_cover'],
                ],
                'show_if' => ['key' => 'player', 'eq' => true],
            ],
        ];
    }

    public function notes() {
        return [['key' => 'video_note_shared', 'when' => 'always']];
    }

    public function read_images($product_id, array $att_ids) {
        $out = [];
        foreach ($att_ids as $id) {
            $out[(int) $id] = $this->read_one((int) $id);
        }
        return $out;
    }

    private function read_one($id) {
        $video = Brikpanel_Video::blank($this);
        $opts  = get_post_meta($id, self::META, true);
        $opts  = is_array($opts) ? $opts : [];

        if ($opts) {
            $event = isset($opts['media_video_event']) ? (string) $opts['media_video_event'] : '';
            if (! in_array($event, ['click', 'autoplay', 'hover'], true)) {
                $event = (isset($opts['media_video_autoplay']) && $opts['media_video_autoplay'] === 'yes') ? 'autoplay' : 'click';
            }
            $video['options'] = [
                'event'        => $event,
                'hover_revert' => ! isset($opts['media_video_hover_revert']) || $opts['media_video_hover_revert'] !== 'no',
                'loop'         => isset($opts['media_video_loop']) && $opts['media_video_loop'] === 'yes',
                'player'       => isset($opts['media_video_player']) && $opts['media_video_player'] === 'yes',
                'size'         => (isset($opts['media_video_size']) && $opts['media_video_size'] === 'cover') ? 'cover' : 'contain',
            ];

            // Same rules as blocksy_get_video_data(): a missing source means upload.
            $source = isset($opts['media_video_source']) ? (string) $opts['media_video_source'] : 'upload';
            if ($source === 'youtube') {
                $url = isset($opts['media_video_youtube_url']) ? (string) $opts['media_video_youtube_url'] : '';
                if ($url !== '' && (strpos($url, 'youtube') !== false || strpos($url, 'youtu.be') !== false)) {
                    $video['has']     = true;
                    $video['source']  = 'youtube';
                    $video['youtube'] = $url;
                }
            } elseif ($source === 'vimeo') {
                $url = isset($opts['media_video_vimeo_url']) ? (string) $opts['media_video_vimeo_url'] : '';
                if ($url !== '' && strpos($url, 'vimeo') !== false) {
                    $video['has']    = true;
                    $video['source'] = 'vimeo';
                    $video['vimeo']  = $url;
                }
            } else {
                $upload = isset($opts['media_video_upload']) ? $opts['media_video_upload'] : '';
                if (is_numeric($upload) && (int) $upload > 0) {
                    $video = Brikpanel_Video::from_file($video, (int) $upload);
                } elseif (is_string($upload) && trim($upload) !== '') {
                    // A link stored as "upload": a direct file, or any link
                    // Blocksy hands to oEmbed. Kept exactly as stored.
                    $link = trim($upload);
                    if (Brikpanel_Video::file_link($link, $this->file_exts()) !== '') {
                        $video['has']    = true;
                        $video['source'] = 'url';
                        $video['url']    = $link;
                    } else {
                        $video['has']    = true;
                        $video['source'] = 'other';
                        $video['other']  = $link;
                    }
                }
            }
            return $video;
        }

        // Very old Blocksy: one URL of any kind.
        $legacy = trim((string) get_post_meta($id, self::LEGACY, true));
        if ($legacy !== '') {
            $video = Brikpanel_Video::from_link($video, $legacy, $this);
        }
        return $video;
    }

    public function write_images($product_id, array $changes) {
        foreach ($changes as $id => $video) {
            $id = (int) $id;
            if (! $id || get_post_type($id) !== 'attachment') {
                continue;
            }
            $opts = get_post_meta($id, self::META, true);
            $opts = is_array($opts) ? $opts : [];

            if ($video === null) {
                foreach (self::VIDEO_KEYS as $key) {
                    unset($opts[$key]);
                }
                if ($opts) {
                    update_post_meta($id, self::META, $opts);
                } else {
                    delete_post_meta($id, self::META);
                }
                delete_post_meta($id, self::LEGACY);
                continue;
            }

            $o = $video['options'];
            switch ($video['source']) {
                case 'youtube':
                    $opts['media_video_source']      = 'youtube';
                    $opts['media_video_youtube_url'] = $video['youtube'];
                    break;
                case 'vimeo':
                    $opts['media_video_source']    = 'vimeo';
                    $opts['media_video_vimeo_url'] = $video['vimeo'];
                    break;
                case 'file':
                    $opts['media_video_source'] = 'upload';
                    $opts['media_video_upload'] = (int) $video['file_id'];
                    break;
                case 'url':
                    $opts['media_video_source'] = 'upload';
                    $opts['media_video_upload'] = $video['url'];
                    break;
                case 'other':
                    // Unchanged link (for example from the legacy meta), stored
                    // the way Blocksy reads a link: as an upload string.
                    $opts['media_video_source'] = 'upload';
                    $opts['media_video_upload'] = $video['other'];
                    break;
                default:
                    continue 2;
            }
            $event                            = in_array($o['event'], ['click', 'autoplay', 'hover'], true) ? $o['event'] : 'click';
            $opts['media_video_event']        = $event;
            $opts['media_video_autoplay']     = $event === 'autoplay' ? 'yes' : 'no';
            $opts['media_video_hover_revert'] = ! empty($o['hover_revert']) ? 'yes' : 'no';
            $opts['media_video_loop']         = ! empty($o['loop']) ? 'yes' : 'no';
            $opts['media_video_player']       = ! empty($o['player']) ? 'yes' : 'no';
            $opts['media_video_size']         = $o['size'] === 'cover' ? 'cover' : 'contain';

            update_post_meta($id, self::META, $opts);
            // The options array now carries the video, which supersedes the
            // legacy link; delete it only after the new one is stored.
            delete_post_meta($id, self::LEGACY);
        }
    }
}
