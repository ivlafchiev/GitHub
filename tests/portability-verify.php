<?php
/**
 * Fresh site after `wp flexo-booking import`: everything arrived.
 */
require __DIR__ . '/lib.php';
t_section( 'Import into a fresh site' );
$a = get_page_by_path( 'sea-view', OBJECT, 'flexo_room' );
$b = get_page_by_path( 'garden-studio', OBJECT, 'flexo_room' );
t_ok( $a && $b, 'rooms created by slug' );
$sa = Flexo_Booking_Seasons::for_room( $a->ID );
t_eq( 2, count( $sa ), 'Sea View has 2 seasons' );
t_ok( 'Summer' === $sa[0]['name'] && '2027-06-01' === $sa[0]['date_from'] && 140.0 === $sa[0]['price'] && 160.0 === $sa[0]['weekend_price'] && 3 === $sa[0]['min_nights'], 'season details intact' );
t_eq( 1, count( Flexo_Booking_Seasons::for_room( $b->ID ) ), 'Garden Studio has 1 season' );
$closures = Flexo_Booking_Closures::all();
t_eq( 2, count( $closures ), '2 closed periods' );
t_ok( 0 === $closures[1]['room_id'] || 0 === $closures[0]['room_id'], 'property-wide closure kept as property-wide' );
$room_closure = array_values( array_filter( $closures, function ( $c ) { return $c['room_id'] > 0; } ) );
t_eq( $b->ID, $room_closure[0]['room_id'], 'room closure mapped to the new room ID' );
t_ok( Flexo_Booking_Features::is_enabled( 'seasonal_pricing' ), 'seasonal prices switched on like the template' );
t_eq( 'space_comma', Flexo_Booking_Settings::get( 'number_format' ), 'currency format imported' );
t_eq( 'owner@client.test', Flexo_Booking_Settings::notification_email(), 'notification email stays the client\'s own' );
$q = Flexo_Booking_Pricing::quote( array( 'room' => 'sea-view', 'check_in' => '2027-06-07', 'check_out' => '2027-06-10' ) );
t_eq( 420.0, $q['total'], 'imported seasons price stays (Mon–Wed 3 × 140)' );
t_done();
