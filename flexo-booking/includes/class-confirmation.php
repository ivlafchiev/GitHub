<?php
/**
 * The Thank You page (1.9.0): which booking it may show, and how.
 *
 * Security model:
 * - The booking form gets a one-time hand-off link (/thank-you/?fb_t=…):
 *   32 random bytes, valid for 10 minutes, usable once, created only in
 *   the response to the guest who just booked (or paid).
 * - On arrival the token is exchanged for a short-lived cookie
 *   (HttpOnly, Secure on HTTPS, SameSite=Lax, only for the Thank You
 *   path, 60 minutes, signed with the site's secret keys) and the guest is
 *   sent on to the clean address – no token in history, logs or referrers.
 * - Without a valid cookie the page shows a general thank-you text and no
 *   booking data. A booking number, room or email in the address never
 *   shows anything. The guest's long-term way back is the "Manage your
 *   booking" link in the confirmation email.
 * - The page shows the state the booking and payment engine stored; it
 *   never changes a booking.
 *
 * One renderer serves the built-in page (and, in Session C, the shortcode
 * and the Elementor widget for the hotel's own Thank You page).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Confirmation {

	const COOKIE = 'flexo_booking_ty';

	/**
	 * How long the Thank You page shows the booking (seconds).
	 */
	const CONTEXT_TTL = HOUR_IN_SECONDS;

	/**
	 * How long the hand-off link works (seconds).
	 */
	const TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Query parameters that carry a guest's access to a booking.
	 */
	const KEY_PARAMS = array( 'fb_key', 'fb_t', 'fb_manage', 'fb_done', 'fb_payment' );

	/**
	 * @var array|null|false Booking of this request (false: not looked up yet).
	 */
	private static $booking = false;

	/* ---------------------------------------------------------------------
	 * Where guests go after booking
	 * ------------------------------------------------------------------- */

	/**
	 * Whether bookings end on a separate Thank You page.
	 */
	public static function separate() {
		$all = Flexo_Booking_System_Pages::all();
		return 'separate' === $all['after_booking'] && '' !== Flexo_Booking_System_Pages::url( 'thank_you' );
	}

	/**
	 * The address to send a guest to after booking or paying ('' = show the
	 * confirmation in the booking form).
	 *
	 * @param array $booking
	 * @param bool  $payment_started Payment details were just given (bank transfer): on the
	 *                               hotel's own page they stay in the form, as before 1.9.0.
	 */
	public static function redirect_url( array $booking, $payment_started = false ) {
		$all = Flexo_Booking_System_Pages::all();
		if ( 'separate' !== $all['after_booking'] ) {
			return '';
		}
		$url = Flexo_Booking_System_Pages::url( 'thank_you', $booking['locale'] );
		if ( '' === $url ) {
			return '';
		}
		$own = '' !== Flexo_Booking_System_Pages::own_page_url( 'thank_you' );
		if ( $own ) {
			// The hotel's own page: as before 1.9.0, bank details stay in the form.
			if ( $payment_started ) {
				return '';
			}
			$url = add_query_arg( 'booking', rawurlencode( $booking['reference'] ), $url );
		}
		return add_query_arg( 'fb_t', self::create_token( $booking ), $url );
	}

	/**
	 * A one-time hand-off token for a booking.
	 */
	public static function create_token( array $booking ) {
		$token = bin2hex( random_bytes( 32 ) );
		set_transient( self::token_key( $token ), (int) $booking['id'], self::TOKEN_TTL );
		return $token;
	}

	private static function token_key( $token ) {
		return 'flexo_ty_' . substr( hash( 'sha256', (string) $token ), 0, 40 );
	}

	/**
	 * The booking ID of a token, once (0 when unknown, used or expired).
	 */
	public static function consume_token( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return 0;
		}
		$key = self::token_key( $token );
		$id  = (int) get_transient( $key );
		delete_transient( $key );
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Arriving on the Thank You page
	 * ------------------------------------------------------------------- */

	/**
	 * Built-in page: a hand-off token in the address becomes the cookie,
	 * then the guest continues to the clean address (303).
	 */
	public static function maybe_arrive() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
		if ( ! isset( $_GET['fb_t'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = self::consume_token( sanitize_text_field( wp_unslash( $_GET['fb_t'] ) ) );
		Flexo_Booking_Page_Cache::protect( 'thank_you' );
		$clean = self::clean_url();
		if ( $id ) {
			self::set_cookie( $id, (string) wp_parse_url( $clean, PHP_URL_PATH ) );
		}
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		wp_safe_redirect( $clean, 303 );
		exit;
	}

	/**
	 * The hotel's own Thank You page: the same hand-off, and never cached.
	 */
	public static function maybe_arrive_on_own_page() {
		$own = Flexo_Booking_System_Pages::own_page_url( 'thank_you' );
		if ( '' === $own || ! is_singular() ) {
			return;
		}
		$own_path = trim( (string) wp_parse_url( $own, PHP_URL_PATH ), '/' );
		$here     = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
		$page     = Flexo_Booking_System_Pages::get( 'thank_you' );
		if ( $own_path !== $here && ! ( $page['page_id'] && get_queried_object_id() === (int) $page['page_id'] ) ) {
			return;
		}
		Flexo_Booking_Page_Cache::protect( 'thank_you' );
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		add_filter(
			'wp_robots',
			static function ( $robots ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				return $robots;
			}
		);
		self::maybe_arrive();
	}

	/**
	 * Any page opened with a guest key in its address (confirmation,
	 * manage booking, card payment return): never cached, no referrer.
	 */
	public static function maybe_protect_key_request() {
		if ( ! self::has_key_in_request() ) {
			return;
		}
		Flexo_Booking_Page_Cache::protect( 'guest_link' );
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	public static function has_key_in_request() {
		foreach ( self::KEY_PARAMS as $param ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
			if ( isset( $_GET[ $param ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * This address without the token.
	 */
	private static function clean_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$url    = remove_query_arg( 'fb_t', $scheme . $host . $uri );
		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	/* ---------------------------------------------------------------------
	 * The booking context (cookie)
	 * ------------------------------------------------------------------- */

	private static function sign( $id, $expires ) {
		return substr( hash_hmac( 'sha256', 'thank-you|' . (int) $id . '|' . (int) $expires, wp_salt( 'auth' ) ), 0, 40 );
	}

	public static function cookie_value( $id, $expires ) {
		return (int) $id . '.' . (int) $expires . '.' . self::sign( $id, $expires );
	}

	private static function set_cookie( $id, $path ) {
		$expires = time() + (int) apply_filters( 'flexo_booking_thank_you_ttl', self::CONTEXT_TTL );
		$value   = self::cookie_value( $id, $expires );
		$path    = '' !== $path ? $path : '/';
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => $expires,
					'path'     => $path,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $value;
		self::$booking           = false;
	}

	/**
	 * The booking ID in a cookie value (0 when invalid or expired).
	 */
	public static function parse_cookie( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{40})$/', $value, $m ) ) {
			return 0;
		}
		if ( (int) $m[2] < time() || ! hash_equals( self::sign( $m[1], $m[2] ), $m[3] ) ) {
			return 0;
		}
		return (int) $m[1];
	}

	/**
	 * The booking the visitor may see on the Thank You page, or null.
	 *
	 * @return array|null
	 */
	public static function booking() {
		if ( false !== self::$booking ) {
			return self::$booking;
		}
		self::$booking = null;
		$id            = isset( $_COOKIE[ self::COOKIE ] ) ? self::parse_cookie( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : 0;
		if ( $id ) {
			$booking = Flexo_Booking_Bookings::get( $id );
			// Bookings whose personal data was removed are never shown.
			if ( $booking && empty( $booking['anonymized_at'] ) ) {
				self::$booking = $booking;
			}
		}
		return self::$booking;
	}

	/**
	 * Forgets the looked-up booking (tests, a new cookie).
	 */
	public static function reset() {
		self::$booking = false;
	}

	/* ---------------------------------------------------------------------
	 * What the page shows
	 * ------------------------------------------------------------------- */

	/**
	 * Everything the Thank You page shows for a booking: the guest view
	 * (Flexo_Booking_Guest::view(), the same data as the confirmation in
	 * the form) plus the state used for headings.
	 */
	public static function view( array $booking ) {
		$view = Flexo_Booking_Guest::view( $booking );
		$pay  = $view['payment'];
		if ( 'confirmed' === $view['state'] ) {
			$view['kind'] = $pay['paid'] > 0 ? 'paid' : 'instant';
		} elseif ( 'request' === $view['state'] ) {
			$view['kind'] = 'request';
		} elseif ( 'awaiting_transfer' === $view['state'] ) {
			$view['kind'] = 'transfer';
		} elseif ( in_array( $view['state'], array( 'held', 'processing' ), true ) ) {
			// The card payment is not confirmed yet (webhook pending).
			$view['kind'] = 'pending';
		} else {
			$view['kind'] = 'problem';
		}
		if ( in_array( $view['state'], array( 'cancelled', 'expired', 'failed' ), true ) ) {
			$view['manage'] = '';
		}
		return $view;
	}

	/**
	 * The main heading for the current visitor.
	 */
	public static function heading( $view = null ) {
		if ( null === $view ) {
			$booking = self::booking();
			$view    = $booking ? self::view( $booking ) : null;
		}
		if ( ! $view ) {
			return Flexo_Booking_System_Pages::text( 'thank_you', 'generic_title' );
		}
		$names = array(
			'request'  => 'heading_request',
			'instant'  => 'heading_instant',
			'paid'     => 'heading_paid',
			'transfer' => 'heading_transfer',
			'pending'  => 'heading_pending',
		);
		if ( isset( $names[ $view['kind'] ] ) ) {
			return self::fill( Flexo_Booking_System_Pages::text( 'thank_you', $names[ $view['kind'] ] ), $view );
		}
		return '' !== $view['state_label'] ? $view['state_label'] : __( 'Your booking', 'flexo-booking' );
	}

	/**
	 * Placeholders in the hotel's texts: {hotel_name}, {reference},
	 * {check_in}, {check_out}. Values are plain text.
	 */
	public static function fill( $text, $view = null ) {
		$values = array(
			'{hotel_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{reference}'  => $view ? $view['reference'] : '',
			'{check_in}'   => $view ? $view['check_in_text'] : '',
			'{check_out}'  => $view ? $view['check_out_text'] : '',
		);
		return strtr( (string) $text, $values );
	}

	/**
	 * Same, for texts with simple formatting (the values are escaped).
	 */
	private static function fill_html( $text, $view = null ) {
		$values = array(
			'{hotel_name}' => esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'{reference}'  => $view ? esc_html( $view['reference'] ) : '',
			'{check_in}'   => $view ? esc_html( $view['check_in_text'] ) : '',
			'{check_out}'  => $view ? esc_html( $view['check_out_text'] ) : '',
		);
		return wp_kses_post( strtr( (string) $text, $values ) );
	}

	/**
	 * Sections of the stay summary (the rest are blocks and actions).
	 */
	private static function group( $section ) {
		if ( in_array( $section, array( 'bank', 'next', 'contact', 'address' ), true ) ) {
			return 'info';
		}
		if ( in_array( $section, array( 'calendar', 'directions', 'manage', 'back' ), true ) ) {
			return 'actions';
		}
		return 'stay';
	}

	/**
	 * The sections to show, in the hotel's order.
	 *
	 * @param array $overrides Optional { order, hidden } (widgets).
	 */
	public static function sections( array $overrides = array() ) {
		$page   = Flexo_Booking_System_Pages::get( 'thank_you' );
		$order  = isset( $overrides['order'] ) ? (array) $overrides['order'] : $page['order'];
		$hidden = isset( $overrides['hidden'] ) ? (array) $overrides['hidden'] : $page['hidden'];
		return array_values( array_diff( array_intersect( $order, Flexo_Booking_System_Pages::SECTIONS ), $hidden ) );
	}

	/* ---------------------------------------------------------------------
	 * Preview for the hotel (sample data, never a real booking)
	 * ------------------------------------------------------------------- */

	public static function preview_kinds() {
		return array(
			'request'  => __( 'Booking request', 'flexo-booking' ),
			'instant'  => __( 'Confirmed booking', 'flexo-booking' ),
			'paid'     => __( 'Paid by card', 'flexo-booking' ),
			'transfer' => __( 'Bank transfer', 'flexo-booking' ),
			'pending'  => __( 'Payment being confirmed', 'flexo-booking' ),
			'generic'  => __( 'Without booking details', 'flexo-booking' ),
		);
	}

	public static function preview_url( $kind ) {
		return add_query_arg(
			array(
				'flexo_preview' => $kind,
				'_wpnonce'      => wp_create_nonce( 'flexo_preview' ),
			),
			Flexo_Booking_System_Pages::builtin_url( 'thank_you' )
		);
	}

	/**
	 * The sample state asked for by a logged-in administrator ('' otherwise).
	 */
	public static function preview_kind() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET['flexo_preview'], $_GET['_wpnonce'] ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'flexo_preview' ) ) {
			return '';
		}
		$kind = sanitize_key( wp_unslash( $_GET['flexo_preview'] ) );
		// phpcs:enable
		return isset( self::preview_kinds()[ $kind ] ) ? $kind : '';
	}

	/**
	 * A made-up booking in the given state (the hotel's preview).
	 */
	public static function sample_view( $kind ) {
		$rooms    = Flexo_Booking_Rooms::all();
		$room     = $rooms ? get_the_title( $rooms[0] ) : __( 'Double room', 'flexo-booking' );
		$in       = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$out      = gmdate( 'Y-m-d', strtotime( '+33 days' ) );
		$settings = Flexo_Booking_Settings::all();
		$total    = 360.0;
		$paid     = 'paid' === $kind ? 108.0 : 0.0;
		$states   = array(
			'request'  => 'request',
			'instant'  => 'confirmed',
			'paid'     => 'confirmed',
			'transfer' => 'awaiting_transfer',
			'pending'  => 'processing',
		);
		$state    = $states[ $kind ];
		$reference = 'FB-SAMPLE';
		$bank      = null;
		if ( 'transfer' === $kind ) {
			$bank = array(
				'rows' => array(
					array( 'key' => 'amount', 'label' => __( 'Amount', 'flexo-booking' ), 'value' => Flexo_Booking_Money::format( $total ) ),
					array( 'key' => 'beneficiary', 'label' => __( 'Beneficiary', 'flexo-booking' ), 'value' => '' !== $settings['bank_beneficiary'] ? $settings['bank_beneficiary'] : get_bloginfo( 'name' ) ),
					array( 'key' => 'iban', 'label' => 'IBAN', 'value' => '' !== $settings['bank_iban'] ? $settings['bank_iban'] : 'BG80 BNBG 9661 1020 3456 78' ),
					array( 'key' => 'reference', 'label' => __( 'Payment reference', 'flexo-booking' ), 'value' => $reference ),
					array( 'key' => 'deadline', 'label' => __( 'Pay by', 'flexo-booking' ), 'value' => Flexo_Booking_I18n::format_date( gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ) ),
				),
				'note' => __( 'Please enter the payment reference exactly as shown, so we can match your payment.', 'flexo-booking' ),
			);
		}
		$messages = array(
			'request'  => __( 'Thank you! We received your booking request and will confirm it shortly by email.', 'flexo-booking' ),
			'instant'  => __( 'Your booking is confirmed! A confirmation has been sent to your email.', 'flexo-booking' ),
			'paid'     => __( 'Thank you, we received your payment. Your booking is confirmed and a confirmation has been sent to your email.', 'flexo-booking' ),
			/* translators: 1: amount, 2: date */
			'transfer' => sprintf( __( 'Your booking is reserved. To confirm it, please pay %1$s by bank transfer by %2$s. The payment details are below and in your email.', 'flexo-booking' ), Flexo_Booking_Money::format( $total ), Flexo_Booking_I18n::format_date( gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ) ),
			'pending'  => __( 'Your payment is being processed. You will receive an email as soon as it is confirmed.', 'flexo-booking' ),
		);
		$fake = array(
			'guest_email' => 'guest@example.com',
		);
		$next = array();
		if ( 'request' === $kind ) {
			/* translators: %s: e.g. "within 24 hours" */
			$next[] = sprintf( __( 'We will check your request and confirm it by email %s.', 'flexo-booking' ), Flexo_Booking_Guest::reply_time_text() );
		} elseif ( 'transfer' === $kind ) {
			$next[] = __( 'We confirm your booking by email as soon as the money arrives.', 'flexo-booking' );
		} else {
			/* translators: %s: email address */
			$next[] = sprintf( __( 'Your confirmation has been sent to %s.', 'flexo-booking' ), Flexo_Booking_Guest::mask_email( $fake['guest_email'] ) );
		}
		/* translators: 1: check-in time, 2: check-out time */
		$next[] = sprintf( __( 'Check-in from %1$s, check-out until %2$s.', 'flexo-booking' ), $settings['check_in_time'], $settings['check_out_time'] );
		return array(
			'reference'      => $reference,
			'status'         => 'confirmed',
			'state'          => $state,
			'state_label'    => Flexo_Booking_Guest::state_label( $state ),
			'message'        => $messages[ $kind ],
			'room'           => $room,
			'image'          => $rooms ? (string) get_the_post_thumbnail_url( $rooms[0], 'medium_large' ) : '',
			'rate'           => null,
			'check_in'       => $in,
			'check_out'      => $out,
			'check_in_text'  => Flexo_Booking_I18n::format_date( $in ),
			'check_out_text' => Flexo_Booking_I18n::format_date( $out ),
			'nights'         => 3,
			/* translators: %d: number of nights */
			'nights_label'   => sprintf( _n( '%d night', '%d nights', 3, 'flexo-booking' ), 3 ),
			'guests'         => sprintf( _n( '%d adult', '%d adults', 2, 'flexo-booking' ), 2 ),
			'quote'          => array(
				'lines'              => array(),
				'discount_formatted' => '',
			),
			'total_formatted' => Flexo_Booking_Money::format( $total ),
			'payment'        => array(
				'method'                => in_array( $kind, array( 'paid', 'pending' ), true ) ? 'stripe' : ( 'transfer' === $kind ? 'bank_transfer' : '' ),
				'paid'                  => $paid,
				'paid_formatted'        => $paid > 0 ? Flexo_Booking_Money::format( $paid ) : '',
				'at_property'           => 'paid' === $kind ? $total - $paid : ( 'instant' === $kind ? $total : 0 ),
				'at_property_formatted' => 'paid' === $kind ? Flexo_Booking_Money::format( $total - $paid ) : ( 'instant' === $kind ? Flexo_Booking_Money::format( $total ) : '' ),
				'instructions'          => $bank,
			),
			'next_steps'     => $next,
			'contact'        => Flexo_Booking_Guest::contact(),
			'ics'            => '#',
			'manage'         => Flexo_Booking_Guest::manage_enabled() ? '#' : '',
			'kind'           => $kind,
		);
	}

	/**
	 * The Thank You content: the booking of the cookie, else the general
	 * thank-you text.
	 *
	 * @param array $args { layout, order, hidden } – the page's settings by default.
	 */
	public static function render( array $args = array() ) {
		$page   = Flexo_Booking_System_Pages::get( 'thank_you' );
		$layout = isset( $args['layout'] ) && isset( Flexo_Booking_System_Pages::thank_you_layouts()[ $args['layout'] ] ) ? $args['layout'] : $page['layout'];
		$preview = 'thank_you' === Flexo_Booking_System_Pages::current() ? self::preview_kind() : '';
		if ( '' !== $preview ) {
			$view = 'generic' === $preview ? null : self::sample_view( $preview );
		} else {
			$booking = self::booking();
			$view    = $booking ? self::view( $booking ) : null;
		}
		$vars    = array(
			'layout'   => $layout,
			'view'     => $view,
			'heading'  => self::heading( $view ),
			'lead'     => self::lead( $view ),
			'blocks'   => self::blocks( $view, self::sections( $args ), 'split' === $layout ),
			'pending'  => $view && 'pending' === $view['kind'],
			'icon'     => $view ? $view['kind'] : 'generic',
		);
		ob_start();
		if ( '' !== $preview ) {
			echo self::preview_bar( $preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in preview_bar().
		}
		Flexo_Booking_Frontend::load_template( 'confirmation.php', $vars );
		return ob_get_clean();
	}

	/**
	 * "Preview with a sample booking" and the states to switch between
	 * (only the hotel sees it).
	 */
	private static function preview_bar( $current ) {
		$html = '<nav class="flexo-preview-bar" aria-label="' . esc_attr__( 'Preview', 'flexo-booking' ) . '"><strong>' . esc_html__( 'Preview with a sample booking – only you see this.', 'flexo-booking' ) . '</strong> ';
		foreach ( self::preview_kinds() as $kind => $label ) {
			$html .= $kind === $current
				? '<span aria-current="true">' . esc_html( $label ) . '</span> '
				: '<a href="' . esc_url( self::preview_url( $kind ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		return $html . '</nav>';
	}

	/**
	 * The text under the heading.
	 */
	private static function lead( $view ) {
		if ( ! $view ) {
			return self::fill_html( Flexo_Booking_System_Pages::text( 'thank_you', 'generic_text' ) );
		}
		$intro = Flexo_Booking_System_Pages::text( 'thank_you', 'intro' );
		if ( '' !== $intro ) {
			return self::fill_html( $intro, $view );
		}
		if ( 'request' === $view['kind'] ) {
			return esc_html__( 'The hotel will review your request and contact you shortly.', 'flexo-booking' );
		}
		return esc_html( $view['message'] );
	}

	/**
	 * The sections as HTML blocks, in order. Stay rows next to each other
	 * form one summary list, actions one button row.
	 *
	 * @param bool $split Split layout: the stay summary in its own column.
	 * @return array { main: string[], side: string[] }
	 */
	private static function blocks( $view, array $sections, $split ) {
		if ( ! $view ) {
			// Without a booking: only contact and "back to the website".
			$sections = array_values( array_intersect( $sections, array( 'contact', 'back' ) ) );
		}
		$main = array();
		$side = array();
		$run  = array(
			'group' => '',
			'html'  => '',
		);
		$flush = static function () use ( &$run, &$main, &$side, $split ) {
			if ( '' === $run['html'] ) {
				return;
			}
			if ( 'stay' === $run['group'] ) {
				$html = '<dl class="fb-ty-details">' . $run['html'] . '</dl>';
			} elseif ( 'actions' === $run['group'] ) {
				$html = '<div class="fb-ty-actions">' . $run['html'] . '</div>';
			} else {
				$html = $run['html'];
			}
			if ( $split && 'stay' === $run['group'] ) {
				$side[] = $html;
			} else {
				$main[] = $html;
			}
			$run['html'] = '';
		};
		foreach ( $sections as $section ) {
			$html = self::section( $section, $view );
			if ( '' === $html ) {
				continue;
			}
			$group = self::group( $section );
			if ( $group !== $run['group'] || 'info' === $group ) {
				$flush();
				$run['group'] = $group;
			}
			$run['html'] .= $html;
		}
		$flush();
		return array(
			'main' => $main,
			'side' => $side,
		);
	}

	private static function row( $section, $label, $value_html ) {
		return '<div class="fb-ty-row fb-ty-row--' . esc_attr( $section ) . '"><dt>' . esc_html( $label ) . '</dt><dd>' . $value_html . '</dd></div>';
	}

	private static function copy_button( $value, $label ) {
		/* translators: %s: what is copied, e.g. "IBAN" */
		return ' <button type="button" class="fb-link fb-bank__copy" data-fb-copy="' . esc_attr( $value ) . '" aria-label="' . esc_attr( sprintf( __( 'Copy: %s', 'flexo-booking' ), $label ) ) . '" hidden>' . esc_html__( 'Copy', 'flexo-booking' ) . '</button>';
	}

	/**
	 * One section ('' when it doesn't apply to this booking).
	 */
	private static function section( $section, $view ) {
		$pay      = $view ? $view['payment'] : null;
		$contact  = Flexo_Booking_Guest::contact();
		$text     = static function ( $name ) use ( $view ) {
			return self::fill( Flexo_Booking_System_Pages::text( 'thank_you', $name ), $view );
		};
		switch ( $section ) {
			case 'status':
				return self::row( $section, __( 'Status', 'flexo-booking' ), '<span class="fb-ty-badge fb-ty-badge--' . esc_attr( $view['kind'] ) . '">' . esc_html( $view['state_label'] ) . '</span>' );
			case 'reference':
				return self::row( $section, __( 'Booking reference', 'flexo-booking' ), '<strong class="fb-ty-reference">' . esc_html( $view['reference'] ) . '</strong>' . self::copy_button( $view['reference'], __( 'Booking reference', 'flexo-booking' ) ) );
			case 'room':
				$image = '' !== $view['image'] ? '<img class="fb-ty-room__image" src="' . esc_url( $view['image'] ) . '" alt="" loading="lazy">' : '';
				return self::row( $section, __( 'Room', 'flexo-booking' ), $image . '<span class="fb-ty-room__name">' . esc_html( $view['room'] ) . '</span>' );
			case 'dates':
				return self::row( $section, __( 'Stay', 'flexo-booking' ), '<span class="fb-ty-dates">' . esc_html( $view['check_in_text'] ) . ' → ' . esc_html( $view['check_out_text'] ) . '</span> <span class="fb-ty-muted">· ' . esc_html( $view['nights_label'] ) . '</span>' );
			case 'guests':
				return self::row( $section, __( 'Guests', 'flexo-booking' ), esc_html( $view['guests'] ) );
			case 'rate':
				if ( ! $view['rate'] ) {
					return '';
				}
				$parts = array_filter( array( $view['rate']['meals'], $view['rate']['cancellation'] ) );
				return self::row( $section, __( 'Rate', 'flexo-booking' ), '<strong>' . esc_html( $view['rate']['name'] ) . '</strong>' . ( $parts ? '<br><span class="fb-ty-muted">' . esc_html( implode( ' · ', $parts ) ) . '</span>' : '' ) );
			case 'breakdown':
				$lines = isset( $view['quote']['lines'] ) ? $view['quote']['lines'] : array();
				if ( count( $lines ) < 2 && empty( $view['quote']['discount_formatted'] ) ) {
					return '';
				}
				$html = '<ul class="fb-ty-lines">';
				foreach ( $lines as $line ) {
					$html .= '<li><span>' . esc_html( $line['label'] ) . '</span><span>' . esc_html( $line['formatted'] ) . '</span></li>';
				}
				if ( ! empty( $view['quote']['discount_formatted'] ) ) {
					$html .= '<li><span>' . esc_html__( 'Discount', 'flexo-booking' ) . '</span><span>' . esc_html( $view['quote']['discount_formatted'] ) . '</span></li>';
				}
				return self::row( $section, __( 'Price details', 'flexo-booking' ), $html . '</ul>' );
			case 'total':
				return self::row( $section, __( 'Total', 'flexo-booking' ), '<strong class="fb-ty-total">' . esc_html( $view['total_formatted'] ) . '</strong>' );
			case 'payment_status':
				$label = self::payment_label( $view );
				return '' === $label ? '' : self::row( $section, __( 'Payment', 'flexo-booking' ), esc_html( $label ) );
			case 'paid':
				return $pay && $pay['paid'] > 0 ? self::row( $section, __( 'Paid', 'flexo-booking' ), esc_html( $pay['paid_formatted'] ) ) : '';
			case 'remaining':
				if ( ! $pay || $pay['at_property'] <= 0 || in_array( $view['kind'], array( 'request', 'transfer', 'problem' ), true ) || ( $pay['paid'] <= 0 && '' === (string) $pay['method'] ) ) {
					return '';
				}
				return self::row( $section, __( 'To pay at the property', 'flexo-booking' ), esc_html( $pay['at_property_formatted'] ) );
			case 'bank':
				if ( ! $pay || empty( $pay['instructions']['rows'] ) ) {
					return '';
				}
				$html = '<section class="fb-ty-block fb-bank"><h2 class="fb-ty-block__title">' . esc_html__( 'Bank transfer details', 'flexo-booking' ) . '</h2><dl class="fb-bank__list">';
				foreach ( $pay['instructions']['rows'] as $item ) {
					$copy  = in_array( $item['key'], array( 'iban', 'reference', 'amount' ), true ) ? self::copy_button( 'iban' === $item['key'] ? preg_replace( '/\s+/', '', $item['value'] ) : $item['value'], $item['label'] ) : '';
					$html .= '<div class="fb-bank__row fb-bank__row--' . esc_attr( $item['key'] ) . '"><dt>' . esc_html( $item['label'] ) . '</dt><dd><span class="fb-bank__value">' . esc_html( $item['value'] ) . '</span>' . $copy . '</dd></div>';
				}
				$html .= '</dl>';
				if ( '' !== (string) $pay['instructions']['note'] ) {
					$html .= '<p class="fb-bank__note">' . esc_html( $pay['instructions']['note'] ) . '</p>';
				}
				return $html . '</section>';
			case 'next':
				$own   = Flexo_Booking_System_Pages::text( 'thank_you', 'next_text' );
				$steps = $view['next_steps'];
				if ( '' === $own && ! $steps ) {
					return '';
				}
				$html = '<section class="fb-ty-block fb-ty-next"><h2 class="fb-ty-block__title">' . esc_html( $text( 'next_title' ) ) . '</h2>';
				if ( '' !== $own ) {
					$html .= '<div class="fb-ty-next__text">' . self::fill_html( wpautop( $own ), $view ) . '</div>';
				}
				if ( $steps ) {
					$html .= '<ul class="fb-ty-next__list">';
					foreach ( $steps as $step ) {
						$html .= '<li>' . esc_html( $step ) . '</li>';
					}
					$html .= '</ul>';
				}
				return $html . '</section>';
			case 'contact':
				$links = '';
				if ( '' !== $contact['phone'] ) {
					$links .= '<a class="fb-ty-contact__link" href="' . esc_url( $contact['phone_link'] ) . '">' . esc_html( $contact['phone'] ) . '</a>';
				}
				if ( '' !== $contact['email'] ) {
					$links .= '<a class="fb-ty-contact__link" href="' . esc_url( 'mailto:' . $contact['email'] ) . '">' . esc_html( $contact['email'] ) . '</a>';
				}
				if ( '' === $links ) {
					return '';
				}
				return '<section class="fb-ty-block fb-ty-contact"><h2 class="fb-ty-block__title">' . esc_html( $text( 'help_title' ) ) . '</h2><p class="fb-ty-contact__links">' . $links . '</p></section>';
			case 'address':
				if ( '' === $contact['address'] ) {
					return '';
				}
				return '<section class="fb-ty-block fb-ty-address"><h2 class="fb-ty-block__title">' . esc_html__( 'Address', 'flexo-booking' ) . '</h2><p>' . nl2br( esc_html( $contact['address'] ) ) . '</p></section>';
			case 'calendar':
				return '' === $view['ics'] ? '' : '<a class="fb-button fb-button--ghost" href="' . esc_url( $view['ics'] ) . '" rel="nofollow" download>' . esc_html( $text( 'calendar_label' ) ) . '</a>';
			case 'directions':
				return '' === $contact['directions'] ? '' : '<a class="fb-button fb-button--ghost" href="' . esc_url( $contact['directions'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text( 'directions_label' ) ) . '</a>';
			case 'manage':
				return '' === $view['manage'] ? '' : '<a class="fb-button fb-button--ghost" href="' . esc_url( $view['manage'] ) . '" rel="nofollow">' . esc_html( $text( 'manage_label' ) ) . '</a>';
			case 'back':
				return '<a class="fb-button" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $text( 'back_label' ) ) . '</a>';
		}
		return '';
	}

	/**
	 * Payment in guests' words ('' when payments are not used for the booking).
	 */
	private static function payment_label( array $view ) {
		$pay = $view['payment'];
		if ( 'paid' === $view['kind'] ) {
			return __( 'Payment received', 'flexo-booking' );
		}
		if ( 'transfer' === $view['kind'] ) {
			return __( 'Waiting for your bank transfer', 'flexo-booking' );
		}
		if ( 'pending' === $view['kind'] ) {
			return __( 'Your payment is being confirmed', 'flexo-booking' );
		}
		if ( 'request' === $view['kind'] ) {
			return '' !== (string) $pay['method'] ? __( 'Nothing is charged until the hotel confirms your request', 'flexo-booking' ) : '';
		}
		if ( 'instant' === $view['kind'] && $pay['at_property'] > 0 && Flexo_Booking_Payments::enabled() ) {
			return __( 'You pay at the property', 'flexo-booking' );
		}
		return '';
	}
}
