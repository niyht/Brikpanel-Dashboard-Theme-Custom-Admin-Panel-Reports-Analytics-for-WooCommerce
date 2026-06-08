<?php
/**
 * BrikPanel — Sheets proxy transport guard.
 *
 * Sheets only routes its OAuth handshake (start / redeem / refresh) through
 * brksoft.com; all Sheets/Drive API traffic goes straight to googleapis.com
 * with the user token. So this guard only wraps the OAuth endpoints: it
 * verifies the signed envelope and enforces the operator kill-switch (a signed
 * `revoke` / `revoke:sheets` directive wipes the local token vault so a
 * compromised brksoft.com can be cut off remotely).
 *
 * @package BrikPanel
 * @since   3.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brikpanel_Sheets_Proxy {

	/** Option latched when a signed revoke directive is received. */
	const KILLSWITCH_OPTION = 'brikpanel_gs_killswitch';

	/** True when the operator kill-switch has been tripped. */
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
	 * @param array|WP_Error $resp
	 * @param string         $context
	 * @return array{ok:bool, wp_error:bool, code:int, data:array, error:string}
	 */
	public static function open( $resp, $context = '' ) {
		if ( is_wp_error( $resp ) ) {
			return [ 'ok' => false, 'wp_error' => true, 'code' => 0, 'data' => [], 'error' => 'wp_error' ];
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		$env  = Brikpanel_Proxy_Envelope::open( $code, $body );

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
	 * Honour a signed kill-switch directive that targets Sheets.
	 *
	 * @param string $directive Normalised by Brikpanel_Proxy_Envelope.
	 * @param string $context
	 */
	private static function apply_directive( $directive, $context ) {
		if ( $directive !== 'revoke' && $directive !== 'revoke:sheets' ) {
			return; // ads-scoped directive — not ours.
		}

		Brikpanel_Sheets_Tokens::clear();
		update_option(
			self::KILLSWITCH_OPTION,
			wp_json_encode( [ 'at' => time(), 'directive' => $directive ] ),
			false
		);

		if ( class_exists( 'Brikpanel_Sheets_Logger' ) ) {
			Brikpanel_Sheets_Logger::log(
				'oauth',
				'Operator kill-switch received (' . $directive . ') via ' . $context . ' — local tokens wiped.'
			);
		}
	}
}
