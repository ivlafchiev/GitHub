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

// Block themes: the header and footer parts are built before wp_head(), as
// WordPress does for block templates, so their scripts (e.g. the navigation's
// script modules) are known when the head is printed.
$flexo_parts = array();
if ( $flexo_blocks ) {
	foreach ( array( 'header', 'footer' ) as $flexo_part ) {
		ob_start();
		block_template_part( $flexo_part );
		$flexo_parts[ $flexo_part ] = ob_get_clean();
	}
}

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
		echo '<div class="wp-site-blocks"><header class="wp-block-template-part">' . $flexo_parts['header'] . '</header>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress block output.
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
		echo '<footer class="wp-block-template-part">' . $flexo_parts['footer'] . '</footer></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress block output.
	}
	wp_footer();
	echo "</body>\n</html>\n";
} else {
	get_footer();
}
