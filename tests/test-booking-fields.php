<?php
/**
 * 1.9.0 booking form fields: defaults unchanged, name and email always
 * required, phone required / optional / not asked, special requests,
 * labels, placeholders, help texts and order – with bookings, inventory
 * and prices unchanged.
 *
 *   wp eval-file tests/test-booking-fields.php
 *
 * Brief items 17–26 (27, booking_complete once, is checked in the browser:
 * tests/e2e/day8.js, and by test-confirmation.php for the Thank You page).
 */
require __DIR__ . '/lib.php';

$bf_saved = array(
	'forms'    => get_option( Flexo_Booking_Forms::OPTION ),
	'settings' => get_option( Flexo_Booking_Settings::OPTION ),
);
register_shutdown_function(
	static function () use ( $bf_saved ) {
		false === $bf_saved['forms'] ? delete_option( Flexo_Booking_Forms::OPTION ) : update_option( Flexo_Booking_Forms::OPTION, $bf_saved['forms'] );
		update_option( Flexo_Booking_Settings::OPTION, $bf_saved['settings'] );
		t_reset_inventory();
	}
);
add_filter( 'flexo_booking_rate_limit', '__return_zero' );
function bf_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function bf_form() {
	return Flexo_Booking_Frontend::render( array() );
}
function bf_book( array $data ) {
	$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( array( 'room' => 'bf-room', 'check_in' => t_day( 10 ), 'check_out' => t_day( 13 ), 'adults' => 2, 'privacy_consent' => true, 'terms' => true ), $data ) ) );
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}
t_reset_inventory();
delete_option( Flexo_Booking_Forms::OPTION );
t_room( 'bf-room', 'BF Room', array( 'price' => 90, 'weekend_price' => 0, 'capacity' => 2, 'units' => 2, 'min_nights' => 0 ) );
bf_settings( array( 'field_phone' => 'required', 'field_notes' => 'optional', 'terms_url' => '', 'min_nights' => 1 ) );

/* ------------------------------------------------------------------------- */
t_section( 'Defaults unchanged (17)' );
$html = bf_form();
preg_match_all( '/name="(guest_name|guest_email|guest_phone|notes)"/', $html, $m );
t_eq( array( 'guest_name', 'guest_email', 'guest_phone', 'notes' ), $m[1], '17. name, email, phone, special requests – in that order' );
t_ok( 1 === preg_match( '/id="(fb-[^"]+)-name" type="text" name="guest_name" required autocomplete="name"/', $html, $uid ), '17. same field ids, types and autocomplete as before' );
$uid = $uid[1];
t_ok( false !== strpos( $html, 'id="' . $uid . '-email" type="email" name="guest_email" required' ) && false !== strpos( $html, 'inputmode="email"' ), '17. email field (keyboard for email)' );
t_ok( false !== strpos( $html, 'id="' . $uid . '-email-hint">' . esc_html__( 'We send your confirmation here.', 'flexo-booking' ) ), '17. email help text' );
t_ok( false !== strpos( $html, 'type="tel" name="guest_phone" required' ) && false !== strpos( $html, 'name="phone_country"' ), '17. phone required with country code' );
t_ok( false !== strpos( $html, 'placeholder="' . esc_attr__( 'e.g. late arrival, baby cot, quiet room', 'flexo-booking' ) . '"' ), '17. special requests example text' );
t_ok( 2 === substr_count( $html, '<span class="fb-req" aria-hidden="true">*</span></label><input' ) && 1 === substr_count( $html, '<span class="fb-req" aria-hidden="true">*</span></label><div class="fb-phone">' ), '17. required fields marked with *' );
t_ok( false !== strpos( $html, 'fb-optional' ), '17. optional field says "(optional)"' );

/* ------------------------------------------------------------------------- */
t_section( 'Name and email always asked and required (18, 19)' );
Flexo_Booking_Forms::update( array( 'booking_order' => array( 'notes', 'phone' ), 'booking_fields' => array( 'name' => array( 'mode' => 'hidden' ) ) ) );
t_eq( 'required', Flexo_Booking_Forms::booking_field_mode( 'name' ), '18. name cannot be switched off' );
t_eq( 'required', Flexo_Booking_Forms::booking_field_mode( 'email' ), '19. email cannot be switched off' );
t_eq( array( 'notes', 'phone', 'name', 'email' ), Flexo_Booking_Forms::all()['booking_order'], 'a partial order keeps every field' );
$html = bf_form();
t_ok( false !== strpos( $html, 'name="guest_name" required' ) && false !== strpos( $html, 'name="guest_email" required' ), '18/19. both still in the form, required' );
$r = bf_book( t_guest( array( 'guest_name' => '' ) ) );
t_ok( 400 === $r['status'], '18. a booking without a name is refused by the server' );
$r = bf_book( t_guest( array( 'guest_email' => 'not-an-email' ) ) );
t_ok( 400 === $r['status'], '19. a booking without a valid email is refused' );
delete_option( Flexo_Booking_Forms::OPTION );

/* ------------------------------------------------------------------------- */
t_section( 'Phone and special requests (20–23)' );
bf_settings( array( 'field_phone' => 'hidden' ) );
$html = bf_form();
t_ok( false === strpos( $html, 'name="guest_phone"' ), '20. phone not asked: no field' );
$r = bf_book( t_guest( array( 'guest_phone' => '' ) ) );
t_eq( 201, $r['status'], '20. booking without a phone' );
t_reset_inventory();
bf_settings( array( 'field_phone' => 'optional' ) );
$html = bf_form();
t_ok( false !== strpos( $html, 'type="tel" name="guest_phone" autocomplete' ), '21. phone optional: field without "required"' );
$r = bf_book( t_guest( array( 'guest_phone' => '' ) ) );
t_eq( 201, $r['status'], '21. booking without a phone' );
t_reset_inventory();
bf_settings( array( 'field_phone' => 'required' ) );
$r = bf_book( t_guest( array( 'guest_phone' => '' ) ) );
t_eq( 400, $r['status'], '22. phone required: refused without one' );
$r = bf_book( t_guest() );
t_eq( 201, $r['status'], '22. accepted with one' );
t_reset_inventory();
bf_settings( array( 'field_notes' => 'hidden' ) );
t_ok( false === strpos( bf_form(), 'name="notes"' ), '23. special requests hidden' );
bf_settings( array( 'field_notes' => 'optional' ) );
t_ok( false !== strpos( bf_form(), 'name="notes"' ), '23. special requests shown' );

/* ------------------------------------------------------------------------- */
t_section( 'Labels, example texts, help and order (D)' );
Flexo_Booking_Forms::update(
	array(
		'booking_order'  => array( 'email', 'name', 'notes', 'phone' ),
		'booking_fields' => array(
			'name'  => array( 'label' => 'Your name<b>', 'placeholder' => 'Jane Smith', 'help' => 'As on your ID' ),
			'phone' => array( 'label' => 'Mobile', 'help' => 'For arrival questions only' ),
			'notes' => array( 'placeholder' => __( 'e.g. late arrival, baby cot, quiet room', 'flexo-booking' ) ),
		),
	)
);
$all = Flexo_Booking_Forms::all();
t_eq( 'Your name', $all['booking_fields']['name']['label'], 'labels are plain text' );
t_eq( '', $all['booking_fields']['notes']['placeholder'], 'the default text is not stored (stays translated)' );
$html = bf_form();
preg_match_all( '/name="(guest_name|guest_email|guest_phone|notes)"/', $html, $m );
t_eq( array( 'guest_email', 'guest_name', 'notes', 'guest_phone' ), $m[1], 'the hotel\'s order' );
t_ok( false !== strpos( $html, '>Your name <span class="fb-req"' ) && false !== strpos( $html, 'placeholder="Jane Smith"' ), 'own label and example text' );
t_ok( 1 === preg_match( '/name="guest_name"[^>]*aria-describedby="(fb-[^"]+-name-hint)"/', $html, $hint ) && false !== strpos( $html, 'id="' . $hint[1] . '">As on your ID</small>' ), 'help text linked to the field (aria-describedby)' );
t_ok( false !== strpos( $html, '>Mobile <span class="fb-req"' ), 'phone label' );
$strings = Flexo_Booking_I18n::translatable_strings();
t_eq( 'Mobile', isset( $strings['field_booking_phone_label'] ) ? $strings['field_booking_phone_label'] : '', 'changed texts go to Polylang / WPML' );
add_filter(
	'flexo_booking_guest_fields',
	static function ( $fields ) {
		$fields['email']['help'] = 'Filtered help';
		return $fields;
	}
);
t_ok( false !== strpos( bf_form(), 'Filtered help' ), 'extension point: flexo_booking_guest_fields' );
remove_all_filters( 'flexo_booking_guest_fields' );
$theme_override = Flexo_Booking_Frontend::render( array() );
t_ok( false !== strpos( $theme_override, 'data-fb-extra' ) || false === strpos( $theme_override, 'fb-consent' ), 'privacy consent / invoice fields keep their own place and settings' );

/* ------------------------------------------------------------------------- */
t_section( 'Bookings, inventory and prices unchanged (24–26)' );
delete_option( Flexo_Booking_Forms::OPTION );
t_reset_inventory();
$quote = Flexo_Booking_Pricing::quote( array( 'room' => Flexo_Booking_Rooms::to_array( Flexo_Booking_Rooms::find( 'bf-room' ) ), 'check_in' => t_day( 10 ), 'check_out' => t_day( 13 ), 'adults' => 2, 'context' => 'booking' ) );
$free  = Flexo_Booking_Inventory::units_available( Flexo_Booking_Rooms::to_array( Flexo_Booking_Rooms::find( 'bf-room' ) ), t_day( 10 ), t_day( 13 ) );
$r     = bf_book( t_guest( array( 'notes' => 'Quiet room please' ) ) );
t_eq( 201, $r['status'], '24. booking created' );
$b = Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] );
t_eq( 'Quiet room please', $b['notes'], '24. special requests stored' );
t_eq( (float) $quote['total'], (float) $b['total'], '26. price as the pricing engine says' );
t_eq( 270.0, (float) $b['total'], '26. 3 nights × 90' );
t_eq( $free - 1, Flexo_Booking_Inventory::units_available( Flexo_Booking_Rooms::to_array( Flexo_Booking_Rooms::find( 'bf-room' ) ), t_day( 10 ), t_day( 13 ) ), '25. exactly one room taken' );

t_done();
