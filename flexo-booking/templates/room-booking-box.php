<?php
/**
 * Room booking box: dates and guests for one room, its availability and
 * total, and "Book now", which opens the booking page with everything
 * chosen. Without JavaScript the form opens the booking page with the room
 * and dates.
 *
 * Override by copying to {your-theme}/flexo-booking/room-booking-box.php.
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
 * @var bool       $autosearch
 * @var bool       $ask_ages   Children's ages are asked ("Children & ages" feature).
 * @var string     $from       Formatted "from" price per night, or ''.
 * @var int        $max_adults Most adults this room takes.
 * @var int        $max_kids   Most children this room takes.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="flexo-booking flexo-booking--box"
	data-flexo-booking-box
	data-room="<?php echo esc_attr( $room['slug'] ); ?>"
	data-booking-url="<?php echo esc_url( $booking_url ); ?>"
	data-book-text="<?php echo esc_attr( $atts['book_text'] ); ?>"
	data-autosearch="<?php echo $autosearch ? '1' : '0'; ?>"<?php echo Flexo_Booking_Frontend::locale_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in locale_attributes(). ?>>

	<?php if ( $atts['title'] ) : ?>
		<h3 class="fb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
	<?php endif; ?>

	<?php if ( '' !== $from ) : ?>
		<p class="fb-box__from">
			<?php
			/* translators: %s: price per night, e.g. €95 */
			printf( esc_html__( 'from %s', 'flexo-booking' ), '<strong class="fb-box__from-price">' . esc_html( $from ) . '</strong>' );
			?>
			<span class="fb-box__from-unit"><?php esc_html_e( 'per night', 'flexo-booking' ); ?></span>
		</p>
	<?php endif; ?>

	<form class="fb-search fb-box__form" method="get" action="<?php echo esc_url( $booking_url ); ?>" novalidate>
		<input type="hidden" name="room" value="<?php echo esc_attr( $room['slug'] ); ?>">
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
				<?php for ( $i = 1; $i <= $max_adults; $i++ ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( min( $prefill['adults'], $max_adults ), $i ); ?>><?php echo esc_html( $i ); ?></option>
				<?php endfor; ?>
			</select>
		</div>
		<?php if ( $max_kids > 0 ) : ?>
			<div class="fb-field fb-field--small">
				<label for="<?php echo esc_attr( $uid ); ?>-children"><?php esc_html_e( 'Children', 'flexo-booking' ); ?></label>
				<select id="<?php echo esc_attr( $uid ); ?>-children" name="children">
					<?php for ( $i = 0; $i <= $max_kids; $i++ ) : ?>
						<option value="<?php echo esc_attr( $i ); ?>" <?php selected( min( $prefill['children'], $max_kids ), $i ); ?>><?php echo esc_html( $i ); ?></option>
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
	<div class="fb-box__result" data-fb-box-result aria-live="polite"></div>
</div>
