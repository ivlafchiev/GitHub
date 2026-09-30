<?php
/**
 * Booking history: what happened to a booking, when and by whom (status
 * changes, payments, emails resent, guest requests, notes). Enquiries from
 * the booking form are kept here too, with booking ID 0.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Log {

	public static function table() {
		return Flexo_Booking_Schema::table( 'booking_log' );
	}

	/**
	 * @param int          $booking_id
	 * @param string       $action  e.g. "created", "status", "guest_request", "enquiry", "note", "email_resent".
	 * @param array|string $details Stored as JSON.
	 * @param int|null     $user_id Defaults to the current user (0 = guest or system).
	 * @return int Entry ID (0 when the table is missing).
	 */
	public static function add( $booking_id, $action, $details = array(), $user_id = null ) {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::table_exists( 'booking_log' ) ) {
			return 0;
		}
		$wpdb->insert(
			self::table(),
			array(
				'booking_id' => (int) $booking_id,
				'action'     => substr( sanitize_key( $action ), 0, 40 ),
				'details'    => wp_json_encode( $details ),
				'user_id'    => null === $user_id ? get_current_user_id() : (int) $user_id,
				'created_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array[] Newest first.
	 */
	public static function for_booking( $booking_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the schema.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id DESC", $booking_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Entries of one kind, newest first.
	 *
	 * @param string $since Y-m-d H:i:s, optional.
	 * @return array[]
	 */
	public static function recent( $action, $since = '', $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the schema.
		$rows = '' === $since
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT %d", $action, $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s AND created_at >= %s ORDER BY id DESC LIMIT %d", $action, $since, $limit ), ARRAY_A );
		// phpcs:enable
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * How many entries of a kind a booking got since a time (rate limits).
	 */
	public static function count_since( $booking_id, $action, $since ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the schema.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE booking_id = %d AND action = %s AND created_at >= %s", $booking_id, $action, $since ) );
	}

	public static function update_details( $id, array $details ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'details' => wp_json_encode( $details ) ), array( 'id' => (int) $id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the schema.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Personal data in enquiries and guest requests is removed with the
	 * booking's (anonymise) and after the retention period.
	 */
	public static function erase_booking( $booking_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET details = %s WHERE booking_id = %d AND action IN ('guest_request','enquiry')", wp_json_encode( array( 'erased' => true ) ), $booking_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Enquiries by an email address (personal data export and erase).
	 *
	 * @return array[]
	 */
	public static function enquiries_by_email( $email ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the schema.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = 0 AND action = 'enquiry' AND details LIKE %s", '%' . $wpdb->esc_like( '"email":' . wp_json_encode( $email ) ) . '%' ), ARRAY_A );
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$row = self::hydrate( $row );
			if ( isset( $row['details']['email'] ) && 0 === strcasecmp( $row['details']['email'], $email ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Enquiries older than the retention period (default 12 months) are deleted.
	 */
	public static function cleanup() {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::table_exists( 'booking_log' ) ) {
			return;
		}
		$months = (int) Flexo_Booking_Settings::get( 'retention_months' );
		$months = $months > 0 ? $months : 12;
		$before = wp_date( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE booking_id = 0 AND action = 'enquiry' AND created_at < %s", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function hydrate( array $row ) {
		$details = json_decode( (string) $row['details'], true );
		return array(
			'id'         => (int) $row['id'],
			'booking_id' => (int) $row['booking_id'],
			'action'     => $row['action'],
			'details'    => is_array( $details ) ? $details : array(),
			'user_id'    => (int) $row['user_id'],
			'created_at' => $row['created_at'],
		);
	}
}
