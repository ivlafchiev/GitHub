<?php
/**
 * Money formatting. Prices are stored as plain numbers; changing the currency
 * setting never converts them.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Money {

	/**
	 * Number format presets: key => [decimal separator, thousands separator].
	 * 'auto' follows the site language (1.0.0 behaviour).
	 */
	public static function number_formats() {
		return array(
			'auto'        => array( null, null, __( 'Automatic (site language)', 'flexo-booking' ) ),
			'comma_dot'   => array( '.', ',', '1,234.56' ),
			'dot_comma'   => array( ',', '.', '1.234,56' ),
			'space_comma' => array( ',', "\xC2\xA0", '1 234,56' ),
			'space_dot'   => array( '.', "\xC2\xA0", '1 234.56' ),
			'none_comma'  => array( ',', '', '1234,56' ),
			'none_dot'    => array( '.', '', '1234.56' ),
		);
	}

	public static function positions() {
		return array(
			'before'        => __( 'Before, no space (€120.00)', 'flexo-booking' ),
			'before_space'  => __( 'Before, with space (€ 120.00)', 'flexo-booking' ),
			'after'         => __( 'After, with space (120.00 €)', 'flexo-booking' ),
			'after_nospace' => __( 'After, no space (120.00€)', 'flexo-booking' ),
		);
	}

	public static function decimals() {
		return min( 3, max( 0, (int) Flexo_Booking_Settings::get( 'currency_decimals' ) ) );
	}

	public static function round( $amount ) {
		return round( (float) $amount, self::decimals() );
	}

	public static function currency() {
		return (string) Flexo_Booking_Settings::get( 'currency' );
	}

	/**
	 * @param float       $amount
	 * @param string|null $currency Currency code the amount is in (e.g. a
	 *                              booking's stored currency). When it differs
	 *                              from the current setting, the code is shown
	 *                              instead of the symbol, so old bookings are
	 *                              never re-labelled.
	 */
	public static function format( $amount, $currency = null ) {
		$decimals = self::decimals();
		$formats  = self::number_formats();
		$preset   = Flexo_Booking_Settings::get( 'number_format' );
		$preset   = isset( $formats[ $preset ] ) ? $preset : 'auto';

		if ( 'auto' === $preset ) {
			$number = number_format_i18n( (float) $amount, $decimals );
		} else {
			$number = number_format( (float) $amount, $decimals, $formats[ $preset ][0], $formats[ $preset ][1] );
		}

		$symbol = (string) Flexo_Booking_Settings::get( 'currency_symbol' );
		if ( $currency && strtoupper( $currency ) !== strtoupper( self::currency() ) ) {
			return $number . ' ' . strtoupper( $currency );
		}

		switch ( Flexo_Booking_Settings::get( 'currency_position' ) ) {
			case 'before':
				return $symbol . $number;
			case 'before_space':
				return $symbol . ' ' . $number;
			case 'after_nospace':
				return $number . $symbol;
			default:
				return $number . ' ' . $symbol;
		}
	}
}
