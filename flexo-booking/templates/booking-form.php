<?php
/**
 * Full booking form: dates → room choice → guest details → confirmation.
 *
 * Override by copying to {your-theme}/flexo-booking/booking-form.php.
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
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="flexo-booking flexo-booking--full"
	data-flexo-booking
	data-room="<?php echo esc_attr( $room ? $room['slug'] : '' ); ?>"
	data-autosearch="<?php echo $autosearch ? '1' : '0'; ?>">

	<?php if ( $atts['title'] ) : ?>
		<h3 class="fb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
	<?php endif; ?>

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
		<?php endif; ?>
		<div class="fb-field fb-field--action">
			<button type="submit" class="fb-button"><?php echo esc_html( $atts['button_text'] ); ?></button>
		</div>
	</form>

	<div class="fb-notice" role="alert" hidden></div>

	<div class="fb-results" aria-live="polite" hidden></div>

	<form class="fb-details" novalidate hidden>
		<h4 class="fb-step-title"><?php esc_html_e( 'Your details', 'flexo-booking' ); ?></h4>
		<div class="fb-summary"></div>

		<div class="fb-grid">
			<div class="fb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'flexo-booking' ); ?> <span aria-hidden="true">*</span></label>
				<input id="<?php echo esc_attr( $uid ); ?>-name" type="text" name="guest_name" required autocomplete="name">
			</div>
			<div class="fb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'flexo-booking' ); ?> <span aria-hidden="true">*</span></label>
				<input id="<?php echo esc_attr( $uid ); ?>-email" type="email" name="guest_email" required autocomplete="email">
			</div>
			<div class="fb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Phone', 'flexo-booking' ); ?> <span aria-hidden="true">*</span></label>
				<input id="<?php echo esc_attr( $uid ); ?>-phone" type="tel" name="guest_phone" required autocomplete="tel">
			</div>
			<div class="fb-field fb-field--wide">
				<label for="<?php echo esc_attr( $uid ); ?>-notes"><?php esc_html_e( 'Special requests', 'flexo-booking' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-notes" name="notes" rows="3"></textarea>
			</div>
		</div>

		<div class="fb-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $uid ); ?>-website">Website</label>
			<input id="<?php echo esc_attr( $uid ); ?>-website" type="text" name="fb_website" tabindex="-1" autocomplete="off">
		</div>

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
