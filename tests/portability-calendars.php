<?php
/**
 * Target site: calendar connections travel only when asked for, and export
 * links (tokens) are never copied. Needs FLEXO_EXPORT (file from a site with
 * connected calendars) and FLEXO_SOURCE_TOKEN (a source room's token).
 */
require __DIR__ . '/lib.php';
global $wpdb;
$file = json_decode( file_get_contents( getenv( 'FLEXO_EXPORT' ) ), true );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'calendars' ) );
Flexo_Booking_Features::set_enabled( array( 'guest_emails', 'calendar_sync' ) );

t_section( 'Calendar connections in Import/Export' );
t_eq( 3, (int) $file['schema'], 'export schema 3' );
$in_file = 0;
foreach ( $file['rooms'] as $room ) {
	$in_file += count( $room['calendars'] );
	foreach ( $room['calendars'] as $c ) {
		t_ok( ! isset( $c['token'] ) && false === strpos( wp_json_encode( $c ), 'token' ), 'no export token in the file (' . $c['name'] . ')' );
	}
}
t_ok( $in_file >= 3, "file lists {$in_file} connections" );

$r = Flexo_Booking_Portability::import( $file, array() );
t_eq( 0, $r['calendars'], 'default import: connections NOT imported (safe for templates)' );
t_eq( 0, count( Flexo_Booking_ICal::calendars() ), 'no connections on the site' );

$r = Flexo_Booking_Portability::import( $file, array( 'calendars' => true ) );
t_eq( $in_file, $r['calendars'], 'with "calendars": all connections imported' );
$unit2 = wp_list_filter( Flexo_Booking_ICal::calendars(), array( 'unit' => 2 ) );
t_ok( 1 === count( $unit2 ), 'room-number link kept (Room no. 2)' );
$r = Flexo_Booking_Portability::import( $file, array( 'calendars' => true ) );
t_eq( 0, $r['calendars'], 're-import does not duplicate connections' );

$suite = get_page_by_path( 'family-suite', OBJECT, 'flexo_room' );
t_ok( Flexo_Booking_ICal::token( $suite->ID ) !== getenv( 'FLEXO_SOURCE_TOKEN' ), 'this site has its own export link (new token)' );
t_done();
