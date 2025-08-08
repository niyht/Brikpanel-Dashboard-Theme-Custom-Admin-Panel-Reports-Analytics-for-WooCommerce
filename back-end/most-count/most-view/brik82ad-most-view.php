<?php
if (!defined('ABSPATH')) { exit; }

function brik82ad_custom_dashboard_metabox_most_view() {
    wp_add_dashboard_widget(
        'brik82ad_metabox_most_view',
        'Most Viewed Pages',
        'brik82ad_most_view_callback',
        'null',
        'null',
        'column4'
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_most_view');

function brik82ad_most_view_callback() {
    ?>
<a href="https://brksoft.com/brikpanel/" target="_blank">
    <img src="<?php echo esc_url( plugin_dir_url( dirname(__FILE__, 3) ) . 'images/normal.webp' ); ?>" alt="view Products" style="width: 100%; height: auto;">
</a>

    <?php
}

