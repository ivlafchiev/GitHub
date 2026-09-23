<?php
/**
 * Database table creation and upgrades.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Install {

	const DB_VERSION_OPTION = 'flexo_booking_db_version';

	public static function activate() {
		self::install();
		Flexo_Booking_Rooms::register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Runs on every load and installs the table when it is missing or outdated.
	 * This covers multisite sub-sites, sites cloned from a template without
	 * the plugin's table, and plugin updates that change the schema.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== FLEXO_BOOKING_DB_VERSION ) {
			self::install();
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'flexo_bookings';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				reference varchar(20) NOT NULL,
				room_id bigint(20) unsigned NOT NULL,
				check_in date NOT NULL,
				check_out date NOT NULL,
				nights smallint(5) unsigned NOT NULL DEFAULT 1,
				adults tinyint(3) unsigned NOT NULL DEFAULT 1,
				children tinyint(3) unsigned NOT NULL DEFAULT 0,
				guest_name varchar(190) NOT NULL DEFAULT '',
				guest_email varchar(190) NOT NULL DEFAULT '',
				guest_phone varchar(50) NOT NULL DEFAULT '',
				notes text NULL,
				total decimal(10,2) NOT NULL DEFAULT 0,
				currency varchar(10) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				source varchar(20) NOT NULL DEFAULT 'website',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY reference (reference),
				KEY room_dates (room_id,check_in,check_out),
				KEY status (status)
			) {$charset};"
		);

		if ( false === get_option( Flexo_Booking_Settings::OPTION ) ) {
			add_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::defaults() );
		}

		update_option( self::DB_VERSION_OPTION, FLEXO_BOOKING_DB_VERSION );
	}
}
