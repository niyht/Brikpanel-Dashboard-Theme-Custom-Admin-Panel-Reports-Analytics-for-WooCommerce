/**
 * BrikPanel - numbers, percentages, money and dates in the browser, formatted
 * exactly like the server does (includes/brikpanel-format.php).
 *
 * Field test E2 (22 Sep 2026): thirteen screens formatted with their own
 * helper, most with the browser's language (`toLocaleString()` with no
 * locale), so an English store on a Turkish computer read "22 Eyl 2026", and
 * calendars were always English with Sunday first. Everything here comes from
 * `window.brikpanelFormatL10n`, which PHP prints right before this file:
 * the store's separators, date and time formats, the viewer's month and day
 * names, and where their language puts the percent sign.
 *
 * Dates are "wall clock" values: "2026-09-22 14:05" from the server is shown
 * as is, never shifted by the browser's timezone. A timestamp (seconds) is
 * turned into the store's clock first.
 *
 * API: window.brikpanelFormat.number(n, decimals, trim), percent(v, decimals,
 * trim), money(v, {symbol, decimals}), compact(n), date(v, phpFormat),
 * dateTime(v), dateShort(v), dateShortTime(v), dayMonth(v), monthYear(v),
 * range(a, b), parts(v), now(), compare(a, b), fill(pattern, value),
 * format(pattern, values), plural(message, n), count(message, n),
 * flatpickrLocale(), datePicker(input, options), chart(Chart), chartNumber(v),
 * tooltipValue(ctx).
 *
 * Plurals: a count the browser works out itself ("3 selected") comes as a
 * message from brikpanel_js_plural() in PHP, every form of the translation;
 * plural() picks the form with the language's own rule, the same one _n()
 * uses (field test E7: "5 value(s)" style text).
 */
(function (window) {
	'use strict';

	if (window.brikpanelFormat) {
		return;
	}

	var L = window.brikpanelFormatL10n || {};

	// Without the data (a lost inline script) stay neutral: digits and ISO dates.
	function arr(a, n) {
		return Array.isArray(a) && a.length === n ? a : null;
	}
	var months = arr(L.months, 12);
	var monthsShort = arr(L.monthsShort, 12);
	var monthsGenitive = arr(L.monthsGenitive, 12);
	var weekdays = arr(L.weekdays, 7);
	var weekdaysShort = arr(L.weekdaysShort, 7);
	var meridiem = L.meridiem || { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' };
	var DECIMAL = typeof L.decimal === 'string' && L.decimal !== '' ? L.decimal : '.';
	var THOUSANDS = typeof L.thousands === 'string' ? L.thousands : ',';
	var PERCENT = typeof L.percent === 'string' && L.percent.indexOf('%s') !== -1 ? L.percent : '%s%%';

	function pad(n, w) {
		n = String(n);
		while (n.length < w) {
			n = '0' + n;
		}
		return n;
	}

	/* ---------------------------------------------------------------- numbers */

	function number(n, decimals, trim) {
		var v = Number(n);
		if (!isFinite(v)) {
			v = 0;
		}
		var d = Math.max(0, decimals | 0);
		var f = Math.pow(10, d);
		// Pre-rounding to 15 digits drops binary noise (1.005 * 100 = 100.49999...),
		// so halves round up like PHP's number_format().
		var r = Math.round(Number((Math.abs(v) * f).toPrecision(15))) / f;
		var fixed = r.toFixed(d).split('.');
		var intPart = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS);
		var decPart = fixed[1] || '';
		if (trim) {
			decPart = decPart.replace(/0+$/, '');
		}
		return (v < 0 && r !== 0 ? '-' : '') + intPart + (decPart ? DECIMAL + decPart : '');
	}

	/** "%s" becomes the value, "%%" a percent sign. The pattern is a translation. */
	function fill(pattern, value) {
		var out = '';
		var p = String(pattern);
		for (var i = 0; i < p.length; i++) {
			var c = p.charAt(i);
			if (c === '%' && i + 1 < p.length) {
				var nx = p.charAt(i + 1);
				if (nx === '%') {
					out += '%';
					i++;
					continue;
				}
				if (nx === 's') {
					out += value;
					i++;
					continue;
				}
			}
			out += c;
		}
		return out;
	}

	/** "%s" / "%d" in order, "%1$s" by position, "%%" a percent sign. Values go in as they are. */
	function format(pattern, values) {
		var vals = Array.isArray(values) ? values : [values];
		var next = 0;
		return String(pattern == null ? '' : pattern).replace(/%(?:(\d+)\$)?([sd%])/g, function (m, num, type) {
			if (type === '%') {
				return '%';
			}
			var v = num ? vals[parseInt(num, 10) - 1] : vals[next++];
			return v == null ? '' : String(v);
		});
	}

	/**
	 * The form of a plural message for n. A message is { forms: [...], en }
	 * from brikpanel_js_plural(); a plain string is returned as it is.
	 */
	function plural(msg, n) {
		if (msg == null) {
			return '';
		}
		if (typeof msg === 'string') {
			return msg;
		}
		var forms = Array.isArray(msg.forms) ? msg.forms : [];
		if (!forms.length) {
			return '';
		}
		var abs = Math.abs(Math.round(Number(n) || 0));
		var table = Array.isArray(L.pluralIndex) ? L.pluralIndex : null;
		var idx;
		if (msg.en || !table || table.length < 200) {
			idx = abs === 1 ? 0 : 1;
		} else {
			idx = table[abs < 200 ? abs : 100 + (abs % 100)];
		}
		return forms[Math.min(idx | 0, forms.length - 1)];
	}

	/** plural() with n written in the store's number format: "1,250 values added". */
	function count(msg, n) {
		return format(plural(msg, n), [number(n)]);
	}

	function percent(value, decimals, trim) {
		var n = number(value, decimals == null ? 1 : decimals, trim !== false);
		// The minus stays in front of the whole value: "-%3" in Turkish, not "%-3".
		if (n.charAt(0) === '-') {
			return '-' + fill(PERCENT, n.slice(1));
		}
		return fill(PERCENT, n);
	}

	function money(amount, opts) {
		opts = opts || {};
		var m = L.money || {};
		var dec = opts.decimals != null ? opts.decimals : (m.decimals != null ? m.decimals : 2);
		var sym = opts.symbol != null ? String(opts.symbol) : (m.symbol || '');
		var fmt = typeof m.format === 'string' && m.format ? m.format : '%1$s%2$s';
		var v = Number(amount);
		if (!isFinite(v)) {
			v = 0;
		}
		var num = number(Math.abs(v), dec);
		var out = fmt.replace('%1$s', sym).replace('%2$s', num);
		// Minus in front of the symbol, like wc_price(); never "-0.00".
		return (v < 0 && /[1-9]/.test(num) ? '-' : '') + out;
	}

	/** Chart axis: 12k / "12 bin" from 12,000; small values with up to 2 decimals. */
	function compact(n) {
		var v = Number(n) || 0;
		var a = Math.abs(v);
		if (a >= 1000) {
			return fill(typeof L.thousandsAbbr === 'string' && L.thousandsAbbr.indexOf('%s') !== -1 ? L.thousandsAbbr : '%sk', number(v / 1000, a >= 10000 ? 0 : 1, true));
		}
		return chartNumber(v);
	}

	function chartNumber(v) {
		var n = Number(v) || 0;
		return number(n, Math.abs(n % 1) > 0 ? 2 : 0, true);
	}

	/* ------------------------------------------------------------------ dates */

	var GMT_OFFSET = Number(L.gmtOffset) || 0;
	var ZONE = typeof L.timezone === 'string' ? L.timezone : '';
	var zoneFormatter = null;

	function epochParts(seconds) {
		var ms = Number(seconds) * 1000;
		if (/^[A-Za-z]+(\/[A-Za-z0-9_+-]+)+$|^UTC$/.test(ZONE)) {
			try {
				// Numeric parts of the store's clock only, never shown as text.
				zoneFormatter = zoneFormatter || new Intl.DateTimeFormat('en-US', { // i18n-ignore: timezone arithmetic, not display
					timeZone: ZONE, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit',
					hour: '2-digit', minute: '2-digit', second: '2-digit'
				});
				var o = {};
				zoneFormatter.formatToParts(new Date(ms)).forEach(function (p) {
					o[p.type] = p.value;
				});
				return { y: +o.year, m: +o.month, d: +o.day, H: (+o.hour) % 24, i: +o.minute, s: +o.second };
			} catch (e) {
				zoneFormatter = null;
			}
		}
		var d = new Date(ms + GMT_OFFSET * 3600000);
		return { y: d.getUTCFullYear(), m: d.getUTCMonth() + 1, d: d.getUTCDate(), H: d.getUTCHours(), i: d.getUTCMinutes(), s: d.getUTCSeconds() };
	}

	/**
	 * {y, m, d, H, i, s} from "Y-m-d", "Y-m-d H:i[:s]", "Y-m-dTH:i", a Date (its
	 * wall clock, as a date picker hands it over) or a timestamp in seconds.
	 */
	function parts(v) {
		if (v == null || v === '') {
			return null;
		}
		if (v instanceof Date) {
			return isNaN(v.getTime()) ? null : { y: v.getFullYear(), m: v.getMonth() + 1, d: v.getDate(), H: v.getHours(), i: v.getMinutes(), s: v.getSeconds() };
		}
		if (typeof v === 'number') {
			return epochParts(v);
		}
		var m = String(v).match(/^\s*(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
		if (!m) {
			return /^\d{9,}$/.test(String(v)) ? epochParts(Number(v)) : null;
		}
		return { y: +m[1], m: +m[2], d: +m[3], H: +(m[4] || 0), i: +(m[5] || 0), s: +(m[6] || 0) };
	}

	function now() {
		return epochParts(Math.floor(Date.now() / 1000));
	}

	function weekday(p) {
		return new Date(Date.UTC(p.y, p.m - 1, p.d)).getUTCDay();
	}

	function ordinal(d) {
		// What PHP's "S" prints, in English, like wp_date() leaves it.
		if (d % 100 >= 11 && d % 100 <= 13) {
			return 'th';
		}
		return ['th', 'st', 'nd', 'rd'][d % 10] || 'th';
	}

	function escapeRe(s) {
		return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	var WORD_END = '(?![\\p{L}\\p{N}_])';

	/** wp_maybe_decline_date(): Polish and Russian decline the month after a day. */
	function decline(date, fmt) {
		if (!L.declineMonths || !months || !monthsGenitive) {
			return date;
		}
		var i;
		if (/[dj]\.? F/.test(fmt)) {
			for (i = 0; i < 12; i++) {
				date = date.replace(new RegExp(' ' + escapeRe(months[i]) + WORD_END, 'gu'), ' ' + monthsGenitive[i]);
			}
		}
		if (/F [dj]/.test(fmt)) {
			for (i = 0; i < 12; i++) {
				// A captured lead character instead of a lookbehind (Safari before 16.4 has none).
				date = date.replace(
					new RegExp('(^|[^\\p{L}\\p{N}_])' + escapeRe(months[i]) + ' (\\d{1,2})(st|nd|rd|th)?([-\\u2013]\\d{1,2})?(st|nd|rd|th)?' + WORD_END, 'gu'),
					'$1$2$4 ' + monthsGenitive[i]
				);
			}
		}
		return date;
	}

	/** A date in a PHP date() format, with wp_date()'s localized names. */
	function date(v, fmt) {
		var p = parts(v);
		if (!p) {
			return '';
		}
		fmt = typeof fmt === 'string' && fmt !== '' ? fmt : (L.dateFormat || 'Y-m-d');
		if (!months || !weekdays) {
			fmt = /[aAgGhHis]/.test(fmt.replace(/\\./g, '')) ? 'Y-m-d H:i' : 'Y-m-d';
		}
		var dow = weekday(p);
		var out = '';
		for (var i = 0; i < fmt.length; i++) {
			var c = fmt.charAt(i);
			switch (c) {
				case '\\':
					i++;
					if (i < fmt.length) {
						out += fmt.charAt(i);
					}
					break;
				case 'd': out += pad(p.d, 2); break;
				case 'D': out += weekdaysShort ? weekdaysShort[dow] : ''; break;
				case 'j': out += p.d; break;
				case 'l': out += weekdays[dow]; break;
				case 'N': out += dow === 0 ? 7 : dow; break;
				case 'S': out += ordinal(p.d); break;
				case 'w': out += dow; break;
				case 'F': out += months[p.m - 1]; break;
				case 'M': out += monthsShort ? monthsShort[p.m - 1] : months[p.m - 1]; break;
				case 'm': out += pad(p.m, 2); break;
				case 'n': out += p.m; break;
				case 't': out += new Date(Date.UTC(p.y, p.m, 0)).getUTCDate(); break;
				case 'L': out += ((p.y % 4 === 0 && p.y % 100 !== 0) || p.y % 400 === 0) ? 1 : 0; break;
				case 'Y':
				case 'o': out += p.y; break;
				case 'y': out += pad(p.y % 100, 2); break;
				case 'a': out += p.H < 12 ? meridiem.am : meridiem.pm; break;
				case 'A': out += p.H < 12 ? meridiem.AM : meridiem.PM; break;
				case 'g': out += (p.H % 12) || 12; break;
				case 'G': out += p.H; break;
				case 'h': out += pad((p.H % 12) || 12, 2); break;
				case 'H': out += pad(p.H, 2); break;
				case 'i': out += pad(p.i, 2); break;
				case 's': out += pad(p.s, 2); break;
				case 'e':
				case 'T': out += ZONE; break;
				case 'P':
				case 'O':
					var mins = Math.round(GMT_OFFSET * 60);
					var sign = mins < 0 ? '-' : '+';
					mins = Math.abs(mins);
					out += sign + pad(Math.floor(mins / 60), 2) + (c === 'P' ? ':' : '') + pad(mins % 60, 2);
					break;
				default: out += c;
			}
		}
		return decline(out, fmt);
	}

	function dateTime(v) {
		return date(v, (L.dateFormat || 'Y-m-d') + ' ' + (L.timeFormat || 'H:i'));
	}

	function dateShort(v) {
		return date(v, L.shortDate || L.dateFormat);
	}

	function dateShortTime(v) {
		return date(v, (L.shortDate || L.dateFormat || 'Y-m-d') + ' ' + (L.timeFormat || 'H:i'));
	}

	function dayMonth(v) {
		return date(v, L.dayMonth || 'M j');
	}

	function monthYear(v) {
		return date(v, L.monthYear || 'M Y');
	}

	function range(a, b) {
		return String(a) + (typeof L.rangeSeparator === 'string' ? L.rangeSeparator : ' / ') + String(b);
	}

	/** Sorting names the way the viewer's language orders letters (Turkish İ/ı). */
	function compare(a, b) {
		try {
			return String(a).localeCompare(String(b), L.locale || undefined, { numeric: true, sensitivity: 'base' });
		} catch (e) {
			return String(a) < String(b) ? -1 : (String(a) > String(b) ? 1 : 0);
		}
	}

	/* ------------------------------------------------------------- calendars */

	function flatpickrLocale() {
		var loc = {
			firstDayOfWeek: Number(L.startOfWeek) || 0,
			ordinal: function () {
				return '';
			},
			rangeSeparator: typeof L.rangeSeparator === 'string' ? L.rangeSeparator : ' / ',
			amPM: [meridiem.AM, meridiem.PM],
			time_24hr: !/[aAgh]/.test(String(L.timeFormat || '').replace(/\\./g, ''))
		};
		if (weekdays && weekdaysShort) {
			loc.weekdays = { shorthand: weekdaysShort.slice(), longhand: weekdays.slice() };
		}
		if (months && monthsShort) {
			loc.months = { shorthand: monthsShort.slice(), longhand: months.slice() };
		}
		if (L.yearLabel) {
			loc.yearAriaLabel = L.yearLabel;
		}
		if (L.monthLabel) {
			loc.monthAriaLabel = L.monthLabel;
		}
		return loc;
	}

	/**
	 * flatpickr in the viewer's language, the store's first weekday and, with
	 * `altInput: true`, the store's short date in the visible field while the
	 * field itself keeps Y-m-d. `altInputClass` defaults to the field's own
	 * classes, which duplicates JS hook classes: pass the styling classes only.
	 */
	function datePicker(input, options) {
		if (!input || typeof window.flatpickr !== 'function') {
			return null;
		}
		if (input._flatpickr) {
			return input._flatpickr;
		}
		var o = {};
		var k;
		options = options || {};
		for (k in options) {
			if (Object.prototype.hasOwnProperty.call(options, k)) {
				o[k] = options[k];
			}
		}
		o.locale = flatpickrLocale();
		o.dateFormat = o.dateFormat || 'Y-m-d';
		o.ariaDateFormat = L.dateFormat || 'Y-m-d';
		o.formatDate = function (d, fmt) {
			return date(d, fmt);
		};
		if (o.altInput && !o.altFormat) {
			o.altFormat = L.shortDate || L.dateFormat || 'Y-m-d';
		}
		var fp = window.flatpickr(input, o);
		if (fp && fp.altInput && fp.altInput !== input) {
			var alt = fp.altInput;
			if (input.id) {
				alt.id = input.id + '-display';
				var label = document.querySelector('label[for="' + input.id + '"]');
				if (label) {
					label.htmlFor = alt.id;
				}
			}
			['aria-label', 'aria-labelledby', 'aria-describedby', 'title'].forEach(function (a) {
				var val = input.getAttribute(a);
				if (val) {
					alt.setAttribute(a, val);
				}
			});
		}
		return fp;
	}

	/* ----------------------------------------------------------------- charts */

	/** Chart.js defaults: the viewer's language for anything a chart formats itself. */
	function chart(Chart) {
		if (!Chart || !Chart.defaults || Chart.defaults.__brikpanelFormat) {
			return;
		}
		Chart.defaults.__brikpanelFormat = true;
		Chart.defaults.locale = L.locale || Chart.defaults.locale;
		var lin = Chart.defaults.scales && Chart.defaults.scales.linear;
		if (lin && lin.ticks) {
			lin.ticks.callback = function (value) {
				return chartNumber(value);
			};
		}
	}

	/** The number a tooltip item points at, formatted like the axis. */
	function tooltipValue(ctx) {
		var v = ctx ? ctx.parsed : null;
		if (v !== null && typeof v === 'object') {
			var horizontal = ctx.chart && ctx.chart.options && ctx.chart.options.indexAxis === 'y';
			v = horizontal ? v.x : v.y;
		}
		return v == null || !isFinite(v) ? (ctx && ctx.formattedValue) || '' : chartNumber(v);
	}

	window.brikpanelFormat = {
		locale: L.locale || '',
		l10n: L,
		number: number,
		percent: percent,
		money: money,
		compact: compact,
		chartNumber: chartNumber,
		fill: fill,
		format: format,
		plural: plural,
		count: count,
		parts: parts,
		now: now,
		date: date,
		dateTime: dateTime,
		dateShort: dateShort,
		dateShortTime: dateShortTime,
		dayMonth: dayMonth,
		monthYear: monthYear,
		range: range,
		compare: compare,
		flatpickrLocale: flatpickrLocale,
		datePicker: datePicker,
		chart: chart,
		tooltipValue: tooltipValue
	};

	if (window.Chart) {
		chart(window.Chart);
	}
})(window);
