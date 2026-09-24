<?php
/**
 * Promo codes (feature "promo_codes").
 *
 * Codes are stored in upper case and matched case-insensitively. A code's
 * usage is the number of *confirmed* bookings carrying it, so cancelling a
 * booking gives the use back without any bookkeeping.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Promo_Codes {

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'promo_codes' );
	}

	public static function table() {
		return Flexo_Booking_Schema::table( 'promo_codes' );
	}

	public static function normalize_code( $code ) {
		return strtoupper( substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $code ), 0, 50 ) );
	}

	/**
	 * @return array[]
	 */
	public static function all() {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::table_exists( 'promo_codes' ) ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY code ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_code( $code ) {
		global $wpdb;
		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ) {
		$ids = static function ( $value ) {
			return array_values( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
		};
		return array(
			'id'             => (int) $row['id'],
			'code'           => $row['code'],
			'description'    => $row['description'],
			'active'         => (int) $row['active'],
			'discount_type'  => 'fixed' === $row['discount_type'] ? 'fixed' : 'percent',
			'discount_value' => (float) $row['discount_value'],
			'book_from'      => $row['book_from'] ? $row['book_from'] : null,
			'book_to'        => $row['book_to'] ? $row['book_to'] : null,
			'stay_from'      => $row['stay_from'] ? $row['stay_from'] : null,
			'stay_to'        => $row['stay_to'] ? $row['stay_to'] : null,
			'min_amount'     => null === $row['min_amount'] ? null : (float) $row['min_amount'],
			'min_nights'     => null === $row['min_nights'] ? null : (int) $row['min_nights'],
			'max_uses'       => null === $row['max_uses'] ? null : (int) $row['max_uses'],
			'room_ids'       => $ids( $row['room_ids'] ),
			'rate_plan_ids'  => $ids( $row['rate_plan_ids'] ),
		);
	}

	/**
	 * Validates and stores a code. Dates may be DD.MM.YYYY or Y-m-d; empty
	 * optional fields mean "no limit".
	 *
	 * @return int|WP_Error Code ID.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$code = self::normalize_code( isset( $data['code'] ) ? $data['code'] : '' );
		if ( '' === $code ) {
			return new WP_Error( 'flexo_promo_code', __( 'Please enter a code using letters and numbers, e.g. SUMMER20.', 'flexo-booking' ) );
		}
		$other = self::get_by_code( $code );
		if ( $other && $other['id'] !== (int) $id ) {
			/* translators: %s: promo code */
			return new WP_Error( 'flexo_promo_code', sprintf( __( 'The code %s already exists.', 'flexo-booking' ), $code ) );
		}

		$type  = isset( $data['discount_type'] ) && 'fixed' === $data['discount_type'] ? 'fixed' : 'percent';
		$value = round( (float) str_replace( ',', '.', (string) ( isset( $data['discount_value'] ) ? $data['discount_value'] : 0 ) ), 2 );
		if ( $value <= 0 || ( 'percent' === $type && $value > 100 ) ) {
			return new WP_Error( 'flexo_promo_value', 'percent' === $type ? __( 'The discount must be between 0 and 100%.', 'flexo-booking' ) : __( 'The discount must be more than 0.', 'flexo-booking' ) );
		}

		$dates = array();
		foreach ( array( 'book_from', 'book_to', 'stay_from', 'stay_to' ) as $field ) {
			$raw = isset( $data[ $field ] ) ? trim( (string) $data[ $field ] ) : '';
			if ( '' === $raw ) {
				$dates[ $field ] = null;
				continue;
			}
			$parsed = Flexo_Booking_Dates::parse( $raw );
			if ( ! $parsed ) {
				/* translators: %s: the date as entered */
				return new WP_Error( 'flexo_promo_date', sprintf( __( '"%s" is not a valid date. Use DD.MM.YYYY.', 'flexo-booking' ), $raw ) );
			}
			$dates[ $field ] = $parsed;
		}
		if ( ( $dates['book_from'] && $dates['book_to'] && $dates['book_from'] > $dates['book_to'] ) || ( $dates['stay_from'] && $dates['stay_to'] && $dates['stay_from'] > $dates['stay_to'] ) ) {
			return new WP_Error( 'flexo_promo_date', __( 'The "from" date must be before the "until" date.', 'flexo-booking' ) );
		}

		$optional = static function ( $key, $cast ) use ( $data ) {
			$raw = isset( $data[ $key ] ) ? trim( str_replace( ',', '.', (string) $data[ $key ] ) ) : '';
			if ( '' === $raw || (float) $raw <= 0 ) {
				return null;
			}
			return 'float' === $cast ? round( (float) $raw, 2 ) : absint( $raw );
		};
		$ids = static function ( $value ) {
			$value = is_array( $value ) ? $value : explode( ',', (string) $value );
			return implode( ',', array_unique( array_filter( array_map( 'absint', $value ) ) ) );
		};

		$row = array(
			'code'           => $code,
			'description'    => sanitize_text_field( isset( $data['description'] ) ? $data['description'] : '' ),
			'active'         => empty( $data['active'] ) ? 0 : 1,
			'discount_type'  => $type,
			'discount_value' => $value,
			'book_from'      => $dates['book_from'],
			'book_to'        => $dates['book_to'],
			'stay_from'      => $dates['stay_from'],
			'stay_to'        => $dates['stay_to'],
			'min_amount'     => $optional( 'min_amount', 'float' ),
			'min_nights'     => $optional( 'min_nights', 'int' ),
			'max_uses'       => $optional( 'max_uses', 'int' ),
			'room_ids'       => $ids( isset( $data['room_ids'] ) ? $data['room_ids'] : '' ),
			'rate_plan_ids'  => $ids( isset( $data['rate_plan_ids'] ) ? $data['rate_plan_ids'] : '' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		if ( $id && self::get( $id ) ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			if ( ! $wpdb->insert( self::table(), $row ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The promo code could not be saved.', 'flexo-booking' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		return (int) $id;
	}

	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'active'     => $active ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Deletes a code. Bookings keep the code and discount they were made with.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Confirmed bookings using a code.
	 *
	 * @param int $exclude_booking Booking ID not to count.
	 */
	public static function uses( $id, $exclude_booking = 0 ) {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE promo_id = %d AND status = 'confirmed' AND id <> %d", $id, $exclude_booking ) );
	}

	/**
	 * Usage of every code in one query (admin list).
	 *
	 * @return int[] Code ID => confirmed bookings.
	 */
	public static function uses_map() {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		$map   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT promo_id, COUNT(*) AS uses FROM {$table} WHERE promo_id > 0 AND status = 'confirmed' GROUP BY promo_id" ) as $row ) {
			$map[ (int) $row->promo_id ] = (int) $row->uses;
		}
		return $map;
	}

	/**
	 * Amount the code can be applied to: room price and rate plan, never taxes.
	 */
	public static function discountable( array $quote ) {
		$base = 0.0;
		foreach ( $quote['lines'] as $line ) {
			if ( in_array( $line['type'], array( 'accommodation', 'adjustment', 'rate_plan' ), true ) ) {
				$base += (float) $line['amount'];
			}
		}
		return max( 0.0, $base );
	}

	/**
	 * Checks a code against a quote. Messages are written for guests.
	 *
	 * @param string $code
	 * @param array  $quote        Quote after the rate-plan step.
	 * @param string $booking_date Y-m-d the booking is made on (default today).
	 * @return array|WP_Error The code.
	 */
	public static function validate( $code, array $quote, $booking_date = '' ) {
		$promo = self::get_by_code( $code );
		if ( ! $promo || ! $promo['active'] ) {
			return new WP_Error( 'flexo_promo_invalid', __( 'This promo code is not valid.', 'flexo-booking' ) );
		}

		$today = $booking_date ? $booking_date : wp_date( 'Y-m-d' );
		if ( $promo['book_to'] && $today > $promo['book_to'] ) {
			return new WP_Error( 'flexo_promo_expired', __( 'This promo code has expired.', 'flexo-booking' ) );
		}
		if ( $promo['book_from'] && $today < $promo['book_from'] ) {
			/* translators: %s: date */
			return new WP_Error( 'flexo_promo_not_yet', sprintf( __( 'This promo code can be used from %s.', 'flexo-booking' ), Flexo_Booking_Dates::display_site( $promo['book_from'] ) ) );
		}

		$last_night = Flexo_Booking_Dates::add_days( $quote['check_out'], -1 );
		if ( ( $promo['stay_from'] && $quote['check_in'] < $promo['stay_from'] ) || ( $promo['stay_to'] && $last_night > $promo['stay_to'] ) ) {
			return new WP_Error( 'flexo_promo_dates', self::stay_message( $promo ) );
		}
		if ( $promo['room_ids'] && ! in_array( (int) $quote['room_id'], $promo['room_ids'], true ) ) {
			return new WP_Error( 'flexo_promo_room', __( 'This promo code is not valid for this room.', 'flexo-booking' ) );
		}
		if ( $promo['rate_plan_ids'] ) {
			$plan_id = empty( $quote['rate_plan']['id'] ) ? 0 : (int) $quote['rate_plan']['id'];
			if ( ! in_array( $plan_id, $promo['rate_plan_ids'], true ) ) {
				return new WP_Error( 'flexo_promo_rate', __( 'This promo code is not valid for the selected rate.', 'flexo-booking' ) );
			}
		}
		if ( $promo['min_nights'] && $quote['nights'] < $promo['min_nights'] ) {
			/* translators: %d: number of nights */
			return new WP_Error( 'flexo_promo_min_nights', sprintf( _n( 'This promo code needs a stay of at least %d night.', 'This promo code needs a stay of at least %d nights.', $promo['min_nights'], 'flexo-booking' ), $promo['min_nights'] ) );
		}
		if ( $promo['min_amount'] && self::discountable( $quote ) < $promo['min_amount'] ) {
			/* translators: %s: minimum amount */
			return new WP_Error( 'flexo_promo_min_amount', sprintf( __( 'This promo code applies to bookings of %s or more.', 'flexo-booking' ), Flexo_Booking_Money::format( $promo['min_amount'] ) ) );
		}
		if ( $promo['max_uses'] && self::uses( $promo['id'] ) >= $promo['max_uses'] ) {
			return new WP_Error( 'flexo_promo_used_up', __( 'This promo code has already been fully used.', 'flexo-booking' ) );
		}
		return $promo;
	}

	private static function stay_message( array $promo ) {
		if ( $promo['stay_from'] && $promo['stay_to'] ) {
			/* translators: 1: first night, 2: last night */
			return sprintf( __( 'This promo code is valid for stays between %1$s and %2$s.', 'flexo-booking' ), Flexo_Booking_Dates::display_site( $promo['stay_from'] ), Flexo_Booking_Dates::display_site( Flexo_Booking_Dates::add_days( $promo['stay_to'], 1 ) ) );
		}
		if ( $promo['stay_from'] ) {
			/* translators: %s: date */
			return sprintf( __( 'This promo code is valid for stays from %s.', 'flexo-booking' ), Flexo_Booking_Dates::display_site( $promo['stay_from'] ) );
		}
		/* translators: %s: date */
		return sprintf( __( 'This promo code is valid for stays until %s.', 'flexo-booking' ), Flexo_Booking_Dates::display_site( Flexo_Booking_Dates::add_days( $promo['stay_to'], 1 ) ) );
	}

	/**
	 * The discount a code gives on an amount; never more than the amount.
	 */
	public static function discount( array $promo, $base ) {
		$base   = max( 0.0, (float) $base );
		$amount = 'percent' === $promo['discount_type'] ? $base * $promo['discount_value'] / 100 : $promo['discount_value'];
		return min( $base, Flexo_Booking_Money::round( $amount ) );
	}

	/**
	 * "−20%" or "−30.00 €".
	 */
	public static function describe( array $promo ) {
		return 'percent' === $promo['discount_type']
			? '−' . Flexo_Booking_Children::percent_text( $promo['discount_value'] ) . '%'
			: '−' . Flexo_Booking_Money::format( $promo['discount_value'] );
	}

	/**
	 * A confirmed booking that took a code past its limit (possible when
	 * staff confirm booking requests). Returns a warning for staff, or ''.
	 */
	public static function over_limit_warning( array $booking ) {
		if ( empty( $booking['promo_id'] ) || 'confirmed' !== $booking['status'] ) {
			return '';
		}
		$promo = self::get( $booking['promo_id'] );
		if ( ! $promo || ! $promo['max_uses'] ) {
			return '';
		}
		$uses = self::uses( $promo['id'] );
		if ( $uses <= $promo['max_uses'] ) {
			return '';
		}
		/* translators: 1: promo code, 2: times used, 3: limit */
		return sprintf( __( 'Note: promo code %1$s is now used by %2$d confirmed bookings, above its limit of %3$d. The discount stays on this booking.', 'flexo-booking' ), $promo['code'], $uses, $promo['max_uses'] );
	}

	/**
	 * Counts failed promo attempts per visitor, to stop code guessing.
	 *
	 * @return bool True when the visitor has tried too many wrong codes.
	 */
	public static function too_many_attempts( $record_failure = false ) {
		$limit = (int) apply_filters( 'flexo_booking_promo_attempts', 20 );
		if ( $limit < 1 ) {
			return false;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'flexo_promo_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $record_failure ) {
			set_transient( $key, ++$count, HOUR_IN_SECONDS );
		}
		return $count >= $limit;
	}
}
