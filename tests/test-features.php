<?php
/**
 * Feature switches: available (agency) vs enabled (hotel).
 * Run twice: normally, and with FLEXO_BOOKING_FEATURES / FLEXO_BOOKING_AGENCY_USERS
 * defined via --exec (see run.sh) and FLEXO_CONST_TEST=1.
 */
require __DIR__ . '/lib.php';

function render_to_string( $callback ) {
	ob_start();
	call_user_func( $callback );
	return ob_get_clean();
}

if ( getenv( 'FLEXO_CONST_TEST' ) ) {
	t_section( 'wp-config constant' );
	t_eq( 'constant', Flexo_Booking_Features::available_source(), 'constant wins' );
	t_eq( array( 'booking_request', 'seasonal_pricing', 'guest_emails' ), Flexo_Booking_Features::available_list(), 'available list from constant' );
	Flexo_Booking_Features::set_available( array( 'instant_booking' ) );
	t_eq( 'constant', Flexo_Booking_Features::available_source(), 'agency option ignored while the constant is set' );

	// FLEXO_BOOKING_AGENCY_USERS lists "support" and "agency-test@flexohotels.test".
	foreach ( array( 'flexo-agency-test', 'flexo-hotel-test' ) as $login ) {
		$old = get_user_by( 'login', $login );
		if ( $old ) {
			wp_delete_user( $old->ID );
		}
	}
	$agency = wp_insert_user( array( 'user_login' => 'flexo-agency-test', 'user_pass' => wp_generate_password(), 'user_email' => 'agency-test@flexohotels.test', 'role' => 'administrator' ) );
	$hotel  = wp_insert_user( array( 'user_login' => 'flexo-hotel-test', 'user_pass' => wp_generate_password(), 'user_email' => 'owner-test@hotel.test', 'role' => 'administrator' ) );
	t_ok( ! is_wp_error( $agency ) && ! is_wp_error( $hotel ), 'test users created' );
	t_ok( Flexo_Booking_Features::is_agency_user( get_user_by( 'id', $agency ) ), 'agency user recognised (by email)' );
	t_ok( ! Flexo_Booking_Features::is_agency_user( get_user_by( 'id', $hotel ) ), 'hotel administrator is not an agency user' );
	wp_delete_user( $agency );
	wp_delete_user( $hotel );
	Flexo_Booking_Features::set_available( null );
	t_done();
	return;
}

t_reset_inventory();
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
$settings                 = Flexo_Booking_Settings::all();
$settings['booking_mode'] = 'instant';
update_option( Flexo_Booking_Settings::OPTION, $settings );

t_section( 'Defaults' );
t_eq( 'default', Flexo_Booking_Features::available_source(), 'nothing restricted' );
t_eq( count( Flexo_Booking_Features::definitions() ), count( Flexo_Booking_Features::available_list() ), 'all ' . count( Flexo_Booking_Features::definitions() ) . ' features available' );
t_ok( Flexo_Booking_Features::is_enabled( 'instant_booking' ) && ! Flexo_Booking_Features::is_enabled( 'booking_request' ), 'booking mode is a single choice (instant)' );
t_ok( ! Flexo_Booking_Features::is_enabled( 'seasonal_pricing' ), 'seasonal prices off by default' );
Flexo_Booking_Features::set_enabled( array( 'online_payment', 'bank_transfer', 'deposit' ) );
t_ok( ! Flexo_Booking_Features::is_enabled( 'online_payment' ) && ! Flexo_Booking_Features::is_enabled( 'deposit' ), 'features not built yet cannot be switched on' );
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );

$room_id = t_room( 'feature-room', 'Feature Room', array( 'price' => 100, 'capacity' => 2, 'units' => 3 ) );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing' ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $room_id, 'name' => 'Peak', 'date_from' => t_day( 0 ), 'date_to' => t_day( 30 ), 'price' => 250 ) );
$existing = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'feature-room', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ) ) ) );
t_eq( 500.0, $existing['total'], 'booking with season price stored (2 × 250)' );
t_eq( 'confirmed', $existing['status'], 'instant mode → confirmed' );

t_section( 'Agency makes features unavailable' );
Flexo_Booking_Features::set_available( array( 'booking_request', 'guest_emails' ) );
t_eq( 'agency', Flexo_Booking_Features::available_source(), 'agency list in use' );
t_ok( ! Flexo_Booking_Features::is_enabled( 'seasonal_pricing' ), 'unavailable feature is off although the hotel had it on' );
t_ok( in_array( 'seasonal_pricing', Flexo_Booking_Features::stored_enabled(), true ), 'hotel choice kept for later' );
t_eq( array( 'request' ), Flexo_Booking_Features::booking_modes(), 'only booking requests offered' );
t_eq( 'request', Flexo_Booking_Features::booking_mode(), 'saved "instant" falls back to request' );
$q = Flexo_Booking_Pricing::quote( array( 'room' => $room_id, 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ) ) );
t_eq( 200.0, $q['total'], 'seasons ignored when unavailable (room price)' );
$req = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'feature-room', 'check_in' => t_day( 5 ), 'check_out' => t_day( 6 ) ) ) );
t_eq( 'pending', $req['status'], 'new booking is a request (pending)' );
t_eq( 500.0, Flexo_Booking_Bookings::get( $existing['id'] )['total'], 'existing booking unaffected' );
t_eq( 'confirmed', Flexo_Booking_Bookings::get( $existing['id'] )['status'], 'existing booking status unaffected' );

$tab = render_to_string( array( 'Flexo_Booking_Features', 'render_tab' ) );
t_ok( false === strpos( $tab, 'Seasonal prices' ) && false === strpos( $tab, 'Promo codes' ), 'Features tab hides unavailable features' );
t_ok( false === strpos( $tab, 'value="instant"' ), 'Features tab hides the unavailable booking mode' );
$tools = render_to_string( array( 'Flexo_Booking_Portability', 'render_page' ) );
t_ok( false === strpos( $tools, 'import_seasons' ), 'Import screen hides seasonal price option' );
$form = do_shortcode( '[flexo_booking]' );
t_ok( false !== strpos( $form, 'Send booking request' ) && false === strpos( $form, 'Confirm booking' ), 'guest form shows the request button' );

t_section( 'Filter hook for a future licence module' );
add_filter( 'flexo_booking_available_features', function ( $list ) { return array_diff( $list, array( 'guest_emails' ) ); } );
Flexo_Booking_Features::reset_cache();
t_ok( ! Flexo_Booking_Features::is_available( 'guest_emails' ), 'filter can remove features' );
remove_all_filters( 'flexo_booking_available_features' );
Flexo_Booking_Features::set_available( null );

t_section( 'Hotel disables a feature' );
Flexo_Booking_Features::set_enabled( array( 'seasonal_pricing' ) );
$GLOBALS['flexo_mails'] = array();
$b = Flexo_Booking_Bookings::create( array_merge( t_guest( array( 'guest_email' => 'noemail@example.com' ) ), array( 'room' => 'feature-room', 'check_in' => t_day( 7 ), 'check_out' => t_day( 8 ) ) ) );
$to = wp_list_pluck( $GLOBALS['flexo_mails'], 'to' );
t_ok( ! in_array( 'noemail@example.com', $to, true ) && 1 === count( $to ), 'guest emails off: only the hotel alert is sent' );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing' ) );
$GLOBALS['flexo_mails'] = array();
Flexo_Booking_Bookings::update_status( $b['id'], 'cancelled' );
t_eq( 1, count( $GLOBALS['flexo_mails'] ), 'guest emails on again: cancellation email sent' );
$settings_before = get_option( Flexo_Booking_Settings::OPTION );
Flexo_Booking_Features::set_enabled( array() );
t_eq( $settings_before, get_option( Flexo_Booking_Settings::OPTION ), 'switching features off leaves settings untouched' );

t_section( 'Settings tabs keep each other\'s values' );
$before = Flexo_Booking_Settings::all();
$clean  = Flexo_Booking_Settings::sanitize( array( 'notification_email' => 'desk@hotel.test', 'email_request_subject' => 'Hi {reference}' ) );
t_eq( $before['min_nights'], $clean['min_nights'], 'saving the Emails tab keeps General values' );
t_eq( $before['delete_data_on_uninstall'], $clean['delete_data_on_uninstall'], 'checkbox not on the tab keeps its value' );
$clean = Flexo_Booking_Settings::sanitize( array( 'delete_data_on_uninstall' => '0', 'currency' => 'bgn' ) );
t_eq( 0, $clean['delete_data_on_uninstall'], 'unticked checkbox (hidden 0) saves as off' );
t_eq( 'BGN', $clean['currency'], 'currency code upper-cased' );

wp_delete_post( $room_id, true );
t_reset_inventory();
$settings                 = Flexo_Booking_Settings::all();
$settings['booking_mode'] = 'request';
update_option( Flexo_Booking_Settings::OPTION, $settings );
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
t_done();
