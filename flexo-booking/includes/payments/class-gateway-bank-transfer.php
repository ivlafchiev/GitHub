<?php
/**
 * Bank transfer (feature "bank_transfer"): the guest transfers the deposit
 * or the full amount; the hotel marks it as received.
 *
 * With instant booking the booking waits for the payment ("Awaiting
 * payment") until the deadline; with booking requests the bank details are
 * sent once the hotel accepts the request. Unpaid bookings can be cancelled
 * automatically after the deadline, with a reminder before it.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Gateway_Bank_Transfer implements Flexo_Booking_Payment_Gateway {

	public function id() {
		return 'bank_transfer';
	}

	public function label() {
		return __( 'Bank transfer', 'flexo-booking' );
	}

	public function admin_label() {
		return __( 'Bank transfer', 'flexo-booking' );
	}

	public function is_hosted() {
		return false;
	}

	public function is_ready() {
		$settings = Flexo_Booking_Settings::all();
		return '' !== trim( (string) $settings['bank_beneficiary'] ) && '' !== trim( (string) $settings['bank_iban'] );
	}

	public function is_available() {
		return Flexo_Booking_Features::is_enabled( 'bank_transfer' ) && $this->is_ready();
	}

	public function start( array $booking, array $args ) {
		return array( 'instructions' => $this->instructions( $booking ) );
	}

	/**
	 * Payment reference, e.g. the booking reference (setting "Payment reference").
	 */
	public function reference( array $booking ) {
		$format = trim( (string) Flexo_Booking_Settings::get( 'bank_reference' ) );
		$format = '' !== $format ? $format : '{booking_ref}';
		return trim(
			strtr(
				$format,
				array(
					'{booking_ref}' => $booking['reference'],
					'{reference}'   => $booking['reference'],
					'{guest_name}'  => $booking['guest_name'],
					'{check_in}'    => Flexo_Booking_I18n::format_date( $booking['check_in'] ),
				)
			)
		);
	}

	/**
	 * IBAN in groups of four, as printed on bank statements.
	 */
	public static function format_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		return trim( chunk_split( $iban, 4, ' ' ) );
	}

	/**
	 * Payment details for the guest.
	 *
	 * @return array { @type array[] $rows Each: label, value, key. @type string $note }
	 */
	public function instructions( array $booking ) {
		$settings = Flexo_Booking_Settings::all();
		$balance  = Flexo_Booking_Payments::balance( $booking );
		$rows     = array(
			array(
				'key'   => 'amount',
				'label' => __( 'Amount', 'flexo-booking' ),
				'value' => Flexo_Booking_Money::format( $balance['due_now'], $booking['currency'] ),
			),
			array(
				'key'   => 'beneficiary',
				'label' => __( 'Beneficiary', 'flexo-booking' ),
				'value' => $settings['bank_beneficiary'],
			),
			array(
				'key'   => 'iban',
				'label' => 'IBAN',
				'value' => self::format_iban( $settings['bank_iban'] ),
			),
		);
		if ( '' !== $settings['bank_bic'] ) {
			$rows[] = array(
				'key'   => 'bic',
				'label' => 'BIC / SWIFT',
				'value' => strtoupper( $settings['bank_bic'] ),
			);
		}
		if ( '' !== $settings['bank_name'] ) {
			$rows[] = array(
				'key'   => 'bank',
				'label' => __( 'Bank', 'flexo-booking' ),
				'value' => $settings['bank_name'],
			);
		}
		$rows[] = array(
			'key'   => 'reference',
			'label' => __( 'Payment reference', 'flexo-booking' ),
			'value' => $this->reference( $booking ),
		);
		if ( ! empty( $booking['payment_due_at'] ) ) {
			$rows[] = array(
				'key'   => 'deadline',
				'label' => __( 'Pay by', 'flexo-booking' ),
				'value' => Flexo_Booking_I18n::format_date( substr( $booking['payment_due_at'], 0, 10 ) ),
			);
		}
		$note = __( 'Please enter the payment reference exactly as shown, so we can match your payment.', 'flexo-booking' );
		if ( ! empty( $settings['bank_transfer_auto_cancel'] ) && ! empty( $booking['payment_due_at'] ) ) {
			$note .= ' ' . __( 'If the payment does not arrive by the date above, the booking is cancelled automatically.', 'flexo-booking' );
		}
		return array(
			'rows' => $rows,
			'note' => $note,
		);
	}

	/**
	 * The same details as plain text (emails).
	 */
	public function instructions_text( array $booking ) {
		$details = $this->instructions( $booking );
		$lines   = array();
		foreach ( $details['rows'] as $row ) {
			$lines[] = $row['label'] . ': ' . $row['value'];
		}
		return implode( "\n", $lines ) . "\n\n" . $details['note'];
	}
}
