<?php
/**
 * Contact form (1.10.0): one component for [flexo_contact], the "Flexo
 * Contact Form" widget and the "Booking & Contact tabs" ([flexo_forms_tabs]
 * and the Forms Tabs widget).
 *
 * A message is handled like an availability enquiry (Flexo_Booking_Guest::
 * contact_message()): emailed to the hotel with the guest as Reply-To,
 * listed on Today until answered, exported and erased with the guest's
 * other data, and deleted after the enquiry retention period. It never
 * creates a booking.
 *
 * The form uses the booking form's styles, messages and tokens, so it looks
 * like it on the same page and follows the same Appearance settings.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Contact {

	public static function init() {
		add_shortcode( 'flexo_contact', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'flexo_forms_tabs', array( __CLASS__, 'tabs_shortcode' ) );
	}

	public static function shortcode( $atts ) {
		return self::render( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * The contact form.
	 *
	 * @param array $atts {
	 *     @type string $title        Optional heading.
	 *     @type string $intro        Optional text above the fields.
	 *     @type string $button_text  Text of the send button ('' = Settings → Pages → Contact form).
	 *     @type string $success_text Text after sending ('' = Settings → Pages → Contact form).
	 * }
	 */
	public static function render( array $atts ) {
		$atts = shortcode_atts(
			array(
				'title'        => '',
				'intro'        => '',
				'button_text'  => '',
				'success_text' => '',
			),
			$atts,
			'flexo_contact'
		);
		self::assets();
		ob_start();
		Flexo_Booking_Frontend::load_template(
			'contact-form.php',
			array(
				'atts'    => $atts,
				'uid'     => 'fb-' . wp_unique_id(),
				'button'  => '' !== trim( $atts['button_text'] ) ? $atts['button_text'] : Flexo_Booking_Forms::contact_text( 'button' ),
				'success' => trim( (string) $atts['success_text'] ),
				'consent' => self::consent_html(),
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * The booking form's styles and script, its texts and the Appearance CSS.
	 */
	private static function assets() {
		if ( ! wp_script_is( 'flexo-booking', 'registered' ) ) {
			Flexo_Booking_Frontend::register_assets();
		}
		wp_enqueue_style( 'flexo-booking' );
		Flexo_Booking_Frontend::appearance();
		wp_enqueue_script( 'flexo-booking' );
		Flexo_Booking_Frontend::localize();
	}

	/**
	 * Privacy consent, as in the booking form (only when that feature is on).
	 */
	private static function consent_html() {
		if ( ! Flexo_Booking_Privacy::enabled() ) {
			return '';
		}
		$required = Flexo_Booking_Privacy::consent_required();
		// Never ticked in advance.
		return '<label class="fb-terms fb-consent"><input type="checkbox" name="privacy_consent" value="1"' . ( $required ? ' required' : '' ) . '> <span>' . Flexo_Booking_Privacy::consent_text( true, '', 'message' ) . ( $required ? ' <span class="fb-req" aria-hidden="true">*</span>' : '' ) . '</span></label>';
	}

	public static function tabs_shortcode( $atts ) {
		return self::tabs( is_array( $atts ) ? $atts : array() );
	}

	/**
	 * The booking form and the contact form as two tabs ("Booking | Contact").
	 *
	 * @param array $atts {
	 *     @type string $booking_label Tab text (default "Booking").
	 *     @type string $contact_label Tab text (default "Contact").
	 *     @type string $default       booking | contact: the tab shown first.
	 *     @type string $label         Name of the tab list for screen readers.
	 *     @type array  $booking       Options of the booking form (as [flexo_booking]).
	 *     @type array  $contact       Options of the contact form (as [flexo_contact]).
	 * }
	 */
	public static function tabs( array $atts ) {
		$atts = array_merge(
			array(
				'booking_label' => '',
				'contact_label' => '',
				'default'       => 'booking',
				'label'         => '',
				'booking'       => array(),
				'contact'       => array(),
			),
			$atts
		);
		// Shortcode attributes for the forms: booking_title="…", contact_title="…".
		foreach ( $atts as $key => $value ) {
			if ( is_string( $value ) && preg_match( '/^(booking|contact)_(title|intro|button_text|success_text|results|amenity_icons|room)$/', $key, $m ) ) {
				$atts[ $m[1] ][ $m[2] ] = $value;
			}
		}
		$default = 'contact' === $atts['default'] ? 'contact' : 'booking';
		$uid     = 'fb-tabs-' . wp_unique_id();
		$tabs    = array(
			'booking' => '' !== trim( (string) $atts['booking_label'] ) ? $atts['booking_label'] : __( 'Booking', 'flexo-booking' ),
			'contact' => '' !== trim( (string) $atts['contact_label'] ) ? $atts['contact_label'] : __( 'Contact', 'flexo-booking' ),
		);
		$panels = array(
			'booking' => Flexo_Booking_Frontend::render( array_merge( (array) $atts['booking'], array( 'layout' => 'full' ) ) ),
			'contact' => self::render( (array) $atts['contact'] ),
		);
		$list  = '' !== trim( (string) $atts['label'] ) ? $atts['label'] : __( 'Book or send us a message', 'flexo-booking' );
		$html  = '<div class="flexo-forms-tabs" data-flexo-tabs>';
		$html .= '<div class="fb-tabs" role="tablist" aria-label="' . esc_attr( $list ) . '">';
		foreach ( $tabs as $key => $text ) {
			$on    = $key === $default;
			$html .= '<button type="button" class="fb-tab" role="tab" id="' . esc_attr( $uid . '-' . $key ) . '-tab" aria-controls="' . esc_attr( $uid . '-' . $key ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '"' . ( $on ? '' : ' tabindex="-1"' ) . ' data-key="' . esc_attr( $key ) . '">' . esc_html( $text ) . '</button>';
		}
		$html .= '</div>';
		foreach ( $panels as $key => $panel ) {
			$html .= '<div class="fb-tabpanel" role="tabpanel" id="' . esc_attr( $uid . '-' . $key ) . '" aria-labelledby="' . esc_attr( $uid . '-' . $key ) . '-tab" tabindex="0"' . ( $key === $default ? '' : ' hidden' ) . '>' . $panel . '</div>';
		}
		return $html . '</div>';
	}
}
