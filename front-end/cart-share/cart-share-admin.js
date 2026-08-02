/**
 * BrikPanel - Cart Share (admin builder)
 *
 * All user-facing copy comes from the localized `brikpanelCartShareAdmin.i18n`
 * object; nothing readable is hardcoded here (see the i18n audit rule).
 */
( function () {
    'use strict';

    var cfg = window.brikpanelCartShareAdmin || {};
    var i18n = cfg.i18n || {};

    var els = {};
    var rows = [];          // [{ uid, productId, name, isVariable, variationId, variationLabel, variations, qty }]
    var uidSeq = 0;
    var searchTimer = null;
    var activeSuggestion = -1;
    var lastResults = [];

    function t( key ) {
        return typeof i18n[ key ] === 'string' ? i18n[ key ] : '';
    }

    function ready( fn ) {
        if ( document.readyState !== 'loading' ) {
            fn();
        } else {
            document.addEventListener( 'DOMContentLoaded', fn );
        }
    }

    function ajax( action, data ) {
        var body = new URLSearchParams();
        body.append( 'action', action );
        body.append( 'security', cfg.nonce || '' );
        Object.keys( data || {} ).forEach( function ( k ) {
            body.append( k, data[ k ] );
        } );
        return fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        } ).then( function ( r ) {
            return r.json();
        } );
    }

    function toast( message, type ) {
        var container = els.toast;
        if ( ! container ) {
            return;
        }
        var el = document.createElement( 'div' );
        el.className = 'brikpanel-cs-toast ' + ( type === 'error' ? 'error' : 'success' );
        el.textContent = message;
        container.appendChild( el );
        requestAnimationFrame( function () {
            el.classList.add( 'show' );
        } );
        setTimeout( function () {
            el.classList.remove( 'show' );
            setTimeout( function () {
                if ( el.parentNode ) {
                    el.parentNode.removeChild( el );
                }
            }, 300 );
        }, 3000 );
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    function onSearchInput() {
        var term = els.search.value.trim();
        clearTimeout( searchTimer );
        if ( term.length < 2 ) {
            hideSuggestions();
            return;
        }
        searchTimer = setTimeout( function () {
            runSearch( term );
        }, 220 );
    }

    function runSearch( term ) {
        renderSuggestions( [ { loading: true } ] );
        ajax( 'brikpanel_cartshare_search_products', { q: term } ).then( function ( res ) {
            if ( ! res || ! res.success ) {
                hideSuggestions();
                return;
            }
            lastResults = res.data.items || [];
            renderSuggestions( lastResults );
        } ).catch( function () {
            hideSuggestions();
        } );
    }

    function renderSuggestions( items ) {
        var box = els.suggestions;
        box.innerHTML = '';
        activeSuggestion = -1;

        if ( items.length === 1 && items[ 0 ].loading ) {
            var l = document.createElement( 'div' );
            l.className = 'brikpanel-cs-suggestion is-muted';
            l.textContent = t( 'searching' );
            box.appendChild( l );
            box.hidden = false;
            return;
        }

        if ( ! items.length ) {
            var none = document.createElement( 'div' );
            none.className = 'brikpanel-cs-suggestion is-muted';
            none.textContent = t( 'no_results' );
            box.appendChild( none );
            box.hidden = false;
            return;
        }

        items.forEach( function ( item, idx ) {
            var row = document.createElement( 'div' );
            row.className = 'brikpanel-cs-suggestion';
            row.setAttribute( 'data-idx', String( idx ) );

            var name = document.createElement( 'span' );
            name.className = 'brikpanel-cs-suggestion-name';
            name.textContent = item.label;
            row.appendChild( name );

            var meta = document.createElement( 'span' );
            meta.className = 'brikpanel-cs-suggestion-meta';
            meta.textContent = item.is_variable ? t( 'variable_badge' ) : item.price;
            row.appendChild( meta );

            row.addEventListener( 'mousedown', function ( e ) {
                e.preventDefault();
                addProduct( item );
            } );
            box.appendChild( row );
        } );
        box.hidden = false;
    }

    function hideSuggestions() {
        els.suggestions.hidden = true;
        els.suggestions.innerHTML = '';
        activeSuggestion = -1;
    }

    function onSearchKeydown( e ) {
        var box = els.suggestions;
        if ( box.hidden ) {
            return;
        }
        var options = box.querySelectorAll( '.brikpanel-cs-suggestion:not(.is-muted)' );
        if ( ! options.length ) {
            return;
        }
        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            activeSuggestion = Math.min( activeSuggestion + 1, options.length - 1 );
            highlight( options );
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            activeSuggestion = Math.max( activeSuggestion - 1, 0 );
            highlight( options );
        } else if ( e.key === 'Enter' ) {
            if ( activeSuggestion >= 0 && options[ activeSuggestion ] ) {
                e.preventDefault();
                var idx = parseInt( options[ activeSuggestion ].getAttribute( 'data-idx' ), 10 );
                if ( lastResults[ idx ] ) {
                    addProduct( lastResults[ idx ] );
                }
            }
        } else if ( e.key === 'Escape' ) {
            hideSuggestions();
        }
    }

    function highlight( options ) {
        options.forEach( function ( o, i ) {
            o.classList.toggle( 'is-active', i === activeSuggestion );
        } );
    }

    // ---------------------------------------------------------------------
    // Rows
    // ---------------------------------------------------------------------

    function addProduct( item ) {
        // Collapse duplicates of a simple product into a quantity bump.
        if ( ! item.is_variable ) {
            var existing = rows.filter( function ( r ) {
                return r.productId === item.id && ! r.isVariable;
            } )[ 0 ];
            if ( existing ) {
                existing.qty += 1;
                els.search.value = '';
                hideSuggestions();
                renderRows();
                updateLink();
                return;
            }
        }

        rows.push( {
            uid: ++uidSeq,
            productId: item.id,
            name: item.label,
            isVariable: !! item.is_variable,
            variationId: 0,
            variationLabel: '',
            variations: null,
            loadingVariations: false,
            qty: 1,
        } );

        els.search.value = '';
        hideSuggestions();
        els.search.focus();
        renderRows();
        updateLink();

        if ( item.is_variable ) {
            loadVariations( uidSeq );
        }
    }

    function loadVariations( uid ) {
        var row = rowByUid( uid );
        if ( ! row ) {
            return;
        }
        row.loadingVariations = true;
        renderRows();

        ajax( 'brikpanel_cartshare_get_variations', { product_id: row.productId } ).then( function ( res ) {
            row.loadingVariations = false;
            if ( res && res.success && res.data.variations && res.data.variations.length ) {
                row.variations = res.data.variations;
            } else {
                row.variations = [];
            }
            renderRows();
        } ).catch( function () {
            row.loadingVariations = false;
            row.variations = [];
            renderRows();
        } );
    }

    function rowByUid( uid ) {
        return rows.filter( function ( r ) {
            return r.uid === uid;
        } )[ 0 ];
    }

    function removeRow( uid ) {
        rows = rows.filter( function ( r ) {
            return r.uid !== uid;
        } );
        renderRows();
        updateLink();
    }

    function renderRows() {
        var list = els.items;
        // Drop existing rendered rows but keep the empty-state node.
        Array.prototype.slice.call( list.querySelectorAll( '.brikpanel-cs-item' ) ).forEach( function ( n ) {
            n.parentNode.removeChild( n );
        } );

        els.empty.hidden = rows.length > 0;

        rows.forEach( function ( row ) {
            list.appendChild( buildRowEl( row ) );
        } );
    }

    function buildRowEl( row ) {
        var el = document.createElement( 'div' );
        el.className = 'brikpanel-cs-item';

        var main = document.createElement( 'div' );
        main.className = 'brikpanel-cs-item-main';

        var name = document.createElement( 'div' );
        name.className = 'brikpanel-cs-item-name';
        name.textContent = row.name;
        main.appendChild( name );

        if ( row.isVariable ) {
            main.appendChild( buildVariationControl( row ) );
        }
        el.appendChild( main );

        // Quantity stepper
        var qtyWrap = document.createElement( 'div' );
        qtyWrap.className = 'brikpanel-cs-qty';

        var minus = document.createElement( 'button' );
        minus.type = 'button';
        minus.className = 'brikpanel-cs-qty-btn';
        minus.textContent = '−';
        minus.setAttribute( 'aria-label', t( 'decrease' ) );
        minus.addEventListener( 'click', function () {
            row.qty = Math.max( 1, row.qty - 1 );
            renderRows();
            updateLink();
        } );

        var qtyInput = document.createElement( 'input' );
        qtyInput.type = 'text';
        qtyInput.className = 'brikpanel-cs-qty-input';
        qtyInput.value = String( row.qty );
        qtyInput.setAttribute( 'inputmode', 'numeric' );
        qtyInput.setAttribute( 'aria-label', t( 'quantity' ) );
        qtyInput.addEventListener( 'input', function () {
            var v = parseInt( qtyInput.value.replace( /[^0-9]/g, '' ), 10 );
            row.qty = isNaN( v ) || v < 1 ? 1 : v;
            updateLink();
        } );
        qtyInput.addEventListener( 'blur', function () {
            qtyInput.value = String( row.qty );
        } );

        var plus = document.createElement( 'button' );
        plus.type = 'button';
        plus.className = 'brikpanel-cs-qty-btn';
        plus.textContent = '+';
        plus.setAttribute( 'aria-label', t( 'increase' ) );
        plus.addEventListener( 'click', function () {
            row.qty += 1;
            renderRows();
            updateLink();
        } );

        qtyWrap.appendChild( minus );
        qtyWrap.appendChild( qtyInput );
        qtyWrap.appendChild( plus );
        el.appendChild( qtyWrap );

        // Remove
        var remove = document.createElement( 'button' );
        remove.type = 'button';
        remove.className = 'brikpanel-cs-item-remove';
        remove.setAttribute( 'aria-label', t( 'remove' ) );
        remove.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        remove.addEventListener( 'click', function () {
            removeRow( row.uid );
        } );
        el.appendChild( remove );

        return el;
    }

    function buildVariationControl( row ) {
        var wrap = document.createElement( 'div' );
        wrap.className = 'brikpanel-cs-item-variation';

        if ( row.loadingVariations ) {
            var loading = document.createElement( 'span' );
            loading.className = 'brikpanel-cs-item-hint';
            loading.textContent = t( 'loading_variations' );
            wrap.appendChild( loading );
            return wrap;
        }

        if ( row.variations && ! row.variations.length ) {
            var noneHint = document.createElement( 'span' );
            noneHint.className = 'brikpanel-cs-item-hint is-warning';
            noneHint.textContent = t( 'no_shareable_variations' );
            wrap.appendChild( noneHint );
            return wrap;
        }

        var select = document.createElement( 'select' );
        select.className = 'brikpanel-cs-variation-select';

        var placeholder = document.createElement( 'option' );
        placeholder.value = '';
        placeholder.textContent = t( 'select_variation' );
        select.appendChild( placeholder );

        ( row.variations || [] ).forEach( function ( v ) {
            var opt = document.createElement( 'option' );
            opt.value = String( v.id );
            opt.textContent = v.label + ' — ' + v.price;
            if ( v.id === row.variationId ) {
                opt.selected = true;
            }
            select.appendChild( opt );
        } );

        if ( ! row.variationId ) {
            select.classList.add( 'is-required' );
        }

        select.addEventListener( 'change', function () {
            row.variationId = parseInt( select.value, 10 ) || 0;
            select.classList.toggle( 'is-required', ! row.variationId );
            updateLink();
        } );

        wrap.appendChild( select );
        return wrap;
    }

    // ---------------------------------------------------------------------
    // Link
    // ---------------------------------------------------------------------

    function buildTokens() {
        var tokens = [];
        var incomplete = false;

        rows.forEach( function ( row ) {
            if ( row.isVariable && ! row.variationId ) {
                incomplete = true;
                return;
            }
            var qty = Math.max( 1, row.qty );
            if ( row.isVariable && row.variationId ) {
                tokens.push( row.productId + ':' + qty + ':' + row.variationId );
            } else if ( qty > 1 ) {
                tokens.push( row.productId + ':' + qty );
            } else {
                tokens.push( String( row.productId ) );
            }
        } );

        return { tokens: tokens, incomplete: incomplete };
    }

    function currentLink() {
        var built = buildTokens();
        if ( ! built.tokens.length ) {
            return '';
        }
        return cfg.linkPrefix + built.tokens.join( ',' );
    }

    function updateLink() {
        var link = currentLink();
        var built = buildTokens();

        els.link.value = link;
        els.copy.disabled = ! link;

        if ( link ) {
            els.whatsapp.hidden = false;
            els.whatsapp.href = cfg.whatsappBase + encodeURIComponent( t( 'shareText' ) + ' ' + link );
            if ( cfg.canNativeShare ) {
                els.nativeShare.hidden = false;
            }
        } else {
            els.whatsapp.hidden = true;
            els.nativeShare.hidden = true;
        }

        if ( built.incomplete && els.incompleteNote ) {
            els.incompleteNote.hidden = false;
        } else if ( els.incompleteNote ) {
            els.incompleteNote.hidden = true;
        }
    }

    function copyLink() {
        var link = els.link.value;
        if ( ! link ) {
            return;
        }
        copyText( link ).then( function () {
            toast( t( 'copied' ), 'success' );
        } ).catch( function () {
            els.link.select();
            toast( t( 'copy_failed' ), 'error' );
        } );
    }

    function copyText( text ) {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            return navigator.clipboard.writeText( text );
        }
        return new Promise( function ( resolve, reject ) {
            try {
                els.link.select();
                var ok = document.execCommand( 'copy' );
                ok ? resolve() : reject();
            } catch ( e ) {
                reject( e );
            }
        } );
    }

    function nativeShare() {
        var link = els.link.value;
        if ( ! link || ! navigator.share ) {
            return;
        }
        navigator.share( {
            title: t( 'share_title' ),
            text: t( 'shareText' ),
            url: link,
        } ).catch( function () {} );
    }

    // ---------------------------------------------------------------------
    // Init
    // ---------------------------------------------------------------------

    ready( function () {
        var root = document.getElementById( 'brikpanel-cart-share' );
        if ( ! root ) {
            return;
        }

        els.search = document.getElementById( 'bpcs-search' );
        els.suggestions = document.getElementById( 'bpcs-suggestions' );
        els.items = document.getElementById( 'bpcs-items' );
        els.empty = document.getElementById( 'bpcs-empty' );
        els.link = document.getElementById( 'bpcs-link' );
        els.copy = document.getElementById( 'bpcs-copy' );
        els.whatsapp = document.getElementById( 'bpcs-whatsapp' );
        els.nativeShare = document.getElementById( 'bpcs-native-share' );
        els.toast = document.getElementById( 'bpcs-toast-container' );
        els.incompleteNote = document.getElementById( 'bpcs-incomplete-note' );

        cfg.canNativeShare = !! navigator.share;

        els.search.addEventListener( 'input', onSearchInput );
        els.search.addEventListener( 'keydown', onSearchKeydown );
        els.search.addEventListener( 'blur', function () {
            setTimeout( hideSuggestions, 150 );
        } );
        els.copy.addEventListener( 'click', copyLink );
        if ( els.nativeShare ) {
            els.nativeShare.addEventListener( 'click', nativeShare );
        }

        updateLink();
    } );
} )();
