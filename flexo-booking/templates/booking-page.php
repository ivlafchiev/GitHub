<?php
/**
 * The plugin's built-in booking page, shown at the booking page address
 * (/booking/) while the website has no page with the booking form, so
 * "Book now" never leads to "Page not found". Create a real page (Settings
 * → Hotel → Booking page → Create the booking page) to design it yourself.
 *
 * Override by copying to {your-theme}/flexo-booking/booking-page.php.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

$flexo_shell_canvas  = false;
$flexo_shell_content = static function () {
	echo '<div class="flexo-builtin-booking">' . Flexo_Booking_Frontend::render( array( 'title' => __( 'Book your stay', 'flexo-booking' ) ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
};
require FLEXO_BOOKING_DIR . 'templates/page-shell.php';
