<?php
/**
 * Children & ages (feature "children").
 *
 * One simple rule set, used for every per-person amount (rate-plan
 * supplements per guest, and the tourist tax when it follows these rules):
 *
 *   younger than "free_under"            → free
 *   from "free_under" up to "adult_from" → "percent" % of the adult amount
 *   "adult_from" and older               → the adult amount
 *
 * The rules are global (Settings → Children); a room can use its own
 * (room meta _flexo_child_rules). The room price itself is per room, so
 * children never change it.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Children {

	const ROOM_META = '_flexo_child_rules';
	const MAX_AGE   = 17;

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'children' );
	}

	/**
	 * The rules that apply to a room: its own when set, otherwise the global ones.
	 *
	 * @param array|int|null $room Room array or ID.
	 * @return array { free_under, percent, adult_from, custom }
	 */
	public static function rules( $room = null ) {
		$room_id = is_array( $room ) ? (int) $room['id'] : (int) $room;
		if ( $room_id ) {
			$own = self::room_rules( $room_id );
			if ( $own ) {
				return array_merge( $own, array( 'custom' => true ) );
			}
		}
		return array_merge( self::global_rules(), array( 'custom' => false ) );
	}

	public static function global_rules() {
		return self::normalize(
			array(
				'free_under' => Flexo_Booking_Settings::get( 'child_free_under' ),
				'percent'    => Flexo_Booking_Settings::get( 'child_percent' ),
				'adult_from' => Flexo_Booking_Settings::get( 'child_adult_from' ),
			)
		);
	}

	/**
	 * @return array|null The room's own rules, or null to use the global ones.
	 */
	public static function room_rules( $room_id ) {
		$own = get_post_meta( (int) $room_id, self::ROOM_META, true );
		return is_array( $own ) && isset( $own['free_under'], $own['percent'], $own['adult_from'] ) ? self::normalize( $own ) : null;
	}

	/**
	 * @param int        $room_id
	 * @param array|null $rules Null removes the room's own rules.
	 */
	public static function save_room_rules( $room_id, $rules ) {
		if ( ! is_array( $rules ) ) {
			delete_post_meta( (int) $room_id, self::ROOM_META );
			return;
		}
		update_post_meta( (int) $room_id, self::ROOM_META, self::normalize( $rules ) );
	}

	public static function normalize( array $rules ) {
		$free  = min( 18, absint( isset( $rules['free_under'] ) ? $rules['free_under'] : 0 ) );
		$adult = min( 18, absint( isset( $rules['adult_from'] ) ? $rules['adult_from'] : 18 ) );
		return array(
			'free_under' => $free,
			'percent'    => min( 100, max( 0, round( (float) ( isset( $rules['percent'] ) ? $rules['percent'] : 100 ), 2 ) ) ),
			'adult_from' => max( $free, $adult ),
		);
	}

	/**
	 * Share of the adult amount a child of this age pays: 0, the percentage, or 1.
	 * A child whose age is unknown pays the adult amount.
	 */
	public static function factor( array $rules, $age ) {
		if ( null === $age ) {
			return 1.0;
		}
		if ( $age < $rules['free_under'] ) {
			return 0.0;
		}
		if ( $age < $rules['adult_from'] ) {
			return $rules['percent'] / 100;
		}
		return 1.0;
	}

	/**
	 * Parses children's ages from "4, 11", "4,11" or an array.
	 *
	 * @return int[]|WP_Error
	 */
	public static function parse_ages( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_string( $value ) || is_numeric( $value ) ) {
			$value = preg_split( '/[\s,;]+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		}
		$ages = array();
		foreach ( (array) $value as $age ) {
			$age = is_string( $age ) ? trim( $age ) : $age;
			if ( '' === $age || null === $age || ! is_numeric( $age ) || (int) $age != $age || $age < 0 || $age > self::MAX_AGE ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- "4" == 4.
				return self::age_error();
			}
			$ages[] = (int) $age;
		}
		return $ages;
	}

	/**
	 * Checks that an age was given for each child.
	 *
	 * @return int[]|WP_Error
	 */
	public static function validate( $children, $ages ) {
		$ages = self::parse_ages( $ages );
		if ( is_wp_error( $ages ) ) {
			return $ages;
		}
		if ( count( $ages ) !== (int) $children ) {
			return self::age_error();
		}
		return $ages;
	}

	private static function age_error() {
		/* translators: %d: maximum child age */
		return new WP_Error( 'flexo_child_ages', sprintf( __( 'Please select the age of each child (0–%d years).', 'flexo-booking' ), self::MAX_AGE ) );
	}

	/**
	 * Plain-language summary, e.g. "Under 3: free · 3–11: 50% · 12 and older: adult price".
	 */
	public static function describe( array $rules ) {
		$parts = array();
		if ( $rules['free_under'] > 0 ) {
			/* translators: %d: age */
			$parts[] = sprintf( __( 'under %d: free', 'flexo-booking' ), $rules['free_under'] );
		}
		if ( $rules['adult_from'] > $rules['free_under'] ) {
			$parts[] = sprintf(
				/* translators: 1: from age, 2: to age, 3: percentage */
				__( '%1$d–%2$d: %3$s%% of the adult price', 'flexo-booking' ),
				$rules['free_under'],
				$rules['adult_from'] - 1,
				self::percent_text( $rules['percent'] )
			);
		}
		/* translators: %d: age */
		$parts[] = sprintf( __( '%d and older: adult price', 'flexo-booking' ), $rules['adult_from'] );
		return ucfirst( implode( ' · ', $parts ) );
	}

	public static function percent_text( $percent ) {
		return rtrim( rtrim( number_format( (float) $percent, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * "2 adults, 2 children (4, 11)" – for admin and emails.
	 */
	public static function guests_text( $adults, $children, $ages = '' ) {
		/* translators: %d: number of adults */
		$text = sprintf( _n( '%d adult', '%d adults', $adults, 'flexo-booking' ), $adults );
		if ( $children ) {
			/* translators: %d: number of children */
			$text .= ', ' . sprintf( _n( '%d child', '%d children', $children, 'flexo-booking' ), $children );
			$ages  = is_array( $ages ) ? implode( ', ', $ages ) : str_replace( ',', ', ', (string) $ages );
			if ( '' !== $ages ) {
				/* translators: %s: ages, e.g. "4, 11" */
				$text .= ' ' . sprintf( __( '(ages %s)', 'flexo-booking' ), $ages );
			}
		}
		return $text;
	}
}
