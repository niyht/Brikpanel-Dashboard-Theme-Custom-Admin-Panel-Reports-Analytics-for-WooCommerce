<?php
/**
 * BrikPanel — Sidebar Navigation Customizer
 *
 * Lets administrators reorder, hide, and add new sidebar items from the
 * BrikPanel WC settings tab. Configuration is stored as JSON in a single
 * option (`brikpanel_nav_config`) and applied at render time inside
 * `brikpanel_get_navigation_items()`.
 *
 * Sections:
 *   - "store"           — top group (above the "Site management" heading).
 *   - "site_management" — group below the heading.
 *
 * Items are either:
 *   - { type: "system", slug, section, hidden }
 *       References a real WP/Woo top-level menu item by slug.
 *   - { type: "custom", id, label, url, icon, section, hidden, new_tab }
 *       A user-defined link (internal or external).
 *
 * Items not present in the saved config are auto-appended at the end of the
 * "store" group on render so newly-installed plugins immediately show up.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// CONSTANTS
// =============================================================================

if ( ! defined( 'BRIKPANEL_NAV_CONFIG_OPTION' ) ) {
	define( 'BRIKPANEL_NAV_CONFIG_OPTION', 'brikpanel_nav_config' );
}

// =============================================================================
// CONFIG: GET / SAVE
// =============================================================================

/**
 * Return the saved navigation config as an array.
 *
 * Schema: [ 'version' => 1, 'items' => [ ...item objects ] ].
 * Returns the empty default when no config has been saved yet — empty config
 * means "use the natural order from $menu, everything visible, no custom links".
 *
 * Memoised. Six call sites read this during a single admin render (sidebar
 * order, spacing, Site Management label, the customizer UI itself, and the
 * synthetic-slug redirect), and each one previously cost a get_option() plus a
 * json_decode() of the stored blob.
 *
 * @param bool $refresh Drop the memo and re-read. Callers that have just
 *                      written the option must pass true, or the rest of the
 *                      request keeps rendering from the pre-save value.
 * @return array
 */
function brikpanel_nav_config_get( $refresh = false ) {
	static $config = null;

	if ( $refresh ) {
		$config = null;
	}
	if ( null !== $config ) {
		return $config;
	}

	$raw = get_option( BRIKPANEL_NAV_CONFIG_OPTION, '' );
	if ( empty( $raw ) ) {
		return $config = [ 'version' => 1, 'items' => [] ];
	}
	$decoded = is_array( $raw ) ? $raw : json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return $config = [ 'version' => 1, 'items' => [] ];
	}
	$decoded['items'] = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : [];
	return $config = $decoded;
}

/**
 * Custom links are injected into the admin menu with a synthetic slug of the
 * form `brikpanel_custom__<id>`; the item's real destination lives in the row's
 * metadata (index 7) that only BrikPanel's own sidebar renderer reads. That's
 * fine for the BrikPanel sidebar — it emits the real <a href> directly.
 *
 * But some hosts render their OWN admin navigation from the raw $menu/$submenu
 * slugs instead of BrikPanel's markup (notably WordPress.com / Atomic's
 * nav-unification sidebar, and the admin bar's "shortcuts"). Those build a link
 * to `wp-admin/brikpanel_custom__<id>` (or `admin.php?page=brikpanel_custom__<id>`),
 * which is not a real screen — clicking it lands the user on a 404 / "you are not
 * allowed to access this page" error instead of the URL they configured.
 *
 * This guard closes that gap regardless of which navigation rendered the link:
 * if the current request targets a synthetic custom-link slug, we look the id up
 * in the saved config and redirect to the real destination. Spacers (which have
 * no destination) fall back to the admin dashboard rather than erroring.
 *
 * Hooked on `admin_page_access_denied`, which WordPress fires from menu.php the
 * moment it decides the requested page is not one the user can access — exactly
 * when someone lands on a synthetic slug — and right before it calls wp_die().
 * No output has started yet at that point, so the redirect always succeeds.
 */
function brikpanel_nav_redirect_synthetic_slug() {
	// Only the `admin.php?page=` form is interceptable in PHP; the bare
	// `wp-admin/<slug>` path form is resolved by the web server before WordPress
	// boots. WordPress.com routes both through admin.php, so this covers the
	// real-world case.
	$page = isset( $_GET['page'] ) ? (string) wp_unslash( $_GET['page'] ) : '';
	if ( $page === '' ) {
		return;
	}
	$is_custom = strpos( $page, 'brikpanel_custom__' ) === 0;
	$is_spacer = strpos( $page, 'brikpanel_spacer__' ) === 0;
	if ( ! $is_custom && ! $is_spacer ) {
		return;
	}

	// Spacers are decorative; there is nothing to open. Send the user home.
	if ( $is_spacer ) {
		wp_safe_redirect( admin_url() );
		exit;
	}

	$id     = substr( $page, strlen( 'brikpanel_custom__' ) );
	$config = brikpanel_nav_config_get();
	foreach ( $config['items'] as $cfg ) {
		if ( empty( $cfg['type'] ) || $cfg['type'] !== 'custom' ) {
			continue;
		}
		if ( ! isset( $cfg['id'] ) || (string) $cfg['id'] !== $id ) {
			continue;
		}
		$url = isset( $cfg['url'] ) ? trim( (string) $cfg['url'] ) : '';
		if ( $url === '' ) {
			break;
		}
		// Full external URL → redirect straight there (custom links may point
		// off-site by design). Otherwise treat it as an admin-relative path.
		if ( preg_match( '#^https?://#i', $url ) ) {
			wp_redirect( esc_url_raw( $url ) );
		} else {
			wp_safe_redirect( admin_url( ltrim( $url, '/' ) ) );
		}
		exit;
	}

	// No matching custom link (stale link after the item was deleted): fall back
	// to the dashboard instead of a hard error.
	wp_safe_redirect( admin_url() );
	exit;
}
add_action( 'admin_page_access_denied', 'brikpanel_nav_redirect_synthetic_slug' );

// =============================================================================
// PER-AUDIENCE VISIBILITY (role / admin gating)
// =============================================================================
//
// Each item and submenu carries an optional audience rule on top of the binary
// "hidden" flag:
//   - hidden:true          → hidden from everyone (the original behaviour).
//   - audience 'all'        → visible to everyone (default).
//   - audience 'admins'     → visible to administrators only (hidden from every
//                             non-administrator).
//   - audience 'roles'      → hidden from any user holding one of `hide_roles`.
//
// Resolution is per current user, evaluated at sidebar render time. The settings
// page itself stays administrator-gated, so an administrator can always reach it
// to change a rule back even after hiding an item from their own role.

/**
 * Whether the current user counts as an administrator for audience rules.
 * Uses `manage_options`, which also covers multisite super admins.
 *
 * @return bool
 */
function brikpanel_nav_current_user_is_admin() {
	return current_user_can( 'manage_options' );
}

/**
 * Current user's role slugs (lower-cased strings). Empty for logged-out.
 *
 * @return string[]
 */
function brikpanel_nav_current_user_roles() {
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		return [];
	}
	$user = wp_get_current_user();
	return ( $user instanceof WP_User ) ? array_map( 'strval', (array) $user->roles ) : [];
}

/**
 * Validate a list of role slugs against the registered roles. Caps the list and
 * drops duplicates / unknown slugs.
 *
 * @param mixed $roles Raw role list.
 * @return string[]
 */
function brikpanel_nav_sanitize_roles( $roles ) {
	if ( ! is_array( $roles ) ) {
		return [];
	}
	$valid = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->roles ) : [];
	$out   = [];
	foreach ( $roles as $role ) {
		$role = sanitize_key( (string) $role );
		if ( $role !== '' && in_array( $role, $valid, true ) && ! in_array( $role, $out, true ) ) {
			$out[] = $role;
		}
		if ( count( $out ) >= 30 ) {
			break;
		}
	}
	return $out;
}

/**
 * Whether a config entry (item or submenu) must be hidden from the current
 * user, taking both the binary `hidden` flag and the audience rule into account.
 *
 * @param array $cfg Item/submenu config row.
 * @return bool
 */
function brikpanel_nav_cfg_hidden_for_current_user( $cfg ) {
	if ( ! empty( $cfg['hidden'] ) ) {
		return true;
	}
	$audience = isset( $cfg['audience'] ) ? (string) $cfg['audience'] : 'all';

	if ( $audience === 'admins' ) {
		// Visible to administrators only.
		return ! brikpanel_nav_current_user_is_admin();
	}

	if ( $audience === 'roles' ) {
		$hide_roles = isset( $cfg['hide_roles'] ) && is_array( $cfg['hide_roles'] )
			? array_map( 'strval', $cfg['hide_roles'] )
			: [];
		if ( empty( $hide_roles ) ) {
			return false;
		}
		return (bool) array_intersect( brikpanel_nav_current_user_roles(), $hide_roles );
	}

	return false;
}

/**
 * Normalize the audience fields of a raw config row into a clean target array.
 * Writes `audience` (+ `hide_roles` when applicable) onto $row only when the
 * rule is non-default, keeping saved configs small and backward compatible.
 *
 * @param array $raw Raw decoded item/submenu.
 * @param array $row Target row to enrich (passed by reference).
 */
function brikpanel_nav_apply_audience_to_row( $raw, &$row ) {
	$audience = isset( $raw['audience'] ) ? sanitize_key( (string) $raw['audience'] ) : 'all';
	if ( ! in_array( $audience, [ 'all', 'admins', 'roles' ], true ) ) {
		$audience = 'all';
	}
	if ( $audience === 'all' ) {
		return;
	}
	if ( $audience === 'roles' ) {
		$roles = brikpanel_nav_sanitize_roles( isset( $raw['hide_roles'] ) ? $raw['hide_roles'] : [] );
		if ( empty( $roles ) ) {
			// "Specific roles" with no roles chosen is a no-op — drop it.
			return;
		}
		$row['audience']   = 'roles';
		$row['hide_roles'] = $roles;
		return;
	}
	$row['audience'] = 'admins';
}

/**
 * Sanitize and persist the navigation config.
 *
 * Each item is normalized to the expected shape; unknown keys are dropped.
 * Custom item URLs are validated (esc_url_raw) and labels stripped of HTML.
 *
 * @param array $config Raw decoded JSON.
 */
function brikpanel_nav_config_save( $config ) {
	if ( ! is_array( $config ) ) {
		$config = [];
	}

	$items   = isset( $config['items'] ) && is_array( $config['items'] ) ? $config['items'] : [];
	$cleaned = [];

	$valid_sections = [ 'store', 'site_management', 'more' ];
	$icon_options   = brikpanel_nav_customizer_icon_options();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$type    = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : '';
		$section = isset( $item['section'] ) && in_array( $item['section'], $valid_sections, true ) ? $item['section'] : 'store';
		$hidden  = ! empty( $item['hidden'] );

		if ( $type === 'spacer' ) {
			// Purely visual gap / divider the user drops between menu rows. It
			// carries no link, icon or audience rule — just an id (for stable
			// ordering) and a variant ('space' = blank gap, 'line' = divider).
			$id = isset( $item['id'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $item['id'] ) : '';
			if ( $id === '' ) {
				$id = 's' . wp_generate_password( 10, false, false );
			}
			$variant = isset( $item['variant'] ) && in_array( $item['variant'], [ 'space', 'line' ], true ) ? $item['variant'] : 'space';
			$cleaned[] = [
				'type'    => 'spacer',
				'id'      => $id,
				'section' => $section,
				'variant' => $variant,
			];
			continue;
		}

		if ( $type === 'system' ) {
			$slug = isset( $item['slug'] ) ? wp_unslash( $item['slug'] ) : '';
			$slug = is_string( $slug ) ? trim( $slug ) : '';
			if ( $slug === '' ) {
				continue;
			}
			// Slugs may contain query strings (e.g. "edit.php?post_type=product")
			// and ampersands (e.g. "wc-admin&path=/analytics/overview"). Strip
			// only HTML-unsafe bytes; preserve the rest verbatim so $menu lookup
			// matches the real slug.
			$slug = wp_strip_all_tags( $slug );

			$row = [
				'type'    => 'system',
				'slug'    => $slug,
				'section' => $section,
				'hidden'  => $hidden,
			];

			// Optional audience rule (visible to admins only / hidden from roles).
			brikpanel_nav_apply_audience_to_row( $item, $row );

			// Optional icon override — must be one of the picker's known slugs.
			if ( isset( $item['icon_override'] ) ) {
				$icon_override = sanitize_key( (string) $item['icon_override'] );
				if ( $icon_override !== '' && isset( $icon_options[ $icon_override ] ) ) {
					$row['icon_override'] = $icon_override;
				}
			}

			// Optional custom SVG icon — wins over the built-in icon_override
			// when present. Sanitised down to a safe base64 data URI.
			if ( isset( $item['icon_svg'] ) ) {
				$icon_svg = brikpanel_nav_sanitize_icon_svg( $item['icon_svg'] );
				if ( $icon_svg !== '' ) {
					$row['icon_svg'] = $icon_svg;
				}
			}

			// Optional label override — renames a system item in the sidebar
			// without disturbing its slug or icon.
			if ( isset( $item['label_override'] ) ) {
				$label_override = wp_strip_all_tags( wp_unslash( (string) $item['label_override'] ) );
				$label_override = trim( preg_replace( '/\s+/', ' ', $label_override ) );
				if ( $label_override !== '' ) {
					$row['label_override'] = function_exists( 'mb_substr' )
						? mb_substr( $label_override, 0, 120 )
						: substr( $label_override, 0, 120 );
				}
			}

			// Optional submenu visibility config. Each entry is { slug, hidden }.
			// Hard-cap at 50 to defend against pathological input sizes.
			if ( isset( $item['submenus'] ) && is_array( $item['submenus'] ) ) {
				$subs = [];
				$count = 0;
				foreach ( $item['submenus'] as $sub ) {
					if ( ! is_array( $sub ) || $count >= 50 ) {
						continue;
					}
					$sub_slug = isset( $sub['slug'] ) ? wp_unslash( $sub['slug'] ) : '';
					$sub_slug = is_string( $sub_slug ) ? trim( wp_strip_all_tags( $sub_slug ) ) : '';
					if ( $sub_slug === '' ) {
						continue;
					}
					$sub_row = [
						'slug'   => $sub_slug,
						'hidden' => ! empty( $sub['hidden'] ),
					];
					brikpanel_nav_apply_audience_to_row( $sub, $sub_row );
					$subs[] = $sub_row;
					$count++;
				}
				if ( ! empty( $subs ) ) {
					$row['submenus'] = $subs;
				}
			}

			$cleaned[] = $row;
			continue;
		}

		if ( $type === 'custom' ) {
			$id      = isset( $item['id'] ) ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $item['id'] ) : '';
			$label   = isset( $item['label'] ) ? wp_strip_all_tags( wp_unslash( $item['label'] ) ) : '';
			$url     = isset( $item['url'] ) ? esc_url_raw( wp_unslash( $item['url'] ), [ 'http', 'https', 'mailto' ] ) : '';
			$icon    = isset( $item['icon'] ) ? sanitize_key( $item['icon'] ) : 'default';
			$new_tab = ! empty( $item['new_tab'] );

			if ( $id === '' ) {
				$id = 'c' . wp_generate_password( 10, false, false );
			}
			if ( $label === '' || $url === '' ) {
				continue;
			}
			$custom_row = [
				'type'    => 'custom',
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => $icon,
				'section' => $section,
				'hidden'  => $hidden,
				'new_tab' => $new_tab,
			];
			// Optional custom SVG icon — wins over the built-in `icon` slug.
			if ( isset( $item['icon_svg'] ) ) {
				$icon_svg = brikpanel_nav_sanitize_icon_svg( $item['icon_svg'] );
				if ( $icon_svg !== '' ) {
					$custom_row['icon_svg'] = $icon_svg;
				}
			}
			brikpanel_nav_apply_audience_to_row( $item, $custom_row );
			$cleaned[] = $custom_row;
			continue;
		}
	}

	// Global item-spacing preference — controls the vertical gap between every
	// sidebar row. 'comfortable' matches the historical default so existing
	// installs render unchanged.
	$spacing = isset( $config['spacing'] ) ? (string) $config['spacing'] : 'comfortable';
	$spacing = in_array( $spacing, [ 'compact', 'comfortable', 'spacious' ], true ) ? $spacing : 'comfortable';

	// Optional custom label for the "Site management" heading. Empty string
	// means "use the default translated label", so existing installs are
	// unaffected. Stripped of markup and length-capped to stay a heading.
	$sitemgmt_label = isset( $config['sitemgmt_label'] ) ? wp_strip_all_tags( (string) $config['sitemgmt_label'] ) : '';
	$sitemgmt_label = trim( preg_replace( '/\s+/', ' ', $sitemgmt_label ) );
	if ( $sitemgmt_label !== '' ) {
		$sitemgmt_label = function_exists( 'mb_substr' )
			? mb_substr( $sitemgmt_label, 0, 60 )
			: substr( $sitemgmt_label, 0, 60 );
	}

	$payload = [
		'version'        => 1,
		'items'          => $cleaned,
		'spacing'        => $spacing,
		'sitemgmt_label' => $sitemgmt_label,
	];
	update_option( BRIKPANEL_NAV_CONFIG_OPTION, wp_json_encode( $payload ), false );

	// Drop the memo in brikpanel_nav_config_get() so the rest of this request
	// renders the sidebar from the value just saved rather than the pre-save
	// one. Without this the customizer would show stale ordering immediately
	// after a save.
	brikpanel_nav_config_get( true );
}

/**
 * Return the sanitized global item-spacing preference
 * ('compact' | 'comfortable' | 'spacious'). Used by the live renderer to add
 * the matching CSS class to the sidebar.
 *
 * @return string
 */
function brikpanel_nav_config_spacing() {
	$config  = brikpanel_nav_config_get();
	$spacing = isset( $config['spacing'] ) ? (string) $config['spacing'] : 'comfortable';
	return in_array( $spacing, [ 'compact', 'comfortable', 'spacious' ], true ) ? $spacing : 'comfortable';
}

/**
 * Return the label to render for the "Site management" heading. When the
 * administrator has set a custom label in the navigation customizer it wins;
 * otherwise the default translated string is used so untouched installs are
 * unchanged.
 *
 * @return string Ready-to-print heading text (not yet escaped).
 */
function brikpanel_nav_config_sitemgmt_label() {
	$config = brikpanel_nav_config_get();
	$label  = isset( $config['sitemgmt_label'] ) ? trim( (string) $config['sitemgmt_label'] ) : '';
	if ( $label !== '' ) {
		return $label;
	}
	return __( 'Site management', 'brikpanel' );
}

/**
 * Whether newly-detected menu items (from freshly installed plugins, not yet
 * placed in the saved config) should be hidden by default in the live sidebar
 * until an administrator reviews them in the editor. Off by default, so the
 * historical behaviour — new items appear immediately — is unchanged.
 *
 * @return bool
 */
function brikpanel_nav_hide_new_items_enabled() {
	return get_option( 'brikpanel_nav_hide_new_items', 'no' ) === 'yes';
}

// =============================================================================
// AVAILABLE ICON LIST (for custom-link picker)
// =============================================================================

/**
 * Available icon options for custom links. Maps icon slug → human label.
 * Slugs match the SVG filenames in front-end/navigation/icons/.
 *
 * @return array<string,string>
 */
function brikpanel_nav_customizer_icon_options() {
	return [
		'default'       => __( 'Default link', 'brikpanel' ),
		'home'          => __( 'Home', 'brikpanel' ),
		'dashboard'     => __( 'Dashboard', 'brikpanel' ),
		'products'      => __( 'Products', 'brikpanel' ),
		'orders'        => __( 'Orders', 'brikpanel' ),
		'customers'     => __( 'Customers', 'brikpanel' ),
		'analytics'     => __( 'Analytics', 'brikpanel' ),
		'marketing'     => __( 'Marketing', 'brikpanel' ),
		'payments'      => __( 'Payments', 'brikpanel' ),
		'credit-card'   => __( 'Credit card', 'brikpanel' ),
		'invoice'       => __( 'Invoice', 'brikpanel' ),
		'subscriptions' => __( 'Subscriptions', 'brikpanel' ),
		'settings'      => __( 'Settings', 'brikpanel' ),
		'tools'         => __( 'Tools', 'brikpanel' ),
		'plugins'       => __( 'Plugins', 'brikpanel' ),
		'users'         => __( 'Users', 'brikpanel' ),
		'comments'      => __( 'Comments', 'brikpanel' ),
		'media'         => __( 'Media', 'brikpanel' ),
		'pages'         => __( 'Pages', 'brikpanel' ),
		'posts'         => __( 'Posts', 'brikpanel' ),
		'appearance'    => __( 'Appearance', 'brikpanel' ),
		'form'          => __( 'Form', 'brikpanel' ),
		'scissors'      => __( 'Snippets', 'brikpanel' ),
		'more'          => __( 'More', 'brikpanel' ),
	];
}

/**
 * Sanitize a user-supplied custom icon into a safe
 * `data:image/svg+xml;base64,…` URI, or return '' when it can't be made safe.
 *
 * Accepts three input shapes so users can paste whatever Icons8 / SVG Repo /
 * similar sites hand them:
 *   - Raw SVG markup ("<svg …>…</svg>").
 *   - A base64 data URI ("data:image/svg+xml;base64,….").
 *   - A URL-encoded data URI ("data:image/svg+xml,%3Csvg…").
 *
 * The SVG is stripped of scripts, event handlers, external references and other
 * active content before being re-encoded. Final rendering always happens inside
 * an <img> tag, where browsers never execute SVG scripts — the sanitising here
 * is defence in depth and keeps the stored option clean.
 *
 * @param mixed $raw Raw user input.
 * @return string Base64 data URI, or '' when invalid.
 */
function brikpanel_nav_sanitize_icon_svg( $raw ) {
	if ( ! is_string( $raw ) ) {
		return '';
	}
	$raw = trim( wp_unslash( $raw ) );
	if ( $raw === '' ) {
		return '';
	}
	// Hard cap on raw input size to avoid pathological option bloat.
	if ( strlen( $raw ) > 100000 ) {
		return '';
	}

	// Decode the three accepted input shapes down to raw SVG markup.
	$svg = '';
	if ( stripos( $raw, 'data:image/svg+xml' ) === 0 ) {
		$comma = strpos( $raw, ',' );
		if ( $comma === false ) {
			return '';
		}
		$meta    = substr( $raw, 0, $comma );
		$payload = substr( $raw, $comma + 1 );
		if ( stripos( $meta, ';base64' ) !== false ) {
			$decoded = base64_decode( $payload, true );
			$svg     = is_string( $decoded ) ? $decoded : '';
		} else {
			$svg = rawurldecode( $payload );
		}
	} elseif ( stripos( $raw, '<svg' ) !== false ) {
		$svg = $raw;
	} else {
		return '';
	}

	$svg = trim( $svg );
	if ( $svg === '' || stripos( $svg, '<svg' ) === false || stripos( $svg, '</svg>' ) === false ) {
		return '';
	}

	// Keep only the <svg> root onward — drops XML prolog / doctype / comments.
	$start = stripos( $svg, '<svg' );
	$svg   = substr( $svg, $start );
	$end   = strripos( $svg, '</svg>' );
	if ( $end === false ) {
		return '';
	}
	$svg = substr( $svg, 0, $end + 6 );

	// Strip active content: scripts, foreignObject, CDATA, event handlers and
	// javascript: references.
	$svg = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg );
	$svg = preg_replace( '#<foreignObject\b[^>]*>.*?</foreignObject>#is', '', $svg );
	$svg = preg_replace( '#<!\[CDATA\[.*?\]\]>#is', '', $svg );
	$svg = preg_replace( '#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg );
	$svg = preg_replace( '#(href|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\')#i', '', $svg );

	// Reject anything that still smells executable.
	if ( preg_match( '#javascript:|<script|<iframe|<embed|<object#i', $svg ) ) {
		return '';
	}

	// Cap final cleaned size.
	if ( strlen( $svg ) > 50000 ) {
		return '';
	}

	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

// =============================================================================
// AVAILABLE MENU ITEMS (collected from $menu at settings page render time)
// =============================================================================

/**
 * Snapshot the current admin menu and return a list of human-readable
 * top-level items. Used to populate the customizer with anything currently
 * registered (so newly-installed plugins surface automatically).
 *
 * Mirrors the WC submenu→top-level promotion AND the default `move_item_after`
 * reorderings the rendered sidebar applies, so the customizer displays items
 * in the same order users see them in the sidebar. Without this, saving an
 * unmodified default config would silently re-order the sidebar and items
 * like "Settings" and "More" (created at render time) would never appear in
 * the customizer for users to manage.
 *
 * Each item also carries its child submenu list (slug + title) so the
 * customizer UI can offer per-submenu visibility toggles.
 *
 * @return array<int,array{slug:string,title:string,submenus:array<int,array{slug:string,title:string}>}>
 */
function brikpanel_nav_customizer_collect_menu_items() {
	global $menu, $submenu;
	if ( ! is_array( $menu ) ) {
		return [];
	}

	// Work on copies so we don't disturb the live globals (the navigation
	// renderer applies its own promotion/reordering later in the request).
	$menu_snapshot    = $menu;
	$submenu_snapshot = is_array( $submenu ) ? $submenu : [];

	// Mirror the live sidebar's WooCommerce relocation on the snapshot copies via
	// the SAME shared function the renderer uses, so the customizer lists every
	// item exactly where the sidebar renders it (WooCommerce keeps Orders /
	// Customers, everything else lands under "More"). The renderer skips this when
	// Admin Menu Editor owns the menu or the modern navigation is switched off, so
	// honour the same conditions here.
	$relocate = ! ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'admin-menu-editor/menu-editor.php' ) )
		&& get_option( 'brikpanel_modern_navigation', 'yes' ) !== 'no';
	if ( $relocate && function_exists( 'brikpanel_nav_relocate_wc_submenus' ) ) {
		brikpanel_nav_relocate_wc_submenus( $menu_snapshot, $submenu_snapshot );
	}
	brikpanel_nav_customizer_apply_default_reorder( $menu_snapshot );

	$normalize_title = static function ( $raw ) {
		$raw = (string) $raw;
		// WP core often packs notification badges into menu titles as nested
		// <span> elements (e.g. comments "0 in moderation", plugins "2"). Strip
		// the <span>...</span> blocks before stripping remaining tags so the
		// numeric content inside them goes too.
		$raw = preg_replace( '#<span[^>]*>.*?</span>#is', '', $raw );
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
	};

	$items = [];
	foreach ( $menu_snapshot as $item ) {
		if ( empty( $item ) || ! isset( $item[2] ) ) {
			continue;
		}
		$slug = (string) $item[2];
		// Skip native separators — we render our own group structure and the
		// raw "wp-menu-separator" slugs are useless to the user.
		if ( isset( $item[4] ) && is_string( $item[4] ) && strpos( $item[4], 'wp-menu-separator' ) !== false ) {
			continue;
		}
		$title = $normalize_title( isset( $item[0] ) ? $item[0] : $slug );

		$submenus = [];
		if ( isset( $submenu_snapshot[ $slug ] ) && is_array( $submenu_snapshot[ $slug ] ) ) {
			foreach ( $submenu_snapshot[ $slug ] as $sub ) {
				if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
					continue;
				}
				$sub_slug = (string) $sub[2];
				if ( $sub_slug === '' ) {
					continue;
				}
				// Cap submenus at 50 per parent (matches the sanitize cap).
				if ( count( $submenus ) >= 50 ) {
					break;
				}
				$sub_title = $normalize_title( isset( $sub[0] ) ? $sub[0] : $sub_slug );
				$submenus[] = [
					'slug'  => $sub_slug,
					'title' => $sub_title !== '' ? $sub_title : $sub_slug,
				];
			}
		}

		$items[] = [
			'slug'     => $slug,
			'title'    => $title !== '' ? $title : $slug,
			'submenus' => $submenus,
		];
	}
	return $items;
}

/**
 * Apply the same default top-level reorderings the navigation renderer uses,
 * to a copy of $menu. Skips the moves entirely when Admin Menu Editor is
 * active (its custom order takes precedence). Inlined locally rather than
 * relying on `brikpanel_move_item_after()` so the customizer also works when
 * the modern-navigation toggle is off.
 *
 * @param array $menu Reference to the menu array to reorder in place.
 */
function brikpanel_nav_customizer_apply_default_reorder( &$menu ) {
	if ( ! is_array( $menu ) ) {
		return;
	}
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		return;
	}

	$move_after = static function ( array $arr, $item_to_move, $after_item_value ) {
		$idx_move = null;
		$idx_after = null;
		$item_value = null;
		foreach ( $arr as $i => $row ) {
			if ( ! isset( $row[2] ) ) {
				continue;
			}
			if ( $row[2] === $item_to_move ) {
				$idx_move = $i;
				$item_value = $row;
			}
			if ( $row[2] === $after_item_value ) {
				$idx_after = $i;
			}
		}
		if ( $idx_move === null || $idx_after === null ) {
			return $arr;
		}
		unset( $arr[ $idx_move ] );
		if ( $idx_move < $idx_after ) {
			$idx_after--;
		}
		if ( $idx_after === count( $arr ) - 1 ) {
			$arr[] = $item_value;
		} else {
			$arr = array_merge(
				array_slice( $arr, 0, $idx_after + 1 ),
				[ $item_value ],
				array_slice( $arr, $idx_after + 1 )
			);
		}
		return array_values( $arr );
	};

	// Mirrors — in the SAME ORDER — every move applied by
	// brikpanel_get_navigation_items() so the customizer's snapshot reflects what
	// users actually see in the sidebar. Any move that exists here but not there
	// (or vice versa) makes saving an unmodified config silently re-order the
	// sidebar, because the saved order then diverges from the live default.
	$menu = $move_after( $menu, 'edit.php?post_type=product', 'woocommerce' );
	$menu = $move_after( $menu, 'woocommerce-more', 'woocommerce-marketing' );
	$menu = $move_after( $menu, 'admin.php?page=wc-settings', 'woocommerce-marketing' );
	$menu = $move_after( $menu, 'admin.php?page=wc-settings&tab=checkout', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'wf_woocommerce_packing_list', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'brikpanel-segments', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'brikpanel-customer-analytics', 'brikpanel-segments' );
	$menu = $move_after( $menu, 'brikpanel-google-sheets', 'brikpanel-customer-analytics' );
	// Abandoned Carts — mirror of the renderer's two-step pin (after Sheets
	// when present, else after Customer Analytics).
	$menu = $move_after( $menu, 'brikpanel-abandoned-carts', 'brikpanel-customer-analytics' );
	$menu = $move_after( $menu, 'brikpanel-abandoned-carts', 'brikpanel-google-sheets' );
	// Vendors is pinned AFTER the customizer in the renderer; mirror it last so
	// the snapshot order matches the rendered sidebar's final position.
	$menu = $move_after( $menu, 'brikpanel-vendors', 'edit.php?post_type=product' );

	// Push third-party top-levels that leaked above the "Site management"
	// anchor down into that section — mirrors the same pass the live renderer
	// runs, so the settings-page snapshot lists them exactly where the sidebar
	// shows them and saving an unmodified config stays a true no-op.
	brikpanel_nav_demote_foreign_toplevels( $menu );
}

/**
 * Whether a top-level menu slug legitimately belongs in the store cluster that
 * BrikPanel renders ABOVE the "Site management" heading.
 *
 * Third-party plugins frequently register their top-level menu with a tiny
 * menu position (e.g. Elementor at 2, WOOCS Multi Currency at 2.08), which
 * sorts them above the Posts (edit.php) anchor and makes them masquerade as
 * part of BrikPanel's store cluster. Everything above the anchor that is NOT
 * one of these curated store surfaces is treated as a foreign leak and demoted
 * into Site management. Slugs below the anchor are never inspected here.
 *
 * @param string $slug Top-level menu slug ($item[2]).
 * @return bool
 */
function brikpanel_nav_is_store_slug( $slug ) {
	$slug = (string) $slug;
	if ( $slug === '' ) {
		return false;
	}

	// BrikPanel's own + BrikMarket top-levels always belong in the store cluster.
	foreach ( array( 'brikpanel', 'brikmarket', 'brik_' ) as $prefix ) {
		if ( strpos( $slug, $prefix ) === 0 ) {
			return true;
		}
	}

	// WooCommerce store surfaces BrikPanel deliberately promotes to the top
	// (matched exactly so lookalikes such as "woocommerce-multi-currency" or
	// "woocommerce-more"-imitating third-party slugs are not swept in).
	static $exact = array(
		'index.php'                   => true, // Dashboard
		'woocommerce'                 => true, // Orders
		'edit.php?post_type=product'  => true, // Products
		'woocommerce-marketing'       => true, // Marketing
		'woocommerce-more'            => true, // More
		'wf_woocommerce_packing_list' => true,
	);
	if ( isset( $exact[ $slug ] ) ) {
		return true;
	}

	// WooCommerce analytics / payments / customers / settings / reports
	// surfaces — their slugs carry query args so match by substring.
	foreach ( array(
		'wc-admin&path=/analytics',
		'wc-admin&path=/payments',
		'wc-admin&path=/wc-pay',
		'wc-admin&path=/customers',
		'page=wc-settings',
		'page=wc-reports',
	) as $needle ) {
		if ( strpos( $slug, $needle ) !== false ) {
			return true;
		}
	}

	/**
	 * Companion plugins (e.g. BrikMentor) mark their own top-level slugs as
	 * store-cluster surfaces so they are kept beside BrikPanel's items instead
	 * of being demoted into the "Site management" group.
	 *
	 * @param bool   $is_store Whether $slug belongs in the store cluster.
	 * @param string $slug     Top-level menu slug under test.
	 */
	return (bool) apply_filters( 'brikpanel_nav_is_store_slug', false, $slug );
}

/**
 * Relocate foreign top-level menu items that sort ABOVE the "Site management"
 * anchor (edit.php / Posts) to the bottom of the menu, grouping them with the
 * other third-party plugin menus that already live at the end of Site
 * management. Store-cluster surfaces (see brikpanel_nav_is_store_slug()) and
 * native separators keep their position.
 *
 * Called identically by the live renderer and the settings-page snapshot so
 * the two never diverge. No-op when there is no anchor (Posts hidden) or when
 * nothing needs demoting.
 *
 * @param array $menu Reference to a $menu-shaped array (mutated in place).
 */
function brikpanel_nav_demote_foreign_toplevels( &$menu ) {
	if ( ! is_array( $menu ) || empty( $menu ) ) {
		return;
	}

	// Without the Posts anchor there is no store / site-management split, so
	// there is nothing to demote.
	$has_anchor = false;
	foreach ( $menu as $item ) {
		if ( isset( $item[2] ) && (string) $item[2] === 'edit.php' ) {
			$has_anchor = true;
			break;
		}
	}
	if ( ! $has_anchor ) {
		return;
	}

	$before_anchor = true;
	$kept          = array();
	$demoted       = array();
	foreach ( $menu as $item ) {
		$slug = isset( $item[2] ) ? (string) $item[2] : '';

		if ( $before_anchor && $slug === 'edit.php' ) {
			$before_anchor = false;
		}

		$is_separator = isset( $item[4] ) && is_string( $item[4] ) && strpos( $item[4], 'wp-menu-separator' ) !== false;

		if ( $before_anchor && $slug !== '' && ! $is_separator && ! brikpanel_nav_is_store_slug( $slug ) ) {
			$demoted[] = $item;
			continue;
		}

		$kept[] = $item;
	}

	if ( empty( $demoted ) ) {
		return;
	}

	// Re-append the demoted rows (in their original relative order) after
	// everything else, so they sit with the other third-party plugin menus at
	// the bottom of Site management.
	$menu = array_merge( $kept, $demoted );
}

// =============================================================================
// CONFIG → MENU APPLICATION (called from brikpanel-navigation.php)
// =============================================================================

/**
 * Apply the saved nav config to a copy of $menu (and optionally $submenu).
 *
 * - Reorders items per config.
 * - Drops hidden items.
 * - Inserts custom items (with a sentinel 8th-element flag so the renderer
 *   can recognize them and pull metadata from the config).
 * - Moves items configured with section "more" into the WooCommerce-More
 *   submenu (requires $submenu reference; silently degrades to the store
 *   section when not provided).
 * - Hides individual submenu rows whose parent has a `submenus` config with
 *   `hidden: true` for that slug.
 * - Auto-appends any unconfigured items at the end of their default section
 *   so newly-installed plugins still appear.
 * - Returns the slug of the first "site_management" item — used as the anchor
 *   the renderer attaches the "Site management" heading to. Also returns an
 *   icon override map keyed by system item slug. Each value is an array
 *   `[ 'type' => 'builtin'|'svg', 'value' => slug|data-uri ]`.
 *
 * Custom items are encoded as $menu rows where:
 *   $item[2] = unique slug 'brikpanel_custom__<id>'
 *   $item[7] = full custom item config (label, url, icon, new_tab)
 *
 * @param array      $menu    The $menu global, by reference (mutated).
 * @param array|null $submenu The $submenu global, by reference (mutated when provided).
 * @return array{0:string,1:array<string,string>} [ $sitemgmt_anchor_slug, $icon_override_map ]
 */
function brikpanel_nav_customizer_apply( &$menu, &$submenu = null ) {
	$config = brikpanel_nav_config_get();

	if ( ! is_array( $menu ) || empty( $config['items'] ) ) {
		// Nothing configured — leave $menu alone, anchor stays at the legacy
		// hardcoded position (edit.php).
		return [ 'edit.php', [] ];
	}

	// Build slug → original-menu-item map.
	$by_slug = [];
	foreach ( $menu as $item ) {
		if ( isset( $item[2] ) ) {
			$by_slug[ (string) $item[2] ] = $item;
		}
	}

	$has_submenu_ref      = is_array( $submenu );
	$more_supported       = $has_submenu_ref && isset( $by_slug['woocommerce-more'] );
	$new_menu             = [];
	$consumed             = [];
	$icon_map             = [];
	$first_sitemgmt       = '';
	$submenu_hide_pending = []; // parent_slug => [ child_slug, ... ]

	foreach ( $config['items'] as $cfg ) {
		if ( empty( $cfg['type'] ) ) {
			continue;
		}
		$raw_section = isset( $cfg['section'] ) ? (string) $cfg['section'] : 'store';
		$section     = in_array( $raw_section, [ 'store', 'site_management', 'more' ], true ) ? $raw_section : 'store';

		// "More" section requires the woocommerce-more parent. If we don't
		// have a $submenu reference (or "More" isn't in $menu), gracefully
		// downgrade to "store" — keeps AME-active mode safe.
		if ( $section === 'more' && ! $more_supported ) {
			$section = 'store';
		}

		if ( $cfg['type'] === 'spacer' ) {
			// Spacers are only meaningful in the two top-level sections; inside
			// the "More" dropdown they'd have nothing to separate, so skip them.
			if ( $raw_section === 'more' ) {
				continue;
			}
			$id = isset( $cfg['id'] ) ? (string) $cfg['id'] : '';
			if ( $id === '' ) {
				continue;
			}
			$variant = ( isset( $cfg['variant'] ) && $cfg['variant'] === 'line' ) ? 'line' : 'space';
			$slug    = 'brikpanel_spacer__' . $id;
			// Synthetic $menu row: index 7 carries a sentinel the renderer reads
			// to emit a decorative gap/divider instead of a normal link. It never
			// becomes the site-management anchor.
			$new_menu[] = [
				'',
				'read',
				$slug,
				'',
				'menu-top brikpanel-nav-spacer-item',
				'menu-' . $slug,
				'div',
				[
					'is_spacer' => true,
					'variant'   => $variant,
				],
			];
			continue;
		}

		if ( $cfg['type'] === 'system' ) {
			$slug = isset( $cfg['slug'] ) ? (string) $cfg['slug'] : '';
			if ( $slug === '' || ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}
			$consumed[ $slug ] = true;
			if ( brikpanel_nav_cfg_hidden_for_current_user( $cfg ) ) {
				continue;
			}

			// Track icon override — applied by the renderer when emitting the
			// icon. A custom SVG (stored as a data URI) wins over a built-in
			// slug. The value is an array so the renderer can tell them apart.
			if ( ! empty( $cfg['icon_svg'] ) ) {
				$icon_map[ $slug ] = [ 'type' => 'svg', 'value' => (string) $cfg['icon_svg'] ];
			} elseif ( ! empty( $cfg['icon_override'] ) ) {
				$icon_map[ $slug ] = [ 'type' => 'builtin', 'value' => (string) $cfg['icon_override'] ];
			}

			// Track per-submenu hidden flags — applied to $submenu later.
			if ( ! empty( $cfg['submenus'] ) && is_array( $cfg['submenus'] ) ) {
				$hide_list = [];
				foreach ( $cfg['submenus'] as $sub_cfg ) {
					if ( ! is_array( $sub_cfg ) || ! brikpanel_nav_cfg_hidden_for_current_user( $sub_cfg ) ) {
						continue;
					}
					$sub_slug = isset( $sub_cfg['slug'] ) ? (string) $sub_cfg['slug'] : '';
					if ( $sub_slug !== '' ) {
						$hide_list[] = $sub_slug;
					}
				}
				if ( ! empty( $hide_list ) ) {
					$submenu_hide_pending[ $slug ] = $hide_list;
				}
			}

			if ( $section === 'more' ) {
				$row = $by_slug[ $slug ];
				$title = isset( $row[0] ) ? (string) $row[0] : $slug;
				if ( ! empty( $cfg['label_override'] ) ) {
					$title = (string) $cfg['label_override'];
				}
				$cap   = isset( $row[1] ) ? (string) $row[1] : 'read';
				// WP submenu row shape: [ title, cap, file, page_title, classes ].
				// Use admin.php?page= prefix unless the slug already looks like a URL/file.
				$target = $slug;
				if ( strpos( $slug, '.php' ) === false && strpos( $slug, '?' ) === false ) {
					$target = 'admin.php?page=' . $slug;
				}
				$submenu['woocommerce-more'][] = [ $title, $cap, $target, $title, 'brikpanel-more-promoted' ];
				continue;
			}

			// Apply the optional label override without touching the slug, so
			// the built-in slug→icon map (and thus the original icon) survives.
			$row = $by_slug[ $slug ];
			if ( ! empty( $cfg['label_override'] ) ) {
				$row[0] = (string) $cfg['label_override'];
				$row[3] = (string) $cfg['label_override'];
			}
			$new_menu[] = $row;
			if ( $section === 'site_management' && $first_sitemgmt === '' ) {
				$first_sitemgmt = $slug;
			}
			continue;
		}

		if ( $cfg['type'] === 'custom' ) {
			if ( brikpanel_nav_cfg_hidden_for_current_user( $cfg ) ) {
				continue;
			}
			$id      = isset( $cfg['id'] ) ? (string) $cfg['id'] : '';
			$label   = isset( $cfg['label'] ) ? (string) $cfg['label'] : '';
			$url     = isset( $cfg['url'] ) ? (string) $cfg['url'] : '';
			$icon    = isset( $cfg['icon'] ) ? (string) $cfg['icon'] : 'default';
			$icon_svg = isset( $cfg['icon_svg'] ) ? (string) $cfg['icon_svg'] : '';
			$new_tab = ! empty( $cfg['new_tab'] );
			if ( $id === '' || $label === '' || $url === '' ) {
				continue;
			}
			$slug = 'brikpanel_custom__' . $id;

			if ( $section === 'more' ) {
				// Inject as a synthetic submenu row under "More". Index 4 carries
				// extra metadata (custom_url/icon/new_tab) the renderer reads.
				$submenu['woocommerce-more'][] = [
					$label,
					'read',
					$slug,
					$label,
					'brikpanel-more-promoted brikpanel-more-custom',
					'',
					'',
					[
						'is_custom' => true,
						'url'       => $url,
						'icon'      => $icon,
						'icon_svg'  => $icon_svg,
						'new_tab'   => $new_tab,
					],
				];
				continue;
			}

			// Format mirrors WP's $menu row: [title, cap, slug, page_title, classes, hookname, icon].
			// Index 7 carries our extra metadata so the renderer can detect this
			// is a custom row and emit the right markup.
			$new_menu[] = [
				$label,
				'read',
				$slug,
				$label,
				'menu-top brikpanel-custom-nav-item',
				'menu-' . $slug,
				'div',
				[
					'is_custom' => true,
					'url'       => $url,
					'icon'      => $icon,
					'icon_svg'  => $icon_svg,
					'new_tab'   => $new_tab,
				],
			];
			// Custom links render through their embedded meta (index 7), not the
			// slug→icon map, so no $icon_map entry is needed here.
			if ( $section === 'site_management' && $first_sitemgmt === '' ) {
				$first_sitemgmt = $slug;
			}
			continue;
		}
	}

	// Append any system items not present in the config (newly installed
	// plugins, or items the user has not yet customized). We append them to
	// the store section by default; users can move them in the customizer.
	//
	// When "Hide new menu items by default" is on, these unconfigured items are
	// left OUT of the live sidebar until an administrator reviews them in the
	// editor (which always lists them). Native separators are always kept.
	$hide_new = brikpanel_nav_hide_new_items_enabled();
	foreach ( $menu as $item ) {
		if ( ! isset( $item[2] ) ) {
			continue;
		}
		$slug = (string) $item[2];
		if ( isset( $consumed[ $slug ] ) ) {
			continue;
		}
		// Skip native separators — they are unrelated to user-controllable items.
		if ( isset( $item[4] ) && is_string( $item[4] ) && strpos( $item[4], 'wp-menu-separator' ) !== false ) {
			$new_menu[] = $item;
			continue;
		}
		if ( $hide_new ) {
			// Newly-detected item, not yet reviewed — keep it off the sidebar.
			continue;
		}
		$new_menu[] = $item;
	}

	$menu = $new_menu;

	// Apply per-submenu hide flags now that $menu is final. Filter out hidden
	// child slugs from $submenu[$parent]; if the renderer received no $submenu
	// reference, this becomes a no-op.
	if ( $has_submenu_ref && ! empty( $submenu_hide_pending ) ) {
		foreach ( $submenu_hide_pending as $parent_slug => $hide_slugs ) {
			if ( ! isset( $submenu[ $parent_slug ] ) || ! is_array( $submenu[ $parent_slug ] ) ) {
				continue;
			}
			$hide_lookup = array_flip( $hide_slugs );
			$submenu[ $parent_slug ] = array_values( array_filter(
				$submenu[ $parent_slug ],
				static function ( $row ) use ( $hide_lookup ) {
					if ( ! is_array( $row ) || ! isset( $row[2] ) ) {
						return true;
					}
					return ! isset( $hide_lookup[ (string) $row[2] ] );
				}
			) );
		}
	}

	if ( $first_sitemgmt === '' ) {
		// No site-management group configured — disable the heading entirely
		// by handing back a sentinel slug that won't match anything.
		$first_sitemgmt = '__brikpanel_no_sitemgmt__';
	}

	return [ $first_sitemgmt, $icon_map ];
}

// =============================================================================
// CUSTOM-ITEM RENDER HELPERS (called from brikpanel-navigation.php)
// =============================================================================

/**
 * Detect whether a $menu row was injected by the customizer as a custom link,
 * and if so return its metadata.
 *
 * @param array $item A row from $menu.
 * @return array{is_custom:bool,url?:string,icon?:string,new_tab?:bool}|null
 */
function brikpanel_nav_customizer_extract_meta( $item ) {
	if ( ! is_array( $item ) || ! isset( $item[7] ) || ! is_array( $item[7] ) ) {
		return null;
	}
	if ( empty( $item[7]['is_custom'] ) ) {
		return null;
	}
	return $item[7];
}

// =============================================================================
// SETTINGS PAGE: WC CUSTOM FIELD TYPE
// =============================================================================

/**
 * Render the Sidebar Navigation customizer field. Wired up via
 * `add_action('woocommerce_admin_field_brikpanel_nav_customizer', ...)`.
 */
function brikpanel_render_nav_customizer_field( $value ) {
	$config           = brikpanel_nav_config_get();
	$current_items    = brikpanel_nav_customizer_collect_menu_items();
	$icon_options     = brikpanel_nav_customizer_icon_options();
	$icons_url_base   = plugins_url( 'icons/', __FILE__ );
	$spacing          = brikpanel_nav_config_spacing();
	$hide_new         = brikpanel_nav_hide_new_items_enabled();
	$sitemgmt_label   = isset( $config['sitemgmt_label'] ) ? trim( (string) $config['sitemgmt_label'] ) : '';

	// Build slug → submenu list and slug → title maps from the live snapshot
	// for quick lookup while constructing rows.
	$current_by_slug = [];
	foreach ( $current_items as $ci ) {
		$current_by_slug[ $ci['slug'] ] = $ci;
	}

	// Merge saved per-submenu hidden config with the live submenu list. New
	// children that weren't in the saved config default to visible.
	$merge_submenus = static function ( $live_subs, $saved_subs ) {
		$saved_lookup = [];
		if ( is_array( $saved_subs ) ) {
			foreach ( $saved_subs as $row ) {
				if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
					continue;
				}
				$saved_lookup[ (string) $row['slug'] ] = $row;
			}
		}
		$out = [];
		foreach ( $live_subs as $sub ) {
			$saved = isset( $saved_lookup[ $sub['slug'] ] ) ? $saved_lookup[ $sub['slug'] ] : [];
			$out[] = [
				'slug'       => $sub['slug'],
				'title'      => $sub['title'],
				'hidden'     => ! empty( $saved['hidden'] ),
				'audience'   => isset( $saved['audience'] ) ? (string) $saved['audience'] : 'all',
				'hide_roles' => isset( $saved['hide_roles'] ) && is_array( $saved['hide_roles'] ) ? array_map( 'strval', $saved['hide_roles'] ) : [],
			];
		}
		return $out;
	};

	// Build the full list of rows to show in the UI:
	//   1. Every item from saved config, in order.
	//   2. Any current $menu items not yet in config, appended to "store".
	$consumed = [];
	$rows     = [];

	foreach ( $config['items'] as $cfg ) {
		if ( empty( $cfg['type'] ) ) {
			continue;
		}
		if ( $cfg['type'] === 'system' ) {
			$slug = isset( $cfg['slug'] ) ? (string) $cfg['slug'] : '';
			if ( $slug === '' ) {
				continue;
			}
			$consumed[ $slug ] = true;
			$live      = isset( $current_by_slug[ $slug ] ) ? $current_by_slug[ $slug ] : null;
			$title     = $live ? $live['title'] : $slug;
			$live_subs = $live && isset( $live['submenus'] ) && is_array( $live['submenus'] ) ? $live['submenus'] : [];
			$saved_subs = isset( $cfg['submenus'] ) && is_array( $cfg['submenus'] ) ? $cfg['submenus'] : [];
			$rows[] = [
				'type'           => 'system',
				'slug'           => $slug,
				'title'          => $title,
				'label_override' => ! empty( $cfg['label_override'] ) ? (string) $cfg['label_override'] : '',
				'section'        => isset( $cfg['section'] ) ? $cfg['section'] : 'store',
				'hidden'         => ! empty( $cfg['hidden'] ),
				'audience'       => isset( $cfg['audience'] ) ? (string) $cfg['audience'] : 'all',
				'hide_roles'     => isset( $cfg['hide_roles'] ) && is_array( $cfg['hide_roles'] ) ? array_map( 'strval', $cfg['hide_roles'] ) : [],
				'icon_override'  => ! empty( $cfg['icon_override'] ) ? (string) $cfg['icon_override'] : '',
				'icon_svg'       => ! empty( $cfg['icon_svg'] ) ? (string) $cfg['icon_svg'] : '',
				'submenus'       => $merge_submenus( $live_subs, $saved_subs ),
				'available'      => $live !== null,
			];
		} elseif ( $cfg['type'] === 'spacer' ) {
			$rows[] = [
				'type'    => 'spacer',
				'id'      => isset( $cfg['id'] ) ? (string) $cfg['id'] : '',
				'variant' => ( isset( $cfg['variant'] ) && $cfg['variant'] === 'line' ) ? 'line' : 'space',
				'section' => isset( $cfg['section'] ) ? $cfg['section'] : 'store',
			];
		} elseif ( $cfg['type'] === 'custom' ) {
			$rows[] = [
				'type'       => 'custom',
				'id'         => isset( $cfg['id'] ) ? (string) $cfg['id'] : '',
				'label'      => isset( $cfg['label'] ) ? (string) $cfg['label'] : '',
				'url'        => isset( $cfg['url'] ) ? (string) $cfg['url'] : '',
				'icon'       => isset( $cfg['icon'] ) ? (string) $cfg['icon'] : 'default',
				'icon_svg'   => ! empty( $cfg['icon_svg'] ) ? (string) $cfg['icon_svg'] : '',
				'section'    => isset( $cfg['section'] ) ? $cfg['section'] : 'store',
				'hidden'     => ! empty( $cfg['hidden'] ),
				'audience'   => isset( $cfg['audience'] ) ? (string) $cfg['audience'] : 'all',
				'hide_roles' => isset( $cfg['hide_roles'] ) && is_array( $cfg['hide_roles'] ) ? array_map( 'strval', $cfg['hide_roles'] ) : [],
				'new_tab'    => ! empty( $cfg['new_tab'] ),
			];
		}
	}

	// Determine the default section for unconfigured items POSITIONALLY, exactly
	// the way the live renderer does it: the renderer walks $menu in order and
	// opens the "Site management" group when it reaches the anchor slug
	// ('edit.php' when no config is saved). Everything before the anchor renders
	// in the store section, everything from the anchor on renders in site
	// management. Using the same positional rule here (instead of a content
	// heuristic) makes saving an unmodified config a true no-op — otherwise
	// boundary items like Payments (registered below edit.php but heuristically
	// "store") or Multi Currency (registered above edit.php but heuristically
	// "site management") would be saved into the wrong section and silently
	// re-order the sidebar on the next save. $current_items is already in render
	// order (relocation + default reorder applied by collect_menu_items()).
	$anchor_pos = null;
	foreach ( $current_items as $idx => $ci ) {
		if ( $ci['slug'] === 'edit.php' ) {
			$anchor_pos = $idx;
			break;
		}
	}

	foreach ( $current_items as $idx => $ci ) {
		if ( isset( $consumed[ $ci['slug'] ] ) ) {
			continue;
		}
		$default_section = ( $anchor_pos !== null && $idx >= $anchor_pos ) ? 'site_management' : 'store';
		$rows[] = [
			'type'           => 'system',
			'slug'           => $ci['slug'],
			'title'          => $ci['title'],
			'label_override' => '',
			'section'        => $default_section,
			// Never placed in the saved config → a "new" item. When the hide-new
			// preference is on it starts hidden, matching the live sidebar, so the
			// admin can review it here and flip it on deliberately.
			'hidden'         => $hide_new,
			'is_new'         => true,
			'audience'       => 'all',
			'hide_roles'     => [],
			'icon_override'  => '',
			'icon_svg'       => '',
			'submenus'       => $merge_submenus( isset( $ci['submenus'] ) ? $ci['submenus'] : [], [] ),
			'available'      => true,
		];
	}

	// Bucket rows by section preserving order.
	$store    = [];
	$sitemgmt = [];
	$more     = [];
	foreach ( $rows as $row ) {
		if ( $row['section'] === 'site_management' ) {
			$sitemgmt[] = $row;
		} elseif ( $row['section'] === 'more' ) {
			$more[] = $row;
		} else {
			$store[] = $row;
		}
	}

	?>
	</table>
	<div class="brikpanel-nav-customizer-wrap">
		<div class="brikpanel-nav-customizer" data-icons-base="<?php echo esc_attr( $icons_url_base ); ?>">
				<input type="hidden"
				       name="brikpanel_nav_config_json"
				       id="brikpanel_nav_config_json"
				       value="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">

				<div class="brikpanel-navc-card">
					<div class="brikpanel-navc-header">
						<div>
							<h3 class="brikpanel-navc-title"><?php esc_html_e( 'Sidebar navigation', 'brikpanel' ); ?></h3>
							<p class="brikpanel-navc-subtitle">
								<?php esc_html_e( 'Drag to reorder, toggle visibility, move items between sections, or add custom links.', 'brikpanel' ); ?>
							</p>
						</div>
						<button type="button" class="brikpanel-navc-btn brikpanel-navc-btn-secondary" data-navc-action="reset">
							<?php esc_html_e( 'Reset to defaults', 'brikpanel' ); ?>
						</button>
					</div>

					<div class="brikpanel-navc-controls">
						<label class="brikpanel-navc-control">
							<span class="brikpanel-navc-control-label"><?php esc_html_e( 'Item spacing', 'brikpanel' ); ?></span>
							<select class="brikpanel-navc-audience" data-navc-spacing aria-label="<?php esc_attr_e( 'Spacing between menu items', 'brikpanel' ); ?>">
								<option value="compact" <?php selected( $spacing, 'compact' ); ?>><?php esc_html_e( 'Compact', 'brikpanel' ); ?></option>
								<option value="comfortable" <?php selected( $spacing, 'comfortable' ); ?>><?php esc_html_e( 'Comfortable', 'brikpanel' ); ?></option>
								<option value="spacious" <?php selected( $spacing, 'spacious' ); ?>><?php esc_html_e( 'Spacious', 'brikpanel' ); ?></option>
							</select>
						</label>
						<span class="brikpanel-navc-control-hint"><?php esc_html_e( 'Sets the vertical gap between every sidebar item.', 'brikpanel' ); ?></span>
					</div>

					<div class="brikpanel-navc-controls">
						<label class="brikpanel-navc-control">
							<span class="brikpanel-navc-control-label"><?php esc_html_e( 'Section heading label', 'brikpanel' ); ?></span>
							<input type="text" class="brikpanel-navc-input" data-navc-sitemgmt-label maxlength="60" value="<?php echo esc_attr( $sitemgmt_label ); ?>" placeholder="<?php esc_attr_e( 'Site management', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Label for the Site management heading', 'brikpanel' ); ?>">
						</label>
						<span class="brikpanel-navc-control-hint"><?php esc_html_e( 'Rename the “Site management” heading that separates your store items from the rest. Leave blank to keep the default.', 'brikpanel' ); ?></span>
					</div>

					<?php
					$roles_list = brikpanel_access_collect_roles();

					// Audience selector ("Everyone / Admins only / Specific roles")
					// rendered inline inside a row.
					$render_audience_select = function ( $audience ) {
						$audience = in_array( $audience, [ 'all', 'admins', 'roles' ], true ) ? $audience : 'all';
						?>
						<select class="brikpanel-navc-audience" data-navc-audience aria-label="<?php esc_attr_e( 'Who can see this item', 'brikpanel' ); ?>">
							<option value="all" <?php selected( $audience, 'all' ); ?>><?php esc_html_e( 'Everyone', 'brikpanel' ); ?></option>
							<option value="admins" <?php selected( $audience, 'admins' ); ?>><?php esc_html_e( 'Admins only', 'brikpanel' ); ?></option>
							<option value="roles" <?php selected( $audience, 'roles' ); ?>><?php esc_html_e( 'Specific roles', 'brikpanel' ); ?></option>
						</select>
						<?php
					};

					// Role checklist block, rendered as a full-width strip right
					// after the row it belongs to. Revealed only when the matching
					// selector is set to "Specific roles".
					$render_audience_roles = function ( $audience, $hide_roles ) use ( $roles_list ) {
						$audience   = in_array( $audience, [ 'all', 'admins', 'roles' ], true ) ? $audience : 'all';
						$hide_roles = is_array( $hide_roles ) ? array_map( 'strval', $hide_roles ) : [];
						?>
						<div class="brikpanel-navc-roles" data-navc-roles <?php echo $audience === 'roles' ? '' : 'hidden'; ?>>
							<span class="brikpanel-navc-roles-title"><?php esc_html_e( 'Hide from these roles', 'brikpanel' ); ?></span>
							<div class="brikpanel-navc-roles-grid">
								<?php foreach ( $roles_list as $role_slug => $role_name ) : ?>
									<label class="brikpanel-navc-role">
										<input type="checkbox" data-navc-role value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( (string) $role_slug, $hide_roles, true ) ); ?>>
										<span><?php echo esc_html( $role_name ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
						<?php
					};

					$render_section = function( $section_key, $heading_text, $hint_text, $items ) use ( $icons_url_base, $icon_options, $render_audience_select, $render_audience_roles ) {
						?>
						<div class="brikpanel-navc-section" data-section="<?php echo esc_attr( $section_key ); ?>">
							<div class="brikpanel-navc-section-header">
								<span class="brikpanel-navc-section-title"><?php echo esc_html( $heading_text ); ?></span>
								<span class="brikpanel-navc-section-hint"><?php echo esc_html( $hint_text ); ?></span>
							</div>
							<ul class="brikpanel-navc-list" data-section="<?php echo esc_attr( $section_key ); ?>">
								<?php foreach ( $items as $row ) : ?>
									<?php
									// Spacer rows are purely decorative — render a compact,
									// self-contained item and skip the normal link markup.
									if ( isset( $row['type'] ) && $row['type'] === 'spacer' ) {
										$spacer_variant = ( isset( $row['variant'] ) && $row['variant'] === 'line' ) ? 'line' : 'space';
										?>
										<li class="brikpanel-navc-item is-spacer"
										    data-type="spacer"
										    data-id="<?php echo esc_attr( isset( $row['id'] ) ? $row['id'] : '' ); ?>"
										    data-variant="<?php echo esc_attr( $spacer_variant ); ?>">
											<div class="brikpanel-navc-row">
												<span class="brikpanel-navc-drag" aria-hidden="true">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
												</span>
												<span class="brikpanel-navc-spacer-badge">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="12" x2="20" y2="12"/><polyline points="8 8 4 12 8 16"/><polyline points="16 8 20 12 16 16"/></svg>
													<span><?php esc_html_e( 'Spacer', 'brikpanel' ); ?></span>
												</span>
												<select class="brikpanel-navc-audience brikpanel-navc-spacer-variant" data-navc-spacer-variant aria-label="<?php esc_attr_e( 'Spacer style', 'brikpanel' ); ?>">
													<option value="space" <?php selected( $spacer_variant, 'space' ); ?>><?php esc_html_e( 'Blank space', 'brikpanel' ); ?></option>
													<option value="line" <?php selected( $spacer_variant, 'line' ); ?>><?php esc_html_e( 'Divider line', 'brikpanel' ); ?></option>
												</select>
												<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" title="<?php esc_attr_e( 'Remove spacer', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Remove spacer', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
												</button>
											</div>
										</li>
										<?php
										continue;
									}
									$is_custom      = ( $row['type'] === 'custom' );
									$icon_slug      = $is_custom ? ( $row['icon'] ?: 'default' ) : '';
									$icon_url       = $is_custom ? brikpanel_nav_icon_src( $icon_slug ) : '';
									$icon_override  = $is_custom ? '' : ( isset( $row['icon_override'] ) ? (string) $row['icon_override'] : '' );
									$icon_svg       = isset( $row['icon_svg'] ) ? (string) $row['icon_svg'] : '';
									$has_submenus   = ! $is_custom && ! empty( $row['submenus'] );
									$override_url   = $icon_override !== '' ? brikpanel_nav_icon_src( $icon_override ) : '';
									$label_override = $is_custom ? '' : ( isset( $row['label_override'] ) ? (string) $row['label_override'] : '' );
									$display_label  = $is_custom ? $row['label'] : ( $label_override !== '' ? $label_override : $row['title'] );
									?>
									<li class="brikpanel-navc-item <?php echo $is_custom ? 'is-custom' : 'is-system'; ?> <?php echo ! empty( $row['hidden'] ) ? 'is-hidden' : ''; ?>"
									    data-type="<?php echo esc_attr( $row['type'] ); ?>"
									    data-icon-svg="<?php echo esc_attr( $icon_svg ); ?>"
									    <?php if ( $is_custom ) : ?>
									    data-id="<?php echo esc_attr( $row['id'] ); ?>"
									    data-label="<?php echo esc_attr( $row['label'] ); ?>"
									    data-url="<?php echo esc_attr( $row['url'] ); ?>"
									    data-icon="<?php echo esc_attr( $row['icon'] ); ?>"
									    data-new-tab="<?php echo ! empty( $row['new_tab'] ) ? '1' : '0'; ?>"
									    <?php else : ?>
									    data-slug="<?php echo esc_attr( $row['slug'] ); ?>"
									    data-orig-title="<?php echo esc_attr( $row['title'] ); ?>"
									    data-label-override="<?php echo esc_attr( $label_override ); ?>"
									    data-icon-override="<?php echo esc_attr( $icon_override ); ?>"
									    <?php endif; ?>>
										<div class="brikpanel-navc-row">
											<span class="brikpanel-navc-drag" aria-hidden="true">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
											</span>
											<span class="brikpanel-navc-icon">
												<?php if ( $icon_svg !== '' ) : ?>
													<img src="<?php echo esc_url( $icon_svg, [ 'data', 'http', 'https' ] ); ?>" alt="" width="14" height="14">
												<?php elseif ( $is_custom ) : ?>
													<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="14" height="14">
												<?php elseif ( $icon_override !== '' ) : ?>
													<img src="<?php echo esc_url( $override_url ); ?>" alt="" width="14" height="14">
												<?php else : ?>
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>
												<?php endif; ?>
											</span>
											<span class="brikpanel-navc-label">
												<span class="brikpanel-navc-label-text">
													<?php echo esc_html( $display_label ); ?>
													<?php if ( ! empty( $row['is_new'] ) ) : ?>
														<span class="brikpanel-navc-new-badge" title="<?php esc_attr_e( 'Newly detected — not yet reviewed', 'brikpanel' ); ?>"><?php esc_html_e( 'New', 'brikpanel' ); ?></span>
													<?php endif; ?>
												</span>
												<?php if ( $is_custom ) : ?>
													<span class="brikpanel-navc-label-meta"><?php echo esc_html( $row['url'] ); ?></span>
												<?php endif; ?>
											</span>
											<label class="brikpanel-navc-toggle" title="<?php esc_attr_e( 'Visible in sidebar', 'brikpanel' ); ?>">
												<input type="checkbox" data-navc-toggle <?php checked( empty( $row['hidden'] ) ); ?>>
												<span class="brikpanel-navc-toggle-track" aria-hidden="true">
													<span class="brikpanel-navc-toggle-thumb"></span>
												</span>
											</label>
											<?php $render_audience_select( isset( $row['audience'] ) ? $row['audience'] : 'all' ); ?>
											<?php if ( $is_custom ) : ?>
												<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="edit" title="<?php esc_attr_e( 'Edit link', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Edit link', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
												</button>
												<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" title="<?php esc_attr_e( 'Delete link', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Delete link', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
												</button>
											<?php else : ?>
												<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="edit-system" title="<?php esc_attr_e( 'Rename or change icon', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Rename or change icon', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
												</button>
												<?php if ( $has_submenus ) : ?>
													<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-submenu-chevron" data-navc-action="toggle-submenus" title="<?php esc_attr_e( 'Manage submenu items', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Manage submenu items', 'brikpanel' ); ?>" aria-expanded="false">
														<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
													</button>
												<?php endif; ?>
											<?php endif; ?>
										</div>
										<?php $render_audience_roles( isset( $row['audience'] ) ? $row['audience'] : 'all', isset( $row['hide_roles'] ) ? $row['hide_roles'] : [] ); ?>
										<?php if ( $has_submenus ) : ?>
											<div class="brikpanel-navc-submenus" hidden>
												<div class="brikpanel-navc-submenus-inner">
													<span class="brikpanel-navc-submenus-title"><?php esc_html_e( 'Submenu items', 'brikpanel' ); ?></span>
													<ul class="brikpanel-navc-submenu-list">
														<?php foreach ( $row['submenus'] as $sub ) : ?>
															<li class="brikpanel-navc-submenu-item <?php echo ! empty( $sub['hidden'] ) ? 'is-hidden' : ''; ?>" data-sub-slug="<?php echo esc_attr( $sub['slug'] ); ?>">
																<span class="brikpanel-navc-submenu-label"><?php echo esc_html( $sub['title'] ); ?></span>
																<label class="brikpanel-navc-toggle brikpanel-navc-toggle-sm" title="<?php esc_attr_e( 'Visible in submenu', 'brikpanel' ); ?>">
																	<input type="checkbox" data-navc-sub-toggle <?php checked( empty( $sub['hidden'] ) ); ?>>
																	<span class="brikpanel-navc-toggle-track" aria-hidden="true">
																		<span class="brikpanel-navc-toggle-thumb"></span>
																	</span>
																</label>
																<?php $render_audience_select( isset( $sub['audience'] ) ? $sub['audience'] : 'all' ); ?>
																<?php $render_audience_roles( isset( $sub['audience'] ) ? $sub['audience'] : 'all', isset( $sub['hide_roles'] ) ? $sub['hide_roles'] : [] ); ?>
															</li>
														<?php endforeach; ?>
													</ul>
												</div>
											</div>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<div class="brikpanel-navc-actions">
								<button type="button" class="brikpanel-navc-add" data-navc-action="add">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
									<?php esc_html_e( 'Add custom link', 'brikpanel' ); ?>
								</button>
								<?php if ( $section_key !== 'more' ) : ?>
									<button type="button" class="brikpanel-navc-add" data-navc-action="add-spacer">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="12" x2="20" y2="12"/><polyline points="8 8 4 12 8 16"/><polyline points="16 8 20 12 16 16"/></svg>
										<?php esc_html_e( 'Add spacer', 'brikpanel' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>
						<?php
					};

					$render_section( 'store', __( 'Top section', 'brikpanel' ), __( 'Items above the “Site management” heading.', 'brikpanel' ), $store );
					$render_section( 'site_management', __( 'Site management', 'brikpanel' ), __( 'Items below the “Site management” heading. Leave empty to hide the heading.', 'brikpanel' ), $sitemgmt );
					$render_section( 'more', __( 'More menu', 'brikpanel' ), __( 'Items shown inside the More dropdown.', 'brikpanel' ), $more );
					?>
				</div>

				<!-- Add/Edit dialog (hidden by default, JS controls visibility) -->
				<div class="brikpanel-navc-dialog-backdrop" hidden>
					<div class="brikpanel-navc-dialog" role="dialog" aria-modal="true" aria-labelledby="brikpanel-navc-dialog-title">
						<div class="brikpanel-navc-dialog-header">
							<h4 id="brikpanel-navc-dialog-title"><?php esc_html_e( 'Custom link', 'brikpanel' ); ?></h4>
							<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="dialog-close" aria-label="<?php esc_attr_e( 'Close', 'brikpanel' ); ?>">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
							</button>
						</div>
						<div class="brikpanel-navc-dialog-body">
							<label class="brikpanel-navc-field">
								<span><?php esc_html_e( 'Label', 'brikpanel' ); ?></span>
								<input type="text" data-navc-field="label" placeholder="<?php esc_attr_e( 'Customer support', 'brikpanel' ); ?>">
							</label>
							<label class="brikpanel-navc-field">
								<span><?php esc_html_e( 'URL', 'brikpanel' ); ?></span>
								<input type="text" data-navc-field="url" placeholder="https://example.com">
							</label>
							<label class="brikpanel-navc-field">
								<span><?php esc_html_e( 'Icon', 'brikpanel' ); ?></span>
								<select data-navc-field="icon">
									<?php foreach ( $icon_options as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<div class="brikpanel-navc-field brikpanel-navc-svg-field">
								<span><?php esc_html_e( 'Or paste a custom SVG icon', 'brikpanel' ); ?></span>
								<div class="brikpanel-navc-svg-row">
									<span class="brikpanel-navc-svg-preview" aria-hidden="true"></span>
									<textarea data-navc-field="icon_svg" rows="3" placeholder="<?php esc_attr_e( 'Paste SVG code or a data:image/svg+xml;base64,… URI', 'brikpanel' ); ?>"></textarea>
								</div>
								<span class="brikpanel-navc-svg-hint"><?php esc_html_e( 'Paste an SVG from Icons8, SVG Repo or anywhere — it overrides the icon chosen above. Leave empty to use the icon above.', 'brikpanel' ); ?></span>
							</div>
							<label class="brikpanel-navc-field brikpanel-navc-field-checkbox">
								<input type="checkbox" data-navc-field="new_tab">
								<span><?php esc_html_e( 'Open in a new tab', 'brikpanel' ); ?></span>
							</label>
						</div>
						<div class="brikpanel-navc-dialog-footer">
							<button type="button" class="brikpanel-navc-btn brikpanel-navc-btn-secondary" data-navc-action="dialog-clear-icon" hidden><?php esc_html_e( 'Use original', 'brikpanel' ); ?></button>
							<button type="button" class="brikpanel-navc-btn brikpanel-navc-btn-secondary" data-navc-action="dialog-cancel"><?php esc_html_e( 'Cancel', 'brikpanel' ); ?></button>
							<button type="button" class="brikpanel-navc-btn brikpanel-navc-btn-primary" data-navc-action="dialog-save"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
						</div>
					</div>
				</div>
		</div>
	</div>
	<table class="form-table">
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_nav_customizer', 'brikpanel_render_nav_customizer_field' );

// =============================================================================
// SETTINGS PAGE: SAVE
// =============================================================================

/**
 * Persist nav config when the BrikPanel WC settings tab is submitted.
 * Runs after the standard `woocommerce_update_options(brikpanel_settings_fields())`
 * call (priority 11).
 */
add_action( 'woocommerce_update_options_brikpanel', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! isset( $_POST['brikpanel_nav_config_json'] ) ) {
		return;
	}
	$raw = wp_unslash( $_POST['brikpanel_nav_config_json'] );
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return;
	}
	brikpanel_nav_config_save( $decoded );
}, 11 );

// =============================================================================
// SETTINGS PAGE: ENQUEUE ASSETS
// =============================================================================

/**
 * Load the customizer's CSS and JS only on the BrikPanel WC settings tab.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( $hook !== 'woocommerce_page_wc-settings' ) {
		return;
	}
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
	if ( $tab !== 'brikpanel' ) {
		return;
	}
	// filemtime version so edits to the asset (e.g. the mobile breakpoint)
	// bust the browser cache immediately, even between releases — a static
	// BRIKPANEL_VERSION would serve a stale copy until the next version bump.
	$navc_css_ver = @filemtime( __DIR__ . '/brikpanel-nav-customizer.css' ) ?: BRIKPANEL_VERSION;
	$navc_js_ver  = @filemtime( __DIR__ . '/brikpanel-nav-customizer.js' ) ?: BRIKPANEL_VERSION;
	wp_enqueue_style(
		'brikpanel-nav-customizer',
		plugins_url( 'brikpanel-nav-customizer.css', __FILE__ ),
		[],
		$navc_css_ver
	);
	wp_enqueue_script(
		'brikpanel-nav-customizer',
		plugins_url( 'brikpanel-nav-customizer.js', __FILE__ ),
		[ 'jquery', 'jquery-ui-sortable' ],
		$navc_js_ver,
		true
	);
	wp_localize_script( 'brikpanel-nav-customizer', 'brikpanelNavCustomizer', [
		'iconOptions' => brikpanel_nav_customizer_icon_options(),
		'iconsBase'   => plugins_url( 'icons/', __FILE__ ),
		'iconVer'     => BRIKPANEL_VERSION,
		'iconStyle'   => function_exists( 'brikpanel_nav_icon_style' ) ? brikpanel_nav_icon_style() : 'solid',
		'lineIconSlugs' => brikpanel_nav_line_icon_slugs(),
		'roles'       => brikpanel_access_collect_roles(),
		'i18n'        => [
			'editLink'      => __( 'Edit custom link', 'brikpanel' ),
			'addLink'       => __( 'Add custom link', 'brikpanel' ),
			'changeIcon'    => __( 'Change icon', 'brikpanel' ),
			'editItem'      => __( 'Edit menu item', 'brikpanel' ),
			'invalidSvg'    => __( 'That SVG could not be read. Paste valid SVG code or a data:image/svg+xml URI.', 'brikpanel' ),
			'confirmReset'  => __( 'Reset sidebar navigation to its defaults? This clears all customizations and removes custom links.', 'brikpanel' ),
			'confirmDelete' => __( 'Remove this custom link?', 'brikpanel' ),
			'invalidUrl'    => __( 'Please enter a valid URL (starting with http:// or https://) or an admin path.', 'brikpanel' ),
			'invalidLabel'  => __( 'Please enter a label.', 'brikpanel' ),
			'edit'          => __( 'Edit', 'brikpanel' ),
			'delete'        => __( 'Delete', 'brikpanel' ),
			'audienceLabel' => __( 'Who can see this item', 'brikpanel' ),
			'audienceAll'   => __( 'Everyone', 'brikpanel' ),
			'audienceAdmins'=> __( 'Admins only', 'brikpanel' ),
			'audienceRoles' => __( 'Specific roles', 'brikpanel' ),
			'hideFromRoles' => __( 'Hide from these roles', 'brikpanel' ),
			'spacer'        => __( 'Spacer', 'brikpanel' ),
			'spacerStyle'   => __( 'Spacer style', 'brikpanel' ),
			'spacerSpace'   => __( 'Blank space', 'brikpanel' ),
			'spacerLine'    => __( 'Divider line', 'brikpanel' ),
			'removeSpacer'  => __( 'Remove spacer', 'brikpanel' ),
		],
	] );
} );
