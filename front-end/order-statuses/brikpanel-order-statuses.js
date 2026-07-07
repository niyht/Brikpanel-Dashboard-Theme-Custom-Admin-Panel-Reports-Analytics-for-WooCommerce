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

	// The repeater card is the one holding the list; a sibling "import" card may
	// precede it in the DOM, so select by the list, not by the shared card class.
	var list = document.querySelector( '[data-cos-list]' );
	var root = list ? list.closest( '.bp-cos-card' ) : null;
	if ( ! root || ! list ) {
		return;
	}

	var emptyMsg = root.querySelector( '[data-cos-emptymsg]' );
	var template = root.querySelector( '[data-cos-template]' );
	if ( ! template ) {
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

/**
 * Import card — adopt foreign order statuses into BrikPanel via AJAX, then
 * reload so the freshly adopted rows appear in the repeater below (fully
 * editable) and the import card refreshes. Kept as a separate IIFE so it runs
 * even on installs where the repeater card is somehow absent.
 */
( function () {
	'use strict';

	var cfg  = window.brikpanelOrderStatuses || {};
	var i18n = cfg.i18n || {};

	var card = document.querySelector( '[data-cos-import]' );
	if ( ! card ) {
		return;
	}
	var runBtn = card.querySelector( '[data-cos-import-run]' );
	if ( ! runBtn ) {
		return;
	}

	runBtn.addEventListener( 'click', function () {
		var checked = card.querySelectorAll( '.bp-cos-import-check:checked' );
		var slugs   = Array.prototype.map.call( checked, function ( el ) {
			return el.value;
		} );

		if ( ! slugs.length ) {
			window.alert( i18n.importNone || 'Select at least one status to import.' );
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'brikpanel_cos_import' );
		body.append( 'nonce', cfg.importNonce || '' );
		slugs.forEach( function ( slug ) {
			body.append( 'slugs[]', slug );
		} );

		runBtn.disabled = true;
		var originalLabel = runBtn.lastChild ? runBtn.lastChild.nodeValue : '';
		if ( runBtn.lastChild && i18n.importing ) {
			runBtn.lastChild.nodeValue = ' ' + i18n.importing;
		}

		fetch( cfg.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					// Reload so adopted statuses render as editable rows and the
					// import card recomputes (dropping the ones just imported).
					window.location.reload();
				} else {
					throw new Error( ( res && res.data && res.data.message ) || '' );
				}
			} )
			.catch( function ( err ) {
				runBtn.disabled = false;
				if ( runBtn.lastChild ) {
					runBtn.lastChild.nodeValue = originalLabel || ( ' ' + ( i18n.importBtn || 'Import selected' ) );
				}
				window.alert( ( err && err.message ) || i18n.importError || 'Import failed. Please try again.' );
			} );
	} );
}() );
