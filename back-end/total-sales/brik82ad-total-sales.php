<?php
if( ! defined( 'ABSPATH' ) ) {
    exit;
}

function brik82ad_custom_dashboard_metabox() {
    wp_add_dashboard_widget(
        'brik82ad_total_sales_metabox', 
        __( 'Total Sales', 'brikpanel-admin-panel-dashboard-for-woocommerce' ), 
        'brik82ad_total_sales',
        'null',
        'null',
        'normal'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox');

function brik82ad_total_sales() {
    ?>
    <div id="brik82adRadioFilter" name="brik82adRadioFilter" class="brik82adRadioFilter">
        <label class="filter">
            <input type="radio" name="filter" value="today" checked>
            <span class="name"><?php esc_html_e( 'Today', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="yesterday">
            <span class="name"><?php esc_html_e( 'Yesterday', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="7days">
            <span class="name"><?php esc_html_e( 'Last 7 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="30days">
            <span class="name"><?php esc_html_e( 'Last 30 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="90days">
            <span class="name"><?php esc_html_e( 'Last 90 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="365days">
            <span class="name"><?php esc_html_e( 'Last 365 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filter">
            <input type="radio" name="filter" value="custom">
            <span class="name"><?php esc_html_e( 'Custom', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
    </div>

    <p id="brik82adAjaxValue" style="display: inline-block;"></p>
    <div class="container">
        <input type="text" id="brik82adDateSelect" placeholder="<?php esc_attr_e( 'Select Date', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?>" style="display: none;">
        <button id="brik82adSendButton" style="display: none;"><?php esc_html_e( 'Send', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></button>
    </div>
    <?php
}

// Takvim için ajax
function brik82ad_ajax_send() {
    // Tarih kontrolleri ve güvenlik doğrulaması
    if (
        !isset($_POST['start_date']) || 
        !isset($_POST['end_date']) ||
        !isset($_POST['security'])
    ) {
        wp_send_json_error(['message' => 'Eksik bilgi gönderildi.']);
        return;
    }

    // Nonce değerini düzgünce temizle ve kontrol et
    $security = sanitize_text_field(wp_unslash($_POST['security']));
    if ( !wp_verify_nonce( $security, 'brik82ad_nonce' ) ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }
    
    // Yetki kontrolü
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    // Tarihleri güvenli şekilde al
    $start_date = sanitize_text_field(wp_unslash($_POST['start_date']));
    $end_date   = sanitize_text_field(wp_unslash($_POST['end_date']));

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => $start_date,
                'before' => $end_date,
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
        ),
    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);

}
add_action('wp_ajax_brik82ad_ajax_send', 'brik82ad_ajax_send');

// Bugün değeri için ajax
function brik82ad_ajax_today() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }

    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }


    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d'),
                'inclusive' => true, // 7 gün önceki tarihi de dahil eder
            ),
        ),    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_today', 'brik82ad_ajax_today');

function brik82ad_ajax_yesterday() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d 00:00:00', strtotime('-1 day')),
                'before' => gmdate('Y-m-d 23:59:59', strtotime('-1 day')),
                'inclusive' => true
        ),
),

    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_yesterday', 'brik82ad_ajax_yesterday');

function brik82ad_ajax_7days() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d', strtotime('-7 days')),
                'inclusive' => true, // 7 gün önceki tarihi de dahil eder
            ),
        ),
    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_7days', 'brik82ad_ajax_7days');

function brik82ad_ajax_30days() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }

    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d', strtotime('-30 days')),
                'inclusive' => true, // 7 gün önceki tarihi de dahil eder
            ),
        ),
    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_30days', 'brik82ad_ajax_30days');

function brik82ad_ajax_90days() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d', strtotime('-90 days')),
                'inclusive' => true, // 7 gün önceki tarihi de dahil eder
            ),
        ),
    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_90days', 'brik82ad_ajax_90days');

function brik82ad_ajax_365days() {

    if (
        !isset($_POST['security']) ||
        !wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['security'] ) ),
            'brik82ad_nonce'
        )
    ) {
        wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
        return;
    }
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    $args = array(
        'post_status' => array('wc-completed', 'wc-processing'),
        'posts_per_page' => -1,
        'date_query' => array(
            array(
                'after' => gmdate('Y-m-d', strtotime('-365 days')),
                'inclusive' => true, // 7 gün önceki tarihi de dahil eder
            ),
        ),
    );

    $orders = wc_get_orders($args);
    $total = 0;

    foreach ($orders as $order) {
        $total += $order->get_total();
    }

    wp_send_json_success(['total' => wc_price($total)]);
}
add_action('wp_ajax_brik82ad_ajax_365days', 'brik82ad_ajax_365days');
