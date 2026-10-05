<?php
/**
 * The booking confirmation of the Thank You page: status, the stay and
 * payment, what happens next, contact and actions – the sections and their
 * order from Settings → Pages → Thank You. Without a booking (no secure
 * context) only the general thank-you text, contact and "back" are shown.
 *
 * Override by copying to {your-theme}/flexo-booking/confirmation.php.
 *
 * @package FlexoBooking
 *
 * @var string     $layout  card | summary | split
 * @var array|null $view    Flexo_Booking_Confirmation::view() or null.
 * @var string     $heading Main heading (plain text).
 * @var string     $lead    Text under the heading (HTML, already escaped).
 * @var array      $blocks  { main: string[], side: string[] } section HTML (escaped).
 * @var bool       $pending The card payment is not confirmed yet: the page re-checks.
 * @var string     $icon    request | instant | paid | transfer | pending | problem | generic
 */

defined( 'ABSPATH' ) || exit;

$flexo_show_title = 'band' !== Flexo_Booking_System_Pages::get_header_style() || '' === Flexo_Booking_System_Pages::current();
?>
<div class="flexo-booking flexo-confirmation flexo-confirmation--<?php echo esc_attr( $layout ); ?> flexo-confirmation--<?php echo esc_attr( $icon ); ?>" data-flexo-confirmation>
	<div class="flexo-confirmation__hero" role="status">
		<span class="flexo-confirmation__icon" aria-hidden="true"></span>
		<?php if ( $flexo_show_title ) : ?>
			<h1 class="flexo-confirmation__title"><?php echo esc_html( $heading ); ?></h1>
		<?php else : ?>
			<p class="flexo-confirmation__title flexo-confirmation__title--small"><?php echo esc_html( $heading ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $lead ) : ?>
			<div class="flexo-confirmation__lead"><?php echo $lead; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?></div>
		<?php endif; ?>
		<?php if ( $pending ) : ?>
			<p class="flexo-confirmation__pending" data-fb-ty-refresh="5" data-fb-ty-max="24">
				<span class="fb-spinner" aria-hidden="true"></span>
				<?php esc_html_e( 'This page updates by itself as soon as your payment is confirmed. You also receive an email.', 'flexo-booking' ); ?>
				<a href="<?php echo esc_url( remove_query_arg( 'fb_t' ) ); ?>" class="fb-link" data-fb-ty-reload><?php esc_html_e( 'Check again', 'flexo-booking' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
	<?php if ( 'split' === $layout && $blocks['side'] ) : ?>
		<div class="flexo-confirmation__split">
			<div class="flexo-confirmation__main">
				<?php echo implode( '', $blocks['main'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>
			</div>
			<aside class="flexo-confirmation__side" aria-label="<?php esc_attr_e( 'Your stay', 'flexo-booking' ); ?>">
				<?php echo implode( '', $blocks['side'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>
			</aside>
		</div>
	<?php elseif ( $blocks['main'] || $blocks['side'] ) : ?>
		<div class="flexo-confirmation__body">
			<?php echo implode( '', array_merge( $blocks['main'], $blocks['side'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer. ?>
		</div>
	<?php endif; ?>
</div>
