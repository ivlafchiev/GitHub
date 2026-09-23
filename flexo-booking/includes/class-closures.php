<?php
/**
 * Closed dates: periods when the whole property (room_id 0) or one room type
 * takes no bookings, e.g. closed for the winter.
 *
 * Closures are core (not tied to a feature switch), so switching a feature
 * off can never re-open dates the hotel has closed. date_from / date_to are
 * the first and last closed night; departing on the first closed day is fine.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Closures {

	/**
	 * Closures preloaded per stay: "check_in|check_out" => rows.
	 *
	 * @var array
	 */
	private static $cache = array();

	public static function table() {
		return Flexo_Booking_Schema::table( 'closures' );
	}

	private static function hydrate( $row ) {
		$row            = (array) $row;
		$row['id']      = (int) $row['id'];
		$row['room_id'] = (int) $row['room_id'];
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY date_from ASC, room_id ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * All closures (any room) touching a stay – one query per search.
	 */
	private static function for_range( $check_in, $check_out ) {
		global $wpdb;
		$key = $check_in . '|' . $check_out;
		if ( ! isset( self::$cache[ $key ] ) ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows                = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE date_from <= %s AND date_to >= %s ORDER BY room_id ASC, date_from ASC", Flexo_Booking_Dates::add_days( $check_out, -1 ), $check_in ), ARRAY_A );
			self::$cache[ $key ] = array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
		}
		return self::$cache[ $key ];
	}

	/**
	 * The first closure covering any night of the stay for this room
	 * (property-wide closures first), or null.
	 */
	public static function for_stay( $room_id, $check_in, $check_out ) {
		foreach ( self::for_range( $check_in, $check_out ) as $closure ) {
			if ( 0 === $closure['room_id'] || (int) $room_id === $closure['room_id'] ) {
				return $closure;
			}
		}
		return null;
	}

	/**
	 * A property-wide closure touching the stay, or null.
	 */
	public static function property_closure( $check_in, $check_out ) {
		foreach ( self::for_range( $check_in, $check_out ) as $closure ) {
			if ( 0 === $closure['room_id'] ) {
				return $closure;
			}
		}
		return null;
	}

	public static function flush_cache() {
		self::$cache = array();
	}

	/**
	 * Text shown to guests.
	 */
	public static function guest_message( array $closure ) {
		$from = Flexo_Booking_Dates::display_site( $closure['date_from'] );
		$to   = Flexo_Booking_Dates::display_site( $closure['date_to'] );
		if ( '' !== $closure['label'] ) {
			/* translators: 1: guest message set by the hotel, 2: first closed night, 3: last closed night */
			return sprintf( __( '%1$s (%2$s – %3$s)', 'flexo-booking' ), $closure['label'], $from, $to );
		}
		if ( 0 === $closure['room_id'] ) {
			/* translators: 1: first closed night, 2: last closed night */
			return sprintf( __( 'We are closed from %1$s to %2$s. Please choose other dates.', 'flexo-booking' ), $from, $to );
		}
		/* translators: 1: first closed night, 2: last closed night */
		return sprintf( __( 'This room is closed from %1$s to %2$s.', 'flexo-booking' ), $from, $to );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function validate( array $input ) {
		$room_id = isset( $input['room_id'] ) ? absint( $input['room_id'] ) : 0;
		if ( $room_id ) {
			$room = get_post( $room_id );
			if ( ! $room || Flexo_Booking_Rooms::POST_TYPE !== $room->post_type ) {
				return new WP_Error( 'flexo_closure_room', __( 'Please choose a room or "Whole property".', 'flexo-booking' ) );
			}
		}
		$from = Flexo_Booking_Dates::parse( isset( $input['date_from'] ) ? $input['date_from'] : '' );
		$to   = Flexo_Booking_Dates::parse( isset( $input['date_to'] ) ? $input['date_to'] : '' );
		if ( ! $from || ! $to ) {
			return new WP_Error( 'flexo_closure_dates', __( 'Please enter both dates as DD.MM.YYYY.', 'flexo-booking' ) );
		}
		if ( $from > $to ) {
			return new WP_Error( 'flexo_closure_dates', __( 'The closed period must end on or after its start date.', 'flexo-booking' ) );
		}
		return array(
			'room_id'   => $room_id,
			'date_from' => $from,
			'date_to'   => $to,
			'label'     => isset( $input['label'] ) ? sanitize_text_field( $input['label'] ) : '',
		);
	}

	/**
	 * @return int|WP_Error Closure ID.
	 */
	public static function save( array $input, $id = 0 ) {
		global $wpdb;

		$data = self::validate( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = current_time( 'mysql' );
		if ( $id ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		} else {
			$data['created_at'] = $data['updated_at'];
			if ( ! $wpdb->insert( self::table(), $data ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The closed period could not be saved.', 'flexo-booking' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::flush_cache();
		return (int) $id;
	}

	public static function delete( $id ) {
		global $wpdb;
		self::flush_cache();
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	public static function exists( $room_id, $from, $to ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE room_id = %d AND date_from = %s AND date_to = %s LIMIT 1", $room_id, $from, $to ) );
	}

	/**
	 * Copies the closures starting in $year to the following year, skipping
	 * ones that already exist.
	 *
	 * @return array { @type int $copied, @type int $skipped }
	 */
	public static function copy_to_next_year( $year ) {
		global $wpdb;
		$table  = self::table();
		$year   = (int) $year;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE date_from BETWEEN %s AND %s", $year . '-01-01', $year . '-12-31' ), ARRAY_A );
		$result = array(
			'copied'  => 0,
			'skipped' => 0,
		);
		foreach ( $rows ? $rows : array() as $row ) {
			$from = Flexo_Booking_Dates::next_year( $row['date_from'] );
			$to   = Flexo_Booking_Dates::next_year( $row['date_to'] );
			if ( self::exists( (int) $row['room_id'], $from, $to ) ) {
				++$result['skipped'];
				continue;
			}
			$saved = self::save(
				array(
					'room_id'   => $row['room_id'],
					'date_from' => $from,
					'date_to'   => $to,
					'label'     => $row['label'],
				)
			);
			is_wp_error( $saved ) ? ++$result['skipped'] : ++$result['copied'];
		}
		return $result;
	}
}
