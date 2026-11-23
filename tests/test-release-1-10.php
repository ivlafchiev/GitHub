<?php
/**
 * 1.10.0 (UI, responsive and widget controls release), the server side:
 * button and field corners, room card icons (Appearance, a page's own look,
 * the shortcode), the contact form (shortcode, REST, storage, emails,
 * Today, privacy, limits), the Booking & Contact tabs, readable email
 * colours, and that nothing changes for sites that don't use the new
 * settings.
 *
 *   wp eval-file tests/test-release-1-10.php
 */
require __DIR__ . '/lib.php';

$rel_saved = array(
	'settings' => get_option( Flexo_Booking_Settings::OPTION ),
	'forms'    => get_option( Flexo_Booking_Forms::OPTION ),
	'pages'    => get_option( Flexo_Booking_System_Pages::OPTION ),
	'features' => get_option( 'flexo_booking_features' ),
);
register_shutdown_function(
	static function () use ( $rel_saved ) {
		update_option( Flexo_Booking_Settings::OPTION, $rel_saved['settings'] );
		false === $rel_saved['forms'] ? delete_option( Flexo_Booking_Forms::OPTION ) : update_option( Flexo_Booking_Forms::OPTION, $rel_saved['forms'] );
		false === $rel_saved['pages'] ? delete_option( Flexo_Booking_System_Pages::OPTION ) : update_option( Flexo_Booking_System_Pages::OPTION, $rel_saved['pages'] );
		false === $rel_saved['features'] ? delete_option( 'flexo_booking_features' ) : update_option( 'flexo_booking_features', $rel_saved['features'] );
	}
);
function rel_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function rel_mails( callable $send ) {
	$GLOBALS['flexo_mails'] = array();
	$send();
	return isset( $GLOBALS['flexo_mails'] ) ? $GLOBALS['flexo_mails'] : array();
}
function rel_clear_limits() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_flexo\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_flexo\\_rl\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	wp_cache_flush();
}

Flexo_Booking_Features::set_enabled( array_unique( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'custom_appearance' ) ) ) );

/* --------------------------------------------------------------------- */
t_section( 'Nothing changes without the new settings' );
rel_settings(
	array(
		'appearance_mode'    => 'custom',
		'appearance_primary' => '#225577',
	)
);
$css = Flexo_Booking_Appearance::css();
t_ok( false !== strpos( $css, '.flexo-booking{--fb-primary:#225577' ), 'Custom: the form\'s variables as before' );
t_ok( false === strpos( $css, '--fb-button-radius' ) && false === strpos( $css, '--fb-field-radius' ), 'no button or field corners unless chosen' );
t_ok( false === strpos( $css, '--fb-amenity' ) && false === strpos( $css, '--fb-icon-' ), 'no room card icon settings unless chosen' );
t_ok( false !== strpos( $css, '.flexo-forms-tabs{--fb-primary:#225577' ), 'the Booking & Contact tabs get the main colour too' );
$defaults = Flexo_Booking_Settings::defaults();
foreach ( array( 'appearance_button_corners', 'appearance_field_corners', 'appearance_amenity_icons', 'appearance_icon_color', 'appearance_icon_size' ) as $key ) {
	t_eq( '', $defaults[ $key ], "{$key}: empty by default (as before)" );
}

/* --------------------------------------------------------------------- */
t_section( 'Button and field corners (Appearance)' );
rel_settings( array( 'appearance_button_corners' => 'pill' ) );
t_ok( false !== strpos( Flexo_Booking_Appearance::css(), '--fb-button-radius:999px' ), 'pill buttons: round ends' );
rel_settings(
	array(
		'appearance_button_corners'    => 'custom',
		'appearance_button_corners_px' => 150,
		'appearance_field_corners'     => 'buttons',
	)
);
$v = Flexo_Booking_Appearance::values();
t_eq( 100, $v['appearance_button_corners_px'], 'exact radius kept within 0–100 px' );
$css = Flexo_Booking_Appearance::css();
t_ok( false !== strpos( $css, '--fb-button-radius:100px' ), 'exact radius written' );
t_ok( false !== strpos( $css, '--fb-field-radius:var(--fb-button-radius)' ), 'fields can match the buttons' );
rel_settings(
	array(
		'appearance_button_corners' => '<script>',
		'appearance_field_corners'  => '0px;}body{display:none',
	)
);
$css = Flexo_Booking_Appearance::css();
t_ok( false === strpos( $css, '--fb-button-radius' ) && false === strpos( $css, 'display:none' ), 'unknown choices are refused (no CSS injection)' );
rel_settings(
	array(
		'appearance_button_corners' => 'slight',
		'appearance_field_corners'  => 'custom',
		'appearance_field_corners_px' => 3,
	)
);
$css = Flexo_Booking_Appearance::css();
t_ok( false !== strpos( $css, '--fb-button-radius:6px' ) && false !== strpos( $css, '--fb-field-radius:3px' ), 'presets and exact field radius' );
rel_settings(
	array(
		'appearance_mode' => 'match',
	)
);
t_eq( '', Flexo_Booking_Appearance::css(), '"Match my website": none of it is added' );
rel_settings( array( 'appearance_mode' => 'custom' ) );

/* --------------------------------------------------------------------- */
t_section( 'A page\'s own look: button and field corners' );
$pages                          = Flexo_Booking_System_Pages::all();
$pages['booking']['appearance'] = array_merge(
	Flexo_Booking_System_Pages::appearance_defaults(),
	array(
		'use_global'        => 0,
		'button_corners'    => 'rounded',
		'field_corners'     => 'custom',
		'field_corners_px'  => 12,
	)
);
update_option( Flexo_Booking_System_Pages::OPTION, $pages );
$page_css = Flexo_Booking_Appearance::page_css( 'booking' );
t_ok( 0 === strpos( $page_css, '.flexo-system-page--booking,.flexo-system-page--booking .flexo-booking{' ), 'scoped to the Booking page' );
t_ok( false !== strpos( $page_css, '--fb-button-radius:16px' ) && false !== strpos( $page_css, '--fb-field-radius:12px' ), 'its own button and field corners' );
$clean = Flexo_Booking_System_Pages::sanitize_appearance( array( 'button_corners' => 'oval', 'field_corners_px' => -4 ), 'booking' );
t_ok( '' === $clean['button_corners'] && 0 === $clean['field_corners_px'], 'page settings cleaned' );
// A page look saved before 1.10.0 (without the new keys) still reads them.
$pages['booking']['appearance'] = array(
	'use_global' => 0,
	'primary'    => '#113355',
);
update_option( Flexo_Booking_System_Pages::OPTION, $pages );
$look = Flexo_Booking_System_Pages::get( 'booking' )['appearance'];
t_ok( isset( $look['button_corners'], $look['field_corners_px'] ) && '' === $look['button_corners'], 'older saved page looks get the new settings, empty' );

/* --------------------------------------------------------------------- */
t_section( 'Room card icons' );
rel_settings( array( 'appearance_amenity_icons' => 'tick', 'appearance_icon_color' => '#aa3300', 'appearance_icon_size' => 'large' ) );
$css = Flexo_Booking_Appearance::css();
t_ok( false !== strpos( $css, '--fb-amenity-tick:var(--fb-tick-icon)' ), 'a simple tick for every amenity' );
t_ok( false !== strpos( $css, '--fb-icon-color:#aa3300' ) && false !== strpos( $css, '--fb-icon-size:1.4em' ), 'icon colour and size' );
rel_settings( array( 'appearance_amenity_icons' => 'none' ) );
t_ok( false !== strpos( Flexo_Booking_Appearance::css(), '--fb-amenity-icon-display:none' ), 'no icon' );
rel_settings( array( 'appearance_amenity_icons' => 'stars' ) );
t_eq( '', Flexo_Booking_Appearance::values()['appearance_amenity_icons'], 'unknown choice: each amenity\'s own icon' );
$html = do_shortcode( '[flexo_booking amenity_icons="tick"]' );
t_ok( false !== strpos( $html, 'data-fb-amenities="tick"' ), 'shortcode: its own choice on the form' );
$html = do_shortcode( '[flexo_booking]' );
t_ok( false === strpos( $html, 'data-fb-amenities' ), 'shortcode without it: Appearance decides' );
$html = do_shortcode( '[flexo_booking amenity_icons="x\'onmouseover=alert(1)"]' );
t_ok( false === strpos( $html, 'onmouseover' ) && false === strpos( $html, 'data-fb-amenities' ), 'unknown value ignored' );
$booking_css = (string) file_get_contents( FLEXO_BOOKING_DIR . 'assets/css/booking.css' );
t_ok( false !== strpos( $booking_css, '.flexo-booking[data-fb-amenities="none"]' ) && false !== strpos( $booking_css, '--fb-tick-icon:' ), 'the stylesheet draws the three choices' );
t_eq( 11, substr_count( $booking_css, '!important' ), 'booking.css: no new !important' );
t_eq( 0, substr_count( (string) file_get_contents( FLEXO_BOOKING_DIR . 'assets/css/flexo-admin.css' ), '!important' ), 'admin stylesheet: no !important' );

/* --------------------------------------------------------------------- */
t_section( 'Contact form: settings' );
delete_option( Flexo_Booking_Forms::OPTION );
$forms = Flexo_Booking_Forms::all();
t_eq( 'optional', $forms['contact_phone'], 'phone optional by default' );
t_eq( 'hidden', $forms['contact_subject'], 'subject not asked by default' );
t_eq( 0, $forms['contact_autoreply'], 'no answer to the guest by default' );
Flexo_Booking_Forms::update(
	array(
		'contact_fields'    => array(
			'message' => array(
				'label'       => 'Your question',
				'placeholder' => 'How can we help?',
			),
		),
		'contact_phone'     => 'loud',
		'contact_subject'   => 'required',
		'contact_texts'     => array( 'button' => 'Send it <b>now</b>' ),
		'contact_autoreply' => '1',
	)
);
$forms = Flexo_Booking_Forms::all();
t_eq( 'Your question', $forms['contact_fields']['message']['label'], 'own label kept' );
t_eq( '', $forms['contact_fields']['message']['placeholder'], 'default text not stored (stays translated)' );
t_eq( 'optional', $forms['contact_phone'], 'unknown mode refused' );
t_eq( 'required', $forms['contact_subject'], 'subject can be required' );
t_eq( 'Send it now', $forms['contact_texts']['button'], 'texts are plain text' );
t_eq( 1, $forms['contact_autoreply'], 'answer to the guest switched on' );
t_ok( isset( Flexo_Booking_Forms::translatable_strings()['field_contact_message_label'] ), 'own texts offered to Polylang / WPML' );
delete_option( Flexo_Booking_Forms::OPTION );

/* --------------------------------------------------------------------- */
t_section( 'Contact form: markup' );
rel_settings( array( 'privacy_consent_required' => 1 ) );
Flexo_Booking_Features::set_enabled( array_unique( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'privacy_consent' ) ) ) );
$html = do_shortcode( '[flexo_contact title="Write to us" intro="We reply within a day."]' );
t_ok( false !== strpos( $html, 'data-flexo-contact' ) && false !== strpos( $html, 'class="flexo-booking flexo-booking--contact"' ), 'the booking form\'s look and script' );
t_ok( false !== strpos( $html, 'name="name"' ) && false !== strpos( $html, 'name="email"' ) && false !== strpos( $html, 'name="message"' ) && false !== strpos( $html, 'name="phone"' ), 'name, email, phone and message' );
t_ok( false === strpos( $html, 'name="subject"' ), 'subject not asked by default' );
t_ok( false !== strpos( $html, 'name="fb_website"' ), 'spam trap' );
t_ok( false !== strpos( $html, 'to answer my message' ) && false === strpos( $html, 'my booking' ), 'privacy consent worded for a message' );
t_ok( false !== strpos( $html, '>Write to us<' ) && false !== strpos( $html, 'We reply within a day.' ), 'heading and intro' );
$html = do_shortcode( '[flexo_contact title="<img src=x onerror=alert(1)>"]' );
t_ok( false === strpos( $html, '<img src=x' ), 'texts escaped' );
Flexo_Booking_Forms::update( array( 'contact_phone' => 'hidden', 'contact_subject' => 'optional' ) );
$html = do_shortcode( '[flexo_contact]' );
t_ok( false === strpos( $html, 'name="phone"' ) && false !== strpos( $html, 'name="subject"' ), 'phone not asked, subject asked' );
delete_option( Flexo_Booking_Forms::OPTION );

/* --------------------------------------------------------------------- */
t_section( 'Contact form: sending' );
$routes = rest_get_server()->get_routes();
t_ok( isset( $routes['/flexo-booking/v1/contact'] ), 'REST route /contact' );
rel_clear_limits();
$base = array(
	'name'            => 'Maria Petrova',
	'email'           => 'maria@example.com',
	'message'         => 'Can we bring a dog?',
	'privacy_consent' => '1',
	'locale'          => 'en_US',
);
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'name' => '' ) ) );
t_ok( is_wp_error( $r ) && 'name' === $r->get_error_data()['field'], 'name required' );
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'email' => 'maria' ) ) );
t_ok( is_wp_error( $r ) && 'email' === $r->get_error_data()['field'], 'valid email required' );
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'message' => '   ' ) ) );
t_ok( is_wp_error( $r ) && 'message' === $r->get_error_data()['field'], 'message required' );
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'privacy_consent' => '' ) ) );
t_ok( is_wp_error( $r ) && 'privacy_consent' === $r->get_error_data()['field'], 'privacy consent when it is required' );
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'fb_website' => 'http://spam' ) ) );
t_ok( is_wp_error( $r ) && 'flexo_spam' === $r->get_error_code(), 'spam trap' );
Flexo_Booking_Forms::update( array( 'contact_phone' => 'required', 'contact_subject' => 'required' ) );
$r = Flexo_Booking_Guest::contact_message( $base );
t_ok( is_wp_error( $r ) && 'phone' === $r->get_error_data()['field'], 'phone when required' );
$r = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'phone' => '+359 888 123 456' ) ) );
t_ok( is_wp_error( $r ) && 'subject' === $r->get_error_data()['field'], 'subject when required' );
delete_option( Flexo_Booking_Forms::OPTION );
rel_clear_limits();

$before = count( Flexo_Booking_Log::recent( 'enquiry', '', 500 ) );
$sent   = null;
$mails  = rel_mails(
	static function () use ( $base, &$sent ) {
		$sent = Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'name' => "Evil\r\nBcc: spy@example.com, <x@y.z>", 'subject' => "Dogs\r\nBcc: spy@example.com" ) ) );
	}
);
t_ok( is_array( $sent ) && ! empty( $sent['sent'] ) && '' !== $sent['message'], 'message sent, with the hotel\'s thank-you text' );
t_eq( 1, count( $mails ), 'one email (to the hotel), no answer to the guest by default' );
$mail = $mails ? $mails[0] : array( 'headers' => array(), 'subject' => '', 'to' => array() );
$reply = implode( "\n", array_filter( (array) $mail['headers'], static function ( $h ) { return 0 === stripos( $h, 'Reply-To:' ); } ) );
t_ok( 1 === substr_count( $reply, '@' ) && false !== strpos( $reply, '<maria@example.com>' ) && false === strpos( $reply, "\n" ) && false === strpos( $reply, ',' ), 'Reply-To: only the guest\'s address, nothing added through the name' );
t_ok( false === strpos( $mail['subject'], "\n" ) && false !== strpos( $mail['subject'], 'Message from' ), 'subject on one line' );
$log = Flexo_Booking_Log::recent( 'enquiry', '', 500 );
t_eq( $before + 1, count( $log ), 'kept with the enquiries' );
$entry = $log[0];
t_eq( 'contact', $entry['details']['kind'], 'marked as a contact message' );
t_eq( 'open', $entry['details']['status'], 'open until answered' );
t_eq( 0, (int) $entry['booking_id'], 'not a booking' );

// Today: "Message from …" with "Reply by email".
$items = Flexo_Booking_Today_Admin::attention();
$found = null;
foreach ( $items as $item ) {
	if ( 'contact' === $item['type'] && false !== strpos( $item['title'], 'Evil' ) ) {
		$found = $item;
	}
}
t_ok( null !== $found, 'listed on Today' );
$reply_url = $found ? $found['actions'][0]['url'] : '';
t_ok( 0 === strpos( $reply_url, 'mailto:maria@example.com?subject=' ) && false !== strpos( $reply_url, rawurlencode( 'Re: Dogs' ) ), '"Reply by email" with "Re: subject"' );

// The answer to the guest, when switched on.
rel_clear_limits();
Flexo_Booking_Forms::update( array( 'contact_autoreply' => 1, 'contact_subject' => 'optional' ) );
$mails = rel_mails(
	static function () use ( $base ) {
		Flexo_Booking_Guest::contact_message( array_merge( $base, array( 'subject' => 'Parking' ) ) );
	}
);
t_eq( 2, count( $mails ), 'hotel and guest emailed' );
t_ok( isset( $mails[1] ) && in_array( 'maria@example.com', (array) $mails[1]['to'], true ) && false !== strpos( $mails[1]['message'], 'Can we bring a dog?' ), 'the guest gets a copy of the message' );
delete_option( Flexo_Booking_Forms::OPTION );

// Limit per visitor.
rel_clear_limits();
$codes = array();
for ( $i = 0; $i < 6; $i++ ) {
	$r       = Flexo_Booking_Guest::contact_message( $base );
	$codes[] = is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}
t_eq( 'flexo_rate_limited', end( $codes ), 'the sixth message within an hour is refused' );
t_ok( ! is_wp_error( Flexo_Booking_Guest::enquiry( array_merge( $base, array( 'check_in' => t_day( 10 ), 'check_out' => t_day( 12 ) ) ) ) ), 'availability enquiries have their own limit' );
rel_clear_limits();

// Privacy: export and erase with the guest's other data.
$export = Flexo_Booking_Privacy::export( 'maria@example.com' );
$rows   = array();
foreach ( $export['data'] as $item ) {
	if ( 'flexo-booking-enquiries' === $item['group_id'] ) {
		foreach ( $item['data'] as $field ) {
			$rows[] = $field['name'] . '=' . $field['value'];
		}
	}
}
t_ok( in_array( 'Sent from=Contact form', $rows, true ) && in_array( 'Subject=Parking', $rows, true ), 'exported (sent from the contact form, with its subject)' );
Flexo_Booking_Privacy::erase( 'maria@example.com' );
t_eq( 0, count( Flexo_Booking_Log::enquiries_by_email( 'maria@example.com' ) ), 'erased' );

/* --------------------------------------------------------------------- */
t_section( 'Booking & Contact tabs' );
$html = do_shortcode( '[flexo_forms_tabs booking_label="Booking Form" contact_label="Contact Form" default="contact" contact_title="Ask us"]' );
t_ok( 1 === substr_count( $html, 'role="tablist"' ) && 2 === substr_count( $html, 'role="tab"' ) && 2 === substr_count( $html, 'role="tabpanel"' ), 'tab list, two tabs, two panels' );
t_ok( false !== strpos( $html, '>Booking Form</button>' ) && false !== strpos( $html, '>Contact Form</button>' ), 'own tab texts' );
t_ok( 1 === preg_match( '#data-key="contact"[^>]*>|aria-selected="true"[^>]*data-key="contact"#', $html ) && false !== strpos( $html, 'aria-selected="true" data-key="contact"' ), 'contact shown first' );
t_ok( 1 === preg_match( '#<div class="fb-tabpanel"[^>]*id="[^"]*-booking"[^>]*hidden>#', $html ), 'booking panel hidden until chosen' );
t_ok( false !== strpos( $html, 'data-flexo-booking' ) && false !== strpos( $html, 'data-flexo-contact' ) && false !== strpos( $html, '>Ask us<' ), 'both forms, with their options' );

/* --------------------------------------------------------------------- */
t_section( 'Emails: readable text on the hotel colour' );
rel_settings( array( 'appearance_mode' => 'match', 'email_color' => '#1f6f5c' ) );
$h = Flexo_Booking_Emails::html( 'Test', "Hello\n\nView your booking: https://example.com/b" );
t_ok( false !== strpos( $h, 'font-weight:bold;color:#ffffff' ) && false !== strpos( $h, 'color:#ffffff;text-decoration:none' ), 'dark colour: white text, as before' );
rel_settings( array( 'email_color' => '#e8d9b0' ) );
$h = Flexo_Booking_Emails::html( 'Test', "Hello\n\nView your booking: https://example.com/b" );
t_ok( false !== strpos( $h, 'font-weight:bold;color:#1f2933' ) && false !== strpos( $h, 'color:#1f2933;text-decoration:none' ), 'light colour: dark text on the header and button' );
t_ok( 1 === preg_match( '#<a href="[^"]+" style="color:\#1f2933;">#', $h ), 'light colour: the footer link stays readable on white' );

t_done();
