/**
 * BrikPanel — hierarchical taxonomy metabox shim.
 *
 * Drives the HTML output by WP core's `post_categories_meta_box` on the
 * BrikPanel product editor page:
 *   - Tab switching between "All" and "Most Used" panels
 *   - "+ Add New X" link toggles the inline add-new form
 *   - Submitting the add-new form hits admin-ajax `add-{taxonomy}` and
 *     splices the new <li> into the checklist + refreshes the parent
 *     <select>, replicating wp-admin/js/post.js behavior.
 *
 * All selectors are scoped to `.brikpanel-pe-metaboxes-wrap` so this script
 * cannot leak into other admin pages or touch BrikPanel's own status /
 * visibility widgets.
 */
(function ($) {
    'use strict';

    var SCOPE = '.brikpanel-pe-metaboxes-wrap';

    $(function () {
        bindTabs();
        bindAddToggle();
        bindAddSubmit();
    });

    function bindTabs() {
        $(document).on('click', SCOPE + ' .categorydiv .category-tabs a', function (e) {
            e.preventDefault();
            var $a = $(this);
            var $tabs = $a.closest('ul.category-tabs');
            var target = $a.attr('href');
            if (!target) return;
            $tabs.find('li').removeClass('tabs');
            $a.parent('li').addClass('tabs');
            $tabs.siblings('.tabs-panel').hide();
            $(target).show();
        });
    }

    function bindAddToggle() {
        $(document).on('click', SCOPE + ' .categorydiv .taxonomy-add-new', function (e) {
            e.preventDefault();
            var target = $(this).attr('href');
            if (target) $(target).toggleClass('wp-hidden-child');
        });
    }

    function bindAddSubmit() {
        $(document).on('click', SCOPE + ' .categorydiv .category-add-submit', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $div = $btn.closest('.categorydiv');
            var taxonomy = extractTaxonomy($div.attr('id'));
            if (!taxonomy) return;

            var $input = $div.find('input[type="text"][name^="new"]').first();
            var $parent = $div.find('select[name^="new"][name$="_parent"]').first();
            var $nonce = $div.find('input[name="_ajax_nonce-add-' + taxonomy + '"]').first();

            var name = $.trim($input.val() || '');
            if (!name) { $input.trigger('focus'); return; }

            $btn.prop('disabled', true);

            // Build POST exactly the way core's wpList-based submit does.
            var payload = {
                action: 'add-' + taxonomy,
                taxonomy: taxonomy,
                post_category: 0
            };
            payload['new' + taxonomy] = name;
            if ($parent.length) payload['new' + taxonomy + '_parent'] = $parent.val() || -1;
            if ($nonce.length)  payload['_ajax_nonce-add-' + taxonomy] = $nonce.val();

            // Force dataType:"text" so jQuery hands us the raw XML string.
            // admin-ajax sends `Content-Type: text/xml` for WP_Ajax_Response,
            // which makes jQuery auto-parse into an XMLDocument — our parser
            // expects a string, so we short-circuit that auto-detection.
            $.ajax({
                url: brikpanelPEHierTax.ajax_url,
                type: 'POST',
                data: payload,
                dataType: 'text'
            }).done(function (xml) {
                applyAddResponse($div, xml);
                $input.val('').trigger('focus');
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    /**
     * Parse the XML-ish WP_Ajax_Response body and splice in the new term.
     *
     * Response shape (trimmed):
     *   <wp_ajax>
     *     <response action="add-{tax}_{id}">
     *       <{tax} id="{id}" position="-1">
     *         <response_data><![CDATA[<li>...</li>]]></response_data>
     *         <supplemental>
     *           <newcat_parent><![CDATA[<select>...</select>]]></newcat_parent>
     *         </supplemental>
     *       </{tax}>
     *     </response>
     *   </wp_ajax>
     */
    function applyAddResponse($div, xml) {
        if (!xml || typeof xml !== 'string') return;
        var doc;
        try {
            doc = new DOMParser().parseFromString(xml, 'text/xml');
        } catch (err) { return; }
        if (!doc || doc.getElementsByTagName('parsererror').length) return;

        var respNode = doc.getElementsByTagName('response_data')[0];
        if (!respNode) return;
        var liHtml = (respNode.textContent || '').replace(/^\s+|\s+$/g, '');
        if (!liHtml) return;

        // The "All" panel's checklist — its id has no `-pop` suffix — is the
        // authoritative list the saved tax_input reads from. The "Most Used"
        // panel has its own separate UL we must skip. Prepend so the newly
        // added term appears at the top — matches wp-admin UX.
        var $checklist = $div.find('ul.categorychecklist')
            .filter(function () { return this.id && this.id.indexOf('-pop') === -1; })
            .first();
        if ($checklist.length) {
            $checklist.prepend(liHtml);
        }

        // Replace the parent <select> options with the supplemental payload.
        // WP core returns it under <newcat_parent>, containing a full <select>
        // element whose inner options we hoist over the existing select's
        // children so the just-added term becomes a pickable parent.
        var parentNode = doc.getElementsByTagName('newcat_parent')[0];
        if (parentNode) {
            var parentHtml = (parentNode.textContent || '').replace(/^\s+|\s+$/g, '');
            if (parentHtml) {
                var temp = document.createElement('div');
                temp.innerHTML = parentHtml;
                var newSelect = temp.querySelector('select');
                if (newSelect) {
                    $div.find('select[name^="new"][name$="_parent"]').html(newSelect.innerHTML);
                }
            }
        }
    }

    /**
     * Extract the taxonomy slug from a core `.categorydiv` id. Core uses two
     * patterns: `taxonomy-{slug}` (the wp-admin edit screen) and `{slug}div`
     * (back-compat and what BrikPanel registers). Handle both.
     */
    function extractTaxonomy(id) {
        if (!id) return '';
        if (id.indexOf('taxonomy-') === 0) {
            return id.substring(9);
        }
        if (id.length > 3 && id.substring(id.length - 3) === 'div') {
            return id.substring(0, id.length - 3);
        }
        return '';
    }
})(jQuery);
