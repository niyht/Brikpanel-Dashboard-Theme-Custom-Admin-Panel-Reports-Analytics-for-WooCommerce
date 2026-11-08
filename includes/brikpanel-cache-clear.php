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

    /**
     * What the top bar cache button does with LiteSpeed Cache: purge
     * everything in one click (the default, and all it did before this
     * setting existed) or open a menu of LiteSpeed's own purge options.
     */
    const MODE_OPTION    = 'brikpanel_topbar_cache_button';
    const MODE_ONE_CLICK = 'one_click';
    const MODE_MENU      = 'menu';

    private static $instance = null;
    private static $supported_cache = null;

    /** LiteSpeed's purge entries taken from this request's admin bar, see capture_litespeed_items(). */
    private static $litespeed_items = [];

    /** CLP Varnish Cache's settings reader once found on this request, see clp_varnish_manager(). */
    private static $clp_varnish_manager = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_clear' ] );
        // The last moment before WordPress draws its admin bar, after every
        // plugin (LiteSpeed at admin_bar_menu 95) has added or removed nodes.
        add_action( 'wp_before_admin_bar_render', [ $this, 'capture_litespeed_items' ], PHP_INT_MAX );
        add_filter( 'brikpanel_settings_fields', [ $this, 'add_settings_field' ] );
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
     *  - extra   : Optional. True for a cache that goes along with every
     *              purge covering all active caches instead of having a row
     *              of its own (Elementor's generated files). It never makes
     *              the button appear by itself, and `part` names it in the
     *              lists of what a click clears.
     *
     * Each clear does what the plugin's own "clear all" button does, so the
     * used CSS that LiteSpeed, WP Rocket and Easy Optimizer keep apart stays
     * too: it rebuilds page by page, slowly, and on LiteSpeed out of its
     * monthly QUIC.cloud quota.
     *
     * Detection runs on every admin page render, so the callables must be
     * cheap (function_exists / class_exists / defined, or a setting the
     * plugin itself has already read on this request).
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

            'easy-optimizer' => [
                'label'  => 'Easy Optimizer',
                // The condition Easy Optimizer shows its own "Clear All Cache"
                // admin bar link under; it has read its settings by now.
                'detect' => static function () {
                    return is_callable( [ 'EasyOpt_Cache', 'is_enabled' ] ) && EasyOpt_Cache::is_enabled();
                },
                'clear'  => static function () {
                    if ( ! is_callable( [ 'EasyOpt_Cache', 'clear_all' ] ) ) {
                        return false;
                    }
                    EasyOpt_Cache::clear_all();
                    return true;
                },
            ],

            'flying-press' => [
                'label'  => 'FlyingPress',
                'detect' => static function () {
                    return is_callable( [ 'FlyingPress\\Purge', 'purge_pages' ] );
                },
                // Pages only. purge_everything() would also drop the used CSS
                // and the other generated files.
                'clear'  => static function () {
                    \FlyingPress\Purge::purge_pages();
                    return true;
                },
            ],

            'perfmatters' => [
                'label'  => 'Perfmatters',
                'detect' => static function () {
                    return is_callable( [ 'Perfmatters\\Minify', 'clear_minified' ] );
                },
                // The minified CSS and JS; its used CSS stays, as above.
                'clear'  => static function () {
                    $cleared = false;
                    foreach ( [ 'css', 'js' ] as $type ) {
                        try {
                            \Perfmatters\Minify::clear_minified( $type );
                            $cleared = true;
                        } catch ( \Throwable $e ) {
                            unset( $e );
                        }
                    }
                    return $cleared;
                },
            ],

            'nginx-helper' => [
                'label'  => 'Nginx Helper',
                // Its own "Purge Cache" admin bar link only shows with purging on.
                'detect' => static function () {
                    global $nginx_helper_admin;
                    return class_exists( 'Nginx_Helper' )
                        && is_object( $nginx_helper_admin )
                        && ! empty( $nginx_helper_admin->options['enable_purge'] )
                        && has_action( 'rt_nginx_helper_purge_all' );
                },
                'clear'  => static function () {
                    do_action( 'rt_nginx_helper_purge_all' );
                    // What its own "Purge Cache" fires next, for plugins that follow it.
                    do_action( 'rt_nginx_helper_after_purge_all' );
                    return true;
                },
            ],

            'proxy-cache-purge' => [
                'label'  => 'Proxy Cache Purge',
                'detect' => static function () {
                    if ( ! is_callable( [ 'VarnishPurger', 'purge_url' ] ) || ! is_callable( [ 'VarnishPurger', 'the_home_url' ] ) ) {
                        return false;
                    }
                    // Its own rule: on the main site of a subdirectory network
                    // the full purge also empties every other site, so only a
                    // network admin gets it there.
                    return ! ( is_multisite() && ! is_subdomain_install() && is_main_site() && ! current_user_can( 'manage_network' ) );
                },
                // Its "Purge Cache (All Pages)".
                'clear'  => static function () {
                    VarnishPurger::purge_url( VarnishPurger::the_home_url() . '/?vhp-regex' );
                    return true;
                },
            ],

            'clp-varnish-cache' => [
                'label'  => 'CLP Varnish Cache',
                // CloudPanel's own plugin for its Varnish (wp.org
                // clp-varnish-cache). Listed while that Varnish is switched
                // on, the condition its own automatic purge goes by. The
                // plugin loads in wp-admin only, admin-ajax included.
                'detect' => static function () {
                    $manager = self::clp_varnish_manager();
                    return null !== $manager && is_callable( [ $manager, 'is_enabled' ] ) && $manager->is_enabled();
                },
                // Its "Purge > Entire Cache", see clp_varnish_purge_all().
                'clear'  => static function () {
                    return self::clp_varnish_purge_all();
                },
            ],

            'kinsta' => [
                'label'  => 'Kinsta',
                'detect' => static function () {
                    global $kinsta_cache;
                    return class_exists( 'Kinsta\\Cache' )
                        && is_object( $kinsta_cache )
                        && isset( $kinsta_cache->kinsta_cache_purge )
                        && is_callable( [ $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ] );
                },
                'clear'  => static function () {
                    global $kinsta_cache;
                    $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
                    return true;
                },
            ],

            'wp-engine' => [
                'label'  => 'WP Engine',
                'detect' => static function () {
                    return class_exists( 'WpeCommon' );
                },
                'clear'  => static function () {
                    $ran = false;
                    foreach ( [ 'purge_memcached', 'purge_varnish_cache' ] as $method ) {
                        if ( is_callable( [ 'WpeCommon', $method ] ) ) {
                            call_user_func( [ 'WpeCommon', $method ] );
                            $ran = true;
                        }
                    }
                    // clear_maxcdn_cache() is the older name of clear_cdn_cache().
                    if ( is_callable( [ 'WpeCommon', 'clear_cdn_cache' ] ) ) {
                        WpeCommon::clear_cdn_cache();
                    } elseif ( is_callable( [ 'WpeCommon', 'clear_maxcdn_cache' ] ) ) {
                        WpeCommon::clear_maxcdn_cache();
                    }
                    if ( is_callable( [ 'WpeCommon', 'instance' ] ) ) {
                        $wpe = WpeCommon::instance();
                        if ( is_object( $wpe ) && is_callable( [ $wpe, 'purge_object_cache' ] ) ) {
                            $wpe->purge_object_cache();
                        }
                    }
                    return $ran;
                },
            ],

            'godaddy' => [
                'label'  => 'GoDaddy',
                'detect' => static function () {
                    return is_callable( [ 'WPaaS\\Cache', 'ban' ] ) && is_callable( [ 'WPaaS\\Cache', 'has_ban' ] );
                },
                // GoDaddy flushes at the end of the request: its full ban takes
                // the place of the per-page purge there, once.
                'clear'  => static function () {
                    remove_action( 'shutdown', [ '\\WPaaS\\Cache', 'purge' ], PHP_INT_MAX );
                    remove_action( 'shutdown', [ 'WPaaS\\Cache', 'purge' ], PHP_INT_MAX );
                    if ( ! \WPaaS\Cache::has_ban() ) {
                        add_action( 'shutdown', [ 'WPaaS\\Cache', 'ban' ], PHP_INT_MAX );
                    }
                    return true;
                },
            ],

            'elementor' => [
                'label'  => 'Elementor',
                'part'   => _x( 'Elementor cache', 'cache purge part', 'brikpanel' ),
                'extra'  => true,
                'detect' => static function () {
                    return did_action( 'elementor/loaded' )
                        && isset( \Elementor\Plugin::$instance->files_manager )
                        && is_callable( [ \Elementor\Plugin::$instance->files_manager, 'clear_cache' ] );
                },
                // Elementor's own "Clear Files & Data": its CSS files rebuild on
                // the next visit to each page. LiteSpeed and WP Rocket purge
                // themselves on its elementor/core/files/clear_cache action.
                'clear'  => static function () {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                    return true;
                },
            ],
        ];

        return self::$supported_cache;
    }

    /**
     * Returns active cache plugins as [ id => label ], without the extras
     * (see get_supported()): those never get a row of their own.
     */
    public static function get_active_caches() {
        $active = [];
        foreach ( self::get_supported() as $id => $def ) {
            if ( empty( $def['extra'] ) && call_user_func( $def['detect'] ) ) {
                $active[ $id ] = $def['label'];
            }
        }
        return $active;
    }

    /**
     * Active extras as [ id => definition ]: what goes along with a purge of
     * every active cache.
     */
    private static function get_active_extras() {
        $extras = [];
        foreach ( self::get_supported() as $id => $def ) {
            if ( ! empty( $def['extra'] ) && call_user_func( $def['detect'] ) ) {
                $extras[ $id ] = $def;
            }
        }
        return $extras;
    }

    /**
     * Names of the active extras for the lists of what a click clears, such
     * as "Elementor cache". Empty when there are none.
     *
     * @return string[]
     */
    public static function extra_parts() {
        return self::parts_of( self::get_active_extras() );
    }

    /**
     * @param array $defs Extras' definitions.
     * @return string[] Their names for a list of what was cleared.
     */
    private static function parts_of( array $defs ) {
        $parts = [];
        foreach ( $defs as $def ) {
            $parts[] = isset( $def['part'] ) ? $def['part'] : $def['label'];
        }
        return $parts;
    }

    // =========================================================================
    // CLP VARNISH CACHE (CLOUDPANEL)
    // =========================================================================

    /**
     * CLP Varnish Cache's settings reader, or null where the plugin is not
     * loaded (it loads in wp-admin only, admin-ajax included).
     *
     * The reader its admin holds comes first: on an admin page it has already
     * read the settings file for the plugin's own admin bar menu (the admin
     * bar is built at in_admin_header 0, the top bar at 10), so finding the
     * cache on every admin page costs no second file read. Once found, it is
     * kept for the rest of the request.
     *
     * @return object|null
     */
    private static function clp_varnish_manager() {
        if ( null !== self::$clp_varnish_manager ) {
            return self::$clp_varnish_manager;
        }
        global $clp_varnish_cache_admin;
        $manager = null;
        if ( is_object( $clp_varnish_cache_admin ) && is_callable( [ $clp_varnish_cache_admin, 'get_clp_cache_manager' ] ) ) {
            $from_admin = $clp_varnish_cache_admin->get_clp_cache_manager();
            if ( is_object( $from_admin ) ) {
                $manager = $from_admin;
            }
        }
        if ( null === $manager && class_exists( 'ClpVarnishCacheManager' ) ) {
            $manager = new ClpVarnishCacheManager();
        }
        self::$clp_varnish_manager = $manager;
        return $manager;
    }

    /**
     * What CLP Varnish Cache's own "Purge > Entire Cache" does: a PURGE for
     * the store's host and a PURGE for the tag every page of the site carries
     * (its cache tag prefix), both to the Varnish server in its settings,
     * which CloudPanel writes there (127.0.0.1:6081). The host is the home
     * URL's, as in the plugin's own automatic purge.
     *
     * The requests are sent here instead of through the plugin's manager:
     * its purge prints an error and ends the whole request whenever Varnish
     * answers anything but 200, which would cut off this purge's reply, the
     * caches after it in an "all" purge, and a settings save that runs
     * purge_all(). wp_remote_request, not the "safe" variant: the server is a
     * local address by design, read from the plugin's settings file, never
     * from the request.
     *
     * @return bool True when Varnish answered 200 to every request sent.
     */
    private static function clp_varnish_purge_all() {
        $manager = self::clp_varnish_manager();
        if ( null === $manager || ! is_callable( [ $manager, 'get_server' ] ) ) {
            return false;
        }
        $server = trim( (string) $manager->get_server() );
        $url    = 'http://' . $server;
        if ( '' === $server || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
            return false;
        }

        $requests = [];
        $host     = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( is_string( $host ) && '' !== $host ) {
            $requests[] = [ 'Host' => $host ];
        }
        $prefix = is_callable( [ $manager, 'get_cache_tag_prefix' ] ) ? trim( (string) $manager->get_cache_tag_prefix() ) : '';
        if ( '' !== $prefix ) {
            $requests[] = [ 'X-Cache-Tags' => $prefix ];
        }
        if ( ! $requests ) {
            return false;
        }

        // Both go out even when the first one fails: the tag alone already
        // empties every page of the site.
        $ok = true;
        foreach ( $requests as $headers ) {
            $response = wp_remote_request( $url, [
                'method'      => 'PURGE',
                'headers'     => $headers,
                'timeout'     => 5,
                'redirection' => 0,
            ] );
            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    // =========================================================================
    // LITESPEED: WHAT A PURGE CLEARS, AND ITS OWN PURGE MENU
    // =========================================================================

    /** @return bool */
    public static function litespeed_active() {
        $supported = self::get_supported();
        return (bool) call_user_func( $supported['litespeed']['detect'] );
    }

    /**
     * Whether the cache button opens LiteSpeed's purge menu. Anything stored
     * other than 'menu' means one click: WooCommerce checks a select on save,
     * but an import or a direct option write could leave any value here.
     *
     * @return bool
     */
    public static function litespeed_menu_mode() {
        return self::MODE_MENU === get_option( self::MODE_OPTION, self::MODE_ONE_CLICK );
    }

    /**
     * What LiteSpeed's "Purge All" clears on this site, as translated names
     * in LiteSpeed's own order. Mirrors LiteSpeed Cache's Purge::_purge_all():
     * a page cache exists only behind a LiteSpeed server (LITESPEED_ON), the
     * CSS/JS cache always goes, the object cache only while LiteSpeed's
     * drop-in runs it, OPcache only when LiteSpeed is allowed to reset it,
     * and Cloudflare only when the integration and its "clear on Purge All"
     * switch are both on. Localized resources are left out: LiteSpeed only
     * tags them for the page cache purge that already covers them.
     *
     * @param bool $with_extras Append the active extras ("Elementor cache"),
     *                          for a click that purges every active cache.
     * @return string[]
     */
    public static function litespeed_purge_all_parts( $with_extras = false ) {
        $parts = [];
        if ( defined( 'LITESPEED_ON' ) && LITESPEED_ON ) {
            $parts[] = _x( 'page cache', 'LiteSpeed purge part', 'brikpanel' );
        }
        $parts[] = _x( 'CSS/JS', 'LiteSpeed purge part', 'brikpanel' );
        if ( defined( 'LSCWP_OBJECT_CACHE' ) && LSCWP_OBJECT_CACHE ) {
            $parts[] = _x( 'object cache', 'LiteSpeed purge part', 'brikpanel' );
        }
        if ( self::litespeed_opcache_purgeable() ) {
            $parts[] = _x( 'opcode cache', 'LiteSpeed purge part', 'brikpanel' );
        }
        if ( has_filter( 'litespeed_conf' ) ) {
            // Without LiteSpeed's own callback the filter hands the key back.
            $cloudflare = apply_filters( 'litespeed_conf', 'cdn-cloudflare' );
            $clear      = apply_filters( 'litespeed_conf', 'cdn-cloudflare_clear' );
            if ( $cloudflare && 'cdn-cloudflare' !== $cloudflare && $clear && 'cdn-cloudflare_clear' !== $clear ) {
                $parts[] = 'Cloudflare';
            }
        }
        if ( $with_extras ) {
            $parts = array_merge( $parts, self::extra_parts() );
        }
        return $parts;
    }

    /**
     * Hover text of the one-click LiteSpeed button: what it is about to clear.
     *
     * @param bool $with_extras See litespeed_purge_all_parts().
     */
    public static function litespeed_purge_all_title( $with_extras = false ) {
        return sprintf(
            /* translators: 1: cache plugin name (LiteSpeed Cache), 2: what gets cleared, e.g. "page cache, CSS/JS and object cache" */
            __( 'Purge %1$s: %2$s', 'brikpanel' ),
            'LiteSpeed Cache',
            wp_sprintf_l( '%l', self::litespeed_purge_all_parts( $with_extras ) )
        );
    }

    /**
     * Grey line under LiteSpeed's "Clear everything" row.
     *
     * @param bool $with_extras See litespeed_purge_all_parts().
     */
    public static function litespeed_purge_all_summary( $with_extras = false ) {
        return sprintf(
            /* translators: %s: what gets cleared, e.g. "page cache, CSS/JS and object cache" */
            __( 'Clears the %s', 'brikpanel' ),
            wp_sprintf_l( '%l', self::litespeed_purge_all_parts( $with_extras ) )
        );
    }

    /**
     * Whether LiteSpeed would really reset OPcache here. LiteSpeed lists its
     * "Opcode Cache" entry whenever OPcache is on, then refuses quietly (and
     * still redirects as if it worked) when opcache.restrict_api shuts it out,
     * so both the menu and the summary ask its own two checks.
     *
     * @return bool
     */
    private static function litespeed_opcache_purgeable() {
        $router = '\LiteSpeed\Router';
        if ( class_exists( $router ) && method_exists( $router, 'opcache_enabled' ) ) {
            if ( ! $router::opcache_enabled() ) {
                return false;
            }
        } elseif ( ! function_exists( 'opcache_reset' ) || ! ini_get( 'opcache.enable' ) ) {
            return false;
        }
        // LiteSpeed asks this about the file that runs the reset (since 7.3).
        if ( defined( 'LSCWP_DIR' ) && class_exists( $router ) && method_exists( $router, 'opcache_restricted' ) ) {
            return ! $router::opcache_restricted( LSCWP_DIR . 'src/purge.cls.php' );
        }
        return true;
    }

    /**
     * Keep LiteSpeed's own purge entries from this request's admin bar.
     *
     * The BrikPanel top bar replaces the WordPress admin bar on screen, but
     * WordPress still builds that bar (hidden by CSS), LiteSpeed's
     * "Purge All - …" entries included. They carry LiteSpeed's own nonce and
     * only list what applies to this site, so the cache menu reuses them
     * instead of copying LiteSpeed's rules. Read here, just before the bar is
     * drawn: once drawn it is bound and get_nodes() returns nothing. The top
     * bar renders later on the same in_admin_header hook (10 against 0).
     */
    public function capture_litespeed_items() {
        if ( ! is_admin() || ! self::litespeed_active() || ! current_user_can( 'manage_options' ) || ! self::litespeed_menu_mode() ) {
            return;
        }
        global $wp_admin_bar;
        if ( ! is_object( $wp_admin_bar ) || ! method_exists( $wp_admin_bar, 'get_nodes' ) ) {
            return;
        }
        self::$litespeed_items = self::litespeed_items_from_nodes( (array) $wp_admin_bar->get_nodes() );
    }

    /**
     * LiteSpeed's purge entries captured on this request, in LiteSpeed's order.
     *
     * @return array<int, array{id: string, label: string, href: string}>
     */
    public static function litespeed_items() {
        return self::$litespeed_items;
    }

    /**
     * The purge entries under LiteSpeed's admin bar menu, except "Purge All"
     * itself (the menu's first row runs that through BrikPanel's own purge).
     * Labels lose LiteSpeed's "Purge All - " prefix, taken from its own
     * "Purge All" entry so it matches in every language; links that are not
     * LiteSpeed purge links on this site are dropped.
     *
     * @param object[] $nodes Admin bar nodes (id, parent, title, href).
     * @return array<int, array{id: string, label: string, href: string}>
     */
    public static function litespeed_items_from_nodes( array $nodes ) {
        $prefix = '';
        foreach ( $nodes as $node ) {
            if ( is_object( $node ) && isset( $node->id ) && 'litespeed-purge-all' === $node->id ) {
                $all    = self::plain_node_title( isset( $node->title ) ? $node->title : '' );
                $prefix = '' !== $all ? $all . ' - ' : '';
                break;
            }
        }

        $items = [];
        foreach ( $nodes as $node ) {
            if ( ! is_object( $node ) || empty( $node->id ) || ! isset( $node->parent ) || 'litespeed-menu' !== $node->parent ) {
                continue;
            }
            $id = (string) $node->id;
            if ( 0 !== strpos( $id, 'litespeed-purge' ) || 'litespeed-purge-all' === $id ) {
                continue;
            }
            if ( 'litespeed-purge-opcache' === $id && ! self::litespeed_opcache_purgeable() ) {
                continue;
            }
            $href = self::validated_litespeed_href( isset( $node->href ) ? $node->href : '' );
            if ( '' === $href ) {
                continue;
            }
            $label = self::plain_node_title( isset( $node->title ) ? $node->title : '' );
            if ( '' !== $prefix && 0 === strpos( $label, $prefix ) ) {
                $label = trim( substr( $label, strlen( $prefix ) ) );
            }
            if ( '' === $label ) {
                continue;
            }
            $items[] = [ 'id' => $id, 'label' => $label, 'href' => $href ];
        }
        return $items;
    }

    /** An admin bar title as plain text: tags out, entities decoded, spaces folded. */
    private static function plain_node_title( $title ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
    }

    /**
     * LiteSpeed's purge link as a plain URL, or '' when it is not one: http(s),
     * the host, port and path of this site's admin, and LiteSpeed's controller
     * and nonce in the query. LiteSpeed hands the URL over HTML-escaped
     * (&amp;), which has to be undone before the query can be read.
     *
     * @param string $href
     * @return string
     */
    private static function validated_litespeed_href( $href ) {
        $url   = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $parts = wp_parse_url( $url );
        $admin = wp_parse_url( admin_url() );
        if ( ! is_array( $parts ) || ! is_array( $admin ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $admin['host'] ) ) {
            return '';
        }
        if ( ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
            return '';
        }
        if ( strtolower( $parts['host'] ) !== strtolower( $admin['host'] ) ) {
            return '';
        }
        if ( ( isset( $parts['port'] ) ? (int) $parts['port'] : 0 ) !== ( isset( $admin['port'] ) ? (int) $admin['port'] : 0 ) ) {
            return '';
        }
        $admin_path = isset( $admin['path'] ) ? $admin['path'] : '/';
        if ( empty( $parts['path'] ) || 0 !== strpos( $parts['path'], $admin_path ) || empty( $parts['query'] ) ) {
            return '';
        }
        wp_parse_str( $parts['query'], $query );
        if ( empty( $query['LSCWP_CTRL'] ) || empty( $query['LSCWP_NONCE'] ) ) {
            return '';
        }
        return $url;
    }

    /**
     * The "Cache button" setting, right under the top bar items, on stores
     * where LiteSpeed Cache is active: it changes nothing anywhere else.
     * Import / Export knows the key from the file-scope registration below.
     *
     * @param array $fields WC settings field definitions.
     * @return array
     */
    public function add_settings_field( $fields ) {
        if ( ! self::litespeed_active() ) {
            return $fields;
        }
        $field = [
            'name'     => __( 'Cache button', 'brikpanel' ),
            'id'       => self::MODE_OPTION,
            'type'     => 'select',
            'desc_tip' => true,
            'desc'     => __( 'What the cache button in the top bar does with LiteSpeed Cache. The menu lists the purge options LiteSpeed offers on this site, such as the page cache, CSS/JS and the object cache.', 'brikpanel' ),
            'options'  => [
                self::MODE_ONE_CLICK => __( 'Clear everything in one click', 'brikpanel' ),
                self::MODE_MENU      => __( 'Open a menu to choose what to clear', 'brikpanel' ),
            ],
            'default'  => self::MODE_ONE_CLICK,
        ];
        $fields = array_values( (array) $fields );
        foreach ( $fields as $i => $existing ) {
            if ( is_array( $existing ) && isset( $existing['id'] ) && 'brikpanel_topbar_items_field' === $existing['id'] ) {
                array_splice( $fields, $i + 1, 0, [ $field ] );
                break;
            }
        }
        return $fields;
    }

    /**
     * Run the purge callback of every supplied cache definition.
     *
     * Some plugins (notably WP Rocket on broken DB installs) echo wpdberror
     * HTML or admin notices straight to STDOUT during their purge routine,
     * which would corrupt a JSON response or a redirect. Every callback is
     * wrapped in an output buffer that is discarded, and in a Throwable guard
     * so one broken cache plugin cannot take the request down with it.
     *
     * @param array $targets Map of cache id => definition.
     * @return array{0: string[], 1: string[]} Cleared labels, failed labels.
     */
    private static function run_purge( array $targets ) {
        $cleared = [];
        $failed  = [];

        foreach ( $targets as $def ) {
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

        return [ $cleared, $failed ];
    }

    /**
     * Purge every page cache BrikPanel can detect, without any UI.
     *
     * Used when a setting change invalidates already-cached storefront HTML —
     * the cookie-consent gate decides which shape of the tracker script gets
     * baked into the page, so flipping it has to reach the cache or the
     * change silently does nothing until the cache expires on its own.
     *
     * The extras are left out: Elementor's files do not carry such settings.
     *
     * @param string[] $skip Cache ids to leave alone, e.g. 'redis-object-cache'
     *                       when only page caches carry what changed.
     * @return string[] Labels of the caches that were purged.
     */
    public static function purge_all( array $skip = [] ) {
        $targets = [];
        foreach ( self::get_supported() as $key => $def ) {
            if ( ! empty( $def['extra'] ) || in_array( $key, $skip, true ) ) {
                continue;
            }
            if ( call_user_func( $def['detect'] ) ) {
                $targets[ $key ] = $def;
            }
        }
        if ( empty( $targets ) ) {
            return [];
        }

        list( $cleared ) = self::run_purge( $targets );

        return $cleared;
    }

    /**
     * AJAX: clear one cache by id, or `all` to purge every detected cache.
     *
     * A purge that covers every active cache (`all`, or the id of the only
     * one) takes the extras along, first: the page caches purged after them
     * rebuild their pages against Elementor's new files. A single row of the
     * 2+ plugin menu purges that plugin alone.
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
        $active    = self::get_active_caches();

        $targets = [];
        if ( '' === $id || 'all' === $id ) {
            foreach ( array_keys( $active ) as $key ) {
                $targets[ $key ] = $supported[ $key ];
            }
        } elseif ( isset( $supported[ $id ] ) && empty( $supported[ $id ]['extra'] ) ) {
            if ( ! isset( $active[ $id ] ) ) {
                wp_send_json_error( [ 'message' => __( 'Cache plugin is not active.', 'brikpanel' ) ], 400 );
            }
            $targets[ $id ] = $supported[ $id ];
        } else {
            wp_send_json_error( [ 'message' => __( 'Unknown cache.', 'brikpanel' ) ], 400 );
        }

        if ( empty( $targets ) ) {
            wp_send_json_error( [ 'message' => __( 'No active cache plugins detected.', 'brikpanel' ) ], 404 );
        }

        $extras = count( $targets ) === count( $active ) ? self::get_active_extras() : [];
        list( $extras_cleared, $extras_failed ) = self::run_purge( $extras );
        list( $cleared, $failed ) = self::run_purge( $targets );
        $failed = array_merge( $failed, $extras_failed );

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

        if ( [ 'litespeed' ] === array_keys( $targets ) && 1 === count( $cleared ) ) {
            // LiteSpeed alone: say what its Purge All really cleared here.
            $done = [];
            foreach ( $extras as $def ) {
                if ( in_array( $def['label'], $extras_cleared, true ) ) {
                    $done[] = $def;
                }
            }
            $message = sprintf(
                /* translators: 1: cache plugin name (LiteSpeed Cache), 2: what was cleared, e.g. "page cache, CSS/JS and object cache" */
                __( '%1$s purged: %2$s.', 'brikpanel' ),
                $cleared[0],
                wp_sprintf_l( '%l', array_merge( self::litespeed_purge_all_parts(), self::parts_of( $done ) ) )
            );
        } else {
            $names   = array_merge( $cleared, $extras_cleared );
            $message = ( count( $names ) === 1 )
                ? sprintf( /* translators: %s: cache plugin name */ __( '%s purged.', 'brikpanel' ), $names[0] )
                : sprintf( /* translators: %s: comma-separated cache plugin names */ __( 'Caches purged: %s.', 'brikpanel' ), implode( ', ', $names ) );
        }
        if ( $failed ) {
            $message .= ' ' . sprintf(
                /* translators: %s: comma-separated names of the cache plugins whose purge failed */
                __( 'Could not clear: %s.', 'brikpanel' ),
                implode( ', ', $failed )
            );
        }

        wp_send_json_success( [
            'cleared' => array_merge( $cleared, $extras_cleared ),
            'failed'  => $failed,
            'message' => $message,
        ] );
    }
}

/**
 * Tell Import / Export about the cache button setting.
 *
 * Registered here, at file scope, because the settings field only exists on
 * stores where LiteSpeed Cache is active: the settings-field walk misses the
 * key everywhere else, so an export from such a store would leave it out and
 * an import into one would skip it.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_cache_button_register_export_keys' );
function brikpanel_cache_button_register_export_keys( $map ) {
    $map[ Brikpanel_Cache_Clear::MODE_OPTION ] = [
        'class'    => 'portable',
        'group'    => 'dashboard',
        'sanitize' => 'brikpanel_cache_button_sanitize_mode',
        'default'  => Brikpanel_Cache_Clear::MODE_ONE_CLICK,
    ];
    return $map;
}

/**
 * @param mixed $value Stored or imported value.
 * @return string 'menu', or the one-click default for anything else.
 */
function brikpanel_cache_button_sanitize_mode( $value ) {
    return Brikpanel_Cache_Clear::MODE_MENU === $value ? Brikpanel_Cache_Clear::MODE_MENU : Brikpanel_Cache_Clear::MODE_ONE_CLICK;
}

Brikpanel_Cache_Clear::instance();
