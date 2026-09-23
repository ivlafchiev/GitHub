<?php
/**
 * Plugin settings: storage, defaults and the settings screen.
 *
 * Everything lives in a single option so the whole configuration can be
 * exported and imported as one JSON object (see class-portability.php).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Settings {

	const OPTION = 'flexo_booking_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			'currency'                 => 'EUR',
			'currency_symbol'          => '€',
			'currency_position'        => 'after',
			'booking_mode'             => 'request',
			'min_nights'               => 1,
			'max_nights'               => 30,
			'max_advance_days'         => 365,
			'max_adults'               => 6,
			'max_children'             => 4,
			'check_in_time'            => '14:00',
			'check_out_time'           => '11:00',
			'notification_email'       => '',
			'thank_you_url'            => '',
			'terms_url'                => '',
			'email_request_subject'    => __( 'We received your booking request {reference}', 'flexo-booking' ),
			'email_request_body'       => __( "Hello {guest_name},\n\nThank you for your booking request at {site_name}. We will confirm it shortly.\n\n{booking_details}\n\nKind regards,\n{site_name}", 'flexo-booking' ),
			'email_confirmed_subject'  => __( 'Your booking {reference} is confirmed', 'flexo-booking' ),
			'email_confirmed_body'     => __( "Hello {guest_name},\n\nYour booking at {site_name} is confirmed. We look forward to welcoming you!\n\n{booking_details}\n\nCheck-in from {check_in_time}, check-out until {check_out_time}.\n\nKind regards,\n{site_name}", 'flexo-booking' ),
			'email_cancelled_subject'  => __( 'Your booking {reference} has been cancelled', 'flexo-booking' ),
			'email_cancelled_body'     => __( "Hello {guest_name},\n\nYour booking {reference} at {site_name} has been cancelled. If you have any questions, simply reply to this email.\n\nKind regards,\n{site_name}", 'flexo-booking' ),
			'delete_data_on_uninstall' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function notification_email() {
		$email = self::get( 'notification_email' );
		return is_email( $email ) ? $email : get_option( 'admin_email' );
	}

	/**
	 * Turns a stored path like "/thank-you/" into a full URL for the current
	 * site. Paths (not absolute URLs) are stored so settings survive moving to
	 * another domain. Absolute URLs are still accepted.
	 */
	public static function site_url_setting( $key ) {
		$value = trim( (string) self::get( $key ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return home_url( '/' . ltrim( $value, '/' ) );
	}

	public static function format_price( $amount ) {
		$number = number_format_i18n( (float) $amount, 2 );
		$symbol = self::get( 'currency_symbol' );
		return 'before' === self::get( 'currency_position' ) ? $symbol . $number : $number . ' ' . $symbol;
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : $default;

			switch ( $key ) {
				case 'min_nights':
				case 'max_nights':
				case 'max_advance_days':
				case 'max_adults':
					$clean[ $key ] = max( 1, absint( $value ) );
					break;
				case 'max_children':
					$clean[ $key ] = absint( $value );
					break;
				case 'delete_data_on_uninstall':
					$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
					break;
				case 'booking_mode':
					$clean[ $key ] = in_array( $value, array( 'request', 'instant' ), true ) ? $value : 'request';
					break;
				case 'currency_position':
					$clean[ $key ] = in_array( $value, array( 'before', 'after' ), true ) ? $value : 'after';
					break;
				case 'notification_email':
					$clean[ $key ] = sanitize_email( $value );
					break;
				case 'thank_you_url':
				case 'terms_url':
					$clean[ $key ] = self::sanitize_path_or_url( $value );
					break;
				case 'check_in_time':
				case 'check_out_time':
					$clean[ $key ] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $default;
					break;
				default:
					$clean[ $key ] = substr( $key, -5 ) === '_body' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
		}

		$clean['max_nights'] = max( $clean['min_nights'], $clean['max_nights'] );

		return $clean;
	}

	/**
	 * Converts a same-site absolute URL to a path so the value stays valid
	 * after the site moves to a different domain.
	 */
	private static function sanitize_path_or_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$home = untrailingslashit( home_url() );
		if ( 0 === stripos( $value, $home ) ) {
			$value = substr( $value, strlen( $home ) );
			$value = '' === $value ? '/' : $value;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return '/' . ltrim( sanitize_text_field( $value ), '/' );
	}

	public static function register() {
		register_setting(
			'flexo_booking',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::all();
		$name = self::OPTION;
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Booking settings', 'flexo-booking' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'flexo_booking' ); ?>

				<h2><?php esc_html_e( 'General', 'flexo-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Booking mode', 'flexo-booking' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[booking_mode]" value="request" <?php checked( $s['booking_mode'], 'request' ); ?>> <?php esc_html_e( 'Booking request: new bookings are "pending" until you confirm them', 'flexo-booking' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[booking_mode]" value="instant" <?php checked( $s['booking_mode'], 'instant' ); ?>> <?php esc_html_e( 'Instant booking: new bookings are confirmed automatically', 'flexo-booking' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency', 'flexo-booking' ); ?></th>
						<td>
							<input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>" aria-label="<?php esc_attr_e( 'Currency code', 'flexo-booking' ); ?>" placeholder="EUR">
							<input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency_symbol]" value="<?php echo esc_attr( $s['currency_symbol'] ); ?>" aria-label="<?php esc_attr_e( 'Currency symbol', 'flexo-booking' ); ?>" placeholder="€">
							<select name="<?php echo esc_attr( $name ); ?>[currency_position]" aria-label="<?php esc_attr_e( 'Symbol position', 'flexo-booking' ); ?>">
								<option value="before" <?php selected( $s['currency_position'], 'before' ); ?>><?php esc_html_e( 'Symbol before amount (€120.00)', 'flexo-booking' ); ?></option>
								<option value="after" <?php selected( $s['currency_position'], 'after' ); ?>><?php esc_html_e( 'Symbol after amount (120.00 €)', 'flexo-booking' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stay length', 'flexo-booking' ); ?></th>
						<td>
							<?php esc_html_e( 'Minimum', 'flexo-booking' ); ?> <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[min_nights]" value="<?php echo esc_attr( $s['min_nights'] ); ?>">
							<?php esc_html_e( 'Maximum', 'flexo-booking' ); ?> <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_nights]" value="<?php echo esc_attr( $s['max_nights'] ); ?>">
							<?php esc_html_e( 'nights', 'flexo-booking' ); ?>
							<p class="description"><?php esc_html_e( 'Individual rooms can override the minimum.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-advance"><?php esc_html_e( 'Book up to', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-advance" type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_advance_days]" value="<?php echo esc_attr( $s['max_advance_days'] ); ?>"> <?php esc_html_e( 'days in advance', 'flexo-booking' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guest selector limits', 'flexo-booking' ); ?></th>
						<td>
							<?php esc_html_e( 'Adults up to', 'flexo-booking' ); ?> <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_adults]" value="<?php echo esc_attr( $s['max_adults'] ); ?>">
							<?php esc_html_e( 'Children up to', 'flexo-booking' ); ?> <input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_children]" value="<?php echo esc_attr( $s['max_children'] ); ?>">
							<p class="description"><?php esc_html_e( 'Set "Children up to" to 0 to hide the children field.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Check-in / check-out', 'flexo-booking' ); ?></th>
						<td>
							<input type="time" name="<?php echo esc_attr( $name ); ?>[check_in_time]" value="<?php echo esc_attr( $s['check_in_time'] ); ?>" aria-label="<?php esc_attr_e( 'Check-in time', 'flexo-booking' ); ?>">
							/
							<input type="time" name="<?php echo esc_attr( $name ); ?>[check_out_time]" value="<?php echo esc_attr( $s['check_out_time'] ); ?>" aria-label="<?php esc_attr_e( 'Check-out time', 'flexo-booking' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-thanks"><?php esc_html_e( 'Thank-you page', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-thanks" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[thank_you_url]" value="<?php echo esc_attr( $s['thank_you_url'] ); ?>" placeholder="/thank-you/">
							<p class="description"><?php esc_html_e( 'Optional. Use a path such as /thank-you/ so it keeps working when the site moves to another domain. Leave empty to show the confirmation inside the form.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-terms"><?php esc_html_e( 'Terms page', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-terms" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[terms_url]" value="<?php echo esc_attr( $s['terms_url'] ); ?>" placeholder="/terms-and-conditions/">
							<p class="description"><?php esc_html_e( 'Optional. When set, guests must accept the terms before booking.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Emails', 'flexo-booking' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Placeholders:', 'flexo-booking' ); ?>
					<code>{reference}</code> <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{room}</code> <code>{check_in}</code> <code>{check_out}</code> <code>{nights}</code> <code>{guests}</code> <code>{total}</code> <code>{status}</code> <code>{booking_details}</code> <code>{check_in_time}</code> <code>{check_out_time}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-notify"><?php esc_html_e( 'Send new booking alerts to', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-notify" type="email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[notification_email]" value="<?php echo esc_attr( $s['notification_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
					</tr>
					<?php
					$templates = array(
						'request'   => __( 'Booking request received (to guest)', 'flexo-booking' ),
						'confirmed' => __( 'Booking confirmed (to guest)', 'flexo-booking' ),
						'cancelled' => __( 'Booking cancelled (to guest)', 'flexo-booking' ),
					);
					foreach ( $templates as $type => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<input type="text" class="large-text" name="<?php echo esc_attr( $name . '[email_' . $type . '_subject]' ); ?>" value="<?php echo esc_attr( $s[ 'email_' . $type . '_subject' ] ); ?>" aria-label="<?php esc_attr_e( 'Subject', 'flexo-booking' ); ?>">
								<textarea class="large-text" rows="7" name="<?php echo esc_attr( $name . '[email_' . $type . '_body]' ); ?>" aria-label="<?php esc_attr_e( 'Message', 'flexo-booking' ); ?>"><?php echo esc_textarea( $s[ 'email_' . $type . '_body' ] ); ?></textarea>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2><?php esc_html_e( 'Uninstall', 'flexo-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'flexo-booking' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete all rooms, bookings and settings when the plugin is deleted', 'flexo-booking' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
