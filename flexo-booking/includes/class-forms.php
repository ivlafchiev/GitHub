<?php
/**
 * Form fields (1.9.0): the guest details of the booking form (here) and
 * the contact form (Session B).
 *
 * Booking fields are not a form builder: name and email are always asked
 * and required; phone keeps its Required / Optional / Not asked setting and
 * special requests Optional / Not asked (both stay in the main settings,
 * field_phone and field_notes, as before). This class adds labels,
 * placeholders, help texts and the order. Dates, rooms, guests, rates and
 * prices belong to the booking engine and are never form fields. Privacy
 * consent and invoice requests keep their own settings.
 *
 * Extension point: the filter flexo_booking_guest_fields can change the
 * definitions (texts, order); it can't add stored fields.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Forms {

	const OPTION = 'flexo_booking_forms';

	/**
	 * Guest detail fields of the booking form, in their default order.
	 *
	 * @return array key => { label, placeholder, help, type, input }
	 */
	public static function booking_field_defaults() {
		return array(
			'name'  => array(
				'label'       => __( 'Full name', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => '',
			),
			'email' => array(
				'label'       => __( 'Email', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => __( 'We send your confirmation here.', 'flexo-booking' ),
			),
			'phone' => array(
				'label'       => __( 'Phone', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => '',
			),
			'notes' => array(
				'label'       => __( 'Special requests', 'flexo-booking' ),
				'placeholder' => __( 'e.g. late arrival, baby cot, quiet room', 'flexo-booking' ),
				'help'        => '',
			),
		);
	}

	public static function defaults() {
		$fields = array();
		foreach ( array_keys( self::booking_field_defaults() ) as $key ) {
			$fields[ $key ] = array(
				'label'       => '',
				'placeholder' => '',
				'help'        => '',
			);
		}
		return array(
			'booking_fields' => $fields,
			'booking_order'  => array_keys( self::booking_field_defaults() ),
		);
	}

	public static function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
		foreach ( $defaults['booking_fields'] as $key => $field ) {
			$out['booking_fields'][ $key ] = array_merge( $field, isset( $saved['booking_fields'][ $key ] ) && is_array( $saved['booking_fields'][ $key ] ) ? $saved['booking_fields'][ $key ] : array() );
		}
		return $out;
	}

	/**
	 * Sanitises submitted field settings and merges them with the saved ones.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$clean    = self::all();
		$defaults = self::booking_field_defaults();
		if ( isset( $input['booking_fields'] ) && is_array( $input['booking_fields'] ) ) {
			foreach ( $defaults as $key => $default ) {
				if ( ! isset( $input['booking_fields'][ $key ] ) || ! is_array( $input['booking_fields'][ $key ] ) ) {
					continue;
				}
				foreach ( array( 'label', 'placeholder', 'help' ) as $part ) {
					if ( ! isset( $input['booking_fields'][ $key ][ $part ] ) ) {
						continue;
					}
					$value = trim( sanitize_text_field( (string) $input['booking_fields'][ $key ][ $part ] ) );
					$value = substr( $value, 0, 200 );
					// The default text is not stored, so it stays translated.
					$clean['booking_fields'][ $key ][ $part ] = $value === $default[ $part ] ? '' : $value;
				}
			}
		}
		if ( isset( $input['booking_order'] ) ) {
			$order                  = is_array( $input['booking_order'] ) ? $input['booking_order'] : explode( ',', (string) $input['booking_order'] );
			$order                  = array_values( array_intersect( array_unique( array_map( 'sanitize_key', $order ) ), array_keys( $defaults ) ) );
			$clean['booking_order'] = array_merge( $order, array_values( array_diff( array_keys( $defaults ), $order ) ) );
		}
		return $clean;
	}

	public static function update( array $changes ) {
		update_option( self::OPTION, self::sanitize( $changes ) );
	}

	/**
	 * How a booking field is asked: required, optional or hidden. Name and
	 * email are always required (the booking engine needs them).
	 */
	public static function booking_field_mode( $key, $settings = null ) {
		$settings = null === $settings ? Flexo_Booking_Settings::all() : $settings;
		if ( 'phone' === $key ) {
			return in_array( $settings['field_phone'], array( 'required', 'optional', 'hidden' ), true ) ? $settings['field_phone'] : 'required';
		}
		if ( 'notes' === $key ) {
			return 'hidden' === $settings['field_notes'] ? 'hidden' : 'optional';
		}
		return 'required';
	}

	/**
	 * The guest detail fields as shown: texts (the hotel's, translated, or
	 * the defaults), mode, in the hotel's order.
	 *
	 * @return array key => { label, placeholder, help, mode }
	 */
	public static function booking_fields( $settings = null ) {
		$all      = self::all();
		$defaults = self::booking_field_defaults();
		$fields   = array();
		foreach ( $all['booking_order'] as $key ) {
			if ( ! isset( $defaults[ $key ] ) ) {
				continue;
			}
			$field = array( 'mode' => self::booking_field_mode( $key, $settings ) );
			foreach ( array( 'label', 'placeholder', 'help' ) as $part ) {
				$own            = (string) $all['booking_fields'][ $key ][ $part ];
				$field[ $part ] = '' !== $own ? Flexo_Booking_I18n::translate( $own, 'field_booking_' . $key . '_' . $part ) : $defaults[ $key ][ $part ];
			}
			$fields[ $key ] = $field;
		}
		return apply_filters( 'flexo_booking_guest_fields', $fields );
	}

	/**
	 * Texts the hotel changed, for Polylang / WPML string translation.
	 */
	public static function translatable_strings() {
		$strings = array();
		$all     = self::all();
		foreach ( $all['booking_fields'] as $key => $field ) {
			foreach ( $field as $part => $value ) {
				if ( '' !== trim( (string) $value ) ) {
					$strings[ 'field_booking_' . $key . '_' . $part ] = $value;
				}
			}
		}
		return $strings;
	}

	/**
	 * The guest detail fields of the booking form (HTML).
	 */
	public static function booking_fields_html( $uid, $settings = null ) {
		$html = '';
		foreach ( self::booking_fields( $settings ) as $key => $field ) {
			if ( 'hidden' === $field['mode'] ) {
				continue;
			}
			$id       = $uid . '-' . $key;
			$required = 'required' === $field['mode'];
			$marker   = $required ? ' <span class="fb-req" aria-hidden="true">*</span>' : ' <span class="fb-optional">' . esc_html__( '(optional)', 'flexo-booking' ) . '</span>';
			$help     = '' !== $field['help'] ? '<small class="fb-hint" id="' . esc_attr( $id ) . '-hint">' . esc_html( $field['help'] ) . '</small>' : '';
			$describe = '' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-hint"' : '';
			$holder   = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
			$label    = '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $marker . '</label>';
			switch ( $key ) {
				case 'name':
					$html .= '<div class="fb-field">' . $label . '<input id="' . esc_attr( $id ) . '" type="text" name="guest_name" required autocomplete="name" autocapitalize="words" enterkeyhint="next"' . $holder . $describe . '>' . $help . '</div>';
					break;
				case 'email':
					$html .= '<div class="fb-field">' . $label . '<input id="' . esc_attr( $id ) . '" type="email" name="guest_email" required autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" enterkeyhint="next"' . $holder . $describe . '>' . $help . '</div>';
					break;
				case 'phone':
					$html .= '<div class="fb-field fb-field--phone">' . $label . '<div class="fb-phone">' . Flexo_Booking_Frontend::phone_country_select( $uid ) . '<input id="' . esc_attr( $id ) . '" type="tel" name="guest_phone"' . ( $required ? ' required' : '' ) . ' autocomplete="tel" inputmode="tel" enterkeyhint="next"' . $holder . $describe . '></div>' . $help . '</div>';
					break;
				case 'notes':
					$html .= '<div class="fb-field fb-field--wide">' . $label . '<textarea id="' . esc_attr( $id ) . '" name="notes" rows="3"' . $holder . $describe . '></textarea>' . $help . '</div>';
					break;
			}
		}
		return $html;
	}
}
