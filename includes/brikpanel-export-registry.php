<?php
/**
 * BrikPanel — Settings export registry.
 *
 * WHY THIS FILE EXISTS
 *
 * Import / Export used to derive the list of exportable options from
 * `brikpanel_settings_fields()`, treating each field's `id` as an option name.
 * That inverted the dependency: a UI structure decided what the data layer
 * knew about itself. Three consequences, all measured in the field before this
 * registry existed:
 *
 *   1. A card that renders its own UI declares a PLACEHOLDER id (there is
 *      nothing for WooCommerce's generic save loop to write), so the real
 *      option behind it was invisible to the export. Six cards, twelve
 *      options, including every top bar setting — an agency cloning a client
 *      store got a file that silently omitted them.
 *   2. A field declared conditionally (`brikpanel_brikmentor_live` is only
 *      added when BrikMentor is NOT installed) disappeared from the list on
 *      exactly the sites where its value mattered most.
 *   3. Nothing could tell "not exportable because it is a secret" from
 *      "not exportable because nobody thought about it". Both looked the same:
 *      absent.
 *
 * So the registry is the authority and the field walk is demoted to one
 * contributor among several. EVERY `brikpanel_*` option the plugin persists is
 * classified here or by its own module — including the ones that must never
 * travel — and `tools/export-coverage-audit.php` fails the build when a key
 * appears in the code without a class.
 *
 * HOW A MODULE REGISTERS ITS OWN KEYS
 *
 *   add_filter( 'brikpanel_exportable_option_keys', 'my_module_export_keys' );
 *   function my_module_export_keys( $map ) {
 *       $map['brikpanel_my_setting'] = [
 *           'class'    => 'portable',
 *           'group'    => 'my-module',
 *           'sanitize' => 'my_module_sanitize_import',
 *           'default'  => [],
 *           'clear'    => 'delete',
 *       ];
 *       return $map;
 *   }
 *
 * The call MUST sit at file scope and ABOVE any module gate or early return.
 * A registration that only runs while the module is switched on cannot answer
 * for the module while it is switched off, which is precisely when an import
 * is about to overwrite it. The audit enforces this (rule D).
 *
 * ENTRY FIELDS
 *
 *   class     portable | secret | site | internal   (required)
 *             portable — a merchant choice that should travel.
 *             secret   — credentials/PII. Never written into the file.
 *             site     — a merchant choice that is meaningless or harmful on
 *                        another site (local user ids, attachment ids,
 *                        spreadsheet ids, blog ids).
 *             internal — caches, counters, migration flags, queues.
 *   group     Section slug, used to tell a merchant which sections an older
 *             file predates. See brikpanel_export_group_slugs().
 *   type      Legacy WooCommerce field type. Only used to pick a fallback
 *             sanitizer and to read `format_version: 1` files.
 *   sanitize  Name of a callable that cleans an imported value. Resolved late
 *             with is_callable(), so the owning module may be unloaded at
 *             registration time. Preferred over `type` when both are present.
 *   default   The value the CODE falls back to when the row is absent — which
 *             is not always the value the settings field declares. Audit rule
 *             B proves the two agree for every `clear => delete` key.
 *   clear     What to do when the source site has no row for this key:
 *             delete        — remove the target's row. This is the DEFAULT and
 *                             the semantically exact one: a source with no row
 *                             is reading its code fallback, and a target with
 *                             no row reads the same fallback, because both run
 *                             the same build. No value has to be guessed.
 *             write_default — write `default` instead of deleting, for the few
 *                             keys that must keep a row. Only correct while
 *                             `default` really is the code's fallback, which is
 *                             what audit rule B checks.
 *             never         — leave the target alone. For keys where clearing
 *                             has a blast radius a merchant would not expect
 *                             (the BrikMentor promotion switch, the master
 *                             on/off switch) and for third-party keys we cannot
 *                             reason about.
 *   after     Name of a callable to run once after an import that touched this
 *             key, for side effects `update_option()` does not fire on its own.
 *             The clear path needs this more than the apply path: most modules
 *             hook `update_option_X` (which a plain update_option does fire)
 *             but almost none hook `delete_option_X`.
 *   rewrite_urls  true when the value can carry an absolute URL pointing at the
 *             source site, which the import repoints at the target. Only URLs
 *             whose host matches the exporting site are touched.
 *
 * @package BrikPanel
 * @since   3.3.14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload format written by this build.
 *
 * 1 — options only; a key with no row was simply absent and the target kept
 *     whatever it had.
 * 2 — adds `defaults` (keys that are at their default on the source), `groups`
 *     and `user_layouts`. A version 1 file still imports; see the import
 *     handler for how the two are told apart.
 */
const BRIKPANEL_EXPORT_FORMAT_VERSION = 2;

/**
 * Fill in the fields an entry left out.
 *
 * `clear` defaults to `delete` because that is the only strategy that needs no
 * knowledge at all: it reproduces "the source never saved this" exactly, on a
 * target running the same build. `write_default` has to be asked for, and has
 * to be right.
 *
 * @param array $entry Raw entry.
 * @return array
 */
function brikpanel_export_entry_defaults( array $entry ) {
	return $entry + [
		'class'        => 'portable',
		'group'        => 'general',
		'type'         => '',
		'sanitize'     => '',
		'default'      => null,
		'clear'        => 'delete',
		'after'        => '',
		'rewrite_urls' => false,
	];
}

/**
 * The distinct groups the portable half of the registry covers.
 *
 * A group is a settings SECTION slug, the same vocabulary
 * `brikpanel_settings_get_sections()` already uses, so the import screen can
 * name a missing section with a label the merchant has seen on the settings
 * tab rather than an invented one. Labels are resolved there, not here: this
 * file is meant to stay callable with nothing but PHP loaded.
 *
 * Written into the export file so that a later build can tell a merchant
 * exactly which sections their older file predates, instead of the current
 * silence.
 *
 * @return string[]
 */
function brikpanel_export_group_slugs() {
	$groups = [];
	foreach ( brikpanel_export_portable_entries() as $entry ) {
		$groups[ $entry['group'] ] = true;
	}
	return array_keys( $groups );
}

/**
 * Field ids that name a settings CARD, never an option.
 *
 * These render their own UI for values stored under other names, so the
 * settings-field walk must skip them instead of exporting an option that can
 * never exist. Cards that own exactly one option do not appear here: they were
 * renamed so their field id IS the option name, the way the WhatsApp
 * per-status card has always done it.
 *
 * @return string[]
 */
function brikpanel_export_placeholder_field_ids() {
	return [
		'brikpanel_topbar_items_field',
		'brikpanel_dashboard_widget_access_field',
		'brikpanel_dashboard_sections',
		'brikpanel_nav_customizer_field',
		'brikpanel_pe_visible_sections_field',
		'brikpanel_cartab_popup_i18n_field',
		'brikpanel_brikmentor_promo_field',
		'brikpanel_dev_docs_field',
		'brikpanel_ea_settings_field',
	];
}

/**
 * Per-user admin layouts that travel with a settings file.
 *
 * These are preferences of a PERSON, not of a site, so they are read from the
 * account that exports and written to the account that imports — never to
 * anybody else. Each entry names the storage kind because the two are not
 * interchangeable on multisite: `option` goes through update_user_option(),
 * which prefixes the blog id, while `meta` is network-wide.
 *
 * @return array<string, array{kind:string, sanitize:string}>
 */
function brikpanel_export_user_layout_keys() {
	return [
		'brikpanel_products_visible_columns' => [
			'kind'     => 'meta',
			'sanitize' => 'brikpanel_products_sanitize_import_columns',
		],
		'brikpanel_cartab_columns'           => [
			'kind'     => 'meta',
			'sanitize' => 'brikpanel_cartab_sanitize_import_columns',
		],
		'brikpanel_orders_row_columns'       => [
			'kind'     => 'option',
			'sanitize' => 'brikpanel_orders_sanitize_import_row_columns',
		],
		'brikpanel_order_side_boxes'         => [
			'kind'     => 'option',
			'sanitize' => 'brikpanel_order_sanitize_import_side_boxes',
		],
	];
}

/**
 * Keys owned by the plugin core rather than by any one module.
 *
 * Migrations, caches, counters, one-shot flags and the handful of read-only
 * escape hatches that have no UI at all. None of them travel; they are listed
 * so the audit can tell "classified as internal" from "forgotten".
 *
 * @return array<string, array>
 */
function brikpanel_export_core_table() {
	$internal = [
		// Install / upgrade bookkeeping.
		'brikpanel_db_version',
		'brikpanel_activated_at',
		'brikpanel_recurring_engine_since',
		'brikpanel_cron_reconciled',
		'brikpanel_debug_marker',
		// One-shot migrations and their cursors. Clearing one of these would
		// re-run a backfill over the whole catalogue.
		'brikpanel_cogs_default_applied',
		'brikpanel_cogs_unified_native',
		'brikpanel_cos_legacy_migrated',
		'brikpanel_meta_fanout_refresh_done',
		'brikpanel_native_cogs_backfilled',
		'brikpanel_payment_fees_default_applied',
		'brikpanel_shipping_cost_default_applied',
		'brikpanel_var_stock_fix_cursor',
		'brikpanel_var_stock_fix_done',
		'brikpanel_var_stock_fix2_cursor',
		'brikpanel_var_stock_fix2_done',
		'brikpanel_cartab_credit_dedupe_done',
		'brikpanel_cartab_failed_recovery_repair_done',
		'brikpanel_cartab_failed_recovery_repair_stats',
		'brikpanel_cartab_zeroed_repair_done',
		'brikpanel_cartab_zeroed_repair_stats',
		'brikpanel_qe_field_order_migrated_v1',
		'brikpanel_pe_metaboxes_merged',
		'brikpanel_pe_selected_metaboxes',
		'brikpanel_nav_index_transients_cleared',
		'brikpanel_brikcontrol_scan_pileup_cleaned',
		'brikpanel_size_color_migrated_v1',
		// Caches and counters.
		'brikpanel_data_cache_ver',
		'brikpanel_data_cache_version',
		'brikpanel_ca_cache_ver',
		'brikpanel_order_notify_latest_id',
		'brikpanel_completed_orders_count',
		'brikpanel_last_new_order',
		'brikpanel_shopify_orders',
		// Notice / nag state. A dismissal is a moment in one admin's life, not
		// a configuration choice, and cloning it would silence a notice the
		// target's owner has never seen.
		'brikpanel_review_dismissed',
		'brikpanel_review_snooze_until',
		'brikpanel_newsletter_card_dismissed',
		'brikpanel_bm_live_card_dismissed',
		'brikpanel_ea_subscribed',
		'brikpanel_ea_outbox',
		'brikpanel_ea_last_flush',
		'brikpanel_ea_lead_id',
		'brikpanel_brikmentor_claim_id',
		// Store health results and their working state.
		'brikpanel_brikcontrol_results',
		'brikpanel_brikcontrol_progress',
		'brikpanel_brikcontrol_image_partial',
		'brikpanel_cost_dupes_backup',
		'brikpanel_bot_traffic_backup',
		'brikpanel_cart_count_cleanup_backup',
		// Read-only escape hatches: nothing in the plugin ever writes these,
		// they exist to be set by hand on one site for one reason.
		'brikpanel_modern_segments',
		'brikpanel_dashboard_wp_widgets_position',
		'brikpanel_brikmentor_url',
		'brikpanel_brikmentor_checkout_url',
	];

	$map = [];
	foreach ( $internal as $key ) {
		$map[ $key ] = [ 'class' => 'internal' ];
	}

	// Per-user preferences that are NOT layouts.
	//
	// The difference matters: a column arrangement is work somebody did and is
	// worth handing to the next store (see brikpanel_export_user_layout_keys()),
	// while these are the state of one person at one moment — a collapsed
	// sidebar, a date range, a dismissed finding, a WhatsApp opt-in. Cloning
	// them would put one admin's session onto another admin's screen.
	foreach ( [
		'brikpanel_sidebar_hidden',
		'brikpanel_dash_range',
		'brikpanel_whatsapp_optin',
		'brikpanel_brikcontrol_dismissed',
	] as $key ) {
		$map[ $key ] = [ 'class' => 'internal' ];
	}

	// PII captured by the newsletter card. Never leaves the site it was typed on.
	$map['brikpanel_ea_lead'] = [ 'class' => 'secret' ];

	// Multisite network grant, addressed by blog id. Meaningless elsewhere, and
	// it is a SITE option, so it is not even in the same table as the rest.
	$map['brikpanel_network_access'] = [ 'class' => 'site' ];

	return $map;
}

/**
 * The authoritative registry: every classified key, module contributions
 * included.
 *
 * @return array<string, array>
 */
function brikpanel_export_registry() {
	$map = brikpanel_export_core_table();

	/**
	 * Filter the option keys BrikPanel knows how to classify.
	 *
	 * Modules add their own keys here — portable ones AND the secrets and
	 * site-specific values they own, so the coverage audit can prove every key
	 * was considered.
	 *
	 * @since 2.8.6 (registry semantics since 3.3.14)
	 *
	 * @param array $map [ option_key => entry ]. See the file docblock.
	 */
	$map = apply_filters( 'brikpanel_exportable_option_keys', $map );

	$out = [];
	foreach ( $map as $key => $entry ) {
		if ( ! is_string( $key ) || ! is_array( $entry ) ) {
			continue;
		}
		$entry = brikpanel_export_entry_defaults( $entry );
		if ( ! in_array( $entry['class'], [ 'portable', 'secret', 'site', 'internal' ], true ) ) {
			// An unknown class must not be guessed into `portable`: that is the
			// one direction where being wrong writes a secret into a file a
			// merchant emails around.
			$entry['class'] = 'internal';
		}
		$out[ $key ] = $entry;
	}

	return $out;
}

/**
 * Just the keys that travel, in registry order.
 *
 * Registry order is the apply order, which is why `after` dependencies are
 * expressed by placing the dependent registration later rather than by a
 * separate sort: custom order statuses must exist before the per-status email
 * settings that name them.
 *
 * @return array<string, array>
 */
function brikpanel_export_portable_entries() {
	$out = [];
	foreach ( brikpanel_export_registry() as $key => $entry ) {
		if ( 'portable' === $entry['class'] ) {
			$out[ $key ] = $entry;
		}
	}
	return $out;
}

/**
 * Every classified key with its class — the audit's ground truth.
 *
 * @return array<string, string>
 */
function brikpanel_export_classified_keys() {
	$out = [];
	foreach ( brikpanel_export_registry() as $key => $entry ) {
		$out[ $key ] = $entry['class'];
	}
	foreach ( brikpanel_export_user_layout_keys() as $key => $_meta ) {
		$out[ $key ] = 'user';
	}
	return $out;
}
