<?php
/**
 * Form fields (1.9.0): the guest details of the booking form and (1.10.0)
 * the contact form.
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

	/**
	 * Fields of the contact form (1.10.0), in their order.
	 *
	 * @return array key => { label, placeholder, help }
	 */
	public static function contact_field_defaults() {
		return array(
			'name'    => array(
				'label'       => __( 'Your name', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => '',
			),
			'email'   => array(
				'label'       => __( 'Email', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => __( 'We reply to this address.', 'flexo-booking' ),
			),
			'phone'   => array(
				'label'       => __( 'Phone', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => '',
			),
			'subject' => array(
				'label'       => __( 'Subject', 'flexo-booking' ),
				'placeholder' => '',
				'help'        => '',
			),
			'message' => array(
				'label'       => __( 'Your message', 'flexo-booking' ),
				'placeholder' => __( 'How can we help?', 'flexo-booking' ),
				'help'        => '',
			),
		);
	}

	/**
	 * Default texts of the contact form around its fields.
	 */
	public static function contact_text_defaults() {
		return array(
			'button'  => __( 'Send message', 'flexo-booking' ),
			'success' => __( 'Thank you! Your message has been sent. We will reply by email soon.', 'flexo-booking' ),
		);
	}

	public static function defaults() {
		$empty  = array(
			'label'       => '',
			'placeholder' => '',
			'help'        => '',
		);
		$fields = array();
		foreach ( array_keys( self::booking_field_defaults() ) as $key ) {
			$fields[ $key ] = $empty;
		}
		$contact = array();
		foreach ( array_keys( self::contact_field_defaults() ) as $key ) {
			$contact[ $key ] = $empty;
		}
		return array(
			'booking_fields'    => $fields,
			'booking_order'     => array_keys( self::booking_field_defaults() ),
			// 1.10.0: contact form.
			'contact_fields'    => $contact,
			'contact_phone'     => 'optional',
			'contact_subject'   => 'hidden',
			'contact_texts'     => array(
				'button'  => '',
				'success' => '',
			),
			'contact_autoreply' => 0,
		);
	}

	public static function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
		foreach ( array( 'booking_fields', 'contact_fields' ) as $group ) {
			foreach ( $defaults[ $group ] as $key => $field ) {
				$out[ $group ][ $key ] = array_merge( $field, isset( $saved[ $group ][ $key ] ) && is_array( $saved[ $group ][ $key ] ) ? $saved[ $group ][ $key ] : array() );
			}
		}
		$out['contact_texts'] = array_merge( $defaults['contact_texts'], is_array( $out['contact_texts'] ) ? array_intersect_key( $out['contact_texts'], $defaults['contact_texts'] ) : array() );
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
		// 1.10.0: contact form.
		$contact = self::contact_field_defaults();
		if ( isset( $input['contact_fields'] ) && is_array( $input['contact_fields'] ) ) {
			foreach ( $contact as $key => $default ) {
				foreach ( array( 'label', 'placeholder', 'help' ) as $part ) {
					if ( ! isset( $input['contact_fields'][ $key ][ $part ] ) ) {
						continue;
					}
					$value = substr( trim( sanitize_text_field( (string) $input['contact_fields'][ $key ][ $part ] ) ), 0, 200 );
					$clean['contact_fields'][ $key ][ $part ] = $value === $default[ $part ] ? '' : $value;
				}
			}
		}
		if ( isset( $input['contact_phone'] ) ) {
			$clean['contact_phone'] = in_array( $input['contact_phone'], array( 'required', 'optional', 'hidden' ), true ) ? $input['contact_phone'] : 'optional';
		}
		if ( isset( $input['contact_subject'] ) ) {
			$clean['contact_subject'] = in_array( $input['contact_subject'], array( 'required', 'optional', 'hidden' ), true ) ? $input['contact_subject'] : 'hidden';
		}
		if ( isset( $input['contact_texts'] ) && is_array( $input['contact_texts'] ) ) {
			$text_defaults = self::contact_text_defaults();
			foreach ( $text_defaults as $key => $default ) {
				if ( isset( $input['contact_texts'][ $key ] ) ) {
					$value = substr( trim( sanitize_text_field( (string) $input['contact_texts'][ $key ] ) ), 0, 300 );
					$clean['contact_texts'][ $key ] = $value === $default ? '' : $value;
				}
			}
		}
		if ( isset( $input['contact_autoreply'] ) ) {
			$clean['contact_autoreply'] = empty( $input['contact_autoreply'] ) ? 0 : 1;
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
		foreach ( array( 'booking', 'contact' ) as $form ) {
			foreach ( $all[ $form . '_fields' ] as $key => $field ) {
				foreach ( $field as $part => $value ) {
					if ( '' !== trim( (string) $value ) ) {
						$strings[ 'field_' . $form . '_' . $key . '_' . $part ] = $value;
					}
				}
			}
		}
		foreach ( $all['contact_texts'] as $key => $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$strings[ 'contact_' . $key ] = $value;
			}
		}
		return $strings;
	}

	/**
	 * How a contact field is asked (1.10.0): name, email and message are always required.
	 */
	public static function contact_field_mode( $key ) {
		$all = self::all();
		if ( 'phone' === $key ) {
			return $all['contact_phone'];
		}
		if ( 'subject' === $key ) {
			return $all['contact_subject'];
		}
		return 'required';
	}

	/**
	 * The contact form's fields as shown (texts translated or the defaults, mode).
	 *
	 * @return array key => { label, placeholder, help, mode }
	 */
	public static function contact_fields() {
		$all    = self::all();
		$fields = array();
		foreach ( self::contact_field_defaults() as $key => $default ) {
			$field = array( 'mode' => self::contact_field_mode( $key ) );
			foreach ( array( 'label', 'placeholder', 'help' ) as $part ) {
				$own            = (string) $all['contact_fields'][ $key ][ $part ];
				$field[ $part ] = '' !== $own ? Flexo_Booking_I18n::translate( $own, 'field_contact_' . $key . '_' . $part ) : $default[ $part ];
			}
			$fields[ $key ] = $field;
		}
		return apply_filters( 'flexo_booking_contact_fields', $fields );
	}

	/**
	 * A text around the contact form: the hotel's (translated) or the default.
	 *
	 * @param string $key button | success.
	 */
	public static function contact_text( $key ) {
		$all      = self::all();
		$defaults = self::contact_text_defaults();
		$own      = isset( $all['contact_texts'][ $key ] ) ? (string) $all['contact_texts'][ $key ] : '';
		return '' !== $own ? Flexo_Booking_I18n::translate( $own, 'contact_' . $key ) : ( isset( $defaults[ $key ] ) ? $defaults[ $key ] : '' );
	}

	/**
	 * The contact form's fields (HTML), laid out like the booking form's guest details.
	 */
	public static function contact_fields_html( $uid ) {
		$html = '';
		foreach ( self::contact_fields() as $key => $field ) {
			if ( 'hidden' === $field['mode'] ) {
				continue;
			}
			$id       = $uid . '-' . $key;
			$required = 'required' === $field['mode'];
			$marker   = $required ? ' <span class="fb-req" aria-hidden="true">*</span>' : ' <span class="fb-optional">' . esc_html__( '(optional)', 'flexo-booking' ) . '</span>';
			$help     = '' !== $field['help'] ? '<small class="fb-hint" id="' . esc_attr( $id ) . '-hint">' . esc_html( $field['help'] ) . '</small>' : '';
			$describe = '' !== $help ? ' aria-describedby="' . esc_attr( $id ) . '-hint"' : '';
			$holder   = '' !== $field['placeholder'] ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
			$req      = $required ? ' required' : '';
			$label    = '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . $marker . '</label>';
			switch ( $key ) {
				case 'name':
					$html .= '<div class="fb-field">' . $label . '<input id="' . esc_attr( $id ) . '" type="text" name="name" required maxlength="120" autocomplete="name" autocapitalize="words" enterkeyhint="next"' . $holder . $describe . '>' . $help . '</div>';
					break;
				case 'email':
					$html .= '<div class="fb-field">' . $label . '<input id="' . esc_attr( $id ) . '" type="email" name="email" required maxlength="200" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" enterkeyhint="next"' . $holder . $describe . '>' . $help . '</div>';
					break;
				case 'phone':
					$html .= '<div class="fb-field fb-field--phone">' . $label . '<div class="fb-phone">' . Flexo_Booking_Frontend::phone_country_select( $uid ) . '<input id="' . esc_attr( $id ) . '" type="tel" name="phone"' . $req . ' maxlength="40" autocomplete="tel" inputmode="tel" enterkeyhint="next"' . $holder . $describe . '></div>' . $help . '</div>';
					break;
				case 'subject':
					$html .= '<div class="fb-field fb-field--wide">' . $label . '<input id="' . esc_attr( $id ) . '" type="text" name="subject"' . $req . ' maxlength="150" enterkeyhint="next"' . $holder . $describe . '>' . $help . '</div>';
					break;
				case 'message':
					$html .= '<div class="fb-field fb-field--wide">' . $label . '<textarea id="' . esc_attr( $id ) . '" name="message" rows="5" required maxlength="2000"' . $holder . $describe . '></textarea>' . $help . '</div>';
					break;
			}
		}
		return $html;
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
