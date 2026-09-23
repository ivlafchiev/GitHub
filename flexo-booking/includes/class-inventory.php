<?php
/**
 * Inventory: how many units of a room type are free, closed dates, and the
 * per-room lock that serialises every change to occupancy.
 *
 * Everything that occupies a room is a row in the bookings table (guest
 * bookings, staff blocks – and later iCal imports and payment holds), so
 * availability is always one query.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Inventory {

	/**
	 * Statuses that take a unit. Day 5 extends this with payment holds.
	 */
	public static function occupying_statuses() {
		return apply_filters( 'flexo_booking_occupying_statuses', Flexo_Booking_Bookings::OCCUPYING );
	}

	/**
	 * SQL condition (with its parameters) selecting occupying bookings.
	 *
	 * @return array { @type string $sql, @type array $params }
	 */
	public static function occupying_sql() {
		$statuses = self::occupying_statuses();
		return array(
			'sql'    => 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
			'params' => $statuses,
		);
	}

	/**
	 * Units of this room type free for every night of the stay. Bookings are
	 * counted night by night, so two stays that don't overlap each other take
	 * only one unit.
	 */
	public static function units_available( array $room, $check_in, $check_out, $exclude_id = 0 ) {
		global $wpdb;

		if ( $room['units'] < 1 ) {
			return 0;
		}

		$table    = Flexo_Booking_Install::table();
		$occupied = self::occupying_sql();
		$params   = array_merge( array( $room['id'], $check_out, $check_in, (int) $exclude_id ), $occupied['params'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders are built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT check_in, check_out FROM {$table} WHERE room_id = %d AND check_in < %s AND check_out > %s AND id <> %d AND {$occupied['sql']}", $params ) );

		$max_used = 0;
		foreach ( Flexo_Booking_Dates::nights( $check_in, $check_out ) as $date ) {
			$used = 0;
			foreach ( $rows as $row ) {
				if ( $row->check_in <= $date && $row->check_out > $date ) {
					++$used;
				}
			}
			$max_used = max( $max_used, $used );
		}

		return max( 0, $room['units'] - $max_used );
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
