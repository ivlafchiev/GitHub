<?php
/**
 * Day 6: usability and appearance. Migration 7, room and rate details,
 * phone numbers, the guest key, confirmation view and .ics, the date
 * picker's availability, nearby dates, enquiries, guest requests, emails
 * (blocks, booking page link, calendar file), bookings list filters, Today,
 * booking history, roles and capabilities, settings a manager may change,
 * health checks, wizard helpers, appearance, Import/Export and privacy.
 */
require __DIR__ . '/lib.php';

global $wpdb;
$t6_saved_settings = get_option( Flexo_Booking_Settings::OPTION );
$t6_saved_features = get_option( Flexo_Booking_Features::ENABLED_OPTION );
$t6_saved_secrets  = get_option( Flexo_Booking_Payments::SECRETS_OPTION );
t_reset_inventory();
foreach ( array( 'email_log', 'booking_log', 'payments' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'instant_booking', 'guest_emails', 'rate_plans', 'seasonal_pricing', 'guest_booking_page', 'custom_appearance', 'privacy_consent' ) );
add_filter( 'flexo_booking_rate_limit', '__return_zero' );
add_filter( 'flexo_booking_enquiry_limit', static function () {
	return 100;
} );
wp_set_current_user( 0 );

function t6_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function t6_mails( $reset = false ) {
	$mails = isset( $GLOBALS['flexo_mails'] ) ? $GLOBALS['flexo_mails'] : array();
	if ( $reset ) {
		$GLOBALS['flexo_mails'] = array();
	}
	return $mails;
}
function t6_rest( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, '/flexo-booking/v1/' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

t6_settings(
	array(
		'booking_mode'        => 'instant',
		'min_nights'          => 1,
		'hotel_phone'         => '+359 52 111 222',
		'hotel_email'         => 'desk@sunrise.test',
		'hotel_address'       => "12 Sea Street\nVarna 9000",
		'notification_email'  => 'reception@sunrise.test',
		'request_reply_hours' => 48,
		'booking_page'        => '/booking/',
		'privacy_consent_required' => 0,
	)
);
// Only this test's rooms (other tests create theirs again).
foreach ( get_posts( array( 'post_type' => 'flexo_room', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $flexo_other ) {
	if ( ! in_array( get_post_field( 'post_name', $flexo_other ), array( 't6-double', 't6-family' ), true ) ) {
		wp_delete_post( $flexo_other, true );
	}
}
function t6_fresh_attention() {
	$ref = new ReflectionProperty( 'Flexo_Booking_Today_Admin', 'attention' );
	$ref->setAccessible( true );
	$ref->setValue( null, null );
	return wp_list_pluck( Flexo_Booking_Today_Admin::attention(), 'type' );
}
$double = t_room( 't6-double', 'T6 Double', array( 'price' => 100, 'capacity' => 2, 'units' => 1 ) );
$family = t_room( 't6-family', 'T6 Family', array( 'price' => 150, 'capacity' => 4, 'units' => 2, 'size' => 32, 'beds' => '1 double + 2 single', 'amenities' => array( 'wifi', 'balcony', 'sea_view', 'kitchen', 'tv', 'safe', 'parking' ) ) );

/* ------------------------------------------------------------------ */
t_section( 'Migration 7' );
t_ok( Flexo_Booking_Migrations::current_version() >= 7, 'database version 7' );
t_ok( Flexo_Booking_Schema::table_exists( 'booking_log' ), 'booking history table' );
t_ok( Flexo_Booking_Schema::column_exists( 'bookings', 'staff_notes' ) && Flexo_Booking_Schema::column_exists( 'rate_plans', 'meals' ), 'staff notes and rate meals columns' );
t_ok( get_role( 'hotel_staff' ) && get_role( 'hotel_manager' ), 'Hotel Staff and Hotel Manager roles' );
t_ok( get_role( 'editor' )->has_cap( 'flexo_manage_prices' ) && get_role( 'administrator' )->has_cap( 'flexo_manage_settings' ), 'editors and administrators keep their access' );
$presets = Flexo_Booking_Rate_Plans::presets();
t_eq( 'half_board', $presets['half_board']['meals'], 'ready-made plans state their meals' );

/* ------------------------------------------------------------------ */
t_section( 'Room and rate details' );
$room = Flexo_Booking_Rooms::to_array( get_post( $family ) );
t_ok( 32 === $room['size'] && '1 double + 2 single' === $room['beds'] && 7 === count( $room['amenities'] ), 'size, beds, amenities stored' );
$search = Flexo_Booking_Bookings::search( t_day( 10 ), t_day( 12 ), 2, 0 );
$card   = null;
foreach ( $search['rooms'] as $r ) {
	if ( $r['id'] === $family ) {
		$card = $r;
	}
}
t_ok( $card && 32 === $card['size'] && in_array( 'Free Wi-Fi', $card['amenities'], true ), 'search results carry size and amenity names' );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'rate_plans' ) );
Flexo_Booking_Rate_Plans::flush_cache();
$bb = Flexo_Booking_Rate_Plans::save( array( 'name' => 'B&B', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 10, 'meals' => 'breakfast', 'active' => 1, 'refundable' => 1 ) );
$nr = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Saver', 'adjustment_type' => 'percent', 'adjustment_value' => -10, 'meals' => 'bogus', 'active' => 1, 'refundable' => 0 ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $family, array( $bb => null, $nr => null ) );
Flexo_Booking_Rate_Plans::flush_cache();
t_eq( 'breakfast', Flexo_Booking_Rate_Plans::get( $bb )['meals'], 'meals saved' );
t_eq( '', Flexo_Booking_Rate_Plans::get( $nr )['meals'], 'unknown meals value refused' );
$q = Flexo_Booking_Pricing::public_view( Flexo_Booking_Pricing::quote( array( 'room' => $room, 'check_in' => t_day( 10 ), 'check_out' => t_day( 12 ), 'adults' => 2, 'rate_plan_id' => $bb ) ) );
t_eq( 'Breakfast included', $q['rate_plan']['meals_label'], 'rate row shows the meals' );

/* ------------------------------------------------------------------ */
t_section( 'Phone numbers' );
t_eq( '+359 888 123 456', Flexo_Booking_Phone::normalize( '0888 123 456', 'BG' ), 'national number + country code' );
t_eq( '+44 20 7946 0000', Flexo_Booking_Phone::normalize( '0044 20 7946 0000', 'BG' ), '00 prefix keeps its own code' );
t_eq( '+49 30 1234567', Flexo_Booking_Phone::normalize( '+49 30 1234567', 'BG' ), '+ prefix kept' );
t_ok( ! Flexo_Booking_Phone::looks_valid( '12' ) && Flexo_Booking_Phone::looks_valid( '+359 888 123 456' ), 'too short numbers refused' );
$bad = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 't6-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 4 ), 'guest_phone' => '1', 'source' => 'website' ) ) );
t_ok( is_wp_error( $bad ) && 'flexo_invalid_phone' === $bad->get_error_code(), 'booking with a one-digit phone refused' );
$ok = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 't6-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 4 ), 'guest_phone' => '0888 111 222', 'phone_country' => 'BG', 'source' => 'website' ) ) );
t_eq( '+359 888 111 222', $ok['guest_phone'], 'stored with the country code' );
$countries = Flexo_Booking_Phone::countries( 'en_US' );
t_ok( count( $countries ) > 190 && '359' === $countries['BG']['code'], 'country list with calling codes' );

/* ------------------------------------------------------------------ */
t_section( 'Confirmation, key and .ics' );
t6_mails( true );
list( $status, $created ) = t6_rest(
	'POST',
	'bookings',
	t_guest(
		array(
			'room'          => 't6-double',
			'check_in'      => t_day( 20 ),
			'check_out'     => t_day( 23 ),
			'guest_email'   => 'maria@example.com',
			'phone_country' => 'BG',
			'guest_phone'   => '0888 555 666',
		)
	)
);
t_eq( 201, $status, 'booking created' );
t_ok( ! empty( $created['key'] ) && ! empty( $created['view'] ), 'response has the guest key and the full confirmation' );
$v = $created['view'];
t_ok( 'confirmed' === $v['state'] && '3 nights' === $v['nights_label'] && '2 adults' === $v['guests'], 'view: state, nights, guests' );
t_ok( $v['contact']['phone'] === '+359 52 111 222' && 'desk@sunrise.test' === $v['contact']['email'] && false !== strpos( $v['contact']['directions'], 'google.com/maps' ), 'contact and directions' );
t_ok( in_array( 'Check-in from 14:00, check-out until 11:00.', $v['next_steps'], true ), 'next steps' );
t_ok( false !== strpos( $v['ics'], 'booking.ics' ) && false !== strpos( $v['manage'], 'fb_manage=' ), 'calendar and guest booking page links' );
$b = Flexo_Booking_Bookings::get_by_reference( $created['reference'] );
t_ok( Flexo_Booking_Guest::check( $b, $created['key'] ) && ! Flexo_Booking_Guest::check( $b, 'wrong' ) && ! Flexo_Booking_Guest::check( $b, '' ), 'key accepted, wrong key refused' );
list( $status, $view ) = t6_rest( 'GET', 'guest-booking', array( 'reference' => $b['reference'], 'key' => $created['key'] ) );
t_ok( 200 === $status && $view['reference'] === $b['reference'], 'confirmation again after a refresh' );
list( $status ) = t6_rest( 'GET', 'guest-booking', array( 'reference' => $b['reference'], 'key' => substr( $created['key'], 0, -1 ) . 'x' ) );
t_eq( 404, $status, 'guessed key: not found' );
$ics = Flexo_Booking_Guest::ics( $b );
t_ok( false !== strpos( $ics, 'DTSTART;VALUE=DATE:' . str_replace( '-', '', t_day( 20 ) ) ) && false !== strpos( $ics, 'DTEND;VALUE=DATE:' . str_replace( '-', '', t_day( 23 ) ) ), '.ics: all-day from arrival to departure' );
t_ok( false !== strpos( $ics, 'LOCATION:12 Sea Street\\, Varna 9000' ) && false !== strpos( $ics, "\r\n" ), '.ics: address, CRLF line ends' );
$mail = null;
foreach ( t6_mails() as $m ) {
	if ( 'maria@example.com' === ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] ) ) {
		$mail = $m;
	}
}
t_ok( $mail && false !== strpos( $mail['message'], '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px' ), 'email: booking details as a table' );
t_ok( $mail && false !== strpos( $mail['message'], 'fb_manage=' ) && false !== strpos( $mail['message'], 'View your booking or ask us for a change' ), 'email: guest booking page link' );
t_ok( $mail && ! empty( $mail['attachments'] ) && '.ics' === substr( $mail['attachments'][0], -4 ), 'email: .ics attached to the confirmation' );
t_ok( $mail && ! file_exists( $mail['attachments'][0] ), 'temporary .ics removed after sending' );
Flexo_Booking_Features::set_enabled( array_diff( Flexo_Booking_Features::default_enabled(), array( 'guest_booking_page' ) ) + array( 99 => 'rate_plans' ) );
t_eq( '', Flexo_Booking_Guest::links( $b )['manage'], 'feature off: no guest booking page link' );
list( $status ) = t6_rest( 'GET', 'guest-booking', array( 'reference' => $b['reference'], 'key' => $created['key'], 'manage' => 1 ) );
t_eq( 404, $status, 'feature off: guest booking page not available' );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'instant_booking', 'guest_emails', 'rate_plans', 'seasonal_pricing', 'guest_booking_page', 'custom_appearance', 'privacy_consent' ) );

/* ------------------------------------------------------------------ */
t_section( 'Guest requests (change / cancel)' );
t6_mails( true );
$r = Flexo_Booking_Guest::send_request( $b, 'change', '' );
t_ok( is_wp_error( $r ) && 'message' === $r->get_error_data()['field'], 'change request needs a message' );
$r = Flexo_Booking_Guest::send_request( $b, 'cancel', 'Plans changed' );
t_ok( ! is_wp_error( $r ) && false !== strpos( $r['message'], 'within 2 days' ), 'request sent, reply time from the settings' );
t_eq( 'confirmed', Flexo_Booking_Bookings::get( $b['id'] )['status'], 'nothing changes by itself' );
$hotel = t6_mails();
t_ok( 1 === count( $hotel ) && 'reception@sunrise.test' === $hotel[0]['to'][0] && false !== strpos( $hotel[0]['subject'], 'Cancellation request' ), 'hotel emailed' );
Flexo_Booking_Guest::send_request( $b, 'change', 'One more night?' );
Flexo_Booking_Guest::send_request( $b, 'change', 'Or two?' );
$r = Flexo_Booking_Guest::send_request( $b, 'change', 'Four' );
t_ok( is_wp_error( $r ) && 'flexo_rate_limited' === $r->get_error_code(), 'at most 3 requests a day' );
$items = t6_fresh_attention();
t_ok( in_array( 'guest_request', $items, true ), 'requests appear under "Needs your attention"' );

/* ------------------------------------------------------------------ */
t_section( 'Date picker availability' );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => t_day( 40 ), 'date_to' => t_day( 41 ), 'reason' => 'Event' ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $double, 'name' => 'Peak', 'date_from' => t_day( 50 ), 'date_to' => t_day( 56 ), 'price' => 120, 'min_nights' => 3 ) );
Flexo_Booking_Closures::flush_cache();
Flexo_Booking_Seasons::flush_cache();
$month = substr( t_day( 20 ), 0, 7 );
$cal   = Flexo_Booking_Guest::calendar( $month, 3, 't6-double', 2, 0 );
$idx   = static function ( $ymd ) use ( $cal ) {
	return (int) ( new DateTimeImmutable( $cal['from'] ) )->diff( new DateTimeImmutable( $ymd ) )->format( '%a' );
};
t_eq( 1, count( $cal['rooms'] ), 'one room asked for' );
t_eq( '0', $cal['rooms'][0]['free'][ $idx( t_day( 21 ) ) ], 'booked night shown as full' );
t_eq( 'c', $cal['rooms'][0]['free'][ $idx( t_day( 40 ) ) ], 'closed night shown as closed' );
t_eq( 3, $cal['rooms'][0]['min'][ $idx( t_day( 50 ) ) ], 'season minimum stay for arrivals' );
$big = Flexo_Booking_Guest::calendar( $month, 1, '', 3, 0 );
t_eq( array( $family ), wp_list_pluck( $big['rooms'], 'id' ), 'only rooms that fit the guests' );
t_eq( array(), $cal['prices'], 'no prices unless switched on' );
t6_settings( array( 'picker_prices' => 1 ) );
$cal = Flexo_Booking_Guest::calendar( $month, 1, 't6-double', 2, 0 );
t_ok( count( $cal['prices'] ) > 5, '"from" prices when switched on' );
t6_settings( array( 'picker_prices' => 0 ) );
list( $status ) = t6_rest( 'GET', 'calendar', array( 'month' => '2026-13' ) );
t_eq( 400, $status, 'invalid month refused' );

/* ------------------------------------------------------------------ */
t_section( 'Nothing free: nearby dates and enquiries' );
$alt = Flexo_Booking_Guest::alternatives( t_day( 20 ), t_day( 23 ), 2, 0, '', 't6-double' );
t_ok( count( $alt['dates'] ) >= 1 && count( $alt['dates'] ) <= 3, 'up to 3 nearby stays' );
foreach ( $alt['dates'] as $d ) {
	t_ok( 3 === (int) ( new DateTimeImmutable( $d['check_in'] ) )->diff( new DateTimeImmutable( $d['check_out'] ) )->days && ( $d['check_out'] <= t_day( 20 ) || $d['check_in'] >= t_day( 23 ) ), 'same length, not overlapping the booked stay: ' . $d['check_in'] );
}
t_eq( 1, $alt['other_rooms'], 'the other room is free on those dates' );
t6_mails( true );
$e = Flexo_Booking_Guest::enquiry( array( 'name' => '', 'email' => 'x@example.com' ) );
t_ok( is_wp_error( $e ) && 'name' === $e->get_error_data()['field'], 'enquiry needs a name' );
$e = Flexo_Booking_Guest::enquiry( array( 'name' => 'Ivan', 'email' => 'bad' ) );
t_ok( is_wp_error( $e ) && 'email' === $e->get_error_data()['field'], 'enquiry needs a valid email' );
$e = Flexo_Booking_Guest::enquiry( array( 'name' => 'Bot', 'email' => 'bot@example.com', 'fb_website' => 'http://spam' ) );
t_ok( is_wp_error( $e ), 'honeypot' );
$e = Flexo_Booking_Guest::enquiry( array( 'name' => 'Ivan Petrov', 'email' => 'ivan@example.com', 'phone' => '0888 777 888', 'phone_country' => 'BG', 'message' => 'Any room?', 'check_in' => t_day( 20 ), 'check_out' => t_day( 23 ), 'adults' => 2 ) );
t_ok( ! is_wp_error( $e ) && $e['sent'], 'enquiry sent' );
$m = t6_mails();
t_ok( 1 === count( $m ) && false !== strpos( $m[0]['subject'], 'Enquiry from Ivan Petrov' ) && in_array( 'Reply-To: Ivan Petrov <ivan@example.com>', $m[0]['headers'], true ), 'hotel emailed, reply goes to the guest' );
t_ok( false !== strpos( $m[0]['message'], 'This is not a booking' ), 'email says it is not a booking' );
$log = Flexo_Booking_Log::recent( 'enquiry' );
t_ok( 1 === count( $log ) && '+359 888 777 888' === $log[0]['details']['phone'], 'enquiry logged' );
t_eq( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Install::table() . " WHERE guest_email = 'ivan@example.com'" ), 'no booking created' );
t_ok( in_array( 'enquiry', t6_fresh_attention(), true ), 'enquiry under "Needs your attention"' );
$export = Flexo_Booking_Privacy::export( 'ivan@example.com' );
t_ok( in_array( 'flexo-booking-enquiries', wp_list_pluck( $export['data'], 'group_id' ), true ), 'enquiry in the personal data export' );
Flexo_Booking_Privacy::erase( 'ivan@example.com' );
t_eq( array(), Flexo_Booking_Log::enquiries_by_email( 'ivan@example.com' ), 'enquiry erased on request' );

/* ------------------------------------------------------------------ */
t_section( 'Bookings list: filters and order' );
$wpdb->insert( Flexo_Booking_Install::table(), array( 'reference' => 'FB-T6PAST', 'room_id' => $family, 'check_in' => t_day( -20 ), 'check_out' => t_day( -18 ), 'nights' => 2, 'adults' => 2, 'guest_name' => 'Past Guest', 'status' => 'confirmed', 'source' => 'admin', 'currency' => 'EUR', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$list = Flexo_Booking_Bookings::query( array( 'order' => 'upcoming', 'per_page' => 0 ) );
$first = $list['items'][0];
t_ok( $first['check_out'] >= wp_date( 'Y-m-d' ) && end( $list['items'] )['check_in'] < wp_date( 'Y-m-d' ), 'current and upcoming first, past stays last' );
t_eq( 1, Flexo_Booking_Bookings::query( array( 'source' => 'admin' ) )['total'], 'filter: added by staff' );
t_eq( 1, Flexo_Booking_Bookings::query( array( 'arrival_from' => t_day( 19 ), 'arrival_to' => t_day( 21 ) ) )['total'], 'filter: arrival between two dates' );

/* ------------------------------------------------------------------ */
t_section( 'Today' );
$today = wp_date( 'Y-m-d' );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Install::table() );
$arr = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 't6-family', 'rate_plan' => $bb, 'check_in' => $today, 'check_out' => Flexo_Booking_Dates::add_days( $today, 2 ), 'source' => 'admin', 'status' => 'confirmed' ) ) );
$dep = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 't6-double', 'check_in' => Flexo_Booking_Dates::add_days( $today, 1 ), 'check_out' => Flexo_Booking_Dates::add_days( $today, 3 ), 'source' => 'admin', 'status' => 'pending' ) ) );
$wpdb->insert( Flexo_Booking_Install::table(), array( 'reference' => 'FB-T6DEP1', 'room_id' => $family, 'check_in' => Flexo_Booking_Dates::add_days( $today, -3 ), 'check_out' => $today, 'nights' => 3, 'adults' => 2, 'guest_name' => 'Leaving Guest', 'status' => 'confirmed', 'source' => 'admin', 'currency' => 'EUR', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$wpdb->insert( Flexo_Booking_Install::table(), array( 'reference' => 'FB-T6STAY', 'room_id' => $family, 'check_in' => Flexo_Booking_Dates::add_days( $today, -1 ), 'check_out' => Flexo_Booking_Dates::add_days( $today, 1 ), 'nights' => 2, 'adults' => 2, 'guest_name' => 'Staying Guest', 'status' => 'cancelled', 'source' => 'admin', 'currency' => 'EUR', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
t_eq( array( $arr['id'] ), wp_list_pluck( Flexo_Booking_Today_Admin::arrivals( $today ), 'id' ), 'arriving today' );
t_eq( array( 'Leaving Guest' ), wp_list_pluck( Flexo_Booking_Today_Admin::departures( $today ), 'guest_name' ), 'leaving today' );
t_eq( array(), wp_list_pluck( Flexo_Booking_Today_Admin::staying( $today ), 'guest_name' ), 'cancelled stays are not counted' );
t_eq( array( $dep['id'] ), wp_list_pluck( Flexo_Booking_Today_Admin::arrivals( Flexo_Booking_Dates::add_days( $today, 1 ) ), 'id' ), 'arriving tomorrow (a request too)' );
$week = Flexo_Booking_Today_Admin::period( $today, Flexo_Booking_Dates::add_days( $today, 7 ) );
t_ok( 2 === $week['arrivals'] && 4 === $week['nights'] && 3 === $week['departures'], 'next 7 days: arrivals, nights, departures' );
$types = t6_fresh_attention();
t_ok( in_array( 'request', $types, true ), 'pending request needs attention' );
t_eq( count( $types ), Flexo_Booking_Today_Admin::attention_count(), 'menu badge counts everything that needs attention' );

/* ------------------------------------------------------------------ */
t_section( 'Booking history' );
$h = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 't6-double', 'check_in' => t_day( 30 ), 'check_out' => t_day( 32 ), 'source' => 'admin', 'status' => 'pending' ) ) );
Flexo_Booking_Bookings::update_status( $h['id'], 'confirmed' );
Flexo_Booking_Bookings::update_status( $h['id'], 'cancelled' );
$actions = array_reverse( wp_list_pluck( Flexo_Booking_Log::for_booking( $h['id'] ), 'action' ) );
t_eq( array( 'created', 'status', 'status' ), $actions, 'created, confirmed, cancelled logged' );
$text = Flexo_Booking_Log::describe( Flexo_Booking_Log::for_booking( $h['id'] )[0] );
t_eq( 'Status: Confirmed → Cancelled', $text, 'history in hotel words' );
t_eq( 'Waiting for confirmation', Flexo_Booking_Bookings::status_label( 'pending' ), 'status name: Waiting for confirmation' );
t_eq( 'Waiting for bank transfer', Flexo_Booking_Bookings::status_label( 'awaiting_payment' ), 'status name: Waiting for bank transfer' );

/* ------------------------------------------------------------------ */
t_section( 'Roles' );
$users = array();
foreach ( array( 't6staff' => 'hotel_staff', 't6manager' => 'hotel_manager', 't6editor' => 'editor' ) as $login => $role ) {
	$u = get_user_by( 'login', $login );
	$users[ $role ] = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => $role ) );
}
$can = static function ( $role, $cap, $arg = null ) use ( $users ) {
	return null === $arg ? user_can( $users[ $role ], $cap ) : user_can( $users[ $role ], $cap, $arg );
};
t_ok( $can( 'hotel_staff', 'flexo_manage_bookings' ) && ! $can( 'hotel_staff', 'flexo_manage_prices' ) && ! $can( 'hotel_staff', 'flexo_manage_settings' ) && ! $can( 'hotel_staff', 'manage_options' ), 'staff: bookings only' );
t_ok( ! $can( 'hotel_staff', 'edit_flexo_rooms' ) && ! $can( 'hotel_staff', 'edit_posts' ), 'staff: no rooms, no posts' );
t_ok( $can( 'hotel_manager', 'flexo_manage_prices' ) && $can( 'hotel_manager', 'flexo_manage_settings' ) && ! $can( 'hotel_manager', 'manage_options' ), 'manager: prices, emails, appearance – not settings' );
t_ok( $can( 'hotel_manager', 'edit_flexo_rooms' ) && $can( 'hotel_manager', 'edit_post', $family ) && $can( 'hotel_manager', 'publish_flexo_rooms' ), 'manager edits rooms' );
t_ok( $can( 'editor', 'flexo_manage_bookings' ) && $can( 'editor', 'edit_post', $family ), 'editors keep bookings and rooms' );

/* ------------------------------------------------------------------ */
t_section( 'Settings a manager may change' );
wp_set_current_user( $users['hotel_manager'] );
$before = Flexo_Booking_Settings::all();
$after  = Flexo_Booking_Settings::sanitize( array_merge( $before, array( 'booking_mode' => 'request', 'email_confirmed_subject' => 'Manager subject', 'appearance_mode' => 'custom', 'currency' => 'USD' ) ) );
t_ok( 'instant' === $after['booking_mode'] && 'EUR' === $after['currency'], 'booking rules and currency unchanged' );
t_ok( 'Manager subject' === $after['email_confirmed_subject'] && 'custom' === $after['appearance_mode'], 'emails and appearance changed' );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_keep' ) );
$secrets = Flexo_Booking_Payments::sanitize_secrets( array( 'stripe_test_secret_key' => 'sk_test_changed' ) );
t_eq( 'sk_test_keep', $secrets['stripe_test_secret_key'], 'payment keys unchanged by a manager' );
wp_set_current_user( 0 );

/* ------------------------------------------------------------------ */
t_section( 'Health check' );
$status_of = static function ( $id ) {
	foreach ( Flexo_Booking_Health::checks() as $c ) {
		if ( $c['id'] === $id ) {
			return $c['status'];
		}
	}
	return 'missing';
};
$saved_page = Flexo_Booking_Settings::get( 'booking_page' );
t6_settings( array( 'booking_page' => '' ) );
// Every published page with the form – shortcode or Elementor widget (e.g. left by the browser-test seeds).
$t6_form_pages = array_merge(
	get_posts( array( 'post_type' => 'page', 's' => '[flexo_booking', 'posts_per_page' => -1, 'post_status' => 'publish' ) ),
	get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_elementor_data', 'meta_value' => 'flexo-booking-form', 'meta_compare' => 'LIKE' ) )
);
foreach ( $t6_form_pages as $p ) {
	wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
	$drafted[] = $p->ID;
}
Flexo_Booking_Guest::forget_booking_page();
t_eq( 'error', $status_of( 'booking_page' ), 'missing booking page detected' );
foreach ( isset( $drafted ) ? array_unique( $drafted ) : array() as $id ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
}
Flexo_Booking_Guest::forget_booking_page();
t6_settings( array( 'booking_page' => $saved_page ) );
Flexo_Booking_Emails::log( 0, 'guest_confirmed', 'x@example.com', 'Test', 'failed', 'SMTP error' );
t_eq( 'error', $status_of( 'email' ), 'failing email detected' );
update_option( Flexo_Booking_Health::CRON_OPTION, time() - 6 * HOUR_IN_SECONDS );
t_eq( 'error', $status_of( 'cron' ), 'stopped WP-Cron detected' );
Flexo_Booking_Health::cron_ran();
t_eq( 'ok', $status_of( 'cron' ), 'WP-Cron running' );
Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'calendar_sync' ) ) );
$cal_id = Flexo_Booking_ICal::add_calendar( $double, 'Broken feed', 'https://example.invalid/feed.ics' );
$wpdb->update( Flexo_Booking_Schema::table( 'calendars' ), array( 'last_status' => 'error', 'last_error' => 'Not found (404)', 'fail_count' => 3 ), array( 'id' => is_wp_error( $cal_id ) ? 0 : (int) $cal_id ) );
t_eq( 'error', $status_of( 'ical' ), 'broken calendar feed detected' );
Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'online_payment' ) ) );
t6_settings( array( 'stripe_mode' => 'test' ) );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => '', 'stripe_test_webhook_secret' => '' ) );
t_eq( 'error', $status_of( 'stripe' ), 'missing payment keys detected' );
$mock = getenv( 'STRIPE_MOCK' );
if ( $mock ) {
	update_option( 'flexo_test_stripe_api', rtrim( $mock, '/' ) . '/v1' );
	update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'invalid-key', 'stripe_test_webhook_secret' => 'whsec_x' ) );
	$check = Flexo_Booking_Payments::gateway( 'stripe' )->check_keys();
	t_ok( is_wp_error( $check ), 'invalid payment key refused by the (mock) Stripe API' );
	update_option( Flexo_Booking_Health::STRIPE_OPTION, array( 'ok' => false, 'message' => $check->get_error_message(), 'mode' => 'test', 'time' => time() ) );
	t_eq( 'error', $status_of( 'stripe' ), 'invalid payment keys shown as a problem' );
	update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_ok', 'stripe_test_webhook_secret' => 'whsec_x' ) );
	t_ok( true === Flexo_Booking_Payments::gateway( 'stripe' )->check_keys(), 'valid key accepted' );
	delete_option( 'flexo_test_stripe_api' );
}
delete_option( Flexo_Booking_Health::STRIPE_OPTION );
$report = Flexo_Booking_Health::report();
t_ok( false !== strpos( $report, 'Flexo Booking: ' . FLEXO_BOOKING_VERSION ) && false === strpos( $report, 'sk_test' ) && false === strpos( $report, 'whsec' ) && false === strpos( $report, 'maria@example.com' ), 'system report without keys or guest data' );

/* ------------------------------------------------------------------ */
t_section( 'Wizard helpers and settings tabs' );
$page = Flexo_Booking_Wizard::create_page();
t_ok( ! is_wp_error( $page ) && false !== strpos( get_post_field( 'post_content', $page ), '[flexo_booking]' ), 'booking page created with the form' );
t_eq( wp_make_link_relative( get_permalink( $page ) ), Flexo_Booking_Settings::get( 'booking_page' ), '…and set as the booking page' );
wp_delete_post( $page, true );
t6_settings( array( 'booking_page' => '/booking/' ) );
t_ok( ! Flexo_Booking_Wizard::is_fresh(), 'a site with rooms is not "fresh" (no wizard redirect)' );
$tabs = Flexo_Booking_Settings::tabs();
t_ok( isset( $tabs['general'], $tabs['rules'], $tabs['health'], $tabs['features'] ), 'settings tabs: hotel, booking rules, health, features' );

/* ------------------------------------------------------------------ */
t_section( 'Appearance' );
t6_settings( array( 'appearance_mode' => 'match' ) );
t_eq( '', Flexo_Booking_Appearance::css(), '"Match my website": no extra CSS' );
t6_settings( array( 'appearance_mode' => 'custom', 'appearance_primary' => '#123456', 'appearance_heading_font' => 'lora', 'appearance_body_font' => '', 'appearance_corners' => 'rounded' ) );
$css = Flexo_Booking_Appearance::css();
preg_match_all( '/(?:^|})\s*([^{}]+)\{/', $css, $flexo_sel );
$flexo_scoped = array_filter(
	array_map( 'trim', $flexo_sel[1] ),
	static function ( $sel ) {
		return '@font-face' !== $sel && 0 !== strpos( $sel, '.flexo-booking' );
	}
);
t_ok( ! $flexo_scoped && false !== strpos( $css, '--fb-primary:#123456' ) && false === strpos( $css, '!important' ), 'custom CSS only for the booking form (and its fonts), no !important' );
$faces = Flexo_Booking_Appearance::font_faces( array( 'lora' ) );
t_ok( false !== strpos( $faces, 'lora-cyrillic' ) && false === strpos( $faces, 'inter' ) && false === strpos( $faces, 'googleapis' ), 'only the chosen font, self-hosted, with Cyrillic' );
t_eq( '#123456', Flexo_Booking_Appearance::email_color(), 'emails use the main colour' );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( FLEXO_BOOKING_DIR ) );
$google = false;
foreach ( $files as $file ) {
	if ( preg_match( '/\.(php|js|css)$/', $file ) && false !== strpos( file_get_contents( $file ), 'fonts.googleapis' ) ) {
		$google = true;
	}
}
t_ok( ! $google, 'no Google Fonts anywhere in the plugin' );
$data = Flexo_Booking_Portability::export();
t_ok( '#123456' === $data['settings']['appearance_primary'] && 'custom' === $data['settings']['appearance_mode'], 'appearance in Import/Export' );
t_ok( in_array( 'breakfast', wp_list_pluck( $data['rate_plans'], 'meals' ), true ), 'rate meals in Import/Export' );

/* ------------------------------------------------------------------ */
update_option( Flexo_Booking_Settings::OPTION, $t6_saved_settings );
update_option( Flexo_Booking_Features::ENABLED_OPTION, $t6_saved_features );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, $t6_saved_secrets );
if ( ! is_wp_error( $cal_id ) ) {
	Flexo_Booking_ICal::delete_calendar( $cal_id );
}
foreach ( $users as $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $id );
}
t_done();
