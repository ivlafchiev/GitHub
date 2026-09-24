<?php
/**
 * A way for guests to pay (card via Stripe, bank transfer, …).
 *
 * Gateways are registered through the `flexo_booking_payment_gateways`
 * filter (see Flexo_Booking_Payments::gateways()). Amounts are never taken
 * from the browser: a gateway always receives the stored booking, whose
 * amount due was calculated by the pricing service.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

interface Flexo_Booking_Payment_Gateway {

	/**
	 * Short key stored on bookings and payments, e.g. "stripe".
	 */
	public function id();

	/**
	 * Name shown to guests, e.g. "Card".
	 */
	public function label();

	/**
	 * Name shown to the hotel, e.g. "Card (Stripe)".
	 */
	public function admin_label();

	/**
	 * Whether the hotel has entered everything the gateway needs.
	 */
	public function is_ready();

	/**
	 * Whether guests can use it now: feature on, configured and allowed in
	 * the current booking mode.
	 */
	public function is_available();

	/**
	 * True when the guest pays on another page right away (the room is held
	 * meanwhile); false when the payment arrives later (bank transfer).
	 */
	public function is_hosted();

	/**
	 * Starts the payment for a stored booking.
	 *
	 * @param array $booking Stored booking (Flexo_Booking_Bookings::get()).
	 * @param array $args    { @type string $token Guest access token, @type string $return_url Page to come back to. }
	 * @return array|WP_Error { @type string $redirect URL to send the guest to, @type array $instructions … }
	 */
	public function start( array $booking, array $args );
}
