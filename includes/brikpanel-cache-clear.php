<?php
/**
 * BrikPanel - Cache Plugin Clear Service
 *
 * Detects active third-party cache / optimization plugins and exposes a single
 * AJAX endpoint to purge them. Used by the global topbar to render a
 * one-click cache clear control whenever a supported plugin is active.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_Cache_Clear {

    const NONCE_ACTION = 'brikpanel_cache_clear';
    const AJAX_ACTION  = 'brikpanel_cache_clear';

    private static $instance = null;
    private static $supported_cache = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_clear' ] );
    }

    /**
     * Definitions of supported cache / optimization plugins.
     *
     * Each entry contains:
     *  - label   : Human readable plugin name (translatable).
     *  - detect  : Callable returning true when the plugin is active.
     *  - clear   : Callable that purges the plugin's caches. May return
     *              false to indicate failure; throwing is also fine — both
     *              are surfaced as a "failed" item to the caller.
     *
     * Detection runs on every admin page render, so the callables must be
     * cheap (function_exists / class_exists / defined).
     */
    private static function get_supported() {
        if ( self::$supported_cache !== null ) {
            return self::$supported_cache;
        }

        self::$supported_cache = [

            'wp-rocket' => [
                'label'  => 'WP Rocket',
                'detect' => static function () {
                    return function_exists( 'rocket_clean_domain' ) || defined( 'WP_ROCKET_VERSION' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'rocket_clean_domain' ) ) {
                        rocket_clean_domain();
                    }
                    if ( function_exists( 'rocket_clean_minify' ) ) {
                        rocket_clean_minify();
                    }
                    if ( function_exists( 'rocket_clean_cache_busting' ) ) {
                        rocket_clean_cache_busting();
                    }
                    if ( function_exists( 'rocket_clean_user_cache' ) ) {
                        rocket_clean_user_cache();
                    }
                    return true;
                },
            ],

            'litespeed' => [
                'label'  => 'LiteSpeed Cache',
                'detect' => static function () {
                    return defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core' );
                },
                'clear'  => static function () {
                    do_action( 'litespeed_purge_all' );
                    return true;
                },
            ],

            'w3-total-cache' => [
                'label'  => 'W3 Total Cache',
                'detect' => static function () {
                    return defined( 'W3TC' ) || function_exists( 'w3tc_flush_all' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'w3tc_flush_all' ) ) {
                        w3tc_flush_all();
                        return true;
                    }
                    if ( function_exists( 'w3tc_pgcache_flush' ) ) {
                        w3tc_pgcache_flush();
                    }
                    if ( function_exists( 'w3tc_dbcache_flush' ) ) {
                        w3tc_dbcache_flush();
                    }
                    if ( function_exists( 'w3tc_minify_flush' ) ) {
                        w3tc_minify_flush();
                    }
                    if ( function_exists( 'w3tc_objectcache_flush' ) ) {
                        w3tc_objectcache_flush();
                    }
                    return true;
                },
            ],

            'wp-super-cache' => [
                'label'  => 'WP Super Cache',
                'detect' => static function () {
                    return function_exists( 'wp_cache_clear_cache' )
                        || function_exists( 'wp_cache_clean_cache' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'wp_cache_clear_cache' ) ) {
                        wp_cache_clear_cache();
                        return true;
                    }
                    if ( function_exists( 'wp_cache_clean_cache' ) ) {
                        global $file_prefix;
                        wp_cache_clean_cache( $file_prefix, true );
                    }
                    return true;
                },
            ],

            'wp-fastest-cache' => [
                'label'  => 'WP Fastest Cache',
                'detect' => static function () {
                    return class_exists( 'WpFastestCache' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'wpfc_clear_all_cache' ) ) {
                        wpfc_clear_all_cache( true );
                        return true;
                    }
                    if ( class_exists( 'WpFastestCache' ) ) {
                        $wpfc = new WpFastestCache();
                        if ( method_exists( $wpfc, 'deleteCache' ) ) {
                            $wpfc->deleteCache( true );
                        }
                    }
                    return true;
                },
            ],

            'cache-enabler' => [
                'label'  => 'Cache Enabler',
                'detect' => static function () {
                    return class_exists( 'Cache_Enabler' );
                },
                'clear'  => static function () {
                    if ( method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
                        Cache_Enabler::clear_complete_cache();
                    }
                    return true;
                },
            ],

            'autoptimize' => [
                'label'  => 'Autoptimize',
                'detect' => static function () {
                    return class_exists( 'autoptimizeCache' );
                },
                'clear'  => static function () {
                    if ( method_exists( 'autoptimizeCache', 'clearall' ) ) {
                        autoptimizeCache::clearall();
                    }
                    return true;
                },
            ],

            'hummingbird' => [
                'label'  => 'Hummingbird',
                'detect' => static function () {
                    return class_exists( '\\Hummingbird\\WP_Hummingbird' )
                        || defined( 'WPHB_VERSION' );
                },
                'clear'  => static function () {
                    do_action( 'wphb_clear_page_cache' );
                    do_action( 'wphb_clear_minification_cache' );
                    do_action( 'wphb_clear_cache' );
                    return true;
                },
            ],

            'sg-cachepress' => [
                'label'  => 'SG Optimizer',
                'detect' => static function () {
                    return function_exists( 'sg_cachepress_purge_cache' )
                        || class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
                        sg_cachepress_purge_cache();
                        return true;
                    }
                    if ( class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' )
                        && method_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache' ) ) {
                        \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
                    }
                    return true;
                },
            ],

            'breeze' => [
                'label'  => 'Breeze',
                'detect' => static function () {
                    return class_exists( 'Breeze_PurgeCache' ) || class_exists( 'Breeze_Admin' );
                },
                'clear'  => static function () {
                    if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
                        Breeze_PurgeCache::breeze_cache_flush();
                    }
                    if ( class_exists( 'Breeze_Admin' ) && method_exists( 'Breeze_Admin', 'breeze_clear_all_cache' ) ) {
                        Breeze_Admin::breeze_clear_all_cache();
                    }
                    do_action( 'breeze_clear_all_cache' );
                    return true;
                },
            ],

            'wp-optimize' => [
                'label'  => 'WP-Optimize',
                'detect' => static function () {
                    return class_exists( 'WP_Optimize' ) && function_exists( 'WP_Optimize' );
                },
                'clear'  => static function () {
                    if ( ! function_exists( 'WP_Optimize' ) ) {
                        return false;
                    }
                    $wpo = WP_Optimize();
                    if ( $wpo && method_exists( $wpo, 'get_page_cache' ) ) {
                        $page_cache = $wpo->get_page_cache();
                        if ( $page_cache && method_exists( $page_cache, 'purge' ) ) {
                            $page_cache->purge();
                        }
                    }
                    if ( class_exists( 'WP_Optimize_Minify_Cache_Functions' )
                        && method_exists( 'WP_Optimize_Minify_Cache_Functions', 'purge' ) ) {
                        WP_Optimize_Minify_Cache_Functions::purge();
                    }
                    return true;
                },
            ],

            'swift-performance' => [
                'label'  => 'Swift Performance',
                'detect' => static function () {
                    return class_exists( 'Swift_Performance_Cache' );
                },
                'clear'  => static function () {
                    if ( method_exists( 'Swift_Performance_Cache', 'clear_all_cache' ) ) {
                        Swift_Performance_Cache::clear_all_cache();
                    }
                    return true;
                },
            ],

            'comet-cache' => [
                'label'  => 'Comet Cache',
                'detect' => static function () {
                    return class_exists( '\\WebSharks\\CometCache\\Plugin' );
                },
                'clear'  => static function () {
                    if ( method_exists( '\\WebSharks\\CometCache\\Plugin', 'wipeCache' ) ) {
                        \WebSharks\CometCache\Plugin::wipeCache();
                    }
                    return true;
                },
            ],

            'nitropack' => [
                'label'  => 'NitroPack',
                'detect' => static function () {
                    return defined( 'NITROPACK_VERSION' ) || function_exists( 'nitropack_purge_local' );
                },
                'clear'  => static function () {
                    if ( function_exists( 'nitropack_purge_local' ) ) {
                        nitropack_purge_local();
                    }
                    if ( function_exists( 'nitropack_sdk_purge_cache' ) ) {
                        nitropack_sdk_purge_cache();
                    }
                    do_action( 'nitropack_integration_purge_all' );
                    return true;
                },
            ],

            'wp-cloudflare-page-cache' => [
                'label'  => 'Super Page Cache for Cloudflare',
                'detect' => static function () {
                    return class_exists( 'SW_CLOUDFLARE_PAGECACHE' );
                },
                'clear'  => static function () {
                    do_action( 'swcfpc_clear_whole_page_cache' );
                    do_action( 'swcfpc_purge_whole_cache' );
                    return true;
                },
            ],

            'redis-object-cache' => [
                'label'  => 'Redis Object Cache',
                'detect' => static function () {
                    if ( ! class_exists( 'WP_Object_Cache' ) ) {
                        return false;
                    }
                    global $wp_object_cache;
                    return is_object( $wp_object_cache )
                        && ( method_exists( $wp_object_cache, 'redis_status' ) || isset( $wp_object_cache->redis ) );
                },
                'clear'  => static function () {
                    return wp_cache_flush();
                },
            ],
        ];

        return self::$supported_cache;
    }

    /**
     * Returns active cache plugins as [ id => label ].
     */
    public static function get_active_caches() {
        $active = [];
        foreach ( self::get_supported() as $id => $def ) {
            if ( call_user_func( $def['detect'] ) ) {
                $active[ $id ] = $def['label'];
            }
        }
        return $active;
    }

    /**
     * AJAX: clear one cache by id, or `all` to purge every detected cache.
     */
    public function ajax_clear() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'security', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'brikpanel' ) ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'brikpanel' ) ], 403 );
        }

        $id        = isset( $_POST['cache_id'] ) ? sanitize_key( wp_unslash( $_POST['cache_id'] ) ) : '';
        $supported = self::get_supported();

        $cleared = [];
        $failed  = [];

        $targets = [];
        if ( '' === $id || 'all' === $id ) {
            foreach ( $supported as $key => $def ) {
                if ( call_user_func( $def['detect'] ) ) {
                    $targets[ $key ] = $def;
                }
            }
        } elseif ( isset( $supported[ $id ] ) ) {
            $def = $supported[ $id ];
            if ( ! call_user_func( $def['detect'] ) ) {
                wp_send_json_error( [ 'message' => __( 'Cache plugin is not active.', 'brikpanel' ) ], 400 );
            }
            $targets[ $id ] = $def;
        } else {
            wp_send_json_error( [ 'message' => __( 'Unknown cache.', 'brikpanel' ) ], 400 );
        }

        if ( empty( $targets ) ) {
            wp_send_json_error( [ 'message' => __( 'No active cache plugins detected.', 'brikpanel' ) ], 404 );
        }

        foreach ( $targets as $key => $def ) {
            // Some plugins (notably WP Rocket on broken DB installs) echo
            // wpdberror HTML or admin notices straight to STDOUT during their
            // purge routine, which corrupts our JSON response. Wrap every
            // callback in a buffer and discard whatever was printed.
            ob_start();
            try {
                $result = call_user_func( $def['clear'] );
                ob_end_clean();
                if ( false === $result ) {
                    $failed[] = $def['label'];
                } else {
                    $cleared[] = $def['label'];
                }
            } catch ( \Throwable $e ) {
                ob_end_clean();
                $failed[] = $def['label'];
            }
        }

        // For an "all" purge also drop the WP object cache so stale transients
        // and preloaded fragments don't survive.
        if ( '' === $id || 'all' === $id ) {
            wp_cache_flush();
        }

        if ( empty( $cleared ) ) {
            wp_send_json_error( [
                'message' => __( 'Cache could not be cleared.', 'brikpanel' ),
                'failed'  => $failed,
            ], 500 );
        }

        $message = ( count( $cleared ) === 1 )
            ? sprintf( /* translators: %s: cache plugin name */ __( '%s purged.', 'brikpanel' ), $cleared[0] )
            : sprintf( /* translators: %s: comma-separated cache plugin names */ __( 'Caches purged: %s', 'brikpanel' ), implode( ', ', $cleared ) );

        wp_send_json_success( [
            'cleared' => $cleared,
            'failed'  => $failed,
            'message' => $message,
        ] );
    }
}

Brikpanel_Cache_Clear::instance();
