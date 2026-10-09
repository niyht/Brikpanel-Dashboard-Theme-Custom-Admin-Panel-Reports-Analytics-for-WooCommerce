<?php
/**
 * BrikPanel — Ad Platforms dashboard injector.
 *
 * Wires the Ad Platforms module into the BrikPanel dashboard via the two
 * hooks added to Brikpanel_Dashboard:
 *
 *   - `brikpanel_dashboard_after_kpis` (action)  → render extra KPI cards.
 *   - `brikpanel_dashboard_data`       (filter)  → attach ad_spend payload.
 *
 * Renders three new cards next to the existing KPIs:
 *
 *   - Ad Spend  — sum across all connected platforms in the active range.
 *   - ROAS      — store revenue ÷ ad spend (single number, or "—" if no
 *                 ad spend in the range).
 *   - Net Profit — revenue − COGS − ad spend − manual expenses.
 *
 * The class is also responsible for two practical details:
 *
 *   - Multi-currency. Ad accounts often report in a different currency than
 *     the store. We never blindly sum mixed currencies — when more than one
 *     currency is present we group spend per currency and the front-end
 *     shows them as "₺X + $Y" so the merchant knows both halves.
 *
 *   - Currency-aware ROAS / Net Profit. When the ad currency differs from
 *     the store currency, those derived figures can't be computed without
 *     a conversion rate. We omit them (UI shows "—") rather than print a
 *     bogus number the user will treat as gospel.
 *
 * @package BrikPanel
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Dashboard {

	/**
	 * Site-wide lock behind the dashboard's manual "update ad spend" button.
	 *
	 * Site-wide rather than per-user because the people who asked for this
	 * button are an agency: five staff looking at the same store with a
	 * per-user lock would make five times the identical API calls for
	 * byte-identical data.
	 *
	 * 90 seconds is longer than the worst realistic run of the thing it
	 * guards (both API clients use MAX_ATTEMPTS 3, BACKOFF [1,3,6] and a 30s
	 * timeout), and it can never cost data: Meta and Google revise recent-day
	 * figures on an hours-long cadence, so two pulls half a minute apart
	 * return the same numbers by construction.
	 */
	const REFRESH_COOLDOWN_KEY = 'brikpanel_ads_refresh_cooldown';
	const REFRESH_COOLDOWN_SEC = 90;

	public function __construct() {
		add_action( 'brikpanel_dashboard_after_kpis', [ $this, 'render_kpi_cards' ] );
		add_filter( 'brikpanel_dashboard_data',       [ $this, 'inject_ad_payload' ], 10, 4 );
		add_action( 'admin_enqueue_scripts',          [ $this, 'enqueue_inline_assets' ] );
		// Registered here rather than in the settings class so it inherits this
		// class's own gate for free: brikpanel-ad-platforms.php returns before
		// the class loader when the module is switched off, so with the module
		// off the endpoint does not exist at all.
		add_action( 'wp_ajax_brikpanel_ads_refresh_spend', [ $this, 'ajax_refresh_spend' ] );
	}

	// =========================================================================
	// Render (PHP) — empty card markup; values fill in from AJAX
	// =========================================================================

	public function render_kpi_cards() {
		// Only render the cards when at least one platform has any data —
		// otherwise the cards would just show "—" forever and confuse the user.
		if ( ! self::has_any_data() ) {
			return;
		}
		// The refresh button is gated HARDER than the cards. The cards only need
		// historical rows to be worth drawing; the button needs a platform that
		// a pull can actually succeed against, because run_inline() throws
		// "Choose at least one ad account first." every single time otherwise, and a
		// button that can only ever fail is worse than no button.
		$can_refresh = [] !== self::refreshable_platforms();
		?>
		<div class="brikpanel-dash-cards brikpanel-dash-cards-ads" id="brikpanel-ads-kpis">
			<div class="brikpanel-dash-card" data-metric="roas" id="brikpanel-roas-card">
				<div class="brikpanel-dash-card-head">
					<span class="brikpanel-dash-card-label"><span class="brikpanel-dash-card-label-text"><?php esc_html_e( 'ROAS', 'brikpanel' ); ?></span></span>
					<span class="brikpanel-dash-card-tools">
						<?php if ( $can_refresh ) : ?>
						<button type="button" class="brikpanel-dash-ads-refresh" id="brikpanel-ads-refresh"
							title="<?php esc_attr_e( 'Pull today\'s spend from your connected ad platforms now, instead of waiting for the daily sync.', 'brikpanel' ); ?>"
							aria-label="<?php esc_attr_e( 'Update ad spend', 'brikpanel' ); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<polyline points="23 4 23 10 17 10"></polyline>
								<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
							</svg>
						</button>
						<?php endif; ?>
						<?php
						/*
						 * Cost per order is folded away behind a chevron rather than
						 * printed under the ROAS figure. Printed, it added four lines
						 * to this card, and because grid items stretch, every other
						 * card in the KPI row grew with it and stood half empty. This
						 * is the disclosure the Revenue and Expenses cards already
						 * use — same classes, so the chevron, the rotation and the
						 * 0fr-to-1fr open share their styling and cannot drift from
						 * them. Collapsed it adds no flow height at all, so the ROAS
						 * card measures exactly like its five neighbours.
						 */
						?>
						<button type="button" class="brikpanel-dash-bd-toggle" id="brikpanel-ads-cpo-toggle"
							aria-expanded="false" aria-controls="brikpanel-ads-cpo-collapse" hidden
							title="<?php esc_attr_e( 'Show ad cost per order', 'brikpanel' ); ?>"
							aria-label="<?php esc_attr_e( 'Show ad cost per order', 'brikpanel' ); ?>">
							<svg class="brikpanel-dash-bd-chevron" width="14" height="14" viewBox="0 0 24 24"
								fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
								stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
						</button>
					</span>
				</div>
				<span class="brikpanel-dash-card-value" id="card-roas">--</span>
				<span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-roas"></span>
				<?php
				/*
				 * inert, not just visually collapsed. The "?" inside carries
				 * tabindex="0", so without this a keyboard user tabbing along
				 * the row would land on a control folded away to zero height
				 * with nothing on screen to show where the focus went. The
				 * toggle clears it when it opens the panel.
				 */
				?>
				<div class="brikpanel-dash-bd-collapse" id="brikpanel-ads-cpo-collapse" inert>
				<div class="brikpanel-dash-bd-inner">
				<div class="brikpanel-dash-ads-cpo">
					<div class="brikpanel-dash-ads-cpo-row">
						<?php
						/*
						 * Two strings for one figure, on purpose.
						 *
						 * The visible one is short because this label shares a
						 * one-line slot with a currency figure inside a card
						 * that is a sixth of the KPI row. "Ad cost per order"
						 * fits that slot in English and in no other language we
						 * ship: the Turkish reading is 30 characters and the
						 * Dutch 32, which broke one word per line, four lines
						 * deep, and since grid items stretch that set the height
						 * of all six cards in the row.
						 *
						 * The full phrase is not lost. It is read out to screen
						 * readers here, it titles the "?" beside it, and its
						 * msgid is the one the nine existing translations are
						 * attached to, so none of them are orphaned.
						 *
						 * The visible text needs its own element rather than
						 * sitting as a bare text node next to the "?": a bare
						 * node inside a flex container becomes an anonymous flex
						 * item, and no selector can reach one.
						 */
						?>
						<span class="brikpanel-dash-ads-cpo-label">
							<span class="screen-reader-text"><?php esc_html_e( 'Ad cost per order', 'brikpanel' ); ?></span>
							<span class="brikpanel-dash-ads-cpo-label-text" aria-hidden="true"><?php
								echo esc_html( _x( 'Cost / order', 'compact KPI sub-label inside the ROAS card: ad spend divided by order count', 'brikpanel' ) );
							?></span>
							<?php self::render_cpo_hint(); ?>
						</span>
						<span class="brikpanel-dash-card-value brikpanel-dash-ads-cpo-value" id="card-ad-cpo">--</span>
					</div>
					<span class="brikpanel-dash-ads-cpo-note" id="delta-ad-cpo"></span>
				</div>
				</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * "?" tooltip on the Ad cost per order figure inside the ROAS card.
	 *
	 * The body spells out the divisor on purpose. This figure and the ROAS
	 * above it do NOT share a basis: ROAS divides COMBINED revenue (site +
	 * marketplace) by spend, while this divides spend by the SITE-ONLY order
	 * count, the same number the Orders card shows. Site-only is the right
	 * divisor here, because ad spend cannot produce a marketplace order, but
	 * that is invisible from the figure alone and an unexplained number on a
	 * KPI card gets believed.
	 *
	 * Copies Brikpanel_Dashboard::render_hint()'s markup rather than calling
	 * it: that method is private, and widening a core method's visibility for
	 * one add-on figure is a worse trade than twelve lines of markup.
	 *
	 * The bubble is wider than the card it hangs off and is meant to be: it
	 * opens fixed over the page (front-end/shared/brikpanel-tip.js), so the
	 * card's clip and the folding panel's clip never cut it, and the helper
	 * measures where it fits on screen at open time.
	 */
	private static function render_cpo_hint() {
		$title = __( 'How Ad cost per order is calculated', 'brikpanel' );
		$body  = __( 'Ad spend for the selected period divided by the orders placed in it.', 'brikpanel' )
			. '<br>' . __( 'Orders placed by store administrators are not counted.', 'brikpanel' );
		if ( function_exists( 'brikpanel_brikmarket_active' ) && brikpanel_brikmarket_active() ) {
			$body .= '<br>' . __( 'Marketplace orders are not counted, because ad spend drives your own store.', 'brikpanel' );
		}
		?>
		<span class="brikpanel-dash-hint" data-bp-tip="start" tabindex="0" role="button" aria-expanded="false" aria-label="<?php echo esc_attr( $title ); ?>" aria-describedby="brikpanel-cpo-hint-body">
			<svg class="brikpanel-dash-hint-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="12" cy="12" r="10"></circle>
				<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
				<line x1="12" y1="17" x2="12.01" y2="17"></line>
			</svg>
			<span class="brikpanel-dash-hint-tip brikpanel-tip" role="tooltip">
				<span class="brikpanel-dash-hint-title"><?php echo esc_html( $title ); ?></span>
				<span class="brikpanel-dash-hint-body" id="brikpanel-cpo-hint-body"><?php echo wp_kses( $body, [ 'br' => [], 'strong' => [] ] ); ?></span>
			</span>
		</span>
		<?php
	}

	// =========================================================================
	// AJAX payload injection
	// =========================================================================

	/**
	 * Append ad_spend / roas / net_profit to the dashboard payload.
	 *
	 * @param array  $payload
	 * @param array  $window      ['start_local', 'end_local']
	 * @param string $range
	 * @param float  $total_sales Revenue already calculated by the dashboard.
	 */
	public function inject_ad_payload( $payload, $window, $range, $total_sales ) {
		if ( ! is_array( $payload ) ) {
			return $payload;
		}
		$start_date = isset( $window['start_local'] ) ? substr( (string) $window['start_local'], 0, 10 ) : '';
		$end_date   = isset( $window['end_local'] ) ? substr( (string) $window['end_local'], 0, 10 ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return $payload;
		}

		$totals = Brikpanel_Ads_Store::totals_for_range( $start_date, $end_date );

		$store_currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $store_currency ), ENT_QUOTES, 'UTF-8' ) : '';

		// Group spend by currency.
		$by_currency = [];
		$by_platform = [];
		foreach ( $totals as $row ) {
			$cur = $row['currency'] !== '' ? $row['currency'] : $store_currency;
			if ( ! isset( $by_currency[ $cur ] ) ) {
				$by_currency[ $cur ] = 0.0;
			}
			$by_currency[ $cur ] += (float) $row['spend'];
			$plat = (string) $row['platform'];
			if ( ! isset( $by_platform[ $plat ] ) ) {
				$by_platform[ $plat ] = [ 'spend' => 0.0, 'currency' => $cur ];
			}
			$by_platform[ $plat ]['spend'] += (float) $row['spend'];
		}

		// ROAS + Net Profit only when ad currency matches store currency.
		$roas_value      = null;
		$net_profit      = null;
		$net_profit_curr = $store_currency;
		$same_currency_spend = 0.0;
		$cost_per_order  = null;
		$cpo_orders      = 0;
		if ( $store_currency !== '' && isset( $by_currency[ $store_currency ] ) && count( $by_currency ) === 1 ) {
			$same_currency_spend = (float) $by_currency[ $store_currency ];
			if ( $same_currency_spend > 0 ) {
				$roas_value = (float) $total_sales / $same_currency_spend;
			}
			$cogs        = self::compute_cogs( $window );
			$expenses    = self::compute_manual_expenses( $start_date, $end_date );
			// Real gateway processing fees are part of Expenses on the Profit
			// card, so they have to be part of this figure too or the two would
			// quietly disagree about the same period. Read from the profit block
			// the dashboard already built for THIS window rather than re-querying:
			// same basis by construction, and no second pass over the orders.
			$fees        = isset( $payload['profit']['payment_fees_raw'] )
				? (float) $payload['profit']['payment_fees_raw']
				: 0.0;
			$net_profit  = (float) $total_sales - $cogs - $same_currency_spend - $expenses - $fees;

			// Cost per order. Deliberately inside the SAME currency gate as
			// ROAS rather than repeating the condition: an ad spend in TRY
			// divided by orders on a USD store is a number with no unit, and
			// inheriting the gate means the two cards can never drift apart if
			// somebody changes the rule later.
			//
			// The divisor is the order count the dashboard has ALREADY computed
			// for this window (same trick as payment_fees_raw above), so it
			// cannot disagree with the Orders card sitting next to it and it
			// costs no second query. Null when there are no orders: a division
			// by zero is not a figure, it is a crash with a decimal point.
			$cpo_orders = isset( $payload['order_count'] ) ? (int) $payload['order_count'] : 0;
			if ( $cpo_orders > 0 ) {
				$cost_per_order = $same_currency_spend / $cpo_orders;
			}
		}

		$payload['ad_spend'] = [
			'currencies'         => $by_currency,                          // e.g. { TRY: 5000, USD: 200 }
			'by_platform'        => $by_platform,                          // e.g. { google_ads: { spend, currency } }
			'store_currency'     => $store_currency,
			'store_symbol'       => $currency_symbol,
			'roas'               => $roas_value,                           // null if cross-currency or zero spend
			'net_profit'         => $net_profit,                           // null when cross-currency
			'net_profit_currency'=> $net_profit_curr,
			// Formatted server-side with wc_price(), like every other money
			// card on this dashboard. Intl.NumberFormat in the browser would
			// use the VIEWER's locale separators, so this one card would read
			// "1.234,56 $" beside an Avg. Order Value of "1,234.56 $" on any
			// store whose WooCommerce separators differ from the browser.
			'cost_per_order'          => $cost_per_order,   // null: cross-currency, no spend rows, or no orders
			// function_exists like the rest of this module (vendors, stock
			// orders). Belt and braces here: the payload this filter runs on is
			// built by code that already calls wc_price() unguarded, so a site
			// without it would have fatalled long before this line. A null
			// simply drops the card back to its client-side money formatter.
			'cost_per_order_display'  => ( null === $cost_per_order || ! function_exists( 'wc_price' ) ) ? null : wc_price( $cost_per_order ),
			'cost_per_order_currency' => $store_currency,
			'cost_per_order_orders'   => $cpo_orders,       // the divisor, so the UI can state it
			'has_data'           => ! empty( $totals ),
		];
		return $payload;
	}

	// =========================================================================
	// Manual refresh (dashboard button)
	// =========================================================================

	/**
	 * Platforms a manual refresh can actually pull from.
	 *
	 * Derived from the vault, never from the request. describe() reports
	 * connected=false for a vault that cannot be decrypted, so a site whose
	 * stored credentials went unreadable lands in the "nothing connected"
	 * branch instead of spending the cooldown on two guaranteed failures.
	 *
	 * The account half matters just as much: run_inline() throws "Choose at
	 * least one ad account first." before it opens a socket, so a platform
	 * with no account ticked is not a refresh candidate, it is a settings
	 * problem.
	 *
	 * @return string[]
	 */
	private static function refreshable_platforms() {
		if ( ! class_exists( 'Brikpanel_Ads_Tokens' ) ) {
			return [];
		}
		$out = [];
		foreach ( [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ] as $platform ) {
			$desc = Brikpanel_Ads_Tokens::describe( $platform );
			if ( ! empty( $desc['connected'] ) && ! empty( $desc['accounts'] ) ) {
				$out[] = $platform;
			}
		}
		return $out;
	}

	/**
	 * Seconds left on the manual-refresh lock, 0 when it is free.
	 *
	 * The transient's VALUE is the absolute expiry timestamp, not a flag:
	 * get_transient() cannot report a remaining TTL, and a 429 that cannot say
	 * how long is left reads as a dead button rather than a busy one.
	 */
	private static function cooldown_remaining() {
		$until = (int) get_transient( self::REFRESH_COOLDOWN_KEY );
		return $until > time() ? $until - time() : 0;
	}

	/**
	 * Claim the lock. Called BEFORE any network work, so it doubles as the
	 * concurrency guard: a second click arriving while the first pull is still
	 * in flight is rejected without opening a second sleep()-heavy PHP worker.
	 */
	private static function start_cooldown() {
		set_transient( self::REFRESH_COOLDOWN_KEY, time() + self::REFRESH_COOLDOWN_SEC, self::REFRESH_COOLDOWN_SEC );
	}

	/**
	 * Merchant-facing platform name. Reuses the msgids the Expenses breakdown
	 * already ships, so the partial-failure sentence costs no new translation.
	 */
	private static function platform_label( $platform ) {
		if ( class_exists( 'Brikpanel_Ads_Tokens' ) && Brikpanel_Ads_Tokens::PLATFORM_GOOGLE === $platform ) {
			return __( 'Google Ads', 'brikpanel' );
		}
		return __( 'Meta Ads', 'brikpanel' );
	}

	/**
	 * Manual "update ad spend" from the dashboard.
	 *
	 * One endpoint covering every connected platform, rather than the browser
	 * calling the settings page's per-platform brikpanel_ads_sync_now twice.
	 * Four reasons, in ascending order of importance: one WordPress bootstrap
	 * instead of two; one cooldown instead of two independent ones; the
	 * partial-failure sentence composed in PHP where it can be translated
	 * (a .js file may not carry user-visible text); and above all ONE cache
	 * bust, because the dashboard payload transient is what actually decides
	 * whether the merchant sees new numbers, and busting it between two
	 * requests would leave the second platform's rows invisible for the rest
	 * of the TTL.
	 */
	public function ajax_refresh_spend() {
		// Non-dying nonce check on purpose. The dying form answers a bare "-1",
		// which r.json() throws on, so the merchant would get the generic
		// fallback instead of "your session expired" — the one message that
		// actually tells them what to do on a tab left open overnight.
		if ( ! check_ajax_referer( Brikpanel_Ads_Settings::NONCE_ACTION, '_ajax_nonce', false ) ) {
			wp_send_json_error( [
				'message' => __( 'Your session expired. Reload the page and try again.', 'brikpanel' ),
				'status'  => 'expired',
				'refetch' => false,
			], 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [
				'message' => __( 'Permission denied.', 'brikpanel' ),
				'status'  => 'denied',
				'refetch' => false,
			], 403 );
		}

		$targets = self::refreshable_platforms();
		if ( ! $targets ) {
			// Answered BEFORE the cooldown is claimed, which is what makes a
			// separate "release the lock on a config error" escape hatch
			// unnecessary: nothing was called, and a merchant one click away
			// from fixing their connection must not wait 90 seconds to retry.
			wp_send_json_error( [
				'message' => __( 'No ad platform is connected yet. Connect one to pull spend.', 'brikpanel' ),
				'status'  => 'idle',
				'refetch' => false,
			], 400 );
		}

		$left = self::cooldown_remaining();
		if ( $left > 0 ) {
			wp_send_json_error( [
				'message'     => sprintf(
					/* translators: %d: seconds to wait before ad spend can be updated again. */
					_n(
						'Ad spend was just updated. Try again in %d second.',
						'Ad spend was just updated. Try again in %d seconds.',
						$left,
						'brikpanel'
					),
					$left
				),
				'status'      => 'cooldown',
				'retry_after' => $left,
				// Refetch anyway. In an agency the usual reason to hit this lock
				// is that a colleague pulled forty seconds ago, which means THEIR
				// rows are already in the database and this browser is the stale
				// one. A refetch costs one transient read and is the difference
				// between the lock feeling protective and feeling broken.
				'refetch'     => true,
			], 429 );
		}
		self::start_cooldown();

		$sync     = new Brikpanel_Ads_Sync();
		$ok       = [];
		$failed   = [];
		$errors   = [];
		$detail   = [];
		$deferred = false;
		// One budget for the whole request: every platform may have up to
		// twenty accounts, pulled one after another inside this browser
		// request. Accounts left when it runs out wait for the daily sync.
		$deadline = time() + Brikpanel_Ads_Sync::INLINE_BUDGET_SECONDS;

		foreach ( $targets as $platform ) {
			// Inside the loop, not before it: set_time_limit RESETS the counter,
			// so this hands each platform its own budget. One call outside would
			// let a slow Google pull eat Meta's share of the same allowance.
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			try {
				$result = (array) $sync->run_inline( $platform, $deadline );
				$ok[]   = $platform;
				// 'days' (distinct days returned by the API), never 'rows' ($wpdb
				// affected-rows, which counts 2 for every updated row).
				$detail[ $platform ] = [ 'ok' => empty( $result['failed'] ), 'days' => (int) ( $result['days'] ?? 0 ) ];

				// Some accounts of this platform failed while others landed:
				// that is the partial answer below, like a failed platform.
				if ( ! empty( $result['failed'] ) ) {
					$account_id = (string) array_key_first( $result['failed'] );
					$failed[]   = $platform;
					$errors[]   = Brikpanel_Ads_Sync::account_error( $account_id, (string) $result['failed'][ $account_id ] );
					$detail[ $platform ]['error'] = end( $errors );
				}
				if ( ! empty( $result['deferred'] ) ) {
					$deferred = true;
				}

				// Bust per platform, not once after the loop. The rows are
				// already written; if this worker dies while pulling the next
				// platform (set_time_limit disabled on the host, a fastcgi read
				// timeout, a fatal in the other client) they would otherwise
				// stay invisible behind the dashboard transient for its full
				// TTL — up to ten minutes on a site with no object cache.
				//
				// Bulk_upsert() does NOT do this for us despite what its own
				// comment says: it bumps brikpanel_ads_cache_version, which only
				// keys the "has any ads data at all" transient, never bp_dash_*.
				//
				// brikpanel_bust_data_caches() coalesces per request (first call
				// bumps at once, later ones add a single shutdown bump), so this
				// costs at most two option writes however many platforms run.
				if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
					brikpanel_bust_data_caches();
				}
			} catch ( \Throwable $e ) {
				$message  = $e->getMessage();
				$failed[] = $platform;
				$errors[] = $message;
				$detail[ $platform ] = [ 'ok' => false, 'error' => $message ];
				if ( class_exists( 'Brikpanel_Ads_Logger' ) ) {
					Brikpanel_Ads_Logger::log( 'sync', 'Dashboard refresh ' . $platform . ' failed: ' . $message );
				}
				// run_inline() records the failed attempt for the settings card
				// itself, so it no longer claims "last synced OK" after a failure
				// the merchant just watched happen.
			}
		}

		// The lock was taken before the pulls, which may have used most of it.
		// Hold it for the full period again from now, so the next click cannot
		// start a second round straight after this one.
		self::start_cooldown();

		// One last bump, before the response rather than after it.
		//
		// brikpanel_bust_data_caches() coalesces: the FIRST call in a request
		// bumps the version straight away and every later one is deferred to
		// `shutdown`. Shutdown runs after this response has already been handed
		// to the browser, and the browser refetches the instant it reads it. So
		// on a store with two platforms connected, the second platform's rows
		// were invalidated by a bump that could land after the refetch had
		// already read the old version, and on a busy store another request
		// could re-cache the pre-pull figures under it in between.
		//
		// Bumping here closes that window: by the time the browser has the
		// response, every row this request wrote is already behind a version no
		// cached payload can be holding. Cheap enough to do unconditionally on
		// the success paths, one option write, and it makes the deferred bump a
		// harmless no-op rather than load-bearing.
		if ( function_exists( 'brikpanel_bump_data_cache_ver' ) ) {
			remove_action( 'shutdown', 'brikpanel_bump_data_cache_ver', PHP_INT_MAX );
			brikpanel_bump_data_cache_ver();
		}

		if ( ! $ok ) {
			// Surface the upstream sentence verbatim. The Meta client already
			// maps known Graph codes to merchant-readable text, and a raw Google
			// message is diagnostic data worth quoting to support, not a
			// sentence to translate.
			wp_send_json_error( [
				'message'   => (string) reset( $errors ),
				'status'    => 'failed',
				'refetch'   => false,
				'platforms' => $detail,
			] );
		}

		if ( $failed ) {
			// A partial stays a SUCCESS, deliberately. Real rows landed and the
			// cache was busted; if the browser treated this as an error and
			// skipped the refetch, the merchant would sit looking at stale
			// figures while fresh ones wait in the database — precisely the
			// failure this button exists to remove. The red toast is what says
			// something went wrong.
			$first = reset( $failed );
			// A platform that answered for some accounts and not others is not
			// "unreachable": name the account and what the platform said.
			$message = in_array( $first, $ok, true )
				? sprintf(
					/* translators: %s: "Account <ID>: <error message from the ad platform>" */
					__( 'Ad spend updated, but not for every account. %s', 'brikpanel' ),
					(string) reset( $errors )
				)
				: sprintf(
					/* translators: %s: ad platform name, for example "Google Ads". */
					__( 'Ad spend updated, but %s could not be reached.', 'brikpanel' ),
					self::platform_label( $first )
				);
			wp_send_json_success( [
				'message'   => $message,
				'status'    => 'partial',
				'toast'     => 'error',
				'refetch'   => true,
				'platforms' => $detail,
			] );
		}

		$message = __( 'Ad spend updated.', 'brikpanel' );
		if ( $deferred ) {
			$message .= ' ' . __( 'The other accounts will be updated by the next daily sync.', 'brikpanel' );
		}
		wp_send_json_success( [
			'message'   => $message,
			'status'    => 'ok',
			'toast'     => 'success',
			'refetch'   => true,
			'platforms' => $detail,
		] );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Quick existence check used to skip rendering the cards entirely until
	 * the first sync writes a row. Hits a tiny COUNT against the cache version
	 * so we only re-check when new data lands.
	 */
	private static function has_any_data() {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$ver  = Brikpanel_Ads_Store::cache_version();
		$key  = 'bp_ads_has_data_' . $ver;
		$hit  = get_transient( $key );
		if ( $hit === '1' || $hit === '0' ) {
			$memo = $hit === '1';
			return $memo;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Brikpanel_Ads_Store::table() . " LIMIT 1" );
		$memo  = $count > 0;
		set_transient( $key, $memo ? '1' : '0', HOUR_IN_SECONDS );
		return $memo;
	}

	/**
	 * Sum COGS across orders inside the window. Uses WooCommerce's built-in
	 * COGS feature flag (`_wc_cog_item_total_value` order meta), which
	 * BrikPanel enables by default on activation. Returns 0 when the
	 * feature is disabled or the orders have no COGS recorded.
	 */
	private static function compute_cogs( $window ) {
		global $wpdb;
		$start = isset( $window['start_local'] ) ? (string) $window['start_local'] : '';
		$end   = isset( $window['end_local'] ) ? (string) $window['end_local'] : '';
		if ( $start === '' || $end === '' ) {
			return 0.0;
		}

		// $window carries SITE-LOCAL dates, usually already truncated to Y-m-d,
		// but the columns below are UTC datetimes. Feeding a bare local date
		// straight in made MySQL read it as local-midnight-as-UTC: the window
		// started late, ended ~a day short, and for a single-day range start and
		// end collapsed to the same instant so this returned 0. Widen to the full
		// local day first, then convert to GMT the same way calculate_dates()
		// does, so this matches the COGS the KPI card reports.
		$start = get_gmt_from_date( substr( $start, 0, 10 ) . ' 00:00:00' );
		$end   = get_gmt_from_date( substr( $end, 0, 10 ) . ' 23:59:59' );
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		$paid    = brikpanel_paid_order_statuses();
		$placeholders = implode( ',', array_fill( 0, count( $paid ), '%s' ) );

		if ( $is_hpos ) {
			$sql = "SELECT COALESCE(SUM(CAST(om.meta_value AS DECIMAL(20,4))), 0)
				FROM {$wpdb->prefix}wc_orders o
				" . brikpanel_sql_single_meta_join( 'order', 'om', 'o.id', '_wc_cog_order_total_value', '', 'INNER' ) . "
				WHERE o.type = 'shop_order'
				AND o.status IN ($placeholders)
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s";
		} else {
			$sql = "SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))), 0)
				FROM {$wpdb->posts} p
				" . brikpanel_sql_single_meta_join( 'post', 'pm', 'p.ID', '_wc_cog_order_total_value', '', 'INNER' ) . "
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ($placeholders)
				AND p.post_date_gmt >= %s
				AND p.post_date_gmt <= %s";
		}

		$args = array_merge( $paid, [ $start, $end ] );
		$val  = $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		return (float) $val;
	}

	/**
	 * Sum the manual `wp_brikpanel_expenses` table inside the date range.
	 *
	 * Money rows only. A percentage row's `amount` holds a RATE and a per-order
	 * row's a UNIT PRICE. Summing either here reported a 2.9% commission as
	 * £2.90 of ad-adjacent spend.
	 */
	private static function compute_manual_expenses( $start_date, $end_date ) {
		global $wpdb;
		$table = $wpdb->prefix . 'brikpanel_expenses';
		$kinds = function_exists( 'brikpanel_expense_money_kinds_sql' ) ? brikpanel_expense_money_kinds_sql() : '';
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE expense_date BETWEEN %s AND %s{$kinds}",
			$start_date,
			$end_date
		) );
		return (float) $val;
	}

	// =========================================================================
	// Inline assets — colour the new card row consistently with the rest of
	// the dashboard, plus the small JS that fills the new cards from the
	// shared AJAX payload.
	// =========================================================================

	public function enqueue_inline_assets( $hook ) {
		// Only on the BrikPanel dashboard page.
		if ( $hook !== 'toplevel_page_brikpanel-dashboard'
			&& strpos( (string) $hook, 'brikpanel-dashboard' ) === false ) {
			return;
		}

		$css = '
			/* The ads cards are relocated by JS into the main KPI row
			   (#brikpanel-kpi-cards) so they sit inline instead of dropping to
			   a row of their own below. That row is laid out by the shared
			   tiles helper (front-end/shared/brikpanel-tiles.js): six cards,
			   3 + 3 or 2 + 2 + 2, the widest that fits the real figures, so
			   no column count is set here. A figure overflows rather than
			   breaking mid-number, which is what lets the helper see that a
			   row is too tight. */
			.brikpanel-dash-card[data-metric="roas"] .brikpanel-dash-card-delta {
				color: #616161; font-size: 0.75rem;
			}
			/* Refresh and the chevron sit in the head row of the card, beside the
			   label (.brikpanel-dash-card-tools in brikpanel-dashboard.css), in
			   the flow: no corner positions, no space held back in the label. */

			/* "Ad cost per order" sits inside the ROAS card rather than beside
			   it. Two cards for two readings of the same ad spend left the KPI
			   row ragged, and the two figures are easier to read together than
			   apart. A rule and a smaller type size keep it clearly secondary
			   to the ROAS figure above it. */
			/* Matches .brikpanel-dash-bd-list, the body of the other two
			   disclosures on this dashboard, so an opened ROAS card and an
			   opened Expenses card separate from their heading identically. */
			.brikpanel-dash-ads-cpo {
				margin-top: 0.75rem; padding-top: 0.75rem;
				border-top: 1px solid var(--bp-border, #e3e3e3);
			}
			/* flex-wrap is the whole fix for the long-locale case. Label and
			   figure sit side by side while both fit, and the moment they do
			   not the label takes a full-width line of its own and the figure
			   drops beneath it. Without it the label was squeezed into roughly
			   85px of a 160px card and Turkish broke one word per line, four
			   lines deep, which set the height of all six cards in the row.
			   Two-value gap so the wrapped form gets 0.25rem between its lines
			   rather than the 0.75rem meant for the horizontal split. */
			.brikpanel-dash-ads-cpo-row {
				display: flex; flex-wrap: wrap; align-items: baseline;
				justify-content: space-between; gap: 0.25rem 0.75rem;
			}
			/* Only ever carries a caveat (mixed currencies), so it collapses
			   away on the ordinary day rather than padding the card out. */
			.brikpanel-dash-ads-cpo-note {
				display: block; margin-top: 0.25rem;
				color: var(--bp-text-secondary, #616161);
				font-size: 0.6875rem; line-height: 1.3;
			}
			.brikpanel-dash-ads-cpo-note:empty { display: none; }
			.brikpanel-dash-ads-cpo-label {
				display: flex; align-items: center; gap: 0.25rem;
				min-width: 0;
				color: var(--bp-text-secondary, #616161);
				font-size: 0.75rem; font-weight: 550; line-height: 1.3;
			}
			.brikpanel-dash-ads-cpo-label-text {
				min-width: 0;
				overflow-wrap: anywhere;
			}
			/* 16px square. Without this it is an ordinary shrinkable flex item
			   and the browser squashed it to a sliver to buy the label room. */
			.brikpanel-dash-ads-cpo-label .brikpanel-dash-hint { flex-shrink: 0; }
			/* Keeps the shared .brikpanel-dash-card-value class so the loading
			   shimmer and the wc_price() markup styling still reach it, and
			   only steps the size down. */
			.brikpanel-dash-card .brikpanel-dash-ads-cpo-value {
				font-size: 0.9375rem; font-weight: 650; line-height: 1.2;
				white-space: nowrap;
			}

			/* Manual refresh, in the head row of the ROAS card. Same look as
			   the .brikpanel-dash-bd-toggle of the dashboard. */
			.brikpanel-dash-card[data-metric="roas"] { position: relative; }
			.brikpanel-dash-ads-refresh {
				appearance: none; -webkit-appearance: none;
				background: none; border: 0; padding: 0.1875rem;
				display: inline-flex; align-items: center; justify-content: center;
				cursor: pointer; line-height: 0; border-radius: 0.375rem;
				color: var(--bp-text-secondary, #616161);
				transition: color 0.15s ease, background-color 0.15s ease;
			}
			.brikpanel-dash-ads-refresh:hover {
				color: var(--bp-text, #303030);
				background: var(--bp-bg, #f1f1f1);
			}
			.brikpanel-dash-ads-refresh:focus-visible {
				outline: none;
				box-shadow: 0 0 0 2px var(--bp-text, #303030);
			}
			.brikpanel-dash-ads-refresh[disabled] { cursor: default; opacity: 0.65; }
			.brikpanel-dash-ads-refresh.is-loading svg { animation: bp-ads-refresh-spin 0.8s linear infinite; }
			@keyframes bp-ads-refresh-spin { to { transform: rotate(360deg); } }

			/* The "Connect ad accounts" link beside Export Excel is styled in
			   brikpanel-dashboard.css with the header it sits in. */
		';
		wp_register_style( 'brikpanel-ads-inline', false, [], BRIKPANEL_VERSION );
		wp_enqueue_style( 'brikpanel-ads-inline' );
		wp_add_inline_style( 'brikpanel-ads-inline', $css );

		// The formatter lays numbers and money out like the rest of the dashboard.
		wp_register_script( 'brikpanel-ads-inline', '', function_exists( 'brikpanel_narrow_dep' ) ? brikpanel_narrow_dep( 'format' ) : [], BRIKPANEL_VERSION, true );
		wp_enqueue_script( 'brikpanel-ads-inline' );
		// One bag with an i18n sub-array, rather than the flat string map this
		// used to be: the refresh button needs an endpoint and a nonce beside
		// the copy, and mixing transport config into a list of translated
		// sentences is how a .js file ends up with a hardcoded string in it.
		// Both existing msgids are unchanged, so their nine translations carry
		// over untouched.
		wp_localize_script(
			'brikpanel-ads-inline',
			'BrikpanelAdsDash',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'action'   => 'brikpanel_ads_refresh_spend',
				'nonce'    => wp_create_nonce( Brikpanel_Ads_Settings::NONCE_ACTION ),
				'i18n'     => [
					'roas_label'          => __( 'Revenue / Ad spend', 'brikpanel' ),
					'roas_cross_currency' => __( 'Ad currency differs from store', 'brikpanel' ),
					'refresh_working'     => __( 'Updating…', 'brikpanel' ),
					'refresh_error'       => __( 'Could not update ad spend. Please try again.', 'brikpanel' ),
				],
			]
		);

		$js = <<<JS
		(function () {
			if (typeof window === 'undefined') return;
			var CFG  = (typeof BrikpanelAdsDash === 'object' && BrikpanelAdsDash) ? BrikpanelAdsDash : {};
			var i18n = CFG.i18n || {};

			// Move the ad cards out of their standalone wrapper and into the
			// main KPI grid so they sit inline instead of wrapping onto a row
			// of their own underneath the other five.
			function relocateAdCards() {
				var adsWrap = document.getElementById('brikpanel-ads-kpis');
				if (!adsWrap) return;
				// querySelectorAll, not querySelector: this function REMOVES the
				// wrapper on its last line, so any card still inside it is
				// destroyed. The single-card version of this line would delete
				// every ads card except ROAS, with nothing in the console.
				var cards = adsWrap.querySelectorAll('.brikpanel-dash-card');
				if (!cards.length) return;
				// The store cards row has its own id since October 2026. The
				// sibling walk stays for a dashboard printed by an older copy:
				// the ads wrapper follows the headline KPI grid, so that grid is
				// its nearest preceding .brikpanel-dash-cards sibling.
				var mainGrid = document.getElementById('brikpanel-kpi-cards');
				if (!mainGrid) {
					mainGrid = adsWrap.previousElementSibling;
					while ( mainGrid && ! ( mainGrid.classList && mainGrid.classList.contains('brikpanel-dash-cards') ) ) {
						mainGrid = mainGrid.previousElementSibling;
					}
				}
				if (!mainGrid) return;
				Array.prototype.forEach.call(cards, function (card) {
					card.classList.add('bp-dv-kpi');
					mainGrid.appendChild(card);
				});
				applyKpiColumns(mainGrid);
				if (adsWrap.parentNode) { adsWrap.parentNode.removeChild(adsWrap); }
			}

			// Column count follows the real card count, the way the Profit rows
			// bp-profit-cols-N classes do. Read off the DOM rather than
			// hardcoded, so a future third ads card cannot silently leave the
			// grid one track short.
			function applyKpiColumns(grid) {
				var n = grid.querySelectorAll('.brikpanel-dash-card').length;
				grid.classList.remove('bp-kpi-cols-6', 'bp-kpi-cols-7');
				if (n === 6 || n === 7) { grid.classList.add('bp-kpi-cols-' + n); }
			}

			// The dashboard script is a closed IIFE that exports nothing, but it
			// already broadcasts on document, so these events are the return leg
			// of a channel that exists. Dispatching to a listener that is not
			// there yet is a silent no-op, where calling a method on an
			// undefined global would be a TypeError that kills the rest of the
			// click handler.
			function emit(name, detail) {
				try {
					document.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
				} catch (err) { /* very old WebView: nothing worth doing */ }
			}

			// Manual "update ad spend". Every sentence the merchant reads here
			// was translated server-side; this only places it.
			function initRefresh() {
				var btn = document.getElementById('brikpanel-ads-refresh');
				if (!btn || !CFG.ajax_url || !CFG.action || !CFG.nonce) return;
				var inFlight = false;
				btn.addEventListener('click', function () {
					if (inFlight) return;
					inFlight = true;
					btn.disabled = true;
					btn.classList.add('is-loading');
					var restoreLabel = btn.getAttribute('aria-label');
					if (i18n.refresh_working) { btn.setAttribute('aria-label', i18n.refresh_working); }

					var fd = new FormData();
					fd.append('action', CFG.action);
					fd.append('_ajax_nonce', CFG.nonce);

					fetch(CFG.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							var data = (res && res.data) ? res.data : {};
							// A partial success answers 200 with toast:'error':
							// rows really landed, so the refetch must still run,
							// but the merchant needs to know one platform is
							// missing from the figure.
							var tone = (res && res.success && data.toast !== 'error') ? 'success' : 'error';
							emit('brikpanel:toast', { message: data.message || i18n.refresh_error, type: tone });
							if (data.refetch) { emit('brikpanel:refresh', {}); }
						})
						.catch(function () {
							emit('brikpanel:toast', { message: i18n.refresh_error, type: 'error' });
						})
						.then(function () {
							inFlight = false;
							btn.disabled = false;
							btn.classList.remove('is-loading');
							if (restoreLabel) { btn.setAttribute('aria-label', restoreLabel); }
						});
				});
			}

			// Opens the cost-per-order panel. Deliberately a copy of the
			// dashboards own wireBreakdownToggle() rather than a call to it:
			// that function lives inside a closed IIFE in brikpanel-dashboard.js
			// and exports nothing, and the class names it drives are the shared
			// ones, so the two stay in step through the stylesheet.
			function initCpoToggle() {
				var toggle = document.getElementById('brikpanel-ads-cpo-toggle');
				var card   = document.getElementById('brikpanel-roas-card');
				var panel  = document.getElementById('brikpanel-ads-cpo-collapse');
				if (!toggle || !card) return;
				toggle.addEventListener('click', function () {
					var open = !card.classList.contains('is-bd-open');
					card.classList.toggle('is-bd-open', open);
					toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
					// Assigning the property rather than the attribute so a
					// browser without inert support simply no-ops here instead
					// of leaving a stale attribute behind for a polyfill to
					// misread later.
					if (panel) { panel.inert = !open; }
				});
			}

			function start() { relocateAdCards(); initRefresh(); initCpoToggle(); }
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', start);
			} else {
				start();
			}

			document.addEventListener('brikpanel:dashboardData', function (e) {
				var data = e && e.detail ? e.detail : null;
				if (!data || !data.ad_spend) return;
				var ad = data.ad_spend;

				// Ad Spend and Net Profit live in the unified Profit section at
				// the top of the dashboard (single source of truth). This row
				// keeps the two ad-specific efficiency metrics, which do not
				// belong among the P&L cards.
				//
				// There is deliberately no early return on !ad.has_data. There
				// used to be, and it meant switching from a period WITH spend to
				// one without left the previous period's ROAS sitting on the
				// card as though it belonged to the new dates. A period with no
				// spend has a correct answer, and it is an em dash.
				var codes = (ad.currencies && typeof ad.currencies === 'object') ? Object.keys(ad.currencies) : [];
				// Mirrors the server's gate exactly. Computing it here rather
				// than inferring it from "the number came back null" is what
				// keeps the sub-label honest: a null can equally mean zero spend
				// or zero orders, and blaming the currency for either of those
				// sends the merchant to the wrong settings page.
				var crossCurrency = !!ad.has_data && !!ad.store_currency
					&& (codes.length !== 1 || codes[0] !== ad.store_currency);

				var roasEl  = document.getElementById('card-roas');
				var roasSub = document.getElementById('delta-roas');
				if (roasEl) {
					if (typeof ad.roas === 'number' && isFinite(ad.roas)) {
						roasEl.textContent = (window.brikpanelFormat ? window.brikpanelFormat.number(ad.roas, 2) : ad.roas.toFixed(2)) + 'x';
						if (roasSub) { roasSub.textContent = i18n.roas_label || ''; }
					} else {
						roasEl.textContent = '\u2014'; // Empty-value marker (em dash), the one dash the UI keeps.
						if (roasSub) { roasSub.textContent = crossCurrency ? (i18n.roas_cross_currency || '') : ''; }
					}
				}

				var cpoEl  = document.getElementById('card-ad-cpo');
				var cpoSub = document.getElementById('delta-ad-cpo');
				if (cpoEl) {
					var haveCpo = false;
					if (ad.cost_per_order_display) {
						// Server-formatted with wc_price(), exactly like the
						// other money cards. That is also what makes the shared
						// .woocommerce-Price-amount styling apply, and what
						// keeps the separators consistent with the card next to
						// it on a store whose WooCommerce format differs from
						// the viewer's browser locale.
						cpoEl.innerHTML = ad.cost_per_order_display;
						haveCpo = true;
					} else if (typeof ad.cost_per_order === 'number' && isFinite(ad.cost_per_order)) {
						// Fallback for a payload served out of a transient
						// written by an older build: it carries the number but
						// not the formatted string.
						cpoEl.textContent = formatMoney(ad.cost_per_order, ad.cost_per_order_currency, ad);
						haveCpo = true;
					} else {
						cpoEl.textContent = '\u2014';
					}
					if (cpoSub) {
						// Only a caveat goes here now that the figure sits under
						// the ROAS value with a label of its own: the formula
						// that used to fill this line is in the "?" beside that
						// label, and repeating it under every card would be
						// noise. Empty collapses the line away.
						cpoSub.textContent = ( ! haveCpo && crossCurrency ) ? ( i18n.roas_cross_currency || '' ) : '';
					}
					// No figure and no caveat means the panel would open onto an
					// em dash, so the chevron is not offered at all: the same
					// call renderExpenseBreakdown() makes for an empty
					// breakdown. The class is what moves the refresh button
					// aside, so both corners cannot end up on top of each other.
					var cpoToggle = document.getElementById('brikpanel-ads-cpo-toggle');
					var roasCard  = document.getElementById('brikpanel-roas-card')
						|| document.querySelector('.brikpanel-dash-card[data-metric="roas"]');
					var offerCpo  = haveCpo || ( cpoSub && cpoSub.textContent !== '' );
					if (cpoToggle) {
						cpoToggle.hidden = ! offerCpo;
						if (! offerCpo) { cpoToggle.setAttribute('aria-expanded', 'false'); }
					}
					if (roasCard) {
						roasCard.classList.toggle('has-cpo', !! offerCpo);
						// A period change can pull the figure out from under an
						// open panel, so close it and seal it again rather than
						// leaving an empty panel hanging open.
						if (! offerCpo) {
							roasCard.classList.remove('is-bd-open');
							var shutPanel = document.getElementById('brikpanel-ads-cpo-collapse');
							if (shutPanel) { shutPanel.inert = true; }
						}
					}
				}
			});

			// Only when the server sent no ready-made figure: the store's price
			// layout (front-end/shared/brikpanel-format.js), not the browser's.
			function formatMoney(amount, currency, ad) {
				var sym = (ad && ad.store_symbol) || currency || '';
				if (window.brikpanelFormat) {
					return window.brikpanelFormat.money(Number(amount) || 0, { symbol: sym });
				}
				return sym + ' ' + Number(amount).toFixed(2);
			}
		})();
JS;
		wp_add_inline_script( 'brikpanel-ads-inline', $js );
	}
}
