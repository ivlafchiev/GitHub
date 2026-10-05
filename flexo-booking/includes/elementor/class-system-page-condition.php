<?php
/**
 * Elementor Pro Theme Builder condition "Flexo Booking pages" (General):
 * a header or footer can be shown on the built-in Booking, Thank You and
 * Contact pages, which are not WordPress pages ("Singular → Page" does not
 * apply to them; "Entire Site" does).
 *
 * Loaded only when Elementor Pro's Theme Builder is active.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_System_Page_Condition extends \ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base {

	public static function get_type() {
		return 'general';
	}

	public function get_name() {
		return 'flexo_booking_pages';
	}

	public function get_label() {
		return __( 'Flexo Booking pages', 'flexo-booking' );
	}

	public function get_all_label() {
		return __( 'Flexo Booking pages', 'flexo-booking' );
	}

	public function check( $args ) {
		return '' !== Flexo_Booking_System_Pages::current();
	}
}
