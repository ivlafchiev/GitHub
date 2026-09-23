<?php
require dirname( __DIR__ ) . '/lib.php';
t_reset_inventory();
foreach ( Flexo_Booking_Rooms::all( 'any' ) as $p ) { Flexo_Booking_Seasons::delete_for_room( $p->ID ); wp_delete_post( $p->ID, true ); }
foreach ( get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'any' ) ) as $p ) { wp_delete_post( $p->ID, true ); }
Flexo_Booking_Features::set_available( null );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'seasonal_pricing' ) );
$s = Flexo_Booking_Settings::defaults();
$s['terms_url'] = '/terms/';
update_option( Flexo_Booking_Settings::OPTION, $s );
$d = t_room( 'deluxe-double', 'Deluxe Double', array( 'price' => 100, 'weekend_price' => 150, 'capacity' => 2, 'units' => 2 ) );
wp_update_post( array( 'ID' => $d, 'post_excerpt' => 'Sea view, king-size bed' ) );
$f = t_room( 'family-suite', 'Family Suite', array( 'price' => 200, 'capacity' => 4, 'units' => 1, 'min_nights' => 2 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $d, 'name' => 'Low season', 'date_from' => t_day( 0 ), 'date_to' => t_day( 13 ), 'price' => 80 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $d, 'name' => 'High season', 'date_from' => t_day( 14 ), 'date_to' => t_day( 27 ), 'price' => 150, 'weekend_price' => 200, 'min_nights' => 3 ) );
Flexo_Booking_Closures::save( array( 'room_id' => 0, 'date_from' => t_day( 40 ), 'date_to' => t_day( 45 ), 'label' => '' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Booking', 'post_name' => 'booking', 'post_status' => 'publish', 'post_content' => '[flexo_booking title="Book your stay"]' ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Home', 'post_name' => 'home', 'post_status' => 'publish', 'post_content' => '[flexo_booking layout="search" booking_page="/booking/"]' ) );
// Elementor page: search bar with custom colour + full form preselecting a room + button with the dynamic tag.
$data = array( array( 'id' => 'c0c0c0c', 'elType' => 'container', 'settings' => array(), 'elements' => array(
	array( 'id' => 'e1e1e1e', 'elType' => 'widget', 'widgetType' => 'flexo-booking-form', 'settings' => array( 'layout' => 'search', 'booking_page' => '/booking/', 'title' => 'Find your room', 'color_primary' => '#b08d57' ), 'elements' => array() ),
	array( 'id' => 'f2f2f2f', 'elType' => 'widget', 'widgetType' => 'flexo-booking-form', 'settings' => array( 'room' => 'deluxe-double', 'button_text' => 'Check dates' ), 'elements' => array() ),
	array( 'id' => 'b3b3b3b', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Book now', '__dynamic__' => array( 'link' => '[elementor-tag id="aa11bb2" name="flexo-room-booking-link" settings="%7B%22room%22%3A%22family-suite%22%2C%22booking_page%22%3A%22%2Fbooking%2F%22%7D"]' ) ), 'elements' => array() ),
) ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Elementor Booking', 'post_name' => 'elementor-booking', 'post_status' => 'publish' ) );
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_template_type', 'wp-page' );
update_post_meta( $page, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
echo "seeded; d0=" . t_day( 0 ) . "\n";
