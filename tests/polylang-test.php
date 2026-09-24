<?php
/**
 * Polylang: booking page per language, texts and dates in the page's
 * language, guest emails in the booking's language (built-in and hotel texts
 * translated with Polylang string translation), privacy and thank-you pages
 * per language, hotel emails in the site language.
 *
 *   FLEXO_PHASE=setup wp eval-file tests/polylang-test.php   (once, Polylang active)
 *   wp rewrite flush
 *   BASE=http://localhost:8095 wp eval-file tests/polylang-test.php
 *
 * Site: Bulgarian default, English second language. run.sh-style stand-in
 * for the Bulgarian core pack must exist in wp-content/languages.
 */
require __DIR__ . '/lib.php';

if ( ! function_exists( 'PLL' ) ) {
	echo "Polylang is not active\n";
	exit( 1 );
}

function tp_page( $title, $slug, $content, $lang ) {
	$existing = get_page_by_path( $slug );
	$id       = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => 'page', 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_content' => $content ) );
	pll_set_post_language( $id, $lang );
	return $id;
}

if ( 'setup' === getenv( 'FLEXO_PHASE' ) ) {
	$model = PLL()->model;
	foreach ( array( array( 'Български', 'bg', 'bg_BG', 0 ), array( 'English', 'en', 'en_US', 1 ) ) as $l ) {
		if ( ! $model->get_language( $l[1] ) ) {
			$r = $model->add_language( array( 'name' => $l[0], 'slug' => $l[1], 'locale' => $l[2], 'rtl' => 0, 'term_group' => $l[3] ) );
			echo is_wp_error( $r ) ? 'language error: ' . $r->get_error_message() . "\n" : "added {$l[1]}\n";
		}
	}
	$options                  = get_option( 'polylang' );
	$options['default_lang']  = 'bg';
	$options['force_lang']    = 1; // Language from the directory name (/en/…).
	$options['hide_default']  = 1;
	$options['rewrite']       = 1;
	update_option( 'polylang', $options );
	update_option( 'WPLANG', 'bg_BG' );
	$model->clean_languages_cache();

	$room = t_room( 'sea-view', 'Sea View Room', array( 'price' => 90, 'capacity' => 2, 'units' => 20 ) );
	pll_set_post_language( $room, 'bg' );
	$bg = tp_page( 'Резервация', 'booking', '[flexo_booking]', 'bg' );
	$en = tp_page( 'Booking', 'booking-en', '[flexo_booking]', 'en' );
	pll_save_post_translations( array( 'bg' => $bg, 'en' => $en ) );
	$pbg = tp_page( 'Поверителност', 'privacy', 'Политика за поверителност', 'bg' );
	$pen = tp_page( 'Privacy', 'privacy-en', 'Privacy policy', 'en' );
	pll_save_post_translations( array( 'bg' => $pbg, 'en' => $pen ) );
	update_option( 'wp_page_for_privacy_policy', $pbg );
	$tbg = tp_page( 'Благодарим', 'thank-you', 'Благодарим!', 'bg' );
	$ten = tp_page( 'Thank you', 'thank-you-en', 'Thank you!', 'en' );
	pll_save_post_translations( array( 'bg' => $tbg, 'en' => $ten ) );

	Flexo_Booking_Features::set_enabled( array( 'booking_request', 'guest_emails', 'privacy_consent' ) );
	$s                       = Flexo_Booking_Settings::defaults();
	$s['notification_email'] = 'desk@hotel.test';
	$s['thank_you_url']      = '/thank-you/';
	update_option( Flexo_Booking_Settings::OPTION, $s );
	echo "setup done – now run: wp rewrite flush (so Polylang's /en/ rules exist)\n";
	return;
}

global $wpdb;
$base = getenv( 'BASE' );
t_reset_inventory();
add_filter( 'flexo_booking_rate_limit', '__return_zero' );

t_section( 'Languages' );
t_eq( 'bg_BG', get_locale(), 'site language Bulgarian' );
$langs = wp_list_pluck( Flexo_Booking_I18n::multilingual_languages(), 'locale', 'slug' );
t_eq( array( 'bg' => 'bg_BG', 'en' => 'en_US' ), $langs, 'Polylang languages seen by the plugin' );
t_ok( in_array( 'en_US', Flexo_Booking_I18n::locales(), true ), 'English accepted as a guest language' );

t_section( 'Booking page in each language' );
$en_page = get_permalink( get_page_by_path( 'booking-en' ) );
$bg_page = get_permalink( get_page_by_path( 'booking' ) );
$html_en = wp_remote_retrieve_body( wp_remote_get( $en_page, array( 'timeout' => 30 ) ) );
$html_bg = wp_remote_retrieve_body( wp_remote_get( $bg_page, array( 'timeout' => 30 ) ) );
t_ok( false !== strpos( $en_page, '/en/' ), 'English page URL: ' . $en_page );
t_ok( false !== strpos( $html_en, 'data-locale="en_US"' ) && false !== strpos( $html_en, 'Check availability' ), 'English page: form in English, tells REST calls en_US' );
t_ok( false !== strpos( $html_bg, 'data-locale="bg_BG"' ) && false !== strpos( $html_bg, 'Провери наличността' ), 'Bulgarian page: form in Bulgarian' );
t_ok( false !== strpos( $html_bg, 'data-date-format="d.m.Y"' ), 'Bulgarian page: dates as DD.MM.YYYY' );
t_ok( false !== strpos( $html_en, '"checking":"Checking availability' ) && false !== strpos( $html_bg, '"checking":' . substr( wp_json_encode( 'Проверка на наличността' ), 0, -1 ) ), 'script texts follow the page language' );
$privacy_en = get_permalink( get_page_by_path( 'privacy-en' ) );
t_ok( false !== strpos( $html_en, 'href="' . $privacy_en . '"' ), 'English consent links to the English privacy page' );
t_ok( false !== strpos( $html_bg, 'Приемам <a href="' . get_permalink( get_page_by_path( 'privacy' ) ) . '"' ), 'Bulgarian consent: Bulgarian text and privacy page' );

t_section( 'Guest in English on a Bulgarian site' );
$in  = t_day( 30 );
$out = t_day( 32 );
$GLOBALS['flexo_mails'] = array();
$response = wp_remote_post(
	rest_url( 'flexo-booking/v1/bookings' ),
	array(
		'timeout' => 30,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array( 'room' => 'sea-view', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'guest_name' => 'Tom', 'guest_email' => 'tom@example.com', 'guest_phone' => '1', 'privacy_consent' => true, 'locale' => 'en_US' ) ),
	)
);
$data = json_decode( wp_remote_retrieve_body( $response ), true );
t_eq( 201, wp_remote_retrieve_response_code( $response ), 'booked over HTTP' );
t_ok( 0 === strpos( (string) $data['message'], 'Thank you! We received your booking request' ), 'answer in English' );
t_eq( get_permalink( get_page_by_path( 'thank-you-en' ) ) . '?booking=' . $data['reference'], $data['redirect'], 'redirected to the English thank-you page' );
$booking = Flexo_Booking_Bookings::get_by_reference( $data['reference'] );
t_eq( 'en_US', $booking['locale'], 'booking stored as English' );
$consent = Flexo_Booking_Privacy::consent_for( $booking['id'] );
t_ok( 0 === strpos( $consent['consent_text'], 'I agree to the Privacy Policy (' . $privacy_en . ')' ), 'consent record: the English text and English privacy page' );
$log = array();
foreach ( Flexo_Booking_Emails::log_entries( array( 'booking_id' => $booking['id'] ) ) as $e ) {
	$log[ $e['email_type'] ] = $e['subject'];
}
t_ok( isset( $log['guest_request'] ) && 0 === strpos( $log['guest_request'], 'We received your booking request' ), 'guest email in English: ' . ( isset( $log['guest_request'] ) ? $log['guest_request'] : '' ) );
t_ok( isset( $log['hotel_new_booking'] ) && 0 === strpos( $log['hotel_new_booking'], 'Нова заявка за резервация' ), 'hotel email in Bulgarian: ' . ( isset( $log['hotel_new_booking'] ) ? $log['hotel_new_booking'] : '' ) );

t_section( 'Hotel texts translated with Polylang string translation' );
$s                            = Flexo_Booking_Settings::all();
$s['email_confirmed_subject'] = 'Резервация {booking_ref} е потвърдена – очакваме ви!';
update_option( Flexo_Booking_Settings::OPTION, $s );
$en_lang = PLL()->model->get_language( 'en' );
$mo      = new PLL_MO();
$mo->import_from_db( $en_lang );
$mo->add_entry( $mo->make_entry( $s['email_confirmed_subject'], 'Booking {booking_ref} is confirmed – see you soon!' ) );
$mo->export_to_db( $en_lang );
$strings = Flexo_Booking_I18n::translatable_strings();
t_ok( isset( $strings['email_confirmed_subject'] ) && ! isset( $strings['email_request_body'] ), 'texts the hotel changed are offered to Polylang (group "Flexo Booking"); unchanged built-in texts translate themselves' );
$GLOBALS['flexo_mails'] = array();
Flexo_Booking_Bookings::update_status( $booking['id'], 'confirmed' );
$mail = $GLOBALS['flexo_mails'] ? $GLOBALS['flexo_mails'][0] : array( 'subject' => '' );
t_eq( 'Booking ' . $booking['reference'] . ' is confirmed – see you soon!', $mail['subject'], 'English guest gets the English translation of the hotel\'s own subject' );
$bg_booking = Flexo_Booking_Bookings::create( array_merge( t_guest( array( 'guest_email' => 'ivan@example.com' ) ), array( 'room' => 'sea-view', 'check_in' => $in, 'check_out' => $out, 'privacy_consent' => 1, 'locale' => 'bg_BG' ) ) );
$GLOBALS['flexo_mails'] = array();
Flexo_Booking_Bookings::update_status( $bg_booking['id'], 'confirmed' );
t_eq( 'Резервация ' . $bg_booking['reference'] . ' е потвърдена – очакваме ви!', $GLOBALS['flexo_mails'][0]['subject'], 'Bulgarian guest gets the original' );
t_ok( false !== strpos( $GLOBALS['flexo_mails'][0]['message'], 'Настаняване: ' . Flexo_Booking_Dates::display( $in ) ), 'Bulgarian email dates DD.MM.YYYY' );
$s['email_confirmed_subject'] = '';
update_option( Flexo_Booking_Settings::OPTION, $s );
t_reset_inventory();
t_done();
