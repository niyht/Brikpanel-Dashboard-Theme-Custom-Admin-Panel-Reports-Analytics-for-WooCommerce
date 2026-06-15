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
 * core status plus the two legacy BrikPanel statuses and the WordPress system
 * statuses. Used both when minting a slug and when validating the default.
 *
 * @return string[] Slugs without the `wc-` prefix.
 */
function brikpanel_cos_reserved_slugs() {
	return [
		'pending', 'processing', 'on-hold', 'completed', 'cancelled',
		'refunded', 'failed', 'checkout-draft', 'return-draft', 'change',
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
	wp_enqueue_style(
		'brikpanel-order-statuses',
		plugins_url( 'brikpanel-order-statuses.css', __FILE__ ),
		[],
		BRIKPANEL_VERSION
	);
	wp_enqueue_script(
		'brikpanel-order-statuses',
		plugins_url( 'brikpanel-order-statuses.js', __FILE__ ),
		[],
		BRIKPANEL_VERSION,
		true
	);
	wp_localize_script( 'brikpanel-order-statuses', 'brikpanelOrderStatuses', [
		'i18n' => [
			'confirmRemoveExisting' => __( 'Remove this status? Orders already using it will show the raw status key until you re-create it.', 'brikpanel' ),
			'newBadge'              => __( 'new', 'brikpanel' ),
		],
	] );
} );
