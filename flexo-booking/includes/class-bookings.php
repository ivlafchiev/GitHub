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
	 * Total price of a stay.
	 *
	 * @deprecated 1.1.0 Use Flexo_Booking_Pricing::quote(), which returns the itemised breakdown.
	 */
	public static function calculate_total( array $room, $check_in, $check_out ) {
		$quote = Flexo_Booking_Pricing::quote(
			array(
				'room'      => $room,
				'check_in'  => $check_in,
				'check_out' => $check_out,
			)
		);
		return is_wp_error( $quote ) ? 0.0 : (float) $quote['total'];
	}

	/**
	 * @deprecated 1.1.0 Use Flexo_Booking_Inventory::units_available().
	 */
	public static function units_available( array $room, $check_in, $check_out, $exclude_id = 0 ) {
		return Flexo_Booking_Inventory::units_available( $room, $check_in, $check_out, $exclude_id );
	}

	/**
	 * Availability and price for all rooms (or one room) for a stay.
	 *
	 * Minimum stay is checked per room (arrival season → room → global), so
	 * it can differ between rooms and seasons. Rooms offering rate plans
	 * list each plan with its price; the room's price is the lowest one.
	 *
	 * @param int[]|string|null $children_ages Ages of the children (feature "children").
	 * @return array|WP_Error
	 */
	public static function search( $check_in, $check_out, $adults, $children, $room_id_or_slug = '', $children_ages = null ) {
		$stay = self::validate_dates( $check_in, $check_out, true, 1 );
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		$adults   = max( 1, (int) $adults );
		$children = max( 0, (int) $children );
		$ages     = array();
		if ( Flexo_Booking_Children::enabled() ) {
			$ages = Flexo_Booking_Children::validate( $children, $children_ages );
			if ( is_wp_error( $ages ) ) {
				return $ages;
			}
		}

		if ( $room_id_or_slug ) {
			$post  = Flexo_Booking_Rooms::find( $room_id_or_slug );
			$posts = $post ? array( $post ) : array();
		} else {
			$posts = Flexo_Booking_Rooms::all();
		}

		if ( Flexo_Booking_Seasons::enabled() ) {
			Flexo_Booking_Seasons::preload( wp_list_pluck( $posts, 'ID' ), $stay['check_in'], $stay['check_out'] );
		}

		$guests  = $adults + $children;
		$results = array();

		foreach ( $posts as $post ) {
			$room = Flexo_Booking_Rooms::to_array( $post );
			if ( $room['units'] < 1 ) {
				continue;
			}

			$request = array(
				'room'          => $room,
				'check_in'      => $stay['check_in'],
				'check_out'     => $stay['check_out'],
				'adults'        => $adults,
				'children'      => $children,
				'children_ages' => $ages,
				'context'       => 'search',
			);

			// One quote per rate plan the room offers (or one without a plan).
			$plans  = array();
			$quote  = null;
			$offers = Flexo_Booking_Rate_Plans::for_room( $room['id'] );
			foreach ( $offers ? $offers : array( null ) as $plan ) {
				$plan_quote = Flexo_Booking_Pricing::quote( array_merge( $request, array( 'rate_plan_id' => $plan ? $plan['id'] : 0 ) ) );
				if ( is_wp_error( $plan_quote ) ) {
					continue;
				}
				if ( $plan ) {
					$plans[] = Flexo_Booking_Pricing::public_view( $plan_quote );
				}
				if ( ! $quote || $plan_quote['total'] < $quote['total'] ) {
					$quote = $plan_quote;
				}
			}
			if ( ! $quote ) {
				continue;
			}

			$reason = Flexo_Booking_Inventory::closed_reason( $room['id'], $stay['check_in'], $stay['check_out'] );
			if ( ! $reason ) {
				$reason = self::capacity_error( $room, $adults, $children, 'short' );
			}
			if ( ! $reason && $stay['nights'] < $quote['min_nights'] ) {
				$reason = self::min_nights_message( $quote );
			}

			$left = Flexo_Booking_Inventory::units_available( $room, $stay['check_in'], $stay['check_out'] );
			if ( ! $reason && $left < 1 ) {
				$reason = __( 'Sold out for these dates.', 'flexo-booking' );
			}

			$average   = Flexo_Booking_Pricing::nightly_average( $quote );
			$results[] = array(
				'id'                      => $room['id'],
				'slug'                    => $room['slug'],
				'title'                   => $room['title'],
				'excerpt'                 => $room['excerpt'],
				'image'                   => $room['image'],
				'capacity'                => $room['capacity'],
				'max_adults'              => Flexo_Booking_Children::enabled() ? $room['max_adults'] : 0,
				'available'               => '' === $reason,
				'reason'                  => $reason,
				'units_left'              => $left,
				'price'                   => $room['price'],
				'price_formatted'         => Flexo_Booking_Money::format( $room['price'] ),
				'price_average'           => $average['average'],
				'price_average_formatted' => Flexo_Booking_Money::format( $average['average'] ),
				'price_varies'            => $average['varies'],
				'min_nights'              => $quote['min_nights'],
				'total'                   => $quote['total'],
				'total_formatted'         => Flexo_Booking_Money::format( $quote['total'] ),
				'price_from'              => count( $plans ) > 1,
				'breakdown'               => Flexo_Booking_Pricing::format_lines( $quote ),
				'quote'                   => Flexo_Booking_Pricing::public_view( $quote ),
				'plans'                   => $plans,
			);
		}

		$closure = Flexo_Booking_Closures::property_closure( $stay['check_in'], $stay['check_out'] );

		return array(
			'check_in'      => $stay['check_in'],
			'check_out'     => $stay['check_out'],
			'nights'        => $stay['nights'],
			'adults'        => $adults,
			'children'      => $children,
			'children_ages' => $ages,
			/* translators: %d: number of nights */
			'nights_label'  => sprintf( _n( 'Total for %d night', 'Total for %d nights', $stay['nights'], 'flexo-booking' ), $stay['nights'] ),
			'notice'        => $closure ? Flexo_Booking_Closures::guest_message( $closure ) : '',
			'rooms'         => $results,
		);
	}

	/**
	 * Whether the guests fit the room: "max guests" counts adults and
	 * children; "max adults" applies when children's ages are asked.
	 *
	 * @param string $style "short" for room cards, "long" for booking errors.
	 * @return string Message, or '' when they fit.
	 */
	public static function capacity_error( array $room, $adults, $children, $style = 'long' ) {
		if ( $adults + $children > $room['capacity'] ) {
			return 'short' === $style
				/* translators: %d: number of guests */
				? sprintf( _n( 'Fits up to %d guest.', 'Fits up to %d guests.', $room['capacity'], 'flexo-booking' ), $room['capacity'] )
				/* translators: %d: number of guests */
				: sprintf( _n( 'This room fits up to %d guest.', 'This room fits up to %d guests.', $room['capacity'], 'flexo-booking' ), $room['capacity'] );
		}
		if ( Flexo_Booking_Children::enabled() && $room['max_adults'] > 0 && $adults > $room['max_adults'] ) {
			return 'short' === $style
				/* translators: %d: number of adults */
				? sprintf( _n( 'Fits up to %d adult.', 'Fits up to %d adults.', $room['max_adults'], 'flexo-booking' ), $room['max_adults'] )
				/* translators: %d: number of adults */
				: sprintf( _n( 'This room fits up to %d adult.', 'This room fits up to %d adults.', $room['max_adults'], 'flexo-booking' ), $room['max_adults'] );
		}
		return '';
	}

	private static function min_nights_message( array $quote ) {
		$min = $quote['min_nights'];
		if ( ! empty( $quote['min_nights_season'] ) ) {
			/* translators: 1: number of nights, 2: season name */
			return sprintf( _n( 'Minimum stay %1$d night for arrivals in %2$s.', 'Minimum stay %1$d nights for arrivals in %2$s.', $min, 'flexo-booking' ), $min, $quote['min_nights_season'] );
		}
		/* translators: %d: number of nights */
		return sprintf( _n( 'Minimum stay %d night.', 'Minimum stay %d nights.', $min, 'flexo-booking' ), $min );
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
	 *     @type int[]|string $children_ages Required per child when the "children" feature is on.
	 *     @type int        $rate_plan      Rate plan ID (required when the room offers several).
	 *     @type string     $promo_code
	 *     @type float      $expected_total Optional: the total the guest was shown. The booking is
	 *                                      refused when the server's price differs.
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

		$check_in = isset( $data['check_in'] ) ? (string) $data['check_in'] : '';
		$min      = 1;
		if ( ! $is_admin && Flexo_Booking_Dates::parse( $check_in ) === $check_in ) {
			$min_rule = Flexo_Booking_Seasons::min_nights( $room, $check_in );
			$min      = $min_rule['nights'];
		}

		$stay = self::validate_dates(
			$check_in,
			isset( $data['check_out'] ) ? $data['check_out'] : '',
			! $is_admin,
			$min
		);
		if ( is_wp_error( $stay ) ) {
			return $stay;
		}

		$adults   = max( 1, absint( isset( $data['adults'] ) ? $data['adults'] : 1 ) );
		$children = absint( isset( $data['children'] ) ? $data['children'] : 0 );
		$ages     = array();
		if ( Flexo_Booking_Children::enabled() ) {
			$raw_ages = isset( $data['children_ages'] ) ? $data['children_ages'] : array();
			// Staff may type just the ages; the count follows from them.
			$ages = $is_admin ? Flexo_Booking_Children::parse_ages( $raw_ages ) : Flexo_Booking_Children::validate( $children, $raw_ages );
			if ( is_wp_error( $ages ) ) {
				return $ages;
			}
			if ( $is_admin && $ages ) {
				$children = count( $ages );
			}
		}
		$plan_id  = absint( isset( $data['rate_plan'] ) ? $data['rate_plan'] : 0 );
		$promo    = Flexo_Booking_Promo_Codes::enabled() ? Flexo_Booking_Promo_Codes::normalize_code( isset( $data['promo_code'] ) ? $data['promo_code'] : '' ) : '';
		$expected = isset( $data['expected_total'] ) && '' !== $data['expected_total'] && null !== $data['expected_total'] ? (float) $data['expected_total'] : null;
		$name     = sanitize_text_field( isset( $data['guest_name'] ) ? $data['guest_name'] : '' );
		$email    = sanitize_email( isset( $data['guest_email'] ) ? $data['guest_email'] : '' );
		$phone    = sanitize_text_field( isset( $data['guest_phone'] ) ? $data['guest_phone'] : '' );
		$notes    = sanitize_textarea_field( isset( $data['notes'] ) ? $data['notes'] : '' );
		$status   = isset( $data['status'] ) && array_key_exists( $data['status'], self::statuses() ) ? $data['status'] : ( 'instant' === Flexo_Booking_Features::booking_mode() ? 'confirmed' : 'pending' );

		if ( ! $is_admin ) {
			$capacity = self::capacity_error( $room, $adults, $children );
			if ( $capacity ) {
				return new WP_Error( 'flexo_capacity', $capacity );
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
		if ( 'blocked' === $status ) {
			$promo = '';
		}

		// Everything below runs under the room lock: closures, availability and
		// the price are re-checked there, so two guests can never take the
		// last unit and the stored price is the one calculated at that moment.
		$insert = static function () use ( $wpdb, $room, $stay, $status, $is_admin, $adults, $children, $ages, $plan_id, $promo, $expected, $name, $email, $phone, $notes ) {
			if ( ! $is_admin ) {
				$closed = Flexo_Booking_Inventory::closed_reason( $room['id'], $stay['check_in'], $stay['check_out'] );
				if ( $closed ) {
					return new WP_Error( 'flexo_closed', $closed );
				}
			}
			if ( 'cancelled' !== $status && Flexo_Booking_Inventory::units_available( $room, $stay['check_in'], $stay['check_out'] ) < 1 ) {
				return new WP_Error( 'flexo_unavailable', __( 'Sorry, this room is no longer available for the selected dates.', 'flexo-booking' ) );
			}

			$quote = null;
			if ( 'blocked' !== $status ) {
				$quote = Flexo_Booking_Pricing::quote(
					array(
						'room'          => $room,
						'check_in'      => $stay['check_in'],
						'check_out'     => $stay['check_out'],
						'adults'        => $adults,
						'children'      => $children,
						'children_ages' => $ages,
						'rate_plan_id'  => $plan_id,
						'promo_code'    => $promo,
						'context'       => $is_admin ? 'admin' : 'booking',
					)
				);
				if ( is_wp_error( $quote ) ) {
					return $quote;
				}
				if ( ! empty( $quote['promo_error'] ) ) {
					return new WP_Error( $quote['promo_error']['code'], $quote['promo_error']['message'] );
				}
				// The guest confirms the price they were shown; if anything
				// changed since (or the request was tampered with), refuse.
				if ( null !== $expected && abs( $expected - (float) $quote['total'] ) >= 0.005 ) {
					return new WP_Error(
						'flexo_price_changed',
						/* translators: %s: new total */
						sprintf( __( 'The price for this stay is now %s. Please check it and send your booking again.', 'flexo-booking' ), Flexo_Booking_Money::format( $quote['total'] ) ),
						array( 'total' => $quote['total'] )
					);
				}
			}

			$now     = current_time( 'mysql' );
			$booking = array(
				'reference'       => self::generate_reference(),
				'room_id'         => $room['id'],
				'check_in'        => $stay['check_in'],
				'check_out'       => $stay['check_out'],
				'nights'          => $stay['nights'],
				'adults'          => $adults,
				'children'        => $children,
				'children_ages'   => implode( ',', $ages ),
				'guest_name'      => $name,
				'guest_email'     => $email,
				'guest_phone'     => $phone,
				'notes'           => $notes,
				'total'           => $quote ? $quote['total'] : 0,
				'currency'        => Flexo_Booking_Money::currency(),
				'status'          => $status,
				'source'          => $is_admin ? 'admin' : 'website',
				'price_breakdown' => $quote ? wp_json_encode( $quote ) : null,
				'rate_plan_id'    => $quote && $quote['rate_plan'] ? $quote['rate_plan']['id'] : 0,
				'promo_id'        => $quote && $quote['promo'] ? $quote['promo']['id'] : 0,
				'promo_code'      => $quote && $quote['promo'] ? $quote['promo']['code'] : '',
				'discount_total'  => $quote ? $quote['discount_total'] : 0,
				'tax_total'       => $quote ? $quote['tax_total'] : 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			);

			$booking = apply_filters( 'flexo_booking_before_insert', $booking, $room );

			if ( ! $wpdb->insert( Flexo_Booking_Install::table(), $booking ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The booking could not be saved. Please try again.', 'flexo-booking' ) );
			}
			$booking['id'] = (int) $wpdb->insert_id;
			return $booking;
		};

		// A code with a usage limit is also locked, so two instant bookings
		// in different rooms can't both take its last use.
		$promo_lock = '';
		if ( '' !== $promo && 'confirmed' === $status ) {
			$promo_row = Flexo_Booking_Promo_Codes::get_by_code( $promo );
			if ( $promo_row && $promo_row['max_uses'] ) {
				$promo_lock = 'promo_' . $promo_row['id'];
				if ( ! Flexo_Booking_Lock::acquire( $promo_lock, 10 ) ) {
					return new WP_Error( 'flexo_busy', __( 'We are processing another booking. Please try again in a moment.', 'flexo-booking' ) );
				}
			}
		}
		try {
			$booking = Flexo_Booking_Inventory::with_lock( $room['id'], $insert );
		} finally {
			if ( $promo_lock ) {
				Flexo_Booking_Lock::release( $promo_lock );
			}
		}

		if ( is_wp_error( $booking ) ) {
			return $booking;
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
		foreach ( array( 'id', 'room_id', 'nights', 'adults', 'children', 'rate_plan_id', 'promo_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}
		foreach ( array( 'total', 'discount_total', 'tax_total' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (float) $row[ $key ] : 0.0;
		}
		$row['children_ages'] = isset( $row['children_ages'] ) ? (string) $row['children_ages'] : '';
		$row['promo_code']    = isset( $row['promo_code'] ) ? (string) $row['promo_code'] : '';
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

		$result = Flexo_Booking_Inventory::with_lock(
			$booking['room_id'],
			static function () use ( $wpdb, $booking, $id, $status ) {
				$occupying = Flexo_Booking_Inventory::occupying_statuses();
				if ( ! in_array( $booking['status'], $occupying, true ) && in_array( $status, $occupying, true ) ) {
					$post = get_post( $booking['room_id'] );
					if ( $post ) {
						$room = Flexo_Booking_Rooms::to_array( $post );
						if ( Flexo_Booking_Inventory::units_available( $room, $booking['check_in'], $booking['check_out'], $booking['id'] ) < 1 ) {
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
				return true;
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

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
}
