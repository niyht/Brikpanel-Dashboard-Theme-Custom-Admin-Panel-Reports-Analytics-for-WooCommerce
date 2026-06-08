<?php
/**
 * BrikPanel — Ad Platforms settings page + AJAX + asset enqueue.
 *
 * Registers the top-level admin page (slug: brikpanel-ad-platforms) where
 * users connect Google Ads + Meta, pick a primary account per platform,
 * and trigger manual syncs.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Settings {

	const PAGE_SLUG    = 'brikpanel-ad-platforms';
	const NONCE_ACTION = 'brikpanel_ads_nonce';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX endpoints (oauth_start / oauth_disconnect live in the OAuth class).
		add_action( 'wp_ajax_brikpanel_ads_status',              [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_brikpanel_ads_list_accounts',       [ $this, 'ajax_list_accounts' ] );
		add_action( 'wp_ajax_brikpanel_ads_save_primary',        [ $this, 'ajax_save_primary' ] );
		add_action( 'wp_ajax_brikpanel_ads_save_login_customer', [ $this, 'ajax_save_login_customer' ] );
		add_action( 'wp_ajax_brikpanel_ads_sync_now',            [ $this, 'ajax_sync_now' ] );
		add_action( 'wp_ajax_brikpanel_ads_spend_breakdown',     [ $this, 'ajax_spend_breakdown' ] );
		add_action( 'wp_ajax_brikpanel_ads_view_log',            [ $this, 'ajax_view_log' ] );
		add_action( 'wp_ajax_brikpanel_ads_clear_log',           [ $this, 'ajax_clear_log' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		// Submenu under WooCommerce (not a top-level item). The page is a
		// set-and-forget connection screen, so it doesn't earn a permanent
		// sidebar slot — it lives in the WooCommerce menu, which BrikPanel's
		// modern navigation folds into the "More" group. The dashboard
		// "Connect ad accounts" CTA is the primary entry point.
		$menu_title = __( 'Ad Platforms', 'brikpanel' )
			. ' <span class="brikpanel-beta-badge" aria-label="'
			. esc_attr__( 'Beta feature', 'brikpanel' )
			. '">' . esc_html__( 'Beta', 'brikpanel' ) . '</span>';

		$hook = add_submenu_page(
			'woocommerce',
			__( 'Ad Platforms', 'brikpanel' ),
			$menu_title,
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Ad Platforms', 'brikpanel' );
			} );
		}
	}

	public function render_page() {
		$google_desc = Brikpanel_Ads_Tokens::describe( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE );
		$meta_desc   = Brikpanel_Ads_Tokens::describe( Brikpanel_Ads_Tokens::PLATFORM_META );

		$google_last_sync = (array) get_option( 'brikpanel_ads_last_sync_' . Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, [] );
		$meta_last_sync   = (array) get_option( 'brikpanel_ads_last_sync_' . Brikpanel_Ads_Tokens::PLATFORM_META, [] );

		$google_backfill = (array) get_option( 'brikpanel_ads_backfill_status_' . Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, [] );
		$meta_backfill   = (array) get_option( 'brikpanel_ads_backfill_status_' . Brikpanel_Ads_Tokens::PLATFORM_META, [] );

		$flash = [
			'tone'    => isset( $_GET['brikpanel_ads_flash'] ) ? sanitize_key( wp_unslash( $_GET['brikpanel_ads_flash'] ) ) : '',
			'message' => isset( $_GET['brikpanel_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['brikpanel_msg'] ) ) : '',
		];

		// Both platforms ship open by default; the lock is an operator escape
		// hatch (constant / option) for taking a platform down.
		$google_locked = function_exists( 'brikpanel_ads_google_locked' )
			? brikpanel_ads_google_locked()
			: true;
		$meta_locked = function_exists( 'brikpanel_ads_meta_locked' )
			? brikpanel_ads_meta_locked()
			: true;

		// "Coming soon" skin over a fully functional connection (button works,
		// OAuth + account binding live, connected state falls through to the
		// normal body). Ignored when the platform is hard-locked above.
		$google_disguised = ! $google_locked
			&& function_exists( 'brikpanel_ads_google_disguised' )
			&& brikpanel_ads_google_disguised();
		$meta_disguised = ! $meta_locked
			&& function_exists( 'brikpanel_ads_meta_disguised' )
			&& brikpanel_ads_meta_disguised();

		include BRIKPANEL_ADS_DIR . 'views/page.php';
	}

	// =========================================================================
	// Asset enqueue
	// =========================================================================

	public function enqueue_assets( $hook ) {
		// As a WooCommerce submenu the hook suffix is
		// "woocommerce_page_<slug>". Keep the toplevel_page_ check too so a
		// future move back to a top-level menu doesn't silently drop assets.
		if ( $hook !== 'woocommerce_page_' . self::PAGE_SLUG
			&& $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style(
			'brikpanel-ads',
			BRIKPANEL_ADS_URL . 'assets/brikpanel-ad-platforms.css',
			[],
			BRIKPANEL_VERSION
		);
		wp_enqueue_script(
			'brikpanel-ads',
			BRIKPANEL_ADS_URL . 'assets/brikpanel-ad-platforms.js',
			[],
			BRIKPANEL_VERSION,
			true
		);
		wp_localize_script(
			'brikpanel-ads',
			'BrikpanelAds',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => [
					'connecting'         => __( 'Connecting…', 'brikpanel' ),
					'disconnect_confirm' => __( 'Disconnect this platform? Your synced spend data will be deleted.', 'brikpanel' ),
					'syncing'            => __( 'Syncing…', 'brikpanel' ),
					'queued'             => __( 'Sync queued. Refresh the page in a moment.', 'brikpanel' ),
					'saved'              => __( 'Saved.', 'brikpanel' ),
					'loading_accounts'   => __( 'Loading accounts…', 'brikpanel' ),
					'no_accounts'        => __( 'No ad accounts found for this connection.', 'brikpanel' ),
					'generic_error'      => __( 'Something went wrong. Please try again.', 'brikpanel' ),
					'log_empty'          => __( 'No errors logged.', 'brikpanel' ),
					'connected_label'    => __( 'Connected', 'brikpanel' ),
					'not_connected_label'=> __( 'Not connected', 'brikpanel' ),
					'pick_account_first' => __( 'Pick a primary account first.', 'brikpanel' ),
					'manager_suffix'     => __( 'Manager', 'brikpanel' ),
					/* translators: %1$d = chunks completed, %2$d = total chunks (each chunk is 90 days). */
					'backfill_progress'  => __( 'Loading history… %1$d of %2$d 90-day chunks done', 'brikpanel' ),
					'just_now'           => __( 'Just now', 'brikpanel' ),
					'platform_google'    => __( 'Google Ads', 'brikpanel' ),
					'platform_meta'      => __( 'Meta Ads', 'brikpanel' ),
					'insights'           => [
						'loading'      => __( 'Loading imported data…', 'brikpanel' ),
						'empty'        => __( 'No spend data imported yet. It appears here once the first sync or backfill completes.', 'brikpanel' ),
						'error'        => __( 'Could not load imported data.', 'brikpanel' ),
						/* translators: %s = ad account ID */
						'account'      => __( 'Account %s', 'brikpanel' ),
						'span'         => __( '%1$s to %2$s', 'brikpanel' ),
						/* translators: %s = number of days with data */
						'days_with_data' => __( '%s days with spend', 'brikpanel' ),
						'kpi_spend'    => __( 'Total spend', 'brikpanel' ),
						'kpi_impr'     => __( 'Impressions', 'brikpanel' ),
						'kpi_clicks'   => __( 'Clicks', 'brikpanel' ),
						'kpi_ctr'      => __( 'Avg CTR', 'brikpanel' ),
						'kpi_cpc'      => __( 'Avg CPC', 'brikpanel' ),
						'kpi_cpm'      => __( 'Avg CPM', 'brikpanel' ),
						'col_month'    => __( 'Month', 'brikpanel' ),
						'col_spend'    => __( 'Spend', 'brikpanel' ),
						'col_impr'     => __( 'Impressions', 'brikpanel' ),
						'col_clicks'   => __( 'Clicks', 'brikpanel' ),
						'col_ctr'      => __( 'CTR', 'brikpanel' ),
						'col_cpc'      => __( 'CPC', 'brikpanel' ),
						'col_days'     => __( 'Days', 'brikpanel' ),
						'total_row'    => __( 'All time', 'brikpanel' ),
					],
				],
			]
		);
	}

	// =========================================================================
	// AJAX helpers
	// =========================================================================

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	private function sanitize_platform( $value ) {
		$p = sanitize_key( (string) $value );
		return in_array( $p, [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ], true ) ? $p : '';
	}

	// =========================================================================
	// AJAX — connection / sync status (polled by JS after Connect / Sync now)
	// =========================================================================

	public function ajax_status() {
		$this->check_auth();
		$out = [];
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $p ) {
			$desc = Brikpanel_Ads_Tokens::describe( $p );
			$last = (array) get_option( 'brikpanel_ads_last_sync_' . $p, [] );
			$back = (array) get_option( 'brikpanel_ads_backfill_status_' . $p, [] );
			$out[ $p ] = [
				'connected'         => (bool) $desc['connected'],
				'email'             => (string) $desc['email'],
				'primary_account'   => (string) $desc['primary_account'],
				'login_customer_id' => (string) $desc['login_customer_id'],
				'last_sync_ts'      => (int)    ( $last['ts'] ?? 0 ),
				'last_sync_ok'      => (bool)   ( $last['ok'] ?? false ),
				'backfill'          => [
					'total'     => (int) ( $back['total_chunks'] ?? 0 ),
					'completed' => (int) ( $back['completed_chunks'] ?? 0 ),
					'error'     => (string) ( $back['last_error'] ?? '' ),
				],
			];
		}
		wp_send_json_success( $out );
	}

	// =========================================================================
	// AJAX — list ad accounts under the connected user (for primary picker)
	// =========================================================================

	public function ajax_list_accounts() {
		$this->check_auth();
		$platform = $this->sanitize_platform( $_POST['platform'] ?? '' );
		if ( $platform === '' ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'brikpanel' ) ], 400 );
		}
		if ( ! Brikpanel_Ads_Tokens::is_connected( $platform ) ) {
			wp_send_json_error( [ 'message' => __( 'Connect this platform first.', 'brikpanel' ) ], 400 );
		}

		try {
			if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
				$accounts = ( new Brikpanel_Ads_Google_Client() )->list_accounts();
			} else {
				$accounts = ( new Brikpanel_Ads_Meta_Client() )->list_accounts();
			}
		} catch ( \Throwable $e ) {
			Brikpanel_Ads_Logger::log( $platform, 'list_accounts failed: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}

		wp_send_json_success( [ 'accounts' => $accounts ] );
	}

	// =========================================================================
	// AJAX — save primary account selection (kicks backfill if first pick)
	// =========================================================================

	public function ajax_save_primary() {
		$this->check_auth();
		$platform   = $this->sanitize_platform( $_POST['platform'] ?? '' );
		$account_id = sanitize_text_field( wp_unslash( $_POST['account_id'] ?? '' ) );

		if ( $platform === '' || $account_id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Pick an account before saving.', 'brikpanel' ) ], 400 );
		}
		if ( ! Brikpanel_Ads_Tokens::is_connected( $platform ) ) {
			wp_send_json_error( [ 'message' => __( 'Connect this platform first.', 'brikpanel' ) ], 400 );
		}

		// If the user switched to a different account, wipe data for the
		// previous account so the dashboard doesn't show mixed totals.
		$desc = Brikpanel_Ads_Tokens::describe( $platform );
		$prev = (string) ( $desc['primary_account'] ?? '' );
		if ( $prev !== '' && $prev !== $account_id ) {
			Brikpanel_Ads_Store::delete_account( $platform, $prev );
		}

		Brikpanel_Ads_Tokens::set_meta( $platform, 'primary_account', $account_id );

		// Trigger a backfill if this account has no rows yet.
		if ( ! Brikpanel_Ads_Store::has_data( $platform, $account_id ) ) {
			( new Brikpanel_Ads_Sync() )->schedule_backfill( $platform, $account_id );
			$message = __( 'Account saved. Loading 3 years of history in the background. Refresh the page in a few minutes to see it.', 'brikpanel' );
		} else {
			$message = __( 'Account saved.', 'brikpanel' );
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	// =========================================================================
	// AJAX — save Google login_customer_id (MCC users)
	// =========================================================================

	public function ajax_save_login_customer() {
		$this->check_auth();
		$value = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['login_customer_id'] ?? '' ) );
		Brikpanel_Ads_Tokens::set_meta( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, 'login_customer_id', $value );
		wp_send_json_success();
	}

	// =========================================================================
	// AJAX — manual "Sync now" for one platform
	// =========================================================================

	public function ajax_sync_now() {
		$this->check_auth();
		$platform = $this->sanitize_platform( $_POST['platform'] ?? '' );
		if ( $platform === '' ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'brikpanel' ) ], 400 );
		}

		// Bump time limit so the 7-day pull comfortably finishes inline. The
		// PHP default 30s is enough for a 7-day window in both APIs, but the
		// retry / refresh loop can push it over on slow networks.
		@set_time_limit( 90 );

		try {
			$result = ( new Brikpanel_Ads_Sync() )->run_inline( $platform );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: row count, 2: day count */
				__( 'Synced %1$d row(s) across %2$d day(s).', 'brikpanel' ),
				(int) $result['rows'],
				(int) $result['days']
			),
			'result' => $result,
		] );
	}

	// =========================================================================
	// AJAX — imported spend data (monthly breakdown + summary per platform)
	// =========================================================================

	public function ajax_spend_breakdown() {
		$this->check_auth();

		$out = [];
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $p ) {
			$desc = Brikpanel_Ads_Tokens::describe( $p );
			$account_id = (string) $desc['primary_account'];

			if ( ! $desc['connected'] || $account_id === '' ) {
				$out[ $p ] = [ 'connected' => false ];
				continue;
			}

			$summary = Brikpanel_Ads_Store::account_summary( $p, $account_id );
			$months  = Brikpanel_Ads_Store::monthly_breakdown( $p, $account_id );

			$out[ $p ] = [
				'connected'  => true,
				'account_id' => $account_id,
				'email'      => (string) $desc['email'],
				'currency'   => $summary ? (string) $summary['currency'] : '',
				'summary'    => $summary,   // null until the first row lands
				'months'     => $months,
			];
		}

		wp_send_json_success( $out );
	}

	// =========================================================================
	// AJAX — error log
	// =========================================================================

	public function ajax_view_log() {
		$this->check_auth();
		$flow = sanitize_key( (string) ( $_POST['flow'] ?? '' ) );
		$pool = Brikpanel_Ads_Logger::recent( $flow === '' ? 50 : Brikpanel_Ads_Logger::MAX_ENTRIES );

		$keep = [];
		if ( $flow === '' ) {
			$keep = $pool;
		} else {
			$visible = [ $flow, 'oauth', 'sync' ];
			foreach ( $pool as $e ) {
				if ( in_array( (string) ( $e['flow'] ?? '' ), $visible, true ) ) {
					$keep[] = $e;
				}
			}
			$keep = array_slice( $keep, 0, 50 );
		}

		foreach ( $keep as &$e ) {
			$e['ts_display'] = $e['ts']
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['ts'] )
				: '';
		}
		wp_send_json_success( [ 'entries' => $keep ] );
	}

	public function ajax_clear_log() {
		$this->check_auth();
		Brikpanel_Ads_Logger::clear();
		wp_send_json_success();
	}
}
