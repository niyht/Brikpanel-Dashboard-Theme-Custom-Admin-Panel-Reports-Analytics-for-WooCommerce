<?php
/**
 * Customer returns: the Imagick editor used for return photos.
 *
 * WordPress' own Imagick editor keeps the EXIF profile on purpose
 * (WP_Image_Editor_Imagick::strip_meta() lists it as a profile to keep) and
 * only strips inside a resize, so a photo smaller than the limit would be
 * stored with the phone's GPS position in it. Return photos never need any of
 * that: this subclass removes every profile except the colour profile.
 *
 * Loaded only by brikpanel_returns_define_imagick_editor(), after WordPress'
 * Imagick editor class exists.
 *
 * @package BrikPanel
 * @since   3.3.52
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WP_Image_Editor_Imagick', false ) && ! class_exists( 'Brikpanel_Returns_Image_Editor_Imagick', false ) ) {

	class Brikpanel_Returns_Image_Editor_Imagick extends WP_Image_Editor_Imagick {

		/**
		 * Drop every image profile and property but the colour profile.
		 *
		 * @return bool Whether the image was stripped.
		 */
		public function brikpanel_strip() {
			if ( ! $this->image instanceof Imagick ) {
				return false;
			}
			try {
				$icc = '';
				$profiles = $this->image->getImageProfiles( 'icc', true );
				if ( is_array( $profiles ) && ! empty( $profiles['icc'] ) ) {
					$icc = $profiles['icc'];
				}
				$this->image->stripImage();
				if ( '' !== $icc ) {
					$this->image->profileImage( 'icc', $icc );
				}
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}
	}
}
