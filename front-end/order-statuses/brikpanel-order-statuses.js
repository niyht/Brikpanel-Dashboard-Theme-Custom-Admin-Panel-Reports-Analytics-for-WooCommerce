/**
 * Custom order statuses — settings repeater.
 *
 * Add / remove rows, live colour dot, and a client-side slug preview for new
 * rows (the authoritative slug is minted server-side on save). All user-facing
 * text comes from the localized `brikpanelOrderStatuses.i18n` object.
 */
( function () {
	'use strict';

	var cfg  = window.brikpanelOrderStatuses || {};
	var i18n = cfg.i18n || {};

	var root = document.querySelector( '.bp-cos-card' );
	if ( ! root ) {
		return;
	}

	var list     = root.querySelector( '[data-cos-list]' );
	var emptyMsg = root.querySelector( '[data-cos-emptymsg]' );
	var template = root.querySelector( '[data-cos-template]' );
	if ( ! list || ! template ) {
		return;
	}

	// New rows continue numbering after the server-rendered ones so the posted
	// brikpanel_cos[i][...] keys never collide.
	var nextIndex = list.querySelectorAll( '[data-cos-row]' ).length;

	function slugify( value ) {
		return String( value )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 17 )
			.replace( /-+$/g, '' );
	}

	function refreshEmpty() {
		var has = list.querySelectorAll( '[data-cos-row]' ).length > 0;
		if ( emptyMsg ) {
			emptyMsg.hidden = has;
		}
		if ( has ) {
			list.removeAttribute( 'data-cos-empty' );
		} else {
			list.setAttribute( 'data-cos-empty', '' );
		}
	}

	function addRow() {
		var frag = template.content.cloneNode( true );
		var row  = frag.querySelector( '[data-cos-row]' );
		var idx  = nextIndex++;

		// New rows post only label + colour. The slug is intentionally left
		// nameless (never submitted) so the server always mints it fresh from
		// the label — this also immunises new rows against browser autofill
		// dropping a stray value into the hidden slug field.
		row.querySelector( '[data-cos-field="label"]' ).setAttribute( 'name', 'brikpanel_cos[' + idx + '][label]' );
		row.querySelector( '[data-cos-field="color"]' ).setAttribute( 'name', 'brikpanel_cos[' + idx + '][color]' );

		var slugEl = row.querySelector( '[data-cos-slug]' );
		if ( slugEl && i18n.newBadge ) {
			slugEl.textContent = i18n.newBadge;
			slugEl.classList.add( 'is-pending' );
		}

		list.appendChild( frag );
		refreshEmpty();

		var labelInput = list.lastElementChild.querySelector( '[data-cos-field="label"]' );
		if ( labelInput ) {
			labelInput.focus();
		}
	}

	function removeRow( row ) {
		if ( row.getAttribute( 'data-cos-existing' ) === '1' ) {
			var msg = i18n.confirmRemoveExisting || '';
			if ( msg && ! window.confirm( msg ) ) {
				return;
			}
		}
		row.parentNode.removeChild( row );
		refreshEmpty();
	}

	root.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest ? e.target.closest( '[data-cos-action]' ) : null;
		if ( ! trigger ) {
			return;
		}
		var action = trigger.getAttribute( 'data-cos-action' );
		if ( action === 'add' ) {
			e.preventDefault();
			addRow();
		} else if ( action === 'remove' ) {
			e.preventDefault();
			var row = trigger.closest( '[data-cos-row]' );
			if ( row ) {
				removeRow( row );
			}
		}
	} );

	root.addEventListener( 'input', function ( e ) {
		var field = e.target.getAttribute ? e.target.getAttribute( 'data-cos-field' ) : null;
		if ( ! field ) {
			return;
		}
		var row = e.target.closest( '[data-cos-row]' );
		if ( ! row ) {
			return;
		}

		if ( field === 'color' ) {
			var dot = row.querySelector( '[data-cos-dot]' );
			if ( dot ) {
				dot.style.background = e.target.value;
			}
		}

		// Live slug preview only for not-yet-saved rows; existing slugs are fixed.
		if ( field === 'label' && row.getAttribute( 'data-cos-existing' ) !== '1' ) {
			var slugEl = row.querySelector( '[data-cos-slug]' );
			if ( slugEl ) {
				var preview = slugify( e.target.value );
				slugEl.textContent = preview || ( i18n.newBadge || '' );
				slugEl.classList.toggle( 'is-pending', preview === '' );
			}
		}
	} );
}() );
