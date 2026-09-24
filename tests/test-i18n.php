<?php
/**
 * Translation: Bulgarian texts, emails in the language the guest booked in,
 * dates per language, REST answers in the page's language.
 * Runs on a site whose language is Bulgarian (run.sh sets WPLANG=bg_BG).
 */
require __DIR__ . '/lib.php';

global $wpdb;
t_reset_inventory();
add_filter( 'flexo_booking_rate_limit', '__return_zero' );
function t4_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function t4_features( array $extra ) {
	Flexo_Booking_Features::set_enabled( array_merge( array( 'booking_request', 'instant_booking', 'guest_emails' ), $extra ) );
}
function t4_mails( $reset = false ) {
	$mails = isset( $GLOBALS['flexo_mails'] ) ? $GLOBALS['flexo_mails'] : array();
	if ( $reset ) {
		$GLOBALS['flexo_mails'] = array();
	}
	return $mails;
}
function t4_to( $mail ) {
	return implode( ',', (array) $mail['to'] );
}
// Start from the built-in email texts, whatever an earlier run left behind.
$t4_defaults = Flexo_Booking_Settings::defaults();
t4_settings( array_intersect_key( array_fill_keys( array_keys( $t4_defaults ), '' ), array_flip( preg_grep( '/^email_.*_(subject|body)$/', array_keys( $t4_defaults ) ) ) ) );
t4_settings( array( 'booking_mode' => 'request', 'terms_url' => '', 'field_phone' => 'required', 'field_notes' => 'optional' ) );
t_room( 'd4-room', 'D4 Room', array( 'price' => 100, 'capacity' => 3, 'units' => 60 ) );
$g    = t_guest( array( 'guest_email' => 'anna@example.com' ) );
$stay = array( 'room' => 'd4-room', 'check_in' => t_day( 20 ), 'check_out' => t_day( 22 ) );

// ---------------------------------------------------------------------------
t_section( 'Translation: emails in the guest\'s language' );
// WordPress switches only to installed languages. run.sh installs an empty
// stand-in for the Bulgarian core pack (this test site can't download it).
t_ok( in_array( 'bg_BG', Flexo_Booking_I18n::locales(), true ), 'Bulgarian available' );
t_eq( 'bg_BG', get_locale(), 'site language is Bulgarian (run.sh sets it for this test)' );
t4_features( array() );
t4_settings( array( 'notification_email' => 'desk@hotel.test' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'email_log' ) );
t4_mails( true );
$en = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'john@example.com', 'guest_name' => 'John', 'locale' => 'en_US' ) ) );
$m  = t4_mails( true );
$by = array();
foreach ( $m as $mail ) {
	$by[ t4_to( $mail ) ] = $mail;
}
t_eq( 'en_US', $en['locale'], 'booking stores the guest\'s language' );
t_ok( 0 === strpos( $by['john@example.com']['subject'], 'We received your booking request' ), 'English guest on a Bulgarian site: English email – ' . $by['john@example.com']['subject'] );
t_ok( false !== strpos( $by['john@example.com']['message'], 'Check-in: ' . wp_date( 'F j, Y', strtotime( $stay['check_in'] ) ) ), 'English date format in the English email' );
t_ok( 0 === strpos( $by['desk@hotel.test']['subject'], 'Нова заявка за резервация' ), 'hotel email in the site language (Bulgarian) – ' . $by['desk@hotel.test']['subject'] );
t_ok( false !== strpos( $by['desk@hotel.test']['message'], 'English (US)' ), 'hotel email says the guest booked in English' );
Flexo_Booking_Bookings::update_status( $en['id'], 'confirmed' );
$m = t4_mails( true );
t_ok( 0 === strpos( $m[0]['subject'], 'Your booking ' . $en['reference'] . ' is confirmed' ), 'later emails (confirmed) also in English, even from the Bulgarian admin' );
$bg = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'ivan@example.com', 'guest_name' => 'Иван', 'locale' => 'bg_BG' ) ) );
$m  = t4_mails( true );
$gm = array_values( array_filter( $m, static function ( $x ) { return 'ivan@example.com' === t4_to( $x ); } ) );
t_ok( $gm && 0 === strpos( $gm[0]['subject'], 'Получихме заявката ви за резервация ' . $bg['reference'] ), 'Bulgarian guest: Bulgarian email – ' . ( $gm ? $gm[0]['subject'] : '' ) );
t_ok( $gm && false !== strpos( $gm[0]['message'], 'Настаняване: ' . Flexo_Booking_Dates::display( $stay['check_in'] ) ), 'Bulgarian dates as DD.MM.YYYY' );
t_ok( $gm && false !== strpos( $gm[0]['message'], 'Здравейте, Иван' ), 'Bulgarian default text' );
t4_settings( array( 'email_request_subject' => 'Custom subject {booking_ref}' ) );
$cu = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'ivan@example.com', 'locale' => 'bg_BG' ) ) );
$m  = array_values( array_filter( t4_mails( true ), static function ( $x ) { return 'ivan@example.com' === t4_to( $x ); } ) );
t_ok( 'Custom subject ' . $cu['reference'] === $m[0]['subject'], 'a text the hotel changed is used as written (translate it with Polylang/WPML)' );
t4_settings( array( 'email_request_subject' => '' ) );
t_eq( 'd.m.Y', Flexo_Booking_I18n::date_format(), 'date format for Bulgarian' );
t_eq( 'Резервации', __( 'Bookings', 'flexo-booking' ), 'admin texts in Bulgarian' );
t_eq( '3 нощувки', sprintf( _n( '%d night', '%d nights', 3, 'flexo-booking' ), 3 ), 'Bulgarian plural forms' );
switch_to_locale( 'en_US' );
t_eq( 'Bookings', __( 'Bookings', 'flexo-booking' ), 'switching to English works' );
restore_previous_locale();
t_eq( 'Резервации', __( 'Bookings', 'flexo-booking' ), 'and back to Bulgarian' );
$request = new WP_REST_Request( 'GET', '/flexo-booking/v1/availability' );
$request->set_query_params( array( 'check_in' => $stay['check_in'], 'check_out' => $stay['check_out'], 'adults' => 2, 'locale' => 'en_US' ) );
$data = rest_do_request( $request )->get_data();
t_eq( 'Total for 2 nights', $data['nights_label'], 'REST answers in the page\'s language (locale=en_US on a Bulgarian site)' );
restore_current_locale();

t4_settings( array( 'notification_email' => '' ) );
t_reset_inventory();
t_done();
