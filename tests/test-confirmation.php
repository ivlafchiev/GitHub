<?php
/**
 * 1.9.0 Thank You page: hand-off token, secure cookie, what it shows for
 * each booking and payment state, sections, order, layouts, inline mode,
 * no private data in addresses, and two guests behind a page cache.
 * Needs the site served over HTTP (BASE, else the site address).
 *
 *   BASE=http://localhost:8092 wp eval-file tests/test-confirmation.php
 *
 * Brief items 28–44 and 46. Card payments use a faked Stripe API (the real
 * one can't be reached from tests) and signed webhooks, as in test-day5.
 */
require __DIR__ . '/lib.php';

global $wpdb;
$base     = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();
$tc_saved = array(
	'pages'    => get_option( Flexo_Booking_System_Pages::OPTION ),
	'settings' => get_option( Flexo_Booking_Settings::OPTION ),
	'features' => Flexo_Booking_Features::stored_enabled(),
	'secrets'  => get_option( Flexo_Booking_Payments::SECRETS_OPTION ),
);
$drafted  = array();
$mu       = WP_CONTENT_DIR . '/mu-plugins/flexo-mini-page-cache.php';
register_shutdown_function(
	static function () use ( $tc_saved, &$drafted, $mu ) {
		false === $tc_saved['pages'] ? delete_option( Flexo_Booking_System_Pages::OPTION ) : update_option( Flexo_Booking_System_Pages::OPTION, $tc_saved['pages'] );
		update_option( Flexo_Booking_Settings::OPTION, $tc_saved['settings'] );
		update_option( Flexo_Booking_Payments::SECRETS_OPTION, $tc_saved['secrets'] );
		Flexo_Booking_Features::set_enabled( $tc_saved['features'] );
		foreach ( $drafted as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		}
		if ( is_file( $mu ) ) {
			unlink( $mu );
		}
		array_map( 'unlink', glob( WP_CONTENT_DIR . '/flexo-mini-cache/*.html' ) ?: array() );
		t_reset_inventory();
	}
);
foreach ( array( 'thank-you', 'booking' ) as $path ) {
	$page = get_page_by_path( $path );
	if ( $page && 'publish' === $page->post_status ) {
		wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ) );
		$drafted[] = $page->ID;
	}
}
add_filter( 'flexo_booking_rate_limit', '__return_zero' );
t_reset_inventory();

const TC_SECRET = 'whsec_tc_secret_123';
function tc_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function tc_features( array $extra ) {
	Flexo_Booking_Features::set_enabled( array_merge( array( 'booking_request', 'instant_booking', 'guest_emails', 'guest_booking_page' ), $extra ) );
}
// Faked Stripe API.
$GLOBALS['tc_stripe'] = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.stripe.com/v1' ) ) {
			return $pre;
		}
		$GLOBALS['tc_stripe'][] = array( 'url' => $url, 'body' => $args['body'] );
		$id = 'cs_tc_' . count( $GLOBALS['tc_stripe'] );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
function tc_webhook_paid( array $booking, $cents ) {
	$payload = wp_json_encode(
		array(
			'id'       => 'evt_' . wp_generate_password( 14, false, false ),
			'object'   => 'event',
			'type'     => 'checkout.session.completed',
			'livemode' => false,
			'data'     => array(
				'object' => array(
					'id'                  => $booking['payment_session'],
					'object'              => 'checkout.session',
					'amount_total'        => $cents,
					'currency'            => 'eur',
					'payment_status'      => 'paid',
					'payment_intent'      => 'pi_' . strtolower( $booking['reference'] ),
					'client_reference_id' => $booking['reference'],
					'metadata'            => array( 'booking_id' => (string) $booking['id'], 'reference' => $booking['reference'] ),
				),
			),
		)
	);
	$time    = time();
	$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/stripe-webhook' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'stripe-signature', 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $payload, TC_SECRET ) );
	$request->set_body( $payload );
	return rest_do_request( $request )->get_status();
}
function tc_book( array $data = array() ) {
	// Each booking on its own dates, so the room never runs out.
	static $n = 0;
	$start   = 7 + 3 * ( $n++ );
	$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( t_guest( array( 'room' => 'tc-room', 'check_in' => t_day( $start ), 'check_out' => t_day( $start + 2 ), 'privacy_consent' => true, 'return_url' => home_url( '/booking/' ) ) ), $data ) ) );
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}
function tc_payment_view( $reference, $key ) {
	$request = new WP_REST_Request( 'GET', '/flexo-booking/v1/payment' );
	$request->set_query_params( array( 'reference' => $reference, 'key' => $key ) );
	return rest_do_request( $request )->get_data();
}
/** The Thank You content for a booking, as the guest with the cookie sees it. */
function tc_render_for( $booking_id, array $args = array() ) {
	$_COOKIE[ Flexo_Booking_Confirmation::COOKIE ] = Flexo_Booking_Confirmation::cookie_value( $booking_id, time() + 600 );
	Flexo_Booking_Confirmation::reset();
	$html = Flexo_Booking_Confirmation::render( $args );
	unset( $_COOKIE[ Flexo_Booking_Confirmation::COOKIE ] );
	Flexo_Booking_Confirmation::reset();
	return $html;
}
$get = static function ( $url, array $cookies = array(), array $headers = array() ) use ( $base ) {
	$url = 0 === strpos( $url, 'http' ) ? $url : $base . $url;
	$r   = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'cookies' => $cookies, 'headers' => $headers ) );
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $r ),
		'body'     => (string) wp_remote_retrieve_body( $r ),
		'location' => (string) wp_remote_retrieve_header( $r, 'location' ),
		'headers'  => wp_remote_retrieve_headers( $r ),
		'cookies'  => wp_remote_retrieve_cookies( $r ),
		'raw'      => $r,
	);
};
$set_cookie = static function ( array $r ) {
	$raw = wp_remote_retrieve_header( $r['raw'], 'set-cookie' );
	return is_array( $raw ) ? implode( "\n", $raw ) : (string) $raw;
};

// ---- Setup -------------------------------------------------------------------
t_room( 'tc-room', 'TC Garden Room', array( 'price' => 120, 'weekend_price' => 0, 'capacity' => 2, 'units' => 3, 'min_nights' => 0 ) );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_tc', 'stripe_test_webhook_secret' => TC_SECRET ) );
tc_settings(
	array(
		'booking_mode'      => 'instant',
		'currency'          => 'EUR',
		'min_nights'        => 1,
		'payment_mode'      => 'full',
		'stripe_mode'       => 'test',
		'hold_minutes'      => 30,
		'bank_beneficiary'  => 'TC Hotel Ltd.',
		'bank_iban'         => 'BG80 BNBG 9661 1020 3456 78',
		'bank_reference'    => '{booking_ref}',
		'bank_transfer_days' => 3,
		'hotel_phone'       => '+359 2 123 4567',
		'hotel_address'     => 'Seaside 1, Varna',
		'field_phone'       => 'optional',
		'terms_url'         => '',
	)
);
tc_features( array() );
delete_option( Flexo_Booking_System_Pages::OPTION ); // Fresh install defaults: separate built-in Thank You page.

/* ------------------------------------------------------------------------- */
t_section( 'Secure hand-off (28, 31, 33)' );
$r = tc_book();
t_eq( 201, $r['status'], 'instant booking created' );
$link = $r['data']['redirect'];
t_ok( 1 === preg_match( '#^' . preg_quote( home_url( '/thank-you/' ), '#' ) . '\?fb_t=[a-f0-9]{64}$#', $link ), 'the form is sent to /thank-you/?fb_t=… (one-time token only)' );
t_ok( false === strpos( $link, $r['data']['reference'] ) && false === strpos( $link, 'fb_key' ), 'no reference and no guest key in the address' );
$a = $get( $link );
t_eq( 303, $a['code'], '31. token checked, then a redirect (303)' );
t_eq( home_url( '/thank-you/' ), $a['location'], '31. to the clean address without the token' );
$cookie_header = $set_cookie( $a );
t_ok( false !== stripos( $cookie_header, Flexo_Booking_Confirmation::COOKIE . '=' ) && false !== stripos( $cookie_header, 'HttpOnly' ) && false !== stripos( $cookie_header, 'SameSite=Lax' ) && false !== stripos( $cookie_header, 'path=/thank-you/' ), 'cookie: HttpOnly, SameSite=Lax, only for /thank-you/' );
t_ok( 1 === preg_match( '/Max-Age=(\d+)/i', $cookie_header, $m ) ? abs( (int) $m[1] - 3600 ) < 30 : ( false !== stripos( $cookie_header, 'expires=' ) ), 'cookie lasts 60 minutes' );
$jar = $a['cookies'];
$t   = $get( '/thank-you/', $jar );
$ref = $r['data']['reference'];
t_ok( 200 === $t['code'] && false !== strpos( $t['body'], $ref ) && false !== strpos( $t['body'], 'TC Garden Room' ), '28. the booking is shown with the cookie' );
t_ok( false !== strpos( $t['body'], esc_html__( 'Your booking is confirmed', 'flexo-booking' ) ), '35. instant booking: "Your booking is confirmed"' );
t_eq( 'no-referrer', (string) wp_remote_retrieve_header( $t['raw'], 'referrer-policy' ), '33. Referrer-Policy: no-referrer' );
t_ok( false !== strpos( (string) wp_remote_retrieve_header( $t['raw'], 'x-robots-tag' ), 'noindex' ), '33. noindex, nofollow' );
t_ok( false === strpos( $t['body'], 'booking_complete' ), '27. the Thank You page never sends booking_complete (sent once by the form)' );
$again = $get( $link );
t_ok( 303 === $again['code'] && false === stripos( $set_cookie( $again ), Flexo_Booking_Confirmation::COOKIE . '=' ), '31. the token works once' );
$t2 = $get( '/thank-you/' );
t_ok( false === strpos( $t2['body'], $ref ) && false === strpos( $t2['body'], 'TC Garden Room' ), 'without the cookie: no booking data' );

/* ------------------------------------------------------------------------- */
t_section( 'No access without the secure context (29, 30, 32)' );
$booking = Flexo_Booking_Bookings::get_by_reference( $ref );
$forged  = (string) $booking['id'] . '.' . ( time() + 600 ) . '.' . str_repeat( 'a', 40 );
$t       = $get( '/thank-you/', array( new WP_Http_Cookie( array( 'name' => Flexo_Booking_Confirmation::COOKIE, 'value' => $forged ) ) ) );
t_ok( false === strpos( $t['body'], $ref ), '29. forged cookie: nothing shown' );
$t = $get( '/thank-you/?fb_t=' . str_repeat( 'b', 64 ) );
t_ok( 303 === $t['code'] && false === stripos( $set_cookie( $t ), Flexo_Booking_Confirmation::COOKIE . '=' ), '29. unknown token: no cookie' );
foreach ( array( '?booking=' . $ref, '?reference=' . $ref, '?fb_done=' . $ref, '?fb_key=' . Flexo_Booking_Guest::key( $booking ), '?id=' . $booking['id'] ) as $query ) {
	$t = $get( '/thank-you/' . $query );
	t_ok( false === strpos( $t['body'], 'TC Garden Room' ) && false === strpos( $t['body'], 'guest@example.com' ), '30. ' . $query . ' shows nothing private' );
}
$expired = Flexo_Booking_Confirmation::cookie_value( $booking['id'], time() - 5 );
$t       = $get( '/thank-you/', array( new WP_Http_Cookie( array( 'name' => Flexo_Booking_Confirmation::COOKIE, 'value' => $expired ) ) ) );
t_ok( false === strpos( $t['body'], $ref ) && false !== strpos( $t['body'], esc_html__( 'Thank you', 'flexo-booking' ) ), '32. expired context: general thank-you text' );
t_eq( 0, Flexo_Booking_Confirmation::parse_cookie( $expired ), '32. expired cookie refused' );
t_eq( (int) $booking['id'], Flexo_Booking_Confirmation::parse_cookie( Flexo_Booking_Confirmation::cookie_value( $booking['id'], time() + 60 ) ), 'a valid cookie is accepted' );
$wpdb->update( Flexo_Booking_Install::table(), array( 'anonymized_at' => current_time( 'mysql' ) ), array( 'id' => $booking['id'] ) );
t_ok( false === strpos( tc_render_for( $booking['id'] ), $ref ), 'a booking whose personal data was removed is never shown' );
$wpdb->update( Flexo_Booking_Install::table(), array( 'anonymized_at' => null ), array( 'id' => $booking['id'] ) );

/* ------------------------------------------------------------------------- */
t_section( 'Two guests behind a page cache (46)' );
if ( ! is_dir( dirname( $mu ) ) ) {
	mkdir( dirname( $mu ) );
}
copy( __DIR__ . '/fixtures/mini-page-cache.php', $mu );
array_map( 'unlink', glob( WP_CONTENT_DIR . '/flexo-mini-cache/*.html' ) ?: array() );
$cache = array( 'X-Flexo-Mini-Cache' => '1' );
$get( '/?tc_probe=1', array(), $cache );
$probe = $get( '/?tc_probe=1', array(), $cache );
t_eq( 'hit', (string) wp_remote_retrieve_header( $probe['raw'], 'x-flexo-mini-cache' ), 'the stand-in cache stores normal pages' );
$guest_a = tc_book( array( 'guest_name' => 'Anna Cached', 'guest_email' => 'anna@example.com' ) );
$a       = $get( $guest_a['data']['redirect'], array(), $cache );
$a_page  = $get( '/thank-you/', $a['cookies'], $cache );
t_ok( false !== strpos( $a_page['body'], $guest_a['data']['reference'] ), '46. guest A sees her booking' );
$b_page = $get( '/thank-you/', array(), $cache );
t_ok( '' === (string) wp_remote_retrieve_header( $b_page['raw'], 'x-flexo-mini-cache' ) && false === strpos( $b_page['body'], $guest_a['data']['reference'] ), '46. guest B gets no cached copy of A\'s page' );
$guest_b = tc_book( array( 'guest_name' => 'Boris Cached', 'guest_email' => 'boris@example.com' ) );
$b       = $get( $guest_b['data']['redirect'], array(), $cache );
$b_page  = $get( '/thank-you/', $b['cookies'], $cache );
t_ok( false !== strpos( $b_page['body'], $guest_b['data']['reference'] ) && false === strpos( $b_page['body'], $guest_a['data']['reference'] ), '46. guest B sees only his booking' );
$get( '/booking/', array(), $cache );
$cached_booking = $get( '/booking/', array(), $cache );
t_eq( 'hit', (string) wp_remote_retrieve_header( $cached_booking['raw'], 'x-flexo-mini-cache' ), 'decision 1: the Booking page may be cached' );
unlink( $mu );
t_reset_inventory();

/* ------------------------------------------------------------------------- */
t_section( 'State-aware content (34–39)' );
tc_settings( array( 'booking_mode' => 'request' ) );
$r    = tc_book();
$html = tc_render_for( Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] )['id'] );
t_ok( '' !== $r['data']['redirect'], 'request: sent to the Thank You page too' );
t_ok( false !== strpos( $html, esc_html__( 'Your booking request has been received', 'flexo-booking' ) ) && false !== strpos( $html, esc_html__( 'The hotel will review your request and contact you shortly.', 'flexo-booking' ) ), '34. request: heading and message' );
t_ok( false === strpos( $html, esc_html__( 'Bank transfer details', 'flexo-booking' ) ) && false === strpos( $html, 'fb-ty-row--paid' ), '39. no payment details for a request without payment' );
tc_settings( array( 'booking_mode' => 'instant' ) );

tc_features( array( 'online_payment', 'bank_transfer' ) );
$r = tc_book( array( 'payment_method' => 'bank_transfer' ) );
t_ok( 201 === $r['status'] && ! empty( $r['data']['payment'] ) && '' !== $r['data']['redirect'], 'bank transfer: details given, Thank You page next' );
$html = tc_render_for( Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] )['id'] );
t_ok( false !== strpos( $html, esc_html__( 'Your reservation is awaiting payment', 'flexo-booking' ) ), '38. bank transfer heading' );
t_ok( false !== strpos( $html, 'BG80 BNBG 9661 1020 3456 78' ) && false !== strpos( $html, 'TC Hotel Ltd.' ) && false !== strpos( $html, 'fb-bank__row--reference' ) && false !== strpos( $html, 'fb-bank__row--deadline' ) && false !== strpos( $html, 'fb-bank__row--amount' ), '38. amount, bank details, payment reference and deadline' );
t_ok( false !== strpos( $html, esc_html__( 'Waiting for your bank transfer', 'flexo-booking' ) ), '38. payment status' );

$r       = tc_book( array( 'payment_method' => 'stripe' ) );
$card    = Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] );
t_ok( ! empty( $r['data']['payment']['redirect'] ) && '' === $r['data']['redirect'], 'card: Stripe first (no Thank You link yet)' );
$success = '';
foreach ( $GLOBALS['tc_stripe'] as $call ) {
	parse_str( (string) $call['body'], $fields );
	if ( isset( $fields['success_url'] ) ) {
		$success = $fields['success_url'];
	}
}
t_ok( 0 === strpos( $success, home_url( '/booking/' ) ), '(decision 4) Stripe returns to the booking page' );
$view = tc_payment_view( $card['reference'], $r['data']['payment']['key'] );
t_ok( 'held' === $view['state'] && empty( $view['redirect'] ), '37. before the webhook: no Thank You link (nothing confirmed by a browser)' );
$html = tc_render_for( $card['id'] );
t_ok( false !== strpos( $html, esc_html__( 'Your payment is being confirmed', 'flexo-booking' ) ) && false !== strpos( $html, 'data-fb-ty-refresh="5"' ) && false !== strpos( $html, 'data-fb-ty-max="24"' ), '37. "being confirmed", re-checked every 5 s for 2 minutes' );
t_ok( false === strpos( $html, esc_html__( 'Payment received', 'flexo-booking' ) ), '37. not shown as paid' );
t_eq( 200, tc_webhook_paid( $card, (int) round( $card['amount_due'] * 100 ) ), 'Stripe webhook: paid' );
$view = tc_payment_view( $card['reference'], $r['data']['payment']['key'] );
t_ok( 'confirmed' === $view['state'] && 1 === preg_match( '#/thank-you/\?fb_t=[a-f0-9]{64}$#', (string) $view['redirect'] ), 'confirmed by the server: Thank You link' );
$html = tc_render_for( $card['id'] );
t_ok( false !== strpos( $html, esc_html__( 'Your booking is confirmed', 'flexo-booking' ) ) && false !== strpos( $html, esc_html__( 'Payment received', 'flexo-booking' ) ), '36. paid by card: confirmed, "Payment received"' );
t_ok( false !== strpos( $html, 'fb-ty-row--paid' ) && false === strpos( $html, esc_html__( 'Bank transfer details', 'flexo-booking' ) ), '39. amount paid shown, no bank details' );

tc_features( array() );
$r    = tc_book();
$plain = Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] );
$html = tc_render_for( $plain['id'] );
t_ok( false === strpos( $html, esc_html__( 'Bank transfer details', 'flexo-booking' ) ) && false === strpos( $html, 'fb-ty-row--paid' ) && false === strpos( $html, 'fb-ty-row--payment_status' ), '39. without payments: no payment sections' );
t_ok( false !== strpos( $html, 'Seaside 1, Varna' ) && false !== strpos( $html, 'tel:+35921234567' ) && false !== strpos( $html, 'google.com/maps' ), 'hotel contact, address and directions' );
t_ok( false !== strpos( $html, 'booking.ics' ) && false !== strpos( $html, 'fb_manage=' ), 'add to calendar and manage booking (feature on)' );
tc_features( array() );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'instant_booking', 'guest_emails' ) );
t_ok( false === strpos( tc_render_for( $plain['id'] ), 'fb_manage=' ), 'manage booking only with the guest booking page feature' );
tc_features( array() );

/* ------------------------------------------------------------------------- */
t_section( 'Sections, order, texts and layouts (40, 41, F, editable texts)' );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'hidden' => array( 'reference', 'address' ) ) ) );
$html = tc_render_for( $plain['id'] );
t_ok( false === strpos( $html, 'fb-ty-row--reference' ) && false === strpos( $html, 'fb-ty-address' ), '40. hidden sections are not shown' );
t_ok( false !== strpos( $html, 'fb-ty-row--room' ), '40. the others are' );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'hidden' => array(), 'order' => array( 'next', 'contact', 'status', 'reference' ) ) ) );
$html = tc_render_for( $plain['id'] );
t_ok( strpos( $html, 'fb-ty-next' ) < strpos( $html, 'fb-ty-contact' ) && strpos( $html, 'fb-ty-contact' ) < strpos( $html, 'fb-ty-row--status' ), '41. the hotel\'s order' );
t_eq( count( Flexo_Booking_System_Pages::SECTIONS ), count( Flexo_Booking_System_Pages::get( 'thank_you' )['order'] ), 'sections missing from a saved order are added at the end' );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'order' => Flexo_Booking_System_Pages::SECTIONS, 'heading_instant' => 'Welcome, {reference}!', 'intro' => 'See you at <strong>{hotel_name}</strong><script>x()</script>', 'back_label' => 'Home' ) ) );
$html = tc_render_for( $plain['id'] );
t_ok( false !== strpos( $html, 'Welcome, ' . $plain['reference'] . '!' ), 'own heading with {reference}' );
t_ok( false !== strpos( $html, 'See you at <strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>' ) && false === strpos( $html, '<script>x()' ), 'own introduction: {hotel_name}, formatting kept, no scripts' );
t_ok( false !== strpos( $html, '>Home</a>' ), 'own button label' );
foreach ( array( 'card', 'summary', 'split' ) as $layout ) {
	$html = tc_render_for( $plain['id'], array( 'layout' => $layout ) );
	t_ok( false !== strpos( $html, 'flexo-confirmation--' . $layout ), 'layout ' . $layout );
}
t_ok( false !== strpos( tc_render_for( $plain['id'], array( 'layout' => 'split' ) ), 'flexo-confirmation__side' ), 'Split: the stay in its own column' );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'heading_instant' => '', 'intro' => '', 'back_label' => '' ) ) );

/* ------------------------------------------------------------------------- */
t_section( 'Own page and inline mode (42, 43)' );
Flexo_Booking_System_Pages::update( array( 'after_booking' => 'inline' ) );
$r = tc_book();
t_ok( 201 === $r['status'] && '' === $r['data']['redirect'] && ! empty( $r['data']['view'] ), '43. inline: no redirect, the confirmation is in the response' );
t_ok( ! empty( $r['data']['key'] ), '43. with the key the form keeps in the tab' );
Flexo_Booking_System_Pages::update( array( 'after_booking' => 'separate' ) );
tc_settings( array( 'thank_you_url' => '/tc-old-thanks/' ) );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'source' => 'page', 'page_id' => 0 ) ) );
$r = tc_book();
t_ok( 0 === strpos( $r['data']['redirect'], home_url( '/tc-old-thanks/?booking=' . $r['data']['reference'] ) ), '42. own page by address (1.8 setting): ?booking=REF as before' );
tc_features( array( 'online_payment', 'bank_transfer' ) );
$r = tc_book( array( 'payment_method' => 'bank_transfer' ) );
t_eq( '', $r['data']['redirect'], '42. own page + bank transfer: details stay in the form, as before' );
tc_features( array() );
Flexo_Booking_System_Pages::update( array( 'thank_you' => array( 'source' => 'builtin' ) ) );
tc_settings( array( 'thank_you_url' => '' ) );

/* ------------------------------------------------------------------------- */
t_section( 'No private data in any address (44)' );
tc_features( array( 'online_payment', 'bank_transfer' ) );
$GLOBALS['tc_stripe'] = array();
$pii = array( 'guest_name' => 'Zelda Quartzite', 'guest_email' => 'zelda.quartzite@example.com', 'guest_phone' => '+359 888 765 432', 'notes' => 'Secret-note-xyz' );
$urls = array();
$r    = tc_book( $pii );
$urls[] = $r['data']['redirect'];
$b      = Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] );
$urls   = array_merge( $urls, array_values( Flexo_Booking_Guest::links( $b ) ) );
$r      = tc_book( array_merge( $pii, array( 'payment_method' => 'stripe' ) ) );
$card   = Flexo_Booking_Bookings::get_by_reference( $r['data']['reference'] );
$urls[] = $r['data']['payment']['redirect'];
foreach ( $GLOBALS['tc_stripe'] as $call ) {
	parse_str( (string) $call['body'], $fields );
	foreach ( array( 'success_url', 'cancel_url' ) as $k ) {
		if ( isset( $fields[ $k ] ) ) {
			$urls[] = $fields[ $k ];
		}
	}
}
tc_webhook_paid( $card, (int) round( $card['amount_due'] * 100 ) );
$urls[] = tc_payment_view( $card['reference'], $r['data']['payment']['key'] )['redirect'];
$urls   = array_filter( $urls );
$leak   = array();
foreach ( $urls as $url ) {
	foreach ( array( 'zelda', 'quartzite', '765', 'secret-note' ) as $needle ) {
		if ( false !== stripos( rawurldecode( $url ), $needle ) ) {
			$leak[] = $url;
		}
	}
}
t_ok( count( $urls ) >= 6 && ! $leak, '44. no name, email, phone or message in ' . count( $urls ) . ' generated addresses' );
tc_features( array() );

/* ------------------------------------------------------------------------- */
t_section( 'Preview for the hotel' );
t_eq( '', Flexo_Booking_Confirmation::preview_kind(), 'no preview for visitors' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );
$_GET['flexo_preview'] = 'transfer';
$_GET['_wpnonce']      = wp_create_nonce( 'flexo_preview' );
t_eq( 'transfer', Flexo_Booking_Confirmation::preview_kind(), 'administrator with a valid link' );
$sample = Flexo_Booking_Confirmation::sample_view( 'transfer' );
t_ok( 'FB-SAMPLE' === $sample['reference'] && ! empty( $sample['payment']['instructions']['rows'] ), 'sample data only (never a real booking)' );
$_GET['_wpnonce'] = 'bad';
t_eq( '', Flexo_Booking_Confirmation::preview_kind(), 'invalid link: no preview' );
unset( $_GET['flexo_preview'], $_GET['_wpnonce'] );
wp_set_current_user( 0 );

t_done();
