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
 *     header, sanitises every value against its registry entry, and applies it.
 *
 * Both endpoints require `manage_woocommerce` and a fresh nonce.
 *
 * WHAT CHANGED IN 3.3.14, AND WHY
 *
 * This module used to decide what was exportable by walking
 * `brikpanel_settings_fields()` and treating each field's `id` as an option
 * name. It is now one contributor to `includes/brikpanel-export-registry.php`
 * instead of the authority, because that walk could not see:
 *
 *   - a card that renders its own inputs, whose field id is a placeholder and
 *     whose real options live under other names (six cards, twelve options,
 *     every top bar setting among them);
 *   - a field declared conditionally, such as the BrikMentor promotion switch,
 *     which is only added when BrikMentor is absent.
 *
 * Two further faults are fixed here rather than in the registry:
 *
 *   - A value the source admin never saved was simply absent from the file, so
 *     the target kept its own different value and the merchant was told the
 *     import had succeeded. The file now records those keys in `defaults` and
 *     the import clears them, which is what "clone this site" has to mean.
 *   - Anything whose type had no case in the sanitiser fell through a generic
 *     branch that FLATTENED nested arrays and ran `sanitize_text_field()` over
 *     scalars — which is how multi-line custom CSS arrived as a single line.
 *     Shapes are now cleaned by the module that owns them.
 *
 * @package BrikPanel
 * @since   2.8.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =============================================================================
// REGISTRY CONTRIBUTION — the declared settings fields
// =============================================================================

/**
 * Register every option that IS a declared WooCommerce settings field.
 *
 * Priority 5 so modules registering at the default priority can refine what
 * the walk produced — a module knows its own shape, its own sanitiser and
 * whether clearing its key is safe; the walk only knows the field type.
 *
 * Placeholder ids are skipped (they name a card, not an option) and the brand
 * logo attachment id is classified `site` rather than dropped, so the audit can
 * see it was considered: an attachment id points at a media library that does
 * not exist on the target.
 *
 * @param array $map Registry so far.
 * @return array
 */
add_filter( 'brikpanel_exportable_option_keys', 'brikpanel_import_export_register_declared_fields', 5 );
function brikpanel_import_export_register_declared_fields( $map ) {
	if ( ! function_exists( 'brikpanel_settings_fields' ) ) {
		return $map;
	}

	$placeholders = array_flip( brikpanel_export_placeholder_field_ids() );
	$group        = 'general';

	foreach ( brikpanel_settings_fields() as $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		$id   = isset( $field['id'] ) ? (string) $field['id'] : '';

		// A title opens a section; remember which one so the fields under it
		// can tell a merchant where they came from.
		if ( 'title' === $type && '' !== $id ) {
			$section = function_exists( 'brikpanel_settings_section_for_title' )
				? (string) brikpanel_settings_section_for_title( $id )
				: '';
			$group   = '' === $section ? 'general' : $section;
			continue;
		}
		if ( '' === $id || '' === $type || 'sectionend' === $type ) {
			continue;
		}
		if ( isset( $placeholders[ $id ] ) ) {
			continue;
		}
		if ( isset( $map[ $id ] ) ) {
			continue;  // Already owned by its module; the module's entry wins.
		}

		if ( 'brikpanel_brand_logo_id' === $id || 'brikpanel_brand_logo_picker' === $type ) {
			$map[ $id ] = [
				'class' => 'site',
				'group' => $group,
			];
			continue;
		}

		$map[ $id ] = [
			'class'   => 'portable',
			'group'   => $group,
			'type'    => $type,
			'default' => array_key_exists( 'default', $field ) ? $field['default'] : null,
		];
	}

	return $map;
}

/**
 * The exportable option map, kept as a named function because other code (and
 * the screen's own copy) asks for it.
 *
 * @return array<string, array> [ option_key => registry entry ]
 */
function brikpanel_import_export_get_option_map() {
	return brikpanel_export_portable_entries();
}

// =============================================================================
// SANITISATION
// =============================================================================

/**
 * Clean one imported value.
 *
 * The registry entry decides: a `sanitize` callable from the owning module
 * wins, because only that module knows the shape. Otherwise the legacy
 * WooCommerce field type picks a generic cleaner.
 *
 * Returns the sentinel `null` when the value cannot be cleaned safely, so the
 * caller can count it rather than write something wrong. The old code's
 * catch-all branch is deliberately NOT the fallback any more: it flattened
 * nested arrays into a list of strings, which silently destroyed every
 * multi-level setting it was handed.
 *
 * @param mixed $value Raw value from the JSON file.
 * @param array $entry Registry entry.
 * @return mixed|null Cleaned value, or null when it must be skipped.
 */
function brikpanel_import_export_sanitize_entry( $value, array $entry ) {
	if ( '' !== $entry['sanitize'] ) {
		if ( ! is_callable( $entry['sanitize'] ) ) {
			// The owning module is not loaded on this request. Writing the raw
			// value would be worse than skipping it.
			return null;
		}
		return call_user_func( $entry['sanitize'], $value );
	}

	$type = (string) $entry['type'];
	if ( '' === $type ) {
		// No shape declared at all. Scalars are safe to clean generically;
		// anything structured is not.
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
	}

	return brikpanel_import_export_sanitize_value( $value, $type );
}

/**
 * Sanitize a value against a legacy WooCommerce field type.
 *
 * Kept as its own function because `format_version: 1` files are still
 * imported through it, and because a type is all the walk knows.
 *
 * @param mixed  $value Raw value as it came from the JSON file.
 * @param string $type  Declared field type ('checkbox', 'select', …).
 * @return mixed|null
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

		case 'url':
			// Restricted to http(s) so an imported file can never inject a
			// javascript:/data: payload that later renders in admin CSS.
			return is_scalar( $value ) ? esc_url_raw( (string) $value, [ 'http', 'https' ] ) : '';

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
			// An unrecognised type means nobody has declared this shape. Skip
			// it: the old behaviour here — flatten arrays, strip newlines out
			// of scalars — quietly corrupted every value it could not name.
			return null;
	}
}

// =============================================================================
// PAYLOAD
// =============================================================================

/**
 * Build the payload that goes into the downloaded JSON file.
 *
 * `options` keeps exactly its version 1 meaning: keys that have a row, values
 * verbatim. Keys with no row go into `defaults` as a flat list of names rather
 * than as nulls inside `options`, and that shape is load-bearing: a BrikPanel
 * 3.3.13 reading this file ignores the key it does not know, whereas a null
 * inside `options` would have been cleaned into an empty string and written
 * over a good value on the target.
 *
 * @param int $user_id Whose per-user layouts to include. 0 for none.
 * @return array
 */
function brikpanel_import_export_build_payload( $user_id = 0 ) {
	$options  = [];
	$defaults = [];

	foreach ( brikpanel_export_portable_entries() as $key => $entry ) {
		// `null` is the documented "not in DB" return; it is what tells the
		// difference between a stored empty string and a setting the source
		// admin never touched.
		$value = get_option( $key, null );
		if ( $value === null ) {
			$defaults[] = $key;
			continue;
		}
		$options[ $key ] = $value;
	}

	$payload = [
		'format'          => 'brikpanel-settings',
		'format_version'  => BRIKPANEL_EXPORT_FORMAT_VERSION,
		'plugin_version'  => defined( 'BRIKPANEL_VERSION' ) ? BRIKPANEL_VERSION : '',
		'exported_at'     => gmdate( 'c' ),
		'source_site_url' => home_url( '/' ),
		'groups'          => brikpanel_export_group_slugs(),
		'options'         => $options,
		'defaults'        => $defaults,
	];

	$layouts = brikpanel_import_export_read_user_layouts( $user_id );
	if ( $layouts ) {
		$payload['user_layouts'] = $layouts;
	}

	return $payload;
}

/**
 * Read the per-user admin layouts (column choices) of one account.
 *
 * These are preferences of a person, not of a site. They travel so an agency
 * can hand a client store the same table layout it built, and they are applied
 * to the account that imports — never to anybody else's.
 *
 * @param int $user_id User whose layouts to read.
 * @return array
 */
function brikpanel_import_export_read_user_layouts( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return [];
	}

	$out = [];
	foreach ( brikpanel_export_user_layout_keys() as $key => $meta ) {
		$value = 'option' === $meta['kind']
			? get_user_option( $key, $user_id )
			: get_user_meta( $user_id, $key, true );
		if ( '' === $value || false === $value || null === $value || [] === $value ) {
			continue;
		}
		$out[ $key ] = $value;
	}
	return $out;
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

	$payload = brikpanel_import_export_build_payload( get_current_user_id() );
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

	$version = isset( $payload['format_version'] ) ? (int) $payload['format_version'] : 1;
	if ( $version > BRIKPANEL_EXPORT_FORMAT_VERSION ) {
		// A file from a newer BrikPanel. Applying the half we understand would
		// produce a partial clone described as a complete one.
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'too_new', $redirect ) );
		exit;
	}

	$incoming = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : [];
	$absent   = ( $version >= 2 && isset( $payload['defaults'] ) && is_array( $payload['defaults'] ) )
		? $payload['defaults']
		: [];
	if ( empty( $incoming ) && empty( $absent ) ) {
		wp_safe_redirect( add_query_arg( 'brikpanel_import', 'empty', $redirect ) );
		exit;
	}

	$result = brikpanel_import_export_apply( $payload, $incoming, $absent, $version );

	wp_safe_redirect( add_query_arg(
		[
			'brikpanel_import'            => 'ok',
			'brikpanel_import_applied'    => $result['applied'],
			'brikpanel_import_cleared'    => $result['cleared'],
			'brikpanel_import_skipped'    => $result['skipped'],
			'brikpanel_import_rewritten'  => $result['rewritten'],
			'brikpanel_import_layouts'    => $result['layouts'],
			'brikpanel_import_stale'      => rawurlencode( implode( ',', $result['stale_groups'] ) ),
			'brikpanel_import_v'          => $version,
		],
		$redirect
	) );
	exit;
}

/**
 * Apply a validated payload.
 *
 * Registry order is the apply order, and that is deliberate: the per-status
 * email settings name statuses that the custom-status option has to have
 * written first, and the Quick Edit mirrors are derived from a list that has
 * to be in place before they are recomputed.
 *
 * @param array $payload  Whole decoded file (header included).
 * @param array $incoming `options` map.
 * @param array $absent   `defaults` list of keys that are at their default on
 *                        the source.
 * @param int   $version  Payload format version.
 * @return array Counters plus the stale-group list.
 */
function brikpanel_import_export_apply( array $payload, array $incoming, array $absent, $version ) {
	$registry = brikpanel_export_portable_entries();
	$absent   = array_flip( array_filter( $absent, 'is_string' ) );

	$applied   = 0;
	$cleared   = 0;
	$skipped   = 0;
	$rewritten = 0;
	$after     = [];
	$touched   = [];

	$source_host = '';
	if ( ! empty( $payload['source_site_url'] ) && is_string( $payload['source_site_url'] ) ) {
		$source_host = (string) wp_parse_url( $payload['source_site_url'], PHP_URL_HOST );
	}

	// Count the keys the file does not mention at all, so a version 1 file can
	// be described honestly rather than reported as a clean clone.
	foreach ( $incoming as $key => $_value ) {
		if ( ! is_string( $key ) || ! isset( $registry[ $key ] ) ) {
			$skipped++;
		}
	}

	foreach ( $registry as $key => $entry ) {
		$has_value = array_key_exists( $key, $incoming );

		if ( $has_value ) {
			$clean = brikpanel_import_export_sanitize_entry( $incoming[ $key ], $entry );
			if ( $clean === null ) {
				$skipped++;
				continue;
			}
			if ( $entry['rewrite_urls'] && '' !== $source_host ) {
				$clean = brikpanel_import_export_rewrite_urls( $clean, $source_host, $rewritten );
			}
			brikpanel_update_option( $key, $clean );
			$applied++;
			$touched[] = $key;
			if ( '' !== $entry['after'] ) {
				$after[ $entry['after'] ] = true;
			}
			continue;
		}

		if ( ! isset( $absent[ $key ] ) || 'never' === $entry['clear'] ) {
			continue;
		}

		// Count only what actually changed. A key the target had never saved
		// either is already at the source's value, and reporting it would turn
		// an accurate number into an alarming one: a fresh store importing a
		// template would be told a hundred and thirty settings had been reset
		// when nothing on the screen moved.
		$had_row = ( get_option( $key, null ) !== null );

		if ( 'write_default' === $entry['clear'] ) {
			brikpanel_update_option( $key, $entry['default'] );
		} else {
			delete_option( $key );
		}
		if ( $had_row ) {
			$cleared++;
		}
		$touched[] = $key;
		if ( '' !== $entry['after'] ) {
			$after[ $entry['after'] ] = true;
		}
	}

	$layouts = 0;
	if ( ! empty( $payload['user_layouts'] ) && is_array( $payload['user_layouts'] ) ) {
		$layouts = brikpanel_import_export_apply_user_layouts( $payload['user_layouts'], get_current_user_id() );
	}

	// Side effects the write itself does not produce. `update_option_X` hooks
	// DO fire from a plain update_option(), so this is not about those; it is
	// about the two gaps they leave: handlers bound to
	// `woocommerce_update_options_brikpanel` (which read $_POST and therefore
	// cannot run here), and the clear path, where almost nothing in the plugin
	// registers a `delete_option_X` counterpart.
	foreach ( array_keys( $after ) as $callable ) {
		if ( is_callable( $callable ) ) {
			call_user_func( $callable );
		}
	}

	/**
	 * Fires once after a settings import has been applied.
	 *
	 * @since 3.3.14
	 *
	 * @param string[] $touched Option keys written or cleared.
	 * @param array    $payload The decoded file.
	 */
	do_action( 'brikpanel_settings_imported', $touched, $payload );

	// Pop any cached data + branded "saved" toast so the next page load
	// reflects the imported configuration immediately.
	if ( function_exists( 'brikpanel_bust_data_caches' ) ) {
		brikpanel_bust_data_caches();
	}
	// Action Scheduler schedules are fingerprinted; an import can change which
	// jobs should exist (a module toggled off, an interval changed). Dropping
	// the fingerprint makes the next init re-register them instead of waiting
	// out the reconcile TTL.
	delete_option( 'brikpanel_cron_reconciled' );

	set_transient( 'brikpanel_settings_saved_' . get_current_user_id(), 1, 30 );

	return [
		'applied'      => $applied,
		'cleared'      => $cleared,
		'skipped'      => $skipped,
		'rewritten'    => $rewritten,
		'layouts'      => $layouts,
		'stale_groups' => brikpanel_import_export_stale_groups( $payload, $version ),
	];
}

/**
 * Repoint absolute URLs that pointed at the source site.
 *
 * Only URLs whose host matches the exporting site are touched. An agency's own
 * domain, a supplier portal, a documentation link — anything external — is left
 * exactly as it was, which is the whole reason this is a host comparison and
 * not a search and replace.
 *
 * @param mixed  $value       Cleaned value.
 * @param string $source_host Host of the exporting site.
 * @param int    $count       Running count of rewrites, by reference.
 * @return mixed
 */
function brikpanel_import_export_rewrite_urls( $value, $source_host, &$count ) {
	$target = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	if ( '' === $target || $target === $source_host ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		// Some layouts (the sidebar) are stored as a JSON string, so the URLs
		// are nested inside it rather than being the value. Walk the decoded
		// structure and re-encode, otherwise a whole navigation config would
		// parse as "no host" and quietly keep pointing at the source site.
		$trimmed = ltrim( $value );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$before  = $count;
				$decoded = brikpanel_import_export_rewrite_urls( $decoded, $source_host, $count );
				return $count > $before ? wp_json_encode( $decoded ) : $value;
			}
		}

		$host = (string) wp_parse_url( $value, PHP_URL_HOST );
		if ( '' !== $host && strtolower( $host ) === strtolower( $source_host ) ) {
			$count++;
			return str_ireplace( '//' . $source_host, '//' . $target, $value );
		}
		return $value;
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = brikpanel_import_export_rewrite_urls( $v, $source_host, $count );
		}
	}

	return $value;
}

/**
 * Write the per-user layouts onto the importing account.
 *
 * @param array $layouts From the payload.
 * @param int   $user_id Importing user.
 * @return int Number of layouts applied.
 */
function brikpanel_import_export_apply_user_layouts( array $layouts, $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return 0;
	}

	$applied = 0;
	foreach ( brikpanel_export_user_layout_keys() as $key => $meta ) {
		if ( ! array_key_exists( $key, $layouts ) ) {
			continue;
		}
		$value = $layouts[ $key ];
		if ( '' !== $meta['sanitize'] ) {
			if ( ! is_callable( $meta['sanitize'] ) ) {
				continue;
			}
			$value = call_user_func( $meta['sanitize'], $value );
		}
		if ( null === $value ) {
			continue;
		}
		// update_user_option() prefixes the blog id; on multisite the two are
		// not interchangeable, so the storage kind is part of the contract.
		if ( 'option' === $meta['kind'] ) {
			update_user_option( $user_id, $key, $value );
		} else {
			update_user_meta( $user_id, $key, $value );
		}
		$applied++;
	}
	return $applied;
}

/**
 * Which sections does this file predate?
 *
 * A version 1 file carries no group list, so every group this build knows is
 * potentially missing from it — but saying "all of them" would be noise. The
 * honest answer for a v1 file is a different sentence, handled by the screen;
 * here it returns the concrete diff whenever the file does carry groups.
 *
 * @param array $payload Decoded file.
 * @param int   $version Format version.
 * @return string[] Group slugs this build has and the file does not.
 */
/**
 * Human label for a registry group.
 *
 * Groups are settings-section slugs, so the section list answers almost all of
 * them and the merchant reads the same words as on the settings tab. The few
 * that belong to a screen outside that tab get a label here — reusing strings
 * the catalogues already carry rather than minting new ones.
 *
 * @param string $slug     Group slug.
 * @param array  $sections Section slug => label.
 * @return string
 */
function brikpanel_import_export_group_label( $slug, array $sections ) {
	$key = 'general' === $slug ? '' : $slug;
	if ( isset( $sections[ $key ] ) ) {
		return (string) $sections[ $key ];
	}
	$extra = [
		'expenses' => __( 'Expenses', 'brikpanel' ),
	];
	return isset( $extra[ $slug ] ) ? $extra[ $slug ] : $slug;
}

function brikpanel_import_export_stale_groups( array $payload, $version ) {
	if ( $version < 2 || empty( $payload['groups'] ) || ! is_array( $payload['groups'] ) ) {
		return [];
	}
	$theirs = array_filter( $payload['groups'], 'is_string' );
	return array_values( array_diff( brikpanel_export_group_slugs(), $theirs ) );
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
	$post_url = admin_url( 'admin-post.php' );
	$sections = function_exists( 'brikpanel_settings_get_sections' ) ? brikpanel_settings_get_sections() : [];
	$labels   = [];
	foreach ( brikpanel_export_group_slugs() as $slug ) {
		$labels[] = brikpanel_import_export_group_label( $slug, $sections );
	}
	sort( $labels );

	$status    = isset( $_GET['brikpanel_import'] ) ? sanitize_key( wp_unslash( $_GET['brikpanel_import'] ) ) : '';
	$applied   = isset( $_GET['brikpanel_import_applied'] ) ? (int) $_GET['brikpanel_import_applied'] : 0;
	$cleared   = isset( $_GET['brikpanel_import_cleared'] ) ? (int) $_GET['brikpanel_import_cleared'] : 0;
	$skipped   = isset( $_GET['brikpanel_import_skipped'] ) ? (int) $_GET['brikpanel_import_skipped'] : 0;
	$rewritten = isset( $_GET['brikpanel_import_rewritten'] ) ? (int) $_GET['brikpanel_import_rewritten'] : 0;
	$layouts   = isset( $_GET['brikpanel_import_layouts'] ) ? (int) $_GET['brikpanel_import_layouts'] : 0;
	$file_ver  = isset( $_GET['brikpanel_import_v'] ) ? (int) $_GET['brikpanel_import_v'] : 0;
	$stale_raw = isset( $_GET['brikpanel_import_stale'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['brikpanel_import_stale'] ) ) ) : '';

	// Build the success message out of one plural-aware line per non-zero
	// counter. One msgid carrying five numbers would be untranslatable in half
	// the catalogues and unreadable in the rest.
	$lines = [];
	if ( 'ok' === $status ) {
		$lines[] = sprintf(
			/* translators: %d: number of settings written. */
			_n( '%d setting applied.', '%d settings applied.', $applied, 'brikpanel' ),
			$applied
		);
		if ( $cleared > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of settings reset to their default. */
				_n( '%d setting cleared to its default.', '%d settings cleared to their defaults.', $cleared, 'brikpanel' ),
				$cleared
			);
		}
		if ( $skipped > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of keys the file carried that this site does not know. */
				_n( '%d unknown key skipped.', '%d unknown keys skipped.', $skipped, 'brikpanel' ),
				$skipped
			);
		}
		if ( $rewritten > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of links repointed from the source site to this one. */
				_n( '%d link repointed to this site.', '%d links repointed to this site.', $rewritten, 'brikpanel' ),
				$rewritten
			);
		}
		if ( $layouts > 0 ) {
			$lines[] = __( 'Your saved column layouts were applied to your own account.', 'brikpanel' );
		}
		if ( $file_ver > 0 && $file_ver < 2 ) {
			$lines[] = __( 'This file lists only the settings that had been saved on the source site. Anything it does not mention was left as it is here.', 'brikpanel' );
		} elseif ( '' !== $stale_raw ) {
			$stale = array_filter( array_map( 'trim', explode( ',', $stale_raw ) ) );
			$names = [];
			foreach ( $stale as $slug ) {
				$names[] = brikpanel_import_export_group_label( $slug, $sections );
			}
			if ( $names ) {
				$lines[] = sprintf(
					/* translators: %s: comma-separated list of settings section names. */
					__( 'This file was made by an older BrikPanel and does not include: %s. Those settings were left as they are here.', 'brikpanel' ),
					implode( ', ', $names )
				);
			}
		}
	}

	$status_messages = [
		'ok'         => [ 'type' => 'success', 'text' => implode( ' ', $lines ) ],
		'no_file'    => [ 'type' => 'error', 'text' => __( 'Pick a BrikPanel JSON file before clicking Import.', 'brikpanel' ) ],
		'too_large'  => [ 'type' => 'error', 'text' => __( 'That file is too large to be a BrikPanel settings export.', 'brikpanel' ) ],
		'unreadable' => [ 'type' => 'error', 'text' => __( 'Could not read the uploaded file. Please try again.', 'brikpanel' ) ],
		'invalid'    => [ 'type' => 'error', 'text' => __( 'That file is not a valid BrikPanel settings export.', 'brikpanel' ) ],
		'empty'      => [ 'type' => 'error', 'text' => __( 'The uploaded file did not contain any BrikPanel settings.', 'brikpanel' ) ],
		'too_new'    => [ 'type' => 'error', 'text' => __( 'That file was made by a newer BrikPanel. Update this site first, then import it.', 'brikpanel' ) ],
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
							/* translators: %s: comma-separated list of settings section names. */
							esc_html__( 'Download your BrikPanel configuration as a single JSON file, covering %s.', 'brikpanel' ),
							esc_html( implode( ', ', $labels ) )
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
				<?php esc_html_e( 'Your own column layouts travel with the file and are applied to the account that imports it. Connection keys, media library picks and anything holding a user or page ID from this site stay behind, so the brand logo has to be picked again after importing.', 'brikpanel' ); ?>
			</p>
		</section>

		<section class="brikpanel-iox__card">
			<header class="brikpanel-iox__card-head">
				<div>
					<h2 class="brikpanel-iox__card-title"><?php esc_html_e( 'Import settings', 'brikpanel' ); ?></h2>
					<p class="brikpanel-iox__card-desc">
						<?php esc_html_e( 'Upload a JSON file exported from BrikPanel. This site is made to match the one the file came from: settings it carries are overwritten, and settings the source site had left at their default are reset here too. Unknown keys are skipped.', 'brikpanel' ); ?>
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
