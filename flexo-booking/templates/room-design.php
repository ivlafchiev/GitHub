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

// Elementor Canvas: no header or footer. Block themes: their header and footer parts.
$flexo_canvas = Flexo_Booking_Room_Design::is_canvas();
$flexo_blocks = ! $flexo_canvas && wp_is_block_theme() && function_exists( 'block_template_part' );
$flexo_own    = $flexo_canvas || $flexo_blocks;

if ( $flexo_own ) {
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php
	wp_body_open();
	if ( $flexo_blocks ) {
		echo '<div class="wp-site-blocks"><header class="wp-block-template-part">';
		block_template_part( 'header' );
		echo '</header>';
	}
} else {
	get_header();
}
?>
<main id="content" class="site-main flexo-room-design__main">
	<?php
	while ( have_posts() ) {
		the_post();
		echo Flexo_Booking_Room_Design::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own output.
	}
	?>
</main>
<?php
if ( $flexo_own ) {
	if ( $flexo_blocks ) {
		echo '<footer class="wp-block-template-part">';
		block_template_part( 'footer' );
		echo '</footer></div>';
	}
	wp_footer();
	echo "</body>\n</html>\n";
} else {
	get_footer();
}
