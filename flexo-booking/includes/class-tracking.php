<?php
/**
 * Conversion tracking (feature "tracking").
 *
 * The booking form (booking.js) pushes four events to window.dataLayer:
 * search, room_select, begin_checkout and booking_complete. Tag Manager
 * decides what goes to GA4 or Meta, so consent-mode / cookie plugins stay in
 * control. Events never contain names, emails or phone numbers.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Tracking {

	public static function init() {
		// Nothing server-side: events are sent by the booking form.
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'tracking' );
	}

	/**
	 * Settings for booking.js; false when tracking is off.
	 *
	 * @return array|false
	 */
	public static function config() {
		if ( ! self::enabled() ) {
			return false;
		}
		return array(
			'currency'  => Flexo_Booking_Money::currency(),
			'metaPixel' => (bool) Flexo_Booking_Settings::get( 'tracking_meta_pixel' ),
		);
	}
}
