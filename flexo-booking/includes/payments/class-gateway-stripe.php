<?php
/**
 * Card payments with Stripe Checkout (feature "online_payment").
 *
 * The guest pays on Stripe's own payment page, so card details never reach
 * this website. The booking is confirmed only by Stripe's signed webhook
 * (checkout.session.completed), never by the guest returning to the site.
 * Stored: Stripe's IDs (Checkout Session, PaymentIntent, refunds), amount,
 * currency, status and time – nothing about the card.
 *
 * Webhook events handled:
 *   checkout.session.completed / async_payment_succeeded → payment recorded, booking confirmed
 *   checkout.session.async_payment_failed               → payment failed, room released
 *   checkout.session.expired                            → not paid, room released
 *   payment_intent.payment_failed                       → failed attempt noted (the guest may retry)
 *   charge.refunded                                     → refund recorded
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Gateway_Stripe implements Flexo_Booking_Payment_Gateway {

	const API_VERSION = '2024-06-20';

	/**
	 * Currencies Stripe counts in whole units.
	 */
	const ZERO_DECIMAL = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );

	public function id() {
		return 'stripe';
	}

	public function label() {
		return __( 'Card', 'flexo-booking' );
	}

	public function admin_label() {
		return __( 'Card (Stripe)', 'flexo-booking' );
	}

	public function is_hosted() {
		return true;
	}

	/**
	 * Test mode unless the hotel switched to live payments.
	 */
	public function is_live() {
		return 'live' === Flexo_Booking_Settings::get( 'stripe_mode' );
	}

	public function secret_key() {
		return Flexo_Booking_Payments::secret( $this->is_live() ? 'stripe_live_secret_key' : 'stripe_test_secret_key' );
	}

	public function webhook_secret() {
		return Flexo_Booking_Payments::secret( $this->is_live() ? 'stripe_live_webhook_secret' : 'stripe_test_webhook_secret' );
	}

	public function is_ready() {
		return '' !== $this->secret_key() && '' !== $this->webhook_secret();
	}

	/**
	 * Card payments need instant booking: a guest is never charged for a
	 * booking the hotel hasn't accepted yet.
	 */
	public function is_available() {
		return Flexo_Booking_Features::is_enabled( 'online_payment' ) && 'instant' === Flexo_Booking_Features::booking_mode() && $this->is_ready();
	}

	public static function webhook_url() {
		return rest_url( Flexo_Booking_Rest::NAMESPACE_V1 . '/stripe-webhook' );
	}

	/**
	 * Stripe API address (a test server can be used instead).
	 */
	private function api_base() {
		return untrailingslashit( (string) apply_filters( 'flexo_booking_stripe_api_base', 'https://api.stripe.com/v1' ) );
	}

	public static function to_minor( $amount, $currency ) {
		$factor = in_array( strtolower( $currency ), self::ZERO_DECIMAL, true ) ? 1 : 100;
		return (int) round( (float) $amount * $factor );
	}

	public static function from_minor( $amount, $currency ) {
		$factor = in_array( strtolower( $currency ), self::ZERO_DECIMAL, true ) ? 1 : 100;
		return round( (int) $amount / $factor, 2 );
	}

	/**
	 * @return array|WP_Error Decoded response.
	 */
	private function api( $method, $path, array $params = array(), $idempotency_key = '' ) {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key(),
			'Stripe-Version' => self::API_VERSION,
		);
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$response = wp_remote_request(
			$this->api_base() . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => $params ? http_build_query( $params, '', '&' ) : null,
				'timeout' => 20,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'flexo_stripe', __( 'Online payment is not available at the moment. Please try again in a few minutes.', 'flexo-booking' ), array( 'detail' => $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$detail = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'flexo_stripe', __( 'Online payment is not available at the moment. Please try again in a few minutes.', 'flexo-booking' ), array( 'detail' => $detail ) );
		}
		return $data;
	}

	/**
	 * Opens a Stripe Checkout page for the amount due on the booking.
	 */
	public function start( array $booking, array $args ) {
		$amount = Flexo_Booking_Payments::balance( $booking )['due_now'];
		if ( $amount <= 0 ) {
			return new WP_Error( 'flexo_stripe', __( 'There is nothing to pay for this booking.', 'flexo-booking' ) );
		}
		$currency = strtolower( $booking['currency'] );
		$back     = Flexo_Booking_Payments::return_url( isset( $args['return_url'] ) ? $args['return_url'] : '' );
		$query    = array(
			'fb_ref' => $booking['reference'],
			'fb_key' => $args['token'],
		);
		$hold_end = ! empty( $booking['hold_expires_at'] ) ? strtotime( get_gmt_from_date( $booking['hold_expires_at'] ) . ' UTC' ) : time();
		$deposit  = $amount + 0.005 < (float) $booking['total'];
		$name     = $deposit
			/* translators: %s: booking reference */
			? sprintf( __( 'Deposit for booking %s', 'flexo-booking' ), $booking['reference'] )
			/* translators: %s: booking reference */
			: sprintf( __( 'Booking %s', 'flexo-booking' ), $booking['reference'] );
		$lang     = strtolower( substr( (string) $booking['locale'], 0, 2 ) );

		$params = array(
			'mode'                => 'payment',
			'success_url'         => add_query_arg( array_merge( array( 'fb_payment' => 'return' ), $query ), $back ),
			'cancel_url'          => add_query_arg( array_merge( array( 'fb_payment' => 'cancel' ), $query ), $back ),
			'client_reference_id' => $booking['reference'],
			'customer_email'      => $booking['guest_email'],
			// Stripe keeps a payment page open for at least 30 minutes; the
			// page is closed earlier when the hold ends (see cancel()).
			'expires_at'          => max( $hold_end, time() + 31 * MINUTE_IN_SECONDS ),
			'locale'              => in_array( $lang, array( 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hr', 'hu', 'it', 'lt', 'lv', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr' ), true ) ? $lang : 'auto',
			'line_items'          => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => $currency,
						'unit_amount'  => self::to_minor( $amount, $currency ),
						'product_data' => array(
							'name'        => $name,
							'description' => $booking['room_title'] . ', ' . Flexo_Booking_I18n::format_date( $booking['check_in'] ) . ' – ' . Flexo_Booking_I18n::format_date( $booking['check_out'] ),
						),
					),
				),
			),
			'metadata'            => array(
				'booking_id' => (string) $booking['id'],
				'reference'  => $booking['reference'],
				'site'       => home_url(),
			),
			'payment_intent_data' => array(
				'description' => $name . ' – ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'metadata'    => array(
					'booking_id' => (string) $booking['id'],
					'reference'  => $booking['reference'],
				),
			),
		);
		$params = apply_filters( 'flexo_booking_stripe_session_params', $params, $booking );

		$session = $this->api( 'POST', '/checkout/sessions', $params, 'flexo-' . $booking['reference'] . '-' . md5( (string) $booking['hold_expires_at'] . $booking['access_key'] ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( empty( $session['id'] ) || empty( $session['url'] ) ) {
			return new WP_Error( 'flexo_stripe', __( 'Online payment is not available at the moment. Please try again in a few minutes.', 'flexo-booking' ) );
		}
		global $wpdb;
		$wpdb->update( Flexo_Booking_Install::table(), array( 'payment_session' => substr( $session['id'], 0, 255 ) ), array( 'id' => $booking['id'] ) );
		return array( 'redirect' => esc_url_raw( $session['url'] ) );
	}

	/**
	 * Closes the booking's payment page (hold released or a new page opened).
	 * If the guest is paying at this very moment, Stripe refuses and the
	 * payment arrives by webhook – handled as a late payment.
	 */
	public function cancel( array $booking ) {
		if ( empty( $booking['payment_session'] ) || ! $this->is_ready() ) {
			return;
		}
		$this->api( 'POST', '/checkout/sessions/' . rawurlencode( $booking['payment_session'] ) . '/expire' );
	}

	/* ---------------------------------------------------------------------
	 * Webhooks
	 * ------------------------------------------------------------------- */

	/**
	 * Checks a Stripe-Signature header ("t=…,v1=…"): HMAC-SHA256 of
	 * "{t}.{payload}" with the endpoint's signing secret, at most 5 minutes old.
	 */
	public static function verify_signature( $payload, $header, $secret, $tolerance = 300, $now = null ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}
		$now = null === $now ? time() : (int) $now;
		if ( ! $timestamp || ! $signatures || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * REST endpoint for Stripe webhooks.
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_body();
		$header  = (string) $request->get_header( 'stripe_signature' );
		$secrets = array_filter(
			array(
				'live' => Flexo_Booking_Payments::secret( 'stripe_live_webhook_secret' ),
				'test' => Flexo_Booking_Payments::secret( 'stripe_test_webhook_secret' ),
			)
		);
		$valid   = false;
		foreach ( $secrets as $secret ) {
			if ( self::verify_signature( $payload, $header, $secret ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid payload' ), 400 );
		}
		// A test payment never confirms a booking on a live site (and back).
		if ( isset( $event['livemode'] ) && (bool) $event['livemode'] !== $this->is_live() ) {
			return new WP_REST_Response( array( 'ignored' => 'mode' ), 200 );
		}
		return $this->process_event( $event );
	}

	/**
	 * Processes a verified event once. Events already processed are
	 * acknowledged without doing anything again.
	 */
	public function process_event( array $event ) {
		global $wpdb;
		$table = Flexo_Booking_Schema::table( 'webhook_events' );
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			$table,
			array(
				'gateway'    => 'stripe',
				'event_id'   => substr( (string) $event['id'], 0, 255 ),
				'event_type' => substr( (string) $event['type'], 0, 100 ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		$wpdb->suppress_errors( $suppress );
		if ( ! $inserted ) {
			return new WP_REST_Response( array( 'duplicate' => true ), 200 );
		}
		$row_id = (int) $wpdb->insert_id;

		$result = $this->dispatch( $event );
		if ( is_wp_error( $result ) ) {
			// Not processed: forget the event so Stripe's retry is handled.
			$wpdb->delete( $table, array( 'id' => $row_id ) );
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}
		if ( ! empty( $result['booking_id'] ) ) {
			$wpdb->update( $table, array( 'booking_id' => (int) $result['booking_id'] ), array( 'id' => $row_id ) );
		}
		return new WP_REST_Response( array( 'received' => true ) + $result, 200 );
	}

	/**
	 * @return array|WP_Error
	 */
	private function dispatch( array $event ) {
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();
		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$booking = $this->booking_from( $object );
				if ( ! $booking ) {
					return array( 'result' => 'unknown_booking' );
				}
				if ( 'paid' !== ( isset( $object['payment_status'] ) ? $object['payment_status'] : '' ) ) {
					return $this->mark_processing( $booking );
				}
				$currency = isset( $object['currency'] ) ? $object['currency'] : $booking['currency'];
				if ( strtolower( $currency ) !== strtolower( $booking['currency'] ) ) {
					return new WP_Error( 'flexo_stripe_currency', 'Currency does not match the booking.' );
				}
				$amount = self::from_minor( isset( $object['amount_total'] ) ? $object['amount_total'] : 0, $currency );
				$result = Flexo_Booking_Payments::complete(
					$booking['id'],
					$amount,
					'stripe',
					! empty( $object['payment_intent'] ) ? (string) $object['payment_intent'] : (string) $object['id'],
					array(
						'currency' => strtoupper( $currency ),
						'note'     => $amount + 0.005 < $booking['total'] ? __( 'Deposit', 'flexo-booking' ) : '',
						'meta'     => array(
							'session'  => (string) $object['id'],
							'livemode' => ! empty( $event['livemode'] ),
						),
					)
				);
				return is_wp_error( $result ) ? $result : array(
					'result'     => $result['result'],
					'booking_id' => $booking['id'],
				);

			case 'checkout.session.async_payment_failed':
				$booking = $this->booking_from( $object );
				if ( ! $booking ) {
					return array( 'result' => 'unknown_booking' );
				}
				Flexo_Booking_Payments::log(
					$booking['id'],
					array(
						'gateway'        => 'stripe',
						'type'           => 'attempt',
						'status'         => 'failed',
						'amount'         => self::from_minor( isset( $object['amount_total'] ) ? $object['amount_total'] : 0, $booking['currency'] ),
						'currency'       => $booking['currency'],
						'transaction_id' => ! empty( $object['payment_intent'] ) ? (string) $object['payment_intent'] : (string) $object['id'],
					)
				);
				$this->reset_processing( $booking );
				Flexo_Booking_Payments::release_hold( $booking['id'], 'failed' );
				return array(
					'result'     => 'failed',
					'booking_id' => $booking['id'],
				);

			case 'checkout.session.expired':
				$booking = $this->booking_from( $object );
				// Only the booking's current payment page; an older one was replaced by a retry.
				if ( ! $booking || $booking['payment_session'] !== ( isset( $object['id'] ) ? $object['id'] : '' ) ) {
					return array( 'result' => 'ignored' );
				}
				Flexo_Booking_Payments::release_hold( $booking['id'], Flexo_Booking_Payments::had_failed_attempt( $booking['id'] ) ? 'failed' : 'expired' );
				return array(
					'result'     => 'released',
					'booking_id' => $booking['id'],
				);

			case 'payment_intent.payment_failed':
				$booking = $this->booking_from( $object );
				if ( ! $booking ) {
					return array( 'result' => 'unknown_booking' );
				}
				Flexo_Booking_Payments::log(
					$booking['id'],
					array(
						'gateway'        => 'stripe',
						'type'           => 'attempt',
						'status'         => 'failed',
						'amount'         => self::from_minor( isset( $object['amount'] ) ? $object['amount'] : 0, $booking['currency'] ),
						'currency'       => $booking['currency'],
						'transaction_id' => (string) $object['id'],
						// Stripe's decline message, e.g. "Your card was declined." – no card details.
						'note'           => isset( $object['last_payment_error']['message'] ) ? (string) $object['last_payment_error']['message'] : '',
					)
				);
				return array(
					'result'     => 'attempt_failed',
					'booking_id' => $booking['id'],
				);

			case 'charge.refunded':
				$intent  = isset( $object['payment_intent'] ) ? (string) $object['payment_intent'] : '';
				$booking = '' !== $intent ? Flexo_Booking_Payments::booking_for_transaction( 'stripe', $intent ) : null;
				if ( ! $booking ) {
					$booking = $this->booking_from( $object );
				}
				if ( ! $booking ) {
					return array( 'result' => 'unknown_booking' );
				}
				$currency = isset( $object['currency'] ) ? $object['currency'] : $booking['currency'];
				// amount_refunded is the charge's running total: record what's new.
				$total_refunded = self::from_minor( isset( $object['amount_refunded'] ) ? $object['amount_refunded'] : 0, $currency );
				$new            = Flexo_Booking_Money::round( $total_refunded - $this->refunded_for( $booking['id'], (string) $object['id'] ) );
				if ( $new <= 0 ) {
					return array(
						'result'     => 'nothing_new',
						'booking_id' => $booking['id'],
					);
				}
				$result = Flexo_Booking_Payments::refund( $booking['id'], $new, 'stripe', $object['id'] . ':' . (int) ( isset( $object['amount_refunded'] ) ? $object['amount_refunded'] : 0 ), __( 'Refunded in Stripe', 'flexo-booking' ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( 'recorded' === $result['result'] ) {
					Flexo_Booking_Payments::notify_refund( $result['booking'], $new );
				}
				return array(
					'result'     => 'refund_' . $result['result'],
					'booking_id' => $booking['id'],
				);
		}
		return array( 'result' => 'ignored' );
	}

	/**
	 * The booking an event belongs to (from the metadata set when paying).
	 */
	private function booking_from( array $object ) {
		$id = isset( $object['metadata']['booking_id'] ) ? absint( $object['metadata']['booking_id'] ) : 0;
		if ( $id ) {
			$booking = Flexo_Booking_Bookings::get( $id );
			if ( $booking && ( empty( $object['metadata']['reference'] ) || $object['metadata']['reference'] === $booking['reference'] ) ) {
				return $booking;
			}
		}
		if ( ! empty( $object['client_reference_id'] ) ) {
			return Flexo_Booking_Bookings::get_by_reference( sanitize_text_field( $object['client_reference_id'] ) );
		}
		return null;
	}

	/**
	 * Refunds already recorded for one Stripe charge.
	 */
	private function refunded_for( $booking_id, $charge_id ) {
		$sum = 0.0;
		foreach ( Flexo_Booking_Payments::history( $booking_id ) as $row ) {
			if ( 'refund' === $row['type'] && 'stripe' === $row['gateway'] && 0 === strpos( $row['transaction_id'], $charge_id . ':' ) ) {
				$sum += $row['amount'];
			}
		}
		return $sum;
	}

	/**
	 * Paid with a method that takes days to settle: keep the room reserved
	 * until Stripe reports the result.
	 */
	private function mark_processing( array $booking ) {
		global $wpdb;
		if ( Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] ) {
			$wpdb->update(
				Flexo_Booking_Install::table(),
				array(
					'payment_status'  => 'processing',
					'hold_expires_at' => wp_date( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
				),
				array( 'id' => $booking['id'] )
			);
		}
		return array(
			'result'     => 'processing',
			'booking_id' => $booking['id'],
		);
	}

	private function reset_processing( array $booking ) {
		global $wpdb;
		if ( 'processing' === $booking['payment_status'] ) {
			$wpdb->update( Flexo_Booking_Install::table(), array( 'payment_status' => 'pending' ), array( 'id' => $booking['id'] ) );
		}
	}
}
