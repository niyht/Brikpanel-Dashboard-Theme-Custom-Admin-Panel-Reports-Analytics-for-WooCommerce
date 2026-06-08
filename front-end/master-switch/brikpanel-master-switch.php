<?php
/**
 * BrikPanel - Master interface switch (UI + AJAX).
 *
 * A single store-wide on/off control for the entire BrikPanel interface,
 * surfaced two ways for administrators only:
 *
 *   1. A toggle switch inside the BrikPanel top bar — the in-context control
 *      while BrikPanel is on (rendered from the top bar via
 *      render_topbar_switch()).
 *   2. A node in the native WordPress admin bar — the always-reachable control,
 *      so once BrikPanel is switched off (top bar gone) it can still be turned
 *      back on from the stock admin chrome.
 *
 * Both surfaces post to the same AJAX endpoint, which flips
 * BRIKPANEL_MASTER_OPT and reloads. The actual neutralization when the switch
 * is off is owned by the access-control layer
 * (includes/brikpanel-access-control.php) via brikpanel_master_enabled(): every
 * gated interface option is forced to "no" for every back-office user and the
 * BrikPanel assets / menu entries are swept, handing the whole store the stock
 * WordPress / WooCommerce admin. This module only owns the controls and the
 * endpoint.
 *
 * The control's styles + behaviour are printed inline (not enqueued) on
 * purpose: the access-control asset sweep dequeues every BrikPanel-owned
 * style/script when the switch is off, which would otherwise strip the one
 * control that turns BrikPanel back on. Inline output survives the sweep.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Master_Switch {

	const NONCE_ACTION = 'brikpanel_master_switch';
	const AJAX_ACTION  = 'brikpanel_master_toggle';

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Endpoint that flips the option. Registered unconditionally so it is
		// always available on admin-ajax, in either state.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_toggle' ] );

		// Native admin-bar entry. Late priority so it sits at the far right of
		// the bar. Visible whenever the BrikPanel top bar is not covering the
		// bar (panel off, or top bar disabled / on a block-editor screen).
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_node' ], 999 );

		// Inline styles + behaviour for both surfaces, printed in the footer so
		// the access-control asset sweep can never strip them.
		add_action( 'admin_print_footer_scripts', [ $this, 'print_inline_assets' ], 1 );
	}

	// =========================================================================
	// CAPABILITY / CONTEXT
	// =========================================================================

	/**
	 * Only administrators (manage_options) may see and flip the switch. Shop
	 * managers, who hold manage_woocommerce but not manage_options, never see
	 * the control even though the switch affects their interface too.
	 *
	 * @return bool
	 */
	public static function user_can_toggle() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current request is an admin screen where the controls should
	 * be wired up (not AJAX, not the network / user admin contexts).
	 *
	 * @return bool
	 */
	private function eligible_context() {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( is_network_admin() || is_user_admin() ) {
			return false;
		}
		return self::user_can_toggle();
	}

	// =========================================================================
	// AJAX
	// =========================================================================

	/**
	 * Flip the master switch. Expects POST `enable` = '1' | '0'. Administrator
	 * only, nonce protected.
	 */
	public function ajax_toggle() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed. Please reload and try again.', 'brikpanel' ) ], 400 );
		}
		if ( ! self::user_can_toggle() ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to change this.', 'brikpanel' ) ], 403 );
		}

		$enable = isset( $_POST['enable'] ) && '1' === (string) wp_unslash( $_POST['enable'] );
		update_option( BRIKPANEL_MASTER_OPT, $enable ? 'yes' : 'no' );

		wp_send_json_success( [ 'enabled' => $enable ] );
	}

	// =========================================================================
	// SURFACE 1 — BRIKPANEL TOP BAR SWITCH
	// =========================================================================

	/**
	 * Render the on/off switch inside the BrikPanel top bar. Called from the
	 * top bar's render(); the top bar only renders while the panel is on, so
	 * this switch is always shown in its "on" position and clicking it turns
	 * BrikPanel off store-wide.
	 *
	 * Administrator only — a non-admin viewing the top bar (e.g. a shop manager)
	 * never sees the control.
	 */
	public function render_topbar_switch() {
		if ( ! self::user_can_toggle() ) {
			return;
		}

		$tooltip = __( 'BrikPanel is on for the whole store. Switch off to use the classic WordPress admin.', 'brikpanel' );
		?>
		<div class="brikpanel-topbar-masterswitch" title="<?php echo esc_attr( $tooltip ); ?>">
			<svg class="brikpanel-topbar-masterswitch-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
			<button type="button"
					class="brikpanel-masterswitch-toggle brikpanel-masterswitch-trigger is-on"
					role="switch"
					aria-checked="true"
					aria-label="<?php echo esc_attr( $tooltip ); ?>">
				<span class="brikpanel-masterswitch-knob" aria-hidden="true"></span>
			</button>
		</div>
		<?php
	}

	// =========================================================================
	// SURFACE 2 — NATIVE WP ADMIN BAR NODE
	// =========================================================================

	/**
	 * Add the master-switch entry to the native WordPress admin bar. This is the
	 * always-reachable control: while BrikPanel is off its top bar is gone, but
	 * the stock admin bar (and this node) is shown, so it can be switched back
	 * on. While BrikPanel is on with the top bar active the stock admin bar is
	 * hidden by CSS, so this node simply does not show — the top bar switch
	 * takes over.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public function add_admin_bar_node( $bar ) {
		if ( ! is_admin() ) {
			return;
		}
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}
		if ( ! self::user_can_toggle() ) {
			return;
		}
		if ( ! $bar instanceof WP_Admin_Bar ) {
			return;
		}

		$on    = brikpanel_master_enabled();
		$label = $on ? __( 'BrikPanel on', 'brikpanel' ) : __( 'BrikPanel off', 'brikpanel' );
		$hint  = $on
			? __( 'Switch BrikPanel off and use the classic WordPress admin', 'brikpanel' )
			: __( 'Switch the BrikPanel interface back on', 'brikpanel' );

		// A power glyph (green when on, grey when off) carried in WP's own
		// .ab-icon slot, so on phones — where WP shows admin-bar icons but hides
		// their text — the control stays recognizable instead of collapsing to a
		// bare dot. The label rides alongside on wider screens.
		$power_svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>';
		$title     = sprintf(
			'<span class="ab-icon brikpanel-ab-icon %1$s" aria-hidden="true">%2$s</span><span class="ab-label">%3$s</span>',
			$on ? 'is-on' : 'is-off',
			$power_svg,
			esc_html( $label )
		);

		$bar->add_node( [
			'id'    => 'brikpanel-master',
			'title' => $title, // Built from esc_html() above; classes are static.
			'href'  => '#',
			'meta'  => [
				'class'        => 'brikpanel-ab-masterswitch brikpanel-masterswitch-trigger',
				'title'        => $hint,
				'tabindex'     => 0,
			],
		] );
	}

	// =========================================================================
	// INLINE STYLES + BEHAVIOUR
	// =========================================================================

	/**
	 * Print the styles, the localized config, and the click behaviour shared by
	 * both surfaces. Printed inline so it survives the access-control asset
	 * sweep that runs while the switch is off.
	 */
	public function print_inline_assets() {
		if ( ! $this->eligible_context() ) {
			return;
		}

		$cfg = wp_json_encode( [
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'action'       => self::AJAX_ACTION,
			'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
			'enabled'      => brikpanel_master_enabled(),
			// Fallback landing page when switching off from a BrikPanel-only
			// screen (page=brikpanel-*): that screen ceases to exist once the
			// panel is off, so we send the admin to the stock WP dashboard
			// instead of reloading a now-inaccessible URL.
			'dashboardUrl' => admin_url( 'index.php' ),
			'i18n'         => [
				'error' => __( 'Could not change the BrikPanel switch. Please try again.', 'brikpanel' ),
			],
		] );
		?>
<style id="brikpanel-masterswitch-css">
/* Everything is inline (not in the swept topbar stylesheet) so both surfaces
   are styled without depending on a cache-busting version bump, and so the one
   control that turns BrikPanel back on is always styled while the panel is off
   and the topbar CSS has been swept. */
/* ---- In-top-bar switch (shown while BrikPanel is on) ---- */
.brikpanel-topbar-masterswitch{display:inline-flex;align-items:center;gap:6px;padding:0 4px;height:32px;flex:0 0 auto;color:var(--bp-topbar-text-3,#8a8a8a);}
.brikpanel-topbar-masterswitch-icon{flex:0 0 auto;}
.brikpanel-masterswitch-toggle{position:relative;display:inline-block;width:40px;height:22px;flex:0 0 auto;border:0;padding:0;margin:0;border-radius:22px;background:#cccccc;cursor:pointer;transition:background .15s ease;-webkit-appearance:none;appearance:none;}
.brikpanel-masterswitch-toggle.is-on{background:var(--bp-topbar-primary,#303030);}
.brikpanel-masterswitch-toggle:focus-visible{outline:2px solid var(--bp-topbar-primary,#303030);outline-offset:2px;}
.brikpanel-masterswitch-knob{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#ffffff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .15s ease;}
.brikpanel-masterswitch-toggle.is-on .brikpanel-masterswitch-knob{transform:translateX(18px);}
.brikpanel-masterswitch-toggle.is-busy{opacity:.55;cursor:default;pointer-events:none;}
/* Phones: drop the decorative power glyph and tighten, but keep the switch
   reachable — turning BrikPanel on/off must work on mobile too. */
@media screen and (max-width:782px){
	.brikpanel-topbar-masterswitch{gap:0;padding:0 2px;}
	.brikpanel-topbar-masterswitch-icon{display:none;}
}
/* Very small phones: match the shrunken icon-button scale. */
@media screen and (max-width:380px){
	.brikpanel-masterswitch-toggle{width:36px;height:20px;}
	.brikpanel-masterswitch-knob{width:14px;height:14px;top:3px;left:3px;}
	.brikpanel-masterswitch-toggle.is-on .brikpanel-masterswitch-knob{transform:translateX(16px);}
}
/* ---- Native WP admin-bar node (shown while BrikPanel is off, or on screens
       where the top bar does not render) ---- */
#wpadminbar #wp-admin-bar-brikpanel-master>.ab-item{display:flex !important;align-items:center;gap:6px;}
#wpadminbar .brikpanel-ab-icon{display:inline-flex !important;align-items:center;width:auto;height:auto;color:#a7aaad;}
#wpadminbar .brikpanel-ab-icon.is-on{color:#46b450;}
#wpadminbar .brikpanel-ab-icon.is-off{color:#a7aaad;}
#wpadminbar .brikpanel-ab-icon svg{display:block;}
#wpadminbar .brikpanel-ab-masterswitch.is-busy{opacity:.6;}
/* On phones WP hides every left-hand admin-bar item except its own toggle, which
   would bury the one control that turns BrikPanel back on. Force our node to stay
   visible and tappable (46px touch target, like WP's mobile bar) and show just
   the icon — matching WP's icon-only mobile bar. The id + footer load order
   outrank WP core's mobile rule. */
@media screen and (max-width:782px){
	#wpadminbar li#wp-admin-bar-brikpanel-master{display:block !important;}
	#wpadminbar #wp-admin-bar-brikpanel-master>.ab-item{display:flex !important;align-items:center;height:46px;padding:0 14px;}
	#wpadminbar #wp-admin-bar-brikpanel-master .ab-label{display:none !important;}
	#wpadminbar #wp-admin-bar-brikpanel-master .brikpanel-ab-icon svg{width:22px;height:22px;}
}
</style>
<script id="brikpanel-masterswitch-js">
(function(){
	var cfg = <?php echo $cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output. ?>;
	if(!cfg||!cfg.ajaxUrl){return;}
	var busy=false;

	function setBusy(state){
		busy=state;
		var nodes=document.querySelectorAll('.brikpanel-masterswitch-trigger');
		for(var i=0;i<nodes.length;i++){
			if(state){nodes[i].classList.add('is-busy');}else{nodes[i].classList.remove('is-busy');}
		}
	}

	function toggle(){
		if(busy){return;}
		setBusy(true);
		var want=cfg.enabled?'0':'1';
		var body='action='+encodeURIComponent(cfg.action)+'&nonce='+encodeURIComponent(cfg.nonce)+'&enable='+want;
		fetch(cfg.ajaxUrl,{
			method:'POST',
			credentials:'same-origin',
			headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
			body:body
		}).then(function(r){return r.json();}).then(function(res){
			if(res&&res.success){
				// When switching off from a BrikPanel-only screen, that page is
				// gone afterwards — land on the stock WP dashboard instead of
				// reloading a now-inaccessible URL. Otherwise reload in place.
				var target='';
				if(cfg.enabled){
					var pg=new URLSearchParams(window.location.search).get('page')||'';
					if(pg.indexOf('brikpanel')===0){target=cfg.dashboardUrl;}
				}
				if(target){window.location.href=target;}else{window.location.reload();}
			}else{
				setBusy(false);
				window.alert((res&&res.data&&res.data.message)||cfg.i18n.error);
			}
		}).catch(function(){
			setBusy(false);
			window.alert(cfg.i18n.error);
		});
	}

	document.addEventListener('click',function(e){
		var trigger=e.target.closest('.brikpanel-masterswitch-trigger');
		if(!trigger){return;}
		e.preventDefault();
		toggle();
	},false);
})();
</script>
		<?php
	}
}

Brikpanel_Master_Switch::instance();
