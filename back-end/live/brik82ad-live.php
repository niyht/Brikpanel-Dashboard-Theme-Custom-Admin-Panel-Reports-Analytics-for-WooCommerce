<?php

if (!defined('ABSPATH')) exit;

function brik82ad_custom_dashboard_metabox_live_visitors() {
    wp_add_dashboard_widget(
        'brik82ad_metabox_live_visitors',
        'Live Visitors (Real-Time)',
        'brik82ad_live_visitors_callback',
        'null',
        'null',
        'column4'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_live_visitors');
function brik82ad_live_visitors_callback() {
    ?>
<a href="https://brksoft.com/brikpanel/" target="_blank">
    <img src="<?php echo esc_url( plugin_dir_url( dirname(__FILE__, 2) ) . 'images/live.webp' ); ?>" alt="live Products" style="width: 100%; height: auto;">
</a>

    <?php
}
