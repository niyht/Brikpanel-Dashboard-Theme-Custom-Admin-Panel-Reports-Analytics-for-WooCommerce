<?php
/**
 * BrikPanel - Embedded Dashboard Widget Access
 *
 * Adds per-widget audience rules for the WordPress dashboard widgets the owner
 * chose to embed inside the BrikPanel dashboard (the "Include WordPress
 * dashboard widgets" setting). Each embedded widget — most notably the
 * "Site Health Status" widget — can be shown to Everyone, Admins only, or
 * hidden from specific roles, exactly like the top bar controls.
 *
 * Storage:
 *   - `brikpanel_dashboard_widget_audience`   maps widget_id → 'all'|'admins'|'roles'
 *   - `brikpanel_dashboard_widget_hide_roles` maps widget_id → array of role slugs
 *     the widget is hidden from when the audience is 'roles'.
 * Absent/empty means "everyone", so existing installs keep every embedded widget
 * visible with no migration. Administrators (and network admins) always pass so
 * an owner can never hide a widget from their own account by mistake.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const BRIKPANEL_DASH_WIDGET_AUDIENCE_OPTION = 'brikpanel_dashboard_widget_audience';
const BRIKPANEL_DASH_WIDGET_ROLES_OPTION    = 'brikpanel_dashboard_widget_hide_roles';

/**
 * Saved per-widget audience map (widget_id => 'all'|'admins'|'roles').
 *
 * @return array<string,string>
 */
function brikpanel_dashboard_widget_audience_map() {
    $map = get_option( BRIKPANEL_DASH_WIDGET_AUDIENCE_OPTION, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Saved per-widget hidden-roles map (widget_id => string[]).
 *
 * @return array<string,string[]>
 */
function brikpanel_dashboard_widget_roles_map() {
    $map = get_option( BRIKPANEL_DASH_WIDGET_ROLES_OPTION, [] );
    return is_array( $map ) ? $map : [];
}

/**
 * Whether the current user's role passes an embedded widget's audience rule.
 * Administrators (and network admins) always pass. Unknown widgets default to
 * visible so a newly embedded widget shows until the owner sets a rule.
 *
 * @param string $widget_id
 * @return bool
 */
function brikpanel_dashboard_widget_audience_allows( $widget_id ) {
    $map      = brikpanel_dashboard_widget_audience_map();
    $audience = ( isset( $map[ $widget_id ] ) && in_array( $map[ $widget_id ], [ 'all', 'admins', 'roles' ], true ) ) ? $map[ $widget_id ] : 'all';
    if ( $audience === 'all' ) {
        return true;
    }
    // Real administrators (and multisite super admins) always pass, so an owner
    // can never hide a widget from their own account. Deliberately role-based via
    // brikpanel_user_is_administrator(): stores routinely grant `manage_options`
    // to shop managers with a role editor, and a bare capability check would let
    // those managers slip past every rule here — defeating the whole point of
    // keeping a sensitive widget (Site Health) away from staff.
    $is_admin = function_exists( 'brikpanel_user_is_administrator' )
        ? brikpanel_user_is_administrator()
        : ( current_user_can( 'manage_options' ) || ( is_multisite() && current_user_can( 'manage_network' ) ) );
    if ( $is_admin ) {
        return true;
    }
    if ( $audience === 'admins' ) {
        return false;
    }
    // audience 'roles' — hidden from users holding any listed role.
    $roles_map = brikpanel_dashboard_widget_roles_map();
    $hide      = ( isset( $roles_map[ $widget_id ] ) && is_array( $roles_map[ $widget_id ] ) ) ? $roles_map[ $widget_id ] : [];
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
 * The embedded widgets the owner has selected, as widget_id => title. Reads the
 * saved multiselect and resolves titles from the live widget registry so the
 * access list mirrors exactly what renders on the dashboard.
 *
 * @return array<string,string>
 */
function brikpanel_dashboard_selected_embedded_widgets() {
    $selected = (array) get_option( 'brikpanel_dashboard_wp_widgets', [] );
    if ( empty( $selected ) || ! function_exists( 'brikpanel_collect_dashboard_widgets' ) ) {
        return [];
    }
    $widgets = brikpanel_collect_dashboard_widgets();
    $out     = [];
    foreach ( $selected as $widget_id ) {
        if ( is_string( $widget_id ) && isset( $widgets[ $widget_id ] ) ) {
            $out[ $widget_id ] = (string) $widgets[ $widget_id ]['title'];
        }
    }
    return $out;
}

/**
 * Render the audience list for the settings field — one row per embedded widget
 * with an audience dropdown and (for the "Specific roles" choice) a role list.
 *
 * @param array $field WooCommerce settings field definition.
 */
function brikpanel_render_dashboard_widget_access_field( $field ) {
    $widgets      = brikpanel_dashboard_selected_embedded_widgets();
    $audience_map = brikpanel_dashboard_widget_audience_map();
    $roles_map    = brikpanel_dashboard_widget_roles_map();
    $all_roles    = function_exists( 'brikpanel_access_collect_roles' ) ? brikpanel_access_collect_roles() : [];

    $title = ! empty( $field['name'] ) ? esc_html( $field['name'] ) : '';
    $help  = ! empty( $field['desc'] ) ? $field['desc'] : '';
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?></label>
        </th>
        <td class="forminp">
            <div class="brikpanel-topbar-items" id="brikpanel-dash-widget-access">
                <?php if ( $help !== '' ) : ?>
                    <p class="brikpanel-topbar-items-help"><?php echo esc_html( $help ); ?></p>
                <?php endif; ?>
                <input type="hidden" name="brikpanel_dashboard_widget_access_submitted" value="1">
                <?php if ( empty( $widgets ) ) : ?>
                    <p class="brikpanel-topbar-items-help" style="margin:0;">
                        <?php esc_html_e( 'Pick one or more widgets in "Include WordPress dashboard widgets" above and save. Each embedded widget then appears here so you can choose who sees it.', 'brikpanel' ); ?>
                    </p>
                <?php else : ?>
                    <ul class="brikpanel-topbar-items-list" role="list">
                        <?php foreach ( $widgets as $widget_id => $widget_title ) :
                            $audience   = ( isset( $audience_map[ $widget_id ] ) && in_array( $audience_map[ $widget_id ], [ 'all', 'admins', 'roles' ], true ) ) ? $audience_map[ $widget_id ] : 'all';
                            $item_roles = ( isset( $roles_map[ $widget_id ] ) && is_array( $roles_map[ $widget_id ] ) ) ? array_map( 'strval', $roles_map[ $widget_id ] ) : [];
                            ?>
                            <li class="brikpanel-topbar-items-row">
                                <div class="brikpanel-topbar-items-main">
                                    <span class="brikpanel-topbar-items-label"><?php echo esc_html( $widget_title ); ?></span>
                                </div>
                                <div class="brikpanel-topbar-items-audience-line" style="padding-left:.875rem;">
                                    <span class="brikpanel-topbar-items-audience-label"><?php esc_html_e( 'Visible to', 'brikpanel' ); ?></span>
                                    <select class="brikpanel-topbar-items-audience" name="brikpanel_dashboard_widget_audience[<?php echo esc_attr( $widget_id ); ?>]" data-bp-audience aria-label="<?php esc_attr_e( 'Who can see this widget', 'brikpanel' ); ?>">
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
                                                    <input type="checkbox" name="brikpanel_dashboard_widget_roles[<?php echo esc_attr( $widget_id ); ?>][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( (string) $role_slug, $item_roles, true ) ); ?>>
                                                    <span><?php echo esc_html( $role_name ); ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <script>
            (function () {
                var root = document.getElementById('brikpanel-dash-widget-access');
                if (!root || root.dataset.bpInit === '1') { return; }
                root.dataset.bpInit = '1';
                root.addEventListener('change', function (e) {
                    var audience = e.target.closest('[data-bp-audience]');
                    if (!audience) { return; }
                    var row = audience.closest('.brikpanel-topbar-items-row');
                    var panel = row ? row.querySelector('[data-bp-roles]') : null;
                    if (!panel) { return; }
                    if (audience.value === 'roles') { panel.removeAttribute('hidden'); }
                    else { panel.setAttribute('hidden', ''); }
                });
            })();
            </script>
        </td>
    </tr>
    <?php
}
add_action( 'woocommerce_admin_field_brikpanel_dashboard_widget_access', 'brikpanel_render_dashboard_widget_access_field' );

/**
 * Persist the per-widget audience rules when the BrikPanel settings tab is
 * saved. Runs at priority 11 (after WooCommerce writes the multiselect) and
 * only touches the options when this field was actually on the submitted form.
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( ! isset( $_POST['brikpanel_dashboard_widget_access_submitted'] ) ) {
        return;
    }

    $valid_roles = function_exists( 'brikpanel_access_collect_roles' ) ? array_keys( brikpanel_access_collect_roles() ) : [];
    $audience_in = ( isset( $_POST['brikpanel_dashboard_widget_audience'] ) && is_array( $_POST['brikpanel_dashboard_widget_audience'] ) )
        ? wp_unslash( $_POST['brikpanel_dashboard_widget_audience'] )
        : [];
    $roles_in    = ( isset( $_POST['brikpanel_dashboard_widget_roles'] ) && is_array( $_POST['brikpanel_dashboard_widget_roles'] ) )
        ? wp_unslash( $_POST['brikpanel_dashboard_widget_roles'] )
        : [];

    $audience_out = [];
    $roles_out    = [];
    foreach ( $audience_in as $widget_id => $aud ) {
        $widget_id = sanitize_text_field( (string) $widget_id );
        if ( $widget_id === '' ) {
            continue;
        }
        $aud = sanitize_key( (string) $aud );
        if ( ! in_array( $aud, [ 'all', 'admins', 'roles' ], true ) ) {
            $aud = 'all';
        }
        if ( $aud !== 'all' ) {
            $audience_out[ $widget_id ] = $aud;
        }
        if ( $aud === 'roles' && isset( $roles_in[ $widget_id ] ) && is_array( $roles_in[ $widget_id ] ) ) {
            $picked = array_values( array_intersect(
                array_map( 'sanitize_key', $roles_in[ $widget_id ] ),
                $valid_roles
            ) );
            if ( ! empty( $picked ) ) {
                $roles_out[ $widget_id ] = $picked;
            }
        }
    }
    update_option( BRIKPANEL_DASH_WIDGET_AUDIENCE_OPTION, $audience_out, false );
    update_option( BRIKPANEL_DASH_WIDGET_ROLES_OPTION, $roles_out, false );
}, 11 );
