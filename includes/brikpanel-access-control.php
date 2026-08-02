<?php
/**
 * BrikPanel — Per-user / per-role access control.
 *
 * Lets the store owner keep the BrikPanel interface for some users (e.g. shop
 * managers) while leaving administrators — or specific roles / individual
 * users — on the stock WordPress / WooCommerce admin. This is the common
 * agency ask: clients (shop managers) get the BrikPanel experience, while the
 * agency's own administrator account stays vanilla and untouched.
 *
 * Two enforcement layers, both keyed off
 * brikpanel_access_is_disabled_for_user():
 *
 *   1. Option neutralization — every BrikPanel interface toggle is forced to
 *      "off" through a `pre_option_*` short-circuit, so each module renders
 *      the native screen and never hijacks the menu / dashboard / editors.
 *      Requires zero edits to the feature modules: they already gate on their
 *      own option at render time.
 *
 *   2. Asset + chrome sweep — any always-on BrikPanel CSS/JS, the extra
 *      order-list columns, and the appearance (font / accent / brand logo)
 *      theming are removed so the admin is pixel-for-pixel the stock
 *      WordPress / WooCommerce experience.
 *
 * The BrikPanel WooCommerce settings tab is always exempt from neutralization
 * so an excluded administrator can still open
 * WooCommerce → Settings → BrikPanel to read the real toggle states and
 * change who the panel is disabled for.
 *
 * @package BrikPanel
 * @since   3.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// OPTION KEYS
// =============================================================================
const BRIKPANEL_ACCESS_OPT_ADMINS = 'brikpanel_access_disable_for_admins';
const BRIKPANEL_ACCESS_OPT_ROLES  = 'brikpanel_access_disabled_roles';
const BRIKPANEL_ACCESS_OPT_USERS  = 'brikpanel_access_disabled_users';

// Roles for which the orders-screen analytics summary strip (the 30-day
// revenue / order-count overview above the orders list) is hidden. A finer
// axis than the interface gate above: a user in one of these roles keeps the
// full BrikPanel orders list but never sees store-wide totals. The common ask
// is multi-branch stores where branch staff process orders without seeing the
// whole store's revenue. Empty by default, so every staff member sees it.
const BRIKPANEL_ACCESS_OPT_OVERVIEW_ROLES = 'brikpanel_orders_overview_hidden_roles';

// Settings-page admin lock. When 'yes' (the default) only administrators may
// open and change the BrikPanel settings tab. Every other role, including shop
// managers who hold WooCommerce's `manage_woocommerce` cap, is kept out.
const BRIKPANEL_ACCESS_OPT_SETTINGS_ADMINS_ONLY = 'brikpanel_settings_admins_only';

// Global master switch for the whole BrikPanel interface. When 'no', every
// gated interface option is neutralized for *every* back-office user (not only
// an excluded one), so the entire store sees the stock WordPress / WooCommerce
// admin in one click. This is the broad on/off an administrator flips while the
// plugin is still being tuned. The control itself lives in the BrikPanel top
// bar and the native WordPress admin bar (front-end/master-switch/). It is a
// store-wide setting only administrators can change. On ('yes') by default.
const BRIKPANEL_MASTER_OPT = 'brikpanel_master_enabled';

// Which roles may see and use the BrikPanel on/off switch (the power control in
// the top bar and the native admin-bar node). The switch flips the store-wide
// master option above, so it is deliberately narrow: administrators (and, on
// multisite, super admins) always see it, and any role listed here sees it too.
// Empty by default, so out of the box only administrators see the control — a
// shop manager who was handed `manage_options` by a role editor no longer gets
// it. Add roles here to widen access; you can always change it back from the
// BrikPanel settings page as an administrator.
const BRIKPANEL_MASTER_SWITCH_ROLES = 'brikpanel_master_switch_roles';

// Per-user control ("personal mode"). When 'yes', the BrikPanel interface is no
// longer a single store-wide decision an administrator makes for everyone:
// instead every back-office user governs BrikPanel for their OWN account, from
// the same on/off switch in the top bar / WordPress toolbar and from their
// profile page. One user's choice never touches another user's view. What each
// role STARTS with — before the user picks — is decided by the role-defaults
// option below. Off by default, so nothing changes until the store owner opts
// in. The admin-forced rules (disabled users / roles / "disable for admins")
// still win over a user's personal choice: an account the owner has locked to
// the native admin cannot re-enable BrikPanel for itself.
const BRIKPANEL_ACCESS_OPT_PERSONAL_MODE = 'brikpanel_access_personal_mode';

// Roles that START with BrikPanel switched off under personal mode (the user can
// still turn it on for themselves). Everyone else starts with it on. Empty by
// default, so out of the box every role starts on. Setting this to
// [administrator] is the canonical agency case: administrators start on the
// native admin, shop managers keep BrikPanel, and each admin can opt their own
// account back in without affecting the others.
const BRIKPANEL_ACCESS_OPT_DEFAULT_OFF_ROLES = 'brikpanel_access_default_off_roles';

// Per-user preference storage (user meta). One of 'yes' (this user forces
// BrikPanel on for themselves), 'no' (forces it off) or absent/'' (follow the
// role default). Only consulted while personal mode is active.
const BRIKPANEL_ACCESS_USER_META_PERSONAL = 'brikpanel_personal_enabled';

// "Screen Options" tab control. Two independent axes, both off by default:
//   - …_SCREEN_OPTIONS_ALL  hides the Screen Options tab from every back-office
//     user (administrators included).
//   - …_SCREEN_OPTIONS_NONADMIN hides it only from non-administrators, so the
//     store owner keeps it while staff / clients do not.
// This replaces the equivalent White Label CMS behaviour. The tab is purely a
// per-screen preference panel, so hiding it never blocks any action and the
// choice is always reversible from this settings page.
const BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_ALL      = 'brikpanel_hide_screen_options';
const BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_NONADMIN = 'brikpanel_hide_screen_options_non_admins';

/**
 * Whether the BrikPanel interface master switch is currently on.
 *
 * Treats a missing option as on, so a fresh install ships with BrikPanel
 * visible. Any explicit 'no' turns the whole interface off store-wide.
 *
 * @return bool
 */
function brikpanel_master_enabled() {
	return get_option( BRIKPANEL_MASTER_OPT, 'yes' ) !== 'no';
}

/**
 * Whether the current user may see and use the BrikPanel on/off (power) switch.
 *
 * The switch flips the store-wide master option, so visibility is gated by
 * *role* rather than a raw capability: administrators (and, on multisite, super
 * admins) always qualify — they must always be able to turn BrikPanel back on —
 * and any role the store owner adds to BRIKPANEL_MASTER_SWITCH_ROLES qualifies
 * too. This intentionally does not use `manage_options`, so a non-administrator
 * who was granted that capability by a role editor does not get the control
 * unless their role is explicitly listed.
 *
 * @param int|null $user_id Optional user to test; defaults to the current user.
 * @return bool
 */
function brikpanel_can_use_master_switch( $user_id = null ) {
	$user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	// Multisite network administrators always keep the control.
	if ( is_multisite() && is_super_admin( $user->ID ) ) {
		return true;
	}

	$roles = array_map( 'strval', (array) $user->roles );

	// Administrators always keep the control (safety: they must be able to turn
	// the interface back on after switching it off).
	if ( in_array( 'administrator', $roles, true ) ) {
		return true;
	}

	// Additional roles the store owner has opted in.
	$allowed = array_map( 'strval', (array) get_option( BRIKPANEL_MASTER_SWITCH_ROLES, [] ) );
	if ( $allowed && array_intersect( $roles, $allowed ) ) {
		return true;
	}

	return false;
}

// =============================================================================
// CORE GATE
// =============================================================================

/**
 * The BrikPanel interface toggles that are forced to "off" for an excluded
 * user. These are the options whose modules transform an existing WordPress /
 * WooCommerce screen (or the admin chrome) — neutralizing them hands the user
 * back the native experience.
 *
 * Login is intentionally excluded: the modern login page renders before any
 * user is authenticated, so a per-user / per-role rule cannot be evaluated
 * there, and the login screen is a site-wide surface rather than a per-user
 * admin modification. The storefront variation gallery is likewise excluded —
 * it changes the shop front for every visitor, not one admin's panel.
 *
 * @return string[]
 */
function brikpanel_access_gated_options() {
	return (array) apply_filters( 'brikpanel_access_gated_options', [
		'brikpanel_modern_navigation',
		'brikpanel_native_menu_styled',
		'brikpanel_modern_dashboard',
		'brikpanel_dashboard_topbar',
		'brikpanel_orders_enhancements',
		'brikpanel_modern_order_edit',
		'brikpanel_simple_product_editor',
		'brikpanel_modern_products_list',
		'brikpanel_modern_coupons',
		'brikpanel_hide_foreign_notices',
		'brikpanel_order_notify_popup',
		'brikpanel_order_notify_sound',
		'brikpanel_order_notify_confetti',
	] );
}

/**
 * Whether per-user control ("personal mode") is switched on for the store.
 *
 * @return bool
 */
function brikpanel_access_personal_mode_active() {
	return get_option( BRIKPANEL_ACCESS_OPT_PERSONAL_MODE, 'no' ) === 'yes';
}

/**
 * A user's own saved BrikPanel preference under personal mode.
 *
 * @param WP_User|int $user User or user ID.
 * @return string 'yes' (force on for me), 'no' (force off for me) or '' (follow
 *                the role default).
 */
function brikpanel_access_user_personal_pref( $user ) {
	$uid = $user instanceof WP_User ? (int) $user->ID : (int) $user;
	if ( ! $uid ) {
		return '';
	}
	$pref = get_user_meta( $uid, BRIKPANEL_ACCESS_USER_META_PERSONAL, true );
	return ( 'yes' === $pref || 'no' === $pref ) ? $pref : '';
}

/**
 * Whether a user's ROLE starts BrikPanel off under personal mode, before the
 * user's own preference is applied.
 *
 * @param WP_User $user
 * @return bool
 */
function brikpanel_access_role_defaults_off( WP_User $user ) {
	$off_roles = array_map( 'strval', (array) get_option( BRIKPANEL_ACCESS_OPT_DEFAULT_OFF_ROLES, [] ) );
	if ( ! $off_roles ) {
		return false;
	}
	$roles = array_map( 'strval', (array) $user->roles );
	return (bool) array_intersect( $roles, $off_roles );
}

/**
 * The admin-forced ("hard") disable rules only, evaluated for a user.
 *
 * These are the store-owner rules a user can never override for themselves:
 *   1. The user's ID is in the explicit "disabled users" list.
 *   2. One of the user's roles is in the "disabled roles" list.
 *   3. "Disable for administrators" is on and the user has the
 *      `administrator` role.
 *
 * Kept separate from the personal-mode layer so both the core gate and the
 * "may this user flip their own switch?" check can share one source of truth.
 *
 * @param WP_User $user
 * @return bool
 */
function brikpanel_access_hard_disabled_for_user( WP_User $user ) {
	$uid   = (int) $user->ID;
	$roles = array_map( 'strval', (array) $user->roles );

	// 1. Explicit per-user list.
	$disabled_users = array_map( 'absint', (array) get_option( BRIKPANEL_ACCESS_OPT_USERS, [] ) );
	if ( in_array( $uid, $disabled_users, true ) ) {
		return true;
	}

	// 2. Per-role list.
	$disabled_roles = array_map( 'strval', (array) get_option( BRIKPANEL_ACCESS_OPT_ROLES, [] ) );
	if ( $disabled_roles && array_intersect( $roles, $disabled_roles ) ) {
		return true;
	}

	// 3. Blanket administrator switch.
	if ( get_option( BRIKPANEL_ACCESS_OPT_ADMINS, 'no' ) === 'yes'
		&& in_array( 'administrator', $roles, true ) ) {
		return true;
	}

	return false;
}

/**
 * Whether the BrikPanel interface should be disabled for a given user.
 *
 * Resolution order (first decisive layer wins):
 *   1. Admin-forced hard rules (disabled users / roles / "disable for admins").
 *      These always win — a locked account cannot re-enable BrikPanel itself.
 *   2. Personal mode (per-user control), when the store owner has switched it
 *      on: the user's own saved preference decides; with no preference the role
 *      default decides (a role listed in "start off" begins disabled).
 *   3. Otherwise BrikPanel stays enabled.
 *
 * The final decision is passed through the `brikpanel_access_user_disabled`
 * filter, so a store can implement any bespoke per-user policy in code without
 * touching the options — returning true hands that user the native admin, false
 * keeps BrikPanel.
 *
 * Returns false for logged-out requests (front-end / login page) and any
 * context where the current user cannot yet be resolved (very early boot,
 * WP-CLI without a user, cron) so site-wide data collection, the storefront
 * and background jobs are never affected.
 *
 * @param WP_User|null $user Optional user; defaults to the current user.
 * @return bool
 */
function brikpanel_access_is_disabled_for_user( $user = null ) {
	static $cache = [];

	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}

	if ( null === $user ) {
		$user = wp_get_current_user();
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	$uid = (int) $user->ID;
	if ( isset( $cache[ $uid ] ) ) {
		return $cache[ $uid ];
	}

	// 1. Admin-forced hard rules always win.
	$disabled = brikpanel_access_hard_disabled_for_user( $user );

	// 2. Personal mode: the user governs BrikPanel for their own account.
	if ( ! $disabled && brikpanel_access_personal_mode_active() ) {
		$pref = brikpanel_access_user_personal_pref( $user );
		if ( 'yes' === $pref ) {
			$disabled = false;
		} elseif ( 'no' === $pref ) {
			$disabled = true;
		} else {
			$disabled = brikpanel_access_role_defaults_off( $user );
		}
	}

	/**
	 * Filter the final per-user BrikPanel interface decision.
	 *
	 * Runs after every built-in rule (the admin-forced lists, the personal-mode
	 * preference and the role defaults). Return true to hand this user the
	 * native WordPress / WooCommerce admin, false to keep the BrikPanel
	 * interface — letting a store implement any bespoke per-user policy in code
	 * without touching the options.
	 *
	 * @since 3.2.23
	 *
	 * @param bool    $disabled Whether BrikPanel is disabled for this user.
	 * @param WP_User $user     The user being evaluated.
	 */
	$disabled = (bool) apply_filters( 'brikpanel_access_user_disabled', $disabled, $user );

	return $cache[ $uid ] = $disabled;
}

/**
 * Whether the orders-screen analytics summary strip must be hidden from a user.
 *
 * Independent of the interface gate above: this hides only the 30-day revenue /
 * order-count overview that sits above the orders list, while the user keeps the
 * full BrikPanel orders experience.
 *
 * Pure role match, consistent with the "Disable for roles" rule above: a user
 * is hidden if and only if one of their roles is in the configured list. No role
 * is special-cased, so selecting "Administrator" hides it from administrators
 * too — what is selected is exactly what is hidden. This never locks anyone out
 * of the BrikPanel settings page (governed separately), so the choice is always
 * reversible.
 *
 * Returns false for logged-out / unresolved users so nothing outside wp-admin is
 * ever affected.
 *
 * @param WP_User|null $user Optional user; defaults to the current user.
 * @return bool
 */
function brikpanel_orders_overview_hidden_for_user( $user = null ) {
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return false;
	}
	if ( null === $user ) {
		$user = wp_get_current_user();
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	$hidden_roles = array_map( 'strval', (array) get_option( BRIKPANEL_ACCESS_OPT_OVERVIEW_ROLES, [] ) );
	if ( ! $hidden_roles ) {
		return false;
	}

	$roles = array_map( 'strval', (array) $user->roles );
	return (bool) array_intersect( $roles, $hidden_roles );
}

/**
 * Whether the current request is the BrikPanel WooCommerce settings tab.
 *
 * This is the one surface kept fully functional for an excluded user: it must
 * show the real (non-neutralized) toggle states and let the admin save, so the
 * person who locked themselves out can always unlock again.
 *
 * @return bool
 */
function brikpanel_access_on_settings_page() {
	if ( ! is_admin() ) {
		return false;
	}
	if ( ! isset( $_GET['page'], $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context detection.
		return false;
	}
	return sanitize_key( wp_unslash( $_GET['page'] ) ) === 'wc-settings' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& sanitize_key( wp_unslash( $_GET['tab'] ) ) === 'brikpanel';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Whether BrikPanel must neutralize itself for this request: the user is
 * excluded, we are inside wp-admin, and this is not the BrikPanel settings
 * tab. Front-end, REST, cron and the storefront never neutralize.
 *
 * @return bool
 */
function brikpanel_access_should_neutralize() {
	if ( ! is_admin() ) {
		return false;
	}
	if ( brikpanel_access_on_settings_page() ) {
		return false;
	}
	// Global master switch wins over the per-user rules: when it is off the
	// whole interface is neutralized for everyone (the settings tab stays
	// exempt above so an administrator can always read the real toggle states
	// and switch it back on).
	if ( ! brikpanel_master_enabled() ) {
		return true;
	}
	return brikpanel_access_is_disabled_for_user();
}

/**
 * Whether a user counts as a real site administrator for BrikPanel access rules.
 *
 * Deliberately role-based, NOT capability-based. Stores routinely hand the
 * `manage_options` capability to shop managers with a role editor, so treating
 * that capability as "administrator" lets those managers slip straight past
 * every admin-only gate (the settings lock, the Screen Options hiding, the
 * welcome tour). We therefore look at the real `administrator` role, plus
 * multisite network super admins who must always keep control. This mirrors
 * `brikpanel_can_use_master_switch()`, which switched off the capability check
 * for exactly this reason.
 *
 * @param WP_User|int|null $user Optional user or user ID; defaults to current.
 * @return bool
 */
function brikpanel_user_is_administrator( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	} elseif ( is_numeric( $user ) ) {
		$user = get_user_by( 'id', (int) $user );
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	// Multisite network administrators always count, even on a subsite where
	// they may not carry the `administrator` role directly.
	if ( is_multisite() && is_super_admin( (int) $user->ID ) ) {
		return true;
	}

	return in_array( 'administrator', array_map( 'strval', (array) $user->roles ), true );
}

/**
 * Whether a user is a back-office (staff) account BrikPanel could ever apply to.
 *
 * Plain shoppers (customers / subscribers) never see the BrikPanel interface, so
 * they are excluded from the personal on/off switch. Anyone who can manage
 * WooCommerce, edit posts, or is a real administrator qualifies — this covers
 * shop managers, editors and administrators.
 *
 * @param WP_User|int|null $user Optional user or user ID; defaults to current.
 * @return bool
 */
function brikpanel_user_is_backoffice( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	} elseif ( is_numeric( $user ) ) {
		$user = get_user_by( 'id', (int) $user );
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}
	if ( brikpanel_user_is_administrator( $user ) ) {
		return true;
	}
	return user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'edit_posts' );
}

/**
 * Whether a user may flip the BrikPanel on/off switch for their OWN account.
 *
 * True only when: personal mode is active, the user is a back-office account,
 * and the store owner has not hard-locked that account to the native admin via
 * the "disabled users / roles / administrators" rules (those win, so offering a
 * personal switch there would be a lie). This is the gate the top-bar switch,
 * the WordPress-toolbar node and the profile checkbox all share.
 *
 * @param WP_User|int|null $user Optional user or user ID; defaults to current.
 * @return bool
 */
function brikpanel_can_use_personal_switch( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	} elseif ( is_numeric( $user ) ) {
		$user = get_user_by( 'id', (int) $user );
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}
	if ( ! brikpanel_access_personal_mode_active() ) {
		return false;
	}
	if ( ! brikpanel_user_is_backoffice( $user ) ) {
		return false;
	}
	if ( brikpanel_access_hard_disabled_for_user( $user ) ) {
		return false;
	}
	return true;
}

// =============================================================================
// PERSONAL MODE — SELF-SERVICE PROFILE CHECKBOX
// =============================================================================
//
// A discoverable home for the per-user choice, on the user's own profile screen.
// Rendered only on the user's OWN profile (`show_user_profile`) and only when
// personal mode is on for an eligible account. It reads and writes the same user
// meta the top-bar / toolbar switch flips, so the two controls stay in lock-step.

/**
 * Render the "Use the BrikPanel interface for my account" checkbox on the user's
 * own profile page.
 *
 * @param WP_User $profileuser The profile being edited (the current user here).
 */
function brikpanel_access_render_personal_profile_field( $profileuser ) {
	if ( ! $profileuser instanceof WP_User ) {
		return;
	}
	if ( ! brikpanel_can_use_personal_switch( $profileuser ) ) {
		return;
	}

	$on = ! brikpanel_access_is_disabled_for_user( $profileuser );
	wp_nonce_field( 'brikpanel_personal_pref', 'brikpanel_personal_pref_nonce' );
	?>
	<h2><?php esc_html_e( 'BrikPanel interface', 'brikpanel' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'My interface', 'brikpanel' ); ?></th>
			<td>
				<label for="brikpanel_personal_enabled">
					<input type="checkbox" name="brikpanel_personal_enabled" id="brikpanel_personal_enabled" value="1" <?php checked( $on ); ?> />
					<?php esc_html_e( 'Use the BrikPanel interface for my account', 'brikpanel' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Turn this off to use the default WordPress and WooCommerce admin for your own account only. It does not change anything for other users, and you can turn it back on here or from the on/off switch in the toolbar at any time.', 'brikpanel' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'brikpanel_access_render_personal_profile_field' );

/**
 * Save the personal-mode checkbox from the user's own profile submission.
 *
 * @param int $user_id The user being saved.
 */
function brikpanel_access_save_personal_profile_field( $user_id ) {
	if ( ! brikpanel_access_personal_mode_active() ) {
		return;
	}
	// "Own account only": ignore an administrator editing someone else's profile.
	if ( get_current_user_id() !== (int) $user_id ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_personal_pref_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['brikpanel_personal_pref_nonce'] ) ), 'brikpanel_personal_pref' ) ) {
		return;
	}
	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user instanceof WP_User || ! brikpanel_can_use_personal_switch( $user ) ) {
		return;
	}
	$enabled = isset( $_POST['brikpanel_personal_enabled'] );
	update_user_meta( (int) $user_id, BRIKPANEL_ACCESS_USER_META_PERSONAL, $enabled ? 'yes' : 'no' );
}
add_action( 'personal_options_update', 'brikpanel_access_save_personal_profile_field' );

// =============================================================================
// SETTINGS-PAGE ADMIN LOCK
// =============================================================================
//
// Separate, narrower axis from the interface gate above. The gate decides who
// SEES BrikPanel (dashboard, product/order tools, styling); this lock decides
// who may open and change BrikPanel's single configuration screen
// (WooCommerce → Settings → BrikPanel). They are independent: a shop manager
// can keep the full BrikPanel interface while the settings tab itself stays
// administrator-only, which is the common store-owner ask.
//
// On by default so settings are protected out of the box. An administrator can
// lift it from the Access control section, handing the tab back to every
// `manage_woocommerce` user (the historic WooCommerce behaviour).

/**
 * Whether the BrikPanel settings tab is currently restricted to administrators.
 *
 * @return bool
 */
function brikpanel_settings_admins_only_active() {
	return get_option( BRIKPANEL_ACCESS_OPT_SETTINGS_ADMINS_ONLY, 'yes' ) === 'yes';
}

/**
 * Whether a user may open and save the BrikPanel settings tab.
 *
 * Baseline is WooCommerce's own `manage_woocommerce` cap (so a user who could
 * never reach WC settings stays out regardless). When the admin lock is on the
 * user must additionally be a real administrator. That test is role-based, not
 * `manage_options`-based: stores commonly grant shop managers `manage_options`
 * with a role editor, and gating on the capability would let those managers
 * open the settings tab anyway. `brikpanel_user_is_administrator()` looks at the
 * actual `administrator` role plus multisite super admins.
 *
 * @param WP_User|int|null $user Optional user or user ID; defaults to current.
 * @return bool
 */
function brikpanel_user_can_open_settings( $user = null ) {
	if ( null === $user ) {
		$user = wp_get_current_user();
	} elseif ( is_numeric( $user ) ) {
		$user = get_user_by( 'id', (int) $user );
	}
	if ( ! $user instanceof WP_User || ! $user->ID ) {
		return false;
	}

	if ( ! user_can( $user, 'manage_woocommerce' ) ) {
		return false;
	}

	if ( ! brikpanel_settings_admins_only_active() ) {
		return true;
	}

	return brikpanel_user_is_administrator( $user );
}

/**
 * Hide the BrikPanel settings tab from the WooCommerce settings nav for a user
 * the admin lock keeps out. Priority 102 so it runs after the tab is registered
 * (priority 50) and after the multisite network-access gates (100 / 101).
 */
add_filter( 'woocommerce_settings_tabs_array', static function ( $tabs ) {
	if ( is_array( $tabs ) && isset( $tabs['brikpanel'] ) && ! brikpanel_user_can_open_settings() ) {
		unset( $tabs['brikpanel'] );
	}
	return $tabs;
}, 102 );

/**
 * Clean denial path for a direct hit on WC → Settings → BrikPanel.
 *
 * Intercepting on admin_init (priority 0, before WooCommerce renders the tab or
 * processes its save POST) sidesteps the "Undefined array key brikpanel" notice
 * WooCommerce core would emit when it builds the breadcrumb from the filtered
 * tab array we just trimmed. A blocked user lands silently on WC General, as if
 * the tab never existed. The save POST URL also carries
 * page=wc-settings&tab=brikpanel, so the same redirect discards an unauthorised
 * save before WooCommerce can handle it.
 */
add_action( 'admin_init', static function () {
	if ( ! is_admin() || wp_doing_ajax() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing on menu params, no state change here.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( $page !== 'wc-settings' || $tab !== 'brikpanel' ) {
		return;
	}
	if ( brikpanel_user_can_open_settings() ) {
		return;
	}
	wp_safe_redirect( add_query_arg(
		[ 'page' => 'wc-settings', 'tab' => 'general' ],
		admin_url( 'admin.php' )
	) );
	exit;
}, 0 );

/**
 * Defense-in-depth render + save guards for any non-HTTP code path that reaches
 * the tab without passing through the admin_init redirect above. Priority 3 so
 * they sit just after the multisite network-access guards (1 / 2).
 */
add_action( 'woocommerce_settings_tabs_brikpanel', static function () {
	if ( ! brikpanel_user_can_open_settings() ) {
		wp_die( esc_html__( 'You do not have permission to view BrikPanel settings.', 'brikpanel' ), 403 );
	}
}, 3 );

add_action( 'woocommerce_update_options_brikpanel', static function () {
	if ( ! brikpanel_user_can_open_settings() ) {
		wp_die( esc_html__( 'You do not have permission to change BrikPanel settings.', 'brikpanel' ), 403 );
	}
}, 3 );

// =============================================================================
// SCREEN OPTIONS TAB
// =============================================================================
//
// Independent of the BrikPanel interface gate and master switch: this hides the
// WordPress "Screen Options" tab regardless of whether the user is on BrikPanel
// or the native admin, because it is an admin-chrome preference in its own right
// (the common White Label CMS ask). Administrators are spared unless the
// "from everyone" axis is on.

/**
 * Whether the Screen Options tab must be hidden for the current request/user.
 *
 * @return bool
 */
function brikpanel_should_hide_screen_options() {
	if ( ! is_admin() ) {
		return false;
	}
	if ( get_option( BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_ALL, 'no' ) === 'yes' ) {
		return true;
	}
	if ( get_option( BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_NONADMIN, 'no' ) === 'yes'
		&& ! brikpanel_user_is_administrator() ) {
		return true;
	}
	return false;
}

/**
 * Hide the Screen Options tab by short-circuiting WordPress' own
 * `screen_options_show_screen` gate.
 */
add_filter( 'screen_options_show_screen', static function ( $show ) {
	return brikpanel_should_hide_screen_options() ? false : $show;
}, PHP_INT_MAX );

// =============================================================================
// LAYER 1 — OPTION NEUTRALIZATION
// =============================================================================
/**
 * Short-circuit every gated interface option to "no" for an excluded user.
 *
 * `pre_option_{$option}` is used (rather than `option_{$option}`) so the
 * override applies whether or not the option row exists in the database —
 * a fresh install whose toggles are still on their code defaults is gated
 * exactly like a saved one. Returning the untouched `$pre` when we are not
 * neutralizing leaves WordPress' normal lookup (and any other plugin's
 * filters) completely intact.
 */
foreach ( brikpanel_access_gated_options() as $brikpanel_access_opt ) {
	add_filter(
		'pre_option_' . $brikpanel_access_opt,
		static function ( $pre ) {
			return brikpanel_access_should_neutralize() ? 'no' : $pre;
		},
		10,
		1
	);
}
unset( $brikpanel_access_opt );

// =============================================================================
// LAYER 2 — ASSET SWEEP
// =============================================================================
/**
 * Remove every BrikPanel-owned style/script for an excluded user.
 *
 * Layer 1 stops the modules from rendering their custom screens, but a few
 * assets are enqueued unconditionally (e.g. the inline order-status changer
 * on the orders list). Running at PHP_INT_MAX — after every BrikPanel
 * enqueue, the latest of which is priority 999 — and matching on the plugin
 * URL guarantees a clean sweep without having to know each handle by name.
 *
 * The Google Fonts handle for the appearance feature is matched separately
 * because its `src` points at fonts.googleapis.com, not the plugin folder.
 */
function brikpanel_access_sweep_assets() {
	if ( ! brikpanel_access_should_neutralize() ) {
		return;
	}

	$base = defined( 'BRIKPANEL_URL' ) ? BRIKPANEL_URL : '';

	foreach ( [ wp_styles(), wp_scripts() ] as $assets ) {
		if ( ! $assets instanceof WP_Dependencies ) {
			continue;
		}
		$is_styles = ( $assets instanceof WP_Styles );
		foreach ( $assets->registered as $handle => $dep ) {
			$src   = isset( $dep->src ) ? (string) $dep->src : '';
			$owned = ( $base !== '' && $src !== '' && strpos( $src, $base ) === 0 )
				|| $handle === 'brikpanel-appearance-font'
				|| strpos( (string) $handle, 'brikpanel' ) === 0;
			if ( ! $owned ) {
				continue;
			}
			if ( $is_styles ) {
				wp_dequeue_style( $handle );
				wp_deregister_style( $handle );
			} else {
				wp_dequeue_script( $handle );
				wp_deregister_script( $handle );
			}
		}
	}
}
add_action( 'admin_enqueue_scripts', 'brikpanel_access_sweep_assets', PHP_INT_MAX );

// =============================================================================
// LAYER 2 — EXTRA ORDER-LIST COLUMNS
// =============================================================================
/**
 * The orders module adds Payment Method / Items / Tax Total columns to the
 * WooCommerce orders list unconditionally (they are not behind the orders
 * toggle). Strip them back out for an excluded user so the list is the stock
 * WooCommerce layout. Priority 99 runs after the module's own priority-20
 * `add_filter`, so our removal always wins.
 *
 * @param array $columns
 * @return array
 */
function brikpanel_access_strip_order_columns( $columns ) {
	if ( ! brikpanel_access_should_neutralize() ) {
		return $columns;
	}
	unset( $columns['payment_method'], $columns['order_items'], $columns['tax_total'] );
	return $columns;
}
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'brikpanel_access_strip_order_columns', 99 );
add_filter( 'manage_edit-shop_order_columns', 'brikpanel_access_strip_order_columns', 99 );

// =============================================================================
// LAYER 2 — BRIKPANEL ADMIN MENU ENTRIES
// =============================================================================
/**
 * Hide every BrikPanel-owned admin menu / submenu entry for an excluded user
 * (Segments, Customer Analytics, Vendors, Stock Orders, Operational Expenses,
 * Google Sheets, Ad Platforms, Scheduled Tasks, …). Every BrikPanel page slug
 * is prefixed `brikpanel-`, so a single prefix test removes them all without
 * touching WooCommerce / WordPress core entries.
 *
 * Runs at PHP_INT_MAX so it fires after every module has registered its menu.
 * Skipped on Network Admin (the multisite access-governance page lives there
 * and is governed separately) and on the BrikPanel settings tab (so an
 * excluded administrator can always navigate back to it).
 */
function brikpanel_access_hide_menu() {
	if ( is_network_admin() ) {
		return;
	}
	if ( ! brikpanel_access_should_neutralize() ) {
		return;
	}

	global $menu, $submenu;

	if ( is_array( $menu ) ) {
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && strpos( (string) $item[2], 'brikpanel' ) === 0 ) {
				unset( $menu[ $i ] );
			}
		}
	}

	if ( is_array( $submenu ) ) {
		foreach ( $submenu as $parent => $items ) {
			if ( strpos( (string) $parent, 'brikpanel' ) === 0 ) {
				unset( $submenu[ $parent ] );
				continue;
			}
			foreach ( $items as $j => $sub ) {
				if ( isset( $sub[2] ) && strpos( (string) $sub[2], 'brikpanel' ) === 0 ) {
					unset( $submenu[ $parent ][ $j ] );
				}
			}
		}
	}
}
add_action( 'admin_menu', 'brikpanel_access_hide_menu', PHP_INT_MAX );

// =============================================================================
// SETTINGS UI — roles + staff users helpers
// =============================================================================

/**
 * All registered roles as slug => translated display name, for the settings
 * multiselect.
 *
 * @return array<string,string>
 */
function brikpanel_access_collect_roles() {
	$out = [];
	foreach ( wp_roles()->roles as $slug => $info ) {
		$out[ $slug ] = isset( $info['name'] ) ? translate_user_role( $info['name'] ) : $slug;
	}
	return $out;
}

/**
 * Back-office "staff" users (everyone except plain customers / subscribers),
 * as user_id => "Display Name (login)" for the per-user picker.
 *
 * Plain shoppers are excluded both because the panel only ever affects users
 * who can reach wp-admin and to keep the option list small on stores with a
 * large customer base. The query is capped and only ever runs while the
 * BrikPanel settings tab is being rendered or saved.
 *
 * @return array<int,string>
 */
function brikpanel_access_collect_staff_users() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$users = get_users( [
		'role__not_in' => [ 'customer', 'subscriber' ],
		'fields'       => [ 'ID', 'display_name', 'user_login' ],
		'orderby'      => 'display_name',
		'order'        => 'ASC',
		'number'       => 500,
	] );

	$out = [];
	foreach ( $users as $u ) {
		$name = $u->display_name !== '' ? $u->display_name : $u->user_login;
		/* translators: 1: user display name, 2: user login. */
		$out[ (int) $u->ID ] = sprintf( __( '%1$s (%2$s)', 'brikpanel' ), $name, $u->user_login );
	}

	$cache = $out;
	return $out;
}

// =============================================================================
// SETTINGS UI — section + fields
// =============================================================================

/**
 * Add an "Access control" sub-section right after General in the BrikPanel
 * settings tab nav.
 */
add_filter( 'woocommerce_get_sections_brikpanel', function ( $sections ) {
	if ( ! is_array( $sections ) ) {
		return $sections;
	}
	$out = [];
	foreach ( $sections as $id => $label ) {
		$out[ $id ] = $label;
		if ( $id === '' ) {
			$out['access'] = __( 'Access control', 'brikpanel' );
		}
	}
	if ( ! isset( $out['access'] ) ) {
		$out['access'] = __( 'Access control', 'brikpanel' );
	}
	return $out;
}, 20 );

/**
 * Map the Access control title row to its section so the settings-tab slicer
 * renders it under the "Access control" sub-nav.
 */
add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	if ( is_array( $map ) ) {
		$map['brk_access_title']         = 'access';
		$map['brk_screen_options_title'] = 'access';
	}
	return $map;
} );

/**
 * Register the Access control fields. Appended after the existing field list;
 * the settings-tab slicer groups rows by their title row's section, so the
 * physical position does not matter.
 */
add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}

	$block = [
		[
			'name' => __( 'Access control', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_access_title',
			'desc' => __( 'Choose who sees the BrikPanel interface. Anyone disabled below gets the default WordPress / WooCommerce admin — native menu, dashboard, product and order screens, no BrikPanel styling — while everyone else keeps BrikPanel. Ideal for agencies that want clients (shop managers) on BrikPanel while their own administrator account stays untouched. This only changes the interface; BrikPanel analytics, tracking and background jobs keep running for the store. You can always reach this page to change it back, even while disabled for yourself.', 'brikpanel' ),
		],
		[
			'name'    => __( 'BrikPanel interface', 'brikpanel' ),
			'id'      => BRIKPANEL_MASTER_OPT,
			'type'    => 'checkbox',
			'desc'    => __( 'Master switch for the whole BrikPanel interface. Turn this off to instantly hand every back-office user the default WordPress / WooCommerce admin store-wide; turn it on to bring BrikPanel back. You can also flip this from the on/off switch in the BrikPanel top bar, or from the native WordPress admin bar while it is off. Only administrators see and change it. On by default.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'name'     => __( 'Show the on/off switch to roles', 'brikpanel' ),
			'id'       => BRIKPANEL_MASTER_SWITCH_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Choose which roles, besides administrators, can see and use the BrikPanel on/off (power) switch in the top bar. The switch turns BrikPanel off for the whole store, so administrators always keep it; everyone else only sees it if their role is selected here. Leave empty so only administrators can reach it. This hides the control from shop managers even if they were granted the "manage options" capability elsewhere.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_access_collect_roles(),
			'default'  => [],
		],
		[
			'name'    => __( 'Let each user choose (per-user control)', 'brikpanel' ),
			'id'      => BRIKPANEL_ACCESS_OPT_PERSONAL_MODE,
			'type'    => 'checkbox',
			'desc'    => __( 'Let each user turn the BrikPanel interface on or off for their own account only, from the on/off switch in the top bar (or the WordPress toolbar) and from their profile page. One person\'s choice never affects anyone else. Use "Roles that start with BrikPanel off" below to decide what each role begins with. Off by default.', 'brikpanel' ),
			'default' => 'no',
		],
		[
			'name'     => __( 'Roles that start with BrikPanel off', 'brikpanel' ),
			'id'       => BRIKPANEL_ACCESS_OPT_DEFAULT_OFF_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Only used when per-user control is on. Users with any of the selected roles start with BrikPanel switched off and can turn it on for their own account; everyone else starts with it on. For example select Administrator so administrators start on the native admin while shop managers keep BrikPanel, and each administrator can opt their own account back in. Leave empty to start everyone with BrikPanel on.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_access_collect_roles(),
			'default'  => [],
		],
		[
			'name'    => __( 'Restrict settings to administrators', 'brikpanel' ),
			'id'      => BRIKPANEL_ACCESS_OPT_SETTINGS_ADMINS_ONLY,
			'type'    => 'checkbox',
			'desc'    => __( 'Only administrators can open and change this BrikPanel settings page. Shop managers and other roles that can manage WooCommerce are kept out of this tab, even by direct link, while still keeping the BrikPanel interface itself. Turn this off to hand the settings page back to everyone who can manage WooCommerce. On by default.', 'brikpanel' ),
			'default' => 'yes',
		],
		[
			'name'    => __( 'Disable for administrators', 'brikpanel' ),
			'id'      => BRIKPANEL_ACCESS_OPT_ADMINS,
			'type'    => 'checkbox',
			'desc'    => __( 'Administrators see the stock WordPress / WooCommerce admin. Shop managers and every other role keep the BrikPanel interface.', 'brikpanel' ),
			'default' => 'no',
		],
		[
			'name'     => __( 'Disable for roles', 'brikpanel' ),
			'id'       => BRIKPANEL_ACCESS_OPT_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Users with any of the selected roles get the default admin instead of BrikPanel. Leave empty to apply no role rule.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_access_collect_roles(),
			'default'  => [],
		],
		[
			'name'     => __( 'Disable for specific users', 'brikpanel' ),
			'id'       => BRIKPANEL_ACCESS_OPT_USERS,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Hand-pick individual staff accounts that should always get the default admin, regardless of their role. Customers and subscribers are not listed.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_access_collect_staff_users(),
			'default'  => [],
		],
		[
			'name'     => __( 'Hide orders analytics from roles', 'brikpanel' ),
			'id'       => BRIKPANEL_ACCESS_OPT_OVERVIEW_ROLES,
			'type'     => 'multiselect',
			'class'    => 'wc-enhanced-select',
			'desc'     => __( 'Users with any of the selected roles keep the BrikPanel orders list but no longer see the analytics summary above it (the last 30 days revenue and order counts). Ideal for multi-branch stores where branch staff process orders without seeing the whole store\'s totals. What you select is exactly what is hidden, so adding Administrator hides it from administrators too; you can always reopen this page to change it back. Leave empty to show it to everyone.', 'brikpanel' ),
			'desc_tip' => true,
			'options'  => brikpanel_access_collect_roles(),
			'default'  => [],
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_access_title',
		],
		[
			'name' => __( 'Screen Options', 'brikpanel' ),
			'type' => 'title',
			'id'   => 'brk_screen_options_title',
			'desc' => __( 'Control the WordPress "Screen Options" tab that appears at the top-right of admin screens. Hiding it keeps staff from changing column layouts and per-screen settings. This is a display preference only, never a permission, so it is always safe and reversible from here.', 'brikpanel' ),
		],
		[
			'name'    => __( 'Hide Screen Options from everyone', 'brikpanel' ),
			'id'      => BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_ALL,
			'type'    => 'checkbox',
			'desc'    => __( 'Remove the Screen Options tab for every back-office user, administrators included. Off by default.', 'brikpanel' ),
			'default' => 'no',
		],
		[
			'name'    => __( 'Hide Screen Options from non-administrators', 'brikpanel' ),
			'id'      => BRIKPANEL_ACCESS_OPT_SCREEN_OPTIONS_NONADMIN,
			'type'    => 'checkbox',
			'desc'    => __( 'Keep the Screen Options tab for administrators but remove it for every other role (shop managers, editors, clients). Off by default.', 'brikpanel' ),
			'default' => 'no',
		],
		[
			'type' => 'sectionend',
			'id'   => 'brk_screen_options_title',
		],
	];

	return array_merge( $fields, $block );
}, 7 );
