<?php
/**
 * Full booking form: dates → room choice → guest details → (payment) →
 * confirmation, with a summary next to the form (below it on phones).
 *
 * Override by copying to {your-theme}/flexo-booking/booking-form.php.
 * Overrides made before 1.6.0 keep working: the script adds the step bar,
 * the date picker and the stay line itself, and shows the summary where
 * the template has it.
 *
 * @package FlexoBooking
 *
 * @var array      $atts
 * @var array      $settings
 * @var array      $prefill
 * @var array|null $room
 * @var string     $uid
 * @var string     $min_date
 * @var string     $max_date
 * @var string     $terms_url
 * @var bool       $autosearch
 * @var bool       $ask_ages   Children's ages are asked ("Children & ages" feature).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="flexo-booking flexo-booking--full"
	data-flexo-booking
	data-room="<?php echo esc_attr( $room ? $room['slug'] : '' ); ?>"
	data-autosearch="<?php echo $autosearch ? '1' : '0'; ?>"<?php echo Flexo_Booking_Frontend::locale_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in locale_attributes(). ?>>

	<?php if ( $atts['title'] ) : ?>
		<h3 class="fb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
	<?php endif; ?>

	<?php echo Flexo_Booking_Frontend::steps(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in steps(). ?>

	<div class="fb-layout">
	<div class="fb-main">

	<?php if ( $room ) : ?>
		<p class="fb-selected-room">
			<?php
			/* translators: %s: room name */
			printf( esc_html__( 'Booking: %s', 'flexo-booking' ), '<strong>' . esc_html( $room['title'] ) . '</strong>' );
			?>
		</p>
	<?php endif; ?>

	<form class="fb-search" novalidate>
		<div class="fb-field">
			<label for="<?php echo esc_attr( $uid ); ?>-in"><?php esc_html_e( 'Check-in', 'flexo-booking' ); ?></label>
			<input id="<?php echo esc_attr( $uid ); ?>-in" type="date" name="check_in" required min="<?php echo esc_attr( $min_date ); ?>" max="<?php echo esc_attr( $max_date ); ?>" value="<?php echo esc_attr( $prefill['check_in'] ); ?>">
		</div>
		<div class="fb-field">
			<label for="<?php echo esc_attr( $uid ); ?>-out"><?php esc_html_e( 'Check-out', 'flexo-booking' ); ?></label>
			<input id="<?php echo esc_attr( $uid ); ?>-out" type="date" name="check_out" required min="<?php echo esc_attr( $min_date ); ?>" value="<?php echo esc_attr( $prefill['check_out'] ); ?>">
		</div>
		<div class="fb-field fb-field--small">
			<label for="<?php echo esc_attr( $uid ); ?>-adults"><?php esc_html_e( 'Adults', 'flexo-booking' ); ?></label>
			<select id="<?php echo esc_attr( $uid ); ?>-adults" name="adults">
				<?php for ( $i = 1; $i <= (int) $settings['max_adults']; $i++ ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $prefill['adults'], $i ); ?>><?php echo esc_html( $i ); ?></option>
				<?php endfor; ?>
			</select>
		</div>
		<?php if ( (int) $settings['max_children'] > 0 ) : ?>
			<div class="fb-field fb-field--small">
				<label for="<?php echo esc_attr( $uid ); ?>-children"><?php esc_html_e( 'Children', 'flexo-booking' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-children" name="children">
					<?php for ( $i = 0; $i <= (int) $settings['max_children']; $i++ ) : ?>
						<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $prefill['children'], $i ); ?>><?php echo esc_html( $i ); ?></option>
					<?php endfor; ?>
				</select>
			</div>
			<?php if ( ! empty( $ask_ages ) ) : ?>
				<?php // Filled by booking.js with one age selector per child. ?>
				<div class="fb-ages" data-fb-ages="<?php echo esc_attr( implode( ',', $prefill['ages'] ) ); ?>" hidden></div>
			<?php endif; ?>
		<?php endif; ?>
		<div class="fb-field fb-field--action">
			<button type="submit" class="fb-button"><?php echo esc_html( $atts['button_text'] ); ?></button>
		</div>
	</form>

	<div class="fb-stay" data-fb-stay hidden></div>

	<div class="fb-notice" role="alert" hidden></div>

	<div class="fb-results" aria-live="polite" hidden></div>

	<form class="fb-details" novalidate hidden>
		<h4 class="fb-step-title" tabindex="-1"><?php esc_html_e( 'Your details', 'flexo-booking' ); ?></h4>
		<p class="fb-required-note"><?php esc_html_e( 'Fields marked * are required.', 'flexo-booking' ); ?></p>

		<div class="fb-grid">
			<div class="fb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'flexo-booking' ); ?> <span class="fb-req" aria-hidden="true">*</span></label>
				<input id="<?php echo esc_attr( $uid ); ?>-name" type="text" name="guest_name" required autocomplete="name" autocapitalize="words" enterkeyhint="next">
			</div>
			<div class="fb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'flexo-booking' ); ?> <span class="fb-req" aria-hidden="true">*</span></label>
				<input id="<?php echo esc_attr( $uid ); ?>-email" type="email" name="guest_email" required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" enterkeyhint="next" aria-describedby="<?php echo esc_attr( $uid ); ?>-email-hint">
				<small class="fb-hint" id="<?php echo esc_attr( $uid ); ?>-email-hint"><?php esc_html_e( 'We send your confirmation here.', 'flexo-booking' ); ?></small>
			</div>
			<?php $phone_mode = isset( $settings['field_phone'] ) ? $settings['field_phone'] : 'required'; ?>
			<?php if ( 'hidden' !== $phone_mode ) : ?>
				<div class="fb-field fb-field--phone">
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Phone', 'flexo-booking' ); ?><?php echo 'required' === $phone_mode ? ' <span class="fb-req" aria-hidden="true">*</span>' : ' <span class="fb-optional">' . esc_html__( '(optional)', 'flexo-booking' ) . '</span>'; ?></label>
					<div class="fb-phone">
						<?php echo Flexo_Booking_Frontend::phone_country_select( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in phone_country_select(). ?>
						<input id="<?php echo esc_attr( $uid ); ?>-phone" type="tel" name="guest_phone" <?php echo 'required' === $phone_mode ? 'required' : ''; ?> autocomplete="tel" inputmode="tel" enterkeyhint="next">
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! isset( $settings['field_notes'] ) || 'hidden' !== $settings['field_notes'] ) : ?>
				<div class="fb-field fb-field--wide">
					<label for="<?php echo esc_attr( $uid ); ?>-notes"><?php esc_html_e( 'Special requests', 'flexo-booking' ); ?> <span class="fb-optional"><?php esc_html_e( '(optional)', 'flexo-booking' ); ?></span></label>
					<textarea id="<?php echo esc_attr( $uid ); ?>-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'e.g. late arrival, baby cot, quiet room', 'flexo-booking' ); ?>"></textarea>
				</div>
			<?php endif; ?>
		</div>

		<div class="fb-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $uid ); ?>-website">Website</label>
			<input id="<?php echo esc_attr( $uid ); ?>-website" type="text" name="fb_website" tabindex="-1" autocomplete="off">
		</div>

		<?php echo Flexo_Booking_Frontend::extra_fields( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>

		<?php if ( $terms_url ) : ?>
			<label class="fb-terms">
				<input type="checkbox" name="terms" value="1" required>
				<?php
				printf(
					/* translators: %s: link to the terms page */
					esc_html__( 'I agree to the %s', 'flexo-booking' ),
					'<a href="' . esc_url( $terms_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms and conditions', 'flexo-booking' ) . '</a>'
				);
				?>
				<span class="fb-req" aria-hidden="true">*</span>
			</label>
		<?php endif; ?>

		<div class="fb-actions">
			<button type="button" class="fb-button fb-button--ghost" data-fb-back><?php esc_html_e( 'Back', 'flexo-booking' ); ?></button>
			<button type="submit" class="fb-button">
				<?php echo 'instant' === $settings['booking_mode'] ? esc_html__( 'Confirm booking', 'flexo-booking' ) : esc_html__( 'Send booking request', 'flexo-booking' ); ?>
			</button>
		</div>
	</form>

	<div class="fb-success" role="status" tabindex="-1" hidden></div>
	</div>

	<aside class="fb-aside" data-fb-aside hidden aria-labelledby="<?php echo esc_attr( $uid ); ?>-summary-title">
		<button type="button" class="fb-aside__toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-summary">
			<span class="fb-aside__title" id="<?php echo esc_attr( $uid ); ?>-summary-title"><?php esc_html_e( 'Your stay', 'flexo-booking' ); ?></span>
			<span class="fb-aside__short" data-fb-aside-short></span>
		</button>
		<div class="fb-summary" id="<?php echo esc_attr( $uid ); ?>-summary"></div>
	</aside>
	</div>
</div>
