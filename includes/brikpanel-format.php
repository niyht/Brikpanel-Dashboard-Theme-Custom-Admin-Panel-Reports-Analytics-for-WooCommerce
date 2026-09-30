<?php
/**
 * BrikPanel: one place that formats numbers, percentages, money and dates.
 *
 * Field test E2 and E9 (22 Sep 2026): thirteen screens formatted numbers and
 * dates in the browser with their own helper, most of them with the browser's
 * language (`toLocaleString()` with no locale), so an English store opened on
 * a Turkish computer showed "22 Eyl 2026". Percent signs were glued after the
 * number in code, so Turkish read "0% gelirin" instead of "%0". PHP itself used
 * three rules for the decimal mark.
 *
 * The rules now, the same on the server and in the browser:
 * - Numbers and percentages use the store's WooCommerce separators, like the
 *   prices beside them (the owner's decision, 27 Sep 2026).
 * - Where the percent sign goes comes from the translation of "%s%%"
 *   (Turkish "%%%s", German and French "%s %%" with a no-break space).
 * - Money follows the WooCommerce price settings (wc_price / money()).
 * - Dates use the store's date and time formats with month and day names in
 *   the viewer's language, declined like wp_date() does.
 *
 * brikpanel_format_l10n() hands the same settings to
 * front-end/shared/brikpanel-format.js (window.brikpanelFormat), so text built
 * in the browser matches text built here character for character.
 *
 * @package BrikPanel
 * @since   3.3.25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'brikpanel_safe_sprintf' ) ) {
	/**
	 * sprintf() for a translated format string that must not crash.
	 *
	 * Translations are community-edited: a translator who drops a placeholder
	 * or leaves a bare "%" makes PHP 8 throw (ValueError / ArgumentCountError)
	 * and PHP 7.4 warn and return false. Inside an Action Scheduler worker
	 * that throw aborted a whole Store Health scan for every user of the
	 * language (tr_TR "%30", June 2026). This degrades to the sentence without
	 * its numbers instead.
	 *
	 * @param string $format Translated format.
	 * @param mixed  ...$args Values.
	 * @return string
	 */
	function brikpanel_safe_sprintf( $format, ...$args ) {
		$format = (string) $format;
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- PHP 7.4 warns before returning false.
			$out = @vsprintf( $format, $args );
			if ( false !== $out ) {
				return $out;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$stripped = preg_replace( '/%(?:\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', '', $format );
		return trim( str_replace( '%%', '%', (string) $stripped ) );
	}
}

if ( ! function_exists( 'brikpanel_number_separators' ) ) {
	/**
	 * The store's thousands and decimal separators (WooCommerce price
	 * settings), used for every number BrikPanel shows. Memoized per blog.
	 *
	 * @return array{decimal:string, thousands:string}
	 */
	function brikpanel_number_separators() {
		static $cache = array();
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( isset( $cache[ $blog ] ) ) {
			return $cache[ $blog ];
		}
		if ( function_exists( 'wc_get_price_decimal_separator' ) ) {
			$decimal   = (string) wc_get_price_decimal_separator();
			$thousands = (string) wc_get_price_thousand_separator();
		} else {
			// No WooCommerce (network admin, a store mid-update): the language's own marks.
			global $wp_locale;
			$decimal   = isset( $wp_locale->number_format['decimal_point'] ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
			$thousands = isset( $wp_locale->number_format['thousands_sep'] ) ? (string) $wp_locale->number_format['thousands_sep'] : ',';
		}
		// fr_FR stores "&nbsp;"; an entity would be printed as text by textContent.
		$decimal   = html_entity_decode( $decimal, ENT_QUOTES, 'UTF-8' );
		$thousands = html_entity_decode( $thousands, ENT_QUOTES, 'UTF-8' );
		$cache[ $blog ] = array(
			'decimal'   => '' !== $decimal ? $decimal : '.',
			'thousands' => $thousands,
		);
		return $cache[ $blog ];
	}
}

if ( ! function_exists( 'brikpanel_number' ) ) {
	/**
	 * A number with the store's separators: 1,234.5 or 1.234,5.
	 *
	 * @param int|float|string $number   Value.
	 * @param int              $decimals Digits after the decimal mark.
	 * @param bool             $trim     Drop trailing zeros ("12.50" becomes "12.5", "3.00" becomes "3").
	 * @return string
	 */
	function brikpanel_number( $number, $decimals = 0, $trim = false ) {
		$sep      = brikpanel_number_separators();
		$decimals = max( 0, (int) $decimals );
		$value    = is_numeric( $number ) ? (float) $number : 0.0;
		$out      = number_format( $value, $decimals, $sep['decimal'], $sep['thousands'] );
		if ( $trim && $decimals > 0 ) {
			$out = rtrim( rtrim( $out, '0' ), $sep['decimal'] );
		}
		// number_format() keeps the sign of a value that rounds to zero: "-0".
		if ( preg_match( '/^-0(?:\D0*)?$/', $out ) ) {
			$out = substr( $out, 1 );
		}
		return $out;
	}
}

if ( ! function_exists( 'brikpanel_plural_n' ) ) {
	/**
	 * The number to hand _n() for a value shown with decimals. gettext plural
	 * rules only know whole numbers: a whole shown value is itself, anything
	 * else ("1.5 days") takes the form of 2, which is the plural in English
	 * and the form Russian and Polish use after a fraction.
	 *
	 * @param int|float $value    Value.
	 * @param int       $decimals Digits it is shown with.
	 * @return int
	 */
	function brikpanel_plural_n( $value, $decimals = 0 ) {
		$shown = round( abs( (float) $value ), max( 0, (int) $decimals ) );
		return floor( $shown ) == $shown ? (int) $shown : 2; // phpcs:ignore Universal.Operators.StrictComparisons -- float equality after round() is exact here.
	}
}

if ( ! function_exists( 'brikpanel_plural_table' ) ) {
	/**
	 * Which plural form the viewer's language uses for 0 to 199, from the
	 * brikpanel translation file's own Plural-Forms rule (the rule _n() uses).
	 * Every gettext rule in use depends on n below 100 and on n % 100 above
	 * it, so the browser reads index 100 + n % 100 for anything larger.
	 *
	 * @return int[]
	 */
	function brikpanel_plural_table() {
		static $cache = array();
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( isset( $cache[ $locale ] ) ) {
			return $cache[ $locale ];
		}

		$expression   = 'n != 1';
		$translations = get_translations_for_domain( 'brikpanel' );
		$headers      = is_object( $translations ) ? (array) $translations->headers : array();
		foreach ( $headers as $name => $value ) {
			if ( 'plural-forms' === strtolower( (string) $name )
				&& preg_match( '/^\s*nplurals\s*=\s*\d+\s*;\s*plural\s*=\s*(.+)$/', (string) $value, $m ) ) {
				$expression = rtrim( trim( $m[1] ), ';' );
				break;
			}
		}

		$table = array();
		try {
			$rule = new Plural_Forms( $expression );
			for ( $n = 0; $n < 200; $n++ ) {
				$table[] = (int) $rule->get( $n );
			}
		} catch ( \Throwable $e ) {
			$table = array();
			for ( $n = 0; $n < 200; $n++ ) {
				$table[] = 1 === $n ? 0 : 1;
			}
		}

		$cache[ $locale ] = $table;
		return $table;
	}
}

if ( ! function_exists( 'brikpanel_js_plural' ) ) {
	/**
	 * A plural message for text the browser counts itself ("3 selected"):
	 * every form of the translation, for brikpanelFormat.plural() / count()
	 * to pick from with the same rule as _n().
	 *
	 * Pass the result of _n_noop() so the string is extracted for
	 * translation: brikpanel_js_plural( _n_noop( '%s value', '%s values', 'brikpanel' ) ).
	 *
	 * @param array $nooped _n_noop() result.
	 * @return array{forms: string[], en: bool} `en`: not translated yet, the
	 *                                          English rule applies.
	 */
	function brikpanel_js_plural( $nooped ) {
		$singular = isset( $nooped['singular'] ) ? (string) $nooped['singular'] : '';
		$plural   = isset( $nooped['plural'] ) ? (string) $nooped['plural'] : $singular;

		// The smallest number for each form of this language.
		$sample = array();
		foreach ( brikpanel_plural_table() as $n => $index ) {
			if ( ! isset( $sample[ $index ] ) ) {
				$sample[ $index ] = $n;
			}
		}
		ksort( $sample );

		$forms   = array();
		$english = true;
		foreach ( $sample as $index => $n ) {
			$forms[ $index ] = translate_nooped_plural( $nooped, $n );
			if ( $forms[ $index ] !== ( 1 === $n ? $singular : $plural ) ) {
				$english = false;
			}
		}

		// Untranslated: _n() falls back to English, one and other.
		if ( $english ) {
			return array(
				'forms' => array( $singular, $plural ),
				'en'    => true,
			);
		}

		$list = array();
		$last = empty( $forms ) ? 0 : max( array_keys( $forms ) );
		for ( $i = 0; $i <= $last; $i++ ) {
			$list[] = isset( $forms[ $i ] ) ? $forms[ $i ] : $plural;
		}
		return array(
			'forms' => $list,
			'en'    => false,
		);
	}
}

if ( ! function_exists( 'brikpanel_percent_pattern' ) ) {
	/**
	 * Where the percent sign goes, from the translation: "%s%%" in English,
	 * "%%%s" in Turkish, "%s %%" (no-break space) in German and French.
	 *
	 * @return string
	 */
	function brikpanel_percent_pattern() {
		/* translators: A percentage, e.g. 25%. %s is the number, already formatted. Write the percent sign (%%) where your language puts it: "%%%s" in Turkish, "%s %%" with a no-break space in German and French. */
		$pattern = _x( '%s%%', 'percentage value', 'brikpanel' );
		// A translation that lost the number would show a bare sign.
		return false === strpos( $pattern, '%s' ) ? '%s%%' : $pattern;
	}
}

if ( ! function_exists( 'brikpanel_fill_pattern' ) ) {
	/**
	 * Fill a one-placeholder pattern: "%s" becomes the value, "%%" a literal
	 * percent sign. Deliberately not sprintf(): the pattern is a translation.
	 *
	 * @param string $pattern Pattern with one %s.
	 * @param string $value   Replacement.
	 * @return string
	 */
	function brikpanel_fill_pattern( $pattern, $value ) {
		$out = '';
		$len = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '%' === $c && $i + 1 < $len ) {
				$n = $pattern[ $i + 1 ];
				if ( '%' === $n ) {
					$out .= '%';
					$i++;
					continue;
				}
				if ( 's' === $n ) {
					$out .= $value;
					$i++;
					continue;
				}
			}
			$out .= $c;
		}
		return $out;
	}
}

if ( ! function_exists( 'brikpanel_percent' ) ) {
	/**
	 * A percentage in the viewer's language with the store's separators:
	 * "12.5%", "%12,5", "12,5 %".
	 *
	 * @param int|float $value    Percent value (12.5 means 12.5%).
	 * @param int       $decimals Digits after the decimal mark.
	 * @param bool      $trim     Drop trailing zeros (the default: "50%" rather than "50.0%").
	 * @return string
	 */
	function brikpanel_percent( $value, $decimals = 1, $trim = true ) {
		$num = brikpanel_number( $value, $decimals, $trim );
		// The minus stays in front of the whole value: "-%3" in Turkish, not "%-3".
		if ( '' !== $num && '-' === $num[0] ) {
			return '-' . brikpanel_fill_pattern( brikpanel_percent_pattern(), substr( $num, 1 ) );
		}
		return brikpanel_fill_pattern( brikpanel_percent_pattern(), $num );
	}
}

if ( ! function_exists( 'brikpanel_day_month_format' ) ) {
	/**
	 * A short "day and month" date format (chart axes, compact lists), per
	 * language: "M j" in English, "j M" in Turkish.
	 *
	 * @return string PHP date format.
	 */
	function brikpanel_day_month_format() {
		/* translators: Short date format with day and month only, for chart axes. See https://www.php.net/manual/datetime.format.php */
		return _x( 'M j', 'short day and month date format', 'brikpanel' );
	}
}

if ( ! function_exists( 'brikpanel_month_year_format' ) ) {
	/**
	 * A short "month and year" date format (cohort rows, monthly tables).
	 *
	 * @return string PHP date format.
	 */
	function brikpanel_month_year_format() {
		/* translators: Short date format with month and year only, e.g. "Sep 2026". See https://www.php.net/manual/datetime.format.php */
		return _x( 'M Y', 'short month and year date format', 'brikpanel' );
	}
}

if ( ! function_exists( 'brikpanel_locale_tag' ) ) {
	/**
	 * The viewer's language as a BCP 47 tag the browser accepts ("tr-TR").
	 * Only language and region: WordPress variants like "pt_PT_ao90" or
	 * "de_DE_formal" make Intl throw.
	 *
	 * @return string
	 */
	function brikpanel_locale_tag() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$parts  = explode( '_', (string) $locale );
		$tag    = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && preg_match( '/^[A-Za-z]{2}$|^\d{3}$/', $parts[1] ) ) {
			$tag .= '-' . strtoupper( $parts[1] );
		}
		return preg_match( '/^[a-z]{2,3}(-[A-Z0-9]{2,3})?$/', $tag ) ? $tag : 'en-US';
	}
}

if ( ! function_exists( 'brikpanel_format_l10n' ) ) {
	/**
	 * Everything front-end/shared/brikpanel-format.js needs to format like
	 * this file does. Built once per request and language.
	 *
	 * @return array
	 */
	function brikpanel_format_l10n() {
		static $cache = array();
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$key    = $locale . '|' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		global $wp_locale;
		$sep = brikpanel_number_separators();

		$money = array(
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			'format'   => function_exists( 'get_woocommerce_price_format' ) ? html_entity_decode( (string) get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ) : '%1$s%2$s',
		);

		$names = array(
			'months'        => array(),
			'monthsShort'   => array(),
			'monthsGenitive' => array(),
			'weekdays'      => array(),
			'weekdaysShort' => array(),
			'weekdaysMin'   => array(),
			'meridiem'      => array(
				'am' => 'am',
				'pm' => 'pm',
				'AM' => 'AM',
				'PM' => 'PM',
			),
		);
		if ( $wp_locale instanceof WP_Locale ) {
			for ( $m = 1; $m <= 12; $m++ ) {
				$full                     = $wp_locale->get_month( $m );
				$names['months'][]        = $full;
				$names['monthsShort'][]   = $wp_locale->get_month_abbrev( $full );
				$two                      = zeroise( $m, 2 );
				$names['monthsGenitive'][] = isset( $wp_locale->month_genitive[ $two ] ) ? $wp_locale->month_genitive[ $two ] : $full;
			}
			for ( $d = 0; $d < 7; $d++ ) {
				$day                      = $wp_locale->get_weekday( $d );
				$names['weekdays'][]      = $day;
				$names['weekdaysShort'][] = $wp_locale->get_weekday_abbrev( $day );
				$names['weekdaysMin'][]   = $wp_locale->get_weekday_initial( $day );
			}
			foreach ( array( 'am', 'pm', 'AM', 'PM' ) as $m ) {
				$names['meridiem'][ $m ] = $wp_locale->get_meridiem( $m );
			}
		}

		$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';

		$cache[ $key ] = array(
			'locale'       => brikpanel_locale_tag(),
			'decimal'      => $sep['decimal'],
			'thousands'    => $sep['thousands'],
			'percent'      => brikpanel_percent_pattern(),
			/* translators: A compact number on a chart axis: 12k for 12,000. %s is the number of thousands. */
			'thousandsAbbr' => _x( '%sk', 'thousands abbreviation on a chart axis', 'brikpanel' ),
			'money'        => $money,
			'dateFormat'   => brikpanel_date_format(),
			'timeFormat'   => brikpanel_time_format(),
			'shortDate'    => brikpanel_short_date_format(),
			'dayMonth'     => brikpanel_day_month_format(),
			'monthYear'    => brikpanel_month_year_format(),
			// WordPress core's own switch for languages that decline month names (pl, ru, uk...).
			'declineMonths' => 'on' === _x( 'off', 'decline months names: on or off' ), // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core string, core domain on purpose.
			'startOfWeek'  => (int) get_option( 'start_of_week', 0 ),
			/* translators: Separator between the two dates of a date range in the calendar field, with spaces, e.g. " to " in "Sep 1 to Sep 22". */
			'rangeSeparator' => _x( ' to ', 'date range separator', 'brikpanel' ),
			'timezone'     => $tz_string,
			'gmtOffset'    => (float) get_option( 'gmt_offset', 0 ),
			'yearLabel'    => __( 'Year', 'brikpanel' ),
			'monthLabel'   => __( 'Month', 'brikpanel' ),
			// Plural form per number, for brikpanelFormat.plural().
			'pluralIndex'  => brikpanel_plural_table(),
		) + $names;

		return $cache[ $key ];
	}
}
