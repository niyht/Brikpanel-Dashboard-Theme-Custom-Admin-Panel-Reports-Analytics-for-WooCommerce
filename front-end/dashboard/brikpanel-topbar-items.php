<?php
/**
 * BrikPanel - Top Bar Item Visibility
 *
 * Lets the store owner choose which individual controls appear in the
 * BrikPanel top bar (logo, live-visitor pill, search, quick-create,
 * notifications, view-store, user menu). Adds a custom WooCommerce settings
 * field rendered as a list of toggle switches under the "Dashboard top bar"
 * group, and a render-time helper the top bar consults for each control.
 *
 * Storage: option `brikpanel_topbar_hidden_items` holds an array of the item
 * keys that are HIDDEN. An empty/absent option means every control shows, so
 * existing installs keep the full top bar with no migration.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const BRIKPANEL_TOPBAR_HIDDEN_ITEMS_OPTION = 'brikpanel_topbar_hidden_items';

/** Hidden entries inside the quick-create dropdown (e.g. 'post'). */
const BRIKPANEL_TOPBAR_CREATE_HIDDEN_OPTION = 'brikpanel_topbar_create_hidden_items';

/** Custom top bar shortcut button — owner-defined label and target. */
const BRIKPANEL_TOPBAR_CUSTOM_LABEL_OPTION = 'brikpanel_topbar_custom_link_label';
const BRIKPANEL_TOPBAR_CUSTOM_URL_OPTION   = 'brikpanel_topbar_custom_link_url';

/**
 * Per-item audience rules (who may SEE a control), on top of the binary
 * show/hide. `..._audience` maps item key → 'all' | 'admins' | 'roles';
 * `..._roles` maps item key → array of role slugs the control is hidden from
 * when the audience is 'roles'. Absent/empty means "everyone", so existing
 * installs are unchanged.
 */
const BRIKPANEL_TOPBAR_ITEM_AUDIENCE_OPTION = 'brikpanel_topbar_item_audience';
const BRIKPANEL_TOPBAR_ITEM_ROLES_OPTION    = 'brikpanel_topbar_item_hide_roles';

/**
 * Saved per-item audience map (key => 'all'|'admins'|'roles').
 *
 * @return array<string,string>
 */
function brikpanel_topbar_item_audience_map() {
    $map = get_option( BRIKPANEL_TOPBAR_ITEM_AUDIENCE_OPTION, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Saved per-item hidden-roles map (key => string[]).
 *
 * @return array<string,string[]>
 */
function brikpanel_topbar_item_roles_map() {
    $map = get_option( BRIKPANEL_TOPBAR_ITEM_ROLES_OPTION, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Whether the current user's role passes a top bar item's audience rule.
 * Administrators (and network admins) always pass, so an owner can never lock
 * themselves out of a control they hid from their own role.
 *
 * @param string $key
 * @return bool
 */
function brikpanel_topbar_item_audience_allows( $key ) {
    $map = brikpanel_topbar_item_audience_map();
    // Backward-compat: the hidden-notices bell historically shared the
    // "notifications" audience rule. If no explicit rule has been saved for it
    // yet, inherit the notifications rule so pre-existing configs behave the
    // same until the owner sets a rule of its own. Once the settings page is
    // saved, an explicit key is written and the two become independent.
    if ( $key === 'hidden_notices' && ! isset( $map[ $key ] ) && isset( $map['notifications'] ) ) {
        $key = 'notifications';
    }
    $audience = ( isset( $map[ $key ] ) && in_array( $map[ $key ], [ 'all', 'admins', 'roles' ], true ) ) ? $map[ $key ] : 'all';
    if ( $audience === 'all' ) {
        return true;
    }
    if ( current_user_can( 'manage_options' ) || ( is_multisite() && current_user_can( 'manage_network' ) ) ) {
        return true;
    }
    if ( $audience === 'admins' ) {
        return false;
    }
    // audience 'roles' — hidden from users holding any listed role.
    $roles_map = brikpanel_topbar_item_roles_map();
    $hide      = ( isset( $roles_map[ $key ] ) && is_array( $roles_map[ $key ] ) ) ? $roles_map[ $key ] : [];
    if ( empty( $hide ) ) {
        return true;
    }
    $user = wp_get_current_user();
    if ( ! $user || empty( $user->roles ) ) {
        return true;
    }
    foreach ( (array) $user->roles as $role ) {
        if ( in_array( $role, $hide, true ) ) {
            return false;
        }
    }
    return true;
}

/**
 * The toggleable top bar controls, in the order they appear in the bar.
 * Each entry carries a translatable label and a static inline SVG icon.
 *
 * @return array<string,array{label:string,icon:string}>
 */
function brikpanel_topbar_items_label_map() {
    return [
        'brand'         => [
            'label' => __( 'Logo &amp; store name', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1-5h16l1 5"/><path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg>',
        ],
        'live'          => [
            'label' => __( 'Live visitors counter', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        ],
        'search'        => [
            'label' => __( 'Search bar', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
        ],
        'create'        => [
            'label' => __( 'Quick create button', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        ],
        'notifications' => [
            'label' => __( 'Order notifications', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
        ],
        'hidden_notices' => [
            'label' => __( 'Hidden notices bell', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><line x1="1" y1="1" x2="23" y2="23"/></svg>',
        ],
        'view_site'     => [
            'label' => __( 'View store button', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        ],
        'custom_link'   => [
            'label' => __( 'Custom shortcut button', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        ],
        'user'          => [
            'label' => __( 'User menu', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        ],
    ];
}

/**
 * Valid item keys.
 *
 * @return string[]
 */
function brikpanel_topbar_item_keys() {
    return array_keys( brikpanel_topbar_items_label_map() );
}

/**
 * Keys of the top bar controls the owner has hidden.
 *
 * @return string[]
 */
function brikpanel_topbar_hidden_items() {
    $hidden = get_option( BRIKPANEL_TOPBAR_HIDDEN_ITEMS_OPTION, [] );
    if ( ! is_array( $hidden ) ) {
        return [];
    }
    // Guard against stale keys lingering after a rename.
    return array_values( array_intersect( $hidden, brikpanel_topbar_item_keys() ) );
}

/**
 * Whether a given top bar control should render. Unknown keys default to
 * visible so a new control is shown until the owner opts out.
 *
 * @param string $key
 * @return bool
 */
function brikpanel_topbar_item_is_visible( $key ) {
    if ( in_array( $key, brikpanel_topbar_hidden_items(), true ) ) {
        return false;
    }
    return brikpanel_topbar_item_audience_allows( $key );
}

/**
 * The entries inside the quick-create dropdown, in render order. Each carries a
 * translatable label; the matching <a> in the bar is gated by its key so an
 * owner can strip non-store entries (e.g. "New post") for a Woo-focused menu.
 *
 * @return array<string,string>
 */
function brikpanel_topbar_create_items_label_map() {
    return [
        'product' => __( 'New product', 'brikpanel' ),
        'order'   => __( 'New order', 'brikpanel' ),
        'coupon'  => __( 'New coupon', 'brikpanel' ),
        'post'    => __( 'New post', 'brikpanel' ),
    ];
}

/**
 * Valid quick-create entry keys.
 *
 * @return string[]
 */
function brikpanel_topbar_create_item_keys() {
    return array_keys( brikpanel_topbar_create_items_label_map() );
}

/**
 * Keys of the quick-create entries the owner has hidden.
 *
 * @return string[]
 */
function brikpanel_topbar_create_hidden_items() {
    $hidden = get_option( BRIKPANEL_TOPBAR_CREATE_HIDDEN_OPTION, [] );
    if ( ! is_array( $hidden ) ) {
        return [];
    }
    return array_values( array_intersect( $hidden, brikpanel_topbar_create_item_keys() ) );
}

/**
 * Whether a quick-create entry should render. Unknown keys default to visible.
 *
 * @param string $key
 * @return bool
 */
function brikpanel_topbar_create_item_is_visible( $key ) {
    return ! in_array( $key, brikpanel_topbar_create_hidden_items(), true );
}

/**
 * Whether at least one quick-create entry is still visible. The Create button
 * hides itself when every entry has been turned off, so the bar never shows an
 * empty dropdown.
 *
 * @return bool
 */
function brikpanel_topbar_has_visible_create_items() {
    return count( brikpanel_topbar_create_hidden_items() ) < count( brikpanel_topbar_create_item_keys() );
}

/**
 * Sanitize an owner-supplied shortcut URL. Accepts absolute http(s) URLs and
 * site-relative paths (e.g. "/wp-admin/edit.php?post_type=shop_order"); strips
 * dangerous schemes (javascript:, data:, …) so the bar can never carry an XSS
 * vector. Returns '' for anything that does not survive.
 *
 * @param mixed $raw
 * @return string
 */
function brikpanel_topbar_sanitize_link_url( $raw ) {
    $raw = trim( (string) $raw );
    if ( $raw === '' ) {
        return '';
    }
    // Reject any explicit non-http(s) scheme up front (covers javascript:, data:, vbscript:, file:, …).
    if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $raw ) && ! preg_match( '#^https?:#i', $raw ) ) {
        return '';
    }
    // A scheme-less path that is not already rooted (e.g. "wp-admin/edit.php") is
    // almost always a site path typed without the leading slash. Root it so
    // esc_url does not read the first segment as a host ("http://wp-admin/…").
    // Protocol-relative URLs ("//host/…") start with "/" and are left untouched.
    if ( ! preg_match( '#^https?:#i', $raw ) && $raw[0] !== '/' ) {
        $raw = '/' . ltrim( $raw, '/' );
    }
    // esc_url_raw keeps root-relative paths intact and enforces the protocol allow-list for absolute URLs.
    return esc_url_raw( $raw, [ 'http', 'https' ] );
}

/**
 * The configured custom shortcut, or null when not fully set up. Both a label
 * and a valid target are required before the button appears in the bar.
 *
 * @return array{label:string,url:string}|null
 */
function brikpanel_topbar_custom_link() {
    $label = sanitize_text_field( (string) get_option( BRIKPANEL_TOPBAR_CUSTOM_LABEL_OPTION, '' ) );
    $url   = brikpanel_topbar_sanitize_link_url( get_option( BRIKPANEL_TOPBAR_CUSTOM_URL_OPTION, '' ) );
    if ( $label === '' || $url === '' ) {
        return null;
    }
    return [ 'label' => $label, 'url' => $url ];
}

/**
 * Render the toggle-switch list for the settings field.
 *
 * @param array $field WooCommerce settings field definition.
 */
function brikpanel_render_topbar_items_field( $field ) {
    $items  = brikpanel_topbar_items_label_map();
    $hidden = brikpanel_topbar_hidden_items();

    // Per-item audience (who may see each control) + the role list to choose from.
    $audience_map = brikpanel_topbar_item_audience_map();
    $roles_map    = brikpanel_topbar_item_roles_map();
    $all_roles    = function_exists( 'brikpanel_access_collect_roles' ) ? brikpanel_access_collect_roles() : [];

    $create_items  = brikpanel_topbar_create_items_label_map();
    $create_hidden = brikpanel_topbar_create_hidden_items();
    $custom_label  = sanitize_text_field( (string) get_option( BRIKPANEL_TOPBAR_CUSTOM_LABEL_OPTION, '' ) );
    $custom_url    = trim( (string) get_option( BRIKPANEL_TOPBAR_CUSTOM_URL_OPTION, '' ) );

    // Show a real, store-correct orders URL as the link placeholder (HPOS-aware).
    $orders_url = ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() )
        ? admin_url( 'admin.php?page=wc-orders' )
        : admin_url( 'edit.php?post_type=shop_order' );

    $title   = ! empty( $field['name'] ) ? esc_html( $field['name'] ) : '';
    $tooltip = ( ! empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ) ? wc_help_tip( $field['desc'] ) : '';
    $help    = ( empty( $field['desc_tip'] ) && ! empty( $field['desc'] ) ) ? $field['desc'] : '';
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></label>
            <?php echo $tooltip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_help_tip returns safe markup ?>
        </th>
        <td class="forminp">
            <div class="brikpanel-topbar-items" id="brikpanel-topbar-items">
                <?php if ( $help !== '' ) : ?>
                    <p class="brikpanel-topbar-items-help"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <input type="hidden" name="brikpanel_topbar_items_submitted" value="1">
                <ul class="brikpanel-topbar-items-list" role="list">
                    <?php foreach ( $items as $key => $item ) :
                        $is_visible = ! in_array( $key, $hidden, true );
                        $audience   = ( isset( $audience_map[ $key ] ) && in_array( $audience_map[ $key ], [ 'all', 'admins', 'roles' ], true ) ) ? $audience_map[ $key ] : 'all';
                        $item_roles = ( isset( $roles_map[ $key ] ) && is_array( $roles_map[ $key ] ) ) ? array_map( 'strval', $roles_map[ $key ] ) : [];
                        ?>
                        <li class="brikpanel-topbar-items-row<?php echo $is_visible ? '' : ' is-hidden-item'; ?>">
                            <div class="brikpanel-topbar-items-main">
                                <span class="brikpanel-topbar-items-icon" aria-hidden="true"><?php
                                    echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup
                                ?></span>
                                <span class="brikpanel-topbar-items-label"><?php echo esc_html( $item['label'] ); ?></span>
                                <label class="brikpanel-topbar-items-switch">
                                    <input type="checkbox" name="brikpanel_topbar_visible_items[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_visible ); ?>>
                                    <span class="brikpanel-topbar-items-track" aria-hidden="true"></span>
                                </label>
                            </div>
                            <div class="brikpanel-topbar-items-audience-line">
                                <span class="brikpanel-topbar-items-audience-label"><?php esc_html_e( 'Visible to', 'brikpanel' ); ?></span>
                                <select class="brikpanel-topbar-items-audience" name="brikpanel_topbar_item_audience[<?php echo esc_attr( $key ); ?>]" data-bp-audience aria-label="<?php esc_attr_e( 'Who can see this control', 'brikpanel' ); ?>">
                                    <option value="all" <?php selected( $audience, 'all' ); ?>><?php esc_html_e( 'Everyone', 'brikpanel' ); ?></option>
                                    <option value="admins" <?php selected( $audience, 'admins' ); ?>><?php esc_html_e( 'Admins only', 'brikpanel' ); ?></option>
                                    <option value="roles" <?php selected( $audience, 'roles' ); ?>><?php esc_html_e( 'Specific roles', 'brikpanel' ); ?></option>
                                </select>
                            </div>
                            <?php if ( ! empty( $all_roles ) ) : ?>
                                <div class="brikpanel-topbar-items-roles" data-bp-roles <?php echo $audience === 'roles' ? '' : 'hidden'; ?>>
                                    <span class="brikpanel-topbar-items-roles-title"><?php esc_html_e( 'Hide from these roles', 'brikpanel' ); ?></span>
                                    <div class="brikpanel-topbar-items-roles-grid">
                                        <?php foreach ( $all_roles as $role_slug => $role_name ) : ?>
                                            <label class="brikpanel-topbar-items-role">
                                                <input type="checkbox" name="brikpanel_topbar_item_roles[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( (string) $role_slug, $item_roles, true ) ); ?>>
                                                <span><?php echo esc_html( $role_name ); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </li>

                        <?php if ( $key === 'create' ) : ?>
                            <li class="brikpanel-topbar-subpanel" data-bp-subpanel="create">
                                <p class="brikpanel-topbar-subpanel-title"><?php esc_html_e( 'Items in the create menu', 'brikpanel' ); ?></p>
                                <ul class="brikpanel-topbar-subitems" role="list">
                                    <?php foreach ( $create_items as $ckey => $clabel ) :
                                        $c_visible = ! in_array( $ckey, $create_hidden, true );
                                        ?>
                                        <li class="brikpanel-topbar-subitem<?php echo $c_visible ? '' : ' is-hidden-item'; ?>">
                                            <span class="brikpanel-topbar-subitem-label"><?php echo esc_html( $clabel ); ?></span>
                                            <label class="brikpanel-topbar-items-switch">
                                                <input type="checkbox" name="brikpanel_topbar_create_visible_items[]" value="<?php echo esc_attr( $ckey ); ?>" <?php checked( $c_visible ); ?>>
                                                <span class="brikpanel-topbar-items-track" aria-hidden="true"></span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php elseif ( $key === 'custom_link' ) : ?>
                            <li class="brikpanel-topbar-subpanel" data-bp-subpanel="custom_link">
                                <p class="brikpanel-topbar-subpanel-title"><?php esc_html_e( 'Set a label and a target, then turn the button on above. The button stays hidden until both are filled in.', 'brikpanel' ); ?></p>
                                <div class="brikpanel-topbar-custom-fields">
                                    <label class="brikpanel-topbar-custom-field">
                                        <span class="brikpanel-topbar-custom-field-label"><?php esc_html_e( 'Button label', 'brikpanel' ); ?></span>
                                        <input type="text" name="brikpanel_topbar_custom_link_label" value="<?php echo esc_attr( $custom_label ); ?>" maxlength="40" placeholder="<?php esc_attr_e( 'All orders', 'brikpanel' ); ?>">
                                    </label>
                                    <label class="brikpanel-topbar-custom-field">
                                        <span class="brikpanel-topbar-custom-field-label"><?php esc_html_e( 'Button link', 'brikpanel' ); ?></span>
                                        <input type="text" name="brikpanel_topbar_custom_link_url" value="<?php echo esc_attr( $custom_url ); ?>" placeholder="<?php echo esc_attr( $orders_url ); ?>">
                                    </label>
                                </div>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <style id="brikpanel-topbar-items-style">
                .brikpanel-topbar-items {
                    max-width: 480px;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                }
                .brikpanel-topbar-items-help {
                    margin: 0 0 .625rem;
                    font-size: .8125rem;
                    line-height: 1.5;
                    color: #616161;
                }
                .brikpanel-topbar-items-list {
                    margin: 0;
                    padding: 0;
                    list-style: none;
                    background: #ffffff;
                    border: 1px solid #e3e3e3;
                    border-radius: .5rem;
                    overflow: hidden;
                }
                .brikpanel-topbar-items-row {
                    border-top: 1px solid #f0f0f0;
                    background: #ffffff;
                    transition: background .15s ease;
                }
                .brikpanel-topbar-items-row:first-child {
                    border-top: 0;
                }
                .brikpanel-topbar-items-main {
                    display: flex;
                    align-items: center;
                    gap: .75rem;
                    padding: .625rem .875rem;
                }
                /* Audience control sits on its own line beneath the label + toggle
                   so the four columns never crowd each other in WooCommerce's
                   narrow settings column. */
                .brikpanel-topbar-items-audience-line {
                    display: flex;
                    align-items: center;
                    gap: .5rem;
                    padding: 0 .875rem .625rem 3.25rem;
                }
                .brikpanel-topbar-items-audience-label {
                    font-size: .75rem;
                    font-weight: 600;
                    color: #616161;
                    flex-shrink: 0;
                }
                /* WooCommerce's admin form styles force select width, max-width,
                   padding, line-height and font-size with class-based selectors.
                   The container ID here raises specificity to (1,1,0) so every one
                   of ours wins and the select stays compact and on-brand. */
                #brikpanel-topbar-items .brikpanel-topbar-items-audience {
                    flex: 0 0 auto;
                    width: 11rem;
                    max-width: 11rem;
                    min-width: 0;
                    height: 34px;
                    min-height: 0;
                    margin: 0;
                    padding: 0 1.5rem 0 .5rem;
                    font-size: .8125rem;
                    line-height: 1.5;
                    color: #303030;
                    background-color: #fff;
                    border: 1px solid #8a8a8a;
                    border-radius: .375rem;
                }
                .brikpanel-topbar-items-roles {
                    padding: .5rem .875rem .75rem 3.25rem;
                    background: #fbfbfb;
                    border-top: 1px dashed #e3e3e3;
                }
                .brikpanel-topbar-items-roles[hidden] {
                    display: none;
                }
                .brikpanel-topbar-items-roles-title {
                    display: block;
                    margin-bottom: .375rem;
                    font-size: .75rem;
                    font-weight: 600;
                    color: #616161;
                }
                .brikpanel-topbar-items-roles-grid {
                    display: flex;
                    flex-wrap: wrap;
                    gap: .375rem .875rem;
                }
                .brikpanel-topbar-items-role {
                    display: inline-flex;
                    align-items: center;
                    gap: .3rem;
                    font-size: .8125rem;
                    color: #303030;
                }
                .brikpanel-topbar-items-icon {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 30px;
                    height: 30px;
                    flex-shrink: 0;
                    border-radius: .375rem;
                    background: #f7f7f7;
                    color: #303030;
                    transition: color .15s ease, background .15s ease;
                }
                .brikpanel-topbar-items-icon svg {
                    width: 16px;
                    height: 16px;
                }
                .brikpanel-topbar-items-label {
                    flex: 1;
                    font-size: .875rem;
                    font-weight: 400;
                    color: #303030;
                    transition: color .15s ease;
                }
                .brikpanel-topbar-items-row.is-hidden-item .brikpanel-topbar-items-icon {
                    color: #8a8a8a;
                    background: #f7f7f7;
                }
                .brikpanel-topbar-items-row.is-hidden-item .brikpanel-topbar-items-label {
                    color: #8a8a8a;
                }
                /* Sub-panel under a parent row (create entries, custom link fields). */
                .brikpanel-topbar-subpanel {
                    list-style: none;
                    border-top: 1px solid #f0f0f0;
                    padding: .75rem .875rem .875rem 3.25rem;
                    background: #fbfbfb;
                }
                .brikpanel-topbar-subpanel-title {
                    margin: 0 0 .5rem;
                    font-size: .75rem;
                    line-height: 1.5;
                    color: #8a8a8a;
                }
                .brikpanel-topbar-subitems {
                    margin: 0;
                    padding: 0;
                    list-style: none;
                    background: #ffffff;
                    border: 1px solid #e3e3e3;
                    border-radius: .5rem;
                    overflow: hidden;
                }
                .brikpanel-topbar-subitem {
                    display: flex;
                    align-items: center;
                    gap: .75rem;
                    padding: .5rem .75rem;
                    border-top: 1px solid #f0f0f0;
                }
                .brikpanel-topbar-subitem:first-child {
                    border-top: 0;
                }
                .brikpanel-topbar-subitem-label {
                    flex: 1;
                    font-size: .8125rem;
                    color: #303030;
                    transition: color .15s ease;
                }
                .brikpanel-topbar-subitem.is-hidden-item .brikpanel-topbar-subitem-label {
                    color: #8a8a8a;
                }
                .brikpanel-topbar-custom-fields {
                    display: flex;
                    flex-direction: column;
                    gap: .625rem;
                }
                .brikpanel-topbar-custom-field {
                    display: flex;
                    flex-direction: column;
                    gap: .375rem;
                }
                .brikpanel-topbar-custom-field-label {
                    font-size: .8125rem;
                    font-weight: 600;
                    color: #303030;
                }
                .brikpanel-topbar-custom-field input[type="text"] {
                    width: 100%;
                    max-width: 360px;
                    padding: .5rem .625rem;
                    font-size: .875rem;
                    color: #303030;
                    border: 1px solid #8a8a8a;
                    border-radius: .5rem;
                    background: #ffffff;
                    box-shadow: none;
                }
                .brikpanel-topbar-custom-field input[type="text"]:focus {
                    border-color: #303030;
                    box-shadow: 0 0 0 1px #303030;
                    outline: none;
                }
                .brikpanel-topbar-custom-field input[type="text"]::placeholder {
                    color: #8a8a8a;
                }
                /* Toggle switch — matches the BrikPanel design system. */
                .brikpanel-topbar-items-switch {
                    position: relative;
                    display: inline-block;
                    width: 40px;
                    height: 22px;
                    flex-shrink: 0;
                    margin: 0;
                    cursor: pointer;
                }
                .brikpanel-topbar-items-switch input {
                    position: absolute;
                    opacity: 0;
                    width: 0;
                    height: 0;
                    margin: 0;
                }
                .brikpanel-topbar-items-track {
                    position: absolute;
                    inset: 0;
                    background: #ccc;
                    border-radius: 22px;
                    transition: background .15s ease;
                }
                .brikpanel-topbar-items-track::before {
                    content: "";
                    position: absolute;
                    top: 3px;
                    left: 3px;
                    width: 16px;
                    height: 16px;
                    background: #ffffff;
                    border-radius: 50%;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
                    transition: transform .15s ease;
                }
                .brikpanel-topbar-items-switch input:checked + .brikpanel-topbar-items-track {
                    background: #303030;
                }
                .brikpanel-topbar-items-switch input:checked + .brikpanel-topbar-items-track::before {
                    transform: translateX(18px);
                }
                .brikpanel-topbar-items-switch input:focus-visible + .brikpanel-topbar-items-track {
                    box-shadow: 0 0 0 2px #fff, 0 0 0 4px #303030;
                }
            </style>
            <script>
            (function () {
                var root = document.getElementById('brikpanel-topbar-items');
                if (!root || root.dataset.bpInit === '1') { return; }
                root.dataset.bpInit = '1';
                root.addEventListener('change', function (e) {
                    var audience = e.target.closest('[data-bp-audience]');
                    if (audience) {
                        var row = audience.closest('.brikpanel-topbar-items-row');
                        var panel = row ? row.querySelector('[data-bp-roles]') : null;
                        if (panel) {
                            if (audience.value === 'roles') { panel.removeAttribute('hidden'); }
                            else { panel.setAttribute('hidden', ''); }
                        }
                        return;
                    }
                    var cb = e.target.closest('.brikpanel-topbar-items-switch input');
                    if (!cb) { return; }
                    var row2 = cb.closest('.brikpanel-topbar-subitem') || cb.closest('.brikpanel-topbar-items-row');
                    if (row2) { row2.classList.toggle('is-hidden-item', !cb.checked); }
                });
            })();
            </script>
        </td>
    </tr>
    <?php
}
add_action( 'woocommerce_admin_field_brikpanel_topbar_items', 'brikpanel_render_topbar_items_field' );

/**
 * Persist the hidden-item list when the BrikPanel settings tab is saved.
 * Runs after WooCommerce's default option write (priority 11). A hidden marker
 * input lets us distinguish "saved with nothing checked" (hide all) from "this
 * field was not on the submitted form" (leave the option untouched).
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( ! isset( $_POST['brikpanel_topbar_items_submitted'] ) ) {
        return;
    }

    $known       = brikpanel_topbar_item_keys();
    $visible_raw = ( isset( $_POST['brikpanel_topbar_visible_items'] ) && is_array( $_POST['brikpanel_topbar_visible_items'] ) )
        ? wp_unslash( $_POST['brikpanel_topbar_visible_items'] )
        : [];

    $visible = [];
    foreach ( $visible_raw as $key ) {
        if ( is_string( $key ) && in_array( $key, $known, true ) && ! in_array( $key, $visible, true ) ) {
            $visible[] = $key;
        }
    }

    $hidden = array_values( array_diff( $known, $visible ) );
    update_option( BRIKPANEL_TOPBAR_HIDDEN_ITEMS_OPTION, $hidden, false );

    // Quick-create dropdown entries — same hidden-list semantics as the bar items.
    $create_known   = brikpanel_topbar_create_item_keys();
    $create_vis_raw = ( isset( $_POST['brikpanel_topbar_create_visible_items'] ) && is_array( $_POST['brikpanel_topbar_create_visible_items'] ) )
        ? wp_unslash( $_POST['brikpanel_topbar_create_visible_items'] )
        : [];
    $create_visible = [];
    foreach ( $create_vis_raw as $key ) {
        if ( is_string( $key ) && in_array( $key, $create_known, true ) && ! in_array( $key, $create_visible, true ) ) {
            $create_visible[] = $key;
        }
    }
    $create_hidden = array_values( array_diff( $create_known, $create_visible ) );
    update_option( BRIKPANEL_TOPBAR_CREATE_HIDDEN_OPTION, $create_hidden, false );

    // Custom shortcut button — store the raw owner input; sanitize on read/render.
    $custom_label = isset( $_POST['brikpanel_topbar_custom_link_label'] )
        ? sanitize_text_field( wp_unslash( $_POST['brikpanel_topbar_custom_link_label'] ) )
        : '';
    $custom_url = isset( $_POST['brikpanel_topbar_custom_link_url'] )
        ? brikpanel_topbar_sanitize_link_url( wp_unslash( $_POST['brikpanel_topbar_custom_link_url'] ) )
        : '';
    update_option( BRIKPANEL_TOPBAR_CUSTOM_LABEL_OPTION, $custom_label, false );
    update_option( BRIKPANEL_TOPBAR_CUSTOM_URL_OPTION, $custom_url, false );

    // Per-item audience (who may see each control) + hidden-roles list.
    $valid_roles   = function_exists( 'brikpanel_access_collect_roles' ) ? array_keys( brikpanel_access_collect_roles() ) : [];
    $audience_in   = ( isset( $_POST['brikpanel_topbar_item_audience'] ) && is_array( $_POST['brikpanel_topbar_item_audience'] ) )
        ? wp_unslash( $_POST['brikpanel_topbar_item_audience'] )
        : [];
    $roles_in      = ( isset( $_POST['brikpanel_topbar_item_roles'] ) && is_array( $_POST['brikpanel_topbar_item_roles'] ) )
        ? wp_unslash( $_POST['brikpanel_topbar_item_roles'] )
        : [];
    $audience_out  = [];
    $roles_out     = [];
    foreach ( $known as $item_key ) {
        $aud = isset( $audience_in[ $item_key ] ) ? sanitize_key( $audience_in[ $item_key ] ) : 'all';
        if ( ! in_array( $aud, [ 'all', 'admins', 'roles' ], true ) ) {
            $aud = 'all';
        }
        if ( $aud !== 'all' ) {
            $audience_out[ $item_key ] = $aud;
        }
        if ( $aud === 'roles' && isset( $roles_in[ $item_key ] ) && is_array( $roles_in[ $item_key ] ) ) {
            $picked = array_values( array_intersect(
                array_map( 'sanitize_key', $roles_in[ $item_key ] ),
                $valid_roles
            ) );
            if ( ! empty( $picked ) ) {
                $roles_out[ $item_key ] = $picked;
            }
        }
    }
    update_option( BRIKPANEL_TOPBAR_ITEM_AUDIENCE_OPTION, $audience_out, false );
    update_option( BRIKPANEL_TOPBAR_ITEM_ROLES_OPTION, $roles_out, false );
}, 11 );
