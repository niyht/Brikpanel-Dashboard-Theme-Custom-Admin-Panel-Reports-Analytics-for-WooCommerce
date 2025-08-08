<?php
if(!defined('ABSPATH')) { exit; }

function brik82ad_custom_dashboard_metabox_order_value() {
    wp_add_dashboard_widget(
        'brik82ad_order_value_metabox',
        __('Average Order Value', 'brikpanel-admin-panel-dashboard-for-woocommerce'),
        'brik82ad_order_value',
        'null',
        'null',
        'column3'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_order_value');

function brik82ad_order_value() {
    ?>
    <div id="brik82adRadioFilterOrderValue" name="brik82adRadioFilterOrderValue" class="brik82adRadioFilterOrderValue">
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="today" checked>
            <span class="name"><?php esc_html_e( 'Today', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="yesterday">
            <span class="name"><?php esc_html_e( 'Yesterday', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="7days">
            <span class="name"><?php esc_html_e( 'Last 7 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="30days">
            <span class="name"><?php esc_html_e( 'Last 30 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="90days">
            <span class="name"><?php esc_html_e( 'Last 90 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="365days">
            <span class="name"><?php esc_html_e( 'Last 365 Days', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
        <label class="filterOrderValue">
            <input type="radio" name="filterOrderValue" value="custom">
            <span class="name"><?php esc_html_e( 'Custom', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></span>
        </label>
    </div>

    <p id="brik82adAjaxOrderValue" style="display: inline-block;"></p>
    <div class="container">
        <input type="text" id="brik82adDateSelectOrderValue" placeholder="<?php esc_attr_e( 'Select Date', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?>" style="display: none;">
        <button id="brik82adSendButtonOrderValue" style="display: none;"><?php esc_html_e( 'Send', 'brikpanel-admin-panel-dashboard-for-woocommerce' ); ?></button>
    </div>
    <?php
}


// date ajax
function brik82ad_date_ajax_order_value() {
    if (
        !isset($_POST['start_date']) ||
        !isset($_POST['end_date'])
    ) {
        wp_send_json_error(['message' => 'Date information is missing.']);
        return; // Eksikse işlemi durdur
    }

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

    if ( !current_user_can('manage_woocommerce') ) {
        wp_send_json_error(['message' => 'Bu işlemi yapma yetkiniz yok.']);
        return;
    }

    // Tarihleri güvenli şekilde al
    $start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ) );
    $end_date   = sanitize_text_field( wp_unslash( $_POST['end_date'] ) );


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
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_date_ajax_order_value', 'brik82ad_date_ajax_order_value');

function brik82ad_ajax_today_order_value() {

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
                'after' => 'today',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_today_order_value', 'brik82ad_ajax_today_order_value');

function brik82ad_ajax_yesterday_order_value() {

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
                'after' => 'yesterday',
                'before' => 'today',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_yesterday_order_value', 'brik82ad_ajax_yesterday_order_value');

function brik82ad_ajax_7days_order_value() {

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
                'after' => '7 days ago',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_7days_order_value', 'brik82ad_ajax_7days_order_value');

function brik82ad_ajax_30days_order_value() {

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
                'after' => '30 days ago',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_30days_order_value', 'brik82ad_ajax_30days_order_value');

function brik82ad_ajax_90days_order_value() {

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
                'after' => '90 days ago',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_90days_order_value', 'brik82ad_ajax_90days_order_value');

function brik82ad_ajax_365days_order_value() {

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
                'after' => '365 days ago',
                'inclusive' => true,
                'column'    => 'post_date' // WooCommerce sipariş tarihini kullan
            ),
            'return' => 'objects', // Sipariş nesnelerini döndür
        ),
    );

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $order_count = count($orders);
    
    if ($order_count > 0) {
        foreach ($orders as $order) {
            $total_revenue += $order->get_total(); // Sipariş toplamını al
        }
        $average_order_value = $total_revenue / $order_count;
    } else {
        $average_order_value = 0;
    }
    
    wp_send_json_success(['total' => wc_price($average_order_value)]);
}
add_action('wp_ajax_brik82ad_ajax_365days_order_value', 'brik82ad_ajax_365days_order_value');
