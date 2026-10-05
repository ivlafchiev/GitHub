<?php
/**
 * Seed for tests/e2e/day8.js (1.9.0 system pages): the Day 5 seed (rooms,
 * card payments against the local Stripe mock, deposit, bank transfer,
 * tracking), then a fresh-install page setup: no booking or thank-you
 * page, so the built-in Booking and Thank You pages are used.
 *
 *   STRIPE_MOCK=http://localhost:12111 STRIPE_MOCK_DIR=<dir> wp eval-file tests/e2e/seed-day8.php   (prints D0)
 */
require __DIR__ . '/seed-day5.php';

foreach ( array( 'booking', 'thank-you' ) as $flexo_path ) {
	$flexo_page = get_page_by_path( $flexo_path );
	if ( $flexo_page ) {
		wp_delete_post( $flexo_page->ID, true );
	}
}
$flexo_s                 = Flexo_Booking_Settings::all();
$flexo_s['booking_page'] = '';
$flexo_s['thank_you_url'] = '';
update_option( Flexo_Booking_Settings::OPTION, $flexo_s );
delete_option( Flexo_Booking_System_Pages::OPTION ); // Fresh install: built-in pages, separate Thank You page.
Flexo_Booking_Guest::forget_booking_page();
