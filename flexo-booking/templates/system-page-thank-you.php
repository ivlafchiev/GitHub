<?php
/**
 * The built-in Thank You page (Settings → Pages → Thank You): the booking
 * the guest just made, from a short-lived secure cookie – or a general
 * thank-you text without one. Never cached, never indexed.
 *
 * Override by copying to {your-theme}/flexo-booking/system-page-thank-you.php
 * (the content itself is templates/confirmation.php).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

$flexo_shell_canvas  = false;
$flexo_shell_content = static function () {
	ob_start();
	do_action( 'flexo_booking_thank_you_bottom' );
	$flexo_bottom = ob_get_clean();
	echo Flexo_Booking_System_Pages::wrap( 'thank_you', Flexo_Booking_Confirmation::render() . $flexo_bottom ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the confirmation template and by the hooked code.
};
require FLEXO_BOOKING_DIR . 'templates/page-shell.php';
