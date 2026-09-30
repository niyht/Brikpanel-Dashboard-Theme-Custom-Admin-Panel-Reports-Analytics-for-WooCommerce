<?php
/**
 * Base class for product-video providers.
 *
 * A provider is one theme or plugin that plays videos on the product page and
 * stores them in its own format. The BrikPanel editor talks to whichever
 * provider is active through this contract, so a video set in BrikPanel lands
 * exactly where that theme reads it, and a video set on the theme's own screen
 * shows up in BrikPanel.
 *
 * Videos travel through the editor in one normalized shape (see
 * Brikpanel_Video::blank()); each provider translates between that shape and
 * its own storage.
 *
 * @package Brikpanel
 */

if (! defined('ABSPATH')) {
    exit;
}

abstract class Brikpanel_Video_Provider {

    /**
     * Stable id. The editor posts it back so a save can tell when the store's
     * video setup changed while the page was open.
     *
     * @return string
     */
    abstract public function id();

    /**
     * Theme or plugin name, shown in the editor ("Shown by %s").
     *
     * @return string
     */
    abstract public function label();

    /**
     * Whether the theme or plugin code is loaded on this request. Must be cheap:
     * no database work beyond cached options.
     *
     * @return bool
     */
    abstract public function detected();

    /**
     * Whether the storefront really plays videos stored by this provider.
     *
     * @return bool
     */
    public function active() {
        return $this->detected();
    }

    /**
     * 'image' = one video per product image, 'product' = one video per product.
     *
     * @return string
     */
    public function kind() {
        return 'image';
    }

    /**
     * 'product' = product meta, 'attachment' = meta on the image itself. For
     * attachment storage the editor checks edit_post on every image it writes.
     *
     * @return string
     */
    public function storage() {
        return 'product';
    }

    /**
     * A theme with its own video feature usually builds its own gallery, so
     * themes (50) outrank gallery add-ons that hook into the standard one.
     *
     * @return int
     */
    public function priority() {
        return 50;
    }

    /**
     * Image slots the storefront plays videos on: featured, gallery, variation
     * (a variation's main image).
     *
     * @return string[]
     */
    public function slots() {
        return ['featured', 'gallery'];
    }

    /**
     * Sources the editor offers: youtube, vimeo, file (a video from the media
     * library) and url (a direct link to a video file).
     *
     * @return string[]
     */
    public function sources() {
        return ['youtube', 'vimeo', 'file'];
    }

    /**
     * File extensions the storefront player can play, for media-library files
     * and direct links.
     *
     * @return string[]
     */
    public function file_exts() {
        return ['mp4', 'webm'];
    }

    /**
     * Keep YouTube Shorts links as Shorts. Players that do not understand them
     * get the regular watch link of the same video instead.
     *
     * @return bool
     */
    public function keeps_youtube_shorts() {
        return false;
    }

    /**
     * Keep the privacy hash of an unlisted Vimeo link.
     *
     * @return bool
     */
    public function keeps_vimeo_hash() {
        return false;
    }

    /**
     * Player options. Each entry:
     *   key, type (switch|choice|text), label (i18n key), default,
     *   choices ([value, label (i18n key)] for choice),
     *   show_if ([key, eq]), pattern + error (for text).
     *
     * @return array[]
     */
    public function options() {
        return [];
    }

    /**
     * Notes shown in the dialog: [key (i18n key), when (always|variation|featured)].
     *
     * @return array[]
     */
    public function notes() {
        return [];
    }

    /**
     * Current videos of the given images.
     *
     * @param int   $product_id Product id.
     * @param int[] $att_ids    Image attachment ids.
     * @return array [att_id => normalized]
     */
    public function read_images($product_id, array $att_ids) {
        return [];
    }

    /**
     * Current product video (kind 'product').
     *
     * @param int $product_id Product id.
     * @return array Normalized video.
     */
    public function read_product($product_id) {
        return Brikpanel_Video::blank($this);
    }

    /**
     * Store per-image changes.
     *
     * @param int   $product_id Product id.
     * @param array $changes    [att_id => normalized video, or null to remove].
     * @return void
     */
    public function write_images($product_id, array $changes) {
    }

    /**
     * Store the product video (kind 'product').
     *
     * @param int        $product_id Product id.
     * @param array|null $video      Normalized video, or null to remove.
     * @return void
     */
    public function write_product($product_id, $video) {
    }

    /**
     * Extra checks on a parsed video.
     *
     * @param array $video Normalized video.
     * @return true|WP_Error
     */
    public function validate(array $video) {
        return true;
    }

    /**
     * Whether this theme or plugin has a save handler that deletes its own data
     * when a BrikPanel save does not carry its form fields.
     *
     * @return bool
     */
    public function guard_needed() {
        return false;
    }

    /**
     * Runs before the first hook of a BrikPanel save. Adjusts $_POST through
     * Brikpanel_Video::set_post() (restored after the save) or detaches the
     * provider's own save handler, so a form that never showed its fields
     * cannot clear them.
     *
     * @param int $product_id Product being saved (never 0).
     * @return void
     */
    public function guard($product_id) {
    }

    /**
     * Last resort when guard() throws: keep the provider's save handler from
     * running at all. Skipping a save can only leave data as it was.
     *
     * @return void
     */
    public function guard_fail_safe() {
    }

    /**
     * Values of the provider's own form fields for a product video, keyed by
     * input name. The editor copies them into that provider's panel when it is
     * shown on the page, so the next save cannot post stale values back.
     *
     * @param int   $product_id Product id.
     * @param array $video      Normalized video.
     * @return array
     */
    public function mirror($product_id, array $video) {
        return [];
    }
}
