<?php
/**
 * 1.0.0 behaviour that must keep working, plus currency formatting.
 */
require __DIR__ . '/lib.php';

t_reset_inventory();
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
$settings                 = Flexo_Booking_Settings::all();
$settings['booking_mode'] = 'request';
$settings['min_nights']   = 1;
$settings['terms_url']    = '';
update_option( Flexo_Booking_Settings::OPTION, $settings );

$deluxe = t_room( 'deluxe-double', 'Deluxe Double', array( 'price' => 100, 'weekend_price' => 150, 'capacity' => 2, 'units' => 2 ) );
$suite  = t_room( 'family-suite', 'Family Suite', array( 'price' => 200, 'capacity' => 4, 'units' => 1, 'min_nights' => 2 ) );
$room   = Flexo_Booking_Rooms::to_array( get_post( $deluxe ) );

t_section( 'Validation' );
t_ok( is_wp_error( Flexo_Booking_Bookings::validate_dates( '2020-01-01', '2020-01-03' ) ), 'past dates rejected' );
t_ok( is_wp_error( Flexo_Booking_Bookings::validate_dates( t_day( 3 ), t_day( 3 ) ) ), 'zero nights rejected' );
t_ok( is_wp_error( Flexo_Booking_Bookings::validate_dates( '2026-02-30', t_day( 3 ) ) ), 'invalid date rejected' );
t_ok( is_wp_error( Flexo_Booking_Bookings::search( t_day( 0 ), t_day( 40 ), 2, 0 ) ), 'max nights enforced' );
$g = t_guest();
t_ok( is_wp_error( Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 0 ), 'check_out' => t_day( 2 ), 'guest_email' => 'bad' ) ) ) ), 'bad email rejected' );
t_ok( is_wp_error( Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 0 ), 'check_out' => t_day( 2 ), 'adults' => 3 ) ) ) ), 'over capacity rejected' );
t_ok( is_wp_error( Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 0 ), 'check_out' => t_day( 2 ), 'guest_phone' => '' ) ) ) ), 'missing phone rejected' );

t_section( 'Search' );
$r   = Flexo_Booking_Bookings::search( t_day( 0 ), t_day( 1 ), 2, 0 );
$map = array_column( $r['rooms'], null, 'slug' );
t_ok( $map['deluxe-double']['available'] && 2 === $map['deluxe-double']['units_left'], 'deluxe available, 2 units' );
t_ok( ! $map['family-suite']['available'], 'suite: room minimum 2 nights – ' . $map['family-suite']['reason'] );
t_eq( '100.00 €', $map['deluxe-double']['price_formatted'], 'price_formatted unchanged' );
t_eq( 'Total for 1 night', $r['nights_label'], 'nights label' );
$r   = Flexo_Booking_Bookings::search( t_day( 0 ), t_day( 2 ), 3, 1 );
$map = array_column( $r['rooms'], null, 'slug' );
t_ok( ! $map['deluxe-double']['available'] && $map['family-suite']['available'], 'capacity filter' );

t_section( 'Bookings and inventory' );
$a = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 0 ), 'check_out' => t_day( 2 ) ) ) );
t_ok( is_array( $a ) && 'pending' === $a['status'] && 200.0 === $a['total'], 'booking request created: pending, 200' );
t_ok( ! empty( $a['price_breakdown'] ), 'price breakdown stored on the booking' );
$b = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => $deluxe, 'check_in' => t_day( 2 ), 'check_out' => t_day( 4 ) ) ) );
t_ok( is_array( $b ), 'booking by room ID' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $room, t_day( 0 ), t_day( 4 ) ), 'non-overlapping bookings take one unit' );
$c = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ) ) ) );
t_ok( is_array( $c ), 'third booking fits' );
$x = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 1 ), 'check_out' => t_day( 2 ) ) ) );
t_ok( is_wp_error( $x ) && 'flexo_unavailable' === $x->get_error_code(), 'overbooking prevented' );
t_ok( true === Flexo_Booking_Bookings::update_status( $c['id'], 'cancelled' ), 'cancel' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $room, t_day( 1 ), t_day( 2 ) ), 'cancel frees a unit' );
$x = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 1 ), 'check_out' => t_day( 2 ) ) ) );
t_ok( is_array( $x ), 'rebook after cancel' );
t_ok( is_wp_error( Flexo_Booking_Bookings::update_status( $c['id'], 'confirmed' ) ), 'reinstate blocked when full' );
t_ok( true === Flexo_Booking_Bookings::update_status( $a['id'], 'confirmed' ), 'confirm' );

t_section( 'Staff bookings and blocked dates' );
$blk = Flexo_Booking_Bookings::create( array( 'room' => 'family-suite', 'check_in' => t_day( 10 ), 'check_out' => t_day( 12 ), 'status' => 'blocked', 'source' => 'admin' ) );
t_ok( is_array( $blk ) && 'blocked' === $blk['status'] && 0.0 === $blk['total'] && null === $blk['price_breakdown'], 'block: status blocked, total 0, no breakdown' );
$r = Flexo_Booking_Bookings::search( t_day( 10 ), t_day( 12 ), 2, 0, 'family-suite' );
t_ok( ! $r['rooms'][0]['available'], 'blocked dates unavailable' );
$man = Flexo_Booking_Bookings::create( array( 'room' => 'family-suite', 'check_in' => t_day( 20 ), 'check_out' => t_day( 21 ), 'guest_name' => 'Phone Guest', 'status' => 'confirmed', 'source' => 'admin' ) );
t_ok( is_array( $man ) && 200.0 === $man['total'], 'manual booking ignores the room minimum and is priced by the service' );

t_section( 'Instant booking' );
$settings['booking_mode'] = 'instant';
update_option( Flexo_Booking_Settings::OPTION, $settings );
$i = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-suite', 'check_in' => t_day( 30 ), 'check_out' => t_day( 33 ), 'children' => 2 ) ) );
t_ok( is_array( $i ) && 'confirmed' === $i['status'], 'instant booking confirmed' );
$settings['booking_mode'] = 'request';
update_option( Flexo_Booking_Settings::OPTION, $settings );

t_section( 'Emails' );
$GLOBALS['flexo_mails'] = array();
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'deluxe-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 7 ) ) ) );
t_eq( 2, count( $GLOBALS['flexo_mails'] ), 'guest + hotel email' );
$body = $GLOBALS['flexo_mails'][0]['message'];
t_ok( false !== strpos( $body, 'Total: 500.00 €' ), 'guest email total (Thu 100, Fri 150, Sat 150, Sun 100)' );
t_ok( false !== strpos( $body, 'Standard rate – weekend: 2 nights × 150.00 €' ), 'guest email lists weekend nights' );
echo "    ---\n    " . str_replace( "\n", "\n    ", $body ) . "\n    ---\n";
$ph = Flexo_Booking_Emails::placeholders( Flexo_Booking_Bookings::get( $e['id'] ) );
t_ok( false !== strpos( $ph['{price_breakdown}'], 'Total: 500.00 €' ), '{price_breakdown} placeholder' );

t_section( 'Admin list query and CSV details' );
$q = Flexo_Booking_Bookings::query( array( 'search' => 'guest@' ) );
t_ok( $q['total'] >= 5, 'search by email: ' . $q['total'] );
t_ok( Flexo_Booking_Bookings::count_pending() >= 1, 'pending count' );
$text = Flexo_Booking_Pricing::summary_text( Flexo_Booking_Pricing::snapshot( Flexo_Booking_Bookings::get( $e['id'] ) ) );
t_ok( false !== strpos( $text, 'Accommodation, 4 nights: 500.00 €' ), 'CSV price details text' );

t_section( 'Currency' );
$fmt = function ( $changes, $amount, $currency = null ) use ( $settings ) {
	update_option( Flexo_Booking_Settings::OPTION, array_merge( $settings, $changes ) );
	return Flexo_Booking_Money::format( $amount, $currency );
};
t_eq( '1,234.50 €', $fmt( array(), 1234.5 ), 'default: EUR, symbol after, site language' );
t_eq( '€1,234.50', $fmt( array( 'currency_position' => 'before' ), 1234.5 ), 'symbol before (1.0 option)' );
t_eq( '1 234,50 €', str_replace( "\xC2\xA0", ' ', $fmt( array( 'number_format' => 'space_comma' ), 1234.5 ) ), 'Bulgarian style 1 234,50 €' );
t_eq( '1.234,50€', $fmt( array( 'number_format' => 'dot_comma', 'currency_position' => 'after_nospace' ), 1234.5 ), '1.234,50€' );
t_eq( '€ 1235', $fmt( array( 'number_format' => 'none_dot', 'currency_position' => 'before_space', 'currency_decimals' => 0 ), 1234.5 ), 'no decimals' );
t_eq( '200.00 BGN', $fmt( array(), 200, 'BGN' ), 'old booking in another currency shows its own code' );
update_option( Flexo_Booking_Settings::OPTION, array_merge( $settings, array( 'currency' => 'BGN', 'currency_symbol' => 'лв.' ) ) );
t_eq( 200.0, Flexo_Booking_Bookings::get( $a['id'] )['total'], 'changing currency never converts stored amounts' );
t_eq( '200.00 EUR', Flexo_Booking_Money::format( 200, Flexo_Booking_Bookings::get( $a['id'] )['currency'] ), 'EUR booking keeps its currency after switching to BGN' );
t_eq( 100.0, Flexo_Booking_Rooms::to_array( get_post( $deluxe ) )['price'], 'room price unchanged' );
update_option( Flexo_Booking_Settings::OPTION, $settings );

t_reset_inventory();
t_done();
