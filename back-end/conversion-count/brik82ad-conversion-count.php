<?php
if (!defined('ABSPATH')) { exit; }

function brik82ad_custom_dashboard_metabox_visitor_count() {
    wp_add_dashboard_widget(
        'brik82ad_metabox_visitor_count',
        'Conversion Journey',
        'brik82ad_conversion_count_callback',
        'null',
        'null',
        'side',
    );
}
add_action('wp_dashboard_setup', 'brik82ad_custom_dashboard_metabox_visitor_count');

function brik82ad_conversion_count_callback() {
    ?>
<a href="https://brksoft.com/brikpanel/" target="_blank">
    <img src="<?php echo esc_url( plugin_dir_url( dirname(__FILE__, 2) ) . 'images/conversion.webp' ); ?>" alt="Conversion Products" style="width: 100%; height: auto;">
</a>

    <?php
}

