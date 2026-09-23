<?php
/**
 * Named locks used to serialise inventory changes and migrations.
 *
 * On MySQL/MariaDB this uses GET_LOCK(), which is connection-scoped and can
 * never be left behind by a crashed request. On other databases (e.g. the
 * SQLite integration) it falls back to a row in wp_options: a plain INSERT on
 * the unique option_name key only succeeds for one request.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Lock {

	/**
	 * Locks held by this request: name => 'mysql' | 'option'.
	 *
	 * @var array
	 */
	private static $held = array();

	/**
	 * @param string $name    Lock name (unique per site).
	 * @param int    $timeout Seconds to wait. 0 = try once.
	 * @return bool
	 */
	public static function acquire( $name, $timeout = 10 ) {
		global $wpdb;

		if ( isset( self::$held[ $name ] ) ) {
			return true;
		}

		if ( self::use_mysql() ) {
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::mysql_name( $name ), max( 0, (int) $timeout ) ) );
			if ( '1' === (string) $result ) {
				self::$held[ $name ] = 'mysql';
				return true;
			}
			if ( '0' === (string) $result ) {
				return false; // Timed out: someone else holds it.
			}
			// NULL = GET_LOCK not supported by this server; fall back below.
		}

		$attempts = max( 1, (int) $timeout * 10 );
		$option   = self::option_name( $name );
		for ( $i = 0; $i < $attempts; $i++ ) {
			$suppress = $wpdb->suppress_errors( true );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, time() ) );
			$wpdb->suppress_errors( $suppress );

			if ( $inserted ) {
				self::$held[ $name ] = 'option';
				return true;
			}

			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			if ( $since && time() - $since > 60 ) {
				// Left behind by a request that died while holding it.
				$wpdb->delete( $wpdb->options, array( 'option_name' => $option ) );
				continue;
			}
			if ( $i < $attempts - 1 ) {
				usleep( 100000 );
			}
		}
		return false;
	}

	public static function release( $name ) {
		global $wpdb;

		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		if ( 'mysql' === self::$held[ $name ] ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::mysql_name( $name ) ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::option_name( $name ) ) );
		}
		unset( self::$held[ $name ] );
	}

	private static function use_mysql() {
		$is_mysql = ! defined( 'DB_ENGINE' ) || 'mysql' === DB_ENGINE;
		return (bool) apply_filters( 'flexo_booking_use_mysql_locks', $is_mysql );
	}

	/**
	 * GET_LOCK names are server-wide and limited to 64 characters, so the
	 * database and table prefix are hashed in to keep sites apart.
	 */
	private static function mysql_name( $name ) {
		global $wpdb;
		return 'flexo_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . $name );
	}

	private static function option_name( $name ) {
		return '_flexo_lock_' . md5( $name );
	}
}
