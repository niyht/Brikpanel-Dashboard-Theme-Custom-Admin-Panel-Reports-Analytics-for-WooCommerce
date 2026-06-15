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
        'view_site'     => [
            'label' => __( 'View store button', 'brikpanel' ),
            'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
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
    return ! in_array( $key, brikpanel_topbar_hidden_items(), true );
}

/**
 * Render the toggle-switch list for the settings field.
 *
 * @param array $field WooCommerce settings field definition.
 */
function brikpanel_render_topbar_items_field( $field ) {
    $items  = brikpanel_topbar_items_label_map();
    $hidden = brikpanel_topbar_hidden_items();

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
                        ?>
                        <li class="brikpanel-topbar-items-row<?php echo $is_visible ? '' : ' is-hidden-item'; ?>">
                            <span class="brikpanel-topbar-items-icon" aria-hidden="true"><?php
                                echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup
                            ?></span>
                            <span class="brikpanel-topbar-items-label"><?php echo esc_html( $item['label'] ); ?></span>
                            <label class="brikpanel-topbar-items-switch">
                                <input type="checkbox" name="brikpanel_topbar_visible_items[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $is_visible ); ?>>
                                <span class="brikpanel-topbar-items-track" aria-hidden="true"></span>
                            </label>
                        </li>
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
                    display: flex;
                    align-items: center;
                    gap: .75rem;
                    padding: .625rem .875rem;
                    border-top: 1px solid #f0f0f0;
                    background: #ffffff;
                    transition: background .15s ease;
                }
                .brikpanel-topbar-items-row:first-child {
                    border-top: 0;
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
                    var cb = e.target.closest('.brikpanel-topbar-items-switch input');
                    if (!cb) { return; }
                    var row = cb.closest('.brikpanel-topbar-items-row');
                    if (row) { row.classList.toggle('is-hidden-item', !cb.checked); }
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
}, 11 );
