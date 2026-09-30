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

	public static function init() {
		add_action( 'flexo_booking_created', array( __CLASS__, 'on_created' ), 20 );
		add_action( 'flexo_booking_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
		add_action( 'flexo_booking_payment_recorded', array( __CLASS__, 'on_payment' ), 20, 3 );
		add_action( 'flexo_booking_refund_recorded', array( __CLASS__, 'on_refund' ), 20, 3 );
		add_action( 'flexo_booking_anonymised', array( __CLASS__, 'on_anonymised' ), 20 );
	}

	public static function on_created( $booking ) {
		self::add( $booking['id'], 'created', array( 'status' => $booking['status'], 'source' => $booking['source'] ) );
	}

	public static function on_status( $booking, $old, $new, $context = array() ) {
		self::add(
			$booking['id'],
			'status',
			array(
				'from'   => $old,
				'to'     => $new,
				'reason' => isset( $context['reason'] ) ? (string) $context['reason'] : '',
			)
		);
	}

	public static function on_payment( $booking, $result, $payment ) {
		self::add(
			$booking['id'],
			'payment',
			array(
				'amount'   => isset( $payment['amount'] ) ? (float) $payment['amount'] : 0,
				'gateway'  => isset( $payment['gateway'] ) ? $payment['gateway'] : '',
				'currency' => $booking['currency'],
			)
		);
	}

	public static function on_refund( $booking, $amount, $gateway ) {
		self::add(
			$booking['id'],
			'refund',
			array(
				'amount'   => (float) $amount,
				'gateway'  => $gateway,
				'currency' => $booking['currency'],
			)
		);
	}

	public static function on_anonymised( $booking_id ) {
		self::add( $booking_id, 'anonymised', array() );
	}

	/**
	 * One history line in the hotel's words.
	 */
	public static function describe( array $entry ) {
		$d = $entry['details'];
		switch ( $entry['action'] ) {
			case 'created':
				return isset( $d['source'] ) && 'admin' === $d['source'] ? __( 'Added by staff', 'flexo-booking' ) : __( 'Booked on the website', 'flexo-booking' );
			case 'status':
				/* translators: 1: old status, 2: new status */
				return sprintf( __( 'Status: %1$s → %2$s', 'flexo-booking' ), Flexo_Booking_Bookings::status_label( isset( $d['from'] ) ? $d['from'] : '' ), Flexo_Booking_Bookings::status_label( isset( $d['to'] ) ? $d['to'] : '' ) );
			case 'payment':
				/* translators: 1: amount, 2: payment method */
				return sprintf( __( 'Payment of %1$s recorded (%2$s)', 'flexo-booking' ), Flexo_Booking_Money::format( isset( $d['amount'] ) ? $d['amount'] : 0, isset( $d['currency'] ) ? $d['currency'] : null ), Flexo_Booking_Payments::method_label( isset( $d['gateway'] ) ? $d['gateway'] : '' ) );
			case 'refund':
				/* translators: %s: amount */
				return sprintf( __( 'Refund of %s recorded', 'flexo-booking' ), Flexo_Booking_Money::format( isset( $d['amount'] ) ? $d['amount'] : 0, isset( $d['currency'] ) ? $d['currency'] : null ) );
			case 'note':
				return __( 'Staff note', 'flexo-booking' ) . ': ' . ( isset( $d['text'] ) ? $d['text'] : '' );
			case 'email_resent':
				/* translators: %s: email name */
				return sprintf( __( 'Email sent again: %s', 'flexo-booking' ), Flexo_Booking_Emails::type_label( 'guest_' . ( isset( $d['type'] ) ? $d['type'] : '' ) ) );
			case 'guest_request':
				if ( ! empty( $d['erased'] ) ) {
					return __( 'Guest request (personal data removed)', 'flexo-booking' );
				}
				$types = Flexo_Booking_Guest::request_types();
				/* translators: 1: request, 2: message */
				return sprintf( __( 'Guest asked: "%1$s" %2$s', 'flexo-booking' ), isset( $types[ $d['type'] ] ) ? $types[ $d['type'] ] : '', '' !== (string) $d['message'] ? '– ' . $d['message'] : '' );
			case 'request_answered':
				return __( 'Guest request marked as answered', 'flexo-booking' );
			case 'anonymised':
				return __( 'Personal data removed', 'flexo-booking' );
		}
		return $entry['action'];
	}

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
