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
			'currency_decimals'        => 2,
			'number_format'            => 'auto',
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
			'ical_interval'            => 30,
			// Children & ages (Day 3): free under this age, then a share of
			// the adult amount, then the adult amount.
			'child_free_under'         => 3,
			'child_percent'            => 50,
			'child_adult_from'         => 12,
			// Tourist tax (Day 3).
			'tourist_tax_amount'       => 0,
			'tourist_tax_children'     => 'adult',
			'tourist_tax_exempt_under' => 18,
			'tourist_tax_collect'      => 'booking',
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

	/**
	 * @param float       $amount
	 * @param string|null $currency Currency the amount is in (e.g. a booking's).
	 */
	public static function format_price( $amount, $currency = null ) {
		return Flexo_Booking_Money::format( $amount, $currency );
	}

	/**
	 * Sanitises submitted settings. Keys that are not submitted keep their
	 * saved value, so each settings tab only changes its own fields.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$base     = self::all();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : $base[ $key ];

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
				case 'child_free_under':
				case 'child_adult_from':
				case 'tourist_tax_exempt_under':
					$clean[ $key ] = min( 18, absint( $value ) );
					break;
				case 'child_percent':
					$clean[ $key ] = min( 100, max( 0, round( (float) $value, 2 ) ) );
					break;
				case 'tourist_tax_amount':
					$clean[ $key ] = max( 0, round( (float) str_replace( ',', '.', (string) $value ), 2 ) );
					break;
				case 'tourist_tax_children':
					$clean[ $key ] = in_array( $value, array( 'adult', 'exempt', 'rules' ), true ) ? $value : 'adult';
					break;
				case 'tourist_tax_collect':
					$clean[ $key ] = in_array( $value, array( 'booking', 'property' ), true ) ? $value : 'booking';
					break;
				case 'ical_interval':
					$clean[ $key ] = in_array( (int) $value, array( 15, 30, 60 ), true ) ? (int) $value : 30;
					break;
				case 'delete_data_on_uninstall':
					$clean[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'booking_mode':
					$clean[ $key ] = in_array( $value, array( 'request', 'instant' ), true ) ? $value : 'request';
					break;
				case 'currency_position':
					$clean[ $key ] = array_key_exists( $value, Flexo_Booking_Money::positions() ) ? $value : 'after';
					break;
				case 'currency_decimals':
					$clean[ $key ] = min( 3, absint( $value ) );
					break;
				case 'number_format':
					$clean[ $key ] = array_key_exists( $value, Flexo_Booking_Money::number_formats() ) ? $value : 'auto';
					break;
				case 'currency':
					$clean[ $key ] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 10 ) );
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

		$clean['max_nights']       = max( $clean['min_nights'], $clean['max_nights'] );
		$clean['child_adult_from'] = max( $clean['child_free_under'], $clean['child_adult_from'] );

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

	public static function tabs() {
		$tabs = array(
			'general'  => __( 'General', 'flexo-booking' ),
			'features' => __( 'Features', 'flexo-booking' ),
		);
		if ( Flexo_Booking_Features::is_enabled( 'children' ) ) {
			$tabs['children'] = __( 'Children', 'flexo-booking' );
		}
		if ( Flexo_Booking_Features::is_enabled( 'tourist_tax' ) ) {
			$tabs['tourist_tax'] = __( 'Tourist tax', 'flexo-booking' );
		}
		$tabs['emails'] = __( 'Emails', 'flexo-booking' );
		return $tabs;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$tab  = array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
		$s    = self::all();
		$name = self::OPTION;
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Booking settings', 'flexo-booking' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => Flexo_Booking_Admin::MENU_SLUG . '-settings', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php if ( 'features' === $tab && isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Features saved.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( 'features' === $tab ) {
				Flexo_Booking_Features::render_tab();
				echo '</div>';
				return;
			}
			?>

			<form method="post" action="options.php">
				<?php settings_fields( 'flexo_booking' ); ?>

				<?php if ( 'general' === $tab ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Currency', 'flexo-booking' ); ?></th>
						<td>
							<input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency]" value="<?php echo esc_attr( $s['currency'] ); ?>" aria-label="<?php esc_attr_e( 'Currency code', 'flexo-booking' ); ?>" placeholder="EUR">
							<input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency_symbol]" value="<?php echo esc_attr( $s['currency_symbol'] ); ?>" aria-label="<?php esc_attr_e( 'Currency symbol', 'flexo-booking' ); ?>" placeholder="€">
							<select name="<?php echo esc_attr( $name ); ?>[currency_position]" aria-label="<?php esc_attr_e( 'Symbol position', 'flexo-booking' ); ?>">
								<?php foreach ( Flexo_Booking_Money::positions() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['currency_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p>
								<label><?php esc_html_e( 'Number format', 'flexo-booking' ); ?>
									<select name="<?php echo esc_attr( $name ); ?>[number_format]">
										<?php foreach ( Flexo_Booking_Money::number_formats() as $value => $format ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['number_format'], $value ); ?>><?php echo esc_html( $format[2] ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
								<label><?php esc_html_e( 'Decimals', 'flexo-booking' ); ?>
									<input type="number" min="0" max="3" class="small-text" name="<?php echo esc_attr( $name ); ?>[currency_decimals]" value="<?php echo esc_attr( $s['currency_decimals'] ); ?>">
								</label>
							</p>
							<p class="description">
								<?php
								/* translators: %s: example price */
								printf( esc_html__( 'Example: %s. Changing the currency does not convert your prices – update room prices yourself.', 'flexo-booking' ), '<strong>' . esc_html( Flexo_Booking_Money::format( 1234.5 ) ) . '</strong>' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stay length', 'flexo-booking' ); ?></th>
						<td>
							<?php esc_html_e( 'Minimum', 'flexo-booking' ); ?> <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[min_nights]" value="<?php echo esc_attr( $s['min_nights'] ); ?>">
							<?php esc_html_e( 'Maximum', 'flexo-booking' ); ?> <input type="number" min="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_nights]" value="<?php echo esc_attr( $s['max_nights'] ); ?>">
							<?php esc_html_e( 'nights', 'flexo-booking' ); ?>
							<p class="description"><?php esc_html_e( 'Rooms and seasons can set their own minimum.', 'flexo-booking' ); ?></p>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'flexo-booking' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( $s['delete_data_on_uninstall'], 1 ); ?>> <?php esc_html_e( 'Delete all rooms, bookings, prices and settings when the plugin is deleted', 'flexo-booking' ); ?></label>
						</td>
					</tr>
				</table>
				<?php endif; ?>

				<?php if ( 'children' === $tab ) : ?>
				<p><?php esc_html_e( 'Guests enter the age of each child when they search. Children count towards each room\'s "Max guests". The room price stays the same; these rules set what children pay for per-person extras such as breakfast or half board (rate plans charged per guest per night).', 'flexo-booking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-child-free"><?php esc_html_e( 'Free for children under', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-child-free" type="number" min="0" max="18" class="small-text" name="<?php echo esc_attr( $name ); ?>[child_free_under]" value="<?php echo esc_attr( $s['child_free_under'] ); ?>"> <?php esc_html_e( 'years', 'flexo-booking' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-child-percent"><?php esc_html_e( 'Older children pay', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-child-percent" type="number" min="0" max="100" step="0.01" class="small-text" name="<?php echo esc_attr( $name ); ?>[child_percent]" value="<?php echo esc_attr( Flexo_Booking_Children::percent_text( $s['child_percent'] ) ); ?>"> <?php esc_html_e( '% of the adult price', 'flexo-booking' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-child-adult"><?php esc_html_e( 'Adult price from age', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-child-adult" type="number" min="0" max="18" class="small-text" name="<?php echo esc_attr( $name ); ?>[child_adult_from]" value="<?php echo esc_attr( $s['child_adult_from'] ); ?>">
							<p class="description">
								<?php
								/* translators: %s: summary of the rules */
								printf( esc_html__( 'Now: %s.', 'flexo-booking' ), esc_html( Flexo_Booking_Children::describe( Flexo_Booking_Children::global_rules() ) ) );
								?>
								<?php esc_html_e( 'A room can use its own rules (edit the room → Child prices).', 'flexo-booking' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php endif; ?>

				<?php if ( 'tourist_tax' === $tab ) : ?>
				<p><?php esc_html_e( 'The tourist tax is shown as a separate line in the price breakdown and is never discounted by promo codes.', 'flexo-booking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-tax"><?php esc_html_e( 'Amount per adult per night', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-tax" type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $name ); ?>[tourist_tax_amount]" value="<?php echo esc_attr( $s['tourist_tax_amount'] ); ?>"> <?php echo esc_html( $s['currency'] ); ?>
							<p class="description"><?php esc_html_e( 'Set by your municipality. 0 = no tourist tax.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Children', 'flexo-booking' ); ?></th>
						<td>
							<fieldset>
								<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[tourist_tax_children]" value="adult" <?php checked( $s['tourist_tax_children'], 'adult' ); ?>> <?php esc_html_e( 'Children pay the same as adults', 'flexo-booking' ); ?></label>
								<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[tourist_tax_children]" value="exempt" <?php checked( $s['tourist_tax_children'], 'exempt' ); ?>>
									<?php esc_html_e( 'Children younger than', 'flexo-booking' ); ?>
									<input type="number" min="0" max="18" class="small-text" name="<?php echo esc_attr( $name ); ?>[tourist_tax_exempt_under]" value="<?php echo esc_attr( $s['tourist_tax_exempt_under'] ); ?>" aria-label="<?php esc_attr_e( 'Exemption age', 'flexo-booking' ); ?>">
									<?php esc_html_e( 'years don\'t pay; older children pay the full amount', 'flexo-booking' ); ?>
								</label>
								<?php if ( Flexo_Booking_Features::is_enabled( 'children' ) ) : ?>
									<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[tourist_tax_children]" value="rules" <?php checked( $s['tourist_tax_children'], 'rules' ); ?>> <?php esc_html_e( 'Use the child price rules (Settings → Children)', 'flexo-booking' ); ?></label>
								<?php endif; ?>
							</fieldset>
							<?php if ( ! Flexo_Booking_Features::is_enabled( 'children' ) ) : ?>
								<p class="description"><?php esc_html_e( 'Children\'s ages are only asked when "Children & ages" is switched on under Features. Without ages, every child pays the adult amount.', 'flexo-booking' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment', 'flexo-booking' ); ?></th>
						<td>
							<fieldset>
								<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[tourist_tax_collect]" value="booking" <?php checked( $s['tourist_tax_collect'], 'booking' ); ?>> <?php esc_html_e( 'Included in the booking total', 'flexo-booking' ); ?></label>
								<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[tourist_tax_collect]" value="property" <?php checked( $s['tourist_tax_collect'], 'property' ); ?>> <?php esc_html_e( 'Paid separately at the property (shown to the guest, not part of the total)', 'flexo-booking' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php endif; ?>

				<?php if ( 'emails' === $tab ) : ?>
				<p class="description">
					<?php esc_html_e( 'Placeholders:', 'flexo-booking' ); ?>
					<code>{reference}</code> <code>{guest_name}</code> <code>{guest_email}</code> <code>{guest_phone}</code> <code>{room}</code> <code>{check_in}</code> <code>{check_out}</code> <code>{nights}</code> <code>{guests}</code> <code>{total}</code> <code>{price_breakdown}</code> <code>{rate_plan}</code> <code>{cancellation_policy}</code> <code>{promo_code}</code> <code>{status}</code> <code>{booking_details}</code> <code>{check_in_time}</code> <code>{check_out_time}</code> <code>{site_name}</code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-notify"><?php esc_html_e( 'Send new booking alerts to', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-notify" type="email" class="regular-text" name="<?php echo esc_attr( $name ); ?>[notification_email]" value="<?php echo esc_attr( $s['notification_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
					</tr>
					<?php if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) : ?>
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
					<?php else : ?>
						<tr><td colspan="2"><p class="description"><?php esc_html_e( 'Guest emails are switched off under Settings → Features. Only the new-booking alert above is sent.', 'flexo-booking' ); ?></p></td></tr>
					<?php endif; ?>
				</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
