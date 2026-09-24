<?php
/**
 * Payments (features "online_payment", "deposit", "bank_transfer").
 *
 * What the guest pays when booking is decided by the hotel's payment mode:
 *
 *   property  – nothing online; everything is paid at the property
 *   deposit   – a deposit now (fixed amount or % of the total), the rest at the property
 *   full      – the full amount now
 *
 * combined with the booking mode (requests / instant booking). Card payments
 * (Stripe Checkout) only work with instant booking: a guest is never charged
 * for a booking the hotel hasn't accepted. Bank transfers work with both;
 * with requests the payment details are sent once the hotel accepts.
 *
 * Amounts always come from the pricing service (step 95, the payment
 * schedule) and are stored on the booking; nothing the browser sends is
 * used as an amount. Card payments are confirmed only by the gateway's
 * signed webhook, never by the guest returning to the website.
 *
 * Booking statuses used here:
 *   pending_payment  – card payment in progress; the room is held until hold_expires_at
 *   awaiting_payment – waiting for a bank transfer until payment_due_at
 *   expired          – the guest didn't pay in time; the room was released
 *
 * Payment statuses (bookings.payment_status): pending, processing, unpaid,
 * deposit_paid, paid, failed, expired, partially_refunded, refunded.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Payments {

	const SECRETS_OPTION = 'flexo_booking_payment_secrets';
	const EXPIRE_HOOK    = 'flexo_booking_expire_hold';

	/**
	 * @var Flexo_Booking_Payment_Gateway[]|null
	 */
	private static $gateways = null;

	public static function init() {
		add_filter( 'flexo_booking_pricing_steps', array( __CLASS__, 'pricing_steps' ) );
		add_filter( 'flexo_booking_update_status_target', array( __CLASS__, 'status_target' ), 10, 3 );
		add_action( 'flexo_booking_status_changed', array( __CLASS__, 'on_status_changed' ), 5, 4 );
		add_action( 'flexo_booking_payment_recorded', array( __CLASS__, 'notify_payment' ), 10, 3 );
		add_action( Flexo_Booking_Emails::HOURLY, array( __CLASS__, 'run_scheduled' ) );
		add_action( self::EXPIRE_HOOK, array( __CLASS__, 'expire_hold' ) );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------- */

	/**
	 * Any way of paying is switched on.
	 */
	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'online_payment' ) || Flexo_Booking_Features::is_enabled( 'bank_transfer' );
	}

	/**
	 * @return Flexo_Booking_Payment_Gateway[] id => gateway.
	 */
	public static function gateways() {
		if ( null === self::$gateways ) {
			$list           = apply_filters(
				'flexo_booking_payment_gateways',
				array(
					new Flexo_Booking_Gateway_Stripe(),
					new Flexo_Booking_Gateway_Bank_Transfer(),
				)
			);
			self::$gateways = array();
			foreach ( $list as $gateway ) {
				if ( $gateway instanceof Flexo_Booking_Payment_Gateway ) {
					self::$gateways[ $gateway->id() ] = $gateway;
				}
			}
		}
		return self::$gateways;
	}

	/**
	 * @return Flexo_Booking_Payment_Gateway|null
	 */
	public static function gateway( $id ) {
		$gateways = self::gateways();
		return isset( $gateways[ $id ] ) ? $gateways[ $id ] : null;
	}

	/**
	 * Ways guests can pay right now, in display order.
	 *
	 * @return string[] Gateway IDs.
	 */
	public static function methods() {
		$ids = array();
		foreach ( self::gateways() as $id => $gateway ) {
			if ( $gateway->is_available() ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * The hotel's payment mode: property, deposit or full ('' when payments are off).
	 */
	public static function mode() {
		if ( ! self::enabled() ) {
			return '';
		}
		$mode = Flexo_Booking_Settings::get( 'payment_mode' );
		if ( 'deposit' === $mode && ! Flexo_Booking_Features::is_enabled( 'deposit' ) ) {
			$mode = 'full';
		}
		return in_array( $mode, array( 'property', 'deposit', 'full' ), true ) ? $mode : 'property';
	}

	/**
	 * Whether guests pay something when booking (a mode that takes money
	 * and at least one way to pay).
	 */
	public static function collects_now() {
		return in_array( self::mode(), array( 'deposit', 'full' ), true ) && self::methods();
	}

	/**
	 * Minutes a room is held while the guest pays online (20–30).
	 */
	public static function hold_minutes() {
		return min( 30, max( 20, (int) Flexo_Booking_Settings::get( 'hold_minutes' ) ) );
	}

	/**
	 * API keys and webhook secrets. Stored apart from the other settings so
	 * they are never exported: they must be entered on each website.
	 */
	public static function secrets() {
		$saved = get_option( self::SECRETS_OPTION, array() );
		return array_merge(
			array(
				'stripe_test_secret_key'     => '',
				'stripe_test_webhook_secret' => '',
				'stripe_live_secret_key'     => '',
				'stripe_live_webhook_secret' => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	public static function secret( $key ) {
		$secrets = self::secrets();
		return isset( $secrets[ $key ] ) ? (string) $secrets[ $key ] : '';
	}

	/**
	 * Sanitises the secrets form. An empty field keeps the saved value
	 * (saved keys are never printed back into the page).
	 */
	public static function sanitize_secrets( $input ) {
		$current = self::secrets();
		if ( ! is_array( $input ) ) {
			return $current; // Another settings tab was saved.
		}
		foreach ( array_keys( $current ) as $key ) {
			if ( ! empty( $input[ $key . '_clear' ] ) ) {
				$current[ $key ] = '';
				continue;
			}
			$value = isset( $input[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $input[ $key ] ) ) ) : '';
			if ( '' !== $value ) {
				$current[ $key ] = preg_replace( '/[^A-Za-z0-9_]/', '', $value );
			}
		}
		return $current;
	}

	/* ---------------------------------------------------------------------
	 * Amounts (pricing step 95)
	 * ------------------------------------------------------------------- */

	public static function pricing_steps( $steps ) {
		if ( self::enabled() ) {
			$steps[95] = array( __CLASS__, 'step_payment' );
		}
		return $steps;
	}

	/**
	 * Step 95: what the guest pays when booking and at the property.
	 * Lines collected at the property (e.g. tourist tax) are always paid there.
	 */
	public static function step_payment( array $quote ) {
		$total    = (float) $quote['total'];
		$property = isset( $quote['due_at_property'] ) ? (float) $quote['due_at_property'] : 0.0;
		$mode     = self::collects_now() ? self::mode() : 'property';
		$settings = Flexo_Booking_Settings::all();
		$now      = 0.0;
		$label    = '';

		if ( 'full' === $mode ) {
			$now = $total;
		} elseif ( 'deposit' === $mode ) {
			if ( 'fixed' === $settings['deposit_type'] ) {
				$now   = min( $total, (float) $settings['deposit_value'] );
				$label = __( 'Deposit', 'flexo-booking' );
			} else {
				$percent = min( 100, max( 0, (float) $settings['deposit_value'] ) );
				$now     = $total * $percent / 100;
				/* translators: %s: percentage */
				$label = sprintf( __( 'Deposit (%s%%)', 'flexo-booking' ), Flexo_Booking_Children::percent_text( $percent ) );
			}
		}
		$now = max( 0.0, Flexo_Booking_Money::round( $now ) );

		$quote['payable'] = array(
			'mode'        => $mode,
			'now'         => $now,
			'deposit'     => 'deposit' === $mode ? $now : 0.0,
			'at_property' => Flexo_Booking_Money::round( $total - $now + $property ),
			'label'       => $label,
		);
		return $quote;
	}

	/**
	 * What the booking form shows about paying, or null when payments are off.
	 */
	public static function public_view( array $quote ) {
		if ( empty( $quote['payable']['mode'] ) ) {
			return null;
		}
		$currency = isset( $quote['currency'] ) ? $quote['currency'] : null;
		$payable  = $quote['payable'];
		return array(
			'mode'                  => $payable['mode'],
			'now'                   => (float) $payable['now'],
			'now_formatted'         => Flexo_Booking_Money::format( $payable['now'], $currency ),
			'now_label'             => '' !== $payable['label'] ? $payable['label'] : __( 'Pay now', 'flexo-booking' ),
			'at_property'           => (float) $payable['at_property'],
			'at_property_formatted' => Flexo_Booking_Money::format( $payable['at_property'], $currency ),
		);
	}

	/* ---------------------------------------------------------------------
	 * New bookings
	 * ------------------------------------------------------------------- */

	/**
	 * How a new website booking will be paid, before it is stored.
	 *
	 * @return array|WP_Error { @type string $method Gateway ID, "property" or ''. @type string $status Status when something is due now. }
	 */
	public static function prepare( array $data, $is_admin ) {
		if ( $is_admin || ! self::enabled() ) {
			return array(
				'method' => '',
				'status' => '',
			);
		}
		if ( ! self::collects_now() ) {
			return array(
				'method' => 'property',
				'status' => '',
			);
		}
		$methods   = self::methods();
		$requested = isset( $data['payment_method'] ) ? sanitize_key( $data['payment_method'] ) : '';
		if ( '' === $requested ) {
			$requested = $methods[0];
		}
		if ( ! in_array( $requested, $methods, true ) ) {
			return new WP_Error( 'flexo_payment_method', __( 'This payment method is not available. Please choose another one.', 'flexo-booking' ) );
		}
		if ( self::gateway( $requested )->is_hosted() ) {
			$status = Flexo_Booking_Bookings::HOLD_STATUS;
		} else {
			$status = 'instant' === Flexo_Booking_Features::booking_mode() ? 'awaiting_payment' : 'pending';
		}
		return array(
			'method' => $requested,
			'status' => $status,
		);
	}

	/**
	 * Adds the payment columns to a booking about to be stored (inside the
	 * room lock, with the quote calculated there).
	 *
	 * @param string $token Set to the guest's access token (only when a payment follows).
	 */
	public static function apply( array $row, $quote, array $plan, &$token ) {
		$token = '';
		if ( '' === $plan['method'] || ! $quote || 'blocked' === $row['status'] ) {
			return $row;
		}
		$due = isset( $quote['payable']['now'] ) ? (float) $quote['payable']['now'] : 0.0;
		if ( 'property' === $plan['method'] || $due <= 0 ) {
			// Nothing to pay now (pay at the property, or e.g. a 100% promo code).
			$row['payment_method'] = 'property';
			return $row;
		}

		$token                 = wp_generate_password( 32, false, false );
		$row['status']         = $plan['status'];
		$row['payment_method'] = $plan['method'];
		$row['amount_due']     = $due;
		$row['access_key']     = hash( 'sha256', $token );
		if ( Flexo_Booking_Bookings::HOLD_STATUS === $plan['status'] ) {
			$row['payment_status']  = 'pending';
			$row['hold_expires_at'] = wp_date( 'Y-m-d H:i:s', time() + self::hold_minutes() * MINUTE_IN_SECONDS );
		} else {
			$row['payment_status'] = 'unpaid';
			if ( 'awaiting_payment' === $plan['status'] ) {
				$row['payment_due_at'] = self::deadline();
			}
		}
		return $row;
	}

	/**
	 * Starts the payment of a new booking: the Stripe payment page, or the
	 * bank details. Called right after the booking is stored.
	 *
	 * @return array|null|WP_Error What the booking form needs (see guest_view()), or null when nothing is paid now.
	 */
	public static function start( array $booking, $token, $return_url ) {
		$gateway = self::gateway( $booking['payment_method'] );
		if ( ! $gateway || '' === $token ) {
			return null;
		}
		if ( ! $gateway->is_hosted() ) {
			return self::guest_view( $booking );
		}
		$result = $gateway->start(
			$booking,
			array(
				'token'      => $token,
				'return_url' => $return_url,
			)
		);
		if ( is_wp_error( $result ) ) {
			// The payment page could not be opened: don't keep the room held.
			self::release_hold( $booking['id'], 'expired' );
			return $result;
		}
		if ( ! empty( $booking['hold_expires_at'] ) ) {
			$ends = strtotime( get_gmt_from_date( $booking['hold_expires_at'] ) . ' UTC' );
			wp_schedule_single_event( $ends + 60, self::EXPIRE_HOOK, array( (int) $booking['id'] ) );
		}
		return array_merge( self::guest_view( self::fresh( $booking['id'] ) ), $result );
	}

	/**
	 * Page to come back to after paying: the booking page the guest used
	 * (same website only), else the home page.
	 */
	public static function return_url( $url ) {
		$url = wp_validate_redirect( esc_url_raw( (string) $url ), '' );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		return remove_query_arg( array( 'fb_payment', 'fb_ref', 'fb_key' ), $url );
	}

	/**
	 * Bank transfer deadline: the end of the day, N days from now.
	 */
	public static function deadline( $from = null ) {
		$days = max( 1, (int) Flexo_Booking_Settings::get( 'bank_transfer_days' ) );
		$from = $from ? $from : current_time( 'mysql' );
		return Flexo_Booking_Dates::add_days( substr( $from, 0, 10 ), $days ) . ' 23:59:59';
	}

	/* ---------------------------------------------------------------------
	 * Payment history
	 * ------------------------------------------------------------------- */

	public static function table() {
		return Flexo_Booking_Schema::table( 'payments' );
	}

	/**
	 * @return array[] Oldest first.
	 */
	public static function history( $booking_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC", $booking_id ), ARRAY_A );
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['amount'] = (float) $row['amount'];
		}
		return $rows;
	}

	public static function has_transaction( $gateway, $transaction_id, $type ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND transaction_id = %s AND type = %s", $gateway, $transaction_id, $type ) );
	}

	/**
	 * Booking that a gateway transaction (e.g. a Stripe PaymentIntent) paid.
	 */
	public static function booking_for_transaction( $gateway, $transaction_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM {$table} WHERE gateway = %s AND transaction_id = %s ORDER BY id ASC LIMIT 1", $gateway, $transaction_id ) );
		return $id ? Flexo_Booking_Bookings::get( $id ) : null;
	}

	/**
	 * Adds a row to the payment history.
	 *
	 * @param array $entry gateway, type (payment|refund|attempt), status, amount, currency, transaction_id, note, meta.
	 */
	public static function log( $booking_id, array $entry ) {
		global $wpdb;
		$entry = wp_parse_args(
			$entry,
			array(
				'gateway'        => '',
				'type'           => 'payment',
				'status'         => 'succeeded',
				'amount'         => 0,
				'currency'       => Flexo_Booking_Money::currency(),
				'transaction_id' => '',
				'note'           => '',
				'meta'           => array(),
			)
		);
		$wpdb->insert(
			self::table(),
			array(
				'booking_id'     => (int) $booking_id,
				'gateway'        => substr( sanitize_key( $entry['gateway'] ), 0, 20 ),
				'type'           => in_array( $entry['type'], array( 'payment', 'refund', 'attempt' ), true ) ? $entry['type'] : 'payment',
				'status'         => substr( sanitize_key( $entry['status'] ), 0, 20 ),
				'amount'         => Flexo_Booking_Money::round( (float) $entry['amount'] ),
				'currency'       => substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $entry['currency'] ) ), 0, 10 ),
				'transaction_id' => substr( sanitize_text_field( (string) $entry['transaction_id'] ), 0, 255 ),
				'note'           => substr( sanitize_text_field( (string) $entry['note'] ), 0, 255 ),
				'meta'           => $entry['meta'] ? wp_json_encode( $entry['meta'] ) : null,
				'created_by'     => get_current_user_id(),
				'created_at'     => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Totals of a booking: price, what was paid and refunded, and what is
	 * still to pay (now, and in total at the property).
	 */
	public static function balance( array $booking ) {
		$snapshot = Flexo_Booking_Pricing::snapshot( $booking );
		$total    = (float) $booking['total'];
		$property = isset( $snapshot['due_at_property'] ) ? (float) $snapshot['due_at_property'] : 0.0;
		$paid     = isset( $booking['amount_paid'] ) ? (float) $booking['amount_paid'] : 0.0;
		$refunded = isset( $booking['amount_refunded'] ) ? (float) $booking['amount_refunded'] : 0.0;
		$net      = Flexo_Booking_Money::round( $paid - $refunded );
		$due      = isset( $booking['amount_due'] ) ? (float) $booking['amount_due'] : 0.0;
		return array(
			'total'       => $total,
			'overall'     => Flexo_Booking_Money::round( $total + $property ),
			'paid'        => $paid,
			'refunded'    => $refunded,
			'net'         => $net,
			'due_now'     => max( 0.0, Flexo_Booking_Money::round( $due - $paid ) ),
			'outstanding' => max( 0.0, Flexo_Booking_Money::round( $total + $property - $net ) ),
		);
	}

	/**
	 * Payment status from the amounts paid and refunded.
	 */
	private static function status_from_amounts( array $booking, $paid, $refunded ) {
		$net = $paid - $refunded;
		if ( $refunded > 0.004 ) {
			return $net <= 0.004 ? 'refunded' : 'partially_refunded';
		}
		if ( $paid > 0.004 ) {
			return $paid + 0.005 >= (float) $booking['total'] ? 'paid' : 'deposit_paid';
		}
		return $booking['payment_status'];
	}

	/**
	 * Records a payment and confirms the booking when it covers the amount
	 * due. Safe to call twice for the same transaction (webhook retries).
	 *
	 * A payment that arrives after the hold expired confirms the booking only
	 * if the room is still free; otherwise the booking is marked as a payment
	 * conflict and the hotel is alerted – never overbooked.
	 *
	 * @param array $args { @type string $note, @type array $meta, @type string $currency }
	 * @return array|WP_Error { @type string $result confirmed|recorded|duplicate|conflict, @type array $booking }
	 */
	public static function complete( $booking_id, $amount, $gateway, $transaction_id, array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'note'     => '',
				'meta'     => array(),
				'currency' => '',
			)
		);
		$lock = 'payment_' . (int) $booking_id;
		if ( ! Flexo_Booking_Lock::acquire( $lock, 10 ) ) {
			return new WP_Error( 'flexo_busy', 'Payment is being processed.' );
		}
		try {
			$booking = Flexo_Booking_Bookings::get( $booking_id );
			if ( ! $booking ) {
				return new WP_Error( 'flexo_not_found', __( 'Booking not found.', 'flexo-booking' ) );
			}
			if ( '' !== $transaction_id && self::has_transaction( $gateway, $transaction_id, 'payment' ) ) {
				return array(
					'result'  => 'duplicate',
					'booking' => $booking,
				);
			}
			$previous = $booking['status'];
			$amount   = Flexo_Booking_Money::round( (float) $amount );
			$log_id   = self::log(
				$booking['id'],
				array(
					'gateway'        => $gateway,
					'type'           => 'payment',
					'amount'         => $amount,
					'currency'       => '' !== $args['currency'] ? $args['currency'] : $booking['currency'],
					'transaction_id' => $transaction_id,
					'note'           => $args['note'],
					'meta'           => $args['meta'],
				)
			);
			$paid   = Flexo_Booking_Money::round( $booking['amount_paid'] + $amount );
			$update = array(
				'amount_paid'    => $paid,
				'payment_status' => self::status_from_amounts( $booking, $paid, $booking['amount_refunded'] ),
				'updated_at'     => current_time( 'mysql' ),
			);
			if ( '' === $booking['payment_method'] || 'property' === $booking['payment_method'] ) {
				$update['payment_method'] = $gateway;
			}
			$wpdb->update( Flexo_Booking_Install::table(), $update, array( 'id' => $booking['id'] ) );

			$result = 'recorded';
			$needed = $booking['amount_due'] > 0 ? min( $booking['amount_due'], $booking['total'] ) : $booking['total'];
			$waits  = in_array( $booking['status'], array( Flexo_Booking_Bookings::HOLD_STATUS, 'expired', 'awaiting_payment' ), true );
			if ( $waits && $paid + 0.005 >= $needed ) {
				$changed = Flexo_Booking_Bookings::update_status(
					$booking['id'],
					'confirmed',
					array(
						'notify' => false,
						'reason' => 'payment',
					)
				);
				if ( is_wp_error( $changed ) && 'flexo_busy' === $changed->get_error_code() ) {
					// The room is being booked right now: undo, so the gateway's retry is processed.
					$wpdb->delete( self::table(), array( 'id' => $log_id ) );
					$wpdb->update(
						Flexo_Booking_Install::table(),
						array(
							'amount_paid'    => $booking['amount_paid'],
							'payment_status' => $booking['payment_status'],
						),
						array( 'id' => $booking['id'] )
					);
					return $changed;
				}
				if ( is_wp_error( $changed ) ) {
					// Paid after the hold ended and someone else took the room.
					$wpdb->update(
						Flexo_Booking_Install::table(),
						array(
							'payment_conflict' => 1,
							'status'           => Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] ? 'expired' : $booking['status'],
						),
						array( 'id' => $booking['id'] )
					);
					$result = 'conflict';
				} else {
					$wpdb->update( Flexo_Booking_Install::table(), array( 'hold_expires_at' => null ), array( 'id' => $booking['id'] ) );
					$result = 'confirmed';
				}
			} elseif ( 'cancelled' === $booking['status'] ) {
				// Paid for a booking that was cancelled meanwhile.
				$wpdb->update( Flexo_Booking_Install::table(), array( 'payment_conflict' => 1 ), array( 'id' => $booking['id'] ) );
				$result = 'conflict';
			}
		} finally {
			Flexo_Booking_Lock::release( $lock );
		}

		$gateway_obj = self::gateway( $gateway );
		$booking     = Flexo_Booking_Bookings::get( $booking_id );
		/**
		 * Fires after a payment was recorded (not for duplicates).
		 *
		 * @param array  $booking
		 * @param string $result  confirmed | recorded | conflict
		 * @param array  $payment { amount, gateway, transaction_id, previous_status, hosted }
		 */
		do_action(
			'flexo_booking_payment_recorded',
			$booking,
			$result,
			array(
				'amount'          => $amount,
				'gateway'         => $gateway,
				'transaction_id'  => $transaction_id,
				'previous_status' => $previous,
				'hosted'          => $gateway_obj && $gateway_obj->is_hosted(),
			)
		);
		return array(
			'result'  => $result,
			'booking' => $booking,
		);
	}

	/**
	 * Records a refund (made in Stripe or by the hotel). The booking status
	 * doesn't change: cancel it separately if the stay is cancelled.
	 *
	 * @return array|WP_Error { @type string $result recorded|duplicate, @type array $booking }
	 */
	public static function refund( $booking_id, $amount, $gateway, $transaction_id, $note = '' ) {
		global $wpdb;
		$lock = 'payment_' . (int) $booking_id;
		if ( ! Flexo_Booking_Lock::acquire( $lock, 10 ) ) {
			return new WP_Error( 'flexo_busy', 'Payment is being processed.' );
		}
		try {
			$booking = Flexo_Booking_Bookings::get( $booking_id );
			if ( ! $booking ) {
				return new WP_Error( 'flexo_not_found', __( 'Booking not found.', 'flexo-booking' ) );
			}
			if ( '' !== $transaction_id && self::has_transaction( $gateway, $transaction_id, 'refund' ) ) {
				return array(
					'result'  => 'duplicate',
					'booking' => $booking,
				);
			}
			$amount = Flexo_Booking_Money::round( (float) $amount );
			if ( $amount <= 0 ) {
				return new WP_Error( 'flexo_refund_amount', __( 'Please enter the amount refunded.', 'flexo-booking' ) );
			}
			if ( $amount > $booking['amount_paid'] - $booking['amount_refunded'] + 0.005 ) {
				return new WP_Error( 'flexo_refund_amount', __( 'The refund is larger than the amount paid.', 'flexo-booking' ) );
			}
			self::log(
				$booking['id'],
				array(
					'gateway'        => $gateway,
					'type'           => 'refund',
					'amount'         => $amount,
					'currency'       => $booking['currency'],
					'transaction_id' => $transaction_id,
					'note'           => $note,
				)
			);
			$refunded = Flexo_Booking_Money::round( $booking['amount_refunded'] + $amount );
			$wpdb->update(
				Flexo_Booking_Install::table(),
				array(
					'amount_refunded' => $refunded,
					'payment_status'  => self::status_from_amounts( $booking, $booking['amount_paid'], $refunded ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( 'id' => $booking['id'] )
			);
		} finally {
			Flexo_Booking_Lock::release( $lock );
		}
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		do_action( 'flexo_booking_refund_recorded', $booking, $amount, $gateway );
		return array(
			'result'  => 'recorded',
			'booking' => $booking,
		);
	}

	/**
	 * Ends a payment hold: the room is released for other guests.
	 *
	 * @param string $reason expired (not paid in time) | failed (payment declined).
	 * @return bool Whether the hold was released now.
	 */
	public static function release_hold( $booking_id, $reason = 'expired' ) {
		global $wpdb;
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		if ( ! $booking || Flexo_Booking_Bookings::HOLD_STATUS !== $booking['status'] ) {
			return false;
		}
		$changed = Flexo_Booking_Bookings::update_status(
			$booking['id'],
			'expired',
			array(
				'notify' => false,
				'reason' => $reason,
			)
		);
		if ( is_wp_error( $changed ) ) {
			return false;
		}
		// A payment may have been recorded meanwhile (the webhook won the race).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Flexo_Booking_Install::table() . " SET payment_status = %s WHERE id = %d AND payment_status IN ('pending','processing')", 'failed' === $reason ? 'failed' : 'expired', $booking['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$gateway = self::gateway( $booking['payment_method'] );
		if ( $gateway && method_exists( $gateway, 'cancel' ) ) {
			$gateway->cancel( $booking );
		}
		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( (int) $booking['id'] ) );

		$booking = Flexo_Booking_Bookings::get( $booking['id'] );
		if ( 'failed' === $reason && Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			Flexo_Booking_Emails::send_guest( $booking, 'payment_failed' );
		}
		do_action( 'flexo_booking_hold_released', $booking, $reason );
		return true;
	}

	/**
	 * WP-Cron: a hold's time is up (scheduled when the hold starts).
	 */
	public static function expire_hold( $booking_id ) {
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		if ( $booking && Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] && ! Flexo_Booking_Inventory::is_occupying( $booking ) ) {
			self::release_hold( $booking_id, self::had_failed_attempt( $booking_id ) ? 'failed' : 'expired' );
		}
	}

	public static function had_failed_attempt( $booking_id ) {
		foreach ( self::history( $booking_id ) as $row ) {
			if ( 'attempt' === $row['type'] && 'failed' === $row['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The guest tries to pay again (after cancelling on the payment page, or
	 * after the hold expired while the room is still free).
	 *
	 * @return array|WP_Error Guest view with a new payment page URL.
	 */
	public static function retry( array $booking, $return_url ) {
		global $wpdb;
		$gateway = self::gateway( $booking['payment_method'] );
		if ( ! $gateway || ! $gateway->is_hosted() || ! $gateway->is_available() || ! in_array( $booking['status'], array( Flexo_Booking_Bookings::HOLD_STATUS, 'expired' ), true ) || $booking['amount_paid'] > 0 ) {
			return new WP_Error( 'flexo_payment_retry', __( 'This booking can no longer be paid online. Please make a new booking or contact us.', 'flexo-booking' ) );
		}
		$token = wp_generate_password( 32, false, false );
		$held  = Flexo_Booking_Inventory::with_lock(
			$booking['room_id'],
			static function () use ( $wpdb, $booking, $token ) {
				$current = Flexo_Booking_Bookings::get( $booking['id'] );
				$post    = get_post( $current['room_id'] );
				if ( ! $post || ( ! Flexo_Booking_Inventory::is_occupying( $current ) && Flexo_Booking_Inventory::units_available( Flexo_Booking_Rooms::to_array( $post ), $current['check_in'], $current['check_out'], $current['id'] ) < 1 ) ) {
					return new WP_Error( 'flexo_unavailable', __( 'Sorry, this room is no longer available for the selected dates.', 'flexo-booking' ) );
				}
				$wpdb->update(
					Flexo_Booking_Install::table(),
					array(
						'status'          => Flexo_Booking_Bookings::HOLD_STATUS,
						'payment_status'  => 'pending',
						'hold_expires_at' => wp_date( 'Y-m-d H:i:s', time() + self::hold_minutes() * MINUTE_IN_SECONDS ),
						'access_key'      => hash( 'sha256', $token ),
						'updated_at'      => current_time( 'mysql' ),
					),
					array( 'id' => $current['id'] )
				);
				return true;
			}
		);
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		if ( method_exists( $gateway, 'cancel' ) ) {
			$gateway->cancel( $booking ); // The previous payment page can't be used any more.
		}
		wp_clear_scheduled_hook( self::EXPIRE_HOOK, array( (int) $booking['id'] ) );
		$result = self::start( Flexo_Booking_Bookings::get( $booking['id'] ), $token, $return_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['key'] = $token;
		return $result;
	}

	private static function fresh( $id ) {
		return Flexo_Booking_Bookings::get( $id );
	}

	/* ---------------------------------------------------------------------
	 * Status changes
	 * ------------------------------------------------------------------- */

	/**
	 * Accepting a booking request paid by bank transfer asks for the payment
	 * first: "Confirm" moves it to "Awaiting payment".
	 */
	public static function status_target( $status, $booking, $context ) {
		if ( 'confirmed' === $status && 'pending' === $booking['status'] && empty( $context['force'] ) && 'bank_transfer' === $booking['payment_method'] && $booking['amount_paid'] + 0.005 < $booking['amount_due'] ) {
			$gateway = self::gateway( 'bank_transfer' );
			if ( $gateway && $gateway->is_available() ) {
				return 'awaiting_payment';
			}
		}
		return $status;
	}

	public static function on_status_changed( $booking, $old_status, $new_status, $context = array() ) {
		global $wpdb;
		if ( 'awaiting_payment' === $new_status && 'pending' === $old_status ) {
			// Request accepted: the payment deadline starts now.
			$wpdb->update( Flexo_Booking_Install::table(), array( 'payment_due_at' => self::deadline() ), array( 'id' => $booking['id'] ) );
			$booking = Flexo_Booking_Bookings::get( $booking['id'] );
			if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
				Flexo_Booking_Emails::send_guest( $booking, 'awaiting_deposit' );
			}
		}
		if ( 'cancelled' === $new_status && Flexo_Booking_Bookings::HOLD_STATUS === $old_status ) {
			$gateway = self::gateway( $booking['payment_method'] );
			if ( $gateway && method_exists( $gateway, 'cancel' ) ) {
				$gateway->cancel( $booking );
			}
		}
	}

	/**
	 * Emails after a payment: the guest's confirmation, the hotel's
	 * notification of a new paid booking, or the conflict alert.
	 */
	public static function notify_payment( $booking, $result, $payment ) {
		if ( 'conflict' === $result ) {
			self::send_conflict_alert( $booking, $payment );
			return;
		}
		if ( 'confirmed' !== $result ) {
			return;
		}
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			Flexo_Booking_Emails::send_guest( $booking, 'payment_received' );
		}
		if ( ! empty( $payment['hosted'] ) ) {
			// The hotel hears about a card booking only once it is paid.
			Flexo_Booking_Emails::send_admin( $booking );
		}
	}

	private static function send_conflict_alert( $booking, $payment ) {
		Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking, $payment ) {
				$vars = Flexo_Booking_Emails::placeholders( $booking );
				/* translators: %s: booking reference */
				$subject = sprintf( __( 'Action needed: payment received for %s, but the room is no longer free', 'flexo-booking' ), $booking['reference'] );
				$body    = 'cancelled' === $booking['status']
					? __( 'A guest paid for a booking that had already been cancelled. The booking was NOT reinstated.', 'flexo-booking' )
					: __( 'A guest paid after their reservation had expired, and the room was booked by someone else in the meantime. The booking was NOT confirmed, so nothing is double-booked.', 'flexo-booking' );
				$body   .= "\n\n" . sprintf(
					/* translators: %s: amount */
					__( 'Amount received: %s', 'flexo-booking' ),
					Flexo_Booking_Money::format( $payment['amount'], $booking['currency'] )
				);
				$body .= "\n\n" . __( 'Please contact the guest: offer another room or dates (then confirm the booking), or refund the payment in your Stripe account.', 'flexo-booking' );
				$body .= "\n\n" . $vars['{booking_details}'] . "\n\n" . __( 'Guest', 'flexo-booking' ) . ': ' . $booking['guest_name'] . "\n" . __( 'Email', 'flexo-booking' ) . ': ' . $booking['guest_email'] . "\n" . __( 'Phone', 'flexo-booking' ) . ': ' . $booking['guest_phone'];
				$body .= "\n\n" . __( 'Open the booking:', 'flexo-booking' ) . ' ' . admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $booking['id'] );
				Flexo_Booking_Emails::send_hotel( 'payment_conflict', $subject, $body, $booking['id'] );
			}
		);
	}

	/**
	 * Tells the hotel about a refund made in Stripe.
	 */
	public static function notify_refund( $booking, $amount ) {
		Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking, $amount ) {
				$balance = self::balance( $booking );
				/* translators: 1: amount, 2: booking reference */
				$subject = sprintf( __( 'Refund of %1$s recorded for booking %2$s', 'flexo-booking' ), Flexo_Booking_Money::format( $amount, $booking['currency'] ), $booking['reference'] );
				$body    = __( 'A refund made in Stripe was recorded on this booking. The booking itself was not changed – cancel it if the stay is cancelled.', 'flexo-booking' ) . "\n\n";
				$body   .= __( 'Paid', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $balance['paid'], $booking['currency'] ) . "\n";
				$body   .= __( 'Refunded', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $balance['refunded'], $booking['currency'] ) . "\n\n";
				$body   .= __( 'Open the booking:', 'flexo-booking' ) . ' ' . admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $booking['id'] );
				Flexo_Booking_Emails::send_hotel( 'payment', $subject, $body, $booking['id'] );
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Scheduled: holds, bank transfer reminders and deadlines
	 * ------------------------------------------------------------------- */

	/**
	 * Hourly: releases expired holds (normally done when each one ends),
	 * sends payment reminders and cancels unpaid bank-transfer bookings.
	 *
	 * @param string|null $now Y-m-d H:i:s (tests), default now.
	 * @return array Counts: released, reminded, cancelled.
	 */
	public static function run_scheduled( $now = null ) {
		global $wpdb;
		$counts = array(
			'released'  => 0,
			'reminded'  => 0,
			'cancelled' => 0,
		);
		if ( ! Flexo_Booking_Schema::column_exists( 'bookings', 'hold_expires_at' ) ) {
			return $counts;
		}
		if ( ! Flexo_Booking_Lock::acquire( 'payment_jobs', 0 ) ) {
			return $counts;
		}
		try {
			$now   = $now ? $now : current_time( 'mysql' );
			$table = Flexo_Booking_Install::table();

			$counts['released'] = self::release_expired_holds( $now );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$settings = Flexo_Booking_Settings::all();
			$hour     = (int) substr( $now, 11, 2 );
			$days     = (int) $settings['bank_transfer_reminder_days'];
			if ( $days > 0 && $hour >= 8 && $hour < 21 && Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
				$remind_from = wp_date( 'Y-m-d H:i:s', strtotime( get_gmt_from_date( $now ) . ' UTC' ) + $days * DAY_IN_SECONDS );
				$ids         = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'awaiting_payment' AND payment_due_at > %s AND payment_due_at <= %s AND emails_sent NOT LIKE %s", $now, $remind_from, '%payment_reminder%' ) );
				foreach ( $ids as $id ) {
					$booking = Flexo_Booking_Bookings::get( $id );
					if ( ! $booking || 'awaiting_payment' !== $booking['status'] || Flexo_Booking_Emails::was_sent( $booking, 'payment_reminder' ) ) {
						continue;
					}
					Flexo_Booking_Emails::mark_sent( $booking, 'payment_reminder' );
					if ( Flexo_Booking_Emails::send_guest( $booking, 'payment_reminder' ) ) {
						++$counts['reminded'];
					}
				}
			}

			if ( ! empty( $settings['bank_transfer_auto_cancel'] ) ) {
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'awaiting_payment' AND payment_due_at IS NOT NULL AND payment_due_at < %s", $now ) );
				foreach ( $ids as $id ) {
					if ( self::cancel_unpaid( $id ) ) {
						++$counts['cancelled'];
					}
				}
			}
			// phpcs:enable
		} finally {
			Flexo_Booking_Lock::release( 'payment_jobs' );
		}
		return $counts;
	}

	/**
	 * Releases every hold whose time is up (also run when the bookings list opens).
	 *
	 * @return int Holds released.
	 */
	public static function release_expired_holds( $now = null ) {
		global $wpdb;
		$now   = $now ? $now : current_time( 'mysql' );
		$table = Flexo_Booking_Install::table();
		$count = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND hold_expires_at <= %s", Flexo_Booking_Bookings::HOLD_STATUS, $now ) );
		foreach ( $ids as $id ) {
			if ( self::release_hold( $id, self::had_failed_attempt( $id ) ? 'failed' : 'expired' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Cancels a bank-transfer booking whose payment didn't arrive in time,
	 * and tells the guest and the hotel.
	 */
	public static function cancel_unpaid( $booking_id ) {
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		if ( ! $booking || 'awaiting_payment' !== $booking['status'] || $booking['amount_paid'] > 0 ) {
			return false;
		}
		$changed = Flexo_Booking_Bookings::update_status(
			$booking_id,
			'cancelled',
			array(
				'notify' => false,
				'reason' => 'payment_deadline',
			)
		);
		if ( is_wp_error( $changed ) ) {
			return false;
		}
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			Flexo_Booking_Emails::send_guest( $booking, 'payment_cancelled' );
		}
		Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking ) {
				$vars = Flexo_Booking_Emails::placeholders( $booking );
				/* translators: 1: booking reference, 2: room name */
				$subject = sprintf( __( 'Booking cancelled %1$s – %2$s (payment not received)', 'flexo-booking' ), $booking['reference'], $booking['room_title'] );
				$body    = sprintf(
					/* translators: %s: date */
					__( 'The bank transfer for this booking did not arrive by %s, so the booking was cancelled automatically and the dates are available again. The guest was told by email.', 'flexo-booking' ),
					$vars['{payment_deadline}']
				) . "\n\n" . $vars['{booking_details}'] . "\n\n" . __( 'If the money arrives later, record it on the booking and reinstate it (if the room is still free).', 'flexo-booking' );
				$body   .= "\n\n" . __( 'Open the booking:', 'flexo-booking' ) . ' ' . admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $booking['id'] );
				Flexo_Booking_Emails::send_hotel( 'cancelled', $subject, $body, $booking['id'] );
			}
		);
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Labels and guest views
	 * ------------------------------------------------------------------- */

	public static function method_label( $method, $for_admin = true ) {
		if ( 'property' === $method ) {
			return __( 'At the property', 'flexo-booking' );
		}
		$staff = array(
			'manual'        => __( 'Other (recorded by staff)', 'flexo-booking' ),
			'cash'          => __( 'Cash', 'flexo-booking' ),
			'card_terminal' => __( 'Card at the property', 'flexo-booking' ),
		);
		if ( isset( $staff[ $method ] ) ) {
			return $staff[ $method ];
		}
		$gateway = self::gateway( $method );
		if ( ! $gateway ) {
			return (string) $method;
		}
		return $for_admin ? $gateway->admin_label() : $gateway->label();
	}

	/**
	 * Payment status in hotel language ("Deposit received", …).
	 */
	public static function status_label( array $booking ) {
		$status = $booking['payment_status'];
		if ( 'unpaid' === $status ) {
			return $booking['amount_due'] + 0.005 < $booking['total'] ? __( 'Awaiting deposit', 'flexo-booking' ) : __( 'Awaiting payment', 'flexo-booking' );
		}
		$labels = array(
			'pending'            => __( 'Pending payment', 'flexo-booking' ),
			'processing'         => __( 'Payment processing', 'flexo-booking' ),
			'deposit_paid'       => __( 'Deposit received', 'flexo-booking' ),
			'paid'               => __( 'Payment received', 'flexo-booking' ),
			'failed'             => __( 'Payment failed', 'flexo-booking' ),
			'expired'            => __( 'Not paid in time', 'flexo-booking' ),
			'partially_refunded' => __( 'Partially refunded', 'flexo-booking' ),
			'refunded'           => __( 'Refunded', 'flexo-booking' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}

	/**
	 * Checks the guest's access token for a booking (return page, retry).
	 */
	public static function check_key( $booking, $key ) {
		return $booking && '' !== (string) $booking['access_key'] && '' !== (string) $key && hash_equals( $booking['access_key'], hash( 'sha256', (string) $key ) );
	}

	/**
	 * What the guest sees about a booking's payment (after booking, and on
	 * the page they return to from the payment page).
	 */
	public static function guest_view( array $booking ) {
		$balance  = self::balance( $booking );
		$currency = $booking['currency'];
		$state    = 'confirmed';
		$message  = '';
		$gateway  = self::gateway( $booking['payment_method'] );

		if ( $booking['payment_conflict'] ) {
			$state   = 'conflict';
			$message = __( 'We received your payment, but your reservation had expired and the room was booked by someone else in the meantime. Your booking is not confirmed. The hotel has been notified and will contact you shortly about another room or a refund.', 'flexo-booking' );
		} elseif ( Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] ) {
			if ( 'processing' === $booking['payment_status'] ) {
				$state   = 'processing';
				$message = __( 'Your payment is being processed. You will receive an email as soon as it is confirmed.', 'flexo-booking' );
			} else {
				$state   = Flexo_Booking_Inventory::is_occupying( $booking ) ? 'held' : 'expired';
				$message = 'held' === $state
					? sprintf(
						/* translators: %s: time */
						__( 'Your payment has not been completed. The room is reserved for you until %s.', 'flexo-booking' ),
						mysql2date( get_option( 'time_format' ), $booking['hold_expires_at'] )
					)
					: __( 'The time to pay has run out and the room is no longer reserved for you.', 'flexo-booking' );
			}
		} elseif ( 'expired' === $booking['status'] ) {
			$state   = 'failed' === $booking['payment_status'] ? 'failed' : 'expired';
			$message = 'failed' === $state
				? __( 'Your payment did not go through, so the room is no longer reserved for you.', 'flexo-booking' )
				: __( 'The time to pay has run out and the room is no longer reserved for you.', 'flexo-booking' );
		} elseif ( 'awaiting_payment' === $booking['status'] ) {
			$state   = 'awaiting_transfer';
			$message = sprintf(
				/* translators: 1: amount, 2: date */
				__( 'Your booking is reserved. To confirm it, please pay %1$s by bank transfer by %2$s. The payment details are below and in your email.', 'flexo-booking' ),
				Flexo_Booking_Money::format( $balance['due_now'], $currency ),
				Flexo_Booking_I18n::format_date( substr( (string) $booking['payment_due_at'], 0, 10 ) )
			);
		} elseif ( 'pending' === $booking['status'] ) {
			$state   = 'request';
			$message = 'bank_transfer' === $booking['payment_method']
				? sprintf(
					/* translators: %s: amount */
					__( 'Thank you! We received your booking request. Once we confirm it, we will email you the bank details to pay %s.', 'flexo-booking' ),
					Flexo_Booking_Money::format( $booking['amount_due'], $currency )
				)
				: __( 'Thank you! We received your booking request and will confirm it shortly by email.', 'flexo-booking' );
		} elseif ( 'cancelled' === $booking['status'] ) {
			$state   = 'cancelled';
			$message = __( 'This booking has been cancelled.', 'flexo-booking' );
		} else {
			$message = $balance['net'] > 0
				? __( 'Thank you, we received your payment. Your booking is confirmed and a confirmation has been sent to your email.', 'flexo-booking' )
				: __( 'Your booking is confirmed! A confirmation has been sent to your email.', 'flexo-booking' );
		}

		$view = array(
			'reference'             => $booking['reference'],
			'status'                => $booking['status'],
			'state'                 => $state,
			'message'               => $message,
			'method'                => $booking['payment_method'],
			'method_label'          => self::method_label( $booking['payment_method'], false ),
			'room'                  => $booking['room_title'],
			'check_in'              => $booking['check_in'],
			'check_out'             => $booking['check_out'],
			'total'                 => (float) $booking['total'],
			'total_formatted'       => Flexo_Booking_Money::format( $booking['total'], $currency ),
			'due_now'               => $balance['due_now'],
			'due_now_formatted'     => Flexo_Booking_Money::format( $balance['due_now'], $currency ),
			'paid'                  => $balance['net'],
			'paid_formatted'        => $balance['net'] > 0 ? Flexo_Booking_Money::format( $balance['net'], $currency ) : '',
			'at_property'           => $balance['outstanding'],
			'at_property_formatted' => $balance['outstanding'] > 0 ? Flexo_Booking_Money::format( $balance['outstanding'], $currency ) : '',
			'can_retry'             => in_array( $state, array( 'held', 'expired', 'failed' ), true ) && $gateway && $gateway->is_hosted() && $gateway->is_available() && $booking['amount_paid'] <= 0,
			'instructions'          => null,
		);
		if ( 'bank_transfer' === $booking['payment_method'] && 'awaiting_transfer' === $state ) {
			$view['instructions'] = self::gateway( 'bank_transfer' )->instructions( $booking );
		}
		return apply_filters( 'flexo_booking_payment_guest_view', $view, $booking );
	}

	/**
	 * Bookings paid while their room was no longer free (to resolve).
	 *
	 * @return array[]
	 */
	public static function open_conflicts() {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::column_exists( 'bookings', 'payment_conflict' ) ) {
			return array();
		}
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE payment_conflict = 1 ORDER BY id DESC" );
		return array_filter( array_map( array( 'Flexo_Booking_Bookings', 'get' ), $ids ) );
	}

	public static function resolve_conflict( $booking_id ) {
		global $wpdb;
		return (bool) $wpdb->update( Flexo_Booking_Install::table(), array( 'payment_conflict' => 0 ), array( 'id' => (int) $booking_id ) );
	}
}
