<?php
/**
 * Front-end booking form: shortcode, assets and template rendering.
 *
 * The same renderer powers the [flexo_booking] shortcode and the Elementor
 * widget, so the form looks and works identically wherever it is placed.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'flexo_booking', array( __CLASS__, 'shortcode' ) );
	}

	public static function register_assets() {
		wp_register_style( 'flexo-booking', FLEXO_BOOKING_URL . 'assets/css/booking.css', array(), FLEXO_BOOKING_VERSION );
		wp_register_script( 'flexo-booking', FLEXO_BOOKING_URL . 'assets/js/booking.js', array(), FLEXO_BOOKING_VERSION, true );

		$settings = Flexo_Booking_Settings::all();
		wp_localize_script(
			'flexo-booking',
			'FlexoBookingConfig',
			array(
				'restUrl'   => esc_url_raw( rest_url( Flexo_Booking_Rest::NAMESPACE_V1 . '/' ) ),
				'minNights' => (int) $settings['min_nights'],
				'i18n'      => array(
					'checking'     => __( 'Checking availability…', 'flexo-booking' ),
					'noRooms'      => __( 'No rooms are available for these dates. Please try different dates.', 'flexo-booking' ),
					'showAll'      => __( 'Show other rooms', 'flexo-booking' ),
					'select'       => __( 'Select', 'flexo-booking' ),
					'unavailable'  => __( 'Unavailable', 'flexo-booking' ),
					'perNight'     => __( 'per night', 'flexo-booking' ),
					/* translators: %d: number of guests */
					'upTo'         => __( 'Up to %d guests', 'flexo-booking' ),
					/* translators: %d: rooms left */
					'onlyLeft'     => __( 'Only %d left!', 'flexo-booking' ),
					'sending'      => __( 'Sending…', 'flexo-booking' ),
					'reference'    => __( 'Booking reference', 'flexo-booking' ),
					'genericError' => __( 'Something went wrong. Please try again or contact us.', 'flexo-booking' ),
					'datesInvalid' => __( 'Check-out must be after check-in.', 'flexo-booking' ),
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * @param array $atts {
	 *     @type string $layout       "full" (complete booking flow) or "search" (compact bar that sends visitors to the booking page).
	 *     @type string $room         Room slug or ID to preselect.
	 *     @type string $booking_page Path or URL of the booking page, used by the "search" layout.
	 *     @type string $title        Optional heading.
	 *     @type string $button_text  Text of the search button.
	 * }
	 */
	public static function render( array $atts ) {
		$atts = shortcode_atts(
			array(
				'layout'       => 'full',
				'room'         => '',
				'booking_page' => '/booking/',
				'title'        => '',
				'button_text'  => '',
			),
			$atts,
			'flexo_booking'
		);

		$atts['layout'] = 'search' === $atts['layout'] ? 'search' : 'full';
		if ( '' === $atts['button_text'] ) {
			$atts['button_text'] = 'search' === $atts['layout'] ? __( 'Search', 'flexo-booking' ) : __( 'Check availability', 'flexo-booking' );
		}

		// Values passed from a search bar or a "Book now" link.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		$prefill = array(
			'check_in'  => isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : '',
			'check_out' => isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : '',
			'adults'    => isset( $_GET['adults'] ) ? max( 1, absint( $_GET['adults'] ) ) : 2,
			'children'  => isset( $_GET['children'] ) ? absint( $_GET['children'] ) : 0,
			'room'      => isset( $_GET['room'] ) ? sanitize_title( wp_unslash( $_GET['room'] ) ) : '',
		);
		// phpcs:enable

		$room = null;
		if ( $prefill['room'] ) {
			$room = Flexo_Booking_Rooms::find( $prefill['room'] );
		}
		if ( ! $room && $atts['room'] ) {
			$room = Flexo_Booking_Rooms::find( $atts['room'] );
		}

		$settings = Flexo_Booking_Settings::all();
		$tz       = wp_timezone();
		$today    = new DateTimeImmutable( 'today', $tz );

		$vars = array(
			'atts'         => $atts,
			'settings'     => $settings,
			'prefill'      => $prefill,
			'room'         => $room ? Flexo_Booking_Rooms::to_array( $room ) : null,
			'uid'          => 'fb-' . wp_unique_id(),
			'min_date'     => $today->format( 'Y-m-d' ),
			'max_date'     => $today->modify( '+' . (int) $settings['max_advance_days'] . ' days' )->format( 'Y-m-d' ),
			'booking_url'  => self::resolve_url( $atts['booking_page'] ),
			'terms_url'    => Flexo_Booking_Settings::site_url_setting( 'terms_url' ),
			'autosearch'   => $prefill['check_in'] && $prefill['check_out'],
		);

		wp_enqueue_style( 'flexo-booking' );
		wp_enqueue_script( 'flexo-booking' );

		ob_start();
		self::load_template( 'search' === $atts['layout'] ? 'search-bar.php' : 'booking-form.php', $vars );
		return ob_get_clean();
	}

	private static function resolve_url( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return home_url( '/' . ltrim( $value, '/' ) );
	}

	/**
	 * Themes can override templates by copying them to
	 * {theme}/flexo-booking/{template}.
	 */
	public static function load_template( $template, array $vars ) {
		$file = locate_template( 'flexo-booking/' . $template );
		if ( ! $file ) {
			$file = FLEXO_BOOKING_DIR . 'templates/' . $template;
		}
		$file = apply_filters( 'flexo_booking_template', $file, $template, $vars );
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $file;
	}
}
