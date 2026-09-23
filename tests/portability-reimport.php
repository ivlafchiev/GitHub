<?php
/** Importing the same file again must not duplicate anything. */
require __DIR__ . '/lib.php';
t_section( 'Re-import is safe' );
$a = get_page_by_path( 'sea-view', OBJECT, 'flexo_room' );
t_eq( 2, count( Flexo_Booking_Seasons::for_room( $a->ID ) ), 'seasons replaced, not duplicated' );
t_eq( 2, count( Flexo_Booking_Closures::all() ), 'closures not duplicated' );
$file = json_decode( file_get_contents( getenv( 'FLEXO_EXPORT' ) ), true );
t_eq( count( $file['rooms'] ), count( Flexo_Booking_Rooms::all( 'any' ) ), 'rooms not duplicated (one per room in the file)' );
t_done();
