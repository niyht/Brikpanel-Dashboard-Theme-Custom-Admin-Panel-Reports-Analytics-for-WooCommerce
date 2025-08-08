<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Eğer hpos (High-Performance Order Storage) aktif değilse, WooCommerce menüsünü özelleştirmek için CSS ekle
add_action('admin_head', function() {
    // HPOS aktif değilse ana WooCommerce başlığı ve ikonunu gizle
    if (get_option('woocommerce_custom_orders_table_enabled') !== 'yes') { ?>
        <style>
            /* WooCommerce ana başlığı ve ikonunu gizle */
            #toplevel_page_woocommerce > .brik82ad-menu-icon-title-chevron-container > .brik82ad-menu-icon-title-container {
                display: none !important;
            }
        </style>
    <?php }
});



/**
 * Menü sıralamasını elle değiştirmemizi sağlar.
 */
add_filter( 'custom_menu_order', '__return_true' );

/**
 * "Dashboard" (index.php) menüsünü en üste taşı.
 */
function brik82ad_move_dashboard_to_top( $menu_order ) {
	$dashboard_index = array_search( 'index.php', $menu_order );
	if ( $dashboard_index !== false ) {
		unset( $menu_order[ $dashboard_index ] );
		array_unshift( $menu_order, 'index.php' );
	}

	return $menu_order;
}
add_filter( 'menu_order', 'brik82ad_move_dashboard_to_top', 999 );

/**
 * Admin paneline özel menü yapısını yerleştiren fonksiyon.
 */
function brik82ad_render_navigation() {
	$items = brik82ad_get_navigation_items();
	echo '<nav id="brik82ad-navigation">';
	echo '    <ul>';
	echo        wp_kses_post($items);
	echo '    </ul>';
	echo '</nav>';
}
add_action( 'admin_footer', 'brik82ad_render_navigation' );

/**
 * WordPress global $menu ve $submenu dizilerini okuyup
 * özel HTML yapımızı oluşturan fonksiyon.
 */
function brik82ad_get_navigation_items( $submenu_as_parent = true ) {
	global $menu, $submenu, $self, $parent_file, $submenu_file, $plugin_page, $typenow;

	// Eğer Admin Menu Editor eklentisi aktifse, özel menüleri yükle.
	if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		global $wp_menu_editor;
		if ( isset( $wp_menu_editor ) && $wp_menu_editor->load_custom_menu() ) {
			$wp_menu_editor->replace_wp_menu();
		}
	}

	$html = '';

	// Admin Menu Editor yoksa "More" isimli sahte menü öğesi ekleniyor (WooCommerce alt menüleri oraya taşınabiliyor).
	if ( ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		
		// Gereksiz ve fazla woocommerce altındaki menüleri göndermek için more menüsünü ekliyoruz.
		$menu[] = array(
			__('More', 'brikpanel-admin-panel-dashboard-for-woocommerce'), // Menü başlığı
			'manage_woocommerce', // Yetki
			'woocommerce-more', // Slug
		);

		/*
        2- $item[2] demek menünün ikinci itemi demek değil, bunu başta yanlış anlamıştım, işin aslı şu:
        WordPress’te $menu dizisinin her bir elemanı bir dizidir ve sırasıyla şu bilgileri tutar:
	        • $item[0] → Menüde görünen isim
	        • $item[1] → Yetki (capability)
	        • $item[2] → Slug veya bağlantı (örn. “woocommerce”)
	        • $item[3] → Menü açıklaması/tooltip (opsiyonel)
	        • $item[4] → İkon (opsiyonel)
        Yani 2, menü dizisindeki slug’ın dizi içindeki yeridir.

        Yani aslında foreachle aldığımız menunun her bir itemi değil, menünün her bir iteminin her bir özelliği,
		bu herbiritemin her biri array yani aslında.
		*/
		foreach ( $menu as $key => $item ) {
			// Eğer menü öğesi WooCommerce değilse, atla. demin de anlattığım gibi, $item[2] menülerin slugını kontrol eder.
			if ( $item[2] !== 'woocommerce' ) {
				continue;
			}

			// WooCommerce menüsünü bulduktan sonra yaptığınız işlem.
			if ( ! empty( $submenu[ $item[2] ] ) ) {
    			$submenu_items = $submenu[ $item[2] ];
			} else {
    			$submenu_items = array();
			}

			// Taşınacaklar
			$to_move = array(
				'wc-settings',
				'wc-reports',
				'wc-status',
				'wc-admin&path=/extensions',
			);

			// İstemediğiniz sluglar
			$skip_slugs = array(
				'wc-orders',
				'edit.php?post_type=shop_order',
				'wc-orders--shop_subscription',
				'wc-admin&path=/customers'
			);

			foreach ( $submenu_items as $sub_key => $sub_item ) {
				// Alt menü öğesinin slug'ını alalım
				$slug = $sub_item[2];
				// Alt menü ögesini geçici değişkende tutalım
				$temp = $submenu_items[ $sub_key ];

				// 1) Skip slug’larsa
				if ( in_array( $slug, $skip_slugs ) ) {
					// Orijinal menüden çıkar
					// "More" altına da eklemiyoruz
					continue;
				}

				// Alt menü slug'ını "admin.php?page=" formatına dönüştür:
				// Örneğin "wc-settings" -> "admin.php?page=wc-settings"
				$temp[2] = 'admin.php?page=' . $temp[2];

				// Eğer slug $to_move listesinde varsa (wc-settings, wc-reports vb.)
				if ( in_array( $slug, $to_move ) ) {
					if ( $temp[2] === 'admin.php?page=wc-settings' ) {
						array_push( $menu, $temp );
					} else {
						// "More" altına ekle
						$submenu['woocommerce-more'][] = $temp;
					}
					// Orijinal menüden çıkar
					unset( $submenu_items[ $sub_key ] );
					continue;
				}

				// 3) Yeni bir Woo alt menüsü (ne skip ne to_move)
				// -> otomatik olarak “More” altına ekle
				$submenu['woocommerce-more'][] = $temp;

				// Orijinal menüden çıkar
				unset( $submenu_items[ $sub_key ] );
			}

			// Döngü bittiğinde, orijinal Woo alt menüsünü güncelleyin
			$submenu['woocommerce'] = $submenu_items;
		}
		$menu = brik82ad_move_item_after( $menu, 'woocommerce-more', 'woocommerce-marketing' );
		$menu = brik82ad_move_item_after( $menu, 'admin.php?page=wc-settings', 'woocommerce-marketing' );
		$menu = brik82ad_move_item_after( $menu, 'admin.php?page=wc-settings&tab=checkout', 'edit.php?post_type=product' );
		$menu = brik82ad_move_item_after( $menu, 'wf_woocommerce_packing_list', 'edit.php?post_type=product' );
	}

	$first = true;

	// Her bir üst seviye menüyü dönüp HTML oluşturuyoruz.
	foreach ( $menu as $key => $item ) {
		$admin_is_parent = false;
		$class           = array();
		$aria_attributes = '';
		$aria_hidden     = '';
		$is_separator    = false;

		if ( $first ) {
			$class[] = 'wp-first-item';
			$first   = false;
		}

		$submenu_items = ! empty( $submenu[ $item[2] ] ) ? $submenu[ $item[2] ] : array();

		if ( ! empty( $submenu_items ) ) {
			$class[] = 'wp-has-submenu';
		}

		// Güvenli hale getirilen path, page ve tab değerleri
		$path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$tab  = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';

		// WooCommerce sayfaları için özel kontroller
		$viewing_add_product_page = $item[2] === 'edit.php?post_type=product' && strpos($path, '/add-product') !== false;
		$viewing_payments_page = $item[2] === 'wc-admin&path=/payments/overview' && strpos($path, '/payments') !== false;
		$viewing_analytics_page = $item[2] === 'wc-admin&path=/analytics/overview' && strpos($path, '/analytics') !== false;
		$viewing_marketing_page = $item[2] === 'woocommerce-marketing' && strpos($path, '/marketing') !== false;
		$viewing_more_page =
			strpos($item[2], 'woocommerce-more') !== false &&
			(
				strpos($page, 'wc-reports') !== false ||
				strpos($page, 'wc-status') !== false ||
				strpos($path, '/extensions') !== false
			);

		// Menü aktiflik durumu
		if (
			($parent_file && $item[2] === $parent_file) ||
			(empty($typenow) && $self === $item[2]) ||
			$viewing_add_product_page ||
			$viewing_payments_page ||
			$viewing_analytics_page ||
			$viewing_marketing_page ||
			$viewing_more_page
		) {
			if (!empty($submenu_items)) {
				$class[] = 'brik82ad-has-open-submenu';
			} else {
				$class[] = 'brik82ad-current';
				$aria_attributes .= 'aria-current="page"';
			}
		} else {
			$class[] = 'wp-not-current-submenu';

			if ($item[2] === 'wc-admin&path=/wc-pay-welcome-page' && strpos($path, '/wc-pay') !== false) {
				$class[] = 'brik82ad-current';
			}
			if ($item[2] === 'wc-admin&path=/payments/connect' && strpos($path, '/payments/connect') !== false) {
				$class[] = 'brik82ad-current';
			}
			if ($item[2] === 'wc-admin&path=/payments/overview' && strpos($path, '/payments/overview') !== false) {
				$class[] = 'brik82ad-current';
			}

			if (strpos($item[2], 'wc-settings') !== false && $page === 'wc-settings') {
				if (strpos($item[2], 'tab=checkout') !== false && $tab === 'checkout') {
					$class[] = 'brik82ad-current';
				} else if (strpos($item[2], 'tab=checkout') === false && $tab !== 'checkout') {
					$class[] = 'brik82ad-current';
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

		$title = wptexturize( $item[0] );

		// Separator için erişilebilirlik ayarı:
		if ( $is_separator ) {
			$aria_hidden = ' aria-hidden="true"';
		}

		// Bazı özel başlıklar (örn: Edit Posts / Pages) için heading ekliyoruz (isteğe bağlı).
		$heading = '';
		if ( ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
			$heading = $item[2] === 'edit.php' ? '<span class="brik82ad-menu-heading">' . __( 'Site management', 'brikpanel-admin-panel-dashboard-for-woocommerce' ) . '</span>' : '';
		}

		$html .= "
			$heading
			<li $class $id $aria_hidden>
		";

		// Özel ikon atamaları:
		$has_custom_icon = array(
			'edit.php?post_type=product' => 'products',
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
		foreach ( $has_custom_icon as $slug => $icon_file ) {
			if ( $item[2] === $slug ) {
				$icon = '<img src="' . plugins_url( 'icons/' . $icon_file . '.svg', __FILE__ ) . '" width="15" height="18">';
				break;
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
			$menu_hook     = get_plugin_page_hook( $submenu_items[0][2], $item[2] );
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
				if ( $item[2] !== 'woocommerce' || is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
					$style = $item[2] === 'meowapps-main-menu' ? 'style="padding-left: 24px;"' : '';
					$html .= "
						<div class='brik82ad-menu-icon-title-chevron-container'>
							<div class='brik82ad-menu-icon-title-container $toplevel_page_class'>
								$icon
								<a href='admin.php?page={$submenu_items[0][2]}' $class $style $aria_attributes>
									$title
								</a>
							</div>
					";
				}
			} else {
				$html .= "
					<div class='brik82ad-menu-icon-title-chevron-container'>
						<div class='brik82ad-menu-icon-title-container $toplevel_page_class'>
							$icon
							<a href='{$submenu_items[0][2]}' $class $aria_attributes>
								$title
							</a>
						</div>
				";
			}

			if ( ! empty( $submenu_items ) && ( $item[2] !== 'woocommerce' || is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) ) {
				$html .= '
						<img
							class="brik82ad-menu-chevron"
							src="' . plugins_url( 'icons/chevron-down.svg', __FILE__ ) . '"
							width="10"
							height="10"
						>
					</div>
				';
			}
			$html .= '</a>';
		} elseif ( ! empty( $item[2] ) && current_user_can( $item[1] ) ) {
			// Alt menüsü yoksa veya alt menüleri parent yapmadıysak, doğrudan link veriyoruz.
			$menu_hook = get_plugin_page_hook( $item[2], 'admin.php' );
			$menu_file = $item[2];
			$pos       = strpos( $menu_file, '?' );

			if ( false !== $pos ) {
				$menu_file = substr( $menu_file, 0, $pos );
			}

			if (
				! empty( $menu_hook )
				|| (
					( 'index.php' !== $item[2] )
					&& file_exists( WP_PLUGIN_DIR . "/$menu_file" )
					&& ! file_exists( ABSPATH . "/wp-admin/$menu_file" )
				)
			) {
				$admin_is_parent = true;
				$html .= "
					<div class='brik82ad-menu-icon-title-container $toplevel_page_class'>
						{$icon}
						<a href='admin.php?page={$item[2]}' $class $aria_attributes>
							{$title}
						</a>
					</div>
				";
			} else {
				$html .= "
					<div class='brik82ad-menu-icon-title-container $toplevel_page_class'>
						{$icon}
						<a href='{$item[2]}' $class $aria_attributes>
							{$title}
						</a>
					</div>
				";
			}
		}

		// Alt menü render
		if ( ! empty( $submenu_items ) ) {
			if ( $item[2] === 'woocommerce' && ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap'>";
			} else {
				$html .= "\n\t<ul class='wp-submenu wp-submenu-wrap brik82ad-submenu'>";
			}

			$first = true;

			foreach ( $submenu_items as $sub_key => $sub_item ) {

				// ---------------------------------------------------
				// --- WooCommerce Home (wc-admin) alt menüsünü kaldır ---
				// ---------------------------------------------------
				if ( $sub_item[2] === 'wc-admin' ) {
					continue;
				}
				// ---------------------------------------------------

				if ( ! current_user_can( $sub_item[1] ) ) {
					continue;
				}

				// Daha önce "top level"e taşınan alt menüler burada atlanıyor.
				$moved_submenu_items = array( 'wc-reports', 'wc-settings', 'wc-status', 'wc-admin&path=/extensions' );
				if ( in_array( $sub_item[2], $moved_submenu_items ) && ! is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
					continue;
				}

				$class           = array();
				$aria_attributes = '';

				if ( $first ) {
					$first = false;
				}

				$menu_file = $item[2];
				$pos       = strpos( $menu_file, '?' );
				if ( false !== $pos ) {
					$menu_file = substr( $menu_file, 0, $pos );
				}

				// Güvenli hale getirilen GET değişkenleri
				$path = isset($_GET['path']) ? sanitize_text_field(wp_unslash($_GET['path'])) : '';
				$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

				// Alt menüde hangi sayfa açıksa aktif göstermek için kontroller
				if (isset($submenu_file)) {
					if ($submenu_file === $sub_item[2]) {
						$class[] = 'brik82ad-current';
						$aria_attributes .= ' aria-current="page"';
					}
				} elseif (
					(!isset($plugin_page) && $self === $sub_item[2])
					|| (
						isset($plugin_page) && $plugin_page === $sub_item[2]
						&& ($item[2] === (!empty($typenow) ? $self . '?post_type=' . $typenow : 'nothing')
							|| $item[2] === $self
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
						$class[] = 'brik82ad-current';
						$aria_attributes .= ' aria-current="page"';
					}
				}

				// Belirli sayfalarda menüyü aktif göster
				if ($sub_item[2] === 'wc-admin&path=/customers' && $path === '/customers') {
					$class[] = 'brik82ad-current';
				}
				if ($sub_item[2] === 'admin.php?page=wc-admin&path=/add-product' && $path === '/add-product') {
					$class[] = 'brik82ad-current';
				}
				if ($sub_item[2] === 'wc-admin&path=/extensions' && $path === '/extensions') {
					$class[] = 'brik82ad-current';
				}

				// WooCommerce raporlar ve durum sayfaları
				if (strpos($sub_item[2], 'wc-reports') !== false && $page === 'wc-reports') {
					$class[] = 'brik82ad-current';
				}
				if (strpos($sub_item[2], 'wc-status') !== false && $page === 'wc-status') {
					$class[] = 'brik82ad-current';
				}
				if (strpos($sub_item[2], '/extensions') !== false && $path === '/extensions') {
					$class[] = 'brik82ad-current';
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

				if (isset($analytics_slugs[$sub_item[2]]) && $path === $analytics_slugs[$sub_item[2]]) {
					$class[] = 'brik82ad-current';
				}

				// WooCommerce Marketing sayfası
				if ($sub_item[2] === 'admin.php?page=wc-admin&path=/marketing' && $path === '/marketing') {
					$class[] = 'brik82ad-current';
					$class[] = 'brik82ad-has-open-submenu';
				}

				// Submenu için ek class'lar
				if (!empty($sub_item[4])) {
					$class[] = esc_attr($sub_item[4]);
				}

				$class = $class ? ' class="' . implode( ' ', $class ) . '"' : '';

				$menu_hook = get_plugin_page_hook( $sub_item[2], $item[2] );
				$sub_file  = $sub_item[2];
				$pos       = strpos( $sub_file, '?' );
				if ( false !== $pos ) {
					$sub_file = substr( $sub_file, 0, $pos );
				}

				$title = wptexturize( $sub_item[0] );

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
					if ( $sub_item[2] === $slug ) {
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
						( 'index.php' !== $sub_item[2] )
						&& file_exists( WP_PLUGIN_DIR . "/$sub_file" )
						&& ! file_exists( ABSPATH . "/wp-admin/$sub_file" )
					)
				) {
					if (
						( ! $admin_is_parent && file_exists( WP_PLUGIN_DIR . "/$menu_file" ) && ! is_dir( WP_PLUGIN_DIR . "/{$item[2]}" ) )
						|| file_exists( $menu_file )
					) {
						$sub_item_url = add_query_arg( array( 'page' => $sub_item[2] ), $item[2] );
					} else {
						$sub_item_url = add_query_arg( array( 'page' => $sub_item[2] ), 'admin.php' );
					}

					$sub_item_url = esc_url( $sub_item_url );

					$html .= "
						<li$class>
							<div class='brik82ad-menu-icon-title-container'>
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
							<div class='brik82ad-menu-icon-title-container'>
								$icon
								<a href='{$sub_item[2]}' $class $aria_attributes>
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
 * WooCommerce üst menülerini en üste taşıma (sipariş, rapor vb. hızlı erişim için).
 */
function brik82ad_move_woocommerce_to_top( $menu_order ) {
	if ( is_plugin_active( 'admin-menu-editor/menu-editor.php' ) ) {
		return $menu_order;
	}

	$new_positions = array(
		'woocommerce',
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
add_filter( 'menu_order', 'brik82ad_move_woocommerce_to_top' );

/**
 * Bir öğeyi, başka bir öğeden hemen sonra taşımak için yardımcı fonksiyon.
 */
function brik82ad_move_item_after( $array, $item_to_move, $after_item_value ) {
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


