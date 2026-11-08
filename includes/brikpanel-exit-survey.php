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
 * Speed numbers (8 November 2026), only with "It slowed down my site" and
 * "Something did not work": a summary of how pages arrived in this browser
 * (the page arrival watch's samples, includes/brikpanel-net-watch.php: how
 * many, how many got stuck, median server time, first byte and load, the
 * protocol shares) and a few facts about the server (object cache, active
 * plugin count, late background jobs, WP-Cron switched off, web server
 * family). The window lists the exact values under the comment box before
 * anything is sent. Why: the survey said "slow" six times in its first 98
 * answers and nobody could tell whether BrikPanel, the server or the
 * connection was slow.
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
 * Reasons that send the speed numbers along.
 *
 * @return string[]
 */
function brikpanel_exit_survey_diag_reasons() {
	return [ 'slow', 'broken' ];
}

/**
 * Facts about the server sent with the speed numbers. Computed once per
 * request: the window lists them, the answer reads them again on its own
 * request.
 *
 * @return array{oc: bool, plugins: int, asLate: int|null, cronOff: bool, server: string}
 */
function brikpanel_exit_survey_server_facts() {
	static $facts = null;
	if ( null !== $facts ) {
		return $facts;
	}
	$plugins = count( (array) get_option( 'active_plugins', [] ) );
	if ( is_multisite() ) {
		$plugins += count( (array) get_site_option( 'active_sitewide_plugins', [] ) );
	}
	// Pending background jobs whose time passed more than ten minutes ago: a
	// stuck WP-Cron or Action Scheduler (vensakimya, October 2026: 7,292).
	$late = null;
	if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_get_datetime_object' ) ) {
		try {
			$late = min( 100000, (int) ActionScheduler::store()->query_actions(
				[
					'status'       => 'pending',
					'date'         => as_get_datetime_object( time() - 10 * MINUTE_IN_SECONDS ),
					'date_compare' => '<=',
				],
				'count'
			) );
		} catch ( \Throwable $e ) {
			$late = null;
		}
	}
	// The family only, never the version string.
	$soft   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	$server = '' === $soft ? '' : 'other';
	foreach ( [ 'litespeed', 'openresty', 'nginx', 'apache', 'microsoft-iis', 'caddy' ] as $family ) {
		if ( false !== strpos( $soft, $family ) ) {
			$server = 'microsoft-iis' === $family ? 'iis' : $family;
			break;
		}
	}
	$facts = [
		// What brikpanel_has_object_cache() reads; that helper loads only past the WooCommerce guard.
		'oc'      => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
		'plugins' => $plugins,
		'asLate'  => $late,
		'cronOff' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'server'  => $server,
	];
	return $facts;
}

/**
 * The server facts as label and value, for the window's list.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function brikpanel_exit_survey_fact_rows() {
	$f    = brikpanel_exit_survey_server_facts();
	$num  = static function ( $n ) {
		return function_exists( 'brikpanel_number' ) ? brikpanel_number( (int) $n ) : (string) (int) $n;
	};
	$yes  = _x( 'Yes', 'deactivation survey: speed numbers', 'brikpanel' );
	$no   = _x( 'No', 'deactivation survey: speed numbers', 'brikpanel' );
	$name = [
		'litespeed' => 'LiteSpeed',
		'openresty' => 'OpenResty',
		'nginx'     => 'nginx',
		'apache'    => 'Apache',
		'iis'       => 'IIS',
		'caddy'     => 'Caddy',
		'other'     => _x( 'Other', 'deactivation survey: web server family', 'brikpanel' ),
	];
	$rows = [
		[ __( 'Object cache', 'brikpanel' ), $f['oc'] ? $yes : $no ],
		[ __( 'Active plugins', 'brikpanel' ), $num( $f['plugins'] ) ],
	];
	if ( null !== $f['asLate'] ) {
		$rows[] = [ __( 'Late background jobs', 'brikpanel' ), $num( $f['asLate'] ) ];
	}
	$rows[] = [ __( 'WP-Cron switched off', 'brikpanel' ), $f['cronOff'] ? $yes : $no ];
	if ( '' !== $f['server'] ) {
		$rows[] = [ __( 'Web server', 'brikpanel' ), $name[ $f['server'] ] ];
	}
	return $rows;
}

/**
 * Cleans the browser's summary of page arrivals: known keys, whole numbers,
 * sane ranges. Anything else is dropped. Keys: pages measured (n), pages not
 * ready after the watch's limit (stuck), stuck pages that never got there
 * (never), medians in milliseconds of the server time (srv), first byte
 * (ttfb), page ready (dom) and fully loaded (load), and the share of pages in
 * percent that came over HTTP/3, HTTP/2 and HTTP/1 (h3, h2, h1).
 *
 * @param mixed $raw Decoded JSON from the window.
 * @return array<string, int>
 */
function brikpanel_exit_survey_clean_net( $raw ) {
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$limits = [
		'n'     => 40,
		'stuck' => 40,
		'never' => 40,
		'srv'   => 600000,
		'ttfb'  => 600000,
		'dom'   => 600000,
		'load'  => 600000,
		'h3'    => 100,
		'h2'    => 100,
		'h1'    => 100,
	];
	$out = [];
	foreach ( $limits as $key => $max ) {
		if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
			$out[ $key ] = max( 0, min( $max, (int) round( (float) $raw[ $key ] ) ) );
		}
	}
	return $out;
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

	// The window uses the shared fields and buttons, and the shared number
	// formatter for the speed numbers. Without WooCommerce the shared parts
	// never register, so register those two under the same handles (the
	// formatter then writes plain digits: its store settings load past the
	// guard too).
	if ( ! wp_style_is( 'brikpanel_ui', 'registered' ) && file_exists( BRIKPANEL_PATH . 'front-end/shared/brikpanel-ui.css' ) ) {
		wp_register_style( 'brikpanel_ui', BRIKPANEL_URL . 'front-end/shared/brikpanel-ui.css', [], brikpanel_exit_survey_ver( 'front-end/shared/brikpanel-ui.css' ) );
	}
	if ( ! wp_script_is( 'brikpanel_format', 'registered' ) && file_exists( BRIKPANEL_PATH . 'front-end/shared/brikpanel-format.js' ) ) {
		wp_register_script( 'brikpanel_format', BRIKPANEL_URL . 'front-end/shared/brikpanel-format.js', [], brikpanel_exit_survey_ver( 'front-end/shared/brikpanel-format.js' ), false );
	}
	$sec = _x( '%s s', 'seconds', 'brikpanel' );

	wp_enqueue_style(
		'brikpanel-exit-survey',
		BRIKPANEL_URL . 'front-end/exit-survey/brikpanel-exit-survey.css',
		wp_style_is( 'brikpanel_ui', 'registered' ) ? [ 'brikpanel_ui' ] : [],
		brikpanel_exit_survey_ver( 'front-end/exit-survey/brikpanel-exit-survey.css' )
	);
	wp_enqueue_script(
		'brikpanel-exit-survey',
		BRIKPANEL_URL . 'front-end/exit-survey/brikpanel-exit-survey.js',
		wp_script_is( 'brikpanel_format', 'registered' ) ? [ 'brikpanel_format' ] : [],
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
			// Reasons that carry the speed numbers, and where the page arrival
			// watch keeps this site's samples in the browser (brikpanel-net-watch.js).
			'diag'    => brikpanel_exit_survey_diag_reasons(),
			'netKey'  => 'brikpanel.net.' . get_current_blog_id(),
			'i18n'    => [
				'sending'  => __( 'Sending…', 'brikpanel' ),
				'netNone'  => __( 'No pages measured in this browser yet.', 'brikpanel' ),
				'netPages' => __( 'Pages measured', 'brikpanel' ),
				/* translators: %s: a number of seconds with its unit, e.g. "15 s". */
				'netStuck' => sprintf( __( 'Pages not ready after %s', 'brikpanel' ), sprintf( $sec, defined( 'BRIKPANEL_NET_WATCH_STALL_MS' ) ? (int) round( BRIKPANEL_NET_WATCH_STALL_MS / 1000 ) : 15 ) ),
				'netNever' => __( 'Pages that never arrived', 'brikpanel' ),
				'netSrv'   => __( 'Server time, median', 'brikpanel' ),
				'netTtfb'  => __( 'First byte, median', 'brikpanel' ),
				'netDom'   => __( 'Page ready, median', 'brikpanel' ),
				'netLoad'  => __( 'Fully loaded, median', 'brikpanel' ),
				/* translators: %s: a connection type, e.g. "HTTP/3". */
				'netProto' => __( 'Pages over %s', 'brikpanel' ),
				'sec'      => $sec,
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
		// A dependency: WordPress drops a script whose dependency is gone.
		$keep[] = 'brikpanel_format';
	}
	return $keep;
}
add_filter( 'brikpanel_access_sweep_keep', 'brikpanel_exit_survey_sweep_keep' );

/**
 * The speed numbers an answer about slowness carries, listed under its comment
 * box as they will be sent. The server's part is printed here and read again
 * when the answer goes out (the late job count may have moved by then); the
 * browser's part is worked out and filled in by the script when the window
 * opens (it lives in this browser's storage, the server sees only the summary
 * that is sent).
 */
function brikpanel_exit_survey_render_diag() {
	?>
	<details class="bp-exit__diag">
		<summary><?php esc_html_e( 'Speed numbers sent with this answer', 'brikpanel' ); ?></summary>
		<p class="bp-exit__diag-text"><?php esc_html_e( 'They help tell a slow server from a slow connection.', 'brikpanel' ); ?></p>
		<p class="bp-exit__diag-head"><?php esc_html_e( 'This browser', 'brikpanel' ); ?></p>
		<dl class="bp-exit__facts" data-bp-exit-net></dl>
		<p class="bp-exit__diag-head"><?php esc_html_e( 'This server', 'brikpanel' ); ?></p>
		<dl class="bp-exit__facts">
			<?php foreach ( brikpanel_exit_survey_fact_rows() as $row ) : ?>
				<div class="bp-exit__fact"><dt><?php echo esc_html( $row[0] ); ?></dt><dd><?php echo esc_html( $row[1] ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
	</details>
	<?php
}

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
							<?php
							if ( in_array( $key, brikpanel_exit_survey_diag_reasons(), true ) ) {
								brikpanel_exit_survey_render_diag();
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>
			</fieldset>
			<?php // One of the two shows: the second for the reasons that also send the speed numbers. ?>
			<p class="bp-exit__privacy" data-bp-exit-privacy="plain">
				<?php esc_html_e( 'Pressing Send shares only your answer, how many days BrikPanel was in use, your BrikPanel, WordPress, WooCommerce and PHP versions and your admin language with brksoft.com. Your site address, email and store data are not sent.', 'brikpanel' ); ?>
				<a href="https://brksoft.com/privacy-policy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'brikpanel' ); ?></a>
			</p>
			<p class="bp-exit__privacy" data-bp-exit-privacy="diag" hidden>
				<?php esc_html_e( 'Pressing Send shares only your answer, the speed numbers listed above, how many days BrikPanel was in use, your BrikPanel, WordPress, WooCommerce and PHP versions and your admin language with brksoft.com. Your site address, email and store data are not sent.', 'brikpanel' ); ?>
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
	// The speed numbers, only with the reasons the window lists them for. The
	// browser's summary is a short JSON object of numbers; anything longer or
	// unknown is dropped (brikpanel_exit_survey_clean_net()).
	$diag = null;
	if ( in_array( $reason, brikpanel_exit_survey_diag_reasons(), true ) ) {
		$net = isset( $_POST['net'] ) ? sanitize_text_field( wp_unslash( $_POST['net'] ) ) : '';
		$diag = [
			'net'  => strlen( $net ) <= 1000 ? brikpanel_exit_survey_clean_net( json_decode( $net, true ) ) : [],
			'site' => brikpanel_exit_survey_server_facts(),
		];
	}
	// phpcs:enable
	$comment = function_exists( 'brikpanel_substr' ) ? brikpanel_substr( $comment, 0, BRIKPANEL_EXIT_SURVEY_COMMENT_MAX ) : $comment;

	// The browser stops waiting after a few seconds and deactivates; the answer should still leave.
	ignore_user_abort( true );

	$payload = [
		'reason'  => $reason,
		'feature' => $feature,
		'comment' => $comment,
		'days'    => brikpanel_exit_survey_days(),
		'bp'      => BRIKPANEL_VERSION,
		'wp'      => (string) get_bloginfo( 'version' ),
		'wc'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
		'php'     => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
		'locale'  => get_user_locale(),
	];
	if ( null !== $diag ) {
		$payload['diag'] = $diag;
	}
	$sent = brikpanel_exit_survey_send( $payload );
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
