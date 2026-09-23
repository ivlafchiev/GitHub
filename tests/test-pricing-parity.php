<?php
/**
 * The new pricing service must give exactly the 1.0.0 results for rooms
 * without seasons, with seasonal prices switched off and on.
 */
require __DIR__ . '/lib.php';

/** Verbatim copy of Flexo_Booking_Bookings::calculate_total() from 1.0.0. */
function legacy_calculate_total( array $room, $check_in, $check_out ) {
	$tz    = wp_timezone();
	$night = new DateTimeImmutable( $check_in, $tz );
	$end   = new DateTimeImmutable( $check_out, $tz );
	$total = 0.0;
	while ( $night < $end ) {
		$is_weekend = in_array( (int) $night->format( 'N' ), array( 5, 6 ), true );
		$total     += ( $is_weekend && $room['weekend_price'] > 0 ) ? $room['weekend_price'] : $room['price'];
		$night      = $night->modify( '+1 day' );
	}
	return (float) apply_filters( 'flexo_booking_calculate_total', round( $total, 2 ), $room, $check_in, $check_out );
}

t_reset_inventory();
$rooms = array(
	t_room( 'parity-a', 'Parity A', array( 'price' => 100, 'weekend_price' => 150, 'capacity' => 2, 'units' => 3 ) ),
	t_room( 'parity-b', 'Parity B', array( 'price' => 89.99, 'weekend_price' => '', 'capacity' => 4, 'units' => 1 ) ),
	t_room( 'parity-c', 'Parity C', array( 'price' => 0, 'weekend_price' => 35.5, 'capacity' => 1, 'units' => 1 ) ),
	t_room( 'parity-d', 'Parity D', array( 'price' => 1234.56, 'weekend_price' => 0.01, 'capacity' => 6, 'units' => 2 ) ),
);

mt_srand( 20260923 );
foreach ( array( false, true ) as $seasonal ) {
	t_section( 'Seasonal prices ' . ( $seasonal ? 'ON (no seasons defined)' : 'OFF' ) );
	Flexo_Booking_Features::set_enabled( $seasonal ? array( 'guest_emails', 'seasonal_pricing' ) : array( 'guest_emails' ) );
	$checked = 0;
	$diffs   = 0;
	foreach ( $rooms as $id ) {
		$room = Flexo_Booking_Rooms::to_array( get_post( $id ) );
		for ( $i = 0; $i < 130; $i++ ) {
			$in     = t_day( mt_rand( -30, 400 ) );
			$out    = Flexo_Booking_Dates::add_days( $in, mt_rand( 1, 21 ) );
			$old    = legacy_calculate_total( $room, $in, $out );
			$quote  = Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => $in, 'check_out' => $out ) );
			$compat = Flexo_Booking_Bookings::calculate_total( $room, $in, $out );
			++$checked;
			if ( $old !== $quote['total'] || $old !== $compat ) {
				++$diffs;
				echo "    diff {$room['slug']} {$in}→{$out}: old {$old}, new {$quote['total']}, wrapper {$compat}\n";
			}
		}
	}
	t_eq( 0, $diffs, "{$checked} random stays priced identically to 1.0.0" );
}

t_section( 'Legacy filter flexo_booking_calculate_total still applies' );
add_filter( 'flexo_booking_calculate_total', function ( $total ) { return $total * 0.9; } );
$room  = Flexo_Booking_Rooms::to_array( get_post( $rooms[0] ) );
$old   = legacy_calculate_total( $room, t_day( 0 ), t_day( 7 ) );
$quote = Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => t_day( 0 ), 'check_out' => t_day( 7 ) ) );
t_eq( $old, $quote['total'], 'filtered total identical (' . $old . ')' );
t_eq( 'adjustment', $quote['lines'][1]['type'], 'filter difference shown as an adjustment line' );
remove_all_filters( 'flexo_booking_calculate_total' );

t_section( 'Breakdown adds up' );
$quote = Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => t_day( 0 ), 'check_out' => t_day( 7 ) ) );
t_eq( 800.0, $quote['total'], 'Mon→Mon: 5 × 100 + 2 × 150 = 800' );
t_eq( $quote['total'], array_sum( wp_list_pluck( $quote['lines'], 'amount' ) ), 'lines sum to total' );
t_eq( 2, count( $quote['lines'][0]['groups'] ), 'weekday and weekend groups' );
t_eq( $quote['total'], (float) $quote['payable']['at_property'], 'Day 1: everything payable at property' );

foreach ( $rooms as $id ) {
	wp_delete_post( $id, true );
}
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
t_done();
