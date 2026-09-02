<?php
/**
 * BrikPanel — BrikControl Bootstrap
 *
 * Loads every BrikControl class, instantiates the public façade and registers
 * the default Image Health check on the registry.
 *
 * Required from `brikpanel.php` inside `brikpanel_init_admin()`.
 *
 * @package BrikPanel
 * @since   3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// Module enable toggle — registered BEFORE the disabled-state early return so
// the admin can always flip Store Health back on from the BrikPanel WC settings
// tab, even after the whole module has been switched off.
// =============================================================================

/**
 * Whether the BrikControl (Store Health) module is enabled in BrikPanel
 * settings. Defaults to "yes" so existing installs keep working untouched;
 * admins can turn it off from WooCommerce → Settings → BrikPanel → Store Health.
 *
 * @return bool
 */
function brikpanel_brikcontrol_is_enabled() {
    return get_option( 'brikpanel_brikcontrol_enabled', 'yes' ) === 'yes';
}

/**
 * Register the "Store Health" sub-section in the BrikPanel settings tab nav,
 * inserted just before "Developers" so it sits alongside the other feature
 * groups instead of after the developer tools.
 *
 * @param array<string,string> $sections
 * @return array<string,string>
 */
add_filter( 'woocommerce_get_sections_brikpanel', 'brikpanel_brikcontrol_register_settings_section' );
function brikpanel_brikcontrol_register_settings_section( $sections ) {
    if ( ! is_array( $sections ) || isset( $sections['store-health'] ) ) {
        return $sections;
    }
    $out = [];
    foreach ( $sections as $id => $label ) {
        if ( $id === 'developers' ) {
            $out['store-health'] = __( 'Store Health', 'brikpanel' );
        }
        $out[ $id ] = $label;
    }
    if ( ! isset( $out['store-health'] ) ) {
        $out['store-health'] = __( 'Store Health', 'brikpanel' );
    }
    return $out;
}

/**
 * Map the Store Health title block to its section so the settings renderer
 * slices the toggle into the right sub-tab.
 *
 * @param array<string,string> $map
 * @return array<string,string>
 */
add_filter( 'brikpanel_settings_title_section_map', 'brikpanel_brikcontrol_map_settings_title' );
function brikpanel_brikcontrol_map_settings_title( $map ) {
    if ( is_array( $map ) ) {
        $map['brk_brikcontrol_title'] = 'store-health';
    }
    return $map;
}

/**
 * Inject the Store Health master toggle into the BrikPanel settings fields.
 * Spliced in just before the "Developers" block to match the nav ordering.
 *
 * @param array $fields
 * @return array
 */
add_filter( 'brikpanel_settings_fields', 'brikpanel_brikcontrol_register_settings_field', 6 );
function brikpanel_brikcontrol_register_settings_field( $fields ) {
    if ( ! is_array( $fields ) ) {
        return $fields;
    }

    $section = [
        [
            'name' => __( 'Store Health', 'brikpanel' ),
            'type' => 'title',
            'id'   => 'brk_brikcontrol_title',
            'desc' => __( 'BrikControl scans your store in the background and flags issues, like products missing images, from the topbar shield, a dashboard banner, and its own Store Health page. Turn it off to hide all of that and stop the scheduled scans. Your latest results stay saved, so turning it back on restores them.', 'brikpanel' ),
        ],
        [
            'name'    => __( 'Enable Store Health (BrikControl)', 'brikpanel' ),
            'id'      => 'brikpanel_brikcontrol_enabled',
            'type'    => 'checkbox',
            'desc'    => __( 'When on, the Store Health page, topbar shield, dashboard banner and daily background scans are active. When off, the entire module is dormant: no menu item, no topbar icon, no banner, and no scheduled scans.', 'brikpanel' ),
            'default' => 'yes',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'brk_brikcontrol_title',
        ],
    ];

    $insert_at = null;
    foreach ( $fields as $i => $field ) {
        if ( isset( $field['id'], $field['type'] )
            && $field['id'] === 'brk_developers_title'
            && $field['type'] === 'title' ) {
            $insert_at = $i;
            break;
        }
    }
    if ( $insert_at === null ) {
        return array_merge( $fields, $section );
    }
    array_splice( $fields, $insert_at, 0, $section );
    return $fields;
}

/**
 * When the module is switched off from the settings tab, cancel the pending
 * recurring scan and any queued batches so Action Scheduler stops firing a
 * handler that is no longer registered. Re-enabling re-schedules the daily scan
 * automatically on the next init (see Brikpanel_BrikControl_Runner::register).
 *
 * Runs at priority 20 so it fires after `woocommerce_update_options()` (default
 * priority 10) has already persisted the new option value.
 */
add_action( 'woocommerce_update_options_brikpanel', 'brikpanel_brikcontrol_sync_schedule_on_save', 20 );
function brikpanel_brikcontrol_sync_schedule_on_save() {
    if ( brikpanel_brikcontrol_is_enabled() || ! class_exists( 'Brikpanel_Cron' ) ) {
        return;
    }
    Brikpanel_Cron::cancel( 'brikpanel_brikcontrol_scan' );
    Brikpanel_Cron::cancel( 'brikpanel_brikcontrol_scan_batch' );
}

// =============================================================================
// Hard short-circuit when Store Health is disabled. Nothing below loads: no
// classes, no admin page, no AJAX handlers, no topbar/dashboard render hooks,
// and no Action Scheduler handler registration. External callers in the
// dashboard + topbar guard their render with class_exists( 'Brikpanel_BrikControl' ),
// which stays false while the class file is never required.
// =============================================================================
if ( ! brikpanel_brikcontrol_is_enabled() ) {
    return;
}

require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-storage.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-image-plugins.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/checks/abstract-class-brikpanel-brikcontrol-check.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/checks/class-brikpanel-brikcontrol-image-health-check.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/checks/class-brikpanel-brikcontrol-product-lookup-check.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-registry.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-runner.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol.php';

// Default check registration. Other plugins can extend by hooking the
// `brikpanel_brikcontrol_checks` filter or calling
// Brikpanel_BrikControl_Registry::register() before runner registration.
Brikpanel_BrikControl_Registry::register( new Brikpanel_BrikControl_Image_Health_Check() );
Brikpanel_BrikControl_Registry::register( new Brikpanel_BrikControl_Product_Lookup_Check() );

// Boot the public façade (admin menu + AJAX).
Brikpanel_BrikControl::instance();

// Register Action Scheduler handlers + recurring schedule. Runs on init at
// priority 20 so Brikpanel_Cron + WC's AS bootstrap have completed.
add_action( 'init', [ 'Brikpanel_BrikControl_Runner', 'register' ], 20 );

/**
 * One-time repair for the kickoff pile-up that shipped before 3.2.70.
 *
 * Sites that bulk-toggled plugins accumulated one pending scan per plugin
 * (21 rows on a 20-plugin store), and every one of them ran a full sweep.
 * Fixing the enqueue path does nothing for a queue that is already full, so
 * the leftovers are cancelled once here.
 *
 * Guarded by its own marker rather than brikpanel_db_version: a site that has
 * already taken this version still needs the repair, and a version gate would
 * silently skip exactly the site that needed it. When Action Scheduler is not
 * ready on this request the marker is not written, so the next request retries.
 *
 * init:30 — after the runner registers at 20, and AS is not initialised at
 * plugins_loaded.
 */
add_action( 'init', function () {
    if ( get_option( 'brikpanel_brikcontrol_scan_pileup_cleaned' ) === '1' ) {
        return;
    }
    // Mirror EVERY precondition cleanup_duplicate_scans() has, not just the
    // as_* function check. The store classes are a separate requirement, and
    // when they were missing the pass returned 0 having done nothing while this
    // callback still stamped the marker — the repair was then permanently
    // recorded as done on exactly the site that never got it.
    if ( ! class_exists( 'Brikpanel_Cron' ) || ! Brikpanel_Cron::is_available() ) {
        return;
    }
    if ( ! class_exists( 'ActionScheduler_Store' ) || ! class_exists( 'ActionScheduler' ) ) {
        return;
    }
    if ( ! class_exists( 'Brikpanel_BrikControl_Runner' ) ) {
        return;
    }
    Brikpanel_BrikControl_Runner::cleanup_duplicate_scans();
    update_option( 'brikpanel_brikcontrol_scan_pileup_cleaned', '1', true );
}, 30 );
