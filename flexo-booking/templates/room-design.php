<?php
/**
 * Room page with the design chosen in Settings → Room pages: the theme's
 * header and footer around the Elementor design, printed with the room as
 * the current post (its tags and room widgets show this room's data).
 *
 * Override by copying to {your-theme}/flexo-booking/room-design.php.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

$flexo_shell_canvas  = Flexo_Booking_Room_Design::is_canvas();
$flexo_shell_content = static function () {
	while ( have_posts() ) {
		the_post();
		echo '<div class="flexo-room-design__main">' . Flexo_Booking_Room_Design::content() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own output.
	}
};
require FLEXO_BOOKING_DIR . 'templates/page-shell.php';
