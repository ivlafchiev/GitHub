<?php
/**
 * Rate plans (feature "rate_plans"): ways of selling a room, such as
 * "Breakfast included" or "Non-refundable".
 *
 * A plan is defined once for the whole property. Each room chooses the plans
 * it offers, optionally with its own amount (room meta _flexo_rate_plans:
 * plan ID => override amount or ''). A room offering no plan is sold at its
 * normal price, exactly as without the feature.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Rate_Plans {

	const ROOM_META      = '_flexo_rate_plans';
	const PRESETS_OPTION = 'flexo_booking_add_rate_plan_presets';

	/**
	 * @var array|null Per-request cache of all plans, by ID.
	 */
	private static $cache = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_add_presets' ), 20 );
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'rate_plans' );
	}

	/**
	 * Adds the ready-made plans once after the Day 3 migration.
	 */
	public static function maybe_add_presets() {
		if ( get_option( self::PRESETS_OPTION ) ) {
			delete_option( self::PRESETS_OPTION );
			if ( ! self::all() ) {
				self::add_presets();
			}
		}
	}

	public static function table() {
		return Flexo_Booking_Schema::table( 'rate_plans' );
	}

	public static function types() {
		return array(
			'per_night'       => __( 'Fixed amount per night', 'flexo-booking' ),
			'per_booking'     => __( 'Fixed amount per booking', 'flexo-booking' ),
			'per_guest_night' => __( 'Amount per guest per night', 'flexo-booking' ),
			'percent'         => __( 'Percentage of the room price', 'flexo-booking' ),
		);
	}

	/**
	 * Ready-made plans, added switched off for the hotel to edit.
	 */
	public static function presets() {
		$flexible = __( 'Free cancellation up to 7 days before arrival. Later cancellations or no-shows are charged the first night.', 'flexo-booking' );
		return array(
			'room_only'      => array(
				'name'                => __( 'Room Only', 'flexo-booking' ),
				'description'         => __( 'Accommodation without meals.', 'flexo-booking' ),
				'adjustment_type'     => 'per_night',
				'adjustment_value'    => 0,
				'refundable'          => 1,
				'cancellation_policy' => $flexible,
			),
			'breakfast'      => array(
				'name'                => __( 'Breakfast Included', 'flexo-booking' ),
				'description'         => __( 'Breakfast every morning.', 'flexo-booking' ),
				'adjustment_type'     => 'per_guest_night',
				'adjustment_value'    => 8,
				'refundable'          => 1,
				'cancellation_policy' => $flexible,
			),
			'half_board'     => array(
				'name'                => __( 'Half Board', 'flexo-booking' ),
				'description'         => __( 'Breakfast and dinner.', 'flexo-booking' ),
				'adjustment_type'     => 'per_guest_night',
				'adjustment_value'    => 18,
				'refundable'          => 1,
				'cancellation_policy' => $flexible,
			),
			'full_board'     => array(
				'name'                => __( 'Full Board', 'flexo-booking' ),
				'description'         => __( 'Breakfast, lunch and dinner.', 'flexo-booking' ),
				'adjustment_type'     => 'per_guest_night',
				'adjustment_value'    => 28,
				'refundable'          => 1,
				'cancellation_policy' => $flexible,
			),
			'all_inclusive'  => array(
				'name'                => __( 'All Inclusive', 'flexo-booking' ),
				'description'         => __( 'All meals, snacks and selected drinks.', 'flexo-booking' ),
				'adjustment_type'     => 'per_guest_night',
				'adjustment_value'    => 35,
				'refundable'          => 1,
				'cancellation_policy' => $flexible,
			),
			'non_refundable' => array(
				'name'                => __( 'Non-refundable', 'flexo-booking' ),
				'description'         => __( 'Our best price. Cannot be changed or cancelled.', 'flexo-booking' ),
				'adjustment_type'     => 'percent',
				'adjustment_value'    => -10,
				'refundable'          => 0,
				'cancellation_policy' => __( 'This booking cannot be cancelled or changed, and the amount is not refunded.', 'flexo-booking' ),
			),
		);
	}

	/**
	 * Adds the ready-made plans that are missing (switched off, no rooms).
	 *
	 * @return int Number added.
	 */
	public static function add_presets() {
		$have  = wp_list_pluck( self::all(), 'preset' );
		$added = 0;
		$order = count( self::all() );
		foreach ( self::presets() as $key => $preset ) {
			if ( in_array( $key, $have, true ) ) {
				continue;
			}
			$preset['preset']     = $key;
			$preset['active']     = 0;
			$preset['sort_order'] = ++$order;
			if ( ! is_wp_error( self::save( $preset ) ) ) {
				++$added;
			}
		}
		return $added;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * @return array[] All plans (active or not), in display order.
	 */
	public static function all() {
		global $wpdb;
		if ( null === self::$cache ) {
			self::$cache = array();
			if ( ! Flexo_Booking_Schema::table_exists( 'rate_plans' ) ) {
				return array();
			}
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC", ARRAY_A ) as $row ) {
				$plan                      = self::hydrate( $row );
				self::$cache[ $plan['id'] ] = $plan;
			}
		}
		return array_values( self::$cache );
	}

	public static function get( $id ) {
		self::all();
		return isset( self::$cache[ (int) $id ] ) ? self::$cache[ (int) $id ] : null;
	}

	public static function get_by_name( $name ) {
		foreach ( self::all() as $plan ) {
			if ( 0 === strcasecmp( $plan['name'], trim( (string) $name ) ) ) {
				return $plan;
			}
		}
		return null;
	}

	private static function hydrate( array $row ) {
		return array(
			'id'                  => (int) $row['id'],
			'name'                => $row['name'],
			'description'         => (string) $row['description'],
			'preset'              => $row['preset'],
			'adjustment_type'     => array_key_exists( $row['adjustment_type'], self::types() ) ? $row['adjustment_type'] : 'per_night',
			'adjustment_value'    => (float) $row['adjustment_value'],
			'refundable'          => (int) $row['refundable'],
			'cancellation_policy' => (string) $row['cancellation_policy'],
			'active'              => (int) $row['active'],
			'sort_order'          => (int) $row['sort_order'],
		);
	}

	/**
	 * Validates and stores a plan.
	 *
	 * @return int|WP_Error Plan ID.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;

		$name = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		if ( '' === $name ) {
			return new WP_Error( 'flexo_plan_name', __( 'Please give the rate plan a name, e.g. "Breakfast Included".', 'flexo-booking' ) );
		}
		$other = self::get_by_name( $name );
		if ( $other && $other['id'] !== (int) $id ) {
			/* translators: %s: plan name */
			return new WP_Error( 'flexo_plan_name', sprintf( __( 'A rate plan called "%s" already exists.', 'flexo-booking' ), $name ) );
		}
		$type = isset( $data['adjustment_type'] ) && array_key_exists( $data['adjustment_type'], self::types() ) ? $data['adjustment_type'] : 'per_night';
		$raw  = isset( $data['adjustment_value'] ) ? str_replace( ',', '.', trim( (string) $data['adjustment_value'] ) ) : '0';
		if ( '' === $raw ) {
			$raw = '0';
		}
		if ( ! is_numeric( $raw ) ) {
			return new WP_Error( 'flexo_plan_value', __( 'Please enter the price change as a number, e.g. 8 or -10.', 'flexo-booking' ) );
		}
		$value = round( (float) $raw, 3 );
		if ( 'percent' === $type && ( $value < -100 || $value > 500 ) ) {
			return new WP_Error( 'flexo_plan_value', __( 'The percentage must be between -100 and 500.', 'flexo-booking' ) );
		}

		$row = array(
			'name'                => $name,
			'description'         => sanitize_textarea_field( isset( $data['description'] ) ? $data['description'] : '' ),
			'preset'              => isset( $data['preset'] ) ? sanitize_key( $data['preset'] ) : '',
			'adjustment_type'     => $type,
			'adjustment_value'    => $value,
			'refundable'          => empty( $data['refundable'] ) ? 0 : 1,
			'cancellation_policy' => sanitize_textarea_field( isset( $data['cancellation_policy'] ) ? $data['cancellation_policy'] : '' ),
			'active'              => empty( $data['active'] ) ? 0 : 1,
			'sort_order'          => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
			'updated_at'          => current_time( 'mysql' ),
		);

		if ( $id && self::get( $id ) ) {
			if ( ! isset( $data['preset'] ) ) {
				unset( $row['preset'] );
			}
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			if ( ! $wpdb->insert( self::table(), $row ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The rate plan could not be saved.', 'flexo-booking' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::flush_cache();
		return (int) $id;
	}

	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'active'     => $active ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
		self::flush_cache();
	}

	/**
	 * Deletes a plan and removes it from every room. Existing bookings keep
	 * the plan's name and price in their stored breakdown.
	 */
	public static function delete( $id ) {
		global $wpdb;
		foreach ( self::rooms_for_plan( $id ) as $room_id => $override ) {
			$assigned = self::room_assignments( $room_id );
			unset( $assigned[ (int) $id ] );
			self::set_room_assignments( $room_id, $assigned );
		}
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		self::flush_cache();
	}

	/**
	 * Plans a room offers: plan ID => its own amount (float) or null for the plan's amount.
	 *
	 * @return array
	 */
	public static function room_assignments( $room_id ) {
		$raw = get_post_meta( (int) $room_id, self::ROOM_META, true );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $plan_id => $override ) {
			$out[ (int) $plan_id ] = ( null === $override || '' === $override ) ? null : (float) $override;
		}
		return $out;
	}

	public static function set_room_assignments( $room_id, array $assignments ) {
		$clean = array();
		foreach ( $assignments as $plan_id => $override ) {
			if ( (int) $plan_id > 0 ) {
				$clean[ (int) $plan_id ] = ( null === $override || '' === $override ) ? '' : round( (float) $override, 3 );
			}
		}
		if ( $clean ) {
			update_post_meta( (int) $room_id, self::ROOM_META, $clean );
		} else {
			delete_post_meta( (int) $room_id, self::ROOM_META );
		}
	}

	/**
	 * @return array Room ID => own amount or null.
	 */
	public static function rooms_for_plan( $plan_id ) {
		$rooms = array();
		foreach ( Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) ) as $post ) {
			$assigned = self::room_assignments( $post->ID );
			if ( array_key_exists( (int) $plan_id, $assigned ) ) {
				$rooms[ $post->ID ] = $assigned[ (int) $plan_id ];
			}
		}
		return $rooms;
	}

	/**
	 * Switched-on plans a room offers, with the amount that applies to this
	 * room in "value". Empty when the feature is off.
	 *
	 * @return array[]
	 */
	public static function for_room( $room_id ) {
		if ( ! self::enabled() ) {
			return array();
		}
		$assigned = self::room_assignments( $room_id );
		$plans    = array();
		foreach ( self::all() as $plan ) {
			if ( $plan['active'] && array_key_exists( $plan['id'], $assigned ) ) {
				$plan['value'] = null === $assigned[ $plan['id'] ] ? $plan['adjustment_value'] : $assigned[ $plan['id'] ];
				$plans[]       = $plan;
			}
		}
		return $plans;
	}

	/**
	 * "+8.00 € per guest per night", "−10% of the room price", "No extra charge".
	 */
	public static function describe_adjustment( $type, $value, $currency = null ) {
		$value = (float) $value;
		if ( abs( $value ) < 0.0005 ) {
			return __( 'No extra charge', 'flexo-booking' );
		}
		$sign = $value < 0 ? '−' : '+';
		if ( 'percent' === $type ) {
			/* translators: 1: + or −, 2: percentage */
			return sprintf( __( '%1$s%2$s%% of the room price', 'flexo-booking' ), $sign, Flexo_Booking_Children::percent_text( abs( $value ) ) );
		}
		$amount = $sign . Flexo_Booking_Money::format( abs( $value ), $currency );
		switch ( $type ) {
			case 'per_booking':
				/* translators: %s: amount */
				return sprintf( __( '%s per booking', 'flexo-booking' ), $amount );
			case 'per_guest_night':
				/* translators: %s: amount */
				return sprintf( __( '%s per guest per night', 'flexo-booking' ), $amount );
			default:
				/* translators: %s: amount */
				return sprintf( __( '%s per night', 'flexo-booking' ), $amount );
		}
	}

	/**
	 * What the plan adds to a quote: the amount and the lines that explain it.
	 *
	 * @param array $plan  Plan with its effective "value".
	 * @param array $quote Quote after the nightly step (room price lines).
	 * @param array $room
	 * @return array { amount: float, items: array[] }
	 */
	public static function adjustment( array $plan, array $quote, array $room ) {
		$value  = (float) $plan['value'];
		$nights = (int) $quote['nights'];

		switch ( $plan['adjustment_type'] ) {
			case 'per_booking':
				return array(
					'amount' => $value,
					'items'  => array(),
				);
			case 'per_guest_night':
				return Flexo_Booking_Pricing::per_person( $value, $nights, $quote['adults'], $quote['children_ages'], $quote['children'], Flexo_Booking_Children::enabled() ? Flexo_Booking_Children::rules( $room ) : null );
			case 'percent':
				$base = Flexo_Booking_Pricing::room_amount( $quote );
				return array(
					'amount' => $base * $value / 100,
					'items'  => array(
						array(
							/* translators: 1: percentage with sign, 2: room price */
							'text'   => sprintf( __( '%1$s%% of the room price %2$s', 'flexo-booking' ), ( $value < 0 ? '−' : '+' ) . Flexo_Booking_Children::percent_text( abs( $value ) ), Flexo_Booking_Money::format( $base ) ),
							'unit'   => null,
							'amount' => Flexo_Booking_Money::round( $base * $value / 100 ),
						),
					),
				);
			default:
				return array(
					'amount' => $value * $nights,
					'items'  => array(
						array(
							/* translators: %d: number of nights */
							'text'   => sprintf( _n( '%d night', '%d nights', $nights, 'flexo-booking' ), $nights ),
							'unit'   => $value,
							'amount' => Flexo_Booking_Money::round( $value * $nights ),
						),
					),
				);
		}
	}

	/**
	 * What a booking keeps of its plan, so later edits never change it.
	 */
	public static function snapshot( array $plan ) {
		return array(
			'id'                  => (int) $plan['id'],
			'name'                => $plan['name'],
			'description'         => $plan['description'],
			'refundable'          => (int) $plan['refundable'],
			'cancellation_policy' => $plan['cancellation_policy'],
			'adjustment_type'     => $plan['adjustment_type'],
			'adjustment_value'    => (float) ( isset( $plan['value'] ) ? $plan['value'] : $plan['adjustment_value'] ),
		);
	}

	public static function refundable_label( $refundable ) {
		return $refundable ? __( 'Refundable', 'flexo-booking' ) : __( 'Non-refundable', 'flexo-booking' );
	}
}
