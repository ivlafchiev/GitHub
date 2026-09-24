<?php
/**
 * Compact search bar (e.g. for a hero section). Sends the visitor to the
 * booking page, where the full form picks up the chosen dates and guests.
 * Works without JavaScript.
 *
 * Override by copying to {your-theme}/flexo-booking/search-bar.php.
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
 * @var string     $booking_url
 * @var bool       $ask_ages   Children's ages are asked ("Children & ages" feature).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="flexo-booking flexo-booking--search" data-flexo-booking-search>
	<?php if ( $atts['title'] ) : ?>
		<h3 class="fb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
	<?php endif; ?>

	<form class="fb-search" method="get" action="<?php echo esc_url( $booking_url ); ?>">
		<?php if ( $room ) : ?>
			<input type="hidden" name="room" value="<?php echo esc_attr( $room['slug'] ); ?>">
		<?php endif; ?>
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
</div>
