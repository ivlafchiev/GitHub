<?php
/**
 * Seasonal prices and closed dates.
 * Dates are relative to next week's Monday (t_day(0)), so weekdays are fixed:
 * t_day(4) is a Friday, t_day(5) a Saturday.
 */
require __DIR__ . '/lib.php';

t_reset_inventory();
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing' ) );

$id   = t_room( 'season-room', 'Season Room', array( 'price' => 100, 'weekend_price' => 150, 'capacity' => 3, 'units' => 2, 'min_nights' => '' ) );
$room = Flexo_Booking_Rooms::to_array( get_post( $id ) );
$low  = Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Low season', 'date_from' => Flexo_Booking_Dates::display( t_day( 0 ) ), 'date_to' => Flexo_Booking_Dates::display( t_day( 13 ) ), 'price' => '80' ) );
$high = Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'High season', 'date_from' => t_day( 14 ), 'date_to' => t_day( 27 ), 'price' => '150', 'weekend_price' => '200', 'min_nights' => 3 ) );
t_ok( is_int( $low ) && is_int( $high ), 'seasons saved (DD.MM.YYYY and YYYY-MM-DD input)' );

function price( $room, $in, $out ) {
	$q = Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => $in, 'check_out' => $out ) );
	return $q['total'];
}

t_section( 'Pricing' );
t_eq( 240.0, price( $room, t_day( 1 ), t_day( 4 ) ), 'stay inside one season: 3 × 80' );
t_eq( 160.0, price( $room, t_day( 4 ), t_day( 6 ) ), 'Fri+Sat in a season without weekend price: season price, not room weekend price' );
t_eq( 610.0, price( $room, t_day( 12 ), t_day( 17 ) ), 'crossing seasons: 2 low (80) + 3 high (150)' );
t_eq( 550.0, price( $room, t_day( 18 ), t_day( 21 ) ), 'weekend price inside season: Fri 200 + Sat 200 + Sun 150' );
t_eq( 200.0, price( $room, t_day( 30 ), t_day( 32 ) ), 'outside any season: room price 2 × 100' );
t_eq( 300.0, price( $room, t_day( 32 ), t_day( 34 ) ), 'outside any season on a weekend: room weekend price 2 × 150' );
t_eq( 450.0, price( $room, t_day( 26 ), t_day( 29 ) ), 'season end is inclusive: Sat 200 + Sun 150 (last High night) + Mon 100 (room price)' );

$q = Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => t_day( 12 ), 'check_out' => t_day( 17 ) ) );
t_eq( array( 'Low season', 'High season' ), wp_list_pluck( $q['lines'][0]['groups'], 'label' ), 'breakdown groups by season' );
$rows = Flexo_Booking_Pricing::format_lines( $q );
t_eq( 2, count( $rows[0]['details'] ), 'itemised detail lines for the guest' );
echo '    e.g. ' . $rows[0]['details'][0] . ' | ' . $rows[0]['details'][1] . "\n";

t_section( 'Minimum stay from the arrival season' );
$r = Flexo_Booking_Bookings::search( t_day( 14 ), t_day( 16 ), 2, 0, 'season-room' );
t_ok( ! $r['rooms'][0]['available'] && false !== strpos( $r['rooms'][0]['reason'], 'High season' ), 'arrival in High (min 3), 2 nights: unavailable – ' . $r['rooms'][0]['reason'] );
$r = Flexo_Booking_Bookings::search( t_day( 14 ), t_day( 17 ), 2, 0, 'season-room' );
t_ok( $r['rooms'][0]['available'], 'arrival in High, 3 nights: available' );
$r = Flexo_Booking_Bookings::search( t_day( 12 ), t_day( 14 ), 2, 0, 'season-room' );
t_ok( $r['rooms'][0]['available'], 'arrival in Low running into High, 2 nights: allowed (arrival season rules)' );
$e = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'season-room', 'check_in' => t_day( 14 ), 'check_out' => t_day( 16 ) ) ) );
t_ok( is_wp_error( $e ) && 'flexo_min_nights' === $e->get_error_code(), 'booking below season minimum rejected' );
Flexo_Booking_Rooms::save_meta_values( $id, array( '_flexo_min_nights' => 2 ) );
$room = Flexo_Booking_Rooms::to_array( get_post( $id ) );
$r    = Flexo_Booking_Bookings::search( t_day( 30 ), t_day( 31 ), 2, 0, 'season-room' );
t_ok( ! $r['rooms'][0]['available'], 'outside seasons the room minimum (2) applies' );
Flexo_Booking_Rooms::save_meta_values( $id, array( '_flexo_min_nights' => '' ) );
$room = Flexo_Booking_Rooms::to_array( get_post( $id ) );

t_section( 'Validation' );
$o = Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Overlap', 'date_from' => t_day( 10 ), 'date_to' => t_day( 15 ), 'price' => 90 ) );
t_ok( is_wp_error( $o ) && 'flexo_season_overlap' === $o->get_error_code(), 'overlapping season rejected' );
if ( is_wp_error( $o ) ) {
	echo '    message: ' . $o->get_error_message() . "\n";
}
t_ok( is_int( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Low season', 'date_from' => t_day( 0 ), 'date_to' => t_day( 13 ), 'price' => '85,50' ), $low ) ), 'editing a season does not clash with itself; 85,50 accepted' );
t_eq( 85.5, Flexo_Booking_Seasons::get( $low )['price'], 'comma decimal stored as 85.5' );
Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Low season', 'date_from' => t_day( 0 ), 'date_to' => t_day( 13 ), 'price' => '80' ), $low );
t_ok( is_int( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Next', 'date_from' => t_day( 28 ), 'date_to' => t_day( 29 ), 'price' => 120 ) ) ), 'season starting the day after another ends is fine' );
$other = t_room( 'season-other', 'Other Room', array( 'price' => 50, 'capacity' => 2, 'units' => 1 ) );
t_ok( is_int( Flexo_Booking_Seasons::save( array( 'room_id' => $other, 'name' => 'Same dates', 'date_from' => t_day( 10 ), 'date_to' => t_day( 15 ), 'price' => 60 ) ) ), 'same dates on another room are fine' );
t_ok( is_wp_error( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Bad', 'date_from' => '31.02.2027', 'date_to' => '05.03.2027', 'price' => 1 ) ) ), 'invalid date rejected' );
t_ok( is_wp_error( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Bad', 'date_from' => '10.03.2031', 'date_to' => '05.03.2031', 'price' => 1 ) ) ), 'end before start rejected' );
t_ok( is_wp_error( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => 'Bad', 'date_from' => '01.03.2031', 'date_to' => '05.03.2031', 'price' => '' ) ) ), 'missing price rejected' );
t_ok( is_wp_error( Flexo_Booking_Seasons::save( array( 'room_id' => $id, 'name' => '', 'date_from' => '01.03.2031', 'date_to' => '05.03.2031', 'price' => 5 ) ) ), 'missing name rejected' );

t_section( 'Copy to next year' );
$cy = t_room( 'copy-room', 'Copy Room', array( 'price' => 70, 'capacity' => 2, 'units' => 1 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $cy, 'name' => 'Summer', 'date_from' => '01.06.2027', 'date_to' => '31.08.2027', 'price' => 120, 'weekend_price' => 140, 'min_nights' => 4 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $cy, 'name' => 'New Year', 'date_from' => '20.12.2027', 'date_to' => '05.01.2028', 'price' => 200 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $cy, 'name' => 'Leap', 'date_from' => '25.02.2028', 'date_to' => '29.02.2028', 'price' => 90 ) );
$res = Flexo_Booking_Seasons::copy_to_next_year( $cy, 2027 );
t_eq( 2, $res['copied'], 'seasons starting in 2027 copied (Summer, New Year)' );
$by_name = array();
foreach ( Flexo_Booking_Seasons::for_room( $cy ) as $s ) {
	$by_name[ $s['name'] . ' ' . substr( $s['date_from'], 0, 4 ) ] = $s;
}
t_ok( isset( $by_name['Summer 2028'] ) && '2028-08-31' === $by_name['Summer 2028']['date_to'] && 140.0 === $by_name['Summer 2028']['weekend_price'] && 4 === $by_name['Summer 2028']['min_nights'], 'Summer 2028 with same prices and minimum stay' );
t_ok( isset( $by_name['New Year 2028'] ) && '2029-01-05' === $by_name['New Year 2028']['date_to'], 'season across New Year shifted to 20.12.2028 – 05.01.2029' );
$res = Flexo_Booking_Seasons::copy_to_next_year( $cy, 2027 );
t_ok( 0 === $res['copied'] && 2 === count( $res['skipped'] ), 'copying again skips existing (overlapping) seasons' );
Flexo_Booking_Seasons::copy_to_next_year( $cy, 2028 );
$leap = array_values( array_filter( Flexo_Booking_Seasons::for_room( $cy ), function ( $s ) { return 'Leap' === $s['name'] && '2029' === substr( $s['date_from'], 0, 4 ); } ) );
t_ok( $leap && '2029-02-28' === $leap[0]['date_to'], '29.02 becomes 28.02 in a non-leap year' );
$all = Flexo_Booking_Seasons::copy_to_next_year( 0, 2030 );
t_eq( 0, $all['copied'], 'all-rooms copy for a year without seasons copies nothing' );

t_section( 'Closed periods' );
$wide = Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => t_day( 40 ), 'date_to' => t_day( 45 ), 'label' => '' ) );
t_ok( is_int( $wide ), 'property-wide closure saved' );
$r = Flexo_Booking_Bookings::search( t_day( 42 ), t_day( 44 ), 2, 0 );
t_ok( ! array_filter( wp_list_pluck( $r['rooms'], 'available' ) ), 'all rooms unavailable inside a property closure' );
t_ok( '' !== $r['notice'], 'guest notice: ' . $r['notice'] );
$e = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'season-room', 'check_in' => t_day( 44 ), 'check_out' => t_day( 47 ) ) ) );
t_ok( is_wp_error( $e ) && 'flexo_closed' === $e->get_error_code(), 'booking overlapping a closure rejected: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
$ok = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'season-room', 'check_in' => t_day( 38 ), 'check_out' => t_day( 40 ) ) ) );
t_ok( is_array( $ok ), 'checking out on the first closed day is allowed' );
$ok2 = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'season-room', 'check_in' => t_day( 46 ), 'check_out' => t_day( 48 ) ) ) );
t_ok( is_array( $ok2 ), 'arriving the day after the last closed night is allowed' );
Flexo_Booking_Closures::save( array( 'room_id' => $other, 'date_from' => t_day( 50 ), 'date_to' => t_day( 52 ), 'label' => 'Renovation' ) );
$r   = Flexo_Booking_Bookings::search( t_day( 50 ), t_day( 52 ), 1, 0 );
$map = array_column( $r['rooms'], null, 'slug' );
t_ok( ! $map['season-other']['available'] && $map['season-room']['available'], 'room closure only closes that room: ' . $map['season-other']['reason'] );
t_eq( '', $r['notice'], 'no property-wide notice for a room closure' );
$staff = Flexo_Booking_Bookings::create( array( 'room' => 'season-room', 'check_in' => t_day( 41 ), 'check_out' => t_day( 42 ), 'guest_name' => 'Staff', 'status' => 'confirmed', 'source' => 'admin' ) );
t_ok( is_array( $staff ), 'staff can still record a booking in a closed period' );
$c = Flexo_Booking_Closures::copy_to_next_year( (int) substr( t_day( 40 ), 0, 4 ) );
t_ok( $c['copied'] >= 1, 'closures copied to next year' );

t_section( 'Switching seasonal prices off' );
$booked = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'season-room', 'check_in' => t_day( 14 ), 'check_out' => t_day( 17 ) ) ) );
t_eq( 450.0, $booked['total'], 'booking in High season stored at 3 × 150' );
Flexo_Booking_Features::set_enabled( array( 'guest_emails' ) );
t_eq( 300.0, price( $room, t_day( 14 ), t_day( 17 ) ), 'new quotes fall back to the room price' );
$r = Flexo_Booking_Bookings::search( t_day( 14 ), t_day( 16 ), 2, 0, 'season-room' );
t_ok( $r['rooms'][0]['available'], 'season minimum stay no longer applies' );
t_eq( 450.0, Flexo_Booking_Bookings::get( $booked['id'] )['total'], 'existing booking keeps its price' );
t_ok( count( Flexo_Booking_Seasons::for_room( $id ) ) >= 2, 'season data kept' );
$r = Flexo_Booking_Bookings::search( t_day( 42 ), t_day( 44 ), 2, 0 );
t_ok( ! array_filter( wp_list_pluck( $r['rooms'], 'available' ) ), 'closures still apply with seasonal prices off (core)' );

foreach ( array( $id, $other, $cy ) as $post_id ) {
	Flexo_Booking_Seasons::delete_for_room( $post_id );
	wp_delete_post( $post_id, true );
}
t_reset_inventory();
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
t_done();
