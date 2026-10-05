<?php
/**
 * Room page design (Settings → Room pages → Room page design): an
 * Elementor page chosen as the design of every room page. Needs Elementor
 * and the site served over HTTP (BASE); run with a classic theme (e.g.
 * Hello Elementor) and again with a block theme.
 *
 *   BASE=http://localhost:8092 wp eval-file tests/test-room-design.php
 */
require __DIR__ . '/lib.php';

if ( ! did_action( 'elementor/loaded' ) ) {
	echo "Elementor is not active – skipped.\n";
	return;
}

t_reset_inventory();
$base       = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();
$rd_saved   = get_option( Flexo_Booking_Settings::OPTION );
$rd_created = array();
register_shutdown_function(
	static function () use ( $rd_saved, &$rd_created ) {
		if ( getenv( 'RD_KEEP' ) ) {
			return;
		}
		update_option( Flexo_Booking_Settings::OPTION, $rd_saved );
		foreach ( $rd_created as $id ) {
			wp_delete_post( $id, true );
		}
	}
);

$tag = static function ( $name, array $settings = array() ) {
	return '[elementor-tag id="' . substr( md5( $name . wp_json_encode( $settings ) ), 0, 7 ) . '" name="' . $name . '" settings="' . rawurlencode( wp_json_encode( (object) $settings ) ) . '"]';
};
$widget = static function ( $id, $type, array $settings, array $dynamic = array() ) {
	if ( $dynamic ) {
		$settings['__dynamic__'] = $dynamic;
	}
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
};
$elementor_post = static function ( array $args, array $data, $template_type = 'wp-page' ) use ( &$rd_created ) {
	$id           = wp_insert_post( $args );
	$rd_created[] = $id;
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', $template_type );
	update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	return $id;
};
$photo = static function ( $name, $rgb ) use ( &$rd_created ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$im = imagecreatetruecolor( 800, 500 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	$tmp = wp_tempnam( $name );
	imagejpeg( $im, $tmp, 80 );
	$id           = media_handle_sideload( array( 'name' => $name . '.jpg', 'tmp_name' => $tmp ), 0 );
	$rd_created[] = $id;
	return $id;
};
$get = static function ( $path ) use ( $base ) {
	$r = wp_remote_get( $base . $path, array( 'timeout' => 30, 'redirection' => 0 ) );
	return is_wp_error( $r ) ? array( 0, '' ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
};

// Two rooms with their own data.
$sea = t_room( 'rd-sea-view', 'RD Sea View Double', array( 'price' => 270, 'capacity' => 3, 'units' => 1, 'size' => 40 ) );
wp_update_post( array( 'ID' => $sea, 'post_content' => 'Sea view description text.' ) );
set_post_thumbnail( $sea, $photo( 'rd-sea-photo', array( 30, 90, 160 ) ) );
Flexo_Booking_Room_Editor::save_amenities( $sea, array( array( 'key' => '', 'label' => 'Sea-facing terrace', 'icon' => 'sun' ) ) );
$garden = t_room( 'rd-garden', 'RD Garden Double', array( 'price' => 190, 'capacity' => 2, 'units' => 1, 'size' => 28 ) );
wp_update_post( array( 'ID' => $garden, 'post_content' => 'Garden description text.' ) );
set_post_thumbnail( $garden, $photo( 'rd-garden-photo', array( 60, 140, 60 ) ) );
Flexo_Booking_Room_Editor::save_amenities( $garden, array( array( 'key' => '', 'label' => 'Private garden patio', 'icon' => 'leaf' ) ) );
$rd_created[] = $sea;
$rd_created[] = $garden;

// The design: a page made for one room (same address as the Sea View room).
$design_data = array(
	array(
		'id'       => 'rd00001',
		'elType'   => 'container',
		'settings' => array( 'background_background' => 'classic', '__dynamic__' => array( 'background_image' => $tag( 'flexo-room-image' ) ) ),
		'elements' => array(
			$widget( 'rd00002', 'heading', array( 'title' => 'Static title' ), array( 'title' => $tag( 'flexo-room-name' ) ) ),
			$widget( 'rd00003', 'heading', array( 'title' => '€0' ), array( 'title' => $tag( 'flexo-room-price', array( 'price' => 'from' ) ) ) ),
			$widget( 'rd00004', 'text-editor', array( 'editor' => '<p>Hotel rules stay the same for every room.</p>' ) ),
			$widget( 'rd00005', 'text-editor', array( 'editor' => 'x' ), array( 'editor' => $tag( 'flexo-room-description' ) ) ),
			$widget( 'rd00006', 'flexo-room-amenities', array() ),
		),
	),
);
$design = $elementor_post( array( 'post_type' => 'page', 'post_title' => 'Room design', 'post_name' => 'rd-sea-view', 'post_status' => 'publish' ), $design_data );

t_section( 'Choosing the design' );
t_ok( Flexo_Booking_Room_Design::is_valid( $design ), 'a page built with Elementor can be the design' );
$plain = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Plain page', 'post_status' => 'publish' ) );
$rd_created[] = $plain;
t_ok( ! Flexo_Booking_Room_Design::is_valid( $plain ), 'a page not built with Elementor cannot' );
$header = $elementor_post( array( 'post_type' => 'elementor_library', 'post_title' => 'RD Header', 'post_status' => 'publish' ), array(), 'header' );
t_ok( ! Flexo_Booking_Room_Design::is_valid( $header ), 'a header template cannot' );
$choices = Flexo_Booking_Room_Design::choices();
t_ok( in_array( $design, wp_list_pluck( $choices['pages'], 'ID' ), true ) && ! in_array( $plain, wp_list_pluck( $choices['pages'], 'ID' ), true ), 'settings list Elementor pages only' );
t_ok( ! in_array( $header, wp_list_pluck( $choices['templates'], 'ID' ), true ), 'settings leave header templates out' );
$s = Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'room_design' => $plain ) ) );
t_eq( 0, $s['room_design'], 'saving a page that is not built with Elementor keeps automatic' );
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'room_design' => $design ) ) ) );
t_eq( $design, Flexo_Booking_Room_Design::id(), 'design saved' );

t_section( 'Header template is not a room template (bug in 1.8.0)' );
update_post_meta( $header, '_elementor_conditions', array( 'include/general' ) );
t_ok( null === Flexo_Booking_Elementor_Templates::room_template(), 'a header shown on the entire site is not taken for the room template' );
$jet_single = $elementor_post( array( 'post_type' => 'elementor_library', 'post_title' => 'RD Jet Single', 'post_status' => 'publish' ), array(), 'single-post' );
update_post_meta( $jet_single, '_elementor_conditions', array( 'include/singular/rooms' ) );
$others = wp_list_pluck( wp_list_pluck( Flexo_Booking_Elementor_Templates::other_room_templates(), 'post' ), 'ID' );
t_ok( in_array( $jet_single, $others, true ), 'a single template set for JetEngine "rooms" is pointed out' );
t_ok( Flexo_Booking_Room_Design::is_valid( $jet_single ), '…and can be chosen as the design' );
$tb = $elementor_post( array( 'post_type' => 'elementor_library', 'post_title' => 'RD Room Single', 'post_status' => 'publish' ), array(), 'single-post' );
update_post_meta( $tb, '_elementor_conditions', array( 'include/singular/' . Flexo_Booking_Rooms::POST_TYPE ) );
t_eq( $tb, Flexo_Booking_Elementor_Templates::room_template() ? Flexo_Booking_Elementor_Templates::room_template()->ID : 0, 'a single template for Rooms is found' );
wp_delete_post( $tb, true );

t_section( 'What the design is connected to' );
$scan = Flexo_Booking_Room_Design::scan( $design );
t_eq( 5, $scan['room'], 'room tags and widgets counted' );
t_eq( array(), $scan['jet'], 'no JetEngine fields' );
$jet_data = array( $widget( 'rd00010', 'heading', array( 'title' => '€' ), array( 'title' => $tag( 'jet-post-custom-field', array( 'meta_field' => 'price_per_night', 'before' => '€' ) ) ) ) );
update_post_meta( $jet_single, '_elementor_data', wp_slash( wp_json_encode( $jet_data ) ) );
t_eq( array( 'price_per_night' ), Flexo_Booking_Room_Design::scan( $jet_single )['jet'], 'JetEngine fields still used are named' );

t_section( 'Room pages with the design (HTTP)' );
list( $code, $html ) = $get( '/rooms/rd-sea-view/' );
t_eq( 200, $code, 'Sea View room page' );
t_ok( false !== strpos( $html, 'RD Sea View Double' ) && false !== strpos( $html, 'Sea view description text.' ) && false !== strpos( $html, 'Sea-facing terrace' ), 'Sea View: its own name, description and amenities' );
t_ok( false !== strpos( $html, '270' ), 'Sea View: its own price' );
t_ok( false !== strpos( $html, 'Hotel rules stay the same for every room.' ), 'static parts of the design kept' );
t_ok( 1 === preg_match( '/<body[^>]*class="[^"]*elementor-page-' . $design . '[\s"]/', $html ), 'design\'s page classes on the body (page settings, full width in Hello)' );
t_ok( false === strpos( $html, 'flexo-room-page__layout' ), 'the plugin\'s own room page is not shown' );
t_ok( false !== strpos( $html, 'elementor-' . $design ) && false !== strpos( $html, 'data-elementor-id="' . $design . '"' ), 'the design is printed by Elementor' );
$bg = static function ( $html, $room ) {
	return 1 === preg_match( '/elementor-element-rd00001[^{]*\{background-image:url\("[^"]*' . preg_quote( wp_basename( (string) get_attached_file( get_post_thumbnail_id( $room ) ) ), '/' ) . '"\)/', $html );
};
t_ok( $bg( $html, $sea ), 'background from the room\'s own photo (dynamic CSS)' );
t_ok( false !== strpos( $html, '<header' ) || false !== strpos( $html, 'wp-block-template-part' ) || false !== strpos( $html, 'site-header' ), 'theme header kept' );
list( $code, $html2 ) = $get( '/rooms/rd-garden/' );
t_ok( 200 === $code && false !== strpos( $html2, 'RD Garden Double' ) && false !== strpos( $html2, 'Garden description text.' ) && false !== strpos( $html2, 'Private garden patio' ) && false !== strpos( $html2, '190' ), 'Garden: its own name, description, amenities and price' );
t_ok( false === strpos( $html2, 'RD Sea View Double' ) && false === strpos( $html2, 'Sea-facing terrace' ), 'Garden: nothing from the Sea View room' );
t_ok( $bg( $html2, $garden ) && ! $bg( $html2, $sea ), 'Garden: background from its own photo' );
update_post_meta( $sea, '_flexo_price', 300 );
list( , $html ) = $get( '/rooms/rd-sea-view/' );
t_ok( false !== strpos( $html, '300' ), 'a price change in the plugin shows on the page' );

t_section( 'The design page itself' );
list( $code, $own ) = $get( '/rd-sea-view/' );
t_ok( 200 === $code && false !== strpos( $own, 'RD Sea View Double' ), 'opened on its own it shows the room with the same address' );
t_ok( 1 === preg_match( '/<link rel="canonical" href="[^"]*\/rooms\/rd-sea-view\/"/', $own ), 'and points search engines to the room page' );

t_section( 'Back to automatic' );
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'room_design' => 0 ) ) ) );
list( $code, $html ) = $get( '/rooms/rd-sea-view/' );
t_ok( 200 === $code && false !== strpos( $html, 'flexo-room-page' ) && false === strpos( $html, 'data-elementor-id="' . $design . '"' ), 'without a design the plugin\'s room page is back' );
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'room_design' => $design ) ) ) );
wp_trash_post( $design );
t_eq( 0, Flexo_Booking_Room_Design::id(), 'a design in the bin is ignored' );
wp_untrash_post( $design );

t_section( 'Import / Export' );
$file = Flexo_Booking_Portability::export();
t_eq( 0, $file['settings']['room_design'], 'the design (an ID on this site) is not exported' );
$file['settings']['room_design'] = 999999;
Flexo_Booking_Portability::import( $file, array( 'settings' => true, 'rooms' => false ) );
t_eq( $design, absint( Flexo_Booking_Settings::get( 'room_design' ) ), 'importing settings keeps this site\'s design' );

t_reset_inventory();
t_done();
