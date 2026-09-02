<?php
/**
 * BrikPanel — option cache priming.
 *
 * WordPress reads a non-autoloaded option with one dedicated
 * `SELECT option_value FROM wp_options WHERE option_name = '…'`, and on a site
 * with no persistent object cache drop-in that query runs again on every
 * single request. An option that does not exist at all costs the same, because
 * the `notoptions` cache that remembers the miss is per-request only.
 *
 * BrikPanel hit both halves of that at once. Module gates are read at FILE
 * SCOPE in brikpanel.php (the modules call get_option() while being included,
 * before any hook fires), and brikpanel_settings_fields_for_section() only
 * persists the settings section the admin happened to have open, so on a real
 * store roughly two dozen of those gate keys have no row at all. Measured on
 * the reference install: 15 BrikPanel keys cost a query on every storefront
 * request and 49 on every wp-admin request, before a single page of BrikPanel
 * UI had rendered.
 *
 * wp_prime_option_caches() collapses the whole set into ONE query and — the
 * part that fixes the second half — also seeds `notoptions` for the keys that
 * have no row, so the misses stop costing anything too. Keys that are already
 * autoloaded are dropped from the query for free: the function checks
 * `alloptions` first.
 *
 * @package BrikPanel
 * @since   3.2.70
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option keys read on EVERY request, storefront included.
 *
 * Two groups. First, the module gates evaluated at file scope in brikpanel.php
 * — those run on the storefront whether or not the module does anything. They
 * are listed even when they happen to be autoloaded on the reference install,
 * because whether a given store has ever saved that settings section is
 * exactly what varies between installs, and priming an autoloaded key is free.
 *
 * Second, the keys measured hitting the database on every front-end request.
 * The Google Sheets block is the bulk of it: the sync module reads its whole
 * schedule configuration at file scope to decide whether to register hooks.
 *
 * @return string[]
 */
function brikpanel_prime_keys_always() {
	return array(
		// Master / access gates.
		'brikpanel_master_enabled',
		'brikpanel_access_personal_mode',
		'brikpanel_modern_navigation',

		// Login module (front-end/login/brikpanel-login.php, file scope).
		'brikpanel_modern_login',
		'brikpanel_login_force_native',
		'brikpanel_login_hide_footer_credit',

		// Product editor / variation gallery gates.
		'brikpanel_simple_product_editor',
		'brikpanel_variation_gallery_enabled',

		// Module gates read at file scope.
		'brikpanel_brikcontrol_enabled',
		'brikpanel_gs_module_enabled',
		'brikpanel_ads_module_enabled',
		'brikpanel_cartab_enabled',
		'brikpanel_vendors_enabled',
		'brikpanel_cart_share_enabled',
		'brikpanel_cart_share_frontend_button',

		// Google Sheets schedule configuration, read at file scope on every
		// request to decide which sync hooks to register.
		'brikpanel_gs_tokens',
		'brikpanel_gs_orders_enabled',
		'brikpanel_gs_orders_realtime',
		'brikpanel_gs_orders_pull_enabled',
		'brikpanel_gs_orders_pull_interval',
		'brikpanel_gs_orders_bulk_interval',
		'brikpanel_gs_products_enabled',
		'brikpanel_gs_products_pull_enabled',
		'brikpanel_gs_expenses_enabled',
		'brikpanel_gs_expenses_pull_enabled',
		'brikpanel_gs_expenses_pull_interval',
		'brikpanel_gs_reports_enabled',

		// Ad platforms.
		'brikpanel_ads_tokens',

		// Front-end tracking emitter.
		'brikpanel_frontend_tracking',
		'brikpanel_tracking_require_consent',

		// Measured hitting the database on a plain storefront request, on a
		// store that has never saved the matching settings section. Both
		// modules load outside the is_admin() gate on purpose (the Action
		// Scheduler worker and the checkout hooks need them), so their reads
		// are storefront reads.
		'brikpanel_custom_order_statuses',
		'brikpanel_live_ping_interval',
		// Read by the abandoned-cart sweep, which runs under wp-cron.php and the
		// Action Scheduler queue runner — neither of them an admin request.
		'brikpanel_cartab_abandon_minutes',

		// One-shot migration guards evaluated on plugins_loaded / init, i.e.
		// on EVERY request including the storefront, before the marker they
		// gate has ever been written. Once written they are autoloaded and
		// wp_prime_option_caches() drops them from the query for free, so
		// listing them only covers the window where they cost something.
		'brikpanel_db_version',
		'brikpanel_cogs_default_applied',
		'brikpanel_shipping_cost_default_applied',
		'brikpanel_cartab_failed_recovery_repair_done',
		'brikpanel_cartab_zeroed_repair_done',
		'brikpanel_cos_legacy_migrated',
		// init:30, and front-end/brikcontrol/brikpanel-brikcontrol.php is
		// required OUTSIDE the is_admin() gate — this was in the admin list.
		'brikpanel_brikcontrol_scan_pileup_cleaned',

		// Order-status buckets. Read through BRIKPANEL_PAID_STATUSES_OPTION /
		// _REFUNDED_ / the default-status constant by every revenue query
		// (dashboard cards, topbar stats, coupon reports), and those run on
		// admin-ajax AND on the wc-analytics REST routes. REST is not an admin
		// request, so the admin list does not cover it: measured 1-2 SELECTs
		// per poll on a store that had never saved the Orders settings section.
		// Small, read-mostly, and already invalidated by update_option_ hooks.
		'brikpanel_paid_statuses',
		'brikpanel_refunded_statuses',
		'brikpanel_default_order_status',
	);
}

/**
 * Additional keys every wp-admin request touches.
 *
 * Measured, not guessed: each of these produced a real wp_options SELECT on
 * every one of the sampled admin screens. The one-shot migration markers are
 * the most wasteful of the set — the comment at their call sites says the
 * marker "avoids paying for the no-op on every request", but because the
 * marker itself was not autoloaded it WAS the cost it claimed to avoid.
 *
 * Deliberately absent, and both for the same reason — the payload is large and
 * the read is screen-dependent, so priming would move bytes onto screens that
 * never ask for them:
 *
 *   `brikpanel_nav_index_<uid>`      ~30 KB, read once inside the palette AJAX.
 *   `brikpanel_brikcontrol_results`  3 KB and up, grows with the number of
 *                                    health checks; measured absent from the
 *                                    Dashboard and product-list reads.
 *
 * Also absent on purpose: the appearance keys are read on `login_head` too, but
 * wp-login.php is not an admin request. Priming them for every storefront hit
 * to save a handful of queries on the login screen is the wrong trade.
 *
 * @return string[]
 */
function brikpanel_prime_keys_admin() {
	return array(
		// One-shot migration / backfill markers.
		'brikpanel_native_cogs_backfilled',
		'brikpanel_cogs_unified_native',
		'brikpanel_qe_field_order_migrated_v1',
		'brikpanel_qe_field_backfilled_cogs',
		'brikpanel_pe_metaboxes_merged',
		'brikpanel_pe_section_backfilled_cogs',
		'brikpanel_pe_section_backfilled_brand',
		'brikpanel_nav_index_transients_cleared',
		'brikpanel_var_stock_fix2_done',
		'brikpanel_var_stock_fix2_cursor',

		// Review / early-access nags.
		'brikpanel_activated_at',
		'brikpanel_completed_orders_count',
		'brikpanel_review_dismissed',
		'brikpanel_review_snooze_until',
		'brikpanel_ea_outbox',
		'brikpanel_ea_last_flush',
		'brikpanel_ea_subscribed',
		'brikpanel_ea_card_dismissed',
		'brikpanel_ea_dismissed_upto',
		'brikpanel_bm_live_card_dismissed',

		// Topbar rendering (in_admin_header on every admin screen).
		'brikpanel_topbar_hidden_items',
		'brikpanel_topbar_create_hidden_items',
		'brikpanel_topbar_custom_link_label',
		'brikpanel_topbar_custom_link_url',
		'brikpanel_topbar_item_audience',
		'brikpanel_topbar_item_hide_roles',

		// Profit: gates the shipping-cost deduction, the per-order Shipping cost
		// metabox, the Expenses breakdown row AND the dashboard transient key, so
		// it is read several times per admin request. Admin-only on purpose:
		// every read point is wp-admin or admin-ajax, so priming it in the
		// always list would move bytes onto storefront hits for nothing.
		'brikpanel_shipping_cost_enabled',

		// Profit: gates the gateway-fee component, its Expenses breakdown row,
		// the Expenses page toggle AND the dashboard transient key. Read on the
		// same admin-only paths as the shipping-cost gate above.
		'brikpanel_payment_fees_enabled',

		// Dashboard widget access + layout.
		'brikpanel_dashboard_widget_audience',
		'brikpanel_dashboard_widget_hide_roles',
		'brikpanel_dashboard_visible_sections',
		'brikpanel_dashboard_section_order',
		'brikpanel_dashboard_wp_widgets_position',

		// Navigation customizer + appearance, read during menu/header render.
		'brikpanel_nav_config',
		'brikpanel_excluded_roles',
		'brikpanel_excluded_user_ids',

		// Order notification popup/sound, read in admin_enqueue_scripts.
		'brikpanel_order_notify_popup',
		'brikpanel_order_notify_sound',
		'brikpanel_order_notify_confetti',
		'brikpanel_order_notify_volume',
		'brikpanel_order_notify_interval',

		// Ad platform backfill flags, read on every admin request.
		'brikpanel_ads_needs_backfill_google_ads',
		'brikpanel_ads_needs_backfill_meta_ads',

		// Misc screen gates measured hitting the DB.
		'brikpanel_orders_enhancements',
		'brikpanel_modern_segments',
		'brikpanel_whatsapp_order_message',
		'brikpanel_whatsapp_order_status_messages',

		// -------------------------------------------------------------------
		// Read on every admin request from a HOOK CALLBACK rather than at file
		// scope, which is why the first pass missed all of them: the audit
		// script only looked at file scope. Measured with a SAVEQUERIES probe
		// on a store that has never saved these settings sections — 11 to 31
		// dedicated SELECTs per admin screen, on top of the batched prime.
		// -------------------------------------------------------------------

		// Access control: read from the pre_option_* filters registered for
		// every gated option, so they run before almost anything else.
		'brikpanel_access_disabled_users',
		'brikpanel_access_disabled_roles',
		'brikpanel_access_disable_for_admins',
		'brikpanel_master_switch_roles',
		'brikpanel_settings_admins_only',
		'brikpanel_hide_screen_options',
		'brikpanel_hide_screen_options_non_admins',

		// Notice suppression, admin_init on every screen.
		'brikpanel_hide_foreign_notices',
		'brikpanel_hide_error_notices',

		// Screen gates evaluated in module constructors / in_admin_header.
		'brikpanel_modern_dashboard',
		'brikpanel_modern_products_list',
		'brikpanel_modern_coupons',
		'brikpanel_modern_order_edit',
		'brikpanel_native_menu_styled',
		'brikpanel_dashboard_topbar',
		'brikpanel_dashboard_profit_fields',
		'brikpanel_dashboard_wp_widgets',

		// Appearance + branding, printed from admin_head on every screen.
		'brikpanel_ui_font',
		'brikpanel_ui_primary_color',
		'brikpanel_custom_css',
		'brikpanel_brand_logo_id',
		'brikpanel_brand_logo_url',
		'brikpanel_nav_icon_style',

		// Command palette source gates, read while building the palette config.
		'brikpanel_search_orders',
		'brikpanel_search_products',
		'brikpanel_search_customers',
		'brikpanel_search_navigation',

		// Orders / WhatsApp column + overview gates.
		'brikpanel_order_fields_keys',
		'brikpanel_order_fields_manual',
		'brikpanel_orders_overview_hidden_roles',
		'brikpanel_whatsapp_enabled',
		'brikpanel_whatsapp_hidden_roles',

		// Misc.
		'brikpanel_ads_cache_version',
		'brikpanel_brikmentor_live',
	);
}

/**
 * Keys pinned to autoload=on by brikpanel_apply_option_autoload_policy().
 *
 * Scope is deliberately narrow: write-once markers only. Everything else is
 * already covered by the batched prime above, so autoloading it would buy
 * nothing while permanently enlarging `alloptions` — and on a store that DOES
 * run Redis, autoloading a write-hot key means invalidating the entire
 * alloptions blob every time it changes.
 *
 * Values here are tiny ('1' / 'yes') and are written exactly once, by a
 * migration, so they can never grow or churn.
 *
 * @return array<string,bool>
 */
function brikpanel_option_autoload_map() {
	return array(
		'brikpanel_native_cogs_backfilled'          => true,
		'brikpanel_cogs_unified_native'             => true,
		'brikpanel_qe_field_order_migrated_v1'      => true,
		'brikpanel_qe_field_backfilled_cogs'        => true,
		'brikpanel_pe_metaboxes_merged'             => true,
		'brikpanel_pe_section_backfilled_cogs'      => true,
		'brikpanel_pe_section_backfilled_brand'     => true,
		'brikpanel_nav_index_transients_cleared'    => true,
		'brikpanel_brikcontrol_scan_pileup_cleaned' => true,
		'brikpanel_activated_at'                    => true,
		'brikpanel_review_dismissed'                => true,
		'brikpanel_ea_subscribed'                   => true,
	);
}

/**
 * Keys that must NEVER be autoloaded, with the reason.
 *
 * Enforced by tools/option-prime-audit.php, which fails if any of these ever
 * appears in brikpanel_option_autoload_map().
 *
 * @return array<string,string>
 */
function brikpanel_option_autoload_denylist() {
	return array(
		'brikpanel_gs_tokens'                           => 'OAuth credentials',
		'brikpanel_ads_tokens'                          => 'OAuth credentials',
		'brikpanel_ea_lead'                             => 'PII payload',
		'brikpanel_gs_error_log'                        => 'log, grows without bound',
		'brikpanel_ads_error_log'                       => 'log, grows without bound',
		'brikpanel_cartab_failed_recovery_repair_stats' => 'log',
		'brikpanel_brikcontrol_results'                 => 'multi-KB, rewritten every scan',
		'brikpanel_gs_orders_custom_fields'             => 'multi-KB, unbounded',
		'brikpanel_gs_expenses_state'                   => 'multi-KB and write-hot',
		'brikpanel_gs_products_push_queue'              => 'queue, write-hot',
		'brikpanel_ea_outbox'                           => 'queue, write-hot',
		'brikpanel_nav_config'                          => 'grows with the menu',
		'brikpanel_pe_section_order'                    => 'unbounded, screen-scoped',
		'brikpanel_pe_visible_sections'                 => 'unbounded, screen-scoped',
		'brikpanel_qe_field_order'                      => 'unbounded, screen-scoped',
		'brikpanel_qe_visible_fields'                   => 'unbounded, screen-scoped',
		'brikpanel_dashboard_visible_sections'          => 'unbounded, screen-scoped',
		'brikpanel_dashboard_section_order'             => 'unbounded, screen-scoped',
		'brikpanel_excluded_user_ids'                   => 'unbounded',
		'brikpanel_data_cache_ver'                      => 'write-hot: bumped on every order',
		'brikpanel_ca_cache_ver'                        => 'write-hot',
		'brikpanel_ads_cache_version'                   => 'write-hot',
		'brikpanel_completed_orders_count'              => 'write-hot',
		'brikpanel_ea_orders_count'                     => 'write-hot',
		'brikpanel_ea_last_flush'                       => 'write-hot',
		'brikpanel_order_notify_latest_id'              => 'write-hot',
		'brikpanel_brikcontrol_progress'                => 'write-hot during a scan',
	);
}

/**
 * Warm the option cache for every key BrikPanel reads on a hot path.
 *
 * Called once, at file scope in brikpanel.php, BEFORE the first
 * brikpanel_require() of a module — the modules read their gate options while
 * being included, so a plugins_loaded callback would be far too late.
 *
 * Admin requests merge both lists into a SINGLE call, so they still pay one
 * query rather than two.
 *
 * @return void
 */
function brikpanel_prime_option_caches() {
	if ( ! function_exists( 'wp_prime_option_caches' ) ) {
		return; // WP < 6.4: degrade to the previous behaviour exactly.
	}

	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$keys = brikpanel_prime_keys_always();
	if ( is_admin() ) {
		$keys = array_merge( $keys, brikpanel_prime_keys_admin() );
	}

	wp_prime_option_caches( $keys );
}

/**
 * update_option() that resolves the autoload flag from the central registry
 * instead of hardcoding it at the call site.
 *
 * Keys outside the registry keep BrikPanel's default of autoload=off: the
 * plugin owns ~200 options and most of them have no business in alloptions.
 *
 * @param string $option
 * @param mixed  $value
 * @return bool
 */
function brikpanel_update_option( $option, $value ) {
	$map = brikpanel_option_autoload_map();
	return update_option( $option, $value, isset( $map[ $option ] ) );
}

/**
 * Re-assert the autoload flag for every key in the registry.
 *
 * Runs on each version bump, which makes it self-healing: a call site that
 * writes one of these keys with an explicit `false` drifts it back to off, and
 * the next release pulls it straight again. Keys with no row are skipped by
 * wp_set_option_autoload_values() itself.
 *
 * @return void
 */
function brikpanel_apply_option_autoload_policy() {
	if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
		return; // WP < 6.4.
	}

	$values = array();
	foreach ( brikpanel_option_autoload_map() as $key => $_on ) {
		$values[ $key ] = 'on';
	}

	if ( $values ) {
		wp_set_option_autoload_values( $values );
	}
}
