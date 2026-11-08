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

/*
 * NAMES AS PLAIN TEXT.
 *
 * WordPress stores many names HTML-encoded: product titles saved through the
 * WooCommerce REST API or by a user without `unfiltered_html` keep `&` as
 * `&amp;`, term names, display names and gateway titles are always stored
 * encoded, get_the_title() adds `&#8211;`/`&#8217;`, and order item names can
 * carry markup (TranslatePress wraps the variation separator in a <span>).
 * Core screens print them with esc_html(), which does not double-encode, so
 * they look right there. JSON for our screens, CSV files and Sheets cells need
 * the plain text, or "One &amp; One" reaches the merchant (wp.org report).
 * Every JS consumer escapes on insert, so the decoded text stays inert.
 */

if ( ! function_exists( 'brikpanel_plain_name' ) ) {
	/**
	 * Decode a stored name (product title, term name, display name) to plain text.
	 *
	 * @param string $text Name as stored.
	 * @return string
	 */
	function brikpanel_plain_name( $text ) {
		$text = (string) $text;
		if ( false === strpos( $text, '&' ) ) {
			return $text;
		}

		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}

if ( ! function_exists( 'brikpanel_plain_label' ) ) {
	/**
	 * Plain text for a name that may also carry markup (order item names,
	 * variation titles, get_the_title(), get_formatted_name()).
	 *
	 * Tags go before the entities are decoded, otherwise a title that reads
	 * "Size &lt;5cm" would decode to "<5cm" and the strip would eat it. A lone
	 * "<" typed into a title ("I <3 NY") is kept, and each tag boundary becomes
	 * a space so "Tee (SKU)<span>Color: Red</span>" does not glue two words.
	 *
	 * @param string $html Name as stored or rendered.
	 * @return string
	 */
	function brikpanel_plain_label( $html ) {
		$text = (string) $html;
		if ( false === strpbrk( $text, '&<' ) ) {
			return trim( $text );
		}

		if ( false !== strpos( $text, '<' ) ) {
			$text = wp_pre_kses_less_than( $text );
			$text = wp_strip_all_tags( str_replace( '<', ' <', $text ) );
			$text = preg_replace( '/[ \t\r\n]{2,}/', ' ', $text );
		}

		return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}

if ( ! function_exists( 'brikpanel_product_label' ) ) {
	/**
	 * A product's name as plain text for a list that has its own SKU column
	 * or line: the name, plus for a variation the options its title does not
	 * already carry. Never the SKU or ID.
	 *
	 * WooCommerce's get_formatted_name() appends "(SKU)" (or "(#123)"), so the
	 * dashboard's Low stock table read "Notebook 6 - White, S (BPT-V-0006-wh-s)"
	 * next to a SKU column with the same code (field test E3). A variation's
	 * title holds up to two option values ("Notebook 6 - White, S"); with more
	 * options it is just the parent's name and the options follow here.
	 *
	 * @param WC_Product|false|null $product Product or variation.
	 * @return string
	 */
	function brikpanel_product_label( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$name = brikpanel_plain_label( $product->get_name() );
		if ( $product->is_type( 'variation' ) ) {
			// Flat, values only, skipping the options already in the title.
			$extra = brikpanel_plain_label( brikpanel_formatted_variation( $product, false, true ) );
			if ( '' !== $extra ) {
				$sep  = (string) apply_filters( 'woocommerce_product_variation_title_attributes_separator', ' - ', $product );
				$name = ( '' !== $name ? $name . $sep : '' ) . $extra;
			}
		}
		return $name;
	}
}

if ( ! function_exists( 'brikpanel_term_ref' ) ) {
	/**
	 * A term name the browser will send back to be matched against the stored
	 * term (product editor attribute values and tags), or a CSV term cell.
	 *
	 * Only `&` and quotes are decoded. `<` and `>` stay encoded because the save
	 * path runs sanitize_text_field(), which would strip "<XL>" from a value the
	 * merchant never touched, and WooCommerce's CSV importer reads ">" as a
	 * category hierarchy. Rarer entities (`&eacute;`) are left alone so the
	 * lookup by name still finds the same term.
	 *
	 * @param string $name Term name as stored.
	 * @return string
	 */
	function brikpanel_term_ref( $name ) {
		$name = (string) $name;
		if ( false === strpos( $name, '&' ) ) {
			return $name;
		}

		return str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), wp_specialchars_decode( $name, ENT_QUOTES ) );
	}
}

/*
 * Product attribute keys.
 *
 * WooCommerce keys an attribute everywhere by sanitize_title() of its name:
 * the parent's `_product_attributes`, each variation's `attribute_<key>` meta
 * and `_default_attributes` (WC_Product::set_attributes(),
 * WC_Meta_Box_Product_Data::prepare_set_attributes()). Outside ASCII that key
 * is percent-encoded: the taxonomy `pa_вид` is keyed `pa_%d0%b2%d0%b8%d0%b4`,
 * a custom "Размер" `%d1%80%d0%b0%d0%b7%d0%bc%d0%b5%d1%80`. The product editor
 * compared raw taxonomy names with those keys and ran sanitize_text_field()
 * (which deletes every `%xx` octet) over them, and every save erased such an
 * attribute's values (support ticket, Bulgarian stores, 2026-10-14).
 */

if ( ! function_exists( 'brikpanel_attr_key' ) ) {
	/**
	 * WooCommerce's own key for a product attribute name, taxonomy name or
	 * stored key.
	 *
	 * It is sanitize_title() as this site runs it, so a locale's accent rules
	 * (de_DE "Größe") and a transliteration plugin hooked on sanitize_title
	 * give the answer WooCommerce gets. An already encoded key comes back as
	 * it is. Not cached: a filter added later in the request must still count.
	 *
	 * @param string $name Attribute name, taxonomy name or stored key.
	 * @return string
	 */
	function brikpanel_attr_key( $name ) {
		$name = is_scalar( $name ) ? (string) $name : '';

		return '' === $name ? '' : sanitize_title( $name );
	}
}

if ( ! function_exists( 'brikpanel_attr_key_clean' ) ) {
	/**
	 * A posted attribute key, made safe without changing what it means.
	 *
	 * A key that already has WooCommerce's shape (lowercase letters, digits,
	 * `%`, `_`, `-`) is kept as it is, so an encoded key survives; anything
	 * else (a raw taxonomy name such as `pa_вид`, a label) is turned into
	 * WooCommerce's key. sanitize_key() and sanitize_text_field() must never
	 * be used here: the first drops `%`, the second deletes `%xx` octets.
	 *
	 * @param mixed $raw Posted key.
	 * @return string
	 */
	function brikpanel_attr_key_clean( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw || strlen( $raw ) > 600 ) {
			return '';
		}

		if ( preg_match( '/^[A-Za-z0-9%_-]+$/', $raw ) ) {
			return strtolower( $raw );
		}

		return brikpanel_attr_key( $raw );
	}
}

if ( ! function_exists( 'brikpanel_attr_value_clean' ) ) {
	/**
	 * A posted attribute value (a stored term slug or a custom value) for a
	 * comparison against stored data.
	 *
	 * What sanitize_text_field() does, minus deleting `%xx` octets: a term slug
	 * outside ASCII is stored percent-encoded and must come through intact.
	 *
	 * @param mixed $raw Posted value.
	 * @return string
	 */
	function brikpanel_attr_value_clean( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$value = wp_check_invalid_utf8( (string) $raw );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

		return trim( (string) $value );
	}
}

if ( ! function_exists( 'brikpanel_attr_taxonomy_from_key' ) ) {
	/**
	 * The attribute taxonomy behind a WooCommerce attribute key, or '' for a
	 * custom attribute.
	 *
	 * The key of a non-ASCII taxonomy is its encoded name, so taxonomy_exists()
	 * fails on it; WooCommerce decodes it first (WC_Product_Data_Store_CPT).
	 *
	 * @param string $key Attribute key, with or without the `attribute_` prefix.
	 * @return string
	 */
	function brikpanel_attr_taxonomy_from_key( $key ) {
		$key = is_scalar( $key ) ? (string) $key : '';
		if ( 0 === strpos( $key, 'attribute_' ) ) {
			$key = substr( $key, 10 );
		}
		if ( 0 !== strpos( $key, 'pa_' ) ) {
			return '';
		}
		if ( taxonomy_exists( $key ) ) {
			return $key;
		}

		$decoded = urldecode( $key );

		return ( $decoded !== $key && taxonomy_exists( $decoded ) ) ? $decoded : '';
	}
}

if ( ! function_exists( 'brikpanel_attr_pairs' ) ) {
	/**
	 * A variation's options as WooCommerce means to show them: each
	 * attribute's label and the chosen option's name, in the variation's order.
	 *
	 * WooCommerce's readers look a taxonomy up by the key, which is encoded
	 * outside ASCII: wc_get_formatted_variation() printed a global attribute
	 * "Вид" as "pa_вид: спортен" (its term slug, not "Спортен"), and
	 * wc_attribute_label() without the product returns the encoded key
	 * ("%d1%80…"). Here the taxonomy is found from the key first.
	 *
	 * @param WC_Product|array $variation A variation, or a variation attribute
	 *                                    map as a cart item or
	 *                                    get_variation_attributes() holds it
	 *                                    (the `attribute_` prefix is optional).
	 * @param WC_Product|null  $product   With a map: the variation or its
	 *                                    parent, so a custom attribute gets the
	 *                                    name the merchant typed.
	 * @param bool             $with_labels False when only the options are
	 *                                    shown: `label` stays '' and nothing is
	 *                                    looked up for it (a custom attribute's
	 *                                    label loads the variation's parent).
	 * @return array<int,array{key:string,taxonomy:string,label:string,value:string,slug:string}>
	 *         Names as stored (entities kept, like WooCommerce's). `value` is ''
	 *         for an "Any …" option; `slug` is the stored value.
	 */
	function brikpanel_attr_pairs( $variation, $product = null, $with_labels = true ) {
		if ( $variation instanceof WC_Product ) {
			$product    = $variation;
			$attributes = $variation->is_type( 'variation' ) ? (array) $variation->get_attributes() : array();
		} else {
			$attributes = array();
			foreach ( (array) $variation as $key => $value ) {
				$key = (string) $key;
				$attributes[ 0 === strpos( $key, 'attribute_' ) ? substr( $key, 10 ) : $key ] = $value;
			}
		}
		$product = $product instanceof WC_Product ? $product : '';

		$pairs = array();
		foreach ( $attributes as $key => $stored ) {
			if ( ! is_scalar( $stored ) ) {
				continue;
			}
			$key      = (string) $key;
			$stored   = (string) $stored;
			$value    = $stored;
			$taxonomy = brikpanel_attr_taxonomy_from_key( $key );
			$label    = '';
			if ( '' !== $taxonomy ) {
				if ( '' !== $value ) {
					$term = get_term_by( 'slug', $value, $taxonomy );
					if ( $term && ! is_wp_error( $term ) && '' !== (string) $term->name ) {
						$value = (string) $term->name;
					}
				}
				if ( $with_labels ) {
					$label = (string) wc_attribute_label( $taxonomy, $product );
				}
			} elseif ( $with_labels ) {
				$label = (string) wc_attribute_label( $key, $product );
			}
			// No product to read a custom attribute's name from: the key is
			// the closest thing, readable rather than encoded.
			if ( preg_match( '/%[0-9a-f]{2}/i', $label ) ) {
				$label = rawurldecode( $label );
			}

			$pairs[] = array(
				'key'      => $key,
				'taxonomy' => $taxonomy,
				'label'    => $label,
				'value'    => rawurldecode( $value ),
				'slug'     => $stored,
			);
		}

		return $pairs;
	}
}

if ( ! function_exists( 'brikpanel_attr_in_name' ) ) {
	/**
	 * Whether a variation's name already carries an option, so a list does
	 * not repeat it.
	 *
	 * wc_is_attribute_in_product_name() folds case only in ASCII and expects a
	 * space before the option, which the "<span> - </span>" separator some
	 * plugins put into titles takes away. For an encoded taxonomy WooCommerce
	 * also wrote the decoded term slug into the title ("спортен" for
	 * "Спортен"), and order items sold before BrikPanel corrected it
	 * (includes/brikpanel-variation-title.php) keep that name, so that
	 * spelling counts too.
	 *
	 * @param string $value Option name as shown.
	 * @param string $name  Variation or line item name.
	 * @param string $slug  The stored value (a term slug), if any.
	 * @return bool
	 */
	function brikpanel_attr_in_name( $value, $name, $slug = '' ) {
		$value = (string) $value;
		$name  = (string) $name;
		if ( '' === $value || '' === $name ) {
			return false;
		}
		if ( function_exists( 'wc_is_attribute_in_product_name' ) && wc_is_attribute_in_product_name( $value, $name ) ) {
			return true;
		}

		// A Latin slug never reaches a title, and could match another word.
		$options = array( brikpanel_plain_name( $value ) );
		if ( preg_match( '/%[0-9a-f]{2}|[^\x00-\x7F]/i', (string) $slug ) ) {
			$options[] = rawurldecode( (string) $slug );
		}
		$folded = brikpanel_strtolower( brikpanel_plain_label( $name ) );
		foreach ( $options as $option ) {
			$option = trim( $option );
			if ( '' === $option ) {
				continue;
			}
			$needle = ' ' . brikpanel_strtolower( $option );
			if ( false !== strpos( $folded, $needle . ',' ) || substr( $folded, -strlen( $needle ) ) === $needle ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'brikpanel_formatted_variation' ) ) {
	/**
	 * wc_get_formatted_variation( $variation, true, … ) that also reads
	 * attributes outside ASCII: one line of "Label: Option, …", or the options
	 * alone.
	 *
	 * The same text as WooCommerce's for every Latin attribute; for the global
	 * attribute "Вид" it reads "Вид: Спортен" where WooCommerce printed
	 * "pa_вид: спортен" (see brikpanel_attr_pairs()). The options alone also
	 * name every variation (includes/brikpanel-variation-title.php), so
	 * without labels nothing is looked up for them.
	 *
	 * @param WC_Product $variation     Variation.
	 * @param bool       $include_names Put each attribute's label before its option.
	 * @param bool       $skip_in_name  Leave out the options the name already carries.
	 * @return string As stored (entities kept); '' for anything but a variation.
	 */
	function brikpanel_formatted_variation( $variation, $include_names = true, $skip_in_name = false ) {
		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
			return '';
		}

		$name = $skip_in_name ? (string) $variation->get_name() : '';
		$list = array();
		foreach ( brikpanel_attr_pairs( $variation, null, $include_names ) as $pair ) {
			if ( '' === $pair['value'] || ( $skip_in_name && brikpanel_attr_in_name( $pair['value'], $name, $pair['slug'] ) ) ) {
				continue;
			}
			$list[] = $include_names ? $pair['label'] . ': ' . $pair['value'] : $pair['value'];
		}

		return implode( ', ', $list );
	}
}
