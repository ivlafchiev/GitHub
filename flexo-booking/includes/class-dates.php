<?php
/**
 * Date helpers. Storage is always Y-m-d; the admin shows DD.MM.YYYY.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Dates {

	/**
	 * Accepts DD.MM.YYYY (also with / or -) or YYYY-MM-DD.
	 *
	 * @return string|false Y-m-d, or false when invalid.
	 */
	public static function parse( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = (int) $m[3];
		} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			$year  = (int) $m[1];
			$month = (int) $m[2];
			$day   = (int) $m[3];
		} else {
			return false;
		}
		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Y-m-d → DD.MM.YYYY (admin display).
	 */
	public static function display( $ymd ) {
		$parts = explode( '-', (string) $ymd );
		return 3 === count( $parts ) ? $parts[2] . '.' . $parts[1] . '.' . $parts[0] : (string) $ymd;
	}

	/**
	 * Y-m-d → the site's date format (guest-facing text).
	 */
	public static function display_site( $ymd ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		return $date ? wp_date( get_option( 'date_format' ), $date->getTimestamp() ) : (string) $ymd;
	}

	public static function add_days( $ymd, $days ) {
		$date = new DateTimeImmutable( $ymd, wp_timezone() );
		return $date->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Same day and month one year later; 29.02 becomes 28.02.
	 */
	public static function next_year( $ymd ) {
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $ymd ) );
		++$year;
		if ( 2 === $month && 29 === $day && ! checkdate( 2, 29, $year ) ) {
			$day = 28;
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Nights of a stay: check-in up to (not including) check-out.
	 *
	 * @return string[] Y-m-d dates.
	 */
	public static function nights( $check_in, $check_out ) {
		$tz     = wp_timezone();
		$night  = new DateTimeImmutable( $check_in, $tz );
		$end    = new DateTimeImmutable( $check_out, $tz );
		$nights = array();
		while ( $night < $end ) {
			$nights[] = $night->format( 'Y-m-d' );
			$night    = $night->modify( '+1 day' );
		}
		return $nights;
	}

	/**
	 * Friday and Saturday nights use the weekend price.
	 */
	public static function is_weekend_night( $ymd ) {
		$date = new DateTimeImmutable( $ymd, wp_timezone() );
		return in_array( (int) $date->format( 'N' ), array( 5, 6 ), true );
	}
}
