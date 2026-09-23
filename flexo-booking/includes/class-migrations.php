<?php
/**
 * Versioned database migrations.
 *
 * Runs automatically on every load when the stored version is behind, which
 * covers plugin updates, multisite sub-sites and cloned sites. Each step is
 * idempotent; the stored version is bumped after every successful step, and a
 * failed step is retried on the next load (with an admin notice meanwhile).
 *
 * Adding a migration: add the next number to steps(), write the method, and
 * bump LATEST. Table changes go into Flexo_Booking_Schema.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Migrations {

	const OPTION       = 'flexo_booking_db_version';
	const ERROR_OPTION = 'flexo_booking_migration_error';
	const LATEST       = 3;

	/**
	 * @return array Version => method name.
	 */
	private static function steps() {
		return array(
			1 => 'migrate_1_initial',
			2 => 'migrate_2_day1',
			3 => 'migrate_3_day2',
		);
	}

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function current_version() {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * @return bool True when the database is up to date.
	 */
	public static function run() {
		if ( self::current_version() >= self::LATEST ) {
			return true;
		}

		// Another request is already migrating; this one carries on normally.
		if ( ! Flexo_Booking_Lock::acquire( 'migrations', 0 ) ) {
			return false;
		}

		try {
			$version = self::current_version();
			if ( $version >= self::LATEST ) {
				return true;
			}

			Flexo_Booking_Schema::install();

			foreach ( self::steps() as $step => $method ) {
				if ( $step <= $version ) {
					continue;
				}
				$result = call_user_func( array( __CLASS__, $method ) );
				if ( is_wp_error( $result ) ) {
					update_option( self::ERROR_OPTION, $step . ': ' . $result->get_error_message(), false );
					return false;
				}
				update_option( self::OPTION, (string) $step );
			}

			delete_option( self::ERROR_OPTION );
			return true;
		} finally {
			Flexo_Booking_Lock::release( 'migrations' );
		}
	}

	/**
	 * 1.0.0: bookings table and default settings.
	 */
	private static function migrate_1_initial() {
		if ( ! Flexo_Booking_Schema::table_exists( 'bookings' ) ) {
			return new WP_Error( 'flexo_migration', 'bookings table missing' );
		}
		if ( false === get_option( Flexo_Booking_Settings::OPTION ) ) {
			add_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::defaults() );
		}
		return true;
	}

	/**
	 * Day 1 (1.1.0): seasons, closures, price snapshots, feature switches.
	 */
	private static function migrate_2_day1() {
		foreach ( array( 'seasons', 'closures' ) as $table ) {
			if ( ! Flexo_Booking_Schema::table_exists( $table ) ) {
				return new WP_Error( 'flexo_migration', $table . ' table missing' );
			}
		}

		// Keep today's behaviour: both booking modes and guest emails on.
		// Seasonal prices stay off until the hotel switches them on.
		if ( false === get_option( Flexo_Booking_Features::ENABLED_OPTION ) ) {
			add_option( Flexo_Booking_Features::ENABLED_OPTION, Flexo_Booking_Features::default_enabled() );
		}
		return true;
	}

	/**
	 * Day 2 (1.2.0): calendar sync tables.
	 */
	private static function migrate_3_day2() {
		foreach ( array( 'calendars', 'calendar_events' ) as $table ) {
			if ( ! Flexo_Booking_Schema::table_exists( $table ) ) {
				return new WP_Error( 'flexo_migration', $table . ' table missing' );
			}
		}
		return true;
	}

	public static function admin_notice() {
		$error = get_option( self::ERROR_OPTION );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <code>%s</code></p></div>',
			esc_html__( 'Flexo Booking:', 'flexo-booking' ),
			esc_html__( 'the booking database update did not finish and will be retried automatically. If this message stays, please contact FlexoHotels support.', 'flexo-booking' ),
			esc_html( $error )
		);
	}
}
