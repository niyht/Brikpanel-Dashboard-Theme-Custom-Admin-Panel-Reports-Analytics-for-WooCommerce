<?php
/**
 * BrikPanel screens as rows of WordPress's admin menu.
 *
 * BrikPanel replaces WordPress and WooCommerce screens with its own: the
 * dashboard, the product list and editor, the coupon list, Store Health, cart
 * share and order merge. Until 3.3.33 each was registered with an empty parent
 * (add_submenu_page( '', ... )), so it sat in no menu at all. Access plugins
 * such as Advanced Access Manager build their list of screens from WordPress's
 * menu, so they could neither show these screens nor manage them (wp.org,
 * excellira, 2026-10-08):
 *
 *  - with "Restricted by default", a shop manager got "The access is denied."
 *    right after logging in, and there was no row to allow;
 *  - a store that closed Products for a role still had BrikPanel's product list
 *    open, because BrikPanel sends Products to its own screen before the access
 *    plugin looks at the request.
 *
 * Each screen is now registered under the menu it belongs to, labelled
 * "<name> (BrikPanel)", so access plugins list it and a rule on the menu
 * reaches it. People never see the row:
 *
 *  - It carries the classes `hidden brikpanel-menu-only`. WordPress prints
 *    `hidden` on the row, and every BrikPanel reader of the menu (sidebar,
 *    "More", Cmd+K, the Navigation editor, page access) skips it through
 *    brikpanel_is_menu_only_row().
 *  - Its slug leaves $_parent_pages, so menu_page_url() has no address for it
 *    and WordPress's own command palette leaves it out.
 *  - brikpanel_screen_menu_late_pass() puts it last under its parent, so it
 *    never becomes the parent's own link, and drops it where it would be the
 *    only row the person has there.
 *  - brikpanel_screen_menu_render_pass() takes it out of the menu right before
 *    the menu is drawn, after access plugins have checked the request, and
 *    applies WordPress's own rule again: a menu whose only row leads to the
 *    menu itself has no dropdown (a shop manager's Dashboard).
 *
 * The old screen name (admin_page_<slug>) stays registered as well. The product
 * editor tells WordPress it is post.php, because SEO plugins need that (see the
 * bootstrap in brikpanel.php), and WordPress then looks the screen up under the
 * old name; so does any address that carries post_type. Where a row is removed
 * for someone, the screen keeps working exactly as before 3.3.33.
 *
 * A parent menu is used only when it exists and the person may open it;
 * otherwise the screen is registered without a parent, as before. Someone whose
 * role reaches a screen but not its parent menu (for example orders without
 * "edit others' orders") is therefore covered only by a rule on the screen's
 * own row in the access plugin, not by a rule on the parent menu.
 *
 * @package BrikPanel
 * @since   3.3.33
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BRIKPANEL_MENU_ONLY_CLASS' ) ) {
	define( 'BRIKPANEL_MENU_ONLY_CLASS', 'brikpanel-menu-only' );
}

/**
 * Whether a $submenu row is a BrikPanel screen kept in the menu for access
 * plugins only.
 *
 * @param mixed $row A $submenu row.
 * @return bool
 */
function brikpanel_is_menu_only_row( $row ) {
	return is_array( $row )
		&& isset( $row[4] ) && is_string( $row[4] )
		&& 1 === preg_match( '/(?:^|\s)' . BRIKPANEL_MENU_ONLY_CLASS . '(?:\s|$)/', $row[4] );
}

/**
 * The menu a BrikPanel screen sits under for the current person, or '' for no
 * menu (the registration every BrikPanel screen had before 3.3.33).
 *
 * The menu must already exist with its screen name known: registered before
 * its parent, WordPress files the page under one name and looks it up under
 * another, and the page fails with "Cannot load". The person must be able to
 * open the menu itself too, or the hidden row would keep a menu alive that
 * WordPress removes for them.
 *
 * @param string $wanted     Parent menu slug.
 * @param string $capability Capability of the screen.
 * @return string
 */
function brikpanel_screen_menu_parent( $wanted, $capability ) {
	global $menu, $admin_page_hooks;

	if ( ! is_string( $wanted ) || '' === $wanted || ! current_user_can( $capability ) ) {
		return '';
	}
	if ( ! is_array( $admin_page_hooks ) || ! isset( $admin_page_hooks[ $wanted ] ) || ! is_array( $menu ) ) {
		return '';
	}

	foreach ( $menu as $row ) {
		if ( ! is_array( $row ) || ! isset( $row[2] ) || $wanted !== $row[2] ) {
			continue;
		}
		$cap = isset( $row[1] ) ? $row[1] : '';
		if ( ( is_string( $cap ) && '' !== $cap ) || is_int( $cap ) ) {
			return current_user_can( $cap ) ? $wanted : '';
		}
		return '';
	}

	return '';
}

/**
 * Where WooCommerce lists coupons for the current person: Marketing on current
 * versions, WooCommerce's own menu where Marketing is off, a menu of their own
 * for people outside WooCommerce's menu. WooCommerce when coupons are off.
 *
 * @return string
 */
function brikpanel_screen_coupons_parent() {
	$type = post_type_exists( 'shop_coupon' ) ? get_post_type_object( 'shop_coupon' ) : null;

	if ( $type && is_string( $type->show_in_menu ) && '' !== $type->show_in_menu ) {
		return $type->show_in_menu;
	}
	if ( $type && true === $type->show_in_menu ) {
		return 'edit.php?post_type=shop_coupon';
	}

	return 'woocommerce';
}

/**
 * Registers a BrikPanel screen under the menu it belongs to, as a row only
 * access plugins see. See the file header for how the row stays out of sight.
 *
 * @param string   $wanted_parent Parent menu slug.
 * @param string   $title         Screen name, already translated.
 * @param string   $capability    Capability of the screen.
 * @param string   $slug          Page slug.
 * @param callable $callback      Renders the screen.
 * @return string[] Screen hooks the page answers to, to attach load- callbacks
 *                  to. Empty when the person may not open the page.
 */
function brikpanel_add_screen_page( $wanted_parent, $title, $capability, $slug, $callback ) {
	global $submenu, $_registered_pages, $_parent_pages;

	$parent = brikpanel_screen_menu_parent( $wanted_parent, $capability );
	$label  = '';
	if ( '' !== $parent ) {
		$label = sprintf(
			/* translators: %s: name of a BrikPanel screen, for example "Products". Shown only in the screen lists of access plugins such as Advanced Access Manager, next to WordPress's own screen of the same name. */
			__( '%s (BrikPanel)', 'brikpanel' ),
			$title
		);
	}

	$hook = add_submenu_page( $parent, $title, $label, $capability, $slug, $callback );
	if ( ! $hook ) {
		return array();
	}
	if ( '' === $parent ) {
		return array( $hook );
	}

	if ( isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
		foreach ( $submenu[ $parent ] as $key => $row ) {
			if ( is_array( $row ) && isset( $row[2] ) && $slug === $row[2] ) {
				$submenu[ $parent ][ $key ][4] = 'hidden ' . BRIKPANEL_MENU_ONLY_CLASS;
			}
		}
	}

	// menu_page_url() has no address for the screen, so WordPress's own command
	// palette, the one reader of this list, leaves the row out.
	if ( is_array( $_parent_pages ) ) {
		unset( $_parent_pages[ $slug ] );
	}

	$legacy = 'admin_page_' . $slug;
	if ( $legacy === $hook ) {
		return array( $hook );
	}
	add_action( $legacy, $callback );
	$_registered_pages[ $legacy ] = true;

	return array( $hook, $legacy );
}

/**
 * Puts every BrikPanel row last under its parent, once every plugin has built
 * its menus (WooCommerce re-sorts its Home at 20 and Marketing at 99).
 *
 * WordPress links a menu to its first row and, when that row is a page of
 * another file, makes it the menu's address; a BrikPanel row must never be that
 * row. Where the person has no other row under the parent, the BrikPanel row is
 * dropped there: the screen still opens through its old name, as before 3.3.33.
 *
 * @return void
 */
function brikpanel_screen_menu_late_pass() {
	global $submenu;

	if ( ! is_array( $submenu ) ) {
		return;
	}

	foreach ( array_keys( $submenu ) as $parent ) {
		if ( '' === $parent || ! is_array( $submenu[ $parent ] ) ) {
			continue;
		}

		$ours   = array();
		$others = 0;
		foreach ( $submenu[ $parent ] as $key => $row ) {
			if ( brikpanel_is_menu_only_row( $row ) ) {
				$ours[ $key ] = $row;
			} else {
				++$others;
			}
		}
		if ( ! $ours ) {
			continue;
		}

		foreach ( $ours as $key => $row ) {
			unset( $submenu[ $parent ][ $key ] );
			if ( $others ) {
				$submenu[ $parent ][] = $row;
			}
		}
		if ( ! $submenu[ $parent ] ) {
			unset( $submenu[ $parent ] );
		}
	}
}
add_action( 'admin_menu', 'brikpanel_screen_menu_late_pass', PHP_INT_MAX - 1 );

/**
 * The menu row the current BrikPanel screen stands for, so the sidebar shows it
 * as the current one, the way WooCommerce's own screen would be shown. Null on
 * every other screen.
 *
 * @return string|null
 */
function brikpanel_screen_menu_current_row() {
	global $plugin_page, $submenu;

	if ( ! is_string( $plugin_page ) ) {
		return null;
	}

	switch ( $plugin_page ) {
		case 'brikpanel-dashboard':
			return 'index.php';

		case 'brikpanel-products':
			return 'edit.php?post_type=product';

		case 'brikpanel-product-editor':
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks the highlighted menu row.
			$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
			// "Add new product" opens the editor on a fresh auto-draft.
			return ( $product_id && 'auto-draft' === get_post_status( $product_id ) )
				? 'post-new.php?post_type=product'
				: 'edit.php?post_type=product';

		case 'brikpanel-coupons':
			return 'edit.php?post_type=shop_coupon';

		case 'brikpanel-cart-share':
			// Its row in BrikPanel's "More" group (brikpanel_nav_relocate_wc_submenus()).
			return 'admin.php?page=brikpanel-cart-share';

		case 'brikpanel-merge-orders':
			// WooCommerce's own order screen when orders live in its tables.
			if ( isset( $submenu['woocommerce'] ) && is_array( $submenu['woocommerce'] ) ) {
				foreach ( $submenu['woocommerce'] as $row ) {
					if ( is_array( $row ) && isset( $row[2] ) && 'wc-orders' === $row[2] ) {
						return 'wc-orders';
					}
				}
			}
			return 'edit.php?post_type=shop_order';
	}

	return null;
}

/**
 * Right before the admin menu is drawn: takes the BrikPanel rows out of it and
 * marks the row the current BrikPanel screen stands for.
 *
 * Runs on `submenu_file`, which WordPress applies in menu-header.php after the
 * `parent_file` filter (where Advanced Access Manager reads the menu for its
 * list) and after every access check of the request, and before the menu is
 * read for drawing. WordPress removes a menu's only row when it leads to the
 * menu itself before plugins add theirs; that rule is applied again here, so a
 * shop manager's Dashboard stays a plain link.
 *
 * @param string|null $submenu_file Current submenu file.
 * @return string|null
 */
function brikpanel_screen_menu_render_pass( $submenu_file ) {
	global $submenu;

	if ( is_array( $submenu ) ) {
		foreach ( array_keys( $submenu ) as $parent ) {
			if ( ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}
			$touched = false;
			foreach ( $submenu[ $parent ] as $key => $row ) {
				if ( brikpanel_is_menu_only_row( $row ) ) {
					unset( $submenu[ $parent ][ $key ] );
					$touched = true;
				}
			}
			if ( ! $touched ) {
				continue;
			}
			if ( ! $submenu[ $parent ] ) {
				unset( $submenu[ $parent ] );
				continue;
			}
			if ( 1 === count( $submenu[ $parent ] ) ) {
				$only = reset( $submenu[ $parent ] );
				if ( is_array( $only ) && isset( $only[2] ) && (string) $parent === $only[2] ) {
					unset( $submenu[ $parent ] );
				}
			}
		}
	}

	$current = brikpanel_screen_menu_current_row();

	return null === $current ? $submenu_file : $current;
}
add_filter( 'submenu_file', 'brikpanel_screen_menu_render_pass' );
