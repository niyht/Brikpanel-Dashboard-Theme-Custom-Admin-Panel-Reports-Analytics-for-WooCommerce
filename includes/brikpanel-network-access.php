<?php
/**
 * Network-wide access rules for BrikPanel.
 *
 * Why this module exists: when BrikPanel is Network-Activated on a WordPress
 * Multisite install, every subsite administrator (and shop manager) can
 * access BrikPanel's settings tab, admin pages, and AJAX endpoints by virtue
 * of holding `manage_woocommerce`. Some networks want to limit which roles
 * may use BrikPanel on subsites without giving up Network-Activation.
 *
 * This module adds a Network Admin → Settings → BrikPanel Access page
 * (Super Admins only) where the network owner picks the role allowlist that
 * applies across every subsite. When the toggle is off (default) the plugin
 * behaves exactly as before — the allowlist only kicks in once the Super
 * Admin explicitly opts in.
 *
 * Enforcement points (all gated on this single helper, so the rule cannot
 * drift across surfaces):
 *   - WC settings tab visibility + render + save
 *   - BrikPanel top-level + sub-menu items (admin_menu priority 99999)
 *   - All brikpanel_* AJAX actions (admin_init early gate)
 *
 * Storage: a single site option (`brikpanel_network_access`) so the value is
 * shared across the whole network rather than per-site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Site-option key holding the network access ruleset.
 * Stored as: [ 'enforce' => 'no'|'yes', 'roles' => string[] ].
 */
const BRIKPANEL_NETWORK_ACCESS_OPTION = 'brikpanel_network_access';

/**
 * Read the network access ruleset, normalised so callers can rely on shape.
 *
 * Administrator is force-included in the role allowlist on every read so a
 * Super Admin can never lock themselves out of BrikPanel by mis-clicking the
 * UI — even with `enforce` on and an empty role list, administrators always
 * keep access.
 *
 * @return array{enforce:string, roles:string[]}
 */
function brikpanel_network_access_settings() {
    $defaults = [
        'enforce'        => 'no',
        'roles'          => [ 'administrator', 'shop_manager' ],
        // Settings lock: when 'yes', subsite users cannot view or change the
        // BrikPanel settings tab unless their site id is in settings_sites.
        // Default 'no' so existing network-activated installs are untouched
        // by an update — the lock only ever applies after an explicit opt-in.
        'lock_settings'  => 'no',
        'settings_sites' => [],
    ];

    if ( ! is_multisite() ) {
        return $defaults;
    }

    $stored = get_site_option( BRIKPANEL_NETWORK_ACCESS_OPTION, null );
    if ( ! is_array( $stored ) ) {
        // First-run: nothing persisted yet — surface the defaults so the UI
        // can pre-check Shop Manager and the enforcement remains off.
        return $defaults;
    }

    $enforce = isset( $stored['enforce'] ) && $stored['enforce'] === 'yes' ? 'yes' : 'no';
    $roles   = isset( $stored['roles'] ) && is_array( $stored['roles'] )
        ? array_values( array_filter( array_map( 'sanitize_key', $stored['roles'] ) ) )
        : [];

    // Administrator can never be removed from the allowlist.
    if ( ! in_array( 'administrator', $roles, true ) ) {
        $roles[] = 'administrator';
    }

    $lock_settings  = isset( $stored['lock_settings'] ) && $stored['lock_settings'] === 'yes' ? 'yes' : 'no';
    $settings_sites = isset( $stored['settings_sites'] ) && is_array( $stored['settings_sites'] )
        ? array_values( array_unique( array_filter( array_map( 'absint', $stored['settings_sites'] ) ) ) )
        : [];

    return [
        'enforce'        => $enforce,
        'roles'          => $roles,
        'lock_settings'  => $lock_settings,
        'settings_sites' => $settings_sites,
    ];
}

/**
 * Decide whether the current user may VIEW or CHANGE BrikPanel's settings
 * (the WooCommerce → Settings → BrikPanel tab — the plugin's single canonical
 * configuration screen) on the current subsite.
 *
 * This is intentionally independent of brikpanel_user_can_access(): a denied
 * user here can still *use* BrikPanel (dashboard, reports, product/coupon
 * tools) — they simply cannot reconfigure how it behaves. That mirrors the
 * customer ask: lock the plugin's settings network-wide by default and let
 * the Super Admin hand specific subsites permission to manage their own.
 *
 * Returns true when:
 *   - We're not on multisite, OR
 *   - BrikPanel is not network-active here, OR
 *   - The Super Admin has not turned the settings lock on, OR
 *   - The user is a Super Admin, OR
 *   - The current subsite is in the Super Admin's settings allowlist.
 *
 * NOTE: the scope is deliberately the single WC settings tab. Extending the
 * lock to additional config surfaces later means adding their gate next to
 * the existing ones below — the helper itself does not change.
 *
 * @param int|null $user_id User to evaluate, or null for current user.
 * @return bool
 */
function brikpanel_user_can_manage_settings( $user_id = null ) {
    if ( ! is_multisite() ) {
        return true;
    }

    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! is_plugin_active_for_network( BRIKPANEL_BASENAME ) ) {
        return true;
    }

    $settings = brikpanel_network_access_settings();
    if ( $settings['lock_settings'] !== 'yes' ) {
        return true;
    }

    $user = $user_id ? get_user_by( 'id', (int) $user_id ) : wp_get_current_user();
    if ( ! $user || empty( $user->ID ) ) {
        return false;
    }

    if ( is_super_admin( (int) $user->ID ) ) {
        return true;
    }

    return in_array( (int) get_current_blog_id(), $settings['settings_sites'], true );
}

/**
 * Decide whether a given user is allowed to use BrikPanel on the current
 * site. Returns true when:
 *
 *   - We're not on multisite at all, OR
 *   - BrikPanel is not network-active on this install, OR
 *   - The Super Admin has not enabled enforcement, OR
 *   - The user is a Super Admin (full network privileges), OR
 *   - The user holds at least one role in the network allowlist.
 *
 * Otherwise returns false — callers should hide UI / refuse AJAX accordingly.
 *
 * @param int|null $user_id User to evaluate, or null for current user.
 * @return bool
 */
function brikpanel_user_can_access( $user_id = null ) {
    if ( ! is_multisite() ) {
        return true;
    }

    if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! is_plugin_active_for_network( BRIKPANEL_BASENAME ) ) {
        // Per-site activation: each site administrator already controls the
        // plugin themselves, so the network-wide gate doesn't apply.
        return true;
    }

    $settings = brikpanel_network_access_settings();
    if ( $settings['enforce'] !== 'yes' ) {
        return true;
    }

    $user = $user_id ? get_user_by( 'id', (int) $user_id ) : wp_get_current_user();
    if ( ! $user || empty( $user->ID ) ) {
        return false;
    }

    if ( is_super_admin( (int) $user->ID ) ) {
        return true;
    }

    $user_roles = (array) $user->roles;
    foreach ( $user_roles as $role ) {
        if ( in_array( $role, $settings['roles'], true ) ) {
            return true;
        }
    }

    return false;
}

// =============================================================================
// NETWORK ADMIN SETTINGS PAGE
// =============================================================================

/**
 * Register the access-rules page under Network Admin → Settings. Restricted to
 * Super Admins via the `manage_network_options` capability that
 * add_submenu_page enforces — there is no further check needed in the render
 * callback as WordPress short-circuits the page load when the cap is missing.
 */
function brikpanel_network_access_register_page() {
    if ( ! is_multisite() ) {
        return;
    }
    add_submenu_page(
        'settings.php',
        __( 'BrikPanel Access Rules', 'brikpanel' ),
        __( 'BrikPanel Access', 'brikpanel' ),
        'manage_network_options',
        'brikpanel-network-access',
        'brikpanel_network_access_render_page'
    );
}
add_action( 'network_admin_menu', 'brikpanel_network_access_register_page' );

/**
 * Handle the network admin save POST. We can't lean on the normal
 * `options.php` round-trip because Network Admin doesn't expose it for
 * site-options — we have to do the form processing ourselves on admin_init.
 */
function brikpanel_network_access_handle_save() {
    if ( ! is_multisite() ) {
        return;
    }
    if ( empty( $_POST['brikpanel_network_access_submit'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to change these settings.', 'brikpanel' ), 403 );
    }
    check_admin_referer( 'brikpanel_network_access_save', 'brikpanel_network_access_nonce' );

    $enforce = ! empty( $_POST['brikpanel_enforce'] ) ? 'yes' : 'no';
    $roles   = isset( $_POST['brikpanel_roles'] ) && is_array( $_POST['brikpanel_roles'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['brikpanel_roles'] ) )
        : [];

    // Administrator is always implicit but we still persist it so the UI
    // round-trips cleanly when the user re-opens the form.
    if ( ! in_array( 'administrator', $roles, true ) ) {
        $roles[] = 'administrator';
    }

    $lock_settings  = ! empty( $_POST['brikpanel_lock_settings'] ) ? 'yes' : 'no';
    $settings_sites = isset( $_POST['brikpanel_settings_sites'] ) && is_array( $_POST['brikpanel_settings_sites'] )
        ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['brikpanel_settings_sites'] ) ) ) ) )
        : [];

    update_site_option( BRIKPANEL_NETWORK_ACCESS_OPTION, [
        'enforce'        => $enforce,
        'roles'          => array_values( array_unique( $roles ) ),
        'lock_settings'  => $lock_settings,
        'settings_sites' => $settings_sites,
    ] );

    $redirect = add_query_arg(
        [
            'page'    => 'brikpanel-network-access',
            'updated' => '1',
        ],
        network_admin_url( 'settings.php' )
    );
    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_init', 'brikpanel_network_access_handle_save' );

/**
 * Collect every role registered on the main site so the Super Admin can pick
 * from the full set. On multisite, role definitions live in the main site's
 * wp_options (network installs share roles across subsites by default).
 *
 * @return array<string,string>
 */
function brikpanel_network_access_collect_roles() {
    $roles = wp_roles()->roles;
    $out   = [];
    foreach ( $roles as $slug => $info ) {
        $name = isset( $info['name'] ) ? translate_user_role( $info['name'] ) : $slug;
        $out[ $slug ] = $name;
    }
    return $out;
}

/**
 * Render the Network Admin → Settings → BrikPanel Access page. The markup
 * mirrors the plugin's own Shopify-style settings cards (see CLAUDE.md for
 * the design system) so the page does not feel like a stranger inside the
 * network admin chrome.
 */
function brikpanel_network_access_render_page() {
    if ( ! current_user_can( 'manage_network_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'brikpanel' ), 403 );
    }

    $settings  = brikpanel_network_access_settings();
    $all_roles = brikpanel_network_access_collect_roles();
    $updated   = ! empty( $_GET['updated'] );

    // Sites the Super Admin can hand settings-management permission to. Capped
    // so a very large network can't blow up the page; networks past the cap
    // get a notice rather than a silently truncated list.
    $site_cap   = 500;
    $site_query = get_sites( [
        'number'   => $site_cap + 1,
        'archived' => 0,
        'deleted'  => 0,
        'spam'     => 0,
        'orderby'  => 'id',
        'order'    => 'ASC',
    ] );
    $sites_over_cap = count( $site_query ) > $site_cap;
    $all_sites      = array_slice( $site_query, 0, $site_cap );

    $form_action = add_query_arg(
        [ 'page' => 'brikpanel-network-access' ],
        network_admin_url( 'settings.php' )
    );
    ?>
    <div class="wrap brikpanel-net-access-wrap">
        <h1><?php esc_html_e( 'BrikPanel Access Rules', 'brikpanel' ); ?></h1>

        <?php if ( $updated ) : ?>
            <div class="notice notice-success brikpanel-notice is-dismissible">
                <p><strong><?php esc_html_e( 'Settings saved.', 'brikpanel' ); ?></strong>
                <?php esc_html_e( 'The new access rules take effect immediately on every site in the network.', 'brikpanel' ); ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( $form_action ); ?>" class="brikpanel-net-access-form">
            <?php wp_nonce_field( 'brikpanel_network_access_save', 'brikpanel_network_access_nonce' ); ?>

            <section class="bp-card">
                <header class="bp-card__header">
                    <h2><?php esc_html_e( 'Network-wide access control', 'brikpanel' ); ?></h2>
                    <p class="bp-card__desc">
                        <?php esc_html_e( 'By default every subsite administrator and shop manager can use BrikPanel because they hold the manage_woocommerce capability. Turn this on to restrict BrikPanel to a specific set of roles across the whole network.', 'brikpanel' ); ?>
                    </p>
                </header>
                <div class="bp-card__body">
                    <label class="bp-field bp-field--toggle">
                        <span class="bp-field__label"><?php esc_html_e( 'Restrict BrikPanel access on subsites', 'brikpanel' ); ?></span>
                        <span class="bp-toggle">
                            <input type="checkbox" name="brikpanel_enforce" value="1" <?php checked( $settings['enforce'], 'yes' ); ?>>
                            <span class="bp-toggle__slider" aria-hidden="true"></span>
                        </span>
                    </label>
                    <p class="bp-field__help">
                        <?php esc_html_e( 'When off (default), nothing changes — BrikPanel works for every site admin and shop manager as usual. When on, only users whose role appears in the list below see the BrikPanel UI, settings tab, and AJAX endpoints.', 'brikpanel' ); ?>
                    </p>
                </div>
            </section>

            <section class="bp-card">
                <header class="bp-card__header">
                    <h2><?php esc_html_e( 'Roles allowed to use BrikPanel', 'brikpanel' ); ?></h2>
                    <p class="bp-card__desc">
                        <?php esc_html_e( 'Pick the roles whose users may access BrikPanel features on subsites. Network Super Admins are always allowed, regardless of this list.', 'brikpanel' ); ?>
                    </p>
                </header>
                <div class="bp-card__body">
                    <ul class="bp-roles-list">
                        <?php foreach ( $all_roles as $slug => $name ) :
                            $checked  = in_array( $slug, $settings['roles'], true );
                            $is_admin = ( $slug === 'administrator' );
                            ?>
                            <li class="bp-roles-list__item">
                                <label>
                                    <input type="checkbox"
                                        name="brikpanel_roles[]"
                                        value="<?php echo esc_attr( $slug ); ?>"
                                        <?php checked( $checked || $is_admin ); ?>
                                        <?php disabled( $is_admin ); ?>>
                                    <?php if ( $is_admin ) : ?>
                                        <input type="hidden" name="brikpanel_roles[]" value="administrator">
                                    <?php endif; ?>
                                    <span class="bp-roles-list__name"><?php echo esc_html( $name ); ?></span>
                                    <span class="bp-roles-list__slug"><?php echo esc_html( $slug ); ?></span>
                                    <?php if ( $is_admin ) : ?>
                                        <span class="bp-roles-list__pill"><?php esc_html_e( 'Always on', 'brikpanel' ); ?></span>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="bp-card">
                <header class="bp-card__header">
                    <h2><?php esc_html_e( 'Lock BrikPanel settings on subsites', 'brikpanel' ); ?></h2>
                    <p class="bp-card__desc">
                        <?php esc_html_e( 'When on, subsite owners can still use BrikPanel (dashboard, reports, product and coupon tools) but cannot open or change the BrikPanel settings tab. Only the subsites you grant below — and Network Super Admins — may manage BrikPanel settings.', 'brikpanel' ); ?>
                    </p>
                </header>
                <div class="bp-card__body">
                    <label class="bp-field bp-field--toggle">
                        <span class="bp-field__label"><?php esc_html_e( 'Lock the BrikPanel settings tab on subsites', 'brikpanel' ); ?></span>
                        <span class="bp-toggle">
                            <input type="checkbox" name="brikpanel_lock_settings" value="1" <?php checked( $settings['lock_settings'], 'yes' ); ?>>
                            <span class="bp-toggle__slider" aria-hidden="true"></span>
                        </span>
                    </label>
                    <p class="bp-field__help">
                        <?php esc_html_e( 'Off by default — turning this on never affects how BrikPanel works elsewhere, it only hides and blocks the settings tab for subsites that are not granted below.', 'brikpanel' ); ?>
                    </p>
                </div>
            </section>

            <section class="bp-card">
                <header class="bp-card__header">
                    <h2><?php esc_html_e( 'Subsites allowed to manage BrikPanel settings', 'brikpanel' ); ?></h2>
                    <p class="bp-card__desc">
                        <?php esc_html_e( 'Only used when the lock above is on. Tick a subsite to let its administrators open and change the BrikPanel settings tab on that site. Super Admins always have access regardless of this list.', 'brikpanel' ); ?>
                    </p>
                </header>
                <div class="bp-card__body">
                    <?php if ( $sites_over_cap ) : ?>
                        <p class="bp-field__help">
                            <?php
                            printf(
                                /* translators: %d: number of sites shown */
                                esc_html__( 'This network has more sites than can be listed here. Showing the first %d by site ID.', 'brikpanel' ),
                                500
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                    <ul class="bp-roles-list">
                        <?php foreach ( $all_sites as $site ) :
                            $blog_id = (int) $site->blog_id;
                            $checked = in_array( $blog_id, $settings['settings_sites'], true );
                            $details = get_blog_details( $blog_id );
                            $name    = $details && $details->blogname ? $details->blogname : ( '#' . $blog_id );
                            $url     = $details ? $details->siteurl : '';
                            ?>
                            <li class="bp-roles-list__item">
                                <label>
                                    <input type="checkbox"
                                        name="brikpanel_settings_sites[]"
                                        value="<?php echo esc_attr( $blog_id ); ?>"
                                        <?php checked( $checked ); ?>>
                                    <span class="bp-roles-list__name"><?php echo esc_html( $name ); ?></span>
                                    <span class="bp-roles-list__slug"><?php echo esc_html( $url ); ?></span>
                                    <span class="bp-roles-list__pill"><?php echo esc_html( 'ID ' . $blog_id ); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <div class="bp-actions">
                <button type="submit" name="brikpanel_network_access_submit" value="1" class="bp-btn bp-btn--primary">
                    <?php esc_html_e( 'Save changes', 'brikpanel' ); ?>
                </button>
            </div>
        </form>
    </div>

    <style>
        .brikpanel-net-access-wrap { max-width: 820px; margin: 18px auto 40px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #303030; }
        .brikpanel-net-access-wrap h1 { font-size: 1.125rem; font-weight: 600; margin: 0 0 1rem; }
        .brikpanel-net-access-form { display: flex; flex-direction: column; gap: 1rem; }
        .bp-card { background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); overflow: hidden; }
        .bp-card__header { padding: 1.25rem 1.5rem 0.75rem; }
        .bp-card__header h2 { margin: 0 0 0.25rem; font-size: 0.9375rem; font-weight: 600; color: #303030; line-height: 1.4; }
        .bp-card__desc { margin: 0; font-size: 0.8125rem; color: #616161; line-height: 1.5; }
        .bp-card__body { padding: 0.75rem 1.5rem 1.25rem; }
        .bp-field { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.25rem 0; }
        .bp-field__label { font-size: 0.8125rem; font-weight: 600; color: #303030; }
        .bp-field__help { margin: 0.5rem 0 0; font-size: 0.75rem; color: #8a8a8a; line-height: 1.5; }
        .bp-toggle { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
        .bp-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
        .bp-toggle__slider { position: absolute; inset: 0; background: #ccc; border-radius: 22px; transition: background 0.15s ease; cursor: pointer; }
        .bp-toggle__slider::before { content: ""; position: absolute; height: 16px; width: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.2); transition: transform 0.15s ease; }
        .bp-toggle input:checked + .bp-toggle__slider { background: #303030; }
        .bp-toggle input:checked + .bp-toggle__slider::before { transform: translateX(18px); }
        .bp-roles-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
        .bp-roles-list__item label { display: flex; align-items: center; gap: 0.625rem; padding: 0.5rem 0.75rem; border: 1px solid #e3e3e3; border-radius: 0.5rem; cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease; }
        .bp-roles-list__item label:hover { background: #fafafa; }
        .bp-roles-list__item input[type="checkbox"] { margin: 0; }
        .bp-roles-list__item input[type="checkbox"]:disabled + .bp-roles-list__name { color: #616161; }
        .bp-roles-list__name { font-size: 0.875rem; color: #303030; font-weight: 500; }
        .bp-roles-list__slug { font-size: 0.75rem; color: #8a8a8a; font-family: ui-monospace, "SFMono-Regular", Menlo, monospace; }
        .bp-roles-list__pill { margin-left: auto; font-size: 0.6875rem; font-weight: 550; padding: 0.125rem 0.5rem; background: #f1f1f1; color: #616161; border-radius: 999px; }
        .bp-actions { display: flex; justify-content: flex-end; padding: 0 0.25rem; }
        .bp-btn { font: inherit; font-size: 0.8125rem; font-weight: 550; line-height: 1.4; padding: 0.5rem 1rem; border-radius: 0.5rem; border: 0; cursor: pointer; transition: background 0.15s ease; }
        .bp-btn--primary { background: #303030; color: #fff; box-shadow: inset 0 -1px 0 rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.1); }
        .bp-btn--primary:hover { background: #1a1a1a; }
    </style>
    <?php
}

// =============================================================================
// ENFORCEMENT — these run on every (sub)site request once the rule is active.
// Each surface checks the same helper so a deny decision can never diverge.
// =============================================================================

/**
 * Hide the BrikPanel WooCommerce settings tab when the current user is
 * outside the allowlist. The tab is registered at priority 50 in
 * brikpanel-orders.php so we run at 100 to overwrite that registration.
 */
add_filter( 'woocommerce_settings_tabs_array', function ( $tabs ) {
    if ( ! is_array( $tabs ) || empty( $tabs['brikpanel'] ) ) {
        return $tabs;
    }
    if ( brikpanel_user_can_access() ) {
        return $tabs;
    }
    unset( $tabs['brikpanel'] );
    return $tabs;
}, 100 );

/**
 * Hard-stop the settings tab body + save handler in case a denied user
 * crafts the URL manually. Runs at priority 1, ahead of the modules that
 * register their own content / save handlers.
 */
add_action( 'woocommerce_settings_tabs_brikpanel', function () {
    if ( brikpanel_user_can_access() ) {
        return;
    }
    wp_die( esc_html__( 'You do not have permission to view BrikPanel settings.', 'brikpanel' ), 403 );
}, 1 );

add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( brikpanel_user_can_access() ) {
        return;
    }
    wp_die( esc_html__( 'You do not have permission to change BrikPanel settings.', 'brikpanel' ), 403 );
}, 1 );

/**
 * Settings lock (independent of the access gate above): when the Super Admin
 * has locked BrikPanel settings network-wide, a subsite that is not in the
 * allowlist can still USE BrikPanel but must not see or change the settings
 * tab. Mirrors the three surfaces above so the rule cannot diverge.
 *
 * Different priorities (101 / 2) so this gate stays independent of the
 * access gate — either one denying is sufficient, order is irrelevant.
 */
add_filter( 'woocommerce_settings_tabs_array', function ( $tabs ) {
    if ( ! is_array( $tabs ) || empty( $tabs['brikpanel'] ) ) {
        return $tabs;
    }
    if ( brikpanel_user_can_manage_settings() ) {
        return $tabs;
    }
    unset( $tabs['brikpanel'] );
    return $tabs;
}, 101 );

add_action( 'woocommerce_settings_tabs_brikpanel', function () {
    if ( brikpanel_user_can_manage_settings() ) {
        return;
    }
    wp_die( esc_html__( 'BrikPanel settings are managed by the network administrator on this site.', 'brikpanel' ), 403 );
}, 2 );

add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( brikpanel_user_can_manage_settings() ) {
        return;
    }
    wp_die( esc_html__( 'BrikPanel settings are managed by the network administrator on this site.', 'brikpanel' ), 403 );
}, 2 );

/**
 * Clean denial path for the WC → Settings → BrikPanel tab.
 *
 * Why a redirect instead of leaning on the wp_die() guards above: WooCommerce
 * core builds the wc-admin breadcrumb from the *filtered* settings-tabs array
 * (connect-existing-pages.php). Because we remove the `brikpanel` tab for a
 * denied user, that core code dereferences a now-missing key and emits an
 * "Undefined array key \"brikpanel\"" warning *before* our render-stage
 * wp_die() ever runs — so the blocked user sees a broken-looking screen.
 *
 * Intercepting on admin_init (priority 0, before WC renders anything or
 * processes a settings POST) sidesteps that entirely. The bounce is silent
 * by design: on a locked subsite BrikPanel settings should simply not exist
 * for that user — the tab is already absent from the nav, so a typed/booked
 * URL lands cleanly on WC General with no error chrome, exactly as if the
 * tab were never registered. (An earlier revision surfaced a WC error
 * message here; it read as "something broke" and was dropped on customer
 * feedback — hiding it is the more professional behaviour.) The wp_die()
 * guards stay as defense-in-depth for any non-HTTP code path.
 *
 * Covers both gates (USE + settings-lock) so the same clean experience
 * applies however the user lost access, and works for GET and the save POST
 * alike (the POST action URL still carries page=wc-settings&tab=brikpanel,
 * so the redirect discards the unauthorised save before WC handles it).
 */
add_action( 'admin_init', function () {
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
    if ( brikpanel_user_can_access() && brikpanel_user_can_manage_settings() ) {
        return;
    }

    wp_safe_redirect( add_query_arg(
        [ 'page' => 'wc-settings', 'tab' => 'general' ],
        admin_url( 'admin.php' )
    ) );
    exit;
}, 0 );

/**
 * Strip every BrikPanel-owned menu item from the admin sidebar when the
 * current user is denied. We hook very late (priority 99999) so this runs
 * after every BrikPanel module has registered its menu / submenu pages.
 *
 * Implementation walks the live $menu / $submenu globals and yanks any entry
 * whose slug starts with `brikpanel-`. This is more durable than maintaining
 * a hand-written whitelist, which drifts as new pages are added and breaks
 * silently — the customer-reported regression that led to this module was
 * caused by exactly that kind of drift (a Scheduled Tasks page slug was
 * `brikpanel-cron` while the gate expected `brikpanel-scheduled-tasks`).
 *
 * For direct URL access, removing the entry from $_registered_pages and
 * adding it to $_wp_submenu_nopriv tells WordPress's permission check that
 * this user can't load the page even by typing the URL.
 */
add_action( 'admin_menu', function () {
    if ( brikpanel_user_can_access() ) {
        return;
    }

    global $menu, $submenu, $_registered_pages, $_wp_submenu_nopriv;

    $is_brikpanel_slug = static function ( $slug ) {
        $slug = (string) $slug;
        return strpos( $slug, 'brikpanel-' ) === 0 || strpos( $slug, 'brikpanel_' ) === 0;
    };

    // Top-level entries — $menu is keyed by position; entry[2] is the slug.
    if ( is_array( $menu ) ) {
        foreach ( $menu as $position => $entry ) {
            if ( isset( $entry[2] ) && $is_brikpanel_slug( $entry[2] ) ) {
                remove_menu_page( $entry[2] );
            }
        }
    }

    // Sub-menu entries — including those attached to BrikPanel's own parents
    // (Vendors → Stock Orders / Expenses) and those parked under WooCommerce
    // (Scheduled Tasks, Store Health) plus orphan-parent submenu pages
    // registered with parent_slug = '' (Dashboard, Coupons, Products List,
    // Product Editor, BrikControl) that remove_submenu_page can't reach
    // because the empty parent slug short-circuits its lookup.
    if ( is_array( $submenu ) ) {
        foreach ( $submenu as $parent => $children ) {
            if ( ! is_array( $children ) ) {
                continue;
            }
            foreach ( $children as $key => $entry ) {
                if ( ! isset( $entry[2] ) || ! $is_brikpanel_slug( $entry[2] ) ) {
                    continue;
                }
                unset( $submenu[ $parent ][ $key ] );

                // Block direct URL access — both via the standard hook map
                // and via the per-parent "no privileges" allowlist.
                $hook = get_plugin_page_hookname( $entry[2], (string) $parent );
                if ( $hook ) {
                    unset( $_registered_pages[ $hook ] );
                }
                if ( ! isset( $_wp_submenu_nopriv[ (string) $parent ] ) ) {
                    $_wp_submenu_nopriv[ (string) $parent ] = [];
                }
                $_wp_submenu_nopriv[ (string) $parent ][ $entry[2] ] = true;
            }
        }
    }
}, 99999 );

/**
 * Refuse every brikpanel_* AJAX action when the current user is outside the
 * allowlist. We intercept on admin_init (before do_action('wp_ajax_*') fires)
 * so handlers don't have to embed an extra cap check each.
 *
 * Action names are detected by prefix to cover modules that may add new
 * handlers in the future without us having to maintain a registry.
 */
add_action( 'admin_init', function () {
    if ( ! wp_doing_ajax() ) {
        return;
    }
    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
    if ( $action === '' || strpos( $action, 'brikpanel_' ) !== 0 ) {
        return;
    }
    // Public tracking endpoints (visitor counts, etc.) intentionally accept
    // anonymous calls — they pre-date this gate and the network owner's
    // intent is to limit admin-side BrikPanel, not block storefront tracking.
    if ( ! is_user_logged_in() ) {
        return;
    }
    if ( brikpanel_user_can_access() ) {
        return;
    }
    wp_send_json_error(
        [ 'message' => __( 'You do not have permission to use BrikPanel here.', 'brikpanel' ) ],
        403
    );
}, 1 );
