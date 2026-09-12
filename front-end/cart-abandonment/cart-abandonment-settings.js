/**
 * Language tabs for the popup wording card.
 *
 * Every panel stays in the form whether it is on screen or not — the browser
 * posts hidden inputs just the same, so switching tabs never costs the merchant
 * something they typed in another language.
 *
 * No user-facing text lives here: every label is rendered by PHP.
 */
( function () {
	'use strict';

	function init( card ) {
		var tabs = card.querySelectorAll( '[data-cpl-locale]' );
		if ( ! tabs.length ) {
			return;
		}

		function show( locale ) {
			var i;
			for ( i = 0; i < tabs.length; i++ ) {
				var active = tabs[ i ].getAttribute( 'data-cpl-locale' ) === locale;
				tabs[ i ].classList.toggle( 'is-active', active );
				tabs[ i ].setAttribute( 'aria-selected', active ? 'true' : 'false' );
			}
			var panels = card.querySelectorAll( '[data-cpl-panel]' );
			for ( i = 0; i < panels.length; i++ ) {
				panels[ i ].hidden = panels[ i ].getAttribute( 'data-cpl-panel' ) !== locale;
			}
		}

		card.addEventListener( 'click', function ( e ) {
			var tab = e.target.closest ? e.target.closest( '[data-cpl-locale]' ) : null;
			if ( ! tab || ! card.contains( tab ) ) {
				return;
			}
			e.preventDefault();
			show( tab.getAttribute( 'data-cpl-locale' ) );
		} );
	}

	function boot() {
		var cards = document.querySelectorAll( '.bp-cpl-card' );
		for ( var i = 0; i < cards.length; i++ ) {
			init( cards[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
