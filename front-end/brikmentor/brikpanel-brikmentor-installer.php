<?php
/**
 * BrikPanel — BrikMentor installer bridge.
 *
 * Adds a "BrikMentor" section to the BrikPanel settings tab where the store
 * owner pastes the license key from their brksoft.com purchase and clicks
 * one button: the plugin zip is downloaded from the license-gated relay
 * endpoint, installed, activated, and the BrikMentor onboarding wizard opens
 * already knowing the license.
 *
 * Everything here rides BrikPanel's public extension points (settings
 * section/fields filters + a WC custom field type) — no core file changes
 * beyond the single require in the loader.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin basename BrikMentor installs under (fixed by the dist zip layout). */
const BRIKPANEL_BRIKMENTOR_BASENAME = 'brikmentor/brikmentor.php';

/**
 * Relay base URL serving the license-gated download. Shares the option the
 * BrikMentor client itself uses so dev/staging installs point both at the
 * same relay automatically.
 *
 * @return string
 */
function brikpanel_brikmentor_relay_url() {
	$base = (string) get_option( 'brikmentor_relay_url' );
	if ( '' === $base ) {
		$base = 'https://brksoft.com';
	}
	/**
	 * Filter the relay base URL used for the BrikMentor download.
	 *
	 * @param string $base Base URL without trailing slash.
	 */
	return untrailingslashit( (string) apply_filters( 'brikpanel_brikmentor_relay_url', $base ) );
}

/**
 * Current install state of BrikMentor on this site.
 *
 * @return string 'active' | 'installed' | 'absent'
 */
function brikpanel_brikmentor_state() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_plugin_active( BRIKPANEL_BRIKMENTOR_BASENAME ) ) {
		return 'active';
	}
	return file_exists( WP_PLUGIN_DIR . '/' . BRIKPANEL_BRIKMENTOR_BASENAME ) ? 'installed' : 'absent';
}

// -----------------------------------------------------------------------------
// Settings surface (section + card) via BrikPanel's public filters.
// -----------------------------------------------------------------------------

add_filter( 'woocommerce_get_sections_brikpanel', function ( $sections ) {
	$sections['brikmentor'] = __( 'BrikMentor', 'brikpanel' );
	return $sections;
} );

add_filter( 'brikpanel_settings_title_section_map', function ( $map ) {
	$map['brk_brikmentor_title'] = 'brikmentor';
	return $map;
} );

add_filter( 'brikpanel_settings_section_groups', function ( $groups ) {
	if ( isset( $groups['integrations']['sections'] ) ) {
		$groups['integrations']['sections'][] = 'brikmentor';
	}
	return $groups;
} );

add_filter( 'brikpanel_settings_fields', function ( $fields ) {
	$fields[] = [
		'name' => __( 'BrikMentor', 'brikpanel' ),
		'type' => 'title',
		'id'   => 'brk_brikmentor_title',
		'desc' => __( 'Email automation for your store: abandoned cart recovery, win-back and post-purchase flows, AI campaigns. A premium companion plugin by the BrikPanel team.', 'brikpanel' ),
	];
	$fields[] = [
		'type' => 'brikpanel_brikmentor_installer',
		'id'   => 'brikpanel_brikmentor_installer_field',
	];
	$fields[] = [
		'type' => 'sectionend',
		'id'   => 'brk_brikmentor_title',
	];
	return $fields;
} );

/**
 * Render the installer card (custom WC settings field type).
 */
function brikpanel_brikmentor_render_installer() {
	$state = brikpanel_brikmentor_state();
	$can   = current_user_can( 'install_plugins' );
	?>
	<tr valign="top">
		<td colspan="2" style="padding: 0;">
			<div id="brikpanel-brikmentor-card" style="max-width: 560px; background: #fff; border: 1px solid #e3e3e3; border-radius: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 1.25rem 1.5rem;">
				<?php if ( 'active' === $state ) : ?>
					<p style="margin: 0 0 0.75rem; color: #1a8917; font-weight: 600;"><?php esc_html_e( 'BrikMentor is installed and active on this store.', 'brikpanel' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=brikmentor-settings' ) ); ?>"><?php esc_html_e( 'Open BrikMentor settings', 'brikpanel' ); ?></a>
				<?php elseif ( ! $can ) : ?>
					<p style="margin: 0; color: #616161;"><?php esc_html_e( 'You need the "install plugins" permission to set up BrikMentor. Ask a site administrator.', 'brikpanel' ); ?></p>
				<?php else : ?>
					<?php if ( 'installed' === $state ) : ?>
						<p style="margin: 0 0 0.75rem; color: #616161;"><?php esc_html_e( 'BrikMentor is installed but not active yet.', 'brikpanel' ); ?></p>
						<button type="button" class="button button-primary" id="brikpanel-brikmentor-activate"><?php esc_html_e( 'Activate BrikMentor', 'brikpanel' ); ?></button>
					<?php else : ?>
						<p style="margin: 0 0 0.75rem; color: #616161;"><?php esc_html_e( 'Enter the license key from your brksoft.com purchase. The plugin will be downloaded, installed and activated, and setup will continue with your license already in place.', 'brikpanel' ); ?></p>
						<p style="margin: 0 0 0.375rem;">
							<label for="brikpanel-brikmentor-license" style="display: block; font-weight: 600; font-size: 0.8125rem; margin-bottom: 0.375rem;"><?php esc_html_e( 'License key', 'brikpanel' ); ?></label>
							<input type="text" id="brikpanel-brikmentor-license" class="regular-text code" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX">
						</p>
						<button type="button" class="button button-primary" id="brikpanel-brikmentor-install"><?php esc_html_e( 'Install & Activate', 'brikpanel' ); ?></button>
					<?php endif; ?>
					<p id="brikpanel-brikmentor-msg" style="margin: 0.75rem 0 0; display: none;"></p>
					<script>
					( function () {
						var cfg = <?php echo wp_json_encode( [
							'ajaxUrl' => admin_url( 'admin-ajax.php' ),
							'nonce'   => wp_create_nonce( 'brikpanel_brikmentor_install' ),
							'i18n'    => [
								'keyRequired' => __( 'Enter your license key first.', 'brikpanel' ),
								'working'     => __( 'Installing… this can take up to a minute.', 'brikpanel' ),
								'activating'  => __( 'Activating…', 'brikpanel' ),
								'error'       => __( 'Something went wrong. Please try again.', 'brikpanel' ),
							],
						] ); ?>;
						function msg( text, ok ) {
							var el = document.getElementById( 'brikpanel-brikmentor-msg' );
							el.style.display = 'block';
							el.style.color = ok ? '#1a8917' : '#d72c0d';
							el.textContent = text;
						}
						function run( action, license, btn, busyText ) {
							btn.disabled = true;
							msg( busyText, true );
							var body = new URLSearchParams();
							body.append( 'action', 'brikpanel_brikmentor_install' );
							body.append( 'nonce', cfg.nonce );
							body.append( 'bm_action', action );
							body.append( 'license', license );
							fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
								.then( function ( r ) { return r.json(); } )
								.then( function ( data ) {
									if ( data && data.success ) {
										msg( data.data.message, true );
										if ( data.data.redirect ) {
											window.location.href = data.data.redirect;
										}
									} else {
										btn.disabled = false;
										msg( ( data && data.data && data.data.message ) ? data.data.message : cfg.i18n.error, false );
									}
								} )
								.catch( function () {
									btn.disabled = false;
									msg( cfg.i18n.error, false );
								} );
						}
						var installBtn = document.getElementById( 'brikpanel-brikmentor-install' );
						if ( installBtn ) {
							installBtn.addEventListener( 'click', function () {
								var license = document.getElementById( 'brikpanel-brikmentor-license' ).value.trim();
								if ( ! license ) {
									msg( cfg.i18n.keyRequired, false );
									return;
								}
								run( 'install', license, installBtn, cfg.i18n.working );
							} );
						}
						var activateBtn = document.getElementById( 'brikpanel-brikmentor-activate' );
						if ( activateBtn ) {
							activateBtn.addEventListener( 'click', function () {
								run( 'activate', '', activateBtn, cfg.i18n.activating );
							} );
						}
					} )();
					</script>
				<?php endif; ?>
			</div>
		</td>
	</tr>
	<?php
}
add_action( 'woocommerce_admin_field_brikpanel_brikmentor_installer', 'brikpanel_brikmentor_render_installer' );

// -----------------------------------------------------------------------------
// AJAX: download (license-gated) → install → activate → hand the key over.
// -----------------------------------------------------------------------------

function brikpanel_brikmentor_ajax_install() {
	check_ajax_referer( 'brikpanel_brikmentor_install', 'nonce' );

	$action = sanitize_key( wp_unslash( $_POST['bm_action'] ?? 'install' ) );

	// Activate-only path (plugin folder already present).
	if ( 'activate' === $action ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to activate plugins.', 'brikpanel' ) ], 403 );
		}
		$result = activate_plugin( BRIKPANEL_BRIKMENTOR_BASENAME );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 );
		}
		wp_send_json_success( [
			'message'  => __( 'BrikMentor activated. Opening setup…', 'brikpanel' ),
			'redirect' => admin_url( 'admin.php?page=brikmentor-settings' ),
		] );
	}

	if ( ! current_user_can( 'install_plugins' ) ) {
		wp_send_json_error( [ 'message' => __( 'You do not have permission to install plugins.', 'brikpanel' ) ], 403 );
	}

	$license = substr( sanitize_text_field( wp_unslash( $_POST['license'] ?? '' ) ), 0, 128 );
	if ( '' === $license ) {
		wp_send_json_error( [ 'message' => __( 'Enter your license key first.', 'brikpanel' ) ], 400 );
	}

	// 1. Download from the license-gated relay endpoint into a temp file. A
	//    manual streamed request (instead of download_url()) keeps the real
	//    HTTP status, so an invalid key can be reported as exactly that.
	$url  = brikpanel_brikmentor_relay_url() . '/wp-json/brikmentor-relay/v1/download?license=' . rawurlencode( $license );
	$temp = wp_tempnam( 'brikmentor.zip' );
	if ( ! $temp ) {
		wp_send_json_error( [ 'message' => __( 'Could not create a temporary file.', 'brikpanel' ) ], 500 );
	}

	$response = wp_remote_get( $url, [
		'timeout'   => 120,
		'stream'    => true,
		'filename'  => $temp,
		'sslverify' => apply_filters( 'brikpanel_brikmentor_sslverify', true ),
	] );
	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( is_wp_error( $response ) || 200 !== $code ) {
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( 403 === $code ) {
			wp_send_json_error( [ 'message' => __( 'This license key is not valid. Check the key from your brksoft.com purchase email and try again.', 'brikpanel' ) ], 403 );
		}
		wp_send_json_error( [ 'message' => __( 'The download service could not be reached. Please try again in a few minutes.', 'brikpanel' ) ], 502 );
	}

	// 2. Install from the local temp package (BrikMarket wizard pattern).
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result   = $upgrader->install( $temp );
	@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
	}
	if ( ! $result ) {
		wp_send_json_error( [ 'message' => __( 'Installation failed.', 'brikpanel' ) ], 500 );
	}

	// 3. Activate, then hand the license key to BrikMentor so onboarding
	//    starts with it: try a server-side activation right away; whatever
	//    happens, the prefill option makes the wizard field ready.
	update_option( 'brikmentor_prefill_license', $license, false );

	$activate = activate_plugin( BRIKPANEL_BRIKMENTOR_BASENAME );
	if ( is_wp_error( $activate ) ) {
		wp_send_json_error( [ 'message' => sprintf(
			/* translators: %s: activation error detail */
			__( 'Installed, but activation failed: %s', 'brikpanel' ),
			$activate->get_error_message()
		) ], 500 );
	}

	if ( class_exists( 'Brikmentor_License' ) ) {
		$licensed = Brikmentor_License::activate( $license );
		if ( ! empty( $licensed['ok'] ) ) {
			delete_option( 'brikmentor_prefill_license' );
		}
	}

	wp_send_json_success( [
		'message'  => __( 'BrikMentor installed and activated. Opening setup…', 'brikpanel' ),
		'redirect' => admin_url( 'admin.php?page=brikmentor-settings' ),
	] );
}
add_action( 'wp_ajax_brikpanel_brikmentor_install', 'brikpanel_brikmentor_ajax_install' );
