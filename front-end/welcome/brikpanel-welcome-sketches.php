<?php
/**
 * BrikPanel: the hand-drawn sketches of the welcome tour.
 *
 * One drawing per step. The lines draw themselves through CSS: every path has
 * pathLength="1" and brikpanel-welcome.css animates its stroke-dashoffset from
 * 1 to 0 after the delay in --dd. The handwritten notes are HTML laid over the
 * drawing, not SVG text, so a translation can wrap and grows away from the
 * side its arrow leaves from.
 *
 * @package BrikPanel
 * @since   3.3.29
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The filter that gives every sketch its hand-drawn edge. Printed once in the
 * dialog, outside the step panels, so it renders for every panel.
 *
 * @return string
 */
function brikpanel_welcome_sketch_defs() {
	return '<svg class="brikpanel-welcome-defs" width="0" height="0" aria-hidden="true" focusable="false">'
		. '<filter id="brikpanel-welcome-rough" x="-5%" y="-5%" width="110%" height="110%">'
		. '<feTurbulence type="fractalNoise" baseFrequency="0.04" numOctaves="2" seed="5" result="n"/>'
		. '<feDisplacementMap in="SourceGraphic" in2="n" scale="2.8" xChannelSelector="R" yChannelSelector="G"/>'
		. '</filter></svg>';
}

/**
 * One handwritten note over a sketch.
 *
 * @param string $modifier Position class suffix (see brikpanel-welcome.css).
 * @param string $delay    When the note starts to appear.
 * @param string $duration How long writing it takes.
 * @param string $text     Translated note.
 * @return string
 */
function brikpanel_welcome_sketch_note( $modifier, $delay, $duration, $text ) {
	return '<span class="brikpanel-welcome-note brikpanel-welcome-note--' . esc_attr( $modifier ) . '" dir="auto" aria-hidden="true" style="--dd: ' . esc_attr( $delay ) . '; --dur: ' . esc_attr( $duration ) . '">'
		. esc_html( $text )
		. '</span>';
}

/**
 * The sketch for one tour step.
 *
 * @param string $key profit, orders, customers or connect.
 * @return string Static SVG plus escaped, translated notes.
 */
function brikpanel_welcome_sketch( $key ) {
	switch ( $key ) {
		case 'profit':
			$label = __( 'A sketch of the dashboard: three number cards, the net profit card circled, and a rising sales line', 'brikpanel' );
			$paths = '<path pathLength="1" style="--dd: .3s; --dur: .9s" d="M26 16 H436 C 444 16, 450 22, 450 30 V258 C 450 266, 444 272, 436 272 H26 C 18 272, 12 266, 12 258 V30 C 12 22, 18 16, 26 16 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: .9s; --dur: .4s" d="M12 44 H450"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.1s; --dur: .3s" d="M26 30 a3 3 0 1 0 6 0 a3 3 0 1 0 -6 0 M38 30 a3 3 0 1 0 6 0 a3 3 0 1 0 -6 0 M50 30 a3 3 0 1 0 6 0 a3 3 0 1 0 -6 0"/>'
				. '<path pathLength="1" style="--dd: 1.2s; --dur: .45s" d="M34 64 H154 V126 H34 Z"/>'
				. '<path pathLength="1" style="--dd: 1.4s; --dur: .45s" d="M168 64 H288 V126 H168 Z"/>'
				. '<path class="is-fill" style="--dd: 1.9s; fill: #efefef" d="M302 64 H422 V126 H302 Z"/>'
				. '<path pathLength="1" style="--dd: 1.6s; --dur: .45s" d="M302 64 H422 V126 H302 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.9s; --dur: .25s" d="M46 82 H88 M180 82 H230 M314 82 H362"/>'
				. '<path pathLength="1" style="--dd: 2.1s; --dur: .4s" d="M46 106 C 54 100, 60 112, 68 104 C 76 98, 84 110, 98 104 M180 106 C 188 100, 194 112, 202 104 C 210 98, 218 110, 226 104"/>'
				. '<path pathLength="1" style="--dd: 2.4s; --dur: .45s; stroke-width: 3" d="M314 106 C 322 98, 330 114, 340 104 C 350 96, 358 112, 370 102 C 378 96, 388 108, 398 102"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 2.6s; --dur: .4s" d="M34 248 H422 M34 248 V150"/>'
				. '<path pathLength="1" style="--dd: 2.8s; --dur: 1s" d="M40 236 C 70 230, 84 214, 112 220 C 140 226, 152 200, 182 204 C 212 208, 224 186, 252 182 C 280 178, 292 190, 320 174 C 348 158, 366 168, 392 152 C 404 145, 414 150, 420 144"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 3.5s; --dur: .3s" d="M84 246 L96 222 M120 246 L134 218 M156 246 L172 206 M194 246 L212 202 M232 246 L252 186"/>'
				. '<path pathLength="1" style="--dd: 3.9s; --dur: .8s" d="M368 56 C 330 52, 294 60, 293 92 C 292 122, 330 136, 368 133 C 408 130, 434 118, 432 92 C 430 66, 404 54, 356 60"/>'
				. '<path pathLength="1" style="--dd: 5.5s; --dur: .45s" d="M470 120 C 468 134, 456 140, 440 136"/>'
				. '<path pathLength="1" style="--dd: 5.9s; --dur: .2s" d="M449 129 L438 136 L448 143"/>';
			$notes = ''
				. brikpanel_welcome_sketch_note(
					'is-profit',
					'4.6s',
					'.9s',
					/* translators: Short handwritten note in a drawing of the dashboard, next to the circled net profit card. Two or three words. */
					_x( 'real profit!', 'welcome tour handwritten note', 'brikpanel' )
				);
			break;
		case 'orders':
			$label = __( 'A sketch of the order list with a status menu open on one order, and a ringing bell', 'brikpanel' );
			$paths = '<path pathLength="1" style="--dd: .3s; --dur: .9s" d="M26 16 H408 C 416 16, 422 22, 422 30 V258 C 422 266, 416 272, 408 272 H26 C 18 272, 12 266, 12 258 V30 C 12 22, 18 16, 26 16 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: .9s; --dur: .4s" d="M30 40 H96 M150 40 H210 M290 40 H340 M12 54 H422"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.1s; --dur: .5s" d="M12 104 H422 M12 154 H422 M12 204 H422"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.3s; --dur: .4s" d="M30 72 h14 v14 h-14 z M30 122 h14 v14 h-14 z M30 172 h14 v14 h-14 z M30 222 h14 v14 h-14 z"/>'
				. '<path pathLength="1" style="--dd: 1.5s; --dur: .5s" d="M62 80 H104 M62 130 H110 M62 180 H100 M62 230 H108"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.7s; --dur: .5s" d="M150 80 H226 M150 130 H214 M150 180 H232 M150 230 H210"/>'
				. '<path pathLength="1" style="--dd: 1.9s; --dur: .5s" d="M292 70 h70 a10 10 0 0 1 0 20 h-70 a10 10 0 0 1 0 -20 z M292 170 h70 a10 10 0 0 1 0 20 h-70 a10 10 0 0 1 0 -20 z M292 220 h70 a10 10 0 0 1 0 20 h-70 a10 10 0 0 1 0 -20 z"/>'
				. '<path class="is-fill" style="--dd: 2.4s; fill: #303030" d="M292 120 h70 a10 10 0 0 1 0 20 h-70 a10 10 0 0 1 0 -20 z"/>'
				. '<path pathLength="1" style="--dd: 2.2s; --dur: .4s" d="M292 120 h70 a10 10 0 0 1 0 20 h-70 a10 10 0 0 1 0 -20 z"/>'
				. '<path class="is-fill" style="--dd: 2.8s; fill: #ffffff" d="M286 146 H400 V246 H286 Z"/>'
				. '<path pathLength="1" style="--dd: 2.7s; --dur: .6s" d="M292 146 H394 C 398 146, 400 148, 400 152 V240 C 400 244, 398 246, 394 246 H292 C 288 246, 286 244, 286 240 V152 C 286 148, 288 146, 292 146 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 3.2s; --dur: .4s" d="M300 168 H352 M300 196 H360 M300 224 H346"/>'
				. '<path pathLength="1" style="--dd: 3.6s; --dur: .3s" d="M370 192 l6 7 l12 -15"/>'
				. '<path pathLength="1" style="--dd: 3.9s; --dur: .7s" d="M492 34 C 476 34, 470 46, 470 60 C 470 76, 462 82, 462 82 H522 C 522 82, 514 76, 514 60 C 514 46, 508 34, 492 34 Z M486 88 C 488 94, 496 94, 498 88"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 4.5s; --dur: .3s" d="M452 40 L444 34 M448 56 L438 56 M532 40 L540 34 M536 56 L546 56"/>'
				. '<path pathLength="1" style="--dd: 5.9s; --dur: .45s" d="M450 220 C 446 236, 430 242, 410 236"/>'
				. '<path pathLength="1" style="--dd: 6.3s; --dur: .2s" d="M419 229 L408 236 L418 243"/>';
			$notes = ''
				. brikpanel_welcome_sketch_note(
					'is-orders',
					'4.9s',
					'1s',
					/* translators: Short handwritten note in a drawing of the order list, pointing at a status menu opened inside the list. Two to four words. */
					_x( 'right in the list', 'welcome tour handwritten note', 'brikpanel' )
				);
			break;
		case 'customers':
			$label = __( 'Three customers: a loyal one with a heart, a VIP with a star, and one fading away', 'brikpanel' );
			$paths = '<path class="is-fill" style="--dd: .9s; fill: #f4f4f4" d="M218 128 a62 62 0 1 0 124 0 a62 62 0 1 0 -124 0"/>'
				. '<path pathLength="1" style="--dd: .2s; --dur: 1s" d="M218 128 a62 62 0 1 0 124 0 a62 62 0 1 0 -124 0"/>'
				. '<path pathLength="1" style="--dd: .8s; --dur: .6s" d="M260 114 a20 20 0 1 0 40 0 a20 20 0 1 0 -40 0"/>'
				. '<path pathLength="1" style="--dd: 1.2s; --dur: .6s" d="M240 172 C 246 146, 314 146, 320 172"/>'
				. '<path pathLength="1" style="--dd: 1.6s; --dur: .8s" d="M338 48 l8 15 l17 3 l-12 12 l3 17 l-16 -8 l-16 8 l3 -17 l-12 -12 l17 -3 z"/>'
				. '<path pathLength="1" style="--dd: 2.2s; --dur: .8s" d="M98 160 a44 44 0 1 0 88 0 a44 44 0 1 0 -88 0"/>'
				. '<path pathLength="1" style="--dd: 2.7s; --dur: .45s" d="M128 152 a14 14 0 1 0 28 0 a14 14 0 1 0 -28 0"/>'
				. '<path pathLength="1" style="--dd: 3s; --dur: .45s" d="M114 190 C 118 172, 166 172, 170 190"/>'
				. '<path pathLength="1" style="--dd: 3.3s; --dur: .7s" d="M142 98 C 136 88, 122 92, 126 104 C 129 112, 142 120, 142 120 C 142 120, 155 112, 158 104 C 162 92, 148 88, 142 98"/>'
				. '<path class="is-dash" style="--dd: 3.8s; stroke: #8a8a8a" d="M374 160 a44 44 0 1 0 88 0 a44 44 0 1 0 -88 0"/>'
				. '<path class="is-dash" style="--dd: 4s; stroke: #8a8a8a" d="M404 152 a14 14 0 1 0 28 0 a14 14 0 1 0 -28 0"/>'
				. '<path class="is-dash" style="--dd: 4.2s; stroke: #8a8a8a" d="M390 190 C 394 172, 442 172, 446 190"/>'
				. '<path pathLength="1" style="--dd: 4.4s; --dur: .9s" d="M70 224 C 170 218, 380 232, 490 222"/>';
			$notes = ''
				. brikpanel_welcome_sketch_note(
					'is-vip',
					'5s',
					'.5s',
					/* translators: Short handwritten label above the best customer in a drawing. Usually left as VIP. */
					_x( 'VIP', 'welcome tour handwritten note', 'brikpanel' )
				)
				. brikpanel_welcome_sketch_note(
					'is-loyal',
					'5.3s',
					'.6s',
					/* translators: Short handwritten label under a returning customer in a drawing. One word. */
					_x( 'loyal', 'welcome tour handwritten note', 'brikpanel' )
				)
				. brikpanel_welcome_sketch_note(
					'is-risk',
					'5.6s',
					'.7s',
					/* translators: Short handwritten label under a customer who may not come back, in a drawing. One or two words with a question mark. */
					_x( 'at risk?', 'welcome tour handwritten note', 'brikpanel' )
				);
			break;
		case 'connect':
			$label = __( 'Google Sheets, Google Ads and Meta flowing into BrikPanel', 'brikpanel' );
			$paths = '<path pathLength="1" style="--dd: .2s; --dur: .5s" d="M40 22 H112 C 118 22, 122 26, 122 32 V74 C 122 80, 118 84, 112 84 H40 C 34 84, 30 80, 30 74 V32 C 30 26, 34 22, 40 22 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: .7s; --dur: .4s" d="M58 34 H94 V72 H58 Z M58 47 H94 M58 59 H94 M72 34 V72"/>'
				. '<path pathLength="1" style="--dd: .9s; --dur: .5s" d="M40 112 H112 C 118 112, 122 116, 122 122 V164 C 122 170, 118 174, 112 174 H40 C 34 174, 30 170, 30 164 V122 C 30 116, 34 112, 40 112 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 1.4s; --dur: .4s" d="M58 160 V144 M70 160 V130 M82 160 V138 M94 160 V124 M52 162 H100"/>'
				. '<path pathLength="1" style="--dd: 1.6s; --dur: .5s" d="M40 202 H112 C 118 202, 122 206, 122 212 V254 C 122 260, 118 264, 112 264 H40 C 34 264, 30 260, 30 254 V212 C 30 206, 34 202, 40 202 Z"/>'
				. '<path pathLength="1" class="is-thin" style="--dd: 2.1s; --dur: .5s" d="M58 228 V240 H66 L82 250 V218 L66 228 Z M88 226 C 94 230, 94 238, 88 242"/>'
				. '<path pathLength="1" style="--dd: 2.4s; --dur: .7s" d="M128 54 C 220 54, 250 120, 330 132"/>'
				. '<path pathLength="1" style="--dd: 2.7s; --dur: .7s" d="M128 143 C 210 143, 260 140, 330 140"/>'
				. '<path pathLength="1" style="--dd: 3s; --dur: .7s" d="M128 233 C 220 233, 250 160, 330 148"/>'
				. '<path pathLength="1" style="--dd: 3.6s; --dur: .25s" d="M318 126 L332 133 L320 141 M318 134 L332 140 L318 147 M318 141 L332 148 L321 156"/>'
				. '<path class="is-fill" style="--dd: 4.2s; fill: #303030" d="M352 82 H448 C 460 82, 470 92, 470 104 V180 C 470 192, 460 202, 448 202 H352 C 340 202, 330 192, 330 180 V104 C 330 92, 340 82, 352 82 Z"/>'
				. '<path pathLength="1" style="--dd: 3.8s; --dur: .7s" d="M352 82 H448 C 460 82, 470 92, 470 104 V180 C 470 192, 460 202, 448 202 H352 C 340 202, 330 192, 330 180 V104 C 330 92, 340 82, 352 82 Z"/>'
				. '<path class="is-fill" style="--dd: 4.6s; fill: #ffffff" d="M358 110 h34 v34 h-34 z M408 146 h34 v34 h-34 z"/>'
				. '<path class="is-fill" style="--dd: 4.8s; fill: #a3a3a3" d="M408 110 h34 v34 h-34 z M358 146 h34 v34 h-34 z"/>';
			$notes = ''
				. brikpanel_welcome_sketch_note(
					'is-connect',
					'5.2s',
					'.8s',
					/* translators: Short handwritten note under the BrikPanel logo in a drawing where Google Sheets, Google Ads and Meta flow into it. Three or four words. */
					_x( 'all in one place', 'welcome tour handwritten note', 'brikpanel' )
				);
			break;
		default:
			return '';
	}

	return '<div class="brikpanel-welcome-sketch brikpanel-welcome-sketch--' . esc_attr( $key ) . '" dir="ltr">'
		. '<svg class="brikpanel-welcome-ink" viewBox="0 0 560 280" role="img" aria-label="' . esc_attr( $label ) . '" focusable="false">'
		. $paths // Static markup, no user data.
		. '</svg>'
		. $notes
		. '</div>';
}
