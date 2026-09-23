<?php
/**
 * One of two simultaneous booking attempts for the last unit (see concurrency.sh).
 * A 2-second pause inside the room lock makes the race window wide open.
 */
add_action( 'flexo_booking_inside_lock', function () {
	sleep( 2 );
} );
$start  = microtime( true );
$result = Flexo_Booking_Bookings::create(
	array(
		'room'        => 'race-room',
		'check_in'    => getenv( 'RACE_IN' ),
		'check_out'   => getenv( 'RACE_OUT' ),
		'adults'      => 1,
		'guest_name'  => 'Racer ' . getenv( 'RACER' ),
		'guest_email' => 'racer@example.com',
		'guest_phone' => '1',
	)
);
printf( "\nracer %s: %s after %.1fs\n", getenv( 'RACER' ), is_wp_error( $result ) ? 'REJECTED (' . $result->get_error_code() . ')' : 'BOOKED ' . $result['reference'], microtime( true ) - $start );
