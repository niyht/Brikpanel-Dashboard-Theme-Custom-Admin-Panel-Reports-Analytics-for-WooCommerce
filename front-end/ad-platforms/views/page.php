<?php
/**
 * BrikPanel Ad Platforms — page template.
 *
 * Rendered by Brikpanel_Ads_Settings::render_page().
 * Receives:
 *   $google_desc, $meta_desc       — connection metadata for each platform
 *   $google_last_sync, $meta_last_sync — last sync state per platform
 *   $google_backfill, $meta_backfill   — backfill progress per platform
 *   $flash                          — { tone, message } from OAuth return
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a single platform card. Used twice — for Google and Meta — to avoid
 * 200 lines of nearly identical markup.
 */
$render_platform_card = function ( $platform, $title, $tagline, $desc, $last_sync, $backfill, $locked = false, $disguise = false ) {
	$is_connected = (bool) $desc['connected'];
	$primary      = (string) $desc['primary_account'];
	$last_ts      = (int) ( $last_sync['ts'] ?? 0 );
	$last_ok      = (bool) ( $last_sync['ok'] ?? false );

	// "Coming soon" skin. Two reasons we render it:
	//   • $locked     — hard gate (Google until its Dev Token is approved).
	//                    Button is disabled; nothing works yet.
	//   • $disguise    — Meta: cosmetic skin only. Button is LIVE and the
	//                    OAuth handshake runs. We only wear the skin while
	//                    not connected; once connected we fall through to the
	//                    normal body so the account picker / Sync / Disconnect
	//                    are available and the integration genuinely works.
	if ( $locked || ( $disguise && ! $is_connected ) ) {
		$is_google   = ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE );
		$button_live = ! $locked; // disguised → clickable; hard-locked → disabled.
		if ( $is_google ) {
			$pending_title = __( 'Pending Google approval', 'brikpanel' );
			$pending_copy  = __( 'Google Ads needs to approve our API access before this connection can go live. It will be enabled automatically once approved. No action is needed from you.', 'brikpanel' );
			$connect_label = __( 'Connect Google Ads', 'brikpanel' );
		} else {
			$pending_title = __( 'Pending Meta approval', 'brikpanel' );
			$pending_copy  = __( 'Meta needs to review our app before this connection can go live. It will be enabled automatically once approved. No action is needed from you.', 'brikpanel' );
			$connect_label = __( 'Connect Meta Ads', 'brikpanel' );
		}
		?>
		<div class="bp-ads-card bp-ads-card-locked" data-platform="<?php echo esc_attr( $platform ); ?>" data-connected="0">
			<div class="bp-ads-card-header">
				<div class="bp-ads-card-header-left">
					<h2><?php echo esc_html( $title ); ?></h2>
					<p class="bp-ads-card-sub"><?php echo esc_html( $tagline ); ?></p>
				</div>
				<span class="bp-ads-pill" data-state="pending">
					<span class="bp-ads-pill-dot"></span>
					<span class="bp-ads-pill-text"><?php esc_html_e( 'Coming soon', 'brikpanel' ); ?></span>
				</span>
			</div>
			<div class="bp-ads-card-body">
				<div class="bp-ads-locked-note">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
					<div>
						<strong><?php echo esc_html( $pending_title ); ?></strong>
						<p class="bp-ads-card-sub">
							<?php echo esc_html( $pending_copy ); ?>
						</p>
					</div>
				</div>
				<div class="bp-ads-actions">
					<?php if ( $button_live ) : ?>
						<button type="button" class="bp-ads-btn bp-ads-btn-primary" data-action="connect">
							<?php echo esc_html( $connect_label ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="bp-ads-btn bp-ads-btn-primary" disabled aria-disabled="true">
							<?php echo esc_html( $connect_label ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return;
	}
	?>
	<div class="bp-ads-card" data-platform="<?php echo esc_attr( $platform ); ?>" data-connected="<?php echo $is_connected ? '1' : '0'; ?>">
		<div class="bp-ads-card-header">
			<div class="bp-ads-card-header-left">
				<h2><?php echo esc_html( $title ); ?></h2>
				<p class="bp-ads-card-sub"><?php echo esc_html( $tagline ); ?></p>
			</div>
			<span class="bp-ads-pill" data-state="<?php echo $is_connected ? 'live' : 'off'; ?>">
				<span class="bp-ads-pill-dot"></span>
				<span class="bp-ads-pill-text">
					<?php echo $is_connected
						? esc_html__( 'Connected', 'brikpanel' )
						: esc_html__( 'Not connected', 'brikpanel' ); ?>
				</span>
			</span>
		</div>

		<div class="bp-ads-card-body">

			<?php if ( ! $is_connected ) : ?>

				<p class="bp-ads-card-sub">
					<?php
					if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
						esc_html_e( 'BrikPanel will request read-only access to your Google Ads accounts. We only pull total daily spend, impressions and clicks. No campaign management.', 'brikpanel' );
					} else {
						esc_html_e( 'BrikPanel will request read-only access (ads_read) to your Meta ad accounts. We only pull total daily spend, impressions and clicks.', 'brikpanel' );
					}
					?>
				</p>
				<div class="bp-ads-actions">
					<button type="button" class="bp-ads-btn bp-ads-btn-primary" data-action="connect">
						<?php
						if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) {
							esc_html_e( 'Connect Google Ads', 'brikpanel' );
						} else {
							esc_html_e( 'Connect Meta Ads', 'brikpanel' );
						}
						?>
					</button>
				</div>

			<?php else : ?>

				<dl class="bp-ads-dl">
					<dt><?php esc_html_e( 'Connected as', 'brikpanel' ); ?></dt>
					<dd><?php echo esc_html( $desc['email'] ?: __( '(email not shared)', 'brikpanel' ) ); ?></dd>
				</dl>

				<div class="bp-ads-field">
					<label class="bp-ads-label" for="bp-ads-primary-<?php echo esc_attr( $platform ); ?>">
						<?php esc_html_e( 'Primary ad account', 'brikpanel' ); ?>
					</label>
					<p class="bp-ads-help">
						<?php esc_html_e( 'BrikPanel pulls spend from this one account. To switch later, choose another from the list and click Save.', 'brikpanel' ); ?>
					</p>
					<div class="bp-ads-input-row">
						<select id="bp-ads-primary-<?php echo esc_attr( $platform ); ?>" class="bp-ads-input" data-role="primary-select">
							<?php if ( $primary !== '' ) : ?>
								<option value="<?php echo esc_attr( $primary ); ?>" selected><?php echo esc_html( $primary ); ?></option>
							<?php else : ?>
								<option value=""><?php esc_html_e( 'Click "Load accounts" to choose…', 'brikpanel' ); ?></option>
							<?php endif; ?>
						</select>
						<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="load-accounts">
							<?php esc_html_e( 'Load accounts', 'brikpanel' ); ?>
						</button>
						<button type="button" class="bp-ads-btn bp-ads-btn-primary" data-action="save-primary" disabled>
							<?php esc_html_e( 'Save', 'brikpanel' ); ?>
						</button>
					</div>
				</div>

				<?php if ( $platform === Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ) : ?>
					<div class="bp-ads-field">
						<label class="bp-ads-label" for="bp-ads-mcc">
							<?php esc_html_e( 'Manager (MCC) account ID (optional)', 'brikpanel' ); ?>
						</label>
						<p class="bp-ads-help">
							<?php esc_html_e( 'Only fill in if you access the chosen account through a Google Ads manager. Digits only, no dashes.', 'brikpanel' ); ?>
						</p>
						<div class="bp-ads-input-row">
							<input type="text"
								id="bp-ads-mcc"
								class="bp-ads-input"
								inputmode="numeric"
								pattern="[0-9]*"
								placeholder="1234567890"
								value="<?php echo esc_attr( $desc['login_customer_id'] ); ?>">
							<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="save-mcc">
								<?php esc_html_e( 'Save', 'brikpanel' ); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>

				<div class="bp-ads-divider"></div>

				<dl class="bp-ads-dl">
					<dt><?php esc_html_e( 'Last successful sync', 'brikpanel' ); ?></dt>
					<dd data-role="last-sync">
						<?php
						if ( $last_ts > 0 ) {
							echo esc_html( human_time_diff( $last_ts, time() ) ) . ' ' . esc_html__( 'ago', 'brikpanel' );
							if ( ! $last_ok ) {
								echo ' <span class="bp-ads-bad">' . esc_html__( '(last attempt failed, check the error log)', 'brikpanel' ) . '</span>';
							}
						} else {
							echo '—';
						}
						?>
					</dd>
				</dl>

				<?php
				$total     = (int) ( $backfill['total_chunks'] ?? 0 );
				$completed = (int) ( $backfill['completed_chunks'] ?? 0 );
				if ( $total > 0 && $completed < $total ) :
					$pct = max( 0, min( 100, (int) round( ( $completed / max( 1, $total ) ) * 100 ) ) );
					?>
					<div class="bp-ads-backfill">
						<div class="bp-ads-backfill-label">
							<?php
							printf(
								/* translators: 1: completed, 2: total chunks (90 days each) */
								esc_html__( 'Loading history… %1$d of %2$d 90-day chunks done', 'brikpanel' ),
								(int) $completed,
								(int) $total
							);
							?>
						</div>
						<div class="bp-ads-backfill-bar">
							<div class="bp-ads-backfill-fill" style="width: <?php echo esc_attr( $pct ); ?>%"></div>
						</div>
					</div>
				<?php endif; ?>

				<div class="bp-ads-actions">
					<button type="button" class="bp-ads-btn bp-ads-btn-primary" data-action="sync-now" <?php disabled( $primary === '' ); ?>>
						<?php esc_html_e( 'Sync now', 'brikpanel' ); ?>
					</button>
					<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="reconnect">
						<?php esc_html_e( 'Re-authorize', 'brikpanel' ); ?>
					</button>
					<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="disconnect">
						<?php esc_html_e( 'Disconnect', 'brikpanel' ); ?>
					</button>
				</div>

			<?php endif; ?>

		</div>
	</div>
	<?php
};
?>
<div class="wrap brikpanel-ads-wrap">
	<div class="bp-ads"
		id="bp-ads"
		data-flash-tone="<?php echo esc_attr( $flash['tone'] ); ?>"
		data-flash-message="<?php echo esc_attr( $flash['message'] ); ?>">

		<div class="bp-ads-header">
			<div class="bp-ads-header-left">
				<h1>
					<?php esc_html_e( 'Ad Platforms', 'brikpanel' ); ?>
					<span class="brikpanel-beta-badge" aria-label="<?php esc_attr_e( 'Beta feature', 'brikpanel' ); ?>"><?php esc_html_e( 'Beta', 'brikpanel' ); ?></span>
				</h1>
				<p class="bp-ads-subtitle">
					<?php esc_html_e( 'Connect Google Ads and Meta Ads so BrikPanel can show real ROAS and Net Profit on the dashboard.', 'brikpanel' ); ?>
				</p>
			</div>
		</div>

		<div class="bp-ads-toast" id="bp-ads-toast" hidden></div>

		<div class="bp-ads-grid">
			<?php
			$render_platform_card(
				Brikpanel_Ads_Tokens::PLATFORM_GOOGLE,
				__( 'Google Ads', 'brikpanel' ),
				__( 'Pulls daily total spend, impressions and clicks from one Google Ads account.', 'brikpanel' ),
				$google_desc,
				$google_last_sync,
				$google_backfill,
				! empty( $google_locked ),
				! empty( $google_disguised )
			);
			$render_platform_card(
				Brikpanel_Ads_Tokens::PLATFORM_META,
				__( 'Meta Ads', 'brikpanel' ),
				__( 'Pulls daily total spend, impressions and clicks from one Meta ad account (Facebook + Instagram).', 'brikpanel' ),
				$meta_desc,
				$meta_last_sync,
				$meta_backfill,
				! empty( $meta_locked ),
				! empty( $meta_disguised )
			);
			?>
		</div>

		<div class="bp-ads-card bp-ads-card-wide" id="bp-ads-insights" hidden>
			<div class="bp-ads-card-header">
				<div class="bp-ads-card-header-left">
					<h2><?php esc_html_e( 'Imported spend data', 'brikpanel' ); ?></h2>
					<p class="bp-ads-card-sub">
						<?php esc_html_e( 'Everything BrikPanel has pulled so far, broken down by month. This is the raw data behind the dashboard ROAS and Net Profit figures.', 'brikpanel' ); ?>
					</p>
				</div>
				<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="refresh-insights">
					<?php esc_html_e( 'Refresh', 'brikpanel' ); ?>
				</button>
			</div>
			<div class="bp-ads-card-body">
				<div id="bp-ads-insights-body"></div>
			</div>
		</div>

		<div class="bp-ads-card bp-ads-card-wide">
			<div class="bp-ads-card-header">
				<div class="bp-ads-card-header-left">
					<h2><?php esc_html_e( 'How it works', 'brikpanel' ); ?></h2>
				</div>
			</div>
			<div class="bp-ads-card-body">
				<ul class="bp-ads-list">
					<li><?php esc_html_e( 'Connect each platform once. Tokens are encrypted on this server and never leave it.', 'brikpanel' ); ?></li>
					<li><?php esc_html_e( 'On first connect, the last 3 years of daily spend are loaded in the background (90-day chunks every 30 seconds).', 'brikpanel' ); ?></li>
					<li><?php esc_html_e( 'After that, the last 7 days are refreshed once per day to capture late corrections.', 'brikpanel' ); ?></li>
					<li><?php esc_html_e( 'Spend is stored in the ad account currency. The dashboard shows ad-currency totals next to your store revenue so you can compare them directly.', 'brikpanel' ); ?></li>
				</ul>
			</div>
		</div>

		<div class="bp-ads-card bp-ads-card-wide">
			<div class="bp-ads-card-header">
				<div class="bp-ads-card-header-left">
					<h2><?php esc_html_e( 'Recent errors', 'brikpanel' ); ?></h2>
					<p class="bp-ads-card-sub">
						<?php esc_html_e( 'Last 50 entries from the sync log. Token values are redacted before storage.', 'brikpanel' ); ?>
					</p>
				</div>
			</div>
			<div class="bp-ads-card-body">
				<div class="bp-ads-actions bp-ads-actions-tight">
					<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="view-log">
						<?php esc_html_e( 'View log', 'brikpanel' ); ?>
					</button>
					<button type="button" class="bp-ads-btn bp-ads-btn-secondary" data-action="clear-log">
						<?php esc_html_e( 'Clear log', 'brikpanel' ); ?>
					</button>
				</div>
				<div class="bp-ads-log" id="bp-ads-log" hidden></div>
			</div>
		</div>

	</div>
</div>
