<?php
/**
 * BrikPanel Google Sheets Integration — page template.
 *
 * Rendered by Brikpanel_Sheets_Settings::render_page().
 * Receives:
 *   $conn   — Brikpanel_Sheets_Tokens::describe()
 *   $config — bag of options + column mappings
 *   $flash  — { tone, message } from the OAuth return redirect
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
?>
<div class="wrap brikpanel-gs-wrap">
	<div class="bp-gs"
		id="bp-gs"
		data-connected="<?php echo $conn['connected'] ? '1' : '0'; ?>"
		data-flash-tone="<?php echo esc_attr( $flash['tone'] ); ?>"
		data-flash-message="<?php echo esc_attr( $flash['message'] ); ?>">

		<div class="bp-gs-header">
			<div class="bp-gs-header-left">
				<h1>
					<?php esc_html_e( 'Google Sheets', 'brikpanel' ); ?>
					<span class="brikpanel-beta-badge" aria-label="<?php esc_attr_e( 'Beta feature', 'brikpanel' ); ?>"><?php esc_html_e( 'Beta', 'brikpanel' ); ?></span>
				</h1>
				<p class="bp-gs-subtitle">
					<?php esc_html_e( 'Send your orders, customers, and analytics straight to a Google Sheet.', 'brikpanel' ); ?>
				</p>
			</div>
			<div class="bp-gs-header-right">
				<span class="bp-gs-pill" id="bp-gs-pill" data-state="<?php echo $conn['connected'] ? 'live' : 'off'; ?>">
					<span class="bp-gs-pill-dot"></span>
					<span class="bp-gs-pill-text">
						<?php echo $conn['connected']
							? esc_html__( 'Connected', 'brikpanel' )
							: esc_html__( 'Not connected', 'brikpanel' ); ?>
					</span>
				</span>
			</div>
		</div>

		<div class="bp-gs-toast" id="bp-gs-toast" hidden></div>

		<div class="bp-gs-tabs" role="tablist">
			<button type="button" class="bp-gs-tab is-active" data-tab="connection" role="tab"><?php esc_html_e( 'Connection', 'brikpanel' ); ?></button>
			<button type="button" class="bp-gs-tab"           data-tab="orders"     role="tab"><?php esc_html_e( 'Orders',     'brikpanel' ); ?></button>
			<button type="button" class="bp-gs-tab"           data-tab="products"   role="tab"><?php esc_html_e( 'Products',   'brikpanel' ); ?></button>
			<button type="button" class="bp-gs-tab"           data-tab="reports"    role="tab"><?php esc_html_e( 'Reports',    'brikpanel' ); ?></button>
			<button type="button" class="bp-gs-tab"           data-tab="customers"  role="tab"><?php esc_html_e( 'Customers',  'brikpanel' ); ?></button>
		</div>

		<!-- ============================================================== -->
		<!-- CONNECTION TAB                                                  -->
		<!-- ============================================================== -->
		<div class="bp-gs-tabpanel is-active" data-panel="connection">

			<?php if ( ! $conn['connected'] ) : ?>
				<div class="bp-gs-card">
					<div class="bp-gs-card-body">
						<h2><?php esc_html_e( 'Connect your Google account', 'brikpanel' ); ?></h2>
						<p class="bp-gs-card-sub">
							<?php esc_html_e( 'BrikPanel uses a single, narrow Google permission — no app verification screen, no scary “unverified app” warning. It can only touch a spreadsheet it creates for you or one you explicitly hand over. The rest of your Google Drive stays invisible to BrikPanel.', 'brikpanel' ); ?>
						</p>
						<ul class="bp-gs-scope-list">
							<li><?php esc_html_e( 'Drive (per-file) — create a new spreadsheet, or open only the one you pick', 'brikpanel' ); ?></li>
							<li><?php esc_html_e( 'Email — to display which Google account is connected', 'brikpanel' ); ?></li>
						</ul>
						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-primary" id="bp-gs-connect">
								<?php esc_html_e( 'Connect Google Sheets', 'brikpanel' ); ?>
							</button>
						</div>
					</div>
				</div>
			<?php else : ?>
				<div class="bp-gs-card">
					<div class="bp-gs-card-body">
						<h2><?php esc_html_e( 'Connection', 'brikpanel' ); ?></h2>
						<?php if ( ! Brikpanel_Sheets_Tokens::has_drive_scope() ) : ?>
							<div class="bp-gs-callout" role="alert">
								<strong><?php esc_html_e( 'Google Drive access is missing.', 'brikpanel' ); ?></strong>
								<?php esc_html_e( 'Your account is connected, but the Google Drive permission was not granted, so creating or choosing a spreadsheet will fail. Click Disconnect, then connect again and keep the Google Drive checkbox ticked on the permission screen.', 'brikpanel' ); ?>
							</div>
						<?php endif; ?>
						<dl class="bp-gs-dl">
							<dt><?php esc_html_e( 'Connected as', 'brikpanel' ); ?></dt>
							<dd><?php echo esc_html( $conn['email'] ?: __( '(email not shared)', 'brikpanel' ) ); ?></dd>
							<dt><?php esc_html_e( 'Token expires', 'brikpanel' ); ?></dt>
							<dd id="bp-gs-expires">
								<?php
								if ( $conn['expires_at'] > 0 ) {
									$mins = max( 0, (int) ceil( ( $conn['expires_at'] - time() ) / 60 ) );
									/* translators: %d minutes */
									echo esc_html( sprintf( _n( 'in %d minute (auto-refreshed)', 'in %d minutes (auto-refreshed)', $mins, 'brikpanel' ), $mins ) );
								} else {
									esc_html_e( 'unknown', 'brikpanel' );
								}
								?>
							</dd>
						</dl>
						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" id="bp-gs-disconnect"><?php esc_html_e( 'Disconnect', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" id="bp-gs-reauth"><?php esc_html_e( 'Re-authorize', 'brikpanel' ); ?></button>
						</div>
					</div>
				</div>

				<div class="bp-gs-card">
					<div class="bp-gs-card-body">
						<h2><?php esc_html_e( 'Target spreadsheet', 'brikpanel' ); ?></h2>
						<p class="bp-gs-card-sub">
							<?php esc_html_e( 'One spreadsheet is used for every flow. Each flow writes to its own tab inside it.', 'brikpanel' ); ?>
						</p>

						<?php if ( $config['spreadsheet_id'] !== '' ) : ?>
							<div class="bp-gs-current-sheet">
								<div class="bp-gs-current-sheet-title">
									<?php echo esc_html( $config['spreadsheet_title'] ?: $config['spreadsheet_id'] ); ?>
								</div>
								<?php if ( $config['spreadsheet_url'] !== '' || $config['spreadsheet_id'] !== '' ) :
									$url = $config['spreadsheet_url'] !== ''
										? $config['spreadsheet_url']
										: 'https://docs.google.com/spreadsheets/d/' . $config['spreadsheet_id'];
								?>
									<a class="bp-gs-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'Open in Google Sheets ↗', 'brikpanel' ); ?>
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php $bp_gs_picker_cfg = Brikpanel_Sheets_Settings::picker_config(); ?>
						<?php if ( ! empty( $bp_gs_picker_cfg['app_id'] ) && ! empty( $bp_gs_picker_cfg['api_key'] ) ) : ?>
							<div class="bp-gs-field-group">
								<label class="bp-gs-label">
									<?php esc_html_e( 'Use an existing spreadsheet', 'brikpanel' ); ?>
								</label>
								<p class="bp-gs-help">
									<?php esc_html_e( 'Pick one of your Google spreadsheets. You only ever grant BrikPanel access to the single file you choose.', 'brikpanel' ); ?>
								</p>
								<div class="bp-gs-input-row">
									<button type="button" class="bp-gs-btn bp-gs-btn-primary" id="bp-gs-pick">
										<?php esc_html_e( 'Choose existing spreadsheet', 'brikpanel' ); ?>
									</button>
								</div>
							</div>

							<div class="bp-gs-divider"><span><?php esc_html_e( 'or', 'brikpanel' ); ?></span></div>
						<?php endif; ?>

						<div class="bp-gs-field-group">
							<label class="bp-gs-label" for="bp-gs-sheet-create-title">
								<?php esc_html_e( 'Create a new spreadsheet for BrikPanel', 'brikpanel' ); ?>
							</label>
							<div class="bp-gs-input-row">
								<input type="text"
									id="bp-gs-sheet-create-title"
									class="bp-gs-input"
									placeholder="<?php echo esc_attr( sprintf( __( 'BrikPanel — %s', 'brikpanel' ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?>">
								<button type="button" class="bp-gs-btn bp-gs-btn-secondary" id="bp-gs-create">
									<?php esc_html_e( 'Create', 'brikpanel' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

		</div>

		<!-- ============================================================== -->
		<!-- ORDERS TAB                                                       -->
		<!-- ============================================================== -->
		<div class="bp-gs-tabpanel" data-panel="orders">

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Order sync', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub"><?php esc_html_e( 'Push WooCommerce orders to a tab. Each row is one line item — variations get their own row with attribute columns.', 'brikpanel' ); ?></p>

					<form class="bp-gs-form" data-flow="orders">
						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Enable order sync', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="enabled" <?php checked( $config['orders_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>

						<div class="bp-gs-field">
							<label class="bp-gs-label" for="bp-gs-orders-tab"><?php esc_html_e( 'Target tab name', 'brikpanel' ); ?></label>
							<input type="text" id="bp-gs-orders-tab" name="tab" class="bp-gs-input" value="<?php echo esc_attr( $config['orders_tab'] ); ?>">
						</div>

						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Real-time append on new order', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="realtime" <?php checked( $config['orders_realtime'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>

						<div class="bp-gs-field">
							<span class="bp-gs-label"><?php esc_html_e( 'Bulk export schedule', 'brikpanel' ); ?></span>
							<div class="bp-gs-radio-row">
								<?php foreach ( [
									'off'      => __( 'Manual only', 'brikpanel' ),
									'hourly'   => __( 'Hourly', 'brikpanel' ),
									'every_4h' => __( 'Every 4 hours', 'brikpanel' ),
									'daily'    => __( 'Daily', 'brikpanel' ),
								] as $val => $label ) : ?>
									<label class="bp-gs-radio">
										<input type="radio" name="bulk_interval" value="<?php echo esc_attr( $val ); ?>" <?php checked( $config['orders_bulk_interval'], $val ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="bp-gs-field">
							<label class="bp-gs-label" for="bp-gs-bulk-since"><?php esc_html_e( 'Bulk export — include orders from', 'brikpanel' ); ?></label>
							<input type="date" id="bp-gs-bulk-since" name="bulk_since" class="bp-gs-input" value="<?php echo esc_attr( $config['orders_bulk_since'] ); ?>">
						</div>

						<?php if ( ! empty( $order_statuses ) ) : ?>
							<div class="bp-gs-field">
								<span class="bp-gs-label"><?php esc_html_e( 'Statuses to export', 'brikpanel' ); ?></span>
								<div class="bp-gs-checkbox-row">
									<?php foreach ( $order_statuses as $key => $label ) :
										$checked = in_array( $key, $config['orders_bulk_statuses'], true );
									?>
										<label class="bp-gs-checkbox">
											<input type="checkbox" name="bulk_statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
											<span><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<div class="bp-gs-section-divider"></div>

						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Two-way sync: apply status changes from Sheets', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="pull_enabled" <?php checked( $config['orders_pull_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>
						<p class="bp-gs-help"><?php esc_html_e( 'When on, BrikPanel polls the Orders tab and writes any order_status edits back to WooCommerce. Only the Order status column is read back; other edits are display-only and get overwritten on the next push.', 'brikpanel' ); ?></p>

						<div class="bp-gs-field">
							<span class="bp-gs-label"><?php esc_html_e( 'Poll interval', 'brikpanel' ); ?></span>
							<div class="bp-gs-radio-row">
								<?php foreach ( [
									'2'  => __( 'Every 2 minutes', 'brikpanel' ),
									'5'  => __( 'Every 5 minutes', 'brikpanel' ),
									'15' => __( 'Every 15 minutes', 'brikpanel' ),
								] as $val => $label ) : ?>
									<label class="bp-gs-radio">
										<input type="radio" name="pull_interval" value="<?php echo esc_attr( $val ); ?>" <?php checked( $config['orders_pull_interval'], $val ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-primary" data-action="save"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="sync-now"><?php esc_html_e( 'Sync now', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="pull-now" title="<?php esc_attr_e( 'Read the Orders tab right now and apply any status changes back to WooCommerce.', 'brikpanel' ); ?>"><?php esc_html_e( 'Pull now', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-link" data-action="reset-sync" data-reset-flow="orders" title="<?php esc_attr_e( "Wipe the target tab in Google Sheets and re-push the full order history from scratch. Any manual edits in that tab will be lost.", 'brikpanel' ); ?>"><?php esc_html_e( 'Reset & re-push everything', 'brikpanel' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Columns to export', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub"><?php esc_html_e( 'Mandatory columns (Order ID, Line item ID) are always included so we can de-duplicate rows.', 'brikpanel' ); ?></p>
					<?php
					Brikpanel_Sheets_Settings::render_column_mapper( 'orders', $config['columns_orders'], $config['columns_orders_catalogue'] );
					?>
				</div>
			</div>

			<?php
			Brikpanel_Sheets_Settings::render_activity_card( 'orders', $config['orders_last_sync'] );
			?>
		</div>

		<!-- ============================================================== -->
		<!-- PRODUCTS TAB                                                     -->
		<!-- ============================================================== -->
		<div class="bp-gs-tabpanel" data-panel="products">

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Product sync', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub"><?php esc_html_e( 'Push WooCommerce products and variations to a tab so you can review stock at a glance. Variations get their own row. Edits to the Stock column flow back to WooCommerce.', 'brikpanel' ); ?></p>

					<form class="bp-gs-form" data-flow="products">
						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Enable product sync', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="enabled" <?php checked( $config['products_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>

						<div class="bp-gs-field">
							<label class="bp-gs-label" for="bp-gs-products-tab"><?php esc_html_e( 'Target tab name', 'brikpanel' ); ?></label>
							<input type="text" id="bp-gs-products-tab" name="tab" class="bp-gs-input" value="<?php echo esc_attr( $config['products_tab'] ); ?>">
						</div>

						<div class="bp-gs-section-divider"></div>

						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Two-way sync: apply stock edits from Sheets', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="pull_enabled" <?php checked( $config['products_pull_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>
						<p class="bp-gs-help"><?php esc_html_e( 'When on, BrikPanel polls the Products tab and writes any Stock cell edits back to WooCommerce. Only the Stock column is read back; other edits (price, name, etc.) are display-only and get overwritten on the next push.', 'brikpanel' ); ?></p>

						<div class="bp-gs-field">
							<span class="bp-gs-label"><?php esc_html_e( 'Poll interval', 'brikpanel' ); ?></span>
							<div class="bp-gs-radio-row">
								<?php foreach ( [
									'2'  => __( 'Every 2 minutes', 'brikpanel' ),
									'5'  => __( 'Every 5 minutes', 'brikpanel' ),
									'15' => __( 'Every 15 minutes', 'brikpanel' ),
								] as $val => $label ) : ?>
									<label class="bp-gs-radio">
										<input type="radio" name="pull_interval" value="<?php echo esc_attr( $val ); ?>" <?php checked( $config['products_pull_interval'], $val ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-primary" data-action="save"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="sync-now"><?php esc_html_e( 'Sync now', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="pull-now" title="<?php esc_attr_e( 'Read the Products tab right now and apply any Stock changes back to WooCommerce.', 'brikpanel' ); ?>"><?php esc_html_e( 'Pull now', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-link" data-action="reset-sync" data-reset-flow="products" title="<?php esc_attr_e( 'Wipe the Products tab in Google Sheets and re-push every product from scratch. Any manual edits in that tab will be lost.', 'brikpanel' ); ?>"><?php esc_html_e( 'Reset & re-push everything', 'brikpanel' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Columns to export', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub"><?php esc_html_e( 'Product ID is mandatory so rows can be de-duplicated. Stock is the only column that flows back to WooCommerce.', 'brikpanel' ); ?></p>
					<?php
					Brikpanel_Sheets_Settings::render_column_mapper( 'products', $config['columns_products'], $config['columns_products_catalogue'] );
					?>
				</div>
			</div>

			<?php
			Brikpanel_Sheets_Settings::render_activity_card( 'products', $config['products_last_push'] );
			?>
		</div>

		<!-- ============================================================== -->
		<!-- REPORTS TAB                                                      -->
		<!-- ============================================================== -->
		<div class="bp-gs-tabpanel" data-panel="reports">

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Reports sync', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub">
						<?php esc_html_e( "Push BrikPanel's computed analytics into dedicated tabs. Each push overwrites the tab so you always see 'as-of-last-sync' values.", 'brikpanel' ); ?>
					</p>

					<form class="bp-gs-form" data-flow="reports">
						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Enable reports sync', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="enabled" <?php checked( $config['reports_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>

						<div class="bp-gs-field">
							<span class="bp-gs-label"><?php esc_html_e( 'Snapshot interval', 'brikpanel' ); ?></span>
							<div class="bp-gs-radio-row">
								<?php foreach ( [
									'hourly'   => __( 'Hourly', 'brikpanel' ),
									'every_6h' => __( 'Every 6 hours', 'brikpanel' ),
									'daily'    => __( 'Daily', 'brikpanel' ),
								] as $val => $label ) : ?>
									<label class="bp-gs-radio">
										<input type="radio" name="interval" value="<?php echo esc_attr( $val ); ?>" <?php checked( $config['reports_interval'], $val ); ?>>
										<span><?php echo esc_html( $label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<ul class="bp-gs-info-list">
							<li><strong><?php esc_html_e( 'BrikPanel — Sales Summary', 'brikpanel' ); ?></strong> — <?php esc_html_e( 'multi-window revenue, orders, AOV, conversion.', 'brikpanel' ); ?></li>
							<li><strong><?php esc_html_e( 'BrikPanel — Daily KPIs', 'brikpanel' ); ?></strong> — <?php esc_html_e( 'last 90 days, one row per day.', 'brikpanel' ); ?></li>
							<li><strong><?php esc_html_e( 'BrikPanel — Top Products', 'brikpanel' ); ?></strong> — <?php esc_html_e( 'top 50 by units sold, last 90 days.', 'brikpanel' ); ?></li>
							<li><strong><?php esc_html_e( 'BrikPanel — Funnel', 'brikpanel' ); ?></strong> — <?php esc_html_e( 'daily visitor → checkout funnel.', 'brikpanel' ); ?></li>
						</ul>

						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-primary" data-action="save"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="sync-now"><?php esc_html_e( 'Sync now', 'brikpanel' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<?php
			Brikpanel_Sheets_Settings::render_activity_card( 'reports', $config['reports_last'] );
			?>
		</div>

		<!-- ============================================================== -->
		<!-- CUSTOMERS TAB                                                    -->
		<!-- ============================================================== -->
		<div class="bp-gs-tabpanel" data-panel="customers">

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Customers sync', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub">
						<?php esc_html_e( "Each customer's metrics + RFM segment, refreshed after BrikPanel's nightly recompute. Tab is overwritten on every sync.", 'brikpanel' ); ?>
					</p>

					<form class="bp-gs-form" data-flow="customers">
						<div class="bp-gs-field bp-gs-field-toggle">
							<span class="bp-gs-label"><?php esc_html_e( 'Enable customers sync', 'brikpanel' ); ?></span>
							<label class="bp-gs-toggle">
								<input type="checkbox" name="enabled" <?php checked( $config['customers_enabled'], 'yes' ); ?>>
								<span class="bp-gs-toggle-slider"></span>
							</label>
						</div>

						<div class="bp-gs-field">
							<label class="bp-gs-label" for="bp-gs-customers-tab"><?php esc_html_e( 'Target tab name', 'brikpanel' ); ?></label>
							<input type="text" id="bp-gs-customers-tab" name="tab" class="bp-gs-input" value="<?php echo esc_attr( $config['customers_tab'] ); ?>">
						</div>

						<div class="bp-gs-actions">
							<button type="button" class="bp-gs-btn bp-gs-btn-primary" data-action="save"><?php esc_html_e( 'Save', 'brikpanel' ); ?></button>
							<button type="button" class="bp-gs-btn bp-gs-btn-secondary" data-action="sync-now"><?php esc_html_e( 'Sync now', 'brikpanel' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div class="bp-gs-card">
				<div class="bp-gs-card-body">
					<h2><?php esc_html_e( 'Columns to export', 'brikpanel' ); ?></h2>
					<p class="bp-gs-card-sub"><?php esc_html_e( 'Customer key and email are mandatory so rows can be de-duplicated.', 'brikpanel' ); ?></p>
					<?php
					Brikpanel_Sheets_Settings::render_column_mapper( 'customers', $config['columns_customers'], $config['columns_customers_catalogue'] );
					?>
				</div>
			</div>

			<?php
			Brikpanel_Sheets_Settings::render_activity_card( 'customers', $config['customers_last'] );
			?>
		</div>

	</div>
</div>

