<?php
/**
 * Contact form (1.10.0). Copy to {theme}/flexo-booking/contact-form.php to
 * change the markup; keep data-flexo-contact, the field names and the
 * .fb-contact__status element.
 *
 * @package FlexoBooking
 *
 * @var array  $atts    title, intro, button_text, success_text.
 * @var string $uid     Unique prefix for ids.
 * @var string $button  Text of the send button.
 * @var string $success Text shown after sending ('' = the hotel's text from the server).
 * @var string $consent Privacy consent (HTML, escaped) or ''.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="flexo-booking flexo-booking--contact" data-flexo-contact<?php echo '' !== $success ? ' data-success="' . esc_attr( $success ) . '"' : ''; ?><?php echo Flexo_Booking_Frontend::locale_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in locale_attributes(). ?>>
	<?php if ( '' !== trim( (string) $atts['title'] ) ) : ?>
		<h3 class="fb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
	<?php endif; ?>
	<?php if ( '' !== trim( (string) $atts['intro'] ) ) : ?>
		<p class="fb-contact__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
	<?php endif; ?>
	<form class="fb-contact" novalidate>
		<div class="fb-grid">
			<?php echo Flexo_Booking_Forms::contact_fields_html( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in contact_fields_html(). ?>
		</div>
		<?php echo $consent; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in Flexo_Booking_Contact. ?>
		<div class="fb-hp" aria-hidden="true"><input type="text" name="fb_website" tabindex="-1" autocomplete="off"></div>
		<div class="fb-actions">
			<button type="submit" class="fb-button"><?php echo esc_html( $button ); ?></button>
		</div>
		<p class="fb-contact__status" role="status" aria-live="polite"></p>
	</form>
</div>
