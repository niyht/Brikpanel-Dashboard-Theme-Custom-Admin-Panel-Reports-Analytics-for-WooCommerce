<?php
/**
 * BrikPanel — Sheets settings page + AJAX endpoints + asset enqueue.
 *
 * Registers the top-level admin page (slug: brikpanel-google-sheets), all
 * AJAX endpoints used by the four tabs (Connection, Orders, Reports,
 * Customers), and the CSS/JS assets scoped to that page only.
 *
 * @package BrikPanel
 * @since   2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Settings {

	const PAGE_SLUG    = 'brikpanel-google-sheets';
	const NONCE_ACTION = 'brikpanel_gs_nonce';

	/**
	 * Wall-clock budget for one interactive "Sync now" request. Kept well under
	 * the 30s max_execution_time that shared hosts commonly enforce so the
	 * response always comes back as JSON instead of a truncated fatal page.
	 */
	const SYNC_BUDGET_SECONDS = 15;

	/** Transient caching the proxy-served Picker bootstrap config. */
	const PICKER_CFG_TRANSIENT = 'brikpanel_gs_picker_cfg';
	const PICKER_CFG_TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Resolve the Google Picker bootstrap config (numeric GCP project number +
	 * browser API key) used to let the merchant hand over an existing
	 * spreadsheet under the non-sensitive drive.file scope.
	 *
	 * Resolution order (first non-empty wins):
	 *   1. `brikpanel_gs_picker_config` filter — host/dev override.
	 *   2. BRIKPANEL_GS_PICKER_PROJECT_NUMBER / BRIKPANEL_GS_PICKER_API_KEY
	 *      constants (wp-config.php) — staging.
	 *   3. Signed `/picker/config` proxy endpoint (brksoft.com), cached 12h so
	 *      the values stay rotatable server-side without a plugin release.
	 *
	 * Returns [] when not configured yet — callers must degrade gracefully
	 * (hide "pick existing", keep "create new").
	 *
	 * @param bool $force_refresh Skip the transient cache.
	 * @return array{app_id:string, api_key:string}|array{}
	 */
	public static function picker_config( $force_refresh = false ) {
		$override = apply_filters( 'brikpanel_gs_picker_config', null );
		if ( is_array( $override ) && ! empty( $override['app_id'] ) && ! empty( $override['api_key'] ) ) {
			return [
				'app_id'  => (string) $override['app_id'],
				'api_key' => (string) $override['api_key'],
			];
		}

		if ( defined( 'BRIKPANEL_GS_PICKER_PROJECT_NUMBER' ) && defined( 'BRIKPANEL_GS_PICKER_API_KEY' )
			&& BRIKPANEL_GS_PICKER_PROJECT_NUMBER !== '' && BRIKPANEL_GS_PICKER_API_KEY !== '' ) {
			return [
				'app_id'  => (string) BRIKPANEL_GS_PICKER_PROJECT_NUMBER,
				'api_key' => (string) BRIKPANEL_GS_PICKER_API_KEY,
			];
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( self::PICKER_CFG_TRANSIENT );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['app_id'] ) && ! empty( $cached['api_key'] ) ? $cached : [];
			}
		}

		// POST, not GET: managed hosts (e.g. Hostinger "hcdn") intermittently
		// serve a JS-challenge 403 to server-side GETs against /wp-json. Every
		// signed proxy endpoint is POST and is never challenged. Retried once
		// so a cold-cache page load doesn't lose the Picker on a transient blip.
		$cfg = [];
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$resp = wp_remote_post( BRIKPANEL_GS_PROXY_BASE . '/picker/config', [
				'timeout'   => 12,
				'sslverify' => true,
				'headers'   => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
				'body'      => '{}',
			] );
			$open = Brikpanel_Sheets_Proxy::open( $resp, 'picker/config' );
			if ( ! $open['wp_error'] && $open['ok'] && is_array( $open['data'] )
				&& ! empty( $open['data']['app_id'] ) && ! empty( $open['data']['api_key'] ) ) {
				$cfg = [
					'app_id'  => (string) $open['data']['app_id'],
					'api_key' => (string) $open['data']['api_key'],
				];
				break;
			}
		}

		// Cache positives for the full TTL; cache "not configured yet" briefly so
		// a misconfigured proxy doesn't get hammered on every page load but the
		// merchant still sees Picker appear soon after the operator fixes it.
		set_transient(
			self::PICKER_CFG_TRANSIENT,
			$cfg,
			$cfg ? self::PICKER_CFG_TTL : 5 * MINUTE_IN_SECONDS
		);
		return $cfg;
	}

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX endpoints (oauth_start / oauth_disconnect live in the OAuth class).
		add_action( 'wp_ajax_brikpanel_gs_status',              [ $this, 'ajax_status' ] );
		add_action( 'wp_ajax_brikpanel_gs_picker_bootstrap',     [ $this, 'ajax_picker_bootstrap' ] );
		add_action( 'wp_ajax_brikpanel_gs_validate_spreadsheet', [ $this, 'ajax_validate_spreadsheet' ] );
		add_action( 'wp_ajax_brikpanel_gs_create_spreadsheet',   [ $this, 'ajax_create_spreadsheet' ] );
		add_action( 'wp_ajax_brikpanel_gs_save_settings',        [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_brikpanel_gs_save_columns',         [ $this, 'ajax_save_columns' ] );
		add_action( 'wp_ajax_brikpanel_gs_orders_scope',         [ $this, 'ajax_orders_scope' ] );
		add_action( 'wp_ajax_brikpanel_gs_sync_now',             [ $this, 'ajax_sync_now' ] );
		add_action( 'wp_ajax_brikpanel_gs_pull_now',             [ $this, 'ajax_pull_now' ] );
		add_action( 'wp_ajax_brikpanel_gs_view_log',             [ $this, 'ajax_view_log' ] );
		add_action( 'wp_ajax_brikpanel_gs_clear_log',            [ $this, 'ajax_clear_log' ] );
		add_action( 'wp_ajax_brikpanel_gs_reset_sync',           [ $this, 'ajax_reset_sync' ] );
	}

	// =========================================================================
	// Page registration
	// =========================================================================

	public function register_page() {
		$menu_title = __( 'Google Sheets', 'brikpanel' );

		$hook = add_menu_page(
			__( 'Google Sheets', 'brikpanel' ),
			$menu_title,
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-cloud',
			56.8
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, function () {
				global $title;
				$title = __( 'Google Sheets', 'brikpanel' );
			} );
		}
	}

	public function render_page() {
		$conn = Brikpanel_Sheets_Tokens::describe();

		// Refresh the discovered custom-order-field list so the column picker
		// reflects this store's checkout fields. Cheap; safe to run every render.
		Brikpanel_Sheets_Mapping::refresh_order_custom_fields();

		$config = [
			'spreadsheet_id'   => (string) get_option( 'brikpanel_gs_spreadsheet_id', '' ),
			'spreadsheet_url'  => (string) get_option( 'brikpanel_gs_spreadsheet_url', '' ),
			'spreadsheet_title'=> (string) get_option( 'brikpanel_gs_spreadsheet_title', '' ),

			'orders_enabled'   => get_option( Brikpanel_Sheets_Order_Sync::OPT_ENABLED, 'no' ),
			'orders_realtime'  => get_option( Brikpanel_Sheets_Order_Sync::OPT_REALTIME, 'yes' ),
			'orders_tab'       => Brikpanel_Sheets_Order_Sync::tab_name(),
			'orders_row_layout' => Brikpanel_Sheets_Order_Sync::row_layout(),
			'orders_bulk_interval' => (string) get_option( Brikpanel_Sheets_Order_Sync::OPT_BULK_INTERVAL, 'off' ),
			'orders_bulk_since' => (string) get_option( Brikpanel_Sheets_Order_Sync::OPT_BULK_SINCE, gmdate( 'Y-m-d', strtotime( '-90 days' ) ) ),
			'orders_bulk_statuses' => Brikpanel_Sheets_Order_Sync::bulk_statuses(),
			'orders_shipping_methods' => Brikpanel_Sheets_Order_Sync::shipping_method_filter(),
			'orders_shipping_methods_catalogue' => self::shipping_methods_catalogue(),
			'orders_last_sync' => (array) get_option( Brikpanel_Sheets_Order_Sync::OPT_LAST_SYNC, [] ),
			'orders_pull_enabled'  => get_option( Brikpanel_Sheets_Order_Sync::OPT_PULL_ENABLED, 'no' ),
			'orders_pull_interval' => (string) get_option( Brikpanel_Sheets_Order_Sync::OPT_PULL_INTERVAL, '5' ),
			'orders_last_pull'     => (array) get_option( Brikpanel_Sheets_Order_Sync::OPT_LAST_PULL, [] ),

			'products_enabled'      => get_option( Brikpanel_Sheets_Products_Sync::OPT_ENABLED, 'no' ),
			'products_tab'          => Brikpanel_Sheets_Products_Sync::tab_name(),
			'products_pull_enabled' => get_option( Brikpanel_Sheets_Products_Sync::OPT_PULL_ENABLED, 'no' ),
			'products_pull_interval'=> (string) get_option( Brikpanel_Sheets_Products_Sync::OPT_PULL_INTERVAL, '5' ),
			'products_last_push'    => (array) get_option( Brikpanel_Sheets_Products_Sync::OPT_LAST_PUSH, [] ),
			'products_last_pull'    => (array) get_option( Brikpanel_Sheets_Products_Sync::OPT_LAST_PULL, [] ),
			'products_categories'   => Brikpanel_Sheets_Products_Sync::category_filter_ids(),
			'products_categories_catalogue' => self::product_categories_catalogue(),

			'reports_enabled'  => get_option( Brikpanel_Sheets_Reports_Sync::OPT_ENABLED, 'no' ),
			'reports_interval' => (string) get_option( Brikpanel_Sheets_Reports_Sync::OPT_INTERVAL, 'every_6h' ),
			'reports_last'     => (array) get_option( Brikpanel_Sheets_Reports_Sync::OPT_LAST, [] ),

			'customers_enabled' => get_option( Brikpanel_Sheets_Customers_Sync::OPT_ENABLED, 'no' ),
			'customers_tab'     => Brikpanel_Sheets_Customers_Sync::tab_name(),
			'customers_last'    => (array) get_option( Brikpanel_Sheets_Customers_Sync::OPT_LAST, [] ),

			'expenses_enabled'       => get_option( Brikpanel_Sheets_Expenses_Sync::OPT_ENABLED, 'no' ),
			'expenses_tab'           => Brikpanel_Sheets_Expenses_Sync::tab_name(),
			'expenses_pull_enabled'  => get_option( Brikpanel_Sheets_Expenses_Sync::OPT_PULL_ENABLED, 'no' ),
			'expenses_pull_interval' => (string) get_option( Brikpanel_Sheets_Expenses_Sync::OPT_PULL_INTERVAL, '5' ),
			'expenses_last_push'     => (array) get_option( Brikpanel_Sheets_Expenses_Sync::OPT_LAST_PUSH, [] ),
			'expenses_last_pull'     => (array) get_option( Brikpanel_Sheets_Expenses_Sync::OPT_LAST_PULL, [] ),

			'columns_orders'    => Brikpanel_Sheets_Mapping::get_columns( 'orders' ),
			'columns_customers' => Brikpanel_Sheets_Mapping::get_columns( 'customers' ),
			'columns_products'  => Brikpanel_Sheets_Mapping::get_columns( 'products' ),
			'columns_expenses'  => Brikpanel_Sheets_Mapping::get_columns( 'expenses' ),
			'columns_orders_catalogue'    => Brikpanel_Sheets_Mapping::available_columns_for( 'orders' ),
			'columns_customers_catalogue' => Brikpanel_Sheets_Mapping::available_columns_for( 'customers' ),
			'columns_products_catalogue'  => Brikpanel_Sheets_Mapping::available_columns_for( 'products' ),
			'columns_expenses_catalogue'  => Brikpanel_Sheets_Mapping::available_columns_for( 'expenses' ),
		];

		$flash = [
			'tone'    => isset( $_GET['brikpanel_oauth_flash'] ) ? sanitize_key( wp_unslash( $_GET['brikpanel_oauth_flash'] ) ) : '',
			'message' => isset( $_GET['brikpanel_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['brikpanel_msg'] ) ) : '',
		];

		include BRIKPANEL_GS_DIR . 'views/page.php';
	}

	// =========================================================================
	// Asset enqueue
	// =========================================================================

	public function enqueue_assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}
		// Version off the file mtime (fallback to plugin version) so a shipped
		// asset fix is never masked by a stale browser/CDN cache on the same
		// plugin version.
		$css_path = BRIKPANEL_GS_DIR . 'assets/brikpanel-google-sheets.css';
		$js_path  = BRIKPANEL_GS_DIR . 'assets/brikpanel-google-sheets.js';
		$css_ver  = is_readable( $css_path ) ? (string) filemtime( $css_path ) : BRIKPANEL_VERSION;
		$js_ver   = is_readable( $js_path )  ? (string) filemtime( $js_path )  : BRIKPANEL_VERSION;

		wp_enqueue_style(
			'brikpanel-gs',
			BRIKPANEL_GS_URL . 'assets/brikpanel-google-sheets.css',
			[],
			$css_ver
		);
		wp_enqueue_script(
			'brikpanel-gs',
			BRIKPANEL_GS_URL . 'assets/brikpanel-google-sheets.js',
			[],
			$js_ver,
			true
		);
		$picker_cfg = self::picker_config();
		wp_localize_script(
			'brikpanel-gs',
			'BrikpanelGS',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'pickerAvailable' => ! empty( $picker_cfg['app_id'] ) && ! empty( $picker_cfg['api_key'] ),
				'i18n'    => [
					'connecting'         => __( 'Connecting to Google…', 'brikpanel' ),
					'disconnect_confirm' => __( 'Disconnect from Google Sheets?', 'brikpanel' ),
					'syncing'            => __( 'Syncing…', 'brikpanel' ),
					'queued'             => __( 'Sync queued. Check back in a moment.', 'brikpanel' ),
					'saved'              => __( 'Saved.', 'brikpanel' ),
					'created'            => __( 'Spreadsheet created.', 'brikpanel' ),
					'creating'           => __( 'Creating spreadsheet…', 'brikpanel' ),
					'validating'         => __( 'Validating…', 'brikpanel' ),
					'validated'          => __( 'Spreadsheet is reachable.', 'brikpanel' ),
					'picker_loading'     => __( 'Opening Google Picker…', 'brikpanel' ),
					'picker_failed'      => __( 'Could not open the Google Picker. Please try again.', 'brikpanel' ),
					'picker_unavailable' => __( 'Picking an existing spreadsheet is not available yet. Create a new one below.', 'brikpanel' ),
					'generic_error'      => __( 'Something went wrong. Please try again.', 'brikpanel' ),
					'server_error'       => __( 'The server ended the request before it finished. Nothing was lost — click Sync now again to continue where it stopped.', 'brikpanel' ),
					/* translators: 1: orders synced so far, 2: sheet rows written so far. */
					'sync_progress'      => __( 'Synced %1$d orders (%2$d rows)…', 'brikpanel' ),
					/* translators: 1: total orders synced, 2: total sheet rows written. */
					'sync_done'          => __( 'Synced %1$d orders (%2$d rows).', 'brikpanel' ),
					'no_access'          => __( 'BrikPanel does not have access yet. Open the spreadsheet, share it with %s, then click Validate.', 'brikpanel' ),
					'log_empty'          => __( 'No errors logged.', 'brikpanel' ),
					/* translators: %s: number of orders. */
					'scope_all'          => __( 'All %s orders in this date and status range will be exported.', 'brikpanel' ),
					/* translators: 1: matching orders, 2: total orders in range. */
					'scope_filtered'     => __( '%1$s of %2$s orders match this shipping filter.', 'brikpanel' ),
					'scope_warning'      => __( 'Orders with no shipping method recorded are excluded while any box is ticked. Untick every box to export all orders.', 'brikpanel' ),
					'scope_none'         => __( 'No orders match this shipping filter, so nothing would be exported. Untick every box to export all orders.', 'brikpanel' ),
					'scope_counting'     => __( 'Counting…', 'brikpanel' ),
					'reset_confirm'      => __( "This will WIPE the current target tab in Google Sheets and then re-push every order from scratch. Any rows you added manually to that tab will be lost. Continue?", 'brikpanel' ),
					'resetting'          => __( 'Resetting…', 'brikpanel' ),
					'connected_label'    => __( 'Connected', 'brikpanel' ),
					'not_connected_label'=> __( 'Not connected', 'brikpanel' ),
					/* translators: %d minutes until auto-refresh */
					'expires_template'   => __( 'in %d min (auto-refreshed)', 'brikpanel' ),
					'pulling'            => __( 'Pulling changes from Sheets…', 'brikpanel' ),
					'reset_products_confirm' => __( "This will WIPE the current Products tab in Google Sheets and then re-push every product from scratch. Any rows you added manually to that tab will be lost. Continue?", 'brikpanel' ),
					'reset_expenses_confirm' => __( "This will WIPE the current Expenses tab in Google Sheets and then re-write it from your BrikPanel expenses. Any rows you typed into that tab and have not pulled in yet will be lost. Continue?", 'brikpanel' ),
				],
			]
		);
	}

	// =========================================================================
	// AJAX security helper
	// =========================================================================

	private function check_auth() {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'brikpanel' ) ], 403 );
		}
	}

	// =========================================================================
	// AJAX — status pill polling
	// =========================================================================

	public function ajax_status() {
		$this->check_auth();
		$conn = Brikpanel_Sheets_Tokens::describe();
		wp_send_json_success( [
			'connected'    => (bool) $conn['connected'],
			'email'        => (string) $conn['email'],
			'expires_in'   => $conn['expires_at'] > 0 ? max( 0, $conn['expires_at'] - time() ) : 0,
			'spreadsheet_id'    => (string) get_option( 'brikpanel_gs_spreadsheet_id', '' ),
			'spreadsheet_title' => (string) get_option( 'brikpanel_gs_spreadsheet_title', '' ),
			'orders_last_sync'    => (array) get_option( Brikpanel_Sheets_Order_Sync::OPT_LAST_SYNC, [] ),
			'reports_last_sync'   => (array) get_option( Brikpanel_Sheets_Reports_Sync::OPT_LAST, [] ),
			'customers_last_sync' => (array) get_option( Brikpanel_Sheets_Customers_Sync::OPT_LAST, [] ),
		] );
	}

	// =========================================================================
	// AJAX — Google Picker bootstrap (short-lived token + non-secret config)
	// =========================================================================

	/**
	 * Hand the admin browser everything the Google Picker needs:
	 *   - a live OAuth access token (refreshed transparently if near expiry),
	 *   - the numeric GCP project number (setAppId),
	 *   - the browser API key (setDeveloperKey).
	 *
	 * Admin-only (manage_woocommerce) + nonce-checked. The token is the same
	 * drive.file-scoped token already used server-side; exposing it to this
	 * admin-only page for the duration of a file pick is acceptable and is the
	 * Google-recommended pattern for drive.file + Picker.
	 */
	public function ajax_picker_bootstrap() {
		$this->check_auth();
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Connect Google Sheets first.', 'brikpanel' ) ], 400 );
		}
		// A connection made while the Drive permission was left unchecked on
		// Google's consent screen yields a valid token with NO Drive access —
		// handing it to the Picker shows a raw Google "403" overlay. Catch it
		// up front (this also blocks "create new", which equally needs it).
		if ( ! Brikpanel_Sheets_Tokens::has_drive_scope() ) {
			wp_send_json_error( [
				'message'      => __( 'Google Drive access was not granted for this connection. Please disconnect and reconnect, keeping the Google Drive permission checked on the consent screen.', 'brikpanel' ),
				'needs_reauth' => true,
			], 403 );
		}
		$cfg = self::picker_config();
		if ( empty( $cfg['app_id'] ) || empty( $cfg['api_key'] ) ) {
			wp_send_json_error( [
				'message'    => __( 'Picking an existing spreadsheet is not available yet. Create a new BrikPanel spreadsheet instead.', 'brikpanel' ),
				'configured' => false,
			], 503 );
		}
		$token = Brikpanel_Sheets_Tokens::get_access_token();
		if ( $token === null ) {
			wp_send_json_error( [ 'message' => __( 'Google session expired. Re-authorize and try again.', 'brikpanel' ) ], 401 );
		}
		wp_send_json_success( [
			'token'   => $token,
			'app_id'  => (string) $cfg['app_id'],
			'api_key' => (string) $cfg['api_key'],
		] );
	}

	// =========================================================================
	// AJAX — adopt a picked / created spreadsheet
	// =========================================================================

	/**
	 * Confirm and store the target spreadsheet. Under the non-sensitive
	 * drive.file scope the only reachable spreadsheets are ones the app created
	 * or the user just handed over via the Google Picker — so $input is
	 * normally a bare ID coming straight from the Picker callback. A pasted URL
	 * is still parsed for backward compatibility, but will only validate if
	 * that file was previously granted to the app.
	 */
	public function ajax_validate_spreadsheet() {
		$this->check_auth();
		$input = isset( $_POST['input'] ) ? sanitize_text_field( wp_unslash( $_POST['input'] ) ) : '';
		$id    = Brikpanel_Sheets_Client::extract_spreadsheet_id( $input );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Could not parse a spreadsheet ID from that input.', 'brikpanel' ) ], 400 );
		}
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Connect Google Sheets first.', 'brikpanel' ) ], 400 );
		}

		try {
			$client = new Brikpanel_Sheets_Client();
			$meta   = $client->get_spreadsheet( $id );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			if ( $e->http_code === 403 ) {
				wp_send_json_error( [ 'message' => __( 'BrikPanel can only use spreadsheets you create here or hand over with the “Choose existing spreadsheet” picker. Use the picker to grant access to this file.', 'brikpanel' ) ], 403 );
			}
			if ( $e->http_code === 404 ) {
				wp_send_json_error( [ 'message' => __( 'Spreadsheet not found. Check the URL.', 'brikpanel' ) ], 404 );
			}
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}

		$title = (string) ( $meta['properties']['title'] ?? '' );
		$sheets = [];
		foreach ( (array) ( $meta['sheets'] ?? [] ) as $sh ) {
			$sheets[] = (string) ( $sh['properties']['title'] ?? '' );
		}

		self::store_target_spreadsheet( $id, $input, $title );

		wp_send_json_success( [
			'spreadsheet_id'    => $id,
			'spreadsheet_title' => $title,
			'sheets'            => $sheets,
		] );
	}

	// =========================================================================
	// AJAX — create new spreadsheet
	// =========================================================================

	public function ajax_create_spreadsheet() {
		$this->check_auth();
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Connect Google Sheets first.', 'brikpanel' ) ], 400 );
		}
		// Creating a sheet needs drive.file just like the Picker — fail with a
		// fix-it message instead of a bare Google 403 if it was never granted.
		if ( ! Brikpanel_Sheets_Tokens::has_drive_scope() ) {
			wp_send_json_error( [
				'message'      => __( 'Google Drive access was not granted for this connection. Please disconnect and reconnect, keeping the Google Drive permission checked on the consent screen.', 'brikpanel' ),
				'needs_reauth' => true,
			], 403 );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( $title === '' ) {
			$site = wp_parse_url( home_url(), PHP_URL_HOST );
			$title = 'BrikPanel — ' . ( $site ?: 'WooCommerce' );
		}
		try {
			$client = new Brikpanel_Sheets_Client();
			// Seed with the standard tabs the sync flows will use.
			$resp = $client->create_spreadsheet( $title, [
				Brikpanel_Sheets_Order_Sync::tab_name(),
				Brikpanel_Sheets_Products_Sync::tab_name(),
				Brikpanel_Sheets_Customers_Sync::tab_name(),
				Brikpanel_Sheets_Reports_Sync::TAB_SUMMARY,
				Brikpanel_Sheets_Reports_Sync::TAB_KPIS,
				Brikpanel_Sheets_Reports_Sync::TAB_TOP,
				Brikpanel_Sheets_Reports_Sync::TAB_FUNNEL,
			] );
		} catch ( Brikpanel_Sheets_Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 502 );
		}
		$id  = (string) ( $resp['spreadsheetId'] ?? '' );
		$url = (string) ( $resp['spreadsheetUrl'] ?? '' );
		if ( $id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Spreadsheet created but no ID returned.', 'brikpanel' ) ], 502 );
		}
		self::store_target_spreadsheet( $id, $url, $title );
		wp_send_json_success( [
			'spreadsheet_id'    => $id,
			'spreadsheet_url'   => $url,
			'spreadsheet_title' => $title,
		] );
	}

	/**
	 * Persist the target spreadsheet + reset order/customer/report sync state
	 * when switching to a different spreadsheet so the user sees the new sheet
	 * filled from scratch. The previous sheet (if any) is left untouched —
	 * we only clear the per-order "already synced here" flags.
	 *
	 * @param string $id    Spreadsheet ID.
	 * @param string $url   Spreadsheet URL (informational).
	 * @param string $title Spreadsheet title (informational).
	 */
	private static function store_target_spreadsheet( $id, $url, $title ) {
		$old = (string) get_option( 'brikpanel_gs_spreadsheet_id', '' );

		// Normalise: if the user pasted a bare ID instead of a URL, esc_url()
		// would later coerce it into "http://<id>" and break the "Open in
		// Google Sheets" link. Always store a canonical https URL.
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://docs.google.com/spreadsheets/d/' . $id . '/edit';
		}

		update_option( 'brikpanel_gs_spreadsheet_id', $id, false );
		update_option( 'brikpanel_gs_spreadsheet_url', $url, false );
		update_option( 'brikpanel_gs_spreadsheet_title', $title, false );

		// Switching to a different spreadsheet — wipe per-order sync flags so
		// the next push fills the new sheet from scratch. The new sheet's
		// existing rows are intentionally left alone (it might be a sheet the
		// user already populated for another purpose).
		if ( $old !== '' && $old !== $id ) {
			self::reset_sync_state();
			// Products keep their own row/snapshot meta. Leaving it behind meant
			// the next product save wrote into the NEW spreadsheet at the OLD
			// sheet's absolute row numbers — scattering rows at arbitrary
			// positions and, if the new sheet already held the merchant's own
			// data, overwriting it row by row.
			if ( class_exists( 'Brikpanel_Sheets_Products_Sync' ) ) {
				Brikpanel_Sheets_Products_Sync::reset_sync_state();
			}
		}
	}

	/**
	 * Clear the target Orders tab in Google Sheets and re-write the header
	 * row. Used by the manual "Reset & re-push" flow to prevent the user's
	 * next bulk export from appending duplicates onto stale data.
	 *
	 * Best-effort: if the API call fails (e.g., user just disconnected) we
	 * log it and continue — the meta-level reset still happens so the user
	 * can fix the connection and retry.
	 *
	 * @return bool true on success, false on failure.
	 */
	/**
	 * Products-tab equivalent of clear_orders_target_tab(). Wipes the target
	 * Products tab and rewrites the header row so the next push starts from
	 * a clean slate.
	 *
	 * @return bool
	 */
	public static function clear_products_target_tab() {
		$id = (string) get_option( 'brikpanel_gs_spreadsheet_id', '' );
		if ( $id === '' || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return false;
		}
		$tab = Brikpanel_Sheets_Products_Sync::tab_name();
		try {
			$client      = new Brikpanel_Sheets_Client();
			$columns     = Brikpanel_Sheets_Mapping::get_columns( 'products' );
			$headers     = Brikpanel_Sheets_Mapping::headers_for( 'products', $columns );
			$validations = Brikpanel_Sheets_Products_Sync::build_dropdown_validations( $columns );
			$client->ensure_tab( $id, $tab, $headers, $validations );
			$client->values_clear( $id, Brikpanel_Sheets_Client::a1_quote_tab( $tab ) );
			$client->values_update(
				$id,
				Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A1',
				[ $headers ],
				'RAW'
			);
			// Re-apply dropdowns after the clear — values_clear strips them.
			// Look up the sheetId fresh in case the tab was just created.
			if ( ! empty( $validations ) ) {
				$sheet_id = null;
				foreach ( $client->list_sheets( $id ) as $sid => $name ) {
					if ( strcasecmp( $name, $tab ) === 0 ) { $sheet_id = (int) $sid; break; }
				}
				if ( $sheet_id !== null ) {
					$client->apply_data_validation( $id, $sheet_id, $validations );
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( 'products', 'Target tab clear failed during reset: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Wipe the Expenses tab and re-write its header + dropdowns.
	 *
	 * Only ever called from "Reset & re-push", where the merchant has already
	 * been warned that rows they typed into the tab go with it. The normal
	 * push rebuilds the tab too, but ingests it first so nothing typed is lost.
	 *
	 * @return bool False when the wipe did not happen, so the caller can abort
	 *               the rest of the reset instead of leaving the two sides
	 *               inconsistent.
	 */
	public static function clear_expenses_target_tab() {
		$id = (string) get_option( 'brikpanel_gs_spreadsheet_id', '' );
		if ( $id === '' || ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return false;
		}
		$tab = Brikpanel_Sheets_Expenses_Sync::tab_name();
		try {
			$client      = new Brikpanel_Sheets_Client();
			$columns     = Brikpanel_Sheets_Mapping::get_columns( 'expenses' );
			$headers     = Brikpanel_Sheets_Expenses_Sync::headers( $columns );
			$validations = Brikpanel_Sheets_Expenses_Sync::build_dropdown_validations( $columns );
			$client->ensure_tab( $id, $tab, $headers, $validations );
			$client->values_clear( $id, Brikpanel_Sheets_Client::a1_quote_tab( $tab ) );
			$client->values_update(
				$id,
				Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A1',
				[ $headers ],
				'RAW'
			);
			// values_clear() drops data validation — put the dropdowns back.
			if ( ! empty( $validations ) ) {
				$sheet_id = null;
				foreach ( $client->list_sheets( $id ) as $sid => $name ) {
					if ( strcasecmp( $name, $tab ) === 0 ) { $sheet_id = (int) $sid; break; }
				}
				if ( $sheet_id !== null ) {
					$client->apply_data_validation( $id, $sheet_id, $validations );
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( 'expenses', 'Target tab clear failed during reset: ' . $e->getMessage() );
			return false;
		}
	}

	public static function clear_orders_target_tab() {
		$id = (string) get_option( 'brikpanel_gs_spreadsheet_id', '' );
		if ( $id === '' ) {
			return false;
		}
		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			return false;
		}
		$tab = Brikpanel_Sheets_Order_Sync::tab_name();
		try {
			$client      = new Brikpanel_Sheets_Client();
			$columns     = Brikpanel_Sheets_Mapping::get_columns( 'orders' );
			$headers     = Brikpanel_Sheets_Mapping::headers_for( 'orders', $columns );
			$validations = Brikpanel_Sheets_Order_Sync::build_dropdown_validations( $columns );
			$client->ensure_tab( $id, $tab, $headers, $validations );
			$client->values_clear( $id, Brikpanel_Sheets_Client::a1_quote_tab( $tab ) );
			$client->values_update(
				$id,
				Brikpanel_Sheets_Client::a1_quote_tab( $tab ) . '!A1',
				[ $headers ],
				'RAW'
			);
			// values_clear strips data-validation rules; reapply them so the
			// merchant still sees the dropdown after the reset+re-push cycle.
			if ( ! empty( $validations ) ) {
				$sheet_id = null;
				foreach ( $client->list_sheets( $id ) as $sid => $name ) {
					if ( strcasecmp( $name, $tab ) === 0 ) { $sheet_id = (int) $sid; break; }
				}
				if ( $sheet_id !== null ) {
					$client->apply_data_validation( $id, $sheet_id, $validations );
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( 'orders', 'Target tab clear failed during reset: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Wipe per-order Sheets sync state (synced_at flag, row_map, target meta)
	 * across the orders table so the next bulk export re-pushes everything to
	 * the new target spreadsheet. Also clears per-flow last_sync display
	 * markers so the UI does not lie about "X minutes ago".
	 *
	 * HPOS-aware: hits the wc_orders_meta table when HPOS is on, postmeta
	 * otherwise.
	 */
	public static function reset_sync_state() {
		global $wpdb;
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';

		// The tab was (or is about to be) wiped — any cached view of which
		// order occupies which row is now a lie. Without this, a long-lived
		// worker could "adopt" orders onto rows that no longer exist.
		if ( class_exists( 'Brikpanel_Sheets_Order_Sync' ) ) {
			Brikpanel_Sheets_Order_Sync::invalidate_sheet_index();
		}

		// Cancel pending AS jobs FIRST so that, after we wipe the per-order
		// META_SYNCED_AT flags below, a stale realtime/bulk worker doesn't
		// race in, see every order as "unsynced", and re-push the entire
		// dataset on top of the inline sync that's about to run.
		//
		// The recurring bulk schedule self-heals on the next request because
		// register_handlers() re-schedules it via schedule_recurring() (which
		// is idempotent). update_rows jobs lose their target row_map after the
		// meta wipe anyway — cancelling them avoids a flood of "row 0" no-ops.
		if ( class_exists( 'Brikpanel_Cron' ) && Brikpanel_Cron::is_available() ) {
			Brikpanel_Cron::cancel( Brikpanel_Sheets_Order_Sync::HOOK_REALTIME_FLUSH );
			Brikpanel_Cron::cancel( Brikpanel_Sheets_Order_Sync::HOOK_BULK_FLUSH );
			Brikpanel_Cron::cancel( Brikpanel_Sheets_Order_Sync::HOOK_UPDATE_ROWS );
			Brikpanel_Cron::cancel( Brikpanel_Sheets_Order_Sync::HOOK_PULL );
		}

		$keys = [
			Brikpanel_Sheets_Order_Sync::META_SYNCED_AT,
			Brikpanel_Sheets_Order_Sync::META_ROW_MAP,
			Brikpanel_Sheets_Order_Sync::META_SPREADSHEET,
			Brikpanel_Sheets_Order_Sync::META_TAB,
			Brikpanel_Sheets_Order_Sync::META_LAST_PUSHED_STATUS,
			// Clear skip markers too, so an order that failed to map once gets
			// another chance on a full re-push.
			Brikpanel_Sheets_Order_Sync::META_SKIPPED,
		];
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		if ( $is_hpos ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ($placeholders)",
				$keys
			) );
			// Also wipe legacy postmeta in case both tables hold copies.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
				$keys
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
				$keys
			) );
		}

		delete_option( Brikpanel_Sheets_Order_Sync::OPT_LAST_SYNC );
		delete_option( Brikpanel_Sheets_Reports_Sync::OPT_LAST );
		delete_option( Brikpanel_Sheets_Customers_Sync::OPT_LAST );
		// Expense row snapshots describe rows in the OLD spreadsheet. Carried
		// into a new one they would make the first pull compare live cells
		// against hashes from a document that is no longer the target.
		if ( class_exists( 'Brikpanel_Sheets_Expenses_Sync' ) ) {
			Brikpanel_Sheets_Expenses_Sync::reset_sync_state();
		}
		// Drop any stale flush lock so the immediate "Sync now" can acquire it.
		delete_transient( Brikpanel_Sheets_Order_Sync::FLUSH_LOCK );
		Brikpanel_Sheets_Logger::log( 'orders', 'Sync state reset (spreadsheet changed).' );
	}

	// =========================================================================
	// AJAX — save flow settings (toggles, schedules, tab names)
	// =========================================================================

	public function ajax_save_settings() {
		$this->check_auth();
		$flow = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : '';

		switch ( $flow ) {
			case 'orders':
				update_option( Brikpanel_Sheets_Order_Sync::OPT_ENABLED,    $this->yes_no( $_POST['enabled'] ?? '' ), false );
				update_option( Brikpanel_Sheets_Order_Sync::OPT_REALTIME,   $this->yes_no( $_POST['realtime'] ?? '' ), false );
				$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? '' ) );
				update_option( Brikpanel_Sheets_Order_Sync::OPT_TAB_NAME,   $tab !== '' ? $tab : 'Orders', false );

				$interval = sanitize_key( $_POST['bulk_interval'] ?? 'off' );
				if ( ! in_array( $interval, [ 'off', 'hourly', 'every_4h', 'daily' ], true ) ) {
					$interval = 'off';
				}
				update_option( Brikpanel_Sheets_Order_Sync::OPT_BULK_INTERVAL, $interval, false );

				$since = sanitize_text_field( wp_unslash( $_POST['bulk_since'] ?? '' ) );
				if ( $since !== '' && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $since, $m )
					&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					update_option( Brikpanel_Sheets_Order_Sync::OPT_BULK_SINCE, $since, false );
				} elseif ( $since !== '' ) {
					wp_send_json_error( [
						'message' => __( 'Invalid "include orders from" date. Use the date picker.', 'brikpanel' ),
					], 400 );
				}
				$statuses = isset( $_POST['bulk_statuses'] ) ? (array) wp_unslash( $_POST['bulk_statuses'] ) : [];
				$statuses = array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );
				if ( ! empty( $statuses ) ) {
					update_option( Brikpanel_Sheets_Order_Sync::OPT_BULK_STATUSES, $statuses, false );
				}

				// Shipping-method filter. Empty (no boxes checked) = export every
				// order regardless of method. Keep only ids that are real
				// registered shipping methods so a stale value can't break the
				// query later.
				$ship = isset( $_POST['shipping_methods'] ) ? (array) wp_unslash( $_POST['shipping_methods'] ) : [];
				$ship = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $ship ) ) ) );
				$valid_methods = array_keys( self::shipping_methods_catalogue() );
				$ship = array_values( array_intersect( $ship, $valid_methods ) );
				update_option( Brikpanel_Sheets_Order_Sync::OPT_SHIPPING_METHODS, $ship, false );
				update_option( Brikpanel_Sheets_Order_Sync::OPT_PULL_ENABLED, $this->yes_no( $_POST['pull_enabled'] ?? '' ), false );
				$pull_int = sanitize_key( $_POST['pull_interval'] ?? '5' );
				if ( ! in_array( $pull_int, [ '2', '5', '15' ], true ) ) {
					$pull_int = '5';
				}
				update_option( Brikpanel_Sheets_Order_Sync::OPT_PULL_INTERVAL, $pull_int, false );

				// Row layout LAST, because a failed switch aborts the request:
				// everything saved above must already be committed by then.
				// Switching layouts changes what a row IS, so old rows can
				// never be mixed with new ones — the target tab must be wiped
				// and the sync state reset in the same atomic step as the
				// option flip. If the wipe fails (quota, connection), revert
				// the flip and report; nothing is left half-switched.
				$new_layout = sanitize_key( wp_unslash( $_POST['row_layout'] ?? '' ) );
				if ( in_array( $new_layout, [ 'order', 'line_item' ], true )
					&& $new_layout !== Brikpanel_Sheets_Order_Sync::row_layout() ) {

					$old_layout  = Brikpanel_Sheets_Order_Sync::row_layout();
					$old_columns = Brikpanel_Sheets_Mapping::get_columns( 'orders' );

					update_option( Brikpanel_Sheets_Order_Sync::OPT_ROW_LAYOUT, $new_layout, false );
					Brikpanel_Sheets_Mapping::set_columns(
						'orders',
						Brikpanel_Sheets_Mapping::adapt_order_columns_to_layout( $old_columns, $new_layout )
					);

					// Clear AFTER the flip so the freshly written header row
					// reflects the new column set, not the old one.
					if ( ! self::clear_orders_target_tab() ) {
						update_option( Brikpanel_Sheets_Order_Sync::OPT_ROW_LAYOUT, $old_layout, false );
						Brikpanel_Sheets_Mapping::set_columns( 'orders', $old_columns );
						wp_send_json_error( [
							'message' => __( 'Row layout was not changed: the target tab could not be wiped (rate limit or connection issue). Your other settings were saved — wait a minute and try the layout switch again.', 'brikpanel' ),
						], 502 );
					}
					self::reset_sync_state();

					wp_send_json_success( [
						'message' => __( 'Row layout changed — the target tab was wiped and all orders will re-export in the new layout. Click "Sync now" to fill it.', 'brikpanel' ),
						'reload'  => true,
					] );
				}

				// Re-register handlers / recurring schedule on next cron tick.
				do_action( 'brikpanel_cron_register' );
				break;

			case 'products':
				update_option( Brikpanel_Sheets_Products_Sync::OPT_ENABLED, $this->yes_no( $_POST['enabled'] ?? '' ), false );
				$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? '' ) );
				update_option( Brikpanel_Sheets_Products_Sync::OPT_TAB_NAME, $tab !== '' ? $tab : 'Products', false );
				update_option( Brikpanel_Sheets_Products_Sync::OPT_PULL_ENABLED, $this->yes_no( $_POST['pull_enabled'] ?? '' ), false );
				$pull_int = sanitize_key( $_POST['pull_interval'] ?? '5' );
				if ( ! in_array( $pull_int, [ '2', '5', '15' ], true ) ) {
					$pull_int = '5';
				}
				update_option( Brikpanel_Sheets_Products_Sync::OPT_PULL_INTERVAL, $pull_int, false );

				// Category filter: empty (no boxes checked) means "sync every
				// product". Keep only IDs that are real product_cat terms so a
				// stale/spoofed ID can never widen or break the query later.
				$cats = isset( $_POST['categories'] ) ? (array) wp_unslash( $_POST['categories'] ) : [];
				$cats = array_values( array_unique( array_filter( array_map( 'absint', $cats ) ) ) );
				$valid_cats = [];
				foreach ( $cats as $cid ) {
					if ( term_exists( $cid, 'product_cat' ) ) {
						$valid_cats[] = $cid;
					}
				}
				update_option( Brikpanel_Sheets_Products_Sync::OPT_CATEGORIES, $valid_cats, false );

				do_action( 'brikpanel_cron_register' );
				break;

			case 'reports':
				update_option( Brikpanel_Sheets_Reports_Sync::OPT_ENABLED, $this->yes_no( $_POST['enabled'] ?? '' ), false );
				$interval = sanitize_key( $_POST['interval'] ?? 'every_6h' );
				if ( ! in_array( $interval, [ 'hourly', 'every_6h', 'daily' ], true ) ) {
					$interval = 'every_6h';
				}
				update_option( Brikpanel_Sheets_Reports_Sync::OPT_INTERVAL, $interval, false );
				do_action( 'brikpanel_cron_register' );
				break;

			case 'customers':
				update_option( Brikpanel_Sheets_Customers_Sync::OPT_ENABLED, $this->yes_no( $_POST['enabled'] ?? '' ), false );
				$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? '' ) );
				update_option( Brikpanel_Sheets_Customers_Sync::OPT_TAB_NAME, $tab !== '' ? $tab : 'Customers', false );
				break;

			case 'expenses':
				update_option( Brikpanel_Sheets_Expenses_Sync::OPT_ENABLED, $this->yes_no( $_POST['enabled'] ?? '' ), false );
				$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? '' ) );
				update_option( Brikpanel_Sheets_Expenses_Sync::OPT_TAB_NAME, $tab !== '' ? $tab : 'Expenses', false );
				update_option( Brikpanel_Sheets_Expenses_Sync::OPT_PULL_ENABLED, $this->yes_no( $_POST['pull_enabled'] ?? '' ), false );
				$pull_int = sanitize_key( $_POST['pull_interval'] ?? '5' );
				if ( ! in_array( $pull_int, [ '2', '5', '15' ], true ) ) {
					$pull_int = '5';
				}
				update_option( Brikpanel_Sheets_Expenses_Sync::OPT_PULL_INTERVAL, $pull_int, false );
				do_action( 'brikpanel_cron_register' );
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown settings group.', 'brikpanel' ) ], 400 );
		}
		wp_send_json_success();
	}

	private function yes_no( $val ) {
		return ( $val === 'yes' || $val === '1' || $val === true ) ? 'yes' : 'no';
	}

	/**
	 * Product categories offered in the product-sync filter UI. All categories
	 * (including empty ones) sorted by name. Returns an empty array if the
	 * taxonomy query fails so the template can degrade gracefully.
	 *
	 * @return WP_Term[]
	 */
	private static function product_categories_catalogue() {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );
		return ( is_array( $terms ) ) ? $terms : [];
	}

	/**
	 * Registered WooCommerce shipping methods offered in the order-sync shipping
	 * filter UI, as [ method_id => title ]. These base method ids (flat_rate,
	 * local_pickup, …) are what each order's shipping line reports, so they match
	 * cleanly against stored orders. Returns [] if WooCommerce shipping is
	 * unavailable so the template can degrade gracefully.
	 *
	 * @return array<string, string>
	 */
	public static function shipping_methods_catalogue() {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return [];
		}
		$out = [];
		foreach ( WC()->shipping()->get_shipping_methods() as $id => $method ) {
			$title = method_exists( $method, 'get_method_title' ) ? $method->get_method_title() : '';
			if ( $title === '' ) {
				$title = isset( $method->method_title ) ? $method->method_title : (string) $id;
			}
			$out[ (string) $id ] = (string) $title;
		}
		return $out;
	}

	// =========================================================================
	// AJAX — save column mapping
	// =========================================================================

	public function ajax_save_columns() {
		$this->check_auth();
		$flow    = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : '';
		$columns = isset( $_POST['columns'] ) ? (array) wp_unslash( $_POST['columns'] ) : [];
		$columns = array_values( array_filter( array_map( 'sanitize_key', $columns ) ) );

		if ( ! in_array( $flow, [ 'orders', 'customers', 'products', 'expenses' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown flow.', 'brikpanel' ) ], 400 );
		}
		Brikpanel_Sheets_Mapping::set_columns( $flow, $columns );
		wp_send_json_success();
	}

	// =========================================================================
	// AJAX — export-scope preview for the orders filters
	// =========================================================================

	/**
	 * Count how many orders the CURRENTLY EDITED (not yet saved) orders filters
	 * would export. Lets the settings screen warn before a merchant syncs and
	 * wonders why only a handful of rows landed in the sheet.
	 */
	public function ajax_orders_scope() {
		$this->check_auth();

		$statuses = isset( $_POST['statuses'] ) ? (array) wp_unslash( $_POST['statuses'] ) : [];
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		// Only statuses WooCommerce actually knows; a stale/unknown key would
		// silently narrow the count and make the preview lie.
		$valid    = array_keys( wc_get_order_statuses() );
		$statuses = array_values( array_intersect( $statuses, $valid ) );

		$methods = isset( $_POST['shipping_methods'] ) ? (array) wp_unslash( $_POST['shipping_methods'] ) : [];
		$methods = array_values( array_filter( array_map( 'sanitize_text_field', $methods ) ) );

		$since_raw = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '';
		$since_ts  = $since_raw !== '' ? strtotime( $since_raw . ' 00:00:00 UTC' ) : 0;
		if ( ! $since_ts ) {
			$since_ts = Brikpanel_Sheets_Order_Sync::bulk_since_timestamp();
		}

		$scope = Brikpanel_Sheets_Order_Sync::count_export_scope( $statuses, $since_ts, $methods );

		wp_send_json_success( [
			'matched'  => (int) $scope['matched'],
			'total'    => (int) $scope['total'],
			'filtered' => ! empty( $methods ),
		] );
	}

	// =========================================================================
	// AJAX — manual sync triggers
	// =========================================================================

	public function ajax_sync_now() {
		$this->check_auth();
		$flow = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : '';

		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Connect Google Sheets first.', 'brikpanel' ) ], 400 );
		}
		if ( (string) get_option( 'brikpanel_gs_spreadsheet_id', '' ) === '' ) {
			wp_send_json_error( [ 'message' => __( 'Pick or create a target spreadsheet first.', 'brikpanel' ) ], 400 );
		}
		$flow_enabled = [
			'orders'    => Brikpanel_Sheets_Order_Sync::is_enabled(),
			'products'  => Brikpanel_Sheets_Products_Sync::is_enabled(),
			'reports'   => Brikpanel_Sheets_Reports_Sync::is_enabled(),
			'customers' => Brikpanel_Sheets_Customers_Sync::is_enabled(),
			'expenses'  => Brikpanel_Sheets_Expenses_Sync::is_enabled(),
		];
		if ( isset( $flow_enabled[ $flow ] ) && ! $flow_enabled[ $flow ] ) {
			wp_send_json_error( [ 'message' => __( 'Enable this sync first, then click Save before clicking Sync now.', 'brikpanel' ) ], 400 );
		}

		// Run inline so the user sees results immediately. Bulk handlers
		// page themselves through Action Scheduler if more rows remain.
		// Use a generous time cap but well below typical PHP max_execution_time.
		@set_time_limit( 90 );

		$started = microtime( true );

		try {
			switch ( $flow ) {
				case 'orders':
					$sync = new Brikpanel_Sheets_Order_Sync();

					// Drain in small passes under a wall-clock budget rather than
					// one huge pass. A single 250-order pass was the whole reason
					// a large date range failed: it could outlast the request and
					// die after the rows had already reached the sheet. Small
					// passes keep every request short, and the browser asks for
					// the next one while showing progress.
					$result   = [ 'orders' => 0, 'rows' => 0, 'more' => false ];
					$deadline = microtime( true ) + self::SYNC_BUDGET_SECONDS;
					do {
						$pass = $sync->handle_flush_bulk( [
							'limit' => Brikpanel_Sheets_Order_Sync::INTERACTIVE_BATCH_SIZE,
							'defer' => false,
						] );
						if ( ! empty( $pass['locked'] ) ) {
							// A background job (hourly schedule, realtime append)
							// holds the flush lock. That is NOT "all synced" —
							// wait briefly and retry within the budget; if the
							// budget runs out first, report `more` so the
							// browser keeps polling until the lock frees up.
							$result['more'] = true;
							if ( microtime( true ) + 2 < $deadline ) {
								sleep( 2 );
								continue;
							}
							break;
						}
						$result['orders'] += (int) ( $pass['orders'] ?? 0 );
						$result['rows']   += (int) ( $pass['rows'] ?? 0 );
						$result['more']    = ! empty( $pass['more'] );
					} while ( $result['more'] && microtime( true ) < $deadline );

					// Safety net: if the merchant closes the tab mid-drain the
					// browser stops asking, so hand the remainder to the
					// background queue. 'unique' keeps a second click from
					// stacking duplicate paging jobs.
					if ( ! empty( $result['more'] ) ) {
						Brikpanel_Cron::enqueue_async(
							Brikpanel_Sheets_Order_Sync::HOOK_BULK_FLUSH,
							[],
							[ 'unique' => true ]
						);
					}

					$orders_n = (int) ( $result['orders'] ?? 0 );
					if ( $orders_n === 0 && ! empty( $result['more'] ) ) {
						// Whole budget spent waiting on the flush lock — a
						// background job owns the export right now. Saying
						// "everything is synced" here would be a lie.
						$message = __( 'A background sync is already running — it will finish on its own, or click "Sync now" again in a moment to watch progress.', 'brikpanel' );
					} elseif ( $orders_n === 0 ) {
						// Distinguish "already synced everything" from "your filters
						// match no orders in the configured window". Both produce 0
						// here but the user-facing fix is different.
						global $wpdb;
						$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
						$total_matching = 0;
						$statuses = Brikpanel_Sheets_Order_Sync::bulk_statuses();
						$since_gmt = gmdate( 'Y-m-d H:i:s', Brikpanel_Sheets_Order_Sync::bulk_since_timestamp() );
						if ( $is_hpos && ! empty( $statuses ) ) {
							$status_ph  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
							$total_matching = (int) $wpdb->get_var( $wpdb->prepare(
								"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type='shop_order' AND status IN ($status_ph) AND date_created_gmt >= %s",
								array_merge( $statuses, [ $since_gmt ] )
							) );
						}
						if ( $total_matching === 0 ) {
							$message = __( 'No orders matched the selected status filters and "include orders from" date. Adjust those settings and try again.', 'brikpanel' );
						} else {
							$message = __( 'No new orders to sync — every matching order is already marked synced. To wipe the target tab and re-push the full history, click "Reset & re-push everything".', 'brikpanel' );
						}
					} else {
						$message = sprintf(
							/* translators: 1: rows, 2: orders */
							_n(
								'Synced %2$d order (%1$d rows).',
								'Synced %2$d orders (%1$d rows).',
								$orders_n,
								'brikpanel'
							),
							(int) ( $result['rows'] ?? 0 ),
							$orders_n
						);
					}
					if ( ! empty( $result['more'] ) ) {
						$message .= ' ' . __( 'More running in background…', 'brikpanel' );
					}
					$last = (array) get_option( Brikpanel_Sheets_Order_Sync::OPT_LAST_SYNC, [] );
					break;

				case 'products':
					$sync   = new Brikpanel_Sheets_Products_Sync();
					$result = $sync->handle_push_bulk();
					$message = sprintf(
						/* translators: 1: appended count, 2: updated count */
						__( 'Pushed %1$d new and updated %2$d existing product rows.', 'brikpanel' ),
						(int) ( $result['appended'] ?? 0 ),
						(int) ( $result['updated'] ?? 0 )
					);
					if ( ! empty( $result['more'] ) ) {
						$message .= ' ' . __( 'More running in background…', 'brikpanel' );
					}
					$last = (array) get_option( Brikpanel_Sheets_Products_Sync::OPT_LAST_PUSH, [] );
					break;

				case 'reports':
					$sync   = new Brikpanel_Sheets_Reports_Sync();
					$result = $sync->handle();
					$message = sprintf(
						/* translators: 1: rows, 2: tabs */
						__( 'Refreshed %2$d report tabs (%1$d rows).', 'brikpanel' ),
						(int) ( $result['rows'] ?? 0 ),
						(int) ( $result['tabs'] ?? 0 )
					);
					$last = (array) get_option( Brikpanel_Sheets_Reports_Sync::OPT_LAST, [] );
					break;

				case 'customers':
					$sync   = new Brikpanel_Sheets_Customers_Sync();
					$result = $sync->handle();
					// Resolve the plural into a variable before sprintf(): the
					// string extractor that builds brikpanel.pot skips an _n()
					// written inline as sprintf's first argument, so this message
					// was shipping untranslatable.
					$cust_n = (int) ( $result['rows'] ?? 0 );
					/* translators: %d: customer rows */
					$template = _n( 'Synced %d customer.', 'Synced %d customers.', $cust_n, 'brikpanel' );
					$message  = sprintf( $template, $cust_n );
					$last = (array) get_option( Brikpanel_Sheets_Customers_Sync::OPT_LAST, [] );
					break;

				case 'expenses':
					$sync    = new Brikpanel_Sheets_Expenses_Sync();
					$result  = $sync->handle_push();
					$rows_n  = (int) ( $result['rows'] ?? 0 );
					/* translators: %d: expense rows written to the sheet */
					$template = _n( 'Wrote %d expense to the sheet.', 'Wrote %d expenses to the sheet.', $rows_n, 'brikpanel' );
					$message  = sprintf( $template, $rows_n );
					// The push ingests the tab first when two-way sync is on, so
					// report anything it picked up on the way in — otherwise a
					// merchant who typed rows and clicked "Sync now" would see
					// only the row count and wonder if their entries were read.
					$ingested = (int) ( $result['created'] ?? 0 ) + (int) ( $result['updated'] ?? 0 );
					if ( $ingested > 0 ) {
						$message .= ' ' . sprintf(
							/* translators: 1: expenses created from the sheet, 2: expenses updated from the sheet */
							__( 'Added %1$d and updated %2$d from your edits in the sheet.', 'brikpanel' ),
							(int) ( $result['created'] ?? 0 ),
							(int) ( $result['updated'] ?? 0 )
						);
					}
					$last = (array) get_option( Brikpanel_Sheets_Expenses_Sync::OPT_LAST_PUSH, [] );
					break;

				default:
					wp_send_json_error( [ 'message' => __( 'Unknown flow.', 'brikpanel' ) ], 400 );
			}
		} catch ( Brikpanel_Sheets_Exception $e ) {
			wp_send_json_error( [
				'message'  => $e->getMessage(),
				'http'     => $e->http_code,
				'reason'   => $e->api_reason,
			], 502 );
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( $flow, 'manual sync threw: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}

		// Add a human-readable "X seconds ago" hint to the response so the JS
		// can update the Activity card without a separate AJAX round-trip.
		$last_display = ! empty( $last['ts'] )
			? sprintf(
				/* translators: 1: time-ago, 2: row count */
				__( '%1$s ago, %2$d rows', 'brikpanel' ),
				human_time_diff( (int) $last['ts'], time() ),
				(int) ( $last['rows'] ?? 0 )
			)
			: '';

		wp_send_json_success( [
			'message'            => $message,
			'result'             => $result ?? [],
			'duration_seconds'   => round( microtime( true ) - $started, 2 ),
			'last_sync_display'  => $last_display,
		] );
	}

	// =========================================================================
	// AJAX — error log
	// =========================================================================

	/**
	 * Manual "Pull now" trigger for the reverse-direction (Sheets → Woo)
	 * flows. Mirrors ajax_sync_now() but invokes the pull handler instead of
	 * the push so the merchant can verify their sheet edits without waiting
	 * for the next scheduled poll.
	 */
	public function ajax_pull_now() {
		$this->check_auth();
		$flow = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : '';

		if ( ! Brikpanel_Sheets_Tokens::is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Connect Google Sheets first.', 'brikpanel' ) ], 400 );
		}
		if ( (string) get_option( 'brikpanel_gs_spreadsheet_id', '' ) === '' ) {
			wp_send_json_error( [ 'message' => __( 'Pick or create a target spreadsheet first.', 'brikpanel' ) ], 400 );
		}

		@set_time_limit( 90 );
		$started = microtime( true );

		try {
			switch ( $flow ) {
				case 'orders':
					if ( ! Brikpanel_Sheets_Order_Sync::is_enabled() || ! Brikpanel_Sheets_Order_Sync::pull_enabled() ) {
						wp_send_json_error( [ 'message' => __( 'Enable two-way sync for orders first, then click Save before clicking Pull now.', 'brikpanel' ) ], 400 );
					}
					$sync   = new Brikpanel_Sheets_Order_Sync();
					$result = $sync->handle_pull();
					$message = sprintf(
						/* translators: 1: rows checked, 2: changes applied, 3: conflicts */
						__( 'Checked %1$d order rows. Applied %2$d status changes from Sheets, %3$d conflicts skipped.', 'brikpanel' ),
						(int) ( $result['checked'] ?? 0 ),
						(int) ( $result['applied'] ?? 0 ),
						(int) ( $result['conflicts'] ?? 0 )
					);
					break;

				case 'products':
					if ( ! Brikpanel_Sheets_Products_Sync::is_enabled() || ! Brikpanel_Sheets_Products_Sync::pull_enabled() ) {
						wp_send_json_error( [ 'message' => __( 'Enable two-way sync for products first, then click Save before clicking Pull now.', 'brikpanel' ) ], 400 );
					}
					$sync   = new Brikpanel_Sheets_Products_Sync();
					$result = $sync->handle_pull();
					$message = sprintf(
						/* translators: 1: rows checked, 2: changes applied, 3: conflicts */
						__( 'Checked %1$d product rows. Applied %2$d changes from Sheets, %3$d conflicts skipped.', 'brikpanel' ),
						(int) ( $result['checked'] ?? 0 ),
						(int) ( $result['applied'] ?? 0 ),
						(int) ( $result['conflicts'] ?? 0 )
					);
					break;

				case 'expenses':
					if ( ! Brikpanel_Sheets_Expenses_Sync::is_enabled() || ! Brikpanel_Sheets_Expenses_Sync::pull_enabled() ) {
						wp_send_json_error( [ 'message' => __( 'Enable two-way sync for expenses first, then click Save before clicking Pull now.', 'brikpanel' ) ], 400 );
					}
					$sync   = new Brikpanel_Sheets_Expenses_Sync();
					$result = $sync->handle_pull();
					$message = sprintf(
						/* translators: 1: rows read, 2: expenses added, 3: expenses updated, 4: rows skipped */
						__( 'Read %1$d rows: added %2$d, updated %3$d, skipped %4$d.', 'brikpanel' ),
						(int) ( $result['checked'] ?? 0 ),
						(int) ( $result['created'] ?? 0 ),
						(int) ( $result['updated'] ?? 0 ),
						(int) ( $result['skipped'] ?? 0 )
					);
					if ( ! empty( $result['conflicts'] ) ) {
						$message .= ' ' . sprintf(
							/* translators: %d: rows changed on both sides since the last sync */
							_n(
								'%d row was changed in both places, so the BrikPanel version was kept.',
								'%d rows were changed in both places, so the BrikPanel versions were kept.',
								(int) $result['conflicts'],
								'brikpanel'
							),
							(int) $result['conflicts']
						);
					}
					break;

				default:
					wp_send_json_error( [ 'message' => __( 'Unknown flow.', 'brikpanel' ) ], 400 );
			}
		} catch ( Brikpanel_Sheets_Exception $e ) {
			wp_send_json_error( [
				'message'  => $e->getMessage(),
				'http'     => $e->http_code,
				'reason'   => $e->api_reason,
			], 502 );
		} catch ( \Throwable $e ) {
			Brikpanel_Sheets_Logger::log( $flow, 'manual pull threw: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}

		wp_send_json_success( [
			'message'          => $message,
			'result'           => $result ?? [],
			'duration_seconds' => round( microtime( true ) - $started, 2 ),
		] );
	}

	public function ajax_view_log() {
		$this->check_auth();
		$flow = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : '';

		// Pull a wider window when filtering so the panel can still surface
		// 50 entries after the per-flow filter; without this, a noisy flow
		// could push the requested one off the tail of the ring buffer.
		$pool = Brikpanel_Sheets_Logger::recent( $flow === '' ? 50 : Brikpanel_Sheets_Logger::MAX_ENTRIES );

		// Always include shared-infrastructure flows that affect the picked
		// flow (oauth + client). User asks "why didn't customer sync work?"
		// — the answer often lives in an oauth or client-level error.
		$keep = [];
		if ( $flow === '' ) {
			$keep = $pool;
		} else {
			$visible = [ $flow, 'oauth', 'client' ];
			foreach ( $pool as $e ) {
				if ( in_array( (string) ( $e['flow'] ?? '' ), $visible, true ) ) {
					$keep[] = $e;
				}
			}
			$keep = array_slice( $keep, 0, 50 );
		}

		foreach ( $keep as &$e ) {
			$e['ts_display'] = $e['ts'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['ts'] ) : '';
			// Repeats are collapsed into one entry (see the logger); say so, or
			// a recurring failure reads as a single one-off event.
			$repeats = (int) ( $e['count'] ?? 1 );
			if ( $repeats > 1 ) {
				$e['message'] = sprintf(
					/* translators: 1: log message, 2: how many times it repeated. */
					__( '%1$s (repeated %2$s times)', 'brikpanel' ),
					(string) $e['message'],
					number_format_i18n( $repeats )
				);
			}
		}
		wp_send_json_success( [ 'entries' => $keep ] );
	}

	public function ajax_clear_log() {
		$this->check_auth();
		Brikpanel_Sheets_Logger::clear();
		wp_send_json_success();
	}

	/**
	 * Wipe order sync state AND clear the current target tab in Google Sheets
	 * so the next "Sync now" produces a clean, duplicate-free re-push.
	 *
	 * Without the tab clear, the next bulk export would append fresh rows on
	 * top of the existing data — multiplying every order's rows on every
	 * reset cycle.
	 */
	public function ajax_reset_sync() {
		$this->check_auth();
		$flow = isset( $_POST['flow'] ) ? sanitize_key( wp_unslash( $_POST['flow'] ) ) : 'orders';

		// ATOMIC, clear-first ordering. The old flow wiped the per-order meta
		// even when the tab clear failed (quota, network, revoked token) and
		// then told the user to "Sync now anyway" — which appended a complete
		// second copy of every order onto the stale rows. If we cannot wipe
		// the tab, we must NOT touch the meta: the sheet and the row maps are
		// still consistent with each other, so aborting is loss-free.
		if ( $flow === 'products' ) {
			$tab_cleared = self::clear_products_target_tab();
			if ( $tab_cleared ) {
				Brikpanel_Sheets_Products_Sync::reset_sync_state();
			}
		} elseif ( $flow === 'expenses' ) {
			$tab_cleared = self::clear_expenses_target_tab();
			if ( $tab_cleared ) {
				Brikpanel_Sheets_Expenses_Sync::reset_sync_state();
			}
		} else {
			// Default: orders (matches the original behaviour).
			$tab_cleared = self::clear_orders_target_tab();
			if ( $tab_cleared ) {
				self::reset_sync_state();
			}
		}

		if ( ! $tab_cleared ) {
			wp_send_json_error( [
				'message' => __( 'The target tab could not be wiped (rate limit or connection issue), so the reset was cancelled to avoid duplicate rows. Nothing was changed — wait a minute and try again.', 'brikpanel' ),
			], 502 );
		}

		wp_send_json_success( [
			'message'     => __( 'Sync state cleared and target tab wiped. Click "Sync now" to repopulate it.', 'brikpanel' ),
			'tab_cleared' => true,
		] );
	}

	// =========================================================================
	// View helpers (called from views/page.php)
	// =========================================================================

	/**
	 * Render the column mapping UI for a flow.
	 *
	 * @param string   $flow       'orders' | 'customers'
	 * @param string[] $selected   Currently saved column keys in order.
	 * @param array    $catalogue  Catalogue entries (label / mandatory / group).
	 */
	public static function render_column_mapper( $flow, array $selected, array $catalogue ) {
		// Render selected items first (preserving order), then unselected at the bottom.
		$rendered = [];
		?>
		<div class="bp-gs-column-mapper" data-flow="<?php echo esc_attr( $flow ); ?>">
			<?php
			// Two-way notice: list exactly which columns sync Sheets -> Woo so a
			// merchant never assumes an edit to a display-only column (name,
			// description, price, ...) will reach the store. Renders on the
			// orders flow (order_status) and the products flow (stock, price,
			// COGS, status); the customers flow has no writable columns.
			$writable_labels = [];
			foreach ( $catalogue as $ck => $cmeta ) {
				if ( ! empty( $cmeta['writable'] ) && isset( $cmeta['label'] ) ) {
					$writable_labels[] = (string) $cmeta['label'];
				}
			}
			// Writeback only actually happens when that flow's reverse-sync
			// toggle is on. Saying "your edits are written back" while the
			// toggle is off is the exact mismatch that makes merchants report
			// two-way sync as missing, so surface the real state here.
			// The sync classes load in the same all-or-nothing block as this
			// one (see brikpanel-google-sheets.php), so they are always here in
			// practice; class_exists keeps a direct third-party call to this
			// public static from fataling, and defaults to the quiet state.
			$pull_on = true;
			if ( 'orders' === $flow && class_exists( 'Brikpanel_Sheets_Order_Sync' ) ) {
				$pull_on = Brikpanel_Sheets_Order_Sync::pull_enabled();
			} elseif ( 'products' === $flow && class_exists( 'Brikpanel_Sheets_Products_Sync' ) ) {
				$pull_on = Brikpanel_Sheets_Products_Sync::pull_enabled();
			}
			if ( $writable_labels ) :
			?>
				<div class="bp-gs-twoway-note">
					<span class="bp-gs-twoway-note-title">
						<span class="bp-gs-col-badge"><?php echo esc_html( Brikpanel_Sheets_Mapping::WRITABLE_GLYPH ); ?></span>
						<?php esc_html_e( 'Two-way columns', 'brikpanel' ); ?>
					</span>
					<p>
						<?php
						printf(
							/* translators: %s: comma-separated list of column names that sync back to the store. */
							esc_html__( 'Edits you make in the sheet are written back to your store for these columns only: %s.', 'brikpanel' ),
							'<strong>' . esc_html( implode( ', ', $writable_labels ) ) . '</strong>'
						);
						?>
					</p>
					<p><?php esc_html_e( 'Every other column is one-way (store to sheet). Anything you type into those cells is display-only and will be overwritten on the next sync. Columns that sync back are marked with this glyph in the sheet header.', 'brikpanel' ); ?></p>
					<?php if ( ! $pull_on ) : ?>
						<p class="bp-gs-twoway-note-off">
							<?php
							if ( 'orders' === $flow ) {
								esc_html_e( 'Two-way sync is currently turned off, so edits in the sheet are not written back yet. Turn on "Two-way sync: apply status changes from Sheets" below and save to enable it.', 'brikpanel' );
							} else {
								esc_html_e( 'Two-way sync is currently turned off, so edits in the sheet are not written back yet. Turn on the two-way sync option below and save to enable it.', 'brikpanel' );
							}
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<ul class="bp-gs-column-list" data-role="selected">
				<?php foreach ( $selected as $key ) :
					if ( ! isset( $catalogue[ $key ] ) ) {
						continue;
					}
					$meta = $catalogue[ $key ];
					$mandatory = ! empty( $meta['mandatory'] );
					$rendered[ $key ] = true;
				?>
					<li class="bp-gs-column-item is-selected<?php echo $mandatory ? ' is-mandatory' : ''; ?>" data-key="<?php echo esc_attr( $key ); ?>">
						<label class="bp-gs-checkbox">
							<input type="checkbox" checked <?php disabled( $mandatory ); ?>>
							<span><?php echo esc_html( $meta['label'] ); ?></span>
						</label>
						<?php if ( Brikpanel_Sheets_Mapping::is_writable( $flow, $key ) ) : ?>
							<span class="bp-gs-col-badge" title="<?php esc_attr_e( 'Edits to this column sync back to your store', 'brikpanel' ); ?>"><?php echo esc_html( Brikpanel_Sheets_Mapping::WRITABLE_GLYPH ); ?> <?php esc_html_e( 'two-way', 'brikpanel' ); ?></span>
						<?php endif; ?>
						<span class="bp-gs-column-actions">
							<button type="button" class="bp-gs-icon-btn" data-act="up" aria-label="<?php esc_attr_e( 'Move up', 'brikpanel' ); ?>">↑</button>
							<button type="button" class="bp-gs-icon-btn" data-act="down" aria-label="<?php esc_attr_e( 'Move down', 'brikpanel' ); ?>">↓</button>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<details class="bp-gs-column-extras">
				<summary><?php esc_html_e( 'Show more columns', 'brikpanel' ); ?></summary>
				<ul class="bp-gs-column-list" data-role="available">
					<?php
					$custom_heading_done = false;
					foreach ( $catalogue as $key => $meta ) :
						if ( isset( $rendered[ $key ] ) ) {
							continue;
						}
						// Set off the auto-discovered custom checkout/order fields
						// from the built-in columns with a single heading so the
						// list reads as intentional, not a data dump.
						if ( ! $custom_heading_done && isset( $meta['group'] ) && $meta['group'] === 'custom' ) :
							$custom_heading_done = true;
						?>
							<li class="bp-gs-column-grouplabel" aria-hidden="true"><?php esc_html_e( 'Custom checkout / order fields', 'brikpanel' ); ?></li>
						<?php endif; ?>
						<li class="bp-gs-column-item" data-key="<?php echo esc_attr( $key ); ?>">
							<label class="bp-gs-checkbox">
								<input type="checkbox">
								<span><?php echo esc_html( $meta['label'] ); ?></span>
							</label>
							<?php if ( Brikpanel_Sheets_Mapping::is_writable( $flow, $key ) ) : ?>
								<span class="bp-gs-col-badge" title="<?php esc_attr_e( 'Edits to this column sync back to your store', 'brikpanel' ); ?>"><?php echo esc_html( Brikpanel_Sheets_Mapping::WRITABLE_GLYPH ); ?> <?php esc_html_e( 'two-way', 'brikpanel' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
			<div class="bp-gs-actions">
				<button type="button" class="bp-gs-btn bp-gs-btn-primary" data-action="save-columns"><?php esc_html_e( 'Save columns', 'brikpanel' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the activity / log card for a flow.
	 *
	 * @param string $flow
	 * @param array  $last_sync
	 */
	public static function render_activity_card( $flow, $last_sync ) {
		$ts   = (int) ( $last_sync['ts'] ?? 0 );
		$rows = (int) ( $last_sync['rows'] ?? 0 );
		?>
		<div class="bp-gs-card">
			<div class="bp-gs-card-body">
				<h2><?php esc_html_e( 'Recent activity', 'brikpanel' ); ?></h2>
				<dl class="bp-gs-dl">
					<dt><?php esc_html_e( 'Last successful sync', 'brikpanel' ); ?></dt>
					<dd>
						<?php
						if ( $ts > 0 ) {
							/* translators: 1: time-ago, 2: row count */
							printf(
								esc_html__( '%1$s ago, %2$d rows', 'brikpanel' ),
								esc_html( human_time_diff( $ts, time() ) ),
								(int) $rows
							);
						} else {
							echo '—';
						}
						?>
					</dd>
				</dl>
				<div class="bp-gs-actions">
					<button type="button" class="bp-gs-btn bp-gs-btn-link" data-action="view-log" data-flow="<?php echo esc_attr( $flow ); ?>">
						<?php esc_html_e( 'View error log', 'brikpanel' ); ?>
					</button>
				</div>
				<div class="bp-gs-log" id="bp-gs-log-<?php echo esc_attr( $flow ); ?>" hidden></div>
			</div>
		</div>
		<?php
	}
}
