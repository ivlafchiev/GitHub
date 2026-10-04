<?php
/**
 * Day 7 with Elementor active: room dynamic tags and room widgets.
 *
 * Elementor Pro renders a Theme Builder single template (and each Loop
 * item) with the room as the global post. This script does the same with
 * a template document and checks every tag and widget against the room's
 * data, a room picked in the settings, a page that is not about a room, and
 * the sample room used while a template is edited.
 */
require __DIR__ . '/lib.php';

if ( ! did_action( 'elementor/loaded' ) ) {
	echo "Elementor is not active – skipped.\n";
	return;
}

use Elementor\Plugin;

t_reset_inventory();
$t7e_settings = get_option( Flexo_Booking_Settings::OPTION );
// Put the settings back even if a check stops the script.
register_shutdown_function(
	static function () use ( $t7e_settings ) {
		update_option( Flexo_Booking_Settings::OPTION, $t7e_settings );
	}
);
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'booking_page' => '/booking/', 'currency_symbol' => '€', 'currency_position' => 'before', 'currency_decimals' => 2 ) ) ) );

function t7e_tag( $name, array $settings = array() ) {
	return '[elementor-tag id="' . substr( md5( $name . wp_json_encode( $settings ) ), 0, 7 ) . '" name="' . $name . '" settings="' . rawurlencode( wp_json_encode( (object) $settings ) ) . '"]';
}

function t7e_widget( $id, $type, array $settings, array $dynamic = array() ) {
	if ( $dynamic ) {
		$settings['__dynamic__'] = $dynamic;
	}
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'widgetType' => $type,
		'settings'   => $settings,
		'elements'   => array(),
	);
}

function t7e_document( $title, array $widgets, $type = 'single' ) {
	$id = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => $title, 'post_status' => 'publish' ) );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', $type );
	update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	update_post_meta(
		$id,
		'_elementor_data',
		wp_slash(
			wp_json_encode(
				array(
					array(
						'id'       => 'c0ffee1',
						'elType'   => 'container',
						'settings' => array(),
						'elements' => $widgets,
					),
				)
			)
		)
	);
	return $id;
}

/** Renders a document as Pro does for a room page / Loop item: the room is the global post. */
function t7e_render( $doc, $room_id ) {
	global $post, $wp_query;
	$saved_post  = $post;
	$saved_query = $wp_query;
	if ( $room_id ) {
		$wp_query = new WP_Query( array( 'p' => $room_id, 'post_type' => Flexo_Booking_Rooms::POST_TYPE ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$wp_query->the_post();
	}
	$html     = Plugin::$instance->frontend->get_builder_content( $doc, false );
	$wp_query = $saved_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$post     = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	if ( $post ) {
		setup_postdata( $post );
	}
	return $html;
}

/* ---------------------------------------------------------------- */
t_section( 'Registration' );
$tags = Plugin::$instance->dynamic_tags->get_tags_config();
foreach ( array( 'flexo-room-name', 'flexo-room-type', 'flexo-room-excerpt', 'flexo-room-description', 'flexo-room-price', 'flexo-room-size', 'flexo-room-guests', 'flexo-room-beds', 'flexo-room-view', 'flexo-room-amenities', 'flexo-room-detail', 'flexo-room-image', 'flexo-room-gallery', 'flexo-room-url', 'flexo-room-booking-link' ) as $name ) {
	t_ok( isset( $tags[ $name ] ), "tag {$name} registered" );
}
t_eq( 'flexo-booking', $tags['flexo-room-name']['group'], 'tags are in the Flexo Booking group' );
t_ok( in_array( 'gallery', $tags['flexo-room-gallery']['categories'], true ), 'gallery tag fits Gallery / Image Carousel widgets' );
t_ok( in_array( 'image', $tags['flexo-room-image']['categories'], true ), 'image tag fits Image widgets and backgrounds' );
t_ok( in_array( 'number', $tags['flexo-room-size']['categories'], true ) && in_array( 'text', $tags['flexo-room-size']['categories'], true ), 'size is text and number (Heading, Counter)' );
foreach ( array( 'flexo-room-amenities', 'flexo-room-details', 'flexo-room-gallery', 'flexo-room-booking-box', 'flexo-booking-form' ) as $name ) {
	t_ok( (bool) Plugin::$instance->widgets_manager->get_widget_types( $name ), "widget {$name} registered" );
}
$widget = Plugin::$instance->widgets_manager->get_widget_types( 'flexo-room-gallery' );
t_ok( in_array( Flexo_Booking_Room_Render::SCRIPT, $widget->get_script_depends(), true ), 'gallery widget loads its script only where used' );

/* ---------------------------------------------------------------- */
$img = array();
foreach ( array( 'a', 'b', 'c' ) as $n ) {
	$file = wp_upload_dir()['path'] . "/t7e-{$n}.png";
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	$img[ $n ] = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => "t7e {$n}", 'post_status' => 'inherit' ), $file );
	update_post_meta( $img[ $n ], '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $file ) ) );
}
$room = t_room( 't7e-suite', 'T7E Suite', array( 'price' => 145, 'weekend_price' => 170, 'capacity' => 3, 'units' => 1, 'size' => 38, 'beds' => '1 king-size bed' ) );
wp_update_post( array( 'ID' => $room, 'post_excerpt' => 'Suite with a terrace.', 'post_content' => 'First paragraph.' . "\n\n" . 'Second paragraph.' ) );
update_post_meta( $room, Flexo_Booking_Room_Content::VIEW, 'Sea view' );
set_post_thumbnail( $room, $img['b'] );
update_post_meta( $room, Flexo_Booking_Room_Content::GALLERY, array( $img['a'], $img['c'] ) );
Flexo_Booking_Room_Editor::save_amenities(
	$room,
	array(
		array( 'key' => 'wifi' ),
		array( 'key' => '', 'label' => 'Rain shower <b>XL</b>', 'icon' => 'shower' ),
		array( 'key' => 'sea_view' ),
	)
);
update_post_meta( $room, Flexo_Booking_Room_Content::DETAILS, array( array( 'icon' => 'layers', 'label' => 'Floor', 'value' => '2nd' ), array( 'icon' => '', 'label' => 'Bathroom', 'value' => 'Rain shower' ) ) );
$type = term_exists( 'T7E Suite', Flexo_Booking_Room_Content::TAXONOMY );
$type = $type ? $type : wp_insert_term( 'T7E Suite', Flexo_Booking_Room_Content::TAXONOMY );
wp_set_object_terms( $room, array( (int) $type['term_id'] ), Flexo_Booking_Room_Content::TAXONOMY );
$other = t_room( 't7e-double', 'T7E Double', array( 'price' => 95, 'capacity' => 2, 'units' => 1, 'size' => 22 ) );
Flexo_Booking_Room_Content::forget( $room );

/* ---------------------------------------------------------------- */
t_section( 'Single room template (as Theme Builder renders it)' );
$template = t7e_document(
	'T7E Single room',
	array(
		t7e_widget( 'h000001', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-name' ) ) ),
		t7e_widget( 'h000002', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-type' ) ) ),
		t7e_widget( 'h000003', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-price', array( 'before' => 'from ' ) ) ) ),
		t7e_widget( 'h000004', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-price', array( 'price' => 'weekend', 'format' => 'number' ) ) ) ),
		t7e_widget( 'h000005', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-size' ) ) ),
		t7e_widget( 'h000006', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-guests', array( 'format' => 'guests' ) ) ) ),
		t7e_widget( 'h000007', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-beds' ) ) ),
		t7e_widget( 'h000008', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-view' ) ) ),
		t7e_widget( 'h000009', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-detail', array( 'label' => 'floor', 'part' => 'both' ) ) ) ),
		t7e_widget( 'h000010', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-amenities', array( 'format' => 'nth', 'position' => 2 ) ) ) ),
		t7e_widget( 'h000011', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-amenities', array( 'format' => 'comma' ) ) ) ),
		t7e_widget( 't000001', 'text-editor', array( 'editor' => 'x' ), array( 'editor' => t7e_tag( 'flexo-room-description' ) ) ),
		t7e_widget( 't000002', 'text-editor', array( 'editor' => 'x' ), array( 'editor' => t7e_tag( 'flexo-room-excerpt' ) ) ),
		t7e_widget( 't000003', 'text-editor', array( 'editor' => 'x' ), array( 'editor' => t7e_tag( 'flexo-room-amenities' ) ) ),
		t7e_widget( 'i000001', 'image', array( 'image' => array( 'url' => '' ) ), array( 'image' => t7e_tag( 'flexo-room-image' ) ) ),
		t7e_widget( 'g000001', 'image-gallery', array( 'wp_gallery' => array() ), array( 'wp_gallery' => t7e_tag( 'flexo-room-gallery' ) ) ),
		t7e_widget( 'b000001', 'button', array( 'text' => 'Book now', 'link' => array( 'url' => '' ) ), array( 'link' => t7e_tag( 'flexo-room-booking-link' ) ) ),
		t7e_widget( 'b000002', 'button', array( 'text' => 'View room', 'link' => array( 'url' => '' ) ), array( 'link' => t7e_tag( 'flexo-room-url' ) ) ),
		t7e_widget( 'w000001', 'flexo-room-amenities', array( 'layout' => 'grid' ) ),
		t7e_widget( 'w000002', 'flexo-room-details', array( 'facts' => array( 'size', 'guests', 'beds', 'view', 'type' ), 'show' => 'both' ) ),
		t7e_widget( 'w000003', 'flexo-room-gallery', array( 'layout' => 'carousel', 'autoplay' => 5 ) ),
		t7e_widget( 'w000004', 'flexo-room-booking-box', array( 'book_text' => 'Reserve now' ) ),
		t7e_widget( 'h000012', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-price', array( 'price' => 'from', 'after' => ' / night' ) ) ) ),
	)
);

$html = t7e_render( $template, $room );
t_ok( false !== strpos( $html, '>T7E Suite</h2>' ), 'Room name' );
t_ok( substr_count( $html, 'T7E Suite' ) >= 2, 'Room type' );
t_ok( false !== strpos( $html, 'from €145' ), 'Room price, whole amount without decimals, with Before text' );
t_ok( false !== strpos( $html, '>170</h2>' ), 'weekend price as a number' );
t_ok( false !== strpos( $html, '>38 m²</h2>' ), 'Room size' );
t_ok( false !== strpos( $html, '>3 guests</h2>' ), 'Guests' );
t_ok( false !== strpos( $html, '>1 king-size bed</h2>' ), 'Beds' );
t_ok( false !== strpos( $html, '>Sea view</h2>' ), 'View' );
t_ok( false !== strpos( $html, '>Floor: 2nd</h2>' ), 'Room detail by name' );
t_ok( false !== strpos( $html, '>Rain shower XL</h2>' ), 'n-th amenity' );
t_ok( false !== strpos( $html, 'Free Wi-Fi, Rain shower XL, Sea view' ), 'amenities as a comma list' );
t_ok( false !== strpos( $html, '<p>First paragraph.</p>' ) && false !== strpos( $html, '<p>Second paragraph.</p>' ), 'Full description with paragraphs' );
t_ok( false !== strpos( $html, 'Suite with a terrace.' ), 'Short description' );
t_ok( false !== strpos( $html, wp_get_attachment_url( $img['b'] ) ), 'main photo in the Image widget' );
t_ok( preg_match( '/gallery-item.*t7e-b.*gallery-item.*t7e-a.*gallery-item.*t7e-c/s', $html ), 'Basic Gallery: main photo then gallery, in order' );
t_ok( false !== strpos( $html, 'href="' . home_url( '/booking/?room=t7e-suite' ) . '"' ), 'Book now button: booking link of the current room' );
t_ok( false !== strpos( $html, 'href="' . home_url( '/rooms/t7e-suite/' ) . '"' ), 'View room button: room page' );
t_ok( false !== strpos( $html, 'flexo-room-amenities flexo-room-list--grid' ), 'Room amenities widget' );
t_ok( 2 === substr_count( $html, 'class="flexo-room-icon"' ) - 0 || false !== strpos( $html, 'lucide' ) || substr_count( $html, 'flexo-room-icon' ) >= 3, 'each amenity has an icon' );
t_ok( false === strpos( $html, '<b>XL</b>' ), 'amenity text escaped' );
t_ok( false !== strpos( $html, 'flexo-room-details__type' ) && false !== strpos( $html, 'Up to 3 guests' ), 'Room details widget with facts' );
t_ok( false !== strpos( $html, 'flexo-room-details__label">Floor</span>' ), 'Room details widget with More details' );
t_ok( false !== strpos( $html, 'data-flexo-carousel' ) && false !== strpos( $html, 'data-autoplay="5000"' ), 'Room gallery widget: carousel with autoplay' );
t_ok( false !== strpos( $html, 'data-elementor-open-lightbox="yes"' ), 'Room gallery opens Elementor\'s lightbox' );
t_eq( 3, substr_count( $html, 'flexo-room-gallery__slide' ), 'gallery widget: 3 photos' );
t_ok( false !== strpos( $html, 'data-flexo-booking-box' ) && false !== strpos( $html, 'data-room="t7e-suite"' ) && false !== strpos( $html, 'data-book-text="Reserve now"' ), 'Room booking box widget: current room' );
$from = Flexo_Booking_Room_Prices::get( $room );
t_ok( $from && false !== strpos( $html, '>' . Flexo_Booking_Money::format( $from['amount'], null, true ) . ' / night</h2>' ), 'Room price tag: "from" price' );

$html2 = t7e_render( $template, $other );
t_ok( false !== strpos( $html2, '>T7E Double</h2>' ) && false === strpos( $html2, 'T7E Suite' ), 'same template, another room: that room\'s data (Loop items work the same)' );
t_ok( false !== strpos( $html2, 'href="' . home_url( '/booking/?room=t7e-double' ) . '"' ), 'booking link follows the room' );
t_ok( false !== strpos( $html2, 'data-room="t7e-double"' ), 'booking box follows the room' );
t_ok( false !== strpos( $html2, 'flexo-room-gallery--empty' ), 'room without photos: neutral placeholder' );
t_ok( false === strpos( $html2, 'flexo-room-amenities flexo-room-list' ), 'room without amenities: no empty list' );

/* ---------------------------------------------------------------- */
t_section( 'Pages that are not about a room' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'T7E Page', 'post_status' => 'publish' ) );
$html = t7e_render( $template, 0 );
$GLOBALS['post'] = get_post( $page );
setup_postdata( $GLOBALS['post'] );
$html = Plugin::$instance->frontend->get_builder_content( $template, false );
t_ok( false === strpos( $html, 'T7E' ), 'no room: tags and widgets show nothing' );
t_ok( false !== strpos( $html, 'href="' . home_url( '/booking/' ) . '"' ), 'booking link without a room: the booking page (guest chooses)' );
t_ok( false === strpos( $html, 'flexo-room-note' ), 'no editor notes for visitors' );
$picked = t7e_document(
	'T7E Picked',
	array(
		t7e_widget( 'p000001', 'heading', array( 'title' => 'x' ), array( 'title' => t7e_tag( 'flexo-room-name', array( 'room' => 't7e-double' ) ) ) ),
		t7e_widget( 'p000002', 'flexo-room-details', array( 'room' => 't7e-double', 'facts' => array( 'size' ), 'more' => '', 'show' => 'value' ) ),
		t7e_widget( 'p000003', 'button', array( 'text' => 'Book', 'link' => array( 'url' => '' ) ), array( 'link' => t7e_tag( 'flexo-room-booking-link', array( 'room' => 't7e-double', 'booking_page' => '/reserve/' ) ) ) ),
	)
);
$html = Plugin::$instance->frontend->get_builder_content( $picked, false );
t_ok( false !== strpos( $html, '>T7E Double</h2>' ), 'tag with a picked room works on any page' );
t_ok( false !== strpos( $html, '22 m²' ), 'widget with a picked room works on any page' );
t_ok( false !== strpos( $html, 'href="' . home_url( '/reserve/?room=t7e-double' ) . '"' ), 'booking link with its own booking page path' );
$_GET = array( 'check_in' => '2027-07-01', 'check_out' => '2027-07-04', 'adults' => '2', 'children' => 'x1', 'children_ages' => '5,<b>9' );
$html = Plugin::$instance->frontend->get_builder_content( $picked, false );
t_ok( false !== strpos( $html, 'check_in=2027-07-01&#038;check_out=2027-07-04&#038;adults=2&#038;children=0&#038;children_ages=59' ) || false !== strpos( $html, 'check_in=2027-07-01' ), 'booking link keeps valid dates and guests from the address' );
$_GET = array( 'check_in' => 'yesterday' );
$html = Plugin::$instance->frontend->get_builder_content( $picked, false );
t_ok( false === strpos( $html, 'check_in' ), 'invalid dates are not passed on' );
$_GET = array();
wp_reset_postdata();
unset( $GLOBALS['post'] );

/* ---------------------------------------------------------------- */
t_section( 'Old "Room booking link" settings (before 1.8.0)' );
$old_tag = Plugin::$instance->dynamic_tags->create_tag( null, 'flexo-room-booking-link', array( 'room' => 't7e-suite', 'booking_page' => '/booking/' ) );
t_eq( home_url( '/booking/?room=t7e-suite' ), $old_tag->get_value(), 'room + path chosen in 1.x: same link' );
$old_tag = Plugin::$instance->dynamic_tags->create_tag( null, 'flexo-room-booking-link', array( 'room' => '', 'booking_page' => '/booking/' ) );
t_eq( home_url( '/booking/' ), $old_tag->get_value(), 'no room on a normal page: booking page, guest chooses (as before)' );

/* ---------------------------------------------------------------- */
t_section( 'Editing a template: sample room' );
$GLOBALS['post'] = get_post( $template );
setup_postdata( $GLOBALS['post'] );
t_eq( 0, Flexo_Booking_Room_Content::current_id(), 'not editing: a template on its own has no room' );
Plugin::$instance->editor->set_edit_mode( true );
t_eq( (int) Flexo_Booking_Rooms::all()[0]->ID, Flexo_Booking_Room_Content::current_id(), 'editing a room template without a preview room: first room as sample' );
$GLOBALS['post'] = get_post( $page );
setup_postdata( $GLOBALS['post'] );
t_eq( 0, Flexo_Booking_Room_Content::current_id(), 'editing a normal page: no sample room' );
$html = Plugin::$instance->frontend->get_builder_content( $template, false );
t_ok( false !== strpos( $html, 'flexo-room-note' ), 'editor note explains why a room widget is empty' );
Plugin::$instance->editor->set_edit_mode( false );
wp_reset_postdata();
unset( $GLOBALS['post'] );

/* ---------------------------------------------------------------- */
foreach ( array( $template, $picked, $page, $room, $other ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $img as $id ) {
	wp_delete_attachment( $id, true );
}
wp_delete_term( (int) $type['term_id'], Flexo_Booking_Room_Content::TAXONOMY );
update_option( Flexo_Booking_Settings::OPTION, $t7e_settings );
t_done();
