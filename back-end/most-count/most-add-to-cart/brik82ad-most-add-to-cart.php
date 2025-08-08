<?php
if (!defined('ABSPATH')) { exit; }

function brik82ad_custom_dashboard_metabox_most_add_to_cart() {
    wp_add_dashboard_widget(
        'brik82ad_metabox_most_add_to_cart',
        'Most Add To Cart Products',
        'brik82ad_most_add_to_cart_callback',
        'null',
        'null',
        'normal'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_most_add_to_cart');

function brik82ad_most_add_to_cart_callback() {
    ?>
<a href="https://brksoft.com/brikpanel/" target="_blank">
    <img src="<?php echo esc_url( plugin_dir_url( dirname(__FILE__, 3) ) . 'images/normal.webp' ); ?>" alt="add to cart Products" style="width: 100%; height: auto;">
</a>

    <?php
}

