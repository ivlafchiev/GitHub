<?php
/**
 * Guest and hotel email notifications.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Emails {

	public static function init() {
		add_action( 'flexo_booking_created', array( __CLASS__, 'on_created' ) );
		add_action( 'flexo_booking_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	public static function on_created( $booking ) {
		if ( 'website' !== $booking['source'] ) {
			return;
		}
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			self::send_guest( $booking, 'confirmed' === $booking['status'] ? 'confirmed' : 'request' );
		}
		self::send_admin( $booking );
	}

	public static function on_status_changed( $booking, $old_status, $new_status ) {
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) && in_array( $new_status, array( 'confirmed', 'cancelled' ), true ) ) {
			self::send_guest( $booking, $new_status );
		}
	}

	public static function send_guest( $booking, $type ) {
		if ( ! is_email( $booking['guest_email'] ) ) {
			return false;
		}
		$vars    = self::placeholders( $booking );
		$subject = strtr( (string) Flexo_Booking_Settings::get( 'email_' . $type . '_subject' ), $vars );
		$body    = strtr( (string) Flexo_Booking_Settings::get( 'email_' . $type . '_body' ), $vars );
		$headers = array( 'Reply-To: ' . Flexo_Booking_Settings::notification_email() );

		$email = apply_filters(
			'flexo_booking_guest_email',
			compact( 'subject', 'body', 'headers' ),
			$booking,
			$type
		);

		return wp_mail( $booking['guest_email'], $email['subject'], $email['body'], $email['headers'] );
	}

	public static function send_admin( $booking ) {
		$vars = self::placeholders( $booking );
		/* translators: 1: booking reference, 2: room name */
		$subject = sprintf( __( 'New booking %1$s – %2$s', 'flexo-booking' ), $booking['reference'], $booking['room_title'] );
		$body    = __( 'A new booking was made on your website.', 'flexo-booking' ) . "\n\n" . $vars['{booking_details}'] . "\n\n";
		$body   .= sprintf(
			"%s: %s\n%s: %s\n%s: %s\n",
			__( 'Guest', 'flexo-booking' ),
			$booking['guest_name'],
			__( 'Email', 'flexo-booking' ),
			$booking['guest_email'],
			__( 'Phone', 'flexo-booking' ),
			$booking['guest_phone']
		);
		if ( $booking['notes'] ) {
			$body .= __( 'Notes', 'flexo-booking' ) . ': ' . $booking['notes'] . "\n";
		}
		$body .= "\n" . __( 'Manage bookings:', 'flexo-booking' ) . ' ' . admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&s=' . rawurlencode( $booking['reference'] ) );

		$headers = array();
		if ( is_email( $booking['guest_email'] ) ) {
			$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $booking['guest_name'] ) . ' <' . $booking['guest_email'] . '>';
		}

		$email = apply_filters( 'flexo_booking_admin_email', compact( 'subject', 'body', 'headers' ), $booking );

		return wp_mail( Flexo_Booking_Settings::notification_email(), $email['subject'], $email['body'], $email['headers'] );
	}

	public static function placeholders( $booking ) {
		$date_format = get_option( 'date_format' );
		$check_in    = date_i18n( $date_format, strtotime( $booking['check_in'] ) );
		$check_out   = date_i18n( $date_format, strtotime( $booking['check_out'] ) );
		$guests      = sprintf(
			/* translators: %d: number of adults */
			_n( '%d adult', '%d adults', $booking['adults'], 'flexo-booking' ),
			$booking['adults']
		);
		if ( $booking['children'] ) {
			$guests .= ', ' . sprintf(
				/* translators: %d: number of children */
				_n( '%d child', '%d children', $booking['children'], 'flexo-booking' ),
				$booking['children']
			);
		}
		$total    = Flexo_Booking_Money::format( $booking['total'], $booking['currency'] );
		$snapshot = Flexo_Booking_Pricing::snapshot( $booking );
		$prices   = array();
		foreach ( Flexo_Booking_Pricing::format_lines( $snapshot ) as $row ) {
			foreach ( $row['details'] as $detail ) {
				$prices[] = '  ' . $detail;
			}
		}

		$details = array(
			__( 'Reference', 'flexo-booking' ) . ': ' . $booking['reference'],
			__( 'Room', 'flexo-booking' ) . ': ' . $booking['room_title'],
			__( 'Check-in', 'flexo-booking' ) . ': ' . $check_in,
			__( 'Check-out', 'flexo-booking' ) . ': ' . $check_out,
			__( 'Nights', 'flexo-booking' ) . ': ' . $booking['nights'],
			__( 'Guests', 'flexo-booking' ) . ': ' . $guests,
			__( 'Total', 'flexo-booking' ) . ': ' . $total,
		);
		// Nights priced differently (seasons, weekends) are listed under the total.
		$details   = array_merge( $details, $prices );
		$details[] = __( 'Status', 'flexo-booking' ) . ': ' . Flexo_Booking_Bookings::status_label( $booking['status'] );
		$details   = implode( "\n", $details );

		return apply_filters(
			'flexo_booking_email_placeholders',
			array(
				'{reference}'       => $booking['reference'],
				'{guest_name}'      => $booking['guest_name'],
				'{guest_email}'     => $booking['guest_email'],
				'{guest_phone}'     => $booking['guest_phone'],
				'{room}'            => $booking['room_title'],
				'{check_in}'        => $check_in,
				'{check_out}'       => $check_out,
				'{nights}'          => $booking['nights'],
				'{guests}'          => $guests,
				'{total}'           => $total,
				'{price_breakdown}' => Flexo_Booking_Pricing::summary_text( $snapshot ),
				'{status}'          => Flexo_Booking_Bookings::status_label( $booking['status'] ),
				'{booking_details}' => $details,
				'{check_in_time}'   => Flexo_Booking_Settings::get( 'check_in_time' ),
				'{check_out_time}'  => Flexo_Booking_Settings::get( 'check_out_time' ),
				'{site_name}'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
			$booking
		);
	}
}
