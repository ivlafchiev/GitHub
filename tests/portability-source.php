<?php
/**
 * Template site: rooms, seasons, closures and features; then export to $FLEXO_EXPORT.
 */
require __DIR__ . '/lib.php';
t_reset_inventory();
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing' ) );
$a = t_room( 'sea-view', 'Sea View Double', array( 'price' => 90, 'weekend_price' => 110, 'capacity' => 2, 'units' => 4 ) );
$b = t_room( 'garden-studio', 'Garden Studio', array( 'price' => 70, 'capacity' => 3, 'units' => 2 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $a, 'name' => 'Summer', 'date_from' => '01.06.2027', 'date_to' => '31.08.2027', 'price' => 140, 'weekend_price' => 160, 'min_nights' => 3 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $a, 'name' => 'Christmas', 'date_from' => '20.12.2027', 'date_to' => '02.01.2028', 'price' => 180 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $b, 'name' => 'Summer', 'date_from' => '01.06.2027', 'date_to' => '31.08.2027', 'price' => 99 ) );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => '01.11.2027', 'date_to' => '31.03.2028', 'label' => 'Closed for the winter' ) );
Flexo_Booking_Closures::save( array( 'room_id' => $b, 'date_from' => '10.05.2027', 'date_to' => '20.05.2027', 'label' => '' ) );
$s                      = Flexo_Booking_Settings::all();
$s['number_format']     = 'space_comma';
$s['currency_position'] = 'after';
$s['notification_email'] = 'template@agency.test';
update_option( Flexo_Booking_Settings::OPTION, $s );
file_put_contents( getenv( 'FLEXO_EXPORT' ), wp_json_encode( Flexo_Booking_Portability::export() ) );
echo "Exported template setup\n";
