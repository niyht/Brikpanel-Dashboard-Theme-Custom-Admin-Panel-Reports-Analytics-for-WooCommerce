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

require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-storage.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-image-plugins.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/checks/abstract-class-brikpanel-brikcontrol-check.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/checks/class-brikpanel-brikcontrol-image-health-check.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-registry.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol-runner.php';
require_once BRIKPANEL_PATH . 'front-end/brikcontrol/class-brikpanel-brikcontrol.php';

// Default check registration. Other plugins can extend by hooking the
// `brikpanel_brikcontrol_checks` filter or calling
// Brikpanel_BrikControl_Registry::register() before runner registration.
Brikpanel_BrikControl_Registry::register( new Brikpanel_BrikControl_Image_Health_Check() );

// Boot the public façade (admin menu + AJAX).
Brikpanel_BrikControl::instance();

// Register Action Scheduler handlers + recurring schedule. Runs on init at
// priority 20 so Brikpanel_Cron + WC's AS bootstrap have completed.
add_action( 'init', [ 'Brikpanel_BrikControl_Runner', 'register' ], 20 );
