<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Object types the page-view table can hold.
 *
 * 'post' is any singular entry (page, post, product, CPT). 'term' is an
 * archive listing screen (product category, tag, any public taxonomy), which
 * has no post of its own but is still a page a shopper looked at.
 *
 * @return string[]
 */
function brikpanel_view_object_types() {
    return [ 'post', 'term' ];
}

/**
 * Which page the current storefront request should be credited to.
 *
 * This used to be `get_the_ID()` read in the footer, which is only correct on
 * a singular template. Everywhere else it returns whichever post the main
 * loop happened to stop on, so a product category archive, the blog index or
 * a search results page silently credited all of its traffic to one arbitrary
 * product listed on it — inventing demand for that product and hiding the
 * archive. On templates that leave no post behind at all (the shop archive,
 * and cart / my account under some themes) it returned 0 and the view was
 * dropped instead.
 *
 * Resolving the queried object fixes both directions: real pages are credited
 * to themselves, archives are credited to their term, and a request with
 * genuinely nothing to attribute (search, 404) is skipped rather than guessed.
 *
 * @return array{id:int,type:string} Zero id when the request is not trackable.
 */
function brikpanel_current_view_target() {
    $none = [ 'id' => 0, 'type' => '' ];

    if ( ! did_action( 'wp' ) ) {
        return $none;
    }

    // Singular first: this also covers the WooCommerce cart, checkout and
    // account screens, which are ordinary pages behind the scenes.
    if ( is_singular() ) {
        $id = (int) get_queried_object_id();
        return $id > 0 ? [ 'id' => $id, 'type' => 'post' ] : $none;
    }

    // The shop archive is a post type archive, not a page, but WooCommerce
    // keeps a real page behind it that merchants recognise by name.
    if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
        $id = (int) wc_get_page_id( 'shop' );
        return $id > 0 ? [ 'id' => $id, 'type' => 'post' ] : $none;
    }

    if ( is_category() || is_tag() || is_tax() ) {
        $id = (int) get_queried_object_id();
        return $id > 0 ? [ 'id' => $id, 'type' => 'term' ] : $none;
    }

    // Blog index used as the posts page. (A static front page is singular and
    // was already handled above.)
    if ( is_home() ) {
        $id = (int) get_option( 'page_for_posts' );
        return $id > 0 ? [ 'id' => $id, 'type' => 'post' ] : $none;
    }

    // Search, author, date archives and 404s have no object worth ranking.
    return $none;
}

/**
 * Sayfa görüntülenmesini veritabanına kaydeder (kayıt çekirdeği).
 * Not: Veriler Yerel Zaman (Local Time) olarak kaydediliyor.
 *
 * Shared by the unified tracker endpoint and the legacy standalone AJAX
 * action below. Callers apply the master-switch / admin / bot guards.
 *
 * @param int    $page_id     Post ID, or term ID when $object_type is 'term'.
 * @param string $object_type One of brikpanel_view_object_types().
 */
function brikpanel_record_page_view( $page_id, $object_type = 'post' ) {
    global $wpdb;

    $page_id = (int) $page_id;
    if ( $page_id <= 0 ) {
        return;
    }

    // Rows written before 3.2.41 carry the column default, so an unknown value
    // has to fall back to 'post' rather than create a third bucket.
    if ( ! in_array( $object_type, brikpanel_view_object_types(), true ) ) {
        $object_type = 'post';
    }

    $table_name = $wpdb->prefix . 'brikpanel_visited_pages';
    $today      = wp_date( 'Y-m-d' );
    $day_start  = $today . ' 00:00:00';
    $day_end    = $today . ' 23:59:59';

    // BETWEEN on the indexed datetime column engages idx_page_date instead of
    // forcing a full scan as LIKE would have done.
    $updated = $wpdb->query( $wpdb->prepare(
        "UPDATE {$table_name}
            SET visit_count = visit_count + 1,
                date_column = %s
          WHERE page_id = %d
            AND object_type = %s
            AND date_column BETWEEN %s AND %s
          ORDER BY date_column DESC
          LIMIT 1",
        current_time( 'mysql' ),
        $page_id,
        $object_type,
        $day_start,
        $day_end
    ) );

    if ( ! $updated ) {
        $wpdb->insert(
            $table_name,
            [
                'page_id'     => $page_id,
                'object_type' => $object_type,
                'visit_count' => 1,
                'date_column' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%s' ]
        );
    }
}

/**
 * Legacy standalone AJAX action (pre-3.2.20 tracker JS). New pages ship the
 * unified tracker; this stays registered for cached pages still carrying the
 * old inline JS.
 */
function brikpanel_track_page_view() {
    // Master tracking switch and cookie-consent gate — also guards pings from
    // cached pages that still carry the old tracker JS after the merchant
    // turned tracking off or switched the consent gate on.
    if ( function_exists( 'brikpanel_frontend_tracking_allowed' ) && ! brikpanel_frontend_tracking_allowed( 'endpoint' ) ) {
        wp_send_json_success();
    }
    if ( brikpanel_is_admin_user() ) {
        wp_send_json_success();
    }
    if ( function_exists( '_brikpanel_is_bot_ua' ) && _brikpanel_is_bot_ua() ) {
        wp_send_json_success();
    }

    if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'brikpanel_nonce_action' ) ) {
        wp_send_json_error( [ 'message' => 'Invalid nonce.' ] );
    }
    if ( ! isset( $_POST['page_id'] ) ) {
        wp_send_json_error( 'Page ID missing' );
    }

    $object_type = isset( $_POST['page_type'] ) ? sanitize_key( wp_unslash( $_POST['page_type'] ) ) : 'post';

    brikpanel_record_page_view( intval( $_POST['page_id'] ), $object_type );

    wp_send_json_success( [ 'count' => 1 ] );
}
add_action('wp_ajax_brikpanel_track_page_view', 'brikpanel_track_page_view');
add_action('wp_ajax_nopriv_brikpanel_track_page_view', 'brikpanel_track_page_view');

// The inline page-view tracker JS moved into the unified tracker
// (back-end/tracking/brikpanel-unified-tracker.php) in 3.2.20.