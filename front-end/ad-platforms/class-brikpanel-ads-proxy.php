<?php
/**
 * BrikPanel — Ad Platforms proxy transport guard.
 *
 * Single choke-point every Ad Platforms proxy call passes through. It:
 *   - opens the signed response envelope (Brikpanel_Proxy_Envelope),
 *   - enforces the operator kill-switch (a signed `revoke` directive wipes the
 *     local token vault so a compromised brksoft.com can be cut off remotely),
 *   - exposes a local kill-switch latch so the plugin stops talking to the
 *     proxy entirely once revoked, until the merchant reconnects.
 *
 * @package BrikPanel
 * @since   3.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Ads_Proxy {

	/** Option latched when a signed revoke directive is received. */
	const KILLSWITCH_OPTION = 'brikpanel_ads_killswitch';

	/**
	 * True when the operator kill-switch has been tripped. Callers should
	 * refuse to start new proxy work while latched (the merchant must
	 * reconnect, which clears it).
	 *
	 * @return bool
	 */
	public static function is_killed() {
		return get_option( self::KILLSWITCH_OPTION, '' ) !== '';
	}

	/** Clear the latch — called from the OAuth success path on reconnect. */
	public static function clear_killswitch() {
		delete_option( self::KILLSWITCH_OPTION );
	}

	/**
	 * Open + verify a wp_remote_* response and apply any signed directive.
	 *
	 * @param array|WP_Error $resp     Result of wp_remote_request().
	 * @param string         $context  Short label for logs (e.g. 'google query').
	 * @return array{
	 *     ok:bool,        // true only on a fresh, correctly-signed 2xx envelope
	 *     wp_error:bool,  // true when the HTTP call itself failed
	 *     code:int,       // HTTP status (0 when wp_error)
	 *     data:array,     // verified payload (ok) or decoded error body (!ok)
	 *     error:string    // 'wp_error'|'http'|'unsigned'|'stale'|'bad_sig'|'malformed'|''
	 * }
	 */
	public static function open( $resp, $context = '' ) {
		if ( is_wp_error( $resp ) ) {
			return [
				'ok'       => false,
				'wp_error' => true,
				'code'     => 0,
				'data'     => [],
				'error'    => 'wp_error',
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		$env  = Brikpanel_Proxy_Envelope::open( $code, $body );

		// A directive is only ever present on a verified envelope, so this
		// cannot be forged by an attacker without the shared secret.
		if ( $env['ok'] && $env['directive'] !== '' ) {
			self::apply_directive( $env['directive'], $context );
		}

		return [
			'ok'       => (bool) $env['ok'],
			'wp_error' => false,
			'code'     => $code,
			'data'     => is_array( $env['data'] ) ? $env['data'] : [],
			'error'    => (string) $env['error'],
		];
	}

	/**
	 * Honour a signed kill-switch directive: wipe the affected vault(s) and
	 * latch so we stop calling the proxy until the merchant reconnects.
	 *
	 * @param string $directive Normalised by Brikpanel_Proxy_Envelope.
	 * @param string $context
	 */
	private static function apply_directive( $directive, $context ) {
		$targets = [];
		if ( $directive === 'revoke' ) {
			$targets = [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE, Brikpanel_Ads_Tokens::PLATFORM_META ];
		} elseif ( $directive === 'revoke:google_ads' ) {
			$targets = [ Brikpanel_Ads_Tokens::PLATFORM_GOOGLE ];
		} elseif ( $directive === 'revoke:meta_ads' ) {
			$targets = [ Brikpanel_Ads_Tokens::PLATFORM_META ];
		} else {
			return; // 'revoke:sheets' is not ours.
		}

		foreach ( $targets as $platform ) {
			Brikpanel_Ads_Tokens::disconnect( $platform );
		}

		update_option(
			self::KILLSWITCH_OPTION,
			wp_json_encode( [ 'at' => time(), 'directive' => $directive ] ),
			false
		);

		if ( class_exists( 'Brikpanel_Ads_Logger' ) ) {
			Brikpanel_Ads_Logger::log(
				'oauth',
				'Operator kill-switch received (' . $directive . ') via ' . $context . ' — local tokens wiped.'
			);
		}
	}
}
