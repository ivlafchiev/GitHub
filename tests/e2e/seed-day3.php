<?php
/**
 * Seed for e2e/day3.js: children & ages, rate plans (from the ready-made
 * presets), tourist tax and promo codes. Prints D0 (the Monday of t_day(0)).
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
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'rate_plans' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'promo_codes' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendars' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendar_events' ) );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_flexo\\_rl\\_%' OR option_name LIKE '\\_transient\\_flexo\\_promo\\_%'" );
Flexo_Booking_Rate_Plans::flush_cache();

Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'guest_emails', 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ) );
$s                             = Flexo_Booking_Settings::defaults();
$s['terms_url']                = '';
$s['tourist_tax_amount']       = 1.5;
$s['tourist_tax_children']     = 'exempt';
$s['tourist_tax_exempt_under'] = 7;
$s['tourist_tax_collect']      = 'booking';
update_option( Flexo_Booking_Settings::OPTION, $s );

$family = t_room( 'garden-family', 'Garden Family Room', array( 'price' => 100, 'capacity' => 4, 'max_adults' => 2, 'units' => 2 ) );
$studio = t_room( 'simple-studio', 'Simple Studio', array( 'price' => 70, 'capacity' => 2, 'units' => 1 ) );
$double = t_room( 'plain-double', 'Plain Double', array( 'price' => 80, 'capacity' => 2, 'units' => 1 ) );
wp_update_post( array( 'ID' => $family, 'post_excerpt' => 'Garden view, two bedrooms' ) );

// The ready-made plans (all off), then switch on and price four of them.
Flexo_Booking_Rate_Plans::add_presets();
$plans = array();
foreach ( Flexo_Booking_Rate_Plans::all() as $plan ) {
	$plans[ $plan['preset'] ] = $plan;
}
foreach ( array( 'room_only', 'breakfast', 'half_board', 'non_refundable' ) as $key ) {
	Flexo_Booking_Rate_Plans::set_active( $plans[ $key ]['id'], true );
}
Flexo_Booking_Rate_Plans::set_room_assignments(
	$family,
	array(
		$plans['room_only']['id']      => null,
		$plans['breakfast']['id']      => null,
		$plans['half_board']['id']     => null,
		$plans['non_refundable']['id'] => null,
	)
);
Flexo_Booking_Rate_Plans::set_room_assignments( $studio, array( $plans['breakfast']['id'] => null ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $double, array() );

Flexo_Booking_Promo_Codes::save( array( 'code' => 'DIRECT10', 'discount_type' => 'percent', 'discount_value' => 10, 'active' => 1 ) );
Flexo_Booking_Promo_Codes::save( array( 'code' => 'OLD', 'discount_type' => 'percent', 'discount_value' => 10, 'active' => 1, 'book_to' => wp_date( 'Y-m-d', strtotime( '-3 days' ) ) ) );

wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Booking', 'post_name' => 'booking', 'post_status' => 'publish', 'post_content' => '[flexo_booking title="Book your stay"]' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Home', 'post_name' => 'home', 'post_status' => 'publish', 'post_content' => '[flexo_booking layout="search" booking_page="/booking/"]' ) );
echo 'D0=' . t_day( 0 ) . "\n";
