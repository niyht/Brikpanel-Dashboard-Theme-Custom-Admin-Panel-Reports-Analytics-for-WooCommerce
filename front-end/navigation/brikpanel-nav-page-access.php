<?php
/**
 * BrikPanel — pages hidden from the sidebar
 *
 * The Navigation editor hides sidebar items from some users (a role, a
 * permission, everyone but administrators) or from everyone. Until 3.3.25 that
 * only removed the row: the page still opened from a link, the Cmd+K palette
 * still offered it, and the top bar still linked to it (wp.org, nikash70,
 * 2026-09-28: a shop manager with Settings hidden opened the store settings
 * from the address bar, from the palette and through the Payments row).
 *
 * This file answers one question for the current user: does a Navigation rule
 * hide the page a link opens? Two things use the answer.
 *
 *  - The Cmd+K navigation index leaves hidden pages out, always, so the palette
 *    lists what the sidebar lists (brikpanel_nav_url_hidden_for_current_user()).
 *  - With "Block pages hidden from the menu" on, the page is closed: opening it
 *    shows a "not available" card instead, and BrikPanel's shortcuts to it are
 *    left out (brikpanel_nav_url_blocked_for_current_user()). Administrators are
 *    never blocked, so nobody can lock themselves out of the rules.
 *
 * WHICH ROWS. The sidebar's own code decides: the same relocation and
 * brikpanel_nav_customizer_apply() run on copies of $menu / $submenu, and
 * apply() reports every row it leaves out and why. Nothing here repeats a
 * rule. A row hidden by a rule closes its page; a row "Hide new menu items by
 * default" keeps out until it is reviewed does not, because an administrator
 * cannot review menus WordPress only builds for other roles, or rows whose slug
 * changes with a plugin's state, and those staff would be locked out for good.
 *
 * WHICH PAGE A LINK OPENS. A page is closed only when every sidebar row that
 * leads to it most directly is hidden. The same page is often reachable from
 * two rows: WooCommerce's Payments row opens Settings on its Payments tab, so
 * with Settings hidden and Payments shown, the Payments tab stays open and the
 * other tabs close. Screens that open one object (a product, an order, a term,
 * a user) belong to their list and their "add new" rows together: someone who
 * may add products must be able to edit the product they just added.
 *
 * It closes screens. It does not change what a role may do: admin-ajax,
 * admin-post and the REST API behind a closed screen still answer, and so do
 * pages the BrikPanel sidebar never lists.
 *
 * @package BrikPanel
 * @since   3.3.25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BRIKPANEL_NAV_BLOCK_OPTION' ) ) {
	define( 'BRIKPANEL_NAV_BLOCK_OPTION', 'brikpanel_nav_block_hidden_pages' );
}
if ( ! defined( 'BRIKPANEL_NAV_BLOCKED_PAGE' ) ) {
	define( 'BRIKPANEL_NAV_BLOCKED_PAGE', 'brikpanel-page-blocked' );
}
if ( ! defined( 'BRIKPANEL_NAV_BLOCKED_SOURCES_META' ) ) {
	define( 'BRIKPANEL_NAV_BLOCKED_SOURCES_META', 'brikpanel_nav_blocked_sources_' );
}

// =============================================================================
// SETTING AND AUDIENCE
// =============================================================================

/**
 * Whether "Block pages hidden from the menu" is on.
 *
 * @return bool
 */
function brikpanel_nav_block_hidden_pages_enabled() {
	return get_option( BRIKPANEL_NAV_BLOCK_OPTION, 'no' ) === 'yes';
}

/**
 * Whether the current user is never blocked: administrators and network
 * administrators, decided by role as every BrikPanel admin gate is.
 *
 * @return bool
 */
function brikpanel_nav_user_never_blocked() {
	if ( function_exists( 'brikpanel_nav_current_user_is_admin' ) ) {
		return brikpanel_nav_current_user_is_admin();
	}
	return function_exists( 'brikpanel_user_is_administrator' ) && brikpanel_user_is_administrator();
}

/**
 * Whether the Navigation rules shape what the current user sees on this
 * request: the BrikPanel sidebar is drawn for them and a layout is saved.
 *
 * When BrikPanel's sidebar is not in use (modern navigation off, BrikPanel
 * switched off for the user, Desktop Mode, network admin) the user sees
 * WordPress's own menu, where no rule applies, so nothing is hidden or closed
 * either. With Admin Menu Editor active the sidebar is built from that
 * plugin's menu at render time, which this file cannot rebuild early, so it
 * steps aside as well.
 *
 * @return bool
 */
function brikpanel_nav_rules_active_for_current_user() {
	if ( ! is_admin() || wp_doing_ajax() ) {
		return false;
	}
	if ( ! function_exists( 'brikpanel_get_navigation_items' )
		|| ! function_exists( 'brikpanel_nav_relocate_wc_submenus' )
		|| ! function_exists( 'brikpanel_nav_resolve_submenu_rows' )
		|| ! function_exists( 'brikpanel_nav_customizer_apply' ) ) {
		return false;
	}
	if ( function_exists( 'brikpanel_navigation_skip_super_admin_chrome' ) && brikpanel_navigation_skip_super_admin_chrome() ) {
		return false;
	}
	if ( function_exists( 'brikpanel_nav_ame_active' ) && brikpanel_nav_ame_active() ) {
		return false;
	}
	$config = brikpanel_nav_config_get();
	return ! empty( $config['items'] );
}

// =============================================================================
// LINKS AND MENU ROWS AS COMPARABLE TARGETS
// =============================================================================

/**
 * Query arguments as strings, without the ones that only say where a screen
 * came from or where "Back" goes (WooCommerce's Payments row carries
 * `from=PAYMENTS_MENU_ITEM`, core's Customize link a `return` URL).
 *
 * @param array $args Raw arguments.
 * @return array<string,string>
 */
function brikpanel_nav_clean_args( array $args ) {
	static $noise = [
		'from'        => true,
		'return'      => true,
		'return_to'   => true,
		'redirect_to' => true,
		'_wpnonce'    => true,
		'ver'         => true,
	];
	$out = [];
	foreach ( $args as $key => $value ) {
		$key = (string) $key;
		if ( ! is_scalar( $value ) || isset( $noise[ $key ] ) ) {
			continue;
		}
		$out[ $key ] = (string) $value;
	}
	return $out;
}

/**
 * The screen a request opens: a plugin page (by its `page` value, whatever
 * file carries it) or a core file.
 *
 * A page value can carry more arguments after an "&": WooCommerce registers
 * its Analytics rows as "wc-admin&path=/analytics/revenue", and the palette
 * stores such a row's link with the whole slug encoded into `page`.
 *
 * @param string $file Admin file ('admin.php', 'edit.php', ...).
 * @param array  $args Query arguments.
 * @return array{kind:string,key:string,args:array<string,string>}
 */
function brikpanel_nav_request_target( $file, array $args ) {
	$args = brikpanel_nav_clean_args( $args );
	if ( isset( $args['page'] ) && '' !== $args['page'] ) {
		$page = $args['page'];
		unset( $args['page'] );
		if ( false !== strpos( $page, '&' ) ) {
			list( $page, $tail ) = explode( '&', $page, 2 );
			$extra = [];
			parse_str( $tail, $extra );
			foreach ( brikpanel_nav_clean_args( (array) $extra ) as $key => $value ) {
				if ( ! isset( $args[ $key ] ) ) {
					$args[ $key ] = $value;
				}
			}
		}
		return [ 'kind' => 'page', 'key' => $page, 'args' => $args ];
	}
	$file = (string) $file;
	return [ 'kind' => 'file', 'key' => '' === $file ? 'index.php' : $file, 'args' => $args ];
}

/**
 * Split a link into its admin file and arguments. Accepts an absolute URL,
 * a site-relative path ("/wp-admin/edit.php?...") or an admin-relative one
 * ("edit.php?..."). http and https count as the same site. A link to
 * anywhere outside this site's wp-admin returns null.
 *
 * @param string $url Link.
 * @return array{0:string,1:array<string,string>}|null [ file, args ].
 */
function brikpanel_nav_split_url( $url ) {
	$url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $url || '#' === $url[0] ) {
		return null;
	}

	if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url ) || '/' === $url[0] ) {
		$parts = wp_parse_url( $url );
		$admin = wp_parse_url( admin_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $admin ) ) {
			return null;
		}
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return null;
		}
		if ( isset( $parts['host'] ) && strtolower( (string) $parts['host'] ) !== strtolower( isset( $admin['host'] ) ? (string) $admin['host'] : '' ) ) {
			return null;
		}
		$admin_path = '/' . trim( isset( $admin['path'] ) ? (string) $admin['path'] : '/wp-admin/', '/' ) . '/';
		$path       = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( rtrim( $path, '/' ) . '/' === $admin_path ) {
			$path = $admin_path;
		}
		if ( 0 !== strpos( $path, $admin_path ) ) {
			return null;
		}
		$file  = (string) substr( $path, strlen( $admin_path ) );
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
	} else {
		$q     = strpos( $url, '?' );
		$file  = false === $q ? $url : substr( $url, 0, $q );
		$query = false === $q ? '' : substr( $url, $q + 1 );
	}

	$hash = strpos( $query, '#' );
	if ( false !== $hash ) {
		$query = substr( $query, 0, $hash );
	}
	$args = [];
	if ( '' !== $query ) {
		parse_str( $query, $args );
	}
	return [ '' === $file ? 'index.php' : $file, brikpanel_nav_clean_args( (array) $args ) ];
}

/**
 * Whether a menu slug that names a .php file is really a plugin page, by the
 * sidebar renderer's own test: WordPress knows a page hook for it, or the file
 * lives in the plugins folder and is not a wp-admin screen.
 *
 * @param string $slug   Full slug.
 * @param string $file   The slug's file part.
 * @param string $parent Parent slug ('' for a top-level row).
 * @return bool
 */
function brikpanel_nav_slug_is_plugin_file( $slug, $file, $parent ) {
	if ( function_exists( 'get_plugin_page_hook' ) && get_plugin_page_hook( $slug, '' !== $parent ? $parent : 'admin.php' ) ) {
		return true;
	}
	return 'index.php' !== $file
		&& defined( 'WP_PLUGIN_DIR' )
		&& file_exists( WP_PLUGIN_DIR . '/' . $file )
		&& ! file_exists( ABSPATH . 'wp-admin/' . $file );
}

/**
 * The screen a sidebar row opens.
 *
 * A slug without a .php file is a plugin page (admin.php?page=<slug>). A
 * plugin page listed under another screen's menu ("product_attributes" under
 * Products) opens by its page value alone, so the parent's own arguments do not
 * belong to it. Synthetic rows (custom links, spacers, headings, the "More"
 * container) open nothing and return null.
 *
 * @param string $slug   Row slug.
 * @param string $parent Parent slug ('' for a top-level row).
 * @return array{kind:string,key:string,args:array<string,string>}|null
 */
function brikpanel_nav_row_target( $slug, $parent = '' ) {
	$slug = trim( html_entity_decode( (string) $slug, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $slug || 'woocommerce-more' === $slug || preg_match( '/^brikpanel_(?:custom|spacer|heading)__/', $slug ) ) {
		return null;
	}

	if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $slug ) || '/' === $slug[0] ) {
		$split = brikpanel_nav_split_url( $slug );
		if ( null === $split ) {
			return null;
		}
		list( $file, $args ) = $split;
	} else {
		$q    = strpos( $slug, '?' );
		$file = false === $q ? $slug : substr( $slug, 0, $q );
		if ( '.php' !== substr( $file, -4 ) || brikpanel_nav_slug_is_plugin_file( $slug, $file, (string) $parent ) ) {
			return brikpanel_nav_request_target( 'admin.php', [ 'page' => $slug ] );
		}
		$args = [];
		if ( false !== $q ) {
			parse_str( substr( $slug, $q + 1 ), $args );
		}
		$args = brikpanel_nav_clean_args( (array) $args );
	}

	if ( isset( $args['page'] ) && '' !== $args['page'] && 'admin.php' !== $file ) {
		return brikpanel_nav_request_target( 'admin.php', [ 'page' => $args['page'] ] );
	}
	return brikpanel_nav_request_target( $file, $args );
}

/**
 * The post type a taxonomy screen lists terms for when the link names none:
 * the taxonomy's first object type, as WordPress picks it.
 *
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function brikpanel_nav_taxonomy_default_type( $taxonomy ) {
	$tax = get_taxonomy( $taxonomy );
	if ( $tax && ! empty( $tax->object_type ) && is_array( $tax->object_type ) ) {
		return (string) reset( $tax->object_type );
	}
	return 'post';
}

/**
 * The arguments that say WHICH screen a target is, with WordPress's defaults
 * filled in. Two targets of the same file are only the same screen when these
 * agree: edit.php lists posts unless a post_type says otherwise, and a
 * WooCommerce app page is its path ("" is WooCommerce Home, nothing else).
 *
 * @param array $target Target from brikpanel_nav_request_target().
 * @return array<string,string>
 */
function brikpanel_nav_target_identity( array $target ) {
	$args = $target['args'];
	if ( 'page' === $target['kind'] ) {
		$path = isset( $args['path'] ) ? '/' . trim( $args['path'], '/' ) : '';
		return [ 'path' => '/' === $path ? '' : $path ];
	}
	switch ( $target['key'] ) {
		case 'edit.php':
		case 'post-new.php':
			return [ 'post_type' => ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) ? $args['post_type'] : 'post' ];
		case 'edit-tags.php':
		case 'term.php':
			$taxonomy = ( isset( $args['taxonomy'] ) && '' !== $args['taxonomy'] ) ? $args['taxonomy'] : 'post_tag';
			return [
				'taxonomy'  => $taxonomy,
				'post_type' => ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) ? $args['post_type'] : brikpanel_nav_taxonomy_default_type( $taxonomy ),
			];
	}
	return [];
}

// =============================================================================
// THE CURRENT USER'S SIDEBAR ROWS
// =============================================================================

/**
 * Every page the current user's sidebar leads to or hides, indexed for lookup.
 *
 * Rows come from the sidebar's own steps run on copies of $menu / $submenu:
 * the WooCommerce relocation, then brikpanel_nav_customizer_apply(), which
 * reports what it drops (store order and the Vendors pin only reorder rows,
 * so they are skipped). Each row is one of:
 *  - visible:     the sidebar draws a link to it. A parent that shows a
 *                 dropdown links to its first listed child, so only its
 *                 listed children count; a parent without listed children
 *                 counts itself and the row it links to. Custom links the
 *                 user sees count too when they point into wp-admin.
 *  - hidden_rule: a Navigation rule hides it from this user (with its
 *                 children, when it is a top-level row).
 *  - hidden_new:  "Hide new menu items by default" keeps it out.
 * Children of an item moved into "More" are drawn nowhere but hidden by no
 * rule, so they are in no list and stay open.
 *
 * The first call after admin_init is kept for the rest of the request: the
 * sidebar renderer rewrites the globals at admin_footer, after which hidden
 * rows would be gone. Calls before admin_init (the palette index, captured on
 * admin_menu) are kept apart, because WordPress removes the top-level rows a
 * user cannot open only after admin_menu.
 *
 * @param bool $refresh Forget every kept result (tests with synthetic menus).
 * @return array{index:array<string,array<int,array>>}|array Empty when no rule applies.
 */
function brikpanel_nav_page_rows( $refresh = false ) {
	static $memo = [];
	static $last = [];

	if ( $refresh ) {
		$memo = [];
		$last = [];
	}
	if ( ! brikpanel_nav_rules_active_for_current_user() ) {
		return [];
	}

	global $menu, $submenu;
	$config = brikpanel_nav_config_get();
	$key    = implode( '|', [
		get_current_user_id(),
		get_current_blog_id(),
		did_action( 'admin_init' ) ? 'after' : 'before',
		md5( (string) wp_json_encode( $config ) ),
		brikpanel_nav_hide_new_items_enabled() ? 'hide-new' : '',
	] );
	if ( isset( $memo[ $key ] ) ) {
		return $memo[ $key ];
	}
	if ( did_action( 'admin_footer' ) || ! is_array( $menu ) || empty( $menu ) ) {
		return $last;
	}

	$m = $menu;
	$s = is_array( $submenu ) ? $submenu : [];
	brikpanel_nav_relocate_wc_submenus( $m, $s );
	$dropped = [];
	brikpanel_nav_customizer_apply( $m, $s, $dropped );

	$index = [];
	$add   = static function ( $target, $state ) use ( &$index ) {
		if ( null === $target ) {
			return;
		}
		$identity = brikpanel_nav_target_identity( $target );
		$index[ $target['kind'] . ':' . $target['key'] ][] = [
			'identity' => $identity,
			'args'     => array_diff_key( $target['args'], $identity ),
			'state'    => $state,
		];
	};
	$custom_url = static function ( $row ) {
		$meta = function_exists( 'brikpanel_nav_customizer_extract_meta' ) ? brikpanel_nav_customizer_extract_meta( $row ) : null;
		if ( ! is_array( $meta ) || empty( $meta['url'] ) ) {
			return null;
		}
		$split = brikpanel_nav_split_url( (string) $meta['url'] );
		return null === $split ? null : brikpanel_nav_request_target( $split[0], $split[1] );
	};

	foreach ( $dropped as $drop ) {
		$state  = ( 'new' === $drop['reason'] ) ? 'hidden_new' : 'hidden_rule';
		$parent = (string) $drop['parent'];
		$slug   = (string) $drop['slug'];
		if ( '' !== $parent ) {
			$add( brikpanel_nav_row_target( $slug, $parent ), $state );
			continue;
		}
		$add( brikpanel_nav_row_target( $slug, '' ), $state );
		if ( ! empty( $s[ $slug ] ) && is_array( $s[ $slug ] ) ) {
			foreach ( $s[ $slug ] as $child ) {
				// A hidden custom link is only a link: its target keeps its own rows.
				if ( is_array( $child ) && isset( $child[2] ) && is_scalar( $child[2] ) && ! ( function_exists( 'brikpanel_nav_customizer_extract_meta' ) && brikpanel_nav_customizer_extract_meta( $child ) ) ) {
					$add( brikpanel_nav_row_target( (string) $child[2], $slug ), $state );
				}
			}
		}
	}

	foreach ( $m as $row ) {
		if ( ! is_array( $row ) || ! isset( $row[2] ) || ! is_scalar( $row[2] ) ) {
			continue;
		}
		$slug = (string) $row[2];
		if ( isset( $row[4] ) && is_string( $row[4] ) && false !== strpos( $row[4], 'wp-menu-separator' ) ) {
			continue;
		}
		$meta = isset( $row[7] ) && is_array( $row[7] ) ? $row[7] : [];
		if ( ! empty( $meta['is_spacer'] ) || ! empty( $meta['is_heading'] ) ) {
			continue;
		}
		if ( ! empty( $meta['is_custom'] ) ) {
			$add( $custom_url( $row ), 'visible' );
			continue;
		}
		list( $listed, $header ) = brikpanel_nav_resolve_submenu_rows( isset( $s[ $slug ] ) ? $s[ $slug ] : [] );
		if ( ! empty( $listed ) ) {
			foreach ( $listed as $child ) {
				$target = ( function_exists( 'brikpanel_nav_customizer_extract_meta' ) && brikpanel_nav_customizer_extract_meta( $child ) )
					? $custom_url( $child )
					: brikpanel_nav_row_target( (string) $child[2], $slug );
				$add( $target, 'visible' );
			}
			continue;
		}
		$add( brikpanel_nav_row_target( $slug, '' ), 'visible' );
		if ( is_array( $header ) && isset( $header[2] ) ) {
			$target = ( function_exists( 'brikpanel_nav_customizer_extract_meta' ) && brikpanel_nav_customizer_extract_meta( $header ) )
				? $custom_url( $header )
				: brikpanel_nav_row_target( (string) $header[2], $slug );
			$add( $target, 'visible' );
		}
	}

	$last         = [ 'index' => $index ];
	$memo[ $key ] = $last;
	return $last;
}

/**
 * The rows that lead to a target most directly.
 *
 * A row matches when it is the same screen (brikpanel_nav_target_identity())
 * and, in the strict pass, every other argument the row names is in the link
 * with the same value. A WooCommerce app row also covers the pages below its
 * path. The most specific matches win: Settings' Payments tab is reached by the
 * Payments row (tab=checkout) rather than by the plain Settings row. The loose
 * pass, used only when the strict one finds nothing, compares the screen alone,
 * so a row that links to one tab of a page still speaks for its other tabs.
 *
 * @param array $target Target.
 * @param array $index  brikpanel_nav_page_rows()['index'].
 * @param bool  $loose  Compare the screen only.
 * @return array<int,array> Best rows (empty when none match).
 */
function brikpanel_nav_best_rows( array $target, array $index, $loose ) {
	$bucket = isset( $index[ $target['kind'] . ':' . $target['key'] ] ) ? $index[ $target['kind'] . ':' . $target['key'] ] : [];
	if ( empty( $bucket ) ) {
		return [];
	}
	$want      = brikpanel_nav_target_identity( $target );
	$best      = [];
	$best_spec = -1;
	foreach ( $bucket as $row ) {
		if ( 'page' === $target['kind'] ) {
			$row_path  = $row['identity']['path'];
			$want_path = $want['path'];
			if ( '' === $row_path ) {
				if ( '' !== $want_path ) {
					continue;
				}
				$spec = 0;
			} elseif ( $want_path === $row_path || 0 === strpos( $want_path, $row_path . '/' ) ) {
				$spec = substr_count( $row_path, '/' );
			} else {
				continue;
			}
		} else {
			if ( $row['identity'] !== $want ) {
				continue;
			}
			$spec = 0;
		}
		if ( ! $loose ) {
			foreach ( $row['args'] as $name => $value ) {
				if ( ! isset( $target['args'][ $name ] ) || $target['args'][ $name ] !== $value ) {
					continue 2;
				}
			}
			$spec += count( $row['args'] );
		}
		if ( $spec > $best_spec ) {
			$best      = [ $row ];
			$best_spec = $spec;
		} elseif ( $spec === $best_spec ) {
			$best[] = $row;
		}
	}
	return $best;
}

/**
 * The targets a request is judged by, in order: the request itself, then the
 * screens it belongs to. The first group with a matching row decides. Within a
 * group every match counts, and one visible row keeps the page open.
 *
 * Screens that open one object answer to their list and their "add new" row
 * together. BrikPanel's own screens answer to the WordPress screens they
 * replace (the sidebar links the WordPress ones). HPOS and the posts table
 * list orders on different screens, so either one answers for the other.
 *
 * @param string $file Admin file of the request.
 * @param array  $args Query arguments.
 * @return array<int,array<int,array>> Groups of targets.
 */
function brikpanel_nav_request_groups( $file, array $args ) {
	$self   = brikpanel_nav_request_target( $file, $args );
	$args   = brikpanel_nav_clean_args( $args );
	$groups = [ [ $self ] ];

	$target = static function ( $file, array $args = [] ) {
		return brikpanel_nav_request_target( $file, $args );
	};
	$list   = static function ( $type ) use ( $target ) {
		return $target( 'edit.php', 'post' === $type ? [] : [ 'post_type' => $type ] );
	};
	$create = static function ( $type ) use ( $target ) {
		return $target( 'post-new.php', 'post' === $type ? [] : [ 'post_type' => $type ] );
	};
	$orders_screen = static function ( $type ) use ( $target ) {
		if ( 'shop_order' === $type ) {
			return $target( 'admin.php', [ 'page' => 'wc-orders' ] );
		}
		if ( 'shop_subscription' === $type ) {
			return $target( 'admin.php', [ 'page' => 'wc-orders--shop_subscription' ] );
		}
		return null;
	};
	$object = static function ( $type ) use ( $target, $list, $create, $orders_screen ) {
		if ( 'attachment' === $type ) {
			return [ $target( 'upload.php' ), $target( 'media-new.php' ) ];
		}
		$group  = [ $list( $type ), $create( $type ) ];
		$orders = $orders_screen( $type );
		if ( null !== $orders ) {
			$group[] = $orders;
		}
		return $group;
	};
	$post_type_of = static function ( $post_id ) {
		$type = $post_id > 0 ? get_post_type( $post_id ) : '';
		return 'product_variation' === $type ? 'product' : (string) $type;
	};

	if ( 'page' === $self['kind'] ) {
		$action = isset( $args['action'] ) ? $args['action'] : '';
		switch ( $self['key'] ) {
			case 'brikpanel-products':
				$groups[] = [ $list( 'product' ) ];
				break;
			case 'brikpanel-product-editor':
				if ( ! empty( $args['product_id'] ) ) {
					$groups[] = $object( 'product' );
				} else {
					$groups[] = [ $create( 'product' ) ];
					$groups[] = [ $list( 'product' ) ];
				}
				break;
			case 'brikpanel-coupons':
				if ( 'new' === $action ) {
					$groups[] = [ $create( 'shop_coupon' ) ];
					$groups[] = [ $list( 'shop_coupon' ) ];
				} elseif ( '' !== $action ) {
					$groups[] = $object( 'shop_coupon' );
				} else {
					$groups[] = [ $list( 'shop_coupon' ) ];
				}
				break;
			case 'brikpanel-merge-orders':
				$groups[] = [ $orders_screen( 'shop_order' ), $list( 'shop_order' ) ];
				break;
			case 'wc-orders':
				$groups[] = [ $list( 'shop_order' ), $create( 'shop_order' ) ];
				break;
			case 'wc-orders--shop_subscription':
				$groups[] = [ $list( 'shop_subscription' ), $create( 'shop_subscription' ) ];
				break;
		}
	} else {
		switch ( $self['key'] ) {
			case 'post.php':
				$type = $post_type_of( isset( $args['post'] ) ? absint( $args['post'] ) : 0 );
				if ( '' !== $type ) {
					$groups[] = $object( $type );
				}
				break;
			case 'post-new.php':
				$type     = ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) ? $args['post_type'] : 'post';
				$groups[] = [ $list( $type ) ];
				$orders   = $orders_screen( $type );
				if ( null !== $orders ) {
					$groups[] = [ $orders ];
				}
				break;
			case 'edit.php':
				$orders = $orders_screen( ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) ? $args['post_type'] : 'post' );
				if ( null !== $orders ) {
					$groups[] = [ $orders ];
				}
				break;
			case 'term.php':
			case 'edit-tags.php':
				$taxonomy = ( isset( $args['taxonomy'] ) && '' !== $args['taxonomy'] ) ? $args['taxonomy'] : 'post_tag';
				$type     = ( isset( $args['post_type'] ) && '' !== $args['post_type'] ) ? $args['post_type'] : brikpanel_nav_taxonomy_default_type( $taxonomy );
				if ( 'term.php' === $self['key'] ) {
					$groups[] = [ $target( 'edit-tags.php', [ 'taxonomy' => $taxonomy, 'post_type' => $type ] ) ];
				}
				if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
					$groups[] = [ $target( 'admin.php', [ 'page' => 'product_attributes' ] ) ];
				}
				$groups[] = [ $list( $type ) ];
				break;
			case 'comment.php':
				$comment = isset( $args['c'] ) ? get_comment( absint( $args['c'] ) ) : null;
				if ( $comment && 'product' === get_post_type( (int) $comment->comment_post_ID ) ) {
					$groups[] = [ $target( 'admin.php', [ 'page' => 'product-reviews' ] ) ];
				}
				$groups[] = [ $target( 'edit-comments.php' ) ];
				break;
			case 'user-edit.php':
				$groups[] = [ $target( 'users.php' ), $target( 'user-new.php' ) ];
				break;
			case 'user-new.php':
			case 'media-new.php':
				$groups[] = [ $target( 'user-new.php' === $self['key'] ? 'users.php' : 'upload.php' ) ];
				break;
		}
	}

	/**
	 * Filter the screens a request answers to when no sidebar row leads to it
	 * directly. Each group is a list of targets from
	 * brikpanel_nav_request_target(); the first group with a matching row
	 * decides, and one visible row in it keeps the page open.
	 *
	 * @param array  $groups Groups, the request itself first.
	 * @param string $file   Admin file of the request.
	 * @param array  $args   Query arguments.
	 */
	$groups = apply_filters( 'brikpanel_nav_page_aliases', $groups, $file, $args );
	return is_array( $groups ) ? $groups : [ [ $self ] ];
}

/**
 * What the current user's sidebar says about a screen: 'unknown' (no row leads
 * to it), 'visible', 'hidden_rule' or 'hidden_new'.
 *
 * @param string $file Admin file of the request.
 * @param array  $args Query arguments.
 * @return string
 */
function brikpanel_nav_page_state( $file, array $args ) {
	$rows = brikpanel_nav_page_rows();
	if ( empty( $rows['index'] ) ) {
		return 'unknown';
	}
	foreach ( brikpanel_nav_request_groups( $file, $args ) as $group ) {
		$matched = [];
		foreach ( (array) $group as $target ) {
			if ( ! is_array( $target ) || ! isset( $target['kind'], $target['key'], $target['args'] ) ) {
				continue;
			}
			$best = brikpanel_nav_best_rows( $target, $rows['index'], false );
			if ( empty( $best ) ) {
				$best = brikpanel_nav_best_rows( $target, $rows['index'], true );
			}
			foreach ( $best as $row ) {
				$matched[ $row['state'] ] = true;
			}
		}
		if ( empty( $matched ) ) {
			continue;
		}
		if ( isset( $matched['visible'] ) ) {
			return 'visible';
		}
		return ( [ 'hidden_rule' ] === array_keys( $matched ) ) ? 'hidden_rule' : 'hidden_new';
	}
	return 'unknown';
}

/**
 * Screens that never close: the dashboard (where login lands), the user's own
 * profile, the BrikPanel settings tab (whoever may change the rules can always
 * reach them) and the explanation pages themselves.
 *
 * @param string $file Admin file.
 * @param array  $args Query arguments.
 * @return bool
 */
function brikpanel_nav_page_exempt( $file, array $args ) {
	$page = isset( $args['page'] ) && is_scalar( $args['page'] ) ? (string) $args['page'] : '';
	if ( '' !== $page ) {
		$open = [ 'brikpanel-dashboard', BRIKPANEL_NAV_BLOCKED_PAGE ];
		if ( defined( 'BRIKPANEL_MODULE_OFF_PAGE' ) ) {
			$open[] = BRIKPANEL_MODULE_OFF_PAGE;
		}
		if ( in_array( $page, $open, true ) ) {
			return true;
		}
		return 'wc-settings' === $page && isset( $args['tab'] ) && 'brikpanel' === (string) $args['tab'];
	}
	if ( in_array( (string) $file, [ '', 'index.php', 'profile.php', 'admin-post.php', 'admin-ajax.php' ], true ) ) {
		return true;
	}
	if ( 'user-edit.php' === $file ) {
		$user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		return 0 === $user_id || get_current_user_id() === $user_id;
	}
	return false;
}

// =============================================================================
// PUBLIC ANSWERS
// =============================================================================

/**
 * Whether the current user's sidebar hides the page this link opens, by a rule
 * or by "Hide new menu items by default". The palette uses it to list what
 * the sidebar lists, with or without the block setting, administrators too.
 *
 * @param string $url Link (absolute, site-relative or admin-relative).
 * @return bool
 */
function brikpanel_nav_url_hidden_for_current_user( $url ) {
	try {
		$split = brikpanel_nav_split_url( $url );
		if ( null === $split ) {
			return false;
		}
		return in_array( brikpanel_nav_page_state( $split[0], $split[1] ), [ 'hidden_rule', 'hidden_new' ], true );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Whether a request is closed for the current user: some rule hides every
 * sidebar row that leads to it. Does not look at the setting or the user's
 * role; see brikpanel_nav_url_blocked_for_current_user().
 *
 * @param string $file Admin file.
 * @param array  $args Query arguments.
 * @return bool
 */
function brikpanel_nav_request_blocked( $file, array $args ) {
	if ( brikpanel_nav_page_exempt( $file, $args ) ) {
		return false;
	}
	return 'hidden_rule' === brikpanel_nav_page_state( $file, $args );
}

/**
 * Whether "Block pages hidden from the menu" closes this link for the current
 * user. Shortcuts use it to leave out links that would only lead to the
 * "not available" card.
 *
 * @param string $url Link.
 * @return bool
 */
function brikpanel_nav_url_blocked_for_current_user( $url ) {
	if ( ! brikpanel_nav_block_hidden_pages_enabled() || brikpanel_nav_user_never_blocked() ) {
		return false;
	}
	try {
		$split = brikpanel_nav_split_url( $url );
		return null !== $split && brikpanel_nav_request_blocked( $split[0], $split[1] );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * The screen of the current request. A plugin page is known by $plugin_page,
 * not by $pagenow: BrikPanel's product editor sets $pagenow to post.php so SEO
 * plugins load their boxes.
 *
 * @return array{0:string,1:array<string,string>} [ file, args ].
 */
function brikpanel_nav_current_request() {
	global $pagenow, $plugin_page;
	$args = [];
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
	foreach ( (array) $_GET as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$args[ (string) $key ] = (string) wp_unslash( $value );
		}
	}
	if ( is_string( $plugin_page ) && '' !== $plugin_page ) {
		$args['page'] = $plugin_page;
		return [ 'admin.php', $args ];
	}
	unset( $args['page'] );
	return [ ( is_string( $pagenow ) && '' !== $pagenow ) ? $pagenow : 'index.php', $args ];
}

// =============================================================================
// CLOSING THE PAGE
// =============================================================================

/**
 * Send a request for a closed page to the explanation card.
 *
 * Runs at admin_init priority 1: after WordPress has built and cleaned the menu
 * and before BrikPanel sends WordPress screens to its own (priority 10), so the
 * product list is judged before it becomes BrikPanel's product list. Anything
 * unexpected leaves the page open: a fatal error here would take wp-admin down
 * for everyone.
 *
 * @return void
 */
function brikpanel_nav_block_hidden_page_request() {
	global $pagenow;
	if ( wp_doing_ajax() || ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) || 'admin-post.php' === $pagenow ) {
		return;
	}
	if ( ! brikpanel_nav_block_hidden_pages_enabled() || brikpanel_nav_user_never_blocked() ) {
		return;
	}
	try {
		if ( ! brikpanel_nav_rules_active_for_current_user() ) {
			return;
		}
		brikpanel_nav_remember_blocked_sources();
		list( $file, $args ) = brikpanel_nav_current_request();
		if ( ! brikpanel_nav_request_blocked( $file, $args ) ) {
			return;
		}
	} catch ( \Throwable $e ) {
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=' . BRIKPANEL_NAV_BLOCKED_PAGE ) );
	exit;
}
add_action( 'admin_init', 'brikpanel_nav_block_hidden_page_request', 1 );

/**
 * Register the explanation page. It has no menu row and takes no parameters:
 * it only ever says that the page asked for is closed.
 *
 * @return void
 */
function brikpanel_nav_blocked_register_page() {
	$hook = add_submenu_page(
		'',
		__( 'Not available', 'brikpanel' ),
		'',
		'read',
		BRIKPANEL_NAV_BLOCKED_PAGE,
		'brikpanel_nav_blocked_render_page'
	);
	if ( ! $hook ) {
		return;
	}
	add_action(
		'load-' . $hook,
		static function () {
			// Nobody is blocked while the setting is off, and administrators never are.
			if ( ! brikpanel_nav_block_hidden_pages_enabled() || brikpanel_nav_user_never_blocked() ) {
				wp_safe_redirect( admin_url( 'index.php' ) );
				exit;
			}
			// Set before admin-header.php runs, or WordPress prints an empty title.
			$GLOBALS['title'] = __( 'Not available', 'brikpanel' );
		}
	);
}
add_action( 'admin_menu', 'brikpanel_nav_blocked_register_page', 30 );

/**
 * Render the explanation card.
 *
 * @return void
 */
function brikpanel_nav_blocked_render_page() {
	$actions = [
		[
			'label'   => __( 'Back to the dashboard', 'brikpanel' ),
			'url'     => admin_url( 'index.php' ),
			'primary' => true,
		],
	];
	if ( function_exists( 'brikpanel_user_can_open_settings' ) && brikpanel_user_can_open_settings() ) {
		$actions[] = [
			'label'   => __( 'Open Navigation settings', 'brikpanel' ),
			'url'     => admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=navigation' ),
			'primary' => false,
		];
	}
	brikpanel_render_notice_card( [
		'icon'    => 'lock',
		'title'   => __( 'This page is not available to you', 'brikpanel' ),
		'text'    => __( 'The store administrator has hidden this page from your menu, so it cannot be opened from a link either.', 'brikpanel' ),
		'actions' => $actions,
	] );
}

/**
 * WooCommerce's own screens (Home, Analytics, Customers, Marketing) are one
 * app that moves between pages without loading them, so admin_init never sees
 * a click from Home to a closed Analytics report. On those screens, when a
 * rule closes one of their paths, a small script watches the app's page
 * changes and sends a closed path to the explanation card, with the same rule
 * as the server: the most specific row wins, "" is WooCommerce Home only.
 *
 * @return void
 */
function brikpanel_nav_wc_admin_route_guard() {
	global $plugin_page;
	if ( 'wc-admin' !== $plugin_page || ! function_exists( 'wp_print_inline_script_tag' ) ) {
		return;
	}
	if ( ! brikpanel_nav_block_hidden_pages_enabled() || brikpanel_nav_user_never_blocked() ) {
		return;
	}
	try {
		$rows  = brikpanel_nav_page_rows();
		$paths = [];
		$any   = false;
		foreach ( isset( $rows['index']['page:wc-admin'] ) ? $rows['index']['page:wc-admin'] : [] as $row ) {
			$hidden  = 'hidden_rule' === $row['state'];
			$any     = $any || $hidden;
			$paths[] = [ 'p' => $row['identity']['path'], 'h' => $hidden ? 1 : 0 ];
		}
		if ( ! $any ) {
			return;
		}
		$data = wp_json_encode( [
			'to'   => admin_url( 'admin.php?page=' . BRIKPANEL_NAV_BLOCKED_PAGE ),
			'rows' => $paths,
		] );
	} catch ( \Throwable $e ) {
		return;
	}
	$script = '(function(cfg){'
		. 'function norm(p){p="/"+String(p||"").replace(/^\/+|\/+$/g,"");return p==="/"?"":p;}'
		. 'function closed(path){var req=norm(path),best=-1,hidden=true;'
		. 'cfg.rows.forEach(function(r){var rp=norm(r.p);'
		. 'var ok=rp===""?req==="":(req===rp||req.indexOf(rp+"/")===0);if(!ok){return;}'
		. 'var spec=rp===""?0:rp.split("/").length-1;'
		. 'if(spec>best){best=spec;hidden=!!r.h;}else if(spec===best){hidden=hidden&&!!r.h;}});'
		. 'return best>-1&&hidden;}'
		. 'function check(){try{var q=new URLSearchParams(window.location.search);'
		. 'if(q.get("page")==="wc-admin"&&closed(q.get("path"))){window.location.replace(cfg.to);}}catch(e){}}'
		. '["pushState","replaceState"].forEach(function(m){var o=window.history[m];if(typeof o!=="function"){return;}'
		. 'window.history[m]=function(){var out=o.apply(this,arguments);check();return out;};});'
		. 'window.addEventListener("popstate",check);'
		. '})(' . $data . ');';
	wp_print_inline_script_tag( $script, [ 'id' => 'brikpanel-nav-route-guard' ] );
}
add_action( 'admin_head', 'brikpanel_nav_wc_admin_route_guard', 1 );

// =============================================================================
// PALETTE SOURCES
// =============================================================================

/**
 * The user meta key that holds the palette sources closed for this user on
 * the current site.
 *
 * @return string
 */
function brikpanel_nav_blocked_sources_meta_key() {
	return BRIKPANEL_NAV_BLOCKED_SOURCES_META . get_current_blog_id();
}

/**
 * Note which palette sources lead to closed screens for the current user.
 *
 * The palette searches over admin-ajax, where WordPress builds no menu, so the
 * answer is worked out on full page loads (from the page guard) and kept in
 * user meta, written only when it changes. Orders, products and customers
 * each open their own screens (an order, the product editor, a user profile).
 *
 * @return void
 */
function brikpanel_nav_remember_blocked_sources() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	$screens = [
		'orders'    => function_exists( 'brikpanel_wc_orders_list_url' ) ? brikpanel_wc_orders_list_url() : admin_url( 'edit.php?post_type=shop_order' ),
		'products'  => admin_url( 'admin.php?page=brikpanel-product-editor&product_id=1' ),
		'customers' => admin_url( 'users.php' ),
	];
	$closed = [];
	foreach ( $screens as $source => $url ) {
		$split = brikpanel_nav_split_url( $url );
		if ( null !== $split && brikpanel_nav_request_blocked( $split[0], $split[1] ) ) {
			$closed[] = $source;
		}
	}
	$value  = implode( ',', $closed );
	$key    = brikpanel_nav_blocked_sources_meta_key();
	$stored = (string) get_user_meta( $user_id, $key, true );
	if ( $stored === $value ) {
		return;
	}
	if ( '' === $value ) {
		delete_user_meta( $user_id, $key );
	} else {
		update_user_meta( $user_id, $key, $value );
	}
}

/**
 * Whether a palette source leads to screens closed for the current user.
 * Works in admin-ajax: it reads what the last page load noted, and checks the
 * setting and the user's role again, so turning the setting off opens the
 * sources at once.
 *
 * @param string $source Source id ('orders', 'products', 'customers').
 * @return bool
 */
function brikpanel_nav_search_source_blocked( $source ) {
	if ( ! brikpanel_nav_block_hidden_pages_enabled() || brikpanel_nav_user_never_blocked() ) {
		return false;
	}
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}
	$stored = (string) get_user_meta( $user_id, brikpanel_nav_blocked_sources_meta_key(), true );
	return '' !== $stored && in_array( (string) $source, explode( ',', $stored ), true );
}
