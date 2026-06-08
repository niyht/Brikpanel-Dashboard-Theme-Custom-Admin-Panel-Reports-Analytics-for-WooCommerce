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
 * @return array
 */
function brikpanel_nav_config_get() {
	$raw = get_option( BRIKPANEL_NAV_CONFIG_OPTION, '' );
	if ( empty( $raw ) ) {
		return [ 'version' => 1, 'items' => [] ];
	}
	$decoded = is_array( $raw ) ? $raw : json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return [ 'version' => 1, 'items' => [] ];
	}
	$decoded['items'] = isset( $decoded['items'] ) && is_array( $decoded['items'] ) ? $decoded['items'] : [];
	return $decoded;
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

			// Optional icon override — must be one of the picker's known slugs.
			if ( isset( $item['icon_override'] ) ) {
				$icon_override = sanitize_key( (string) $item['icon_override'] );
				if ( $icon_override !== '' && isset( $icon_options[ $icon_override ] ) ) {
					$row['icon_override'] = $icon_override;
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
					$subs[] = [
						'slug'   => $sub_slug,
						'hidden' => ! empty( $sub['hidden'] ),
					];
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
			$cleaned[] = [
				'type'    => 'custom',
				'id'      => $id,
				'label'   => $label,
				'url'     => $url,
				'icon'    => $icon,
				'section' => $section,
				'hidden'  => $hidden,
				'new_tab' => $new_tab,
			];
			continue;
		}
	}

	$payload = [
		'version' => 1,
		'items'   => $cleaned,
	];
	update_option( BRIKPANEL_NAV_CONFIG_OPTION, wp_json_encode( $payload ), false );
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
	brikpanel_nav_customizer_apply_wc_promotion( $menu_snapshot, $submenu_snapshot );
	brikpanel_nav_customizer_apply_default_reorder( $menu_snapshot );

	// Slugs the renderer moves OUT of the WooCommerce submenu tree (either
	// promoted to top-level or pushed under "More"). Filter them from the
	// per-item submenu list so users don't see them duplicated under
	// WooCommerce in the customizer.
	$wc_promoted_or_skipped = [
		'wc-settings',
		'wc-reports',
		'wc-status',
		'wc-admin&path=/extensions',
		'wc-orders',
		'edit.php?post_type=shop_order',
		'wc-orders--shop_subscription',
		'wc-admin&path=/customers',
	];

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
			$is_woocommerce_parent = ( $slug === 'woocommerce' );
			foreach ( $submenu_snapshot[ $slug ] as $sub ) {
				if ( ! is_array( $sub ) || ! isset( $sub[2] ) ) {
					continue;
				}
				$sub_slug = (string) $sub[2];
				if ( $sub_slug === '' ) {
					continue;
				}
				if ( $is_woocommerce_parent && in_array( $sub_slug, $wc_promoted_or_skipped, true ) ) {
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
 * Mirror the WC submenu → top-level promotion that brikpanel_get_navigation_items()
 * does at render time. Adds `woocommerce-more` as a top-level entry and promotes
 * `wc-settings` (and similar moved submenus) up to top-level so the customizer
 * lists them. Skipped when AME is active or when the global "modern navigation"
 * toggle is off — both cases bypass this promotion in the renderer too.
 *
 * @param array $menu     Top-level menu (mutated).
 * @param array $submenu  Submenu tree (mutated — keeps customizer's view consistent).
 */
function brikpanel_nav_customizer_apply_wc_promotion( &$menu, &$submenu ) {
	if ( ! is_array( $menu ) ) {
		return;
	}
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		return;
	}
	if ( get_option( 'brikpanel_modern_navigation', 'yes' ) === 'no' ) {
		return;
	}

	// 1) Synthesize the "More" top-level entry.
	$has_more = false;
	foreach ( $menu as $row ) {
		if ( isset( $row[2] ) && $row[2] === 'woocommerce-more' ) { $has_more = true; break; }
	}
	if ( ! $has_more ) {
		$menu[] = array(
			__( 'More', 'brikpanel' ),
			'manage_woocommerce',
			'woocommerce-more',
			__( 'More', 'brikpanel' ),
		);
	}

	// 2) Walk the WooCommerce submenu and promote wc-settings to top-level.
	foreach ( $menu as $key => $item ) {
		if ( ! isset( $item[2] ) || $item[2] !== 'woocommerce' ) {
			continue;
		}
		$wc_subs = ! empty( $submenu['woocommerce'] ) ? $submenu['woocommerce'] : [];
		$to_move = [ 'wc-settings', 'wc-reports', 'wc-status', 'wc-admin&path=/extensions' ];
		foreach ( $wc_subs as $sub_key => $sub_item ) {
			if ( ! isset( $sub_item[2] ) ) {
				continue;
			}
			$slug = (string) $sub_item[2];
			if ( ! in_array( $slug, $to_move, true ) ) {
				continue;
			}
			$promoted_slug = 'admin.php?page=' . $slug;
			if ( $promoted_slug === 'admin.php?page=wc-settings' ) {
				// Push as top-level entry mirroring the renderer.
				$promoted = $sub_item;
				$promoted[2] = $promoted_slug;
				// Avoid duplicates if user has visited the page already this request.
				$dup = false;
				foreach ( $menu as $existing ) {
					if ( isset( $existing[2] ) && $existing[2] === $promoted_slug ) { $dup = true; break; }
				}
				if ( ! $dup ) {
					$menu[] = $promoted;
				}
			}
		}
		break;
	}
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

	// Mirrors the moves applied by brikpanel_get_navigation_items() so the
	// customizer's snapshot reflects what users actually see in the sidebar.
	$menu = $move_after( $menu, 'woocommerce-more', 'woocommerce-marketing' );
	$menu = $move_after( $menu, 'admin.php?page=wc-settings', 'woocommerce-marketing' );
	$menu = $move_after( $menu, 'admin.php?page=wc-settings&tab=checkout', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'wf_woocommerce_packing_list', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'brikpanel-segments', 'edit.php?post_type=product' );
	$menu = $move_after( $menu, 'brikpanel-customer-analytics', 'brikpanel-segments' );
}

/**
 * Heuristic: classify a slug as belonging to the "store" section by default.
 * Anything WooCommerce, BrikPanel, BrikMarket, or product-related goes on top.
 *
 * @param string $slug
 * @return bool
 */
function brikpanel_nav_customizer_is_store_slug( $slug ) {
	$store_slugs = [
		'index.php',
		'woocommerce',
		'woocommerce-more',
		'brikmarket',
		'edit.php?post_type=product',
		'wc-admin&path=/analytics/overview',
		'wc-admin&path=/payments/overview',
		'wc-admin&path=/payments/connect',
		'wc-admin&path=/wc-pay-welcome-page',
		'woocommerce-marketing',
		'admin.php?page=wc-settings',
		'brikpanel-segments',
		'brikpanel-customer-analytics',
		'brikpanel-expenses',
		'brikpanel-coupons',
	];
	if ( in_array( $slug, $store_slugs, true ) ) {
		return true;
	}
	if ( strpos( $slug, 'brikpanel-' ) === 0 ) {
		return true;
	}
	if ( strpos( $slug, 'wc-admin' ) !== false ) {
		return true;
	}
	if ( strpos( $slug, 'wc-' ) === 0 ) {
		return true;
	}
	// Catch promoted WC submenu entries like "admin.php?page=wc-settings&tab=*"
	// (WC Payments promo, etc.) — they're store-related by intent.
	if ( strpos( $slug, 'admin.php?page=wc-' ) === 0 ) {
		return true;
	}
	return false;
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
 *   icon override map keyed by item slug (covers both custom links and
 *   user-overridden system items).
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

		if ( $cfg['type'] === 'system' ) {
			$slug = isset( $cfg['slug'] ) ? (string) $cfg['slug'] : '';
			if ( $slug === '' || ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}
			$consumed[ $slug ] = true;
			if ( ! empty( $cfg['hidden'] ) ) {
				continue;
			}

			// Track icon override — applied by the renderer when emitting the icon.
			if ( ! empty( $cfg['icon_override'] ) ) {
				$icon_map[ $slug ] = (string) $cfg['icon_override'];
			}

			// Track per-submenu hidden flags — applied to $submenu later.
			if ( ! empty( $cfg['submenus'] ) && is_array( $cfg['submenus'] ) ) {
				$hide_list = [];
				foreach ( $cfg['submenus'] as $sub_cfg ) {
					if ( ! is_array( $sub_cfg ) || empty( $sub_cfg['hidden'] ) ) {
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

			$new_menu[] = $by_slug[ $slug ];
			if ( $section === 'site_management' && $first_sitemgmt === '' ) {
				$first_sitemgmt = $slug;
			}
			continue;
		}

		if ( $cfg['type'] === 'custom' ) {
			if ( ! empty( $cfg['hidden'] ) ) {
				continue;
			}
			$id      = isset( $cfg['id'] ) ? (string) $cfg['id'] : '';
			$label   = isset( $cfg['label'] ) ? (string) $cfg['label'] : '';
			$url     = isset( $cfg['url'] ) ? (string) $cfg['url'] : '';
			$icon    = isset( $cfg['icon'] ) ? (string) $cfg['icon'] : 'default';
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
					'new_tab'   => $new_tab,
				],
			];
			$icon_map[ $slug ] = $icon;
			if ( $section === 'site_management' && $first_sitemgmt === '' ) {
				$first_sitemgmt = $slug;
			}
			continue;
		}
	}

	// Append any system items not present in the config (newly installed
	// plugins, or items the user has not yet customized). We append them to
	// the store section by default; users can move them in the customizer.
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
				$saved_lookup[ (string) $row['slug'] ] = ! empty( $row['hidden'] );
			}
		}
		$out = [];
		foreach ( $live_subs as $sub ) {
			$out[] = [
				'slug'   => $sub['slug'],
				'title'  => $sub['title'],
				'hidden' => isset( $saved_lookup[ $sub['slug'] ] ) ? $saved_lookup[ $sub['slug'] ] : false,
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
				'type'          => 'system',
				'slug'          => $slug,
				'title'         => $title,
				'section'       => isset( $cfg['section'] ) ? $cfg['section'] : 'store',
				'hidden'        => ! empty( $cfg['hidden'] ),
				'icon_override' => ! empty( $cfg['icon_override'] ) ? (string) $cfg['icon_override'] : '',
				'submenus'      => $merge_submenus( $live_subs, $saved_subs ),
				'available'     => $live !== null,
			];
		} elseif ( $cfg['type'] === 'custom' ) {
			$rows[] = [
				'type'    => 'custom',
				'id'      => isset( $cfg['id'] ) ? (string) $cfg['id'] : '',
				'label'   => isset( $cfg['label'] ) ? (string) $cfg['label'] : '',
				'url'     => isset( $cfg['url'] ) ? (string) $cfg['url'] : '',
				'icon'    => isset( $cfg['icon'] ) ? (string) $cfg['icon'] : 'default',
				'section' => isset( $cfg['section'] ) ? $cfg['section'] : 'store',
				'hidden'  => ! empty( $cfg['hidden'] ),
				'new_tab' => ! empty( $cfg['new_tab'] ),
			];
		}
	}

	foreach ( $current_items as $ci ) {
		if ( isset( $consumed[ $ci['slug'] ] ) ) {
			continue;
		}
		$rows[] = [
			'type'          => 'system',
			'slug'          => $ci['slug'],
			'title'         => $ci['title'],
			'section'       => brikpanel_nav_customizer_is_store_slug( $ci['slug'] ) ? 'store' : 'site_management',
			'hidden'        => false,
			'icon_override' => '',
			'submenus'      => $merge_submenus( isset( $ci['submenus'] ) ? $ci['submenus'] : [], [] ),
			'available'     => true,
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

					<?php
					$render_section = function( $section_key, $heading_text, $hint_text, $items ) use ( $icons_url_base, $icon_options ) {
						?>
						<div class="brikpanel-navc-section" data-section="<?php echo esc_attr( $section_key ); ?>">
							<div class="brikpanel-navc-section-header">
								<span class="brikpanel-navc-section-title"><?php echo esc_html( $heading_text ); ?></span>
								<span class="brikpanel-navc-section-hint"><?php echo esc_html( $hint_text ); ?></span>
							</div>
							<ul class="brikpanel-navc-list" data-section="<?php echo esc_attr( $section_key ); ?>">
								<?php foreach ( $items as $row ) : ?>
									<?php
									$is_custom      = ( $row['type'] === 'custom' );
									$icon_slug      = $is_custom ? ( $row['icon'] ?: 'default' ) : '';
									$icon_url       = $is_custom ? $icons_url_base . $icon_slug . '.svg' : '';
									$icon_override  = $is_custom ? '' : ( isset( $row['icon_override'] ) ? (string) $row['icon_override'] : '' );
									$has_submenus   = ! $is_custom && ! empty( $row['submenus'] );
									$override_url   = $icon_override !== '' ? $icons_url_base . $icon_override . '.svg' : '';
									?>
									<li class="brikpanel-navc-item <?php echo $is_custom ? 'is-custom' : 'is-system'; ?> <?php echo ! empty( $row['hidden'] ) ? 'is-hidden' : ''; ?>"
									    data-type="<?php echo esc_attr( $row['type'] ); ?>"
									    <?php if ( $is_custom ) : ?>
									    data-id="<?php echo esc_attr( $row['id'] ); ?>"
									    data-label="<?php echo esc_attr( $row['label'] ); ?>"
									    data-url="<?php echo esc_attr( $row['url'] ); ?>"
									    data-icon="<?php echo esc_attr( $row['icon'] ); ?>"
									    data-new-tab="<?php echo ! empty( $row['new_tab'] ) ? '1' : '0'; ?>"
									    <?php else : ?>
									    data-slug="<?php echo esc_attr( $row['slug'] ); ?>"
									    data-icon-override="<?php echo esc_attr( $icon_override ); ?>"
									    <?php endif; ?>>
										<div class="brikpanel-navc-row">
											<span class="brikpanel-navc-drag" aria-hidden="true">
												<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/></svg>
											</span>
											<span class="brikpanel-navc-icon">
												<?php if ( $is_custom ) : ?>
													<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="14" height="14">
												<?php elseif ( $icon_override !== '' ) : ?>
													<img src="<?php echo esc_url( $override_url ); ?>" alt="" width="14" height="14">
												<?php else : ?>
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/></svg>
												<?php endif; ?>
											</span>
											<span class="brikpanel-navc-label">
												<span class="brikpanel-navc-label-text"><?php echo esc_html( $is_custom ? $row['label'] : $row['title'] ); ?></span>
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
											<?php if ( $is_custom ) : ?>
												<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="edit" title="<?php esc_attr_e( 'Edit link', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Edit link', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
												</button>
												<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-iconbtn-danger" data-navc-action="delete" title="<?php esc_attr_e( 'Delete link', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Delete link', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
												</button>
											<?php else : ?>
												<button type="button" class="brikpanel-navc-iconbtn" data-navc-action="change-icon" title="<?php esc_attr_e( 'Change icon', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Change icon', 'brikpanel' ); ?>">
													<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 2v3"/><path d="M12 19v3"/><path d="M2 12h3"/><path d="M19 12h3"/></svg>
												</button>
												<?php if ( $has_submenus ) : ?>
													<button type="button" class="brikpanel-navc-iconbtn brikpanel-navc-submenu-chevron" data-navc-action="toggle-submenus" title="<?php esc_attr_e( 'Manage submenu items', 'brikpanel' ); ?>" aria-label="<?php esc_attr_e( 'Manage submenu items', 'brikpanel' ); ?>" aria-expanded="false">
														<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
													</button>
												<?php endif; ?>
											<?php endif; ?>
										</div>
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
															</li>
														<?php endforeach; ?>
													</ul>
												</div>
											</div>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<button type="button" class="brikpanel-navc-add" data-navc-action="add">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
								<?php esc_html_e( 'Add custom link', 'brikpanel' ); ?>
							</button>
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
	wp_enqueue_style(
		'brikpanel-nav-customizer',
		plugins_url( 'brikpanel-nav-customizer.css', __FILE__ ),
		[],
		BRIKPANEL_VERSION
	);
	wp_enqueue_script(
		'brikpanel-nav-customizer',
		plugins_url( 'brikpanel-nav-customizer.js', __FILE__ ),
		[ 'jquery', 'jquery-ui-sortable' ],
		BRIKPANEL_VERSION,
		true
	);
	wp_localize_script( 'brikpanel-nav-customizer', 'brikpanelNavCustomizer', [
		'iconOptions' => brikpanel_nav_customizer_icon_options(),
		'iconsBase'   => plugins_url( 'icons/', __FILE__ ),
		'i18n'        => [
			'editLink'      => __( 'Edit custom link', 'brikpanel' ),
			'addLink'       => __( 'Add custom link', 'brikpanel' ),
			'changeIcon'    => __( 'Change icon', 'brikpanel' ),
			'confirmReset'  => __( 'Reset sidebar navigation to its defaults? This clears all customizations and removes custom links.', 'brikpanel' ),
			'confirmDelete' => __( 'Remove this custom link?', 'brikpanel' ),
			'invalidUrl'    => __( 'Please enter a valid URL (starting with http:// or https://) or an admin path.', 'brikpanel' ),
			'invalidLabel'  => __( 'Please enter a label.', 'brikpanel' ),
			'edit'          => __( 'Edit', 'brikpanel' ),
			'delete'        => __( 'Delete', 'brikpanel' ),
		],
	] );
} );
