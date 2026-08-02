/**
 * BrikPanel - Cart Share (storefront button)
 *
 * Adds a "Share cart" button to the cart page. Clicking it opens a small panel
 * with a link that reproduces the current cart, plus copy / WhatsApp / native
 * share. The link is refreshed from the server each time the panel opens, so it
 * always matches the current cart (quantities included).
 *
 * All user-facing copy comes from `brikpanelCartShare.i18n`.
 */
( function () {
    'use strict';

    var cfg = window.brikpanelCartShare || {};
    var i18n = cfg.i18n || {};
    var currentLink = cfg.initialLink || '';
    var mounted = false;
    var refs = {};

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

    function findMount() {
        // Ordered from most specific (block cart / shortcode actions) to broad.
        var selectors = [
            '.wc-block-cart__submit',
            '.wp-block-woocommerce-cart .wc-block-components-sidebar',
            '.cart_totals',
            '.woocommerce-cart-form .actions',
            '.woocommerce-cart-form',
            '.wp-block-woocommerce-cart',
            '.woocommerce',
        ];
        for ( var i = 0; i < selectors.length; i++ ) {
            var el = document.querySelector( selectors[ i ] );
            if ( el ) {
                return el;
            }
        }
        return null;
    }

    function buildButton() {
        var wrap = document.createElement( 'div' );
        wrap.className = 'brikpanel-cshare-wrap';

        var btn = document.createElement( 'button' );
        btn.type = 'button';
        btn.className = 'brikpanel-cshare-btn';
        btn.innerHTML =
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>' +
            '<span></span>';
        btn.querySelector( 'span' ).textContent = t( 'button' );
        btn.addEventListener( 'click', openPanel );

        wrap.appendChild( btn );
        return wrap;
    }

    function buildPanel() {
        var overlay = document.createElement( 'div' );
        overlay.className = 'brikpanel-cshare-overlay';
        overlay.addEventListener( 'click', function ( e ) {
            if ( e.target === overlay ) {
                closePanel();
            }
        } );

        var panel = document.createElement( 'div' );
        panel.className = 'brikpanel-cshare-panel';
        panel.setAttribute( 'role', 'dialog' );
        panel.setAttribute( 'aria-modal', 'true' );

        // Header
        var head = document.createElement( 'div' );
        head.className = 'brikpanel-cshare-head';
        var title = document.createElement( 'h3' );
        title.textContent = t( 'title' );
        var close = document.createElement( 'button' );
        close.type = 'button';
        close.className = 'brikpanel-cshare-close';
        close.setAttribute( 'aria-label', t( 'close' ) );
        close.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        close.addEventListener( 'click', closePanel );
        head.appendChild( title );
        head.appendChild( close );

        // Body
        var body = document.createElement( 'div' );
        body.className = 'brikpanel-cshare-body';

        var desc = document.createElement( 'p' );
        desc.className = 'brikpanel-cshare-desc';
        desc.textContent = t( 'description' );

        var linkRow = document.createElement( 'div' );
        linkRow.className = 'brikpanel-cshare-link-row';

        var input = document.createElement( 'input' );
        input.type = 'text';
        input.readOnly = true;
        input.className = 'brikpanel-cshare-input';

        var copyBtn = document.createElement( 'button' );
        copyBtn.type = 'button';
        copyBtn.className = 'brikpanel-cshare-copy';
        copyBtn.textContent = t( 'copy' );
        copyBtn.addEventListener( 'click', function () {
            copyText( input.value ).then( function () {
                copyBtn.textContent = t( 'copied' );
                copyBtn.classList.add( 'is-copied' );
                setTimeout( function () {
                    copyBtn.textContent = t( 'copy' );
                    copyBtn.classList.remove( 'is-copied' );
                }, 1800 );
            } ).catch( function () {
                input.select();
            } );
        } );

        linkRow.appendChild( input );
        linkRow.appendChild( copyBtn );

        var actions = document.createElement( 'div' );
        actions.className = 'brikpanel-cshare-actions';

        var wa = document.createElement( 'a' );
        wa.className = 'brikpanel-cshare-action wa';
        wa.target = '_blank';
        wa.rel = 'noopener noreferrer';
        wa.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91a9.87 9.87 0 0 0-2.9-7A9.87 9.87 0 0 0 12.04 2zm4.52 11.99c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.25-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.16.04-.31-.02-.43-.06-.12-.56-1.35-.77-1.85-.2-.48-.4-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.23.25-.86.84-.86 2.05 0 1.21.88 2.38 1 2.54.12.16 1.73 2.64 4.19 3.7.59.25 1.04.4 1.4.51.59.19 1.12.16 1.54.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29z"/></svg><span></span>';
        wa.querySelector( 'span' ).textContent = t( 'whatsapp' );

        var native = document.createElement( 'button' );
        native.type = 'button';
        native.className = 'brikpanel-cshare-action native';
        native.hidden = ! navigator.share;
        native.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg><span></span>';
        native.querySelector( 'span' ).textContent = t( 'share' );
        native.addEventListener( 'click', function () {
            if ( ! navigator.share || ! input.value ) {
                return;
            }
            navigator.share( {
                title: t( 'title' ),
                text: t( 'shareText' ),
                url: input.value,
            } ).catch( function () {} );
        } );

        actions.appendChild( wa );
        actions.appendChild( native );

        var emptyMsg = document.createElement( 'p' );
        emptyMsg.className = 'brikpanel-cshare-empty';
        emptyMsg.textContent = t( 'empty' );
        emptyMsg.hidden = true;

        body.appendChild( desc );
        body.appendChild( emptyMsg );
        body.appendChild( linkRow );
        body.appendChild( actions );

        panel.appendChild( head );
        panel.appendChild( body );
        overlay.appendChild( panel );

        refs = {
            overlay: overlay,
            input: input,
            wa: wa,
            native: native,
            linkRow: linkRow,
            actions: actions,
            empty: emptyMsg,
            desc: desc,
        };

        document.body.appendChild( overlay );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && overlay.classList.contains( 'is-open' ) ) {
                closePanel();
            }
        } );
    }

    function applyLink( link ) {
        currentLink = link || '';
        var hasLink = !! currentLink;

        refs.linkRow.hidden = ! hasLink;
        refs.actions.hidden = ! hasLink;
        refs.desc.hidden = ! hasLink;
        refs.empty.hidden = hasLink;

        if ( hasLink ) {
            refs.input.value = currentLink;
            refs.wa.href = cfg.whatsappBase + encodeURIComponent( t( 'shareText' ) + ' ' + currentLink );
        }
    }

    function openPanel() {
        if ( ! refs.overlay ) {
            buildPanel();
        }
        applyLink( currentLink );
        refs.overlay.classList.add( 'is-open' );
        document.body.classList.add( 'brikpanel-cshare-lock' );

        // Refresh from server so quantity edits since page load are reflected.
        refreshLink();
    }

    function closePanel() {
        if ( refs.overlay ) {
            refs.overlay.classList.remove( 'is-open' );
        }
        document.body.classList.remove( 'brikpanel-cshare-lock' );
    }

    function refreshLink() {
        var body = new URLSearchParams();
        body.append( 'action', 'brikpanel_cartshare_current' );
        body.append( 'security', cfg.nonce || '' );

        fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        } ).then( function ( r ) {
            return r.json();
        } ).then( function ( res ) {
            if ( res && res.success ) {
                applyLink( res.data.link || '' );
            }
        } ).catch( function () {} );
    }

    function copyText( text ) {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            return navigator.clipboard.writeText( text );
        }
        return new Promise( function ( resolve, reject ) {
            try {
                refs.input.select();
                var ok = document.execCommand( 'copy' );
                ok ? resolve() : reject();
            } catch ( e ) {
                reject( e );
            }
        } );
    }

    function mount() {
        if ( mounted ) {
            return;
        }
        var host = findMount();
        if ( ! host ) {
            return;
        }
        host.appendChild( buildButton() );
        mounted = true;
    }

    ready( function () {
        mount();
        // Block cart and AJAX cart updates re-render the DOM; re-attach if our
        // button was wiped out by a fragment refresh.
        document.body.addEventListener( 'wc-blocks_render_blocks_frontend', function () {
            mounted = false;
            mount();
        } );
        if ( window.jQuery ) {
            // i18n-ignore: WooCommerce jQuery event names, not user-facing text
            window.jQuery( document.body ).on( 'updated_cart_totals updated_wc_div', function () {
                if ( ! document.querySelector( '.brikpanel-cshare-wrap' ) ) {
                    mounted = false;
                    mount();
                }
            } );
        }
    } );
} )();
