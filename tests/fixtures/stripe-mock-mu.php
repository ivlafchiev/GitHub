<?php
/**
 * Test sites only: send Stripe API calls to the local Stripe mock
 * (tests/stripe-mock/server.php) when the option flexo_test_stripe_api is set.
 */
add_filter( 'flexo_booking_stripe_api_base', function ( $base ) {
	$mock = get_option( 'flexo_test_stripe_api' );
	return $mock ? $mock : $base;
} );
