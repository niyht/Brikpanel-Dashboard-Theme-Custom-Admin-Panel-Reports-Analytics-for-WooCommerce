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
 * @since   3.1.0
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
     *  - label  : Display name (translatable).
     *  - slug   : wp.org plugin directory slug (used for the install link).
     *  - search : Fallback search term for plugin-install.php?s=...
     *  - detect : Cheap predicate returning true when active.
     *
     * @return array
     */
    private static function catalog() {
        if ( self::$catalog_cache !== null ) {
            return self::$catalog_cache;
        }

        self::$catalog_cache = [

            'wp-smushit' => [
                'label'  => 'Smush',
                'slug'   => 'wp-smushit',
                'search' => 'smush',
                'detect' => static function () {
                    return defined( 'WP_SMUSH_VERSION' ) || class_exists( 'WP_Smush' );
                },
            ],

            'shortpixel-image-optimiser' => [
                'label'  => 'ShortPixel',
                'slug'   => 'shortpixel-image-optimiser',
                'search' => 'shortpixel',
                'detect' => static function () {
                    return defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' )
                        || class_exists( 'ShortPixelPlugin' )
                        || function_exists( 'shortpixel_init' );
                },
            ],

            'ewww-image-optimizer' => [
                'label'  => 'EWWW Image Optimizer',
                'slug'   => 'ewww-image-optimizer',
                'search' => 'ewww image optimizer',
                'detect' => static function () {
                    return defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' )
                        || function_exists( 'ewww_image_optimizer_init' );
                },
            ],

            'imagify' => [
                'label'  => 'Imagify',
                'slug'   => 'imagify',
                'search' => 'imagify',
                'detect' => static function () {
                    return defined( 'IMAGIFY_VERSION' )
                        || class_exists( 'Imagify' );
                },
            ],

            'litespeed-cache' => [
                'label'  => 'LiteSpeed Cache (Image Optimization)',
                'slug'   => 'litespeed-cache',
                'search' => 'litespeed cache',
                'detect' => static function () {
                    return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core' );
                },
            ],

            'optimole-wp' => [
                'label'  => 'Optimole',
                'slug'   => 'optimole-wp',
                'search' => 'optimole',
                'detect' => static function () {
                    return defined( 'OPTIMOLE_VERSION' )
                        || class_exists( 'Optml_Main' );
                },
            ],

            'webp-converter-for-media' => [
                'label'  => 'Converter for Media',
                'slug'   => 'webp-converter-for-media',
                'search' => 'webp converter for media',
                'detect' => static function () {
                    return class_exists( 'WebpConverter\\Plugin' )
                        || defined( 'WEBPC_VERSION' );
                },
            ],

            'tinypng-image-compression' => [
                'label'  => 'TinyPNG / TinyJPG',
                'slug'   => 'tiny-compress-images',
                'search' => 'tinypng',
                'detect' => static function () {
                    return defined( 'TINY_PLUGIN_VERSION' )
                        || class_exists( 'Tiny_Plugin' );
                },
            ],
        ];

        return self::$catalog_cache;
    }

    /**
     * @return array<string, string> slug => label
     */
    public static function get_active() {
        $active  = [];
        foreach ( self::catalog() as $slug => $entry ) {
            if ( call_user_func( $entry['detect'] ) ) {
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
            if ( call_user_func( $entry['detect'] ) ) {
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
