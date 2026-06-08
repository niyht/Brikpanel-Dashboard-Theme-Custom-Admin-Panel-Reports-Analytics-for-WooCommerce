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

	public function __construct() {
		add_action( 'brikpanel_dashboard_after_kpis', [ $this, 'render_kpi_cards' ] );
		add_filter( 'brikpanel_dashboard_data',       [ $this, 'inject_ad_payload' ], 10, 4 );
		add_action( 'admin_enqueue_scripts',          [ $this, 'enqueue_inline_assets' ] );
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
		?>
		<div class="brikpanel-dash-cards brikpanel-dash-cards-ads" id="brikpanel-ads-kpis">
			<div class="brikpanel-dash-card" data-metric="roas">
				<span class="brikpanel-dash-card-label"><?php esc_html_e( 'ROAS', 'brikpanel' ); ?></span>
				<span class="brikpanel-dash-card-value" id="card-roas">--</span>
				<span class="brikpanel-dash-card-delta brikpanel-dash-card-delta-static" id="delta-roas"></span>
			</div>
		</div>
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
		if ( $store_currency !== '' && isset( $by_currency[ $store_currency ] ) && count( $by_currency ) === 1 ) {
			$same_currency_spend = (float) $by_currency[ $store_currency ];
			if ( $same_currency_spend > 0 ) {
				$roas_value = (float) $total_sales / $same_currency_spend;
			}
			$cogs        = self::compute_cogs( $window );
			$expenses    = self::compute_manual_expenses( $start_date, $end_date );
			$net_profit  = (float) $total_sales - $cogs - $same_currency_spend - $expenses;
		}

		$payload['ad_spend'] = [
			'currencies'         => $by_currency,                          // e.g. { TRY: 5000, USD: 200 }
			'by_platform'        => $by_platform,                          // e.g. { google_ads: { spend, currency } }
			'store_currency'     => $store_currency,
			'store_symbol'       => $currency_symbol,
			'roas'               => $roas_value,                           // null if cross-currency or zero spend
			'net_profit'         => $net_profit,                           // null when cross-currency
			'net_profit_currency'=> $net_profit_curr,
			'has_data'           => ! empty( $totals ),
		];
		return $payload;
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
		$is_hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		$paid    = [ 'wc-processing', 'wc-completed' ];
		$placeholders = implode( ',', array_fill( 0, count( $paid ), '%s' ) );

		if ( $is_hpos ) {
			$sql = "SELECT COALESCE(SUM(CAST(om.meta_value AS DECIMAL(20,4))), 0)
				FROM {$wpdb->prefix}wc_orders o
				INNER JOIN {$wpdb->prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = '_wc_cog_order_total_value'
				WHERE o.type = 'shop_order'
				AND o.status IN ($placeholders)
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s";
		} else {
			$sql = "SELECT COALESCE(SUM(CAST(pm.meta_value AS DECIMAL(20,4))), 0)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wc_cog_order_total_value'
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
	 */
	private static function compute_manual_expenses( $start_date, $end_date ) {
		global $wpdb;
		$table = $wpdb->prefix . 'brikpanel_expenses';
		$val = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE expense_date BETWEEN %s AND %s",
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
			/* ROAS is relocated by JS into the main KPI grid so it sits inline
			   as the 6th card instead of dropping to its own row below. */
			.brikpanel-dash-cards.brikpanel-dash-cards--has-roas {
				grid-template-columns: repeat(6, 1fr);
			}
			@media (max-width: 1200px) {
				.brikpanel-dash-cards.brikpanel-dash-cards--has-roas { grid-template-columns: repeat(3, 1fr); }
			}
			@media (max-width: 960px) {
				.brikpanel-dash-cards.brikpanel-dash-cards--has-roas { grid-template-columns: repeat(2, 1fr); }
			}
			@media (max-width: 600px) {
				.brikpanel-dash-cards.brikpanel-dash-cards--has-roas { grid-template-columns: repeat(2, 1fr); }
			}
			.brikpanel-dash-card[data-metric="roas"] .brikpanel-dash-card-delta,
			.brikpanel-dash-card[data-metric="net_profit"] .brikpanel-dash-card-delta {
				color: #616161; font-size: 0.75rem;
			}
			.brikpanel-dash-card[data-metric="net_profit"].is-loss .brikpanel-dash-card-value { color: #d72c0d; }
			.brikpanel-dash-card[data-metric="net_profit"].is-profit .brikpanel-dash-card-value { color: #1a8917; }

			/* "Connect ad accounts" CTA next to "Copy everything" */
			.brikpanel-dash-ads-cta {
				display: inline-flex; align-items: center; gap: 0.4rem;
				padding: 0.45rem 0.8rem; margin-left: 0.5rem;
				background: #fff; color: #303030; text-decoration: none;
				border-radius: 0.5rem; font-size: 0.8125rem; font-weight: 550;
				box-shadow: inset 0 0 0 1px #e3e3e3, 0 1px 0 rgba(0,0,0,0.05);
				transition: background-color 0.15s ease;
				vertical-align: middle;
			}
			.brikpanel-dash-ads-cta:hover { background: #f7f7f7; color: #303030; }
			.brikpanel-dash-ads-cta-icon { display: inline-flex; }
			.brikpanel-dash-ads-cta .brikpanel-beta-badge {
				margin-left: 0.1rem;
			}
		';
		wp_register_style( 'brikpanel-ads-inline', false, [], BRIKPANEL_VERSION );
		wp_enqueue_style( 'brikpanel-ads-inline' );
		wp_add_inline_style( 'brikpanel-ads-inline', $css );

		wp_register_script( 'brikpanel-ads-inline', '', [], BRIKPANEL_VERSION, true );
		wp_enqueue_script( 'brikpanel-ads-inline' );
		wp_localize_script(
			'brikpanel-ads-inline',
			'BrikpanelAdsDashI18n',
			[
				'roas_label'           => __( 'Revenue / Ad spend', 'brikpanel' ),
				'roas_cross_currency'  => __( 'Ad currency differs from store', 'brikpanel' ),
			]
		);

		$js = <<<JS
		(function () {
			if (typeof window === 'undefined') return;
			var i18n = (typeof BrikpanelAdsDashI18n === 'object' && BrikpanelAdsDashI18n) ? BrikpanelAdsDashI18n : {};

			// Move the ROAS card out of its standalone wrapper and into the
			// main KPI grid so it sits inline as the 6th card instead of
			// wrapping onto its own row underneath the other five.
			function relocateRoas() {
				var adsWrap = document.getElementById('brikpanel-ads-kpis');
				if (!adsWrap) return;
				var roasCard = adsWrap.querySelector('.brikpanel-dash-card[data-metric="roas"]');
				if (!roasCard) return;
				// The ROAS wrapper is rendered by the after-KPIs hook immediately
				// following the headline KPI grid, so the KPI grid is its nearest
				// preceding .brikpanel-dash-cards sibling. Anchoring this way keeps
				// ROAS on the KPI row even though other grids (Profit) also use
				// the .brikpanel-dash-cards class.
				var mainGrid = adsWrap.previousElementSibling;
				while ( mainGrid && ! ( mainGrid.classList && mainGrid.classList.contains('brikpanel-dash-cards') ) ) {
					mainGrid = mainGrid.previousElementSibling;
				}
				if (!mainGrid) return;
				mainGrid.appendChild(roasCard);
				mainGrid.classList.add('brikpanel-dash-cards--has-roas');
				if (adsWrap.parentNode) { adsWrap.parentNode.removeChild(adsWrap); }
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', relocateRoas);
			} else {
				relocateRoas();
			}

			document.addEventListener('brikpanel:dashboardData', function (e) {
				var data = e && e.detail ? e.detail : null;
				if (!data || !data.ad_spend) return;
				var ad = data.ad_spend;
				if (!ad.has_data) return;

				// Ad Spend + Net Profit now live in the unified Profit section
				// at the top of the dashboard (single source of truth). This
				// row only keeps ROAS, which is an ad-specific efficiency
				// metric that doesn't belong in the P&L cards.

				// --- ROAS (only when single currency matches store) ---
				var \$roas = document.getElementById('card-roas');
				var \$roasDelta = document.getElementById('delta-roas');
				if (\$roas) {
					if (typeof ad.roas === 'number' && isFinite(ad.roas)) {
						\$roas.textContent = ad.roas.toFixed(2) + 'x';
						if (\$roasDelta) { \$roasDelta.textContent = i18n.roas_label || ''; }
					} else {
						\$roas.textContent = '—';
						if (\$roasDelta) {
							\$roasDelta.textContent = ad.has_data
								? (i18n.roas_cross_currency || '')
								: '';
						}
					}
				}
			});

			function formatMoney(amount, currency, ad) {
				try {
					return new Intl.NumberFormat(undefined, {
						style: 'currency',
						currency: currency || (ad && ad.store_currency) || 'USD',
						maximumFractionDigits: 2
					}).format(amount);
				} catch (e) {
					return ((ad && ad.store_symbol) || currency || '') + ' ' + Number(amount).toFixed(2);
				}
			}
		})();
JS;
		wp_add_inline_script( 'brikpanel-ads-inline', $js );
	}
}
