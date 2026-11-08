<?php
/**
 * BrikPanel: a saved sidebar layout carried across BrikPanel versions.
 *
 * The Navigation editor stores the merchant's sidebar as rows keyed by menu
 * slug (brikpanel_nav_config). When a BrikPanel screen is merged into another,
 * its saved row points at a slug that no longer exists: the editor lists it as
 * "Not available on this site", and the screen that replaced it arrives as a
 * new, never-placed row (at the end of the store section, or not at all with
 * "Hide new menu items by default" on). The steps here rewrite those rows so a
 * customized sidebar keeps its shape and its visibility rules.
 *
 * brikpanel_nav_config_migrate() changes nothing it has already changed, so it
 * runs on every read (brikpanel_nav_config_get()), and is written back once per
 * version on each site (brikpanel_maybe_upgrade_db()) and after a settings
 * import, which can bring a layout saved by an older version.
 *
 * Loaded at file level: the upgrade routine runs on plugins_loaded, before the
 * Navigation module loads on init.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BRIKPANEL_NAV_CONFIG_OPTION' ) ) {
	define( 'BRIKPANEL_NAV_CONFIG_OPTION', 'brikpanel_nav_config' );
}

/**
 * A saved Navigation layout with every version step applied.
 *
 * @param mixed $config Decoded layout ([ 'version' => 1, 'items' => [ … ] ]).
 * @return mixed The same value when there was nothing to change.
 */
function brikpanel_nav_config_migrate( $config ) {
	if ( ! is_array( $config ) || empty( $config['items'] ) || ! is_array( $config['items'] ) ) {
		return $config;
	}
	$before = array_values( $config['items'] );
	$items  = brikpanel_nav_migrate_customers_page( $before );
	$items  = brikpanel_nav_migrate_customers_report( $items );
	$items  = brikpanel_nav_migrate_coupons_row( $items );
	if ( $items !== $before ) {
		$config['items'] = $items;
	}
	return $config;
}

/**
 * Write the stored layout back with brikpanel_nav_config_migrate() applied.
 *
 * @return bool Whether the stored layout changed.
 */
function brikpanel_nav_config_migrate_stored() {
	$raw = get_option( BRIKPANEL_NAV_CONFIG_OPTION, '' );
	if ( empty( $raw ) ) {
		return false;
	}
	$decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
	if ( ! is_array( $decoded ) ) {
		return false;
	}
	$migrated = brikpanel_nav_config_migrate( $decoded );
	if ( $migrated === $decoded ) {
		return false;
	}
	// Stored the way brikpanel_nav_config_save() stores it.
	update_option( BRIKPANEL_NAV_CONFIG_OPTION, wp_json_encode( $migrated ), false );
	if ( function_exists( 'brikpanel_nav_config_get' ) ) {
		brikpanel_nav_config_get( true );
	}
	return true;
}

add_action( 'brikpanel_settings_imported', 'brikpanel_nav_config_migrate_imported', 10, 1 );
/**
 * A layout exported by an older version is rewritten as soon as it lands.
 *
 * @param string[] $touched Option keys the import wrote or cleared.
 */
function brikpanel_nav_config_migrate_imported( $touched ) {
	if ( is_array( $touched ) && in_array( BRIKPANEL_NAV_CONFIG_OPTION, $touched, true ) ) {
		brikpanel_nav_config_migrate_stored();
	}
}

add_filter( 'brikpanel_nav_new_item_after', 'brikpanel_nav_customers_first_position', 10, 2 );
/**
 * Where Customers first appears in a layout that never held Segments or
 * Customer Analytics (saved before they existed, or with both rows deleted):
 * right under Orders, its place on a store that never touched the editor.
 *
 * @param string $after Slug to insert after.
 * @param string $slug  The new row's slug.
 * @return string
 */
function brikpanel_nav_customers_first_position( $after, $slug ) {
	return ( 'brikpanel-customers' === $slug && '' === (string) $after ) ? 'woocommerce' : $after;
}

add_filter( 'brikpanel_nav_new_item_after', 'brikpanel_nav_coupons_first_position', 10, 2 );
/**
 * Where Coupons first appears in a layout that never listed it (saved while
 * coupons were switched off, say): right under Analytics, where Marketing
 * stood and Coupons sits on a store that never touched the editor.
 *
 * @param string $after Slug to insert after.
 * @param string $slug  The new row's slug.
 * @return string
 */
function brikpanel_nav_coupons_first_position( $after, $slug ) {
	return ( 'edit.php?post_type=shop_coupon' === $slug && '' === (string) $after ) ? 'wc-admin&path=/analytics/overview' : $after;
}

// =============================================================================
// 3.3.36: SEGMENTS + CUSTOMER ANALYTICS → CUSTOMERS
// =============================================================================

/**
 * Segments and Customer Analytics became one Customers page.
 *
 * The Customers row takes the place of the first retired row, except that a
 * row kept in the store section below Orders moves to directly under Orders,
 * where Customers sits on a store that never touched the editor. A row the
 * merchant filed elsewhere (above Orders, in More, under Site management)
 * keeps its place. Whoever saw either retired row sees Customers; it is
 * hidden from everyone only when both rows were. Renames and icons of the
 * retired rows named the old screens and are left behind.
 *
 * @param array $items Saved rows, numerically indexed.
 * @return array
 */
function brikpanel_nav_migrate_customers_page( array $items ) {
	$retired = [ 'brikpanel-segments' => true, 'brikpanel-customer-analytics' => true ];
	$old     = [];
	$placed  = false;
	$orders  = null;
	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) || ! isset( $item['type'], $item['slug'] ) || 'system' !== $item['type'] ) {
			continue;
		}
		$slug = (string) $item['slug'];
		if ( isset( $retired[ $slug ] ) ) {
			$old[ $i ] = $item;
		} elseif ( 'brikpanel-customers' === $slug ) {
			$placed = true;
		} elseif ( 'woocommerce' === $slug && null === $orders ) {
			$orders = $i;
		}
	}
	if ( ! $old ) {
		return $items;
	}

	$first   = min( array_keys( $old ) );
	$section = brikpanel_nav_migrate_section( $old[ $first ] );
	$after   = 'store' === $section && null !== $orders && $orders < $first
		&& 'store' === brikpanel_nav_migrate_section( $items[ $orders ] );

	$row = null;
	if ( ! $placed ) {
		$rules = [];
		foreach ( $old as $item ) {
			$rules[] = brikpanel_nav_migrate_rule( $item );
		}
		$visibility = brikpanel_nav_migrate_union( $rules );
		$row        = array_merge(
			[
				'type'    => 'system',
				'slug'    => 'brikpanel-customers',
				'section' => $after ? 'store' : $section,
				'hidden'  => ! empty( $visibility['hidden'] ),
			],
			array_diff_key( $visibility, [ 'hidden' => true ] )
		);
	}

	$out = [];
	foreach ( $items as $i => $item ) {
		if ( isset( $old[ $i ] ) ) {
			if ( $row && ! $after && $i === $first ) {
				$out[] = $row;
			}
			continue;
		}
		$out[] = $item;
		if ( $row && $after && $i === $orders ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * WooCommerce's Customers report moved from under Orders into More (named
 * "Customers report" there). A visibility rule the merchant set on it under
 * Orders goes with it, so a report hidden from shop managers stays hidden; More
 * is added to the layout first when the layout predates it.
 *
 * @param array $items Saved rows, numerically indexed.
 * @return array
 */
function brikpanel_nav_migrate_customers_report( array $items ) {
	$from = 'wc-admin&path=/customers';
	$to   = 'admin.php?page=wc-admin&path=/customers';

	$moved = null;
	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) || ! isset( $item['type'], $item['slug'] ) || 'system' !== $item['type']
			|| 'woocommerce' !== (string) $item['slug'] || empty( $item['submenus'] ) || ! is_array( $item['submenus'] ) ) {
			continue;
		}
		$kept = [];
		foreach ( $item['submenus'] as $sub ) {
			if ( is_array( $sub ) && isset( $sub['slug'] ) && brikpanel_nav_migrate_slug_key( $sub['slug'] ) === $from ) {
				$moved = $sub;
				continue;
			}
			$kept[] = $sub;
		}
		if ( null !== $moved ) {
			if ( $kept ) {
				$items[ $i ]['submenus'] = $kept;
			} else {
				unset( $items[ $i ]['submenus'] );
			}
		}
		break;
	}
	if ( null === $moved ) {
		return $items;
	}

	$rule = brikpanel_nav_migrate_rule( $moved );
	if ( ! $rule['hidden'] && 'all' === $rule['audience'] ) {
		// Shown to everyone: More shows it that way without a row.
		return $items;
	}

	$more = null;
	foreach ( $items as $i => $item ) {
		if ( is_array( $item ) && isset( $item['type'], $item['slug'] ) && 'system' === $item['type'] && 'woocommerce-more' === (string) $item['slug'] ) {
			$more = $i;
			break;
		}
	}
	if ( null === $more ) {
		$more  = brikpanel_nav_migrate_more_position( $items );
		$items = array_merge(
			array_slice( $items, 0, $more ),
			[ [ 'type' => 'system', 'slug' => 'woocommerce-more', 'section' => 'store', 'hidden' => false ] ],
			array_slice( $items, $more )
		);
	}

	$subs = ( isset( $items[ $more ]['submenus'] ) && is_array( $items[ $more ]['submenus'] ) ) ? $items[ $more ]['submenus'] : [];
	foreach ( $subs as $sub ) {
		if ( is_array( $sub ) && isset( $sub['slug'] ) && brikpanel_nav_migrate_slug_key( $sub['slug'] ) === $to ) {
			// The merchant already decided about it in More.
			return $items;
		}
	}
	$subs[]                      = array_merge( [ 'slug' => $to, 'hidden' => $rule['hidden'] ], brikpanel_nav_migrate_rule_fields( $rule ) );
	$items[ $more ]['submenus'] = $subs;
	return $items;
}

// =============================================================================
// 3.3.37: COUPONS GETS ITS OWN ROW, MARKETING MOVES INTO "MORE"
// =============================================================================

/**
 * Coupons became a row of its own and Marketing moved into "More"
 * (brikpanel_nav_promote_coupons(), brikpanel_nav_default_more_slugs()).
 *
 * A saved layout gets the same. The Coupons row takes the place of the row it
 * was listed under: Marketing, or, in a layout saved while WooCommerce's
 * marketing feature was off, "More" (WooCommerce's own menu in a layout older
 * than "More"). Marketing kept in the store section moves into "More"; one the
 * merchant filed under Site management or in "More" stays there, and Coupons
 * takes its place in that section. Coupons stays hidden from whoever it was
 * hidden from: the rule on its row and the rule on the row above it both
 * applied, so both still do. A layout that already places Coupons is left
 * alone, which is also what makes this run once.
 *
 * @param array $items Saved rows, numerically indexed.
 * @return array
 */
function brikpanel_nav_migrate_coupons_row( array $items ) {
	$coupons = 'edit.php?post_type=shop_coupon';
	$parents = [];
	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) || ! isset( $item['type'], $item['slug'] ) || 'system' !== $item['type'] ) {
			continue;
		}
		$slug = (string) $item['slug'];
		if ( $coupons === $slug ) {
			return $items;
		}
		if ( in_array( $slug, [ 'woocommerce-marketing', 'woocommerce-more', 'woocommerce' ], true ) && ! isset( $parents[ $slug ] ) ) {
			$parents[ $slug ] = $i;
		}
	}

	// The row Coupons was listed under, and the rule on Coupons there.
	$from = null;
	$sub  = null;
	foreach ( [ 'woocommerce-marketing', 'woocommerce-more', 'woocommerce' ] as $slug ) {
		if ( ! isset( $parents[ $slug ] ) ) {
			continue;
		}
		$subs = ( isset( $items[ $parents[ $slug ] ]['submenus'] ) && is_array( $items[ $parents[ $slug ] ]['submenus'] ) ) ? $items[ $parents[ $slug ] ]['submenus'] : [];
		foreach ( $subs as $row ) {
			if ( is_array( $row ) && isset( $row['slug'] ) && brikpanel_nav_migrate_slug_key( $row['slug'] ) === $coupons ) {
				$sub = $row;
				break;
			}
		}
		// Marketing lists Coupons whether or not the editor saved a rule for it.
		if ( null !== $sub || 'woocommerce-marketing' === $slug ) {
			$from = $parents[ $slug ];
			break;
		}
	}
	if ( null === $from ) {
		return $items;
	}

	$parent     = $items[ $from ];
	$section    = brikpanel_nav_migrate_section( $parent );
	$visibility = brikpanel_nav_migrate_intersect( [ brikpanel_nav_migrate_rule( $parent ), brikpanel_nav_migrate_rule( null === $sub ? [] : $sub ) ] );
	$row        = array_merge(
		[
			'type'    => 'system',
			'slug'    => $coupons,
			'section' => $section,
			'hidden'  => ! empty( $visibility['hidden'] ),
		],
		array_diff_key( $visibility, [ 'hidden' => true ] )
	);

	// Coupons leaves the row it was listed under.
	if ( null !== $sub ) {
		$kept = [];
		foreach ( $items[ $from ]['submenus'] as $other ) {
			if ( ! ( is_array( $other ) && isset( $other['slug'] ) && brikpanel_nav_migrate_slug_key( $other['slug'] ) === $coupons ) ) {
				$kept[] = $other;
			}
		}
		if ( $kept ) {
			$items[ $from ]['submenus'] = $kept;
		} else {
			unset( $items[ $from ]['submenus'] );
		}
	}

	$parent_slug = (string) $parent['slug'];
	if ( 'woocommerce-marketing' === $parent_slug ) {
		if ( 'store' === $section ) {
			$items[ $from ]['section'] = 'more';
		}
		$at = $from;
	} else {
		// Listed inside "More" or WooCommerce: Coupons comes out into the store
		// section, under Analytics, where it sits on an untouched store.
		$row['section'] = 'store';
		$at             = brikpanel_nav_migrate_coupons_position( $items );
	}

	return array_merge( array_slice( $items, 0, $at ), [ $row ], array_slice( $items, $at ) );
}

/**
 * Where a Coupons row goes in a layout without Marketing: right under Analytics
 * when Analytics is in the store section, else right above Settings there,
 * else at the end of the store section.
 *
 * @param array $items Saved rows, numerically indexed.
 * @return int Index to insert at.
 */
function brikpanel_nav_migrate_coupons_position( array $items ) {
	$end      = count( $items );
	$settings = null;
	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$section = brikpanel_nav_migrate_section( $item );
		if ( 'site_management' === $section && $i < $end ) {
			$end = $i;
		}
		if ( 'store' !== $section || ! isset( $item['type'], $item['slug'] ) || 'system' !== $item['type'] ) {
			continue;
		}
		$slug = (string) $item['slug'];
		if ( 0 === strpos( $slug, 'wc-admin&path=/analytics/' ) ) {
			return $i + 1;
		}
		if ( 'admin.php?page=wc-settings' === $slug && null === $settings ) {
			$settings = $i;
		}
	}
	return null !== $settings ? $settings : $end;
}

// =============================================================================
// HELPERS
// =============================================================================

/**
 * A saved row's section, as brikpanel_nav_customizer_apply() reads it.
 *
 * @param array $item Saved row.
 * @return string store | site_management | more
 */
function brikpanel_nav_migrate_section( array $item ) {
	$section = isset( $item['section'] ) ? (string) $item['section'] : 'store';
	return in_array( $section, [ 'store', 'site_management', 'more' ], true ) ? $section : 'store';
}

/**
 * A submenu slug in the form saved and live rows are compared in
 * (brikpanel_nav_slug_key(), which loads later).
 *
 * @param mixed $slug Slug.
 * @return string
 */
function brikpanel_nav_migrate_slug_key( $slug ) {
	return str_replace( '&amp;', '&', (string) $slug );
}

/**
 * Where a missing More row goes: under Settings when Settings is in the store
 * section, else at the end of the store section, as on an untouched store.
 *
 * @param array $items Saved rows, numerically indexed.
 * @return int Index to insert at.
 */
function brikpanel_nav_migrate_more_position( array $items ) {
	$end = count( $items );
	foreach ( $items as $i => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$section = brikpanel_nav_migrate_section( $item );
		if ( 'site_management' === $section && $i < $end ) {
			$end = $i;
		}
		if ( 'store' === $section && isset( $item['type'], $item['slug'] ) && 'system' === $item['type'] && 'admin.php?page=wc-settings' === (string) $item['slug'] ) {
			return $i + 1;
		}
	}
	return $end;
}

/**
 * A saved row's visibility in one shape: the rules
 * brikpanel_nav_cfg_hidden_for_current_user() applies, with a rule whose list
 * is empty read as "everyone", the way it reads it.
 *
 * @param array $row Saved row or submenu row.
 * @return array{hidden: bool, audience: string, hide_roles: string[], show_roles: string[], show_caps: string[]}
 */
function brikpanel_nav_migrate_rule( array $row ) {
	$rule     = [
		'hidden'     => ! empty( $row['hidden'] ),
		'audience'   => 'all',
		'hide_roles' => [],
		'show_roles' => [],
		'show_caps'  => [],
	];
	$audience = isset( $row['audience'] ) ? (string) $row['audience'] : 'all';
	$lists    = [ 'roles' => 'hide_roles', 'only_roles' => 'show_roles', 'caps' => 'show_caps' ];
	if ( 'admins' === $audience ) {
		$rule['audience'] = 'admins';
	} elseif ( isset( $lists[ $audience ] ) ) {
		$key    = $lists[ $audience ];
		$values = [];
		foreach ( ( isset( $row[ $key ] ) && is_array( $row[ $key ] ) ) ? $row[ $key ] : [] as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$values[] = $value;
			}
		}
		if ( $values ) {
			$rule['audience'] = $audience;
			$rule[ $key ]     = array_values( array_unique( $values ) );
		}
	}
	return $rule;
}

/**
 * The saved fields of a rule (nothing for "everyone").
 *
 * @param array $rule From brikpanel_nav_migrate_rule().
 * @return array
 */
function brikpanel_nav_migrate_rule_fields( array $rule ) {
	switch ( $rule['audience'] ) {
		case 'admins':
			return [ 'audience' => 'admins' ];
		case 'roles':
			return [ 'audience' => 'roles', 'hide_roles' => $rule['hide_roles'] ];
		case 'only_roles':
			return [ 'audience' => 'only_roles', 'show_roles' => $rule['show_roles'] ];
		case 'caps':
			return [ 'audience' => 'caps', 'show_caps' => $rule['show_caps'] ];
	}
	return [];
}

/**
 * One rule that shows a row to whoever any of $rules showed it to.
 *
 * Two rules of the same kind combine exactly. Two different kinds (only
 * administrators and a capability, say) are decided role by role: a role sees
 * the row when it saw either retired row, and the result names the roles that
 * do not see it ("Hide from these roles") or the ones that do, whichever list
 * is shorter.
 *
 * @param array[] $rules From brikpanel_nav_migrate_rule().
 * @return array Saved fields; [ 'hidden' => true ] when no rule showed the row.
 */
function brikpanel_nav_migrate_union( array $rules ) {
	$shown = [];
	foreach ( $rules as $rule ) {
		if ( ! $rule['hidden'] ) {
			if ( 'all' === $rule['audience'] ) {
				return [];
			}
			$shown[] = $rule;
		}
	}
	if ( ! $shown ) {
		return [ 'hidden' => true ];
	}
	if ( 1 === count( $shown ) ) {
		return brikpanel_nav_migrate_rule_fields( $shown[0] );
	}

	$a = $shown[0];
	$b = $shown[1];
	if ( $a['audience'] === $b['audience'] ) {
		switch ( $a['audience'] ) {
			case 'admins':
				return [ 'audience' => 'admins' ];
			case 'roles':
				// Hidden only from the roles both rows were hidden from.
				$hide = array_values( array_intersect( $a['hide_roles'], $b['hide_roles'] ) );
				return $hide ? [ 'audience' => 'roles', 'hide_roles' => $hide ] : [];
			case 'only_roles':
				return [ 'audience' => 'only_roles', 'show_roles' => array_values( array_unique( array_merge( $a['show_roles'], $b['show_roles'] ) ) ) ];
			case 'caps':
				// The editor keeps up to 20 capabilities on a row.
				return [ 'audience' => 'caps', 'show_caps' => array_slice( array_values( array_unique( array_merge( $a['show_caps'], $b['show_caps'] ) ) ), 0, 20 ) ];
		}
	}

	$roles = array_keys( wp_roles()->get_names() );
	$sees  = [];
	$not   = [];
	foreach ( $roles as $role ) {
		$role = (string) $role;
		if ( brikpanel_nav_migrate_role_sees( $a, $role ) || brikpanel_nav_migrate_role_sees( $b, $role ) ) {
			$sees[] = $role;
		} else {
			$not[] = $role;
		}
	}
	if ( ! $not ) {
		return [];
	}
	if ( ! $sees ) {
		return brikpanel_nav_migrate_rule_fields( $a );
	}
	return count( $not ) <= count( $sees )
		? [ 'audience' => 'roles', 'hide_roles' => $not ]
		: [ 'audience' => 'only_roles', 'show_roles' => $sees ];
}

/**
 * One rule that shows a row only to whoever every one of $rules showed it to:
 * a row that sat under another, which both had a say in.
 *
 * Two rules of the same kind combine exactly where the saved shape allows it.
 * Otherwise it is decided role by role, as brikpanel_nav_migrate_union() does:
 * a role sees the row when it saw it under every rule, and the result names
 * the roles that do not see it or the ones that do, whichever list is shorter.
 *
 * @param array[] $rules From brikpanel_nav_migrate_rule().
 * @return array Saved fields; [ 'hidden' => true ] when no role sees the row.
 */
function brikpanel_nav_migrate_intersect( array $rules ) {
	$narrow = [];
	foreach ( $rules as $rule ) {
		if ( $rule['hidden'] ) {
			return [ 'hidden' => true ];
		}
		if ( 'all' !== $rule['audience'] ) {
			$narrow[] = $rule;
		}
	}
	if ( ! $narrow ) {
		return [];
	}
	if ( 1 === count( $narrow ) ) {
		return brikpanel_nav_migrate_rule_fields( $narrow[0] );
	}

	$a = $narrow[0];
	$b = $narrow[1];
	if ( 2 === count( $narrow ) && $a['audience'] === $b['audience'] ) {
		switch ( $a['audience'] ) {
			case 'admins':
				return [ 'audience' => 'admins' ];
			case 'roles':
				// Hidden from the roles either row was hidden from.
				return [ 'audience' => 'roles', 'hide_roles' => array_values( array_unique( array_merge( $a['hide_roles'], $b['hide_roles'] ) ) ) ];
			case 'only_roles':
				$show = array_values( array_intersect( $a['show_roles'], $b['show_roles'] ) );
				return $show ? [ 'audience' => 'only_roles', 'show_roles' => $show ] : [ 'hidden' => true ];
		}
	}

	$sees = [];
	$not  = [];
	foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
		$role  = (string) $role;
		$shown = true;
		foreach ( $narrow as $rule ) {
			if ( ! brikpanel_nav_migrate_role_sees( $rule, $role ) ) {
				$shown = false;
				break;
			}
		}
		if ( $shown ) {
			$sees[] = $role;
		} else {
			$not[] = $role;
		}
	}
	if ( ! $sees ) {
		return [ 'hidden' => true ];
	}
	if ( ! $not ) {
		return [];
	}
	return count( $not ) <= count( $sees )
		? [ 'audience' => 'roles', 'hide_roles' => $not ]
		: [ 'audience' => 'only_roles', 'show_roles' => $sees ];
}

/**
 * Whether someone holding only $role sees a row under $rule, read the way
 * brikpanel_nav_cfg_hidden_for_current_user() reads it.
 *
 * @param array  $rule From brikpanel_nav_migrate_rule().
 * @param string $role Role name.
 * @return bool
 */
function brikpanel_nav_migrate_role_sees( array $rule, $role ) {
	switch ( $rule['audience'] ) {
		case 'admins':
			return 'administrator' === $role;
		case 'roles':
			return ! in_array( $role, $rule['hide_roles'], true );
		case 'only_roles':
			return in_array( $role, $rule['show_roles'], true );
		case 'caps':
			$object = get_role( $role );
			if ( ! $object ) {
				return false;
			}
			foreach ( $rule['show_caps'] as $cap ) {
				if ( $object->has_cap( $cap ) ) {
					return true;
				}
			}
			return false;
	}
	return true;
}
