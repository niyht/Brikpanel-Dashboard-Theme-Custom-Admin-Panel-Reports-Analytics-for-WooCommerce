<?php
if (!defined('ABSPATH')) { exit; }

// 📊 En çok satan ürünleri gösteren Dashboard Metabox
function brik82ad_custom_dashboard_metabox_most_sale() {
    wp_add_dashboard_widget(
        'brik82ad_metabox_most_sale',
        'Most Sale Products',
        'brik82ad_most_sale_callback',
        'null',
        'null',
        'side'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_most_sale');

// 🖥️ Widget HTML Çıktısı
function brik82ad_most_sale_callback() {
    ?>
<a href="https://brksoft.com/brikpanel/" target="_blank">
    <img src="<?php echo esc_url( plugin_dir_url( dirname(__FILE__, 3) ) . 'images/normal.webp' ); ?>" alt="sale Products" style="width: 100%; height: auto;">
</a>

    <?php
}

