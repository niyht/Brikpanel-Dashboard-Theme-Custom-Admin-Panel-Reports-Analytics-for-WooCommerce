<?php
/**
 * BrikPanel — BrikControl Image Optimizer Plugin Detector
 *
 * Detects whether the site has any image optimisation / WebP conversion
 * plugin active. Used by the Image Health check to:
 *  - flag a "no optimizer installed" critical recommendation, and
 *  - render install-now buttons that deep-link straight into the WP plugin
 *    install screen (per the user's UX preference).
 *
 * Detection callbacks must stay cheap (class_exists / defined / function_exists)
 * because they run on every BrikControl render. Anything more expensive would
 * break the "topbar adds zero overhead" guarantee.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Image_Plugins {

    /**
     * @var array<string, array{label:string, slug:string, search:string, detect:callable}>|null
     */
    private static $catalog_cache = null;

    /**
     * Static catalogue of supported optimisers. Each entry:
     *  - label    : Display name (translatable).
     *  - slug     : wp.org plugin directory slug (used for the install link).
     *  - basename : `dir/file.php` path that wp_get_active_and_valid_plugins
     *               stores — used as the authoritative active-plugin signal
     *               via is_plugin_active(), independent of constants/classes
     *               that may live in obfuscated/prefixed namespaces.
     *  - search   : Fallback search term for plugin-install.php?s=...
     *  - detect   : Cheap predicate. We OR `is_plugin_active($basename)` on
     *               top so a plugin counts as detected even when its bootstrap
     *               classes use namespaces we didn't list (e.g. Converter for
     *               Media moved from WebpConverter\Plugin → WebpConverter\WebpConverter).
     *
     * @return array
     */
    private static function catalog() {
        if ( self::$catalog_cache !== null ) {
            return self::$catalog_cache;
        }

        // Lazy-include the activate_plugins helpers so is_plugin_active() is callable
        // even when this catalogue is hit from a CLI / cron context where wp-admin
        // hasn't loaded plugin.php yet.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            $plugin_helper = ABSPATH . 'wp-admin/includes/plugin.php';
            if ( file_exists( $plugin_helper ) ) {
                require_once $plugin_helper;
            }
        }

        self::$catalog_cache = [

            'wp-smushit' => [
                'label'    => 'Smush',
                'slug'     => 'wp-smushit',
                'basename' => 'wp-smushit/wp-smush.php',
                'search'   => 'smush',
                'detect'   => static function () {
                    return defined( 'WP_SMUSH_VERSION' ) || class_exists( 'WP_Smush' );
                },
            ],

            'shortpixel-image-optimiser' => [
                'label'    => 'ShortPixel',
                'slug'     => 'shortpixel-image-optimiser',
                'basename' => 'shortpixel-image-optimiser/wp-shortpixel.php',
                'search'   => 'shortpixel',
                'detect'   => static function () {
                    return defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' )
                        || class_exists( 'ShortPixelPlugin' )
                        || function_exists( 'shortpixel_init' );
                },
            ],

            'ewww-image-optimizer' => [
                'label'    => 'EWWW Image Optimizer',
                'slug'     => 'ewww-image-optimizer',
                'basename' => 'ewww-image-optimizer/ewww-image-optimizer.php',
                'search'   => 'ewww image optimizer',
                'detect'   => static function () {
                    return defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' )
                        || function_exists( 'ewww_image_optimizer_init' );
                },
            ],

            'imagify' => [
                'label'    => 'Imagify',
                'slug'     => 'imagify',
                'basename' => 'imagify/imagify.php',
                'search'   => 'imagify',
                'detect'   => static function () {
                    return defined( 'IMAGIFY_VERSION' )
                        || class_exists( 'Imagify' );
                },
            ],

            'litespeed-cache' => [
                'label'    => 'LiteSpeed Cache (Image Optimization)',
                'slug'     => 'litespeed-cache',
                'basename' => 'litespeed-cache/litespeed-cache.php',
                'search'   => 'litespeed cache',
                'detect'   => static function () {
                    return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core' );
                },
            ],

            'optimole-wp' => [
                'label'    => 'Optimole',
                'slug'     => 'optimole-wp',
                'basename' => 'optimole-wp/optimole-wp.php',
                'search'   => 'optimole',
                'detect'   => static function () {
                    return defined( 'OPTIMOLE_VERSION' )
                        || class_exists( 'Optml_Main' );
                },
            ],

            'webp-converter-for-media' => [
                'label'    => 'Converter for Media',
                'slug'     => 'webp-converter-for-media',
                'basename' => 'webp-converter-for-media/webp-converter-for-media.php',
                'search'   => 'webp converter for media',
                'detect'   => static function () {
                    // Covers Pro variant + the historical class name.
                    return class_exists( 'WebpConverter\\WebpConverter' )
                        || class_exists( 'WebpConverter\\Plugin' )
                        || class_exists( 'WebpConverter\\PluginInfo' )
                        || defined( 'WEBPC_VERSION' );
                },
            ],

            'tinypng-image-compression' => [
                'label'    => 'TinyPNG / TinyJPG',
                'slug'     => 'tiny-compress-images',
                'basename' => 'tiny-compress-images/tiny-compress-images.php',
                'search'   => 'tinypng',
                'detect'   => static function () {
                    return defined( 'TINY_PLUGIN_VERSION' )
                        || class_exists( 'Tiny_Plugin' );
                },
            ],
        ];

        return self::$catalog_cache;
    }

    /**
     * Whether a single catalogue entry is active. Tries the cheap predicate
     * first, falls back to is_plugin_active() against the recorded basename
     * so plugins that use prefixed/obfuscated class namespaces (or change
     * them between releases) still count.
     *
     * @param array $entry
     * @return bool
     */
    private static function is_entry_active( array $entry ) {
        if ( ! empty( $entry['detect'] ) && call_user_func( $entry['detect'] ) ) {
            return true;
        }
        if ( ! empty( $entry['basename'] ) && function_exists( 'is_plugin_active' ) ) {
            return (bool) is_plugin_active( $entry['basename'] );
        }
        return false;
    }

    /**
     * @return array<string, string> slug => label
     */
    public static function get_active() {
        $active  = [];
        foreach ( self::catalog() as $slug => $entry ) {
            if ( self::is_entry_active( $entry ) ) {
                $active[ $slug ] = $entry['label'];
            }
        }
        return $active;
    }

    /**
     * @return bool
     */
    public static function any_active() {
        foreach ( self::catalog() as $entry ) {
            if ( self::is_entry_active( $entry ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Curated short-list of plugin recommendations stored at scan time.
     *
     * URLs are intentionally NOT computed here because scans run inside
     * Action Scheduler workers where `current_user_can()` always returns
     * false (no current user). Storing only slug + search lets the renderer
     * pick the right URL per viewer via resolve_install_url().
     *
     * @return array<int, array{slug:string,label:string,search:string}>
     */
    public static function get_recommendations() {
        $featured = [ 'shortpixel-image-optimiser', 'imagify', 'wp-smushit' ];
        $catalog  = self::catalog();
        $out      = [];

        foreach ( $featured as $slug ) {
            if ( ! isset( $catalog[ $slug ] ) ) {
                continue;
            }
            $entry = $catalog[ $slug ];
            $out[] = [
                'slug'   => $entry['slug'],
                'label'  => $entry['label'],
                'search' => $entry['search'],
            ];
        }
        return $out;
    }

    /**
     * Compute an install URL for the current viewer. Prefers the in-admin
     * search screen when the user can install plugins; falls back to the
     * public wp.org page otherwise.
     *
     * @param string $slug   wp.org plugin directory slug.
     * @param string $search Fallback search term for plugin-install.php.
     * @return string
     */
    public static function resolve_install_url( $slug, $search = '' ) {
        if ( current_user_can( 'install_plugins' ) ) {
            $term = $search !== '' ? $search : $slug;
            return admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( $term ) );
        }
        return 'https://wordpress.org/plugins/' . $slug . '/';
    }
}
