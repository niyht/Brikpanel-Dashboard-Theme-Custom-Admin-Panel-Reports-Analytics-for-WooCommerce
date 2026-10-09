<?php
/**
 * BrikPanel screens a setting can switch off.
 *
 * Field test C5: a module that is off does not register its admin page at all,
 * so its address (a bookmark, an old link, a link inside BrikPanel) reached
 * WordPress's access check before any admin chrome and ended on the bare
 * "Sorry, you are not allowed to access this page." screen, even for the
 * administrator who could turn the module back on.
 *
 * This file keeps one list of those screens. A request for one of them while it
 * is off is sent to a small BrikPanel page that says so and, for someone who may
 * open the settings, links straight to the switch. Everything that is not "a
 * module this user may use is switched off" still gets WordPress's own 403: a
 * user without the capability, and a user the multisite network keeps out.
 *
 * Links from one BrikPanel screen to another go through
 * brikpanel_module_url(), so they never point at a screen that is off.
 *
 * @package BrikPanel
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BRIKPANEL_MODULE_OFF_PAGE' ) ) {
	define( 'BRIKPANEL_MODULE_OFF_PAGE', 'brikpanel-module-off' );
}

/**
 * The screens a setting can switch off, keyed by page slug.
 *
 * Each entry:
 * - label:    the screen's name, as the sidebar shows it
 * - enabled:  callable, true while the screen is on
 * - cap:      the capability the screen registers with
 * - section:  WooCommerce > Settings > BrikPanel section holding the switch
 *             ('' is General; null when the screen has no switch there)
 * - option:   the switch's field id, used to jump to and highlight it
 * - fallback: where to send someone who opens the screen while it is off
 *             (the stock WooCommerce screen when there is one)
 * - fallback_label: text of the link to that place
 *
 * @return array<string,array<string,mixed>>
 */
function brikpanel_module_pages() {
	$yes = static function ( $option, $default = 'yes' ) {
		return static function () use ( $option, $default ) {
			return get_option( $option, $default ) === 'yes';
		};
	};

	$back_home = __( 'Back to the dashboard', 'brikpanel' );

	$pages = [
		'brikpanel-dashboard'     => [
			'label'          => __( 'Dashboard', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_modern_dashboard' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'dashboard',
			'option'         => 'brikpanel_modern_dashboard',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-products'      => [
			'label'          => __( 'Products', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_modern_products_list' ),
			'cap'            => 'edit_products',
			'section'        => 'products',
			'option'         => 'brikpanel_modern_products_list',
			'fallback'       => admin_url( 'edit.php?post_type=product' ),
			'fallback_label' => __( 'Open the WooCommerce product list', 'brikpanel' ),
		],
		'brikpanel-coupons'       => [
			'label'          => __( 'Coupons', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_modern_coupons' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'coupons',
			'option'         => 'brikpanel_modern_coupons',
			'fallback'       => admin_url( 'edit.php?post_type=shop_coupon' ),
			'fallback_label' => __( 'Open the WooCommerce coupon list', 'brikpanel' ),
		],
		'brikpanel-segments'      => [
			'label'          => __( 'Segments', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_modern_segments' ),
			'cap'            => 'manage_woocommerce',
			'section'        => null,
			'option'         => '',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-vendors'       => [
			'label'          => __( 'Suppliers', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_vendors_enabled', 'no' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'vendors',
			'option'         => 'brikpanel_vendors_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-stock-orders'  => [
			'label'          => __( 'Stock Orders', 'brikpanel' ),
			'enabled'        => static function () {
				return get_option( 'brikpanel_vendors_enabled', 'no' ) === 'yes'
					&& get_option( 'brikpanel_stock_orders_enabled', 'yes' ) === 'yes';
			},
			'cap'            => 'manage_woocommerce',
			'section'        => 'vendors',
			'option'         => get_option( 'brikpanel_vendors_enabled', 'no' ) === 'yes' ? 'brikpanel_stock_orders_enabled' : 'brikpanel_vendors_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-google-sheets' => [
			'label'          => __( 'Google Sheets', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_gs_module_enabled' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'integrations',
			'option'         => 'brikpanel_gs_module_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-ad-platforms'  => [
			'label'          => __( 'Ad Platforms', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_ads_module_enabled' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'integrations',
			'option'         => 'brikpanel_ads_module_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-brikcontrol'   => [
			'label'          => __( 'Store Health', 'brikpanel' ),
			'enabled'        => $yes( 'brikpanel_brikcontrol_enabled' ),
			'cap'            => 'manage_woocommerce',
			'section'        => 'store-health',
			'option'         => 'brikpanel_brikcontrol_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-cart-share'    => [
			'label'          => __( 'Cart share', 'brikpanel' ),
			'enabled'        => static function () {
				return get_option( 'brikpanel_cart_share_enabled', 'yes' ) !== 'no';
			},
			'cap'            => 'manage_woocommerce',
			'section'        => 'cart-share',
			'option'         => 'brikpanel_cart_share_enabled',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
		'brikpanel-brikmentor'    => [
			'label'          => 'BrikMentor',
			'enabled'        => static function () {
				return function_exists( 'brikpanel_brikmentor_promo_active' ) && brikpanel_brikmentor_promo_active();
			},
			'cap'            => 'manage_woocommerce',
			'section'        => ( function_exists( 'brikpanel_brikmentor_promo_is_pinned' ) && brikpanel_brikmentor_promo_is_pinned() ) ? null : '',
			'option'         => 'brikpanel_brikmentor_live',
			'fallback'       => admin_url( 'index.php' ),
			'fallback_label' => $back_home,
		],
	];

	/**
	 * Filters the BrikPanel screens a setting can switch off.
	 *
	 * @param array<string,array<string,mixed>> $pages Keyed by page slug.
	 */
	return (array) apply_filters( 'brikpanel_module_pages', $pages );
}

/**
 * One registry entry, or null for a slug that is not listed.
 *
 * @param string $slug Page slug.
 * @return array<string,mixed>|null
 */
function brikpanel_module_page( $slug ) {
	$pages = brikpanel_module_pages();
	return isset( $pages[ $slug ] ) && is_array( $pages[ $slug ] ) ? $pages[ $slug ] : null;
}

/**
 * Whether a listed screen is switched on.
 *
 * @param string $slug Page slug.
 * @return bool True for a slug that is not listed (nothing switches it off).
 */
function brikpanel_module_enabled( $slug ) {
	$page = brikpanel_module_page( $slug );
	if ( ! $page ) {
		return true;
	}
	return ! empty( $page['enabled'] ) && is_callable( $page['enabled'] ) ? (bool) call_user_func( $page['enabled'] ) : true;
}

/**
 * Whether the current user can open a BrikPanel screen right now: it is on, the
 * user has its capability, and the multisite network lets the user use
 * BrikPanel.
 *
 * @param string $slug Page slug.
 * @return bool
 */
function brikpanel_module_available( $slug ) {
	$page = brikpanel_module_page( $slug );
	if ( $page && ! current_user_can( (string) $page['cap'] ) ) {
		return false;
	}
	if ( function_exists( 'brikpanel_user_can_access' ) && ! brikpanel_user_can_access() ) {
		return false;
	}
	return brikpanel_module_enabled( $slug );
}

/**
 * The address of a BrikPanel screen, or a fallback while it is off.
 *
 * @param string $slug     Page slug.
 * @param array  $args     Extra query arguments for the screen.
 * @param string $fallback Address to use while the screen is off. Empty: the
 *                         registry's fallback. Pass '' and check the result for
 *                         '' when the link should not be printed at all.
 * @param bool   $use_registry_fallback False to get '' instead of the
 *                         registry's fallback.
 * @return string
 */
function brikpanel_module_url( $slug, $args = [], $fallback = '', $use_registry_fallback = true ) {
	if ( brikpanel_module_available( $slug ) ) {
		return add_query_arg( array_merge( [ 'page' => $slug ], (array) $args ), admin_url( 'admin.php' ) );
	}
	if ( $fallback !== '' ) {
		return $fallback;
	}
	if ( ! $use_registry_fallback ) {
		return '';
	}
	$page = brikpanel_module_page( $slug );
	return $page && ! empty( $page['fallback'] ) ? (string) $page['fallback'] : admin_url( 'index.php' );
}

/**
 * The settings address that shows a screen's switch, or '' when there is no
 * switch in BrikPanel's settings or the user may not open them.
 *
 * @param array $page Registry entry.
 * @return string
 */
function brikpanel_module_settings_url( $page ) {
	if ( ! is_array( $page ) || ! array_key_exists( 'section', $page ) || $page['section'] === null ) {
		return '';
	}
	if ( ! function_exists( 'brikpanel_user_can_open_settings' ) || ! brikpanel_user_can_open_settings() ) {
		return '';
	}
	$args = [ 'page' => 'wc-settings', 'tab' => 'brikpanel' ];
	if ( (string) $page['section'] !== '' ) {
		$args['section'] = (string) $page['section'];
	}
	$url = add_query_arg( $args, admin_url( 'admin.php' ) );
	// The settings page's search jump scrolls to the field and highlights it.
	if ( ! empty( $page['option'] ) ) {
		$url .= '#bp-jump-' . rawurlencode( (string) $page['option'] );
	}
	return $url;
}

/**
 * Send a request for a switched-off screen to the page that explains it.
 *
 * WordPress fires this hook from wp-admin/includes/menu.php when the requested
 * page is not one the user can open, right before wp_die( 403 ). Nothing has
 * been printed yet, so a redirect still works. Priority 5: before the nav
 * customizer's handler for its own synthetic slugs (a different slug set).
 *
 * @return void
 */
function brikpanel_module_off_redirect() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing on the requested page slug.
	$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $slug === '' || $slug === BRIKPANEL_MODULE_OFF_PAGE ) {
		return;
	}
	$page = brikpanel_module_page( $slug );
	if ( ! $page ) {
		return;
	}
	// These are site screens. In the network or user admin they never exist,
	// on or off: WordPress's answer stands there.
	if ( is_network_admin() || is_user_admin() ) {
		return;
	}
	// Keep WordPress's 403 for someone the screen was never meant for, and for
	// a user the multisite network keeps out of BrikPanel on purpose.
	if ( ! current_user_can( (string) $page['cap'] ) ) {
		return;
	}
	if ( function_exists( 'brikpanel_user_can_access' ) && ! brikpanel_user_can_access() ) {
		return;
	}

	// BrikMentor itself is installed: its own screen replaces the promotion.
	if ( $slug === 'brikpanel-brikmentor' && function_exists( 'brikpanel_brikmentor_installed' ) && brikpanel_brikmentor_installed()
		&& class_exists( 'Brikmentor_Admin', false ) && defined( 'Brikmentor_Admin::MENU_SLUG' ) ) {
		wp_safe_redirect( add_query_arg( 'page', (string) constant( 'Brikmentor_Admin::MENU_SLUG' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// On, yet not registered: some other reason (a per-user rule removed it).
	// That is not this page's story; WordPress's answer stands.
	if ( brikpanel_module_enabled( $slug ) && ! ( function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize() ) ) {
		return;
	}

	wp_safe_redirect( add_query_arg(
		[ 'page' => BRIKPANEL_MODULE_OFF_PAGE, 'module' => $slug ],
		admin_url( 'admin.php' )
	) );
	exit;
}
add_action( 'admin_page_access_denied', 'brikpanel_module_off_redirect', 5 );

/**
 * The module requested on the explanation page, when it is listed and the user
 * has its capability; null otherwise.
 *
 * @return array{slug:string,page:array<string,mixed>}|null
 */
function brikpanel_module_off_requested() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page parameter.
	$slug = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
	$page = $slug !== '' ? brikpanel_module_page( $slug ) : null;
	if ( ! $page || ! current_user_can( (string) $page['cap'] ) ) {
		return null;
	}
	return [ 'slug' => $slug, 'page' => $page ];
}

/**
 * Register the explanation page. It has no menu entry: it is only ever reached
 * through the redirect above.
 *
 * @return void
 */
function brikpanel_module_off_register_page() {
	$hook = add_submenu_page(
		'',
		__( 'Turned off', 'brikpanel' ),
		'',
		'read',
		BRIKPANEL_MODULE_OFF_PAGE,
		'brikpanel_module_off_render_page'
	);
	if ( ! $hook ) {
		return;
	}
	add_action(
		'load-' . $hook,
		static function () {
			$req = brikpanel_module_off_requested();
			if ( ! $req ) {
				wp_safe_redirect( admin_url( 'index.php' ) );
				exit;
			}
			// Switched back on (in another tab, say): open the screen itself.
			$neutral = function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize();
			if ( ! $neutral && brikpanel_module_available( $req['slug'] ) ) {
				wp_safe_redirect( add_query_arg( 'page', $req['slug'], admin_url( 'admin.php' ) ) );
				exit;
			}
			// Set before admin-header.php runs, or WordPress prints an empty title.
			$GLOBALS['title'] = (string) $req['page']['label'];
		}
	);
}
add_action( 'admin_menu', 'brikpanel_module_off_register_page', 30 );

/**
 * Render the explanation card.
 *
 * @return void
 */
function brikpanel_module_off_render_page() {
	$req = brikpanel_module_off_requested();
	if ( ! $req ) {
		return;
	}
	$page    = $req['page'];
	$label   = (string) $page['label'];
	$neutral = function_exists( 'brikpanel_access_should_neutralize' ) && brikpanel_access_should_neutralize();

	if ( $neutral ) {
		/* translators: %s: screen name, e.g. "Suppliers". */
		$title    = sprintf( __( '%s is not available', 'brikpanel' ), $label );
		$text     = __( 'The BrikPanel interface is switched off for your account, so this page is not available.', 'brikpanel' );
		$settings = '';
	} else {
		/* translators: %s: screen name, e.g. "Suppliers". */
		$title    = sprintf( __( '%s is turned off', 'brikpanel' ), $label );
		$settings = brikpanel_module_settings_url( $page );
		if ( $settings !== '' ) {
			$text = __( 'This page is turned off in the BrikPanel settings. Turn it on there and this address works again.', 'brikpanel' );
		} elseif ( $page['section'] === null ) {
			$text = __( 'This page is turned off on this store.', 'brikpanel' );
		} else {
			$text = __( 'This page is turned off in the BrikPanel settings. Ask your store administrator to turn it on.', 'brikpanel' );
		}
	}
	$fallback       = ! empty( $page['fallback'] ) ? (string) $page['fallback'] : admin_url( 'index.php' );
	$fallback_label = ! empty( $page['fallback_label'] ) ? (string) $page['fallback_label'] : __( 'Back to the dashboard', 'brikpanel' );

	$actions = [];
	if ( $settings !== '' ) {
		$actions[] = [
			'label'   => __( 'Turn it on in Settings', 'brikpanel' ),
			'url'     => $settings,
			'primary' => true,
		];
	}
	$actions[] = [
		'label'   => $fallback_label,
		'url'     => $fallback,
		'primary' => $settings === '',
	];

	brikpanel_render_notice_card( [
		'icon'    => 'power',
		'title'   => $title,
		'text'    => $text,
		'actions' => $actions,
	] );
}

/**
 * The explanation card BrikPanel shows in place of a page the user cannot
 * open here: a module switched off (above), or a page the Navigation rules
 * close (front-end/navigation/brikpanel-nav-page-access.php). One card, so the
 * two never drift apart.
 *
 * @param array{icon?:string,title:string,text:string,actions:array<int,array{label:string,url:string,primary?:bool}>} $card
 *        icon: 'power' (switched off) or 'lock' (closed to this user).
 * @return void
 */
function brikpanel_render_notice_card( array $card ) {
	$title   = isset( $card['title'] ) ? (string) $card['title'] : '';
	$text    = isset( $card['text'] ) ? (string) $card['text'] : '';
	$icon    = ( isset( $card['icon'] ) && 'lock' === $card['icon'] ) ? 'lock' : 'power';
	$actions = ( isset( $card['actions'] ) && is_array( $card['actions'] ) ) ? $card['actions'] : [];

	// Styles inline, scoped to this page: for a user BrikPanel is switched off
	// for, the asset sweep removes every BrikPanel stylesheet.
	?>
	<style>
		.brikpanel-module-off { max-width: 560px; margin: 3rem auto 2rem; padding: 0 1rem; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.brikpanel-module-off .brikpanel-module-off__card { display: flex; flex-direction: column; align-items: flex-start; gap: .75rem; padding: 2rem 2rem 1.75rem; background: #fff; border: 1px solid #e3e3e3; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
		.brikpanel-module-off .brikpanel-module-off__icon { display: inline-flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: 999px; background: #f1f1f1; color: #616161; }
		.brikpanel-module-off .brikpanel-module-off__title { margin: .25rem 0 0; padding: 0; font-size: 1.125rem; font-weight: 600; line-height: 1.3; color: #303030; }
		.brikpanel-module-off .brikpanel-module-off__text { margin: 0; font-size: .875rem; line-height: 1.5; color: #616161; }
		.brikpanel-module-off .brikpanel-module-off__actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .5rem; }
		.brikpanel-module-off .brikpanel-module-off__btn { display: inline-flex; align-items: center; padding: .5rem 1rem; border-radius: .5rem; font-size: .8125rem; font-weight: 550; line-height: 1.4; text-decoration: none; background: #fff; color: #303030; box-shadow: inset 0 0 0 1px #e3e3e3, 0 1px 0 rgba(0, 0, 0, .05); }
		.brikpanel-module-off .brikpanel-module-off__btn:hover { background: #f7f7f7; color: #303030; }
		.brikpanel-module-off .brikpanel-module-off__btn:focus-visible { outline: 2px solid #303030; outline-offset: 2px; box-shadow: none; }
		.brikpanel-module-off .brikpanel-module-off__btn--primary { background: #303030; color: #fff; box-shadow: inset 0 -1px 0 rgba(0, 0, 0, .2), inset 0 1px 0 rgba(255, 255, 255, .1); }
		.brikpanel-module-off .brikpanel-module-off__btn--primary:hover { background: #1a1a1a; color: #fff; }
		/* In the narrow page shell (brikpanel_shell_pages()) the shell gives the
		   page its width and one symmetric gutter; the card keeps its own width
		   and some air above it. Without the shell (BrikPanel switched off for
		   this user) the rules above lay the page out alone. */
		body.brikpanel-shell .brikpanel-module-off .brikpanel-module-off__card { box-sizing: border-box; max-width: 560px; margin: 1.75rem auto 0; }
		@media (max-width: 600px) {
			.brikpanel-module-off { margin-top: 1.5rem; padding: 0; }
			.brikpanel-module-off .brikpanel-module-off__card { padding: 1.5rem 1.25rem 1.25rem; }
			body.brikpanel-shell .brikpanel-module-off .brikpanel-module-off__card { margin-top: .5rem; }
		}
	</style>
	<div class="wrap brikpanel-module-off brikpanel-shell__page">
		<?php
		// The page title for assistive tech and the notice marker come first,
		// so WordPress puts any notice above the card, not inside it (B5).
		?>
		<h1 class="screen-reader-text"><?php echo esc_html( $title ); ?></h1>
		<?php brikpanel_header_end(); ?>
		<div class="brikpanel-module-off__card">
			<span class="brikpanel-module-off__icon" aria-hidden="true">
				<?php if ( 'lock' === $icon ) : ?>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
				<?php else : ?>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
				<?php endif; ?>
			</span>
			<h2 class="brikpanel-module-off__title" aria-hidden="true"><?php echo esc_html( $title ); ?></h2>
			<p class="brikpanel-module-off__text"><?php echo esc_html( $text ); ?></p>
			<div class="brikpanel-module-off__actions">
				<?php foreach ( $actions as $action ) : ?>
					<?php
					if ( ! is_array( $action ) || empty( $action['url'] ) || ! isset( $action['label'] ) ) {
						continue;
					}
					?>
					<a class="brikpanel-module-off__btn<?php echo ! empty( $action['primary'] ) ? ' brikpanel-module-off__btn--primary' : ''; ?>" href="<?php echo esc_url( (string) $action['url'] ); ?>"><?php echo esc_html( (string) $action['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
