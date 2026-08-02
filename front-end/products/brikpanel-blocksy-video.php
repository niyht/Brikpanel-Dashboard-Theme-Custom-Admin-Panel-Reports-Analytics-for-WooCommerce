<?php
/**
 * Blocksy product-video bridge.
 *
 * The Blocksy theme (and its Pro companion) lets a store attach a video to any
 * product image so the single-product gallery plays it. Blocksy stores that
 * video on the *attachment* (the media item) inside the serialized
 * `blocksy_post_meta_options` meta array, using a handful of `media_video_*`
 * keys. Its editing UI, however, only appears inside the native WordPress media
 * modal, so it is invisible from BrikPanel's own product editor.
 *
 * This bridge reads and writes exactly the same attachment meta Blocksy reads
 * at render time (see the theme's inc/components/media/video.php), which means
 * videos set here play on the front-end through Blocksy with no extra glue, and
 * videos set through Blocksy's own UI show up in BrikPanel. Everything is gated
 * on Blocksy actually being the active theme so the UI never appears elsewhere.
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The single attachment meta key Blocksy stores per-post options under.
 */
if (! defined('BRIKPANEL_BLOCKSY_META')) {
    define('BRIKPANEL_BLOCKSY_META', 'blocksy_post_meta_options');
}

/**
 * Whether the Blocksy video feature applies on this site.
 *
 * True when Blocksy is the active theme or the parent template of the active
 * child theme. We key off the template slug rather than a function so the
 * result is stable regardless of theme load order on admin screens.
 *
 * @return bool
 */
function brikpanel_blocksy_video_active() {
    static $active = null;

    if ($active !== null) {
        return $active;
    }

    // Primary signal: the active theme (or its parent, for child themes) is
    // Blocksy. Secondary signal: Blocksy's own video reader is loaded — this
    // catches installs where the theme folder was renamed but the theme is
    // genuinely Blocksy, so the panel button never silently fails to appear.
    $theme  = wp_get_theme();
    $active = (
        $theme->get_template() === 'blocksy'
        || $theme->get_stylesheet() === 'blocksy'
        || function_exists('blocksy_get_video_data')
        || function_exists('blocksy_get_post_options')
    );

    /**
     * Allow overriding the Blocksy video detection (e.g. white-label forks of
     * Blocksy that keep the same meta contract under a different slug).
     *
     * @param bool $active
     */
    $active = (bool) apply_filters('brikpanel_blocksy_video_active', $active);

    return $active;
}

/**
 * Allowed video sources, mirroring Blocksy's media_video_source values.
 *
 * @return string[]
 */
function brikpanel_blocksy_video_sources() {
    return ['youtube', 'vimeo', 'upload'];
}

/**
 * Allowed play events, mirroring Blocksy's media_video_event values.
 *
 * @return string[]
 */
function brikpanel_blocksy_video_events() {
    return ['click', 'autoplay', 'hover'];
}

/**
 * Read the normalized video record for a single attachment.
 *
 * The shape is the one the editor's JS consumes; `has` reflects whether the
 * attachment currently resolves to a playable video for its chosen source,
 * matching Blocksy's own blocksy_get_video_data() gate.
 *
 * @param int $attachment_id
 * @return array
 */
function brikpanel_blocksy_get_video_for_attachment($attachment_id) {
    $attachment_id = (int) $attachment_id;

    $out = [
        'has'         => false,
        'source'      => 'youtube',
        'upload'      => 0,
        'upload_url'  => '',
        'upload_name' => '',
        'youtube'     => '',
        'vimeo'       => '',
        'event'       => 'click',
        'loop'        => false,
        'player'      => false,
    ];

    if (! $attachment_id) {
        return $out;
    }

    $opts = get_post_meta($attachment_id, BRIKPANEL_BLOCKSY_META, true);
    if (! is_array($opts)) {
        $opts = [];
    }

    // Legacy single-URL storage from very old Blocksy versions. Only relevant
    // when the modern options array carries no video of its own.
    $legacy = get_post_meta($attachment_id, 'blocksy_media_video', true);

    $has_modern = isset($opts['media_video_source'])
        || isset($opts['media_video_upload'])
        || isset($opts['media_video_youtube_url'])
        || isset($opts['media_video_vimeo_url']);

    if (! $has_modern && ! empty($legacy)) {
        // Surface the legacy URL as a self-hosted/upload URL so it is editable.
        $out['has']        = true;
        $out['source']     = 'upload';
        $out['upload_url'] = (string) $legacy;
        $out['upload_name'] = wp_basename((string) $legacy);
        return $out;
    }

    $source = isset($opts['media_video_source']) ? (string) $opts['media_video_source'] : '';
    if (! in_array($source, brikpanel_blocksy_video_sources(), true)) {
        $source = '';
    }

    $youtube = isset($opts['media_video_youtube_url']) ? (string) $opts['media_video_youtube_url'] : '';
    $vimeo   = isset($opts['media_video_vimeo_url']) ? (string) $opts['media_video_vimeo_url'] : '';
    $upload  = isset($opts['media_video_upload']) ? $opts['media_video_upload'] : '';

    $out['youtube'] = $youtube;
    $out['vimeo']   = $vimeo;

    if (is_numeric($upload) && (int) $upload > 0) {
        $out['upload']      = (int) $upload;
        $out['upload_url']  = (string) wp_get_attachment_url((int) $upload);
        $out['upload_name'] = get_the_title((int) $upload);
    } elseif (is_string($upload) && $upload !== '') {
        // A raw URL was stored instead of an attachment id.
        $out['upload_url']  = $upload;
        $out['upload_name'] = wp_basename($upload);
    }

    // Resolve the effective source the same way Blocksy does, then decide
    // whether that source actually holds a value.
    if ($source === '') {
        // No explicit source: infer from whatever is populated.
        if ($youtube !== '') {
            $source = 'youtube';
        } elseif ($vimeo !== '') {
            $source = 'vimeo';
        } elseif ($out['upload_url'] !== '') {
            $source = 'upload';
        } else {
            $source = 'youtube';
        }
    }

    $out['source'] = $source;

    if ($source === 'upload') {
        $out['has'] = ($out['upload_url'] !== '');
    } elseif ($source === 'youtube') {
        $out['has'] = ($youtube !== '' && (strpos($youtube, 'youtube') !== false || strpos($youtube, 'youtu.be') !== false));
    } elseif ($source === 'vimeo') {
        $out['has'] = ($vimeo !== '' && strpos($vimeo, 'vimeo') !== false);
    }

    // Play behaviour. Fall back to the legacy autoplay boolean when the newer
    // event key is missing, exactly as Blocksy's render path does.
    $event = isset($opts['media_video_event']) ? (string) $opts['media_video_event'] : '';
    if (! in_array($event, brikpanel_blocksy_video_events(), true)) {
        $legacy_autoplay = isset($opts['media_video_autoplay']) ? (string) $opts['media_video_autoplay'] : 'no';
        $event = ($legacy_autoplay === 'yes') ? 'autoplay' : 'click';
    }
    $out['event'] = $event;

    $out['loop']   = (isset($opts['media_video_loop']) && $opts['media_video_loop'] === 'yes');
    $out['player'] = (isset($opts['media_video_player']) && $opts['media_video_player'] === 'yes');

    return $out;
}

/**
 * Persist (or clear) the video for a single attachment.
 *
 * Only the `media_video_*` keys are touched; any other Blocksy option stored on
 * the attachment is preserved by merging into the existing options array.
 *
 * @param int   $attachment_id
 * @param array $raw Untrusted input (source, upload, youtube, vimeo, event,
 *                   loop, player, has). When `has` is falsy the video is cleared.
 * @return void
 */
function brikpanel_blocksy_save_video_for_attachment($attachment_id, $raw) {
    $attachment_id = (int) $attachment_id;
    if (! $attachment_id || get_post_type($attachment_id) !== 'attachment') {
        return;
    }

    $opts = get_post_meta($attachment_id, BRIKPANEL_BLOCKSY_META, true);
    if (! is_array($opts)) {
        $opts = [];
    }

    $video_keys = [
        'media_video_source',
        'media_video_upload',
        'media_video_youtube_url',
        'media_video_vimeo_url',
        'media_video_event',
        'media_video_autoplay',
        'media_video_loop',
        'media_video_player',
    ];

    $source  = isset($raw['source']) ? sanitize_key($raw['source']) : '';
    $has_raw = isset($raw['has']) ? $raw['has'] : '';
    $has     = ($has_raw === true || $has_raw === 1 || $has_raw === '1' || $has_raw === 'true');

    if (! in_array($source, brikpanel_blocksy_video_sources(), true)) {
        $has = false;
    }

    if (! $has) {
        // Clearing: drop only the video keys and the legacy single-URL meta.
        $had_any = ! empty($legacy = get_post_meta($attachment_id, 'blocksy_media_video', true));
        foreach ($video_keys as $key) {
            if (array_key_exists($key, $opts)) {
                unset($opts[$key]);
                $had_any = true;
            }
        }

        if (! $had_any) {
            return; // Nothing to remove — avoid a needless write.
        }

        delete_post_meta($attachment_id, 'blocksy_media_video');

        if (empty($opts)) {
            delete_post_meta($attachment_id, BRIKPANEL_BLOCKSY_META);
        } else {
            update_post_meta($attachment_id, BRIKPANEL_BLOCKSY_META, $opts);
        }
        return;
    }

    $event = isset($raw['event']) ? sanitize_key($raw['event']) : 'click';
    if (! in_array($event, brikpanel_blocksy_video_events(), true)) {
        $event = 'click';
    }

    $loop   = ! empty($raw['loop']) && $raw['loop'] !== '0' && $raw['loop'] !== 'false';
    $player = ! empty($raw['player']) && $raw['player'] !== '0' && $raw['player'] !== 'false';

    $opts['media_video_source']   = $source;
    $opts['media_video_event']    = $event;
    // Keep the legacy autoplay boolean in sync for older render paths.
    $opts['media_video_autoplay'] = ($event === 'autoplay') ? 'yes' : 'no';
    $opts['media_video_loop']     = $loop ? 'yes' : 'no';
    $opts['media_video_player']   = $player ? 'yes' : 'no';

    if ($source === 'upload') {
        $upload = isset($raw['upload']) ? $raw['upload'] : '';
        if (is_numeric($upload)) {
            $opts['media_video_upload'] = (int) $upload;
        } else {
            $opts['media_video_upload'] = esc_url_raw((string) $upload);
        }
    } elseif ($source === 'youtube') {
        $opts['media_video_youtube_url'] = esc_url_raw((string) ($raw['youtube'] ?? ''));
    } elseif ($source === 'vimeo') {
        $opts['media_video_vimeo_url'] = esc_url_raw((string) ($raw['vimeo'] ?? ''));
    }

    // A freshly written options array supersedes any legacy single-URL meta.
    delete_post_meta($attachment_id, 'blocksy_media_video');

    update_post_meta($attachment_id, BRIKPANEL_BLOCKSY_META, $opts);
}
