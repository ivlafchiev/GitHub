<?php
/**
 * Seed for tests/e2e/day6.js: two rooms (one with two rates), a closed
 * period, the guest booking page, users for each role and the booking page.
 *
 *   wp eval-file tests/e2e/seed-day6.php     (prints D0=YYYY-MM-DD)
 */
require dirname( __DIR__ ) . '/lib.php';
global $wpdb;

t_reset_inventory();
foreach ( array( 'email_log', 'booking_log', 'payments', 'rate_plans', 'promo_codes' ) as $table ) {
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
}
foreach ( get_posts( array( 'post_type' => 'flexo_room', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
Flexo_Booking_Rate_Plans::flush_cache();
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'instant_booking', 'booking_request', 'guest_emails', 'rate_plans', 'seasonal_pricing', 'guest_booking_page', 'custom_appearance', 'privacy_consent', 'online_payment', 'bank_transfer' ) );
delete_option( Flexo_Booking_Payments::SECRETS_OPTION );
delete_option( 'flexo_booking_wizard' );
update_option(
	Flexo_Booking_Settings::OPTION,
	Flexo_Booking_Settings::sanitize(
		array_merge(
			Flexo_Booking_Settings::defaults(),
			array(
				'booking_mode'        => 'instant',
				'currency'            => 'EUR',
				'hotel_phone'         => '+359 52 111 222',
				'hotel_email'         => 'desk@sunrise.test',
				'hotel_address'       => "12 Sea Street\nVarna 9000",
				'notification_email'  => 'reception@sunrise.test',
				'booking_page'        => '/booking/',
				'thank_you_url'       => '',
				'terms_url'           => '',
				'payment_mode'        => 'full',
				'bank_beneficiary'    => 'Hotel Sunrise Ltd.',
				'bank_iban'           => 'BG80BNBG96611020345678',
				'bank_bic'            => 'BNBGBGSD',
				'appearance_mode'     => 'match',
				'privacy_consent_required' => 1,
			)
		)
	)
);
$double = t_room( 'sea-double', 'Sea Double', array( 'price' => 100, 'capacity' => 2, 'units' => 1, 'size' => 24, 'beds' => '1 double bed', 'amenities' => array( 'wifi', 'air_conditioning', 'balcony', 'sea_view' ) ) );
$family = t_room( 'garden-family', 'Garden Family', array( 'price' => 150, 'capacity' => 4, 'units' => 1, 'size' => 36, 'beds' => '1 double + 2 single', 'amenities' => array( 'wifi', 'kitchen', 'tv', 'parking', 'pets', 'safe', 'heating' ) ) );
$bb = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Bed & Breakfast', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 10, 'meals' => 'breakfast', 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free cancellation up to 7 days before arrival.' ) );
$nr = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Saver', 'adjustment_type' => 'percent', 'adjustment_value' => -10, 'meals' => 'none', 'active' => 1, 'refundable' => 0 ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $family, array( $bb => null, $nr => null ) );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => t_day( 40 ), 'date_to' => t_day( 41 ), 'reason' => 'Private event' ) );

// Booking page with the form.
$page = get_page_by_path( 'booking' );
if ( ! $page ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Booking', 'post_name' => 'booking', 'post_content' => '[flexo_booking title="Book your stay"]' ) );
}
Flexo_Booking_Guest::forget_booking_page();

// Elementor page: one form with its own button colour, one without (Appearance priority).
if ( defined( 'ELEMENTOR_VERSION' ) && ! get_page_by_path( 'elementor-form' ) ) {
	$data = array( array( 'id' => 'd6c0c0c', 'elType' => 'container', 'settings' => array(), 'elements' => array(
		array( 'id' => 'd6e1e1e', 'elType' => 'widget', 'widgetType' => 'flexo-booking-form', 'settings' => array( 'layout' => 'search', 'booking_page' => '/booking/', 'color_primary' => '#b08d57' ), 'elements' => array() ),
		array( 'id' => 'd6f2f2f', 'elType' => 'widget', 'widgetType' => 'flexo-booking-form', 'settings' => array( 'layout' => 'search', 'booking_page' => '/booking/' ), 'elements' => array() ),
	) ) );
	$epage = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Elementor Form', 'post_name' => 'elementor-form', 'post_status' => 'publish' ) );
	update_post_meta( $epage, '_elementor_edit_mode', 'builder' );
	update_post_meta( $epage, '_elementor_template_type', 'wp-page' );
	update_post_meta( $epage, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $epage, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
}

foreach ( array( 'staff' => 'hotel_staff', 'manager' => 'hotel_manager' ) as $login => $role ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		$u->set_role( $role );
	} else {
		wp_insert_user( array( 'user_login' => $login, 'user_pass' => $login, 'user_email' => $login . '@sunrise.test', 'role' => $role ) );
	}
}
// Per-visitor limits (bookings, enquiries, requests) start fresh.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%flexo\_rl\_%' OR option_name LIKE '\_transient\_timeout\_%flexo\_rl\_%'" );
file_put_contents( WP_CONTENT_DIR . '/mail.log', '' );
echo 'D0=' . t_day( 0 ) . "\n";
