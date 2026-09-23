<?php
/**
 * Seeds the browser test for Day 2 (calendar sync + admin calendar).
 * Writes iCal feeds into <site>/feeds/ (served by the test web server) and
 * allows the site to fetch from localhost (test only).
 *
 *   wp eval-file tests/e2e/seed-day2.php
 */
require dirname( __DIR__ ) . '/lib.php';
global $wpdb;

t_reset_inventory();
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendars' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendar_events' ) );
foreach ( Flexo_Booking_Rooms::all( 'any' ) as $p ) {
	wp_delete_post( $p->ID, true );
}
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing', 'calendar_sync' ) );
$s                       = Flexo_Booking_Settings::defaults();
$s['notification_email'] = 'desk@hotel.test';
update_option( Flexo_Booking_Settings::OPTION, $s );

// Test-only: the site may fetch feeds from the local test server.
file_put_contents( WP_CONTENT_DIR . '/mu-plugins/allow-local-feeds.php', "<?php\nadd_filter( 'http_request_host_is_external', '__return_true' );\n" );

$deluxe = t_room( 'deluxe-double', 'Deluxe Double', array( 'price' => 100, 'capacity' => 2, 'units' => 3 ) );
$suite  = t_room( 'family-suite', 'Family Suite', array( 'price' => 200, 'capacity' => 4, 'units' => 1 ) );
$studio = t_room( 'garden-studio', 'Garden Studio', array( 'price' => 70, 'capacity' => 2, 'units' => 1 ) );

$g = t_guest();
Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 1 ), 'check_out' => t_day( 4 ), 'guest_name' => 'Ana Website' ) ) );
$c = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 2 ), 'check_out' => t_day( 5 ), 'guest_name' => 'Boris Confirmed' ) ) );
Flexo_Booking_Bookings::update_status( $c['id'], 'confirmed' );
Flexo_Booking_Bookings::create( array( 'room' => 'deluxe-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 6 ), 'guest_name' => 'Petya Phone', 'guest_phone' => '+359 888 555 444', 'status' => 'confirmed', 'source' => 'admin' ) );
Flexo_Booking_Bookings::create( array( 'room' => 'garden-studio', 'check_in' => t_day( 8 ), 'check_out' => t_day( 10 ), 'status' => 'blocked', 'source' => 'admin', 'notes' => 'Painting' ) );
$x = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'garden-studio', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ), 'guest_name' => 'Cancelled Guest' ) ) );
Flexo_Booking_Bookings::update_status( $x['id'], 'cancelled' );
$conflict_booking = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-suite', 'check_in' => t_day( 15 ), 'check_out' => t_day( 18 ), 'guest_name' => 'Maria Website' ) ) );
Flexo_Booking_Closures::save( array( 'room_id' => $studio, 'date_from' => t_day( 16 ), 'date_to' => t_day( 18 ), 'label' => 'Renovation' ) );

// Feeds: Booking.com (fixture) + an Airbnb booking overlapping Maria (conflict).
$dir = ABSPATH . 'feeds';
wp_mkdir_p( $dir );
$ics = preg_replace_callback(
	'/\{D(PAST2|PAST|\d+)\}/',
	function ( $m ) {
		$n = 'PAST' === $m[1] ? -20 : ( 'PAST2' === $m[1] ? -18 : (int) $m[1] );
		return str_replace( '-', '', t_day( $n ) );
	},
	file_get_contents( dirname( __DIR__ ) . '/fixtures/booking-com.ics' )
);
file_put_contents( $dir . '/suite-booking.ics', $ics );
$ymd = function ( $n ) {
	return str_replace( '-', '', t_day( $n ) );
};
file_put_contents( $dir . '/suite-airbnb.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Airbnb Inc//Hosting Calendar 1.0//EN\r\nBEGIN:VEVENT\r\nDTEND;VALUE=DATE:" . $ymd( 18 ) . "\r\nDTSTART;VALUE=DATE:" . $ymd( 16 ) . "\r\nUID:e2e-conflict@airbnb.com\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" );
file_put_contents( $dir . '/deluxe-unit2.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:" . $ymd( 6 ) . "\r\nDTEND;VALUE=DATE:" . $ymd( 8 ) . "\r\nUID:unit2@vrbo.test\r\nSUMMARY:Reserved\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" );

$base = home_url( '/feeds/' );
Flexo_Booking_ICal::add_calendar( $suite, 'Booking.com', $base . 'suite-booking.ics' );
Flexo_Booking_ICal::add_calendar( $suite, 'Airbnb', $base . 'suite-airbnb.ics' );
Flexo_Booking_ICal::add_calendar( $deluxe, 'Vrbo – no. 2', $base . 'deluxe-unit2.ics', 2 );
Flexo_Booking_ICal::add_calendar( $studio, 'Old Airbnb link', $base . 'missing.ics' );
$results = Flexo_Booking_ICal::sync_all();
foreach ( $results as $id => $r ) {
	$cal = Flexo_Booking_ICal::get_calendar( $id );
	echo $cal['name'] . ': ' . ( is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : $r['total'] . ' bookings' ) . "\n";
}
echo 'conflicts: ' . count( Flexo_Booking_ICal::open_conflicts() ) . "\n";
echo 'D0=' . t_day( 0 ) . "\n";
