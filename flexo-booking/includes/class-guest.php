<?php
/**
 * What guests see around a booking: the confirmation (also after a page
 * refresh), "Add to calendar", the hotel's contact details, the date
 * picker's availability, nearby dates when nothing is free, enquiries, and
 * the guest booking page (feature "guest_booking_page") where guests send a
 * cancellation or change request. Nothing a guest sends changes a booking
 * by itself: the hotel decides.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Guest {

	/**
	 * Cancellation / change requests per booking per day.
	 */
	const REQUEST_LIMIT = 3;

	const PAGE_TRANSIENT = 'flexo_booking_page_detected';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_ics' ), 10, 4 );
		add_action( 'save_post_page', array( __CLASS__, 'forget_booking_page' ) );
		add_action( Flexo_Booking_Emails::DAILY, array( 'Flexo_Booking_Log', 'cleanup' ) );
	}

	public static function manage_enabled() {
		return Flexo_Booking_Features::is_enabled( 'guest_booking_page' );
	}

	/* ---------------------------------------------------------------------
	 * Keys and links
	 * ------------------------------------------------------------------- */

	/**
	 * The guest's key for a booking: signed with the site's secret keys, so
	 * links in emails work for every booking without storing anything.
	 */
	public static function key( array $booking ) {
		return substr( hash_hmac( 'sha256', $booking['id'] . '|' . $booking['reference'] . '|' . $booking['created_at'], wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Accepts the signed key or the payment key a guest got when booking.
	 * Bookings whose personal data was removed are no longer shown.
	 */
	public static function check( $booking, $key ) {
		if ( ! $booking || ! empty( $booking['anonymized_at'] ) || '' === (string) $key || ! is_string( $key ) ) {
			return false;
		}
		return hash_equals( self::key( $booking ), $key ) || Flexo_Booking_Payments::check_key( $booking, $key );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function find( $reference, $key ) {
		$booking = Flexo_Booking_Bookings::get_by_reference( sanitize_text_field( (string) $reference ) );
		if ( ! self::check( $booking, (string) $key ) ) {
			return new WP_Error( 'flexo_not_found', __( 'We could not find this booking. Please use the link from your confirmation email.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/**
	 * The page with the full booking form: the setting, else found
	 * automatically, in the guest's language when the site is multilingual.
	 */
	public static function booking_page_url( $locale = '' ) {
		$url = Flexo_Booking_Settings::site_url_setting( 'booking_page' );
		if ( '' === $url ) {
			$url = self::detect_booking_page();
		}
		return '' === $url ? '' : Flexo_Booking_I18n::page_url( $url, $locale );
	}

	/**
	 * Address of the built-in booking page (see Flexo_Booking_Frontend::builtin_page()).
	 */
	public static function builtin_page_url() {
		return home_url( '/booking/' );
	}

	/**
	 * The booking page for guests: the real one, else the built-in page,
	 * so links never lead to "Page not found".
	 */
	public static function guest_page_url( $locale = '' ) {
		$url = self::booking_page_url( $locale );
		return '' !== $url ? $url : self::builtin_page_url();
	}

	/**
	 * The first published page with the full booking form (shortcode or
	 * Elementor widget), remembered for 12 hours.
	 */
	public static function detect_booking_page() {
		$cached = get_transient( self::PAGE_TRANSIENT );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		global $wpdb;
		$url = '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order, ID LIMIT 20", '%' . $wpdb->esc_like( '[flexo_booking' ) . '%' ) );
		foreach ( $ids as $id ) {
			if ( preg_match_all( '/\[flexo_booking([^\]]*)\]/', (string) get_post_field( 'post_content', $id ), $m ) ) {
				foreach ( $m[1] as $attrs ) {
					if ( ! preg_match( '/layout\s*=\s*["\']?search/', $attrs ) ) {
						$url = get_permalink( $id );
						break 2;
					}
				}
			}
		}
		if ( '' === $url ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_type = 'page' AND p.post_status = 'publish' AND m.meta_value LIKE %s ORDER BY p.menu_order, p.ID LIMIT 20", '%' . $wpdb->esc_like( 'flexo-booking-form' ) . '%' ) );
			foreach ( $ids as $id ) {
				$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
				if ( self::elementor_has_full_form( is_array( $data ) ? $data : array() ) ) {
					$url = get_permalink( $id );
					break;
				}
			}
		}
		// phpcs:enable
		set_transient( self::PAGE_TRANSIENT, (string) $url, 12 * HOUR_IN_SECONDS );
		return (string) $url;
	}

	private static function elementor_has_full_form( array $elements ) {
		foreach ( $elements as $element ) {
			if ( isset( $element['widgetType'] ) && 'flexo-booking-form' === $element['widgetType'] && ( empty( $element['settings']['layout'] ) || 'search' !== $element['settings']['layout'] ) ) {
				return true;
			}
			if ( ! empty( $element['elements'] ) && self::elementor_has_full_form( $element['elements'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function forget_booking_page() {
		delete_transient( self::PAGE_TRANSIENT );
	}

	/**
	 * Links for a booking: add to calendar, the guest booking page and the
	 * confirmation page ('' when not available).
	 */
	public static function links( array $booking ) {
		$key    = self::key( $booking );
		$page   = self::guest_page_url( $booking['locale'] );
		$args   = array(
			'reference' => rawurlencode( $booking['reference'] ),
			'key'       => $key,
		);
		return array(
			'ics'    => in_array( $booking['status'], array( 'cancelled', 'expired', 'blocked' ), true ) ? '' : add_query_arg( $args, rest_url( Flexo_Booking_Rest::NAMESPACE_V1 . '/booking.ics' ) ),
			'manage' => self::manage_enabled() && '' !== $page ? add_query_arg(
				array(
					'fb_manage' => rawurlencode( $booking['reference'] ),
					'fb_key'    => $key,
				),
				$page
			) : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Contact details and the confirmation
	 * ------------------------------------------------------------------- */

	/**
	 * The hotel's contact details for guests.
	 */
	public static function contact() {
		$s       = Flexo_Booking_Settings::all();
		$email   = '' !== $s['hotel_email'] ? $s['hotel_email'] : Flexo_Booking_Settings::notification_email();
		$address = trim( (string) $s['hotel_address'] );
		return array(
			'name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'phone'      => $s['hotel_phone'],
			'phone_link' => '' !== $s['hotel_phone'] ? 'tel:' . preg_replace( '/[^\d+]/', '', $s['hotel_phone'] ) : '',
			'email'      => $email,
			'address'    => $address,
			'directions' => '' !== $address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( preg_replace( '/\s+/', ' ', $address ) ) : '',
		);
	}

	public static function reply_hours() {
		return max( 1, (int) Flexo_Booking_Settings::get( 'request_reply_hours' ) );
	}

	/**
	 * "within 24 hours" / "within 2 days".
	 */
	public static function reply_time_text() {
		$hours = self::reply_hours();
		if ( $hours >= 48 && 0 === $hours % 24 ) {
			/* translators: %d: number of days */
			return sprintf( _n( 'within %d day', 'within %d days', $hours / 24, 'flexo-booking' ), $hours / 24 );
		}
		/* translators: %d: number of hours */
		return sprintf( _n( 'within %d hour', 'within %d hours', $hours, 'flexo-booking' ), $hours );
	}

	public static function guests_text( array $booking ) {
		/* translators: %d: number of adults */
		$text = sprintf( _n( '%d adult', '%d adults', (int) $booking['adults'], 'flexo-booking' ), (int) $booking['adults'] );
		if ( (int) $booking['children'] > 0 ) {
			/* translators: %d: number of children */
			$text .= ', ' . sprintf( _n( '%d child', '%d children', (int) $booking['children'], 'flexo-booking' ), (int) $booking['children'] );
		}
		return $text;
	}

	/**
	 * Status in guests' words.
	 */
	public static function state_label( $state ) {
		$labels = array(
			'request'           => __( 'Waiting for confirmation', 'flexo-booking' ),
			'confirmed'         => __( 'Confirmed', 'flexo-booking' ),
			'awaiting_transfer' => __( 'Waiting for your bank transfer', 'flexo-booking' ),
			'held'              => __( 'Waiting for your payment', 'flexo-booking' ),
			'processing'        => __( 'Payment being processed', 'flexo-booking' ),
			'cancelled'         => __( 'Cancelled', 'flexo-booking' ),
			'expired'           => __( 'Not completed', 'flexo-booking' ),
			'failed'            => __( 'Not completed', 'flexo-booking' ),
			'conflict'          => __( 'The hotel will contact you', 'flexo-booking' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : '';
	}

	/**
	 * Everything the confirmation and the guest booking page show.
	 */
	public static function view( array $booking ) {
		$payment  = Flexo_Booking_Payments::guest_view( $booking );
		$snapshot = Flexo_Booking_Pricing::snapshot( $booking );
		$quote    = Flexo_Booking_Pricing::public_view( $snapshot );
		$plan     = isset( $snapshot['rate_plan'] ) && $snapshot['rate_plan'] ? $snapshot['rate_plan'] : null;
		$settings = Flexo_Booking_Settings::all();
		$post     = get_post( $booking['room_id'] );
		$state    = $payment['state'];
		$contact  = self::contact();

		$next = array();
		if ( 'request' === $state ) {
			/* translators: %s: e.g. "within 24 hours" */
			$next[] = sprintf( __( 'We will check your request and confirm it by email %s.', 'flexo-booking' ), self::reply_time_text() );
			$next[] = __( 'Your booking is not confirmed until you receive our email.', 'flexo-booking' );
		} elseif ( 'awaiting_transfer' === $state ) {
			$next[] = __( 'Pay by bank transfer using the details below. Please use the payment reference, so we can find your payment.', 'flexo-booking' );
			$next[] = __( 'We confirm your booking by email as soon as the money arrives.', 'flexo-booking' );
		} elseif ( 'confirmed' === $state ) {
			/* translators: %s: email address */
			$next[] = sprintf( __( 'Your confirmation has been sent to %s.', 'flexo-booking' ), self::mask_email( $booking['guest_email'] ) );
			if ( $payment['at_property'] > 0 ) {
				/* translators: %s: amount */
				$next[] = sprintf( __( 'You pay %s at the property.', 'flexo-booking' ), $payment['at_property_formatted'] );
			}
		}
		if ( in_array( $state, array( 'request', 'awaiting_transfer', 'confirmed' ), true ) ) {
			/* translators: 1: check-in time, 2: check-out time */
			$next[] = sprintf( __( 'Check-in from %1$s, check-out until %2$s.', 'flexo-booking' ), $settings['check_in_time'], $settings['check_out_time'] );
		}

		$links = self::links( $booking );
		$view  = array(
			'reference'       => $booking['reference'],
			'status'          => $booking['status'],
			'state'           => $state,
			'state_label'     => self::state_label( $state ),
			'message'         => $payment['message'],
			'room'            => $booking['room_title'],
			'image'           => $post ? (string) get_the_post_thumbnail_url( $post, 'medium_large' ) : '',
			'rate'            => $plan ? array(
				'name'         => $plan['name'],
				'meals'        => Flexo_Booking_Rate_Plans::meals_label( isset( $plan['meals'] ) ? $plan['meals'] : '' ),
				'refundable'   => ! empty( $plan['refundable'] ),
				'cancellation' => '' !== (string) $plan['cancellation_policy'] ? $plan['cancellation_policy'] : Flexo_Booking_Rate_Plans::refundable_label( ! empty( $plan['refundable'] ) ),
			) : null,
			'check_in'        => $booking['check_in'],
			'check_out'       => $booking['check_out'],
			'check_in_text'   => Flexo_Booking_I18n::format_date( $booking['check_in'] ),
			'check_out_text'  => Flexo_Booking_I18n::format_date( $booking['check_out'] ),
			'nights'          => (int) $booking['nights'],
			/* translators: %d: number of nights */
			'nights_label'    => sprintf( _n( '%d night', '%d nights', (int) $booking['nights'], 'flexo-booking' ), (int) $booking['nights'] ),
			'guests'          => self::guests_text( $booking ),
			'quote'           => $quote,
			'total_formatted' => Flexo_Booking_Money::format( $booking['total'], $booking['currency'] ),
			'payment'         => $payment,
			'next_steps'      => $next,
			'contact'         => $contact,
			'ics'             => $links['ics'],
			'manage'          => $links['manage'],
		);
		return apply_filters( 'flexo_booking_guest_view', $view, $booking );
	}

	/**
	 * "j***@example.com" – shown on screen without revealing the address.
	 */
	public static function mask_email( $email ) {
		$parts = explode( '@', (string) $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		return mb_substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
	}

	/**
	 * The stay as a calendar event (.ics), all-day from arrival to departure.
	 */
	public static function ics( array $booking ) {
		$contact = self::contact();
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$settings = Flexo_Booking_Settings::all();
		$links   = self::links( $booking );
		$desc    = array(
			__( 'Booking reference', 'flexo-booking' ) . ': ' . $booking['reference'],
			$booking['room_title'] . ' · ' . self::guests_text( $booking ),
			/* translators: 1: check-in time, 2: check-out time */
			sprintf( __( 'Check-in from %1$s, check-out until %2$s.', 'flexo-booking' ), $settings['check_in_time'], $settings['check_out_time'] ),
		);
		if ( '' !== $contact['phone'] ) {
			$desc[] = __( 'Phone', 'flexo-booking' ) . ': ' . $contact['phone'];
		}
		if ( '' !== $contact['email'] ) {
			$desc[] = __( 'Email', 'flexo-booking' ) . ': ' . $contact['email'];
		}
		if ( '' !== $links['manage'] ) {
			$desc[] = __( 'Your booking', 'flexo-booking' ) . ': ' . $links['manage'];
		}
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//FlexoHotels//Flexo Booking ' . FLEXO_BOOKING_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:booking-' . $booking['reference'] . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART;VALUE=DATE:' . str_replace( '-', '', $booking['check_in'] ),
			'DTEND;VALUE=DATE:' . str_replace( '-', '', $booking['check_out'] ),
			/* translators: 1: hotel name, 2: room */
			'SUMMARY:' . Flexo_Booking_ICal::escape( sprintf( __( 'Stay at %1$s (%2$s)', 'flexo-booking' ), $contact['name'], $booking['room_title'] ) ),
			'DESCRIPTION:' . Flexo_Booking_ICal::escape( implode( "\n", $desc ) ),
		);
		if ( '' !== $contact['address'] ) {
			$lines[] = 'LOCATION:' . Flexo_Booking_ICal::escape( preg_replace( '/\s*\n\s*/', ', ', $contact['address'] ) );
		}
		$lines[] = 'STATUS:' . ( 'confirmed' === $booking['status'] ? 'CONFIRMED' : 'TENTATIVE' );
		$lines[] = 'TRANSP:TRANSPARENT';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( 'Flexo_Booking_ICal', 'fold' ), $lines ) ) . "\r\n";
	}

	/* ---------------------------------------------------------------------
	 * Date picker and nearby dates
	 * ------------------------------------------------------------------- */

	/**
	 * Rooms that fit the guests (all rooms, or one).
	 *
	 * @return array[]
	 */
	private static function fitting_rooms( $room, $adults, $children ) {
		$posts = $room ? array_filter( array( Flexo_Booking_Rooms::find( $room ) ) ) : Flexo_Booking_Rooms::bookable();
		$out   = array();
		foreach ( $posts as $post ) {
			if ( Flexo_Booking_Room_Content::is_demo( $post->ID ) ) {
				continue;
			}
			$data = Flexo_Booking_Rooms::to_array( $post );
			if ( $data['units'] > 0 && '' === Flexo_Booking_Bookings::capacity_error( $data, $adults, $children ) ) {
				$out[] = $data;
			}
		}
		return $out;
	}

	/**
	 * Per room and night: free ('1'), fully booked ('0') or closed ('c'),
	 * and the minimum stay for arrivals on each day.
	 *
	 * @return array[] { id, free: string, min: int[] }
	 */
	private static function nights_map( array $rooms, $from, $to ) {
		$dates    = Flexo_Booking_Dates::nights( $from, $to );
		$closures = Flexo_Booking_Closures::for_range( $from, $to );
		$map      = array();
		foreach ( $rooms as $room ) {
			$usage   = Flexo_Booking_Inventory::nightly_usage( $room, $from, $to );
			$seasons = Flexo_Booking_Seasons::enabled() ? Flexo_Booking_Seasons::for_stay( $room['id'], $from, $to ) : array();
			$free    = '';
			$min     = array();
			foreach ( $dates as $date ) {
				$closed = false;
				foreach ( $closures as $closure ) {
					if ( ( 0 === $closure['room_id'] || $room['id'] === $closure['room_id'] ) && $closure['date_from'] <= $date && $closure['date_to'] >= $date ) {
						$closed = true;
						break;
					}
				}
				$free  .= $closed ? 'c' : ( ( isset( $usage[ $date ] ) ? $usage[ $date ] : 0 ) < $room['units'] ? '1' : '0' );
				$season = $seasons ? Flexo_Booking_Seasons::season_for_night( $seasons, $date ) : null;
				$min[]  = $season && $season['min_nights'] ? (int) $season['min_nights'] : max( 1, (int) $room['min_nights'] );
			}
			$map[] = array(
				'id'   => $room['id'],
				'free' => $free,
				'min'  => $min,
			);
		}
		return $map;
	}

	/**
	 * Availability for the date picker: one or more months from `month`.
	 *
	 * @return array|WP_Error
	 */
	public static function calendar( $month, $months, $room, $adults, $children ) {
		$tz    = wp_timezone();
		$first = DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-01', $tz );
		if ( ! $first || $first->format( 'Y-m' ) !== $month ) {
			return new WP_Error( 'flexo_invalid_month', __( 'Please choose a valid month.', 'flexo-booking' ), array( 'status' => 400 ) );
		}
		$months   = min( 3, max( 1, (int) $months ) );
		$settings = Flexo_Booking_Settings::all();
		$today    = new DateTimeImmutable( 'today', $tz );
		$last     = $today->modify( '+' . (int) $settings['max_advance_days'] . ' days' );
		$from     = max( $first, $today );
		$end      = $first->modify( '+' . $months . ' months' );
		if ( $from >= $end || $first > $last ) {
			$from = $end;
		}
		// Look ahead far enough to check the longest minimum stay.
		$to    = $end->modify( '+' . min( 60, max( 1, (int) $settings['max_nights'] ) ) . ' days' );
		$rooms = self::fitting_rooms( $room, $adults, $children );
		$data  = array(
			'from'       => $from->format( 'Y-m-d' ),
			'to'         => $to->format( 'Y-m-d' ),
			'today'      => $today->format( 'Y-m-d' ),
			'last'       => $last->format( 'Y-m-d' ),
			'max_nights' => (int) $settings['max_nights'],
			'rooms'      => $from < $to ? self::nights_map( $rooms, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) ) : array(),
			'prices'     => array(),
		);
		if ( $settings['picker_prices'] && $rooms && $from < $end ) {
			$data['prices'] = self::nightly_prices( $rooms, $data['rooms'], $from->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), $adults, $children );
		}
		return $data;
	}

	/**
	 * Lowest price per night on free nights ("from" prices), cached for 30 minutes.
	 *
	 * @return array Y-m-d => formatted price.
	 */
	private static function nightly_prices( array $rooms, array $map, $from, $to, $adults, $children ) {
		$key    = 'flexo_picker_' . md5( wp_json_encode( array( wp_list_pluck( $rooms, 'id' ), $from, $to, $adults, $children, Flexo_Booking_Money::format( 1 ), get_option( Flexo_Booking_Settings::OPTION ) ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$prices = array();
		foreach ( Flexo_Booking_Dates::nights( $from, $to ) as $i => $date ) {
			$lowest = null;
			foreach ( $rooms as $r => $room ) {
				if ( '1' !== substr( $map[ $r ]['free'], $i, 1 ) ) {
					continue;
				}
				$plans = Flexo_Booking_Rate_Plans::for_room( $room['id'] );
				foreach ( $plans ? $plans : array( null ) as $plan ) {
					$quote = Flexo_Booking_Pricing::quote(
						array(
							'room'         => $room,
							'check_in'     => $date,
							'check_out'    => Flexo_Booking_Dates::add_days( $date, 1 ),
							'adults'       => $adults,
							'children'     => $children,
							'rate_plan_id' => $plan ? $plan['id'] : 0,
							'context'      => 'search',
						)
					);
					if ( ! is_wp_error( $quote ) ) {
						$amount = Flexo_Booking_Pricing::room_amount( $quote );
						$lowest = null === $lowest ? $amount : min( $lowest, $amount );
					}
				}
			}
			if ( null !== $lowest && $lowest > 0 ) {
				$prices[ $date ] = Flexo_Booking_Money::format( $lowest );
			}
		}
		set_transient( $key, $prices, 30 * MINUTE_IN_SECONDS );
		return $prices;
	}

	/**
	 * Up to 3 stays of the same length within 14 days of the dates asked
	 * for, and how many other rooms are free on the dates asked for.
	 *
	 * @return array|WP_Error
	 */
	public static function alternatives( $check_in, $check_out, $adults, $children, $ages, $room ) {
		$stay = Flexo_Booking_Bookings::validate_dates( $check_in, $check_out, false );
		if ( is_wp_error( $stay ) ) {
			$stay->add_data( array( 'status' => 400 ) );
			return $stay;
		}
		$nights   = $stay['nights'];
		$settings = Flexo_Booking_Settings::all();
		$tz       = wp_timezone();
		$today    = ( new DateTimeImmutable( 'today', $tz ) )->format( 'Y-m-d' );
		$last     = ( new DateTimeImmutable( 'today', $tz ) )->modify( '+' . (int) $settings['max_advance_days'] . ' days' )->format( 'Y-m-d' );
		$from     = max( $today, Flexo_Booking_Dates::add_days( $stay['check_in'], -14 ) );
		$to       = Flexo_Booking_Dates::add_days( $stay['check_out'], 14 );
		$rooms    = self::fitting_rooms( $room, $adults, $children );
		$map      = $rooms && $from < $to ? self::nights_map( $rooms, $from, $to ) : array();
		$base     = (int) ( new DateTimeImmutable( $from, $tz ) )->diff( new DateTimeImmutable( $stay['check_in'], $tz ) )->format( '%r%a' );

		$dates = array();
		for ( $step = 1; $step <= 14 && count( $dates ) < 3; $step++ ) {
			foreach ( array( $step, -$step ) as $offset ) {
				$arrival = Flexo_Booking_Dates::add_days( $stay['check_in'], $offset );
				$index   = $base + $offset;
				if ( $arrival < $today || $arrival > $last || $index < 0 ) {
					continue;
				}
				$fits = false;
				foreach ( $map as $r ) {
					if ( str_repeat( '1', $nights ) === substr( $r['free'], $index, $nights ) && $nights >= $r['min'][ $index ] ) {
						$fits = true;
						break;
					}
				}
				if ( ! $fits ) {
					continue;
				}
				$departure = Flexo_Booking_Dates::add_days( $arrival, $nights );
				$found     = Flexo_Booking_Bookings::search( $arrival, $departure, $adults, $children, $room, $ages );
				if ( is_wp_error( $found ) ) {
					continue;
				}
				$free = array_values( array_filter( $found['rooms'], static function ( $r ) {
					return $r['available'];
				} ) );
				if ( ! $free ) {
					continue;
				}
				$lowest  = min( wp_list_pluck( $free, 'total' ) );
				$dates[] = array(
					'check_in'  => $arrival,
					'check_out' => $departure,
					'label'     => Flexo_Booking_I18n::format_date( $arrival ) . ' – ' . Flexo_Booking_I18n::format_date( $departure ),
					'rooms'     => count( $free ),
					/* translators: %s: price */
					'from'      => sprintf( __( 'from %s', 'flexo-booking' ), Flexo_Booking_Money::format( $lowest ) ),
				);
				if ( count( $dates ) >= 3 ) {
					break;
				}
			}
		}
		usort(
			$dates,
			static function ( $a, $b ) {
				return strcmp( $a['check_in'], $b['check_in'] );
			}
		);

		$other = 0;
		if ( $room ) {
			$all = Flexo_Booking_Bookings::search( $stay['check_in'], $stay['check_out'], $adults, $children, '', $ages );
			if ( ! is_wp_error( $all ) ) {
				$selected = Flexo_Booking_Rooms::find( $room );
				foreach ( $all['rooms'] as $r ) {
					$other += $r['available'] && ( ! $selected || $r['id'] !== $selected->ID ) ? 1 : 0;
				}
			}
		}
		return array(
			'dates'       => $dates,
			'other_rooms' => $other,
		);
	}

	/* ---------------------------------------------------------------------
	 * Enquiries and guest requests
	 * ------------------------------------------------------------------- */

	/**
	 * Too many messages from one visitor in an hour.
	 */
	private static function too_many( $kind, $limit ) {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'flexo_rl_' . $kind . '_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * A question from the booking form when nothing fits. It is emailed to
	 * the hotel and kept in the booking history; it is not a booking.
	 *
	 * @return array|WP_Error
	 */
	public static function enquiry( array $data ) {
		if ( '' !== trim( (string) ( isset( $data['fb_website'] ) ? $data['fb_website'] : '' ) ) ) {
			return new WP_Error( 'flexo_spam', __( 'Your message could not be sent.', 'flexo-booking' ), array( 'status' => 400 ) );
		}
		$name    = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		$email   = sanitize_email( isset( $data['email'] ) ? $data['email'] : '' );
		$phone   = Flexo_Booking_Phone::normalize( sanitize_text_field( isset( $data['phone'] ) ? $data['phone'] : '' ), isset( $data['phone_country'] ) ? $data['phone_country'] : '' );
		$message = mb_substr( sanitize_textarea_field( isset( $data['message'] ) ? $data['message'] : '' ), 0, 2000 );
		if ( '' === $name ) {
			return new WP_Error( 'flexo_missing_name', __( 'Please enter your name.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'name' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'flexo_invalid_email', __( 'Please enter your email address, for example name@example.com.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'email' ) );
		}
		if ( '' !== $phone && ! Flexo_Booking_Phone::looks_valid( $phone ) ) {
			return new WP_Error( 'flexo_invalid_phone', __( 'Please enter a phone number we can reach you on, for example +359 888 123 456.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'phone' ) );
		}
		if ( Flexo_Booking_Privacy::enabled() && Flexo_Booking_Privacy::consent_required() && ( empty( $data['privacy_consent'] ) || 'false' === $data['privacy_consent'] ) ) {
			return new WP_Error( 'flexo_consent', __( 'Please accept the privacy policy to send your message.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'privacy_consent' ) );
		}
		if ( self::too_many( 'enquiry', (int) apply_filters( 'flexo_booking_enquiry_limit', 5 ) ) ) {
			return new WP_Error( 'flexo_rate_limited', __( 'You have sent several messages already. Please wait a little or contact us by phone or email.', 'flexo-booking' ), array( 'status' => 429 ) );
		}

		$in     = Flexo_Booking_Dates::parse( isset( $data['check_in'] ) ? $data['check_in'] : '' );
		$out    = Flexo_Booking_Dates::parse( isset( $data['check_out'] ) ? $data['check_out'] : '' );
		$room   = ! empty( $data['room'] ) ? Flexo_Booking_Rooms::find( $data['room'] ) : null;
		$locale = Flexo_Booking_I18n::sanitize_locale( isset( $data['locale'] ) ? $data['locale'] : '' );
		$details = array(
			'name'      => $name,
			'email'     => $email,
			'phone'     => $phone,
			'message'   => $message,
			'check_in'  => $in ? $in : '',
			'check_out' => $out && $in && $out > $in ? $out : '',
			'adults'    => max( 1, (int) ( isset( $data['adults'] ) ? $data['adults'] : 1 ) ),
			'children'  => max( 0, (int) ( isset( $data['children'] ) ? $data['children'] : 0 ) ),
			'room'      => $room ? get_the_title( $room ) : '',
			'locale'    => $locale,
			'status'    => 'open',
		);
		$id = Flexo_Booking_Log::add( 0, 'enquiry', $details, 0 );

		// The email to the hotel is in the site's language.
		$sent = Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $details ) {
				$stay = '' !== $details['check_in'] && '' !== $details['check_out']
					? Flexo_Booking_I18n::format_date( $details['check_in'] ) . ' – ' . Flexo_Booking_I18n::format_date( $details['check_out'] )
					: __( 'no dates given', 'flexo-booking' );
				$lines = array(
					__( 'A guest sent an enquiry from the booking form. This is not a booking – reply to this email to answer.', 'flexo-booking' ),
					'',
					__( 'Name', 'flexo-booking' ) . ': ' . $details['name'],
					__( 'Email', 'flexo-booking' ) . ': ' . $details['email'],
					__( 'Phone', 'flexo-booking' ) . ': ' . ( '' !== $details['phone'] ? $details['phone'] : '–' ),
					__( 'Dates', 'flexo-booking' ) . ': ' . $stay,
					__( 'Guests', 'flexo-booking' ) . ': ' . self::guests_text( $details ),
				);
				if ( '' !== $details['room'] ) {
					$lines[] = __( 'Room', 'flexo-booking' ) . ': ' . $details['room'];
				}
				if ( '' !== $details['locale'] ) {
					$lines[] = __( 'Language', 'flexo-booking' ) . ': ' . Flexo_Booking_I18n::language_name( $details['locale'] );
				}
				$lines[] = '';
				$lines[] = __( 'Message', 'flexo-booking' ) . ':';
				$lines[] = '' !== $details['message'] ? $details['message'] : '–';
				return Flexo_Booking_Emails::send(
					Flexo_Booking_Settings::notification_emails(),
					/* translators: 1: guest name, 2: dates */
					sprintf( __( 'Enquiry from %1$s (%2$s)', 'flexo-booking' ), $details['name'], $stay ),
					implode( "\n", $lines ),
					array(
						'headers' => array( 'Reply-To: ' . $details['name'] . ' <' . $details['email'] . '>' ),
						'type'    => 'hotel_enquiry',
					)
				);
			}
		);
		do_action( 'flexo_booking_enquiry', $details, $id );

		return array(
			'sent'    => (bool) $sent,
			/* translators: 1: hotel name, 2: e.g. "within 24 hours" */
			'message' => sprintf( __( 'Thank you! Your message has been sent to %1$s. We will reply by email %2$s.', 'flexo-booking' ), self::contact()['name'], self::reply_time_text() ),
		);
	}

	/**
	 * Request types a guest can send from the guest booking page.
	 */
	public static function request_types() {
		return array(
			'cancel' => __( 'Cancel my booking', 'flexo-booking' ),
			'change' => __( 'Change my booking', 'flexo-booking' ),
		);
	}

	/**
	 * Whether the guest can still send requests about this booking.
	 */
	public static function can_request( array $booking ) {
		return self::manage_enabled()
			&& in_array( $booking['status'], array( 'pending', 'confirmed', 'awaiting_payment' ), true )
			&& $booking['check_out'] >= current_time( 'Y-m-d' );
	}

	/**
	 * Requests the guest sent about a booking, oldest first.
	 */
	public static function requests( array $booking ) {
		$out = array();
		foreach ( array_reverse( Flexo_Booking_Log::for_booking( $booking['id'] ) ) as $entry ) {
			if ( 'guest_request' === $entry['action'] && empty( $entry['details']['erased'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * A cancellation or change request from the guest booking page: logged
	 * on the booking and emailed to the hotel. The booking itself is not changed.
	 *
	 * @return array|WP_Error
	 */
	public static function send_request( array $booking, $type, $message ) {
		if ( ! self::can_request( $booking ) ) {
			return new WP_Error( 'flexo_request_closed', __( 'Requests can no longer be sent for this booking. Please contact us by phone or email.', 'flexo-booking' ), array( 'status' => 400 ) );
		}
		$types = self::request_types();
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'flexo_request_type', __( 'Please choose what you would like to do.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'type' ) );
		}
		$message = mb_substr( sanitize_textarea_field( (string) $message ), 0, 2000 );
		if ( 'change' === $type && '' === trim( $message ) ) {
			return new WP_Error( 'flexo_request_message', __( 'Please tell us what you would like to change.', 'flexo-booking' ), array( 'status' => 400, 'field' => 'message' ) );
		}
		if ( Flexo_Booking_Log::count_since( $booking['id'], 'guest_request', wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) >= self::REQUEST_LIMIT || self::too_many( 'request', 10 ) ) {
			return new WP_Error( 'flexo_rate_limited', __( 'You have sent several requests already. We will reply to them – for anything urgent, please call us.', 'flexo-booking' ), array( 'status' => 429 ) );
		}

		Flexo_Booking_Log::add(
			$booking['id'],
			'guest_request',
			array(
				'type'    => $type,
				'message' => $message,
				'status'  => 'open',
			),
			0
		);

		Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking, $type, $message ) {
				$labels = array(
					'cancel' => __( 'Cancellation request', 'flexo-booking' ),
					'change' => __( 'Change request', 'flexo-booking' ),
				);
				$lines  = array(
					__( 'A guest sent a request from their booking page. Nothing has been changed – please reply to the guest and update the booking yourself if needed.', 'flexo-booking' ),
					'',
					__( 'Booking', 'flexo-booking' ) . ': ' . $booking['reference'] . ' · ' . $booking['room_title'] . ' · ' . Flexo_Booking_I18n::format_date( $booking['check_in'] ) . ' – ' . Flexo_Booking_I18n::format_date( $booking['check_out'] ),
					__( 'Guest', 'flexo-booking' ) . ': ' . $booking['guest_name'] . ' · ' . $booking['guest_email'] . ( '' !== $booking['guest_phone'] ? ' · ' . $booking['guest_phone'] : '' ),
					__( 'Request', 'flexo-booking' ) . ': ' . $labels[ $type ],
					__( 'Message', 'flexo-booking' ) . ': ' . ( '' !== $message ? $message : '–' ),
					'',
					__( 'Open the booking', 'flexo-booking' ) . ': ' . admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $booking['id'] ),
				);
				Flexo_Booking_Emails::send(
					Flexo_Booking_Settings::notification_emails(),
					/* translators: 1: request type, 2: booking reference */
					sprintf( __( '%1$s for booking %2$s', 'flexo-booking' ), $labels[ $type ], $booking['reference'] ),
					implode( "\n", $lines ),
					array(
						'headers'    => array( 'Reply-To: ' . $booking['guest_name'] . ' <' . $booking['guest_email'] . '>' ),
						'type'       => 'hotel_guest_request',
						'booking_id' => (int) $booking['id'],
					)
				);
			}
		);
		do_action( 'flexo_booking_guest_request', $booking, $type, $message );

		return array(
			/* translators: %s: e.g. "within 24 hours" */
			'message' => sprintf( __( 'Your request has been sent. We will reply by email %s. Until then your booking stays as it is.', 'flexo-booking' ), self::reply_time_text() ),
		);
	}

	/**
	 * The guest booking page's data: the booking plus requests sent.
	 */
	public static function manage_view( array $booking ) {
		$view             = self::view( $booking );
		$view['requests'] = array();
		foreach ( self::requests( $booking ) as $entry ) {
			$types              = self::request_types();
			$view['requests'][] = array(
				'type'    => isset( $types[ $entry['details']['type'] ] ) ? $types[ $entry['details']['type'] ] : '',
				'date'    => Flexo_Booking_I18n::format_date( substr( $entry['created_at'], 0, 10 ) ) . ' ' . substr( $entry['created_at'], 11, 5 ),
				'message' => isset( $entry['details']['message'] ) ? $entry['details']['message'] : '',
				'handled' => isset( $entry['details']['status'] ) && 'handled' === $entry['details']['status'],
			);
		}
		$view['can_request']   = self::can_request( $booking );
		$view['request_types'] = self::request_types();
		return $view;
	}

	/* ---------------------------------------------------------------------
	 * REST
	 * ------------------------------------------------------------------- */

	public static function register_routes() {
		$ns       = Flexo_Booking_Rest::NAMESPACE_V1;
		$key_args = array(
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
		$guests   = array(
			'adults'   => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'children' => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
			),
			'room'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'locale'   => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		register_rest_route(
			$ns,
			'/guest-booking',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$key_args,
					array(
						'manage' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/guest-booking/request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_request' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$key_args,
					array(
						'type'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'message' => array(
							'type'    => 'string',
							'default' => '',
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/booking.ics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_ics' ),
				'permission_callback' => '__return_true',
				'args'                => $key_args,
			)
		);
		register_rest_route(
			$ns,
			'/calendar',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_calendar' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$guests,
					array(
						'month'  => array(
							'type'     => 'string',
							'required' => true,
							'pattern'  => '^\d{4}-\d{2}$',
						),
						'months' => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
							'maximum' => 3,
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/alternatives',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_alternatives' ),
				'permission_callback' => '__return_true',
				'args'                => array_merge(
					$guests,
					array(
						'check_in'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'check_out'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'children_ages' => array(
							'type'    => array( 'string', 'array' ),
							'default' => '',
						),
					)
				),
			)
		);
		register_rest_route(
			$ns,
			'/enquiry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_enquiry' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function use_locale( WP_REST_Request $request ) {
		$locale = Flexo_Booking_I18n::sanitize_locale( $request['locale'] );
		if ( '' !== $locale && $locale !== determine_locale() ) {
			switch_to_locale( $locale );
		}
	}

	private static function no_store( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	public static function rest_booking( WP_REST_Request $request ) {
		self::use_locale( $request );
		$booking = self::find( $request['reference'], $request['key'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( $request['manage'] && ! self::manage_enabled() ) {
			return new WP_Error( 'flexo_not_found', __( 'This page is not available. Please contact us by phone or email.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		return self::no_store( $request['manage'] ? self::manage_view( $booking ) : self::view( $booking ) );
	}

	public static function rest_request( WP_REST_Request $request ) {
		self::use_locale( $request );
		if ( ! self::manage_enabled() ) {
			return new WP_Error( 'flexo_not_found', __( 'This page is not available. Please contact us by phone or email.', 'flexo-booking' ), array( 'status' => 404 ) );
		}
		$booking = self::find( $request['reference'], $request['key'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$result = self::send_request( $booking, sanitize_key( $request['type'] ), $request['message'] );
		return is_wp_error( $result ) ? $result : self::no_store( $result );
	}

	public static function rest_ics( WP_REST_Request $request ) {
		self::use_locale( $request );
		$booking = self::find( $request['reference'], $request['key'] );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$response = new WP_REST_Response( self::ics( $booking ), 200 );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="booking-' . sanitize_file_name( $booking['reference'] ) . '.ics"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * Sends the .ics as a file instead of JSON.
	 */
	public static function serve_ics( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof WP_REST_Request || '/' . Flexo_Booking_Rest::NAMESPACE_V1 . '/booking.ics' !== $request->get_route() ) {
			return $served;
		}
		if ( $result instanceof WP_REST_Response && 200 === $result->get_status() && is_string( $result->get_data() ) ) {
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar text, escaped in ics().
			return true;
		}
		return $served;
	}

	public static function rest_calendar( WP_REST_Request $request ) {
		self::use_locale( $request );
		$result = self::calendar( $request['month'], $request['months'], sanitize_title( $request['room'] ), (int) $request['adults'], (int) $request['children'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_alternatives( WP_REST_Request $request ) {
		self::use_locale( $request );
		$result = self::alternatives( $request['check_in'], $request['check_out'], (int) $request['adults'], (int) $request['children'], $request['children_ages'], sanitize_title( $request['room'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function rest_enquiry( WP_REST_Request $request ) {
		self::use_locale( $request );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$result = self::enquiry( is_array( $params ) ? $params : array() );
		return is_wp_error( $result ) ? $result : self::no_store( $result );
	}
}
