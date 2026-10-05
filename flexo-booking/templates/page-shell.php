<?php
/**
 * Page frame used by the plugin's own pages (room page design, built-in
 * booking page): the theme's header and footer around $flexo_shell_content.
 *
 *   Classic themes: get_header() / get_footer().
 *   Block themes:   their header and footer template parts, built before
 *                   wp_head() as WordPress does, so their scripts are known.
 *   Canvas:         no header or footer ($flexo_shell_canvas).
 *
 * @package FlexoBooking
 *
 * @var callable $flexo_shell_content Prints the page content (inside the loop when there is one).
 * @var bool     $flexo_shell_canvas  No header and footer.
 */

defined( 'ABSPATH' ) || exit;

$flexo_canvas = ! empty( $flexo_shell_canvas );
$flexo_blocks = ! $flexo_canvas && wp_is_block_theme() && function_exists( 'block_template_part' );
$flexo_own    = $flexo_canvas || $flexo_blocks;

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
<main id="content" class="site-main flexo-page">
	<?php call_user_func( $flexo_shell_content ); ?>
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
