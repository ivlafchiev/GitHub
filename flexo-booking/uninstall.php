<?php
/**
 * Removes plugin data on uninstall – only when the site owner opted in under
 * Bookings → Settings, so deleting the plugin never loses bookings by accident.
 *
 * @package FlexoBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$flexo_settings = get_option( 'flexo_booking_settings', array() );

if ( empty( $flexo_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$flexo_rooms = get_posts(
	array(
		'post_type'      => 'flexo_room',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $flexo_rooms as $flexo_room_id ) {
	wp_delete_post( $flexo_room_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}flexo_bookings" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'flexo_booking_settings' );
delete_option( 'flexo_booking_db_version' );
