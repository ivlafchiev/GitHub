<?php
/**
 * Minimal assertion helpers for the Flexo Booking test scripts.
 * Scripts run inside WordPress via: wp eval-file tests/<file>.php
 */

$GLOBALS['flexo_test'] = array( 'pass' => 0, 'fail' => 0 );

function t_ok( $condition, $message ) {
	$GLOBALS['flexo_test'][ $condition ? 'pass' : 'fail' ]++;
	echo ( $condition ? "  PASS " : "  FAIL " ) . $message . "\n";
	return $condition;
}

function t_eq( $expected, $actual, $message ) {
	$ok = $expected === $actual;
	if ( ! $ok && is_float( $expected ) && is_numeric( $actual ) ) {
		$ok = abs( $expected - (float) $actual ) < 0.001;
	}
	return t_ok( $ok, $message . ( $ok ? '' : ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' ) );
}

function t_section( $title ) {
	echo "\n== {$title}\n";
}

function t_done() {
	$r = $GLOBALS['flexo_test'];
	echo "\nResult: {$r['pass']} passed, {$r['fail']} failed\n";
	if ( $r['fail'] ) {
		exit( 1 );
	}
}

/** Creates (or updates) a published room with the given booking meta. */
function t_room( $slug, $title, array $meta ) {
	$existing = get_page_by_path( $slug, OBJECT, 'flexo_room' );
	$id       = $existing ? $existing->ID : wp_insert_post( array( 'post_type' => 'flexo_room', 'post_title' => $title, 'post_name' => $slug, 'post_status' => 'publish' ) );
	$values   = array();
	foreach ( $meta as $key => $value ) {
		$values[ '_flexo_' . $key ] = $value;
	}
	Flexo_Booking_Rooms::save_meta_values( $id, $values );
	return $id;
}

/** Y-m-d for "n days from the Monday after today" – stable weekdays. */
function t_day( $n ) {
	$monday = new DateTimeImmutable( 'monday next week', wp_timezone() );
	return $monday->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' );
}

function t_guest( array $extra = array() ) {
	return array_merge(
		array(
			'guest_name'  => 'Test Guest',
			'guest_email' => 'guest@example.com',
			'guest_phone' => '+359 888 000 000',
			'adults'      => 2,
		),
		$extra
	);
}

/** Clears bookings, seasons and closures between scenarios. */
function t_reset_inventory() {
	global $wpdb;
	foreach ( array( 'bookings', 'seasons', 'closures' ) as $table ) {
		if ( Flexo_Booking_Schema::table_exists( $table ) ) {
			$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
		}
	}
	Flexo_Booking_Seasons::flush_cache();
	Flexo_Booking_Closures::flush_cache();
}
