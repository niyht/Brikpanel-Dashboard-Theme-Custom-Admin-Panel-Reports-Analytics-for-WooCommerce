<?php
/**
 * BrikPanel - deactivation survey ("why are you deactivating?").
 *
 * When an admin clicks BrikPanel's Deactivate link on the Plugins screen, a
 * small window asks why, the way Elementor and Jetpack do. "Skip and
 * deactivate" sends nothing and deactivates at once. "Send and deactivate"
 * posts the answer to the Brksoft Survey endpoint on brksoft.com and then
 * deactivates whatever happened to the answer: BrikPanel is inactive after the
 * redirect, so there is no retry, and nothing is stored on the site.
 *
 * What goes out, and only on Send: the reason, the optional "installed it for"
 * pick and comment, the days since the first activation, the BrikPanel,
 * WordPress, WooCommerce and PHP versions and the admin language. The site
 * address does not: WordPress's default user agent carries it, so the request
 * sets its own. readme.txt documents this under "What data does BrikPanel send
 * outside my site?" (wp.org guideline 7: data leaves only on the user's own
 * action).
 *
 * Loaded above the WooCommerce guard in brikpanel.php, so it also works without
 * WooCommerce, on the network Plugins screen and while the BrikPanel interface
 * is switched off (its handles are kept out of the access-control asset sweep).
 * Nothing here relies on a module loaded after the guard.
 *
 * Results: brksoft.com wp-admin, Survey > BrikPanel exits (the Brksoft Survey
 * plugin, source in the BrikMentor repo under tools/brksoft-survey/).
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BRIKPANEL_EXIT_SURVEY_ENDPOINT' ) ) {
	define( 'BRIKPANEL_EXIT_SURVEY_ENDPOINT', 'https://brksoft.com/wp-json/brksoft-survey/v1/exit' );
}

/** Longest comment the window accepts, in characters (the endpoint cuts at the same length). */
if ( ! defined( 'BRIKPANEL_EXIT_SURVEY_COMMENT_MAX' ) ) {
	define( 'BRIKPANEL_EXIT_SURVEY_COMMENT_MAX', 1000 );
}

/**
 * Reasons in the order the window lists them, each with the label of its own
 * comment box. The keys are the endpoint's allow-list.
 *
 * @return array<string, array{label: string, prompt: string}>
 */
function brikpanel_exit_survey_reasons() {
	return [
		'one_feature'    => [
			'label'  => __( 'I only needed one feature, but my whole admin changed', 'brikpanel' ),
			'prompt' => __( 'What did you run into? (optional)', 'brikpanel' ),
		],
		'temporary'      => [
			'label'  => __( 'I am only deactivating it for a short while', 'brikpanel' ),
			'prompt' => __( 'Anything you would like to tell us? (optional)', 'brikpanel' ),
		],
		'conflict'       => [
			'label'  => __( 'It conflicted with another plugin or my theme', 'brikpanel' ),
			'prompt' => __( 'Which plugin or theme? (optional)', 'brikpanel' ),
		],
		'broken'         => [
			'label'  => __( 'Something did not work', 'brikpanel' ),
			'prompt' => __( 'What did not work? (optional)', 'brikpanel' ),
		],
		'slow'           => [
			'label'  => __( 'It slowed down my site', 'brikpanel' ),
			'prompt' => __( 'Which page felt slow? (optional)', 'brikpanel' ),
		],
		'missing'        => [
			'label'  => __( 'A feature I need is missing', 'brikpanel' ),
			'prompt' => __( 'What were you looking for? (optional)', 'brikpanel' ),
		],
		'prefer_default' => [
			'label'  => __( 'I prefer the default WooCommerce screens', 'brikpanel' ),
			'prompt' => __( 'What did you miss from them? (optional)', 'brikpanel' ),
		],
		'better'         => [
			'label'  => __( 'I found a better plugin', 'brikpanel' ),
			'prompt' => __( 'Which one? (optional)', 'brikpanel' ),
		],
		'other'          => [
			'label'  => _x( 'Other', 'deactivation survey reason', 'brikpanel' ),
			'prompt' => __( 'Please tell us more (optional)', 'brikpanel' ),
		],
	];
}

/**
 * "What did you install BrikPanel for?" options (top reason only).
 *
 * @return array<string, string>
 */
function brikpanel_exit_survey_features() {
	return [
		'abandoned_cart' => __( 'Abandoned cart recovery', 'brikpanel' ),
		'dashboard'      => __( 'Dashboard and reports', 'brikpanel' ),
		'products'       => __( 'Product list and bulk editing', 'brikpanel' ),
		'orders'         => __( 'Orders', 'brikpanel' ),
		'google_sheets'  => __( 'Google Sheets sync', 'brikpanel' ),
		'ads_profit'     => __( 'Ad spend and profit', 'brikpanel' ),
		'inventory'      => __( 'Stock and suppliers', 'brikpanel' ),
		'coupons'        => __( 'Coupons', 'brikpanel' ),
		'login'          => __( 'Login page', 'brikpanel' ),
		'other'          => _x( 'Something else', 'deactivation survey: what BrikPanel was installed for', 'brikpanel' ),
	];
}

/**
 * Whether this request is a Plugins screen where the current user sees
 * BrikPanel's own Deactivate link: on a site where BrikPanel is active (and not
 * network-active), or on the network screen where it is network-active.
 *
 * @return bool
 */
function brikpanel_exit_survey_applies() {
	global $pagenow;
	if ( ! is_admin() || wp_doing_ajax() || 'plugins.php' !== $pagenow || ! function_exists( 'is_plugin_active' ) ) {
		return false;
	}
	$network_active = is_multisite() && is_plugin_active_for_network( BRIKPANEL_BASENAME );
	if ( is_network_admin() ) {
		return $network_active && current_user_can( 'manage_network_plugins' );
	}
	return ! $network_active
		&& is_plugin_active( BRIKPANEL_BASENAME )
		&& current_user_can( 'deactivate_plugin', BRIKPANEL_BASENAME );
}

/**
 * File version for cache busting.
 *
 * @param string $rel Path under the plugin folder.
 * @return string
 */
function brikpanel_exit_survey_ver( $rel ) {
	$time = @filemtime( BRIKPANEL_PATH . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version.
	return $time ? (string) $time : BRIKPANEL_VERSION;
}

/**
 * Window styles and script, on the Plugins screen only. Priority 20: after the
 * shared parts register (priority 1) on sites past the WooCommerce guard.
 *
 * @param string $hook Admin page hook.
 */
function brikpanel_exit_survey_enqueue( $hook ) {
	if ( 'plugins.php' !== $hook || ! brikpanel_exit_survey_applies() ) {
		return;
	}

	// The window uses the shared fields and buttons. Without WooCommerce the
	// shared parts never register, so register that one stylesheet under the
	// same handle.
	if ( ! wp_style_is( 'brikpanel_ui', 'registered' ) && file_exists( BRIKPANEL_PATH . 'front-end/shared/brikpanel-ui.css' ) ) {
		wp_register_style( 'brikpanel_ui', BRIKPANEL_URL . 'front-end/shared/brikpanel-ui.css', [], brikpanel_exit_survey_ver( 'front-end/shared/brikpanel-ui.css' ) );
	}

	wp_enqueue_style(
		'brikpanel-exit-survey',
		BRIKPANEL_URL . 'front-end/exit-survey/brikpanel-exit-survey.css',
		wp_style_is( 'brikpanel_ui', 'registered' ) ? [ 'brikpanel_ui' ] : [],
		brikpanel_exit_survey_ver( 'front-end/exit-survey/brikpanel-exit-survey.css' )
	);
	wp_enqueue_script(
		'brikpanel-exit-survey',
		BRIKPANEL_URL . 'front-end/exit-survey/brikpanel-exit-survey.js',
		[],
		brikpanel_exit_survey_ver( 'front-end/exit-survey/brikpanel-exit-survey.js' ),
		true
	);
	wp_localize_script(
		'brikpanel-exit-survey',
		'brikpanelExitSurvey',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'brikpanel_exit_survey' ),
			'plugin'  => BRIKPANEL_BASENAME,
			// The browser waits this long for the answer to go out, then deactivates anyway.
			'timeout' => 5000,
			'i18n'    => [
				'sending' => __( 'Sending…', 'brikpanel' ),
			],
		]
	);
}
add_action( 'admin_enqueue_scripts', 'brikpanel_exit_survey_enqueue', 20 );

/**
 * Keeps the window's files (and the shared stylesheet it needs) when the
 * BrikPanel interface is switched off for this user: the access-control sweep
 * removes every other BrikPanel file, and the people who switched the
 * interface off are among the most likely to deactivate.
 *
 * @param string[] $keep Handles the sweep leaves alone.
 * @return string[]
 */
function brikpanel_exit_survey_sweep_keep( $keep ) {
	if ( brikpanel_exit_survey_applies() ) {
		$keep[] = 'brikpanel-exit-survey';
		$keep[] = 'brikpanel_ui';
	}
	return $keep;
}
add_filter( 'brikpanel_access_sweep_keep', 'brikpanel_exit_survey_sweep_keep' );

/**
 * The window, printed closed at the end of the Plugins screen. The script
 * opens it from BrikPanel's Deactivate link; without the script (or without
 * <dialog> support) the link deactivates as it always did.
 */
function brikpanel_exit_survey_render() {
	if ( ! brikpanel_exit_survey_applies() ) {
		return;
	}
	$reasons  = brikpanel_exit_survey_reasons();
	$features = brikpanel_exit_survey_features();
	?>
	<dialog class="bp-exit" id="brikpanel-exit-survey" aria-labelledby="bp-exit-title" aria-describedby="bp-exit-sub">
		<form class="bp-exit__form" novalidate>
			<div class="bp-exit__head">
				<div class="bp-exit__titles">
					<h2 class="bp-exit__title" id="bp-exit-title"><?php esc_html_e( 'Before you go: why are you deactivating BrikPanel?', 'brikpanel' ); ?></h2>
					<p class="bp-exit__sub" id="bp-exit-sub"><?php esc_html_e( 'Your answer helps us fix what made you leave. Answering is optional.', 'brikpanel' ); ?></p>
				</div>
				<button type="button" class="bp-exit__close" data-bp-exit="close" aria-label="<?php esc_attr_e( 'Close and keep BrikPanel active', 'brikpanel' ); ?>">
					<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
				</button>
			</div>
			<fieldset class="bp-exit__body">
				<legend class="screen-reader-text"><?php esc_html_e( 'Choose a reason', 'brikpanel' ); ?></legend>
				<?php
				foreach ( $reasons as $key => $reason ) :
					$id = 'bp-exit-' . str_replace( '_', '-', $key );
					?>
					<div class="bp-exit__option">
						<label class="bp-exit__choice">
							<input type="radio" name="reason" value="<?php echo esc_attr( $key ); ?>" aria-controls="<?php echo esc_attr( $id . '-more' ); ?>">
							<span><?php echo esc_html( $reason['label'] ); ?></span>
						</label>
						<div class="bp-exit__more" id="<?php echo esc_attr( $id . '-more' ); ?>" data-reason="<?php echo esc_attr( $key ); ?>" hidden>
							<?php if ( 'one_feature' === $key ) : ?>
								<div class="brikpanel-field">
									<label for="bp-exit-feature"><?php esc_html_e( 'What did you install BrikPanel for?', 'brikpanel' ); ?></label>
									<select class="brikpanel-control" id="bp-exit-feature" name="feature">
										<option value=""><?php esc_html_e( 'Choose one', 'brikpanel' ); ?></option>
										<?php foreach ( $features as $feature_key => $feature_label ) : ?>
											<option value="<?php echo esc_attr( $feature_key ); ?>"><?php echo esc_html( $feature_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
							<div class="brikpanel-field">
								<label for="<?php echo esc_attr( $id . '-comment' ); ?>"><?php echo esc_html( $reason['prompt'] ); ?></label>
								<textarea class="brikpanel-control bp-exit__comment" id="<?php echo esc_attr( $id . '-comment' ); ?>" name="comment" rows="3" maxlength="<?php echo (int) BRIKPANEL_EXIT_SURVEY_COMMENT_MAX; ?>" dir="auto"></textarea>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</fieldset>
			<p class="bp-exit__privacy">
				<?php esc_html_e( 'Pressing Send shares only your answer, how many days BrikPanel was in use, your BrikPanel, WordPress, WooCommerce and PHP versions and your admin language with brksoft.com. Your site address, email and store data are not sent.', 'brikpanel' ); ?>
				<a href="https://brksoft.com/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'brikpanel' ); ?></a>
			</p>
			<div class="bp-exit__foot">
				<button type="button" class="brikpanel-btn brikpanel-btn--secondary bp-exit__btn" data-bp-exit="skip"><?php esc_html_e( 'Skip and deactivate', 'brikpanel' ); ?></button>
				<button type="submit" class="brikpanel-btn brikpanel-btn--primary bp-exit__btn" data-bp-exit="send" disabled><?php esc_html_e( 'Send and deactivate', 'brikpanel' ); ?></button>
			</div>
		</form>
	</dialog>
	<?php
}
add_action( 'admin_footer', 'brikpanel_exit_survey_render' );

/**
 * Days since BrikPanel was first activated on this site, or null when it is
 * not known (the option is set by the review notices module, which loads only
 * past the WooCommerce guard).
 *
 * @return int|null
 */
function brikpanel_exit_survey_days() {
	$first = (int) get_option( 'brikpanel_activated_at', 0 );
	if ( $first <= 0 ) {
		return null;
	}
	return max( 0, min( 20000, (int) floor( ( time() - $first ) / DAY_IN_SECONDS ) ) );
}

/**
 * Receives the answer from the window and passes it on to brksoft.com.
 * Always answers success: the window deactivates either way.
 */
function brikpanel_exit_survey_ajax() {
	check_ajax_referer( 'brikpanel_exit_survey', 'nonce' );
	if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) {
		wp_send_json_error( null, 403 );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
	$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
	if ( ! array_key_exists( $reason, brikpanel_exit_survey_reasons() ) ) {
		wp_send_json_error( null, 400 );
	}
	$feature = '';
	if ( 'one_feature' === $reason && isset( $_POST['feature'] ) ) {
		$feature = sanitize_key( wp_unslash( $_POST['feature'] ) );
		if ( ! array_key_exists( $feature, brikpanel_exit_survey_features() ) ) {
			$feature = '';
		}
	}
	$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
	// phpcs:enable
	$comment = function_exists( 'brikpanel_substr' ) ? brikpanel_substr( $comment, 0, BRIKPANEL_EXIT_SURVEY_COMMENT_MAX ) : $comment;

	// The browser stops waiting after a few seconds and deactivates; the answer should still leave.
	ignore_user_abort( true );

	$sent = brikpanel_exit_survey_send(
		[
			'reason'  => $reason,
			'feature' => $feature,
			'comment' => $comment,
			'days'    => brikpanel_exit_survey_days(),
			'bp'      => BRIKPANEL_VERSION,
			'wp'      => (string) get_bloginfo( 'version' ),
			'wc'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'php'     => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			'locale'  => get_user_locale(),
		]
	);
	wp_send_json_success( [ 'sent' => $sent ] );
}
add_action( 'wp_ajax_brikpanel_exit_survey', 'brikpanel_exit_survey_ajax' );

/**
 * Posts one answer to the survey endpoint.
 *
 * @param array $payload Answer and context, already sanitized.
 * @return bool Whether the endpoint stored it.
 */
function brikpanel_exit_survey_send( array $payload ) {
	/**
	 * Where the answer goes. Staging and tests point it elsewhere; '' sends nothing.
	 *
	 * @param string $endpoint Full URL of the /exit route.
	 */
	$endpoint = (string) apply_filters( 'brikpanel_exit_survey_endpoint', BRIKPANEL_EXIT_SURVEY_ENDPOINT );
	if ( '' === $endpoint ) {
		return false;
	}
	$response = wp_remote_post(
		$endpoint,
		[
			'timeout'             => 4,
			'redirection'         => 0,
			'blocking'            => true,
			'sslverify'           => true,
			// WordPress's default user agent ends with the site address.
			'user-agent'          => 'BrikPanel/' . BRIKPANEL_VERSION,
			'headers'             => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			// Unescaped: \uXXXX escapes would make a 1000 character comment in Russian or Arabic six times longer.
			'body'                => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'limit_response_size' => 4096,
		]
	);
	return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
}
