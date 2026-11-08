<?php
/**
 * BrikPanel: BrikControl unused images (Store Health card)
 *
 * The card under the Store Health checks that finds the images nothing on the
 * site uses (Brikpanel_BrikControl_Unused_Images_Scanner), shows them, and
 * moves the ones the merchant picks to the trash.
 *
 * It is a tool, not a check: it has no status, never colours the shield, and
 * nothing runs until the merchant clicks (owner decision, 22 Oct 2026: it
 * reads the whole site, so every store scanning it every night would be
 * wasted work). The scan then runs in the background, a slice per Action
 * Scheduler job, and the card follows it.
 *
 * Deleting is two steps on purpose. "Move to trash" puts the image in the
 * WordPress trash: it leaves the Media Library, but its files stay on the
 * server, so a page that still shows it somewhere the scan could not see keeps
 * working, and one click brings it back. WordPress deletes it for good after
 * EMPTY_TRASH_DAYS (wp_scheduled_delete()); "Delete permanently" frees the
 * space at once. On a site with the trash switched off the card says so and
 * deletes for good, after asking.
 *
 * Before an image moves, it is checked again against what changed since the
 * scan (Scanner::recheck()), and a list older than STALE_AFTER is not acted
 * on at all.
 *
 * Storage (options, never autoloaded):
 *   brikpanel_unused_images_scan    the scan in progress (Scanner state + touched)
 *   brikpanel_unused_images_result  { v, started, finished, items: { id: bytes }, images, recent }
 * and `_brikpanel_unused_trashed` (bytes) on the images this card trashed.
 *
 * @package BrikPanel
 * @since   3.3.39
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brikpanel_BrikControl_Unused_Images {

    const HOOK        = 'brikpanel_unused_images_scan_step';
    const OPT_STATE   = 'brikpanel_unused_images_scan';
    const OPT_RESULT  = 'brikpanel_unused_images_result';
    const NONCE       = 'brikpanel_unused_images';
    const TRASH_META  = '_brikpanel_unused_trashed';
    const HANDLE      = 'brikpanel-brikcontrol-unused-images';

    /** Images per page of the grid. */
    const PER_PAGE = 48;

    /** Images one request may move, restore or delete. */
    const MAX_IDS = 200;

    /** Seconds a background slice works; the first one, in the click's own request, is shorter. */
    const STEP_BUDGET  = 8;
    const START_BUDGET = 2;

    /** Seconds an action (trash, restore, delete) works before it hands the rest back. */
    const ACTION_BUDGET = 8;

    /** A list older than this is shown but not acted on. */
    const STALE_AFTER = 7 * DAY_IN_SECONDS;

    /** A scan untouched this long, with no job queued, is started again where it stopped. */
    const STALL = 3 * MINUTE_IN_SECONDS;

    /** A slice that keeps failing ends the scan after this many tries. */
    const MAX_FAILS = 3;

    // =========================================================================
    // BOOT
    // =========================================================================

    /**
     * Hooks. Loaded on every request with the rest of Store Health, so the
     * background worker finds its handler; everything else is admin only.
     *
     * @return void
     */
    public static function boot() {
        add_action( 'brikpanel_cron_register', [ __CLASS__, 'register_jobs' ] );
        // An image brought back from the trash some other way stops counting as ours.
        add_action( 'untrashed_post', [ __CLASS__, 'forget_trashed' ] );

        if ( ! is_admin() ) {
            return;
        }
        foreach ( [ 'start', 'progress', 'page', 'trash', 'restore', 'delete' ] as $action ) {
            add_action( 'wp_ajax_brikpanel_unused_images_' . $action, [ __CLASS__, 'ajax_' . $action ] );
        }
    }

    /**
     * @return void
     */
    public static function register_jobs() {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return;
        }
        Brikpanel_Cron::register_handler(
            self::HOOK,
            [ __CLASS__, 'handle_step' ],
            static function () {
                return [
                    'label'       => __( 'Store Health: unused images', 'brikpanel' ),
                    'description' => __( 'Looks for the images nothing on the site uses, one part of the site at a time. Runs only after a click on the Store Health page.', 'brikpanel' ),
                ];
            }
        );
    }

    /**
     * @param int $post_id
     * @return void
     */
    public static function forget_trashed( $post_id ) {
        if ( 'attachment' === get_post_type( $post_id ) ) {
            delete_post_meta( (int) $post_id, self::TRASH_META );
        }
    }

    // =========================================================================
    // SCAN
    // =========================================================================

    /**
     * Start a scan, unless one is running. The first slice runs here, so the
     * bar moves at once even when the background worker takes its time.
     *
     * @return bool Whether a new scan started.
     */
    public static function start() {
        if ( self::is_running() ) {
            return false;
        }
        $state            = Brikpanel_BrikControl_Unused_Images_Scanner::new_state();
        $state['touched'] = time();
        $state['fails']   = 0;
        self::write( self::OPT_STATE, $state );

        self::run_step( self::START_BUDGET );
        return true;
    }

    /**
     * Action Scheduler handler: one slice of the scan it was queued for.
     *
     * @param array $payload { scan: string }
     * @return void
     */
    public static function handle_step( $payload = [] ) {
        $payload = is_array( $payload ) ? $payload : [];
        $state   = self::state();
        // A job left over from a scan that was replaced or finished.
        if ( null === $state || (string) ( $payload['scan'] ?? '' ) !== (string) $state['id'] ) {
            return;
        }
        self::run_step( self::STEP_BUDGET );
    }

    /**
     * Run one slice, under a database lock so two workers never write the
     * same scan, and queue the next one.
     *
     * @param float $budget Seconds.
     * @return void
     */
    private static function run_step( $budget ) {
        global $wpdb;
        $lock = self::lock_name( 's' );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
            return; // Another slice is running; it queues the next one.
        }

        try {
            $state = self::state();
            if ( null === $state ) {
                return;
            }

            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }

            try {
                $scanner = new Brikpanel_BrikControl_Unused_Images_Scanner();
                $done    = $scanner->step( $state, $budget );
            } catch ( \Throwable $e ) {
                error_log( sprintf( '[BrikPanel] Unused images scan slice failed: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
                $state = self::state();
                if ( null === $state ) {
                    return;
                }
                $state['fails'] = (int) ( $state['fails'] ?? 0 ) + 1;
                if ( $state['fails'] >= self::MAX_FAILS ) {
                    self::erase( self::OPT_STATE );
                    return;
                }
                $state['touched'] = time();
                self::write( self::OPT_STATE, $state );
                self::queue_step( (string) $state['id'], MINUTE_IN_SECONDS );
                return;
            }

            if ( $done ) {
                self::finish( $state );
                return;
            }

            $state['touched'] = time();
            self::write( self::OPT_STATE, $state );
            self::queue_step( (string) $state['id'] );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
        }
    }

    /**
     * Queue the next slice. Never unique: the running job is still "in
     * progress" and Action Scheduler would refuse its successor (see
     * Brikpanel_BrikControl_Runner::enqueue_next_batch()).
     *
     * @param string $scan  Scan id.
     * @param int    $delay Seconds.
     * @return void
     */
    private static function queue_step( $scan, $delay = 0 ) {
        if ( ! class_exists( 'Brikpanel_Cron' ) ) {
            return;
        }
        $args = [ 'scan' => (string) $scan ];
        if ( $delay <= 0 && Brikpanel_Cron::enqueue_async( self::HOOK, $args ) ) {
            return;
        }
        Brikpanel_Cron::schedule_single( time() + max( MINUTE_IN_SECONDS, (int) $delay ), self::HOOK, $args );
    }

    /**
     * Revive a scan whose job chain died (a killed worker, a fatal error in
     * another plugin): untouched for STALL and nothing queued.
     *
     * @return void
     */
    private static function maybe_resume() {
        $state = self::state();
        if ( null === $state || time() - (int) ( $state['touched'] ?? 0 ) < self::STALL ) {
            return;
        }
        if ( class_exists( 'Brikpanel_Cron' ) && Brikpanel_Cron::has_any_scheduled( self::HOOK ) ) {
            return;
        }
        $state['touched'] = time();
        self::write( self::OPT_STATE, $state );
        self::queue_step( (string) $state['id'] );
    }

    /**
     * The finished scan becomes the list.
     *
     * @param array $state
     * @return void
     */
    private static function finish( array $state ) {
        self::with_result_lock( static function () use ( $state ) {
            self::write(
                self::OPT_RESULT,
                [
                    'v'        => 1,
                    'started'  => (int) $state['started'],
                    'finished' => time(),
                    'items'    => Brikpanel_BrikControl_Unused_Images_Scanner::unused_of( $state ),
                    'images'   => (int) ( $state['counts']['images'] ?? 0 ),
                    'recent'   => (int) ( $state['counts']['recent'] ?? 0 ),
                ]
            );
        } );
        self::erase( self::OPT_STATE );
    }

    /**
     * The scan in progress, read past the object cache (the worker writes it
     * in another request).
     *
     * @return array|null
     */
    private static function state() {
        $state = self::read( self::OPT_STATE );
        return Brikpanel_BrikControl_Unused_Images_Scanner::is_state( $state ) ? $state : null;
    }

    /**
     * @return bool
     */
    public static function is_running() {
        return null !== self::state();
    }

    // =========================================================================
    // RESULT
    // =========================================================================

    /**
     * The last list, without the images that left the Media Library since.
     *
     * @return array|null
     */
    public static function result() {
        $result = self::read( self::OPT_RESULT );
        if ( ! is_array( $result ) || 1 !== (int) ( $result['v'] ?? 0 ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) ) {
            return null;
        }

        $items = [];
        foreach ( $result['items'] as $id => $bytes ) {
            $items[ (int) $id ] = max( 0, (int) $bytes );
        }
        $alive = self::alive( array_keys( $items ) );
        if ( count( $alive ) !== count( $items ) ) {
            $items = array_intersect_key( $items, array_flip( $alive ) );
            self::update_items( static function ( array $stored ) use ( $alive ) {
                return array_intersect_key( $stored, array_flip( $alive ) );
            } );
        }
        $result['items'] = $items;
        return $result;
    }

    /**
     * Whether a list is too old to act on.
     *
     * @param array $result
     * @return bool
     */
    private static function is_stale( array $result ) {
        return time() - (int) ( $result['finished'] ?? 0 ) > self::STALE_AFTER;
    }

    /**
     * The ids that are still images in the Media Library (not trashed).
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function alive( array $ids ) {
        global $wpdb;
        $out = [];
        foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 5000 ) as $part ) {
            $found = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $part ) . ") AND post_type = 'attachment' AND post_status IN ('inherit','private')"
            );
            foreach ( (array) $found as $id ) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * Change the stored list's items under the lock.
     *
     * @param callable $change Gets the stored items, returns the new ones.
     * @return void
     */
    private static function update_items( callable $change ) {
        self::with_result_lock( static function () use ( $change ) {
            $stored = self::read( self::OPT_RESULT );
            if ( ! is_array( $stored ) || ! isset( $stored['items'] ) || ! is_array( $stored['items'] ) ) {
                return;
            }
            $items = [];
            foreach ( $stored['items'] as $id => $bytes ) {
                $items[ (int) $id ] = (int) $bytes;
            }
            $items = $change( $items );
            ksort( $items );
            $stored['items'] = $items;
            self::write( self::OPT_RESULT, $stored );
        } );
    }

    /**
     * @param callable $work
     * @return void
     */
    private static function with_result_lock( callable $work ) {
        global $wpdb;
        $lock   = self::lock_name( 'r' );
        $locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $lock ) );
        try {
            $work();
        } finally {
            if ( $locked ) {
                $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
            }
        }
    }

    /**
     * Ids in the list, in the order the merchant chose.
     *
     * @param array  $items id => bytes.
     * @param string $sort  'size', 'new' or 'old'.
     * @return int[]
     */
    private static function sorted_ids( array $items, $sort ) {
        $ids = array_keys( $items );
        if ( 'old' === $sort ) {
            sort( $ids );
        } elseif ( 'new' === $sort ) {
            rsort( $ids );
        } else {
            usort( $ids, static function ( $a, $b ) use ( $items ) {
                return [ $items[ $b ], $b ] <=> [ $items[ $a ], $a ];
            } );
        }
        return $ids;
    }

    // =========================================================================
    // TRASH
    // =========================================================================

    /**
     * Whether images go to the trash here, or are deleted at once (a site with
     * EMPTY_TRASH_DAYS set to 0). The filter can only switch the trash off:
     * wp_trash_post() deletes for good whenever the constant is 0.
     *
     * @return int Days in the trash, 0 for none.
     */
    public static function trash_days() {
        $days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
        if ( $days <= 0 ) {
            return 0;
        }
        /**
         * Days the unused images card says the trash keeps images, and whether
         * it uses the trash at all (0 deletes for good). Can only lower it.
         *
         * @since 3.3.39
         *
         * @param int $days EMPTY_TRASH_DAYS.
         */
        return max( 0, min( $days, (int) apply_filters( 'brikpanel_unused_images_trash_days', $days ) ) );
    }

    /**
     * The images this card put in the trash, newest first.
     *
     * @param int $offset
     * @param int $limit  0 for all.
     * @return array{ids:int[], bytes:array<int,int>, total:int, total_bytes:int}
     */
    public static function trash_items( $offset = 0, $limit = 0 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_value AS bytes FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_wp_trash_meta_time'
             WHERE p.post_type = 'attachment' AND p.post_status = 'trash'
             ORDER BY CAST(t.meta_value AS UNSIGNED) DESC, p.ID DESC",
            self::TRASH_META
        ), ARRAY_A );

        $ids   = [];
        $bytes = [];
        $sum   = 0;
        foreach ( (array) $rows as $row ) {
            $id = (int) $row['ID'];
            if ( isset( $bytes[ $id ] ) ) {
                continue;
            }
            $ids[]        = $id;
            $bytes[ $id ] = max( 0, (int) $row['bytes'] );
            $sum         += $bytes[ $id ];
        }

        return [
            'ids'         => $limit > 0 ? array_slice( $ids, max( 0, (int) $offset ), (int) $limit ) : $ids,
            'bytes'       => $bytes,
            'total'       => count( $ids ),
            'total_bytes' => $sum,
        ];
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    /**
     * Nonce and capability, as every Store Health request.
     *
     * @return void
     */
    private static function verify() {
        if ( ! check_ajax_referer( self::NONCE, 'security', false ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'brikpanel' ) ], 403 );
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
        }
    }

    /**
     * @return void
     */
    public static function ajax_start() {
        self::verify();
        if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
            wp_send_json_error( [ 'message' => __( 'Action Scheduler is unavailable.', 'brikpanel' ) ], 500 );
        }
        self::start();
        wp_send_json_success( self::progress_payload() );
    }

    /**
     * @return void
     */
    public static function ajax_progress() {
        self::verify();
        self::maybe_resume();
        wp_send_json_success( self::progress_payload() );
    }

    /**
     * A page of the list or of the trash, as the card's own markup.
     *
     * @return void
     */
    public static function ajax_page() {
        self::verify();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
        $view = isset( $_POST['view'] ) && 'trash' === sanitize_key( wp_unslash( $_POST['view'] ) ) ? 'trash' : 'list';
        $sort = isset( $_POST['sort'] ) ? self::sort_key( sanitize_key( wp_unslash( $_POST['sort'] ) ) ) : 'size';
        $page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
        // phpcs:enable

        $offset = ( $page - 1 ) * self::PER_PAGE;
        if ( 'trash' === $view ) {
            $trash = self::trash_items( $offset, self::PER_PAGE );
            $ids   = $trash['ids'];
            $bytes = $trash['bytes'];
            $total = $trash['total'];
        } else {
            $result = self::result();
            $items  = $result ? $result['items'] : [];
            $ids    = array_slice( self::sorted_ids( $items, $sort ), $offset, self::PER_PAGE );
            $bytes  = $items;
            $total  = count( $items );
        }

        $left = max( 0, $total - $offset - count( $ids ) );
        wp_send_json_success( [
            'html'  => self::tiles_html( $ids, $bytes, $view ),
            'left'  => $left,
            'more'  => $left > 0 ? self::more_label( $left ) : '',
            'total' => $total,
        ] );
    }

    /**
     * Move images to the trash (or delete them, with no trash on the site).
     *
     * @return void
     */
    public static function ajax_trash() {
        self::verify();

        $result = self::result();
        if ( null === $result ) {
            wp_send_json_error( [ 'message' => __( 'Check for unused images first.', 'brikpanel' ) ], 409 );
        }
        if ( self::is_stale( $result ) || self::is_running() ) {
            wp_send_json_error( [ 'message' => __( 'This list is more than a week old. Check again before you delete anything.', 'brikpanel' ) ], 409 );
        }

        $items = $result['items'];
        $ids   = self::posted_ids( array_keys( $items ) );
        if ( empty( $ids ) ) {
            wp_send_json_success( self::action_payload( 'trash', [], [], 0 ) );
        }

        $check = Brikpanel_BrikControl_Unused_Images_Scanner::recheck( $ids, (int) $result['started'] );
        if ( ! empty( $check['stale'] ) ) {
            wp_send_json_error( [ 'message' => __( 'A lot has changed on the site since the check. Check again before you delete anything.', 'brikpanel' ) ], 409 );
        }
        $used    = array_map( 'intval', (array) $check['used'] );
        $days    = self::trash_days();
        $started = microtime( true );
        $moved   = [];
        $failed  = 0;

        $todo = array_values( array_diff( $ids, $used ) );
        $rest = [];
        foreach ( $todo as $i => $id ) {
            if ( microtime( true ) - $started > self::ACTION_BUDGET ) {
                $rest = array_slice( $todo, $i );
                break;
            }
            if ( ! current_user_can( 'delete_post', $id ) ) {
                $failed++;
                continue;
            }
            if ( $days > 0 ) {
                if ( wp_trash_post( $id ) ) {
                    update_post_meta( $id, self::TRASH_META, (int) ( $items[ $id ] ?? 0 ) );
                    $moved[] = $id;
                } else {
                    $failed++;
                }
            } elseif ( wp_delete_attachment( $id, true ) ) {
                $moved[] = $id;
            } else {
                $failed++;
            }
        }

        $gone = array_merge( $moved, $used );
        if ( ! empty( $gone ) ) {
            self::update_items( static function ( array $stored ) use ( $gone ) {
                return array_diff_key( $stored, array_flip( $gone ) );
            } );
        }

        wp_send_json_success( self::action_payload( $days > 0 ? 'trash' : 'delete', $moved, $used, $failed, $rest ) );
    }

    /**
     * Bring images back from the trash, into the list.
     *
     * @return void
     */
    public static function ajax_restore() {
        self::verify();

        $trash    = self::trash_items();
        $ids      = self::posted_ids( $trash['ids'] );
        $started  = microtime( true );
        $restored = [];
        $failed   = 0;
        $rest     = [];

        foreach ( $ids as $i => $id ) {
            if ( microtime( true ) - $started > self::ACTION_BUDGET ) {
                $rest = array_slice( $ids, $i );
                break;
            }
            if ( ! current_user_can( 'delete_post', $id ) || ! wp_untrash_post( $id ) ) {
                $failed++;
                continue;
            }
            delete_post_meta( $id, self::TRASH_META );
            $restored[] = $id;
        }

        if ( ! empty( $restored ) && null !== self::read( self::OPT_RESULT ) ) {
            $bytes = $trash['bytes'];
            self::update_items( static function ( array $stored ) use ( $restored, $bytes ) {
                foreach ( $restored as $id ) {
                    $stored[ $id ] = (int) ( $bytes[ $id ] ?? 0 );
                }
                return $stored;
            } );
        }

        wp_send_json_success( self::action_payload( 'restore', $restored, [], $failed, $rest ) );
    }

    /**
     * Delete images in the trash for good.
     *
     * @return void
     */
    public static function ajax_delete() {
        self::verify();

        $trash   = self::trash_items();
        $ids     = self::posted_ids( $trash['ids'] );
        $started = microtime( true );
        $deleted = [];
        $failed  = 0;
        $rest    = [];

        foreach ( $ids as $i => $id ) {
            if ( microtime( true ) - $started > self::ACTION_BUDGET ) {
                $rest = array_slice( $ids, $i );
                break;
            }
            if ( ! current_user_can( 'delete_post', $id ) || ! wp_delete_attachment( $id, true ) ) {
                $failed++;
                continue;
            }
            $deleted[] = $id;
        }

        wp_send_json_success( self::action_payload( 'purge', $deleted, [], $failed, $rest ) );
    }

    /**
     * The images a request names: `ids[]`, or `all=1` for the next batch of
     * everything allowed. Only ids in $allowed ever pass, so this endpoint
     * cannot touch an image the card did not list.
     *
     * @param int[] $allowed
     * @return int[]
     */
    private static function posted_ids( array $allowed ) {
        $allowed = array_map( 'intval', $allowed );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify().
        if ( ! empty( $_POST['all'] ) ) {
            return array_slice( $allowed, 0, self::MAX_IDS );
        }
        $posted = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
        // phpcs:enable
        $posted = array_slice( array_values( array_unique( array_filter( $posted ) ) ), 0, self::MAX_IDS );
        return array_values( array_intersect( $posted, $allowed ) );
    }

    /**
     * What an action did, and the card's figures after it.
     *
     * @param string $action 'trash', 'delete' (no trash on the site), 'restore' or 'purge'.
     * @param int[]  $done   Images moved, deleted or restored.
     * @param int[]  $kept   Images left alone because they are used now.
     * @param int    $failed Images that could not be changed.
     * @param int[]  $rest   Images left for the next request (the time ran out).
     * @return array
     */
    private static function action_payload( $action, array $done, array $kept, $failed, array $rest = [] ) {
        $count = count( $done );
        $parts = [];

        if ( 'trash' === $action ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of images. */
                _n( '%s image moved to the trash.', '%s images moved to the trash.', $count, 'brikpanel' ),
                brikpanel_number( $count )
            );
        } elseif ( 'delete' === $action || 'purge' === $action ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of images. */
                _n( '%s image deleted for good.', '%s images deleted for good.', $count, 'brikpanel' ),
                brikpanel_number( $count )
            );
        } else {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of images. */
                _n( '%s image put back in the Media Library.', '%s images put back in the Media Library.', $count, 'brikpanel' ),
                brikpanel_number( $count )
            );
        }
        if ( ! empty( $kept ) ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of images. */
                _n( '%s image is used now, so it was kept.', '%s images are used now, so they were kept.', count( $kept ), 'brikpanel' ),
                brikpanel_number( count( $kept ) )
            );
        }
        if ( $failed > 0 ) {
            $parts[] = brikpanel_safe_sprintf(
                /* translators: %s: number of images. */
                _n( '%s image could not be changed.', '%s images could not be changed.', $failed, 'brikpanel' ),
                brikpanel_number( $failed )
            );
        }

        return array_merge( self::figures(), [
            'action'  => $action,
            'done'    => array_values( array_map( 'intval', $done ) ),
            'kept'    => array_values( array_map( 'intval', $kept ) ),
            'failed'  => (int) $failed,
            'rest'    => array_values( array_map( 'intval', $rest ) ),
            'message' => implode( ' ', $parts ),
        ] );
    }

    /**
     * The card's figures as they are now: the list and the trash.
     *
     * @return array
     */
    private static function figures() {
        $result = self::result();
        $items  = $result ? $result['items'] : [];
        $trash  = self::trash_items();
        return [
            'count'       => count( $items ),
            'bytes'       => (int) array_sum( $items ),
            'summary'     => self::summary_text( count( $items ), (int) array_sum( $items ) ),
            'trash_count' => $trash['total'],
            'trash_bytes' => (int) $trash['total_bytes'],
            'trash_title' => self::trash_title( $trash['total'], $trash['total_bytes'] ),
        ];
    }

    /**
     * Where the scan is, for the bar.
     *
     * @return array
     */
    private static function progress_payload() {
        $state = self::state();
        if ( null === $state ) {
            return [ 'running' => false ];
        }
        $progress = Brikpanel_BrikControl_Unused_Images_Scanner::progress( $state );
        return [
            'running' => true,
            'pct'     => (float) $progress['pct'],
            'pct_text' => brikpanel_percent( (float) $progress['pct'], 0 ),
            'label'   => self::phase_label( (string) $progress['phase'] ),
        ];
    }

    // =========================================================================
    // TEXTS
    // =========================================================================

    /**
     * @param string $phase Scanner pass.
     * @return string
     */
    private static function phase_label( $phase ) {
        switch ( $phase ) {
            case 'quick':
            case 'posts':
            case 'meta_files':
                return __( 'Checking products, pages and posts…', 'brikpanel' );
            case 'terms':
            case 'options':
                return __( 'Checking categories and settings…', 'brikpanel' );
            case 'comments':
            case 'users':
                return __( 'Checking reviews and profiles…', 'brikpanel' );
            case 'tables':
                return __( "Checking other plugins' data…", 'brikpanel' );
            case 'theme':
                return __( 'Checking your theme…', 'brikpanel' );
            case 'measure':
            case 'done':
                return __( 'Measuring the files…', 'brikpanel' );
            default:
                return __( 'Getting the images ready…', 'brikpanel' );
        }
    }

    /**
     * @param int $count
     * @param int $bytes
     * @return string
     */
    public static function summary_text( $count, $bytes ) {
        if ( $count < 1 ) {
            return __( 'Every image is used somewhere. There is nothing to clean up.', 'brikpanel' );
        }
        return brikpanel_safe_sprintf(
            /* translators: 1: number of images, 2: their size on the server, e.g. "310 MB". */
            _n(
                '%1$s image is not used anywhere. It takes up %2$s.',
                '%1$s images are not used anywhere. Together they take up %2$s.',
                $count,
                'brikpanel'
            ),
            brikpanel_number( $count ),
            self::bytes_label( $bytes )
        );
    }

    /**
     * @param int $count
     * @param int $bytes
     * @return string
     */
    private static function trash_title( $count, $bytes ) {
        return brikpanel_safe_sprintf(
            /* translators: 1: number of images, 2: their size on the server, e.g. "80 MB". */
            _n( '%1$s image in the trash · %2$s', '%1$s images in the trash · %2$s', $count, 'brikpanel' ),
            brikpanel_number( $count ),
            self::bytes_label( $bytes )
        );
    }

    /**
     * @param int $left
     * @return string
     */
    private static function more_label( $left ) {
        return brikpanel_safe_sprintf(
            /* translators: %s: number of images not shown yet. */
            _n( 'Show more (%s left)', 'Show more (%s left)', $left, 'brikpanel' ),
            brikpanel_number( $left )
        );
    }

    /**
     * A file size in the viewer's language: "820 KB", "3.4 MB", "1.2 GB".
     *
     * @param int $bytes
     * @return string
     */
    public static function bytes_label( $bytes ) {
        $bytes = max( 0, (int) $bytes );
        if ( 0 === $bytes ) {
            // A file that is gone takes no room: 0, not a rounded-up 1.
            /* translators: %s: a size in kilobytes, e.g. "820". */
            return brikpanel_safe_sprintf( __( '%s KB', 'brikpanel' ), brikpanel_number( 0 ) );
        }
        if ( $bytes >= 1073741824 ) {
            /* translators: %s: a size in gigabytes, e.g. "1.2". */
            return brikpanel_safe_sprintf( __( '%s GB', 'brikpanel' ), brikpanel_number( $bytes / 1073741824, 1, true ) );
        }
        if ( $bytes >= 1048576 ) {
            /* translators: %s: an image file size in megabytes, e.g. "2.40" */
            return brikpanel_safe_sprintf( __( '%s MB', 'brikpanel' ), brikpanel_number( $bytes / 1048576, $bytes >= 104857600 ? 0 : 1, true ) );
        }
        /* translators: %s: a size in kilobytes, e.g. "820". */
        return brikpanel_safe_sprintf( __( '%s KB', 'brikpanel' ), brikpanel_number( max( 1, (int) round( $bytes / 1024 ) ) ) );
    }

    /**
     * @param string $sort
     * @return string
     */
    private static function sort_key( $sort ) {
        return in_array( $sort, [ 'size', 'new', 'old' ], true ) ? $sort : 'size';
    }

    // =========================================================================
    // CARD
    // =========================================================================

    /**
     * Styles and script, on the Store Health page only.
     *
     * @return void
     */
    public static function enqueue_assets() {
        $style_deps  = function_exists( 'brikpanel_narrow_deps' ) ? brikpanel_narrow_deps( [ 'ui', 'sheet' ], 'style' ) : [];
        $script_deps = function_exists( 'brikpanel_narrow_deps' ) ? brikpanel_narrow_deps( [ 'sheet', 'format' ] ) : [];

        wp_enqueue_style(
            self::HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol-unused-images.css',
            $style_deps,
            self::asset_version( 'brikpanel-brikcontrol-unused-images.css' )
        );
        wp_enqueue_script(
            self::HANDLE,
            BRIKPANEL_URL . 'front-end/brikcontrol/assets/brikpanel-brikcontrol-unused-images.js',
            $script_deps,
            self::asset_version( 'brikpanel-brikcontrol-unused-images.js' ),
            true
        );

        $days = self::trash_days();
        wp_localize_script( self::HANDLE, 'brikpanelUnusedImages', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE ),
            'max_ids'  => self::MAX_IDS,
            'trash'    => $days > 0,
            // Every sentence with a number comes from the server, or as plural
            // forms counted here with brikpanelFormat.count().
            'i18n'     => [
                /* translators: 1: number of images selected, 2: their size on the server, e.g. "45 MB". */
                'selected_size' => self::js_plural( _n_noop( '%1$s selected · %2$s', '%1$s selected · %2$s', 'brikpanel' ) ),
                'select_all'    => self::js_plural( _n_noop( 'Select all %s', 'Select all %s', 'brikpanel' ) ),
                'confirm_purge' => self::js_plural( _n_noop( 'Delete %s image for good? This cannot be undone.', 'Delete %s images for good? This cannot be undone.', 'brikpanel' ) ),
                'confirm_now'   => self::js_plural( _n_noop( 'Delete %s image for good? This site has no trash, so this cannot be undone.', 'Delete %s images for good? This site has no trash, so this cannot be undone.', 'brikpanel' ) ),
                // The same sentences the server writes after one batch, counted
                // here when an action took several.
                'done'          => [
                    'trash'   => self::js_plural( _n_noop( '%s image moved to the trash.', '%s images moved to the trash.', 'brikpanel' ) ),
                    'purge'   => self::js_plural( _n_noop( '%s image deleted for good.', '%s images deleted for good.', 'brikpanel' ) ),
                    'restore' => self::js_plural( _n_noop( '%s image put back in the Media Library.', '%s images put back in the Media Library.', 'brikpanel' ) ),
                    'kept'    => self::js_plural( _n_noop( '%s image is used now, so it was kept.', '%s images are used now, so they were kept.', 'brikpanel' ) ),
                    'failed'  => self::js_plural( _n_noop( '%s image could not be changed.', '%s images could not be changed.', 'brikpanel' ) ),
                ],
                'bytes'         => [
                    /* translators: %s: a size in kilobytes, e.g. "820". */
                    'kb' => __( '%s KB', 'brikpanel' ),
                    /* translators: %s: an image file size in megabytes, e.g. "2.40" */
                    'mb' => __( '%s MB', 'brikpanel' ),
                    /* translators: %s: a size in gigabytes, e.g. "1.2". */
                    'gb' => __( '%s GB', 'brikpanel' ),
                ],
                /* translators: 1: position of the image in the list, 2: images in the list. */
                'position'      => _x( '%1$s of %2$s', 'position of an image in a list', 'brikpanel' ),
                'starting'      => __( 'Starting…', 'brikpanel' ),
                'working'       => __( 'Working…', 'brikpanel' ),
                'failed'        => __( 'Something went wrong. Please try again.', 'brikpanel' ),
                'start_failed'  => __( 'Could not start the check. Please try again.', 'brikpanel' ),
                'undo'          => __( 'Undo', 'brikpanel' ),
                'delete_now'    => __( 'Delete permanently', 'brikpanel' ),
                'cancel'        => __( 'Cancel', 'brikpanel' ),
            ],
        ] );
    }

    /**
     * @param array $nooped _n_noop() result.
     * @return array|string
     */
    private static function js_plural( $nooped ) {
        return function_exists( 'brikpanel_js_plural' ) ? brikpanel_js_plural( $nooped ) : '';
    }

    /**
     * @param string $file
     * @return string|int
     */
    private static function asset_version( $file ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falls back to the plugin version.
        return @filemtime( BRIKPANEL_PATH . 'front-end/brikcontrol/assets/' . $file ) ?: BRIKPANEL_VERSION;
    }

    /**
     * The card, under the checks of the Store Health page.
     *
     * @return void
     */
    public static function render_card() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        self::maybe_resume();

        $progress = self::progress_payload();
        $result   = self::result();
        $items    = $result ? $result['items'] : [];
        $trash    = self::trash_items();
        $days     = self::trash_days();
        $stale    = $result && self::is_stale( $result );
        $first    = array_slice( self::sorted_ids( $items, 'size' ), 0, self::PER_PAGE );

        $view = [
            'running'     => ! empty( $progress['running'] ),
            'progress'    => $progress,
            'result'      => $result,
            'count'       => count( $items ),
            'summary'     => self::summary_text( count( $items ), (int) array_sum( $items ) ),
            'bytes'       => (int) array_sum( $items ),
            'tiles'       => $result ? self::tiles_html( $first, $items, 'list' ) : '',
            'left'        => max( 0, count( $items ) - count( $first ) ),
            'trash'       => $trash,
            'trash_title' => self::trash_title( $trash['total'], $trash['total_bytes'] ),
            'trash_left'  => max( 0, $trash['total'] - self::PER_PAGE ),
            'days'        => $days,
            'stale'       => $stale,
        ];
        $view['more_label']       = $view['left'] > 0 ? self::more_label( $view['left'] ) : '';
        $view['trash_more_label'] = $view['trash_left'] > 0 ? self::more_label( $view['trash_left'] ) : '';

        include BRIKPANEL_PATH . 'front-end/brikcontrol/views/partials/unused-images-card.php';
    }

    /**
     * The grid's tiles for these images, with what the preview shows in data
     * attributes, written for this viewer.
     *
     * @param int[]  $ids
     * @param array  $bytes id => bytes.
     * @param string $view  'list' or 'trash'.
     * @return string
     */
    public static function tiles_html( array $ids, array $bytes, $view ) {
        if ( empty( $ids ) ) {
            return '';
        }
        $ids = array_values( array_map( 'intval', $ids ) );
        _prime_post_caches( $ids, false, true );

        $parents = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( $post && $post->post_parent > 0 ) {
                $parents[] = (int) $post->post_parent;
            }
        }
        if ( ! empty( $parents ) ) {
            _prime_post_caches( array_values( array_unique( $parents ) ), false, false );
        }

        $upload = wp_get_upload_dir();
        $base   = isset( $upload['basedir'] ) ? wp_normalize_path( (string) $upload['basedir'] ) : '';
        $days   = self::trash_days();

        ob_start();
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }
            $stored = (string) get_post_meta( $id, '_wp_attached_file', true );
            $name   = '' !== $stored ? wp_basename( $stored ) : (string) $post->post_title;
            $path   = (string) get_attached_file( $id, true );
            $local  = '' !== $base && 0 === strpos( wp_normalize_path( $path ), $base );
            $missing = $local && ! is_file( $path );
            $thumb   = $missing ? false : wp_get_attachment_image_src( $id, 'medium' );
            $large   = $missing ? false : wp_get_attachment_image_src( $id, 'large' );
            $meta    = wp_get_attachment_metadata( $id );
            $width   = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
            $height  = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
            // No size for a file that is gone: the tile says "File missing".
            $size    = (int) ( $bytes[ $id ] ?? 0 ) > 0 ? self::bytes_label( (int) $bytes[ $id ] ) : '';
            $time    = get_post_timestamp( $post );

            $parent_title = '';
            $parent_url   = '';
            if ( $post->post_parent > 0 ) {
                $parent = get_post( (int) $post->post_parent );
                if ( $parent ) {
                    $parent_title = brikpanel_plain_label( get_the_title( $parent ) );
                    if ( '' === $parent_title ) {
                        $parent_title = __( '(no title)', 'brikpanel' );
                    }
                    if ( current_user_can( 'edit_post', $parent->ID ) ) {
                        $parent_url = (string) get_edit_post_link( $parent->ID, 'raw' );
                    }
                }
            }

            $until = '';
            if ( 'trash' === $view && $days > 0 ) {
                $at = (int) get_post_meta( $id, '_wp_trash_meta_time', true );
                if ( $at > 0 ) {
                    $until = brikpanel_safe_sprintf(
                        /* translators: %s: date the image is deleted for good. */
                        __( 'Deleted for good on %s', 'brikpanel' ),
                        wp_date( brikpanel_date_format(), $at + $days * DAY_IN_SECONDS )
                    );
                }
            }

            $data = [
                'id'      => $id,
                'bytes'   => (int) ( $bytes[ $id ] ?? 0 ),
                'name'    => $name,
                'full'    => $large ? (string) $large[0] : '',
                'size'    => $size,
                'dims'    => ( $width > 0 && $height > 0 )
                    ? brikpanel_safe_sprintf(
                        /* translators: 1: width in pixels, 2: height in pixels. */
                        __( '%1$s × %2$s px', 'brikpanel' ),
                        brikpanel_number( $width ),
                        brikpanel_number( $height )
                    )
                    : '',
                'date'    => $time ? wp_date( brikpanel_date_format(), $time ) : '',
                'parent'  => $parent_title,
                'parent_url' => $parent_url,
                'media'   => 'list' === $view ? admin_url( 'upload.php?item=' . $id ) : '',
                'until'   => $until,
                'missing' => $missing ? 1 : 0,
            ];
            ?>
            <li class="brikpanel-unimg-tile" data-unimg-tile="<?php echo esc_attr( (string) $id ); ?>"<?php
            foreach ( $data as $key => $value ) {
                if ( 'id' === $key ) {
                    continue;
                }
                $attr = 'data-' . str_replace( '_', '-', $key );
                echo ' ' . esc_attr( $attr ) . '="' . ( in_array( $key, [ 'full', 'parent_url', 'media' ], true ) ? esc_url( (string) $value ) : esc_attr( (string) $value ) ) . '"';
            }
            ?>>
                <label class="brikpanel-unimg-check">
                    <input type="checkbox" value="<?php echo esc_attr( (string) $id ); ?>" aria-label="<?php
                        /* translators: %s: file name of an image. */
                        echo esc_attr( brikpanel_safe_sprintf( __( 'Select %s', 'brikpanel' ), $name ) );
                    ?>">
                    <span class="brikpanel-unimg-check-box" aria-hidden="true"></span>
                </label>
                <button type="button" class="brikpanel-unimg-open" aria-haspopup="dialog" aria-label="<?php
                    /* translators: %s: file name of an image. */
                    echo esc_attr( brikpanel_safe_sprintf( __( 'Preview %s', 'brikpanel' ), $name ) );
                ?>">
                    <?php if ( $thumb ) : ?>
                        <img src="<?php echo esc_url( (string) $thumb[0] ); ?>" alt="" loading="lazy" decoding="async"<?php echo ( ! empty( $thumb[1] ) && ! empty( $thumb[2] ) ) ? ' width="' . (int) $thumb[1] . '" height="' . (int) $thumb[2] . '"' : ''; ?>>
                    <?php else : ?>
                        <span class="brikpanel-unimg-missing">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m3 3 18 18"/><path d="M21 15l-5-5L5 21"/></svg>
                            <span><?php esc_html_e( 'File missing', 'brikpanel' ); ?></span>
                        </span>
                    <?php endif; ?>
                </button>
                <?php if ( (int) ( $bytes[ $id ] ?? 0 ) > 0 ) : ?>
                    <span class="brikpanel-unimg-size" dir="auto"><?php echo esc_html( $size ); ?></span>
                <?php endif; ?>
            </li>
            <?php
        }
        return (string) ob_get_clean();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * A database lock name of this site (multisite sites never share one).
     *
     * @param string $kind
     * @return string
     */
    private static function lock_name( $kind ) {
        global $wpdb;
        return 'bp_unimg_' . $kind . '_' . substr( md5( (string) $wpdb->prefix ), 0, 12 );
    }

    /**
     * One of the card's two records (the scan, the list), read straight from
     * the options table: another request may have written it a moment ago,
     * and a copy in the object cache would double a scan's memory.
     *
     * @param string $name OPT_STATE or OPT_RESULT.
     * @return array|null
     */
    private static function read( $name ) {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }
        $value = Brikpanel_BrikControl_Unused_Images_Scanner::maybe_array( $raw );
        return is_array( $value ) && ! empty( $value ) ? $value : null;
    }

    /**
     * Write one of the card's records, never autoloaded, outside the object
     * cache (see read()).
     *
     * @param string $name
     * @param array  $value
     * @return void
     */
    private static function write( $name, array $value ) {
        global $wpdb;
        $data = serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- read back with classes refused.
        $done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", $data, $name ) );
        if ( ! $done && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ) ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $data, self::autoload_off() ) );
        }
        wp_cache_delete( $name, 'options' );
    }

    /**
     * @param string $name
     * @return void
     */
    private static function erase( $name ) {
        delete_option( $name );
        wp_cache_delete( $name, 'options' );
    }

    /**
     * The "not autoloaded" value of this WordPress: "off" since 6.6, "no" before.
     *
     * @return string
     */
    private static function autoload_off() {
        global $wp_version;
        return version_compare( (string) $wp_version, '6.6', '>=' ) ? 'off' : 'no';
    }
}
