<?php
/**
 * Room page for classic themes, used when no Elementor Pro single template
 * is set for rooms: the theme's header and footer around the room page.
 *
 * Override by copying to {your-theme}/flexo-booking/single-room.php (or
 * create single-flexo_room.php in your theme).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main flexo-room-page-wrap">
	<?php
	while ( have_posts() ) {
		the_post();
		$flexo_room = Flexo_Booking_Room_Content::room( get_the_ID() );
		if ( $flexo_room ) {
			echo Flexo_Booking_Room_Render::page( $flexo_room ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in page().
		}
	}
	?>
</main>
<?php
get_footer();
