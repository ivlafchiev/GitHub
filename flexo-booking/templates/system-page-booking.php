<?php
/**
 * The built-in Booking page (Settings → Pages → Booking): title,
 * introduction, the booking form (the same one as the shortcode and the
 * Elementor widget), reassurance, secure-payment note and help, inside the
 * theme's header and footer.
 *
 * Override by copying to {your-theme}/flexo-booking/system-page-booking.php
 * (copies of the 1.8.3 booking-page.php keep working).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

$flexo_shell_canvas  = false;
$flexo_shell_content = static function () {
	$view    = Flexo_Booking_System_Pages::booking_view();
	$classes = array( 'flexo-sp-booking', 'flexo-sp-booking--' . $view['layout'] );
	if ( ! $view['steps'] ) {
		$classes[] = 'flexo-sp-booking--no-steps';
	}
	if ( ! $view['summary'] ) {
		$classes[] = 'flexo-sp-booking--no-summary';
	}
	ob_start();
	?>
	<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
		<?php if ( '' !== $view['title'] || '' !== $view['intro'] ) : ?>
			<header class="flexo-sp-head">
				<?php if ( '' !== $view['title'] ) : ?>
					<h1 class="flexo-sp-title"><?php echo esc_html( $view['title'] ); ?></h1>
				<?php endif; ?>
				<?php if ( '' !== $view['intro'] ) : ?>
					<div class="flexo-sp-intro"><?php echo $view['intro']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in booking_view(). ?></div>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<div class="flexo-sp-booking__form">
			<?php echo $view['form']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the booking form renderer escapes its output. ?>
		</div>

		<?php if ( $view['reassurance'] || '' !== $view['secure'] || $view['help'] ) : ?>
			<div class="flexo-sp-extras">
				<?php if ( $view['reassurance'] || '' !== $view['secure'] ) : ?>
					<div class="flexo-sp-box flexo-sp-reassure">
						<?php if ( $view['reassurance'] ) : ?>
							<ul class="flexo-sp-reassure__list">
								<?php foreach ( $view['reassurance'] as $line ) : ?>
									<li><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<?php if ( '' !== $view['secure'] ) : ?>
							<p class="flexo-sp-secure"><?php echo esc_html( $view['secure'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( $view['help'] ) : ?>
					<div class="flexo-sp-box flexo-sp-help">
						<h2 class="flexo-sp-help__title"><?php echo esc_html( $view['help']['title'] ); ?></h2>
						<?php if ( '' !== $view['help']['text'] ) : ?>
							<div class="flexo-sp-help__text"><?php echo $view['help']['text']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in booking_view(). ?></div>
						<?php endif; ?>
						<p class="flexo-sp-help__links">
							<?php if ( '' !== $view['help']['contact']['phone'] ) : ?>
								<a href="<?php echo esc_url( $view['help']['contact']['phone_link'] ); ?>"><?php echo esc_html( $view['help']['contact']['phone'] ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $view['help']['contact']['email'] ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $view['help']['contact']['email'] ); ?>"><?php echo esc_html( $view['help']['contact']['email'] ); ?></a>
							<?php endif; ?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	// .flexo-builtin-booking: the 1.8.3 class, kept for themes' CSS.
	echo Flexo_Booking_System_Pages::wrap( 'booking', ob_get_clean(), array( 'flexo-builtin-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
};
require FLEXO_BOOKING_DIR . 'templates/page-shell.php';
