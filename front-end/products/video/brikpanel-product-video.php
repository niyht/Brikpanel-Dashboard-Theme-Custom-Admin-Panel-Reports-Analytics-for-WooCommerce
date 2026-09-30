<?php
/**
 * Product videos in the BrikPanel product editor.
 *
 * Several popular themes and plugins let a store play a video in the product
 * gallery, each with its own storage (see the classes in providers/). This file
 * picks the one active on the site, hands the editor a schema for its dialog,
 * reads and writes videos through it, and keeps a BrikPanel save from wiping
 * data those themes store alongside (their save handlers treat a missing form
 * field as "clear it", and BrikPanel's form does not carry their fields).
 *
 * Loaded on admin and admin-ajax requests only. Nothing runs at load time;
 * provider classes load on the first providers() call.
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-brikpanel-video-provider.php';

/**
 * Whether the editor shows product video controls.
 *
 * They appear automatically when a supported theme or plugin is active. The
 * save guards that stop a save from wiping theme data run either way.
 *
 * @return bool
 */
function brikpanel_product_video_enabled() {
    static $on = null;
    if ($on === null) {
        /**
         * Show the product video controls in the BrikPanel product editor.
         *
         * @param bool $enabled Default true.
         */
        $on = (bool) apply_filters('brikpanel_product_video_enabled', true);
    }
    return $on;
}

final class Brikpanel_Video {

    /** Posted JSON limits: bytes, nesting depth, entries. */
    const MAX_JSON_BYTES = 65536;
    const MAX_JSON_DEPTH = 4;
    const MAX_ENTRIES    = 500;

    /** @var Brikpanel_Video_Provider[]|null */
    private static $providers = null;

    /** @var Brikpanel_Video_Provider|null|false false = not resolved yet */
    private static $active = false;

    /** @var array Original $_POST values changed by guards, restored by end_dispatch(). */
    private static $post_backup = [];

    /** @var array<string,bool>|null Ids of third-party panels and metaboxes the form carried. */
    private static $rendered_boxes = null;

    /** @var array|null Parsed product-video change of this request (kind 'product'). */
    private static $pending_product = null;

    /** @var array<string,int> attachment_url_to_postid() results of this request. */
    private static $url_ids = [];

    // ------------------------------------------------------------------
    // Providers
    // ------------------------------------------------------------------

    /**
     * All known providers, highest priority first.
     *
     * @return Brikpanel_Video_Provider[]
     */
    public static function providers() {
        if (self::$providers !== null) {
            return self::$providers;
        }
        $map = [
            'commercekit' => 'Brikpanel_Video_CommerceKit',
            'woodmart'    => 'Brikpanel_Video_WoodMart',
            'blocksy'     => 'Brikpanel_Video_Blocksy',
            'minimog'     => 'Brikpanel_Video_Minimog',
            'flatsome'    => 'Brikpanel_Video_Flatsome',
            'porto'       => 'Brikpanel_Video_Porto',
        ];
        $list = [];
        foreach ($map as $slug => $class) {
            $file = __DIR__ . '/providers/class-brikpanel-video-' . $slug . '.php';
            if (! class_exists($class, false) && is_readable($file)) {
                require_once $file;
            }
            if (class_exists($class, false)) {
                $list[] = new $class();
            }
        }
        /**
         * Filter the product-video providers.
         *
         * @param Brikpanel_Video_Provider[] $list Provider objects.
         */
        $list = apply_filters('brikpanel_video_providers', $list);
        $list = array_values(array_filter((array) $list, static function ($p) {
            return $p instanceof Brikpanel_Video_Provider;
        }));
        usort($list, static function ($a, $b) {
            return (int) $b->priority() <=> (int) $a->priority();
        });
        self::$providers = $list;
        return $list;
    }

    /**
     * The provider whose storefront plays product videos on this site.
     *
     * @return Brikpanel_Video_Provider|null
     */
    public static function active_provider() {
        if (self::$active !== false) {
            return self::$active;
        }
        $found = null;
        $ids   = [];
        foreach (self::providers() as $p) {
            try {
                if ($p->active()) {
                    $ids[] = $p->id();
                    if ($found === null) {
                        $found = $p;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        /**
         * Pick which active provider the editor uses.
         *
         * @param string   $id         Chosen provider id, '' for none.
         * @param string[] $active_ids Every active provider id, highest priority first.
         */
        $pick = apply_filters('brikpanel_video_provider', $found ? $found->id() : '', $ids);
        self::$active = null;
        if (is_string($pick) && $pick !== '' && in_array($pick, $ids, true)) {
            foreach (self::providers() as $p) {
                if ($p->id() === $pick) {
                    self::$active = $p;
                    break;
                }
            }
        }
        return self::$active;
    }

    /**
     * The provider the editor shows controls for, or null.
     *
     * @return Brikpanel_Video_Provider|null
     */
    public static function ui_provider() {
        return brikpanel_product_video_enabled() ? self::active_provider() : null;
    }

    // ------------------------------------------------------------------
    // Normalized model
    // ------------------------------------------------------------------

    /**
     * An empty video in the editor's shape, with the provider's option defaults.
     *
     * @param Brikpanel_Video_Provider|null $provider Provider.
     * @return array
     */
    public static function blank($provider = null) {
        $options = [];
        if ($provider instanceof Brikpanel_Video_Provider) {
            foreach ($provider->options() as $o) {
                $options[$o['key']] = $o['default'];
            }
        }
        return [
            'has'       => false,
            'source'    => '',
            'youtube'   => '',
            'vimeo'     => '',
            'file_id'   => 0,
            'file_url'  => '',
            'file_name' => '',
            'url'       => '',
            'other'     => '',
            'options'   => $options,
        ];
    }

    /**
     * Fills a video from one stored link: YouTube, Vimeo, a media-library
     * file, a direct file link, or anything else (kept as it is, read-only).
     *
     * @param array                    $video    Normalized video to fill.
     * @param string                   $url      Stored link.
     * @param Brikpanel_Video_Provider $provider Provider.
     * @return array
     */
    public static function from_link(array $video, $url, Brikpanel_Video_Provider $provider) {
        $url = trim((string) $url);
        if ($url === '') {
            return $video;
        }
        $video['has'] = true;
        $yt = self::youtube_ref($url);
        if ($yt) {
            $video['source']  = 'youtube';
            $video['youtube'] = $url;
            return $video;
        }
        $vm = self::vimeo_ref($url);
        if ($vm) {
            $video['source'] = 'vimeo';
            $video['vimeo']  = $url;
            return $video;
        }
        $att = self::attachment_for_url($url);
        if ($att && in_array('file', $provider->sources(), true)) {
            $video['source']    = 'file';
            $video['file_id']   = $att;
            $video['file_url']  = $url;
            $video['file_name'] = self::file_name($att, $url);
            return $video;
        }
        if (in_array('url', $provider->sources(), true) && self::file_link($url, $provider->file_exts()) !== '') {
            $video['source'] = 'url';
            $video['url']    = $url;
            return $video;
        }
        $video['source'] = 'other';
        $video['other']  = $url;
        return $video;
    }

    /**
     * Fills a video from a media-library video id.
     *
     * @param array $video Normalized video.
     * @param int   $id    Attachment id.
     * @return array
     */
    public static function from_file(array $video, $id) {
        $id  = absint($id);
        $url = $id ? (string) wp_get_attachment_url($id) : '';
        if ($url === '') {
            return $video;
        }
        $video['has']       = true;
        $video['source']    = 'file';
        $video['file_id']   = $id;
        $video['file_url']  = $url;
        $video['file_name'] = self::file_name($id, $url);
        return $video;
    }

    /**
     * The one link a provider stores for a video that keeps a single URL.
     *
     * @param array $video Normalized video.
     * @return string
     */
    public static function link_of(array $video) {
        switch ($video['source']) {
            case 'youtube':
                return (string) $video['youtube'];
            case 'vimeo':
                return (string) $video['vimeo'];
            case 'file':
                return (string) $video['file_url'];
            case 'url':
                return (string) $video['url'];
            case 'other':
                return (string) $video['other'];
        }
        return '';
    }

    /**
     * One line describing a video for the product video row, e.g.
     * "YouTube · youtube.com/watch?v=…". '' when there is no video.
     * The editor JS builds the same line (video_row_meta).
     *
     * @param array $video Normalized video.
     * @return string
     */
    public static function summary(array $video) {
        if (empty($video['has'])) {
            return '';
        }
        switch ($video['source']) {
            case 'youtube':
                $label  = __('YouTube', 'brikpanel');
                $detail = self::short_link($video['youtube']);
                break;
            case 'vimeo':
                $label  = __('Vimeo', 'brikpanel');
                $detail = self::short_link($video['vimeo']);
                break;
            case 'file':
                $label  = __('Media library', 'brikpanel');
                $detail = (string) $video['file_name'];
                break;
            case 'url':
                $label  = __('Video link', 'brikpanel');
                $detail = self::short_link($video['url']);
                break;
            default:
                $label  = __('Current video', 'brikpanel');
                $detail = self::short_link($video['other']);
                break;
        }
        /* translators: 1: video source (YouTube, Vimeo…), 2: the video link or file name */
        return sprintf(_x('%1$s · %2$s', 'product video source and link', 'brikpanel'), $label, $detail);
    }

    private static function short_link($url) {
        $url = (string) preg_replace('~^https?://(www\.)?~i', '', trim((string) $url));
        return wp_html_excerpt($url, 80, '…');
    }

    /**
     * What decides whether two videos store the same thing.
     *
     * @param array $video Normalized video.
     * @return string
     */
    private static function signature(array $video) {
        if (empty($video['has'])) {
            return 'none';
        }
        $options = isset($video['options']) && is_array($video['options']) ? $video['options'] : [];
        ksort($options);
        return wp_json_encode([
            $video['source'],
            $video['source'] === 'file' ? (int) $video['file_id'] : self::link_of($video),
            $options,
        ]);
    }

    private static function file_name($id, $url) {
        // get_the_title() is display HTML ("&#8217;"); the JS writes this as text.
        $title = $id ? brikpanel_plain_label(get_the_title($id)) : '';
        return $title !== '' ? $title : wp_basename((string) wp_parse_url($url, PHP_URL_PATH));
    }

    /**
     * attachment_url_to_postid(), once per URL per request, local URLs only.
     *
     * @param string $url Link.
     * @return int
     */
    private static function attachment_for_url($url) {
        $url = (string) $url;
        if (isset(self::$url_ids[$url])) {
            return self::$url_ids[$url];
        }
        $id      = 0;
        $uploads = wp_get_upload_dir();
        $base    = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        $strip   = static function ($u) {
            return preg_replace('~^https?:~i', '', (string) $u);
        };
        if ($base !== '' && strpos($strip($url), $strip($base)) === 0) {
            $id = (int) attachment_url_to_postid($url);
            if ($id && ! wp_attachment_is('video', $id)) {
                $id = 0;
            }
        }
        self::$url_ids[$url] = $id;
        return $id;
    }

    // ------------------------------------------------------------------
    // Link checks (the editor JS carries a copy; keep them in sync)
    // ------------------------------------------------------------------

    /**
     * Parses a YouTube link.
     *
     * @param string $url Link.
     * @return array|null ['id' => 11-char id, 'shorts' => bool]
     */
    public static function youtube_ref($url) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host   = strtolower($parts['host']);
        $path   = isset($parts['path']) ? (string) $parts['path'] : '';
        $id     = '';
        $shorts = false;
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $segments = explode('/', trim($path, '/'));
            $id       = (string) $segments[0];
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            if (preg_match('~^/(embed|shorts|live|v)/([^/?#]+)~i', $path, $m)) {
                $id     = $m[2];
                $shorts = (strtolower($m[1]) === 'shorts');
            } elseif (rtrim($path, '/') === '/watch' && ! empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
                $id = isset($query['v']) && is_string($query['v']) ? $query['v'] : '';
            }
        }
        if (! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }
        return ['id' => $id, 'shorts' => $shorts];
    }

    /**
     * The link stored for a YouTube video.
     *
     * @param array $ref         youtube_ref() result.
     * @param bool  $keep_shorts Keep a Shorts link as Shorts.
     * @return string
     */
    public static function youtube_url(array $ref, $keep_shorts) {
        return ($keep_shorts && ! empty($ref['shorts']))
            ? 'https://www.youtube.com/shorts/' . $ref['id']
            : 'https://www.youtube.com/watch?v=' . $ref['id'];
    }

    /**
     * Parses a Vimeo link.
     *
     * @param string $url Link.
     * @return array|null ['id' => digits, 'hash' => unlisted hash or '']
     */
    public static function vimeo_ref($url) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = wp_parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (! in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            return null;
        }
        $segments = array_values(array_filter(explode('/', isset($parts['path']) ? (string) $parts['path'] : ''), 'strlen'));
        $id       = '';
        $hash     = '';
        foreach ($segments as $i => $segment) {
            if (preg_match('/^\d{1,12}$/', $segment)) {
                $id = $segment;
                if (isset($segments[$i + 1]) && preg_match('/^[0-9a-f]{6,20}$/i', $segments[$i + 1])) {
                    $hash = strtolower($segments[$i + 1]);
                }
                break;
            }
        }
        if ($hash === '' && ! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (isset($query['h']) && is_string($query['h']) && preg_match('/^[0-9a-f]{6,20}$/i', $query['h'])) {
                $hash = strtolower($query['h']);
            }
        }
        if ($id === '') {
            return null;
        }
        return ['id' => $id, 'hash' => $hash];
    }

    /**
     * The link stored for a Vimeo video.
     *
     * @param array $ref       vimeo_ref() result.
     * @param bool  $keep_hash Keep the unlisted hash.
     * @return string
     */
    public static function vimeo_url(array $ref, $keep_hash) {
        return 'https://vimeo.com/' . $ref['id'] . (($keep_hash && $ref['hash'] !== '') ? '/' . $ref['hash'] : '');
    }

    /**
     * A direct http(s) link to a video file with an allowed extension, or ''.
     *
     * @param string   $url  Link.
     * @param string[] $exts Allowed extensions.
     * @return string
     */
    public static function file_link($url, array $exts) {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048 || ! preg_match('~^https?://~i', $url)) {
            return '';
        }
        $clean = esc_url_raw($url, ['http', 'https']);
        if ($clean === '') {
            return '';
        }
        $path = (string) wp_parse_url($clean, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $exts, true) ? $clean : '';
    }

    /**
     * A media-library video the current user may use, in an allowed format.
     *
     * @param mixed    $id   Attachment id.
     * @param string[] $exts Allowed extensions.
     * @return array|null ['id', 'url', 'name']
     */
    public static function video_file($id, array $exts) {
        $id = absint($id);
        if (! $id || get_post_type($id) !== 'attachment' || ! wp_attachment_is('video', $id) || ! current_user_can('read_post', $id)) {
            return null;
        }
        $url = (string) wp_get_attachment_url($id);
        if ($url === '') {
            return null;
        }
        $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (! in_array($ext, $exts, true)) {
            return null;
        }
        return ['id' => $id, 'url' => $url, 'name' => self::file_name($id, $url)];
    }

    // ------------------------------------------------------------------
    // Parsing posted changes
    // ------------------------------------------------------------------

    /**
     * Turns one posted video into a normalized video for the provider.
     *
     * @param Brikpanel_Video_Provider $provider Provider.
     * @param mixed                    $raw      Posted value.
     * @param array                    $current  Stored video.
     * @return array|WP_Error
     */
    public static function parse_change(Brikpanel_Video_Provider $provider, $raw, array $current) {
        if (! is_array($raw)) {
            return new WP_Error('invalid_source');
        }
        $source = isset($raw['source']) && is_string($raw['source']) ? sanitize_key($raw['source']) : '';
        $video  = self::blank($provider);

        $options = self::parse_options($provider, isset($raw['options']) && is_array($raw['options']) ? $raw['options'] : [], isset($current['options']) && is_array($current['options']) ? $current['options'] : []);
        if (is_wp_error($options)) {
            return $options;
        }
        $video['options'] = $options;

        if ($source === 'other') {
            // A video set up outside BrikPanel in a form the editor cannot
            // edit: only its options change, the link stays as stored.
            if (($current['source'] ?? '') !== 'other' || ($current['other'] ?? '') === '') {
                return new WP_Error('invalid_source');
            }
            $video['source'] = 'other';
            $video['other']  = (string) $current['other'];
            $video['has']    = true;
            return $video;
        }
        if (! in_array($source, $provider->sources(), true)) {
            return new WP_Error('invalid_source');
        }
        switch ($source) {
            case 'youtube':
                $ref = self::youtube_ref(isset($raw['youtube']) && is_string($raw['youtube']) ? $raw['youtube'] : '');
                if (! $ref) {
                    return new WP_Error('invalid_youtube');
                }
                $video['youtube'] = self::youtube_url($ref, $provider->keeps_youtube_shorts());
                break;
            case 'vimeo':
                $ref = self::vimeo_ref(isset($raw['vimeo']) && is_string($raw['vimeo']) ? $raw['vimeo'] : '');
                if (! $ref) {
                    return new WP_Error('invalid_vimeo');
                }
                $video['vimeo'] = self::vimeo_url($ref, $provider->keeps_vimeo_hash());
                break;
            case 'file':
                $file = self::video_file($raw['file_id'] ?? 0, $provider->file_exts());
                if (! $file) {
                    return new WP_Error('invalid_file');
                }
                $video['file_id']   = $file['id'];
                $video['file_url']  = $file['url'];
                $video['file_name'] = $file['name'];
                break;
            case 'url':
                $link = self::file_link(isset($raw['url']) && is_string($raw['url']) ? $raw['url'] : '', $provider->file_exts());
                if ($link === '') {
                    return new WP_Error('invalid_url');
                }
                $video['url'] = $link;
                break;
        }
        $video['source'] = $source;
        $video['has']    = true;

        $check = $provider->validate($video);
        if (is_wp_error($check)) {
            return $check;
        }
        return $video;
    }

    /**
     * Posted option values, checked against the provider's schema. Missing
     * keys keep their stored value.
     *
     * @param Brikpanel_Video_Provider $provider Provider.
     * @param array                    $raw      Posted options.
     * @param array                    $current  Stored options.
     * @return array|WP_Error
     */
    private static function parse_options(Brikpanel_Video_Provider $provider, array $raw, array $current) {
        $out = [];
        foreach ($provider->options() as $o) {
            $key   = $o['key'];
            $value = array_key_exists($key, $current) ? $current[$key] : $o['default'];
            if (array_key_exists($key, $raw)) {
                $in = $raw[$key];
                switch ($o['type']) {
                    case 'switch':
                        $value = is_bool($in) ? $in : wp_validate_boolean(is_scalar($in) ? (string) $in : '');
                        break;
                    case 'choice':
                        $allowed = array_map(static function ($c) {
                            return (string) $c['value'];
                        }, $o['choices']);
                        $in    = is_scalar($in) ? (string) $in : '';
                        $value = in_array($in, $allowed, true) ? $in : (string) $o['default'];
                        break;
                    case 'text':
                        $in = is_scalar($in) ? trim(sanitize_text_field((string) $in)) : '';
                        if ($in !== '' && ! empty($o['pattern']) && ! preg_match('~' . $o['pattern'] . '~', $in)) {
                            return new WP_Error(isset($o['error']) ? (string) $o['error'] : 'invalid_option');
                        }
                        $value = $in;
                        break;
                }
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Decodes a JSON field of the save request within the size limits.
     *
     * @param string $key POST key.
     * @return array|null
     */
    private static function decode_json_post($key) {
        if (! isset($_POST[$key]) || ! is_string($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the editor save verified its nonce.
            return null;
        }
        $json = wp_unslash($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every value is checked in parse_change().
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }
        $data = json_decode($json, true, self::MAX_JSON_DEPTH);
        return is_array($data) ? $data : null;
    }

    private static function posted_provider() {
        return isset($_POST['video_provider']) && is_string($_POST['video_provider']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ? sanitize_key(wp_unslash($_POST['video_provider'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            : '';
    }

    // ------------------------------------------------------------------
    // Save: guards before the first hook, writes after the last one
    // ------------------------------------------------------------------

    /**
     * Runs at the top of the editor save, before the first post write fires
     * save_post (WooCommerce's save_meta_boxes then fires
     * woocommerce_process_product_meta when the product-data card sent its
     * nonce).
     *
     * @param int $product_id Product being saved, 0 for a new one.
     * @return void
     */
    public static function begin_save($product_id) {
        self::$post_backup     = [];
        self::$pending_product = null;
        self::$rendered_boxes  = null;

        if (isset($_POST['bp_rendered_boxes']) && is_string($_POST['bp_rendered_boxes'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $boxes = json_decode(wp_unslash($_POST['bp_rendered_boxes']), true, 2); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ids are compared, never output.
            if (is_array($boxes)) {
                self::$rendered_boxes = [];
                foreach (array_slice($boxes, 0, 300) as $box) {
                    if (is_string($box) && $box !== '' && strlen($box) <= 200) {
                        self::$rendered_boxes[strtolower($box)] = true;
                    }
                }
            }
        }

        $product_id = (int) $product_id;

        // A product video (kind 'product') is parsed now: the Flatsome and Porto
        // guards hand it to the theme's own save handler.
        $ui = self::ui_provider();
        if ($ui && $ui->kind() === 'product' && self::posted_provider() === $ui->id()) {
            $raw = self::decode_json_post('product_video');
            if (is_array($raw)) {
                try {
                    $current = $product_id ? $ui->read_product($product_id) : self::blank($ui);
                    if (! empty($raw['remove'])) {
                        self::$pending_product = ['video' => null, 'current' => $current];
                    } else {
                        $parsed                = self::parse_change($ui, $raw, $current);
                        self::$pending_product = is_wp_error($parsed)
                            ? ['error' => $parsed->get_error_code()]
                            : ['video' => $parsed, 'current' => $current];
                    }
                } catch (\Throwable $e) {
                    self::$pending_product = ['error' => 'failed'];
                }
            }
        }

        if ($product_id <= 0) {
            return; // Nothing stored yet, nothing to protect.
        }

        foreach (self::providers() as $provider) {
            try {
                if (! $provider->detected() || ! $provider->guard_needed()) {
                    continue;
                }
                $provider->guard($product_id);
            } catch (\Throwable $e) {
                try {
                    $provider->guard_fail_safe();
                } catch (\Throwable $ignored) {
                    unset($ignored);
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('BrikPanel product video guard (' . $provider->id() . '): ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }
            }
        }
    }

    /**
     * Puts back every $_POST key a guard changed. Runs once all save hooks of
     * the product itself have fired.
     *
     * @return void
     */
    public static function end_dispatch() {
        foreach (self::$post_backup as $key => $original) {
            if ($original === null) {
                unset($_POST[$key]);
            } else {
                $_POST[$key] = $original;
            }
        }
        self::$post_backup = [];
    }

    /**
     * Sets a $_POST value for this save, remembering the original.
     *
     * @param string $key   POST key.
     * @param mixed  $value Value (slashed, like the rest of $_POST).
     * @return void
     */
    public static function set_post($key, $value) {
        if (! array_key_exists($key, self::$post_backup)) {
            self::$post_backup[$key] = array_key_exists($key, $_POST) ? $_POST[$key] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        $_POST[$key] = $value;
    }

    /**
     * Removes a $_POST value for this save, remembering the original.
     *
     * @param string $key POST key.
     * @return void
     */
    public static function unset_post($key) {
        if (! array_key_exists($key, $_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        if (! array_key_exists($key, self::$post_backup)) {
            self::$post_backup[$key] = $_POST[$key]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        unset($_POST[$key]);
    }

    /**
     * Whether a third-party panel or metabox was on the form that posted.
     *
     * @param string   $box_id   Panel or metabox id.
     * @param string[] $always   Field names that always post when the box is on
     *                           the page, for requests from an older editor
     *                           that did not list its boxes.
     * @return bool
     */
    public static function box_rendered($box_id, array $always = []) {
        if (self::$rendered_boxes !== null) {
            return isset(self::$rendered_boxes[strtolower((string) $box_id)]);
        }
        foreach ($always as $name) {
            if (array_key_exists($name, $_POST)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return true;
            }
        }
        return false;
    }

    /**
     * The product-video change of this save for a provider, or null when the
     * merchant did not touch it (or it did not pass the checks).
     *
     * @param Brikpanel_Video_Provider $provider Provider.
     * @return array|null ['video' => normalized|null]
     */
    public static function pending_product_change(Brikpanel_Video_Provider $provider) {
        $ui = self::ui_provider();
        if (! $ui || $ui->id() !== $provider->id() || ! is_array(self::$pending_product) || ! array_key_exists('video', self::$pending_product)) {
            return null;
        }
        return self::$pending_product;
    }

    /**
     * Writes the video changes of a save. Runs after the product, its hooks and
     * its variations are saved, so no other save handler runs after it.
     *
     * @param int   $product_id Saved product.
     * @param array $warnings   Save warnings (appended to).
     * @return array|null State for the editor, see payload_for_editor().
     */
    public static function apply_changes($product_id, array &$warnings) {
        $ui = self::ui_provider();
        if (! $ui) {
            return null;
        }
        $product_id = (int) $product_id;
        $posted     = isset($_POST['gallery_videos']) || isset($_POST['product_video']); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ($posted && self::posted_provider() !== $ui->id()) {
            $warnings[] = __('Video changes were not saved because the store\'s video support changed while you were editing. Reload the page and try again.', 'brikpanel');
            return self::payload_for_editor($product_id);
        }

        $reasons = [];

        if ($ui->kind() === 'product') {
            $pending = self::$pending_product;
            if (is_array($pending) && isset($pending['error'])) {
                $reasons[$pending['error']] = true;
            } elseif (is_array($pending) && array_key_exists('video', $pending)) {
                $current = $ui->read_product($product_id);
                $next    = $pending['video'];
                if (self::signature($next ?: ['has' => false]) !== self::signature($current)) {
                    $ui->write_product($product_id, $next);
                }
            }
        } else {
            $changes = self::decode_json_post('gallery_videos');
            if (isset($_POST['gallery_videos']) && $changes === null) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $reasons['too_large'] = true;
            }
            if (is_array($changes) && $changes) {
                $product = wc_get_product($product_id);
                $slots   = $product ? self::image_slots($product) : [];
                $changes = array_slice($changes, 0, self::MAX_ENTRIES, true);
                $ids     = [];
                foreach (array_keys($changes) as $key) {
                    $id = absint($key);
                    if ($id && isset($slots[$id])) {
                        $ids[] = $id;
                    }
                }
                if ($ids) {
                    update_meta_cache('post', $ids);
                    _prime_post_caches($ids, false, false);
                }
                $current = $ids ? $ui->read_images($product_id, $ids) : [];
                $write   = [];
                foreach ($changes as $key => $raw) {
                    $id = absint($key);
                    if (! $id) {
                        continue;
                    }
                    if (! isset($slots[$id]) || get_post_type($id) !== 'attachment' || ! wp_attachment_is_image($id)) {
                        $reasons['not_in_product'] = true;
                        continue;
                    }
                    if ($ui->storage() === 'attachment' && ! current_user_can('edit_post', $id)) {
                        $reasons['locked'] = true;
                        continue;
                    }
                    $stored = isset($current[$id]) ? $current[$id] : self::blank($ui);
                    if (is_array($raw) && ! empty($raw['remove'])) {
                        if (! empty($stored['has'])) {
                            $write[$id] = null;
                        }
                        continue;
                    }
                    // Adding a video only where the storefront plays one.
                    if (! in_array($slots[$id], $ui->slots(), true)) {
                        $reasons['slot'] = true;
                        continue;
                    }
                    $parsed = self::parse_change($ui, $raw, $stored);
                    if (is_wp_error($parsed)) {
                        $reasons[$parsed->get_error_code()] = true;
                        continue;
                    }
                    if (self::signature($parsed) !== self::signature($stored)) {
                        $write[$id] = $parsed;
                    }
                }
                if ($write) {
                    $ui->write_images($product_id, $write);
                }
            }
        }

        foreach (array_keys($reasons) as $code) {
            $warnings[] = self::reason_message($code, $ui);
        }
        return self::payload_for_editor($product_id);
    }

    /**
     * A save warning for a rejected video.
     *
     * @param string                   $code     Reason code.
     * @param Brikpanel_Video_Provider $provider Provider.
     * @return string
     */
    private static function reason_message($code, Brikpanel_Video_Provider $provider) {
        switch ($code) {
            case 'not_in_product':
                return __('A video was not saved because its image is no longer part of this product.', 'brikpanel');
            case 'locked':
                return __('A video was not saved because you are not allowed to edit that image.', 'brikpanel');
            case 'slot':
                /* translators: %s: theme or plugin name, e.g. WoodMart */
                return sprintf(__('A video was not saved because %s does not play videos on that image.', 'brikpanel'), $provider->label());
            case 'invalid_youtube':
                return __('A video was not saved because its YouTube link is not a video link.', 'brikpanel');
            case 'invalid_vimeo':
                return __('A video was not saved because its Vimeo link is not a video link.', 'brikpanel');
            case 'invalid_file':
                return __('A video was not saved because the chosen file is not a video in a supported format.', 'brikpanel');
            case 'invalid_url':
                return __('A video was not saved because its link does not point to a supported video file.', 'brikpanel');
            case 'invalid_size':
                return __('The product video was not saved because its size must look like 900x900 (width x height).', 'brikpanel');
            case 'too_large':
                return __('Video changes were not saved because too many were sent at once. Save again with fewer changes.', 'brikpanel');
        }
        return __('A video was not saved because its settings were not valid.', 'brikpanel');
    }

    // ------------------------------------------------------------------
    // Editor data
    // ------------------------------------------------------------------

    /**
     * Which slot each image of a product is in. Variation main images only
     * count when they are not also the featured or a gallery image.
     *
     * @param WC_Product $product Product.
     * @return array [att_id => featured|gallery|variation]
     */
    public static function image_slots($product) {
        $slots    = [];
        $featured = (int) $product->get_image_id('edit');
        if ($featured) {
            $slots[$featured] = 'featured';
        }
        foreach ((array) $product->get_gallery_image_ids('edit') as $gid) {
            $gid = (int) $gid;
            if ($gid && ! isset($slots[$gid])) {
                $slots[$gid] = 'gallery';
            }
        }
        if ($product->is_type('variable')) {
            $children = array_map('intval', (array) $product->get_children());
            if ($children) {
                update_meta_cache('post', $children);
                foreach ($children as $child) {
                    $thumb = (int) get_post_meta($child, '_thumbnail_id', true);
                    if ($thumb && ! isset($slots[$thumb])) {
                        $slots[$thumb] = 'variation';
                    }
                }
            }
        }
        return $slots;
    }

    /**
     * Current videos for the editor page (and the save response).
     *
     * @param int $product_id Product id, 0 for a new product.
     * @return array|null ['provider', 'items' => {att_id: video}, 'product' => video|null]
     */
    public static function payload_for_editor($product_id) {
        $ui = self::ui_provider();
        if (! $ui) {
            return null;
        }
        $out        = ['provider' => $ui->id(), 'items' => new stdClass(), 'product' => null];
        $product_id = (int) $product_id;
        try {
            if ($ui->kind() === 'product') {
                $video = $product_id ? $ui->read_product($product_id) : self::blank($ui);
                if ($product_id) {
                    $video['mirror'] = (object) $ui->mirror($product_id, $video);
                }
                $out['product'] = $video;
                return $out;
            }
            if (! $product_id) {
                return $out;
            }
            $product = wc_get_product($product_id);
            if (! $product) {
                return $out;
            }
            $ids = [];
            foreach (self::image_slots($product) as $id => $slot) {
                if ($slot !== 'variation' || in_array('variation', $ui->slots(), true)) {
                    $ids[] = (int) $id;
                }
            }
            if (! $ids) {
                return $out;
            }
            update_meta_cache('post', $ids);
            $items = $ui->read_images($product_id, $ids);
            foreach ($ids as $id) {
                $video = isset($items[$id]) ? $items[$id] : self::blank($ui);
                if ($ui->storage() === 'attachment') {
                    $video['editable'] = current_user_can('edit_post', $id);
                }
                $items[$id] = $video;
            }
            $out['items'] = (object) $items;
        } catch (\Throwable $e) {
            return $out;
        }
        return $out;
    }

    /**
     * What the editor script needs to build its controls.
     *
     * @return array|null
     */
    public static function js_schema() {
        $p = self::ui_provider();
        if (! $p) {
            return null;
        }
        $mimes = [];
        foreach (wp_get_mime_types() as $exts => $mime) {
            foreach (explode('|', $exts) as $ext) {
                if (in_array($ext, $p->file_exts(), true) && strpos($mime, 'video/') === 0) {
                    $mimes[] = $mime;
                }
            }
        }
        return [
            'id'         => $p->id(),
            'label'      => $p->label(),
            'kind'       => $p->kind(),
            'storage'    => $p->storage(),
            'slots'      => array_values($p->slots()),
            'sources'    => array_values($p->sources()),
            'exts'       => array_values($p->file_exts()),
            'mimes'      => array_values(array_unique($mimes)),
            'shorts'     => (bool) $p->keeps_youtube_shorts(),
            'vimeo_hash' => (bool) $p->keeps_vimeo_hash(),
            'options'    => array_values($p->options()),
            'notes'      => array_values($p->notes()),
        ];
    }
}
