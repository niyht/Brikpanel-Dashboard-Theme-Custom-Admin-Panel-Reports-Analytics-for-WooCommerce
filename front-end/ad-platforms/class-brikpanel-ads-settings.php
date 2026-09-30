<?php
/**
 * BrikPanel — Ad Platforms settings page + AJAX + asset enqueue.
 *
 * Registers the top-level admin page (slug: brikpanel-ad-platforms) where
 * users connect Google Ads + Meta, tick the ad accounts each platform pulls
 * spend from, and trigger manual syncs.
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

	/**
	 * Transient prefix for the last account list a platform returned.
	 *
	 * Account names shown on the card come from here, never from the browser:
	 * the save request only carries IDs.
	 */
	const ACCOUNT_LIST_TRANSIENT = 'brikpanel_ads_account_list_';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX endpoints (oauth_start / oauth_disconnect live in the OAuth class).
		add_action( 'wp_ajax_brikpanel_ads_status',              [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_brikpanel_ads_list_accounts',       [ $this, 'ajax_list_accounts' ] );
		add_action( 'wp_ajax_brikpanel_ads_save_accounts',       [ $this, 'ajax_save_accounts' ] );
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
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Ad Platforms', 'brikpanel' ),
			__( 'Ad Platforms', 'brikpanel' ),
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

		$google_backfill = Brikpanel_Ads_Sync::backfill_progress( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, $google_desc['accounts'] );
		$meta_backfill   = Brikpanel_Ads_Sync::backfill_progress( Brikpanel_Ads_Tokens::PLATFORM_META, $meta_desc['accounts'] );

		// The rows of each card's account list: ticked accounts, then accounts
		// that still have imported spend but are not ticked (their spend still
		// counts on the dashboard, so the card must show them).
		$google_rows = $google_desc['connected'] ? self::account_rows( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, $google_desc ) : [];
		$meta_rows   = $meta_desc['connected'] ? self::account_rows( Brikpanel_Ads_Tokens::PLATFORM_META, $meta_desc ) : [];
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		// Set when a token renewal failed permanently. Surfaced as a banner so
		// a dead connection never hides behind a green "Connected" pill.
		$google_stale = Brikpanel_Ads_Tokens::needs_reconnect( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) !== '';
		$meta_stale   = Brikpanel_Ads_Tokens::needs_reconnect( Brikpanel_Ads_Tokens::PLATFORM_META ) !== '';

		// There ARE stored credentials, but this site cannot decrypt them — an
		// address change, a salt rotation, a server without libsodium. Without
		// this the card just said "Not connected", which is what sent the
		// merchant who reported the bug digging through the database for hours.
		// It is a property of the whole vault, so both cards show it.
		$vault_unreadable = Brikpanel_Ads_Tokens::is_unreadable();

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
		// filemtime versions: with the plain plugin version an edited file
		// kept being served from the browser cache between releases.
		$ads_dir = BRIKPANEL_PATH . 'front-end/ad-platforms/assets/';
		$fit_dep = function_exists( 'brikpanel_fit_table_dep' );
		wp_enqueue_style(
			'brikpanel-ads',
			BRIKPANEL_ADS_URL . 'assets/brikpanel-ad-platforms.css',
			array_merge(
				$fit_dep ? brikpanel_fit_table_dep( 'style' ) : [],
				function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'ui', 'style' ) : []
			),
			@filemtime( $ads_dir . 'brikpanel-ad-platforms.css' ) ?: BRIKPANEL_VERSION // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falls back to the plugin version.
		);
		wp_enqueue_script(
			'brikpanel-ads',
			BRIKPANEL_ADS_URL . 'assets/brikpanel-ad-platforms.js',
			array_merge( $fit_dep ? brikpanel_fit_table_dep() : [], function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'format' ) : [] ),
			@filemtime( $ads_dir . 'brikpanel-ad-platforms.js' ) ?: BRIKPANEL_VERSION, // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falls back to the plugin version.
			true
		);
		wp_localize_script(
			'brikpanel-ads',
			'BrikpanelAds',
			[
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE_ACTION ),
				'storeCurrency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
				'i18n'          => [
					'connecting'         => __( 'Connecting…', 'brikpanel' ),
					'disconnect_confirm' => __( 'Disconnect this platform? Your synced spend data will be deleted.', 'brikpanel' ),
					'syncing'            => __( 'Syncing…', 'brikpanel' ),
					'queued'             => __( 'Sync queued. Refresh the page in a moment.', 'brikpanel' ),
					'saved'              => __( 'Saved.', 'brikpanel' ),
					'loading_accounts'   => __( 'Loading accounts…', 'brikpanel' ),
					'no_accounts'        => __( 'No ad accounts found for this connection.', 'brikpanel' ),
					'generic_error'      => __( 'Something went wrong. Please try again.', 'brikpanel' ),
					'log_empty'          => __( 'Nothing logged yet.', 'brikpanel' ),
					/* translators: severity label on a log line that reports routine activity, not a failure. */
					'log_note_label'     => _x( 'Note', 'log entry severity', 'brikpanel' ),
					'log_error_label'    => __( 'Error', 'brikpanel' ),
					'backfill_halted'    => __( 'History import stopped', 'brikpanel' ),
					'only_managers'      => __( 'Only manager accounts were found. A manager account holds no spend of its own, so pick the ad account you actually advertise from: enter its ID below and put the manager ID in the Manager (MCC) field.', 'brikpanel' ),
					'manager_picked'     => __( 'This is a manager account. It usually holds no spend of its own, so the import may come back empty. If it does, use the ID of the ad account you advertise from instead.', 'brikpanel' ),
					'connected_label'    => __( 'Connected', 'brikpanel' ),
					'not_connected_label'=> __( 'Not connected', 'brikpanel' ),
					'pick_accounts_first'=> __( 'Tick at least one ad account first.', 'brikpanel' ),
					'remove_confirm'     => __( 'The spend imported from the accounts you unticked will be deleted. Continue?', 'brikpanel' ),
					'not_selected'       => _x( 'Not selected', 'ad account that has imported spend but is not ticked', 'brikpanel' ),
					'currency_note'      => function_exists( 'brikpanel_js_plural' ) ? brikpanel_js_plural( self::currency_note_noop() ) : [ 'forms' => [ '%s' ], 'en' => true ],
					'manual_empty'       => __( 'Enter an ad account ID first.', 'brikpanel' ),
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
						'months'       => __( 'Monthly breakdown', 'brikpanel' ),
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

	/**
	 * Normalise a picker- or manually-entered ad account ID to the canonical
	 * form each platform's API expects. Returns '' when the value cannot be a
	 * valid ID so the caller can reject it.
	 *
	 * Meta: "act_<digits>". Bare digits are accepted and get the "act_" prefix
	 * so a merchant can paste just the number from Ads Manager. Google: the
	 * numeric customer ID, digits only ("123-456-7890" dashes are stripped).
	 */
	private static function normalize_account_id( $platform, $id ) {
		$id = trim( (string) $id );
		if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_META ) {
			$id = preg_replace( '/\s+/', '', $id );
			if ( preg_match( '/^\d+$/', $id ) ) {
				$id = 'act_' . $id;
			}
			return preg_match( '/^act_\d+$/', $id ) ? $id : '';
		}
		// Google Ads customer ID: digits only.
		return preg_replace( '/\D/', '', $id );
	}

	// =========================================================================
	// AJAX — connection / sync status (polled by JS after Connect / Sync now)
	// =========================================================================

	public function ajax_status() {
		$this->check_auth();
		$out = [];
		// A vault-wide property, reported per platform so a consumer reading
		// connected=false is never left guessing whether the merchant never
		// connected or the site just cannot open what it stored.
		$unreadable = Brikpanel_Ads_Tokens::is_unreadable();
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $p ) {
			$desc = Brikpanel_Ads_Tokens::describe( $p );
			$last = (array) get_option( 'brikpanel_ads_last_sync_' . $p, [] );
			$back = Brikpanel_Ads_Sync::backfill_progress( $p, $desc['accounts'] );
			$out[ $p ] = [
				'connected'         => (bool) $desc['connected'],
				'email'             => (string) $desc['email'],
				'primary_account'   => (string) $desc['primary_account'],
				'accounts'          => $desc['accounts'],
				'login_customer_id' => (string) $desc['login_customer_id'],
				'needs_reconnect'   => Brikpanel_Ads_Tokens::needs_reconnect( $p ) !== '',
				'vault_unreadable'  => $unreadable,
				'last_sync_ts'      => (int)    ( $last['ts'] ?? 0 ),
				'last_sync_ok'      => (bool)   ( $last['ok'] ?? false ),
				'backfill'          => [
					'total'     => (int) ( $back['total_chunks'] ?? 0 ),
					'completed' => (int) ( $back['completed_chunks'] ?? 0 ),
					// Resolved here, not at write time: the background worker
					// that halted the import does not run in the locale the
					// merchant is reading this card in.
					'error'     => Brikpanel_Ads_Sync::halt_reason_text( $back['halt_reason'] ?? '' )
						?: (string) ( $back['last_error'] ?? '' ),
					'halted'    => ! empty( $back['halted'] ),
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

		// Remembered for an hour so the card can name the accounts the merchant
		// ticks. The save request that follows carries IDs only.
		$known = [];
		foreach ( (array) $accounts as $acc ) {
			$id = (string) ( $acc['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$known[ $id ] = [
				'name'         => (string) ( $acc['name'] ?? '' ),
				'currency'     => strtoupper( (string) ( $acc['currency'] ?? '' ) ),
				'status_label' => (string) ( $acc['status_label'] ?? '' ),
				'is_manager'   => ! empty( $acc['is_manager'] ),
			];
		}
		set_transient( self::ACCOUNT_LIST_TRANSIENT . $platform, $known, HOUR_IN_SECONDS );

		wp_send_json_success( [ 'accounts' => $accounts ] );
	}

	/** Drop the remembered account list (disconnect). */
	public static function forget_account_list( $platform ) {
		delete_transient( self::ACCOUNT_LIST_TRANSIENT . $platform );
	}

	/**
	 * What the platform last said about its accounts (name, currency, state).
	 *
	 * @param string $platform
	 * @return array<string, array{name:string, currency:string, status_label:string, is_manager:bool}>
	 */
	private static function known_accounts( $platform ) {
		$known = get_transient( self::ACCOUNT_LIST_TRANSIENT . $platform );
		return is_array( $known ) ? $known : [];
	}

	// =========================================================================
	// AJAX — save the ticked ad accounts (queues the history they miss)
	// =========================================================================

	/**
	 * Save the account selection from the card.
	 *
	 * POST: platform, mode (replace | add), account_ids[], base_ids[].
	 *   - replace: the ticked list becomes the selection. base_ids is the list
	 *     the page was showing; if the stored list has changed since (another
	 *     tab, another admin) the save is refused, so a stale page can never
	 *     delete spend of an account it did not even show.
	 *   - add: the IDs are appended ("Can't find your account?"). Nothing is
	 *     removed, so this mode never deletes anything.
	 */
	public function ajax_save_accounts() {
		$this->check_auth();
		$platform = $this->sanitize_platform( $_POST['platform'] ?? '' );
		if ( $platform === '' ) {
			wp_send_json_error( [ 'message' => __( 'Unknown platform.', 'brikpanel' ) ], 400 );
		}
		$mode = isset( $_POST['mode'] ) && 'add' === $_POST['mode'] ? 'add' : 'replace';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every entry is sanitised in apply_account_selection().
		$ids  = isset( $_POST['account_ids'] ) && is_array( $_POST['account_ids'] ) ? wp_unslash( $_POST['account_ids'] ) : [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
		$base = isset( $_POST['base_ids'] ) && is_array( $_POST['base_ids'] ) ? wp_unslash( $_POST['base_ids'] ) : [];

		$result = self::apply_account_selection( $platform, $mode, $ids, $base );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ], (int) ( $result['status'] ?? 400 ) );
		}
		wp_send_json_success( [ 'message' => $result['message'] ] );
	}

	/**
	 * Validate and apply an account selection. Separate from the AJAX wrapper
	 * so it can be tested without a request.
	 *
	 * Order matters, as it did with the single account: the new selection is
	 * stored first, because a history chunk running in a worker right now
	 * checks it before and after its fetch, and only then is the spend of the
	 * accounts that left the list deleted. If the selection cannot be stored,
	 * nothing is deleted.
	 *
	 * @param string $platform
	 * @param string $mode     'replace' or 'add'.
	 * @param array  $raw_ids  Unsanitised IDs from the request.
	 * @param array  $base_ids The list the page was showing (replace mode).
	 * @return array{ok:bool, message:string, status?:int}
	 */
	public static function apply_account_selection( $platform, $mode, array $raw_ids, array $base_ids = [] ) {
		$fail = static function ( $message, $status = 400 ) {
			return [ 'ok' => false, 'message' => $message, 'status' => $status ];
		};
		if ( ! Brikpanel_Ads_Tokens::is_connected( $platform ) ) {
			return $fail( __( 'Connect this platform first.', 'brikpanel' ) );
		}

		// What is stored right now, not what this request's cache remembers.
		$stored = Brikpanel_Ads_Tokens::selected_accounts_fresh( $platform );

		$ids = [];
		foreach ( array_slice( $raw_ids, 0, 200 ) as $raw ) {
			if ( ! is_scalar( $raw ) ) {
				continue;
			}
			$raw = trim( sanitize_text_field( (string) $raw ) );
			if ( $raw === '' ) {
				continue;
			}
			// An ID that is already stored is taken as it is: it was accepted
			// once, possibly by an older build with other rules, and the
			// merchant did not type it now.
			$id = in_array( $raw, $stored, true ) ? $raw : self::normalize_account_id( $platform, $raw );
			if ( $id === '' ) {
				return $fail( __( 'That does not look like a valid ad account ID.', 'brikpanel' ) );
			}
			if ( ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		if ( $mode === 'add' ) {
			if ( ! $ids ) {
				return $fail( __( 'Enter an ad account ID first.', 'brikpanel' ) );
			}
			$new = $stored;
			foreach ( $ids as $id ) {
				if ( ! in_array( $id, $new, true ) ) {
					$new[] = $id;
				}
			}
		} else {
			$base = [];
			foreach ( array_slice( $base_ids, 0, 200 ) as $raw ) {
				if ( is_scalar( $raw ) && trim( (string) $raw ) !== '' ) {
					$base[] = trim( sanitize_text_field( (string) $raw ) );
				}
			}
			$base = array_values( array_unique( $base ) );
			sort( $base );
			$now = $stored;
			sort( $now );
			if ( $base !== $now ) {
				return $fail( __( 'The account list was changed in another tab. Reload the page and try again.', 'brikpanel' ), 409 );
			}
			$new = $ids;
		}

		if ( ! $new ) {
			return $fail( __( 'Choose at least one ad account.', 'brikpanel' ) );
		}
		if ( count( $new ) > Brikpanel_Ads_Tokens::MAX_ACCOUNTS ) {
			return $fail( sprintf(
				/* translators: %d: the most ad accounts one platform can pull spend from */
				__( 'You can choose up to %d ad accounts.', 'brikpanel' ),
				Brikpanel_Ads_Tokens::MAX_ACCOUNTS
			) );
		}

		// Names come from the account list this server fetched, never from the
		// request; an account without one keeps the name it had.
		$known = self::known_accounts( $platform );
		$names = Brikpanel_Ads_Tokens::describe( $platform )['account_names'];
		foreach ( $new as $id ) {
			if ( isset( $known[ $id ]['name'] ) && $known[ $id ]['name'] !== '' ) {
				$names[ $id ] = $known[ $id ]['name'];
			}
		}

		if ( ! Brikpanel_Ads_Tokens::set_accounts( $platform, $new, $names ) ) {
			return $fail( __( 'Could not save the ad accounts. Please try again.', 'brikpanel' ), 500 );
		}

		if ( $mode !== 'add' ) {
			// Every account with stored spend that is not in the new list: the
			// ones just unticked, and any left over from before a reconnect,
			// which the card listed as "Not selected".
			$removed = array_values( array_diff( Brikpanel_Ads_Store::accounts_with_data( $platform ), $new ) );
			if ( $removed ) {
				$deleted = Brikpanel_Ads_Store::delete_other_accounts( $platform, $new );
				// The dashboard keeps its figures for up to ten minutes; without
				// this the deleted spend would still show there.
				if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
					brikpanel_bust_data_caches();
				}
				Brikpanel_Ads_Logger::note(
					'sync',
					'Ad accounts changed on ' . $platform . ': removed ' . implode( ', ', $removed ) . ', deleted ' . (int) $deleted . ' stored spend row(s).'
				);
			}
		}

		$queued = Brikpanel_Ads_Sync::queue_history( $platform, $new );

		$message = _n( 'Ad account saved.', 'Ad accounts saved.', count( $new ), 'brikpanel' );
		if ( $queued ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of ad accounts whose spend history is being imported */
				_n(
					'Loading the spend history of %d account in the background. Refresh the page in a few minutes to see it.',
					'Loading the spend history of %d accounts in the background. Refresh the page in a few minutes to see it.',
					count( $queued ),
					'brikpanel'
				),
				count( $queued )
			);
		}
		return [ 'ok' => true, 'message' => $message ];
	}

	/**
	 * Rows of one card's account list.
	 *
	 * @param string $platform
	 * @param array  $desc Brikpanel_Ads_Tokens::describe() of the platform.
	 * @return array<int, array{id:string, name:string, currency:string, status_label:string, is_manager:bool, selected:bool, has_data:bool}>
	 */
	private static function account_rows( $platform, array $desc ) {
		$known     = self::known_accounts( $platform );
		$summaries = Brikpanel_Ads_Store::account_summaries( $platform );
		$ids       = array_values( array_unique( array_merge( $desc['accounts'], array_keys( $summaries ) ) ) );
		$rows      = [];
		foreach ( $ids as $id ) {
			$id   = (string) $id;
			$name = (string) ( $desc['account_names'][ $id ] ?? ( $known[ $id ]['name'] ?? '' ) );
			$rows[] = [
				'id'           => $id,
				'name'         => $name !== $id ? $name : '',
				'currency'     => strtoupper( (string) ( $summaries[ $id ]['currency'] ?? ( $known[ $id ]['currency'] ?? '' ) ) ),
				'status_label' => (string) ( $known[ $id ]['status_label'] ?? '' ),
				'is_manager'   => ! empty( $known[ $id ]['is_manager'] ),
				'selected'     => in_array( $id, $desc['accounts'], true ),
				'has_data'     => isset( $summaries[ $id ] ),
			];
		}
		return $rows;
	}

	/**
	 * The card's note about ticked accounts in another currency. One message
	 * for PHP (_n) and JS (brikpanel_js_plural), so both share a translation.
	 *
	 * ROAS has one figure for the whole store and no exchange rate to bring
	 * the spend into the store currency, so the dashboard leaves it empty for
	 * a period with such spend, and Net profit leaves that spend out.
	 *
	 * @return array Nooped plural.
	 */
	public static function currency_note_noop() {
		/* translators: %s: ad account names or IDs, comma separated */
		return _n_noop(
			'%s uses a different currency from your store. Its spend is left out of Net profit, and ROAS stays empty for periods with its spend.',
			'%s use a different currency from your store. Their spend is left out of Net profit, and ROAS stays empty for periods with their spend.',
			'brikpanel'
		);
	}

	// =========================================================================
	// AJAX — save Google login_customer_id (MCC users)
	// =========================================================================

	public function ajax_save_login_customer() {
		$this->check_auth();
		$value = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['login_customer_id'] ?? '' ) );

		// set_meta() refuses to write when the platform has no vault entry.
		// The return value used to be discarded, so a merchant whose connection
		// had lapsed typed their manager ID, saw "Saved.", and then spent the
		// afternoon wondering why every Google call still failed.
		if ( ! Brikpanel_Ads_Tokens::is_connected( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) ) {
			wp_send_json_error( [ 'message' => __( 'Connect this platform first.', 'brikpanel' ) ], 400 );
		}
		if ( ! Brikpanel_Ads_Tokens::set_meta( Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, 'login_customer_id', $value ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not save the manager account ID. Please try again.', 'brikpanel' ) ], 500 );
		}
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
		// retry / refresh loop can push it over on slow networks. The pull
		// itself resets it per account and stops starting new accounts once
		// its budget is spent.
		@set_time_limit( 90 );

		try {
			$result = ( new Brikpanel_Ads_Sync() )->run_inline( $platform, time() + Brikpanel_Ads_Sync::INLINE_BUDGET_SECONDS );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}

		// Distinct days, not rows: two accounts synced over the same week are
		// still one week of spend data.
		$message = sprintf(
			/* translators: %d = number of days of spend data imported. */
			_n( 'Synced %d day of spend data.', 'Synced %d days of spend data.', (int) $result['days'], 'brikpanel' ),
			(int) $result['days']
		);
		$tone = 'success';
		if ( ! empty( $result['failed'] ) ) {
			$account_id = (string) array_key_first( $result['failed'] );
			$message   .= ' ' . Brikpanel_Ads_Sync::account_error( $account_id, (string) $result['failed'][ $account_id ] );
			$tone       = 'error';
		}
		if ( ! empty( $result['deferred'] ) ) {
			$message .= ' ' . __( 'The other accounts will be updated by the next daily sync.', 'brikpanel' );
		}

		wp_send_json_success( [
			'message' => $message,
			'tone'    => $tone,
			'result'  => $result,
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
			if ( ! $desc['connected'] ) {
				$out[ $p ] = [ 'connected' => false ];
				continue;
			}

			// One query each for every account of the platform. Ticked accounts
			// come first in their own order, then any account whose spend is
			// still stored although it is not ticked: that spend still counts on
			// the dashboard, so it is shown here too, marked.
			$summaries = Brikpanel_Ads_Store::account_summaries( $p );
			$months    = Brikpanel_Ads_Store::monthly_breakdown_by_account( $p );
			$ids       = array_values( array_unique( array_merge( $desc['accounts'], array_keys( $summaries ) ) ) );

			$accounts = [];
			$symbols  = [];
			foreach ( $ids as $id ) {
				$id = (string) $id;
				if ( ! isset( $summaries[ $id ] ) ) {
					continue; // nothing imported yet
				}
				$account_months = $months[ $id ] ?? [];

				// Symbols of the account's currencies: spend is shown in the ad
				// account's own currency, laid out like the store's prices.
				foreach ( array_merge( [ (string) $summaries[ $id ]['currency'] ], wp_list_pluck( $account_months, 'currency' ) ) as $code ) {
					$code = strtoupper( (string) $code );
					if ( '' !== $code && ! isset( $symbols[ $code ] ) && function_exists( 'get_woocommerce_currency_symbol' ) ) {
						$symbols[ $code ] = html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
					}
				}

				$accounts[] = [
					'account_id' => $id,
					'name'       => (string) ( $desc['account_names'][ $id ] ?? '' ),
					'selected'   => in_array( $id, $desc['accounts'], true ),
					'currency'   => (string) $summaries[ $id ]['currency'],
					'summary'    => $summaries[ $id ],
					'months'     => $account_months,
				];
			}

			$out[ $p ] = [
				'connected' => true,
				'email'     => (string) $desc['email'],
				'symbols'   => $symbols,
				'accounts'  => $accounts,
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
				? wp_date( brikpanel_datetime_format(), $e['ts'] )
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
