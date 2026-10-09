<?php
/**
 * Cloud and hosting provider addresses (3.3.30).
 *
 * Scripted traffic runs from rented servers: price scrapers, AI agents,
 * headless browsers that fake a mouse. A person shopping almost never does.
 * So a browser whose address belongs to a big cloud or hosting provider is
 * not given the "this is a person" mark (includes/brikpanel-human-proof.php),
 * and nothing it does is counted. The same rule the privacy-first analytics
 * tools apply. The known cost: a shopper on a VPN that rents its exits from
 * one of these providers is not counted either.
 *
 * The ranges ship with the plugin (includes/data/, rebuilt before every
 * release by tools/build-datacenter-ranges.php) and are only read when a
 * browser asks for that mark, about once per visitor per day.
 *
 * @package BrikPanel
 * @since   3.3.30
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether an address belongs to one of the listed cloud or hosting providers.
 *
 * Private, loopback and unparsable addresses are never in the list.
 *
 * @param string $ip IPv4 or IPv6 address.
 * @return bool
 */
function brikpanel_ip_is_datacenter( $ip ) {
    static $sets = null;

    $bin = @inet_pton( trim( (string) $ip ) );
    if ( false === $bin ) {
        return false;
    }

    if ( null === $sets ) {
        $sets = [ 'v4' => [], 'v6' => [] ];
        $file = __DIR__ . '/data/brikpanel-datacenter-ranges.php';
        if ( is_readable( $file ) ) {
            $raw = include $file;
            if ( is_array( $raw ) ) {
                $sets['v4'] = isset( $raw['v4'] ) && is_array( $raw['v4'] ) ? $raw['v4'] : [];
                $sets['v6'] = isset( $raw['v6'] ) && is_array( $raw['v6'] ) ? $raw['v6'] : [];
            }
        }
    }

    // An IPv4 address written the IPv6 way (::ffff:a.b.c.d) is looked up as IPv4.
    if ( 16 === strlen( $bin ) && 0 === strncmp( $bin, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
        $bin = substr( $bin, 12 );
    }
    $set = 16 === strlen( $bin ) ? $sets['v6'] : $sets['v4'];

    // Sorted, non-overlapping "first last" pairs: binary search, turning only
    // the probed pairs into binary.
    $lo = 0;
    $hi = count( $set ) - 1;
    while ( $lo <= $hi ) {
        $mid  = ( $lo + $hi ) >> 1;
        $pair = explode( ' ', (string) $set[ $mid ], 2 );
        $from = @inet_pton( $pair[0] );
        $to   = isset( $pair[1] ) ? @inet_pton( $pair[1] ) : false;
        if ( false === $from || false === $to || strlen( $from ) !== strlen( $bin ) ) {
            return false; // A list this code cannot read proves nothing.
        }
        if ( strcmp( $bin, $from ) < 0 ) {
            $hi = $mid - 1;
        } elseif ( strcmp( $bin, $to ) > 0 ) {
            $lo = $mid + 1;
        } else {
            return true;
        }
    }

    return false;
}

/**
 * Whether the provider-address rule is in force for this request.
 *
 * Off for the rest of a week when it looked like the store sits behind a
 * proxy that hides visitors' addresses (see brikpanel_datacenter_note()).
 * Then every visitor arrives from the proxy's one address, which can be a
 * cloud address, and the rule would refuse everybody.
 *
 * @return bool
 */
function brikpanel_datacenter_rule_active() {
    $active = (int) get_option( 'brikpanel_dc_paused_until', 0 ) < time();

    /**
     * Filters whether visitors from cloud and hosting provider addresses are left out.
     *
     * @since 3.3.30
     *
     * @param bool $active True while the rule applies.
     */
    return (bool) apply_filters( 'brikpanel_datacenter_rule_active', $active );
}

/**
 * Remember how a "this is a person" request ended, to notice a store behind
 * an address-hiding proxy. On such a store every visitor arrives from the
 * same one or two provider addresses, many different browsers among them,
 * and not a single one gets through: a shape real traffic never has (real
 * shoppers come from homes and phones) and a lone scraper does not have
 * either (one address, one browser). Seeing it pauses the rule for seven
 * days, and the next week decides again.
 *
 * One small option, written only while the day's tally can still decide
 * something, so a flood costs a few dozen writes, not one per request.
 *
 * @param string $ip      Client address.
 * @param bool   $refused Whether the provider rule refused it.
 * @return void
 */
function brikpanel_datacenter_note( $ip, $refused ) {
    $today = wp_date( 'Y-m-d' );
    $stats = get_option( 'brikpanel_dc_stats', [] );
    if ( ! is_array( $stats ) || ( $stats['day'] ?? '' ) !== $today ) {
        $stats = [ 'day' => $today, 'ok' => 0, 'dc' => 0, 'ips' => [], 'fps' => [] ];
    }

    if ( ! $refused ) {
        if ( (int) $stats['ok'] > 0 ) {
            return; // One real visitor already proves the addresses are real.
        }
        $stats['ok'] = 1;
        update_option( 'brikpanel_dc_stats', $stats, false );
        return;
    }

    if ( (int) $stats['ok'] > 0 || (int) $stats['dc'] >= 30 ) {
        return; // Decided for today.
    }

    $salt = wp_salt( 'brikpanel_bot_gate' );
    $ips  = isset( $stats['ips'] ) && is_array( $stats['ips'] ) ? $stats['ips'] : [];
    $fps  = isset( $stats['fps'] ) && is_array( $stats['fps'] ) ? $stats['fps'] : [];
    if ( count( $ips ) < 3 ) {
        $ips[ substr( hash_hmac( 'sha256', (string) $ip, $salt ), 0, 12 ) ] = 1;
    }
    if ( count( $fps ) < 10 && function_exists( 'brikpanel_client_fingerprint' ) ) {
        $fps[ substr( hash_hmac( 'sha256', brikpanel_client_fingerprint(), $salt ), 0, 12 ) ] = 1;
    }
    $stats['dc']++;
    $stats['ips'] = $ips;
    $stats['fps'] = $fps;

    if ( (int) $stats['dc'] >= 30 && count( $ips ) <= 2 && count( $fps ) >= 10 ) {
        update_option( 'brikpanel_dc_paused_until', time() + WEEK_IN_SECONDS, false );
    }
    update_option( 'brikpanel_dc_stats', $stats, false );
}
