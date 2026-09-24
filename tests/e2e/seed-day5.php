<?php
/**
 * Seed for e2e/day5.js: card payments against the local Stripe mock,
 * deposit, bank transfer, responsive checks. Prints D0.
 *
 * Needs the Stripe mock running (tests/stripe-mock/server.php) and, in the
 * environment, STRIPE_MOCK (its address, e.g. http://localhost:12111) and
 * STRIPE_MOCK_DIR (its state folder).
 */
require dirname( __DIR__ ) . '/lib.php';
global $wpdb;
t_reset_inventory();
foreach ( Flexo_Booking_Rooms::all( 'any' ) as $p ) {
	Flexo_Booking_Seasons::delete_for_room( $p->ID );
	wp_delete_post( $p->ID, true );
}
foreach ( get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'any' ) ) as $p ) {
	wp_delete_post( $p->ID, true );
}
foreach ( array( 'rate_plans', 'promo_codes', 'calendars', 'calendar_events', 'consents', 'invoices', 'email_log', 'payments', 'webhook_events' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_flexo\\_rl\\_%'" );
Flexo_Booking_Rate_Plans::flush_cache();

$secret = 'whsec_e2e_' . wp_generate_password( 12, false, false );
update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_e2e', 'stripe_test_webhook_secret' => $secret ) );
update_option( 'flexo_test_stripe_api', rtrim( getenv( 'STRIPE_MOCK' ), '/' ) . '/v1' );
$dir = getenv( 'STRIPE_MOCK_DIR' );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
file_put_contents( $dir . '/config.json', wp_json_encode( array( 'webhook_url' => rest_url( 'flexo-booking/v1/stripe-webhook' ), 'webhook_secret' => $secret ) ) );

Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'instant_booking', 'guest_emails', 'online_payment', 'deposit', 'bank_transfer', 'tracking', 'promo_codes', 'rate_plans' ) );
$s                       = Flexo_Booking_Settings::defaults();
$s['booking_mode']       = 'instant';
$s['terms_url']          = '';
$s['notification_email'] = 'desk@hotel.test';
$s['hotel_phone']        = '+359 52 111 222';
$s['payment_mode']       = 'deposit';
$s['deposit_type']       = 'percent';
$s['deposit_value']      = 30;
$s['stripe_mode']        = 'test';
$s['bank_beneficiary']   = 'Hotel Sunrise Ltd.';
$s['bank_iban']          = 'BG80BNBG96611020345678';
$s['bank_bic']           = 'BNBGBGSD';
$s['bank_name']          = 'Test Bank';
update_option( Flexo_Booking_Settings::OPTION, $s );

$sea    = t_room( 'sea-room', 'Sea View Double Room with a Very Long Name for Wrapping', array( 'price' => 200, 'capacity' => 2, 'units' => 3 ) );
$garden = t_room( 'garden-room', 'Garden Room', array( 'price' => 120, 'capacity' => 3, 'units' => 2 ) );
$bb     = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Breakfast Included', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 10, 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free cancellation up to 3 days before arrival.' ) );
$nr     = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Non-refundable', 'adjustment_type' => 'percent', 'adjustment_value' => -10, 'active' => 1, 'refundable' => 0 ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $garden, array( $bb => null, $nr => null ) );
Flexo_Booking_Promo_Codes::save( array( 'code' => 'SUMMER10', 'discount_type' => 'percent', 'discount_value' => 10, 'active' => 1 ) );

wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Booking', 'post_name' => 'booking', 'post_status' => 'publish', 'post_content' => '[flexo_booking title="Book your stay"]' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Thank you', 'post_name' => 'thank-you', 'post_status' => 'publish', 'post_content' => 'Thank you for your booking!' ) );
if ( file_exists( WP_CONTENT_DIR . '/mail.log' ) ) {
	unlink( WP_CONTENT_DIR . '/mail.log' );
}
echo 'D0=' . t_day( 0 ) . "\n";
