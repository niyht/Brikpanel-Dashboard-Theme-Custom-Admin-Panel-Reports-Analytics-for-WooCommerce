<?php
/**
 * BrikPanel — Settings Import / Export
 *
 * Lets the store owner download every BrikPanel configuration option as a
 * portable JSON file, and upload that file on another site (or the same site
 * after a rollback) to restore the exact same layout, toggles, palette and
 * dashboard ordering.
 *
 * Surface:
 *   - WooCommerce → Settings → BrikPanel → Import / Export
 *
 * Endpoints (admin-post.php):
 *   - `brikpanel_export_settings` — streams a JSON download with a `.json`
 *     filename based on the site host + timestamp.
 *   - `brikpanel_import_settings` — accepts a JSON upload, validates the
 *     header, sanitises every value against the option's declared type in
 *     `brikpanel_settings_fields()`, and writes the result with
 *     `update_option()`.
 *
 * Both endpoints require `manage_woocommerce` and a fresh nonce. Attachment
 * IDs (e.g. brand logo) are intentionally excluded — they reference a media
 * library on the source site and would point at a missing file on the target.
 *
 * @package BrikPanel
 * @since   2.8.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative list of option keys that participate in import / export.
 *
 * Built by walking `brikpanel_settings_fields()` (every field with a real
 * `id` and a non-title `type`) plus the few keys that are persisted outside
 * the WC field list (sidebar layout, product editor section order, legacy
 * visible-sections list).
 *
 * Returns `[ option_key => meta ]` where meta carries:
 *   - `type`    — original WC field type (used for sanitisation on import)
 *   - `default` — default value, when the field declared one
 *
 * Third parties can extend or trim the list via the
 * `brikpanel_exportable_option_keys` filter.
 *
 * @return array<string, array{type:string, default?:mixed}>
 */
function brikpanel_import_export_get_option_map() {
	$map = [];

	if ( function_exists( 'brikpanel_settings_fields' ) ) {
		foreach ( brikpanel_settings_fields() as $field ) {
			if ( empty( $field['id'] ) || empty( $field['type'] ) ) {
				continue;
			}
			$type = (string) $field['type'];
			if ( in_array( $type, [ 'title', 'sectionend', 'brikpanel_dev_docs', 'brikpanel_nav_customizer', 'brikpanel_section_order' ], true ) ) {
				continue;
			}
			// Brand logo references an attachment id that does not transfer
			// across sites — exporting it would point at a missing media item
			// on the target. Skip the picker.
			if ( $field['id'] === 'brikpanel_brand_logo_id' || $type === 'brikpanel_brand_logo_picker' ) {
				continue;
			}
			$map[ $field['id'] ] = [
				'type'    => $type,
				'default' => array_key_exists( 'default', $field ) ? $field['default'] : null,
			];
		}
	}

	// Options that live outside the WC field array but are still part of the
	// admin's BrikPanel configuration footprint.
	$extras = [
		'brikpanel_nav_config'           => [ 'type' => 'json_string' ],
		'brikpanel_pe_section_order'     => [ 'type' => 'json_string' ],
		'brikpanel_pe_visible_sections'  => [ 'type' => 'multiselect' ],
		'brikpanel_dashboard_section_order' => [ 'type' => 'json_string' ],
	];
	foreach ( $extras as $key => $meta ) {
		if ( ! isset( $map[ $key ] ) ) {
			$map[ $key ] = $meta;
		}
	}

	/**
	 * Filter the option keys that BrikPanel includes in export / import.
	 *
	 * @param array $map [ option_key => [ type, default? ] ]
	 */
	return apply_filters( 'brikpanel_exportable_option_keys', $map );
}

/**
 * Build the payload that goes into the downloaded JSON file. Header carries
 * provenance metadata so a future BrikPanel build can detect format mismatches
 * and refuse incompatible imports cleanly.
 *
 * @return array
 */
function brikpanel_import_export_build_payload() {
	$map     = brikpanel_import_export_get_option_map();
	$options = [];

	foreach ( $map as $key => $meta ) {
		// `false` is the documented "not in DB" return; preserve that distinction
		// from a stored empty string so import knows whether the source admin
		// intentionally cleared a value.
		$value = get_option( $key, null );
		if ( $value === null ) {
			continue;
		}
		$options[ $key ] = $value;
	}

	return [
		'format'           => 'brikpanel-settings',
		'format_version'   => 1,
		'plugin_version'   => defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '',
		'exported_at'      => gmdate( 'c' ),
		'source_site_url'  => home_url( '/' ),
		'options'          => $options,
	];
}

/**
 * Sanitize a single value against the field type declared in the option map.
 * Anything unrecognised falls through `sanitize_text_field()` so we never
 * write raw payload through to `update_option()`.
 *
 * @param mixed  $value Raw value as it came from the JSON file.
 * @param string $type  Declared field type ('checkbox', 'select', …).
 * @return mixed
 */
function brikpanel_import_export_sanitize_value( $value, $type ) {
	switch ( $type ) {
		case 'checkbox':
			$v = is_string( $value ) ? strtolower( $value ) : $value;
			if ( $v === 'yes' || $v === true || $v === 1 || $v === '1' ) {
				return 'yes';
			}
			return 'no';

		case 'number':
			if ( is_numeric( $value ) ) {
				return (string) (float) $value === (string) (int) $value
					? (string) (int) $value
					: (string) (float) $value;
			}
			return '';

		case 'color':
			$v = is_string( $value ) ? trim( $value ) : '';
			if ( preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $v ) ) {
				return strtolower( $v );
			}
			return '';

		case 'multiselect':
			if ( ! is_array( $value ) ) {
				return [];
			}
			$out = [];
			foreach ( $value as $entry ) {
				if ( is_scalar( $entry ) ) {
					$out[] = sanitize_text_field( (string) $entry );
				}
			}
			return $out;

		case 'select':
		case 'text':
		case 'radio':
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		case 'textarea':
			return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';

		case 'json_string':
			// Stored as a JSON-encoded scalar in the DB. Accept either a
			// pre-encoded string or a nested array (re-encode it).
			if ( is_string( $value ) ) {
				$decoded = json_decode( $value, true );
				return $decoded === null ? '' : wp_json_encode( $decoded );
			}
			if ( is_array( $value ) ) {
				return wp_json_encode( $value );
			}
			return '';

		default:
			if ( is_array( $value ) ) {
				$out = [];
				foreach ( $value as $entry ) {
					if ( is_scalar( $entry ) ) {
						$out[] = sanitize_text_field( (string) $entry );
					}
				}
				return $out;
			}
			return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}
}

/**
 * Suggest a filename for the exported settings, scoped to the source site
 * host plus a UTC timestamp so multiple exports from the same store sort
 * naturally on disk.
 */
function brikpanel_import_export_filename() {
	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$host = is_string( $host ) ? preg_replace( '/[^a-z0-9.\-]/i', '', $host ) : 'site';
	if ( $host === '' ) {
		$host = 'site';
	}
	return sprintf( 'brikpanel-settings-%s-%s.json', $host, gmdate( 'Ymd-His' ) );
}

// =============================================================================
// ADMIN-POST: EXPORT
// =============================================================================

add_action( 'admin_post_brikpanel_export_settings', 'brikpanel_import_export_handle_export' );
function brikpanel_import_export_handle_export() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to export BrikPanel settings.', 'brikpanel' ), '', [ 'response' => 403 ] );
	}
	// Our nonce is named distinctly so it never collides with WC's `_wpnonce`
	// when the export submit piggy-backs on WC's #mainform.
	check_admin_referer( 'brikpanel_export_settings', 'brikpanel_export_nonce' );

	$payload = brikpanel_import_export_build_payload();
	$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( $json === false ) {
		wp_die( esc_html__( 'Could not encode BrikPanel settings as JSON.', 'brikpanel' ) );
	}

	// Drop any buffered output (admin headers, notice fragments) so the
	// download stream is clean.
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . brikpanel_import_export_filename() . '"' );
	header( 'Content-Length: ' . strlen( $json ) );
	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — JSON file body, not HTML.
	exit;
}

// =============================================================================
// ADMIN-POST: IMPORT
// =============================================================================

add_action( 'admin_post_brikpanel_import_settings', 'brikpanel_import_export_handle_import' );
function brikpanel_import_export_handle_import() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to import BrikPanel settings.', 'brikpanel' ), '', [ 'response' => 403 ] );
	}
	// See the export handler for the rationale on the custom nonce field name.
	check_admin_referer( 'brikpanel_import_settings', 'brikpanel_import_nonce' );

	$redirect = admin_url( 'admin.php?page=wc-settings&tab=brikpanel&section=import-export' );

	if ( empty( $_FILES['brikpanel_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['brikpanel_import_file']['tmp_name'] ) ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'no_file', $redirect ) );
		exit;
	}

	$tmp = $_FILES['brikpanel_import_file']['tmp_name'];

	// Cap the file size — a legit export is a few KB. Anything past 1 MB is
	// either corrupted or hostile, refuse before reading it into memory.
	$size = isset( $_FILES['brikpanel_import_file']['size'] ) ? (int) $_FILES['brikpanel_import_file']['size'] : 0;
	if ( $size > 1024 * 1024 ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'too_large', $redirect ) );
		exit;
	}

	$contents = file_get_contents( $tmp );
	if ( $contents === false || $contents === '' ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'unreadable', $redirect ) );
		exit;
	}

	$payload = json_decode( $contents, true );
	if ( ! is_array( $payload ) || empty( $payload['format'] ) || $payload['format'] !== 'brikpanel-settings' ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'invalid', $redirect ) );
		exit;
	}

	$incoming = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : [];
	if ( empty( $incoming ) ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'empty', $redirect ) );
		exit;
	}

	$map     = brikpanel_import_export_get_option_map();
	$applied = 0;
	$skipped = 0;

	foreach ( $incoming as $key => $value ) {
		if ( ! is_string( $key ) || ! isset( $map[ $key ] ) ) {
			$skipped++;
			continue;
		}
		$type      = isset( $map[ $key ]['type'] ) ? (string) $map[ $key ]['type'] : '';
		$sanitized = brikpanel_import_export_sanitize_value( $value, $type );
		update_option( $key, $sanitized );
		$applied++;
	}

	// Pop any cached data + branded "saved" toast so the next page load
	// reflects the imported configuration immediately.
	if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
		brikpanel_bust_data_caches();
	}
	set_transient( 'brikpanel_settings_saved_' . get_current_user_id(), 1, 30 );

	wp_safe_redirect( add_query_arg(
		[
			'brikpanel_import'         => 'ok',
			'brikpanel_import_applied' => $applied,
			'brikpanel_import_skipped' => $skipped,
		],
		$redirect
	) );
	exit;
}

// =============================================================================
// SECTION RENDERER
// =============================================================================

/**
 * Render the Import / Export section body inside the BrikPanel WC settings
 * tab. Called from the `woocommerce_settings_tabs_brikpanel` short-circuit
 * in `front-end/orders/brikpanel-orders.php` when the section is active.
 *
 * WC wraps the entire settings page in a single `<form id="mainform"
 * enctype="multipart/form-data">`. Nested forms are illegal in HTML and the
 * browser parser silently drops the inner `<form>` open tag. We therefore
 * place plain inputs + buttons inside the WC form and use the HTML5
 * `formaction` / `formmethod` / `formenctype` button overrides to redirect
 * the submit to `admin-post.php` with the matching action. The WC nonce
 * (`_wpnonce`) gets posted alongside our own nonce field — we name ours
 * differently so the two never collide.
 */
function brikpanel_import_export_render_section() {
	$map         = brikpanel_import_export_get_option_map();
	$total_keys  = count( $map );
	$post_url    = admin_url( 'admin-post.php' );

	$status = isset( $_GET['brikpanel_import'] ) ? sanitize_key( wp_unslash( $_GET['brikpanel_import'] ) ) : '';
	$applied = isset( $_GET['brikpanel_import_applied'] ) ? (int) $_GET['brikpanel_import_applied'] : 0;
	$skipped = isset( $_GET['brikpanel_import_skipped'] ) ? (int) $_GET['brikpanel_import_skipped'] : 0;

	$status_messages = [
		'ok'         => [
			'type' => 'success',
			'text' => sprintf(
				/* translators: 1: number of options imported, 2: number skipped */
				_n( '%1$d setting imported. %2$d unknown key skipped.', '%1$d settings imported. %2$d unknown keys skipped.', $applied, 'brikpanel' ),
				$applied,
				$skipped
			),
		],
		'no_file'    => [ 'type' => 'error', 'text' => __( 'Pick a BrikPanel JSON file before clicking Import.', 'brikpanel' ) ],
		'too_large'  => [ 'type' => 'error', 'text' => __( 'That file is too large to be a BrikPanel settings export.', 'brikpanel' ) ],
		'unreadable' => [ 'type' => 'error', 'text' => __( 'Could not read the uploaded file. Please try again.', 'brikpanel' ) ],
		'invalid'    => [ 'type' => 'error', 'text' => __( 'That file is not a valid BrikPanel settings export.', 'brikpanel' ) ],
		'empty'      => [ 'type' => 'error', 'text' => __( 'The uploaded file did not contain any BrikPanel settings.', 'brikpanel' ) ],
	];
	?>
	<div class="brikpanel-iox">
		<?php if ( isset( $status_messages[ $status ] ) ) : ?>
			<div class="brikpanel-iox__notice brikpanel-iox__notice--<?php echo esc_attr( $status_messages[ $status ]['type'] ); ?>" role="status">
				<?php echo esc_html( $status_messages[ $status ]['text'] ); ?>
			</div>
		<?php endif; ?>

		<section class="brikpanel-iox__card">
			<header class="brikpanel-iox__card-head">
				<div>
					<h2 class="brikpanel-iox__card-title"><?php esc_html_e( 'Export settings', 'brikpanel' ); ?></h2>
					<p class="brikpanel-iox__card-desc">
						<?php
						printf(
							/* translators: %d: number of options included */
							esc_html__( 'Download every BrikPanel option as a single JSON file. Includes %d configuration keys: toggles, layout, accent color, sidebar order, dashboard order, notification preferences.', 'brikpanel' ),
							(int) $total_keys
						);
						?>
					</p>
				</div>
				<svg class="brikpanel-iox__card-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
			</header>

			<?php wp_nonce_field( 'brikpanel_export_settings', 'brikpanel_export_nonce', true, true ); ?>
			<button
				type="submit"
				class="brikpanel-iox__btn brikpanel-iox__btn--primary"
				formaction="<?php echo esc_url( $post_url ); ?>"
				formmethod="post"
				name="action"
				value="brikpanel_export_settings"
			>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
				<?php esc_html_e( 'Download JSON file', 'brikpanel' ); ?>
			</button>
			<p class="brikpanel-iox__hint">
				<?php esc_html_e( 'The brand logo image is not included — attachment IDs are tied to your media library and would point at a missing file on the target site. Re-pick the logo after importing.', 'brikpanel' ); ?>
			</p>
		</section>

		<section class="brikpanel-iox__card">
			<header class="brikpanel-iox__card-head">
				<div>
					<h2 class="brikpanel-iox__card-title"><?php esc_html_e( 'Import settings', 'brikpanel' ); ?></h2>
					<p class="brikpanel-iox__card-desc">
						<?php esc_html_e( 'Upload a JSON file exported from BrikPanel. Existing options with the same keys will be overwritten. Unknown keys are skipped — your store stays safe.', 'brikpanel' ); ?>
					</p>
				</div>
				<svg class="brikpanel-iox__card-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
			</header>

			<?php wp_nonce_field( 'brikpanel_import_settings', 'brikpanel_import_nonce', true, true ); ?>

			<label class="brikpanel-iox__drop" id="brikpanel-iox-drop">
				<input type="file" name="brikpanel_import_file" id="brikpanel-iox-file" accept="application/json,.json" />
				<div class="brikpanel-iox__drop-inner" data-state="empty">
					<svg class="brikpanel-iox__drop-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
					<div class="brikpanel-iox__drop-title"><?php esc_html_e( 'Drag a .json file here', 'brikpanel' ); ?></div>
					<div class="brikpanel-iox__drop-sub"><?php esc_html_e( 'or click to browse', 'brikpanel' ); ?></div>
					<div class="brikpanel-iox__drop-file" id="brikpanel-iox-filename" hidden></div>
				</div>
			</label>

			<div class="brikpanel-iox__form-row">
				<button
					type="submit"
					class="brikpanel-iox__btn brikpanel-iox__btn--primary"
					id="brikpanel-iox-submit"
					formaction="<?php echo esc_url( $post_url ); ?>"
					formmethod="post"
					formenctype="multipart/form-data"
					name="action"
					value="brikpanel_import_settings"
					disabled
				>
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
					<?php esc_html_e( 'Import settings', 'brikpanel' ); ?>
				</button>
				<span class="brikpanel-iox__hint">
					<?php esc_html_e( 'Tip: export first if you want a quick rollback path.', 'brikpanel' ); ?>
				</span>
			</div>
		</section>
	</div>

	<style id="brikpanel-iox-style">
	.brikpanel-iox { display:flex; flex-direction:column; gap:1rem; max-width:820px; margin-top:.25rem; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; color:#303030; }
	.brikpanel-iox__notice { padding:.75rem 1rem; border-radius:.5rem; font-size:.8125rem; font-weight:550; line-height:1.5; }
	.brikpanel-iox__notice--success { background:#e4f5e1; color:#1a6b15; border:1px solid #b7e1b0; }
	.brikpanel-iox__notice--error { background:#fce4e4; color:#c62828; border:1px solid #f1b0b0; }
	.brikpanel-iox__card { background:#fff; border:1px solid #e3e3e3; border-radius:.75rem; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:1.25rem 1.5rem; }
	.brikpanel-iox__card-head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1rem; }
	.brikpanel-iox__card-title { margin:0 0 .25rem; font-size:1.125rem; font-weight:600; line-height:1.4; }
	.brikpanel-iox__card-desc { margin:0; font-size:.8125rem; color:#616161; line-height:1.5; }
	.brikpanel-iox__card-icon { color:#8a8a8a; flex:none; margin-top:.125rem; }
	.brikpanel-iox__form { display:flex; flex-direction:column; gap:.75rem; }
	.brikpanel-iox__form-row { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
	.brikpanel-iox__hint { font-size:.75rem; color:#8a8a8a; line-height:1.5; margin:0; }
	.brikpanel-iox__btn { display:inline-flex; align-items:center; gap:.5rem; padding:.5rem 1rem; font-size:.8125rem; font-weight:550; border-radius:.5rem; border:0; cursor:pointer; transition:background .15s ease; line-height:1; font-family:inherit; }
	.brikpanel-iox__btn--primary { background:#303030; color:#fff; box-shadow:inset 0 -1px 0 rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.1); }
	.brikpanel-iox__btn--primary:hover:not(:disabled) { background:#1a1a1a; }
	.brikpanel-iox__btn:disabled { opacity:.55; cursor:not-allowed; }
	.brikpanel-iox__drop { position:relative; display:block; border:2px dashed #e3e3e3; border-radius:.75rem; background:#fafafa; transition:background .15s ease,border-color .15s ease; cursor:pointer; }
	.brikpanel-iox__drop:hover, .brikpanel-iox__drop.is-dragover { background:#f1f1f1; border-color:#8a8a8a; }
	.brikpanel-iox__drop input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }
	.brikpanel-iox__drop-inner { padding:1.75rem 1rem; text-align:center; display:flex; flex-direction:column; align-items:center; gap:.25rem; pointer-events:none; }
	.brikpanel-iox__drop-icon { color:#8a8a8a; margin-bottom:.25rem; }
	.brikpanel-iox__drop-title { font-size:.875rem; font-weight:550; color:#303030; }
	.brikpanel-iox__drop-sub { font-size:.8125rem; color:#8a8a8a; }
	.brikpanel-iox__drop-file { margin-top:.5rem; font-size:.8125rem; font-weight:550; color:#1a8917; background:#e4f5e1; padding:.25rem .625rem; border-radius:.375rem; border:1px solid #b7e1b0; }
	/* Hide the WC "Save changes" submit on this section — there is nothing to save in the form-table sense. */
	body.woocommerce_page_wc-settings .brikpanel-settings-section-body[data-section="import-export"] ~ p.submit,
	body.woocommerce_page_wc-settings .brikpanel-settings-section-body[data-section="import-export"] + p.submit { display:none; }
	</style>

	<script>
	(function(){
		var input = document.getElementById('brikpanel-iox-file');
		var drop  = document.getElementById('brikpanel-iox-drop');
		var inner = drop ? drop.querySelector('.brikpanel-iox__drop-inner') : null;
		var name  = document.getElementById('brikpanel-iox-filename');
		var btn   = document.getElementById('brikpanel-iox-submit');
		if (!input || !drop || !inner || !name || !btn) return;

		function applyFile(file){
			if (!file) {
				name.hidden = true;
				name.textContent = '';
				btn.disabled = true;
				inner.dataset.state = 'empty';
				return;
			}
			name.hidden = false;
			name.textContent = file.name;
			btn.disabled = false;
			inner.dataset.state = 'filled';
		}

		input.addEventListener('change', function(){
			applyFile(input.files && input.files[0]);
		});

		['dragenter','dragover'].forEach(function(ev){
			drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('is-dragover'); });
		});
		['dragleave','drop'].forEach(function(ev){
			drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('is-dragover'); });
		});
		drop.addEventListener('drop', function(e){
			if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
			input.files = e.dataTransfer.files;
			applyFile(input.files[0]);
		});
	})();
	</script>
	<?php
}
