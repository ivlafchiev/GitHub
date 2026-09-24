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

foreach ( array( 'bookings', 'seasons', 'closures', 'calendars', 'calendar_events', 'rate_plans', 'promo_codes', 'consents', 'invoices', 'email_log' ) as $flexo_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}flexo_{$flexo_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
foreach ( array( 'flexo_booking_settings', 'flexo_booking_db_version', 'flexo_booking_enabled_features', 'flexo_booking_available_features', 'flexo_booking_migration_error', 'flexo_booking_add_rate_plan_presets', 'flexo_booking_smtp_notice' ) as $flexo_option ) {
	delete_option( $flexo_option );
}
wp_clear_scheduled_hook( 'flexo_booking_ical_sync' );
wp_clear_scheduled_hook( 'flexo_booking_hourly' );
wp_clear_scheduled_hook( 'flexo_booking_daily' );
