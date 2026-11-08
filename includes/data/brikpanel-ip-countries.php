<?php
/**
 * Visitor country list (generated, do not edit).
 *
 * Built 2026-11-03 by tools/build-ip-countries.php from DB-IP's free "IP to
 * Country Lite" database, 2026-11 edition (CC BY 4.0, https://db-ip.com).
 * The two sorted text files beside this one hold the list:
 *
 *   brikpanel-ip-countries-v4.txt  one line per IPv4 range: its first address
 *                                  as 8 hex digits, a space, the country code
 *   brikpanel-ip-countries-v6.txt  one line per run of IPv6 /32 blocks: the
 *                                  block's first 32 bits as 8 hex digits, a
 *                                  space, the country most of the block holds
 *
 * A line holds until the next one starts. Every line is 12 bytes, so the
 * lookup reads a line by its position instead of loading the file. "ZZ"
 * means no country (private, reserved or unassigned addresses).
 * Read by brikpanel_ip_country().
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'built'   => '2026-11-03',
	'source'  => 'DB-IP IP to Country Lite 2026-11',
	'license' => 'CC BY 4.0',
	'sha256'  => 'e5c8459d638a272ece7f4d7cfa49dce18d91b4f403f6940ba9249bf0db3781fd',
	'v4'      => 362998,
	'v6'      => 91219,
];
