<?php
/**
 * Activation and upgrade entry points. The work is done by
 * Flexo_Booking_Migrations and Flexo_Booking_Schema.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Install {

	const DB_VERSION_OPTION = Flexo_Booking_Migrations::OPTION;

	public static function activate() {
		Flexo_Booking_Migrations::run();
		Flexo_Booking_Rooms::register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load; a single option comparison when up to date.
	 */
	public static function maybe_upgrade() {
		Flexo_Booking_Migrations::run();
	}

	public static function table() {
		return Flexo_Booking_Schema::table( 'bookings' );
	}

	/**
	 * @deprecated 1.1.0 Kept for code that called it directly.
	 */
	public static function install() {
		Flexo_Booking_Migrations::run();
	}
}
