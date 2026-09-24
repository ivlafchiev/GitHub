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
			// Emails (Day 4).
			'hotel_phone'                   => '',
			'email_logo'                    => '',
			'email_color'                   => '#1f6f5c',
			'notify_new'                    => 1,
			'notify_cancelled'              => 1,
			'notify_conflict'               => 1,
			'email_pre_arrival_enabled'     => 0,
			'email_pre_arrival_days'        => 3,
			'email_pre_arrival_subject'     => __( 'See you soon at {hotel_name} – booking {booking_ref}', 'flexo-booking' ),
			'email_pre_arrival_body'        => __( "Hello {guest_name},\n\nWe look forward to welcoming you at {hotel_name} on {check_in}.\n\nCheck-in is from {check_in_time} and check-out until {check_out_time}. If you have any questions about your arrival, directions or parking, simply reply to this email or call us at {hotel_phone}.\n\n{booking_details}\n\nSee you soon,\n{hotel_name}", 'flexo-booking' ),
			'email_review_enabled'          => 0,
			'email_review_days'             => 1,
			'review_link'                   => '',
			'email_review_subject'          => __( 'How was your stay at {hotel_name}?', 'flexo-booking' ),
			'email_review_body'             => __( "Hello {guest_name},\n\nThank you for staying with us. We hope you enjoyed your time at {hotel_name}.\n\nWe would be very grateful if you shared your experience. It takes a minute and helps other guests:\n{review_link}\n\nKind regards,\n{hotel_name}", 'flexo-booking' ),
			// Payments (Day 5).
			'email_awaiting_deposit_subject'  => __( 'Payment details for your booking {booking_ref}', 'flexo-booking' ),
			'email_awaiting_deposit_body'     => __( "Hello {guest_name},\n\nThank you for your booking at {hotel_name}. To confirm it, please pay {amount_due} by bank transfer by {payment_deadline}.\n\n{payment_instructions}\n\n{booking_details}\n\nKind regards,\n{hotel_name}", 'flexo-booking' ),
			'email_payment_reminder_subject'  => __( 'Reminder: payment for booking {booking_ref}', 'flexo-booking' ),
			'email_payment_reminder_body'     => __( "Hello {guest_name},\n\nA friendly reminder: we have not received your payment of {amount_due} for booking {booking_ref} yet. Please pay by {payment_deadline} to keep your booking.\n\n{payment_instructions}\n\nIf you have already paid, thank you – please ignore this message.\n\nKind regards,\n{hotel_name}", 'flexo-booking' ),
			'email_payment_received_subject'  => __( 'Payment received – your booking {booking_ref} is confirmed', 'flexo-booking' ),
			'email_payment_received_body'     => __( "Hello {guest_name},\n\nThank you, we received your payment of {amount_paid}. Your booking at {hotel_name} is confirmed.\n\n{booking_details}\n\nCheck-in from {check_in_time}, check-out until {check_out_time}.\n\nWe look forward to welcoming you!\n{hotel_name}", 'flexo-booking' ),
			'email_payment_failed_subject'    => __( 'Payment for booking {booking_ref} did not go through', 'flexo-booking' ),
			'email_payment_failed_body'       => __( "Hello {guest_name},\n\nUnfortunately your payment for booking {booking_ref} did not go through, so the room is no longer reserved for you.\n\nYou are welcome to book again on our website, or contact us at {hotel_phone} and we will be glad to help.\n\nKind regards,\n{hotel_name}", 'flexo-booking' ),
			'email_payment_cancelled_subject' => __( 'Booking {booking_ref} cancelled – payment not received', 'flexo-booking' ),
			'email_payment_cancelled_body'    => __( "Hello {guest_name},\n\nWe did not receive your payment for booking {booking_ref} by {payment_deadline}, so the booking has been cancelled and the room is available again.\n\nIf you have paid in the meantime or would still like to stay with us, please reply to this email or call us at {hotel_phone}.\n\nKind regards,\n{hotel_name}", 'flexo-booking' ),
			'notify_payment'                  => 1,
			'notify_payment_conflict'         => 1,
			'payment_mode'                    => 'full',
			'deposit_type'                    => 'percent',
			'deposit_value'                   => 30,
			'stripe_mode'                     => 'test',
			'hold_minutes'                    => 30,
			'bank_beneficiary'                => '',
			'bank_iban'                       => '',
			'bank_bic'                        => '',
			'bank_name'                       => '',
			'bank_reference'                  => '{booking_ref}',
			'bank_transfer_days'              => 3,
			'bank_transfer_reminder_days'     => 1,
			'bank_transfer_auto_cancel'       => 1,
			'email_log_days'                => 90,
			// Guest details form (Day 4, data minimisation).
			'field_phone'                   => 'required',
			'field_notes'                   => 'optional',
			// Privacy consent (Day 4).
			'privacy_consent_required'      => 1,
			'privacy_consent_text'          => __( 'I agree to the {privacy_policy} and consent to my information being processed for the purpose of my booking.', 'flexo-booking' ),
			'privacy_page'                  => '',
			'retention_months'              => 0,
			// Invoice request fields (Day 4): required / optional / hidden.
			'invoice_full_name'             => 'required',
			'invoice_address'               => 'required',
			'invoice_company_name'          => 'required',
			'invoice_company_id'            => 'required',
			'invoice_vat_number'            => 'optional',
			'invoice_company_address'       => 'required',
			'invoice_contact_person'        => 'optional',
			// Tracking (Day 4).
			'tracking_meta_pixel'           => 0,
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

	/**
	 * First notification address (Reply-To for guest emails).
	 */
	public static function notification_email() {
		$emails = self::notification_emails();
		return $emails[0];
	}

	/**
	 * All addresses that receive hotel notifications ("a@x.com, b@x.com").
	 *
	 * @return string[] Never empty: falls back to the site admin email.
	 */
	public static function notification_emails() {
		$emails = array_values( array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) self::get( 'notification_email' ) ) ), 'is_email' ) );
		return $emails ? $emails : array( get_option( 'admin_email' ) );
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
					$clean[ $key ] = implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[,;\s]+/', (string) $value ) ) ) );
					break;
				case 'payment_mode':
					$clean[ $key ] = in_array( $value, array( 'property', 'deposit', 'full' ), true ) ? $value : 'full';
					break;
				case 'deposit_type':
					$clean[ $key ] = in_array( $value, array( 'percent', 'fixed' ), true ) ? $value : 'percent';
					break;
				case 'deposit_value':
					$clean[ $key ] = max( 0, round( (float) str_replace( ',', '.', (string) $value ), 2 ) );
					break;
				case 'stripe_mode':
					$clean[ $key ] = 'live' === $value ? 'live' : 'test';
					break;
				case 'hold_minutes':
					$clean[ $key ] = min( 30, max( 20, absint( $value ) ) );
					break;
				case 'bank_iban':
					$clean[ $key ] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
					break;
				case 'bank_bic':
					$clean[ $key ] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
					break;
				case 'bank_transfer_days':
					$clean[ $key ] = min( 60, max( 1, absint( $value ) ) );
					break;
				case 'bank_transfer_reminder_days':
					$clean[ $key ] = min( 30, absint( $value ) );
					break;
				case 'notify_payment':
				case 'notify_payment_conflict':
				case 'bank_transfer_auto_cancel':
				case 'notify_new':
				case 'notify_cancelled':
				case 'notify_conflict':
				case 'email_pre_arrival_enabled':
				case 'email_review_enabled':
				case 'privacy_consent_required':
				case 'tracking_meta_pixel':
					$clean[ $key ] = empty( $value ) ? 0 : 1;
					break;
				case 'email_pre_arrival_days':
					$clean[ $key ] = min( 30, max( 1, absint( $value ) ) );
					break;
				case 'email_review_days':
					$clean[ $key ] = min( 30, absint( $value ) );
					break;
				case 'email_log_days':
					$clean[ $key ] = min( 730, max( 7, absint( $value ) ) );
					break;
				case 'retention_months':
					$clean[ $key ] = min( 240, absint( $value ) );
					break;
				case 'email_color':
					$clean[ $key ] = sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : $default;
					break;
				case 'email_logo':
				case 'review_link':
					$clean[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;
				case 'privacy_page':
					$clean[ $key ] = self::sanitize_path_or_url( $value );
					break;
				case 'field_phone':
					$clean[ $key ] = in_array( $value, array( 'required', 'optional', 'hidden' ), true ) ? $value : 'required';
					break;
				case 'field_notes':
					$clean[ $key ] = in_array( $value, array( 'optional', 'hidden' ), true ) ? $value : 'optional';
					break;
				case 'invoice_full_name':
				case 'invoice_address':
				case 'invoice_company_name':
				case 'invoice_company_id':
				case 'invoice_vat_number':
				case 'invoice_company_address':
				case 'invoice_contact_person':
					$clean[ $key ] = in_array( $value, array( 'required', 'optional', 'hidden' ), true ) ? $value : $default;
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

		if ( 'percent' === $clean['deposit_type'] ) {
			$clean['deposit_value'] = min( 100, $clean['deposit_value'] );
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
		// API keys live in their own option (never exported).
		register_setting(
			'flexo_booking',
			Flexo_Booking_Payments::SECRETS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Flexo_Booking_Payments', 'sanitize_secrets' ),
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
		if ( Flexo_Booking_Payments::enabled() ) {
			$tabs['payments'] = __( 'Payments', 'flexo-booking' );
		}
		if ( Flexo_Booking_Features::is_enabled( 'privacy_consent' ) ) {
			$tabs['privacy'] = __( 'Privacy', 'flexo-booking' );
		}
		if ( Flexo_Booking_Features::is_enabled( 'invoice_request' ) ) {
			$tabs['invoices'] = __( 'Invoices', 'flexo-booking' );
		}
		$tabs['emails'] = __( 'Emails', 'flexo-booking' );
		if ( Flexo_Booking_Features::is_enabled( 'tracking' ) ) {
			$tabs['tracking'] = __( 'Tracking', 'flexo-booking' );
		}
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
			<?php Flexo_Booking_Seasons_Admin::notices(); ?>
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
						<th scope="row"><?php esc_html_e( 'Guest details form', 'flexo-booking' ); ?></th>
						<td>
							<p><?php esc_html_e( 'Name and email are always required.', 'flexo-booking' ); ?></p>
							<p><label><?php esc_html_e( 'Phone', 'flexo-booking' ); ?>
								<select name="<?php echo esc_attr( $name ); ?>[field_phone]">
									<option value="required" <?php selected( $s['field_phone'], 'required' ); ?>><?php esc_html_e( 'Required', 'flexo-booking' ); ?></option>
									<option value="optional" <?php selected( $s['field_phone'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'flexo-booking' ); ?></option>
									<option value="hidden" <?php selected( $s['field_phone'], 'hidden' ); ?>><?php esc_html_e( 'Not asked', 'flexo-booking' ); ?></option>
								</select></label>
								<label><?php esc_html_e( 'Special requests', 'flexo-booking' ); ?>
								<select name="<?php echo esc_attr( $name ); ?>[field_notes]">
									<option value="optional" <?php selected( $s['field_notes'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'flexo-booking' ); ?></option>
									<option value="hidden" <?php selected( $s['field_notes'], 'hidden' ); ?>><?php esc_html_e( 'Not asked', 'flexo-booking' ); ?></option>
								</select></label></p>
							<p class="description"><?php esc_html_e( 'Ask only for what you need (data minimisation).', 'flexo-booking' ); ?></p>
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
				<h2><?php esc_html_e( 'Your hotel', 'flexo-booking' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-notify"><?php esc_html_e( 'Send hotel notifications to', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-notify" type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[notification_email]" value="<?php echo esc_attr( $s['notification_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'One or more addresses, separated by commas, e.g. reception@hotel.bg, owner@hotel.bg. Guests\' replies go to the first one.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notify the hotel about', 'flexo-booking' ); ?></th>
						<td>
							<?php foreach ( Flexo_Booking_Emails::hotel_types() as $flexo_type => $flexo_def ) : ?>
								<?php
								if ( ( 'ical_conflict' === $flexo_type && ! Flexo_Booking_Features::is_enabled( 'calendar_sync' ) ) || ( ! empty( $flexo_def['payment'] ) && ! Flexo_Booking_Features::is_enabled( 'online_payment' ) ) ) {
									echo '<input type="hidden" name="' . esc_attr( $name . '[' . $flexo_def['setting'] . ']' ) . '" value="' . esc_attr( $s[ $flexo_def['setting'] ] ) . '">';
									continue;
								}
								?>
								<input type="hidden" name="<?php echo esc_attr( $name . '[' . $flexo_def['setting'] . ']' ); ?>" value="0">
								<label class="flexo-feature-choice"><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $flexo_def['setting'] . ']' ); ?>" value="1" <?php checked( $s[ $flexo_def['setting'] ], 1 ); ?>> <?php echo esc_html( $flexo_def['label'] ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-phone"><?php esc_html_e( 'Hotel phone', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-phone" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[hotel_phone]" value="<?php echo esc_attr( $s['hotel_phone'] ); ?>" placeholder="+359 …"> <span class="description"><?php esc_html_e( 'Used by {hotel_phone} and in the email footer.', 'flexo-booking' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email design', 'flexo-booking' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Colour', 'flexo-booking' ); ?> <input type="color" name="<?php echo esc_attr( $name ); ?>[email_color]" value="<?php echo esc_attr( $s['email_color'] ); ?>"></label>
							<p><label><?php esc_html_e( 'Logo URL', 'flexo-booking' ); ?> <input type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[email_logo]" value="<?php echo esc_attr( $s['email_logo'] ); ?>" placeholder="<?php echo esc_attr( Flexo_Booking_Emails::logo_url() ); ?>"></label></p>
							<p class="description"><?php esc_html_e( 'Leave the logo empty to use the site logo (Appearance → Customize). Emails are simple HTML that works on phones, with a plain-text version for email programs that don\'t show HTML.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Emails to guests', 'flexo-booking' ); ?></h2>
				<?php if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Each email is sent in the language the guest booked in. Texts you haven\'t changed are translated automatically; to translate your own texts, use Polylang or WPML string translation (group "Flexo Booking").', 'flexo-booking' ); ?>
					<br><?php esc_html_e( 'Placeholders:', 'flexo-booking' ); ?>
					<?php foreach ( Flexo_Booking_Emails::placeholder_names() as $flexo_placeholder ) : ?>
						<code><?php echo esc_html( $flexo_placeholder ); ?></code>
					<?php endforeach; ?>
				</p>
				<table class="form-table" role="presentation">
					<?php foreach ( Flexo_Booking_Emails::guest_types() as $type => $flexo_def ) : ?>
						<?php
						if ( ! empty( $flexo_def['payment'] ) && ! self::payment_email_used( $flexo_def['payment'] ) ) {
							continue; // Only for the ways of paying that are on.
						}
						?>
						<tr id="flexo-email-<?php echo esc_attr( $type ); ?>">
							<th scope="row"><?php echo esc_html( $flexo_def['label'] ); ?></th>
							<td>
								<?php if ( 'pre_arrival' === $type ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>[email_pre_arrival_enabled]" value="0">
									<p class="flexo-inline-form"><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[email_pre_arrival_enabled]" value="1" <?php checked( $s['email_pre_arrival_enabled'], 1 ); ?>> <?php esc_html_e( 'Send', 'flexo-booking' ); ?></label>
									<input type="number" min="1" max="30" class="small-text" name="<?php echo esc_attr( $name ); ?>[email_pre_arrival_days]" value="<?php echo esc_attr( $s['email_pre_arrival_days'] ); ?>" aria-label="<?php esc_attr_e( 'Days before arrival', 'flexo-booking' ); ?>">
									<?php esc_html_e( 'days before arrival, to confirmed bookings. Add check-in details, directions and parking to the text.', 'flexo-booking' ); ?></p>
								<?php elseif ( 'review' === $type ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>[email_review_enabled]" value="0">
									<p class="flexo-inline-form"><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[email_review_enabled]" value="1" <?php checked( $s['email_review_enabled'], 1 ); ?>> <?php esc_html_e( 'Send', 'flexo-booking' ); ?></label>
									<input type="number" min="0" max="30" class="small-text" name="<?php echo esc_attr( $name ); ?>[email_review_days]" value="<?php echo esc_attr( $s['email_review_days'] ); ?>" aria-label="<?php esc_attr_e( 'Days after check-out', 'flexo-booking' ); ?>">
									<?php esc_html_e( 'days after check-out, to guests of confirmed bookings.', 'flexo-booking' ); ?></p>
									<p><label><?php esc_html_e( 'Review link', 'flexo-booking' ); ?> <input type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[review_link]" value="<?php echo esc_attr( $s['review_link'] ); ?>" placeholder="https://g.page/r/…/review"></label></p>
									<p class="description"><?php esc_html_e( 'E.g. your Google review link. Without a link, no review request is sent.', 'flexo-booking' ); ?></p>
								<?php endif; ?>
								<input type="text" class="large-text" name="<?php echo esc_attr( $name . '[email_' . $type . '_subject]' ); ?>" value="<?php echo esc_attr( $s[ 'email_' . $type . '_subject' ] ); ?>" aria-label="<?php esc_attr_e( 'Subject', 'flexo-booking' ); ?>">
								<textarea class="large-text" rows="7" name="<?php echo esc_attr( $name . '[email_' . $type . '_body]' ); ?>" aria-label="<?php esc_attr_e( 'Message', 'flexo-booking' ); ?>"><?php echo esc_textarea( $s[ 'email_' . $type . '_body' ] ); ?></textarea>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( 'Reminders and review requests are sent by WordPress\'s scheduled tasks once an hour, between 8:00 and 21:00, only once per booking and never for cancelled bookings.', 'flexo-booking' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Guest emails are switched off under Settings → Features. Only the notifications to the hotel are sent.', 'flexo-booking' ); ?></p>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Email log', 'flexo-booking' ); ?></h2>
				<p><label><?php esc_html_e( 'Keep the log for', 'flexo-booking' ); ?> <input type="number" min="7" max="730" class="small-text" name="<?php echo esc_attr( $name ); ?>[email_log_days]" value="<?php echo esc_attr( $s['email_log_days'] ); ?>"> <?php esc_html_e( 'days', 'flexo-booking' ); ?></label></p>
				<?php endif; ?>

				<?php if ( 'payments' === $tab ) : ?>
					<?php Flexo_Booking_Payments_Admin::render_settings( $s, $name ); ?>
				<?php endif; ?>

				<?php if ( 'privacy' === $tab ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Consent checkbox', 'flexo-booking' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[privacy_consent_required]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[privacy_consent_required]" value="1" <?php checked( $s['privacy_consent_required'], 1 ); ?>> <?php esc_html_e( 'Guests must tick it to book', 'flexo-booking' ); ?></label>
							<p class="description"><?php esc_html_e( 'The box is never ticked in advance. When a guest ticks it, the booking keeps the date, time and the exact text they agreed to.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-consent-text"><?php esc_html_e( 'Text next to the checkbox', 'flexo-booking' ); ?></label></th>
						<td>
							<textarea id="fb-consent-text" class="large-text" rows="2" name="<?php echo esc_attr( $name ); ?>[privacy_consent_text]"><?php echo esc_textarea( $s['privacy_consent_text'] ); ?></textarea>
							<p class="description"><?php esc_html_e( '{privacy_policy} becomes a link to your privacy policy.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-privacy-page"><?php esc_html_e( 'Privacy policy page', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-privacy-page" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[privacy_page]" value="<?php echo esc_attr( $s['privacy_page'] ); ?>" placeholder="<?php echo esc_attr( get_privacy_policy_url() ? wp_make_link_relative( get_privacy_policy_url() ) : '/privacy-policy/' ); ?>">
							<p class="description">
								<?php esc_html_e( 'Leave empty to use the page chosen under Settings → Privacy.', 'flexo-booking' ); ?>
								<?php if ( ! get_privacy_policy_url() ) : ?>
									<strong><?php esc_html_e( 'No privacy policy page is chosen there yet.', 'flexo-booking' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-retention"><?php esc_html_e( 'Remove guest details', 'flexo-booking' ); ?></label></th>
						<td>
							<input id="fb-retention" type="number" min="0" max="240" class="small-text" name="<?php echo esc_attr( $name ); ?>[retention_months]" value="<?php echo esc_attr( $s['retention_months'] ); ?>">
							<?php esc_html_e( 'months after check-out (0 = never)', 'flexo-booking' ); ?>
							<p class="description"><?php esc_html_e( 'Once a day, bookings whose check-out is older than this are anonymised: the guest\'s name, email, phone, special requests and invoice details are removed; dates, room, prices and status stay for your statistics. Personal data should not be kept longer than needed – many hotels choose 24 months, which still covers returning guests and complaints. Invoices you issued are kept in your accounting, not here. Ask your accountant or lawyer if unsure.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
				</table>
				<p><?php esc_html_e( 'Guests can also ask for their data: use Tools → Export Personal Data and Tools → Erase Personal Data with the guest\'s email address. Bookings, invoice details and emails sent are included. A suggested text for your privacy policy is under Settings → Privacy → Policy Guide.', 'flexo-booking' ); ?></p>
				<?php endif; ?>

				<?php if ( 'invoices' === $tab ) : ?>
				<p><?php esc_html_e( 'Guests can tick "I would like an invoice" and enter the details for a person or a company. The plugin only collects the details for you; issue the invoice in your accounting software as usual.', 'flexo-booking' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( Flexo_Booking_Invoices::fields() as $flexo_field => $flexo_def ) : ?>
						<tr>
							<th scope="row"><label for="fb-inv-<?php echo esc_attr( $flexo_field ); ?>"><?php echo esc_html( $flexo_def['label'] ); ?></label></th>
							<td>
								<select id="fb-inv-<?php echo esc_attr( $flexo_field ); ?>" name="<?php echo esc_attr( $name . '[invoice_' . $flexo_field . ']' ); ?>">
									<option value="required" <?php selected( $s[ 'invoice_' . $flexo_field ], 'required' ); ?>><?php esc_html_e( 'Required', 'flexo-booking' ); ?></option>
									<option value="optional" <?php selected( $s[ 'invoice_' . $flexo_field ], 'optional' ); ?>><?php esc_html_e( 'Optional', 'flexo-booking' ); ?></option>
									<option value="hidden" <?php selected( $s[ 'invoice_' . $flexo_field ], 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'flexo-booking' ); ?></option>
								</select>
								<span class="description"><?php echo esc_html( 'company' === $flexo_def['for'] ? __( 'Company invoice', 'flexo-booking' ) : __( 'Invoice for a person', 'flexo-booking' ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( 'The company ID (EIK/BULSTAT) is only checked lightly: when it is made of digits only, it must have 9 or 13 digits. Foreign company numbers and VAT numbers are not checked.', 'flexo-booking' ); ?></p>
				<?php endif; ?>

				<?php if ( 'tracking' === $tab ) : ?>
				<p><?php esc_html_e( 'The booking form sends these events to the Google Tag Manager data layer (window.dataLayer). In Tag Manager, create a "Custom Event" trigger for each event name and connect it to your Google Analytics 4 or Meta Pixel tags. Events contain no names, emails or phone numbers.', 'flexo-booking' ); ?></p>
				<table class="widefat striped flexo-tracking-events">
					<thead><tr><th><?php esc_html_e( 'Event', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'When', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Data', 'flexo-booking' ); ?></th></tr></thead>
					<tbody>
						<tr><td><code>search</code></td><td><?php esc_html_e( 'The guest searches for dates', 'flexo-booking' ); ?></td><td><code>search_term</code>, <code>check_in</code>, <code>check_out</code>, <code>nights</code>, <code>adults</code>, <code>children</code></td></tr>
						<tr><td><code>room_select</code></td><td><?php esc_html_e( 'The guest chooses a room (and rate plan)', 'flexo-booking' ); ?></td><td><code>room</code>, <code>rate_plan</code>, <code>value</code>, <code>currency</code>, <code>ecommerce.items</code></td></tr>
						<tr><td><code>begin_checkout</code></td><td><?php esc_html_e( 'The guest details form is shown', 'flexo-booking' ); ?></td><td><code>value</code>, <code>currency</code>, <code>ecommerce</code></td></tr>
						<tr><td><code>booking_complete</code></td><td><?php esc_html_e( 'The booking or request was sent (once per booking)', 'flexo-booking' ); ?></td><td><code>booking_reference</code>, <code>booking_status</code>, <code>room</code>, <code>rate_plan</code>, <code>value</code>, <code>currency</code>, <code>ecommerce.transaction_id</code></td></tr>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Cookie and consent plugins stay in control: the events only go into the data layer, and your Tag Manager setup (with Consent Mode) decides what is sent to Google or Meta.', 'flexo-booking' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Meta Pixel without Tag Manager', 'flexo-booking' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[tracking_meta_pixel]" value="0">
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[tracking_meta_pixel]" value="1" <?php checked( $s['tracking_meta_pixel'], 1 ); ?>> <?php esc_html_e( 'Also send Search, InitiateCheckout and Purchase to the Meta Pixel directly when it is on the page', 'flexo-booking' ); ?></label>
							<p class="description"><?php esc_html_e( 'Only for sites that add the Meta Pixel without Tag Manager. Leave it off if Tag Manager sends to Meta, or the events are counted twice. The pixel only receives events after your cookie plugin has loaded it.', 'flexo-booking' ); ?></p>
						</td>
					</tr>
				</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
			<?php
			if ( 'emails' === $tab ) {
				self::render_email_tools();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Whether a payment email is used with the ways of paying that are on.
	 *
	 * @param string $kind bank | card | any.
	 */
	private static function payment_email_used( $kind ) {
		if ( 'bank' === $kind ) {
			return Flexo_Booking_Features::is_enabled( 'bank_transfer' );
		}
		if ( 'card' === $kind ) {
			return Flexo_Booking_Features::is_enabled( 'online_payment' );
		}
		return Flexo_Booking_Payments::enabled();
	}

	/**
	 * Test email and the email log (outside the settings form).
	 */
	private static function render_email_tools() {
		$log = Flexo_Booking_Emails::log_entries( array( 'limit' => 50 ) );
		?>
		<div class="flexo-tools-card" id="flexo-test-email">
			<h2><?php esc_html_e( 'Send a test email', 'flexo-booking' ); ?></h2>
			<p><?php esc_html_e( 'Sends the "Booking confirmed" email with example details, so you can see how it looks and whether it arrives.', 'flexo-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form">
				<input type="hidden" name="action" value="flexo_booking_test_email">
				<?php wp_nonce_field( 'flexo_booking_test_email' ); ?>
				<input type="email" name="test_email" class="regular-text" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" aria-label="<?php esc_attr_e( 'Send to', 'flexo-booking' ); ?>">
				<?php submit_button( __( 'Send test email', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<div class="flexo-tools-card" id="flexo-email-log">
			<h2><?php esc_html_e( 'Recent emails', 'flexo-booking' ); ?></h2>
			<table class="widefat striped flexo-email-log">
				<thead><tr>
					<th><?php esc_html_e( 'Time', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Email', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'To', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Result', 'flexo-booking' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No emails sent yet.', 'flexo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $entry['created_at'] ) ); ?></td>
						<td><?php echo esc_html( Flexo_Booking_Emails::type_label( $entry['email_type'] ) ); ?></td>
						<td><?php echo esc_html( $entry['recipient'] ); ?></td>
						<td><?php echo esc_html( $entry['subject'] ); ?></td>
						<td>
							<?php if ( 'sent' === $entry['status'] ) : ?>
								<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'Sent', 'flexo-booking' ); ?></span>
							<?php else : ?>
								<span class="flexo-status flexo-status--error">✕ <?php esc_html_e( 'Failed', 'flexo-booking' ); ?></span>
								<div class="flexo-sync-error"><?php echo esc_html( (string) $entry['error'] ); ?></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( '"Sent" means your website handed the email to the mail server. If guests still don\'t receive emails, set up an SMTP plugin.', 'flexo-booking' ); ?></p>
		</div>
		<?php
	}
}
