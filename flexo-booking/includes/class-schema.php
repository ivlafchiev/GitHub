<?php
/**
 * Database schema: the current definition of every plugin table.
 *
 * dbDelta() compares these statements with the database and adds missing
 * tables and columns. Columns are only ever added, never dropped or renamed.
 * See IMPLEMENTATION_PLAN.md §2 for the tables later days will add.
 *
 * Date conventions:
 * - bookings: check_in = first night, check_out = departure day (exclusive).
 * - seasons, closures: date_from / date_to = first / last night (inclusive).
 * - calendar_events: date_from = first night, date_to = departure (exclusive),
 *   exactly like an iCal all-day DTSTART / DTEND.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Schema {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'flexo_' . $name;
	}

	/**
	 * @return string[] Table key => CREATE TABLE statement.
	 */
	public static function tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$tables  = array();

		$tables['bookings'] = 'CREATE TABLE ' . self::table( 'bookings' ) . " (
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
			price_breakdown longtext NULL,
			children_ages varchar(100) NOT NULL DEFAULT '',
			rate_plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			promo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			promo_code varchar(50) NOT NULL DEFAULT '',
			discount_total decimal(10,2) NOT NULL DEFAULT 0,
			tax_total decimal(10,2) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY room_dates (room_id,check_in,check_out),
			KEY status (status),
			KEY promo (promo_id)
		) {$charset};";

		$tables['seasons'] = 'CREATE TABLE ' . self::table( 'seasons' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			date_from date NOT NULL,
			date_to date NOT NULL,
			price decimal(10,2) NOT NULL DEFAULT 0,
			weekend_price decimal(10,2) NULL,
			min_nights smallint(5) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY room_dates (room_id,date_from,date_to)
		) {$charset};";

		$tables['closures'] = 'CREATE TABLE ' . self::table( 'closures' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_id bigint(20) unsigned NOT NULL DEFAULT 0,
			date_from date NOT NULL,
			date_to date NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY room_dates (room_id,date_from,date_to)
		) {$charset};";

		$tables['calendars'] = 'CREATE TABLE ' . self::table( 'calendars' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			room_id bigint(20) unsigned NOT NULL,
			name varchar(100) NOT NULL DEFAULT '',
			import_url text NOT NULL,
			unit smallint(5) unsigned NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			last_synced_at datetime NULL,
			last_status varchar(20) NOT NULL DEFAULT '',
			last_error text NULL,
			fail_count smallint(5) unsigned NOT NULL DEFAULT 0,
			event_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY room (room_id)
		) {$charset};";

		$tables['calendar_events'] = 'CREATE TABLE ' . self::table( 'calendar_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			calendar_id bigint(20) unsigned NOT NULL,
			room_id bigint(20) unsigned NOT NULL,
			unit smallint(5) unsigned NOT NULL DEFAULT 0,
			uid varchar(255) NOT NULL,
			date_from date NOT NULL,
			date_to date NOT NULL,
			summary varchar(190) NOT NULL DEFAULT '',
			conflict tinyint(1) NOT NULL DEFAULT 0,
			conflict_note varchar(255) NOT NULL DEFAULT '',
			conflict_notified tinyint(1) NOT NULL DEFAULT 0,
			conflict_reviewed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cal_uid (calendar_id,uid(191)),
			KEY room_dates (room_id,date_from,date_to)
		) {$charset};";

		// Day 3. Which rooms offer a plan is stored on the room
		// (meta _flexo_rate_plans), so it travels with the room.
		$tables['rate_plans'] = 'CREATE TABLE ' . self::table( 'rate_plans' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL DEFAULT '',
			description text NULL,
			preset varchar(30) NOT NULL DEFAULT '',
			adjustment_type varchar(20) NOT NULL DEFAULT 'per_night',
			adjustment_value decimal(10,3) NOT NULL DEFAULT 0,
			refundable tinyint(1) NOT NULL DEFAULT 1,
			cancellation_policy text NULL,
			active tinyint(1) NOT NULL DEFAULT 0,
			sort_order int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset};";

		// Day 3. Usage is counted from confirmed bookings (bookings.promo_id),
		// so cancelling a booking gives the use back automatically.
		$tables['promo_codes'] = 'CREATE TABLE ' . self::table( 'promo_codes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(50) NOT NULL,
			description varchar(190) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			discount_type varchar(10) NOT NULL DEFAULT 'percent',
			discount_value decimal(10,2) NOT NULL DEFAULT 0,
			book_from date NULL,
			book_to date NULL,
			stay_from date NULL,
			stay_to date NULL,
			min_amount decimal(10,2) NULL,
			min_nights smallint(5) unsigned NULL,
			max_uses int(10) unsigned NULL,
			room_ids text NULL,
			rate_plan_ids text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) {$charset};";

		return $tables;
	}

	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( array_values( self::tables() ) );
	}

	public static function table_exists( $name ) {
		global $wpdb;
		$table = self::table( $name );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function column_exists( $name, $column ) {
		global $wpdb;
		$table = self::table( $name );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name built above.
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ) as $row ) {
			if ( isset( $row->Field ) && $column === $row->Field ) {
				return true;
			}
		}
		return false;
	}
}
