<?php
/**
 * Custom order statuses.
 *
 * Lets the store owner create their own WooCommerce order statuses (label +
 * colour) from a settings screen, and pick which status new orders should
 * start in — without touching code. Works for both simple and variable
 * products because an order status lives on the order, not the product.
 *
 * Loaded globally (front + admin) from brikpanel.php so the statuses register
 * on every request, not only inside wp-admin. The settings-screen hooks are
 * cheap add_filter() calls and only do real work on the BrikPanel WC tab.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BRIKPANEL_CUSTOM_STATUSES_OPTION = 'brikpanel_custom_order_statuses';
const BRIKPANEL_DEFAULT_STATUS_OPTION  = 'brikpanel_default_order_status';

/**
 * Status slugs we must never let a custom status overwrite: every WooCommerce
 * core status plus the WordPress system statuses. Used both when minting a slug
 * and when validating the default.
 *
 * The former hard-coded BrikPanel statuses "Return Draft" (return-draft) and
 * "Change" (change) are deliberately NOT reserved any more: they are now
 * ordinary managed rows in the editable list (seeded once by
 * brikpanel_cos_migrate_legacy_statuses), so the reader must be allowed to
 * surface and register them instead of skipping them as reserved.
 *
 * @return string[] Slugs without the `wc-` prefix.
 */
function brikpanel_cos_reserved_slugs() {
	return [
		'pending', 'processing', 'on-hold', 'completed', 'cancelled',
		'refunded', 'failed', 'checkout-draft',
		'trash', 'draft', 'auto-draft', 'publish', 'future', 'private', 'inherit',
	];
}

/**
 * Read and normalise the saved custom statuses.
 *
 * Guarantees: slug is a sanitised key, never reserved, never longer than 17
 * chars (the WP `post_status` column is VARCHAR(20) and we prepend `wc-`),
 * label is plain text, colour is a valid hex. Cached per-request.
 *
 * The per-request cache is invalidated whenever the option changes (see the
 * reset hooks below). This matters because WooCommerce settings are saved and
 * the page re-rendered within the *same* request (no redirect), so without the
 * reset the screen would show the pre-save list until a manual refresh.
 *
 * @param bool $reset Internal — flush the cache (called on option change).
 * @return array<string,array{label:string,color:string}> slug => data.
 */
function brikpanel_get_custom_order_statuses( $reset = false ) {
	static $cache = null;
	if ( $reset ) {
		$cache = null;
		return [];
	}
	if ( null !== $cache ) {
		return $cache;
	}

	$raw      = get_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, [] );
	$reserved = brikpanel_cos_reserved_slugs();
	$out      = [];

	if ( is_array( $raw ) ) {
		foreach ( $raw as $slug => $data ) {
			$slug = sanitize_key( (string) $slug );
			$slug = substr( $slug, 0, 17 );
			if ( '' === $slug || in_array( $slug, $reserved, true ) || isset( $out[ $slug ] ) ) {
				continue;
			}
			$label = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}
			$color = isset( $data['color'] ) ? sanitize_hex_color( (string) $data['color'] ) : '';
			if ( ! $color ) {
				$color = '#646970';
			}
			$out[ $slug ] = [
				'label' => $label,
				'color' => $color,
			];
		}
	}

	$cache = $out;
	return $cache;
}

/**
 * Flush the per-request status cache the moment the option changes, so any
 * later read in the same request (the post-save settings render, the
 * wc_order_statuses filter, the colour CSS) sees the new value immediately.
 */
$brikpanel_cos_reset = static function () {
	brikpanel_get_custom_order_statuses( true );
};
add_action( 'add_option_' . BRIKPANEL_CUSTOM_STATUSES_OPTION, $brikpanel_cos_reset );
add_action( 'update_option_' . BRIKPANEL_CUSTOM_STATUSES_OPTION, $brikpanel_cos_reset );
add_action( 'delete_option_' . BRIKPANEL_CUSTOM_STATUSES_OPTION, $brikpanel_cos_reset );
unset( $brikpanel_cos_reset );

/**
 * Build a unique, length-safe slug for a custom status from its label.
 *
 * @param string   $label Source label.
 * @param string[] $taken Slugs already used (reserved + this batch).
 * @return string Slug (<= 17 chars), never empty.
 */
function brikpanel_cos_make_slug( $label, array $taken ) {
	$base = sanitize_title( $label );
	$base = $base ? str_replace( '_', '-', $base ) : 'status';
	$base = substr( $base, 0, 17 );
	$base = trim( $base, '-' );
	if ( '' === $base ) {
		$base = 'status';
	}

	$slug = $base;
	$i    = 2;
	while ( in_array( $slug, $taken, true ) ) {
		$suffix = '-' . $i;
		$slug   = substr( $base, 0, 17 - strlen( $suffix ) ) . $suffix;
		$i++;
	}
	return $slug;
}

/**
 * Convert a #rrggbb hex into an [r, g, b] int triplet for rgba() tints.
 *
 * @param string $hex Validated hex colour.
 * @return array{0:int,1:int,2:int}
 */
function brikpanel_cos_hex_rgb( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) ) {
		return [ 100, 105, 112 ]; // #646970 fallback.
	}
	return [
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) ),
	];
}

/**
 * One-time migration: fold the two legacy hard-coded BrikPanel order statuses
 * — "Return Draft" (return-draft) and "Change" (change) — into the editable
 * custom-status list so merchants can recolour, keep or remove them from the
 * Order statuses settings screen exactly like a status they create themselves.
 *
 * Earlier releases registered these two unconditionally with no off switch,
 * leaving every store with statuses it never asked for. Existing installs keep
 * them once on upgrade (so orders already parked in those statuses, and the
 * dashboard's "Return Draft" returns bucket, keep working) but can now delete
 * them; fresh installs start clean because activation pre-sets the migration
 * flag (see brikpanel_provision_site() in the main plugin file).
 *
 * Runs once, guarded by an autoloaded flag. Hooked before the option-driven
 * registration (init, priority 5) so the seeded rows register in the same
 * request — the update_option() call flushes the per-request status cache via
 * its reset hook. Kept on `init` (not the earlier upgrade routine) so the _x()
 * labels resolve after the text domain has loaded (init, priority 1).
 */
function brikpanel_cos_migrate_legacy_statuses() {
	if ( get_option( 'brikpanel_cos_legacy_migrated' ) ) {
		return;
	}

	$existing = get_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, [] );
	if ( ! is_array( $existing ) ) {
		$existing = [];
	}

	// Seed each legacy status only when it is not already present, so a re-run
	// (or a merchant who had recreated one by hand) never clobbers a live row.
	$legacy = [
		'return-draft' => _x( 'Return Draft', 'Order status', 'brikpanel' ),
		'change'       => _x( 'Change', 'Order status', 'brikpanel' ),
	];
	foreach ( $legacy as $slug => $label ) {
		if ( ! isset( $existing[ $slug ] ) ) {
			$existing[ $slug ] = [
				'label' => $label,
				'color' => '#646970',
			];
		}
	}

	update_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, $existing );
	update_option( 'brikpanel_cos_legacy_migrated', 1 );
}
add_action( 'init', 'brikpanel_cos_migrate_legacy_statuses', 4 );

// =============================================================================
// GLOBAL REGISTRATION — runs on every request, not only in admin.
// =============================================================================

/**
 * Register each custom status as a real post status so WordPress accepts it on
 * save and the admin order-list "status" links/counts work.
 */
add_action( 'init', function () {
	foreach ( brikpanel_get_custom_order_statuses() as $slug => $data ) {
		$count = $data['label'] . ' <span class="count">(%s)</span>'; // i18n-ignore: user-defined status label, not a translatable string.
		register_post_status( 'wc-' . $slug, [
			'label'                     => $data['label'], // i18n-ignore: user-defined status label.
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => [
				0          => $count,
				1          => $count,
				'singular' => $count,
				'plural'   => $count,
				'context'  => null,
				'domain'   => null,
			],
		] );
	}
}, 5 );

/**
 * Expose the custom statuses to WooCommerce so they appear in every status
 * dropdown, bulk action, the BrikPanel inline editor, analytics buckets, etc.
 */
add_filter( 'wc_order_statuses', function ( $statuses ) {
	foreach ( brikpanel_get_custom_order_statuses() as $slug => $data ) {
		$statuses[ 'wc-' . $slug ] = $data['label']; // i18n-ignore: user-defined status label.
	}
	return $statuses;
} );

/**
 * Apply the merchant-chosen default status to brand-new orders.
 *
 * WooCommerce reads this filter whenever an order has no explicit status yet
 * (checkout, programmatic wc_create_order, manual "Add order"). We only
 * override when the stored choice is a real, currently-registered status so a
 * deleted custom status can never strand new orders in a missing state.
 */
add_filter( 'woocommerce_default_order_status', function ( $status ) {
	$chosen = (string) get_option( BRIKPANEL_DEFAULT_STATUS_OPTION, '' );
	if ( '' === $chosen ) {
		return $status;
	}
	$valid = array_merge(
		brikpanel_cos_reserved_slugs(),
		array_keys( brikpanel_get_custom_order_statuses() )
	);
	return in_array( $chosen, $valid, true ) ? $chosen : $status;
} );

/**
 * Paint each custom status' badge + dot in its chosen colour. Attached to the
 * WooCommerce admin stylesheet (present on the order list, order edit and the
 * BrikPanel orders screen) plus the BrikPanel inline-status sheet, so both the
 * native and BrikPanel UIs match. Slugs are sanitised keys and colours are
 * validated hex, so the generated CSS is safe.
 */
add_action( 'admin_enqueue_scripts', function () {
	$statuses = brikpanel_get_custom_order_statuses();
	if ( ! $statuses ) {
		return;
	}
	$css = '';
	foreach ( $statuses as $slug => $data ) {
		list( $r, $g, $b ) = brikpanel_cos_hex_rgb( $data['color'] );
		$css .= sprintf(
			// Orders list pill (BrikPanel + native) and the inline-editor dot.
			'.status-dot-%1$s{background:%2$s;}'
			. 'mark.order-status.status-%1$s,.order-status.status-%1$s{background:rgba(%3$d,%4$d,%5$d,.14);color:%2$s;}'
			// Order-edit header badge dot and its status dropdown dot.
			. '.brikpanel-order-header__status-badge.status--%1$s::before,.brikpanel-status-dropdown__item[data-status="%1$s"]::before{background:%2$s;}',
			$slug,
			$data['color'],
			$r,
			$g,
			$b
		);
	}
	// woocommerce_admin_styles is present on every WC admin screen (orders list,
	// order edit, BrikPanel settings), so one injection covers them all. Fall
	// back to the BrikPanel inline-status sheet only if WC's is somehow absent.
	$handle = wp_style_is( 'woocommerce_admin_styles', 'enqueued' ) || wp_style_is( 'woocommerce_admin_styles', 'registered' )
		? 'woocommerce_admin_styles'
		: 'brikpanel_order_status_inline_styles';
	if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
		wp_add_inline_style( $handle, $css );
	}
}, 100 );

// =============================================================================
// SETTINGS SCREEN — section, fields, render, save, assets.
//
// Admin-only: this whole block builds the BrikPanel WC settings tab, so there
// is no reason to register any of it on front-end requests.
// =============================================================================
if ( ! is_admin() ) {
	return;
}

// =============================================================================
// ORDER-LIST BULK ACTIONS — expose custom statuses in the "Bulk actions" menu.
//
// WooCommerce only registers the four native mark_* bulk actions, so custom
// statuses could be assigned one order at a time from inside the order only.
// WooCommerce's bulk handler, however, already accepts ANY "mark_<slug>" whose
// "wc-<slug>" exists in wc_get_order_statuses() (which our wc_order_statuses
// filter above populates) — for both HPOS and classic post storage — so the
// only missing piece is the dropdown entry itself.
//
// Both list tables run their action list through WP core's
// "bulk_actions-{$screen->id}" filter, so we inject one "mark_<slug>" entry per
// custom status there. On the Trash view WooCommerce omits every mark_* action,
// so we skip injecting when none is present (never offer a status change on
// trashed orders). BrikPanel's own order screen rebuilds its Shopify-style
// action buttons straight from these dropdown options, so they surface there too
// with no extra work.

/**
 * Inject a "Change status to <label>" entry for every custom order status into
 * the orders-list bulk-action menu.
 *
 * @param array $actions Existing bulk actions (key => label).
 * @return array
 */
function brikpanel_cos_add_bulk_actions( $actions ) {
	if ( ! is_array( $actions ) ) {
		return $actions;
	}

	// Only offer status changes where WooCommerce itself does. On the Trash view
	// the action list holds untrash/delete only — no mark_* keys — so bail.
	$has_mark = false;
	foreach ( $actions as $key => $unused ) {
		if ( 0 === strpos( (string) $key, 'mark_' ) ) {
			$has_mark = true;
			break;
		}
	}
	if ( ! $has_mark ) {
		return $actions;
	}

	$custom = brikpanel_get_custom_order_statuses();
	if ( ! $custom ) {
		return $actions;
	}

	// Build the custom entries. Keys mirror WooCommerce's native "mark_<slug>"
	// convention so its existing bulk handler processes them unchanged.
	$custom_entries = [];
	foreach ( $custom as $slug => $data ) {
		/* translators: %s: custom order status label. */
		$custom_entries[ 'mark_' . $slug ] = sprintf(
			_x( 'Change status to %s', 'orders bulk action', 'brikpanel' ),
			$data['label'] // i18n-ignore: user-defined status label.
		);
	}

	// Slot the custom entries right after the native mark_* block (i.e. before
	// "trash"/"remove_personal_data"), so status changes stay grouped together.
	$out    = [];
	$placed = false;
	foreach ( $actions as $key => $label ) {
		if ( ! $placed && 0 !== strpos( (string) $key, 'mark_' ) ) {
			foreach ( $custom_entries as $ck => $cl ) {
				$out[ $ck ] = $cl;
			}
			$placed = true;
		}
		$out[ $key ] = $label;
	}
	if ( ! $placed ) {
		foreach ( $custom_entries as $ck => $cl ) {
			$out[ $ck ] = $cl;
		}
	}

	return $out;
}

// Classic post storage list table screen id + HPOS orders table screen ids
// (the "admin_page_" variant covers users who cannot view the WooCommerce menu).
// Priority 20 runs after WooCommerce's own callback so the native mark_* entries
// and "trash" already exist when we position ours.
add_filter( 'bulk_actions-edit-shop_order', 'brikpanel_cos_add_bulk_actions', 20 );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'brikpanel_cos_add_bulk_actions', 20 );
add_filter( 'bulk_actions-admin_page_wc-orders', 'brikpanel_cos_add_bulk_actions', 20 );

// =============================================================================
// MIGRATION — inherit custom statuses from another plugin.
//
// An order's status is stored on the order itself (the HPOS `status` column or
// the legacy post_status), never inside the plugin that defined the status. So
// when a merchant deactivates a third-party custom-status plugin — WPFactory's
// "Additional Custom Order Status", Tyche's "Custom Order Status", a hand-rolled
// snippet — the orders keep their `wc-<slug>` value untouched; WooCommerce just
// stops recognising the slug, so the list shows the raw key and drops the status
// from its filters, bulk actions, tabs and reports.
//
// Re-registering the same slug in BrikPanel restores all of that. This block
// detects those "foreign" statuses (both ones an active plugin still registers
// and orphans that survive only on the orders) and lets the merchant adopt them
// into BrikPanel's own editable list in one click, so the other plugin can be
// removed without a single order ever losing its status. Works identically for
// orders holding simple or variable products — status lives on the order, not
// the product.
// =============================================================================

/**
 * Turn a status slug into a readable fallback label ("kargo-verildi" ->
 * "Kargo Verildi") for orphans whose defining plugin is gone and left no label.
 *
 * @param string $slug Bare slug.
 * @return string
 */
function brikpanel_cos_humanize_slug( $slug ) {
	$text = trim( str_replace( [ '-', '_' ], ' ', (string) $slug ) );
	return '' === $text ? (string) $slug : ucwords( $text );
}

/**
 * Distinct order statuses actually present on real orders, mapped to how many
 * orders carry each. Reads both the HPOS orders table and the legacy post table
 * so the count is correct whichever storage the store runs.
 *
 * Cached in a short transient: the only callers are the settings-screen render
 * and the import handler (both admin-only, infrequent), and we would rather not
 * group-scan the orders table on every settings paint. Busted after an import.
 *
 * @param bool $fresh Skip and refresh the cache.
 * @return array<string,int> Bare slug (no `wc-`) => order count.
 */
function brikpanel_cos_used_order_statuses( $fresh = false ) {
	$key = 'brikpanel_cos_used_statuses';
	if ( ! $fresh ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	global $wpdb;
	$counts = [];

	// HPOS orders table (authoritative when custom order tables are in use).
	$hpos_table = $wpdb->prefix . 'wc_orders';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- validated table name, no user input.
			"SELECT status, COUNT(*) AS c FROM {$hpos_table} WHERE status LIKE 'wc-%' GROUP BY status",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$slug = substr( (string) $row['status'], 3 );
			if ( '' !== $slug ) {
				$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + (int) $row['c'];
			}
		}
	}

	// Legacy post table (present when HPOS is off, or in sync/compat mode).
	$rows = $wpdb->get_results(
		"SELECT post_status, COUNT(*) AS c FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status LIKE 'wc-%' GROUP BY post_status",
		ARRAY_A
	);
	foreach ( (array) $rows as $row ) {
		$slug = substr( (string) $row['post_status'], 3 );
		if ( '' !== $slug ) {
			// max(), not +=, so a mirrored sync install never double-counts.
			$counts[ $slug ] = max( $counts[ $slug ] ?? 0, (int) $row['c'] );
		}
	}

	set_transient( $key, $counts, 10 * MINUTE_IN_SECONDS );
	return $counts;
}

/**
 * Best-effort colour for a foreign status, read from the formats the common
 * custom-status plugins use. Falls back to BrikPanel's neutral grey, which the
 * merchant can recolour in one click after importing — the colour is purely
 * cosmetic, so a miss never blocks the migration.
 *
 * @param string $slug Bare slug.
 * @return string Validated hex colour.
 */
function brikpanel_cos_guess_foreign_color( $slug ) {
	$default = '#646970';
	global $wpdb;

	// Tyche "Custom Order Status": statuses are a `custom_order_status` CPT whose
	// post_name is the slug and whose colour lives in the `color` post meta.
	$post_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'custom_order_status' AND post_name = %s LIMIT 1",
		$slug
	) );
	if ( $post_id ) {
		$color = sanitize_hex_color( (string) get_post_meta( (int) $post_id, 'color', true ) );
		if ( $color ) {
			return $color;
		}
	}

	// WPFactory "Additional Custom Order Status" and sibling alg_wc_* plugins keep
	// their statuses in options; probe the documented shapes for a hex keyed by
	// this slug. Best-effort only — a miss yields the neutral default.
	foreach ( [ 'alg_orders_custom_statuses_colors', 'alg_wc_custom_order_statuses_colors' ] as $opt_name ) {
		$val = get_option( $opt_name );
		if ( is_array( $val ) ) {
			foreach ( [ $slug, 'wc-' . $slug ] as $candidate ) {
				if ( isset( $val[ $candidate ] ) ) {
					$color = sanitize_hex_color( (string) $val[ $candidate ] );
					if ( $color ) {
						return $color;
					}
				}
			}
		}
	}

	return $default;
}

/**
 * Detect custom order statuses that belong to another plugin and can be adopted
 * into BrikPanel. Two kinds surface:
 *
 *   - "orphan": present on real orders but registered by nobody (the defining
 *     plugin is already gone). These render as raw keys right now, so they are
 *     the highest-value imports — the UI pre-selects them.
 *   - "registered": still registered by an active third-party plugin (the live
 *     WPFactory / Tyche deactivation case). Offered too but not pre-selected,
 *     because the owning plugin is still handling them for now.
 *
 * WooCommerce core statuses and statuses BrikPanel already manages are excluded.
 *
 * @param bool $fresh Refresh the used-status scan.
 * @return array<string,array{label:string,color:string,count:int,registered:bool}>
 */
function brikpanel_cos_detect_foreign_statuses( $fresh = false ) {
	$reserved = brikpanel_cos_reserved_slugs();
	$owned    = brikpanel_get_custom_order_statuses();
	$registry = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
	$used     = brikpanel_cos_used_order_statuses( $fresh );

	$out = [];

	$consider = function ( $slug, $label, $registered ) use ( &$out, $reserved, $owned, $used ) {
		$slug = sanitize_key( (string) $slug );
		// Real order statuses fit `wc-` + <=17 chars; anything longer cannot be
		// one and must never be minted (it would not match the stored value).
		if ( '' === $slug || strlen( $slug ) > 17 ) {
			return;
		}
		if ( in_array( $slug, $reserved, true ) || isset( $owned[ $slug ] ) ) {
			return;
		}
		if ( isset( $out[ $slug ] ) ) {
			// Already seen (e.g. from the registry): promote to registered if any
			// source registers it; keep the first non-empty label.
			if ( $registered ) {
				$out[ $slug ]['registered'] = true;
			}
			return;
		}
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			$label = brikpanel_cos_humanize_slug( $slug );
		}
		$out[ $slug ] = [
			'label'      => $label,
			'color'      => brikpanel_cos_guess_foreign_color( $slug ),
			'count'      => isset( $used[ $slug ] ) ? (int) $used[ $slug ] : 0,
			'registered' => (bool) $registered,
		];
	};

	// 1) Registered-but-foreign, straight from the live WooCommerce registry.
	foreach ( $registry as $wc_key => $label ) {
		$slug = ( 0 === strpos( (string) $wc_key, 'wc-' ) ) ? substr( (string) $wc_key, 3 ) : (string) $wc_key;
		$consider( $slug, $label, true );
	}

	// 2) Orphans that survive only on the orders themselves.
	foreach ( $used as $slug => $count ) {
		$consider( $slug, '', false );
	}

	// Orphans (broken right now) first, then registered; alphabetical within.
	uasort( $out, function ( $a, $b ) {
		if ( $a['registered'] !== $b['registered'] ) {
			return $a['registered'] ? 1 : -1;
		}
		return strcasecmp( $a['label'], $b['label'] );
	} );

	return $out;
}

/**
 * Adopt selected foreign statuses into BrikPanel's managed list (AJAX).
 *
 * Re-validates every requested slug against a fresh detection so a forged POST
 * can never inject an arbitrary status, a reserved slug, or a status BrikPanel
 * already owns. Writes each survivor in the exact option shape the reader
 * expects, then the normal registration path (init / wc_order_statuses) picks
 * them up and the affected orders render, filter and count correctly again.
 */
add_action( 'wp_ajax_brikpanel_cos_import', function () {
	check_ajax_referer( 'brikpanel_cos_import', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'brikpanel' ) ], 403 );
	}

	$requested = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] )
		? array_map( 'sanitize_key', wp_unslash( $_POST['slugs'] ) )
		: [];
	if ( ! $requested ) {
		wp_send_json_error( [ 'message' => __( 'Select at least one status to import.', 'brikpanel' ) ], 400 );
	}

	$detected = brikpanel_cos_detect_foreign_statuses( true );
	$existing = get_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, [] );
	if ( ! is_array( $existing ) ) {
		$existing = [];
	}

	$imported = 0;
	foreach ( $requested as $slug ) {
		if ( ! isset( $detected[ $slug ] ) || isset( $existing[ $slug ] ) ) {
			continue;
		}
		$existing[ $slug ] = [
			'label' => sanitize_text_field( $detected[ $slug ]['label'] ),
			'color' => sanitize_hex_color( $detected[ $slug ]['color'] ) ?: '#646970',
		];
		$imported++;
	}

	if ( $imported > 0 ) {
		update_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, $existing );
		delete_transient( 'brikpanel_cos_used_statuses' );
	}

	wp_send_json_success( [
		'imported' => $imported,
		/* translators: %d: number of order statuses imported. */
		'message'  => sprintf( _n( '%d status imported.', '%d statuses imported.', $imported, 'brikpanel' ), $imported ),
	] );
} );

/**
 * Register the "Order statuses" section under the BrikPanel settings tab and
 * slot it into the Store group, right after Orders.
 */
add_filter( 'woocommerce_get_sections_brikpanel', function ( $sections ) {
	$out = [];
	foreach ( $sections as $id => $label ) {
		$out[ $id ] = $label;
		if ( 'orders' === $id ) {
			$out['order-statuses'] = __( 'Order statuses', 'brikpanel' );
		}
	}
	if ( ! isset( $out['order-statuses'] ) ) {
		$out['order-statuses'] = __( 'Order statuses', 'brikpanel' );
	}
	return $out;
} );

add_filter( 'brikpanel_settings_section_groups', function ( $groups ) {
	if ( isset( $groups['store']['sections'] ) && is_array( $groups['store']['sections'] ) ) {
		// Place it directly after "orders" for a natural reading order.
		$sections = $groups['store']['sections'];
		$pos      = array_search( 'orders', $sections, true );
		if ( false === $pos ) {
			$sections[] = 'order-statuses';
		} else {
			array_splice( $sections, $pos + 1, 0, 'order-statuses' );
		}
		$groups['store']['sections'] = $sections;
	}
	return $groups;
} );

add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	$map['brk_order_statuses_title'] = 'order-statuses';
	return $map;
} );

add_filter( 'brikpanel_settings_section_icons', function ( $icons ) {
	// Flag glyph — reads as "status / label".
	$icons['order-statuses'] = '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>';
	return $icons;
} );

/**
 * Inject the section's fields: a default-status picker (standard WC select,
 * saved by core) and the custom-status repeater (custom field type, saved by
 * our own handler below).
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	$status_options = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
	$default_choices = [ '' => __( 'WooCommerce default (Pending payment)', 'brikpanel' ) ];
	foreach ( $status_options as $key => $label ) {
		// Store the bare slug so it matches what woocommerce_default_order_status expects.
		$default_choices[ str_replace( 'wc-', '', $key ) ] = $label;
	}

	$fields[] = [
		'name' => __( 'Order statuses', 'brikpanel' ),
		'type' => 'title',
		'id'   => 'brk_order_statuses_title',
		'desc' => __( 'Create your own order statuses and choose where new orders begin. Use these for workflows WooCommerce does not cover out of the box, such as "Awaiting stock", "Packed" or "Ready for pickup". This applies to every order, whether it contains simple or variable products.', 'brikpanel' ),
	];
	$fields[] = [
		'name'     => __( 'Default status for new orders', 'brikpanel' ),
		'id'       => BRIKPANEL_DEFAULT_STATUS_OPTION,
		'type'     => 'select',
		'class'    => 'wc-enhanced-select',
		'options'  => $default_choices,
		'desc'     => __( 'The status every new order starts in before payment is processed. Leave on the WooCommerce default unless you have a specific workflow that needs otherwise — changing this affects checkout and can interfere with payment gateways.', 'brikpanel' ),
		'desc_tip' => true,
		'default'  => '',
	];
	$fields[] = [
		'type' => 'brikpanel_order_statuses',
		'id'   => 'brikpanel_order_statuses_field',
	];
	$fields[] = [
		'type' => 'sectionend',
		'id'   => 'brk_order_statuses_title',
	];
	return $fields;
} );

/**
 * Render the "Import from another plugin" card, shown only when foreign statuses
 * are detected. Each row is a checkbox (orphans pre-selected because they are
 * broken right now), the label, a colour dot, the slug, an in-use order count,
 * and a source hint. A single "Import selected" button posts to the AJAX handler.
 */
function brikpanel_render_status_import_card() {
	$foreign = brikpanel_cos_detect_foreign_statuses();
	if ( ! $foreign ) {
		return;
	}
	?>
	<section class="bp-cos-card bp-cos-import" data-cos-import>
		<header class="bp-cos-card__head">
			<div>
				<h3 class="bp-cos-card__title"><?php esc_html_e( 'Import from another plugin', 'brikpanel' ); ?></h3>
				<p class="bp-cos-card__sub"><?php esc_html_e( 'We found custom order statuses created outside BrikPanel. Adopt them here so you can safely deactivate the other plugin — your orders keep their status, colour and filters. This is the same for orders with simple or variable products.', 'brikpanel' ); ?></p>
			</div>
			<button type="button" class="bp-cos-add bp-cos-import-btn" data-cos-import-run>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
				<?php esc_html_e( 'Import selected', 'brikpanel' ); ?>
			</button>
		</header>

		<div class="bp-cos-import-list">
			<?php foreach ( $foreign as $slug => $data ) : ?>
				<label class="bp-cos-import-row">
					<input type="checkbox" class="bp-cos-import-check" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! $data['registered'] ); ?>>
					<span class="bp-cos-dot" style="background:<?php echo esc_attr( $data['color'] ); ?>"></span>
					<span class="bp-cos-import-label"><?php echo esc_html( $data['label'] ); ?></span>
					<code class="bp-cos-slug"><?php echo esc_html( $slug ); ?></code>
					<?php if ( $data['count'] > 0 ) : ?>
						<span class="bp-cos-import-count">
							<?php
							/* translators: %s: number of orders. */
							echo esc_html( sprintf( _n( '%s order', '%s orders', $data['count'], 'brikpanel' ), number_format_i18n( $data['count'] ) ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( $data['registered'] ) : ?>
						<span class="bp-cos-import-tag" title="<?php esc_attr_e( 'Currently provided by another active plugin.', 'brikpanel' ); ?>"><?php esc_html_e( 'active plugin', 'brikpanel' ); ?></span>
					<?php else : ?>
						<span class="bp-cos-import-tag is-orphan" title="<?php esc_attr_e( 'Used by orders but no longer registered by any plugin — orders show the raw status key until you import it.', 'brikpanel' ); ?>"><?php esc_html_e( 'needs rescue', 'brikpanel' ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>

		<p class="bp-cos-hint">
			<?php esc_html_e( 'Imported statuses appear in the list below, where you can rename the colour or remove them. Only import statuses from a plugin you plan to remove — a status marked "active plugin" is still managed by that plugin.', 'brikpanel' ); ?>
		</p>
	</section>
	<?php
}

/**
 * Render the custom-status repeater card.
 *
 * Mirrors the nav-customizer pattern: close the form-table WooCommerce opened
 * for the preceding title, emit our own card, then reopen an empty form-table
 * so the trailing sectionend has something to close.
 */
function brikpanel_render_order_statuses_field() {
	$statuses = brikpanel_get_custom_order_statuses();
	?>
	</table>
	<?php brikpanel_render_status_import_card(); ?>
	<section class="bp-cos-card">
		<header class="bp-cos-card__head">
			<div>
				<h3 class="bp-cos-card__title"><?php esc_html_e( 'Your custom statuses', 'brikpanel' ); ?></h3>
				<p class="bp-cos-card__sub"><?php esc_html_e( 'Add as many as you need. Pick a colour so each one stands out in the orders list.', 'brikpanel' ); ?></p>
			</div>
			<button type="button" class="bp-cos-add" data-cos-action="add">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
				<?php esc_html_e( 'Add status', 'brikpanel' ); ?>
			</button>
		</header>

		<div class="bp-cos-list" data-cos-list <?php echo $statuses ? '' : 'data-cos-empty'; ?>>
			<p class="bp-cos-empty" data-cos-emptymsg <?php echo $statuses ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'No custom statuses yet. Click "Add status" to create your first one.', 'brikpanel' ); ?>
			</p>
			<?php
			$i = 0;
			foreach ( $statuses as $slug => $data ) :
				?>
				<div class="bp-cos-row" data-cos-row data-cos-existing="1">
					<span class="bp-cos-dot" data-cos-dot style="background:<?php echo esc_attr( $data['color'] ); ?>"></span>
					<input
						type="text"
						class="bp-cos-label"
						name="brikpanel_cos[<?php echo (int) $i; ?>][label]"
						value="<?php echo esc_attr( $data['label'] ); ?>"
						placeholder="<?php esc_attr_e( 'Status name', 'brikpanel' ); ?>"
						autocomplete="off"
						data-cos-field="label"
					>
					<label class="bp-cos-color">
						<input
							type="color"
							name="brikpanel_cos[<?php echo (int) $i; ?>][color]"
							value="<?php echo esc_attr( $data['color'] ); ?>"
							data-cos-field="color"
						>
					</label>
					<code class="bp-cos-slug" data-cos-slug><?php echo esc_html( $slug ); ?></code>
					<input type="hidden" name="brikpanel_cos[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" data-cos-field="slug">
					<button type="button" class="bp-cos-remove" data-cos-action="remove" aria-label="<?php esc_attr_e( 'Remove status', 'brikpanel' ); ?>">
						<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
					</button>
				</div>
				<?php
				$i++;
			endforeach;
			?>
		</div>

		<p class="bp-cos-hint">
			<?php esc_html_e( 'Removing a status that orders already use will leave those orders showing the raw status key until you re-create it. Existing status keys cannot be renamed.', 'brikpanel' ); ?>
		</p>

		<template data-cos-template>
			<div class="bp-cos-row" data-cos-row data-cos-existing="0">
				<span class="bp-cos-dot" data-cos-dot style="background:#646970"></span>
				<input type="text" class="bp-cos-label" placeholder="<?php esc_attr_e( 'Status name', 'brikpanel' ); ?>" autocomplete="off" data-cos-field="label">
				<label class="bp-cos-color">
					<input type="color" value="#646970" data-cos-field="color">
				</label>
				<code class="bp-cos-slug" data-cos-slug></code>
				<input type="hidden" value="" data-cos-field="slug">
				<button type="button" class="bp-cos-remove" data-cos-action="remove" aria-label="<?php esc_attr_e( 'Remove status', 'brikpanel' ); ?>">
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
				</button>
			</div>
		</template>
	</section>
	<table class="form-table">
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_order_statuses', 'brikpanel_render_order_statuses_field' );

/**
 * Persist the repeater. Runs after WooCommerce has saved the standard fields
 * (the default-status select among them) for this section.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! function_exists( 'brikpanel_settings_get_current_section' )
		|| 'order-statuses' !== brikpanel_settings_get_current_section() ) {
		return;
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$rows = isset( $_POST['brikpanel_cos'] ) && is_array( $_POST['brikpanel_cos'] )
		? wp_unslash( $_POST['brikpanel_cos'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput — each field sanitised individually below.
		: [];

	$reserved = brikpanel_cos_reserved_slugs();
	$clean    = [];

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
		if ( '' === $label ) {
			continue;
		}
		$color = isset( $row['color'] ) ? sanitize_hex_color( $row['color'] ) : '';
		if ( ! $color ) {
			$color = '#646970';
		}

		$taken = array_merge( $reserved, array_keys( $clean ) );
		$slug  = isset( $row['slug'] ) ? substr( sanitize_key( $row['slug'] ), 0, 17 ) : '';
		if ( '' === $slug || in_array( $slug, $taken, true ) ) {
			$slug = brikpanel_cos_make_slug( $label, $taken );
		}

		$clean[ $slug ] = [
			'label' => $label,
			'color' => $color,
		];
	}

	update_option( BRIKPANEL_CUSTOM_STATUSES_OPTION, $clean );
}, 11 );

/**
 * Load the repeater's CSS + JS only on the BrikPanel WC settings tab.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
	if ( 'brikpanel' !== $tab ) {
		return;
	}
	// filemtime cache-bust so an updated asset is never served stale from cache.
	$css_path = __DIR__ . '/brikpanel-order-statuses.css';
	$js_path  = __DIR__ . '/brikpanel-order-statuses.js';
	wp_enqueue_style(
		'brikpanel-order-statuses',
		plugins_url( 'brikpanel-order-statuses.css', __FILE__ ),
		[],
		file_exists( $css_path ) ? filemtime( $css_path ) : BRIKPANEL_VERSION
	);
	wp_enqueue_script(
		'brikpanel-order-statuses',
		plugins_url( 'brikpanel-order-statuses.js', __FILE__ ),
		[],
		file_exists( $js_path ) ? filemtime( $js_path ) : BRIKPANEL_VERSION,
		true
	);
	wp_localize_script( 'brikpanel-order-statuses', 'brikpanelOrderStatuses', [
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		'importNonce' => wp_create_nonce( 'brikpanel_cos_import' ),
		'i18n' => [
			'confirmRemoveExisting' => __( 'Remove this status? Orders already using it will show the raw status key until you re-create it.', 'brikpanel' ),
			'newBadge'              => __( 'new', 'brikpanel' ),
			'importNone'            => __( 'Select at least one status to import.', 'brikpanel' ),
			'importing'             => __( 'Importing…', 'brikpanel' ),
			'importBtn'             => __( 'Import selected', 'brikpanel' ),
			'importError'           => __( 'Import failed. Please try again.', 'brikpanel' ),
		],
	] );
} );
