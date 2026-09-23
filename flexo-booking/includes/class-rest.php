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
					)
				),
			)
		);
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

	public static function availability( WP_REST_Request $request ) {
		$result = Flexo_Booking_Bookings::search(
			$request['check_in'],
			$request['check_out'],
			$request['adults'],
			$request['children'],
			$request['room']
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public static function create_booking( WP_REST_Request $request ) {
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
				'adults'      => $request['adults'],
				'children'    => $request['children'],
				'guest_name'  => $request['guest_name'],
				'guest_email' => $request['guest_email'],
				'guest_phone' => $request['guest_phone'],
				'notes'       => $request['notes'],
				'source'      => 'website',
			)
		);

		if ( is_wp_error( $booking ) ) {
			$booking->add_data( array( 'status' => in_array( $booking->get_error_code(), array( 'flexo_unavailable', 'flexo_closed', 'flexo_busy' ), true ) ? 409 : 400 ) );
			return $booking;
		}

		$confirmed = 'confirmed' === $booking['status'];
		$redirect  = Flexo_Booking_Settings::site_url_setting( 'thank_you_url' );
		if ( $redirect ) {
			$redirect = add_query_arg( 'booking', rawurlencode( $booking['reference'] ), $redirect );
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
				'message'         => $confirmed
					? __( 'Your booking is confirmed! A confirmation has been sent to your email.', 'flexo-booking' )
					: __( 'Thank you! We received your booking request and will confirm it shortly by email.', 'flexo-booking' ),
				'redirect'        => $redirect,
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
