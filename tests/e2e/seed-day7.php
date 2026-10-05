<?php
/**
 * Seed for tests/e2e/day7.js: the Day 6 seed (booking page, users,
 * settings) plus a full room page – photos, type, amenities, details,
 * a weekend price – a hidden room and, with Elementor, a page with
 * availability tags.
 *
 *   wp eval-file tests/e2e/seed-day7.php     (prints D0=YYYY-MM-DD)
 */
require __DIR__ . '/seed-day6.php';

// Photos drawn with GD (no files in the repository).
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$photos = array();
foreach ( array( array( 70, 130, 170 ), array( 200, 170, 120 ), array( 90, 150, 110 ), array( 160, 110, 130 ) ) as $i => $rgb ) {
	$im = imagecreatetruecolor( 1200, 800 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	imagefilledrectangle( $im, 100, 500, 1100, 700, imagecolorallocate( $im, 240, 240, 235 ) );
	$tmp = wp_tempnam( 'd7-photo-' . $i );
	imagejpeg( $im, $tmp, 80 );
	$photos[] = media_handle_sideload( array( 'name' => 'd7-photo-' . $i . '.jpg', 'tmp_name' => $tmp ), 0 );
}

$suite = wp_insert_post(
	array(
		'post_type'    => 'flexo_room',
		'post_title'   => 'Deluxe Sea View Suite',
		'post_name'    => 'deluxe-sea-view',
		'post_status'  => 'publish',
		'post_excerpt' => 'Bright suite with a private terrace and sweeping views of the bay.',
		'post_content' => "Wake up to the sound of the sea. A king-size bed, a separate lounge and a private terrace facing the bay.\n\nThe bathroom has a rain shower and a deep bathtub.",
	)
);
Flexo_Booking_Rooms::save_meta_values( $suite, array( '_flexo_price' => 180, '_flexo_weekend_price' => 210, '_flexo_capacity' => 4, '_flexo_max_adults' => 2, '_flexo_units' => 1, '_flexo_size' => 42, '_flexo_beds' => '1 king-size bed + sofa bed', '_flexo_min_nights' => 2 ) );
update_post_meta( $suite, '_flexo_view', 'Sea view' );
set_post_thumbnail( $suite, $photos[0] );
update_post_meta( $suite, Flexo_Booking_Room_Content::GALLERY, array_slice( $photos, 1 ) );
Flexo_Booking_Room_Editor::save_amenities( $suite, array( array( 'key' => 'sea_view' ), array( 'key' => 'wifi' ), array( 'key' => 'air_conditioning' ), array( 'key' => '', 'label' => 'Rain shower with a view', 'icon' => 'shower' ), array( 'key' => 'minibar' ), array( 'key' => 'terrace' ) ) );
update_post_meta( $suite, Flexo_Booking_Room_Content::DETAILS, array( array( 'icon' => 'layers', 'label' => 'Floor', 'value' => '3rd, with lift' ), array( 'icon' => 'umbrella', 'label' => 'Distance to the beach', 'value' => '150 m' ) ) );
if ( ! term_exists( 'Suite', Flexo_Booking_Room_Content::TAXONOMY ) ) {
	wp_insert_term( 'Suite', Flexo_Booking_Room_Content::TAXONOMY );
}
wp_set_object_terms( $suite, array( 'Suite' ), Flexo_Booking_Room_Content::TAXONOMY );

$hidden = t_room( 'secret-loft', 'Secret Loft', array( 'price' => 90, 'capacity' => 2, 'units' => 1 ) );
update_post_meta( $hidden, Flexo_Booking_Room_Content::HIDDEN, '1' );

$s                       = Flexo_Booking_Settings::all();
$s['room_price_display'] = 'from';
$s['room_sticky_bar']    = 1;
$s['rooms_page']         = '';
update_option( Flexo_Booking_Settings::OPTION, $s );
Flexo_Booking_Room_Prices::invalidate_all();

// Availability tags (Elementor): one free room, one sold out for the test dates.
if ( defined( 'ELEMENTOR_VERSION' ) ) {
	$old = get_page_by_path( 'd7-availability' );
	if ( $old ) {
		wp_delete_post( $old->ID, true );
	}
	$tag  = static function ( $id, $slug ) {
		return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => 'x', '__dynamic__' => array( 'editor' => '[elementor-tag id="' . $id . '" name="flexo-room-availability" settings="' . rawurlencode( wp_json_encode( array( 'room' => $slug, 'show_total' => 'yes' ) ) ) . '"]' ) ), 'elements' => array() );
	};
	$data = array( array( 'id' => 'd7c0001', 'elType' => 'container', 'settings' => array(), 'elements' => array( $tag( 'd7a0001', 'deluxe-sea-view' ), $tag( 'd7a0002', 'sea-double' ) ) ) );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'D7 availability', 'post_name' => 'd7-availability', 'post_status' => 'publish' ) );
	update_post_meta( $page, '_elementor_edit_mode', 'builder' );
	update_post_meta( $page, '_elementor_template_type', 'wp-page' );
	update_post_meta( $page, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
}

// Sea Double is sold out on days 20–22.
Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'sea-double', 'check_in' => t_day( 20 ), 'check_out' => t_day( 22 ), 'adults' => 2, 'privacy_consent' => 1 ) ) );
update_option( 'flexo_booking_flush_rewrite', 1 );
