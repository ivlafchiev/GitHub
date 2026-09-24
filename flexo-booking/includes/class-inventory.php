<?php
/**
 * Inventory: how many units of a room type are free, closed dates, and the
 * per-room lock that serialises every change to occupancy.
 *
 * Everything that occupies a room is a row in the bookings table (guest
 * bookings, staff blocks and payment holds), so availability is always one
 * query (plus bookings imported from external calendars).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Inventory {

	/**
	 * Statuses that take a unit. Online payment holds ("pending_payment")
	 * take one too, but only until the hold expires – see occupying_sql().
	 */
	public static function occupying_statuses() {
		return apply_filters( 'flexo_booking_occupying_statuses', Flexo_Booking_Bookings::OCCUPYING );
	}

	/**
	 * SQL condition (with its parameters) selecting occupying bookings:
	 * the occupying statuses, plus payment holds that haven't expired.
	 *
	 * @return array { @type string $sql, @type array $params }
	 */
	public static function occupying_sql() {
		$statuses = self::occupying_statuses();
		return array(
			'sql'    => '(status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') OR (status = %s AND hold_expires_at > %s))',
			'params' => array_merge( $statuses, array( Flexo_Booking_Bookings::HOLD_STATUS, current_time( 'mysql' ) ) ),
		);
	}

	/**
	 * Whether a booking takes a unit right now.
	 */
	public static function is_occupying( array $booking ) {
		if ( Flexo_Booking_Bookings::HOLD_STATUS === $booking['status'] ) {
			return ! empty( $booking['hold_expires_at'] ) && $booking['hold_expires_at'] > current_time( 'mysql' );
		}
		return in_array( $booking['status'], self::occupying_statuses(), true );
	}

	/**
	 * Units of this room type free for every night of the stay. Bookings are
	 * counted night by night, so two stays that don't overlap each other take
	 * only one unit.
	 */
	public static function units_available( array $room, $check_in, $check_out, $exclude_id = 0 ) {
		if ( $room['units'] < 1 ) {
			return 0;
		}
		$usage = self::nightly_usage( $room, $check_in, $check_out, $exclude_id );
		return max( 0, $room['units'] - ( $usage ? max( $usage ) : 0 ) );
	}

	/**
	 * Units taken on each night of a stay.
	 *
	 * Rule for bookings imported from external calendars (while Calendar sync
	 * is on): each imported booking takes one unit, except when its calendar
	 * is linked to a specific unit ("room no. 2"). Bookings from calendars
	 * linked to the same unit take that unit only once per night, so the same
	 * reservation appearing in two feeds (e.g. Airbnb repeating Booking.com)
	 * is not counted twice.
	 *
	 * @return int[] Y-m-d => units taken.
	 */
	public static function nightly_usage( array $room, $check_in, $check_out, $exclude_id = 0 ) {
		global $wpdb;

		$table    = Flexo_Booking_Install::table();
		$occupied = self::occupying_sql();
		$params   = array_merge( array( $room['id'], $check_out, $check_in, (int) $exclude_id ), $occupied['params'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders are built above.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT check_in, check_out FROM {$table} WHERE room_id = %d AND check_in < %s AND check_out > %s AND id <> %d AND {$occupied['sql']}", $params ) );
		$events = Flexo_Booking_ICal::enabled() ? Flexo_Booking_ICal::events_for_room( $room['id'], $check_in, $check_out ) : array();

		$usage = array();
		foreach ( Flexo_Booking_Dates::nights( $check_in, $check_out ) as $date ) {
			$used  = 0;
			$units = array();
			foreach ( $rows as $row ) {
				if ( $row->check_in <= $date && $row->check_out > $date ) {
					++$used;
				}
			}
			foreach ( $events as $event ) {
				if ( $event['date_from'] <= $date && $event['date_to'] > $date ) {
					if ( $event['unit'] > 0 ) {
						$units[ $event['unit'] ] = true;
					} else {
						++$used;
					}
				}
			}
			$usage[ $date ] = $used + count( $units );
		}
		return $usage;
	}

	/**
	 * Why the room can't be booked on these dates because of a closure, or ''.
	 */
	public static function closed_reason( $room_id, $check_in, $check_out ) {
		$closure = Flexo_Booking_Closures::for_stay( $room_id, $check_in, $check_out );
		return $closure ? Flexo_Booking_Closures::guest_message( $closure ) : '';
	}

	/**
	 * Runs $callback while holding the lock for this room type. Everything
	 * that changes occupancy must re-check availability inside it.
	 *
	 * @return mixed|WP_Error The callback's result.
	 */
	public static function with_lock( $room_id, callable $callback ) {
		$name = 'room_' . (int) $room_id;
		if ( ! Flexo_Booking_Lock::acquire( $name, 10 ) ) {
			return new WP_Error( 'flexo_busy', __( 'We are processing another booking for this room. Please try again in a moment.', 'flexo-booking' ) );
		}
		try {
			/**
			 * Fires while the room lock is held (used by the concurrency test).
			 */
			do_action( 'flexo_booking_inside_lock', $room_id );
			return $callback();
		} finally {
			Flexo_Booking_Lock::release( $name );
		}
	}
}
