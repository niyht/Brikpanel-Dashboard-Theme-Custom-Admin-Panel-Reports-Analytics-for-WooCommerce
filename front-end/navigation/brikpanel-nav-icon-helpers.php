<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared sidebar helpers, loaded on every admin request before both
 * brikpanel-navigation.php and brikpanel-nav-customizer.php.
 *
 * The navigation module (brikpanel-navigation.php) is only loaded when the
 * "modern navigation" toggle is on, because it registers the hooks that rebuild
 * the admin sidebar. The navigation customizer settings screen, however, is
 * loaded unconditionally (admins can pre-configure the layout before flipping
 * the toggle). Anything both of them need lives here: the icon helpers (the
 * icon picker used them while the toggle was off and fataled with "call to
 * undefined function"), the Admin Menu Editor check, and the default order of
 * the store part of the sidebar, which the live sidebar and the Navigation
 * settings snapshot must share exactly.
 *
 * Definitions only, each behind a function_exists guard, so this file can be
 * required from anywhere (tools/test-wc-detect.php loads it under WP-CLI).
 */

if ( ! function_exists( 'brikpanel_nav_icon_style' ) ) {
	/**
	 * Resolve the sidebar icon style preference ('solid' | 'line'). 'solid' is the
	 * default (the original filled/dark icons that match Shopify's admin); 'line'
	 * swaps in the thin outline set. Set from Settings > BrikPanel > Appearance.
	 * Statically cached — read once per request.
	 *
	 * @return string
	 */
	function brikpanel_nav_icon_style() {
		static $style = null;
		if ( $style === null ) {
			$saved = get_option( 'brikpanel_nav_icon_style', 'solid' );
			$style = ( $saved === 'line' ) ? 'line' : 'solid';
		}
		return $style;
	}
}

if ( ! function_exists( 'brikpanel_nav_icon_src' ) ) {
	/**
	 * Build the URL for a built-in sidebar icon, appending the plugin version as a
	 * cache-busting query. The icon SVGs are referenced with stable filenames, so
	 * without a version query a browser keeps serving a previously-cached copy —
	 * which means an icon redesign shipped in an update would stay invisible to
	 * existing users until their cache expired. The version bump on every release
	 * forces a fresh fetch.
	 *
	 * When the 'line' style is active the thin variant under icons/line/ is used,
	 * falling back to the solid icon whenever a line variant doesn't exist (so a
	 * brand icon without a line version still renders).
	 *
	 * @param string $file  Icon slug (filename without extension).
	 * @param string $style Optional explicit style ('solid'|'line'); defaults to the saved preference.
	 * @return string
	 */
	function brikpanel_nav_icon_src( $file, $style = null ) {
		if ( $style === null ) {
			$style = brikpanel_nav_icon_style();
		}
		$rel = 'icons/' . $file . '.svg';
		if ( $style === 'line' && file_exists( __DIR__ . '/icons/line/' . $file . '.svg' ) ) {
			$rel = 'icons/line/' . $file . '.svg';
		}
		return plugins_url( $rel, __FILE__ ) . '?ver=' . BRIKPANEL_VERSION;
	}
}

if ( ! function_exists( 'brikpanel_nav_line_icon_slugs' ) ) {
	/**
	 * List of icon slugs that ship a thin "line" variant under icons/line/. The
	 * customizer JS uses it to build the right icon URL for client-created rows
	 * when the line style is active. Statically cached.
	 *
	 * @return string[]
	 */
	function brikpanel_nav_line_icon_slugs() {
		static $slugs = null;
		if ( $slugs === null ) {
			$slugs = [];
			foreach ( (array) glob( __DIR__ . '/icons/line/*.svg' ) as $path ) {
				$slugs[] = basename( $path, '.svg' );
			}
		}
		return $slugs;
	}
}

if ( ! function_exists( 'brikpanel_nav_ame_active' ) ) {
	/**
	 * Whether Admin Menu Editor is running and so owns the admin menu layout.
	 *
	 * Lives here, not in brikpanel-navigation.php, because the sidebar renderer
	 * and the customizer's snapshot of it both branch on this answer and must
	 * never disagree; this file is loaded before either of them.
	 *
	 * Asked by class, never by folder. The old check was
	 * is_plugin_active( 'admin-menu-editor/menu-editor.php' ), which only sees the
	 * free plugin in its wp.org folder: Admin Menu Editor Pro ships the same
	 * menu-editor.php from its own folder, so on a Pro store BrikPanel laid its
	 * own menu order, "More" folding and "Site management" group over the menu
	 * the merchant had built in AME. WPMenuEditor is the class both editions
	 * define, and it is exactly what AME itself asks to detect another copy of
	 * itself (includes/version-conflict-check.php).
	 *
	 * @return bool
	 */
	function brikpanel_nav_ame_active() {
		return class_exists( 'WPMenuEditor', false );
	}
}

if ( ! function_exists( 'brikpanel_nav_move_after' ) ) {
	/**
	 * Move one top-level row so it sits directly after another. The menu comes
	 * back unchanged (but re-indexed) when either row is missing.
	 *
	 * The one implementation of "move a sidebar row". The renderer, the
	 * Navigation settings snapshot and companion plugins (through
	 * brikpanel_move_item_after()) all use it, so they can never place a row
	 * differently.
	 *
	 * Positions are taken after array_values(). WordPress keys $menu by position
	 * only until a row is unset (the relocation moves Expenses out, a plugin may
	 * call remove_menu_page() late), and slicing by such a key used to drop the
	 * moved row one place too low.
	 *
	 * @param array  $menu             $menu-shaped rows.
	 * @param string $item_to_move     Slug ($row[2]) of the row to move.
	 * @param string $after_item_value Slug of the row it should follow.
	 * @return array
	 */
	function brikpanel_nav_move_after( $menu, $item_to_move, $after_item_value ) {
		if ( ! is_array( $menu ) ) {
			return $menu;
		}
		$menu     = array_values( $menu );
		$move_at  = null;
		$after_at = null;
		foreach ( $menu as $index => $row ) {
			// Skip scalar entries a third-party plugin may have left in $menu.
			if ( ! is_array( $row ) || ! isset( $row[2] ) ) {
				continue;
			}
			if ( $row[2] === $item_to_move ) {
				$move_at = $index;
			}
			if ( $row[2] === $after_item_value ) {
				$after_at = $index;
			}
		}
		if ( null === $move_at || null === $after_at || $move_at === $after_at ) {
			return $menu;
		}

		$moving = $menu[ $move_at ];
		unset( $menu[ $move_at ] );
		$menu = array_values( $menu );
		if ( $move_at < $after_at ) {
			$after_at--;
		}
		array_splice( $menu, $after_at + 1, 0, array( $moving ) );

		return $menu;
	}
}

if ( ! function_exists( 'brikpanel_nav_wc_analytics_slug' ) ) {
	/**
	 * The slug WooCommerce registered its Analytics top-level under, or '' when
	 * the menu has none.
	 *
	 * WooCommerce names that menu after its landing report, and the landing
	 * report changed: `wc-admin&path=/analytics/revenue` in 4.0 and 4.1,
	 * `wc-admin&path=/analytics/overview` from 4.2 on (checked against the
	 * release packages). Matching only the newer slug left Analytics on older
	 * stores in "Site management", with a generic icon and a dropdown that never
	 * opened on its own reports.
	 *
	 * @param array $items $menu rows, or plain slugs (the menu_order filter's list).
	 * @return string
	 */
	function brikpanel_nav_wc_analytics_slug( $items ) {
		if ( ! is_array( $items ) ) {
			return '';
		}
		$found = '';
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$slug = isset( $item[2] ) && is_string( $item[2] ) ? $item[2] : '';
			} else {
				$slug = is_string( $item ) ? $item : '';
			}
			if ( 'wc-admin&path=/analytics/overview' === $slug ) {
				return $slug;
			}
			if ( '' === $found && 0 === strpos( $slug, 'wc-admin&path=/analytics/' ) ) {
				$found = $slug;
			}
		}
		return $found;
	}
}

if ( ! function_exists( 'brikpanel_nav_store_tail_anchor' ) ) {
	/**
	 * The row Settings and More follow: the last WooCommerce or BrikPanel store
	 * row this menu has.
	 *
	 * Settings and More close the store part of the sidebar. They used to be
	 * pinned after Marketing only, so wherever Marketing was missing
	 * (WooCommerce 4.0, and every role without `manage_woocommerce`) they stayed
	 * where the relocation appended them, at the very end of the menu, inside
	 * the collapsed "Site management" group.
	 *
	 * The candidates run from the bottom of the store part to the top, in the
	 * order brikpanel_nav_apply_store_order() leaves them, and the first one the
	 * menu really has wins. A candidate at or below the "Site management" line
	 * (`edit.php`, the same line brikpanel_nav_demote_foreign_toplevels() uses)
	 * is skipped, so Settings and More are never pulled down there with it.
	 *
	 * @param array $menu $menu-shaped rows.
	 * @return string Slug, or '' when no candidate qualifies.
	 */
	function brikpanel_nav_store_tail_anchor( $menu ) {
		if ( ! is_array( $menu ) ) {
			return '';
		}
		$position = array();
		foreach ( array_values( $menu ) as $index => $row ) {
			if ( is_array( $row ) && isset( $row[2] ) && is_string( $row[2] ) ) {
				$position[ $row[2] ] = $index;
			}
		}
		$line = isset( $position['edit.php'] ) ? $position['edit.php'] : PHP_INT_MAX;

		$candidates = array(
			'woocommerce-marketing',
			brikpanel_nav_wc_analytics_slug( $menu ),
			'wc-admin&path=/payments/overview',
			'wc-admin&path=/payments/connect',
			'wc-admin&path=/wc-pay-welcome-page',
			'admin.php?page=wc-settings&tab=checkout',
			'wf_woocommerce_packing_list',
			'brikpanel-abandoned-carts',
			'brikpanel-google-sheets',
			'brikpanel-customer-analytics',
			'brikpanel-segments',
			'edit.php?post_type=product',
			'brikmarket',
			'woocommerce',
		);
		foreach ( $candidates as $slug ) {
			if ( '' !== $slug && isset( $position[ $slug ] ) && $position[ $slug ] < $line ) {
				return $slug;
			}
		}
		return '';
	}
}

if ( ! function_exists( 'brikpanel_nav_apply_store_order' ) ) {
	/**
	 * The default order of the store part of the modern sidebar, applied in place.
	 *
	 * The single source of that order: the live renderer
	 * (brikpanel_get_navigation_items()) and the Navigation settings snapshot
	 * (brikpanel_nav_customizer_apply_default_reorder()) both call it. They used
	 * to carry two copies that had drifted apart: the snapshot never fired the
	 * store-cluster hook, so the editor filed BrikMentor under "Site management"
	 * while the sidebar showed it in the store section, and saving the screen
	 * unchanged moved it there for real.
	 *
	 * Runs after brikpanel_nav_relocate_wc_submenus(), which appends More and the
	 * promoted Settings, and before a saved Navigation layout is applied, so an
	 * explicit placement still wins.
	 *
	 * @param array $menu $menu-shaped rows, by reference.
	 */
	function brikpanel_nav_apply_store_order( &$menu ) {
		if ( ! is_array( $menu ) ) {
			return;
		}

		// Products directly below WooCommerce (Orders). WordPress has already
		// sorted the menu through the menu_order filters, but WooCommerce's own
		// filter decides where Products lands, and when it runs before
		// BrikPanel's (for example when only WooCommerce is network-activated)
		// Products sorts below Posts. Pinning it here keeps it, and the BrikPanel
		// rows pinned beneath it, in the store section.
		$menu = brikpanel_nav_move_after( $menu, 'edit.php?post_type=product', 'woocommerce' );
		$menu = brikpanel_nav_move_after( $menu, 'admin.php?page=wc-settings&tab=checkout', 'edit.php?post_type=product' );
		$menu = brikpanel_nav_move_after( $menu, 'wf_woocommerce_packing_list', 'edit.php?post_type=product' );

		// BrikPanel's own analytics sit directly under Products: Segments,
		// Customer Analytics, Google Sheets, then Abandoned Carts (after Sheets
		// when present, otherwise after Customer Analytics; the second call is a
		// no-op without Sheets).
		$menu = brikpanel_nav_move_after( $menu, 'brikpanel-segments', 'edit.php?post_type=product' );
		$menu = brikpanel_nav_move_after( $menu, 'brikpanel-customer-analytics', 'brikpanel-segments' );
		$menu = brikpanel_nav_move_after( $menu, 'brikpanel-google-sheets', 'brikpanel-customer-analytics' );
		$menu = brikpanel_nav_move_after( $menu, 'brikpanel-abandoned-carts', 'brikpanel-customer-analytics' );
		$menu = brikpanel_nav_move_after( $menu, 'brikpanel-abandoned-carts', 'brikpanel-google-sheets' );

		// Settings, then More, close the store part. Both follow the same row,
		// More first, so Settings lands above it. With Marketing in the menu this
		// is exactly the old "after Marketing" placement.
		$tail = brikpanel_nav_store_tail_anchor( $menu );
		if ( '' !== $tail ) {
			$menu = brikpanel_nav_move_after( $menu, 'woocommerce-more', $tail );
			$menu = brikpanel_nav_move_after( $menu, 'admin.php?page=wc-settings', $tail );
		}

		/**
		 * Companion plugins (e.g. BrikMentor) pin their own top-levels into the
		 * store part here, now that BrikPanel's rows are in place. Runs before
		 * the foreign-top-level demotion, so pinned rows are treated as store
		 * (paired with the `brikpanel_nav_is_store_slug` filter).
		 *
		 * Fires for the live sidebar and for the Navigation settings snapshot,
		 * which works on a copy, so it can run more than once per request.
		 * Listeners must only change the array they are given (the global
		 * $submenu is not relocated for the snapshot) and should stay idempotent.
		 * Move rows with brikpanel_nav_move_after(); brikpanel_move_item_after()
		 * does the same but only exists while the modern sidebar is on.
		 * brikpanel_nav_store_tail_anchor( $menu ) names the row Settings follows.
		 *
		 * @param array $menu The $menu-shaped array (by reference).
		 */
		do_action_ref_array( 'brikpanel_nav_store_cluster_ready', array( &$menu ) );

		// Third-party plugins (Elementor, Multi Currency, Hezarfen, ...) often
		// register their top-level with a tiny menu position, which sorts them
		// above the Posts (edit.php) line and into the store part. Demote them
		// into "Site management".
		if ( function_exists( 'brikpanel_nav_demote_foreign_toplevels' ) ) {
			brikpanel_nav_demote_foreign_toplevels( $menu );
		}
	}
}
