<?php
/**
 * Booking storage, validation, pricing and availability.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Bookings {

	/**
	 * Statuses that occupy a room. "blocked" is used by staff to close dates
	 * (maintenance, bookings taken on other channels, ...).
	 */
	const OCCUPYING = array( 'pending', 'confirmed', 'blocked' );

	public static function statuses() {
		return array(
			'pending'   => __( 'Pending', 'flexo-booking' ),
			'confirmed' => __( 'Confirmed', 'flexo-booking' ),
			'cancelled' => __( 'Cancelled', 'flexo-booking' ),
			'blocked'   => __( 'Blocked (closed)', 'flexo-booking' ),
		);
	}

	public static function status_label( $status ) {
		$statuses = self::statuses();
		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}

	/**
	 * Parses and validates a stay. Returns an array with the normalised dates
	 * and night count, or a WP_Error.
	 *
	 * @param string $check_in  Y-m-d.
	 * @param string $check_out Y-m-d.
	 * @param bool   $enforce_rules Apply guest-facing rules (min/max nights, no past dates).
	 * @param int    $min_nights    Minimum nights for the chosen room.
	 */
	public static function validate_dates( $check_in, $check_out, $enforce_rules = true, $min_nights = 0 ) {
		$tz  = wp_timezone();
		$in  = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $check_in, $tz );
		$out = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $check_out, $tz );

		if ( ! $in || ! $out || $in->format( 'Y-m-d' ) !== $check_in || $out->format( 'Y-m-d' ) !== $check_out ) {
			return new WP_Error( 'flexo_invalid_dates', __( 'Please choose valid check-in and check-out dates.', 'flexo-booking' ) );
		}

		$nights = (int) $in->diff( $out )->format( '%r%a' );
		if ( $nights < 1 ) {
			return new WP_Error( 'flexo_invalid_dates', __( 'Check-out must be after check-in.', 'flexo-booking' ) );
		}

		if ( $enforce_rules ) {
			$today = new DateTimeImmutable( 'today', $tz );
			if ( $in < $today ) {
				return new WP_Error( 'flexo_past_date', __( 'Check-in cannot be in the past.', 'flexo-booking' ) );
			}
			$advance = (int) Flexo_Booking_Settings::get( 'max_advance_days' );
			if ( $in > $today->modify( '+' . $advance . ' days' ) ) {
				/* translators: %d: number of days */
				return new WP_Error( 'flexo_too_far', sprintf( __( 'Bookings can be made up to %d days in advance.', 'flexo-booking' ), $advance ) );
			}
			$min = $min_nights > 0 ? $min_nights : (int) Flexo_Booking_Settings::get( 'min_nights' );
			$max = (int) Flexo_Booking_Settings::get( 'max_nights' );
			if ( $nights < $min ) {
				/* translators: %d: number of nights */
				return new WP_Error( 'flexo_min_nights', sprintf( _n( 'The minimum stay is %d night.', 'The minimum stay is %d nights.', $min, 'flexo-booking' ), $min ) );
			}
			if ( $nights > $max ) {
				/* translators: %d: number of nights */
				return new WP_Error( 'flexo_max_nights', sprintf( _n( 'The maximum stay is %d night.', 'The maximum stay is %d nights.', $max, 'flexo-booking' ), $max ) );
			}
		}

		return array(
			'check_in'  => $in->format( 'Y-m-d' ),
			'check_out' => $out->format( 'Y-m-d' ),
			'nights'    => $nights,
		);
	}

	/**
	 * Total price of a stay. Friday and Saturday nights use the weekend price
	 * when one is set.
	 */
	public static function calculate_total( array $room, $check_in, $check_out ) {
		$tz    = wp_timezone();
		$night = new DateTimeImmutable( $check_in, $tz );
		$end   = new DateTimeImmutable( $check_out, $tz );
		$total = 0.0;

		while ( $night < $end ) {
			$is_weekend = in_array( (int) $night->format( 'N' ), array( 5, 6 ), true );
			$total     += ( $is_weekend && $room['weekend_price'] > 0 ) ? $room['weekend_price'] : $room['price'];
			$night      = $night->modify( '+1 day' );
		}

		return (float) apply_filters( 'flexo_booking_calculate_total', round( $total, 2 ), $room, $check_in, $check_out );
	}

	/**
	 * Number of rooms of this type still free for every night of the stay.
	 *
	 * Bookings are counted night by night, so two short stays that don't
	 * overlap each other only take one unit.
	 */
	public static function units_available( array $room, $check_in, $check_out, $exclude_id = 0 ) {
		global $wpdb;

		if ( $room['units'] < 1 ) {
			return 0;
		}

		$table        = Flexo_Booking_Install::table();
		$placeholders = implode( ',', array_fill( 0, count( self::OCCUPYING ), '%s' ) );
		$params       = array_merge( array( $room['id'], $check_out, $check_in, (int) $exclude_id ), self::OCCUPYING );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders are built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT check_in, check_out FROM {$table} WHERE room_id = %d AND check_in < %s AND check_out > %s AND id <> %d AND status IN ({$placeholders})", $params ) );

		$tz       = wp_timezone();
		$night    = new DateTimeImmutable( $check_in, $tz );
		$end      = new DateTimeImmutable( $check_out, $tz );
		$max_used = 0;

		while ( $night < $end ) {
			$date = $night->format( 'Y-m-d' );
			$used = 0;
			foreach ( $rows as $row ) {
				if ( $row->check_in <= $date && $row->check_out > $date ) {
					++$used;
				}
			}
			$max_used = max( $max_used, $used );
			$night    = $night->modify( '+1 day' );
		}

		return max( 0, $room['units'] - $max_used );
	}

	/**
	 * Availability and price for all rooms (or one room) for a stay.
	 *
	 * @return array|WP_Error
	 */
	public static function search( $check_in, $check_out, $adults, $children, $room_id_or_slug = '' ) {
		$stay = self::validate_dates( $check_in, $check_out );
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		if ( $room_id_or_slug ) {
			$post  = Flexo_Booking_Rooms::find( $room_id_or_slug );
			$posts = $post ? array( $post ) : array();
		} else {
			$posts = Flexo_Booking_Rooms::all();
		}

		$guests  = (int) $adults + (int) $children;
		$results = array();

		foreach ( $posts as $post ) {
			$room   = Flexo_Booking_Rooms::to_array( $post );
			$reason = '';

			if ( $room['units'] < 1 ) {
				continue;
			}
			if ( $guests > $room['capacity'] ) {
				/* translators: %d: number of guests */
				$reason = sprintf( _n( 'Fits up to %d guest.', 'Fits up to %d guests.', $room['capacity'], 'flexo-booking' ), $room['capacity'] );
			} elseif ( $stay['nights'] < $room['min_nights'] ) {
				/* translators: %d: number of nights */
				$reason = sprintf( _n( 'Minimum stay %d night.', 'Minimum stay %d nights.', $room['min_nights'], 'flexo-booking' ), $room['min_nights'] );
			}

			$left  = self::units_available( $room, $stay['check_in'], $stay['check_out'] );
			$total = self::calculate_total( $room, $stay['check_in'], $stay['check_out'] );

			if ( ! $reason && $left < 1 ) {
				$reason = __( 'Sold out for these dates.', 'flexo-booking' );
			}

			$results[] = array(
				'id'              => $room['id'],
				'slug'            => $room['slug'],
				'title'           => $room['title'],
				'excerpt'         => $room['excerpt'],
				'image'           => $room['image'],
				'capacity'        => $room['capacity'],
				'available'       => '' === $reason,
				'reason'          => $reason,
				'units_left'      => $left,
				'price'           => $room['price'],
				'price_formatted' => Flexo_Booking_Settings::format_price( $room['price'] ),
				'total'           => $total,
				'total_formatted' => Flexo_Booking_Settings::format_price( $total ),
			);
		}

		return array(
			'check_in'     => $stay['check_in'],
			'check_out'    => $stay['check_out'],
			'nights'       => $stay['nights'],
			/* translators: %d: number of nights */
			'nights_label' => sprintf( _n( 'Total for %d night', 'Total for %d nights', $stay['nights'], 'flexo-booking' ), $stay['nights'] ),
			'rooms'        => $results,
		);
	}

	/**
	 * Creates a booking after validating it and re-checking availability
	 * under a lock, so two guests can't take the last room at the same time.
	 *
	 * @param array $data {
	 *     @type int|string $room       Room ID or slug.
	 *     @type string     $check_in   Y-m-d.
	 *     @type string     $check_out  Y-m-d.
	 *     @type int        $adults
	 *     @type int        $children
	 *     @type string     $guest_name
	 *     @type string     $guest_email
	 *     @type string     $guest_phone
	 *     @type string     $notes
	 *     @type string     $status     Optional, defaults to the booking mode.
	 *     @type string     $source     "website" or "admin".
	 * }
	 * @return array|WP_Error The stored booking.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$is_admin = isset( $data['source'] ) && 'admin' === $data['source'];

		$post = Flexo_Booking_Rooms::find( isset( $data['room'] ) ? $data['room'] : 0 );
		if ( ! $post ) {
			return new WP_Error( 'flexo_invalid_room', __( 'Please choose a room.', 'flexo-booking' ) );
		}
		$room = Flexo_Booking_Rooms::to_array( $post );

		$stay = self::validate_dates(
			isset( $data['check_in'] ) ? $data['check_in'] : '',
			isset( $data['check_out'] ) ? $data['check_out'] : '',
			! $is_admin,
			$room['min_nights']
		);
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		$adults   = max( 1, absint( isset( $data['adults'] ) ? $data['adults'] : 1 ) );
		$children = absint( isset( $data['children'] ) ? $data['children'] : 0 );
		$name     = sanitize_text_field( isset( $data['guest_name'] ) ? $data['guest_name'] : '' );
		$email    = sanitize_email( isset( $data['guest_email'] ) ? $data['guest_email'] : '' );
		$phone    = sanitize_text_field( isset( $data['guest_phone'] ) ? $data['guest_phone'] : '' );
		$notes    = sanitize_textarea_field( isset( $data['notes'] ) ? $data['notes'] : '' );
		$status   = isset( $data['status'] ) && array_key_exists( $data['status'], self::statuses() ) ? $data['status'] : ( 'instant' === Flexo_Booking_Settings::get( 'booking_mode' ) ? 'confirmed' : 'pending' );

		if ( ! $is_admin ) {
			if ( $adults + $children > $room['capacity'] ) {
				/* translators: %d: number of guests */
				return new WP_Error( 'flexo_capacity', sprintf( _n( 'This room fits up to %d guest.', 'This room fits up to %d guests.', $room['capacity'], 'flexo-booking' ), $room['capacity'] ) );
			}
			if ( '' === $name ) {
				return new WP_Error( 'flexo_missing_name', __( 'Please enter your name.', 'flexo-booking' ) );
			}
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'flexo_invalid_email', __( 'Please enter a valid email address.', 'flexo-booking' ) );
			}
			if ( '' === $phone ) {
				return new WP_Error( 'flexo_missing_phone', __( 'Please enter your phone number.', 'flexo-booking' ) );
			}
		} elseif ( 'blocked' !== $status && '' === $name ) {
			return new WP_Error( 'flexo_missing_name', __( 'Please enter the guest name.', 'flexo-booking' ) );
		}

		$lock = 'flexo_booking_lock_' . $room['id'];
		if ( ! self::acquire_lock( $lock ) ) {
			return new WP_Error( 'flexo_busy', __( 'We are processing another booking for this room. Please try again in a moment.', 'flexo-booking' ) );
		}

		try {
			if ( 'cancelled' !== $status && self::units_available( $room, $stay['check_in'], $stay['check_out'] ) < 1 ) {
				return new WP_Error( 'flexo_unavailable', __( 'Sorry, this room is no longer available for the selected dates.', 'flexo-booking' ) );
			}

			$now     = current_time( 'mysql' );
			$booking = array(
				'reference'   => self::generate_reference(),
				'room_id'     => $room['id'],
				'check_in'    => $stay['check_in'],
				'check_out'   => $stay['check_out'],
				'nights'      => $stay['nights'],
				'adults'      => $adults,
				'children'    => $children,
				'guest_name'  => $name,
				'guest_email' => $email,
				'guest_phone' => $phone,
				'notes'       => $notes,
				'total'       => 'blocked' === $status ? 0 : self::calculate_total( $room, $stay['check_in'], $stay['check_out'] ),
				'currency'    => Flexo_Booking_Settings::get( 'currency' ),
				'status'      => $status,
				'source'      => $is_admin ? 'admin' : 'website',
				'created_at'  => $now,
				'updated_at'  => $now,
			);

			$booking = apply_filters( 'flexo_booking_before_insert', $booking, $room );

			if ( ! $wpdb->insert( Flexo_Booking_Install::table(), $booking ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The booking could not be saved. Please try again.', 'flexo-booking' ) );
			}
			$booking['id'] = (int) $wpdb->insert_id;
		} finally {
			self::release_lock( $lock );
		}

		$booking = self::get( $booking['id'] );

		/**
		 * Fires after a booking is stored. Emails hook in here; so can payment,
		 * CRM or channel-manager integrations.
		 */
		do_action( 'flexo_booking_created', $booking );

		return $booking;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_reference( $reference ) {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $reference ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ) {
		foreach ( array( 'id', 'room_id', 'nights', 'adults', 'children' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['total']      = (float) $row['total'];
		$room              = get_post( $row['room_id'] );
		$row['room_title'] = $room ? get_the_title( $room ) : __( '(deleted room)', 'flexo-booking' );
		return $row;
	}

	/**
	 * Changes the status of a booking. Re-checks availability when a
	 * cancelled booking is brought back.
	 *
	 * @return true|WP_Error
	 */
	public static function update_status( $id, $status ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'flexo_not_found', __( 'Booking not found.', 'flexo-booking' ) );
		}
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return new WP_Error( 'flexo_invalid_status', __( 'Invalid status.', 'flexo-booking' ) );
		}
		if ( $booking['status'] === $status ) {
			return true;
		}

		if ( ! in_array( $booking['status'], self::OCCUPYING, true ) && in_array( $status, self::OCCUPYING, true ) ) {
			$post = get_post( $booking['room_id'] );
			if ( $post ) {
				$room = Flexo_Booking_Rooms::to_array( $post );
				if ( self::units_available( $room, $booking['check_in'], $booking['check_out'], $booking['id'] ) < 1 ) {
					return new WP_Error( 'flexo_unavailable', __( 'The room is no longer available for these dates.', 'flexo-booking' ) );
				}
			}
		}

		$wpdb->update(
			Flexo_Booking_Install::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		do_action( 'flexo_booking_status_changed', self::get( $id ), $booking['status'], $status );

		return true;
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Flexo_Booking_Install::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Lists bookings for the admin screen and CSV export.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'room_id'  => 0,
				'search'   => '',
				'from'     => '',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['room_id'] ) {
			$where[]  = 'room_id = %d';
			$params[] = $args['room_id'];
		}
		if ( $args['from'] ) {
			$where[]  = 'check_out >= %s';
			$params[] = $args['from'];
		}
		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(reference LIKE %s OR guest_name LIKE %s OR guest_email LIKE %s OR guest_phone LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$table     = Flexo_Booking_Install::table();
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY check_in DESC, id DESC";
		if ( $args['per_page'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['per_page'];
			$params[] = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );

		return array(
			'total' => $total,
			'items' => array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() ),
		);
	}

	public static function count_pending() {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
	}

	private static function generate_reference() {
		do {
			$reference = 'FB-' . strtoupper( wp_generate_password( 6, false, false ) );
		} while ( self::get_by_reference( $reference ) );
		return $reference;
	}

	/**
	 * Database-agnostic mutex built on the unique option_name key: a plain
	 * INSERT of a row that already exists fails, so only one request wins.
	 * (add_option() can't be used: it upserts.) Stale locks from a crashed
	 * request expire after 30 seconds.
	 */
	private static function acquire_lock( $name ) {
		global $wpdb;

		for ( $i = 0; $i < 50; $i++ ) {
			$suppress = $wpdb->suppress_errors( true );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time() ) );
			$wpdb->suppress_errors( $suppress );

			if ( $inserted ) {
				return true;
			}

			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( $since && time() - $since > 30 ) {
				self::release_lock( $name );
				continue;
			}
			usleep( 100000 );
		}
		return false;
	}

	private static function release_lock( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}
}
