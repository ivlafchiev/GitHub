<?php
/**
 * Public REST API used by the booking form: /wp-json/flexo-booking/v1/...
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Rest {

	const NAMESPACE_V1 = 'flexo-booking/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_ical' ), 10, 4 );
	}

	public static function register_routes() {
		$stay_args = array(
			'check_in'  => array(
				'type'     => 'string',
				'required' => true,
			),
			'check_out' => array(
				'type'     => 'string',
				'required' => true,
			),
			'adults'    => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'children'  => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'room'      => array(
				'type'    => 'string',
				'default' => '',
			),
			// "4,11" or ages[]=4&ages[]=11 (feature "children").
			'children_ages' => array(
				'type'    => array( 'string', 'array' ),
				'default' => '',
			),
			// Language of the page the guest is on, e.g. "en_US".
			'locale'        => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/rooms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rooms' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/ical/(?P<room>\d+)(?:\.ics)?',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ical' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => $stay_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quote',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$stay_args,
					array(
						'room'       => array(
							'type'     => 'string',
							'required' => true,
						),
						'rate_plan'  => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'promo_code' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$stay_args,
					array(
						'room'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'guest_name'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'guest_email' => array(
							'type'     => 'string',
							'required' => true,
						),
						'guest_phone' => array(
							'type'    => 'string',
							'default' => '',
						),
						'notes'       => array(
							'type'    => 'string',
							'default' => '',
						),
						'terms'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'fb_website'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'privacy_consent' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'invoice'        => array(
							'type'    => array( 'object', 'null' ),
							'default' => null,
						),
						'rate_plan'      => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'promo_code'     => array(
							'type'    => 'string',
							'default' => '',
						),
						// The total the guest saw; the booking is refused if the price changed.
						'expected_total' => array(
							'type'    => array( 'number', 'null' ),
							'default' => null,
						),
						// "stripe" or "bank_transfer" when payments are on. Amounts are never read from the request.
						'payment_method' => array(
							'type'    => 'string',
							'default' => '',
						),
						// Page to come back to after paying by card (same website only).
						'return_url'     => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		$payment_args = array(
			'reference' => array(
				'type'     => 'string',
				'required' => true,
			),
			'key'       => array(
				'type'     => 'string',
				'required' => true,
			),
			'locale'    => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		// Payment state of a booking, for the page the guest returns to.
		register_rest_route(
			self::NAMESPACE_V1,
			'/payment',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'payment_status' ),
				'permission_callback' => '__return_true',
				'args'                => $payment_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/payment/retry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'payment_retry' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$payment_args,
					array(
						'return_url' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);

		// Stripe webhooks: authenticated by their signature.
		register_rest_route(
			self::NAMESPACE_V1,
			'/stripe-webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'stripe_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function stripe_webhook( WP_REST_Request $request ) {
		$gateway = Flexo_Booking_Payments::gateway( 'stripe' );
		if ( ! $gateway instanceof Flexo_Booking_Gateway_Stripe ) {
			return new WP_REST_Response( array( 'error' => 'Not available' ), 404 );
		}
		return $gateway->handle_webhook( $request );
	}

	/**
	 * The booking a guest may see: reference plus the access key they got.
	 *
	 * @return array|WP_Error
	 */
	private static function guest_booking( WP_REST_Request $request ) {
		$booking = Flexo_Booking_Bookings::get_by_reference( sanitize_text_field( $request['reference'] ) );
		if ( ! Flexo_Booking_Payments::check_key( $booking, $request['key'] ) ) {
			return new WP_Error( 'flexo_not_found', __( 'Booking not found.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	public static function payment_status( WP_REST_Request $request ) {
		self::use_locale( $request );
		$booking = self::guest_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		// A hold whose time is up is released now, not only by WP-Cron.
		if ( Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] && ! Flexo_Booking_Inventory::is_occupying( $booking ) ) {
			Flexo_Booking_Payments::expire_hold( $booking['id'] );
			$booking = Flexo_Booking_Bookings::get( $booking['id'] );
		}
		$view = Flexo_Booking_Payments::guest_view( $booking );
		if ( 'confirmed' === $view['state'] ) {
			$redirect = Flexo_Booking_I18n::page_url( Flexo_Booking_Settings::site_url_setting( 'thank_you_url' ), $booking['locale'] );
			$view['redirect'] = $redirect ? add_query_arg( 'booking', rawurlencode( $booking['reference'] ), $redirect ) : '';
			$view['quote']    = Flexo_Booking_Pricing::public_view( Flexo_Booking_Pricing::snapshot( $booking ) );
		}
		$response = rest_ensure_response( $view );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function payment_retry( WP_REST_Request $request ) {
		self::use_locale( $request );
		$booking = self::guest_booking( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$result = Flexo_Booking_Payments::retry( $booking, $request['return_url'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 'flexo_unavailable' === $result->get_error_code() ? 409 : 400 ) );
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * iCal export feed of one room: /ical/{room}.ics?token=… (Calendar sync).
	 */
	public static function ical( WP_REST_Request $request ) {
		if ( ! Flexo_Booking_ICal::enabled() ) {
			return new WP_Error( 'flexo_ical_disabled', __( 'Calendar sync is switched off on this website.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		$room_id = (int) $request['room'];
		$room    = get_post( $room_id );
		if ( ! $room || Flexo_Booking_Rooms::POST_TYPE !== $room->post_type ) {
			return new WP_Error( 'flexo_ical_room', __( 'Calendar not found.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		if ( ! Flexo_Booking_ICal::check_token( $room_id, (string) $request['token'] ) ) {
			return new WP_Error( 'flexo_ical_token', __( 'This calendar link is not valid. It may have been reset – copy the new link from Bookings → Calendar Sync.', 'flexo-booking' ), array( 'status' => 403 ) );
		}
		$response = new WP_REST_Response( Flexo_Booking_ICal::export_feed( $room_id ), 200 );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'inline; filename="' . sanitize_file_name( $room->post_name ) . '.ics"' );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Sends the iCal feed as plain text instead of JSON.
	 */
	public static function serve_ical( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof WP_REST_Request || ! preg_match( '#^/' . preg_quote( self::NAMESPACE_V1, '#' ) . '/ical/\d+#', $request->get_route() ) ) {
			return $served;
		}
		if ( $result instanceof WP_REST_Response && 200 === $result->get_status() && is_string( $result->get_data() ) ) {
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal text, built and escaped in export_feed().
			return true;
		}
		return $served;
	}

	public static function rooms() {
		$rooms = array();
		foreach ( Flexo_Booking_Rooms::all() as $post ) {
			$room = Flexo_Booking_Rooms::to_array( $post );
			if ( $room['units'] < 1 ) {
				continue;
			}
			unset( $room['min_nights_override'] );
			$room['price_formatted'] = Flexo_Booking_Money::format( $room['price'] );
			$rooms[]                 = $room;
		}
		return rest_ensure_response( $rooms );
	}

	/**
	 * Answers in the guest's language (messages, prices, dates).
	 */
	private static function use_locale( WP_REST_Request $request ) {
		$locale = Flexo_Booking_I18n::sanitize_locale( $request['locale'] );
		if ( '' !== $locale && $locale !== determine_locale() ) {
			switch_to_locale( $locale );
		}
		return $locale;
	}

	public static function availability( WP_REST_Request $request ) {
		self::use_locale( $request );
		$result = Flexo_Booking_Bookings::search(
			$request['check_in'],
			$request['check_out'],
			$request['adults'],
			$request['children'],
			$request['room'],
			$request['children_ages']
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Price of one room, rate plan and promo code (the booking summary).
	 * An invalid promo code still returns the price, with the reason.
	 */
	public static function quote( WP_REST_Request $request ) {
		self::use_locale( $request );
		$promo = Flexo_Booking_Promo_Codes::enabled() ? Flexo_Booking_Promo_Codes::normalize_code( $request['promo_code'] ) : '';
		if ( '' !== $promo && Flexo_Booking_Promo_Codes::too_many_attempts() ) {
			return new WP_Error( 'flexo_rate_limited', __( 'Too many promo code attempts. Please try again later.', 'flexo-booking' ), array( 'status' => 429 ) );
		}

		$room = Flexo_Booking_Rooms::find( $request['room'] );
		if ( ! $room ) {
			return new WP_Error( 'flexo_invalid_room', __( 'Please choose a room.', 'flexo-booking' ), array( 'status' => 400 ) );
		}
		$room = Flexo_Booking_Rooms::to_array( $room );

		$stay = Flexo_Booking_Bookings::validate_dates( $request['check_in'], $request['check_out'], true, 1 );
		$ages = array();
		if ( ! is_wp_error( $stay ) && Flexo_Booking_Children::enabled() ) {
			$ages = Flexo_Booking_Children::validate( $request['children'], $request['children_ages'] );
			$stay = is_wp_error( $ages ) ? $ages : $stay;
		}
		$quote = is_wp_error( $stay ) ? $stay : Flexo_Booking_Pricing::quote(
			array(
				'room'          => $room,
				'check_in'      => $stay['check_in'],
				'check_out'     => $stay['check_out'],
				'adults'        => $request['adults'],
				'children'      => $request['children'],
				'children_ages' => $ages,
				'rate_plan_id'  => $request['rate_plan'],
				'promo_code'    => $promo,
				'context'       => 'booking',
			)
		);
		if ( is_wp_error( $quote ) ) {
			$quote->add_data( array( 'status' => 400 ) );
			return $quote;
		}
		if ( ! empty( $quote['promo_error'] ) ) {
			Flexo_Booking_Promo_Codes::too_many_attempts( true );
		}
		return rest_ensure_response( Flexo_Booking_Pricing::public_view( $quote ) );
	}

	public static function create_booking( WP_REST_Request $request ) {
		$locale = self::use_locale( $request );
		// Honeypot: real visitors never see or fill this field.
		if ( '' !== trim( (string) $request['fb_website'] ) ) {
			return new WP_Error( 'flexo_spam', __( 'Your booking could not be submitted.', 'flexo-booking' ), array( 'status' => 400 ) );
		}

		if ( Flexo_Booking_Settings::get( 'terms_url' ) && ! $request['terms'] ) {
			return new WP_Error( 'flexo_terms', __( 'Please accept the terms and conditions.', 'flexo-booking' ), array( 'status' => 400 ) );
		}

		$limited = self::rate_limited();
		if ( $limited ) {
			return $limited;
		}

		$booking = Flexo_Booking_Bookings::create(
			array(
				'room'        => $request['room'],
				'check_in'    => $request['check_in'],
				'check_out'   => $request['check_out'],
				'adults'         => $request['adults'],
				'children'       => $request['children'],
				'children_ages'  => $request['children_ages'],
				'rate_plan'      => $request['rate_plan'],
				'promo_code'     => $request['promo_code'],
				'expected_total' => $request['expected_total'],
				'payment_method' => $request['payment_method'],
				'privacy_consent' => $request['privacy_consent'],
				'invoice'        => $request['invoice'],
				'locale'         => $locale,
				'guest_name'  => $request['guest_name'],
				'guest_email' => $request['guest_email'],
				'guest_phone' => $request['guest_phone'],
				'notes'       => $request['notes'],
				'source'      => 'website',
			)
		);

		if ( is_wp_error( $booking ) ) {
			if ( in_array( $booking->get_error_code(), array( 'flexo_consent', 'flexo_invoice' ), true ) ) {
				$booking->add_data( array( 'status' => 400 ) );
				return $booking;
			}
			$booking->add_data( array_merge( (array) $booking->get_error_data(), array( 'status' => in_array( $booking->get_error_code(), array( 'flexo_unavailable', 'flexo_closed', 'flexo_busy', 'flexo_price_changed' ), true ) ? 409 : 400 ) ) );
			return $booking;
		}

		// Card: open Stripe's payment page. Bank transfer: the payment details.
		$payment = Flexo_Booking_Payments::start( $booking, $booking['access_token'], $request['return_url'] );
		if ( is_wp_error( $payment ) ) {
			$payment->add_data( array( 'status' => 502 ) );
			return $payment;
		}

		$confirmed = 'confirmed' === $booking['status'];
		$redirect  = Flexo_Booking_I18n::page_url( Flexo_Booking_Settings::site_url_setting( 'thank_you_url' ), $booking['locale'] );
		// Bank details are shown in the form, so the guest stays on the page.
		if ( $redirect && ! $payment ) {
			$redirect = add_query_arg( 'booking', rawurlencode( $booking['reference'] ), $redirect );
		} else {
			$redirect = '';
		}

		$response = new WP_REST_Response(
			array(
				'reference'       => $booking['reference'],
				'status'          => $booking['status'],
				'room'            => $booking['room_title'],
				'check_in'        => $booking['check_in'],
				'check_out'       => $booking['check_out'],
				'nights'          => $booking['nights'],
				'total_formatted' => Flexo_Booking_Money::format( $booking['total'], $booking['currency'] ),
				'breakdown'       => Flexo_Booking_Pricing::format_lines( Flexo_Booking_Pricing::snapshot( $booking ) ),
				'quote'           => Flexo_Booking_Pricing::public_view( Flexo_Booking_Pricing::snapshot( $booking ) ),
				'message'         => $payment ? $payment['message'] : ( $confirmed
					? __( 'Your booking is confirmed! A confirmation has been sent to your email.', 'flexo-booking' )
					: __( 'Thank you! We received your booking request and will confirm it shortly by email.', 'flexo-booking' ) ),
				'redirect'        => $redirect,
				// Payment details (bank transfer) or the payment page to go to (card).
				'payment'         => $payment ? array_merge( $payment, array( 'key' => $booking['access_token'] ) ) : null,
			),
			201
		);

		return $response;
	}

	/**
	 * Allows 10 booking attempts per visitor per hour to stop automated abuse.
	 *
	 * @return WP_Error|null
	 */
	private static function rate_limited() {
		$limit = (int) apply_filters( 'flexo_booking_rate_limit', 10 );
		if ( $limit < 1 ) {
			return null;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'flexo_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'flexo_rate_limited', __( 'Too many booking attempts. Please try again later or contact us directly.', 'flexo-booking' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return null;
	}
}
