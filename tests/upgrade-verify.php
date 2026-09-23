<?php
/**
 * Runs after swapping in the new plugin code on a site prepared by
 * upgrade-fixture.php. Verifies nothing was lost.
 */
require __DIR__ . '/lib.php';
global $wpdb;

$fixture = json_decode( file_get_contents( getenv( 'FLEXO_FIXTURE' ) ), true );

t_section( 'Upgrade from ' . $fixture['plugin'] . ' to ' . FLEXO_BOOKING_VERSION );
t_eq( (string) Flexo_Booking_Migrations::LATEST, get_option( 'flexo_booking_db_version' ), 'database version is latest' );
t_ok( false === get_option( Flexo_Booking_Migrations::ERROR_OPTION ), 'no migration error recorded' );
foreach ( array( 'bookings', 'seasons', 'closures' ) as $table ) {
	t_ok( Flexo_Booking_Schema::table_exists( $table ), "table {$table} exists" );
}
$row     = $wpdb->get_row( 'SELECT * FROM ' . $wpdb->prefix . 'flexo_bookings LIMIT 1', ARRAY_A );
t_ok( array_key_exists( 'price_breakdown', $row ), 'price_breakdown column added' );

$now = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'flexo_bookings ORDER BY id', ARRAY_A );
t_eq( count( $fixture['bookings'] ), count( $now ), 'same number of bookings' );
$same = true;
foreach ( $fixture['bookings'] as $i => $old ) {
	foreach ( $old as $key => $value ) {
		if ( ! isset( $now[ $i ] ) || (string) $now[ $i ][ $key ] !== (string) $value ) {
			$same = false;
			echo "    differs: booking {$i} {$key}\n";
		}
	}
}
t_ok( $same, 'every booking field unchanged' );

$settings = get_option( 'flexo_booking_settings' );
$kept     = true;
foreach ( $fixture['settings'] as $key => $value ) {
	if ( $settings[ $key ] !== $value ) {
		$kept = false;
		echo "    differs: setting {$key}\n";
	}
}
t_ok( $kept, 'every saved setting unchanged' );
t_eq( 'instant', Flexo_Booking_Features::booking_mode(), 'booking mode still instant' );
t_ok( Flexo_Booking_Features::is_enabled( 'guest_emails' ), 'guest emails still on' );
t_ok( ! Flexo_Booking_Features::is_enabled( 'seasonal_pricing' ), 'seasonal prices off after upgrade' );

foreach ( $fixture['rooms'] as $slug => $meta ) {
	$post = get_page_by_path( $slug, OBJECT, 'flexo_room' );
	t_ok( $post && get_post_meta( $post->ID ) == $meta, "room {$slug} meta unchanged" ); // phpcs:ignore Universal.Operators.StrictComparisons
}

// Old bookings show their stored total as a single line.
$old  = Flexo_Booking_Bookings::get( (int) $now[0]['id'] );
$snap = Flexo_Booking_Pricing::snapshot( $old );
t_eq( (float) $old['total'], (float) $snap['lines'][0]['amount'], 'old booking breakdown = stored total' );

// Old bookings still block availability exactly as before.
$room = Flexo_Booking_Rooms::to_array( get_page_by_path( 'deluxe-double', OBJECT, 'flexo_room' ) );
t_eq( 1, Flexo_Booking_Inventory::units_available( $room, t_day( 0 ), t_day( 6 ) ), 'existing bookings still occupy units' );
$suite = Flexo_Booking_Rooms::to_array( get_page_by_path( 'family-suite', OBJECT, 'flexo_room' ) );
t_eq( 0, Flexo_Booking_Inventory::units_available( $suite, t_day( 20 ), t_day( 21 ) ), 'old blocked dates still block' );

t_done();
