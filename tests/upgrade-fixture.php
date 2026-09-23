<?php
/**
 * Runs on a 1.0.0 install: creates rooms, bookings and settings, and writes
 * what they look like to $FLEXO_FIXTURE so upgrade-verify.php can compare.
 */
require __DIR__ . '/lib.php';
global $wpdb;

$deluxe = wp_insert_post( array( 'post_type' => 'flexo_room', 'post_title' => 'Deluxe Double', 'post_name' => 'deluxe-double', 'post_status' => 'publish' ) );
Flexo_Booking_Rooms::save_meta_values( $deluxe, array( '_flexo_price' => 100, '_flexo_weekend_price' => 150, '_flexo_capacity' => 2, '_flexo_units' => 2 ) );
$suite = wp_insert_post( array( 'post_type' => 'flexo_room', 'post_title' => 'Family Suite', 'post_name' => 'family-suite', 'post_status' => 'publish' ) );
Flexo_Booking_Rooms::save_meta_values( $suite, array( '_flexo_price' => 200, '_flexo_capacity' => 4, '_flexo_units' => 1, '_flexo_min_nights' => 2 ) );

$settings                  = Flexo_Booking_Settings::all();
$settings['booking_mode']  = 'instant';
$settings['currency']      = 'EUR';
$settings['terms_url']     = '/terms/';
$settings['email_request_subject'] = 'Custom subject {reference}';
update_option( Flexo_Booking_Settings::OPTION, $settings );

$g = array( 'guest_name' => 'Old Guest', 'guest_email' => 'old@example.com', 'guest_phone' => '123', 'adults' => 2 );
Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 0 ), 'check_out' => t_day( 3 ) ) ) );
Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 4 ), 'check_out' => t_day( 6 ) ) ) );
Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-suite', 'check_in' => t_day( 10 ), 'check_out' => t_day( 13 ), 'adults' => 3 ) ) );
Flexo_Booking_Bookings::create( array( 'room' => 'family-suite', 'check_in' => t_day( 20 ), 'check_out' => t_day( 22 ), 'status' => 'blocked', 'source' => 'admin' ) );

$fixture = array(
	'bookings' => $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'flexo_bookings ORDER BY id', ARRAY_A ),
	'settings' => get_option( 'flexo_booking_settings' ),
	'rooms'    => array(
		'deluxe-double' => get_post_meta( $deluxe ),
		'family-suite'  => get_post_meta( $suite ),
	),
	'db_version' => get_option( 'flexo_booking_db_version' ),
	'plugin'     => FLEXO_BOOKING_VERSION,
);
file_put_contents( getenv( 'FLEXO_FIXTURE' ), wp_json_encode( $fixture ) );
echo 'Fixture on ' . FLEXO_BOOKING_VERSION . ': ' . count( $fixture['bookings'] ) . " bookings, db version {$fixture['db_version']}\n";
