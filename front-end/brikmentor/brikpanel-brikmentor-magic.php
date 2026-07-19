<?php
/**
 * BrikPanel — BrikMentor "zero-paste" magic purchase flow.
 *
 * Turns every launch CTA (FAB, dashboard live-card, settings CTA) into a
 * one-click purchase: mint a local claim_id, open the relay Stripe Checkout in
 * a new tab, then poll the relay's /claim endpoint until the license key comes
 * back and auto-run the EXISTING installer chain
 * (brikpanel_brikmentor_ajax_install). No key is ever pasted by hand on the
 * happy path; failure falls back to the manual installer card.
 *
 * All three surfaces share one enqueued, i18n-localized module — no user-facing
 * strings live in the .js. Polling resumes across page loads on any BrikPanel
 * admin screen while a claim is pending (24h window).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Relay Checkout URL for the primary CTA. Distinct from the landing URL
 * (brikpanel_brikmentor_url, used by "Learn more"): this one starts a purchase.
 * Defaults to the relay's /checkout endpoint; overridable via option/filter so
 * campaigns can re-point without a release.
 *
 * @return string
 */
function brikpanel_brikmentor_checkout_url() {
	$url = get_option( 'brikpanel_brikmentor_checkout_url', '' );
	if ( ! is_string( $url ) || '' === trim( $url ) ) {
		$base = function_exists( 'brikpanel_brikmentor_relay_url' )
			? brikpanel_brikmentor_relay_url()
			: 'https://brksoft.com';
		$url = $base . '/wp-json/brikmentor-relay/v1/checkout';
	}
	/**
	 * Filter the BrikMentor Stripe Checkout URL.
	 *
	 * @param string $url
	 */
	return esc_url_raw( (string) apply_filters( 'brikpanel_brikmentor_checkout_url', $url ) );
}

/**
 * The local pending-claim record, or null. Shape: {id, created}. Expires after
 * 24h (matches the relay's claim TTL).
 *
 * @return array|null
 */
function brikpanel_brikmentor_pending_claim() {
	$claim = get_option( 'brikpanel_brikmentor_claim' );
	if ( ! is_array( $claim ) || empty( $claim['id'] ) ) {
		return null;
	}
	if ( ( time() - (int) ( $claim['created'] ?? 0 ) ) > DAY_IN_SECONDS ) {
		return null;
	}
	return $claim;
}

/* ── Enqueue the shared magic module on BrikPanel / WC-settings screens ───────── */

add_action( 'admin_enqueue_scripts', 'brikpanel_brikmentor_magic_enqueue' );
function brikpanel_brikmentor_magic_enqueue( $hook ) {
	if ( ! function_exists( 'brikpanel_brikmentor_promo_active' ) || ! brikpanel_brikmentor_promo_active() ) {
		return;
	}
	if ( ! current_user_can( 'install_plugins' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$id     = $screen ? (string) $screen->id : '';
	$eligible = ( false !== strpos( $id, 'brikpanel' ) )
		|| ( false !== strpos( (string) $hook, 'brikpanel' ) )
		|| ( 'woocommerce_page_wc-settings' === $id );
	if ( ! $eligible ) {
		return;
	}

	$rel  = plugins_url( 'brikpanel-brikmentor-magic.js', __FILE__ );
	$path = __DIR__ . '/brikpanel-brikmentor-magic.js';
	wp_enqueue_script(
		'brikpanel-brikmentor-magic',
		$rel,
		[],
		file_exists( $path ) ? (string) filemtime( $path ) : ( defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '1' ),
		true
	);

	wp_localize_script( 'brikpanel-brikmentor-magic', 'brikpanelBrikmentor', [
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( 'brikpanel_brikmentor_magic' ),
		'installNonce'    => wp_create_nonce( 'brikpanel_brikmentor_install' ),
		'checkoutUrl'     => brikpanel_brikmentor_checkout_url(),
		'settingsUrl'     => admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=brikmentor' ),
		'openUrl'         => admin_url( 'admin.php?page=brikmentor-settings' ),
		'pollMs'          => 5000,
		'maxPollMin'      => 30,
		'hasPendingClaim' => (bool) brikpanel_brikmentor_pending_claim(),
		'i18n'            => [
			'launching'     => __( 'Opening secure checkout…', 'brikpanel' ),
			'popupBlocked'  => __( 'Your browser blocked the checkout window — click to open it.', 'brikpanel' ),
			'openCheckout'  => __( 'Open checkout', 'brikpanel' ),
			'waiting'       => __( 'Waiting for your payment…', 'brikpanel' ),
			'stillWaiting'  => __( 'Still waiting. Finish checkout in the other tab, or open it again.', 'brikpanel' ),
			'received'      => __( 'Payment received. Setting up BrikMentor…', 'brikpanel' ),
			'installing'    => __( 'Downloading and installing BrikMentor…', 'brikpanel' ),
			'activating'    => __( 'Activating your license…', 'brikpanel' ),
			'done'          => __( 'All set! Opening BrikMentor…', 'brikpanel' ),
			'openBtn'       => __( 'Open BrikMentor', 'brikpanel' ),
			'failedInstall' => __( 'Automatic setup could not finish. Use your license key to complete it manually.', 'brikpanel' ),
			'yourKey'       => __( 'Your license key:', 'brikpanel' ),
			'goToInstaller' => __( 'Finish setup', 'brikpanel' ),
			'expired'       => __( 'This checkout link expired. Check your email for the license key, or start again.', 'brikpanel' ),
			'claimed'       => __( 'This purchase was already set up. Check your email for the license key.', 'brikpanel' ),
			'error'         => __( 'Something went wrong. Please try again.', 'brikpanel' ),
			'retry'         => __( 'Try again', 'brikpanel' ),
			'close'         => __( 'Close', 'brikpanel' ),
		],
	] );
}

/* ── AJAX: start a claim (mint/reuse claim_id, hand back the checkout URL) ────── */

add_action( 'wp_ajax_brikpanel_brikmentor_claim_start', 'brikpanel_brikmentor_ajax_claim_start' );
function brikpanel_brikmentor_ajax_claim_start() {
	check_ajax_referer( 'brikpanel_brikmentor_magic' );
	if ( ! current_user_can( 'install_plugins' ) ) {
		wp_send_json_error( [ 'reason' => 'forbidden' ], 403 );
	}

	$claim = brikpanel_brikmentor_pending_claim();
	if ( ! $claim ) {
		$claim = [ 'id' => wp_generate_uuid4(), 'created' => time() ];
		update_option( 'brikpanel_brikmentor_claim', $claim, false );
	}

	// site_url travels with the checkout so the relay can recognise a RETURNING
	// store. The introductory discount is first-purchase-only, and a store that
	// cancels and re-subscribes months later arrives with a fresh claim_id and
	// possibly a different billing email — the site is the one thing that
	// stays the same.
	$url = add_query_arg(
		[
			'claim_id' => rawurlencode( $claim['id'] ),
			'src'      => 'panel',
			'site_url' => rawurlencode( home_url() ),
		],
		brikpanel_brikmentor_checkout_url()
	);

	wp_send_json_success( [ 'claim_id' => $claim['id'], 'checkout_url' => $url ] );
}

/* ── AJAX: poll the relay /claim endpoint for the minted key ──────────────────── */

add_action( 'wp_ajax_brikpanel_brikmentor_claim_poll', 'brikpanel_brikmentor_ajax_claim_poll' );
function brikpanel_brikmentor_ajax_claim_poll() {
	check_ajax_referer( 'brikpanel_brikmentor_magic' );
	if ( ! current_user_can( 'install_plugins' ) ) {
		wp_send_json_error( [ 'reason' => 'forbidden' ], 403 );
	}

	$claim = brikpanel_brikmentor_pending_claim();
	if ( ! $claim ) {
		// None pending, or expired past the 24h window.
		if ( false !== get_option( 'brikpanel_brikmentor_claim' ) ) {
			delete_option( 'brikpanel_brikmentor_claim' );
			wp_send_json_success( [ 'status' => 'expired' ] );
		}
		wp_send_json_success( [ 'status' => 'idle' ] );
	}

	$url = brikpanel_brikmentor_relay_url() . '/wp-json/brikmentor-relay/v1/claim?claim_id=' . rawurlencode( $claim['id'] );
	$response = wp_remote_get( $url, [
		'timeout'   => 15,
		'sslverify' => apply_filters( 'brikpanel_brikmentor_sslverify', true ),
	] );
	if ( is_wp_error( $response ) ) {
		wp_send_json_success( [ 'status' => 'pending' ] ); // Transient network blip — keep polling.
	}

	$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$status = is_array( $data ) ? (string) ( $data['status'] ?? '' ) : '';

	if ( 'ok' === $status && ! empty( $data['license_key'] ) ) {
		// The key is delivered exactly ONCE by the relay, so this response is
		// the only chance to keep it. Persist BEFORE dropping the claim: if the
		// install chain then fails and the admin reloads the page, the key was
		// otherwise gone from the site entirely (claim spent, key only ever in
		// the DOM) and the customer had to go hunting in their email. This is
		// the same option the installer card prefills from, and the installer
		// deletes it once activation succeeds.
		update_option( 'brikmentor_prefill_license', (string) $data['license_key'], false );
		delete_option( 'brikpanel_brikmentor_claim' );
		wp_send_json_success( [ 'status' => 'ready', 'license' => (string) $data['license_key'] ] );
	}
	if ( 'claimed' === $status || 'expired' === $status ) {
		delete_option( 'brikpanel_brikmentor_claim' );
		wp_send_json_success( [ 'status' => $status ] );
	}

	wp_send_json_success( [ 'status' => 'pending' ] );
}
