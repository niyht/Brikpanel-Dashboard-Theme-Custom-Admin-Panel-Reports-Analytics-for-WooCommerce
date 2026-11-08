<?php
/**
 * The country a visitor browses from (3.3.40).
 *
 * Looked up on the store's own server in the list that ships with the plugin
 * (includes/data/brikpanel-ip-countries-*.txt, built from DB-IP's free "IP to
 * Country Lite" list by tools/build-ip-countries.php before every release).
 * The address is never sent anywhere and never stored; only the two-letter
 * country code is kept. WooCommerce's own lookup is not used: unless the store
 * set up MaxMind, it sends the address to an outside service.
 *
 * Read once per visitor per day (the daily visitor count) and once per Live
 * row, never on a page load.
 *
 * @package BrikPanel
 * @since   3.3.40
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * An address as headers carry it, made plain: the first entry of a list,
 * without a port or the brackets around an IPv6 address.
 *
 * @param string $ip Raw address.
 * @return string Address, or '' when there is none.
 */
function brikpanel_ip_country_clean( $ip ) {
    $ip = trim( (string) $ip );
    if ( '' === $ip ) {
        return '';
    }
    $comma = strpos( $ip, ',' );
    if ( false !== $comma ) {
        $ip = trim( substr( $ip, 0, $comma ) );
    }
    if ( '[' === substr( $ip, 0, 1 ) ) {
        $close = strpos( $ip, ']' );
        $ip    = false === $close ? '' : substr( $ip, 1, $close - 1 );
    } elseif ( 1 === substr_count( $ip, ':' ) && false !== strpos( $ip, '.' ) ) {
        $ip = substr( $ip, 0, strpos( $ip, ':' ) ); // 203.0.113.9:443
    }
    return strtolower( $ip );
}

/**
 * The two-letter country code of an address, '' when unknown.
 *
 * Private, reserved and unparsable addresses are unknown. An IPv4 address
 * written the IPv6 way (::ffff:a.b.c.d) is looked up as IPv4.
 *
 * @param string $ip IPv4 or IPv6 address.
 * @return string Country code WooCommerce knows, or ''.
 */
function brikpanel_ip_country( $ip ) {
    static $memo = [];

    $ip = brikpanel_ip_country_clean( $ip );
    if ( '' === $ip ) {
        return '';
    }
    if ( isset( $memo[ $ip ] ) ) {
        return $memo[ $ip ];
    }
    if ( count( $memo ) > 64 ) {
        $memo = [];
    }

    // ::ffff:a.b.c.d first: the reserved-range check below refuses it as IPv6.
    if ( 0 === strpos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
        $ip = substr( $ip, 7 );
    }
    if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
        return $memo[ $ip ] = '';
    }
    $bin = @inet_pton( $ip );
    if ( false === $bin ) {
        return $memo[ $ip ] = '';
    }
    // Only 2000::/3 is used on the public internet (no multicast, no site-local).
    if ( 16 === strlen( $bin ) && 0x20 !== ( ord( $bin[0] ) & 0xE0 ) ) {
        return $memo[ $ip ] = '';
    }

    // IPv4 by its 32 bits, IPv6 by its first 32 bits (the list keeps /32 blocks).
    $key  = bin2hex( substr( $bin, 0, 4 ) );
    $file = 4 === strlen( $bin ) ? 'v4' : 'v6';

    return $memo[ $ip ] = brikpanel_ip_country_search( $file, $key );
}

/**
 * Binary search in one list file: the last line whose start is at or before
 * $key holds the answer. Every line is "xxxxxxxx CC\n" (12 bytes), so a line
 * is read by its position and the file is never loaded whole. A missing,
 * changed or damaged file answers '' without a warning.
 *
 * @param string $family 'v4' or 'v6'.
 * @param string $key    8 lowercase hex digits.
 * @return string Country code, or ''.
 */
function brikpanel_ip_country_search( $family, $key ) {
    static $handles = [];

    if ( ! array_key_exists( $family, $handles ) ) {
        $handles[ $family ] = false;
        $path               = __DIR__ . '/data/brikpanel-ip-countries-' . $family . '.txt';
        $size               = is_readable( $path ) ? (int) @filesize( $path ) : 0;
        if ( $size >= 12 && 0 === $size % 12 ) {
            $fh = @fopen( $path, 'rb' );
            if ( $fh ) {
                $handles[ $family ] = [ $fh, intdiv( $size, 12 ) ];
            }
        }
    }
    if ( false === $handles[ $family ] ) {
        return '';
    }
    list( $fh, $count ) = $handles[ $family ];

    $lo     = 0;
    $hi     = $count - 1;
    $answer = '';
    while ( $lo <= $hi ) {
        $mid = ( $lo + $hi ) >> 1;
        if ( 0 !== fseek( $fh, $mid * 12 ) ) {
            return '';
        }
        $line = fread( $fh, 12 );
        if ( ! is_string( $line ) || 1 !== preg_match( '/^([0-9a-f]{8}) ([A-Z]{2})\n$/', $line, $m ) ) {
            return ''; // Not the file this code was written for (a half-copied update, a line-ending change).
        }
        if ( strcmp( $m[1], $key ) <= 0 ) {
            $answer = $m[2];
            $lo     = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }

    return 'ZZ' === $answer ? '' : $answer;
}

/**
 * The country of the visitor making this request. The Live part and the daily
 * count of one tracker request both ask; the file is read once, because
 * brikpanel_ip_country() keeps its answers for the request.
 *
 * Proxy aware like the rest of the visitor checks (brikpanel_client_ip()).
 * While the cloud-address rule is paused because the store looks like it sits
 * behind a proxy that hides visitors' addresses (brikpanel_datacenter_note()),
 * the answer is '': every visitor would get the proxy's country.
 *
 * @return string Country code, or ''.
 */
function brikpanel_request_country() {
    $country = '';
    if ( (int) get_option( 'brikpanel_dc_paused_until', 0 ) > time() ) {
        return $country;
    }
    $ip = function_exists( 'brikpanel_client_ip' )
        ? brikpanel_client_ip()
        : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by brikpanel_ip_country().
    $found = brikpanel_ip_country( $ip );

    /**
     * Filters the country a visitor's request comes from.
     *
     * For a store behind a CDN that already tells the country (Cloudflare's
     * CF-IPCountry header, for example). Return a two-letter code, or '' when
     * unknown; anything else is ignored.
     *
     * @since 3.3.40
     *
     * @param string $found Country code from the plugin's list, or ''.
     * @param string $ip    The visitor's address as BrikPanel sees it.
     */
    $filtered = apply_filters( 'brikpanel_visitor_country', $found, $ip );
    $filtered = is_string( $filtered ) ? strtoupper( trim( $filtered ) ) : '';
    $country  = ( 1 === preg_match( '/^[A-Z]{2}$/', $filtered ) && 'ZZ' !== $filtered ) ? $filtered : '';

    return $country;
}
