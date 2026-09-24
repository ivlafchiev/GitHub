<?php
/**
 * Seed for e2e/day4.js: privacy consent, invoice request, tracking, emails.
 * Prints D0 (the Monday of t_day(0)).
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
foreach ( array( 'rate_plans', 'promo_codes', 'calendars', 'calendar_events', 'consents', 'invoices', 'email_log' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_flexo\\_rl\\_%'" );
Flexo_Booking_Rate_Plans::flush_cache();
delete_user_meta( 1, 'flexo_booking_smtp_notice' );

Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'booking_request', 'guest_emails', 'rate_plans', 'privacy_consent', 'invoice_request', 'tracking' ) );
$s                       = Flexo_Booking_Settings::defaults();
$s['terms_url']          = '';
$s['notification_email'] = 'desk@hotel.test';
$s['hotel_phone']        = '+359 52 111 222';
update_option( Flexo_Booking_Settings::OPTION, $s );

$lake = t_room( 'lake-room', 'Lake Room', array( 'price' => 80, 'capacity' => 2, 'units' => 5 ) );
$bb   = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Breakfast Included', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 10, 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free cancellation up to 3 days before arrival.' ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $lake, array( $bb => null ) );

$privacy = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Privacy Policy', 'post_name' => 'privacy-policy', 'post_status' => 'publish', 'post_content' => 'Our privacy policy.' ) );
update_option( 'wp_page_for_privacy_policy', $privacy );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Booking', 'post_name' => 'booking', 'post_status' => 'publish', 'post_content' => '[flexo_booking title="Book your stay"]' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Thank you', 'post_name' => 'thank-you', 'post_status' => 'publish', 'post_content' => 'Thank you for your booking!' ) );
if ( file_exists( WP_CONTENT_DIR . '/mail.log' ) ) {
	unlink( WP_CONTENT_DIR . '/mail.log' );
}
echo 'D0=' . t_day( 0 ) . "\n";
