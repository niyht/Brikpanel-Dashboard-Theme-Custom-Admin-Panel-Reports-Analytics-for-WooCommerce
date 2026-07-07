/**
 * Status change emails — settings card interactions.
 *
 * Per status: expand/collapse the body, reflect the on/off toggle in the header
 * state text + card highlight, and insert a {placeholder} token into whichever
 * text field the merchant last focused. All user-facing text comes from the
 * localized `brikpanelStatusEmails.i18n` object.
 */
( function () {
	'use strict';

	var cfg  = window.brikpanelStatusEmails || {};
	var i18n = cfg.i18n || {};

	var card = document.querySelector( '.bp-cse-card' );
	if ( ! card ) {
		return;
	}

	// Remember the last-focused insertable field per item, so a placeholder chip
	// knows where to drop its token (defaults to the item's Message textarea).
	var lastField = null;

	card.addEventListener( 'focusin', function ( e ) {
		if ( e.target && e.target.hasAttribute && e.target.hasAttribute( 'data-cse-insertable' ) ) {
			lastField = e.target;
		}
	} );

	// Expand / collapse — click or keyboard on the header, but never when the
	// interaction started on the on/off switch (that just toggles enabled).
	function toggleItem( item ) {
		var open = item.classList.toggle( 'is-open' );
		var head = item.querySelector( '[data-cse-toggle]' );
		if ( head ) {
			head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}
	}

	card.addEventListener( 'click', function ( e ) {
		var target = e.target;

		// Placeholder chip → insert its token into the last-focused field.
		var token = target.closest ? target.closest( '[data-token]' ) : null;
		if ( token ) {
			e.preventDefault();
			insertToken( token.getAttribute( 'data-token' ), token );
			return;
		}

		// The on/off switch handles its own change; don't collapse on it.
		if ( target.closest && target.closest( '[data-cse-switch]' ) ) {
			return;
		}

		var head = target.closest ? target.closest( '[data-cse-toggle]' ) : null;
		if ( head ) {
			var item = head.closest( '[data-cse-item]' );
			if ( item ) {
				toggleItem( item );
			}
		}
	} );

	card.addEventListener( 'keydown', function ( e ) {
		if ( ( e.key !== 'Enter' && e.key !== ' ' ) ) {
			return;
		}
		var head = e.target && e.target.getAttribute && e.target.getAttribute( 'data-cse-toggle' ) !== null
			? e.target
			: null;
		if ( head ) {
			e.preventDefault();
			var item = head.closest( '[data-cse-item]' );
			if ( item ) {
				toggleItem( item );
			}
		}
	} );

	// Reflect the on/off toggle in the header (state text + card highlight).
	card.addEventListener( 'change', function ( e ) {
		if ( ! e.target || ! e.target.hasAttribute || ! e.target.hasAttribute( 'data-cse-enable' ) ) {
			return;
		}
		var item = e.target.closest( '[data-cse-item]' );
		if ( ! item ) {
			return;
		}
		var on = e.target.checked;
		item.classList.toggle( 'is-on', on );
		var state = item.querySelector( '[data-cse-state]' );
		if ( state ) {
			state.textContent = on ? ( i18n.on || 'On' ) : ( i18n.off || 'Off' );
		}
	} );

	function insertToken( tokenText, chip ) {
		var field = lastField;
		if ( ! field ) {
			// Fall back to the Message textarea in the same item as the chip.
			var item = chip.closest( '[data-cse-item]' );
			field = item ? item.querySelector( 'textarea[data-cse-insertable]' ) : null;
		}
		if ( ! field ) {
			return;
		}

		var start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
		var end   = typeof field.selectionEnd === 'number' ? field.selectionEnd : field.value.length;
		field.value = field.value.slice( 0, start ) + tokenText + field.value.slice( end );

		var caret = start + tokenText.length;
		field.focus();
		if ( field.setSelectionRange ) {
			field.setSelectionRange( caret, caret );
		}
		lastField = field;
	}
}() );
