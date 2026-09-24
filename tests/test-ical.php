<?php
/**
 * Calendar sync engine: parsing, import, re-sync, broken feeds, unit rule,
 * conflicts, export feed, tokens, cron, feature switch.
 * Feeds are served through a pre_http_request mock ($GLOBALS['feeds']).
 */
require __DIR__ . '/lib.php';

/** Fixture with {Dn} placeholders replaced by t_day(n) as YYYYMMDD. */
function t_ics( $file ) {
	$ics = file_get_contents( __DIR__ . '/fixtures/' . $file );
	return preg_replace_callback(
		'/\{D(PAST2|PAST|\d+)\}/',
		function ( $m ) {
			$n = 'PAST' === $m[1] ? -20 : ( 'PAST2' === $m[1] ? -18 : (int) $m[1] );
			return str_replace( '-', '', t_day( $n ) );
		},
		$ics
	);
}
function t_ymd( $n ) {
	return str_replace( '-', '', t_day( $n ) );
}
function t_feed_event( $uid, $from, $to, $summary = 'Reserved' ) {
	return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART;VALUE=DATE:" . t_ymd( $from ) . "\r\nDTEND;VALUE=DATE:" . t_ymd( $to ) . "\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\n";
}
function t_feed( array $events ) {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n" . implode( '', $events ) . "END:VCALENDAR\r\n";
}

$GLOBALS['feeds'] = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( ! isset( $GLOBALS['feeds'][ $url ] ) ) {
			return $pre;
		}
		$feed = $GLOBALS['feeds'][ $url ];
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		if ( is_array( $feed ) ) {
			return array( 'headers' => array(), 'body' => $feed[1], 'response' => array( 'code' => $feed[0], 'message' => '' ), 'cookies' => array() );
		}
		return array( 'headers' => array(), 'body' => $feed, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	},
	10,
	3
);

t_reset_inventory();
global $wpdb;
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendars' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendar_events' ) );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'calendar_sync' ) );

t_section( 'Parser' );
$events = Flexo_Booking_ICal::parse( t_ics( 'generic.ics' ) );
$by     = array_column( $events, null, 'uid' );
t_eq( 3, count( $events ), 'cancelled event skipped, VALARM ignored (3 events)' );
t_ok( t_day( 40 ) === $by['timed-1@example.com']['date_from'] && t_day( 42 ) === $by['timed-1@example.com']['date_to'], 'timed UTC event → hotel-time dates' );
t_ok( false !== strpos( $by['timed-1@example.com']['summary'], 'in the feed' ), 'folded line unfolded' );
t_ok( t_day( 50 ) === $by['tz-1@example.com']['date_from'] && t_day( 53 ) === $by['tz-1@example.com']['date_to'], 'TZID start + DURATION P3D' );
t_eq( 'Blocked, owner stay', $by['tz-1@example.com']['summary'], 'escaped comma' );
t_ok( t_day( 56 ) === $by['noend-1@example.com']['date_to'], 'all-day event without DTEND lasts one night' );
$bk = Flexo_Booking_ICal::parse( t_ics( 'booking-com.ics' ) );
t_ok( 3 === count( $bk ) && t_day( 13 ) === $bk[0]['date_to'], 'Booking.com format: DTEND is the check-out day' );
$ab = Flexo_Booking_ICal::parse( t_ics( 'airbnb.ics' ) );
t_ok( 2 === count( $ab ) && t_day( 15 ) === $ab[0]['date_from'] && t_day( 17 ) === $ab[0]['date_to'], 'Airbnb format (DTEND before DTSTART in the file)' );

t_section( 'Import into a one-unit room' );
$villa_id = t_room( 'sync-villa', 'Sync Villa', array( 'price' => 150, 'capacity' => 6, 'units' => 1 ) );
$villa    = Flexo_Booking_Rooms::to_array( get_post( $villa_id ) );
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = t_ics( 'booking-com.ics' );
$GLOBALS['feeds']['https://ical.airbnb.test/villa.ics']  = t_ics( 'airbnb.ics' );
$bcal = Flexo_Booking_ICal::add_calendar( $villa_id, 'Booking.com', 'https://ical.booking.test/villa.ics' );
$acal = Flexo_Booking_ICal::add_calendar( $villa_id, 'Airbnb', 'webcal://ical.airbnb.test/villa.ics' );
t_ok( is_int( $acal ) && 'https://ical.airbnb.test/villa.ics' === Flexo_Booking_ICal::get_calendar( $acal )['import_url'], 'webcal:// link accepted as https://' );
t_ok( is_wp_error( Flexo_Booking_ICal::add_calendar( $villa_id, 'Again', 'https://ical.booking.test/villa.ics' ) ), 'same link twice refused' );
t_ok( is_wp_error( Flexo_Booking_ICal::add_calendar( $villa_id, 'Bad', 'not a link' ) ), 'invalid link refused' );
$s = Flexo_Booking_ICal::sync_calendar( $bcal );
t_eq( 2, $s['added'], 'Booking.com: 2 future bookings imported, past one skipped' );
Flexo_Booking_ICal::sync_calendar( $acal );
t_eq( 0, Flexo_Booking_Inventory::units_available( $villa, t_day( 10 ), t_day( 13 ) ), 'imported dates blocked' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 13 ), t_day( 14 ) ), 'check-out day stays free' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 9 ), t_day( 10 ) ), 'night before arrival free' );
t_eq( 0, Flexo_Booking_Inventory::units_available( $villa, t_day( 16 ), t_day( 17 ) ), 'Airbnb booking blocks its nights' );
$r = Flexo_Booking_Bookings::search( t_day( 12 ), t_day( 14 ), 2, 0, 'sync-villa' );
t_ok( ! $r['rooms'][0]['available'], 'guest search: overlapping dates unavailable' );
$r = Flexo_Booking_Bookings::search( t_day( 13 ), t_day( 15 ), 2, 0, 'sync-villa' );
t_ok( $r['rooms'][0]['available'], 'guest search: arrive on the external check-out day' );
$e = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'sync-villa', 'check_in' => t_day( 11 ), 'check_out' => t_day( 12 ) ) ) );
t_ok( is_wp_error( $e ) && 'flexo_unavailable' === $e->get_error_code(), 'website booking over an imported booking refused' );

t_section( 'Re-sync' );
$count = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Schema::table( 'calendar_events' ) );
};
$before = $count();
$s      = Flexo_Booking_ICal::sync_calendar( $bcal );
t_ok( 0 === $s['added'] && 0 === $s['updated'] && $before === $count(), 'unchanged feed: no duplicates, nothing updated' );
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = t_feed(
	array(
		t_feed_event( '3f6c1a2e9b8d4c7a@booking.com', 11, 14, 'CLOSED - Not available' ),
	)
);
$s = Flexo_Booking_ICal::sync_calendar( $bcal );
t_ok( 1 === $s['updated'] && 1 === $s['removed'], 'changed dates updated (same UID), missing booking removed' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 10 ), t_day( 11 ) ), 'moved booking: old first night released' );
t_eq( 0, Flexo_Booking_Inventory::units_available( $villa, t_day( 13 ), t_day( 14 ) ), 'moved booking: new last night blocked' );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 20 ), t_day( 22 ) ), 'removed booking: dates released' );

t_section( 'Broken feeds' );
$GLOBALS['feeds']['https://down.test/cal.ics']  = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
$GLOBALS['feeds']['https://gone.test/cal.ics']  = array( 404, 'Not found' );
$GLOBALS['feeds']['https://html.test/cal.ics']  = '<!doctype html><html><body>Login</body></html>';
$down = Flexo_Booking_ICal::add_calendar( $villa_id, 'Vrbo', 'https://down.test/cal.ics' );
$gone = Flexo_Booking_ICal::add_calendar( $villa_id, 'Old link', 'https://gone.test/cal.ics' );
$html = Flexo_Booking_ICal::add_calendar( $villa_id, 'Wrong link', 'https://html.test/cal.ics' );
$GLOBALS['feeds']['https://ical.airbnb.test/villa.ics'] = t_feed( array( t_feed_event( 'new@airbnb.com', 25, 27 ) ) );
$results = Flexo_Booking_ICal::sync_all();
t_ok( is_wp_error( $results[ $down ] ) && is_wp_error( $results[ $gone ] ) && is_wp_error( $results[ $html ] ), 'broken feeds report errors' );
t_ok( ! is_wp_error( $results[ $acal ] ) && 1 === $results[ $acal ]['added'], 'the other feeds still synced' );
$c = Flexo_Booking_ICal::get_calendar( $down );
t_ok( 'error' === $c['last_status'] && false !== strpos( $c['last_error'], 'did not answer in time' ), 'timeout explained: ' . $c['last_error'] );
t_ok( false !== strpos( Flexo_Booking_ICal::get_calendar( $gone )['last_error'], 'no longer exists' ), '404 explained: ' . Flexo_Booking_ICal::get_calendar( $gone )['last_error'] );
t_ok( false !== strpos( Flexo_Booking_ICal::get_calendar( $html )['last_error'], 'web page, not a calendar' ), 'HTML page explained' );
Flexo_Booking_ICal::sync_all();
Flexo_Booking_ICal::sync_all();
t_ok( 3 === count( Flexo_Booking_ICal::failing_calendars() ), 'after 3 failures in a row the calendars are listed for the admin notice' );
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
Flexo_Booking_ICal::sync_calendar( $bcal );
t_eq( 0, Flexo_Booking_Inventory::units_available( $villa, t_day( 11 ), t_day( 12 ) ), 'a failed download keeps the previously imported bookings' );
foreach ( array( $down, $gone, $html ) as $id ) {
	Flexo_Booking_ICal::delete_calendar( $id );
}
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = t_feed( array() );
Flexo_Booking_ICal::sync_calendar( $bcal );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 11 ), t_day( 14 ) ), 'empty feed releases everything from that calendar' );

t_section( 'Own export coming back is ignored' );
$host = wp_parse_url( home_url(), PHP_URL_HOST );
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = t_feed( array( t_feed_event( 'flexo-fb-abc123@' . $host, 30, 31, 'Booked' ) ) );
t_eq( 0, Flexo_Booking_ICal::sync_calendar( $bcal )['total'], 'events with this website\'s own UID skipped' );

t_section( 'Rooms with several units' );
$apt_id = t_room( 'sync-apartment', 'Sync Apartment', array( 'price' => 90, 'capacity' => 4, 'units' => 3 ) );
$apt    = Flexo_Booking_Rooms::to_array( get_post( $apt_id ) );
$GLOBALS['feeds']['https://a.test/any.ics']    = t_feed( array( t_feed_event( 'x1@a', 10, 12 ) ) );
$GLOBALS['feeds']['https://b.test/unit2.ics']  = t_feed( array( t_feed_event( 'y1@b', 10, 12 ) ) );
$GLOBALS['feeds']['https://c.test/unit2.ics']  = t_feed( array( t_feed_event( 'z1@c', 10, 12, 'Airbnb (Not available)' ) ) );
Flexo_Booking_ICal::sync_calendar( Flexo_Booking_ICal::add_calendar( $apt_id, 'Any unit', 'https://a.test/any.ics' ) );
t_eq( 2, Flexo_Booking_Inventory::units_available( $apt, t_day( 10 ), t_day( 12 ) ), 'one imported booking = one unit (2 of 3 free)' );
Flexo_Booking_ICal::sync_calendar( Flexo_Booking_ICal::add_calendar( $apt_id, 'Booking.com – no. 2', 'https://b.test/unit2.ics', 2 ) );
Flexo_Booking_ICal::sync_calendar( Flexo_Booking_ICal::add_calendar( $apt_id, 'Airbnb – no. 2', 'https://c.test/unit2.ics', 2 ) );
t_eq( 1, Flexo_Booking_Inventory::units_available( $apt, t_day( 10 ), t_day( 12 ) ), 'two calendars of unit no. 2 with the same stay take that unit once (1 of 3 free)' );
Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'sync-apartment', 'check_in' => t_day( 10 ), 'check_out' => t_day( 12 ) ) ) );
t_eq( 0, Flexo_Booking_Inventory::units_available( $apt, t_day( 10 ), t_day( 12 ) ), 'plus a website booking: full' );
t_eq( 0, count( Flexo_Booking_ICal::open_conflicts() ), 'exactly full is not a conflict' );

t_section( 'Conflicts' );
$GLOBALS['flexo_mails'] = array();
$settings = Flexo_Booking_Settings::all();
$settings['notification_email'] = 'desk@hotel.test';
update_option( Flexo_Booking_Settings::OPTION, $settings );
$flexo = Flexo_Booking_Bookings::create( array_merge( t_guest( array( 'guest_name' => 'Ivan Petrov' ) ), array( 'room' => 'sync-villa', 'check_in' => t_day( 60 ), 'check_out' => t_day( 62 ) ) ) );
$GLOBALS['flexo_mails'] = array();
$GLOBALS['feeds']['https://ical.booking.test/villa.ics'] = t_feed( array( t_feed_event( 'late@booking.com', 61, 63, 'CLOSED - Not available' ) ) );
Flexo_Booking_ICal::sync_calendar( $bcal );
$conflicts = Flexo_Booking_ICal::open_conflicts();
t_ok( 1 === count( $conflicts ) && false !== strpos( $conflicts[0]['conflict_note'], $flexo['reference'] ), 'overlap with a Flexo booking in a full room flagged: ' . ( $conflicts ? $conflicts[0]['conflict_note'] : '' ) );
$alerts = array_values( array_filter( $GLOBALS['flexo_mails'], function ( $m ) { return false !== strpos( $m['subject'], 'double booking' ); } ) );
t_ok( 1 === count( $alerts ) && array( 'desk@hotel.test' ) === (array) $alerts[0]['to'], 'alert emailed to the notification address' );
if ( $alerts ) {
	echo '    subject: ' . $alerts[0]['subject'] . "\n";
	t_ok( false !== strpos( $alerts[0]['message'], 'Calendar: Booking.com' ) && false !== strpos( $alerts[0]['message'], $flexo['reference'] ) && false !== strpos( $alerts[0]['message'], Flexo_Booking_Dates::display( t_day( 61 ) ) ), 'alert names the calendar, the dates and the overlapping booking' );
}
$GLOBALS['flexo_mails'] = array();
Flexo_Booking_ICal::sync_calendar( $bcal );
t_eq( 0, count( $GLOBALS['flexo_mails'] ), 'no repeated email on the next sync' );
Flexo_Booking_ICal::mark_reviewed( $conflicts[0]['id'] );
t_eq( 0, count( Flexo_Booking_ICal::open_conflicts() ), 'marked as reviewed' );
Flexo_Booking_Bookings::update_status( $flexo['id'], 'cancelled' );
t_eq( 0, (int) $wpdb->get_var( 'SELECT MAX(conflict) FROM ' . Flexo_Booking_Schema::table( 'calendar_events' ) ), 'cancelling the Flexo booking resolves the conflict' );
$echo = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'sync-villa', 'check_in' => t_day( 70 ), 'check_out' => t_day( 72 ) ) ) );
$GLOBALS['feeds']['https://ical.airbnb.test/villa.ics'] = t_feed( array( t_feed_event( 'echo@airbnb.com', 70, 72, 'Airbnb (Not available)' ) ) );
Flexo_Booking_ICal::sync_calendar( $acal );
$echo_conflict = wp_list_filter( Flexo_Booking_ICal::open_conflicts(), array( 'uid' => 'echo@airbnb.com' ) );
t_ok( $echo_conflict && false !== strpos( reset( $echo_conflict )['conflict_note'], 'repeating your own booking' ), 'same dates as a Flexo booking: note explains a possible echo' );

t_section( 'Export feed' );
Flexo_Booking_Bookings::create( array( 'room' => 'sync-villa', 'check_in' => t_day( 80 ), 'check_out' => t_day( 82 ), 'status' => 'blocked', 'source' => 'admin' ) );
Flexo_Booking_Bookings::create( array( 'room' => 'sync-villa', 'check_in' => t_day( 84 ), 'check_out' => t_day( 85 ), 'status' => 'confirmed', 'source' => 'admin', 'guest_name' => 'Phone Guest', 'guest_phone' => '+359 777 666 555' ) );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => t_day( 90 ), 'date_to' => t_day( 95 ), 'label' => 'Winter' ) );
$ics = Flexo_Booking_ICal::export_feed( $villa_id );
t_ok( 0 === strpos( $ics, "BEGIN:VCALENDAR\r\n" ) && false !== strpos( $ics, "END:VCALENDAR\r\n" ), 'valid calendar with CRLF line endings' );
$lines = explode( "\r\n", trim( $ics ) );
t_ok( max( array_map( 'strlen', $lines ) ) <= 75, 'no line longer than 75 octets' );
t_ok( false === strpos( $ics, 'Ivan' ) && false === strpos( $ics, 'Phone Guest' ) && false === strpos( $ics, '@example.com' ) && false === strpos( $ics, '777' ) && false === strpos( $ics, '€' ), 'no guest names, emails, phones or prices' );
$back = array_column( Flexo_Booking_ICal::parse( $ics ), null, 'date_from' );
t_ok( isset( $back[ t_day( 70 ) ] ) && t_day( 72 ) === $back[ t_day( 70 ) ]['date_to'], 'website booking exported with the check-out day as DTEND' );
t_ok( isset( $back[ t_day( 80 ) ] ) && 'Not available' === $back[ t_day( 80 ) ]['summary'], 'staff block exported' );
t_ok( isset( $back[ t_day( 84 ) ] ) && 'Booked' === $back[ t_day( 84 ) ]['summary'], 'staff booking exported' );
t_ok( isset( $back[ t_day( 90 ) ] ) && t_day( 96 ) === $back[ t_day( 90 ) ]['date_to'], 'closed period exported (last night inclusive → DTEND next day)' );
t_ok( isset( $back[ t_day( 61 ) ] ), 'bookings from other connected calendars exported too' );
t_ok( ! isset( $back[ t_day( 60 ) ] ), 'cancelled booking not exported' );

t_section( 'Tokens' );
$token = Flexo_Booking_ICal::token( $villa_id );
t_ok( preg_match( '/^[a-f0-9]{32}$/', $token ) && Flexo_Booking_ICal::check_token( $villa_id, $token ), 'secret token created and accepted' );
t_ok( ! Flexo_Booking_ICal::check_token( $villa_id, 'wrong' ) && ! Flexo_Booking_ICal::check_token( $villa_id, '' ), 'wrong or empty token rejected' );
$new = Flexo_Booking_ICal::regenerate_token( $villa_id );
t_ok( $new !== $token && ! Flexo_Booking_ICal::check_token( $villa_id, $token ) && Flexo_Booking_ICal::check_token( $villa_id, $new ), 'regenerated: old link stops working' );
t_ok( false !== strpos( Flexo_Booking_ICal::export_url( $villa_id ), 'token=' . $new ), 'export URL carries the new token' );

t_section( 'Cron' );
wp_clear_scheduled_hook( Flexo_Booking_ICal::CRON_HOOK );
Flexo_Booking_ICal::maybe_schedule();
t_eq( 'flexo_ical_30', wp_get_schedule( Flexo_Booking_ICal::CRON_HOOK ), 'scheduled every 30 minutes by default' );
$schedules = wp_get_schedules();
t_eq( 1800, $schedules['flexo_ical_30']['interval'], 'interval is 1800 seconds' );
$settings['ical_interval'] = 15;
update_option( Flexo_Booking_Settings::OPTION, $settings );
Flexo_Booking_ICal::maybe_schedule();
t_eq( 'flexo_ical_15', wp_get_schedule( Flexo_Booking_ICal::CRON_HOOK ), 'rescheduled after changing the interval to 15 minutes' );
$wpdb->query( 'UPDATE ' . Flexo_Booking_Schema::table( 'calendars' ) . ' SET last_synced_at = NULL' );
do_action( Flexo_Booking_ICal::CRON_HOOK );
t_ok( ! $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Schema::table( 'calendars' ) . ' WHERE last_synced_at IS NULL' ), 'cron job synced every calendar' );

t_section( 'Feature switched off' );
Flexo_Booking_Features::set_enabled( array( 'guest_emails' ) );
t_eq( 1, Flexo_Booking_Inventory::units_available( $villa, t_day( 61 ), t_day( 63 ) ), 'imported bookings no longer block' );
Flexo_Booking_ICal::maybe_schedule();
t_ok( ! wp_next_scheduled( Flexo_Booking_ICal::CRON_HOOK ), 'cron stopped' );
t_eq( array(), Flexo_Booking_ICal::open_conflicts(), 'no conflict notices' );
t_ok( count( Flexo_Booking_ICal::calendars() ) >= 2, 'connections kept' );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'calendar_sync' ) );
t_eq( 0, Flexo_Booking_Inventory::units_available( $villa, t_day( 61 ), t_day( 63 ) ), 'switched on again: blocking restored' );

$settings['ical_interval'] = 30;
update_option( Flexo_Booking_Settings::OPTION, $settings );
foreach ( Flexo_Booking_ICal::calendars() as $c ) {
	Flexo_Booking_ICal::delete_calendar( $c['id'] );
}
wp_delete_post( $villa_id, true );
wp_delete_post( $apt_id, true );
t_reset_inventory();
Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
Flexo_Booking_ICal::maybe_schedule();
t_done();
