<?php
// UI audit: the Day 6 seed plus every feature, demo rooms with photos, bookings in every state, a season, a promo code, a closure.
require dirname( __DIR__ ) . '/e2e/seed-day6.php';
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'instant_booking', 'seasonal_pricing', 'calendar_sync', 'rate_plans', 'children', 'tourist_tax', 'promo_codes', 'privacy_consent', 'invoice_request', 'guest_emails', 'guest_booking_page', 'tracking', 'custom_appearance', 'online_payment', 'deposit', 'bank_transfer' ) );
Flexo_Booking_Demo_Rooms::add();
$s = Flexo_Booking_Settings::all();
$s['hotel_name'] = 'Hotel Azure Bay';
update_option( Flexo_Booking_Settings::OPTION, $s );
$rooms = Flexo_Booking_Rooms::all();
$slug  = $rooms[0]->post_name;
$d     = static function ( $n ) { return wp_date( 'Y-m-d', strtotime( "+{$n} days" ) ); };
$make  = static function ( $room, $in, $out, $name, $status, $extra = array() ) {
	return Flexo_Booking_Bookings::create( array_merge( array( 'room' => $room, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'guest_name' => $name, 'guest_email' => sanitize_title( $name ) . '@example.com', 'guest_phone' => '+359 888 123 456', 'source' => 'admin', 'status' => $status ), $extra ) );
};
$names = array( 'Maria Ivanova', 'John Smith', 'Elena Petrova', 'Georgi Dimitrov', 'Anna Müller', 'Luca Rossi', 'Sophie Martin', 'Ivan Georgiev' );
$i = 0;
foreach ( $rooms as $k => $room ) {
	if ( $k > 3 ) {
		break;
	}
	foreach ( array( array( 0, 3, 'confirmed' ), array( 5, 8, 'pending' ), array( 12, 15, 'confirmed' ), array( -3, 0, 'confirmed' ), array( 20, 22, 'cancelled' ) ) as $b ) {
		$r = $make( $room->post_name, $d( $b[0] ), $d( $b[1] ), $names[ $i % count( $names ) ], $b[2], array( 'notes' => 0 === $i % 3 ? 'Late arrival around 22:00, baby cot please.' : '' ) );
		++$i;
	}
}
Flexo_Booking_Promo_Codes::save( array( 'code' => 'SUMMER10', 'description' => 'Summer 10% off', 'active' => 1, 'discount_type' => 'percent', 'discount_value' => 10 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $rooms[0]->ID, 'name' => 'High season', 'date_from' => $d( 60 ), 'date_to' => $d( 120 ), 'price' => 180, 'weekend_price' => 0, 'min_nights' => 3 ) );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => $d( 200 ), 'date_to' => $d( 210 ), 'label' => 'Renovation' ) );
echo 'rooms=', count( $rooms ), ' bookings=', $i, "\n";
