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
		add_shortcode( 'flexo_room_booking', array( __CLASS__, 'room_box_shortcode' ) );
		// Before redirect_canonical(), which would guess another page for the missing address.
		add_action( 'template_redirect', array( __CLASS__, 'builtin_page' ), 0 );
	}

	/**
	 * The built-in booking page: when the booking page address (/booking/,
	 * or the path set under Settings → Hotel) has no page, the booking form
	 * is shown there with the theme's header and footer instead of "Page not
	 * found". A real page with the form always wins.
	 */
	public static function builtin_page() {
		if ( ! is_404() ) {
			return;
		}
		$home    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$target  = trim( (string) wp_parse_url( Flexo_Booking_Guest::guest_page_url(), PHP_URL_PATH ), '/' );
		$path    = trim( rawurldecode( $request ), '/' );
		if ( '' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		if ( '' !== $home && 0 === strpos( $target . '/', $home . '/' ) ) {
			$target = trim( substr( $target, strlen( $home ) ), '/' );
		}
		// The same address with a language in front (/en/booking/) is fine too.
		$matches = static function ( $target ) use ( $path ) {
			return '' !== $target && preg_match( '#^(?:[a-z]{2}(?:[-_][a-z]{2})?/)?' . preg_quote( $target, '#' ) . '$#i', $path );
		};
		if ( ! $matches( $target ) ) {
			// Old or default links to /booking/ go to the real booking page, keeping room and dates.
			$real = Flexo_Booking_Guest::booking_page_url();
			if ( '' !== $real && $matches( 'booking' ) ) {
				$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed on to wp_safe_redirect() unchanged.
				wp_safe_redirect( '' !== $query ? $real . ( false === strpos( $real, '?' ) ? '?' : '&' ) . $query : $real, 302 );
				exit;
			}
			return;
		}
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		add_filter(
			'template_include',
			static function () {
				$theme = locate_template( 'flexo-booking/booking-page.php' );
				return $theme ? $theme : FLEXO_BOOKING_DIR . 'templates/booking-page.php';
			},
			100
		);
		add_filter(
			'pre_get_document_title',
			static function () {
				return __( 'Book your stay', 'flexo-booking' ) . ' – ' . get_bloginfo( 'name' );
			},
			30
		);
		add_filter(
			'body_class',
			static function ( $classes ) {
				return array_merge( array_diff( $classes, array( 'error404' ) ), array( 'flexo-booking-page' ) );
			}
		);
		add_filter(
			'wp_robots',
			static function ( $robots ) {
				$robots['noindex'] = true;
				return $robots;
			}
		);
	}

	/**
	 * [flexo_room_booking room="deluxe-double"]: the room booking box. Without
	 * a room it uses the room of the page (room page, room template).
	 */
	public static function room_box_shortcode( $atts ) {
		$atts           = is_array( $atts ) ? $atts : array();
		$atts['layout'] = 'box';
		return self::render( $atts );
	}

	/**
	 * @var bool Whether the form's texts were already added to the page.
	 */
	private static $localized = false;

	public static function register_assets() {
		wp_register_style( 'flexo-booking', FLEXO_BOOKING_URL . 'assets/css/booking.css', array(), FLEXO_BOOKING_VERSION );
		wp_register_script( 'flexo-booking', FLEXO_BOOKING_URL . 'assets/js/booking.js', array(), FLEXO_BOOKING_VERSION, true );
	}

	/**
	 * Texts and settings for booking.js. Added when the first form is
	 * rendered, when the page's language is known (Polylang / WPML).
	 */
	public static function localize() {
		if ( self::$localized ) {
			return;
		}
		self::$localized = true;
		$settings        = Flexo_Booking_Settings::all();
		wp_localize_script(
			'flexo-booking',
			'FlexoBookingConfig',
			array(
				'restUrl'   => esc_url_raw( rest_url( Flexo_Booking_Rest::NAMESPACE_V1 . '/' ) ),
				'minNights' => (int) $settings['min_nights'],
				'children'  => Flexo_Booking_Children::enabled(),
				'maxAge'    => Flexo_Booking_Children::MAX_AGE,
				'promo'     => Flexo_Booking_Promo_Codes::enabled(),
				'tracking'  => Flexo_Booking_Tracking::config(),
				'payments'  => self::payments_config(),
				// Day 6: steps, date picker, contact details, enquiries, guest booking page.
				'steps'     => self::step_list(),
				'maxNights' => (int) $settings['max_nights'],
				'instant'   => 'instant' === Flexo_Booking_Features::booking_mode(),
				'contact'   => Flexo_Booking_Guest::contact(),
				'replyTime' => Flexo_Booking_Guest::reply_time_text(),
				'manage'    => Flexo_Booking_Guest::manage_enabled(),
				'phoneMode' => $settings['field_phone'],
				'phoneCountry' => $settings['phone_country'],
				'consent'   => Flexo_Booking_Privacy::enabled() ? array(
					'html'     => Flexo_Booking_Privacy::consent_text( true ),
					'required' => Flexo_Booking_Privacy::consent_required(),
				) : false,
				'i18n'      => array_merge( self::texts(), array(
					'checking'     => __( 'Checking availability…', 'flexo-booking' ),
					// Day 7: room booking box.
					'boxAvailable'  => __( 'Available for your dates', 'flexo-booking' ),
					'boxUnavailable' => __( 'Not available for these dates.', 'flexo-booking' ),
					'boxOtherRooms' => __( 'See other rooms for these dates', 'flexo-booking' ),
					'bookNow'       => __( 'Book now', 'flexo-booking' ),
					'noRooms'      => __( 'No rooms are available for these dates. Please try different dates.', 'flexo-booking' ),
					'showAll'      => __( 'Show other rooms', 'flexo-booking' ),
					'select'       => __( 'Select', 'flexo-booking' ),
					'unavailable'  => __( 'Unavailable', 'flexo-booking' ),
					'perNight'     => __( 'per night', 'flexo-booking' ),
					/* translators: %s: average price per night */
					'avgPerNight'  => __( 'avg. %s per night', 'flexo-booking' ),
					/* translators: %d: number of guests */
					'upTo'         => __( 'Up to %d guests', 'flexo-booking' ),
					/* translators: %d: rooms left */
					'onlyLeft'     => __( 'Only %d left!', 'flexo-booking' ),
					'sending'      => __( 'Sending…', 'flexo-booking' ),
					'reference'    => __( 'Booking reference', 'flexo-booking' ),
					'genericError' => __( 'Something went wrong. Please try again or contact us.', 'flexo-booking' ),
					'datesInvalid' => __( 'Check-out must be after check-in.', 'flexo-booking' ),
					/* translators: %d: child number */
					'childAge'     => __( 'Age of child %d', 'flexo-booking' ),
					'agePick'      => __( 'Age', 'flexo-booking' ),
					'ageUnder1'    => __( 'under 1', 'flexo-booking' ),
					'agesMissing'  => __( 'Please select the age of each child.', 'flexo-booking' ),
					/* translators: %s: price */
					'from'         => __( 'from %s', 'flexo-booking' ),
					'chooseRate'   => __( 'Choose your rate', 'flexo-booking' ),
					'choose'       => __( 'Choose', 'flexo-booking' ),
					'rate'         => __( 'Rate', 'flexo-booking' ),
					'guests'       => __( 'Guests', 'flexo-booking' ),
					/* translators: %d: number of adults (1) */
					'adult'        => __( '%d adult', 'flexo-booking' ),
					/* translators: %d: number of adults (2 or more) */
					'adults'       => __( '%d adults', 'flexo-booking' ),
					/* translators: %d: number of children (1) */
					'child'        => __( '%d child', 'flexo-booking' ),
					/* translators: %d: number of children (2 or more) */
					'childrenN'    => __( '%d children', 'flexo-booking' ),
					/* translators: %s: ages */
					'agesList'     => __( '(ages %s)', 'flexo-booking' ),
					'subtotal'     => __( 'Subtotal', 'flexo-booking' ),
					'discount'     => __( 'Discount', 'flexo-booking' ),
					'total'        => __( 'Total', 'flexo-booking' ),
					'finalTotal'   => __( 'Final total', 'flexo-booking' ),
					'atProperty'   => __( 'Payable at the property', 'flexo-booking' ),
					'cancellation' => __( 'Cancellation', 'flexo-booking' ),
					'havePromo'    => __( 'Have a promo code?', 'flexo-booking' ),
					'promoLabel'   => __( 'Promo code', 'flexo-booking' ),
					'apply'        => __( 'Apply', 'flexo-booking' ),
					'remove'       => __( 'Remove', 'flexo-booking' ),
					/* translators: 1: promo code, 2: discount, e.g. "−10%" */
					'promoApplied' => __( 'Promo code %1$s applied (%2$s).', 'flexo-booking' ),
					'consent'      => __( 'Please accept the privacy policy to send your booking.', 'flexo-booking' ),
					'payAtProperty' => __( 'Payment', 'flexo-booking' ),
					'payAtPropertyText' => __( 'at the property', 'flexo-booking' ),
					'payNow'       => __( 'Pay now', 'flexo-booking' ),
					'atPropertyRest' => __( 'At the property', 'flexo-booking' ),
					'toPayNow'     => __( 'To pay now', 'flexo-booking' ),
					'continuePay'  => __( 'Continue to payment', 'flexo-booking' ),
					'confirmBooking' => __( 'Confirm booking', 'flexo-booking' ),
					'sendRequest'  => __( 'Send booking request', 'flexo-booking' ),
					'redirecting'  => __( 'Taking you to the secure payment page…', 'flexo-booking' ),
					'checkingPayment' => __( 'Confirming your payment…', 'flexo-booking' ),
					'stillChecking' => __( 'This is taking longer than usual. You will receive an email as soon as your payment is confirmed – you can safely close this page.', 'flexo-booking' ),
					'payAgain'     => __( 'Pay now', 'flexo-booking' ),
					'searchAgain'  => __( 'Search again', 'flexo-booking' ),
					'bankDetails'  => __( 'Bank transfer details', 'flexo-booking' ),
					'copy'         => __( 'Copy', 'flexo-booking' ),
					'copied'       => __( 'Copied', 'flexo-booking' ),
					'paid'         => __( 'Paid', 'flexo-booking' ),
					'total'        => __( 'Total', 'flexo-booking' ),
				) ),
			)
		);
	}

	/**
	 * The steps of the booking form. "Payment" only when guests pay online
	 * on a payment page.
	 *
	 * @return array key => label
	 */
	public static function step_list() {
		$steps = array(
			'dates'   => __( 'Dates', 'flexo-booking' ),
			'rooms'   => __( 'Room', 'flexo-booking' ),
			'details' => __( 'Your details', 'flexo-booking' ),
		);
		if ( Flexo_Booking_Payments::collects_now() ) {
			foreach ( Flexo_Booking_Payments::methods() as $id ) {
				if ( Flexo_Booking_Payments::gateway( $id )->is_hosted() ) {
					$steps['payment'] = __( 'Payment', 'flexo-booking' );
					break;
				}
			}
		}
		$steps['done'] = __( 'Confirmation', 'flexo-booking' );
		return $steps;
	}

	/**
	 * The step bar (the script marks the current step).
	 */
	public static function steps() {
		$html = '<ol class="fb-steps" data-fb-steps aria-label="' . esc_attr__( 'Booking steps', 'flexo-booking' ) . '">';
		$i    = 0;
		foreach ( self::step_list() as $key => $label ) {
			++$i;
			$html .= '<li class="fb-steps__item" data-step="' . esc_attr( $key ) . '"><span class="fb-steps__num" aria-hidden="true">' . $i . '</span><span class="fb-steps__label">' . esc_html( $label ) . '</span></li>';
		}
		return $html . '</ol>';
	}

	/**
	 * Country code of the phone number: shows "+359", lists countries by name.
	 */
	public static function phone_country_select( $uid ) {
		$default = Flexo_Booking_Settings::get( 'phone_country' );
		$list    = Flexo_Booking_Phone::countries( Flexo_Booking_I18n::current() );
		$code    = isset( $list[ $default ] ) ? $list[ $default ]['code'] : '';
		$html    = '<span class="fb-phone__cc"><span class="fb-phone__code" aria-hidden="true">+' . esc_html( $code ) . '</span>';
		$html   .= '<select id="' . esc_attr( $uid ) . '-phone-country" name="phone_country" autocomplete="off" aria-label="' . esc_attr__( 'Country code', 'flexo-booking' ) . '">';
		foreach ( $list as $iso => $country ) {
			$html .= '<option value="' . esc_attr( $iso ) . '" data-code="' . esc_attr( $country['code'] ) . '"' . selected( $iso, $default, false ) . '>' . esc_html( $country['name'] . ' (+' . $country['code'] . ')' ) . '</option>';
		}
		return $html . '</select></span>';
	}

	/**
	 * Texts of the Day 6 guest flow (steps, picker, validation, no dead
	 * ends, confirmation, guest booking page).
	 */
	private static function texts() {
		return array(
			/* translators: 1: step number, 2: number of steps, 3: step name */
			'stepOf'          => __( 'Step %1$s of %2$s: %3$s', 'flexo-booking' ),
			'change'          => __( 'Change', 'flexo-booking' ),
			'changeSearch'    => __( 'Change dates or guests', 'flexo-booking' ),
			'chooseDates'     => __( 'Choose dates', 'flexo-booking' ),
			'datesDialog'     => __( 'Choose your dates', 'flexo-booking' ),
			'prevMonth'       => __( 'Previous month', 'flexo-booking' ),
			'nextMonth'       => __( 'Next month', 'flexo-booking' ),
			'close'           => __( 'Close', 'flexo-booking' ),
			'clear'           => __( 'Clear dates', 'flexo-booking' ),
			'done'            => __( 'Done', 'flexo-booking' ),
			'pickArrival'     => __( 'Choose your check-in date.', 'flexo-booking' ),
			/* translators: %s: date */
			'pickDeparture'   => __( 'Check-in %s. Now choose your check-out date.', 'flexo-booking' ),
			/* translators: %d: number of nights */
			'minStayHint'     => __( 'Minimum stay %d nights.', 'flexo-booking' ),
			'loadingDates'    => __( 'Loading availability…', 'flexo-booking' ),
			'dayFull'         => __( 'fully booked', 'flexo-booking' ),
			'dayClosed'       => __( 'closed', 'flexo-booking' ),
			/* translators: %d: number of nights */
			'dayMinStay'      => __( 'minimum stay %d nights', 'flexo-booking' ),
			'dayNoArrival'    => __( 'no check-in possible on this day', 'flexo-booking' ),
			'dayUnavailable'  => __( 'not available', 'flexo-booking' ),
			'dayAvailable'    => __( 'available', 'flexo-booking' ),
			'dayCheckIn'      => __( 'check-in', 'flexo-booking' ),
			'dayCheckOut'     => __( 'check-out', 'flexo-booking' ),
			'datesMissing'    => __( 'Please choose your check-in and check-out dates.', 'flexo-booking' ),
			/* translators: %d: number of rooms */
			'roomsFound'      => __( '%d rooms available for your dates.', 'flexo-booking' ),
			'roomFound'       => __( '1 room available for your dates.', 'flexo-booking' ),
			'nothingTitle'    => __( 'Nothing is free for these dates', 'flexo-booking' ),
			'nearbyDates'     => __( 'Free on nearby dates', 'flexo-booking' ),
			/* translators: %d: number of rooms */
			'nRooms'          => __( '%d rooms', 'flexo-booking' ),
			'oneRoom'         => __( '1 room', 'flexo-booking' ),
			/* translators: %d: number of rooms */
			'otherRooms'      => __( 'See %d other rooms free on your dates', 'flexo-booking' ),
			'otherRoom'       => __( 'See 1 other room free on your dates', 'flexo-booking' ),
			'askUs'           => __( 'Can\'t find what you need? Send us a message and we will help.', 'flexo-booking' ),
			'sendEnquiry'     => __( 'Send an enquiry', 'flexo-booking' ),
			'enquiryTitle'    => __( 'Send us an enquiry', 'flexo-booking' ),
			'enquiryNote'     => __( 'This is not a booking. We will reply by email.', 'flexo-booking' ),
			'yourName'        => __( 'Your name', 'flexo-booking' ),
			'email'           => __( 'Email', 'flexo-booking' ),
			'phone'           => __( 'Phone', 'flexo-booking' ),
			'message'         => __( 'Message', 'flexo-booking' ),
			'optional'        => __( '(optional)', 'flexo-booking' ),
			'enquiryPlaceholder' => __( 'e.g. flexible dates, number of rooms, questions', 'flexo-booking' ),
			'sendMessage'     => __( 'Send message', 'flexo-booking' ),
			/* translators: %s: room size in square metres */
			'sqm'             => __( '%s m²', 'flexo-booking' ),
			/* translators: %d: number of more amenities */
			'moreAmenities'   => __( '+%d more', 'flexo-booking' ),
			/* translators: %s: number of nights label, e.g. "3 nights" */
			'forStay'         => __( 'for %s', 'flexo-booking' ),
			/* translators: %d: number of nights */
			'nightsN'         => __( '%d nights', 'flexo-booking' ),
			'night1'          => __( '1 night', 'flexo-booking' ),
			'chooseRoom'      => __( 'Choose a room', 'flexo-booking' ),
			/* translators: %s: room name */
			'roomPhoto'       => __( 'Photo of %s', 'flexo-booking' ),
			'required'        => __( 'Please fill in this field.', 'flexo-booking' ),
			'nameMissing'     => __( 'Please enter your full name.', 'flexo-booking' ),
			'emailMissing'    => __( 'Please enter your email address.', 'flexo-booking' ),
			'emailInvalid'    => __( 'Please enter an email address like name@example.com.', 'flexo-booking' ),
			'phoneMissing'    => __( 'Please enter your phone number.', 'flexo-booking' ),
			'phoneInvalid'    => __( 'Please enter a phone number we can reach you on, for example 888 123 456.', 'flexo-booking' ),
			'termsMissing'    => __( 'Please accept the terms and conditions to continue.', 'flexo-booking' ),
			'ageMissing'      => __( 'Please choose the child\'s age.', 'flexo-booking' ),
			'consentMessage'  => __( 'Please accept the privacy policy to send your message.', 'flexo-booking' ),
			'chooseRequest'   => __( 'Please choose what you would like to do.', 'flexo-booking' ),
			'checkFields'     => __( 'Please check the highlighted fields.', 'flexo-booking' ),
			'beforeYouBook'   => __( 'Before you book', 'flexo-booking' ),
			'payNowText'      => __( 'You pay now', 'flexo-booking' ),
			'payLaterText'    => __( 'You pay at the property', 'flexo-booking' ),
			'nothingNow'      => __( 'Nothing to pay now – you pay at the property.', 'flexo-booking' ),
			/* translators: %s: e.g. "within 24 hours" */
			'requestNote'     => __( 'This is a booking request: we confirm it by email %s. Nothing is charged now.', 'flexo-booking' ),
			'questions'       => __( 'Questions? Contact us', 'flexo-booking' ),
			/* translators: %s: amount */
			'payCard'         => __( 'Continue to secure payment – %s', 'flexo-booking' ),
			/* translators: %s: amount */
			'payBank'         => __( 'Confirm and pay %s by bank transfer', 'flexo-booking' ),
			'doneConfirmed'   => __( 'Your booking is confirmed', 'flexo-booking' ),
			'doneRequest'     => __( 'Your booking request has been sent', 'flexo-booking' ),
			'doneTransfer'    => __( 'Your room is reserved – please pay to confirm', 'flexo-booking' ),
			'doneOther'       => __( 'Your booking', 'flexo-booking' ),
			'nextSteps'       => __( 'What happens next', 'flexo-booking' ),
			'yourBooking'     => __( 'Your booking', 'flexo-booking' ),
			'room'            => __( 'Room', 'flexo-booking' ),
			'dates'           => __( 'Dates', 'flexo-booking' ),
			'contactUs'       => __( 'Contact', 'flexo-booking' ),
			'directions'      => __( 'Directions', 'flexo-booking' ),
			'addCalendar'     => __( 'Add to calendar', 'flexo-booking' ),
			'manageBooking'   => __( 'View or change your booking', 'flexo-booking' ),
			'print'           => __( 'Print', 'flexo-booking' ),
			'status'          => __( 'Status', 'flexo-booking' ),
			'requests'        => __( 'Your requests', 'flexo-booking' ),
			'requestHandled'  => __( 'answered', 'flexo-booking' ),
			'requestOpen'     => __( 'waiting for our reply', 'flexo-booking' ),
			'askChange'       => __( 'Need to change or cancel?', 'flexo-booking' ),
			'askChangeNote'   => __( 'Send us a request. Nothing changes until we reply – we will confirm by email.', 'flexo-booking' ),
			'requestType'     => __( 'What would you like to do?', 'flexo-booking' ),
			'requestMessage'  => __( 'Message to the hotel', 'flexo-booking' ),
			'changePlaceholder' => __( 'e.g. new dates, number of guests', 'flexo-booking' ),
			'sendRequestBtn'  => __( 'Send request', 'flexo-booking' ),
			'noRequests'      => __( 'To change this booking, please contact us by phone or email.', 'flexo-booking' ),
			'loading'         => __( 'Loading…', 'flexo-booking' ),
			'staySummary'     => __( 'Your stay', 'flexo-booking' ),
			'showSummary'     => __( 'Show price details', 'flexo-booking' ),
		);
	}

	public static function shortcode( $atts ) {
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * @param array $atts {
	 *     @type string $layout       "full" (complete booking flow), "search" (compact bar that sends visitors to the booking page)
	 *                                or "box" (one room's booking box: availability, total and "Book now").
	 *     @type string $room         Room slug or ID to preselect. The box uses the room of the page when empty.
	 *     @type string $booking_page Path or URL of the booking page, used by the "search" and "box" layouts
	 *                                (the box uses the booking page from the settings when empty).
	 *     @type string $title        Optional heading.
	 *     @type string $button_text  Text of the search button.
	 *     @type string $book_text    Box: text of the "Book now" button.
	 *     @type string $show_price   Box: "yes" shows the "from" price.
	 * }
	 */
	public static function render( array $atts ) {
		$is_box = isset( $atts['layout'] ) && 'box' === $atts['layout'];
		$atts   = shortcode_atts(
			array(
				'layout'       => 'full',
				'room'         => '',
				'booking_page' => $is_box ? '' : '/booking/',
				'title'        => '',
				'button_text'  => '',
				'book_text'    => '',
				'show_price'   => 'yes',
				'in_dialog'    => '',
			),
			$atts,
			$is_box ? 'flexo_room_booking' : 'flexo_booking'
		);

		$atts['layout'] = in_array( $atts['layout'], array( 'search', 'box' ), true ) ? $atts['layout'] : 'full';
		if ( '' === $atts['button_text'] ) {
			$atts['button_text'] = 'search' === $atts['layout'] ? __( 'Search', 'flexo-booking' ) : __( 'Check availability', 'flexo-booking' );
		}
		if ( '' === $atts['book_text'] ) {
			$atts['book_text'] = __( 'Book now', 'flexo-booking' );
		}
		if ( $is_box ) {
			return self::render_box( $atts );
		}

		// Values passed from a search bar or a "Book now" link.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill.
		$prefill = array(
			'check_in'  => isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : '',
			'check_out' => isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : '',
			'adults'    => isset( $_GET['adults'] ) ? max( 1, absint( $_GET['adults'] ) ) : 2,
			'children'  => isset( $_GET['children'] ) ? absint( $_GET['children'] ) : 0,
			'ages'      => isset( $_GET['children_ages'] ) ? Flexo_Booking_Children::parse_ages( is_array( $_GET['children_ages'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['children_ages'] ) ) : sanitize_text_field( wp_unslash( $_GET['children_ages'] ) ) ) : array(),
			'room'      => isset( $_GET['room'] ) ? sanitize_title( wp_unslash( $_GET['room'] ) ) : '',
		);
		// phpcs:enable
		if ( is_wp_error( $prefill['ages'] ) || ! Flexo_Booking_Children::enabled() ) {
			$prefill['ages'] = array();
		}

		$room = null;
		if ( $prefill['room'] ) {
			$room = Flexo_Booking_Rooms::find( $prefill['room'] );
		}
		if ( ! $room && $atts['room'] ) {
			$room = Flexo_Booking_Rooms::find( $atts['room'] );
		}

		$settings = Flexo_Booking_Settings::all();
		// The mode actually in use (feature switches can limit the choice).
		$settings['booking_mode'] = Flexo_Booking_Features::booking_mode();
		$tz                       = wp_timezone();
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
			'autosearch'   => $prefill['check_in'] && $prefill['check_out'],
			'ask_ages'     => Flexo_Booking_Children::enabled() && (int) $settings['max_children'] > 0,
			'terms_url'    => Flexo_Booking_I18n::page_url( Flexo_Booking_Settings::site_url_setting( 'terms_url' ) ),
		);

		wp_enqueue_style( 'flexo-booking' );
		self::appearance();
		wp_enqueue_script( 'flexo-booking' );
		self::localize();

		ob_start();
		self::load_template( 'search' === $atts['layout'] ? 'search-bar.php' : 'booking-form.php', $vars );
		$html = ob_get_clean();

		// Theme template overrides made before 1.4 have no place for the
		// privacy consent and invoice fields: add them before the buttons.
		if ( 'full' === $atts['layout'] && false === strpos( $html, 'data-fb-extra' ) ) {
			$extra = self::extra_fields( $vars['uid'] );
			if ( '' !== $extra ) {
				$pos  = strpos( $html, '<div class="fb-actions">' );
				$html = false === $pos ? $html : substr_replace( $html, $extra, $pos, 0 );
			}
		}
		return $html;
	}

	/**
	 * The room booking box (layout "box").
	 */
	private static function render_box( array $atts ) {
		$room_id = Flexo_Booking_Room_I18n::canonical_id( Flexo_Booking_Room_Content::current_id( $atts['room'] ) );
		$post    = $room_id ? get_post( $room_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			if ( class_exists( 'Flexo_Booking_Elementor' ) && Flexo_Booking_Elementor::is_editing() ) {
				return '<div class="flexo-room-note">' . esc_html__( 'Room booking box: no room here. Use it in your room template, or choose a room.', 'flexo-booking' ) . '</div>';
			}
			return '';
		}
		if ( Flexo_Booking_Room_Content::is_demo( $post->ID ) ) {
			return '<div class="flexo-room-note">' . esc_html__( 'This is a demo room: it cannot be booked.', 'flexo-booking' ) . '</div>';
		}
		$room     = Flexo_Booking_Rooms::to_array( $post );
		$settings = Flexo_Booking_Settings::all();
		$tz       = wp_timezone();
		if ( '' === $atts['in_dialog'] ) {
			self::$box_rooms[ $room['id'] ] = true;
		}
		$today    = new DateTimeImmutable( 'today', $tz );
		// Dates and guests from a search ("?check_in=…&adults=…") are filled in and checked at once.
		$args    = Flexo_Booking_Room_Content::search_args();
		$prefill = array(
			'check_in'  => isset( $args['check_in'] ) ? $args['check_in'] : '',
			'check_out' => isset( $args['check_out'] ) ? $args['check_out'] : '',
			'adults'    => isset( $args['adults'] ) ? max( 1, (int) $args['adults'] ) : min( 2, $room['capacity'] ),
			'children'  => isset( $args['children'] ) ? (int) $args['children'] : 0,
			'ages'      => array(),
		);
		if ( Flexo_Booking_Children::enabled() && isset( $args['children_ages'] ) ) {
			$ages            = Flexo_Booking_Children::parse_ages( $args['children_ages'] );
			$prefill['ages'] = is_wp_error( $ages ) ? array() : $ages;
		}
		$max_adults = $room['capacity'];
		if ( Flexo_Booking_Children::enabled() && $room['max_adults'] > 0 ) {
			$max_adults = min( $max_adults, $room['max_adults'] );
		}
		$max_adults = max( 1, min( $max_adults, (int) $settings['max_adults'] ) );
		$max_kids   = max( 0, min( (int) $settings['max_children'], $room['capacity'] - 1 ) );
		$from       = '';
		if ( 'yes' === $atts['show_price'] && Flexo_Booking_Room_Prices::shown() ) {
			$price = Flexo_Booking_Room_Prices::get( $room['id'] );
			$from  = $price ? Flexo_Booking_Money::format( $price['amount'], null, true ) : '';
		}
		$page = trim( (string) $atts['booking_page'] );
		$vars = array(
			'atts'        => $atts,
			'settings'    => $settings,
			'prefill'     => $prefill,
			'room'        => $room,
			'uid'         => 'fb-' . wp_unique_id(),
			'min_date'    => $today->format( 'Y-m-d' ),
			'max_date'    => $today->modify( '+' . (int) $settings['max_advance_days'] . ' days' )->format( 'Y-m-d' ),
			'booking_url' => Flexo_Booking_Room_Content::booking_url( '', array(), $page ),
			// In a "Check availability" panel the dates are checked when it opens.
			'autosearch'  => '' === $atts['in_dialog'] && '' !== $prefill['check_in'] && '' !== $prefill['check_out'],
			'ask_ages'    => Flexo_Booking_Children::enabled() && $max_kids > 0,
			'from'        => $from,
			'max_adults'  => $max_adults,
			'max_kids'    => $max_kids,
		);

		wp_enqueue_style( 'flexo-booking' );
		self::appearance();
		wp_enqueue_script( 'flexo-booking' );
		self::localize();

		ob_start();
		self::load_template( 'room-booking-box.php', $vars );
		return ob_get_clean();
	}

	/**
	 * @var array Rooms whose booking box is on the page (room ID => true).
	 */
	private static $box_rooms = array();

	/**
	 * Whether the page already shows this room's booking box.
	 */
	public static function has_box( $room_id ) {
		return isset( self::$box_rooms[ (int) $room_id ] );
	}

	/**
	 * @var bool Custom appearance CSS already added.
	 */
	private static $styled = false;

	private static function appearance() {
		if ( ! self::$styled ) {
			self::$styled = true;
			Flexo_Booking_Appearance::enqueue();
		}
	}

	/**
	 * Language and date format of the page, for the form's REST requests.
	 */
	public static function locale_attributes() {
		$locale = Flexo_Booking_I18n::current();
		return ' data-locale="' . esc_attr( $locale ) . '" data-date-format="' . esc_attr( Flexo_Booking_I18n::date_format( $locale ) ) . '"';
	}

	/**
	 * Payments for booking.js: whether the summary shows what is paid now
	 * and at the property, and which way of paying submits to which label.
	 *
	 * @return array|false
	 */
	private static function payments_config() {
		if ( ! Flexo_Booking_Payments::enabled() ) {
			return false;
		}
		$methods = array();
		foreach ( Flexo_Booking_Payments::collects_now() ? Flexo_Booking_Payments::methods() : array() as $id ) {
			$methods[ $id ] = array( 'hosted' => Flexo_Booking_Payments::gateway( $id )->is_hosted() );
		}
		return array(
			'methods' => $methods,
			'instant' => 'instant' === Flexo_Booking_Features::booking_mode(),
		);
	}

	/**
	 * "How would you like to pay?" in the guest details form, when guests
	 * pay something when booking.
	 */
	public static function payment_fields( $uid ) {
		if ( ! Flexo_Booking_Payments::collects_now() ) {
			return '';
		}
		$methods = Flexo_Booking_Payments::methods();
		$texts   = array(
			'stripe'        => __( 'Visa, Mastercard and other cards. You pay on Stripe\'s secure payment page; your card details never reach us.', 'flexo-booking' ),
			'bank_transfer' => 'instant' === Flexo_Booking_Features::booking_mode()
				/* translators: %d: number of days */
				? sprintf( _n( 'You receive our bank details right away. Please pay within %d day.', 'You receive our bank details right away. Please pay within %d days.', (int) Flexo_Booking_Settings::get( 'bank_transfer_days' ), 'flexo-booking' ), (int) Flexo_Booking_Settings::get( 'bank_transfer_days' ) )
				: __( 'Once we confirm your request, we email you our bank details.', 'flexo-booking' ),
		);
		$html  = '<fieldset class="fb-payment" data-fb-payment>';
		$html .= '<legend class="fb-payment__title">' . esc_html__( 'How would you like to pay?', 'flexo-booking' ) . '</legend>';
		foreach ( $methods as $i => $id ) {
			$gateway = Flexo_Booking_Payments::gateway( $id );
			$html   .= '<label class="fb-choice"><input type="radio" name="payment_method" value="' . esc_attr( $id ) . '"' . ( 0 === $i ? ' checked' : '' ) . ( 1 === count( $methods ) ? ' hidden' : '' ) . '>'
				. '<span class="fb-choice__text"><strong>' . esc_html( $gateway->label() ) . '</strong>'
				. ( isset( $texts[ $id ] ) ? '<small>' . esc_html( $texts[ $id ] ) . '</small>' : '' ) . '</span></label>';
		}
		$html .= '</fieldset>';
		return $html;
	}

	/**
	 * Privacy consent and invoice request fields of the guest details form
	 * (only for features that are on).
	 */
	public static function extra_fields( $uid ) {
		$html = self::payment_fields( $uid );
		if ( Flexo_Booking_Invoices::enabled() ) {
			$html .= Flexo_Booking_Invoices::render_fields( $uid );
		}
		if ( Flexo_Booking_Privacy::enabled() ) {
			$required = Flexo_Booking_Privacy::consent_required();
			// Never ticked in advance.
			$html .= '<label class="fb-terms fb-consent"><input type="checkbox" name="privacy_consent" value="1"' . ( $required ? ' required' : '' ) . '> <span>' . Flexo_Booking_Privacy::consent_text( true ) . ( $required ? ' <span class="fb-req" aria-hidden="true">*</span>' : '' ) . '</span></label>';
		}
		return '' === $html ? '' : '<div class="fb-extra" data-fb-extra>' . $html . '</div>';
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
