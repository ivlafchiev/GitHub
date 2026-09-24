<?php
/**
 * Day 5: payments. Payment modes and amounts, card payments (Stripe
 * Checkout, faked: the real Stripe can't be reached from the test
 * environment), signed and idempotent webhooks, holds and their expiry,
 * late payments, refunds, bank transfers (instructions, received, reminder,
 * auto-cancel), request mode, manipulated amounts, emails and Import/Export.
 */
require __DIR__ . '/lib.php';

global $wpdb;
$t5_saved_settings = get_option( Flexo_Booking_Settings::OPTION );
$t5_saved_features = get_option( Flexo_Booking_Features::ENABLED_OPTION );
t_reset_inventory();
foreach ( array( 'payments', 'webhook_events', 'email_log' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
Flexo_Booking_Features::set_available( null );
add_filter( 'flexo_booking_rate_limit', '__return_zero' );
delete_option( 'flexo_test_stripe_api' );

const T5_SECRET = 'whsec_test_secret_123';

function t5_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function t5_features( array $extra ) {
	Flexo_Booking_Features::set_enabled( array_merge( array( 'booking_request', 'instant_booking', 'guest_emails' ), $extra ) );
}
function t5_mails( $reset = false ) {
	$mails = isset( $GLOBALS['flexo_mails'] ) ? $GLOBALS['flexo_mails'] : array();
	if ( $reset ) {
		$GLOBALS['flexo_mails'] = array();
	}
	return $mails;
}
function t5_subjects() {
	return wp_list_pluck( t5_mails(), 'subject' );
}
function t5_to( $mail ) {
	return implode( ',', (array) $mail['to'] );
}
function t5_find( $needle ) {
	foreach ( t5_mails() as $mail ) {
		if ( false !== strpos( $mail['subject'], $needle ) ) {
			return $mail;
		}
	}
	return null;
}
/** Plain text of an email (the HTML part, without tags). */
function t5_text( $mail ) {
	return $mail ? html_entity_decode( wp_strip_all_tags( str_replace( '<br>', "\n", $mail['message'] ) ) ) : '';
}

// ---- Fake Stripe API (records every request) --------------------------------
$GLOBALS['t5_stripe'] = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.stripe.com/v1' ) ) {
			return $pre;
		}
		if ( ! empty( $GLOBALS['t5_stripe_down'] ) ) {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		}
		parse_str( (string) $args['body'], $body );
		$GLOBALS['t5_stripe'][] = array(
			'url'     => $url,
			'method'  => $args['method'],
			'body'    => $body,
			'headers' => $args['headers'],
		);
		if ( preg_match( '#/checkout/sessions$#', $url ) ) {
			$id = 'cs_test_' . count( $GLOBALS['t5_stripe'] );
			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => wp_json_encode( array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id ) ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		if ( preg_match( '#/checkout/sessions/([^/]+)/expire$#', $url, $m ) ) {
			return array(
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'body'     => wp_json_encode( array( 'id' => $m[1], 'status' => 'expired' ) ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		return array( 'response' => array( 'code' => 404, 'message' => 'Not found' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
	},
	10,
	3
);
function t5_last_stripe( $path_part = '/checkout/sessions' ) {
	foreach ( array_reverse( $GLOBALS['t5_stripe'] ) as $call ) {
		if ( false !== strpos( $call['url'], $path_part ) ) {
			return $call;
		}
	}
	return null;
}

/** Sends a signed Stripe webhook through the REST API. */
function t5_webhook( $type, array $object, array $opts = array() ) {
	$opts    = array_merge(
		array(
			'secret'   => T5_SECRET,
			'livemode' => false,
			'id'       => 'evt_' . wp_generate_password( 14, false, false ),
			'time'     => time(),
			'tamper'   => false,
			'header'   => null,
		),
		$opts
	);
	$payload = wp_json_encode(
		array(
			'id'       => $opts['id'],
			'object'   => 'event',
			'type'     => $type,
			'livemode' => $opts['livemode'],
			'data'     => array( 'object' => $object ),
		)
	);
	$sig     = 't=' . $opts['time'] . ',v1=' . hash_hmac( 'sha256', $opts['time'] . '.' . $payload, $opts['secret'] );
	if ( $opts['tamper'] ) {
		$payload = str_replace( '"amount_total":', '"amount_total":1', $payload );
	}
	$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/stripe-webhook' );
	$request->set_header( 'content-type', 'application/json' );
	if ( false !== $opts['header'] ) {
		$request->set_header( 'stripe-signature', null === $opts['header'] ? $sig : $opts['header'] );
	}
	$request->set_body( $payload );
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
		'id'     => $opts['id'],
	);
}

function t5_session_object( array $booking, $amount_cents, array $extra = array() ) {
	return array_merge(
		array(
			'id'                  => $booking['payment_session'],
			'object'              => 'checkout.session',
			'amount_total'        => $amount_cents,
			'currency'            => 'eur',
			'payment_status'      => 'paid',
			'payment_intent'      => 'pi_' . strtolower( $booking['reference'] ),
			'client_reference_id' => $booking['reference'],
			'metadata'            => array( 'booking_id' => (string) $booking['id'], 'reference' => $booking['reference'] ),
		),
		$extra
	);
}

function t5_book( array $data ) {
	$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( array_merge( t_guest( array( 'room' => 'pay-room', 'check_in' => t_day( 7 ), 'check_out' => t_day( 11 ), 'return_url' => home_url( '/booking/?lang=en' ) ) ), $data ) ) );
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}

function t5_get( $reference ) {
	return Flexo_Booking_Bookings::get_by_reference( $reference );
}

function t5_expire_hold( $booking_id ) {
	global $wpdb;
	$wpdb->update( Flexo_Booking_Install::table(), array( 'hold_expires_at' => wp_date( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $booking_id ) );
}

function t5_payment_view( $reference, $key ) {
	$request = new WP_REST_Request( 'GET', '/flexo-booking/v1/payment' );
	$request->set_query_params( array( 'reference' => $reference, 'key' => $key ) );
	$response = rest_do_request( $request );
	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}

// ---- Setup ---------------------------------------------------------------------
$room_id  = t_room( 'pay-room', 'Pay Room', array( 'price' => 200, 'weekend_price' => 0, 'capacity' => 2, 'units' => 1, 'min_nights' => 0 ) );
$room2_id = t_room( 'pay-room-two', 'Pay Room Two', array( 'price' => 100, 'weekend_price' => 0, 'capacity' => 2, 'units' => 2, 'min_nights' => 0 ) );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_abc123', 'stripe_test_webhook_secret' => T5_SECRET ) );
t5_settings(
	array(
		'booking_mode'                => 'instant',
		'currency'                    => 'EUR',
		'min_nights'                  => 1,
		'tourist_tax_amount'          => 0,
		'payment_mode'                => 'full',
		'deposit_type'                => 'percent',
		'deposit_value'               => 30,
		'stripe_mode'                 => 'test',
		'hold_minutes'                => 30,
		'bank_beneficiary'            => 'Hotel Sunrise Ltd.',
		'bank_iban'                   => 'BG80 BNBG 9661 1020 3456 78',
		'bank_bic'                    => 'BNBGBGSD',
		'bank_name'                   => 'Test Bank',
		'bank_reference'              => '{booking_ref}',
		'bank_transfer_days'          => 3,
		'bank_transfer_reminder_days' => 1,
		'bank_transfer_auto_cancel'   => 1,
		'notification_email'          => 'hotel@example.com',
		'field_phone'                 => 'optional',
	)
);
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );

// ---------------------------------------------------------------------------------
t_section( 'Configuration and payment modes' );
t_eq( array( 'stripe', 'bank_transfer' ), Flexo_Booking_Payments::methods(), 'instant booking: card and bank transfer' );
t_ok( Flexo_Booking_Payments::collects_now(), 'full payment is collected when booking' );
t5_settings( array( 'booking_mode' => 'request' ) );
t_eq( array( 'bank_transfer' ), Flexo_Booking_Payments::methods(), 'booking requests: no card payment (never charged before the hotel accepts)' );
t5_settings( array( 'booking_mode' => 'instant' ) );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_abc123' ) );
t_ok( ! Flexo_Booking_Payments::gateway( 'stripe' )->is_ready(), 'card needs the webhook secret too' );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_abc123', 'stripe_test_webhook_secret' => T5_SECRET ) );
t5_settings( array( 'stripe_mode' => 'live' ) );
t_ok( ! Flexo_Booking_Payments::gateway( 'stripe' )->is_ready(), 'live mode uses the live keys (not set) – test and live are separate' );
t5_settings( array( 'stripe_mode' => 'test' ) );
t5_features( array( 'online_payment', 'bank_transfer' ) );
t5_settings( array( 'payment_mode' => 'deposit' ) );
t_eq( 'full', Flexo_Booking_Payments::mode(), 'deposit mode without the Deposits feature → full amount' );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );
t_eq( 'deposit', Flexo_Booking_Payments::mode(), 'deposit mode with the feature' );
t5_settings( array( 'payment_mode' => 'property' ) );
t_ok( ! Flexo_Booking_Payments::collects_now(), 'pay at the property: nothing collected online' );
t5_settings( array( 'payment_mode' => 'full' ) );
t5_features( array() );
t_eq( '', Flexo_Booking_Payments::mode(), 'payments off: no payment mode' );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );
$secrets_before = Flexo_Booking_Payments::secrets();
$sanitized      = Flexo_Booking_Payments::sanitize_secrets( array( 'stripe_test_secret_key' => '', 'stripe_live_secret_key' => ' sk_live_new<script> ' ) );
t_eq( 'sk_test_abc123', $sanitized['stripe_test_secret_key'], 'empty key field keeps the saved key' );
t_eq( 'sk_live_new', $sanitized['stripe_live_secret_key'], 'new key saved (tags and spaces removed)' );
t_eq( $secrets_before, Flexo_Booking_Payments::sanitize_secrets( null ), 'saving another settings tab keeps the keys' );
t_ok( ! empty( Flexo_Booking_Payments::sanitize_secrets( array( 'stripe_test_webhook_secret_clear' => '1' ) ) ) && '' === Flexo_Booking_Payments::sanitize_secrets( array( 'stripe_test_webhook_secret_clear' => '1' ) )['stripe_test_webhook_secret'], '"Remove" clears a key' );

// ---------------------------------------------------------------------------------
t_section( 'Amounts (pricing step 95)' );
$base = array(
	'room'      => 'pay-room',
	'check_in'  => t_day( 7 ),
	'check_out' => t_day( 11 ),
	'adults'    => 2,
	'context'   => 'booking',
);
$q = Flexo_Booking_Pricing::quote( $base );
t_eq( 800.0, $q['total'], 'total 4 × 200 = 800' );
t_eq( 800.0, $q['payable']['now'], 'full: 800 now' );
t_eq( 0.0, $q['payable']['at_property'], 'full: nothing at the property' );
t5_settings( array( 'payment_mode' => 'deposit' ) );
$q = Flexo_Booking_Pricing::quote( $base );
t_eq( 240.0, $q['payable']['now'], 'deposit 30% of 800 → 240 now' );
t_eq( 560.0, $q['payable']['at_property'], '560 at the property' );
t_eq( 'Deposit (30%)', $q['payable']['label'], 'deposit label' );
$view = Flexo_Booking_Pricing::public_view( $q );
t_eq( '240.00 €', $view['payment']['now_formatted'], 'booking form shows the deposit' );
t_ok( false !== strpos( Flexo_Booking_Pricing::summary_text( $q ), 'Payable at the property: 560.00 €' ), 'text summary: rest at the property' );
t5_settings( array( 'deposit_type' => 'fixed', 'deposit_value' => 100 ) );
t_eq( 100.0, Flexo_Booking_Pricing::quote( $base )['payable']['now'], 'fixed deposit 100' );
t5_settings( array( 'deposit_value' => 5000 ) );
t_eq( 800.0, Flexo_Booking_Pricing::quote( $base )['payable']['now'], 'fixed deposit never more than the total' );
t5_settings( array( 'deposit_type' => 'percent', 'deposit_value' => 150 ) );
t_eq( 100.0, (float) Flexo_Booking_Settings::get( 'deposit_value' ), 'percentage capped at 100' );
t5_settings( array( 'deposit_value' => 30 ) );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer', 'tourist_tax' ) );
t5_settings( array( 'tourist_tax_amount' => 2, 'tourist_tax_collect' => 'property', 'tourist_tax_children' => 'adult' ) );
$q = Flexo_Booking_Pricing::quote( $base );
t_eq( 800.0, $q['total'], 'tourist tax paid at the property is not in the total' );
t_eq( 240.0, $q['payable']['now'], 'deposit from the total without that tax' );
t_eq( 576.0, $q['payable']['at_property'], 'at the property: 560 + tax 16' );
t5_settings( array( 'tourist_tax_collect' => 'booking' ) );
$q = Flexo_Booking_Pricing::quote( $base );
t_eq( 816.0, $q['total'], 'tax collected with the booking is in the total' );
t_eq( 244.8, $q['payable']['now'], 'deposit 30% of 816' );
t5_settings( array( 'tourist_tax_amount' => 0, 'payment_mode' => 'full' ) );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );
t5_features( array() );
t_ok( ! isset( Flexo_Booking_Pricing::quote( $base )['payable']['mode'] ), 'payments off: no payment step' );
t_eq( null, Flexo_Booking_Pricing::public_view( Flexo_Booking_Pricing::quote( $base ) )['payment'], 'payments off: booking form shows nothing about paying' );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );

// ---------------------------------------------------------------------------------
t_section( 'Card payment, full amount' );
t_reset_inventory();
t5_mails( true );
$r = t5_book( array( 'payment_method' => 'stripe', 'expected_total' => 800 ) );
t_eq( 201, $r['status'], 'booking accepted' );
$ref = $r['data']['reference'];
$key = $r['data']['payment']['key'];
$b   = t5_get( $ref );
t_eq( 'pending_payment', $b['status'], 'status: Pending payment' );
t_eq( 'pending', $b['payment_status'], 'payment status: pending' );
t_eq( 'stripe', $b['payment_method'], 'method stored' );
t_eq( 800.0, $b['amount_due'], 'amount due 800' );
t_ok( 0 === strpos( $r['data']['payment']['redirect'], 'https://checkout.stripe.com/' ), 'guest is sent to the Stripe payment page' );
$hold = strtotime( get_gmt_from_date( $b['hold_expires_at'] ) . ' UTC' ) - time();
t_ok( $hold > 29 * 60 && $hold <= 30 * 60, 'room held for 30 minutes' );
t_eq( 64, strlen( $b['access_key'] ), 'only a hash of the guest link key is stored' );
t_ok( $b['access_key'] !== $key && hash( 'sha256', $key ) === $b['access_key'], 'key matches its hash' );
t_eq( 0, count( t5_mails() ), 'no emails before the payment' );
$call = t5_last_stripe();
t_eq( 'Bearer sk_test_abc123', $call['headers']['Authorization'], 'test secret key used' );
t_eq( '80000', $call['body']['line_items'][0]['price_data']['unit_amount'], 'Stripe amount 80000 cents, from the server' );
t_eq( 'eur', $call['body']['line_items'][0]['price_data']['currency'], 'currency' );
t_eq( (string) $b['id'], $call['body']['metadata']['booking_id'], 'booking id in metadata' );
t_eq( (string) $b['id'], $call['body']['payment_intent_data']['metadata']['booking_id'], 'booking id on the PaymentIntent' );
t_ok( false !== strpos( $call['body']['success_url'], 'fb_payment=return' ) && false !== strpos( $call['body']['success_url'], 'fb_ref=' . $ref ) && false !== strpos( $call['body']['success_url'], 'lang=en' ), 'return address: the booking page with its parameters' );
t_ok( (int) $call['body']['expires_at'] >= time() + 30 * 60, 'Stripe page open at least 30 minutes' );
t_ok( ! empty( $call['headers']['Idempotency-Key'] ), 'idempotency key sent' );
t_ok( ! isset( $call['body']['payment_method_types'] ) && ! preg_grep( '/card_number|cvc/i', array_keys( $call['body'] ) ), 'no card data handled' );
t_eq( $call['body']['line_items'][0]['price_data']['product_data']['name'], 'Booking ' . $ref, 'payment named after the booking' );

// The held room is not available to others.
$search = Flexo_Booking_Bookings::search( t_day( 8 ), t_day( 9 ), 2, 0, 'pay-room' );
t_ok( ! $search['rooms'][0]['available'], 'held room is sold out for others' );
$other = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 'pay-room', 'check_in' => t_day( 8 ), 'check_out' => t_day( 9 ), 'guest_email' => 'other@example.com' ) ) );
t_eq( 'flexo_unavailable', is_wp_error( $other ) ? $other->get_error_code() : '', 'another guest cannot take the held room' );

$v = t5_payment_view( $ref, 'wrong-key' );
t_eq( 404, $v['status'], 'payment status needs the guest\'s key' );
$v = t5_payment_view( $ref, $key );
t_eq( 'held', $v['data']['state'], 'before the webhook: still held (the return page never confirms)' );

$w = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ) );
t_eq( 200, $w['status'], 'webhook accepted' );
$b = t5_get( $ref );
t_eq( 'confirmed', $b['status'], 'confirmed by the webhook' );
t_eq( 'paid', $b['payment_status'], 'payment status: Payment received' );
t_eq( 800.0, $b['amount_paid'], 'amount paid 800' );
t_eq( null, $b['hold_expires_at'], 'hold cleared' );
$history = Flexo_Booking_Payments::history( $b['id'] );
t_eq( 1, count( $history ), 'one payment in the history' );
t_eq( 'pi_' . strtolower( $ref ), $history[0]['transaction_id'], 'Stripe PaymentIntent ID stored' );
t_ok( 'stripe' === $history[0]['gateway'] && 800.0 === $history[0]['amount'] && 'EUR' === $history[0]['currency'], 'gateway, amount and currency stored' );
t_eq( 2, count( t5_mails() ), 'two emails: guest and hotel' );
$guest = t5_find( 'Payment received' );
t_ok( $guest && 'guest@example.com' === t5_to( $guest ), 'guest gets "Payment received – confirmed"' );
t_ok( false !== strpos( t5_text( $guest ), 'Paid: 800.00 €' ), 'guest email shows the amount paid' );
$hotel = t5_find( 'New booking' );
t_ok( $hotel && 'hotel@example.com' === t5_to( $hotel ), 'hotel notified of the paid booking' );
t_ok( false !== strpos( t5_text( $hotel ), 'Payment: Card' ), 'hotel email shows the payment' );

t5_mails( true );
$w2 = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'id' => $w['id'] ) );
t_ok( 200 === $w2['status'] && ! empty( $w2['data']['duplicate'] ), 'same event again: acknowledged as a duplicate' );
$w3 = t5_webhook( 'checkout.session.async_payment_succeeded', t5_session_object( $b, 80000 ) );
t_eq( 'duplicate', $w3['data']['result'], 'same payment in another event: not recorded twice' );
t_eq( 800.0, t5_get( $ref )['amount_paid'], 'still 800 paid' );
t_eq( 1, count( Flexo_Booking_Payments::history( $b['id'] ) ), 'still one payment' );
t_eq( 0, count( t5_mails() ), 'no second confirmation email' );
$v = t5_payment_view( $ref, $key );
t_eq( 'confirmed', $v['data']['state'], 'return page: confirmed' );
t_eq( '800.00 €', $v['data']['paid_formatted'], 'return page: paid amount' );
$paid_ref = $ref;

// ---------------------------------------------------------------------------------
t_section( 'Card payment, deposit' );
t_reset_inventory();
t5_settings( array( 'payment_mode' => 'deposit' ) );
t5_mails( true );
$r   = t5_book( array( 'payment_method' => 'stripe' ) );
$ref = $r['data']['reference'];
$b   = t5_get( $ref );
t_eq( 240.0, $b['amount_due'], 'deposit due 240' );
t_eq( '24000', t5_last_stripe()['body']['line_items'][0]['price_data']['unit_amount'], 'Stripe charges 240' );
t_eq( 'Deposit for booking ' . $ref, t5_last_stripe()['body']['line_items'][0]['price_data']['product_data']['name'], 'named as a deposit' );
t5_webhook( 'checkout.session.completed', t5_session_object( $b, 24000 ) );
$b = t5_get( $ref );
t_eq( 'confirmed', $b['status'], 'deposit paid → confirmed' );
t_eq( 'deposit_paid', $b['payment_status'], 'payment status: Deposit received' );
t_eq( 'Deposit received', Flexo_Booking_Payments::status_label( $b ), 'label' );
$balance = Flexo_Booking_Payments::balance( $b );
t_eq( 560.0, $balance['outstanding'], 'remaining 560 at the property' );
$text = t5_text( t5_find( 'Payment received' ) );
t_ok( false !== strpos( $text, 'Paid: 240.00 €' ) && false !== strpos( $text, 'To pay at the property: 560.00 €' ), 'guest email: 240 paid, 560 at the property' );
t_eq( 'Deposit', Flexo_Booking_Payments::history( $b['id'] )[0]['note'], 'history note: Deposit' );
t5_settings( array( 'payment_mode' => 'full' ) );

// ---------------------------------------------------------------------------------
t_section( 'Webhook signatures' );
t_reset_inventory();
$r   = t5_book( array( 'payment_method' => 'stripe' ) );
$ref = $r['data']['reference'];
$key = $r['data']['payment']['key'];
$b   = t5_get( $ref );
t_eq( 400, t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'tamper' => true ) )['status'], 'changed payload → rejected' );
t_eq( 400, t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'secret' => 'whsec_wrong' ) )['status'], 'wrong secret → rejected' );
t_eq( 400, t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'time' => time() - 600 ) )['status'], 'older than 5 minutes (replay) → rejected' );
t_eq( 400, t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'header' => false ) )['status'], 'no signature → rejected' );
t_eq( 400, t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'header' => 't=' . time() . ',v1=deadbeef' ) )['status'], 'forged signature → rejected' );
$live = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'livemode' => true ) );
t_ok( 200 === $live['status'] && 'mode' === $live['data']['ignored'], 'live-mode event on a test-mode site: ignored' );
t_eq( 'pending_payment', t5_get( $ref )['status'], 'none of these confirmed the booking' );
t_eq( 0, count( Flexo_Booking_Payments::history( $b['id'] ) ), 'nothing recorded' );
t_ok( Flexo_Booking_Gateway_Stripe::verify_signature( 'x', 't=100,v1=' . hash_hmac( 'sha256', '100.x', 's' ), 's', 300, 200 ), 'signature check (unit)' );
$unknown = t5_webhook( 'checkout.session.completed', array( 'id' => 'cs_x', 'metadata' => array( 'booking_id' => '999999' ), 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'eur' ) );
t_eq( 'unknown_booking', $unknown['data']['result'], 'event for an unknown booking: acknowledged, nothing done' );
$cur = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000, array( 'currency' => 'usd' ) ) );
t_eq( 500, $cur['status'], 'currency different from the booking: refused (Stripe retries)' );
t_eq( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Schema::table( 'webhook_events' ) . ' WHERE event_id = %s', $cur['id'] ) ), 'a failed event is not marked as processed' );

// ---------------------------------------------------------------------------------
t_section( 'Payment too small is not a confirmation' );
$small = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 100, array( 'payment_intent' => 'pi_small' ) ) );
t_eq( 'recorded', $small['data']['result'], '1.00 recorded' );
t_eq( 'pending_payment', t5_get( $ref )['status'], 'but the booking is not confirmed' );

// ---------------------------------------------------------------------------------
t_section( 'Failed payment' );
t_reset_inventory();
t5_mails( true );
$r   = t5_book( array( 'payment_method' => 'stripe' ) );
$ref = $r['data']['reference'];
$key = $r['data']['payment']['key'];
$b   = t5_get( $ref );
$f   = t5_webhook( 'payment_intent.payment_failed', array( 'id' => 'pi_declined', 'amount' => 80000, 'metadata' => array( 'booking_id' => (string) $b['id'], 'reference' => $ref ), 'last_payment_error' => array( 'message' => 'Your card was declined.' ) ) );
t_eq( 'attempt_failed', $f['data']['result'], 'declined card noted' );
t_eq( 'pending_payment', t5_get( $ref )['status'], 'the guest can still try another card while the hold lasts' );
$attempt = Flexo_Booking_Payments::history( $b['id'] )[0];
t_ok( 'attempt' === $attempt['type'] && 'Your card was declined.' === $attempt['note'], 'failed attempt in the history (no card details)' );
t_eq( 0, count( t5_mails() ), 'no email for a single declined attempt' );
$e = t5_webhook( 'checkout.session.expired', t5_session_object( $b, 80000, array( 'payment_status' => 'unpaid', 'payment_intent' => null ) ) );
t_eq( 'released', $e['data']['result'], 'payment page expired' );
$b = t5_get( $ref );
t_eq( 'expired', $b['status'], 'booking: Not paid' );
t_eq( 'failed', $b['payment_status'], 'payment status: Payment failed' );
$failed = t5_find( 'did not go through' );
t_ok( $failed && 'guest@example.com' === t5_to( $failed ), 'guest gets "Payment failed"' );
t_eq( 1, count( t5_mails() ), 'only that email (no cancellation emails)' );
t_ok( Flexo_Booking_Bookings::search( t_day( 8 ), t_day( 9 ), 2, 0, 'pay-room' )['rooms'][0]['available'], 'room free again' );
t_eq( 'failed', t5_payment_view( $ref, $key )['data']['state'], 'return page: failed' );
t_ok( t5_payment_view( $ref, $key )['data']['can_retry'], 'guest can try again (room still free)' );

t_reset_inventory();
t5_mails( true );
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
t5_webhook( 'checkout.session.async_payment_failed', t5_session_object( $b, 80000, array( 'payment_status' => 'unpaid' ) ) );
t_eq( 'expired', t5_get( $b['reference'] )['status'], 'delayed payment method failed → room released' );
t_ok( (bool) t5_find( 'did not go through' ), 'guest told' );

// ---------------------------------------------------------------------------------
t_section( 'Abandoned payment: hold released' );
t_reset_inventory();
t5_mails( true );
$calls = count( $GLOBALS['t5_stripe'] );
$r     = t5_book( array( 'payment_method' => 'stripe' ) );
$ref   = $r['data']['reference'];
$key   = $r['data']['payment']['key'];
$b     = t5_get( $ref );
t_ok( (bool) wp_next_scheduled( Flexo_Booking_Payments::EXPIRE_HOOK, array( (int) $b['id'] ) ), 'release scheduled for the end of the hold' );
t5_expire_hold( $b['id'] );
t_ok( Flexo_Booking_Bookings::search( t_day( 8 ), t_day( 9 ), 2, 0, 'pay-room' )['rooms'][0]['available'], 'the moment the hold ends the room is bookable (no cron needed)' );
$jobs = Flexo_Booking_Payments::run_scheduled();
t_eq( 1, $jobs['released'], 'scheduled job releases it' );
$b = t5_get( $ref );
t_ok( 'expired' === $b['status'] && 'expired' === $b['payment_status'], 'status: not paid in time' );
t_ok( false !== strpos( t5_last_stripe( '/expire' )['url'], $b['payment_session'] ), 'Stripe payment page closed' );
t_eq( 0, count( t5_mails() ), 'no email for an abandoned payment' );
t_eq( 'expired', t5_payment_view( $ref, $key )['data']['state'], 'return page: time ran out' );
t_ok( ! wp_next_scheduled( Flexo_Booking_Payments::EXPIRE_HOOK, array( (int) $b['id'] ) ), 'scheduled release removed' );

// Returning guest opens the lapsed hold: released right away.
t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
t5_expire_hold( $b['id'] );
t_eq( 'expired', t5_payment_view( $b['reference'], $r['data']['payment']['key'] )['data']['state'], 'status check releases a lapsed hold' );
t_eq( 'expired', t5_get( $b['reference'] )['status'], 'released' );

// ---------------------------------------------------------------------------------
t_section( 'Payment after the hold expired' );
t_reset_inventory();
t5_mails( true );
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$a = t5_get( $r['data']['reference'] );
t5_expire_hold( $a['id'] );
Flexo_Booking_Payments::run_scheduled();
$late = t5_webhook( 'checkout.session.completed', t5_session_object( $a, 80000 ) );
t_eq( 'confirmed', $late['data']['result'], 'room still free: late payment confirms the booking' );
t_eq( 'confirmed', t5_get( $a['reference'] )['status'], 'confirmed' );

t_reset_inventory();
t5_mails( true );
$r  = t5_book( array( 'payment_method' => 'stripe' ) );
$a  = t5_get( $r['data']['reference'] );
$ka = $r['data']['payment']['key'];
t5_expire_hold( $a['id'] );
$other = Flexo_Booking_Bookings::create( t_guest( array( 'room' => 'pay-room', 'check_in' => t_day( 8 ), 'check_out' => t_day( 10 ), 'guest_email' => 'second@example.com', 'source' => 'admin', 'status' => 'confirmed' ) ) );
t_ok( ! is_wp_error( $other ), 'someone else books the room after the hold expired' );
t5_mails( true );
$late = t5_webhook( 'checkout.session.completed', t5_session_object( $a, 80000 ) );
t_eq( 'conflict', $late['data']['result'], 'payment arrives: conflict' );
$a = t5_get( $a['reference'] );
t_eq( 'expired', $a['status'], 'the late booking is NOT confirmed' );
t_eq( 1, $a['payment_conflict'], 'marked as a payment conflict' );
t_eq( 800.0, $a['amount_paid'], 'the money is recorded' );
t_eq( 'confirmed', Flexo_Booking_Bookings::get( $other['id'] )['status'], 'the other guest keeps the room' );
$occupied = Flexo_Booking_Inventory::occupying_sql();
t_eq( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Install::table() . " WHERE room_id = %d AND {$occupied['sql']}", array_merge( array( $room_id ), $occupied['params'] ) ) ), 'no overbooking: one booking occupies the room' );
$alert = t5_find( 'Action needed' );
t_ok( $alert && 'hotel@example.com' === t5_to( $alert ), 'hotel alerted' );
t_ok( false !== strpos( t5_text( $alert ), 'NOT confirmed' ) && false !== strpos( t5_text( $alert ), 'refund' ), 'alert explains what to do' );
t_eq( null, t5_find( 'Payment received' ), 'guest does not get a confirmation' );
t_eq( 'conflict', t5_payment_view( $a['reference'], $ka )['data']['state'], 'return page tells the guest the hotel will contact them' );
t_eq( array( $a['id'] ), wp_list_pluck( Flexo_Booking_Payments::open_conflicts(), 'id' ), 'listed for the hotel' );
Flexo_Booking_Payments::resolve_conflict( $a['id'] );
t_eq( array(), Flexo_Booking_Payments::open_conflicts(), 'marked as resolved' );

// Paid for a booking that the hotel cancelled meanwhile.
t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$c = t5_get( $r['data']['reference'] );
Flexo_Booking_Bookings::update_status( $c['id'], 'cancelled' );
t5_mails( true );
t_eq( 'conflict', t5_webhook( 'checkout.session.completed', t5_session_object( $c, 80000 ) )['data']['result'], 'payment for a cancelled booking: conflict, not reinstated' );
t_eq( 'cancelled', t5_get( $c['reference'] )['status'], 'stays cancelled' );

// ---------------------------------------------------------------------------------
t_section( 'Room busy when the payment arrives' );
t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
add_filter( 'flexo_booking_use_mysql_locks', '__return_false' );
$lock_row = '_flexo_lock_' . md5( 'room_' . $b['room_id'] );
$wpdb->insert( $wpdb->options, array( 'option_name' => $lock_row, 'option_value' => time(), 'autoload' => 'no' ) );
$busy = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ) );
t_eq( 500, $busy['status'], 'room lock busy: webhook answered with an error so Stripe retries' );
t_ok( 'pending_payment' === t5_get( $b['reference'] )['status'] && 0.0 === t5_get( $b['reference'] )['amount_paid'] && ! Flexo_Booking_Payments::history( $b['id'] ), 'nothing half-recorded' );
$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_row ) );
remove_filter( 'flexo_booking_use_mysql_locks', '__return_false' );
$retry = t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ), array( 'id' => $busy['id'] ) );
t_eq( 'confirmed', $retry['data']['result'], 'Stripe\'s retry of the same event confirms the booking' );

// ---------------------------------------------------------------------------------
t_section( 'Refunds' );
$b = t5_get( $paid_ref );
t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ) );
t5_mails( true );
$charge = array( 'id' => 'ch_1', 'object' => 'charge', 'amount' => 80000, 'amount_refunded' => 30000, 'currency' => 'eur', 'payment_intent' => 'pi_' . strtolower( $b['reference'] ) );
$rf     = t5_webhook( 'charge.refunded', $charge );
t_eq( 'refund_recorded', $rf['data']['result'], 'partial refund from Stripe recorded' );
$b = t5_get( $b['reference'] );
t_eq( 300.0, $b['amount_refunded'], '300 refunded' );
t_eq( 'partially_refunded', $b['payment_status'], 'payment status: Partially refunded' );
t_eq( 'confirmed', $b['status'], 'booking status unchanged' );
t_ok( (bool) t5_find( 'Refund of 300.00 €' ), 'hotel told about the refund' );
t_eq( 'nothing_new', t5_webhook( 'charge.refunded', $charge )['data']['result'], 'same refund again (new event): nothing new' );
$charge['amount_refunded'] = 80000;
t5_webhook( 'charge.refunded', $charge );
$b = t5_get( $b['reference'] );
t_eq( 800.0, $b['amount_refunded'], 'rest refunded: 800 in total' );
t_eq( 'refunded', $b['payment_status'], 'payment status: Refunded' );
t_eq( 2, count( array_filter( Flexo_Booking_Payments::history( $b['id'] ), static function ( $p ) { return 'refund' === $p['type']; } ) ), 'two refund rows' );
$too_much = Flexo_Booking_Payments::refund( $b['id'], 1, 'manual', '' );
t_ok( is_wp_error( $too_much ), 'cannot record more refunds than paid' );

t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
t5_webhook( 'checkout.session.completed', t5_session_object( $b, 80000 ) );
$manual = Flexo_Booking_Payments::refund( $b['id'], 100, 'manual', '', 'Goodwill' );
t_ok( ! is_wp_error( $manual ) && 'partially_refunded' === $manual['booking']['payment_status'], 'manual refund recorded by staff' );

// ---------------------------------------------------------------------------------
t_section( 'Trying again' );
t_reset_inventory();
$r    = t5_book( array( 'payment_method' => 'stripe' ) );
$ref  = $r['data']['reference'];
$key  = $r['data']['payment']['key'];
$b    = t5_get( $ref );
$req  = new WP_REST_Request( 'POST', '/flexo-booking/v1/payment/retry' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'reference' => $ref, 'key' => $key, 'return_url' => home_url( '/booking/' ) ) ) );
$res  = rest_do_request( $req );
t_eq( 200, $res->get_status(), 'new payment page while the hold lasts' );
$data = $res->get_data();
t_ok( 0 === strpos( $data['redirect'], 'https://checkout.stripe.com/' ) && ! empty( $data['key'] ), 'redirect and a new key' );
t_ok( false !== strpos( t5_last_stripe( '/expire' )['url'], $b['payment_session'] ), 'old payment page closed' );
t_eq( 404, t5_payment_view( $ref, $key )['status'], 'old key no longer works' );
t_eq( 'held', t5_payment_view( $ref, $data['key'] )['data']['state'], 'new key works' );
$old_session = $b['payment_session'];
$b           = t5_get( $ref );
t_ok( $old_session !== $b['payment_session'], 'new Stripe session stored' );
$stale = t5_webhook( 'checkout.session.expired', array_merge( t5_session_object( $b, 80000 ), array( 'id' => $old_session ) ) );
t_eq( 'ignored', $stale['data']['result'], 'the old page expiring does not release the new hold' );
t5_expire_hold( $b['id'] );
Flexo_Booking_Payments::run_scheduled();
Flexo_Booking_Bookings::create( t_guest( array( 'room' => 'pay-room', 'check_in' => t_day( 7 ), 'check_out' => t_day( 8 ), 'source' => 'admin', 'status' => 'confirmed' ) ) );
$req->set_body( wp_json_encode( array( 'reference' => $ref, 'key' => $data['key'] ) ) );
t_eq( 409, rest_do_request( $req )->get_status(), 'after expiry with the room taken: cannot pay again' );

// ---------------------------------------------------------------------------------
t_section( 'Stripe unreachable' );
t_reset_inventory();
$GLOBALS['t5_stripe_down'] = true;
$r                          = t5_book( array( 'payment_method' => 'stripe' ) );
$GLOBALS['t5_stripe_down'] = false;
t_eq( 502, $r['status'], 'booking form gets an error' );
t_ok( false !== strpos( $r['data']['message'], 'not available at the moment' ), 'friendly message' );
$held = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Install::table() . " WHERE status = 'pending_payment'" );
t_eq( 0, $held, 'no room left held' );

// ---------------------------------------------------------------------------------
t_section( 'Bank transfer (instant booking)' );
t_reset_inventory();
t5_mails( true );
$r   = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$ref = $r['data']['reference'];
$b   = t5_get( $ref );
t_eq( 201, $r['status'], 'booking accepted' );
t_eq( 'awaiting_payment', $b['status'], 'status: Awaiting payment' );
t_eq( 'unpaid', $b['payment_status'], 'payment status: unpaid' );
t_eq( 'Awaiting payment', Flexo_Booking_Payments::status_label( $b ), 'label: Awaiting payment (full amount)' );
t_eq( Flexo_Booking_Dates::add_days( wp_date( 'Y-m-d' ), 3 ) . ' 23:59:59', $b['payment_due_at'], 'deadline: end of the day in 3 days' );
t_eq( '', $r['data']['redirect'], 'no redirect: the details are shown in the form' );
$rows = wp_list_pluck( $r['data']['payment']['instructions']['rows'], 'value', 'key' );
t_eq( 'BG80 BNBG 9661 1020 3456 78', $rows['iban'], 'IBAN shown in groups of four' );
t_eq( $ref, $rows['reference'], 'payment reference = booking reference' );
t_eq( '800.00 €', $rows['amount'], 'amount' );
t_eq( 'Hotel Sunrise Ltd.', $rows['beneficiary'], 'beneficiary' );
t_ok( isset( $rows['bic'], $rows['bank'], $rows['deadline'] ), 'BIC, bank and deadline' );
t_ok( false !== strpos( $r['data']['message'], 'bank transfer' ), 'success message explains the transfer' );
$mail = t5_find( 'Payment details' );
t_ok( $mail && 'guest@example.com' === t5_to( $mail ), 'guest gets the payment details email' );
$text = t5_text( $mail );
t_ok( false !== strpos( $text, 'BG80 BNBG 9661 1020 3456 78' ) && false !== strpos( $text, 'Payment reference: ' . $ref ) && false !== strpos( $text, 'Pay by' ), 'email contains IBAN, reference and deadline' );
$hotel = t5_find( 'New booking' );
t_ok( $hotel && false !== strpos( t5_text( $hotel ), 'waits for payment by bank transfer' ), 'hotel told the booking waits for the transfer' );
t_eq( 2, count( t5_mails() ), 'two emails' );
t_ok( ! Flexo_Booking_Bookings::search( t_day( 8 ), t_day( 9 ), 2, 0, 'pay-room' )['rooms'][0]['available'], 'the room is kept for the guest until the deadline' );
t5_mails( true );
$done = Flexo_Booking_Payments::complete( $b['id'], 800, 'bank_transfer', '' );
t_eq( 'confirmed', $done['result'], 'hotel marks the payment as received' );
$b = t5_get( $ref );
t_ok( 'confirmed' === $b['status'] && 'paid' === $b['payment_status'], 'confirmed, payment received' );
t_ok( (bool) t5_find( 'Payment received' ), 'guest gets "Payment received"' );
t_eq( 1, count( t5_mails() ), 'no email to the hotel (they did it themselves)' );

// Deposit by bank transfer.
t_reset_inventory();
t5_settings( array( 'payment_mode' => 'deposit' ) );
$r = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$b = t5_get( $r['data']['reference'] );
t_eq( 240.0, $b['amount_due'], 'deposit 240 by bank transfer' );
t_eq( 'Awaiting deposit', Flexo_Booking_Payments::status_label( $b ), 'label: Awaiting deposit' );
Flexo_Booking_Payments::complete( $b['id'], 240, 'bank_transfer', '' );
t_eq( 'deposit_paid', t5_get( $b['reference'] )['payment_status'], 'Deposit received' );
t5_settings( array( 'payment_mode' => 'full' ) );

// Reminder and auto-cancel.
t_reset_inventory();
$r   = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$b   = t5_get( $r['data']['reference'] );
$now = substr( $b['payment_due_at'], 0, 10 );
t5_mails( true );
t_eq( 0, Flexo_Booking_Payments::run_scheduled( Flexo_Booking_Dates::add_days( $now, -2 ) . ' 10:00:00' )['reminded'], 'no reminder 2 days before' );
t_eq( 0, Flexo_Booking_Payments::run_scheduled( $now . ' 03:00:00' )['reminded'], 'no reminder at night' );
t_eq( 1, Flexo_Booking_Payments::run_scheduled( $now . ' 10:00:00' )['reminded'], 'reminder 1 day before the deadline' );
t_eq( 0, Flexo_Booking_Payments::run_scheduled( $now . ' 11:00:00' )['reminded'], 'only once' );
$mail = t5_find( 'Reminder' );
t_ok( $mail && false !== strpos( t5_text( $mail ), 'BG80 BNBG' ), 'reminder repeats the bank details' );
t5_mails( true );
t_eq( 0, Flexo_Booking_Payments::run_scheduled( $now . ' 23:00:00' )['cancelled'], 'not cancelled before the deadline' );
t_eq( 1, Flexo_Booking_Payments::run_scheduled( Flexo_Booking_Dates::add_days( $now, 1 ) . ' 00:30:00' )['cancelled'], 'cancelled after the deadline' );
$b = t5_get( $b['reference'] );
t_eq( 'cancelled', $b['status'], 'booking cancelled' );
$g = t5_find( 'payment not received' );
t_ok( $g && 'guest@example.com' === t5_to( $g ), 'guest told the booking was cancelled for non-payment' );
$h = t5_find( '(payment not received)' );
t_ok( $h && 'hotel@example.com' === t5_to( $h ), 'hotel told' );
t_eq( 2, count( t5_mails() ), 'no extra generic cancellation emails' );
t_ok( Flexo_Booking_Bookings::search( t_day( 8 ), t_day( 9 ), 2, 0, 'pay-room' )['rooms'][0]['available'], 'room free again' );

t_reset_inventory();
t5_settings( array( 'bank_transfer_auto_cancel' => 0 ) );
$r = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$b = t5_get( $r['data']['reference'] );
Flexo_Booking_Payments::run_scheduled( Flexo_Booking_Dates::add_days( substr( $b['payment_due_at'], 0, 10 ), 2 ) . ' 10:00:00' );
t_eq( 'awaiting_payment', t5_get( $b['reference'] )['status'], 'auto-cancel off: stays awaiting payment' );
t5_settings( array( 'bank_transfer_auto_cancel' => 1 ) );
Flexo_Booking_Bookings::update_status( $b['id'], 'cancelled' );

// ---------------------------------------------------------------------------------
t_section( 'Bank transfer with booking requests' );
t_reset_inventory();
t5_settings( array( 'booking_mode' => 'request' ) );
t5_mails( true );
$r   = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$ref = $r['data']['reference'];
$b   = t5_get( $ref );
t_eq( 'pending', $b['status'], 'request: pending (waits for the hotel)' );
t_eq( 'bank_transfer', $b['payment_method'], 'bank transfer chosen' );
t_eq( null, $b['payment_due_at'], 'no deadline yet' );
t_eq( null, $r['data']['payment']['instructions'], 'no bank details before the hotel accepts' );
t_ok( false !== strpos( $r['data']['message'], 'Once we confirm it' ), 'guest told the details follow' );
t_ok( (bool) t5_find( 'We received your booking request' ), 'guest gets the request email' );
t_ok( false !== strpos( t5_text( t5_find( 'New booking request' ) ), 'receives your bank details' ), 'hotel told what confirming does' );
t_eq( 'Confirm & ask for payment', Flexo_Booking_Admin::confirm_label( $b ), 'admin button: Confirm & ask for payment' );
t5_mails( true );
Flexo_Booking_Bookings::update_status( $b['id'], 'confirmed' );
$b = t5_get( $ref );
t_eq( 'awaiting_payment', $b['status'], 'accepted → awaiting payment' );
t_eq( Flexo_Booking_Dates::add_days( wp_date( 'Y-m-d' ), 3 ) . ' 23:59:59', $b['payment_due_at'], 'deadline counts from accepting' );
t_ok( (bool) t5_find( 'Payment details' ), 'guest gets the bank details' );
t_eq( null, t5_find( 'is confirmed' ), 'no "confirmed" email yet' );
Flexo_Booking_Bookings::update_status( $b['id'], 'confirmed', array( 'force' => true ) );
t_eq( 'confirmed', t5_get( $ref )['status'], '"Confirm without payment" confirms it' );

t_section( 'Card payments are blocked with booking requests' );
$r = t5_book( array( 'payment_method' => 'stripe', 'check_in' => t_day( 20 ), 'check_out' => t_day( 21 ) ) );
t_eq( 400, $r['status'], 'card payment refused in request mode' );
t_eq( 'flexo_payment_method', $r['data']['code'], 'error: payment method not available' );
t5_settings( array( 'booking_mode' => 'instant' ) );

// ---------------------------------------------------------------------------------
t_section( 'Pay at the property, and no payments' );
t_reset_inventory();
t5_settings( array( 'payment_mode' => 'property' ) );
t5_mails( true );
$calls = count( $GLOBALS['t5_stripe'] );
$r     = t5_book( array( 'payment_method' => 'stripe' ) );
$b = t5_get( $r['data']['reference'] );
t_eq( 'confirmed', $b['status'], 'instant booking confirmed straight away' );
t_eq( 'property', $b['payment_method'], 'payment: at the property' );
t_eq( null, $r['data']['payment'], 'nothing to pay online' );
t_eq( $calls, count( $GLOBALS['t5_stripe'] ), 'no Stripe call' );
$text = t5_text( t5_find( 'is confirmed' ) );
t_ok( false !== strpos( $text, 'Payment: At the property' ) && false !== strpos( $text, 'To pay at the property: 800.00 €' ), 'confirmation says: pay 800 at the property' );
t_eq( 'property', Flexo_Booking_Pricing::public_view( Flexo_Booking_Pricing::snapshot( $b ) )['payment']['mode'], 'booking form shows "pay at the property"' );
t5_settings( array( 'payment_mode' => 'full' ) );

t_reset_inventory();
t5_features( array() );
$r = t5_book( array() );
$b = t5_get( $r['data']['reference'] );
t_ok( 'confirmed' === $b['status'] && '' === $b['payment_method'] && null === $r['data']['payment'], 'instant booking without payments: as before' );
t5_settings( array( 'booking_mode' => 'request' ) );
$r = t5_book( array( 'check_in' => t_day( 20 ), 'check_out' => t_day( 21 ) ) );
$b = t5_get( $r['data']['reference'] );
t_ok( 'pending' === $b['status'] && '' === $b['payment_method'], 'booking request without payments: as before' );
t5_settings( array( 'booking_mode' => 'instant' ) );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );

// ---------------------------------------------------------------------------------
t_section( 'Amounts from the browser are never used' );
t_reset_inventory();
$before = count( $GLOBALS['t5_stripe'] );
$r      = t5_book( array( 'payment_method' => 'stripe', 'expected_total' => 1 ) );
t_eq( 409, $r['status'], 'changed total → refused' );
t_eq( 'flexo_price_changed', $r['data']['code'], 'price changed error' );
t_eq( $before, count( $GLOBALS['t5_stripe'] ), 'no payment page opened' );
$r = t5_book( array( 'payment_method' => 'stripe', 'amount' => 1, 'amount_due' => 1, 'total' => 1, 'deposit' => 1, 'unit_amount' => 100 ) );
t_eq( 201, $r['status'], 'extra amount fields are ignored' );
t_eq( '80000', t5_last_stripe()['body']['line_items'][0]['price_data']['unit_amount'], 'Stripe still charges the server\'s 800' );
t_eq( 800.0, t5_get( $r['data']['reference'] )['amount_due'], 'stored amount due 800' );
$r = t5_book( array( 'payment_method' => 'paypal', 'check_in' => t_day( 20 ), 'check_out' => t_day( 21 ) ) );
t_eq( 'flexo_payment_method', $r['data']['code'], 'unknown payment method refused' );

// ---------------------------------------------------------------------------------
t_section( 'Admin actions' );
t_reset_inventory();
$r = t5_book( array( 'payment_method' => 'bank_transfer' ) );
$b = t5_get( $r['data']['reference'] );
$p = Flexo_Booking_Payments::complete( $b['id'], 300, 'cash', '', array( 'note' => 'Paid at reception' ) );
t_eq( 'recorded', $p['result'], 'part payment recorded' );
t_eq( 'awaiting_payment', t5_get( $b['reference'] )['status'], 'not enough to confirm' );
Flexo_Booking_Payments::complete( $b['id'], 500, 'bank_transfer', '' );
$b = t5_get( $b['reference'] );
t_ok( 'confirmed' === $b['status'] && 800.0 === $b['amount_paid'], 'the rest arrives: confirmed' );
t_eq( 'Cash', Flexo_Booking_Payments::method_label( 'cash' ), 'method labels' );
$html = Flexo_Booking_Payments_Admin::badge( $b );
t_ok( false !== strpos( $html, 'Payment received' ), 'list badge shows the payment status' );
ob_start();
Flexo_Booking_Payments_Admin::render_booking_card( $b );
$card = ob_get_clean();
t_ok( false !== strpos( $card, 'Paid at reception' ) && false !== strpos( $card, 'Remaining balance' ) && false !== strpos( $card, 'Cash' ), 'booking page: payment history and balance' );
$lines = Flexo_Booking_Payments_Admin::calendar_lines( $b );
t_ok( in_array( 'Paid', wp_list_pluck( $lines, 0 ), true ), 'calendar shows what was paid' );

// ---------------------------------------------------------------------------------
t_section( 'Emails and settings screen' );
$types = Flexo_Booking_Emails::guest_types();
foreach ( array( 'awaiting_deposit', 'payment_reminder', 'payment_received', 'payment_failed', 'payment_cancelled' ) as $type ) {
	t_ok( isset( $types[ $type ] ) && empty( $types[ $type ]['reserved'] ), "template {$type} in use" );
}
t_ok( in_array( '{payment_instructions}', Flexo_Booking_Emails::placeholder_names(), true ), '{payment_instructions} placeholder' );
t_ok( array_key_exists( 'payments', Flexo_Booking_Settings::tabs() ), 'Payments settings tab' );
t5_features( array() );
t_ok( ! array_key_exists( 'payments', Flexo_Booking_Settings::tabs() ), 'no Payments tab when payments are off' );
t5_features( array( 'online_payment', 'deposit', 'bank_transfer' ) );
ob_start();
Flexo_Booking_Payments_Admin::render_settings( Flexo_Booking_Settings::all(), Flexo_Booking_Settings::OPTION );
$tab = ob_get_clean();
t_ok( false !== strpos( $tab, 'Test mode' ) && false !== strpos( $tab, 'Live' ), 'test and live clearly labelled' );
t_ok( false === strpos( $tab, 'sk_test_abc123' ) && false === strpos( $tab, T5_SECRET ), 'saved keys are never printed' );
t_ok( false !== strpos( $tab, '•••• c123' ), 'only the last characters are shown' );
t_ok( false !== strpos( $tab, rest_url( 'flexo-booking/v1/stripe-webhook' ) ), 'webhook address shown' );
t5_settings( array( 'booking_mode' => 'request' ) );
ob_start();
Flexo_Booking_Payments_Admin::render_settings( Flexo_Booking_Settings::all(), Flexo_Booking_Settings::OPTION );
$tab = ob_get_clean();
t_ok( false !== strpos( $tab, 'only work with Instant booking' ), 'request mode: card payments explained as paused' );
t5_settings( array( 'booking_mode' => 'instant' ) );

// ---------------------------------------------------------------------------------
t_section( 'Import / Export' );
$export = Flexo_Booking_Portability::export();
$json   = wp_json_encode( $export );
t_eq( 'deposit' === $export['settings']['payment_mode'] || 'full' === $export['settings']['payment_mode'], true, 'payment mode exported' );
t_ok( isset( $export['settings']['deposit_value'], $export['settings']['hold_minutes'], $export['settings']['bank_transfer_days'], $export['settings']['bank_reference'] ), 'deposit, hold and bank transfer rules exported' );
t_ok( false === strpos( $json, 'sk_test_abc123' ) && false === strpos( $json, T5_SECRET ) && false === strpos( $json, 'whsec_' ), 'no API keys or webhook secrets' );
t_ok( '' === $export['settings']['bank_iban'] && '' === $export['settings']['bank_beneficiary'] && false === strpos( $json, 'BNBG9661' ), 'no bank account' );
$export['settings']['payment_mode']  = 'deposit';
$export['settings']['deposit_value'] = 25;
Flexo_Booking_Portability::import( $export, array( 'settings' => true, 'rooms' => false ) );
t_eq( 25.0, (float) Flexo_Booking_Settings::get( 'deposit_value' ), 'payment settings imported' );
t_eq( 'BG80BNBG96611020345678', Flexo_Booking_Settings::get( 'bank_iban' ), 'this site keeps its own bank account' );
t_eq( 'sk_test_abc123', Flexo_Booking_Payments::secret( 'stripe_test_secret_key' ), 'and its own keys' );
t5_settings( array( 'payment_mode' => 'full', 'deposit_value' => 30, 'booking_mode' => 'request' ) );

// Leave the site as it was.
update_option( Flexo_Booking_Settings::OPTION, $t5_saved_settings );
update_option( Flexo_Booking_Features::ENABLED_OPTION, $t5_saved_features );
delete_option( Flexo_Booking_Payments::SECRETS_OPTION );
t_reset_inventory();

t_done();
