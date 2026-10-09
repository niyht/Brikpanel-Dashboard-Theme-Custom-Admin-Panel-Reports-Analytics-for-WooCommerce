<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pure sidebar-icon helpers (brikpanel_nav_icon_style / brikpanel_nav_icon_src /
// brikpanel_nav_line_icon_slugs) now live in brikpanel-nav-icon-helpers.php,
// which is loaded on every admin request. They were moved out of this file so
// the navigation customizer settings screen — which is loaded even when the
// modern navigation toggle is OFF — can still resolve icons without fataling on
// an undefined function.

/**
 * Whether the whole navigation override must stand down for this request.
 *
 * Three independent reasons to leave the native sidebar alone:
 *
 *  1. The custom navigation rebuilds the admin sidebar based on each subsite's
 *     own $menu / $submenu globals — those globals don't exist (and the link
 *     targets don't apply) on the Network Admin or User Admin chrome, so super
 *     admins must see the unmodified core layout there.
 *  2. Desktop Mode: the dock is built from the admin menu and replaces this
 *     sidebar, and the #wpcontent left margin this nav relies on would orphan
 *     inside a chromeless window (see includes/brikpanel-desktop-mode-compat.php).
 *  3. The user is outside BrikPanel's back-office capability baseline. This is
 *     the same gate brikpanel_enqueue_global_assets() uses for
 *     brikpanel-navigation.css, and the two MUST stay in lock-step: that
 *     stylesheet carries `#adminmenu { display: none }`, so rendering this
 *     sidebar without it stacks an unstyled BrikPanel list on top of the native
 *     WordPress menu. Before 3.2.37 the renderer had no capability check at all
 *     while the stylesheet required `manage_woocommerce`, which is exactly how
 *     custom staff roles ended up with two broken menus.
 */
function brikpanel_navigation_skip_super_admin_chrome() {
	if ( function_exists( 'brikpanel_is_desktop_mode' ) && brikpanel_is_desktop_mode() ) {
		return true;
	}
	if ( is_network_admin() || is_user_admin() ) {
		return true;
	}
	// function_exists guard: brikpanel_require() is deliberately resilient, so a
	// missing access-control module must degrade to the native menu rather than
	// fatal the admin.
	if ( function_exists( 'brikpanel_user_can_use_interface' ) && ! brikpanel_user_can_use_interface() ) {
		return true;
	}
	return false;
}

// Clear only WordPress's *own* default admin footer for a cleaner look. We run
// late (priority 999) and leave the text untouched the moment anyone else has
// customised it — white-label / branding plugins such as MainWP Child set their
// own footer copyright here, and eating it would clobber their branding.
//
// WordPress wraps its default "Thank you for creating with WordPress" text in a
// `<span id="footer-thankyou">` marker. Any plugin that customises the footer
// replaces that string (dropping the marker), so its presence is a reliable,
// locale-proof signal that the footer is still the untouched core default.
add_filter('admin_footer_text', function ( $text ) {
	if ( brikpanel_navigation_skip_super_admin_chrome() ) {
		return $text;
	}
	if ( is_string( $text ) && strpos( $text, 'footer-thankyou' ) !== false ) {
		return '';
	}
	return $text;
}, 999);
add_filter('update_footer', function ( $text ) {
	if ( brikpanel_navigation_skip_super_admin_chrome() ) {
		return $text;
	}
	// The right-side string is the core version display by default. Only blank
	// it while it still shows the WordPress version number; if a branding
	// plugin replaced it with custom text, leave that alone.
	$version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
	if ( $version !== '' && is_string( $text ) && strpos( $text, $version ) !== false ) {
		return '';
	}
	return $text;
}, 999);

/**
 * Allows us to manually change menu order.
 */
add_filter( 'custom_menu_order', '__return_true' );

/**
 * Move "Dashboard" (index.php) menu to the top.
 */
function brikpanel_move_dashboard_to_top( $menu_order ) {
    if ( brikpanel_navigation_skip_super_admin_chrome() ) {
        return $menu_order;
    }
    $dashboard_index = array_search( 'index.php', $menu_order );

    if ( $dashboard_index !== false ) {
        unset( $menu_order[ $dashboard_index ] );
        array_unshift( $menu_order, 'index.php' );
    }


    return $menu_order;
}
add_filter( 'menu_order', 'brikpanel_move_dashboard_to_top', 999 );

/**
 * Relocate WooCommerce's crowded submenu into a synthetic "More" top-level
 * item, promote "Settings" to its own top-level entry, and keep Orders /
 * Customers directly under the WooCommerce item.
 *
 * This is the SINGLE source of truth for that relocation. Both the live sidebar
 * renderer (brikpanel_get_navigation_items) and the settings-page customizer
 * snapshot (brikpanel_nav_customizer_collect_menu_items) call it, so the editor
 * always shows items exactly where the sidebar renders them. They used to carry
 * two independent copies of this logic that had drifted apart — the editor
 * showed WooCommerce children nested under "WooCommerce" while the sidebar moved
 * them into "More", and per-submenu hide flags never matched.
 *
 * Moved submenu slugs are rewritten to the `admin.php?page=<slug>` form (the
 * shape the renderer links them with); Orders / Customers / Subscriptions stay
 * under WooCommerce with their original slugs.
 *
 * This runs in admin_footer, long after WordPress removed every menu row the
 * current user may not open (add_submenu_page() refuses them outright, and
 * wp-admin/includes/menu.php drops the inaccessible top-levels). Anything added
 * here skips that clean-up, so every row carries the capability of the page it
 * links to, and a row BrikPanel creates itself (Cart share) is only added for a
 * user who holds it, exactly as add_submenu_page() would have decided. The one
 * deliberate exception is the synthetic "More" container: the nav customizer
 * keys its whole "More" section on that row existing, and the renderer never
 * draws it when nothing visible landed inside.
 *
 * @param array $menu    The $menu global / snapshot, by reference (mutated).
 * @param array $submenu The $submenu global / snapshot, by reference (mutated).
 */
function brikpanel_nav_relocate_wc_submenus( &$menu, &$submenu ) {
	if ( ! is_array( $menu ) ) {
		return;
	}
	if ( ! is_array( $submenu ) ) {
		$submenu = array();
	}

	// Add the synthetic "More" top-level entry once.
	$has_more = false;
	foreach ( $menu as $row ) {
		if ( isset( $row[2] ) && $row[2] === 'woocommerce-more' ) {
			$has_more = true;
			break;
		}
	}
	if ( ! $has_more ) {
		$menu[] = array(
			__( 'More', 'brikpanel' ),
			'manage_woocommerce',
			'woocommerce-more',
			__( 'More', 'brikpanel' ),
		);
	}

	foreach ( $menu as $key => $item ) {
		if ( ! isset( $item[2] ) || $item[2] !== 'woocommerce' ) {
			continue;
		}

		$submenu_items = ! empty( $submenu['woocommerce'] ) ? $submenu['woocommerce'] : array();

		// Promoted to top-level (wc-settings) or pushed under "More".
		$to_move = array(
			'wc-settings',
			'wc-reports',
			'wc-status',
			'wc-admin&path=/extensions',
		);

		// Kept directly under WooCommerce (surfaced for quick access). Orders and
		// Subscriptions are listed in both shapes WooCommerce registers them in:
		// its own screens when orders live in its tables (HPOS), the post type
		// lists on every store that keeps them in posts. The legacy Subscriptions
		// slug was missing, so on those stores Subscriptions was filed under
		// "More" while HPOS stores kept it next to Orders.
		$skip_slugs = array(
			'wc-orders',
			'edit.php?post_type=shop_order',
			'wc-orders--shop_subscription',
			'edit.php?post_type=shop_subscription',
			'wc-admin&path=/customers',
		);

		foreach ( $submenu_items as $sub_key => $sub_item ) {
			if ( ! isset( $sub_item[2] ) ) {
				continue;
			}
			$slug = $sub_item[2];
			$temp = $submenu_items[ $sub_key ];

			// Keep under WooCommerce — leave slug untouched. So does a BrikPanel
			// screen kept in the menu for access plugins only
			// (includes/brikpanel-screen-menu.php): moved into "More" it would
			// also pass for the Cart share row below.
			if ( in_array( $slug, $skip_slugs, true )
				|| ( function_exists( 'brikpanel_is_menu_only_row' ) && brikpanel_is_menu_only_row( $sub_item ) ) ) {
				continue;
			}

			// Rewrite to the URL form the renderer links moved items with, but
			// ONLY for plugin-page slugs (e.g. wc-settings, wpo_wcpdf_options_page).
			// Slugs that already point at a real admin file — custom post types
			// (edit.php?post_type=shop_coupon / ?post_type=estado-pedido) or any
			// other *.php?... target registered by third-party plugins — are
			// navigable as-is; prefixing them produces a broken
			// admin.php?page=edit.php?... URL ("could not load" error). Mirror the
			// nav customizer's guard exactly so the live sidebar and the picker
			// agree on the link target.
			if ( strpos( $slug, '.php' ) === false && strpos( $slug, '?' ) === false ) {
				$temp[2] = 'admin.php?page=' . $temp[2];
			}

			if ( in_array( $slug, $to_move, true ) ) {
				if ( $temp[2] === 'admin.php?page=wc-settings' ) {
					$menu[] = $temp;
				} else {
					$submenu['woocommerce-more'][] = $temp;
				}
				unset( $submenu_items[ $sub_key ] );
				continue;
			}

			// Any other WooCommerce submenu (incl. third-party additions) → More.
			$submenu['woocommerce-more'][] = $temp;
			unset( $submenu_items[ $sub_key ] );
		}

		$submenu['woocommerce'] = $submenu_items;
		break;
	}

	// Operational Expenses, when it stands on its own (Suppliers module off), is
	// a low-frequency management screen — it belongs in the "More" group, not as
	// a prominent top-level item. When the Suppliers module is on it is a
	// submenu under Suppliers instead, so there is no top-level row to move and
	// this is a no-op. Done here (the shared relocation truth) so the live
	// sidebar and the nav customizer agree.
	foreach ( $menu as $exp_key => $exp_item ) {
		if ( isset( $exp_item[2] ) && 'brikpanel-expenses' === $exp_item[2] ) {
			$submenu['woocommerce-more'][] = array(
				isset( $exp_item[0] ) ? $exp_item[0] : __( 'Expenses', 'brikpanel' ),
				// Keep the capability the page was registered with, so the moved
				// row can never drift from what the Expenses screen itself checks.
				( isset( $exp_item[1] ) && is_string( $exp_item[1] ) && '' !== $exp_item[1] ) ? $exp_item[1] : 'manage_woocommerce',
				'admin.php?page=brikpanel-expenses',
				isset( $exp_item[3] ) ? $exp_item[3] : __( 'Operational Expenses', 'brikpanel' ),
			);
			unset( $menu[ $exp_key ] );
			break;
		}
	}

	// Cart share registers a hidden admin page (no menu parent), so before this
	// it was only reachable from the topbar "Create" dropdown. Surface it inside
	// the "More" group so the screen has a real home in the navigation — and do
	// it here, in the shared relocation truth, so the live sidebar and the nav
	// customizer (where it can be renamed, reordered or hidden) agree.
	//
	// Only for a user who may open that page. Added unconditionally, it was the
	// sole child of "More" for editors, authors and contributors, which gave them
	// a row with a dropdown arrow, an empty list and a link that answered 403.
	if ( class_exists( 'Brikpanel_Cart_Share' ) && Brikpanel_Cart_Share::is_enabled()
		&& current_user_can( Brikpanel_Cart_Share::CAPABILITY ) ) {
		$cart_share_target = 'admin.php?page=brikpanel-cart-share';
		$has_cart_share    = false;
		if ( ! empty( $submenu['woocommerce-more'] ) && is_array( $submenu['woocommerce-more'] ) ) {
			foreach ( $submenu['woocommerce-more'] as $more_row ) {
				if ( isset( $more_row[2] ) && $cart_share_target === $more_row[2] ) {
					$has_cart_share = true;
					break;
				}
			}
		}
		if ( ! $has_cart_share ) {
			$submenu['woocommerce-more'][] = array(
				__( 'Cart share', 'brikpanel' ),
				Brikpanel_Cart_Share::CAPABILITY,
				$cart_share_target,
				__( 'Cart share', 'brikpanel' ),
			);
		}
	}
}

/**
 * Split a parent's submenu rows into what the sidebar prints and where the
 * parent's own link points.
 *
 * WordPress hands the sidebar a $submenu that only holds rows the current user
 * may open. BrikPanel breaks that promise itself: the relocation above and the
 * nav customizer both add rows afterwards (the synthetic "More" group, promoted
 * items that keep their original capability, custom links). The renderer used
 * to decide a parent's whole shape (its dropdown arrow, whether it is drawn at
 * all, the page its link opens) from the raw array, and only filtered the
 * children while printing them. Two rules for one question: when every child
 * was filtered out, the parent kept its arrow, opened an empty list and linked
 * to the first child's page, which the user was not allowed to open. That is
 * the dead "More" row editors saw, with Cart share as its only child.
 *
 * So both answers come from here, in one pass:
 *
 *  - $header: the first row the user may OPEN. It is where the parent's own
 *    link goes, which is WordPress core's rule too (_wp_menu_output() links a
 *    parent to its first accessible child). Its absence means the parent has
 *    nothing to lead to.
 *  - $listed: the rows the dropdown SHOWS. Openable, minus the rows the sidebar
 *    hides on purpose: WooCommerce Home (the BrikPanel dashboard replaces it),
 *    raw WooCommerce slugs the relocation already re-homed, and rows WordPress
 *    marks `hide-if-js` (registered only so the page resolves, never shown).
 *
 * Every listed row is openable, so a parent with a dropdown always links to a
 * page the user can open. A parent whose openable children are all hidden
 * becomes a plain link, without an arrow and an empty list.
 *
 * A row is openable when it is a customizer custom link (its audience rule was
 * applied in brikpanel_nav_customizer_apply() and it carries its own URL) or
 * when the user passes its capability, the same test WordPress applies in
 * add_submenu_page(). A capability must be a string, or an int user level as
 * core still accepts from very old plugins; a missing one never passes, which
 * is what the print loop did before this function existed. Arrays and other
 * junk are refused up front instead of reaching current_user_can().
 *
 * @param array $rows Raw $submenu[ $parent ] rows.
 * @return array{0: array, 1: array|null} [ $listed (re-indexed), $header ].
 */
function brikpanel_nav_resolve_submenu_rows( $rows ) {
	$listed = array();
	$header = null;

	if ( empty( $rows ) || ! is_array( $rows ) ) {
		return array( $listed, $header );
	}

	static $relocated = array(
		'wc-reports'                => true,
		'wc-settings'               => true,
		'wc-status'                 => true,
		'wc-admin&path=/extensions' => true,
	);
	$ame = brikpanel_nav_ame_active();

	foreach ( $rows as $row ) {
		// A row without a slug has no destination: printed, it was an empty
		// link, and as a parent's link target it raised an undefined-key warning.
		if ( ! is_array( $row ) || ! isset( $row[2] ) || ! is_scalar( $row[2] ) || '' === (string) $row[2] ) {
			continue;
		}

		// A BrikPanel screen kept in the menu for access plugins only
		// (includes/brikpanel-screen-menu.php) is neither listed nor the
		// parent's link.
		if ( function_exists( 'brikpanel_is_menu_only_row' ) && brikpanel_is_menu_only_row( $row ) ) {
			continue;
		}

		$custom = function_exists( 'brikpanel_nav_customizer_extract_meta' )
			&& brikpanel_nav_customizer_extract_meta( $row );

		if ( ! $custom && ! ( isset( $row[1] ) && ( is_string( $row[1] ) || is_int( $row[1] ) ) && current_user_can( $row[1] ) ) ) {
			continue;
		}

		if ( null === $header ) {
			$header = $row;
		}

		if ( ! $custom ) {
			$slug = isset( $row[2] ) ? (string) $row[2] : '';
			if ( 'wc-admin' === $slug
				|| ( ! $ame && isset( $relocated[ $slug ] ) )
				|| ( isset( $row[4] ) && is_string( $row[4] ) && preg_match( '/(?:^|\s)hide-if-js(?:\s|$)/', $row[4] ) ) ) {
				continue;
			}
		}

		$listed[] = $row;
	}

	return array( $listed, $header );
}

/**
 * A menu title as it may be printed inside a sidebar row's link.
 *
 * WordPress prints menu titles as raw HTML, and some plugins put list or link
 * markup in them. WP Bulk Delete and the other Xylus Themes plugins register
 * their last row as `<li class="current" style="...">Upgrade to Pro</li>`.
 * Inside our `li > div > a` row, a browser closes the row at that `<li>`,
 * rebuilds the link after it and leaves a stray copy of the link directly under
 * the submenu list. The copy's full-row click layer (a::after in
 * brikpanel-navigation.css) is laid against the parent row, so it covered every
 * row of the plugin, and a mouse click there reached no link at all (wp.org
 * support, 2026-09-25). WordPress's own menu gets the same stray markup but has
 * no such layer, which is why the native sidebar still worked.
 *
 * `li` and `a` tags are dropped and what they wrap is kept: they are the tags
 * that take a row apart (`<li>` closes it, a nested `<a>` splits its link). The
 * row keeps its plain label, without the list item's own background. What is
 * left is balanced, so a stray closing tag (`</div>`, `</ul>`, `</p>`) cannot
 * end our row early and an unclosed one is closed inside the link. Count
 * bubbles and badge spans pass unchanged.
 *
 * @param string $title Raw title from $menu or $submenu.
 * @return string
 */
function brikpanel_nav_inline_title( $title ) {
	$title = (string) $title;
	if ( false === strpos( $title, '<' ) ) {
		return $title;
	}
	$clean = preg_replace( '#</?(?:li|a)(?:\s(?:[^>"\']++|"[^"]*"|\'[^\']*\')*)?/?>#i', '', $title );
	// A regex failure returns null: keep the label rather than print nothing.
	return force_balance_tags( null === $clean ? $title : $clean );
}

/**
 * One row of a sidebar dropdown, the way the top-level loop prints it.
 *
 * Split out of brikpanel_get_navigation_items() so the same markup, link and
 * "current page" rules serve two places: a top-level row's own dropdown, and
 * the dropdown of a menu the nav customizer moved into "More" (whose rows are
 * built against their real parent, e.g. tools.php, not against "More").
 * Everything it reads arrives as an argument or is WordPress's own request
 * state, so calling it never disturbs the caller's loop variables.
 *
 * @param array  $sub_item        $submenu row: [ title, cap, slug, page title, classes, ... ].
 * @param string $item_slug       Slug of the row's real parent menu.
 * @param bool   $admin_is_parent Whether the parent's own link is a plugin page.
 * @param string $id              The parent's ` id="..."` attribute, or ''.
 * @param array  $over            Optional: 'href' (link instead of the row's own),
 *                                'current' (mark the row as the page being viewed).
 * @return string
 */
function brikpanel_nav_render_child_row( $sub_item, $item_slug, $admin_is_parent, $id, $over = array() ) {

	$html = '';
	// Ensure all submenu item indices are strings (PHP 8.1+ null deprecation fix)
	$sub_item[0] = $sub_item[0] ?? '';
	$sub_item[2] = $sub_item[2] ?? '';
	$sub_item[4] = $sub_item[4] ?? '';

	$sub_item_slug = $sub_item[2];

	// Customizer-injected custom link inside a submenu (e.g. when an
	// admin promotes a custom URL into the "More" dropdown). Render
	// it with our own URL/icon/target and skip the slug-based logic.
	$brikpanel_sub_custom = function_exists( 'brikpanel_nav_customizer_extract_meta' )
		? brikpanel_nav_customizer_extract_meta( $sub_item )
		: null;
	if ( $brikpanel_sub_custom ) {
		$sub_url    = isset( $brikpanel_sub_custom['url'] ) ? (string) $brikpanel_sub_custom['url'] : '#';
		$sub_icon   = isset( $brikpanel_sub_custom['icon'] ) ? (string) $brikpanel_sub_custom['icon'] : 'default';
		$sub_svg    = isset( $brikpanel_sub_custom['icon_svg'] ) ? (string) $brikpanel_sub_custom['icon_svg'] : '';
		$sub_target = ! empty( $brikpanel_sub_custom['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';
		$sub_title  = wptexturize( $sub_item[0] ?? '' );
		if ( $sub_svg !== '' ) {
			$sub_icon_html = '<img src="' . esc_url( $sub_svg, array( 'data', 'http', 'https' ) ) . '" width="12">';
		} else {
			$sub_icon_html = '<img src="' . esc_url( brikpanel_nav_icon_src( $sub_icon ) ) . '" width="12">';
		}
		$html .= "
			<li class='brikpanel-more-custom-item'>
				<div class='brikpanel-menu-icon-title-container'>
					$sub_icon_html
					<a href='" . esc_url( $sub_url ) . "'" . $sub_target . " class='brikpanel-custom-nav-link'>
						" . esc_html( $sub_title ) . "
					</a>
				</div>
			</li>
		";
		return $html;
	}

	list( $class, $aria_attributes ) = brikpanel_nav_child_state( $sub_item_slug, $item_slug );

	// A caller that knows the row's page better than the row's own slug (a
	// menu moved into "More", see brikpanel_nav_more_plan()) marks it current.
	if ( ! empty( $over['current'] ) && ! in_array( 'brikpanel-current', $class, true ) ) {
		$class[]          = 'brikpanel-current';
		$aria_attributes .= ' aria-current="page"';
	}

	// Submenu için ek class'lar
	if ( ! empty( $sub_item[4] ) ) {
		$class[] = esc_attr( $sub_item[4] );
	}

	$class = $class ? ' class="' . implode( ' ', $class ) . '"' : '';

	$title = wptexturize( brikpanel_nav_inline_title( $sub_item[0] ?? '' ) );

	// WooCommerce alt menülerine özel ikonlar.
	$woocommerce_submenu_has_custom_icon = array(
		'wc-admin'                             => array( 'icon_file' => 'home' ),
		'wc-orders'                            => array( 'icon_file' => 'orders' ),
		'edit.php?post_type=shop_order'        => array( 'icon_file' => 'orders' ), // Non-HPOS
		'wc-orders--shop_subscription'         => array( 'icon_file' => 'subscriptions' ),
		'edit.php?post_type=shop_subscription' => array( 'icon_file' => 'subscriptions' ), // Non-HPOS
		'wc-admin&path=/customers'             => array( 'icon_file' => 'customers' ),
		// Eklentilerde eklenen WooCommerce alt menüleri:
		'wpo_wcpdf_options_page'               => array(
			'icon_file' => 'invoice',
			'width'     => 12,
			'css'       => 'margin-right: 3px;',
		),
		'wc-stripe-main'                       => array(
			'icon_file' => 'stripe',
			'width'     => 12,
			'css'       => 'margin-right: 3px;',
		),
		'wc-pw-gift-cards'                     => array(
			'icon_file' => 'credit-card',
			'width'     => 14,
			'css'       => 'margin-right: 1px;',
		),
		'dgwt_wcas_settings'                   => array(
			'icon_file' => 'fibosearch',
			'width'     => 14,
			'css'       => 'margin-right: 1px;',
		),
	);
	$icon = '<img src="' . brikpanel_nav_icon_src( 'default' ) . '" width="12">';

	foreach ( $woocommerce_submenu_has_custom_icon as $slug => $properties ) {
		if ( $sub_item_slug === $slug ) {
			$width = isset( $properties['width'] ) ? $properties['width'] : 15;
			$css   = isset( $properties['css'] ) ? $properties['css'] : '';
			$icon  = '<img
				src="' . brikpanel_nav_icon_src( $properties['icon_file'] ) . '"
				width="' . $width . '"
				style="' . $css . '"
			>';
			break;
		}
	}
	if ( brikpanel_nav_ame_active() ) {
		$icon = '';
	}

	if ( isset( $over['href'] ) && '' !== (string) $over['href'] ) {
		$sub_item_url = esc_url( (string) $over['href'] );
		$html .= "
			<li$class>
				<div class='brikpanel-menu-icon-title-container'>
					$icon
					<a href='$sub_item_url' $class $aria_attributes>
						$title
					</a>
				</div>
			</li>
		";
		return $html;
	}

	list( $sub_item_url, $is_plugin_page ) = brikpanel_nav_child_href( $sub_item_slug, $item_slug, $admin_is_parent );
	if ( $is_plugin_page ) {
		$sub_item_url = esc_url( $sub_item_url );

		$html .= "
			<li$class>
				<div class='brikpanel-menu-icon-title-container'>
					$icon
					<a href='$sub_item_url' $class $aria_attributes $id>
						$title
					</a>
				</div>
			</li>
		";
	} else {
		$html .= "
			<li$class>
				<div class='brikpanel-menu-icon-title-container'>
					$icon
					<a href='{$sub_item_slug}' $class $aria_attributes>
						$title
					</a>
				</div>
			</li>
		";
	}

	return $html;
}

/**
 * The classes and aria-current a dropdown row gets for the screen being
 * viewed: WordPress core's "current submenu" rule plus the WooCommerce app
 * pages, whose rows differ only in their path argument.
 *
 * @param string $sub_item_slug The row's slug ($submenu row index 2).
 * @param string $item_slug     Slug of the row's real parent menu.
 * @return array{0: string[], 1: string} [ classes, aria attributes ]
 */
function brikpanel_nav_child_state( $sub_item_slug, $item_slug ) {
	global $self, $submenu_file, $plugin_page, $typenow;

	$class           = array();
	$aria_attributes = '';

	$menu_file = $item_slug;
	$pos       = strpos( $menu_file, '?' );
	if ( false !== $pos ) {
		$menu_file = substr( $menu_file, 0, $pos );
	}

                // Güvenli hale getirilen GET değişkenleri
$path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

// Alt menüde hangi sayfa açıksa aktif göstermek için kontroller
if (isset($submenu_file)) {
    if ($submenu_file === $sub_item_slug) {
        $class[] = 'brikpanel-current';
        $aria_attributes .= ' aria-current="page"';
    }
} elseif (
    (!isset($plugin_page) && $self === $sub_item_slug)
    || (
        isset($plugin_page) && $plugin_page === $sub_item_slug
        && ($item_slug === (!empty($typenow) ? $self . '?post_type=' . $typenow : 'nothing')
            || $item_slug === $self
            || !file_exists($menu_file))
    )
) {
    if (
        empty($path)
        || (
            strpos($path, '/customers') === false
            && strpos($path, '/add-product') === false
            && strpos($path, '/extensions') === false
            && strpos($path, '/wc-pay') === false
            && strpos($path, '/payments') === false
            && strpos($path, '/analytics') === false
            && strpos($path, '/marketing') === false
        )
    ) {
        $class[] = 'brikpanel-current';
        $aria_attributes .= ' aria-current="page"';
    }
}

// Belirli sayfalarda menüyü aktif göster
if ($sub_item_slug === 'wc-admin&path=/customers' && $path === '/customers') {
    $class[] = 'brikpanel-current';
}
if ($sub_item_slug === 'admin.php?page=wc-admin&path=/add-product' && $path === '/add-product') {
    $class[] = 'brikpanel-current';
}
if ($sub_item_slug === 'wc-admin&path=/extensions' && $path === '/extensions') {
    $class[] = 'brikpanel-current';
}

// WooCommerce raporlar ve durum sayfaları
if (strpos($sub_item_slug, 'wc-reports') !== false && $page === 'wc-reports') {
    $class[] = 'brikpanel-current';
}
if (strpos($sub_item_slug, 'wc-status') !== false && $page === 'wc-status') {
    $class[] = 'brikpanel-current';
}
// Extensions before WooCommerce moved it into wc-admin (its relocated slug,
// matched exactly: Appearance > Customize carries the current page in its
// return= argument and would match a substring test).
if ($sub_item_slug === 'admin.php?page=wc-addons' && $page === 'wc-addons') {
    $class[] = 'brikpanel-current';
}
if (strpos($sub_item_slug, '/extensions') !== false && $path === '/extensions') {
    $class[] = 'brikpanel-current';
}

// WooCommerce Analytics bölümü
$analytics_base = 'wc-admin&path=/analytics/';
$analytics_sections = array(
    'overview',
    'products',
    'revenue',
    'orders',
    'variations',
    'categories',
    'coupons',
    'taxes',
    'downloads',
    'stock',
    'settings',
);

$analytics_slugs = array();
foreach ($analytics_sections as $section) {
    $analytics_slugs[$analytics_base . $section] = '/analytics/' . $section;
}

if (isset($analytics_slugs[$sub_item_slug]) && $path === $analytics_slugs[$sub_item_slug]) {
    $class[] = 'brikpanel-current';
}

// WooCommerce Marketing sayfası
if ($sub_item_slug === 'admin.php?page=wc-admin&path=/marketing' && $path === '/marketing') {
    $class[] = 'brikpanel-current';
    $class[] = 'brikpanel-has-open-submenu';
}

	return array( $class, $aria_attributes );
}

/**
 * Where a dropdown row links, by WordPress core's rule in _wp_menu_output():
 * a plugin page opens on its parent's file (tools.php?page=x) when the parent
 * is a real admin file, otherwise on admin.php?page=x; any other row is its
 * own file.
 *
 * @param string $sub_item_slug   The row's slug.
 * @param string $item_slug       Slug of the row's real parent menu.
 * @param bool   $admin_is_parent Whether the parent's own link is a plugin page.
 * @return array{0: string, 1: bool} [ URL, not escaped; whether it is a plugin page ]
 */
function brikpanel_nav_child_href( $sub_item_slug, $item_slug, $admin_is_parent ) {
	$menu_file = $item_slug;
	$pos       = strpos( $menu_file, '?' );
	if ( false !== $pos ) {
		$menu_file = substr( $menu_file, 0, $pos );
	}

	$menu_hook = get_plugin_page_hook( $sub_item_slug, $item_slug );
	$sub_file  = $sub_item_slug;
	$pos       = strpos( $sub_file, '?' );
	if ( false !== $pos ) {
		$sub_file = substr( $sub_file, 0, $pos );
	}

	if (
		! empty( $menu_hook )
		|| (
			( 'index.php' !== $sub_item_slug )
			&& file_exists( WP_PLUGIN_DIR . "/$sub_file" )
			&& ! file_exists( ABSPATH . "/wp-admin/$sub_file" )
		)
	) {
		if (
			( ! $admin_is_parent && file_exists( WP_PLUGIN_DIR . "/$menu_file" ) && ! is_dir( WP_PLUGIN_DIR . "/{$item_slug}" ) )
			|| file_exists( $menu_file )
		) {
			return array( add_query_arg( array( 'page' => $sub_item_slug ), $item_slug ), true );
		}
		return array( add_query_arg( array( 'page' => $sub_item_slug ), 'admin.php' ), true );
	}

	return array( $sub_item_slug, false );
}

/**
 * Whether a menu's first openable page is a plugin page (`admin.php?page=x`),
 * which is how WordPress decides the menu's own link and its pages' links.
 *
 * @param string $header_slug Slug of the menu's first openable page.
 * @param string $parent_slug Slug of the menu.
 * @return bool
 */
function brikpanel_nav_header_is_plugin_page( $header_slug, $parent_slug ) {
	$header_slug = (string) $header_slug;
	$menu_file   = $header_slug;
	$pos         = strpos( $menu_file, '?' );
	if ( false !== $pos ) {
		$menu_file = substr( $menu_file, 0, $pos );
	}

	return ! empty( get_plugin_page_hook( $header_slug, (string) $parent_slug ) )
		|| (
			'index.php' !== $header_slug
			&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
			&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" )
		);
}

/**
 * What "More" draws for the menus the nav customizer moved into it.
 *
 * A whole menu (Tools, Settings, a plugin's menu) moved into "More" used to be
 * a single link, and its own pages (Import, Export, Site Health, Permalinks)
 * were drawn nowhere: they stayed open, but nothing in the sidebar led to them
 * (wp.org support, 2026-10-02). Each such row now carries its pages as a
 * dropdown of its own, built against the real menu (tools.php, not "More"),
 * so its links and its "current page" rules are the ones the menu has in its
 * own place.
 *
 * Per moved row:
 *  - href: the first of its pages this user may open, WordPress's own rule for
 *    a menu's link. Without one, the row's own target when the user has the
 *    menu's capability. Otherwise the row is not drawn: it would lead nowhere,
 *    like WooCommerce's own menu, which has no page of its own.
 *  - children: the pages its dropdown lists. None when the only one would
 *    repeat the row's own link: the row is then a plain link.
 *  - open: the page being viewed belongs to this menu.
 * `active` tells the caller to open "More" itself.
 *
 * @param array $listed  "More"'s listed rows, from brikpanel_nav_resolve_submenu_rows().
 * @param array $submenu The $submenu global after the nav customizer ran.
 * @return array{rows: array<int, array>, active: bool}
 */
function brikpanel_nav_more_plan( array $listed, $submenu ) {
	global $self, $parent_file, $typenow;

	$rows   = array();
	$active = false;
	foreach ( $listed as $row ) {
		$real = function_exists( 'brikpanel_nav_more_parent' ) ? brikpanel_nav_more_parent( $row ) : '';
		if ( '' === $real ) {
			$rows[] = array(
				'row'    => $row,
				'parent' => '',
			);
			continue;
		}

		list( $children, $header ) = brikpanel_nav_resolve_submenu_rows(
			isset( $submenu[ $real ] ) && is_array( $submenu[ $real ] ) ? $submenu[ $real ] : array()
		);
		$admin_is_parent = null !== $header && brikpanel_nav_header_is_plugin_page( $header[2], $real );

		$href = '';
		if ( null !== $header ) {
			list( $href ) = brikpanel_nav_child_href( (string) $header[2], $real, $admin_is_parent );
		} else {
			$top_cap = isset( $row[7]['top_cap'] ) && is_string( $row[7]['top_cap'] ) ? $row[7]['top_cap'] : '';
			$dead_wc = 'woocommerce' === $real && ! get_plugin_page_hook( 'woocommerce', 'admin.php' );
			if ( '' !== $top_cap && ! $dead_wc && current_user_can( $top_cap ) ) {
				$href = (string) $row[2];
			}
		}
		if ( '' === $href ) {
			continue;
		}

		if ( 1 === count( $children ) ) {
			list( $only_href ) = brikpanel_nav_child_href( (string) $children[0][2], $real, $admin_is_parent );
			if ( $only_href === $href ) {
				$children = array();
			}
		}

		$open = ( $parent_file && $parent_file === $real ) || ( empty( $typenow ) && $self === $real );
		if ( ! $open ) {
			foreach ( $children as $child ) {
				list( $child_classes ) = brikpanel_nav_child_state( (string) $child[2], $real );
				if ( in_array( 'brikpanel-current', $child_classes, true ) ) {
					$open = true;
					break;
				}
			}
		}
		$active = $active || $open;

		$rows[] = array(
			'row'             => $row,
			'parent'          => $real,
			'href'            => $href,
			'children'        => $children,
			'admin_is_parent' => $admin_is_parent,
			'open'            => $open,
		);
	}

	return array(
		'rows'   => $rows,
		'active' => $active,
	);
}

/**
 * One row of "More" that stands for a whole menu moved there.
 *
 * With pages to list it is drawn like a top-level menu: its link, an arrow,
 * and its own dropdown, open while one of its pages is being viewed. The
 * arrow is the one the sidebar script already toggles (closest li). Without
 * pages it is a plain row of the list, linked to the menu's first page.
 *
 * @param array $entry A row from brikpanel_nav_more_plan().
 * @return string
 */
function brikpanel_nav_render_more_row( array $entry ) {
	$row = $entry['row'];

	if ( empty( $entry['children'] ) ) {
		return brikpanel_nav_render_child_row(
			$row,
			'woocommerce-more',
			false,
			'',
			array(
				'href'    => $entry['href'],
				'current' => $entry['open'],
			)
		);
	}

	$title = wptexturize( brikpanel_nav_inline_title( isset( $row[0] ) ? $row[0] : '' ) );
	$state = $entry['open'] ? 'brikpanel-has-open-submenu' : 'wp-not-current-submenu';
	$icon  = brikpanel_nav_ame_active() ? '' : '<img src="' . brikpanel_nav_icon_src( 'default' ) . '" width="12">';

	$html = "
		<li class='brikpanel-more-promoted brikpanel-more-nested wp-has-submenu $state'>
			<div class='brikpanel-menu-icon-title-chevron-container'>
				<div class='brikpanel-menu-icon-title-container'>
					$icon
					<a href='" . esc_url( $entry['href'] ) . "' class='brikpanel-more-promoted'>
						$title
					</a>
				</div>
				<img class=\"brikpanel-menu-chevron\" src=\"" . brikpanel_nav_icon_src( 'chevron-down' ) . "\" width=\"10\" height=\"10\">
			</div>
			<ul class='wp-submenu wp-submenu-wrap brikpanel-submenu'>";
	foreach ( $entry['children'] as $child ) {
		$html .= brikpanel_nav_render_child_row( $child, $entry['parent'], $entry['admin_is_parent'], '' );
	}
	$html .= '
			</ul>
		</li>
	';

	return $html;
}

/**
 * Function to render custom menu structure in admin panel.
 */
function brikpanel_render_navigation() {
    if ( brikpanel_navigation_skip_super_admin_chrome() ) {
        return;
    }
    $items = brikpanel_get_navigation_items();
    // Global item-spacing preference → CSS class on the sidebar shell.
    $brikpanel_nav_spacing = function_exists( 'brikpanel_nav_config_spacing' ) ? brikpanel_nav_config_spacing() : 'comfortable';
    // Close a link that markup printed earlier on the page left open. The
    // sidebar is printed in admin_footer, after other plugins' footer output,
    // and the HTML parser re-opens an unclosed <a> inside every block that
    // follows it until the next <a> start tag. Those invisible copies landed
    // in front of our first row, pushed the Dashboard label out of line, and
    // their full-row click overlay covered the sidebar's own scrollbar so it
    // could not be dragged (PDF Invoices and Packing Slips by Acowebs 1.4.10
    // closes two <a> tags with </button> in its deactivation form on the
    // Plugins screen). A lone </a> is ignored when no link is open.
    echo '</a>';
    echo '<nav id="brikpanel-navigation" class="' . esc_attr( 'brikpanel-nav-space-' . $brikpanel_nav_spacing ) . '">';
    // Allow the `data:` protocol so user-supplied custom SVG icons (stored as
    // sanitised base64 data URIs and rendered inside <img> tags, where SVG
    // scripts never execute) survive sanitisation. Everything else stays at the
    // standard post allowlist.
    $brikpanel_nav_protocols = wp_allowed_protocols();
    if ( ! in_array( 'data', $brikpanel_nav_protocols, true ) ) {
        $brikpanel_nav_protocols[] = 'data';
    }
    echo '<ul>' . wp_kses( $items, wp_kses_allowed_html( 'post' ), $brikpanel_nav_protocols ) . '</ul>';
    echo '</nav>';
    
    // --- GÜNCELLENMİŞ JAVASCRIPT ---
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const brikpanelNavigation = document.querySelector("#brikpanel-navigation");
            const container = document.querySelector("#adminmenuwrap");
            if (container && brikpanelNavigation) {
                container.insertAdjacentElement("afterbegin", brikpanelNavigation);
            }
            
            const chevrons = document.querySelectorAll(".brikpanel-menu-chevron");
            for (const chevron of chevrons) {
                chevron.addEventListener("click", event => {
                    event.preventDefault();
                    const li = event.target.closest("li");
                    if (li) {
                        li.classList.toggle("brikpanel-has-open-submenu");
                    }
                });
            }
            
            const toggleButton = document.querySelector(".brikpanel-site-management-toggle");
            const itemsContainer = document.querySelector(".brikpanel-site-management-items");

            if (toggleButton && itemsContainer) {
                
                // Sayfa yüklendiğinde hafızadaki tercihi uygula (varsayılan: kapalı)
                if (localStorage.getItem("brikpanelSiteManagementCollapsed") !== "false") {
                    itemsContainer.style.display = "none";
                    toggleButton.classList.add("collapsed");
                }

                toggleButton.addEventListener("click", function(event) {
                    event.preventDefault();
                    const isCollapsed = itemsContainer.style.display === "none";
                    
                    if (isCollapsed) {
                        itemsContainer.style.display = "";
                        toggleButton.classList.remove("collapsed");
                        localStorage.setItem("brikpanelSiteManagementCollapsed", "false");
                    } else {
                        itemsContainer.style.display = "none";
                        toggleButton.classList.add("collapsed");
                        localStorage.setItem("brikpanelSiteManagementCollapsed", "true");
                    }
                });
            }

            // User-created group headings (nav customizer "Add heading"). Same
            // collapse behaviour as the built-in "Site management" heading, but
            // one per heading and DEFAULT OPEN: a heading the owner just added
            // must show what is under it, otherwise the rows look deleted.
            const groupHeadings = document.querySelectorAll(".brikpanel-menu-group-heading");
            for (const groupHeading of groupHeadings) {
                const groupItems = groupHeading.nextElementSibling;
                if (!groupItems || !groupItems.classList.contains("brikpanel-menu-group-items")) {
                    continue;
                }
                const groupToggle = groupHeading.querySelector(".brikpanel-menu-group-toggle");
                // The group id rides in a class (brikpanel-navgroup-<id>) because
                // wp_kses() strips data-* attributes from the sidebar markup.
                let groupId = "";
                for (const cls of groupHeading.classList) {
                    if (cls.indexOf("brikpanel-navgroup-") === 0) {
                        groupId = cls.slice("brikpanel-navgroup-".length);
                        break;
                    }
                }
                if (!groupId) {
                    continue;
                }
                const storageKey = "brikpanelNavGroup:" + groupId;
                // wp_kses() drops tabindex from the sidebar markup, so the
                // heading only becomes keyboard-reachable here.
                groupHeading.setAttribute("tabindex", "0");
                const setCollapsed = function (collapsed) {
                    groupItems.style.display = collapsed ? "none" : "";
                    groupHeading.setAttribute("aria-expanded", collapsed ? "false" : "true");
                    if (groupToggle) {
                        groupToggle.classList.toggle("collapsed", collapsed);
                    }
                };
                // Only an explicit "true" collapses, so a brand new group (no
                // stored preference yet) renders open.
                setCollapsed(localStorage.getItem(storageKey) === "true");
                const toggleGroup = function (event) {
                    event.preventDefault();
                    const nowCollapsed = groupItems.style.display !== "none";
                    setCollapsed(nowCollapsed);
                    localStorage.setItem(storageKey, nowCollapsed ? "true" : "false");
                };
                groupHeading.addEventListener("click", toggleGroup);
                groupHeading.addEventListener("keydown", function (event) {
                    if (event.key === "Enter" || event.key === " " || event.key === "Spacebar") {
                        toggleGroup(event);
                    }
                });
            }
        });
    </script>';
}
add_action( 'admin_footer', 'brikpanel_render_navigation' );


/**
 * Reads global WordPress $menu and $submenu arrays and
 * builds our custom HTML structure.
 */
function brikpanel_get_navigation_items( $submenu_as_parent = true ) {
	global $menu, $submenu, $self, $parent_file, $submenu_file, $plugin_page, $typenow;

	// If Admin Menu Editor is active, load its custom menu. The three methods
	// are checked up front because this now also runs for Admin Menu Editor
	// Pro, which the old folder check never let through, and a menu swapped in
	// here is only ever put back by restore_wp_menu() at the end.
	if ( brikpanel_nav_ame_active() ) {
		global $wp_menu_editor;
		if ( is_object( $wp_menu_editor )
			&& method_exists( $wp_menu_editor, 'load_custom_menu' )
			&& method_exists( $wp_menu_editor, 'replace_wp_menu' )
			&& method_exists( $wp_menu_editor, 'restore_wp_menu' )
			&& $wp_menu_editor->load_custom_menu() ) {
			$wp_menu_editor->replace_wp_menu();
		}
	}

	$html = '';

	// If Admin Menu Editor is not active, fold WooCommerce's crowded submenu into
	// a synthetic "More" item (shared with the settings-page customizer so both
	// stay in lockstep), then apply the default top-level reordering.
	if ( ! brikpanel_nav_ame_active() ) {
		brikpanel_nav_relocate_wc_submenus( $menu, $submenu );

		// The default store order (Products and BrikPanel's rows under WooCommerce,
		// Settings and More closing the store part, the companion-plugin hook,
		// the demotion of foreign top-levels) comes from one function that the
		// Navigation settings snapshot calls too. See
		// brikpanel-nav-icon-helpers.php.
		brikpanel_nav_apply_store_order( $menu );
	}

			// Apply user-defined sidebar customization (reorder / hide / inject
			// custom links / promote items into More / hide individual submenus
			// / override icons). Returns the slug used as the anchor for the
			// "Site management" heading; defaults to 'edit.php' when no config
			// is saved. Pass $submenu by reference so More-section + per-submenu
			// hide can mutate it.
			$brikpanel_sitemgmt_anchor = 'edit.php';
			$brikpanel_custom_icons    = array();
			if ( function_exists( 'brikpanel_nav_customizer_apply' ) ) {
				list( $brikpanel_sitemgmt_anchor, $brikpanel_custom_icons ) = brikpanel_nav_customizer_apply( $menu, $submenu );
			}

			// Pin the Vendors top-level menu right below Products in the store
			// section. Done AFTER the customizer so it survives any saved nav
			// config — newly-registered top-level items would otherwise get
			// auto-appended below the Site management heading. When the master
			// toggle is off, the slug isn't in $menu and the call is a no-op
			// (the entire Vendors group is hidden from the sidebar).
			$menu = brikpanel_move_item_after( $menu, 'brikpanel-vendors', 'edit.php?post_type=product' );

			$first = true;

			// State for user-created group headings (nav customizer "Add heading").
			// A heading opens a collapsible wrapper that stays open until the next
			// heading or the end of the menu, so the loop has to remember whether
			// one is currently open.
			//   $brikpanel_group_open   — a wrapper <div> is waiting to be closed
			//   $brikpanel_group_offset — strlen($html) right BEFORE the heading was
			//                             appended, so an empty group can be undone
			//   $brikpanel_group_rows   — rows emitted since the heading opened
			$brikpanel_group_open   = false;
			$brikpanel_group_offset = 0;
			$brikpanel_group_rows   = 0;

			// Close the currently open heading group, dropping the heading entirely
			// when nothing rendered under it. An empty group happens for real: every
			// row inside it can be capability- or role-filtered away for the current
			// user, and a lone heading with a toggle that opens nothing is worse than
			// no heading at all.
			$brikpanel_close_group = function () use ( &$html, &$brikpanel_group_open, &$brikpanel_group_offset, &$brikpanel_group_rows ) {
				if ( ! $brikpanel_group_open ) {
					return;
				}
				if ( $brikpanel_group_rows === 0 ) {
					// Nothing was appended after the heading, so truncating back to
					// the recorded offset removes exactly the heading + wrapper.
					$html = substr( $html, 0, $brikpanel_group_offset );
				} else {
					$html .= '</div>';
				}
				$brikpanel_group_open = false;
				$brikpanel_group_rows = 0;
			};

			// WooCommerce's Analytics top-level, whatever report this version names
			// it after: it gets the BrikPanel analytics icon and opens on its reports.
			$brikpanel_analytics_slug = brikpanel_nav_wc_analytics_slug( $menu );

			// On a BrikPanel screen, the sidebar row the screen stands for (All
			// Products for the product list, Cart share in "More"). The group
			// holding it opens, wherever the sidebar placed it
			// (includes/brikpanel-screen-menu.php).
			$brikpanel_screen_row = function_exists( 'brikpanel_screen_menu_current_row' ) ? brikpanel_screen_menu_current_row() : null;

			// Loop through each top-level menu and build HTML.
			foreach ( $menu as $key => $item ) {
				// Third-party plugins can leave a non-array (scalar) entry in the
				// global $menu (e.g. an int/bool at some position). Writing to
				// $item[0] on a scalar fatals with "Cannot use a scalar value as
				// an array", so skip anything that isn't a proper menu row.
				if ( ! is_array( $item ) ) {
					continue;
				}

				// Ensure all menu item indices are strings (PHP 8.1+ null deprecation fix)
				$item[0] = $item[0] ?? '';
				$item[2] = $item[2] ?? '';
				$item[4] = $item[4] ?? '';
				$item[5] = $item[5] ?? '';
				$item[6] = $item[6] ?? '';

				$admin_is_parent = false;
				$class           = array();
				$aria_attributes = '';
				$aria_hidden     = '';
				$is_separator    = false;

				$item_slug = $item[2];

				if ( $first ) {
					$class[] = 'wp-first-item';
					$first   = false;
				}

				// The "Site management" heading is attached to whichever row the nav
				// customizer marked as the section anchor (default 'edit.php'). It is
				// resolved HERE, ahead of every "skip this row" branch below, because
				// the same string also OPENS the collapsible wrapper
				// (`<div class="brikpanel-site-management-items">`) that all the items
				// after it live in. Dropping the anchor row without emitting it would
				// leave the whole section outside that wrapper: no heading, and a
				// collapse toggle with nothing to collapse. Every branch that skips a
				// row therefore emits $heading first.
				$heading = '';
				if ( $item_slug === $brikpanel_sitemgmt_anchor && ! brikpanel_nav_ame_active() ) {
					// Custom heading label (falls back to the translated default).
					// Fetched only on the anchor row, never for every menu item.
					$brikpanel_sitemgmt_label = function_exists( 'brikpanel_nav_config_sitemgmt_label' )
						? brikpanel_nav_config_sitemgmt_label()
						: __( 'Site management', 'brikpanel' );
					$heading = '<span class="brikpanel-menu-heading">' . esc_html( $brikpanel_sitemgmt_label ) . '<img class="brikpanel-site-management-toggle" src="' . brikpanel_nav_icon_src( 'chevron-down' ) . '" width="10" height="10"></span><div class="brikpanel-site-management-items">';
					// A user-created group that is still open must end BEFORE the
					// "Site management" wrapper starts, otherwise Site management
					// would nest inside it and collapsing the user's group would
					// take Site management down with it. Doing this here — where
					// $heading is computed, ahead of every skip branch — covers all
					// the places further down that emit $heading.
					$brikpanel_close_group();
				}

				// $submenu_items: the children the dropdown shows. $brikpanel_header_row:
				// the first child the user may open, i.e. where this row's own link
				// goes. Everything below decides the row's shape from these two and
				// never from the raw $submenu array, which can hold rows BrikPanel
				// added after WordPress's own capability clean-up. See
				// brikpanel_nav_resolve_submenu_rows().
				list( $submenu_items, $brikpanel_header_row ) = brikpanel_nav_resolve_submenu_rows(
					isset( $submenu[ $item_slug ] ) ? $submenu[ $item_slug ] : array()
				);
				$brikpanel_container_open = false;

				// Menus the nav customizer moved into "More" keep their own pages as
				// a dropdown under their row (brikpanel_nav_more_plan()). Decided
				// here, before the row's shape and open state are: a moved menu
				// with nothing this user may open leaves the list, and one of its
				// pages being viewed opens "More".
				$brikpanel_more_plan        = null;
				$brikpanel_more_header_href = '';
				if ( 'woocommerce-more' === $item_slug && ! empty( $submenu_items ) && function_exists( 'brikpanel_nav_more_parent' ) ) {
					$brikpanel_more_plan = brikpanel_nav_more_plan( $submenu_items, $submenu );
					$submenu_items       = array_column( $brikpanel_more_plan['rows'], 'row' );
					// "More" links to its first page. When that row is a moved menu,
					// its link is the menu's first page, not the menu's own slug.
					if ( null !== $brikpanel_header_row && '' !== brikpanel_nav_more_parent( $brikpanel_header_row ) ) {
						foreach ( $brikpanel_more_plan['rows'] as $brikpanel_more_entry ) {
							if ( $brikpanel_more_entry['row'] === $brikpanel_header_row ) {
								$brikpanel_more_header_href = $brikpanel_more_entry['href'];
								break;
							}
						}
						if ( '' === $brikpanel_more_header_href && ! empty( $brikpanel_more_plan['rows'] ) ) {
							$brikpanel_more_entry       = $brikpanel_more_plan['rows'][0];
							$brikpanel_more_header_href = '' !== $brikpanel_more_entry['parent']
								? $brikpanel_more_entry['href']
								: brikpanel_nav_child_href( (string) $brikpanel_more_entry['row'][2], 'woocommerce-more', false )[0];
						}
					}
				}

				if ( ! empty( $submenu_items ) ) {
					$class[] = 'wp-has-submenu';
				}

				// The WooCommerce top-level is normally a SILENT container: its
				// Orders / Customers children are rendered as flat top-level rows,
				// so the row itself deliberately carries no label, padding, hover
				// or radius (brikpanel-navigation.css excludes
				// #toplevel_page_woocommerce from those rules) — otherwise that
				// flat list would sit double-indented.
				//
				// When those children are absent the row renders as an ordinary
				// "WooCommerce" link instead, and the stripped styling left it 8px
				// to the left of every other item, shorter, and with no hover
				// state. That is the misalignment non-administrator accounts hit:
				// Orders needs `edit_others_shop_orders` and Customers needs
				// `view_woocommerce_reports`, so a staff role without them (or a
				// store that hid both rows in Navigation settings) sees the bare
				// row an administrator never does. Flag it so the stylesheet can
				// style it like any other menu item.
				//
				// Better still, drop it: WooCommerce registers the entry with NO
				// page callback of its own, so with nothing under it the link
				// pointed at admin.php?page=woocommerce and answered "Cannot load
				// woocommerce." A row that is both misaligned and dead is worth
				// removing, not restyling. The class stays for the one case where
				// the row does lead somewhere — Admin Menu Editor repurposes it,
				// and a third-party plugin may attach a real callback to the slug.
				if ( $item_slug === 'woocommerce' && empty( $submenu_items ) ) {
					$brikpanel_wc_has_page = brikpanel_nav_ame_active()
						|| (bool) get_plugin_page_hook( 'woocommerce', 'admin.php' );
					if ( ! $brikpanel_wc_has_page ) {
						$html .= $heading;
						continue;
					}
					$class[] = 'brikpanel-nav-standalone';
				}

				// Silent: none of the row itself is printed, only its list. Decided
				// here, once, because the link code further down has two branches (the
				// first child is a plugin page, or a plain admin file) and the rule
				// used to live in only one of them. Orders is `wc-orders`, a plugin
				// page, only where orders live in WooCommerce's own tables. On every
				// store that keeps them in posts, WooCommerce 4.0 included, it is
				// `edit.php?post_type=shop_order`, which took the other branch: the
				// "WooCommerce" heading and icon were printed and their row container
				// was never closed, so Orders and Customers were laid out inside it,
				// beside the heading and cut off by the sidebar edge.
				$brikpanel_wc_silent = 'woocommerce' === $item_slug
					&& ! empty( $submenu_items )
					&& ! brikpanel_nav_ame_active();

				// The synthetic "More" group is a pure dropdown container — it has
				// no page of its own. When it lists nothing for this user (all
				// children hidden, none registered, or none this user may open),
				// skip the row entirely instead of emitting a top-level link to the
				// non-existent `woocommerce-more` screen, which resolves to a 404,
				// or to a child page the user is not allowed to open (403).
				if ( $item_slug === 'woocommerce-more' && empty( $submenu_items ) ) {
					$html .= $heading;
					continue;
				}

		// Güvenli hale getirilen path, page ve tab değerleri
		$path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$tab  = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';

		// WooCommerce sayfaları için özel kontroller
		$viewing_add_product_page = $item_slug === 'edit.php?post_type=product' && strpos($path, '/add-product') !== false;
		$viewing_payments_page = $item_slug === 'wc-admin&path=/payments/overview' && strpos($path, '/payments') !== false;
		// The Analytics top-level is named after WooCommerce's landing report,
		// which differs by version (see brikpanel_nav_wc_analytics_slug()).
		$viewing_analytics_page = '' !== $brikpanel_analytics_slug && $item_slug === $brikpanel_analytics_slug && strpos($path, '/analytics') !== false;
		$viewing_marketing_page = $item_slug === 'woocommerce-marketing' && strpos($path, '/marketing') !== false;
		// Extensions is `wc-addons` before WooCommerce moved it into wc-admin.
		$viewing_more_page =
			strpos($item_slug, 'woocommerce-more') !== false &&
			(
				strpos($page, 'wc-reports') !== false ||
				strpos($page, 'wc-status') !== false ||
				strpos($page, 'wc-addons') !== false ||
				strpos($path, '/extensions') !== false
			);
		// A page of a menu moved into "More" opens "More" too.
		if ( $brikpanel_more_plan && $brikpanel_more_plan['active'] ) {
			$viewing_more_page = true;
		}

		$viewing_dashboard_page = $item_slug === 'index.php' && $page === 'brikpanel-dashboard';

		// A BrikPanel screen opens the group that lists the row it stands for.
		$viewing_screen_row = false;
		if ( null !== $brikpanel_screen_row ) {
			foreach ( $submenu_items as $brikpanel_listed_row ) {
				if ( is_array( $brikpanel_listed_row ) && isset( $brikpanel_listed_row[2] ) && $brikpanel_screen_row === (string) $brikpanel_listed_row[2] ) {
					$viewing_screen_row = true;
					break;
				}
			}
		}

		// Menü aktiflik durumu
		if (
			($parent_file && $item_slug === $parent_file) ||
			(empty($typenow) && $self === $item_slug) ||
			$viewing_add_product_page ||
			$viewing_payments_page ||
			$viewing_analytics_page ||
			$viewing_marketing_page ||
			$viewing_more_page ||
			$viewing_dashboard_page ||
			$viewing_screen_row
		) {
			if (!empty($submenu_items)) {
				$class[] = 'brikpanel-has-open-submenu';
			} else {
				$class[] = 'brikpanel-current';
				$aria_attributes .= 'aria-current="page"';
			}
		} else {
			$class[] = 'wp-not-current-submenu';

			if ($item_slug === 'wc-admin&path=/wc-pay-welcome-page' && strpos($path, '/wc-pay') !== false) {
				$class[] = 'brikpanel-current';
			}
			if ($item_slug === 'wc-admin&path=/payments/connect' && strpos($path, '/payments/connect') !== false) {
				$class[] = 'brikpanel-current';
			}
			if ($item_slug === 'wc-admin&path=/payments/overview' && strpos($path, '/payments/overview') !== false) {
				$class[] = 'brikpanel-current';
			}

			if (strpos($item_slug, 'wc-settings') !== false && $page === 'wc-settings') {
				if (strpos($item_slug, 'tab=checkout') !== false && $tab === 'checkout') {
					$class[] = 'brikpanel-current';
				} else if (strpos($item_slug, 'tab=checkout') === false && $tab !== 'checkout') {
					$class[] = 'brikpanel-current';
				}
			}

			if (!empty($submenu_items)) {
				$aria_attributes .= ' data-ariahaspopup';
			}
		}

		if ( ! empty( $item[4] ) ) {
			$class[] = esc_attr( $item[4] );
		}

		// Admin Menu Editor aktifse küçük bir ek class ekliyoruz.
		if ( brikpanel_nav_ame_active() ) {
			$class[] = 'admin-menu-editor-active';
		}

		$class = $class ? ' class="' . implode( ' ', $class ) . '"' : '';
		$id    = ! empty( $item[5] ) ? ' id="' . preg_replace( '|[^a-zA-Z0-9_:.]|', '-', $item[5] ) . '"' : '';

		$id_plain            = ! empty( $item[5] ) ? preg_replace( '|[^a-zA-Z0-9_:.]|', '-', $item[5] ) : '';
		$toplevel_page_class = str_starts_with( $id_plain, 'toplevel_page' ) ? $id_plain : '';
		$img                 = '';
		$img_style           = '';
		$img_class           = ' dashicons-before';

		// Sadece Admin Menu Editor devredeyse menüdeki separator’ları (bölüm ayırıcıları) render ediyoruz.
		if ( str_contains( $class, 'wp-menu-separator' ) ) {
			if ( brikpanel_nav_ame_active() ) {
				$is_separator = true;
			} else {
				continue;
			}
		}

		// Orijinal menü ikonu.
		if ( ! empty( $item[6] ) ) {
			$img = '<img src="' . esc_url( $item[6] ) . '" alt="" />';

			if ( 'none' === $item[6] || 'div' === $item[6] ) {
				$img = '<br />';
			} elseif ( str_starts_with( $item[6], 'data:image/' ) ) {
				// Third-party plugins commonly register their menu icon as an
				// inline data URI (base64 or URL-encoded SVG, or a PNG). Render
				// it as an <img> rather than an inline background-image: the
				// whole navigation is passed through wp_kses() in
				// brikpanel_render_navigation(), and safecss_filter_attr() treats
				// the ";" in "image/svg+xml;base64" as a CSS declaration
				// separator, silently dropping the background-image and leaving
				// the icon blank. The data: protocol is allowlisted for src
				// there, so an <img> survives sanitisation intact.
				$img       = '<img src="' . esc_url( $item[6], array( 'data', 'http', 'https' ) ) . '" alt="" />';
				$img_class = str_contains( $item[6], 'svg' ) ? ' svg' : '';
			} elseif ( str_starts_with( $item[6], 'dashicons-' ) ) {
				$img       = '<br />';
				$img_class = ' dashicons-before ' . sanitize_html_class( $item[6] );
			}
		}

		$title = wptexturize( brikpanel_nav_inline_title( $item[0] ?? '' ) );

		/**
		 * Filter a top-level item's sidebar label. Companion surfaces add a
		 * badge here at render time, so the registered title (what the nav
		 * customizer, the native menu and screen readers see) stays plain.
		 * The value is HTML and goes through the sidebar's wp_kses().
		 *
		 * @param string $title Label HTML.
		 * @param string $slug  Top-level menu slug ($item[2]).
		 */
		$title = apply_filters( 'brikpanel_nav_item_title', $title, $item_slug );

		// Separator için erişilebilirlik ayarı:
		if ( $is_separator ) {
			$aria_hidden = ' aria-hidden="true"';
		}

		// Bazı özel başlıklar (örn: Edit Posts / Pages) için heading ekliyoruz.
		// $heading (the "Site management" label plus the opening tag of the
		// collapsible wrapper) is resolved much earlier in the loop — see the
		// comment there — so the skip branches above can emit it too.

		// None of the three render branches below produce markup for a row that is
		// neither a separator, nor a submenu parent, nor a link the current user
		// may follow — it used to emit a bare `<li></li>`. That happens on real
		// installs: WordPress keeps a top-level entry alive as long as ONE of its
		// submenus is accessible, so a staff account that can reach WooCommerce →
		// Settings but not Orders keeps the WooCommerce row even though its own
		// capability (`edit_others_shop_orders`) is denied — and BrikPanel has by
		// then relocated every one of those submenus under "More", leaving nothing
		// to render. The stray element is invisible today only because that row
		// happens to be stripped of padding; give it back normal styling and it
		// becomes a blank gap in the middle of the menu. Skip the row, but still
		// emit the "Site management" heading when this item was its anchor —
		// otherwise the section (and the wrapper its collapse toggle opens) would
		// never be opened.
		//
		// A row whose capability slot is missing or is not a plain string (third
		// party plugins do push short / malformed $menu arrays) is KEPT: WordPress
		// has already dropped every TOP-LEVEL row the user may not reach, so
		// "unknown" must never mean "hide". The capability test itself is the same
		// one WordPress core applies in _wp_menu_output(), so this can never hide
		// a row the native admin menu would have shown.
		//
		// A parent counts through its first child the user may OPEN, not through
		// the raw submenu array: children can be rows BrikPanel added after
		// WordPress's own clean-up, and a parent drawn for children nobody may
		// open is exactly the dead "More" row (arrow, empty list, 403 link).
		$brikpanel_row_renders = $is_separator
			|| ( $submenu_as_parent && null !== $brikpanel_header_row )
			|| (
				$item_slug !== ''
				&& (
					! isset( $item[1] )
					|| ! is_string( $item[1] )
					|| $item[1] === ''
					|| current_user_can( $item[1] )
				)
			);
		if ( ! $brikpanel_row_renders ) {
			$html .= $heading;
			continue;
		}

		// User-created group heading injected by the nav customizer. Like the
		// built-in "Site management" heading it is NOT a list row: it emits a
		// label plus the opening tag of the collapsible wrapper the rows after
		// it live in, then bows out before any <li> is written.
		if ( isset( $item[7] ) && is_array( $item[7] ) && ! empty( $item[7]['is_heading'] ) ) {
			$brikpanel_close_group();
			$html .= $heading;
			$brikpanel_group_label = isset( $item[7]['label'] ) ? (string) $item[7]['label'] : '';
			$brikpanel_group_id    = isset( $item[7]['id'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $item[7]['id'] ) : '';
			if ( $brikpanel_group_label === '' || $brikpanel_group_id === '' ) {
				continue;
			}
			// The group id travels in a CLASS, not a data-* attribute: the whole
			// sidebar is filtered through wp_kses() with the 'post' allowlist,
			// which drops data-* attributes but keeps class.
			$brikpanel_group_class  = 'brikpanel-navgroup-' . $brikpanel_group_id;
			$brikpanel_group_offset = strlen( $html );
			$brikpanel_group_rows   = 0;
			$brikpanel_group_open   = true;
			// `role` survives wp_kses()'s global-attribute list; `tabindex` does
			// not, so the collapse script adds it at runtime instead.
			$html .= '<span class="brikpanel-menu-heading brikpanel-menu-group-heading ' . esc_attr( $brikpanel_group_class ) . '" role="button">'
				. esc_html( $brikpanel_group_label )
				. '<img class="brikpanel-menu-group-toggle" src="' . brikpanel_nav_icon_src( 'chevron-down' ) . '" width="10" height="10">'
				. '</span><div class="brikpanel-menu-group-items ' . esc_attr( $brikpanel_group_class ) . '">';
			continue;
		}

		if ( $brikpanel_group_open ) {
			$brikpanel_group_rows++;
		}

		$html .= "
			$heading
			<li $class $id $aria_hidden>
		";

		// Custom user-defined link injected by the nav customizer. Render it
		// with the user's URL / icon / target and skip the rest of the loop
		// (it has no submenu and bypasses the normal slug-based icon map).
		$brikpanel_custom_meta = function_exists( 'brikpanel_nav_customizer_extract_meta' )
			? brikpanel_nav_customizer_extract_meta( $item )
			: null;
		if ( $brikpanel_custom_meta ) {
			$custom_url    = isset( $brikpanel_custom_meta['url'] ) ? (string) $brikpanel_custom_meta['url'] : '#';
			$custom_icon   = isset( $brikpanel_custom_meta['icon'] ) ? (string) $brikpanel_custom_meta['icon'] : 'default';
			$custom_svg    = isset( $brikpanel_custom_meta['icon_svg'] ) ? (string) $brikpanel_custom_meta['icon_svg'] : '';
			$custom_target = ! empty( $brikpanel_custom_meta['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';
			if ( $custom_svg !== '' ) {
				$icon_html = '<img src="' . esc_url( $custom_svg, array( 'data', 'http', 'https' ) ) . '" width="15" height="18">';
			} else {
				$icon_html = '<img src="' . esc_url( brikpanel_nav_icon_src( $custom_icon ) ) . '" width="15" height="18">';
			}
			$html .= "
				<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
					$icon_html
					<a href='" . esc_url( $custom_url ) . "'" . $custom_target . " class='brikpanel-custom-nav-link'>
						" . esc_html( $title ) . "
					</a>
				</div>
			";
			$html .= '</li>';
			continue;
		}

		// Decorative spacer injected by the nav customizer — a blank gap or a
		// thin divider line. It has no link, icon, submenu or title; render just
		// the spacer element and skip the rest of the loop.
		if ( isset( $item[7] ) && is_array( $item[7] ) && ! empty( $item[7]['is_spacer'] ) ) {
			$spacer_variant = ( isset( $item[7]['variant'] ) && $item[7]['variant'] === 'line' ) ? 'line' : 'space';
			$html .= '<span class="brikpanel-nav-spacer-inner brikpanel-nav-spacer-' . esc_attr( $spacer_variant ) . '" aria-hidden="true"></span>';
			$html .= '</li>';
			continue;
		}

		// Özel ikon atamaları:
		$has_custom_icon = array(
			'edit.php?post_type=product' => 'products',
			'brikpanel-segments' => 'payments',
			'brikpanel-customer-analytics' => 'credit-card',
			'brikpanel-google-sheets' => 'google-sheets',
			'brikpanel-vendors' => 'invoice',
			'brikpanel-expenses' => 'payments',
			'brikpanel-abandoned-carts' => 'orders',
			'brikpanel-brikmentor' => 'star',
			'wf_woocommerce_packing_list' => 'invoice',
			'admin.php?page=wc-settings&tab=checkout' => 'payments',
			'wc-admin&path=/wc-pay-welcome-page' => 'payments',
			'wc-admin&path=/payments/connect' => 'payments',
			'wc-admin&path=/payments/overview' => 'payments',
			'wc-admin&path=/analytics/overview' => 'analytics',
			'woocommerce-marketing' => 'marketing',
			'admin.php?page=wc-settings' => 'settings',
			'woocommerce-more' => 'more',
			'index.php' => 'home',
			'edit.php' => 'posts',
			'upload.php' => 'media',
			'edit.php?post_type=page' => 'pages',
			'edit-comments.php' => 'comments',
			'wpforms-overview' => 'form',
			'rank-math' => 'rank-math',
			'themes.php' => 'appearance',
			'plugins.php' => 'plugins',
			'snippets' => 'scissors',
			'users.php' => 'users',
			'tools.php' => 'tools',
			'options-general.php' => 'settings',
			'settings.php' => 'settings',
		);
		// Analytics under the slug this WooCommerce version registered
		// (`/analytics/revenue` before 4.2), not only the one above.
		if ( '' !== $brikpanel_analytics_slug ) {
			$has_custom_icon[ $brikpanel_analytics_slug ] = 'analytics';
		}

		$icon = '';
		// User-defined icon override (from the customizer) takes precedence
		// over the built-in slug→icon map. The map value is an array:
		// [ 'type' => 'builtin'|'svg', 'value' => slug|data-uri ].
		if ( isset( $brikpanel_custom_icons[ $item_slug ] ) ) {
			$override = $brikpanel_custom_icons[ $item_slug ];
			if ( is_array( $override ) && isset( $override['type'], $override['value'] ) ) {
				if ( $override['type'] === 'svg' ) {
					$icon = '<img src="' . esc_url( (string) $override['value'], array( 'data', 'http', 'https' ) ) . '" width="15" height="18">';
				} else {
					$icon = '<img src="' . esc_url( brikpanel_nav_icon_src( $override['value'] ) ) . '" width="15" height="18">';
				}
			}
		}
		if ( $icon === '' ) {
			foreach ( $has_custom_icon as $slug => $icon_file ) {
				if ( $item_slug === $slug ) {
					$icon = '<img src="' . brikpanel_nav_icon_src( $icon_file ) . '" width="15" height="18">';
					break;
				}
			}
		}
		// Eğer özel ikon yoksa orijinal ikonu kullanmaya devam ediyoruz.
		if ( $icon === '' && isset( $item[6] ) ) {
			$icon = "<div class='wp-menu-image$img_class'$img_style aria-hidden='true'>$img</div>";
		}

		// Separator ise, altındaki submenü vs yok.
		if ( $is_separator ) {
			$html .= '<div class="separator"></div>';
		} elseif ( $submenu_as_parent && null !== $brikpanel_header_row ) {
			// Link the row itself to its first child the user may open, the way
			// WordPress core's _wp_menu_output() does. That child is resolved by
			// brikpanel_nav_resolve_submenu_rows(), never read from the raw array.

			// A custom-link child (only possible under the synthetic "More" group)
			// keeps its real destination in row metadata (index 7); its index-2
			// slug is a synthetic `brikpanel_custom__<id>` placeholder that is NOT
			// a real screen. Linking the parent header to that placeholder resolves
			// to `/wp-admin/brikpanel_custom__<id>`, which 404s (the reported "More"
			// bug). Resolve the child's real URL so the header always points at a
			// valid destination.
			$first_child_meta = function_exists( 'brikpanel_nav_customizer_extract_meta' )
				? brikpanel_nav_customizer_extract_meta( $brikpanel_header_row )
				: null;
			$first_child_href = ( $first_child_meta && ! empty( $first_child_meta['url'] ) )
				? esc_url( (string) $first_child_meta['url'] )
				: '';
			if ( '' === $first_child_href && '' !== $brikpanel_more_header_href ) {
				$first_child_href = esc_url( $brikpanel_more_header_href );
			}

			$menu_hook     = get_plugin_page_hook( $brikpanel_header_row[2], $item_slug );
			$menu_file     = $brikpanel_header_row[2];
			$pos           = strpos( $menu_file, '?' );

			if ( false !== $pos ) {
				$menu_file = substr( $menu_file, 0, $pos );
			}

			if (
				! empty( $menu_hook )
				|| (
					( 'index.php' !== $brikpanel_header_row[2] )
					&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
					&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" )
				)
			) {
				$admin_is_parent = true;
				// WooCommerce keeps Orders / Customers surfaced directly beneath it
				// with no parent label or chevron — BrikPanel deliberately hides the
				// "WooCommerce" branding so the sidebar reads as clean Shopify-style
				// items. Everything else that used to live under WooCommerce has been
				// relocated to the "More" item by brikpanel_nav_relocate_wc_submenus().
				if ( ! $brikpanel_wc_silent ) {
					$style = $item_slug === 'meowapps-main-menu' ? 'style="padding-left: 24px;"' : '';
					$html .= "
						<div class='brikpanel-menu-icon-title-chevron-container'>
							<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
								$icon
								<a href='admin.php?page={$brikpanel_header_row[2]}' $class $style $aria_attributes>
									$title
								</a>
							</div>
					";
					$brikpanel_container_open = true;
				}
			} elseif ( ! $brikpanel_wc_silent ) {
				// Prefer a custom-link child's real URL over its synthetic slug so
				// the "More" header never links to a non-existent screen.
				$parent_href = $first_child_href !== '' ? $first_child_href : $brikpanel_header_row[2];
				$html .= "
					<div class='brikpanel-menu-icon-title-chevron-container'>
						<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
							$icon
							<a href='{$parent_href}' $class $aria_attributes>
								$title
							</a>
						</div>
				";
				$brikpanel_container_open = true;
			}

			// A container opened above is closed here, exactly once: by the arrow's
			// markup when there is a list to open, otherwise by a bare </div>
			// (every child this user may open is one the sidebar hides on purpose,
			// such as WooCommerce Home or a `hide-if-js` row, so the row is a plain
			// link with no arrow and no empty list). A silent row opened nothing.
			if ( $brikpanel_container_open && ! empty( $submenu_items ) ) {
				$html .= '
						<img
							class="brikpanel-menu-chevron"
							src="' . brikpanel_nav_icon_src( 'chevron-down' ) . '"
							width="10"
							height="10"
						>
					</div>
				';
			} elseif ( $brikpanel_container_open ) {
				$html .= '</div>';
			}
			$html .= '</a>';
		} elseif ( ! empty( $item_slug ) && current_user_can( $item[1] ) ) {
			// Alt menüsü yoksa veya alt menüleri parent yapmadıysak, doğrudan link veriyoruz.
			$menu_hook = get_plugin_page_hook( $item_slug, 'admin.php' );
			$menu_file = $item_slug;
			$pos       = strpos( $menu_file, '?' );

			if ( false !== $pos ) {
				$menu_file = substr( $menu_file, 0, $pos );
			}

			if (
				! empty( $menu_hook )
				|| (
					( 'index.php' !== $item_slug )
					&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
					&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" )
				)
			) {
				$admin_is_parent = true;
				$html .= "
					<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
						{$icon}
						<a href='admin.php?page={$item_slug}' $class $aria_attributes>
							{$title}
						</a>
					</div>
				";
			} else {
				$html .= "
					<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
						{$icon}
						<a href='{$item_slug}' $class $aria_attributes>
							{$title}
						</a>
					</div>
				";
			}
		}

		// Alt menü render
		if ( ! empty( $submenu_items ) ) {
			if ( $item_slug === 'woocommerce' && ! brikpanel_nav_ame_active() ) {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap'>";
			} else {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap brikpanel-submenu'>";
			}

			// $submenu_items is already exactly the list to print (see
			// brikpanel_nav_resolve_submenu_rows()): no capability, WooCommerce Home,
			// relocated-slug or hide-if-js test is repeated here. Filtering again at
			// print time is how a parent once ended up with an arrow over an empty
			// list. This block also no longer touches $first: resetting it here left
			// it true after a list whose rows were all skipped, so the NEXT top-level
			// row was wrongly marked `wp-first-item`.
			foreach ( $submenu_items as $sub_key => $sub_item ) {
				if ( $brikpanel_more_plan && isset( $brikpanel_more_plan['rows'][ $sub_key ] ) && '' !== $brikpanel_more_plan['rows'][ $sub_key ]['parent'] ) {
					$html .= brikpanel_nav_render_more_row( $brikpanel_more_plan['rows'][ $sub_key ] );
					continue;
				}
				$html .= brikpanel_nav_render_child_row( $sub_item, $item_slug, $admin_is_parent, $id );
			}
			$html .= '</ul>';
		}

		$html .= '</li>';
	}

	// Last user-created group never met a following heading, so close it here.
	// (The built-in "Site management" wrapper is deliberately left open — the
	// browser closes it at </ul>, exactly as it always has.)
	$brikpanel_close_group();

	if ( brikpanel_nav_ame_active() ) {
		global $wp_menu_editor;
		if ( is_object( $wp_menu_editor )
			&& method_exists( $wp_menu_editor, 'load_custom_menu' )
			&& method_exists( $wp_menu_editor, 'replace_wp_menu' )
			&& method_exists( $wp_menu_editor, 'restore_wp_menu' )
			&& $wp_menu_editor->load_custom_menu() ) {
			$wp_menu_editor->restore_wp_menu();
		}
	}

	return $html;
}

/**
 * Move WooCommerce top menus to the top (for quick access to orders, reports, etc.).
 */
function brikpanel_move_woocommerce_to_top( $menu_order ) {
	// Same gate as the rest of the navigation override: a user who keeps the
	// native sidebar must also keep its native ordering, otherwise BrikPanel
	// silently reshuffles a menu it is not otherwise touching.
	if ( brikpanel_navigation_skip_super_admin_chrome() ) {
		return $menu_order;
	}
	if ( brikpanel_nav_ame_active() ) {
		return $menu_order;
	}

	// Analytics under whichever report this WooCommerce names it after
	// (`/analytics/revenue` before 4.2). The fixed `/analytics/overview` left it
	// at its registered position on older stores, below Posts.
	$analytics = brikpanel_nav_wc_analytics_slug( $menu_order );

	$new_positions = array(
		'woocommerce',
		'brikmarket',
		'edit.php?post_type=product',
		'wc-admin&path=/wc-pay-welcome-page',
		'wc-admin&path=/payments/connect',
		'wc-admin&path=/payments/overview',
		'' !== $analytics ? $analytics : 'wc-admin&path=/analytics/overview',
		'woocommerce-marketing',
	);

	$position_counter   = 0;
	$adjusted_positions = array();

	foreach ( $new_positions as $item ) {
		$current_index = array_search( $item, $menu_order );
		if ( false !== $current_index ) {
			$adjusted_positions[ $item ] = $position_counter;
			$position_counter++;
		}
	}

	foreach ( $adjusted_positions as $item => $new_position ) {
		$current_index = array_search( $item, $menu_order );
		if ( $current_index !== $new_position ) {
			$removed_item = array_splice( $menu_order, $current_index, 1 );
			array_splice( $menu_order, $new_position, 0, $removed_item );
		}
	}

	return $menu_order;
}
add_filter( 'menu_order', 'brikpanel_move_woocommerce_to_top' );

/**
 * Move one top-level row directly after another.
 *
 * Kept under this name for companion plugins: BrikMentor calls it from the
 * `brikpanel_nav_store_cluster_ready` hook and reads its existence as "the
 * modern sidebar is on", which is why it stays in this conditionally loaded
 * file. The work is done by brikpanel_nav_move_after(), the one implementation
 * the renderer and the Navigation settings snapshot share.
 *
 * @param array  $array            $menu-shaped rows.
 * @param string $item_to_move     Slug of the row to move.
 * @param string $after_item_value Slug of the row it should follow.
 * @return array
 */
function brikpanel_move_item_after( $array, $item_to_move, $after_item_value ) {
	return brikpanel_nav_move_after( $array, $item_to_move, $after_item_value );
}
