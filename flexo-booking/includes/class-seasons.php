<?php
/**
 * Seasonal prices: pricing periods per room (feature "seasonal_pricing").
 *
 * date_from / date_to are the first and last night of the season (inclusive).
 * Seasons of one room may not overlap. When the feature is switched off the
 * rows stay in the database but are ignored.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Seasons {

	/**
	 * Seasons preloaded for a search: "check_in|check_out" => room_id => rows.
	 *
	 * @var array
	 */
	private static $cache = array();

	public static function table() {
		return Flexo_Booking_Schema::table( 'seasons' );
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'seasonal_pricing' );
	}

	private static function hydrate( $row ) {
		$row                  = (array) $row;
		$row['id']            = (int) $row['id'];
		$row['room_id']       = (int) $row['room_id'];
		$row['price']         = (float) $row['price'];
		$row['weekend_price'] = null === $row['weekend_price'] || '' === $row['weekend_price'] ? null : (float) $row['weekend_price'];
		$row['min_nights']    = empty( $row['min_nights'] ) ? null : (int) $row['min_nights'];
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * All seasons of a room, by start date.
	 */
	public static function for_room( $room_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d ORDER BY date_from ASC", $room_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Loads the seasons of several rooms for one stay in a single query.
	 */
	public static function preload( array $room_ids, $check_in, $check_out ) {
		global $wpdb;

		$room_ids = array_filter( array_map( 'intval', $room_ids ) );
		$key      = $check_in . '|' . $check_out;
		if ( ! $room_ids ) {
			return;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $room_ids ), '%d' ) );
		$last_night   = Flexo_Booking_Dates::add_days( $check_out, -1 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id IN ({$placeholders}) AND date_from <= %s AND date_to >= %s ORDER BY date_from ASC", array_merge( $room_ids, array( $last_night, $check_in ) ) ), ARRAY_A );

		self::$cache[ $key ] = array_fill_keys( $room_ids, array() );
		foreach ( $rows ? $rows : array() as $row ) {
			self::$cache[ $key ][ (int) $row['room_id'] ][] = self::hydrate( $row );
		}
	}

	/**
	 * Seasons touching any night of a stay.
	 */
	public static function for_stay( $room_id, $check_in, $check_out ) {
		$key = $check_in . '|' . $check_out;
		if ( ! isset( self::$cache[ $key ][ $room_id ] ) ) {
			self::preload( array( $room_id ), $check_in, $check_out );
		}
		return self::$cache[ $key ][ $room_id ];
	}

	public static function flush_cache() {
		self::$cache = array();
	}

	public static function season_for_night( array $seasons, $date ) {
		foreach ( $seasons as $season ) {
			if ( $season['date_from'] <= $date && $season['date_to'] >= $date ) {
				return $season;
			}
		}
		return null;
	}

	/**
	 * Minimum stay for an arrival: the arrival night's season, then the room,
	 * then the global setting.
	 *
	 * @return array { @type int $nights, @type array|null $season }
	 */
	public static function min_nights( array $room, $check_in ) {
		if ( self::enabled() ) {
			$season = self::season_for_night( self::for_stay( $room['id'], $check_in, Flexo_Booking_Dates::add_days( $check_in, 1 ) ), $check_in );
			if ( $season && $season['min_nights'] ) {
				return array(
					'nights' => $season['min_nights'],
					'season' => $season,
				);
			}
		}
		return array(
			'nights' => max( 1, (int) $room['min_nights'] ),
			'season' => null,
		);
	}

	/**
	 * Checks and normalises a season from a form, import or copy.
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $input, $id = 0 ) {
		global $wpdb;

		$room_id = isset( $input['room_id'] ) ? absint( $input['room_id'] ) : 0;
		$room    = $room_id ? get_post( $room_id ) : null;
		if ( ! $room || Flexo_Booking_Rooms::POST_TYPE !== $room->post_type ) {
			return new WP_Error( 'flexo_season_room', __( 'Please choose a room.', 'flexo-booking' ) );
		}

		$name = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'flexo_season_name', __( 'Please give the season a name, for example "High season".', 'flexo-booking' ) );
		}

		$from = Flexo_Booking_Dates::parse( isset( $input['date_from'] ) ? $input['date_from'] : '' );
		$to   = Flexo_Booking_Dates::parse( isset( $input['date_to'] ) ? $input['date_to'] : '' );
		if ( ! $from || ! $to ) {
			return new WP_Error( 'flexo_season_dates', __( 'Please enter both dates as DD.MM.YYYY.', 'flexo-booking' ) );
		}
		if ( $from > $to ) {
			return new WP_Error( 'flexo_season_dates', __( 'The season must end on or after its start date.', 'flexo-booking' ) );
		}

		$price = self::parse_amount( isset( $input['price'] ) ? $input['price'] : '' );
		if ( null === $price ) {
			return new WP_Error( 'flexo_season_price', __( 'Please enter the price per night.', 'flexo-booking' ) );
		}
		$weekend = self::parse_amount( isset( $input['weekend_price'] ) ? $input['weekend_price'] : '' );
		$min     = isset( $input['min_nights'] ) && '' !== $input['min_nights'] ? absint( $input['min_nights'] ) : 0;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clash = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND id <> %d AND date_from <= %s AND date_to >= %s ORDER BY date_from ASC LIMIT 1", $room_id, (int) $id, $to, $from ), ARRAY_A );
		if ( $clash ) {
			return new WP_Error(
				'flexo_season_overlap',
				sprintf(
					/* translators: 1: season name, 2: start date, 3: end date */
					__( 'These dates overlap with "%1$s" (%2$s – %3$s). Seasons of the same room cannot overlap – change the dates or edit that season.', 'flexo-booking' ),
					$clash['name'],
					Flexo_Booking_Dates::display( $clash['date_from'] ),
					Flexo_Booking_Dates::display( $clash['date_to'] )
				)
			);
		}

		return array(
			'room_id'       => $room_id,
			'name'          => $name,
			'date_from'     => $from,
			'date_to'       => $to,
			'price'         => $price,
			'weekend_price' => $weekend,
			'min_nights'    => $min > 0 ? $min : null,
		);
	}

	/**
	 * "120", "120.50" or "120,50" → 120.5; empty → null.
	 */
	public static function parse_amount( $value ) {
		if ( null === $value ) {
			return null;
		}
		$value = str_replace( array( ' ', "\xC2\xA0" ), '', trim( (string) $value ) );
		if ( '' === $value ) {
			return null;
		}
		$value = str_replace( ',', '.', $value );
		if ( ! is_numeric( $value ) || (float) $value < 0 ) {
			return null;
		}
		return round( (float) $value, 2 );
	}

	/**
	 * @return int|WP_Error Season ID.
	 */
	public static function save( array $input, $id = 0 ) {
		global $wpdb;

		$data = self::validate( $input, $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated_at'] = current_time( 'mysql' );

		if ( $id ) {
			$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		} else {
			$data['created_at'] = $data['updated_at'];
			if ( ! $wpdb->insert( self::table(), $data ) ) {
				return new WP_Error( 'flexo_db_error', __( 'The season could not be saved.', 'flexo-booking' ) );
			}
			$id = (int) $wpdb->insert_id;
		}
		self::flush_cache();
		return (int) $id;
	}

	public static function delete( $id ) {
		global $wpdb;
		self::flush_cache();
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	public static function delete_for_room( $room_id ) {
		global $wpdb;
		self::flush_cache();
		return $wpdb->delete( self::table(), array( 'room_id' => (int) $room_id ) );
	}

	/**
	 * Copies the seasons starting in $year to the following year. Copies that
	 * would overlap an existing season are skipped and reported.
	 *
	 * @param int $room_id Room, or 0 for all rooms.
	 * @return array { @type int $copied, @type string[] $skipped }
	 */
	public static function copy_to_next_year( $room_id, $year ) {
		global $wpdb;

		$table  = self::table();
		$year   = (int) $year;
		$where  = $wpdb->prepare( 'date_from BETWEEN %s AND %s', $year . '-01-01', $year . '-12-31' );
		$where .= $room_id ? $wpdb->prepare( ' AND room_id = %d', $room_id ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY room_id, date_from", ARRAY_A );
		$result = array(
			'copied'  => 0,
			'skipped' => array(),
		);

		foreach ( $rows ? $rows : array() as $row ) {
			$row   = self::hydrate( $row );
			$saved = self::save(
				array(
					'room_id'       => $row['room_id'],
					'name'          => $row['name'],
					'date_from'     => Flexo_Booking_Dates::next_year( $row['date_from'] ),
					'date_to'       => Flexo_Booking_Dates::next_year( $row['date_to'] ),
					'price'         => $row['price'],
					'weekend_price' => $row['weekend_price'],
					'min_nights'    => $row['min_nights'],
				)
			);
			if ( is_wp_error( $saved ) ) {
				$result['skipped'][] = get_the_title( $row['room_id'] ) . ': ' . $row['name'];
			} else {
				++$result['copied'];
			}
		}
		return $result;
	}
}
