<?php
/**
 * Day 4: privacy consent, form fields, retention and anonymising, WordPress
 * personal data export/erase, invoice requests, emails (templates, HTML,
 * log, test email, scheduled reminders), translation of emails, Import/Export.
 */
require __DIR__ . '/lib.php';

global $wpdb;
t_reset_inventory();
foreach ( array( 'consents', 'invoices', 'email_log' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
Flexo_Booking_Features::set_available( null );
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
/** A booking in the past (create() refuses past dates for guests). */
function t4_past_booking( $room_id, $check_in, $check_out, array $extra = array() ) {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert(
		Flexo_Booking_Install::table(),
		array_merge(
			array(
				'reference'   => 'FB-P' . strtoupper( wp_generate_password( 5, false, false ) ),
				'room_id'     => $room_id,
				'check_in'    => $check_in,
				'check_out'   => $check_out,
				'nights'      => 2,
				'adults'      => 2,
				'guest_name'  => 'Past Guest',
				'guest_email' => 'past@example.com',
				'guest_phone' => '+359 1',
				'notes'       => 'Late arrival',
				'total'       => 200,
				'currency'    => 'EUR',
				'status'      => 'confirmed',
				'source'      => 'website',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			$extra
		)
	);
	return Flexo_Booking_Bookings::get( $wpdb->insert_id );
}
$captured = array();
add_filter(
	'flexo_booking_email',
	static function ( $message ) use ( &$captured ) {
		$captured[] = $message;
		return $message;
	}
);

// Start from the built-in email texts, whatever an earlier run left behind.
$t4_defaults = Flexo_Booking_Settings::defaults();
t4_settings( array_intersect_key( array_fill_keys( array_keys( $t4_defaults ), '' ), array_flip( preg_grep( '/^email_.*_(subject|body)$/', array_keys( $t4_defaults ) ) ) ) );
t4_settings(
	array(
		'booking_mode'             => 'request',
		'terms_url'                => '',
		'notification_email'       => 'desk@hotel.test',
		'field_phone'              => 'required',
		'field_notes'              => 'optional',
		'privacy_consent_required' => 1,
		'retention_months'         => 0,
		'email_pre_arrival_enabled' => 0,
		'email_review_enabled'     => 0,
		'review_link'              => '',
		'hotel_phone'              => '+359 52 000 000',
		'email_color'              => '#aa3300',
		'invoice_full_name'        => 'required',
		'invoice_address'          => 'required',
		'invoice_company_name'     => 'required',
		'invoice_company_id'       => 'required',
		'invoice_vat_number'       => 'optional',
		'invoice_company_address'  => 'required',
		'invoice_contact_person'   => 'optional',
	)
);
t4_features( array() );
$room = t_room( 'd4-room', 'D4 Room', array( 'price' => 100, 'capacity' => 3, 'units' => 60 ) );
$g    = t_guest( array( 'guest_email' => 'anna@example.com' ) );
$stay = array( 'room' => 'd4-room', 'check_in' => t_day( 20 ), 'check_out' => t_day( 22 ) );

// ---------------------------------------------------------------------------
t_section( 'Privacy consent' );
t_eq( '', Flexo_Booking_Frontend::extra_fields( 'x' ), 'feature off: no consent checkbox' );
$b = Flexo_Booking_Bookings::create( array_merge( $g, $stay ) );
t_ok( is_array( $b ) && ! Flexo_Booking_Privacy::consent_for( $b['id'] ), 'feature off: booking without consent, nothing recorded' );
t4_features( array( 'privacy_consent' ) );
$html = Flexo_Booking_Frontend::extra_fields( 'x' );
t_ok( false !== strpos( $html, 'name="privacy_consent"' ) && false !== strpos( $html, ' required' ), 'checkbox shown and required' );
t_ok( false === strpos( $html, 'checked' ), 'never ticked in advance' );
t_ok( false !== strpos( $html, 'Privacy Policy' ), 'text with the privacy policy link: ' . wp_strip_all_tags( $html ) );
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay ) );
t_ok( is_wp_error( $e ) && 'flexo_consent' === $e->get_error_code(), 'booking without consent refused: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 'false' ) ) );
t_ok( is_wp_error( $e ), '"false" is not consent' );
delete_transient( 'flexo_rl_' . md5( 'unknown' ) );
$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
$request->set_body_params( array_merge( $g, $stay, array( 'adults' => 2 ) ) );
t_eq( 400, rest_do_request( $request )->get_status(), 'REST: 400 without consent' );
$b = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => true ) ) );
$c = is_array( $b ) ? Flexo_Booking_Privacy::consent_for( $b['id'] ) : null;
t_ok( $c && 1 === (int) $c['granted'] && sha1( $c['consent_text'] ) === $c['text_hash'] && '' !== $c['created_at'], 'consent recorded: time, text and its hash (' . ( $c ? substr( $c['text_hash'], 0, 10 ) : '' ) . ')' );
t_ok( $c && false !== strpos( $c['consent_text'], 'Privacy Policy' ) && false === strpos( $c['consent_text'], '<a' ), 'the exact text the guest agreed to, as plain text' );
t4_settings( array( 'privacy_consent_text' => 'I accept the {privacy_policy} of our guest house.' ) );
$b2 = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1 ) ) );
$c2 = Flexo_Booking_Privacy::consent_for( $b2['id'] );
t_ok( $c2['text_hash'] !== $c['text_hash'] && 0 === strpos( $c2['consent_text'], 'I accept the Privacy Policy' ), 'edited text → new text version' );
t4_settings( array( 'privacy_consent_required' => 0 ) );
$b3 = Flexo_Booking_Bookings::create( array_merge( $g, $stay ) );
t_ok( is_array( $b3 ) && ! Flexo_Booking_Privacy::consent_for( $b3['id'] ), 'not required: booking without ticking, nothing recorded' );
t_ok( false === strpos( Flexo_Booking_Frontend::extra_fields( 'x' ), ' required' ), 'not required: checkbox optional' );
t4_settings( array( 'privacy_consent_required' => 1, 'privacy_consent_text' => Flexo_Booking_Settings::defaults()['privacy_consent_text'] ) );

t_section( 'Guest form fields (data minimisation)' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'guest_phone' => '' ) ) );
t_ok( is_wp_error( $e ) && 'flexo_missing_phone' === $e->get_error_code(), 'phone required by default' );
t4_settings( array( 'field_phone' => 'optional' ) );
t_ok( is_array( Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'guest_phone' => '' ) ) ) ), 'phone optional: booking without phone' );
t4_settings( array( 'field_phone' => 'hidden', 'field_notes' => 'hidden' ) );
$h = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'notes' => 'secret' ) ) );
t_ok( '' === $h['guest_phone'] && '' === $h['notes'], 'fields not asked are never stored, even if sent' );
ob_start();
echo Flexo_Booking_Frontend::render( array() ); // phpcs:ignore
$form = ob_get_clean();
t_ok( false === strpos( $form, 'name="guest_phone"' ) && false === strpos( $form, 'name="notes"' ), 'form without phone and special requests' );
t4_settings( array( 'field_phone' => 'required', 'field_notes' => 'optional' ) );

// ---------------------------------------------------------------------------
t_section( 'Invoice request' );
$inv = array(
	'requested' => true,
	'type'      => 'individual',
	'full_name' => 'Anna Petrova',
	'address'   => 'ul. Vitosha 1, Sofia',
);
$x = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => $inv ) ) );
t_ok( is_array( $x ) && null === Flexo_Booking_Invoices::get( $x['id'] ), 'feature off: invoice details ignored' );
t4_features( array( 'privacy_consent', 'invoice_request' ) );
$plain = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1 ) ) );
t_ok( is_array( $plain ) && null === Flexo_Booking_Invoices::get( $plain['id'] ), 'booking without invoice' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => array_merge( $inv, array( 'address' => '' ) ) ) ) );
t_ok( is_wp_error( $e ) && 'flexo_invoice' === $e->get_error_code(), 'required field missing: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
t4_mails( true );
$ind = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => $inv ) ) );
$row = Flexo_Booking_Invoices::get( $ind['id'] );
t_ok( $row && 'individual' === $row['invoice_type'] && 'Anna Petrova' === $row['full_name'] && '' === $row['company_name'], 'individual invoice stored separately' );
$hotel = array_values( array_filter( t4_mails(), static function ( $m ) { return false !== strpos( t4_to( $m ), 'desk@hotel.test' ); } ) );
t_ok( $hotel && false !== strpos( $hotel[0]['message'], 'INVOICE REQUESTED' ) && false !== strpos( $hotel[0]['message'], 'ul. Vitosha 1, Sofia' ), 'shown in the hotel\'s new-booking email' );
$company = array(
	'requested'       => true,
	'type'            => 'company',
	'full_name'       => 'ignored for companies',
	'company_name'    => 'Flexo Travel EOOD',
	'company_id'      => '12345',
	'vat_number'      => 'DE123456789',
	'company_address' => 'bul. Knyaz Boris 10, Varna',
	'contact_person'  => 'Ivan Ivanov',
);
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => $company ) ) );
t_ok( is_wp_error( $e ) && false !== strpos( $e->get_error_message(), '9 or 13 digits' ), 'EIK with 5 digits refused: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
$co = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => array_merge( $company, array( 'company_id' => '203 456 789' ) ) ) ) );
$row = Flexo_Booking_Invoices::get( $co['id'] );
t_ok( $row && 'company' === $row['invoice_type'] && '203456789' === $row['company_id'] && 'DE123456789' === $row['vat_number'] && '' === $row['full_name'], 'company invoice: EIK with spaces accepted, foreign VAT number not checked, person fields dropped' );
$fr = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => array_merge( $company, array( 'company_id' => 'RCS 552 100 554' ) ) ) ) );
t_ok( is_array( $fr ), 'foreign company number with letters accepted' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => array_merge( $company, array( 'company_name' => '' ) ) ) ) );
t_ok( is_wp_error( $e ), 'company name required' );
t4_settings( array( 'invoice_company_id' => 'optional', 'invoice_contact_person' => 'hidden' ) );
$opt = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'privacy_consent' => 1, 'invoice' => array_merge( $company, array( 'company_id' => '' ) ) ) ) );
$row = Flexo_Booking_Invoices::get( $opt['id'] );
t_ok( $row && '' === $row['company_id'] && '' === $row['contact_person'], 'fields configurable: company ID optional, contact person hidden (not stored)' );
t_ok( false === strpos( Flexo_Booking_Invoices::render_fields( 'x' ), 'invoice_contact_person' ), 'hidden field not in the form' );
t4_settings( array( 'invoice_company_id' => 'required', 'invoice_contact_person' => 'optional' ) );
delete_transient( 'flexo_rl_' . md5( 'unknown' ) );
$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
$request->set_body_params( array_merge( $g, $stay, array( 'adults' => 2, 'privacy_consent' => true, 'invoice' => $company ) ) );
$response = rest_do_request( $request );
t_eq( 400, $response->get_status(), 'REST: invalid invoice details → 400' );
wp_set_current_user( 1 );
ob_start();
$_GET = array( 'booking' => $co['id'] );
Flexo_Booking_Admin::render_list();
$page = ob_get_clean();
$_GET = array();
t_ok( false !== strpos( $page, 'Invoice requested' ) && false !== strpos( $page, 'Flexo Travel EOOD' ) && false !== strpos( $page, '203456789' ), 'shown on the booking details page' );
t_ok( false !== strpos( $page, 'Privacy consent' ) && false !== strpos( $page, 'Text version' ), 'consent shown on the booking details page' );
t_ok( false !== strpos( Flexo_Booking_Invoices::text( $co['id'] ), 'Company ID (EIK/BULSTAT): 203456789' ), 'plain-text block for emails' );

// ---------------------------------------------------------------------------
t_section( 'Retention and anonymising' );
t4_features( array( 'privacy_consent', 'invoice_request' ) );
$old = t4_past_booking( $room, wp_date( 'Y-m-d', strtotime( '-14 months' ) ), wp_date( 'Y-m-d', strtotime( '-14 months +2 days' ) ) );
$new = t4_past_booking( $room, wp_date( 'Y-m-d', strtotime( '-11 months' ) ), wp_date( 'Y-m-d', strtotime( '-11 months +2 days' ) ) );
Flexo_Booking_Invoices::save( $old['id'], array( 'invoice_type' => 'individual', 'full_name' => 'Old Guest', 'address' => 'Somewhere' ) );
t_eq( 0, Flexo_Booking_Privacy::run_retention(), 'retention off by default (0 months): nothing removed' );
t4_settings( array( 'retention_months' => 12 ) );
t_eq( 1, Flexo_Booking_Privacy::run_retention(), '12 months: one booking older than that anonymised' );
$o = Flexo_Booking_Bookings::get( $old['id'] );
t_ok( '' === $o['guest_name'] && '' === $o['guest_email'] && '' === $o['guest_phone'] && '' === $o['notes'] && $o['anonymized_at'], 'name, email, phone and requests removed' );
t_ok( $o['check_in'] === $old['check_in'] && 200.0 === $o['total'] && 'confirmed' === $o['status'] && $o['room_id'] === $room, 'dates, room, price and status kept' );
t_eq( null, Flexo_Booking_Invoices::get( $old['id'] ), 'invoice details removed' );
t_ok( 'Past Guest' === Flexo_Booking_Bookings::get( $new['id'] )['guest_name'], 'the 11-month-old booking is kept' );
t_eq( 0, Flexo_Booking_Privacy::run_retention(), 'running again changes nothing' );
t4_features( array( 'invoice_request' ) );
t4_settings( array( 'retention_months' => 1 ) );
t_eq( 0, Flexo_Booking_Privacy::run_retention(), 'feature off: retention doesn\'t run' );
t4_features( array( 'privacy_consent', 'invoice_request' ) );
t4_settings( array( 'retention_months' => 0 ) );
Flexo_Booking_Privacy::anonymise( $ind['id'] );
$a = Flexo_Booking_Bookings::get( $ind['id'] );
t_ok( '' === $a['guest_email'] && null === Flexo_Booking_Invoices::get( $ind['id'] ) && $a['anonymized_at'], 'manual "Anonymise" on one booking' );
t_ok( Flexo_Booking_Privacy::consent_for( $ind['id'] ), 'the consent record (no personal data) is kept as evidence' );
t4_mails( true );
Flexo_Booking_Bookings::update_status( $a['id'], 'confirmed' );
t_ok( ! array_filter( t4_mails(), static function ( $m ) { return false !== strpos( t4_to( $m ), 'anna' ); } ), 'no email to an anonymised booking' );

t_section( 'WordPress Export / Erase Personal Data' );
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
t_ok( isset( $exporters['flexo-booking'], $erasers['flexo-booking'] ), 'registered with WordPress\'s privacy tools' );
$export = call_user_func( $exporters['flexo-booking']['callback'], 'anna@example.com', 1 );
$groups = array_count_values( wp_list_pluck( $export['data'], 'group_id' ) );
t_ok( isset( $groups['flexo-bookings'] ) && $groups['flexo-bookings'] >= 5, 'export: the guest\'s bookings (' . ( isset( $groups['flexo-bookings'] ) ? $groups['flexo-bookings'] : 0 ) . ')' );
t_ok( ! empty( $groups['flexo-booking-invoices'] ), 'export: invoice details' );
t_ok( ! empty( $groups['flexo-booking-emails'] ), 'export: emails sent' );
$flat = wp_json_encode( $export['data'] );
t_ok( false !== strpos( $flat, 'Flexo Travel EOOD' ) && false !== strpos( $flat, 'Privacy consent' ) && $export['done'], 'export includes company details and the consent record' );
$erase = call_user_func( $erasers['flexo-booking']['callback'], 'anna@example.com', 1 );
t_ok( $erase['items_removed'] && $erase['done'] && $erase['messages'], 'erase: ' . implode( ' ', $erase['messages'] ) );
$after = call_user_func( $exporters['flexo-booking']['callback'], 'anna@example.com', 1 );
t_eq( array(), $after['data'], 'nothing left for that email address' );
t_eq( null, Flexo_Booking_Invoices::get( $co['id'] ), 'company invoice details erased' );
t_ok( '' === Flexo_Booking_Bookings::get( $co['id'] )['guest_name'] && 100.0 < Flexo_Booking_Bookings::get( $co['id'] )['total'], 'booking kept without personal data' );
t_eq( array(), Flexo_Booking_Emails::log_entries( array( 'recipient' => 'anna@example.com' ) ), 'email log entries for the address removed' );
t_ok( false !== has_action( 'admin_init', array( 'Flexo_Booking_Privacy', 'policy_text' ) ), 'privacy policy guide text registered (Settings → Privacy → Policy Guide)' );

// ---------------------------------------------------------------------------
t_section( 'Emails' );
t_reset_inventory();
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'email_log' ) );
t4_features( array() );
t4_settings( array( 'notification_email' => 'desk@hotel.test, owner@hotel.test' ) );
t4_mails( true );
$captured = array();
$e1 = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'mia@example.com' ) ) );
$m  = t4_mails( true );
$to = array_map( 't4_to', $m );
t_ok( in_array( 'mia@example.com', $to, true ) && in_array( 'desk@hotel.test,owner@hotel.test', $to, true ), 'request: guest email + hotel email to both addresses' );
$guest_mail = $m[ array_search( 'mia@example.com', $to, true ) ];
t_ok( 0 === strpos( $guest_mail['subject'], 'We received your booking request' ), 'request received subject: ' . $guest_mail['subject'] );
t_ok( false !== strpos( $guest_mail['message'], '<html' ) && false !== strpos( $guest_mail['message'], '#aa3300' ) && false !== strpos( $guest_mail['message'], 'max-width:600px' ), 'HTML email in the hotel colour, 600 px wide' );
t_ok( in_array( 'Content-Type: text/html; charset=UTF-8', (array) $guest_mail['headers'], true ), 'sent as HTML' );
$text = wp_list_filter( $captured, array( 'type' => 'guest_request' ) );
$text = reset( $text );
t_ok( $text && false === strpos( $text['text'], '<' ) && false !== strpos( $text['text'], $e1['reference'] ), 'plain-text version with the same content' );
$hotel_mail = $m[ array_search( 'desk@hotel.test,owner@hotel.test', $to, true ) ];
t_ok( 0 === strpos( $hotel_mail['subject'], 'New booking request' ) && false !== strpos( $hotel_mail['message'], 'Open the booking' ), 'hotel: "New booking request", link to the booking' );
Flexo_Booking_Bookings::update_status( $e1['id'], 'confirmed' );
$m = t4_mails( true );
t_ok( 1 === count( $m ) && 0 === strpos( $m[0]['subject'], 'Your booking ' . $e1['reference'] . ' is confirmed' ), 'confirmed: guest email' );
Flexo_Booking_Bookings::update_status( $e1['id'], 'cancelled' );
$m    = t4_mails( true );
$subj = wp_list_pluck( $m, 'subject' );
t_ok( 2 === count( $m ) && in_array( 'Your booking ' . $e1['reference'] . ' has been cancelled', $subj, true ) && preg_grep( '/^Booking cancelled FB-/', $subj ), 'cancelled: guest email + hotel notification' );
t4_settings( array( 'notify_new' => 0, 'notify_cancelled' => 0 ) );
$e2 = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'mia@example.com' ) ) );
Flexo_Booking_Bookings::update_status( $e2['id'], 'cancelled' );
t_ok( ! array_filter( t4_mails( true ), static function ( $m ) { return false !== strpos( t4_to( $m ), 'desk@' ); } ), 'hotel notifications can be switched off' );
t4_settings( array( 'notify_new' => 1, 'notify_cancelled' => 1 ) );
t4_settings( array( 'email_confirmed_subject' => 'Welcome {guest_name}! ({booking_ref})', 'email_confirmed_body' => "Call us: {hotel_phone}\n{rate_plan}{booking_details}" ) );
$e3 = Flexo_Booking_Bookings::create( array_merge( $g, $stay, array( 'guest_email' => 'mia@example.com', 'guest_name' => 'Mia' ) ) );
t4_mails( true );
Flexo_Booking_Bookings::update_status( $e3['id'], 'confirmed' );
$m = t4_mails( true );
t_ok( 'Welcome Mia! (' . $e3['reference'] . ')' === $m[0]['subject'] && false !== strpos( $m[0]['message'], '+359 52 000 000' ), 'edited template with {booking_ref} and {hotel_phone}' );
t4_settings( array( 'email_confirmed_subject' => '', 'email_confirmed_body' => '' ) );
Flexo_Booking_Bookings::update_status( $e3['id'], 'cancelled' );
Flexo_Booking_Bookings::update_status( $e3['id'], 'confirmed' );
$m = array_values( array_filter( t4_mails( true ), static function ( $m ) { return 'mia@example.com' === t4_to( $m ) && false !== strpos( $m['subject'], 'confirmed' ); } ) );
t_ok( $m && 0 === strpos( $m[0]['subject'], 'Your booking' ), 'an emptied template falls back to the built-in text' );

t_section( 'Scheduled emails: reminder and review request' );
t_reset_inventory();
t4_settings( array( 'email_pre_arrival_enabled' => 1, 'email_pre_arrival_days' => 3, 'email_review_enabled' => 1, 'email_review_days' => 1, 'review_link' => 'https://g.page/r/test/review' ) );
$today = wp_date( 'Y-m-d' );
$soon  = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, 2 ), Flexo_Booking_Dates::add_days( $today, 4 ), array( 'guest_email' => 'soon@example.com' ) );
$later = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, 5 ), Flexo_Booking_Dates::add_days( $today, 7 ), array( 'guest_email' => 'later@example.com' ) );
$canc  = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, 1 ), Flexo_Booking_Dates::add_days( $today, 3 ), array( 'guest_email' => 'cancelled@example.com', 'status' => 'cancelled' ) );
$pend  = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, 1 ), Flexo_Booking_Dates::add_days( $today, 3 ), array( 'guest_email' => 'pending@example.com', 'status' => 'pending' ) );
$stay1 = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, -3 ), Flexo_Booking_Dates::add_days( $today, -1 ), array( 'guest_email' => 'stayed@example.com' ) );
$stay2 = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, -12 ), Flexo_Booking_Dates::add_days( $today, -10 ), array( 'guest_email' => 'long-ago@example.com' ) );
$stay3 = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, -3 ), Flexo_Booking_Dates::add_days( $today, -1 ), array( 'guest_email' => 'stay-cancelled@example.com', 'status' => 'cancelled' ) );
t4_mails( true );
t_eq( 0, Flexo_Booking_Emails::send_scheduled( $today . ' 23:30:00' ), 'nothing at night (23:30)' );
$sent = Flexo_Booking_Emails::send_scheduled( $today . ' 10:00:00' );
$to   = array_map( 't4_to', t4_mails( true ) );
sort( $to );
t_eq( array( 'soon@example.com', 'stayed@example.com' ), $to, 'at 10:00: reminder for the arrival in 2 days, review request for yesterday\'s check-out – not for cancelled, pending, later or old stays' );
t_eq( 2, $sent, 'two emails sent' );
t_eq( 0, Flexo_Booking_Emails::send_scheduled( $today . ' 11:00:00' ), 'next hour: nothing sent twice' );
t_ok( Flexo_Booking_Emails::was_sent( Flexo_Booking_Bookings::get( $soon['id'] ), 'pre_arrival' ), 'booking remembers the reminder' );
$log = Flexo_Booking_Emails::log_entries( array( 'booking_id' => $stay1['id'] ) );
t_ok( $log && 'guest_review' === $log[0]['email_type'] && 'sent' === $log[0]['status'], 'review request logged' );
$review = array_values( wp_list_filter( $captured, array( 'type' => 'guest_review' ) ) );
t_ok( $review && false !== strpos( end( $review )['text'], 'https://g.page/r/test/review' ), 'review email contains the review link' );
$pre = array_values( wp_list_filter( $captured, array( 'type' => 'guest_pre_arrival' ) ) );
t_ok( $pre && false !== strpos( end( $pre )['text'], '+359 52 000 000' ), 'reminder contains the hotel phone' );
Flexo_Booking_Bookings::update_status( $later['id'], 'cancelled' );
t4_mails( true );
Flexo_Booking_Emails::send_scheduled( Flexo_Booking_Dates::add_days( $today, 3 ) . ' 10:00:00' );
t_ok( ! in_array( 'later@example.com', array_map( 't4_to', t4_mails( true ) ), true ), 'booking cancelled before its reminder: not sent' );
t4_settings( array( 'review_link' => '' ) );
$stay4 = t4_past_booking( $room, Flexo_Booking_Dates::add_days( $today, -3 ), Flexo_Booking_Dates::add_days( $today, -1 ), array( 'guest_email' => 'nolink@example.com' ) );
Flexo_Booking_Emails::send_scheduled( $today . ' 12:00:00' );
t_ok( ! in_array( 'nolink@example.com', array_map( 't4_to', t4_mails( true ) ), true ), 'no review link: no review request' );
t4_features( array( 'booking_request' ) );
t_eq( 0, Flexo_Booking_Emails::send_scheduled( $today . ' 12:00:00' ), 'guest emails off: nothing scheduled is sent' );
t4_features( array() );
t4_settings( array( 'email_pre_arrival_enabled' => 0, 'email_review_enabled' => 0 ) );
t_ok( wp_next_scheduled( Flexo_Booking_Emails::HOURLY ) && wp_next_scheduled( Flexo_Booking_Emails::DAILY ), 'hourly and daily WP-Cron events scheduled' );

t_section( 'Test email, log, cleanup, SMTP check' );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'email_log' ) );
t4_mails( true );
t_ok( Flexo_Booking_Emails::send_test( 'owner@hotel.test' ), 'test email sent' );
$m = t4_mails( true );
t_ok( 1 === count( $m ) && 0 === strpos( $m[0]['subject'], 'Test email: ' ) && false !== strpos( $m[0]['message'], 'emails to guests and to you are working' ), 'test email: example booking with a clear note' );
$log = Flexo_Booking_Emails::log_entries();
t_ok( 'test' === $log[0]['email_type'] && 'sent' === $log[0]['status'] && 'owner@hotel.test' === $log[0]['recipient'], 'log: recipient, type, time, sent' );
$fail = static function () {
	do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );
	return false;
};
add_filter( 'pre_wp_mail', $fail, 99 );
t_ok( ! Flexo_Booking_Emails::send_test( 'owner@hotel.test' ), 'failing mail server: reported as not sent' );
remove_filter( 'pre_wp_mail', $fail, 99 );
$log = Flexo_Booking_Emails::log_entries();
t_ok( 'failed' === $log[0]['status'] && 'SMTP connect() failed.' === $log[0]['error'], 'log: failure with the reason' );
$wpdb->insert( Flexo_Booking_Schema::table( 'email_log' ), array( 'email_type' => 'test', 'recipient' => 'x@y.z', 'subject' => 'old', 'status' => 'sent', 'created_at' => wp_date( 'Y-m-d H:i:s', strtotime( '-120 days' ) ) ) );
t_eq( 1, Flexo_Booking_Emails::cleanup_log(), 'cleanup removes entries older than 90 days' );
t_eq( 2, count( Flexo_Booking_Emails::log_entries() ), 'recent entries kept' );
t_ok( Flexo_Booking_Emails::smtp_detected(), 'mail capture in this test site counts as a mail plugin' );
$saved = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
$phpm = $GLOBALS['wp_filter']['phpmailer_init'] ?? null;
unset( $GLOBALS['wp_filter']['phpmailer_init'] );
t_ok( ! Flexo_Booking_Emails::smtp_detected(), 'without any mail plugin the SMTP notice is shown' );
// Real PHPMailer: the plain-text part is attached; without a mail server the failure is logged.
add_filter(
	'wp_mail_from',
	static function () {
		return 'hotel@example.com';
	}
);
$alt = null;
add_action(
	'phpmailer_init',
	static function ( $phpmailer ) use ( &$alt ) {
		$alt = $phpmailer->AltBody; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	},
	99
);
Flexo_Booking_Emails::send_test( 'owner@hotel.test' );
t_ok( $alt && false !== strpos( $alt, 'working' ) && false === strpos( $alt, '<p' ), 'PHPMailer gets the plain-text version (AltBody)' . ( $alt ? '' : ' – got ' . var_export( $alt, true ) ) );
$log = Flexo_Booking_Emails::log_entries();
t_ok( 'failed' === $log[0]['status'] && '' !== (string) $log[0]['error'], 'no mail server here: logged as failed – ' . $log[0]['error'] );
if ( $saved ) {
	$GLOBALS['wp_filter']['pre_wp_mail'] = $saved;
}
if ( $phpm ) {
	$GLOBALS['wp_filter']['phpmailer_init'] = $phpm;
} else {
	unset( $GLOBALS['wp_filter']['phpmailer_init'] );
}

// ---------------------------------------------------------------------------
t_section( 'Import / Export of Day 4 settings' );
t4_settings( array( 'email_review_enabled' => 1, 'review_link' => 'https://g.page/r/x/review', 'privacy_consent_text' => 'Custom consent {privacy_policy}', 'retention_months' => 24, 'invoice_vat_number' => 'hidden', 'tracking_meta_pixel' => 1, 'email_pre_arrival_body' => 'Parking is behind the hotel.' ) );
$data = Flexo_Booking_Portability::export();
$s    = $data['settings'];
t_ok( 'https://g.page/r/x/review' === $s['review_link'] && 'Parking is behind the hotel.' === $s['email_pre_arrival_body'] && 1 === $s['email_review_enabled'], 'email templates and schedule in the file' );
t_ok( 'Custom consent {privacy_policy}' === $s['privacy_consent_text'] && 24 === $s['retention_months'], 'privacy settings in the file' );
t_ok( 'hidden' === $s['invoice_vat_number'] && 1 === $s['tracking_meta_pixel'], 'invoice and tracking settings in the file' );
t_eq( '', $s['notification_email'], 'hotel notification addresses are not exported' );
t4_settings( array( 'review_link' => '', 'retention_months' => 0, 'invoice_vat_number' => 'optional', 'tracking_meta_pixel' => 0, 'notification_email' => 'own@site.test' ) );
Flexo_Booking_Portability::import( $data, array( 'rooms' => false ) );
$after = Flexo_Booking_Settings::all();
t_ok( 'https://g.page/r/x/review' === $after['review_link'] && 24 === $after['retention_months'] && 'hidden' === $after['invoice_vat_number'] && 1 === $after['tracking_meta_pixel'] && 'own@site.test' === $after['notification_email'], 'imported; this site keeps its own notification addresses' );

// Reset for other tests.
t_reset_inventory();
$defaults = Flexo_Booking_Settings::defaults();
t4_settings(
	array(
		'notification_email'        => '',
		'review_link'               => '',
		'retention_months'          => 0,
		'email_review_enabled'      => 0,
		'email_pre_arrival_enabled' => 0,
		'privacy_consent_text'      => $defaults['privacy_consent_text'],
		'email_pre_arrival_body'    => $defaults['email_pre_arrival_body'],
		'tracking_meta_pixel'       => 0,
		'invoice_vat_number'        => 'optional',
		'hotel_phone'               => '',
		'email_color'               => '#1f6f5c',
	)
);
t4_features( array() );
t_done();
