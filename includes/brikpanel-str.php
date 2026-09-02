<?php
/**
 * Multibyte-safe string helpers that never fatal.
 *
 * WHY THIS FILE EXISTS. `mbstring` is an OPTIONAL PHP extension. WordPress
 * treats it that way too — wp-includes/utf8.php picks its implementation with
 * `extension_loaded( 'mbstring' )` and ships a pure-PHP fallback for when the
 * extension is absent — and so does WooCommerce, whose Status screen lists
 * "Multibyte String" as a recommendation, not a requirement. A store therefore
 * runs perfectly well on a PHP build without it.
 *
 * BrikPanel did not. Most of its `mb_*` calls were wrapped in
 * `function_exists()`, but seven were not, and two of those sat in the term
 * checklists that draw the Category box in the product editor and the Quick
 * Edit drawer on the products list. Since every store has at least the
 * "Uncategorized" term, both screens hit `Call to undefined function
 * mb_strtolower()` on every single page load:
 *
 *   - Add/Edit product died right after the "Category" heading, so the store
 *     owner saw "There has been a critical error on this website" there.
 *   - The products list died inside the HIDDEN Quick Edit drawer, so the error
 *     text was never visible; the request simply never reached `admin_footer`,
 *     the list's JavaScript was never printed, and the server-rendered loading
 *     spinner spun forever with nothing to explain it.
 *
 * Reported on wp.org as "Products page infinite load" against 3.2.80.
 *
 * THE RULE THIS FILE ENCODES. No shipped BrikPanel file calls an `mb_*`
 * function directly, ever. Every call goes through a helper here, the
 * extension is probed exactly once per request, and each helper has a real
 * fallback. `tools/mbstring-audit.php` enforces this and fails the build on a
 * bare `mb_*` call anywhere else.
 *
 * @package BrikPanel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'brikpanel_has_mbstring' ) ) {
	/**
	 * Whether this PHP build can do multibyte string work.
	 *
	 * Probes the EXTENSION rather than a single function, matching how
	 * WordPress core decides the same question. A host that disables
	 * individual `mb_*` functions through `disable_functions` while leaving
	 * the extension loaded is not a configuration we can detect cheaply, and
	 * is not one that occurs in practice.
	 *
	 * @return bool
	 */
	function brikpanel_has_mbstring() {
		static $has = null;

		if ( null === $has ) {
			$has = extension_loaded( 'mbstring' ) && function_exists( 'mb_strtolower' );
		}

		return $has;
	}
}

if ( ! function_exists( 'brikpanel_latin_lowercase_map' ) ) {
	/**
	 * Uppercase => lowercase map for the Latin-1 Supplement and Latin
	 * Extended-A blocks, used only when mbstring is missing.
	 *
	 * `strtolower()` alone would leave "ÇOCUK", "GRÖSSE" or "ŁÓDŹ" untouched,
	 * which quietly breaks the Category / Tag search boxes: those compare a
	 * PHP-lowercased `data-name` attribute against a JavaScript-lowercased
	 * query, and JavaScript's `toLowerCase()` IS Unicode-aware. Generating the
	 * map from the two ranges' own rules keeps this to a few lines instead of
	 * a 300-entry literal, and covers Turkish (ÇĞİÖŞÜ), the Western European
	 * accents and the Central European set in one go.
	 *
	 * @return array<string,string>
	 */
	function brikpanel_latin_lowercase_map() {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map = array();

		// Latin-1 Supplement: U+00C0-U+00DE are uppercase, lowercase sits
		// 0x20 above. U+00D7 is the multiplication sign, not a letter.
		for ( $cp = 0xC0; $cp <= 0xDE; $cp++ ) {
			if ( 0xD7 === $cp ) {
				continue;
			}
			$map[ brikpanel_chr_utf8( $cp ) ] = brikpanel_chr_utf8( $cp + 0x20 );
		}

		// Latin Extended-A pairs each uppercase letter with the code point
		// directly above it, but the block is NOT one uninterrupted
		// even-then-odd rhythm: U+0138 (ĸ) and U+0149 (ŉ) are lowercase
		// letters with no uppercase partner, and each one flips the parity of
		// the run that follows it. Walking the three runs separately is what
		// keeps "ĸ" from being recorded as the lowercase form of "Ĺ".
		$runs = array(
			array( 0x100, 0x136 ), // Ā .. Ķ
			array( 0x139, 0x147 ), // Ĺ .. Ň
			array( 0x14A, 0x176 ), // Ŋ .. Ŷ
			array( 0x179, 0x17D ), // Ź .. Ž
		);
		foreach ( $runs as $run ) {
			for ( $cp = $run[0]; $cp <= $run[1]; $cp += 2 ) {
				$map[ brikpanel_chr_utf8( $cp ) ] = brikpanel_chr_utf8( $cp + 1 );
			}
		}

		$map[ brikpanel_chr_utf8( 0x178 ) ] = brikpanel_chr_utf8( 0xFF ); // Ÿ => ÿ

		// Cyrillic, because the Google Sheets status matcher explicitly depends
		// on folding "ЗАКАЗ" and ru_RU is one of the nine locales we ship.
		// U+0410-U+042F lowercase 0x20 above; U+0400-U+040F lowercase 0x50 above.
		for ( $cp = 0x410; $cp <= 0x42F; $cp++ ) {
			$map[ brikpanel_chr_utf8( $cp ) ] = brikpanel_chr_utf8( $cp + 0x20 );
		}
		for ( $cp = 0x400; $cp <= 0x40F; $cp++ ) {
			$map[ brikpanel_chr_utf8( $cp ) ] = brikpanel_chr_utf8( $cp + 0x50 );
		}

		// U+0130 (İ) is inside the first run, which would pair it with U+0131
		// (ı) — wrong. Unicode lowercases it to "i" plus a combining dot, and
		// that is also what the browser's toLowerCase() produces in the search
		// box this map is compared against, so overwrite the pair.
		$map[ brikpanel_chr_utf8( 0x130 ) ] = "i\xCC\x87";

		return $map;
	}
}

if ( ! function_exists( 'brikpanel_chr_utf8' ) ) {
	/**
	 * Code point => UTF-8 bytes, for the map above.
	 *
	 * Hand-rolled because `mb_chr()` is exactly the sort of thing this file
	 * exists to avoid, and `IntlChar` needs another optional extension.
	 * Only the two-byte range is ever asked for here.
	 *
	 * @param int $cp Unicode code point.
	 * @return string
	 */
	function brikpanel_chr_utf8( $cp ) {
		$cp = (int) $cp;

		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		return chr( 0xE0 | ( $cp >> 12 ) )
			. chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $cp & 0x3F ) );
	}
}

if ( ! function_exists( 'brikpanel_strtolower' ) ) {
	/**
	 * Lowercase a string, multibyte-aware where the host allows it.
	 *
	 * @param string $text Text to fold.
	 * @return string
	 */
	function brikpanel_strtolower( $text ) {
		$text = (string) $text;

		if ( '' === $text ) {
			return '';
		}

		if ( brikpanel_has_mbstring() ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtr( strtolower( $text ), brikpanel_latin_lowercase_map() );
	}
}

if ( ! function_exists( 'brikpanel_strlen' ) ) {
	/**
	 * Length in characters, falling back to bytes.
	 *
	 * The fallback over-counts multibyte text, which is the right way round
	 * for every caller here: they all use the result to decide whether to
	 * truncate, so an over-count truncates a little early instead of blowing
	 * past a hard limit imposed by someone else (an SEO field, a Google
	 * Sheets cell, a WhatsApp message body).
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	function brikpanel_strlen( $text ) {
		$text = (string) $text;

		return brikpanel_has_mbstring() ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}

if ( ! function_exists( 'brikpanel_substr' ) ) {
	/**
	 * Substring by characters, falling back to bytes.
	 *
	 * The byte fallback can land mid-character and leave a broken tail, so it
	 * trims any dangling UTF-8 continuation bytes off the end. Without that,
	 * a cut inside a two-byte character produces invalid UTF-8, which
	 * `esc_html()` then blanks out entirely.
	 *
	 * @param string   $text   Source text.
	 * @param int      $start  Start offset.
	 * @param int|null $length Length, or null for "to the end".
	 * @return string
	 */
	function brikpanel_substr( $text, $start, $length = null ) {
		$text  = (string) $text;
		$start = (int) $start;

		if ( brikpanel_has_mbstring() ) {
			return null === $length
				? mb_substr( $text, $start, null, 'UTF-8' )
				: mb_substr( $text, $start, (int) $length, 'UTF-8' );
		}

		$cut = null === $length ? substr( $text, $start ) : substr( $text, $start, (int) $length );

		if ( false === $cut || '' === $cut ) {
			return '';
		}

		// Drop a trailing partial sequence: continuation bytes are 10xxxxxx,
		// so walk back over them and then off the lead byte they belonged to
		// if that sequence is now incomplete.
		$len = strlen( $cut );
		$i   = $len - 1;
		while ( $i >= 0 && ( ord( $cut[ $i ] ) & 0xC0 ) === 0x80 ) {
			$i--;
		}
		if ( $i >= 0 ) {
			$lead     = ord( $cut[ $i ] );
			$expected = 1;
			if ( ( $lead & 0xE0 ) === 0xC0 ) {
				$expected = 2;
			} elseif ( ( $lead & 0xF0 ) === 0xE0 ) {
				$expected = 3;
			} elseif ( ( $lead & 0xF8 ) === 0xF0 ) {
				$expected = 4;
			}
			if ( $expected > 1 && ( $len - $i ) < $expected ) {
				$cut = substr( $cut, 0, $i );
			}
		}

		return (string) $cut;
	}
}

if ( ! function_exists( 'brikpanel_strrpos' ) ) {
	/**
	 * Last occurrence of a needle, in characters where possible.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @return int|false Offset, or false when not found.
	 */
	function brikpanel_strrpos( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;

		if ( '' === $haystack || '' === $needle ) {
			return false;
		}

		return brikpanel_has_mbstring()
			? mb_strrpos( $haystack, $needle, 0, 'UTF-8' )
			: strrpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'brikpanel_strimwidth' ) ) {
	/**
	 * Truncate to a width, appending a marker when something was cut.
	 *
	 * @param string $text   Source text.
	 * @param int    $start  Start offset.
	 * @param int    $width  Maximum width, marker included.
	 * @param string $marker Appended when the text was truncated.
	 * @return string
	 */
	function brikpanel_strimwidth( $text, $start, $width, $marker = '' ) {
		$text  = (string) $text;
		$width = (int) $width;

		if ( brikpanel_has_mbstring() ) {
			return mb_strimwidth( $text, (int) $start, $width, (string) $marker, 'UTF-8' );
		}

		$cut = brikpanel_substr( $text, $start );
		if ( brikpanel_strlen( $cut ) <= $width ) {
			return $cut;
		}

		$keep = $width - brikpanel_strlen( $marker );

		return brikpanel_substr( $cut, 0, max( 0, $keep ) ) . $marker;
	}
}

if ( ! function_exists( 'brikpanel_title_case' ) ) {
	/**
	 * Title-case a string, e.g. an attribute slug turned into a label.
	 *
	 * @param string $text Text to convert.
	 * @return string
	 */
	function brikpanel_title_case( $text ) {
		$text = (string) $text;

		return brikpanel_has_mbstring()
			? mb_convert_case( $text, MB_CASE_TITLE, 'UTF-8' )
			: ucwords( $text );
	}
}
