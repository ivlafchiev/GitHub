<?php
/**
 * Day 7: room content and room pages. Migration 8, room fields (gallery,
 * view, amenities with icons, more details, room types), hidden and demo
 * rooms in the booking engine and in front-end queries, old slugs, room
 * page addresses (base change, old bases, static pages at the same
 * address), the room view used by tags and widgets, and the room editor's
 * saving.
 *
 * Room page requests are made over HTTP to the site's own address, so the
 * site must be served (php -S …) while this runs.
 */
require __DIR__ . '/lib.php';

global $wpdb;
$t7_saved_settings = get_option( Flexo_Booking_Settings::OPTION );
$t7_saved_features = get_option( Flexo_Booking_Features::ENABLED_OPTION );
// Put the settings back even if a check stops the script.
register_shutdown_function(
	static function () use ( $t7_saved_settings, $t7_saved_features ) {
		update_option( Flexo_Booking_Settings::OPTION, $t7_saved_settings );
		update_option( Flexo_Booking_Features::ENABLED_OPTION, $t7_saved_features );
		delete_option( Flexo_Booking_Room_Pages::BASES_OPTION );
	}
);
t_reset_inventory();
wp_set_current_user( 0 );

function t7_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}

/** Status code and final address of a front-end request (no redirects followed). */
function t7_get( $path ) {
	$response = wp_remote_get(
		home_url( $path ),
		array(
			'redirection' => 0,
			'timeout'     => 20,
			'sslverify'   => false,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array( 0, '', '' );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'location' ), (string) wp_remote_retrieve_body( $response ) );
}

/** Flushes the rewrite rules the way the next request would. */
function t7_flush() {
	Flexo_Booking_Rooms::register_post_type();
	flush_rewrite_rules( false );
	delete_option( Flexo_Booking_Room_Pages::FLUSH_OPTION );
}

function t7_image( $name ) {
	$file = wp_upload_dir()['path'] . '/' . $name . '.png';
	// 1x1 transparent PNG.
	file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$file
	);
	update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $file ) ) );
	update_post_meta( $id, '_wp_attachment_image_alt', 'Alt ' . $name );
	return $id;
}

t7_settings( array( 'room_base' => 'rooms', 'rooms_page' => '', 'booking_page' => '/booking/' ) );
t7_flush();

/* ---------------------------------------------------------------- */
t_section( 'Migration 8' );
t_eq( 8, Flexo_Booking_Migrations::LATEST, 'latest migration is 8' );
t_eq( 8, Flexo_Booking_Migrations::current_version(), 'site is on migration 8' );
$pt = get_post_type_object( Flexo_Booking_Rooms::POST_TYPE );
t_ok( $pt->public && $pt->publicly_queryable && $pt->show_in_nav_menus, 'rooms are a public post type (Theme Builder / Loop Grid can use them)' );
t_ok( false === $pt->has_archive, 'no archive at the room base (the hotel\'s own rooms page stays)' );
t_ok( ! $pt->show_in_rest, 'room screen stays the classic editor' );
t_ok( taxonomy_exists( Flexo_Booking_Room_Content::TAXONOMY ), 'room type taxonomy registered' );
t_ok( is_object_in_taxonomy( Flexo_Booking_Rooms::POST_TYPE, Flexo_Booking_Room_Content::TAXONOMY ), 'room types belong to rooms' );

// A room saved before 1.8.0: ticked amenity keys only.
$old = t_room( 't7-old', 'T7 Old', array( 'price' => 80, 'capacity' => 2, 'units' => 1, 'amenities' => array( 'tv', 'wifi', 'sea_view' ) ) );
delete_post_meta( $old, Flexo_Booking_Room_Content::AMENITY_ITEMS );
$items = Flexo_Booking_Room_Content::amenities( $old );
t_eq( array( 'wifi', 'sea_view', 'tv' ), wp_list_pluck( $items, 'key' ), 'old ticks read as ready-made amenities, in list order' );
t_eq( array( 'wifi', 'waves', 'tv' ), wp_list_pluck( $items, 'icon' ), 'each gets its usual icon' );
t_eq( 'Free Wi-Fi', $items[0]['label'], 'label from the ready-made list' );
$search = Flexo_Booking_Bookings::search( t_day( 1 ), t_day( 3 ), 2, 0, 't7-old' );
t_eq( array( 'Free Wi-Fi', 'Sea view', 'TV' ), $search['rooms'][0]['amenities'], 'booking form cards get the same amenities' );

/* ---------------------------------------------------------------- */
t_section( 'Room content: amenities, details, gallery, view, types' );
$room = t_room( 't7-deluxe', 'T7 Deluxe', array( 'price' => 120, 'capacity' => 4, 'max_adults' => 2, 'units' => 2, 'size' => 32, 'beds' => '1 double bed + sofa bed' ) );
Flexo_Booking_Room_Editor::save_amenities(
	$room,
	array(
		array( 'key' => 'sea_view', 'label' => 'Sea view', 'icon' => '' ),
		array( 'key' => '', 'label' => 'Rain shower with a view', 'icon' => 'shower' ),
		array( 'key' => 'wifi', 'label' => 'Fast Wi-Fi (500 Mbit)', 'icon' => 'bogus-icon' ),
		array( 'key' => '', 'label' => '  <b>Terrace</b> with hot tub ', 'icon' => 'media:999999' ),
		array( 'key' => 'nope', 'label' => '', 'icon' => '' ),
		array( 'key' => '', 'label' => 'rain shower with a view', 'icon' => '' ),
		array( 'key' => 'sea_view', 'label' => '', 'icon' => '' ),
	)
);
$items = Flexo_Booking_Room_Content::amenities( $room );
t_eq( 4, count( $items ), 'unknown keys, empty and repeated amenities dropped' );
t_eq( array( 'Sea view', 'Rain shower with a view', 'Fast Wi-Fi (500 Mbit)', 'Terrace with hot tub' ), wp_list_pluck( $items, 'label' ), 'own amenities and renamed ones kept in the owner\'s order, tags stripped' );
t_eq( array( 'waves', 'shower', 'wifi', 'check' ), wp_list_pluck( $items, 'icon' ), 'unknown icons fall back to the usual icon' );
$stored = get_post_meta( $room, Flexo_Booking_Room_Content::AMENITY_ITEMS, true );
t_eq( '', $stored[0]['label'], 'unchanged ready-made name is not stored (follows the translation)' );
t_eq( array( 'sea_view', 'wifi' ), get_post_meta( $room, '_flexo_amenities', true ), 'ready-made keys kept in step for older exports' );

$details = Flexo_Booking_Room_Content::sanitize_details(
	array(
		array( 'icon' => 'layers', 'label' => 'Floor', 'value' => '2nd, with lift' ),
		array( 'icon' => '', 'label' => '', 'value' => '' ),
		array( 'icon' => 'x', 'label' => 'Bathroom', 'value' => '<script>x</script>Rain shower' ),
	)
);
update_post_meta( $room, Flexo_Booking_Room_Content::DETAILS, $details );
t_eq( 2, count( Flexo_Booking_Room_Content::details( $room ) ), 'empty detail rows dropped' );
t_eq( 'Rain shower', Flexo_Booking_Room_Content::details( $room )[1]['value'], 'detail values are plain text' );
t_eq( '', Flexo_Booking_Room_Content::details( $room )[1]['icon'], 'unknown detail icon dropped' );

$img1 = t7_image( 't7-a' );
$img2 = t7_image( 't7-b' );
$img3 = t7_image( 't7-c' );
set_post_thumbnail( $room, $img2 );
update_post_meta( $room, Flexo_Booking_Room_Content::GALLERY, Flexo_Booking_Room_Content::sanitize_gallery( "{$img1},{$img2},999999,{$img3},{$img1}" ) );
t_eq( array( $img1, $img2, $img3 ), get_post_meta( $room, Flexo_Booking_Room_Content::GALLERY, true ), 'gallery keeps order, drops missing and repeated images' );
t_eq( array( $img2, $img1, $img3 ), Flexo_Booking_Room_Content::gallery( $room ), 'room gallery starts with the main photo, no duplicate' );
wp_delete_attachment( $img3, true );
t_eq( array( $img2, $img1 ), Flexo_Booking_Room_Content::gallery( $room ), 'deleted image skipped' );

update_post_meta( $room, Flexo_Booking_Room_Content::VIEW, 'Sea view' );
$suite = term_exists( 'T7 Suite', Flexo_Booking_Room_Content::TAXONOMY );
$suite = $suite ? $suite : wp_insert_term( 'T7 Suite', Flexo_Booking_Room_Content::TAXONOMY );
wp_set_object_terms( $room, array( (int) $suite['term_id'] ), Flexo_Booking_Room_Content::TAXONOMY );
wp_update_post( array( 'ID' => $room, 'post_content' => "A bright room.\n\nWith a [flexo_t7_test] view.", 'post_excerpt' => 'Short and sweet.' ) );
add_shortcode( 'flexo_t7_test', static function () {
	return 'shortcode';
} );

Flexo_Booking_Features::set_enabled( array_merge( (array) Flexo_Booking_Features::stored_enabled(), array( 'children' ) ) );
$view = Flexo_Booking_Room_Content::room( $room );
t_eq( 'T7 Deluxe', $view['title'], 'view: name' );
t_eq( array( 'T7 Suite' ), $view['types'], 'view: room type' );
t_eq( 'Short and sweet.', $view['excerpt'], 'view: short description' );
t_ok( false !== strpos( Flexo_Booking_Room_Content::description_html( $view ), '<p>With a shortcode view.</p>' ), 'full description: paragraphs and shortcodes' );
t_eq( $img2, $view['image_id'], 'view: main photo' );
t_eq( '32 m²', Flexo_Booking_Room_Content::size_text( $view ), 'size text' );
t_eq( 'Up to 4 guests (max. 2 adults)', Flexo_Booking_Room_Content::guests_text( $view ), 'guests summary with an adult limit' );
t_eq( '3', Flexo_Booking_Room_Content::guests_text( $view, 'max_children' ), 'max children = guests − 1 (one adult stays)' );
t_eq( home_url( '/rooms/t7-deluxe/' ), $view['url'], 'room page address' );
t_eq( home_url( '/booking/?room=t7-deluxe' ), $view['booking_url'], 'booking link uses the booking page from Settings' );
t_eq( home_url( '/booking/?room=t7-deluxe&check_in=2026-07-01' ), Flexo_Booking_Room_Content::booking_url( 't7-deluxe', array( 'check_in' => '2026-07-01' ) ), 'booking link with dates' );
$image = Flexo_Booking_Room_Content::image( $img1 );
t_eq( 'Alt t7-a', $image['alt'], 'image alt text' );

/* ---------------------------------------------------------------- */
t_section( 'Current room' );
t_eq( 0, Flexo_Booking_Room_Content::current_id(), 'outside a room: no current room' );
t_eq( $room, Flexo_Booking_Room_Content::current_id( 't7-deluxe' ), 'named by slug' );
t_eq( $room, Flexo_Booking_Room_Content::current_id( (string) $room ), 'named by ID' );
t_eq( 0, Flexo_Booking_Room_Content::current_id( 'no-such-room' ), 'unknown slug: none' );
$GLOBALS['post'] = get_post( $room );
setup_postdata( $GLOBALS['post'] );
t_eq( $room, Flexo_Booking_Room_Content::current_id(), 'inside a room (template, loop item): that room' );
wp_reset_postdata();
unset( $GLOBALS['post'] );

/* ---------------------------------------------------------------- */
t_section( 'Hidden and demo rooms' );
$hidden = t_room( 't7-hidden', 'T7 Hidden', array( 'price' => 90, 'capacity' => 2, 'units' => 1 ) );
update_post_meta( $hidden, Flexo_Booking_Room_Content::HIDDEN, '1' );
$demo = t_room( 't7-demo', 'T7 Demo', array( 'price' => 70, 'capacity' => 2, 'units' => 1 ) );
update_post_meta( $demo, Flexo_Booking_Room_Content::DEMO, '1' );

$all = Flexo_Booking_Bookings::search( t_day( 1 ), t_day( 3 ), 2, 0 );
$ids = wp_list_pluck( $all['rooms'], 'id' );
t_ok( ! in_array( $hidden, $ids, true ), 'hidden room not offered when guests search all rooms' );
t_ok( ! in_array( $demo, $ids, true ), 'demo room never offered' );
t_ok( in_array( $room, $ids, true ), 'normal room offered' );
$one = Flexo_Booking_Bookings::search( t_day( 1 ), t_day( 3 ), 2, 0, 't7-hidden' );
t_ok( 1 === count( $one['rooms'] ) && $one['rooms'][0]['available'], 'hidden room still bookable through its link' );
$one = Flexo_Booking_Bookings::search( t_day( 1 ), t_day( 3 ), 2, 0, 't7-demo' );
t_eq( 0, count( $one['rooms'] ), 'demo room not offered even by its link' );
$b = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 't7-hidden', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ), 'privacy_consent' => 1 ) ) );
t_ok( ! is_wp_error( $b ), 'hidden room can be booked' );
$b = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 't7-demo', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ) ) ) );
t_ok( is_wp_error( $b ) && 'flexo_demo_room' === $b->get_error_code(), 'demo room cannot be booked' );
$b = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 't7-demo', 'check_in' => t_day( 1 ), 'check_out' => t_day( 3 ), 'source' => 'admin' ) ) );
t_ok( is_wp_error( $b ), 'demo room cannot be booked by staff either' );
$rest = rest_do_request( new WP_REST_Request( 'GET', '/flexo-booking/v1/rooms' ) );
$rids = wp_list_pluck( $rest->get_data(), 'id' );
t_ok( ! in_array( $hidden, $rids, true ) && ! in_array( $demo, $rids, true ) && in_array( $room, $rids, true ), 'REST room list: hidden and demo rooms left out' );
t_ok( in_array( $hidden, wp_list_pluck( Flexo_Booking_Rooms::all(), 'ID' ), true ), 'admin/engine list still has hidden rooms' );

// Front-end queries (Loop Grid, search) as a guest.
$q = new WP_Query( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_ok( ! in_array( $hidden, $q->posts, true ) && ! in_array( $demo, $q->posts, true ) && in_array( $room, $q->posts, true ), 'room lists for guests: hidden and demo left out' );
$q = new WP_Query( array( 's' => 'T7', 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_ok( ! in_array( $hidden, $q->posts, true ) && in_array( $room, $q->posts, true ), 'site search: hidden room left out' );
$q = new WP_Query( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'post__not_in' => array( $room ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_ok( ! in_array( $room, $q->posts, true ) && ! in_array( $hidden, $q->posts, true ), 'existing exclusions kept (e.g. "other rooms" without the current one)' );
$by_name = get_posts( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'name' => 't7-hidden', 'post_status' => 'any', 'fields' => 'ids' ) );
$by_id   = get_posts( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'p' => $hidden, 'post_status' => 'any', 'fields' => 'ids' ) );
t_ok( array( $hidden ) === $by_name && array( $hidden ) === $by_id, 'looking up one room by address or ID finds a hidden room (imports, other plugins)' );
$t7_file = Flexo_Booking_Portability::export();
$t7_file['rooms'] = array_values( array_filter( $t7_file['rooms'], static function ( $r ) { return 't7-hidden' === $r['slug']; } ) );
$t7_before = count( get_posts( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'flexo_all_rooms' => true ) ) );
$t7_result = Flexo_Booking_Portability::import( $t7_file, array( 'settings' => false, 'rooms' => true, 'seasons' => false, 'closures' => false, 'rate_plans' => false, 'promo_codes' => false, 'bookings' => false ) );
$t7_after  = count( get_posts( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'flexo_all_rooms' => true ) ) );
t_ok( ! is_wp_error( $t7_result ) && 1 === $t7_result['rooms_updated'] && $t7_before === $t7_after, 're-importing a hidden room updates it (no duplicate)' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );
$q = new WP_Query( array( 'post_type' => Flexo_Booking_Rooms::POST_TYPE, 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_ok( in_array( $demo, $q->posts, true ) && ! in_array( $hidden, $q->posts, true ), 'staff see demo rooms in lists (to design with), hidden still out' );
wp_set_current_user( 0 );
$sitemap = apply_filters( 'wp_sitemaps_posts_query_args', array(), Flexo_Booking_Rooms::POST_TYPE );
t_ok( in_array( $hidden, $sitemap['post__not_in'], true ) && in_array( $demo, $sitemap['post__not_in'], true ), 'sitemap leaves out hidden and demo rooms' );
t_eq( '', Flexo_Booking_Room_Content::page_url( $hidden ), 'hidden room has no page address' );

/* ---------------------------------------------------------------- */
t_section( 'Room pages over HTTP' );
list( $code, , $body ) = t7_get( '/rooms/t7-deluxe/' );
if ( 0 === $code ) {
	t_ok( false, 'site not reachable at ' . home_url() . ' – serve it while the tests run' );
} else {
	t_eq( 200, $code, 'room page answers' );
	t_ok( false !== strpos( $body, 'T7 Deluxe' ), 'room page shows the room' );
	list( $code ) = t7_get( '/rooms/t7-hidden/' );
	t_eq( 404, $code, 'hidden room page: 404 for guests' );
	list( $code ) = t7_get( '/rooms/t7-demo/' );
	t_eq( 404, $code, 'demo room page: 404 for guests' );
	list( $code ) = t7_get( '/rooms/no-such-room/' );
	t_eq( 404, $code, 'unknown room: 404' );

	// Old slug.
	wp_update_post( array( 'ID' => $room, 'post_name' => 't7-deluxe-sea' ) );
	list( $code, $location ) = t7_get( '/rooms/t7-deluxe/' );
	t_ok( 301 === $code && false !== strpos( $location, '/rooms/t7-deluxe-sea/' ), 'old room slug redirects to the new page' );
	t_eq( $room, Flexo_Booking_Rooms::find( 't7-deluxe' ) ? Flexo_Booking_Rooms::find( 't7-deluxe' )->ID : 0, 'booking links with the old slug find the room' );
	$s = Flexo_Booking_Bookings::search( t_day( 1 ), t_day( 3 ), 2, 0, 't7-deluxe' );
	t_eq( $room, $s['rooms'][0]['id'], 'search with the old slug offers the room' );

	// A static page at /rooms/<slug>/ with no room of that slug is still served.
	$parent = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Rooms', 'post_name' => 'rooms', 'post_status' => 'publish' ) );
	$child  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'T7 Old Static', 'post_name' => 't7-static', 'post_parent' => $parent, 'post_status' => 'publish', 'post_content' => 'static page body' ) );
	list( $code, , $body ) = t7_get( '/rooms/t7-static/' );
	t_ok( 200 === $code && false !== strpos( $body, 'static page body' ), 'static page under the room base still served' );
	list( $code ) = t7_get( '/rooms/' );
	t_eq( 200, $code, 'the hotel\'s own /rooms/ page still served (no archive)' );
	delete_transient( Flexo_Booking_Room_Pages::PAGE_CACHE );
	t_eq( get_permalink( $parent ), Flexo_Booking_Room_Pages::rooms_page_url(), 'All rooms page found automatically at /rooms/' );

	// Change the base: new addresses work, old ones redirect.
	t7_settings( array( 'room_base' => 'stai' ) );
	t_ok( (bool) get_option( Flexo_Booking_Room_Pages::FLUSH_OPTION ), 'base change asks for one rewrite flush' );
	t_eq( array( 'rooms' ), get_option( Flexo_Booking_Room_Pages::BASES_OPTION ), 'old base remembered' );
	list( $code ) = t7_get( '/' ); // The next request flushes.
	wp_cache_flush();
	t_ok( ! get_option( Flexo_Booking_Room_Pages::FLUSH_OPTION ), 'flushed once on the next request' );
	list( $code ) = t7_get( '/stai/t7-deluxe-sea/' );
	t_eq( 200, $code, 'room page at the new base' );
	list( $code, $location ) = t7_get( '/rooms/t7-deluxe-sea/' );
	t_ok( 301 === $code && false !== strpos( $location, '/stai/t7-deluxe-sea/' ), 'old base redirects to the new address' );
	list( $code ) = t7_get( '/rooms/t7-hidden/' );
	t_eq( 404, $code, 'old base does not reveal hidden rooms' );
	t7_settings( array( 'room_base' => 'wp-admin' ) );
	t_eq( 'stai', Flexo_Booking_Room_Pages::base(), 'reserved base refused' );
	t7_settings( array( 'room_base' => 'rooms' ) );
	t_eq( array( 'stai' ), array_values( get_option( Flexo_Booking_Room_Pages::BASES_OPTION ) ), 'back to the first base: list keeps only other bases' );
	t7_get( '/' );
	list( $code ) = t7_get( '/rooms/t7-deluxe-sea/' );
	t_eq( 200, $code, 'room page back at /rooms/' );

	// Rules missing (e.g. files copied over without activation) are added back.
	update_option( 'rewrite_rules', array( 'foo/?$' => 'index.php' ) );
	t7_get( '/' );
	list( $code ) = t7_get( '/rooms/t7-deluxe-sea/' );
	t_eq( 200, $code, 'missing room rules are restored on the next request' );

	wp_delete_post( $child, true );
	wp_delete_post( $parent, true );
}

/* ---------------------------------------------------------------- */
t_section( 'Room editor saving' );
wp_set_current_user( $admin->ID );
$_POST = array(
	'flexo_room_nonce'      => wp_create_nonce( 'flexo_room_meta' ),
	'flexo_room_cards'      => array( 'summary', 'photos', 'facts', 'amenities', 'details', 'prices', 'website', 'seo' ),
	'_flexo_price'          => '150',
	'_flexo_capacity'       => '3',
	'_flexo_units'          => '2',
	'_flexo_size'           => '28',
	'_flexo_beds'           => '2 single beds',
	'flexo_view'            => 'Garden view',
	'flexo_main_photo'      => (string) $img1,
	'flexo_gallery'         => (string) $img2,
	'flexo_room_types'      => array( (string) $suite['term_id'] ),
	'flexo_new_room_types'  => array( 'T7 Family room', '' ),
	'flexo_amenity_key'     => array( 'wifi', '' ),
	'flexo_amenity_label'   => array( 'Free Wi-Fi', 'Hammock' ),
	'flexo_amenity_icon'    => array( '', 'trees' ),
	'flexo_detail_label'    => array( 'Floor' ),
	'flexo_detail_value'    => array( 'Ground' ),
	'flexo_detail_icon'     => array( 'layers' ),
	'flexo_seo_title'       => 'Garden room in Sozopol',
	'flexo_seo_description' => 'Quiet garden room.',
);
Flexo_Booking_Rooms::save_meta( $room, get_post( $room ) );
$r = Flexo_Booking_Room_Content::room( $room );
t_eq( 150.0, $r['price'], 'price saved' );
t_eq( 'Garden view', $r['view'], 'view saved' );
t_eq( $img1, (int) get_post_thumbnail_id( $room ), 'main photo saved (whatever the theme supports)' );
t_eq( array( $img1, $img2 ), $r['gallery'], 'gallery saved' );
t_eq( array( 'T7 Family room', 'T7 Suite' ), $r['types'], 'room types saved, new type created' );
t_eq( array( 'Free Wi-Fi', 'Hammock' ), wp_list_pluck( $r['amenity_list'], 'label' ), 'amenities saved' );
t_eq( 'trees', $r['amenity_list'][1]['icon'], 'own amenity icon saved' );
t_eq( 'Ground', $r['details'][0]['value'], 'details saved' );
t_eq( 'Garden room in Sozopol', get_post_meta( $room, Flexo_Booking_Room_Content::SEO_TITLE, true ), 'SEO title saved' );
t_ok( Flexo_Booking_Room_Content::is_hidden( $room ), 'switch "Show on the website" off (not sent) hides the room' );
$_POST['flexo_show'] = '1';
unset( $_POST['flexo_seo_title'] );
$_POST['flexo_room_cards'] = array( 'website' );
Flexo_Booking_Rooms::save_meta( $room, get_post( $room ) );
t_ok( ! Flexo_Booking_Room_Content::is_hidden( $room ), 'switch on: shown again' );
t_eq( 'Garden room in Sozopol', get_post_meta( $room, Flexo_Booking_Room_Content::SEO_TITLE, true ), 'cards not on the screen keep their values' );
t_eq( 'Ground', Flexo_Booking_Room_Content::details( $room )[0]['value'], 'details kept when their card was not sent' );
t_eq( 150.0, Flexo_Booking_Rooms::to_array( get_post( $room ) )['price'], 'price kept when sent again' );
$_POST = array(
	'flexo_room_nonce' => 'wrong',
	'flexo_room_cards' => array( 'website' ),
);
Flexo_Booking_Rooms::save_meta( $room, get_post( $room ) );
t_ok( ! Flexo_Booking_Room_Content::is_hidden( $room ), 'bad nonce: nothing saved' );
$_POST = array();
wp_set_current_user( 0 );

/* ---------------------------------------------------------------- */
t_section( '"From" price' );
Flexo_Booking_Features::set_enabled( array_merge( (array) Flexo_Booking_Features::stored_enabled(), array( 'seasonal_pricing', 'rate_plans', 'tourist_tax' ) ) );
Flexo_Booking_Features::reset_cache();
t7_settings( array( 'min_nights' => 1, 'max_advance_days' => 365, 'tourist_tax_amount' => 2, 'room_price_display' => 'from' ) );
$fp = t_room( 't7-from', 'T7 From', array( 'price' => 100, 'weekend_price' => 150, 'capacity' => 3, 'units' => 1, 'min_nights' => 2 ) );
$from = Flexo_Booking_Room_Prices::get( $fp );
t_eq( 100.0, (float) $from['amount'], 'weekday stays at the normal price (weekend nights cost more)' );
t_eq( 2, $from['nights'], 'stay of the room\'s minimum length' );
$low = Flexo_Booking_Seasons::save( array( 'room_id' => $fp, 'name' => 'T7 Low', 'date_from' => t_day( 21 ), 'date_to' => t_day( 27 ), 'price' => 70, 'weekend_price' => '' ) );
t_ok( ! is_wp_error( $low ), 'low season saved' );
t_eq( 70.0, (float) Flexo_Booking_Room_Prices::get( $fp )['amount'], 'season change updates the "from" price at once (low season 70)' );
t_ok( $low && Flexo_Booking_Room_Prices::get( $fp )['check_in'] >= t_day( 21 ), 'cheapest arrival is in the low season' );
$closed = Flexo_Booking_Closures::save( array( 'room_id' => $fp, 'date_from' => t_day( 21 ), 'date_to' => t_day( 27 ), 'reason' => 'T7' ) );
t_eq( 100.0, (float) Flexo_Booking_Room_Prices::get( $fp )['amount'], 'closed dates are skipped' );
Flexo_Booking_Closures::delete( $closed );
Flexo_Booking_Seasons::save( array( 'room_id' => $fp, 'name' => 'T7 Low', 'date_from' => t_day( 21 ), 'date_to' => t_day( 27 ), 'price' => 70, 'weekend_price' => '', 'min_nights' => 7 ), $low );
$from = Flexo_Booking_Room_Prices::get( $fp );
t_eq( 7, $from['nights'], 'season\'s own minimum stay used for its arrival days' );
t_eq( 70.0, (float) $from['amount'], 'a whole week in the low season still averages 70' );
$plan = Flexo_Booking_Rate_Plans::save( array( 'name' => 'T7 Breakfast', 'adjustment_type' => 'per_night', 'adjustment_value' => 12, 'active' => 1 ) );
Flexo_Booking_Rate_Plans::set_room_assignments( $fp, array( $plan => null ) );
t_eq( 82.0, (float) Flexo_Booking_Room_Prices::get( $fp )['amount'], 'the room\'s first rate is included (+12 per night)' );
$quote = Flexo_Booking_Pricing::quote( array( 'room' => $fp, 'check_in' => Flexo_Booking_Room_Prices::get( $fp )['check_in'], 'check_out' => Flexo_Booking_Dates::add_days( Flexo_Booking_Room_Prices::get( $fp )['check_in'], 7 ), 'adults' => 2 ) );
t_ok( $quote['tax_total'] > 0 && abs( $quote['subtotal'] / 7 - 82 ) < 0.01, 'tourist tax not in the "from" price; the booking flow quotes the same room price' );
update_post_meta( $fp, '_flexo_price', 60 );
Flexo_Booking_Seasons::delete( $low );
t_eq( 72.0, (float) Flexo_Booking_Room_Prices::get( $fp )['amount'], 'price change updates it (60 + 12)' );
update_post_meta( $fp, '_flexo_units', 0 );
t_eq( null, Flexo_Booking_Room_Prices::get( $fp ), 'room taking no bookings: no "from" price' );
update_post_meta( $fp, '_flexo_units', 1 );
Flexo_Booking_Room_Prices::get( $fp );
$cached = get_post_meta( $fp, Flexo_Booking_Room_Prices::META, true );
t_ok( is_array( $cached ) && wp_date( 'Y-m-d' ) === $cached['computed'], 'cached per room for the day' );
t_ok( (bool) wp_next_scheduled( Flexo_Booking_Room_Prices::EVENT ), 'daily refresh scheduled' );
t_ok( false !== strpos( Flexo_Booking_Room_Prices::from_text( $fp ), '72' ) && false === strpos( Flexo_Booking_Room_Prices::from_text( $fp ), '72.00' ), '"from" text, whole amount without decimals' );
t7_settings( array( 'room_price_display' => 'none' ) );
t_eq( '', Flexo_Booking_Room_Prices::from_text( $fp ), 'prices hidden in the settings: no "from" text' );
t7_settings( array( 'room_price_display' => 'from' ) );
Flexo_Booking_Rate_Plans::delete( $plan );
t_eq( 60.0, (float) Flexo_Booking_Room_Prices::get( $fp )['amount'], 'rate deleted: back to the room price' );

/* ---------------------------------------------------------------- */
t_section( 'Room booking box' );
$html = do_shortcode( '[flexo_room_booking room="t7-from"]' );
t_ok( false !== strpos( $html, 'data-flexo-booking-box' ) && false !== strpos( $html, 'data-room="t7-from"' ), 'shortcode renders the box for the room' );
t_ok( false !== strpos( $html, 'fb-box__from-price' ), 'box shows the "from" price' );
t_ok( false !== strpos( $html, 'action="' . home_url( '/booking/' ) . '"' ), 'without JavaScript the form opens the booking page' );
t_eq( 3, substr_count( preg_replace( '/.*name="adults">(.*?)<\/select>.*/s', '$1', $html ), '<option' ), 'adults limited to the room\'s guests' );
t_eq( '', do_shortcode( '[flexo_room_booking]' ), 'no room on the page: nothing shown' );
$GLOBALS['post'] = get_post( $fp );
setup_postdata( $GLOBALS['post'] );
t_ok( false !== strpos( do_shortcode( '[flexo_room_booking]' ), 'data-room="t7-from"' ), 'inside a room: the current room' );
wp_reset_postdata();
unset( $GLOBALS['post'] );
$_GET = array( 'check_in' => t_day( 10 ), 'check_out' => t_day( 12 ), 'adults' => '2' );
$html = do_shortcode( '[flexo_room_booking room="t7-from" show_price="no" button_text="Check" book_text="Reserve"]' );
t_ok( false !== strpos( $html, 'data-autosearch="1"' ) && false !== strpos( $html, 'value="' . t_day( 10 ) . '"' ), 'dates from a search are filled in and checked at once' );
t_ok( false === strpos( $html, 'fb-box__from' ) && false !== strpos( $html, '>Check</button>' ) && false !== strpos( $html, 'data-book-text="Reserve"' ), 'price switch and button texts' );
$_GET = array();
update_post_meta( $fp, Flexo_Booking_Room_Content::DEMO, '1' );
t_ok( false !== strpos( do_shortcode( '[flexo_room_booking room="t7-from"]' ), 'demo room' ), 'demo room: box says it cannot be booked' );
delete_post_meta( $fp, Flexo_Booking_Room_Content::DEMO );
$full = Flexo_Booking_Bookings::search( t_day( 10 ), t_day( 12 ), 2, 0, 't7-from' );
t_ok( $full['rooms'][0]['available'], 'the box asks the same availability service as the booking form' );
t_eq( '', Flexo_Booking_Room_Render::field_shortcode( array( 'field' => 'nope', 'room' => 't7-from' ) ), '[flexo_room_field] ignores unknown fields' );
t_eq( 'T7 From', do_shortcode( '[flexo_room_field field="name" room="t7-from"]' ), '[flexo_room_field] name' );
t_ok( false !== strpos( do_shortcode( '[flexo_room_field field="from_price" room="t7-from"]' ), '60' ), '[flexo_room_field] from price' );

/* ---------------------------------------------------------------- */
t_section( 'Default room page and search engines over HTTP' );
wp_update_post( array( 'ID' => $fp, 'post_excerpt' => 'A quiet room.', 'post_content' => 'Long text.' ) );
update_post_meta( $fp, Flexo_Booking_Room_Content::SEO_DESCRIPTION, 'Quiet room in the old town.' );
update_post_meta( $fp, Flexo_Booking_Room_Content::SEO_TITLE, 'T7 From – quiet room' );
t7_settings( array( 'room_sticky_bar' => 1 ) );
list( $code, , $body ) = t7_get( '/rooms/t7-from/' );
if ( 200 === $code ) {
	t_ok( false !== strpos( $body, 'class="flexo-room-page"' ), 'block theme: default room page (Single room template)' );
	t_ok( false !== strpos( $body, 'data-flexo-booking-box' ), 'room page has the booking box' );
	t_ok( false !== strpos( $body, 'data-flexo-room-bar' ), 'phone bar on room pages' );
	t_ok( false !== strpos( $body, '<title>T7 From – quiet room</title>' ) || false !== strpos( $body, '<title>T7 From &#8211; quiet room</title>' ), 'SEO title' );
	t_ok( false !== strpos( $body, '<meta name="description" content="Quiet room in the old town.">' ), 'SEO description' );
	t_eq( 1, substr_count( $body, 'application/ld+json' ), 'one structured data block' );
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $body, $m );
	$ld    = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
	$types = $ld ? wp_list_pluck( $ld['@graph'], '@type' ) : array();
	t_eq( array( 'HotelRoom', 'Hotel', 'Offer' ), $types, 'schema: HotelRoom in a Hotel, with an Offer' );
	t_eq( 60.0, $ld ? (float) $ld['@graph'][2]['priceSpecification']['price'] : 0.0, 'offer price = "from" price' );
	t_eq( 3, $ld ? $ld['@graph'][0]['occupancy']['maxValue'] : 0, 'occupancy' );
	t7_settings( array( 'room_sticky_bar' => 0 ) );
	list( , , $body ) = t7_get( '/rooms/t7-from/' );
	t_ok( false === strpos( $body, 'data-flexo-room-bar' ), 'phone bar can be switched off' );

	// Classic theme: the plugin's template with the theme's header and footer.
	$theme = get_stylesheet();
	if ( wp_get_theme( 'twentytwentyone' )->exists() ) {
		switch_theme( 'twentytwentyone' );
		list( $code, , $body ) = t7_get( '/rooms/t7-from/' );
		t_ok( 200 === $code && false !== strpos( $body, 'class="flexo-room-page"' ) && false !== strpos( $body, 'site-header' ), 'classic theme: room page inside the theme\'s header and footer' );
		t_eq( 1, substr_count( $body, '<h1' ), 'classic theme: one main heading' );
		switch_theme( $theme );
	}
}

// Yoast SEO / Rank Math stand-ins: their own titles and descriptions, our room joins their graph.
$GLOBALS['wp_query']     = new WP_Query( array( 'p' => $fp, 'post_type' => Flexo_Booking_Rooms::POST_TYPE ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$graph = apply_filters( 'wpseo_schema_graph', array( array( '@type' => 'WebPage', '@id' => 'x#webpage' ) ), null );
t_eq( array( 'WebPage', 'HotelRoom', 'Hotel', 'Offer' ), wp_list_pluck( $graph, '@type' ), 'Yoast: room added to Yoast\'s graph' );
$graph = apply_filters( 'wpseo_schema_graph', array( array( '@type' => array( 'HotelRoom', 'Product' ) ) ), null );
t_eq( 1, count( $graph ), 'Yoast: no second HotelRoom' );
$rm = apply_filters( 'rank_math/json_ld', array( 'WebPage' => array( '@type' => 'WebPage' ) ), null );
t_eq( 4, count( $rm ), 'Rank Math: room added to Rank Math\'s data' );
$rm = apply_filters( 'rank_math/json_ld', array( 'richSnippet' => array( '@type' => 'Product' ) ), null );
t_eq( 1, count( $rm ), 'Rank Math: a Product schema set on the room is kept, nothing added' );
update_post_meta( $hidden, Flexo_Booking_Room_Content::HIDDEN, '1' );
t_ok( in_array( $hidden, apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', array() ), true ), 'Yoast sitemap: hidden room left out' );
t_eq( false, apply_filters( 'rank_math/sitemap/entry', array( 'loc' => 'x' ), 'post', get_post( $hidden ) ), 'Rank Math sitemap: hidden room left out' );
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', 'stand-in' );
}
ob_start();
Flexo_Booking_Room_SEO::head();
$head = ob_get_clean();
t_eq( '', $head, 'with an SEO plugin: no own description or schema (no duplicates)' );
t_eq( 'Theme title', Flexo_Booking_Room_SEO::title( 'Theme title' ), 'with an SEO plugin: its title is kept' );
wp_reset_query();
wp_delete_post( $fp, true );

/* ---------------------------------------------------------------- */
t_section( 'Demo rooms' );
wp_set_current_user( $admin->ID );
$added = Flexo_Booking_Demo_Rooms::add();
$ids   = Flexo_Booking_Demo_Rooms::ids();
t_eq( 3, $added, 'three demo rooms added' );
t_ok( count( $ids ) >= 3, 'demo rooms are marked as demo' );
$d0 = Flexo_Booking_Room_Content::room( get_page_by_path( 'demo-sea-view-studio', OBJECT, Flexo_Booking_Rooms::POST_TYPE )->ID );
t_ok( $d0['price'] > 0 && $d0['size'] > 0 && count( $d0['amenity_list'] ) >= 5 && $d0['types'], 'demo room filled in (price, size, amenities, type)' );
t_ok( ! function_exists( 'imagecreatetruecolor' ) || count( $d0['gallery'] ) >= 2, 'demo room has sample photos' );
t_eq( 0, Flexo_Booking_Demo_Rooms::add(), 'adding again does not duplicate them' );
$s = Flexo_Booking_Bookings::search( t_day( 5 ), t_day( 7 ), 2, 0 );
t_ok( ! array_intersect( $ids, wp_list_pluck( $s['rooms'], 'id' ) ), 'demo rooms are never offered to guests' );
$photo = $d0['gallery'] ? $d0['gallery'][0] : 0;
$gone  = Flexo_Booking_Demo_Rooms::remove();
t_eq( count( $ids ), $gone['removed'], 'remove demo rooms' );
t_eq( array(), Flexo_Booking_Demo_Rooms::ids(), 'no demo rooms left' );
t_ok( ! $photo || ! get_post( $photo ), 'their sample photos are deleted too' );
wp_set_current_user( 0 );

/* ---------------------------------------------------------------- */
t_section( 'Bring in rooms from JetEngine' );
// JetEngine switched off: its "rooms" posts are still in the database, the post type is not registered.
$jet_img = t7_image( 't7-jet-main' );
$jet_g1  = t7_image( 't7-jet-g1' );
$jet_g2  = t7_image( 't7-jet-g2' );
$jet = array();
foreach ( array( 't7-sea-view-double' => 'T7 Sea View Double Room', 't7-boho-family-suite' => 'T7 Boho Family Suite' ) as $slug => $title ) {
	$jet[ $slug ] = wp_insert_post( array( 'post_type' => 'rooms', 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish', 'post_content' => '<p>Old post text.</p>' ) );
}
$j = $jet['t7-sea-view-double'];
update_post_meta( $j, 'price_per_night', '270' );
update_post_meta( $j, 'room_size', '40 m²' );
update_post_meta( $j, 'max_guests', '1–3' );
update_post_meta( $j, 'beds_info', '1 King bed + sofa bed' );
update_post_meta( $j, 'long_description', '<p>Wake up to the sight of endless blue.</p>' );
foreach ( array( 'Sea-facing terrace', 'Air conditioning', 'High-speed WiFi', 'Daily housekeeping' ) as $i => $label ) {
	update_post_meta( $j, 'amenity-' . ( $i + 1 ), $label );
}
update_post_meta( $j, 'room_gallery', $jet_g1 . ',' . $jet_g2 );
update_post_meta( $j, 'floor', '2nd' );
set_post_thumbnail( $j, $jet_img );
update_post_meta( $jet['t7-boho-family-suite'], 'price_per_night', '€350' );
update_post_meta( $jet['t7-boho-family-suite'], 'max_guests', '4 persons' );

$sources = Flexo_Booking_Room_Importer::sources();
t_ok( isset( $sources['rooms'] ) && 2 <= $sources['rooms'][1], 'JetEngine rooms found even with JetEngine off' );
t_eq( 'rooms', Flexo_Booking_Room_Importer::default_source( $sources ), '"rooms" suggested' );
$fields  = Flexo_Booking_Room_Importer::fields( Flexo_Booking_Room_Importer::posts( 'rooms' ) );
$mapping = array();
foreach ( array_keys( $fields ) as $field ) {
	$mapping[ $field ] = Flexo_Booking_Room_Importer::suggest( $field );
}
t_eq( 'price', $mapping['price_per_night'], 'suggest price_per_night → price' );
t_eq( 'size', $mapping['room_size'], 'suggest room_size → size' );
t_eq( 'capacity', $mapping['max_guests'], 'suggest max_guests → max guests' );
t_eq( 'beds', $mapping['beds_info'], 'suggest beds_info → beds' );
t_eq( 'description', $mapping['long_description'], 'suggest long_description → full description' );
t_eq( 'amenity', $mapping['amenity-1'], 'suggest amenity-N → amenity' );
t_eq( 'gallery', $mapping['room_gallery'], 'suggest gallery' );
$mapping['floor'] = 'detail';
// An existing Flexo room with the same address keeps its own price.
$pre = t_room( 't7-boho-family-suite', 'Old name', array( 'price' => 333, 'capacity' => 2, 'units' => 2 ) );
$results = Flexo_Booking_Room_Importer::import( 'rooms', $mapping, array( 'content' => true ) );
t_eq( 2, count( $results ), 'both rooms brought in' );
$new = Flexo_Booking_Rooms::find( 't7-sea-view-double' );
$r   = $new ? Flexo_Booking_Room_Content::room( $new->ID ) : null;
t_ok( $r && 'T7 Sea View Double Room' === $r['title'], 'same name and address (slug)' );
t_eq( 270.0, $r['price'], 'price' );
t_eq( 40, $r['size'], 'size parsed from "40 m²"' );
t_eq( 3, $r['capacity'], 'max guests from "1–3"' );
t_eq( '1 King bed + sofa bed', $r['beds'], 'beds' );
t_ok( false !== strpos( $r['description'], 'endless blue' ), 'full description from the field' );
t_eq( array( 'Sea-facing terrace', 'Air conditioning', 'High-speed WiFi', 'Daily housekeeping' ), wp_list_pluck( $r['amenity_list'], 'label' ), 'amenities in order' );
t_eq( array( 'sun', 'air_conditioning', 'wifi', 'cleaning' ), wp_list_pluck( $r['amenity_list'], 'icon' ), 'amenities get matching icons (ready-made amenity where the name matches)' );
t_eq( 'air_conditioning', $r['amenity_list'][1]['key'], '"Air conditioning" becomes the ready-made amenity' );
t_eq( array( $jet_img, $jet_g1, $jet_g2 ), $r['gallery'], 'main photo and gallery' );
t_eq( 'Floor', $r['details'][0]['label'], 'other field as a More details line' );
$boho = Flexo_Booking_Room_Content::room( $pre );
t_eq( 333.0, $boho['price'], 'existing room keeps its price' );
t_eq( 4, $boho['capacity'], 'facts updated from the old room' );
t_eq( 'T7 Boho Family Suite', $boho['title'], 'name updated' );
t_ok( false !== strpos( $boho['description'], 'Old post text.' ), 'post text used when no description field' );
$again = Flexo_Booking_Room_Importer::import( 'rooms', $mapping, array( 'content' => true ) );
t_ok( 2 === count( Flexo_Booking_Rooms::all() ) - count( array_diff( wp_list_pluck( Flexo_Booking_Rooms::all(), 'post_name' ), array( 't7-sea-view-double', 't7-boho-family-suite' ) ) ), 'bringing in again updates, no duplicates' );

/* ---------------------------------------------------------------- */
t_section( 'Import/Export of room content' );
$file = Flexo_Booking_Portability::export();
t_eq( 6, $file['schema'], 'export schema 6' );
$exp = null;
foreach ( $file['rooms'] as $row ) {
	if ( 't7-sea-view-double' === $row['slug'] ) {
		$exp = $row;
	}
}
t_ok( $exp && isset( $exp['page'] ), 'room page content exported' );
t_eq( 2, count( $exp['page']['gallery'] ), 'gallery exported as addresses' );
t_eq( wp_get_attachment_url( $jet_img ), $exp['page']['main_photo']['url'], 'main photo address' );
t_eq( 4, count( $exp['page']['amenities'] ), 'amenities exported' );
// Import into a "fresh" room: same slug deleted first.
wp_delete_post( $new->ID, true );
$res = Flexo_Booking_Portability::import( array( 'format' => 'flexo-booking', 'rooms' => array( $exp ) ), array( 'settings' => false, 'images' => false, 'seasons' => false, 'closures' => false, 'rate_plans' => false, 'promo_codes' => false ) );
$imp = Flexo_Booking_Rooms::find( 't7-sea-view-double' );
$ri  = Flexo_Booking_Room_Content::room( $imp->ID );
t_eq( 1, $res['rooms_created'], 'room created from the file' );
t_eq( array( 'Sea-facing terrace', 'Air conditioning', 'High-speed WiFi', 'Daily housekeeping' ), wp_list_pluck( $ri['amenity_list'], 'label' ), 'amenities imported' );
t_eq( 'Floor', $ri['details'][0]['label'], 'details imported' );
t_eq( 3, count( (array) get_post_meta( $imp->ID, Flexo_Booking_Portability::MISSING_META, true ) ), 'photos not downloaded are remembered (main + 2)' );
t_eq( array(), $ri['gallery'], 'no broken images meanwhile' );
// "Download missing photos": same site here, so the files are found in the media library again.
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	$path = str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $url );
	if ( file_exists( $path ) ) {
		if ( ! empty( $args['filename'] ) ) {
			copy( $path, $args['filename'] ); // Downloads are streamed to a temporary file.
		}
		return array( 'headers' => array( 'content-type' => 'image/png' ), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => isset( $args['filename'] ) ? $args['filename'] : null );
	}
	return $pre;
}, 10, 3 );
$got = Flexo_Booking_Portability::download_missing( $imp->ID );
Flexo_Booking_Room_Content::forget( $imp->ID );
$ri = Flexo_Booking_Room_Content::room( $imp->ID );
t_eq( 3, $got, 'missing photos downloaded' );
t_eq( 3, count( $ri['gallery'] ), 'main photo and gallery in place' );
t_ok( ! get_post_meta( $imp->ID, Flexo_Booking_Portability::MISSING_META, true ), 'nothing missing any more' );
t_eq( 0, Flexo_Booking_Portability::download_missing( $imp->ID ), 'nothing to download twice' );
$old_file = array( 'format' => 'flexo-booking', 'rooms' => array( array( 'slug' => 't7-old-file', 'title' => 'T7 Old file', 'meta' => array( '_flexo_price' => 50, '_flexo_amenities' => array( 'wifi', 'tv' ) ) ) ) );
Flexo_Booking_Portability::import( $old_file, array( 'settings' => false, 'images' => false ) );
$of = Flexo_Booking_Rooms::find( 't7-old-file' );
t_eq( array( 'wifi', 'tv' ), wp_list_pluck( Flexo_Booking_Room_Content::amenities( $of->ID ), 'key' ), 'files from 1.7 and earlier still import (amenity ticks)' );

foreach ( array( $of->ID, $imp->ID, $pre ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $jet as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( $jet_img, $jet_g1, $jet_g2 ) as $id ) {
	wp_delete_attachment( $id, true );
}

/* ---------------------------------------------------------------- */
foreach ( array( $old, $room, $hidden, $demo ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( $img1, $img2 ) as $id ) {
	wp_delete_attachment( $id, true );
}
foreach ( get_terms( array( 'taxonomy' => Flexo_Booking_Room_Content::TAXONOMY, 'hide_empty' => false ) ) as $term ) {
	if ( 0 === strpos( $term->name, 'T7 ' ) ) {
		wp_delete_term( $term->term_id, Flexo_Booking_Room_Content::TAXONOMY );
	}
}
delete_option( Flexo_Booking_Room_Pages::BASES_OPTION );
update_option( Flexo_Booking_Settings::OPTION, $t7_saved_settings );
update_option( Flexo_Booking_Features::ENABLED_OPTION, $t7_saved_features );
Flexo_Booking_Features::reset_cache();
t7_flush();
t_reset_inventory();
t_done();
