/**
 * BrikPanel – Variation Gallery Swap (Frontend)
 *
 * When a WooCommerce variation with extra gallery images is selected,
 * replaces the product gallery slides and reinitialises FlexSlider.
 *
 * @package BrikPanel
 * @since 1.6.0
 */
(function ($) {
    'use strict';

    $(function () {
        var $form = $('form.variations_form');
        if (!$form.length) return;

        var $gallery = $('.woocommerce-product-gallery');
        if (!$gallery.length) return;

        // Wait for FlexSlider to finish init, then grab original slides
        var originalSlidesHTML = '';
        var swapped = false;

        setTimeout(function () {
            var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');
            if ($wrapper.length) {
                originalSlidesHTML = $wrapper.html();
            }
        }, 500);

        // Listen for variation selection (after WC's own handler)
        $form.on('found_variation.brikpanel', function (e, variation) {
            var extra = variation.brikpanel_gallery_images;
            if (!extra || !extra.length) {
                if (swapped) resetGallery();
                return;
            }
            // Let WC finish its own image swap first
            setTimeout(function () { swapGallery(variation, extra); }, 150);
        });

        $form.on('reset_data.brikpanel', function () {
            if (swapped) resetGallery();
        });

        function swapGallery(variation, extraImages) {
            var slidesHTML = '';

            // Main variation image
            var img = variation.image || {};
            if (img.src) {
                slidesHTML += slide(img.src, img.full_src, img.gallery_thumbnail_src, img.alt, img.srcset, img.sizes);
            }

            // Extra gallery images
            extraImages.forEach(function (gi) {
                slidesHTML += slide(gi.src, gi.full_src, gi.thumbnail_src, gi.alt, gi.srcset, gi.sizes);
            });

            if (!slidesHTML) return;

            rebuildGallery(slidesHTML);
            swapped = true;
        }

        function resetGallery() {
            if (originalSlidesHTML) {
                rebuildGallery(originalSlidesHTML);
            }
            swapped = false;
        }

        function rebuildGallery(slidesHTML) {
            // 1. Tear down FlexSlider
            // Unwrap the wrapper from .flex-viewport (FlexSlider wraps it)
            var $viewport = $gallery.find('.flex-viewport');
            if ($viewport.length) {
                $viewport.children().unwrap();
            }

            // Remove FlexSlider-generated elements
            $gallery.find('.flex-control-thumbs, .flex-direction-nav').remove();

            // Remove FlexSlider data
            $gallery.removeData('flexslider');

            // 2. Replace slides
            var $wrapper = $gallery.find('.woocommerce-product-gallery__wrapper');
            if ($wrapper.length) {
                $wrapper.html(slidesHTML);
                // Clean up FlexSlider classes
                $wrapper.find('.woocommerce-product-gallery__image').removeClass('flex-active-slide clone');
            }

            // 3. Remove old WC gallery data so it reinitialises fresh
            $gallery.removeData('product_gallery');
            $gallery.removeData('flexslider');

            // 4. Re-init WooCommerce product gallery (FlexSlider + zoom + photoswipe)
            if (typeof $.fn.wc_product_gallery === 'function') {
                $gallery.wc_product_gallery();
            }
        }

        function slide(src, fullSrc, thumbSrc, alt, srcset, sizes) {
            var attrs = '';
            if (srcset) attrs += ' srcset="' + srcset + '"';
            if (sizes) attrs += ' sizes="' + sizes + '"';
            return '<div class="woocommerce-product-gallery__image" data-thumb="' +
                (thumbSrc || src) + '"><a href="' + (fullSrc || src) + '">' +
                '<img src="' + src + '" class="wp-post-image" alt="' +
                (alt || '') + '"' + attrs + '></a></div>';
        }
    });
})(jQuery);
