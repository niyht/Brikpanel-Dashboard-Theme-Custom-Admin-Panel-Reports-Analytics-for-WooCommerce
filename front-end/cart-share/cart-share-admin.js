/**
 * BrikPanel - Cart Share (admin builder)
 *
 * All user-facing copy comes from the localized `brikpanelCartShareAdmin.i18n`
 * object or from the page's own markup; nothing readable is hardcoded here
 * (see the i18n audit rule).
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

    // Coupon box.
    var coupon = null;          // the coupon on the link: { id, code, summary }
    var couponOptions = [];     // rows on screen: { kind: 'coupon', item } or { kind: 'create', code }
    var couponActive = -1;
    var couponTimer = null;
    var couponSeq = 0;          // a slower, older answer never replaces a newer one
    var creating = false;

    function t( key ) {
        return typeof i18n[ key ] === 'string' ? i18n[ key ] : '';
    }

    // A function replacement, so a "$&" inside a coupon code stays literal.
    function fill( pattern, value ) {
        return String( pattern ).replace( '%s', function () {
            return value;
        } );
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
        if ( ! container || ! message ) {
            return;
        }
        var el = document.createElement( 'div' );
        // is-*, never a bare `error`: WordPress styles every div.error as an admin notice.
        el.className = 'brikpanel-cs-toast ' + ( type === 'error' ? 'is-error' : 'is-success' );
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
        select.className = 'brikpanel-cs-variation-select brikpanel-control';

        var placeholder = document.createElement( 'option' );
        placeholder.value = '';
        placeholder.textContent = t( 'select_variation' );
        select.appendChild( placeholder );

        ( row.variations || [] ).forEach( function ( v ) {
            var opt = document.createElement( 'option' );
            opt.value = String( v.id );
            opt.textContent = v.label + ' · ' + v.price;
            if ( v.id === row.variationId ) {
                opt.selected = true;
            }
            select.appendChild( opt );
        } );

        if ( ! row.variationId ) {
            select.classList.add( 'is-required', 'is-error' );
        }

        select.addEventListener( 'change', function () {
            row.variationId = parseInt( select.value, 10 ) || 0;
            select.classList.toggle( 'is-required', ! row.variationId );
            select.classList.toggle( 'is-error', ! row.variationId );
            updateLink();
        } );

        wrap.appendChild( select );
        return wrap;
    }

    // ---------------------------------------------------------------------
    // Coupon: search the store's coupons, or make a new one
    // ---------------------------------------------------------------------

    function openCouponList() {
        if ( els.couponSuggestions.hidden ) {
            runCouponSearch( els.couponSearch.value.trim() );
        }
    }

    function onCouponInput() {
        var term = els.couponSearch.value.trim();
        clearTimeout( couponTimer );
        couponTimer = setTimeout( function () {
            runCouponSearch( term );
        }, 220 );
    }

    function runCouponSearch( term ) {
        var seq = ++couponSeq;
        // Keep the rows on screen while typing; only an empty box says "Searching".
        if ( els.couponSuggestions.hidden ) {
            var box = els.couponSuggestions;
            box.innerHTML = '';
            var l = document.createElement( 'div' );
            l.className = 'brikpanel-cs-suggestion is-muted';
            l.textContent = t( 'searching' );
            box.appendChild( l );
            box.hidden = false;
            setCouponExpanded( true );
        }
        ajax( 'brikpanel_cartshare_search_coupons', { q: term } ).then( function ( res ) {
            // Overtaken by a newer search, or the box was closed meanwhile.
            if ( seq !== couponSeq || document.activeElement !== els.couponSearch ) {
                return;
            }
            var items = res && res.success && res.data && res.data.items ? res.data.items : [];
            renderCouponOptions( items, term );
        } ).catch( function () {
            if ( seq === couponSeq ) {
                hideCouponSuggestions();
            }
        } );
    }

    function renderCouponOptions( items, term ) {
        var box = els.couponSuggestions;
        var lower = term.toLowerCase();
        var exact = false;

        box.innerHTML = '';
        couponOptions = [];
        couponActive = -1;
        els.couponSearch.removeAttribute( 'aria-activedescendant' );

        items.forEach( function ( item ) {
            if ( lower && String( item.code ).toLowerCase() === lower ) {
                exact = true;
            }
            couponOptions.push( { kind: 'coupon', item: item } );
        } );
        // A code that already exists cannot be created again.
        if ( cfg.canCreateCoupon && ! exact ) {
            couponOptions.push( { kind: 'create', code: term } );
        }

        if ( ! couponOptions.length ) {
            var none = document.createElement( 'div' );
            none.className = 'brikpanel-cs-suggestion is-muted';
            none.textContent = t( 'no_coupons' );
            box.appendChild( none );
        } else {
            couponOptions.forEach( function ( opt, idx ) {
                box.appendChild( buildCouponOption( opt, idx ) );
            } );
        }
        box.hidden = false;
        setCouponExpanded( true );
    }

    function buildCouponOption( opt, idx ) {
        var row = document.createElement( 'div' );
        row.id = 'bpcs-coupon-option-' + idx;
        row.setAttribute( 'role', 'option' );
        row.setAttribute( 'data-idx', String( idx ) );

        if ( opt.kind === 'create' ) {
            row.className = 'brikpanel-cs-suggestion brikpanel-cs-coupon-create';
            row.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
            var label = document.createElement( 'span' );
            label.textContent = opt.code ? fill( t( 'create_named' ), opt.code ) : t( 'create_new' );
            row.appendChild( label );
        } else {
            var item = opt.item;
            row.className = 'brikpanel-cs-suggestion brikpanel-cs-coupon-option' + ( item.state ? ' is-disabled' : '' );
            if ( item.state ) {
                row.setAttribute( 'aria-disabled', 'true' );
            }

            var main = document.createElement( 'span' );
            main.className = 'brikpanel-cs-coupon-option-main';
            var code = document.createElement( 'span' );
            code.className = 'brikpanel-cs-coupon-option-code';
            code.textContent = item.code;
            main.appendChild( code );
            if ( item.summary ) {
                var summary = document.createElement( 'span' );
                summary.className = 'brikpanel-cs-coupon-option-summary';
                summary.textContent = item.summary;
                main.appendChild( summary );
            }
            row.appendChild( main );

            if ( item.note ) {
                var note = document.createElement( 'span' );
                note.className = 'brikpanel-cs-coupon-option-note';
                note.textContent = item.note;
                row.appendChild( note );
            }
        }

        row.addEventListener( 'mousedown', function ( e ) {
            e.preventDefault();
            chooseCouponOption( idx );
        } );
        return row;
    }

    function selectableCouponOptions() {
        var list = [];
        couponOptions.forEach( function ( opt, idx ) {
            if ( opt.kind === 'create' || ! opt.item.state ) {
                list.push( idx );
            }
        } );
        return list;
    }

    function highlightCoupon() {
        var active = null;
        Array.prototype.forEach.call( els.couponSuggestions.querySelectorAll( '[data-idx]' ), function ( row ) {
            var on = parseInt( row.getAttribute( 'data-idx' ), 10 ) === couponActive;
            row.classList.toggle( 'is-active', on );
            row.setAttribute( 'aria-selected', on ? 'true' : 'false' );
            if ( on ) {
                active = row;
            }
        } );
        if ( active ) {
            els.couponSearch.setAttribute( 'aria-activedescendant', active.id );
            active.scrollIntoView( { block: 'nearest' } );
        } else {
            els.couponSearch.removeAttribute( 'aria-activedescendant' );
        }
    }

    function onCouponKeydown( e ) {
        if ( e.key === 'Escape' ) {
            hideCouponSuggestions();
            return;
        }
        if ( els.couponSuggestions.hidden ) {
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                openCouponList();
            }
            return;
        }

        var selectable = selectableCouponOptions();
        if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
            e.preventDefault();
            if ( ! selectable.length ) {
                return;
            }
            var pos = selectable.indexOf( couponActive );
            pos = e.key === 'ArrowDown' ? Math.min( pos + 1, selectable.length - 1 ) : Math.max( pos - 1, 0 );
            couponActive = selectable[ pos ];
            highlightCoupon();
        } else if ( e.key === 'Enter' ) {
            e.preventDefault();
            if ( couponActive >= 0 ) {
                chooseCouponOption( couponActive );
                return;
            }
            // No row picked: the exact code if it is in the list, else "Create".
            var term = els.couponSearch.value.trim().toLowerCase();
            for ( var i = 0; i < couponOptions.length; i++ ) {
                var opt = couponOptions[ i ];
                if ( opt.kind === 'coupon' && ! opt.item.state && String( opt.item.code ).toLowerCase() === term ) {
                    chooseCouponOption( i );
                    return;
                }
            }
            if ( term && couponOptions.length && couponOptions[ couponOptions.length - 1 ].kind === 'create' ) {
                chooseCouponOption( couponOptions.length - 1 );
            }
        }
    }

    function chooseCouponOption( idx ) {
        var opt = couponOptions[ idx ];
        if ( ! opt ) {
            return;
        }
        if ( opt.kind === 'create' ) {
            openCreateForm( opt.code );
            return;
        }
        // Greyed out (expired, used up): the link would only show the customer an error.
        if ( opt.item.state ) {
            return;
        }
        setCoupon( opt.item );
    }

    function hideCouponSuggestions() {
        couponSeq++;
        clearTimeout( couponTimer );
        els.couponSuggestions.hidden = true;
        els.couponSuggestions.innerHTML = '';
        couponOptions = [];
        couponActive = -1;
        els.couponSearch.removeAttribute( 'aria-activedescendant' );
        setCouponExpanded( false );
    }

    function setCouponExpanded( open ) {
        els.couponSearch.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
    }

    function setCoupon( item ) {
        coupon = item ? { id: item.id, code: String( item.code ), summary: item.summary || '' } : null;
        hideCouponSuggestions();
        els.couponSearch.value = '';
        els.couponPicker.hidden = !! coupon;
        els.couponSelected.hidden = ! coupon;
        if ( coupon ) {
            els.couponCode.textContent = coupon.code;
            els.couponSummary.textContent = coupon.summary;
            els.couponSummary.hidden = ! coupon.summary;
        }
        updateLink();
    }

    // -- New coupon ---------------------------------------------------------

    function generateCode() {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // i18n-ignore: code alphabet, not text
        var out = '';
        var bytes = new Uint8Array( 8 );
        if ( window.crypto && window.crypto.getRandomValues ) {
            window.crypto.getRandomValues( bytes );
        } else {
            for ( var j = 0; j < bytes.length; j++ ) {
                bytes[ j ] = Math.floor( Math.random() * 256 );
            }
        }
        for ( var i = 0; i < bytes.length; i++ ) {
            out += chars.charAt( bytes[ i ] % chars.length );
        }
        return out;
    }

    function openCreateForm( code ) {
        if ( ! els.couponNew ) {
            return;
        }
        hideCouponSuggestions();
        els.couponPicker.hidden = true;
        els.couponNew.hidden = false;
        els.newCode.value = code || generateCode();
        els.newAmount.value = '';
        setCreateError( '', '' );
        syncAmountPrefix();
        // The code is usually what was just typed; the amount comes next.
        els.newAmount.focus();
    }

    function closeCreateForm() {
        els.couponNew.hidden = true;
        els.couponPicker.hidden = !! coupon;
        setCreateError( '', '' );
    }

    function syncAmountPrefix() {
        els.newAmountPrefix.textContent = els.newType.value === 'percent' ? cfg.percentSign : cfg.currencySymbol;
    }

    /**
     * The amount as "1234.5" whatever marks were typed. The last mark is the
     * decimal one, except a store's thousands mark with three digits after it
     * ("1.000" in Turkish lira is a thousand, "7.5" is seven and a half).
     */
    function normalizeAmount( raw ) {
        var s = String( raw || '' ).replace( /[^0-9.,]/g, '' );
        var at = Math.max( s.lastIndexOf( '.' ), s.lastIndexOf( ',' ) );
        if ( at === -1 ) {
            return s;
        }
        var mark = s.charAt( at );
        var tail = s.slice( at + 1 );
        if ( mark === cfg.thousandSep && mark !== cfg.decimalSep && /^\d{3}$/.test( tail ) ) {
            return s.replace( /[.,]/g, '' );
        }
        return s.slice( 0, at ).replace( /[.,]/g, '' ) + '.' + tail;
    }

    function setCreateError( message, field ) {
        els.newError.textContent = message;
        els.newError.hidden = ! message;
        els.newCode.classList.toggle( 'is-error', field === 'code' );
        els.newAmountGroup.classList.toggle( 'is-error', field === 'amount' );
        if ( field === 'code' ) {
            els.newCode.focus();
        } else if ( field === 'amount' ) {
            els.newAmount.focus();
        }
    }

    function submitCreate() {
        if ( creating ) {
            return;
        }
        var code = els.newCode.value.trim();
        var amount = normalizeAmount( els.newAmount.value );
        var number = parseFloat( amount );

        if ( ! code ) {
            setCreateError( t( 'code_required' ), 'code' );
            return;
        }
        if ( ! amount || isNaN( number ) || number <= 0 ) {
            setCreateError( t( 'amount_required' ), 'amount' );
            return;
        }
        if ( els.newType.value === 'percent' && number > 100 ) {
            setCreateError( t( 'percent_max' ), 'amount' );
            return;
        }

        setCreateError( '', '' );
        creating = true;
        els.newSave.disabled = true;

        ajax( 'brikpanel_cartshare_create_coupon', {
            code: code,
            discount_type: els.newType.value,
            amount: amount,
        } ).then( function ( res ) {
            creating = false;
            els.newSave.disabled = false;
            if ( res && res.success && res.data && res.data.coupon ) {
                closeCreateForm();
                setCoupon( res.data.coupon );
                toast( res.data.message, 'success' );
                return;
            }
            var data = res && res.data ? res.data : {};
            setCreateError( data.message || t( 'create_failed' ), data.field || '' );
        } ).catch( function () {
            creating = false;
            els.newSave.disabled = false;
            setCreateError( t( 'create_failed' ), '' );
        } );
    }

    function onCreateKeydown( e ) {
        if ( e.key === 'Enter' && e.target.tagName === 'INPUT' ) {
            e.preventDefault();
            submitCreate();
        } else if ( e.key === 'Escape' ) {
            e.preventDefault();
            closeCreateForm();
            if ( ! coupon ) {
                els.couponSearch.focus();
            }
        }
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

    function toCheckout() {
        return !! ( els.checkout && els.checkout.checked );
    }

    function currentLink() {
        var built = buildTokens();
        if ( ! built.tokens.length ) {
            return '';
        }
        var link = cfg.linkPrefix + built.tokens.join( ',' );
        if ( coupon ) {
            link += '&' + cfg.couponVar + '=' + encodeURIComponent( coupon.code );
        }
        if ( toCheckout() ) {
            link += '&' + cfg.checkoutVar + '=1';
        }
        return link;
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

        if ( els.hint ) {
            els.hint.textContent = toCheckout() ? t( 'hint_checkout' ) : t( 'hint_cart' );
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

    function initCoupon() {
        els.couponSearch = document.getElementById( 'bpcs-coupon-search' );
        if ( ! els.couponSearch ) {
            // Coupons are turned off in the store: the card shows a note instead.
            return;
        }
        els.couponPicker = document.getElementById( 'bpcs-coupon-picker' );
        els.couponSuggestions = document.getElementById( 'bpcs-coupon-suggestions' );
        els.couponSelected = document.getElementById( 'bpcs-coupon-selected' );
        els.couponCode = document.getElementById( 'bpcs-coupon-code' );
        els.couponSummary = document.getElementById( 'bpcs-coupon-summary' );
        els.couponRemove = document.getElementById( 'bpcs-coupon-remove' );

        els.couponSearch.addEventListener( 'input', onCouponInput );
        els.couponSearch.addEventListener( 'keydown', onCouponKeydown );
        els.couponSearch.addEventListener( 'click', openCouponList );
        els.couponSearch.addEventListener( 'blur', function () {
            setTimeout( function () {
                if ( document.activeElement !== els.couponSearch ) {
                    hideCouponSuggestions();
                }
            }, 150 );
        } );
        els.couponRemove.addEventListener( 'click', function () {
            setCoupon( null );
            els.couponSearch.focus();
        } );

        els.couponNew = document.getElementById( 'bpcs-coupon-new' );
        if ( ! els.couponNew ) {
            // This user may pick coupons but not create them.
            return;
        }
        els.newCode = document.getElementById( 'bpcs-new-code' );
        els.newType = document.getElementById( 'bpcs-new-type' );
        els.newAmount = document.getElementById( 'bpcs-new-amount' );
        els.newAmountGroup = document.getElementById( 'bpcs-new-amount-group' );
        els.newAmountPrefix = document.getElementById( 'bpcs-new-amount-prefix' );
        els.newError = document.getElementById( 'bpcs-new-error' );
        els.newSave = document.getElementById( 'bpcs-new-save' );

        els.newType.addEventListener( 'change', syncAmountPrefix );
        els.newSave.addEventListener( 'click', submitCreate );
        document.getElementById( 'bpcs-new-cancel' ).addEventListener( 'click', function () {
            closeCreateForm();
            els.couponSearch.focus();
        } );
        els.couponNew.addEventListener( 'keydown', onCreateKeydown );
        // Typing again answers the message: it goes, with its red edge.
        els.newCode.addEventListener( 'input', clearCreateError );
        els.newAmount.addEventListener( 'input', clearCreateError );
        els.newType.addEventListener( 'change', clearCreateError );
    }

    function clearCreateError() {
        els.newError.textContent = '';
        els.newError.hidden = true;
        els.newCode.classList.remove( 'is-error' );
        els.newAmountGroup.classList.remove( 'is-error' );
    }

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
        els.checkout = document.getElementById( 'bpcs-checkout' );
        els.hint = document.getElementById( 'bpcs-hint' );

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
        if ( els.checkout ) {
            els.checkout.addEventListener( 'change', updateLink );
        }
        initCoupon();

        updateLink();
    } );
} )();
