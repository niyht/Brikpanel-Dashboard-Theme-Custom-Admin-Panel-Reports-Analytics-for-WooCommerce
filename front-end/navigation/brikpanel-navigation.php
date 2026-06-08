<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The custom navigation rebuilds the admin sidebar based on each subsite's own
 * $menu / $submenu globals — those globals don't exist (and the link targets
 * don't apply) on the Network Admin or User Admin chrome. Skip the entire
 * navigation override there so super admins see the unmodified core layout.
 */
function brikpanel_navigation_skip_super_admin_chrome() {
	// Also stand down under the Desktop Mode shell: the dock is built from
	// the admin menu and replaces this sidebar, and the #wpcontent left
	// margin this nav relies on would orphan inside a chromeless window
	// (see includes/brikpanel-desktop-mode-compat.php).
	if ( function_exists( 'brikpanel_is_desktop_mode' ) && brikpanel_is_desktop_mode() ) {
		return true;
	}
	return is_network_admin() || is_user_admin();
}

// Hide WordPress admin footer (only on per-site admin)
add_filter('admin_footer_text', function ( $text ) {
	return brikpanel_navigation_skip_super_admin_chrome() ? $text : '';
});
add_filter('update_footer', function ( $text ) {
	return brikpanel_navigation_skip_super_admin_chrome() ? $text : '';
}, 11);

// If HPOS (High-Performance Order Storage) is not active, add CSS to customize WooCommerce menu
add_action('admin_head', function() {
    if ( brikpanel_navigation_skip_super_admin_chrome() ) {
        return;
    }
    // If HPOS is not active, hide main WooCommerce title and icon
    if (get_option('woocommerce_custom_orders_table_enabled') !== 'yes') { ?>
        <style>
            /* Hide WooCommerce main title and icon */
            #toplevel_page_woocommerce > .brikpanel-menu-icon-title-chevron-container > .brikpanel-menu-icon-title-container {
                display: none !important;
            }
        </style>
    <?php }
});


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
 * Function to render custom menu structure in admin panel.
 */
function brikpanel_render_navigation() {
    if ( brikpanel_navigation_skip_super_admin_chrome() ) {
        return;
    }
    $items = brikpanel_get_navigation_items();
    echo '<nav id="brikpanel-navigation">';
    echo '<ul>' . wp_kses_post($items) . '</ul>';
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
                        const submenu = li.querySelector(".brikpanel-submenu");
                        if (submenu) {
                            submenu.classList.toggle("visible");
                        }
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

	// If Admin Menu Editor plugin is active, load custom menus.
	if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		global $wp_menu_editor;
		if ( isset( $wp_menu_editor ) && $wp_menu_editor->load_custom_menu() ) {
			$wp_menu_editor->replace_wp_menu();
		}
	}

	$html = '';

	// If Admin Menu Editor is not active, add a fake "More" menu item (WooCommerce submenus can be moved there).
	if ( ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		$menu[] = array(
			__('More', 'brikpanel'),
			'manage_woocommerce',
			'woocommerce-more',
			__('More', 'brikpanel'),
		);		

		foreach ( $menu as $key => $item ) {
			if ( $item[2] !== 'woocommerce' ) {
				continue;
			}

		// This part is inside the “$menu as $key => $item” loop, after finding the WooCommerce menu.
		$submenu_items = ! empty( $submenu[ $item[2] ] ) ? $submenu[ $item[2] ] : array();

		// Items to move
		$to_move = array(
			'wc-settings',
			'wc-reports',
			'wc-status',
			'wc-admin&path=/extensions',
		);

		// Slugs you don't want
		$skip_slugs = array(
			'wc-orders',
			'edit.php?post_type=shop_order',
			'wc-orders--shop_subscription',
			'wc-admin&path=/customers',
		);

		foreach ( $submenu_items as $sub_key => $sub_item ) {
			$slug = $sub_item[2];
			// Temporarily hold submenu item
			$temp = $submenu_items[ $sub_key ];

			// 1) If skip slug
			if ( in_array( $slug, $skip_slugs ) ) {
				// Remove from original menu
				// Do not add under "More"
				continue;
			}

			// Convert submenu slug to "admin.php?page=" format:
			// For example "wc-settings" -> "admin.php?page=wc-settings"
			$temp[2] = 'admin.php?page=' . $temp[2];

			// If slug is in $to_move list (wc-settings, wc-reports etc.)
			if ( in_array( $slug, $to_move ) ) {

				if ( $temp[2] === 'admin.php?page=wc-settings' ) {
					array_push( $menu, $temp );
				} else {
					// Add under "More"
					$submenu['woocommerce-more'][] = $temp;
				}
				// Remove from original menu
				unset( $submenu_items[ $sub_key ] );
				continue;
			}

			// 3) New Woo submenu (not skip, not to_move)
			// -> automatically add under “More”
			$submenu['woocommerce-more'][] = $temp;

			// Remove from original menu
			unset( $submenu_items[ $sub_key ] );
		}

			// After loop, update original Woo submenu
			$submenu['woocommerce'] = $submenu_items;

			}
				$menu = brikpanel_move_item_after( $menu, 'woocommerce-more', 'woocommerce-marketing' );
				$menu = brikpanel_move_item_after( $menu, 'admin.php?page=wc-settings', 'woocommerce-marketing' );
				$menu = brikpanel_move_item_after( $menu, 'admin.php?page=wc-settings&tab=checkout', 'edit.php?post_type=product' );
				$menu = brikpanel_move_item_after( $menu, 'wf_woocommerce_packing_list', 'edit.php?post_type=product' );

				// BrikPanel custom analytics: Segments and Customer Analytics
				// sit directly under Products in the sidebar.
				$menu = brikpanel_move_item_after( $menu, 'brikpanel-segments', 'edit.php?post_type=product' );
				$menu = brikpanel_move_item_after( $menu, 'brikpanel-customer-analytics', 'brikpanel-segments' );
				$menu = brikpanel_move_item_after( $menu, 'brikpanel-google-sheets', 'brikpanel-customer-analytics' );
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

			// Loop through each top-level menu and build HTML.
			foreach ( $menu as $key => $item ) {
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

				$submenu_items = ! empty( $submenu[ $item_slug ] ) ? $submenu[ $item_slug ] : array();

				if ( ! empty( $submenu_items ) ) {
					$class[] = 'wp-has-submenu';
				}

		// Güvenli hale getirilen path, page ve tab değerleri
		$path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$tab  = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';

		// WooCommerce sayfaları için özel kontroller
		$viewing_add_product_page = $item_slug === 'edit.php?post_type=product' && strpos($path, '/add-product') !== false;
		$viewing_payments_page = $item_slug === 'wc-admin&path=/payments/overview' && strpos($path, '/payments') !== false;
		$viewing_analytics_page = $item_slug === 'wc-admin&path=/analytics/overview' && strpos($path, '/analytics') !== false;
		$viewing_marketing_page = $item_slug === 'woocommerce-marketing' && strpos($path, '/marketing') !== false;
		$viewing_more_page =
			strpos($item_slug, 'woocommerce-more') !== false &&
			(
				strpos($page, 'wc-reports') !== false ||
				strpos($page, 'wc-status') !== false ||
				strpos($path, '/extensions') !== false
			);

		$viewing_dashboard_page = $item_slug === 'index.php' && $page === 'brikpanel-dashboard';

		// Menü aktiflik durumu
		if (
			($parent_file && $item_slug === $parent_file) ||
			(empty($typenow) && $self === $item_slug) ||
			$viewing_add_product_page ||
			$viewing_payments_page ||
			$viewing_analytics_page ||
			$viewing_marketing_page ||
			$viewing_more_page ||
			$viewing_dashboard_page
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
		if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
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
			if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
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
			} elseif ( str_starts_with( $item[6], 'data:image/svg+xml;base64,' ) ) {
				$img = '<br />';
				$img_style = ' style="background-image:url(\'' . esc_attr( $item[6] ) . '\')"';
				$img_class = ' svg';
			} elseif ( str_starts_with( $item[6], 'dashicons-' ) ) {
				$img       = '<br />';
				$img_class = ' dashicons-before ' . sanitize_html_class( $item[6] );
			}
		}

		$title = wptexturize( $item[0] ?? '' );

		// Separator için erişilebilirlik ayarı:
		if ( $is_separator ) {
			$aria_hidden = ' aria-hidden="true"';
		}

		// Bazı özel başlıklar (örn: Edit Posts / Pages) için heading ekliyoruz (isteğe bağlı).
		// The anchor slug is dynamic — set by the nav customizer to the first
		// item the user has placed in the "Site management" section. Defaults
		// to 'edit.php' (the legacy hardcoded position) when no config exists.
		$heading = '';
		if ( ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
			$heading = $item_slug === $brikpanel_sitemgmt_anchor ? '<span class="brikpanel-menu-heading">' . __('Site management', 'brikpanel') . '<img class="brikpanel-site-management-toggle" src="' . plugins_url( 'icons/chevron-down.svg', __FILE__ ) . '" width="10" height="10"></span><div class="brikpanel-site-management-items">' : '';
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
			$custom_target = ! empty( $brikpanel_custom_meta['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';
			$icon_html     = '<img src="' . esc_url( plugins_url( 'icons/' . $custom_icon . '.svg', __FILE__ ) ) . '" width="15" height="18">';
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

		// Özel ikon atamaları:
		$has_custom_icon = array(
			'edit.php?post_type=product' => 'products',
			'brikpanel-segments' => 'payments',
			'brikpanel-customer-analytics' => 'credit-card',
			'brikpanel-google-sheets' => 'google-sheets',
			'brikpanel-vendors' => 'invoice',
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

		$icon = '';
		// User-defined icon override (from the customizer) takes precedence
		// over the built-in slug→icon map.
		if ( isset( $brikpanel_custom_icons[ $item_slug ] ) ) {
			$override_slug = $brikpanel_custom_icons[ $item_slug ];
			$icon = '<img src="' . esc_url( plugins_url( 'icons/' . $override_slug . '.svg', __FILE__ ) ) . '" width="15" height="18">';
		}
		if ( $icon === '' ) {
			foreach ( $has_custom_icon as $slug => $icon_file ) {
				if ( $item_slug === $slug ) {
					$icon = '<img src="' . plugins_url( 'icons/' . $icon_file . '.svg', __FILE__ ) . '" width="15" height="18">';
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
		} elseif ( $submenu_as_parent && ! empty( $submenu_items ) ) {
			// Alt menü var, ilk alt menü öğesini üst seviye gibi bağla:
			$submenu_items = array_values( $submenu_items );
			$menu_hook     = get_plugin_page_hook( $submenu_items[0][2], $item_slug );
			$menu_file     = $submenu_items[0][2];
			$pos           = strpos( $menu_file, '?' );

			if ( false !== $pos ) {
				$menu_file = substr( $menu_file, 0, $pos );
			}

			if (
				! empty( $menu_hook )
				|| (
					( 'index.php' !== $submenu_items[0][2] )
					&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
					&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" )
				)
			) {
				$admin_is_parent = true;
				if ( $item_slug !== 'woocommerce' || is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
					$style = $item_slug === 'meowapps-main-menu' ? 'style="padding-left: 24px;"' : '';
					$html .= "
						<div class='brikpanel-menu-icon-title-chevron-container'>
							<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
								$icon
								<a href='admin.php?page={$submenu_items[0][2]}' $class $style $aria_attributes>
									$title
								</a>
							</div>
					";
				}
			} else {
				$html .= "
					<div class='brikpanel-menu-icon-title-chevron-container'>
						<div class='brikpanel-menu-icon-title-container $toplevel_page_class'>
							$icon
							<a href='{$submenu_items[0][2]}' $class $aria_attributes>
								$title
							</a>
						</div>
				";
			}

			if ( ! empty( $submenu_items ) && ( $item_slug !== 'woocommerce' || is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) ) {
				$html .= '
						<img
							class="brikpanel-menu-chevron"
							src="' . plugins_url( 'icons/chevron-down.svg', __FILE__ ) . '"
							width="10"
							height="10"
						>
					</div>
				';
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
			if ( $item_slug === 'woocommerce' && ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap'>";
			} else {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap brikpanel-submenu'>";
			}

			$first = true;

			foreach ( $submenu_items as $sub_key => $sub_item ) {
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
					$sub_target = ! empty( $brikpanel_sub_custom['new_tab'] ) ? ' target="_blank" rel="noopener"' : '';
					$sub_title  = wptexturize( $sub_item[0] ?? '' );
					$sub_icon_html = '<img src="' . esc_url( plugins_url( 'icons/' . $sub_icon . '.svg', __FILE__ ) ) . '" width="12">';
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
					continue;
				}

				// ---------------------------------------------------
				// --- WooCommerce Home (wc-admin) alt menüsünü kaldır ---
				// ---------------------------------------------------
				if ( $sub_item_slug === 'wc-admin' ) {
					continue;
				}
				// ---------------------------------------------------

				if ( ! current_user_can( $sub_item[1] ) ) {
					continue;
				}

				// Daha önce "top level"e taşınan alt menüler burada atlanıyor.
				$moved_submenu_items = array( 'wc-reports', 'wc-settings', 'wc-status', 'wc-admin&path=/extensions' );
				if ( in_array( $sub_item_slug, $moved_submenu_items ) && ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
					continue;
				}

				$class           = array();
				$aria_attributes = '';

				if ( $first ) {
					$first = false;
				}

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

// Submenu için ek class'lar
if (!empty($sub_item[4])) {
    $class[] = esc_attr($sub_item[4]);
}


				$class = $class ? ' class="' . implode( ' ', $class ) . '"' : '';

				$menu_hook = get_plugin_page_hook( $sub_item_slug, $item_slug );
				$sub_file  = $sub_item_slug;
				$pos       = strpos( $sub_file, '?' );
				if ( false !== $pos ) {
					$sub_file = substr( $sub_file, 0, $pos );
				}

				$title = wptexturize( $sub_item[0] ?? '' );

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
				$icon = '<img src="' . plugins_url( 'icons/default.svg', __FILE__ ) . '" width="12">';

				foreach ( $woocommerce_submenu_has_custom_icon as $slug => $properties ) {
					if ( $sub_item_slug === $slug ) {
						$width = isset( $properties['width'] ) ? $properties['width'] : 15;
						$css   = isset( $properties['css'] ) ? $properties['css'] : '';
						$icon  = '<img
							src="' . plugins_url( 'icons/' . $properties['icon_file'] . '.svg', __FILE__ ) . '"
							width="' . $width . '"
							style="' . $css . '"
						>';
						break;
					}
				}
				if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
					$icon = '';
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
						$sub_item_url = add_query_arg( array( 'page' => $sub_item_slug ), $item_slug );
					} else {
						$sub_item_url = add_query_arg( array( 'page' => $sub_item_slug ), 'admin.php' );
					}

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
			}
			$html .= '</ul>';
		}

		$html .= '</li>';
	}

	if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		global $wp_menu_editor;
		if ( isset( $wp_menu_editor ) && $wp_menu_editor->load_custom_menu() ) {
			$wp_menu_editor->restore_wp_menu();
		}
	}

	return $html;
}

/**
 * Move WooCommerce top menus to the top (for quick access to orders, reports, etc.).
 */
function brikpanel_move_woocommerce_to_top( $menu_order ) {
	if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		return $menu_order;
	}

	$new_positions = array(
		'woocommerce',
		'brikmarket',
		'edit.php?post_type=product',
		'wc-admin&path=/wc-pay-welcome-page',
		'wc-admin&path=/payments/connect',
		'wc-admin&path=/payments/overview',
		'wc-admin&path=/analytics/overview',
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
 * Bir öğeyi, başka bir öğeden hemen sonra taşımak için yardımcı fonksiyon.
 */
function brikpanel_move_item_after( $array, $item_to_move, $after_item_value ) {
	$item_to_move_index = null;
	$after_item_index   = null;
	$item_to_move_item  = null;

	// Hangi index'lerin taşınacağını bul.
	foreach ( $array as $index => $inner_array ) {
		if ( $inner_array[2] === $item_to_move ) {
			$item_to_move_index = $index;
			$item_to_move_item  = $inner_array;
		}
		if ( $inner_array[2] === $after_item_value ) {
			$after_item_index = $index;
		}
	}

	if ( null === $item_to_move_index || null === $after_item_index ) {
		return $array;
	}

	unset( $array[ $item_to_move_index ] );

	if ( $item_to_move_index < $after_item_index ) {
		$after_item_index--;
	}

	if ( $after_item_index === count( $array ) - 1 ) {
		$array[] = $item_to_move_item;
	} else {
		$array = array_merge(
			array_slice( $array, 0, $after_item_index + 1 ),
			array( $item_to_move_item ),
			array_slice( $array, $after_item_index + 1 )
		);
	}

	return array_values( $array );
}
