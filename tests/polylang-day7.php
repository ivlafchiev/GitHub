<?php
/**
 * Day 7 with Polylang (Bulgarian main language, English second): a room
 * and its English translation. The translation shows its own texts; the
 * booking engine only knows the Bulgarian (main) room – one room in the
 * booking form, one inventory, bookings on the main room.
 *
 *   BASE=http://localhost:8095 wp eval-file tests/polylang-day7.php
 */
require __DIR__ . '/lib.php';

if ( ! function_exists( 'pll_set_post_language' ) ) {
	echo "Polylang is not active\n";
	exit( 1 );
}

t_reset_inventory();
$base = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();

$bg = wp_insert_post( array( 'post_type' => 'flexo_room', 'post_title' => 'Т7 Двойна стая', 'post_name' => 't7p-double', 'post_status' => 'publish', 'post_excerpt' => 'Светла стая с балкон.', 'post_content' => 'Описание на български.' ) );
Flexo_Booking_Rooms::save_meta_values( $bg, array( '_flexo_price' => 100, '_flexo_capacity' => 2, '_flexo_units' => 1, '_flexo_size' => 25, '_flexo_beds' => '1 двойно легло' ) );
Flexo_Booking_Room_Editor::save_amenities( $bg, array( array( 'key' => 'wifi' ), array( 'key' => '', 'label' => 'Тераса към морето' ) ) );
pll_set_post_language( $bg, 'bg' );
$en = wp_insert_post( array( 'post_type' => 'flexo_room', 'post_title' => 'T7 Double room', 'post_name' => 't7p-double-en', 'post_status' => 'publish', 'post_excerpt' => 'Bright room with a balcony.', 'post_content' => 'Description in English.' ) );
pll_set_post_language( $en, 'en' );
pll_save_post_translations( array( 'bg' => $bg, 'en' => $en ) );
update_post_meta( $en, '_flexo_beds', '1 double bed' );
Flexo_Booking_Room_Editor::save_amenities( $en, array( array( 'key' => 'wifi' ), array( 'key' => '', 'label' => 'Sea-facing terrace' ) ) );

t_section( 'Translatable rooms' );
t_ok( pll_is_translated_post_type( 'flexo_room' ), 'rooms are translatable in Polylang' );
t_ok( pll_is_translated_taxonomy( 'flexo_room_type' ), 'room types are translatable' );
t_ok( in_array( '_flexo_price', apply_filters( 'pll_copy_post_metas', array(), true, $bg ), true ), 'booking data kept in step between translations' );
t_ok( in_array( '_flexo_view', apply_filters( 'pll_copy_post_metas', array(), false, $bg ), true ) && ! in_array( '_flexo_view', apply_filters( 'pll_copy_post_metas', array(), true, $bg ), true ), 'texts copied once, then translated' );

t_section( 'One room for the booking engine' );
$ids = wp_list_pluck( Flexo_Booking_Rooms::all(), 'ID' );
t_ok( in_array( $bg, $ids, true ) && ! in_array( $en, $ids, true ), 'translations are not extra rooms' );
t_eq( $bg, Flexo_Booking_Rooms::find( 't7p-double-en' )->ID, 'a link with the English slug books the main room' );
t_eq( $bg, Flexo_Booking_Room_I18n::canonical_id( $en ), 'main room of the translation' );
switch_to_locale( 'en_US' );
$s     = Flexo_Booking_Bookings::search( t_day( 3 ), t_day( 5 ), 2, 0 );
$found = array_values( array_filter( $s['rooms'], static function ( $r ) use ( $bg ) { return $r['id'] === $bg; } ) );
t_ok( 1 === count( $found ) && 'T7 Double room' === $found[0]['title'], 'booking form in English: one room, English name' );
t_eq( array( 'Free Wi-Fi', 'Sea-facing terrace' ), $found[0]['amenities'], 'English amenities' );
restore_previous_locale();
$s     = Flexo_Booking_Bookings::search( t_day( 3 ), t_day( 5 ), 2, 0 );
$found = array_values( array_filter( $s['rooms'], static function ( $r ) use ( $bg ) { return $r['id'] === $bg; } ) );
t_eq( 'Т7 Двойна стая', $found[0]['title'], 'booking form in Bulgarian: Bulgarian name' );
$b = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 't7p-double-en', 'check_in' => t_day( 3 ), 'check_out' => t_day( 5 ), 'privacy_consent' => 1 ) ) );
t_ok( ! is_wp_error( $b ) && $bg === (int) $b['room_id'], 'booking through the English room is stored on the main room' );
$s = Flexo_Booking_Bookings::search( t_day( 3 ), t_day( 5 ), 2, 0, 't7p-double-en' );
t_ok( ! $s['rooms'][0]['available'], 'same inventory in every language (sold out in English too)' );

t_section( 'Room page content per language' );
$v = Flexo_Booking_Room_Content::room( $en );
t_eq( 'T7 Double room', $v['title'], 'English name' );
t_eq( '1 double bed', $v['beds'], 'English beds' );
t_eq( 100.0, $v['price'], 'price from the main room' );
t_eq( 25, $v['size'], 'size from the main room' );
t_eq( 't7p-double', $v['slug'], 'booking slug of the main room' );
t_ok( false !== strpos( $v['booking_url'], 'room=t7p-double' ) && false === strpos( $v['booking_url'], 'room=t7p-double-en' ), 'booking link of the translation books the main room' );
t_ok( false !== strpos( $v['url'], '/en/' ), 'English room page address' );
update_post_meta( $bg, Flexo_Booking_Room_Content::HIDDEN, '1' );
t_ok( Flexo_Booking_Room_Content::is_hidden( $en ), 'hiding the main room hides its translations' );
delete_post_meta( $bg, Flexo_Booking_Room_Content::HIDDEN );
t_eq( Flexo_Booking_Room_Prices::get( $bg ), Flexo_Booking_Room_Prices::get( $en ), 'same "from" price in every language' );

t_section( 'Room pages over HTTP' );
$get = static function ( $path ) use ( $base ) {
	$r = wp_remote_get( $base . $path, array( 'timeout' => 20, 'redirection' => 0 ) );
	return is_wp_error( $r ) ? array( 0, '' ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
};
list( $code, $body ) = $get( '/en/rooms/t7p-double-en/' );
t_eq( 200, $code, 'English room page' );
t_ok( false !== strpos( $body, 'T7 Double room' ) && false !== strpos( $body, 'Sea-facing terrace' ), 'English texts on the English page' );
t_ok( false !== strpos( $body, 'data-room="t7p-double"' ), 'its booking box books the main room' );
list( $code, $body ) = $get( '/rooms/t7p-double/' );
t_ok( 200 === $code && false !== strpos( $body, 'Т7 Двойна стая' ), 'Bulgarian room page' );

wp_delete_post( $en, true );
wp_delete_post( $bg, true );
t_reset_inventory();
t_done();
