<?php
/**
 * BrikPanel: BrikControl unused images scanner
 *
 * Finds the images in the Media Library that nothing on the site uses any
 * more: the product photo replaced by a better one, the AI test shots, the
 * theme demo pictures (field request, 22 Oct 2026). The Store Health card
 * lists them, and the merchant looks at each one before anything moves.
 *
 * A wrong "unused" breaks a shop page, a wrong "used" only leaves one image
 * off the list. Every rule below leans the same way: when in doubt, used.
 *
 *  1. Candidates are images that could be listed at all. Never: an image
 *     uploaded in the last day (it may be on its way into a product), one
 *     attached to an order or to any other non-content record (a customer's
 *     file), one in woocommerce_uploads (a downloadable product), one whose
 *     file another Media Library entry shares (WPML and Polylang language
 *     copies: deleting one deletes the file under the others too), one
 *     WordPress keeps as a custom header or background.
 *  2. Everything that can point at an image is read once, and every
 *     reference found takes its image off the candidates: post, page,
 *     product, variation, menu, template and block contents with every meta
 *     field, term descriptions and meta, every option, comments and comment
 *     meta (review photos), user meta (vendor logos, avatars), the site's
 *     other tables, the meta of orders and of deleted posts, and the active
 *     theme's files. Revisions are left out on purpose: a replaced image is
 *     exactly the one an old revision still mentions.
 *  3. A reference is a file name in any spelling (an address, JSON with
 *     escaped slashes or \u escapes, a size like -300x200, -scaled,
 *     -rotated, an edited -e123… copy, a .webp twin, base64 inside a page
 *     builder block) or a number where an image id can stand: wp-image-N,
 *     key=N attributes, JSON and serialised values, a field holding just a
 *     number or a list of numbers. A number that is really a price or a
 *     stock level can only keep an image, never list one.
 *
 * The scan runs in slices (step()) and keeps everything it needs in one
 * array, so it can be stored between background steps or run start to end
 * in memory (tests, dry runs on copies of real stores). It never writes.
 * A very large library is read in windows of WINDOW images, each window
 * with its own pass over the site, so the state stays small.
 *
 * @package BrikPanel
 * @since   3.3.39
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Unused_Images_Scanner {

    /** Shape of the state array; a stored state of another shape is dropped. */
    const STATE_VERSION = 1;

    /** Images per window (see the class comment). */
    const WINDOW = 50000;

    /** An image uploaded more recently than this is not a candidate yet. */
    const RECENT = DAY_IN_SECONDS;

    const CHUNK_ATTACHMENTS = 2000;
    const CHUNK_POSTS       = 200;
    const CHUNK_OPTIONS     = 500;
    const CHUNK_IDS         = 20000;
    const CHUNK_REFS        = 20000;
    const CHUNK_TABLE_ROWS  = 2000;
    const CHUNK_FILES       = 100;
    const CHUNK_MEASURE     = 200;

    /** A value longer than this is read in overlapping slices. */
    const SLICE = 1048576;
    const SLICE_OVERLAP = 512;

    /** Other tables larger than this are not read. */
    const MAX_TABLE_BYTES = 209715200;
    const MAX_TABLES      = 300;

    /** Theme files read at most: WoodMart alone has ~5,000 files and 63 MB of them. */
    const THEME_MAX_FILES = 20000;
    const THEME_MAX_FILE  = 4194304;
    const THEME_MAX_TOTAL = 268435456;

    /** File types a reference can name: images, and the video twins of animated GIFs. */
    const EXT = 'jpe?g|jpe|jfif|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|heif|mp4|webm|m4v';

    /**
     * Post types whose rows are not read as content. Revisions keep replaced
     * images on purpose; orders are read for file names only (meta_files);
     * Action Scheduler's old post store holds job arguments.
     */
    const SKIP_POST_TYPES  = [ 'revision', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription', 'scheduled-action' ];
    const ORDER_POST_TYPES = [ 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_subscription' ];

    /**
     * Records an image may be attached to and still be a candidate, besides
     * the post types a visitor can view. Anything else (an order, a form
     * entry, a ticket) holds someone's file.
     */
    const CONTENT_PARENT_TYPES = [ 'product_variation', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'nav_menu_item', 'attachment', 'wp_global_styles', 'customize_changeset' ];

    /**
     * Meta and option keys known to hold numbers that are not image ids:
     * prices, stock, counts, scores, dimensions, times. Their numbers are not
     * read as ids, so an early image whose id equals a price is still found.
     * File names in them are still read.
     */
    const NUMBER_KEYS = [
        '_price', '_regular_price', '_sale_price', '_sale_price_dates_from', '_sale_price_dates_to', '_stock', '_low_stock_amount',
        'total_sales', '_wc_average_rating', '_wc_review_count', '_wc_rating_count', '_download_limit', '_download_expiry',
        '_weight', '_length', '_width', '_height', '_edit_last', '_edit_lock', '_wp_old_date', '_wp_trash_meta_time',
        '_wp_attachment_image_alt', 'menu_order', '_menu_order', 'order', '_order_count', '_money_spent', 'paying_customer',
        'wc_last_active', 'last_update', 'user_level', 'post_views_count', 'views', 'rank_math_seo_score',
        '_yoast_wpseo_linkdex', '_yoast_wpseo_content_score', '_yoast_wpseo_estimated-reading-time-minutes',
        'product_count_product_cat', 'product_count_product_tag', 'posts_per_page', 'posts_per_rss', 'start_of_week',
        'gmt_offset', 'db_version', 'initial_db_version', 'comments_per_page', 'thread_comments_depth', 'close_comments_days_old',
    ];

    /** Key endings with the same meaning as NUMBER_KEYS. */
    const NUMBER_SUFFIXES = [
        '_price', '_count', '_total', '_qty', '_quantity', '_stock', '_amount', '_score', '_rating', '_views', '_time',
        '_timestamp', '_date', '_version', '_weight', '_width', '_height', '_length', '_size_w', '_size_h', '_per_page',
        '_limit', '_percent', '_level',
    ];

    /** Options this scan never reads: caches, jobs, and its own and Store Health's records of image ids. */
    const SKIP_OPTION_PREFIXES = [ '_transient_', '_site_transient_', '_wp_session_', 'brikpanel_unused_images_', 'brikpanel_image_cleanup_backup' ];
    const SKIP_OPTIONS         = [ 'cron', 'rewrite_rules', 'brikpanel_brikcontrol_results', 'brikpanel_brikcontrol_image_partial' ];

    /** User meta that never names an image and makes up most rows on a store. */
    const SKIP_USER_META = [
        'session_tokens', 'capabilities', 'user_level', 'dismissed_wp_pointers', 'community-events-location', 'wc_last_active',
        'last_update', 'paying_customer', '_order_count', '_money_spent', 'nickname', 'first_name', 'last_name', 'rich_editing',
        'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'user-settings',
        'user-settings-time', 'billing_first_name', 'billing_last_name', 'billing_company', 'billing_address_1', 'billing_address_2',
        'billing_city', 'billing_postcode', 'billing_country', 'billing_state', 'billing_phone', 'billing_email',
        'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2',
        'shipping_city', 'shipping_postcode', 'shipping_country', 'shipping_state', 'shipping_phone',
    ];

    /**
     * Other tables whose name says they hold logs, sessions, statistics,
     * caches or queues: never pages or settings, and often the biggest
     * tables of the database. Matched on the name without the site prefix.
     */
    const SKIP_TABLE_PATTERN = '~(?:^|_)(?:logs?|sessions?|stats?|statistics|visits?|visitors?|hits?|analytics|cache|caches|queue|actionscheduler|audit|activity|history|lookup|transients?|backups?|mails?|emails?|notifications?|tokens?|events?|tracking|reports?|crawl|404s?|redirects?|redirections?)(?:_|$)~i';
    const SKIP_TABLE_PREFIXES = [ 'wf', 'brikpanel_', 'wc_admin', 'woocommerce_sessions', 'woocommerce_api_keys', 'woocommerce_payment_token', 'woocommerce_shipping_zone', 'woocommerce_tax_rate', 'woocommerce_downloadable_product_permissions', 'wc_rate_limits', 'wc_reserved_stock', 'wc_webhooks', 'wc_tax_rate_classes', 'wc_download_log' ];
    const SKIP_TABLES = [ 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_order_stats', 'wc_customer_lookup', 'woocommerce_order_items', 'woocommerce_attribute_taxonomies' ];

    /** Theme files read for addresses, and folders that hold none of the site's own. */
    const THEME_EXT       = [ 'php', 'css', 'scss', 'less', 'js', 'html', 'htm', 'twig', 'json', 'svg' ];
    const THEME_SKIP_DIRS = [ 'node_modules', '.git', 'vendor', 'languages' ];

    /** Share of a window each pass takes in the progress bar. */
    const WEIGHTS = [
        'candidates' => 4,
        'quick'      => 2,
        'posts'      => 50,
        'meta_files' => 8,
        'terms'      => 3,
        'options'    => 6,
        'comments'   => 6,
        'users'      => 8,
        'tables'     => 8,
        'theme'      => 5,
    ];

    /** @var wpdb */
    private $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    // =========================================================================
    // STATE
    // =========================================================================

    /**
     * A new scan, before its first slice.
     *
     * @param int $now Time the scan starts.
     * @return array
     */
    public static function new_state( $now = 0 ) {
        $now = $now > 0 ? (int) $now : time();
        return [
            'v'         => self::STATE_VERSION,
            'id'        => $now . '-' . wp_rand( 1000, 9999 ),
            'started'   => $now,
            'phase'     => 'shared',
            'cursor'    => 0,
            'sub'       => 0,
            'win_from'  => 0,
            'win_to'    => 0,
            'win_index' => 0,
            'win_total' => 1,
            'win_seen'  => 0,
            'more'      => false,
            'cands'     => [],
            'stems'     => [],
            'shared'    => [],
            'unused'    => [],
            'measured'  => 0,
            'totals'    => [],
            'done'      => [],
            'tables'    => null,
            'counts'    => [ 'images' => 0, 'candidates' => 0, 'recent' => 0 ],
        ];
    }

    /**
     * Whether a stored value is a state this build can continue.
     *
     * @param mixed $state
     * @return bool
     */
    public static function is_state( $state ) {
        return is_array( $state ) && (int) ( $state['v'] ?? 0 ) === self::STATE_VERSION && ! empty( $state['phase'] );
    }

    /**
     * Work on the scan for about $budget seconds.
     *
     * @param array $state  Changed in place.
     * @param float $budget Seconds.
     * @return bool True once the scan is finished.
     */
    public function step( array &$state, $budget ) {
        $until = microtime( true ) + max( 0.5, (float) $budget );
        $guard = 0;

        while ( 'done' !== $state['phase'] && microtime( true ) < $until && $guard++ < 100000 ) {
            // Every candidate of the window has turned out to be used: the
            // rest of its passes have nothing left to find.
            if ( empty( $state['cands'] ) && isset( self::WEIGHTS[ $state['phase'] ] ) && 'candidates' !== $state['phase'] ) {
                $this->end_window( $state );
                continue;
            }
            switch ( $state['phase'] ) {
                case 'shared':
                    $this->phase_shared( $state );
                    break;
                case 'candidates':
                    $this->phase_candidates( $state );
                    break;
                case 'quick':
                    $this->phase_quick( $state );
                    break;
                case 'posts':
                    $this->phase_posts( $state );
                    break;
                case 'meta_files':
                    $this->phase_meta_files( $state );
                    break;
                case 'terms':
                    $this->phase_terms( $state );
                    break;
                case 'options':
                    $this->phase_options( $state );
                    break;
                case 'comments':
                    $this->phase_comments( $state );
                    break;
                case 'users':
                    $this->phase_users( $state );
                    break;
                case 'tables':
                    $this->phase_tables( $state );
                    break;
                case 'theme':
                    $this->phase_theme( $state );
                    break;
                case 'measure':
                    $this->phase_measure( $state );
                    break;
                default:
                    $state['phase'] = 'done';
            }
        }

        return 'done' === $state['phase'];
    }

    /**
     * The finished scan: unused image id => bytes it takes on the server,
     * by id.
     *
     * @param array $state
     * @return array<int,int>
     */
    public static function unused_of( array $state ) {
        $out = [];
        foreach ( (array) $state['unused'] as $id => $bytes ) {
            $out[ (int) $id ] = max( 0, (int) $bytes );
        }
        ksort( $out );
        return $out;
    }

    /**
     * How far the scan is, 0 to 100, and the pass it is in.
     *
     * @param array $state
     * @return array{pct:float, phase:string}
     */
    public static function progress( array $state ) {
        $phase = (string) ( $state['phase'] ?? 'shared' );
        if ( 'done' === $phase ) {
            return [ 'pct' => 100.0, 'phase' => 'done' ];
        }
        if ( 'shared' === $phase ) {
            return [ 'pct' => 0.0, 'phase' => 'shared' ];
        }
        if ( 'measure' === $phase ) {
            $all  = max( 1, count( (array) $state['unused'] ) );
            $frac = min( 1, (int) $state['measured'] / $all );
            return [ 'pct' => round( 97 + 3 * $frac, 1 ), 'phase' => 'measure' ];
        }

        $before = 0;
        foreach ( self::WEIGHTS as $name => $weight ) {
            if ( $name === $phase ) {
                break;
            }
            $before += $weight;
        }
        $total  = (float) ( $state['totals'][ $phase ] ?? 0 );
        $done   = (float) ( $state['done'][ $phase ] ?? 0 );
        $frac   = $total > 0 ? min( 1, $done / $total ) : 0;
        $window = ( $before + ( self::WEIGHTS[ $phase ] ?? 0 ) * $frac ) / 100;
        $pct    = 97 * ( (int) $state['win_index'] + $window ) / max( 1, (int) $state['win_total'] );

        return [ 'pct' => round( min( 97, $pct ), 1 ), 'phase' => $phase ];
    }

    /**
     * Move on to the next pass of the window, or end the window when no
     * candidate is left to look for.
     *
     * @param array  $state
     * @param string $next
     * @return void
     */
    private function next_phase( array &$state, $next ) {
        $state['cursor'] = 0;
        $state['sub']    = 0;
        if ( 'candidates' !== $next && 'measure' !== $next && empty( $state['cands'] ) ) {
            $this->end_window( $state );
            return;
        }
        $state['phase'] = $next;
    }

    /**
     * Whatever is still a candidate after every pass is unused. Then the next
     * window, or the measuring of what was found.
     *
     * @param array $state
     * @return void
     */
    private function end_window( array &$state ) {
        foreach ( array_keys( (array) $state['cands'] ) as $id ) {
            $state['unused'][ (int) $id ] = 0;
        }
        $state['cands']  = [];
        $state['stems']  = [];
        $state['done']   = [];
        $state['cursor'] = 0;
        $state['sub']    = 0;

        if ( ! empty( $state['more'] ) ) {
            $state['win_index']++;
            $state['win_from'] = (int) $state['win_to'];
            $state['phase']    = 'candidates';
            return;
        }

        $state['phase']    = 'measure';
        $state['measured'] = 0;
    }

    // =========================================================================
    // PASSES
    // =========================================================================

    /**
     * Once per scan: files that more than one Media Library entry shares, and
     * the sizes the progress bar measures against.
     *
     * @param array $state
     * @return void
     */
    private function phase_shared( array &$state ) {
        $db = $this->db;

        $shared = [];
        foreach ( (array) $db->get_col( "SELECT LOWER(meta_value) FROM {$db->postmeta} WHERE meta_key = '_wp_attached_file' GROUP BY LOWER(meta_value) HAVING COUNT(*) > 1" ) as $file ) {
            $shared[ (string) $file ] = true;
        }
        $state['shared'] = $shared;

        $images = (int) $db->get_var( "SELECT COUNT(*) FROM {$db->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status IN ('inherit','private')" );
        $state['counts']['images'] = $images;
        $state['win_total']        = max( 1, (int) ceil( $images / self::WINDOW ) );

        $skip = self::sql_in( self::SKIP_POST_TYPES );
        $state['totals'] = [
            'candidates' => max( 1, min( self::WINDOW, $images ) ),
            'quick'      => 3,
            'posts'      => (int) $db->get_var( "SELECT COUNT(*) FROM {$db->posts} WHERE post_type NOT IN ({$skip})" ),
            'meta_files' => (int) $db->get_var( "SELECT MAX(meta_id) FROM {$db->postmeta}" ),
            'terms'      => (int) $db->get_var( "SELECT MAX(term_taxonomy_id) FROM {$db->term_taxonomy}" ) + (int) $db->get_var( "SELECT MAX(meta_id) FROM {$db->termmeta}" ),
            'options'    => (int) $db->get_var( "SELECT MAX(option_id) FROM {$db->options}" ),
            'comments'   => (int) $db->get_var( "SELECT MAX(comment_ID) FROM {$db->comments}" ),
            'users'      => (int) $db->get_var( "SELECT MAX(umeta_id) FROM {$db->usermeta}" ),
            'tables'     => 1,
            'theme'      => 1,
        ];

        $state['phase']  = 'candidates';
        $state['cursor'] = 0;

        if ( 0 === $images ) {
            $state['phase'] = 'measure';
        }
    }

    /**
     * The window's candidates: up to WINDOW images after the last window, the
     * ones that may be listed at all (class comment, rule 1), with the file
     * names they can be referred to by.
     *
     * @param array $state
     * @return void
     */
    private function phase_candidates( array &$state ) {
        $db     = $this->db;
        $cursor = max( (int) $state['win_from'], (int) $state['cursor'] );

        $rows = $db->get_results( $db->prepare(
            "SELECT ID, post_parent, post_date_gmt FROM {$db->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_status IN ('inherit','private') AND ID > %d
             ORDER BY ID ASC LIMIT %d",
            'image/%',
            $cursor,
            self::CHUNK_ATTACHMENTS
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            $state['more']   = false;
            $state['win_to'] = $cursor;
            $this->next_phase( $state, 'quick' );
            return;
        }

        $ids     = [];
        $parents = [];
        foreach ( $rows as $row ) {
            $ids[] = (int) $row['ID'];
            if ( (int) $row['post_parent'] > 0 ) {
                $parents[ (int) $row['post_parent'] ] = true;
            }
        }

        $meta = $this->meta_of( $ids, [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_is_custom_header', '_wp_attachment_is_custom_background' ] );
        $types = $this->post_types_of( array_keys( $parents ) );
        $since = time() - self::RECENT;

        foreach ( $rows as $row ) {
            $id = (int) $row['ID'];
            $state['win_seen']++;
            $state['cursor'] = $id;

            $uploaded = ( '0000-00-00 00:00:00' !== $row['post_date_gmt'] ) ? strtotime( $row['post_date_gmt'] . ' UTC' ) : 0;
            if ( $uploaded > $since ) {
                $state['counts']['recent']++;
                continue;
            }

            $m    = $meta[ $id ] ?? [];
            $file = isset( $m['_wp_attached_file'] ) ? (string) $m['_wp_attached_file'] : '';
            if ( '' === $file
                || 0 === stripos( $file, 'woocommerce_uploads/' )
                || isset( $state['shared'][ strtolower( $file ) ] )
                || isset( $m['_wp_attachment_is_custom_header'] )
                || isset( $m['_wp_attachment_is_custom_background'] ) ) {
                continue;
            }

            $parent = (int) $row['post_parent'];
            if ( $parent > 0 && isset( $types[ $parent ] ) && ! self::is_content_type( $types[ $parent ] ) ) {
                continue;
            }

            $stems = self::attachment_stems(
                $file,
                isset( $m['_wp_attachment_metadata'] ) ? self::maybe_array( $m['_wp_attachment_metadata'] ) : [],
                isset( $m['_wp_attachment_backup_sizes'] ) ? self::maybe_array( $m['_wp_attachment_backup_sizes'] ) : []
            );
            if ( empty( $stems ) ) {
                continue;
            }

            $state['cands'][ $id ] = true;
            $state['counts']['candidates']++;
            foreach ( $stems as $stem ) {
                self::add_stem( $state, $stem, $id );
            }
        }

        $state['done']['candidates'] = (int) $state['win_seen'];

        if ( $state['win_seen'] >= self::WINDOW ) {
            $state['more']   = count( $rows ) >= self::CHUNK_ATTACHMENTS || (int) $db->get_var( $db->prepare(
                "SELECT COUNT(*) FROM {$db->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d",
                'image/%',
                (int) $state['cursor']
            ) ) > 0;
            $state['win_to']   = (int) $state['cursor'];
            $state['win_seen'] = 0;
            $this->next_phase( $state, 'quick' );
            return;
        }

        if ( count( $rows ) < self::CHUNK_ATTACHMENTS ) {
            $state['more']     = false;
            $state['win_to']   = (int) $state['cursor'];
            $state['win_seen'] = 0;
            $this->next_phase( $state, 'quick' );
        }
    }

    /**
     * Featured images (every post type, variations and video posters too)
     * and product galleries: indexed and cheap, and they take most product
     * images off the candidates before the long passes start.
     *
     * @param array $state
     * @return void
     */
    private function phase_quick( array &$state ) {
        $db   = $this->db;
        $keys = [ '_thumbnail_id', '_product_image_gallery' ];
        $sub  = (int) $state['sub'];

        if ( ! isset( $keys[ $sub ] ) ) {
            $state['done']['quick'] = 3;
            $this->next_phase( $state, 'posts' );
            return;
        }

        $rows = $db->get_results( $db->prepare(
            "SELECT meta_id, meta_value FROM {$db->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
            $keys[ $sub ],
            (int) $state['cursor'],
            self::CHUNK_REFS
        ), ARRAY_A );

        $ids = [];
        foreach ( (array) $rows as $row ) {
            $state['cursor'] = (int) $row['meta_id'];
            foreach ( preg_split( '~\s*,\s*~', trim( (string) $row['meta_value'] ) ) as $token ) {
                if ( ctype_digit( $token ) ) {
                    $ids[ (int) $token ] = true;
                }
            }
        }
        $this->consume( $state, $ids, [] );

        if ( count( (array) $rows ) < self::CHUNK_REFS ) {
            $state['sub']    = $sub + 1;
            $state['cursor'] = 0;
            $state['done']['quick'] = $sub + 1;
        }
    }

    /**
     * Every post except revisions and orders: its contents, its excerpt and
     * all its meta. An image's own Media Library entry is read too (another
     * plugin may store a reference there), minus what it says about itself.
     *
     * @param array $state
     * @return void
     */
    private function phase_posts( array &$state ) {
        $db   = $this->db;
        $skip = self::sql_in( self::SKIP_POST_TYPES );

        $rows = $db->get_results( $db->prepare(
            "SELECT ID, post_type, post_content, post_excerpt, post_content_filtered FROM {$db->posts}
             WHERE ID > %d AND post_type NOT IN ({$skip}) ORDER BY ID ASC LIMIT %d",
            (int) $state['cursor'],
            self::CHUNK_POSTS
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            $this->next_phase( $state, 'meta_files' );
            return;
        }

        $ids = [];
        foreach ( $rows as $row ) {
            $ids[] = (int) $row['ID'];
        }
        $meta_rows = $db->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$db->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ')',
            ARRAY_A
        );
        $meta = [];
        foreach ( (array) $meta_rows as $m ) {
            $meta[ (int) $m['post_id'] ][] = [ (string) $m['meta_key'], (string) $m['meta_value'] ];
        }
        unset( $meta_rows );

        foreach ( $rows as $row ) {
            $id         = (int) $row['ID'];
            $self_id    = 0;
            $self_stems = [];
            $fields     = $meta[ $id ] ?? [];

            if ( 'attachment' === $row['post_type'] ) {
                // What an image's entry says about its own file is not a use of it.
                $self_id = $id;
                $file    = '';
                $info    = [];
                $backup  = [];
                foreach ( $fields as $f ) {
                    if ( '_wp_attached_file' === $f[0] ) {
                        $file = $f[1];
                    } elseif ( '_wp_attachment_metadata' === $f[0] ) {
                        $info = self::maybe_array( $f[1] );
                    } elseif ( '_wp_attachment_backup_sizes' === $f[0] ) {
                        $backup = self::maybe_array( $f[1] );
                    }
                }
                foreach ( self::attachment_stems( $file, $info, $backup ) as $stem ) {
                    $self_stems[ $stem ] = true;
                }
                $fields = array_values( array_filter( $fields, static function ( $f ) {
                    return ! in_array( $f[0], [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ], true );
                } ) );
            }

            foreach ( [ 'post_content', 'post_excerpt', 'post_content_filtered' ] as $col ) {
                if ( '' !== (string) $row[ $col ] ) {
                    $this->read_value( $state, (string) $row[ $col ], true, $self_id, $self_stems );
                }
            }
            foreach ( $fields as $f ) {
                $this->read_value( $state, $f[1], ! self::is_number_key( $f[0] ), $self_id, $self_stems );
            }

            $state['cursor'] = $id;
            $state['done']['posts'] = (int) ( $state['done']['posts'] ?? 0 ) + 1;
        }
    }

    /**
     * The meta of orders and of posts that no longer exist, for file names
     * only: a customer's file sent by address, a plugin that kept a copy.
     * Revisions stay out (see the class comment).
     *
     * @param array $state
     * @return void
     */
    private function phase_meta_files( array &$state ) {
        $db   = $this->db;
        $max  = (int) ( $state['totals']['meta_files'] ?? 0 );
        $from = (int) $state['cursor'];

        if ( $from >= $max ) {
            $this->next_phase( $state, 'terms' );
            return;
        }

        $to     = $from + self::CHUNK_IDS;
        $orders = self::sql_in( self::ORDER_POST_TYPES );
        // Built without prepare(): the LIKE patterns carry literal % signs and
        // every other part is a cast integer or a fixed name.
        $values = $db->get_col(
            "SELECT pm.meta_value FROM {$db->postmeta} pm LEFT JOIN {$db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_id > " . (int) $from . ' AND pm.meta_id <= ' . (int) $to . "
             AND ( p.ID IS NULL OR p.post_type IN ({$orders}) ) AND " . self::sql_has_file( 'pm.meta_value' )
        );
        foreach ( (array) $values as $value ) {
            $this->read_value( $state, (string) $value, false );
        }

        $state['cursor']             = $to;
        $state['done']['meta_files'] = $to;
    }

    /**
     * Term descriptions, then term meta (category, brand and swatch images).
     *
     * @param array $state
     * @return void
     */
    private function phase_terms( array &$state ) {
        $db = $this->db;

        if ( 0 === (int) $state['sub'] ) {
            $rows = $db->get_results( $db->prepare(
                "SELECT term_taxonomy_id, description FROM {$db->term_taxonomy} WHERE term_taxonomy_id > %d ORDER BY term_taxonomy_id ASC LIMIT %d",
                (int) $state['cursor'],
                self::CHUNK_REFS
            ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $state['cursor'] = (int) $row['term_taxonomy_id'];
                if ( '' !== (string) $row['description'] ) {
                    $this->read_value( $state, (string) $row['description'], true );
                }
            }
            $state['done']['terms'] = (int) $state['cursor'];
            if ( count( (array) $rows ) < self::CHUNK_REFS ) {
                $state['sub']    = 1;
                $state['cursor'] = 0;
            }
            return;
        }

        $base = (int) $db->get_var( "SELECT MAX(term_taxonomy_id) FROM {$db->term_taxonomy}" );
        $rows = $db->get_results( $db->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$db->termmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
            (int) $state['cursor'],
            self::CHUNK_REFS
        ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $state['cursor'] = (int) $row['meta_id'];
            $this->read_value( $state, (string) $row['meta_value'], ! self::is_number_key( (string) $row['meta_key'] ) );
        }
        $state['done']['terms'] = $base + (int) $state['cursor'];
        if ( count( (array) $rows ) < self::CHUNK_REFS ) {
            $this->next_phase( $state, 'options' );
        }
    }

    /**
     * Every option but caches and job queues: site icon and logo, theme and
     * builder settings, widgets, the WooCommerce placeholder and e-mail
     * images, BrikPanel's brand logo, other plugins' settings.
     *
     * @param array $state
     * @return void
     */
    private function phase_options( array &$state ) {
        $db   = $this->db;
        $rows = $db->get_results( $db->prepare(
            "SELECT option_id, option_name, option_value FROM {$db->options} WHERE option_id > %d ORDER BY option_id ASC LIMIT %d",
            (int) $state['cursor'],
            self::CHUNK_OPTIONS
        ), ARRAY_A );

        foreach ( (array) $rows as $row ) {
            $state['cursor'] = (int) $row['option_id'];
            $name            = (string) $row['option_name'];
            if ( self::skip_option( $name ) ) {
                continue;
            }
            $this->read_value( $state, (string) $row['option_value'], ! self::is_number_key( $name ) );
        }
        $state['done']['options'] = (int) $state['cursor'];

        if ( count( (array) $rows ) < self::CHUNK_OPTIONS ) {
            $this->next_phase( $state, 'comments' );
        }
    }

    /**
     * Comments and reviews with their meta (photo review plugins keep the
     * customer's photos there by id). Spam and order notes stay out.
     *
     * @param array $state
     * @return void
     */
    private function phase_comments( array &$state ) {
        $db   = $this->db;
        $max  = (int) ( $state['totals']['comments'] ?? 0 );
        $from = (int) $state['cursor'];

        if ( $from >= $max ) {
            $this->next_phase( $state, 'users' );
            return;
        }

        $to   = $from + self::CHUNK_IDS;
        $rows = $db->get_results( $db->prepare(
            "SELECT comment_ID, comment_content FROM {$db->comments}
             WHERE comment_ID > %d AND comment_ID <= %d AND comment_approved <> 'spam' AND comment_type NOT IN ('order_note','webhook_delivery','action_log')",
            $from,
            $to
        ), ARRAY_A );

        if ( ! empty( $rows ) ) {
            $ids = [];
            foreach ( $rows as $row ) {
                $ids[] = (int) $row['comment_ID'];
                if ( '' !== (string) $row['comment_content'] ) {
                    $this->read_value( $state, (string) $row['comment_content'], true );
                }
            }
            foreach ( array_chunk( $ids, 2000 ) as $part ) {
                $meta = $db->get_results(
                    "SELECT meta_key, meta_value FROM {$db->commentmeta} WHERE comment_id IN (" . implode( ',', $part ) . ')',
                    ARRAY_A
                );
                foreach ( (array) $meta as $m ) {
                    $this->read_value( $state, (string) $m['meta_value'], ! self::is_number_key( (string) $m['meta_key'] ) );
                }
            }
        }

        $state['cursor']           = $to;
        $state['done']['comments'] = $to;
    }

    /**
     * User meta: vendor logos and banners (Dokan, WCFM), avatars, profile
     * fields. Sessions, carts and addresses are skipped.
     *
     * @param array $state
     * @return void
     */
    private function phase_users( array &$state ) {
        $db   = $this->db;
        $max  = (int) ( $state['totals']['users'] ?? 0 );
        $from = (int) $state['cursor'];

        if ( $from >= $max ) {
            $this->next_phase( $state, 'tables' );
            return;
        }

        $to   = $from + self::CHUNK_IDS;
        $rows = $db->get_results( $db->prepare(
            "SELECT meta_key, meta_value FROM {$db->usermeta} WHERE umeta_id > %d AND umeta_id <= %d",
            $from,
            $to
        ), ARRAY_A );

        foreach ( (array) $rows as $row ) {
            $key = (string) $row['meta_key'];
            if ( self::skip_user_meta( $key ) ) {
                continue;
            }
            $this->read_value( $state, (string) $row['meta_value'], ! self::is_number_key( $key ) );
        }

        $state['cursor']        = $to;
        $state['done']['users'] = $to;
    }

    /**
     * The site's other tables (sliders, form and add-on plugins, order item
     * meta), for file names only: a number in a table we do not know is not
     * a reference we can trust either way.
     *
     * @param array $state
     * @return void
     */
    private function phase_tables( array &$state ) {
        if ( ! is_array( $state['tables'] ) ) {
            $state['tables']          = $this->plan_tables();
            $state['totals']['tables'] = max( 1, count( $state['tables'] ) );
            $state['sub']             = 0;
            $state['cursor']          = 0;
        }

        $index = (int) $state['sub'];
        if ( ! isset( $state['tables'][ $index ] ) ) {
            $state['tables'] = null;
            $this->next_phase( $state, 'theme' );
            return;
        }

        $table = $state['tables'][ $index ];
        $db    = $this->db;
        $quote = static function ( $identifier ) {
            return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
        };
        $name  = $quote( $table['name'] );
        $where = [];
        foreach ( (array) $table['cols'] as $col ) {
            $where[] = self::sql_has_file( $quote( $col ) );
        }
        $match = '( ' . implode( ' OR ', $where ) . ' )';
        $cols  = implode( ', ', array_map( $quote, (array) $table['cols'] ) );

        // Built without prepare(): the LIKE patterns carry literal % signs;
        // the names come from SHOW TABLES and SHOW COLUMNS (plan_tables()
        // keeps only plain ones) and the numbers are cast.
        if ( '' !== (string) $table['pk'] ) {
            // Keyset on the primary key, whatever its gaps: the next rows that
            // can name a file, after the last one read.
            $pk   = $quote( $table['pk'] );
            $rows = $db->get_results(
                "SELECT {$pk} AS bp_unused_pk, {$cols} FROM {$name} WHERE {$pk} > " . (int) $state['cursor'] . " AND {$match} ORDER BY {$pk} ASC LIMIT " . self::CHUNK_TABLE_ROWS,
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $state['cursor'] = (int) $row['bp_unused_pk'];
                unset( $row['bp_unused_pk'] );
                $this->read_row( $state, $row );
            }
        } else {
            $offset = (int) $state['cursor'];
            $rows   = $db->get_results(
                "SELECT {$cols} FROM {$name} WHERE {$match} LIMIT " . self::CHUNK_TABLE_ROWS . ' OFFSET ' . $offset,
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $this->read_row( $state, $row );
            }
            $state['cursor'] = $offset + self::CHUNK_TABLE_ROWS;
        }

        if ( count( (array) $rows ) < self::CHUNK_TABLE_ROWS ) {
            $state['sub']             = $index + 1;
            $state['cursor']          = 0;
            $state['done']['tables'] = $index + 1;
        }
    }

    /**
     * The site's other tables worth reading, with their text columns and, when
     * they have one, their numeric primary key.
     *
     * @return array<int, array{name:string, pk:string, min:int, max:int, cols:string[]}>
     */
    private function plan_tables() {
        $db     = $this->db;
        $prefix = (string) $db->prefix;
        $all    = (array) $db->get_col( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $prefix ) . '%' ) );

        $core = [];
        foreach ( array_merge( $db->tables( 'blog' ), $db->tables( 'global' ), $db->tables( 'ms_global' ) ) as $table ) {
            $core[ strtolower( (string) $table ) ] = true;
        }

        $out = [];
        foreach ( $all as $table ) {
            $table = (string) $table;
            if ( count( $out ) >= self::MAX_TABLES ) {
                break;
            }
            if ( isset( $core[ strtolower( $table ) ] ) || ! preg_match( '~^[A-Za-z0-9_$]+$~', $table ) ) {
                continue;
            }
            $short = substr( $table, strlen( $prefix ) );
            // The main site of a network shares its prefix with every other site's tables.
            if ( is_multisite() && $prefix === $db->base_prefix && preg_match( '~^\d+_~', $short ) ) {
                continue;
            }
            if ( self::skip_table( $short ) ) {
                continue;
            }

            $status = $db->get_row( $db->prepare( 'SHOW TABLE STATUS LIKE %s', $db->esc_like( $table ) ), ARRAY_A );
            if ( ! is_array( $status ) || (int) ( $status['Data_length'] ?? 0 ) > self::MAX_TABLE_BYTES ) {
                continue;
            }

            $cols = [];
            $pks  = [];
            foreach ( (array) $db->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ) as $col ) {
                $field = (string) ( $col['Field'] ?? '' );
                $type  = strtolower( (string) ( $col['Type'] ?? '' ) );
                if ( '' === $field || ! preg_match( '~^[A-Za-z0-9_$]+$~', $field ) ) {
                    continue;
                }
                if ( 'PRI' === ( $col['Key'] ?? '' ) ) {
                    $pks[] = [ $field, $type ];
                }
                if ( preg_match( '~^(?:var)?char|text|json|blob~', $type ) ) {
                    $cols[] = $field;
                }
            }
            if ( empty( $cols ) ) {
                continue;
            }

            $out[] = [
                'name' => $table,
                // A single whole-number key is read in key order; anything else page by page.
                'pk'   => ( 1 === count( $pks ) && preg_match( '~int~', $pks[0][1] ) ) ? $pks[0][0] : '',
                'cols' => $cols,
            ];
        }

        return $out;
    }

    /**
     * Read the text columns of one row of another table, for file names.
     *
     * @param array $state
     * @param array $row
     * @return void
     */
    private function read_row( array &$state, array $row ) {
        foreach ( $row as $value ) {
            if ( null !== $value && '' !== $value ) {
                $this->read_value( $state, (string) $value, false );
            }
        }
    }

    /**
     * The active theme and its parent theme: a background in a style sheet,
     * an address or an image id written into a template.
     *
     * @param array $state
     * @return void
     */
    private function phase_theme( array &$state ) {
        $files = $this->theme_files();
        $state['totals']['theme'] = max( 1, count( $files ) );

        $index = (int) $state['sub'];
        $end   = min( count( $files ), $index + self::CHUNK_FILES );
        for ( $i = $index; $i < $end; $i++ ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file, read only.
            $text = @file_get_contents( $files[ $i ] );
            if ( ! is_string( $text ) || '' === $text ) {
                continue;
            }
            $ids   = [];
            $stems = self::theme_upload_names( $text );
            // An image id written into a template: wp_get_attachment_image( 123 ).
            if ( preg_match_all( '~wp_get_attachment_[a-z_]+\s*\(\s*(\d{1,20})|[\'"]attachment_id[\'"]\s*=>\s*(\d{1,20})~i', $text, $m ) ) {
                foreach ( array_merge( $m[1], $m[2] ) as $n ) {
                    if ( '' !== $n ) {
                        $ids[ (int) $n ] = true;
                    }
                }
            }
            $this->consume( $state, $ids, $stems );
        }

        $state['sub']            = $end;
        $state['done']['theme'] = $end;

        if ( $end >= count( $files ) ) {
            $this->end_window( $state );
        }
    }

    /**
     * File names a theme file points at in the uploads folder: an address
     * or a path with "uploads/" or a year/month folder before the name. A
     * theme's own pictures ("images/arrow-down.svg") are not the merchant's
     * uploads, and counting them kept an uploaded file of the same name on
     * the site forever.
     *
     * @param string $text
     * @return array<string,bool> file name (see stem_of()) => true.
     */
    private static function theme_upload_names( $text ) {
        $stems = [];
        $text  = str_replace( '\/', '/', (string) $text );
        foreach ( (array) self::name_hits( $text, 500 ) as $hit ) {
            // The address or path in front of the name: after the last
            // character no path can hold.
            if ( ! preg_match( '~([^\s"\'<>()\[\]{}|,;=?#&*`^]+)$~', $hit[0], $m ) ) {
                continue;
            }
            $token = strtolower( $m[1] );
            $slash = strrpos( $token, '/' );
            $dir   = false === $slash ? '' : substr( $token, 0, $slash + 1 );
            if ( false === strpos( $dir, 'uploads/' ) && ! preg_match( '~(?:^|/)\d{4}/\d{2}/$~', $dir ) ) {
                continue;
            }
            $stem = self::stem_of( false === $slash ? $token : substr( $token, $slash + 1 ) );
            if ( '' !== $stem ) {
                $stems[ $stem ] = true;
            }
        }
        return $stems;
    }

    /**
     * Every place a file name with an image extension ends in a text, with
     * up to $before bytes in front of it. Linear in the text: a pattern that
     * looks for the whole name tries each start again inside one long word
     * (a minified script) and slowed down there.
     *
     * @param string $text
     * @param int    $before
     * @return array<int, array{0:string}>|null The bytes in front of each extension's dot, or null when the pattern failed.
     */
    private static function name_hits( $text, $before ) {
        $out = [];
        $n   = preg_match_all( '~\.(?:' . self::EXT . ')(?![a-z0-9])~i', $text, $m, PREG_OFFSET_CAPTURE );
        if ( false === $n ) {
            return null;
        }
        foreach ( $m[0] as $hit ) {
            $end   = (int) $hit[1];
            $from  = max( 0, $end - (int) $before );
            $out[] = [ substr( $text, $from, $end - $from ) ];
        }
        return $out;
    }

    /**
     * The theme files read, in a stable order, within the caps.
     *
     * @return string[]
     */
    private function theme_files() {
        static $cache = null;
        if ( null !== $cache ) {
            return $cache;
        }

        $dirs = array_unique( array_filter( [
            function_exists( 'get_stylesheet_directory' ) ? get_stylesheet_directory() : '',
            function_exists( 'get_template_directory' ) ? get_template_directory() : '',
        ] ) );
        /**
         * Folders the unused images scan reads for image addresses, besides the
         * active theme and its parent.
         *
         * @since 3.3.39
         *
         * @param string[] $dirs Absolute folder paths.
         */
        $dirs = array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'brikpanel_unused_images_scan_dirs', $dirs ) ) ) );

        $files = [];
        $total = 0;
        foreach ( $dirs as $dir ) {
            $root = realpath( $dir );
            if ( false === $root || ! is_dir( $root ) ) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
                        static function ( $item ) {
                            if ( $item->isLink() ) {
                                return false;
                            }
                            if ( $item->isDir() ) {
                                return ! in_array( $item->getFilename(), self::THEME_SKIP_DIRS, true );
                            }
                            return in_array( strtolower( $item->getExtension() ), self::THEME_EXT, true );
                        }
                    )
                );
                foreach ( $iterator as $item ) {
                    if ( count( $files ) >= self::THEME_MAX_FILES ) {
                        break 2;
                    }
                    $size = (int) $item->getSize();
                    if ( $size <= 0 || $size > self::THEME_MAX_FILE || $total + $size > self::THEME_MAX_TOTAL ) {
                        continue;
                    }
                    $total  += $size;
                    $files[] = $item->getPathname();
                }
            } catch ( \Throwable $e ) {
                unset( $e ); // An unreadable folder: the rest is still read.
            }
        }

        sort( $files, SORT_STRING );
        $cache = array_values( array_unique( $files ) );
        return $cache;
    }

    /**
     * What the unused images take on the server: the file, its sizes, the
     * original before scaling or editing, and their WebP/AVIF twins. An image
     * whose files live elsewhere (an offload plugin) is weighed by what
     * WordPress recorded.
     *
     * @param array $state
     * @return void
     */
    private function phase_measure( array &$state ) {
        $ids   = array_keys( (array) $state['unused'] );
        sort( $ids );
        $slice = array_slice( $ids, (int) $state['measured'], self::CHUNK_MEASURE );

        if ( empty( $slice ) ) {
            $state['phase'] = 'done';
            return;
        }

        foreach ( self::sizes_of( $slice ) as $id => $bytes ) {
            $state['unused'][ $id ] = $bytes;
        }
        $state['measured'] = (int) $state['measured'] + count( $slice );
        // Images whose files are elsewhere were loaded as posts to ask for their address.
        if ( function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime();
        }

        if ( $state['measured'] >= count( $ids ) ) {
            $state['phase'] = 'done';
        }
    }

    /**
     * Bytes each image takes on the server.
     *
     * @param int[] $ids
     * @return array<int,int>
     */
    public static function sizes_of( array $ids ) {
        $out = [];
        if ( empty( $ids ) ) {
            return $out;
        }

        $scanner = new self();
        $meta    = $scanner->meta_of( $ids, [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ] );
        $upload  = wp_get_upload_dir();
        $base    = isset( $upload['basedir'] ) ? wp_normalize_path( (string) $upload['basedir'] ) : '';
        $baseurl = self::url_key( isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '' );

        foreach ( $ids as $id ) {
            $id   = (int) $id;
            $m    = $meta[ $id ] ?? [];
            $file = isset( $m['_wp_attached_file'] ) ? (string) $m['_wp_attached_file'] : '';
            $info = isset( $m['_wp_attachment_metadata'] ) ? self::maybe_array( $m['_wp_attachment_metadata'] ) : [];
            $back = isset( $m['_wp_attachment_backup_sizes'] ) ? self::maybe_array( $m['_wp_attachment_backup_sizes'] ) : [];
            // Served from this site's uploads (not an offload or CDN plugin's
            // storage): a file that is gone there takes no room. Asked only
            // when no file was found, since the answer loads the image's post.
            $local = static function () use ( $id, $baseurl ) {
                $url = '' !== $baseurl ? self::url_key( (string) wp_get_attachment_url( $id ) ) : '';
                return '' === $url || 0 === strpos( $url, $baseurl . '/' );
            };

            $out[ $id ] = self::bytes_on_disk( $base, $file, $info, $back, $local );
        }

        return $out;
    }

    /**
     * @param string $base Uploads folder.
     * @param string $file Stored file, relative to it.
     * @param array  $info Attachment metadata.
     * @param array         $back  Backup sizes.
     * @param callable|bool $local Whether the files belong in this site's uploads folder.
     * @return int
     */
    private static function bytes_on_disk( $base, $file, array $info, array $back, $local = true ) {
        if ( '' === $file ) {
            return 0;
        }
        $main = ( '' !== $base && ! path_is_absolute( $file ) ) ? $base . '/' . ltrim( $file, '/' ) : $file;
        $dir  = dirname( $main );

        $names = [ wp_basename( $file ) ];
        array_walk_recursive( $info, static function ( $value ) use ( &$names ) {
            if ( is_string( $value ) && strlen( $value ) < 300 && preg_match( '~\.(?:' . self::EXT . ')$~i', $value ) ) {
                $names[] = wp_basename( $value );
            }
        } );
        foreach ( $back as $size ) {
            if ( is_array( $size ) && ! empty( $size['file'] ) ) {
                $names[] = wp_basename( (string) $size['file'] );
            }
        }

        $bytes = 0;
        $found = false;
        foreach ( array_unique( $names ) as $name ) {
            foreach ( [ $name, $name . '.webp', $name . '.avif' ] as $candidate ) {
                $path = $dir . '/' . $candidate;
                // is_file() first: filesize() on a missing file writes a warning
                // to the error log even when silenced, on sites that log them all.
                if ( is_file( $path ) ) {
                    $bytes += (int) filesize( $path );
                    $found  = true;
                }
            }
        }

        if ( ! $found && ! ( is_callable( $local ) ? $local() : $local ) ) {
            // Files kept elsewhere: what WordPress recorded (6.0 and later).
            if ( ! empty( $info['filesize'] ) ) {
                $bytes += (int) $info['filesize'];
            }
            foreach ( (array) ( $info['sizes'] ?? [] ) as $size ) {
                if ( is_array( $size ) && ! empty( $size['filesize'] ) ) {
                    $bytes += (int) $size['filesize'];
                }
            }
        }

        return $bytes;
    }

    // =========================================================================
    // RECHECK — right before images move to the trash
    // =========================================================================

    /**
     * Which of these images something has started to use since the scan.
     *
     * The places that change between a scan and a click: featured images and
     * galleries, category and other term images, the site icon and logo,
     * widgets, theme settings and any option holding the id, user and comment
     * meta holding the id, and every post changed or comment added since
     * (contents and meta). Over $max_posts changed posts the answer is
     * "stale": the caller asks for a new scan instead of guessing.
     *
     * @param int[] $ids       Images about to move.
     * @param int   $since     Start of the scan they were found in.
     * @param int   $max_posts Changed posts read at most.
     * @return array{used:int[], stale:bool}
     */
    public static function recheck( array $ids, $since, $max_posts = 2000 ) {
        $scanner = new self();
        $db      = $scanner->db;
        $ids     = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( empty( $ids ) ) {
            return [ 'used' => [], 'stale' => false ];
        }

        $state = self::new_state( (int) $since );
        $meta  = $scanner->meta_of( $ids, [ '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ] );
        foreach ( $ids as $id ) {
            $state['cands'][ $id ] = true;
            $m = $meta[ $id ] ?? [];
            foreach ( self::attachment_stems(
                (string) ( $m['_wp_attached_file'] ?? '' ),
                isset( $m['_wp_attachment_metadata'] ) ? self::maybe_array( $m['_wp_attachment_metadata'] ) : [],
                isset( $m['_wp_attachment_backup_sizes'] ) ? self::maybe_array( $m['_wp_attachment_backup_sizes'] ) : []
            ) as $stem ) {
                self::add_stem( $state, $stem, $id );
            }
        }

        $strings = "'" . implode( "','", $ids ) . "'";
        $used    = static function () use ( &$state, $ids ) {
            return array_values( array_diff( $ids, array_map( 'intval', array_keys( $state['cands'] ) ) ) );
        };

        // A field that holds exactly the id, wherever it is.
        $hits = array_merge(
            (array) $db->get_col( "SELECT meta_value FROM {$db->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$strings})" ),
            (array) $db->get_col( "SELECT meta_value FROM {$db->termmeta} WHERE meta_value IN ({$strings})" ),
            (array) $db->get_col( "SELECT option_value FROM {$db->options} WHERE option_value IN ({$strings})" ),
            (array) $db->get_col( "SELECT meta_value FROM {$db->usermeta} WHERE meta_value IN ({$strings})" ),
            (array) $db->get_col( "SELECT meta_value FROM {$db->commentmeta} WHERE meta_value IN ({$strings})" )
        );
        $found = [];
        foreach ( $hits as $hit ) {
            $found[ (int) $hit ] = true;
        }
        $scanner->consume( $state, $found, [] );

        // Galleries, including the variation gallery plugins'.
        $galleries = (array) $db->get_col( "SELECT meta_value FROM {$db->postmeta} WHERE meta_key IN ('_product_image_gallery','_brikpanel_variation_gallery','woo_variation_gallery_images','_wc_additional_variation_images','rtwpvg_images') AND meta_value <> ''" );
        foreach ( $galleries as $value ) {
            $scanner->read_value( $state, (string) $value, true );
        }

        // Widgets, theme settings, the site logo.
        $settings = (array) $db->get_col( "SELECT option_value FROM {$db->options} WHERE option_name LIKE 'widget\\_%' OR option_name LIKE 'theme\\_mods\\_%' OR option_name IN ('sidebars_widgets','site_logo','site_icon','woocommerce_email_header_image')" );
        foreach ( $settings as $value ) {
            $scanner->read_value( $state, (string) $value, true );
        }

        if ( empty( $state['cands'] ) ) {
            return [ 'used' => $used(), 'stale' => false ];
        }

        // Posts changed and comments added since the scan, read in full.
        // Media Library entries are left out: every image moved to the trash
        // or added since changes them, and none of them uses another image.
        $after = gmdate( 'Y-m-d H:i:s', max( 0, (int) $since - MINUTE_IN_SECONDS ) );
        $skip  = self::sql_in( [ 'revision', 'scheduled-action', 'attachment' ] );
        $posts = (array) $db->get_col( $db->prepare(
            "SELECT ID FROM {$db->posts} WHERE post_modified_gmt >= %s AND post_type NOT IN ({$skip}) ORDER BY ID ASC LIMIT %d",
            $after,
            $max_posts + 1
        ) );
        if ( count( $posts ) > $max_posts ) {
            return [ 'used' => $used(), 'stale' => true ];
        }
        foreach ( array_chunk( array_map( 'intval', $posts ), self::CHUNK_POSTS ) as $part ) {
            $list = implode( ',', $part );
            foreach ( (array) $db->get_results( "SELECT ID, post_type, post_content, post_excerpt FROM {$db->posts} WHERE ID IN ({$list})", ARRAY_A ) as $row ) {
                $self = 'attachment' === $row['post_type'] ? (int) $row['ID'] : 0;
                $scanner->read_value( $state, (string) $row['post_content'], true, $self );
                $scanner->read_value( $state, (string) $row['post_excerpt'], true, $self );
            }
            foreach ( (array) $db->get_results( "SELECT post_id, meta_key, meta_value FROM {$db->postmeta} WHERE post_id IN ({$list}) AND meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')", ARRAY_A ) as $m ) {
                $self = in_array( (int) $m['post_id'], $ids, true ) ? (int) $m['post_id'] : 0;
                $scanner->read_value( $state, (string) $m['meta_value'], ! self::is_number_key( (string) $m['meta_key'] ), $self );
            }
        }

        $comments = (array) $db->get_results( $db->prepare(
            "SELECT comment_ID, comment_content FROM {$db->comments} WHERE comment_date_gmt >= %s AND comment_approved <> 'spam' LIMIT %d",
            $after,
            $max_posts
        ), ARRAY_A );
        foreach ( $comments as $row ) {
            $scanner->read_value( $state, (string) $row['comment_content'], true );
            foreach ( (array) $db->get_col( $db->prepare( "SELECT meta_value FROM {$db->commentmeta} WHERE comment_id = %d", (int) $row['comment_ID'] ) ) as $value ) {
                $scanner->read_value( $state, (string) $value, true );
            }
        }

        return [ 'used' => $used(), 'stale' => false ];
    }

    // =========================================================================
    // READING VALUES
    // =========================================================================

    /**
     * Read one stored value and take every image it refers to off the
     * candidates.
     *
     * @param array  $state
     * @param string $value
     * @param bool   $with_ids   Whether numbers in it may be image ids.
     * @param int    $self_id    An image whose own entry this is: it does not count.
     * @param array  $self_stems That image's own file names.
     * @return void
     */
    private function read_value( array &$state, $value, $with_ids, $self_id = 0, array $self_stems = [] ) {
        if ( empty( $state['cands'] ) || '' === $value ) {
            return;
        }

        $length = strlen( $value );
        if ( $length <= self::SLICE ) {
            $this->read_piece( $state, $value, $with_ids, $self_id, $self_stems );
            return;
        }

        for ( $at = 0; $at < $length; $at += self::SLICE - self::SLICE_OVERLAP ) {
            $this->read_piece( $state, substr( $value, $at, self::SLICE ), $with_ids, $self_id, $self_stems );
            if ( empty( $state['cands'] ) ) {
                return;
            }
        }
    }

    /**
     * @param array  $state
     * @param string $piece
     * @param bool   $with_ids
     * @param int    $self_id
     * @param array  $self_stems
     * @return void
     */
    private function read_piece( array &$state, $piece, $with_ids, $self_id, array $self_stems ) {
        $ids   = [];
        $stems = [];
        if ( false === self::extract( $piece, $with_ids, $ids, $stems ) ) {
            // The pattern gave up on this value: look for every remaining
            // file name in it directly, so nothing it names is listed.
            $low = strtolower( $piece );
            foreach ( array_keys( $state['stems'] ) as $stem ) {
                if ( ! isset( $self_stems[ $stem ] ) && false !== strpos( $low, (string) $stem ) ) {
                    $stems[ $stem ] = true;
                }
            }
        }
        if ( $self_id > 0 ) {
            unset( $ids[ $self_id ] );
        }
        $this->consume( $state, $ids, $stems, $self_stems );
    }

    /**
     * Take the images these references point at off the candidates.
     *
     * @param array $state
     * @param array $ids        id => true.
     * @param array $stems      file name => true.
     * @param array $self_stems File names that do not count here.
     * @return void
     */
    private function consume( array &$state, array $ids, array $stems, array $self_stems = [] ) {
        foreach ( $ids as $id => $unused ) {
            unset( $state['cands'][ (int) $id ] );
        }
        foreach ( $stems as $stem => $unused ) {
            if ( isset( $self_stems[ $stem ] ) || ! isset( $state['stems'][ $stem ] ) ) {
                continue;
            }
            foreach ( (array) $state['stems'][ $stem ] as $id ) {
                unset( $state['cands'][ (int) $id ] );
            }
            unset( $state['stems'][ $stem ] );
        }
    }

    /**
     * Remember that an image can be referred to by this name. Most names
     * belong to one image: the id itself is stored, and a list only when two
     * images share a name (a list per name cost ~300 bytes each, ~20 MB in a
     * 50,000 image window).
     *
     * @param array      $state
     * @param string|int $stem
     * @param int        $id
     * @return void
     */
    private static function add_stem( array &$state, $stem, $id ) {
        if ( ! isset( $state['stems'][ $stem ] ) ) {
            $state['stems'][ $stem ] = (int) $id;
        } elseif ( is_array( $state['stems'][ $stem ] ) ) {
            $state['stems'][ $stem ][] = (int) $id;
        } elseif ( (int) $state['stems'][ $stem ] !== (int) $id ) {
            $state['stems'][ $stem ] = [ (int) $state['stems'][ $stem ], (int) $id ];
        }
    }

    /**
     * Every image reference in a piece of stored text.
     *
     * @param string $text
     * @param bool   $with_ids Whether to read numbers as image ids too.
     * @param array  $ids      Filled: id => true.
     * @param array  $stems    Filled: file name (see stem_of()) => true.
     * @param int    $depth    Base64 nesting already opened.
     * @return bool False when a pattern failed on this text (see read_piece()).
     */
    public static function extract( $text, $with_ids, array &$ids, array &$stems, $depth = 0 ) {
        $text = (string) $text;
        if ( '' === $text ) {
            return true;
        }
        $ok = true;

        $names = $text;
        if ( false !== strpos( $names, '\\u' ) ) {
            // JSON writes "ö" as \u00f6 unless told not to (and some
            // builders escape more than that).
            $names = (string) preg_replace_callback( '~\\\\u([0-9a-fA-F]{4})~', static function ( $m ) {
                $char = json_decode( '"\\u' . $m[1] . '"' );
                return is_string( $char ) ? $char : $m[0];
            }, $names );
        }
        $hits = preg_match( '~\.(?:' . self::EXT . ')(?![a-z0-9])~i', $names ) ? self::name_hits( $names, 200 ) : [];
        if ( null === $hits ) {
            $ok   = false;
            $hits = [];
        }
        if ( ! empty( $hits ) ) {
            foreach ( $hits as $hit ) {
                // The name: back to the last character a file name cannot hold.
                $found = preg_match( '~([^\s"\'<>()\[\]{}\\\\/|,;:=?#&*`^]+)$~', $hit[0], $nm );
                if ( false === $found ) {
                    $ok = false;
                    continue;
                }
                if ( $found ) {
                    $stem = self::stem_of( $nm[1] );
                    if ( '' !== $stem ) {
                        $stems[ $stem ] = true;
                    }
                }
            }
        }

        if ( $with_ids && preg_match( '~\d~', $text ) ) {
            $ok = self::extract_ids( $text, $ids ) && $ok;
        }

        // Page builders that keep blocks as base64 (WPBakery's raw HTML).
        if ( $depth < 1 && preg_match_all( '~[A-Za-z0-9+/]{64,}={0,2}~', $text, $b64 ) ) {
            $budget = 2097152;
            foreach ( array_slice( $b64[0], 0, 50 ) as $segment ) {
                if ( $budget <= 0 ) {
                    break;
                }
                $decoded = base64_decode( $segment, true );
                if ( ! is_string( $decoded ) || '' === $decoded ) {
                    continue;
                }
                $budget -= strlen( $decoded );
                if ( false !== strpos( $decoded, '%' ) ) {
                    $decoded = rawurldecode( $decoded );
                }
                $ok = self::extract( $decoded, $with_ids, $ids, $stems, $depth + 1 ) && $ok;
            }
        }

        return $ok;
    }

    /**
     * Numbers that can be image ids (class comment, rule 3).
     *
     * @param string $text
     * @param array  $ids Filled: id => true.
     * @return bool False when a pattern failed.
     */
    private static function extract_ids( $text, array &$ids ) {
        $trim = trim( $text );

        // The whole value is a number or a list of numbers.
        if ( preg_match( '~^\d{1,20}(?:\s*[,|]\s*\d{1,20})*$~', $trim ) ) {
            self::add_ids( $ids, $trim );
            return true;
        }

        $ok       = true;
        $patterns = [];
        if ( false !== strpos( $text, 'wp-image-' ) ) {
            $patterns[] = '~wp-image-(\d{1,20})~';
        }
        if ( false !== strpos( $text, '=' ) ) {
            // key=123, key="123", ids="1,2,3", image_id="123|full", ?attachment_id=123.
            $patterns[] = '~=\s*["\']?\s*(\d{1,20}(?:\s*[,|]\s*\d{1,20})*)~';
        }
        if ( false !== strpbrk( $text, '{[' ) ) {
            // JSON and block attributes: "id":123, "ids":[1,2], "id":"123".
            $patterns[] = '~[:\[,]\s*"?(\d{1,20})"?\s*(?=[,\]}])~';
        }
        if ( preg_match( '~^\s*(?:a|O|C|s|i|d|b):\d~', $text ) || false !== strpos( $text, ':{' ) ) {
            // Serialised PHP: i:123; and s:3:"123"; (also "1,2,3" lists).
            $patterns[] = '~(?<![a-z])i:(\d{1,20});~';
            $patterns[] = '~s:\d{1,6}:"\s*(\d{1,20}(?:\s*[,|]\s*\d{1,20})*)\s*";~';
        }

        foreach ( $patterns as $pattern ) {
            $found = preg_match_all( $pattern, $text, $m );
            if ( false === $found ) {
                $ok = false;
                continue;
            }
            foreach ( $m[1] as $list ) {
                self::add_ids( $ids, $list );
            }
        }

        return $ok;
    }

    /**
     * @param array  $ids
     * @param string $list "12", "12,34" or "12|34".
     * @return void
     */
    private static function add_ids( array &$ids, $list ) {
        foreach ( preg_split( '~\s*[,|]\s*~', trim( (string) $list ) ) as $token ) {
            if ( '' !== $token && ctype_digit( $token ) && strlen( $token ) <= 19 ) {
                $n = (int) $token;
                if ( $n > 0 ) {
                    $ids[ $n ] = true;
                }
            }
        }
    }

    /**
     * The name a file is referred to by, whatever size or copy of it is
     * named: lower case, without folder, extension, size (-300x200),
     * -scaled, -rotated, an edited copy's -e123… or a twin's -jpg suffix.
     * Two different images with the same name are then one name: a
     * reference to either keeps both, the safe direction.
     *
     * @param string $name File name, with or without its extension.
     * @return string
     */
    public static function stem_of( $name ) {
        $name = strtolower( rawurldecode( wp_basename( (string) $name ) ) );
        $name = (string) preg_replace( '~(?:\.(?:' . self::EXT . '))+$~i', '', $name );
        for ( $i = 0; $i < 4; $i++ ) {
            $before = $name;
            $name   = (string) preg_replace( '~(?:-(?:\d{1,5}x\d{1,5}|scaled|rotated|e\d{12,14}|jpe?g|png|gif|webp|avif)|@\dx)$~', '', $name );
            if ( $name === $before ) {
                break;
            }
        }
        return trim( $name );
    }

    /**
     * Every name an image's files can be referred to by: the file, each
     * size, the original before scaling or editing, the source and animated
     * twins WordPress 7 records, and older edits kept as backups.
     *
     * @param string $file   Stored file, relative to the uploads folder.
     * @param array  $info   Attachment metadata.
     * @param array  $backup Backup sizes.
     * @return string[]
     */
    public static function attachment_stems( $file, array $info, array $backup ) {
        $names = [];
        if ( '' !== (string) $file ) {
            $names[] = wp_basename( (string) $file );
        }
        array_walk_recursive( $info, static function ( $value ) use ( &$names ) {
            if ( is_string( $value ) && strlen( $value ) < 300 && preg_match( '~\.(?:' . self::EXT . ')$~i', $value ) ) {
                $names[] = wp_basename( $value );
            }
        } );
        foreach ( $backup as $size ) {
            if ( is_array( $size ) && ! empty( $size['file'] ) ) {
                $names[] = wp_basename( (string) $size['file'] );
            }
        }

        $stems = [];
        foreach ( $names as $name ) {
            $stem = self::stem_of( $name );
            if ( '' !== $stem ) {
                $stems[ $stem ] = true;
            }
        }
        return array_keys( $stems );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Whether a key holds numbers that are not image ids (NUMBER_KEYS).
     *
     * @param string $key
     * @return bool
     */
    public static function is_number_key( $key ) {
        $key = strtolower( (string) $key );
        if ( in_array( $key, self::NUMBER_KEYS, true ) ) {
            return true;
        }
        foreach ( self::NUMBER_SUFFIXES as $suffix ) {
            if ( strlen( $key ) > strlen( $suffix ) && substr( $key, -strlen( $suffix ) ) === $suffix ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $name Option name.
     * @return bool
     */
    private static function skip_option( $name ) {
        if ( in_array( $name, self::SKIP_OPTIONS, true ) ) {
            return true;
        }
        foreach ( self::SKIP_OPTION_PREFIXES as $prefix ) {
            if ( 0 === strpos( $name, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $key User meta key, with or without a site prefix.
     * @return bool
     */
    private static function skip_user_meta( $key ) {
        global $wpdb;
        $short = $key;
        if ( 0 === strpos( $key, (string) $wpdb->base_prefix ) ) {
            $short = (string) preg_replace( '~^' . preg_quote( (string) $wpdb->base_prefix, '~' ) . '(?:\d+_)?~', '', $key );
        }
        if ( in_array( $short, self::SKIP_USER_META, true ) ) {
            return true;
        }
        return 0 === strpos( $key, '_woocommerce_persistent_cart' )
            || 0 === strpos( $short, 'closedpostboxes_' )
            || 0 === strpos( $short, 'metaboxhidden_' )
            || 0 === strpos( $short, 'meta-box-order_' )
            || 0 === strpos( $short, 'screen_layout_' )
            || 0 === strpos( $short, 'manageedit-' )
            || 'columnshidden' === substr( $short, -13 );
    }

    /**
     * @param string $short Table name without the site prefix.
     * @return bool
     */
    private static function skip_table( $short ) {
        $low = strtolower( $short );
        if ( in_array( $low, self::SKIP_TABLES, true ) ) {
            return true;
        }
        foreach ( self::SKIP_TABLE_PREFIXES as $prefix ) {
            if ( 0 === strpos( $low, $prefix ) ) {
                return true;
            }
        }
        // Order meta stays: a customer's file sent by address lives there.
        if ( in_array( $low, [ 'wc_orders_meta', 'woocommerce_order_itemmeta' ], true ) ) {
            return false;
        }
        return (bool) preg_match( self::SKIP_TABLE_PATTERN, $low );
    }

    /**
     * Whether an image attached to a record of this type may be listed.
     *
     * @param string $type Post type.
     * @return bool
     */
    private static function is_content_type( $type ) {
        if ( in_array( $type, self::CONTENT_PARENT_TYPES, true ) ) {
            return true;
        }
        return post_type_exists( $type ) && is_post_type_viewable( $type );
    }

    /**
     * Post type of each id; an id that is not a post has no entry.
     *
     * @param int[] $ids
     * @return array<int,string>
     */
    private function post_types_of( array $ids ) {
        $out = [];
        foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 2000 ) as $part ) {
            foreach ( (array) $this->db->get_results( "SELECT ID, post_type FROM {$this->db->posts} WHERE ID IN (" . implode( ',', $part ) . ')', ARRAY_A ) as $row ) {
                $out[ (int) $row['ID'] ] = (string) $row['post_type'];
            }
        }
        return $out;
    }

    /**
     * Some meta of many objects in one query, read straight from the table
     * so the object cache is not filled with a whole Media Library.
     *
     * @param int[]    $ids
     * @param string[] $keys
     * @return array<int, array<string,string>>
     */
    private function meta_of( array $ids, array $keys ) {
        $out  = [];
        $ids  = array_values( array_filter( array_map( 'intval', $ids ) ) );
        $keys = self::sql_in( $keys );
        foreach ( array_chunk( $ids, 2000 ) as $part ) {
            $rows = $this->db->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$this->db->postmeta} WHERE post_id IN (" . implode( ',', $part ) . ") AND meta_key IN ({$keys})",
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $out[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /**
     * A stored array, or an empty one. Never builds objects.
     *
     * @param string $raw
     * @return array
     */
    public static function maybe_array( $raw ) {
        if ( ! is_string( $raw ) || '' === $raw || ! is_serialized( $raw ) ) {
            return [];
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused.
        $value = @unserialize( trim( $raw ), [ 'allowed_classes' => false ] );
        return is_array( $value ) ? $value : [];
    }

    /**
     * An address as "//host/path", lower case, without its scheme or a
     * trailing slash, so http and https compare equal.
     *
     * @param string $url
     * @return string
     */
    private static function url_key( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        return strtolower( untrailingslashit( (string) preg_replace( '#^[a-z][a-z0-9+.\-]*:#i', '', $url ) ) );
    }

    /**
     * A quoted list for IN (...) of fixed, known strings.
     *
     * @param string[] $values
     * @return string
     */
    private static function sql_in( array $values ) {
        $out = [];
        foreach ( $values as $value ) {
            $out[] = "'" . esc_sql( (string) $value ) . "'";
        }
        return implode( ',', $out );
    }

    /**
     * A condition true when a column can hold an image file name.
     *
     * @param string $column Quoted column name.
     * @return string
     */
    private static function sql_has_file( $column ) {
        $parts = [];
        foreach ( [ '.jp', '.png', '.gif', '.webp', '.avif', '.svg', '.bmp', '.tif', '.ico', '.hei', '.jfif', '.mp4', '.webm', '.m4v' ] as $ext ) {
            $parts[] = "{$column} LIKE '%" . $ext . "%'";
        }
        return '( ' . implode( ' OR ', $parts ) . ' )';
    }
}
