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

foreach ( array( 'bookings', 'seasons', 'closures', 'calendars', 'calendar_events', 'rate_plans', 'promo_codes', 'consents', 'invoices', 'email_log', 'payments', 'webhook_events', 'booking_log' ) as $flexo_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}flexo_{$flexo_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
foreach ( array( 'flexo_booking_settings', 'flexo_booking_db_version', 'flexo_booking_enabled_features', 'flexo_booking_available_features', 'flexo_booking_migration_error', 'flexo_booking_add_rate_plan_presets', 'flexo_booking_smtp_notice', 'flexo_booking_payment_secrets', 'flexo_booking_wizard', 'flexo_booking_wizard_redirect', 'flexo_booking_support', 'flexo_booking_cron_last', 'flexo_booking_stripe_check', 'flexo_booking_last_test_email' ) as $flexo_option ) {
	delete_option( $flexo_option );
}
wp_clear_scheduled_hook( 'flexo_booking_ical_sync' );
wp_clear_scheduled_hook( 'flexo_booking_hourly' );
wp_clear_scheduled_hook( 'flexo_booking_daily' );
wp_unschedule_hook( 'flexo_booking_expire_hold' );

// Day 6: the Hotel Staff / Hotel Manager roles and the plugin's capabilities.
foreach ( array( 'hotel_staff', 'hotel_manager' ) as $flexo_role ) {
	remove_role( $flexo_role );
}
foreach ( array( 'administrator', 'editor' ) as $flexo_role ) {
	$flexo_role = get_role( $flexo_role );
	if ( $flexo_role ) {
		foreach ( array( 'flexo_manage_bookings', 'flexo_manage_prices', 'flexo_manage_settings' ) as $flexo_cap ) {
			$flexo_role->remove_cap( $flexo_cap );
		}
	}
}
delete_transient( 'flexo_booking_page_detected' );
