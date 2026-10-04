<?php
/**
 * "From" prices: the lowest average price per night a guest can actually
 * get for a room in the coming months.
 *
 * For every arrival day in the booking window (up to 12 months) that is not
 * closed, a stay of the room's minimum length for that day is priced –
 * seasons, weekend prices and the room's first rate included – for 2 adults
 * (or fewer when the room takes fewer). Tourist tax and promo codes are not
 * included; availability is not considered (a "from" price describes the
 * room). A quick scan finds the cheapest stays and the best few are priced
 * by the real pricing service, so the booking form always honours the
 * price shown.
 *
 * Cached per room. The cache is refreshed when the room, seasons, closed
 * dates, rates or booking rules change, and once a day (the window moves);
 * a room whose cache is out of date is priced again when it is shown.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Prices {

	const META           = '_flexo_from_price';
	const VERSION_OPTION = 'flexo_booking_prices_version';
	const EVENT          = 'flexo_booking_from_prices';
	const CANDIDATES     = 6;

	/**
	 * Room meta that changes what a room costs.
	 */
	const PRICE_META = array( '_flexo_price', '_flexo_weekend_price', '_flexo_min_nights', '_flexo_capacity', '_flexo_max_adults', '_flexo_units', '_flexo_rate_plans', '_flexo_child_rules' );

	/**
	 * @var array From prices worked out in this request.
	 */
	private static $memo = array();

	public static function init() {
		add_action( 'flexo_booking_prices_changed', array( __CLASS__, 'invalidate_all' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 3 );
		}
		add_action( 'update_option_' . Flexo_Booking_Settings::OPTION, array( __CLASS__, 'invalidate_all' ) );
		add_action( 'update_option_' . Flexo_Booking_Features::ENABLED_OPTION, array( __CLASS__, 'invalidate_all' ) );
		add_action( self::EVENT, array( __CLASS__, 'refresh_all' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'flexo_booking_room_price_options', array( __CLASS__, 'tag_options' ) );
		add_filter( 'flexo_booking_room_price_tag', array( __CLASS__, 'tag_value' ), 10, 3 );
	}

	/**
	 * Daily refresh: the 12-month window moves every day.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( in_array( $key, self::PRICE_META, true ) ) {
			delete_post_meta( (int) $post_id, self::META );
			unset( self::$memo[ (int) $post_id ] );
		}
	}

	/**
	 * Something that changes every room's prices changed (seasons, closed
	 * dates, rates, settings): cached prices are out of date.
	 */
	public static function invalidate_all() {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 0 ) + 1 );
		self::$memo = array();
		if ( ! wp_next_scheduled( self::EVENT ) || wp_next_scheduled( self::EVENT ) > time() + 120 ) {
			wp_schedule_single_event( time() + 60, self::EVENT, array( 'refresh' ) );
		}
	}

	/**
	 * Brings every room's "from" price up to date (cron).
	 */
	public static function refresh_all() {
		foreach ( Flexo_Booking_Rooms::all() as $post ) {
			self::get( $post->ID );
		}
	}

	/**
	 * The room's "from" price.
	 *
	 * @param int $room_id
	 * @return array|null { amount: float (per night), nights: int, check_in: Y-m-d } or null when the room has none.
	 */
	public static function get( $room_id ) {
		$room_id = (int) $room_id;
		if ( array_key_exists( $room_id, self::$memo ) ) {
			return self::$memo[ $room_id ];
		}
		$today   = wp_date( 'Y-m-d' );
		$version = (int) get_option( self::VERSION_OPTION, 0 );
		$cached  = get_post_meta( $room_id, self::META, true );
		if ( is_array( $cached ) && isset( $cached['computed'], $cached['version'] ) && $today === $cached['computed'] && $version === (int) $cached['version'] ) {
			self::$memo[ $room_id ] = $cached['price'];
			return $cached['price'];
		}
		$price = self::compute( $room_id );
		update_post_meta(
			$room_id,
			self::META,
			array(
				'price'    => $price,
				'computed' => $today,
				'version'  => $version,
			)
		);
		self::$memo[ $room_id ] = $price;
		return $price;
	}

	/**
	 * Works the "from" price out (see the class description).
	 *
	 * @return array|null
	 */
	public static function compute( $room_id ) {
		$post = get_post( $room_id );
		if ( ! $post || Flexo_Booking_Rooms::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$room = Flexo_Booking_Rooms::to_array( $post );
		if ( $room['units'] < 1 ) {
			return null;
		}
		$today  = wp_date( 'Y-m-d' );
		$days   = max( 1, min( 365, (int) Flexo_Booking_Settings::get( 'max_advance_days' ) ) );
		$limit  = $room['max_adults'] > 0 && Flexo_Booking_Children::enabled() ? min( $room['max_adults'], $room['capacity'] ) : $room['capacity'];
		$adults = max( 1, min( 2, $limit ) );

		// Everything the scan needs, read once.
		$seasons  = Flexo_Booking_Seasons::enabled() ? Flexo_Booking_Seasons::for_room( $room['id'] ) : array();
		$closures = array();
		foreach ( Flexo_Booking_Closures::all() as $closure ) {
			if ( 0 === $closure['room_id'] || $room['id'] === $closure['room_id'] ) {
				$closures[] = $closure;
			}
		}

		// Quick scan: price of every night, then every arrival's minimum stay.
		$nightly = array();
		$min_for = array();
		$last    = Flexo_Booking_Dates::add_days( $today, $days + 90 );
		for ( $date = $today; $date < $last; $date = Flexo_Booking_Dates::add_days( $date, 1 ) ) {
			$season  = $seasons ? Flexo_Booking_Seasons::season_for_night( $seasons, $date ) : null;
			$weekend = Flexo_Booking_Dates::is_weekend_night( $date );
			if ( $season ) {
				$nightly[ $date ] = (float) ( $weekend && $season['weekend_price'] > 0 ? $season['weekend_price'] : $season['price'] );
				$min_for[ $date ] = $season['min_nights'] ? (int) $season['min_nights'] : max( 1, (int) $room['min_nights'] );
			} else {
				$nightly[ $date ] = (float) ( $weekend && $room['weekend_price'] > 0 ? $room['weekend_price'] : $room['price'] );
				$min_for[ $date ] = max( 1, (int) $room['min_nights'] );
			}
		}

		$candidates = array();
		$dates      = array_keys( $nightly );
		for ( $i = 0; $i < $days; $i++ ) {
			$arrival = $dates[ $i ];
			$nights  = $min_for[ $arrival ];
			if ( $i + $nights > count( $dates ) ) {
				break;
			}
			$departure = Flexo_Booking_Dates::add_days( $arrival, $nights );
			if ( self::closed( $closures, $arrival, $departure ) ) {
				continue;
			}
			$sum = 0.0;
			for ( $n = 0; $n < $nights; $n++ ) {
				$sum += $nightly[ $dates[ $i + $n ] ];
			}
			$candidates[] = array( $sum / $nights, $arrival, $departure, $nights );
		}
		if ( ! $candidates ) {
			return null;
		}
		usort(
			$candidates,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0] ?: strcmp( $a[1], $b[1] );
			}
		);

		// The best candidates, priced for real.
		$best = null;
		foreach ( array_slice( $candidates, 0, self::CANDIDATES ) as $candidate ) {
			$quote = Flexo_Booking_Pricing::quote(
				array(
					'room'      => $room,
					'check_in'  => $candidate[1],
					'check_out' => $candidate[2],
					'adults'    => $adults,
					'children'  => 0,
					'context'   => 'search',
				)
			);
			if ( is_wp_error( $quote ) || $quote['nights'] < 1 ) {
				continue;
			}
			$average = Flexo_Booking_Money::round( $quote['subtotal'] / $quote['nights'] );
			if ( $average > 0 && ( null === $best || $average < $best['amount'] ) ) {
				$best = array(
					'amount'   => $average,
					'nights'   => (int) $quote['nights'],
					'check_in' => $candidate[1],
				);
			}
		}
		return $best;
	}

	/**
	 * Whether a closed period covers any night of the stay.
	 */
	private static function closed( array $closures, $check_in, $check_out ) {
		$last_night = Flexo_Booking_Dates::add_days( $check_out, -1 );
		foreach ( $closures as $closure ) {
			if ( $closure['date_from'] <= $last_night && $closure['date_to'] >= $check_in ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether "from" prices are shown on room pages and in the booking box.
	 */
	public static function shown() {
		return 'none' !== Flexo_Booking_Settings::get( 'room_price_display' );
	}

	/**
	 * "from €95" or '' (no price, or prices hidden in the settings).
	 *
	 * @param int  $room_id
	 * @param bool $force Show even when the setting hides prices.
	 */
	public static function from_text( $room_id, $force = false ) {
		if ( ! $force && ! self::shown() ) {
			return '';
		}
		$price = self::get( $room_id );
		if ( ! $price ) {
			return '';
		}
		/* translators: %s: price per night, e.g. €95 */
		return sprintf( __( 'from %s', 'flexo-booking' ), Flexo_Booking_Money::format( $price['amount'], null, true ) );
	}

	/**
	 * "From price" in the Room price dynamic tag.
	 */
	public static function tag_options( $options ) {
		return array( 'from' => __( 'From price (lowest per night)', 'flexo-booking' ) ) + $options;
	}

	public static function tag_value( $amount, $which, array $room ) {
		if ( 'from' !== $which ) {
			return $amount;
		}
		$price = self::get( $room['id'] );
		return $price ? $price['amount'] : '';
	}
}
