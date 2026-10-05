<?php
/**
 * Appearance of the booking form (feature "custom_appearance").
 *
 * Two modes:
 *   match  – "Match my website" (default): the form inherits Elementor's
 *            global colours and fonts, or the theme's styles. Nothing is added.
 *   custom – the hotel picks a few colours, corners, fonts and text size.
 *
 * Custom values are CSS custom properties on `.flexo-booking`, added right
 * after booking.css, so they only affect the booking form. Priority:
 *   Elementor widget Style settings ({{WRAPPER}} .flexo-booking – more specific)
 *   > Appearance (Custom) > website global styles.
 *
 * Fonts are bundled with the plugin (OFL, self-hosted, Latin + Cyrillic) and
 * never loaded from Google. Only the chosen font files are declared, only on
 * pages with the booking form; browsers download just the subsets they need.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Appearance {

	/**
	 * Colour settings: key => CSS custom property.
	 */
	const COLORS = array(
		'appearance_primary'     => '--fb-primary',
		'appearance_accent'      => '--fb-accent',
		'appearance_text'        => '--fb-text',
		'appearance_bg'          => '--fb-bg',
		'appearance_button_text' => '--fb-primary-contrast',
	);

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_preview' ) );
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'custom_appearance' );
	}

	/**
	 * "custom" only when the feature is on and the hotel chose Custom.
	 */
	public static function mode() {
		return self::enabled() && 'custom' === Flexo_Booking_Settings::get( 'appearance_mode' ) ? 'custom' : 'match';
	}

	/**
	 * Bundled fonts: key => array( name, fallback stack ).
	 */
	public static function fonts() {
		return array(
			'inter'            => array( 'Inter', 'system-ui, sans-serif' ),
			'roboto'           => array( 'Roboto', 'system-ui, sans-serif' ),
			'open-sans'        => array( 'Open Sans', 'system-ui, sans-serif' ),
			'manrope'          => array( 'Manrope', 'system-ui, sans-serif' ),
			'montserrat'       => array( 'Montserrat', 'system-ui, sans-serif' ),
			'lora'             => array( 'Lora', 'Georgia, serif' ),
			'playfair-display' => array( 'Playfair Display', 'Georgia, serif' ),
		);
	}

	/**
	 * The website's Elementor global fonts (active kit): id => array( title, family ).
	 * Chosen as "el:<id>" and used through Elementor's own CSS variable, so the
	 * font is the one the website already loads.
	 */
	public static function elementor_fonts() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			return array();
		}
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
		if ( ! $kit ) {
			return array();
		}
		$fonts = array();
		foreach ( array( 'system_typography', 'custom_typography' ) as $group ) {
			foreach ( (array) $kit->get_settings( $group ) as $item ) {
				if ( empty( $item['_id'] ) || ! preg_match( '/^[a-z0-9]+$/i', (string) $item['_id'] ) ) {
					continue;
				}
				$fonts[ (string) $item['_id'] ] = array(
					isset( $item['title'] ) ? (string) $item['title'] : (string) $item['_id'],
					isset( $item['typography_font_family'] ) ? (string) $item['typography_font_family'] : '',
				);
			}
		}
		return $fonts;
	}

	/**
	 * A font choice: '' (website), a bundled font, "el:<id>" (Elementor
	 * global font) or "custom" (a font name typed by the owner).
	 */
	public static function sanitize_font_choice( $value ) {
		$value = (string) $value;
		if ( array_key_exists( $value, self::fonts() ) || 'custom' === $value || preg_match( '/^el:[a-z0-9]+$/i', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * A font name as used in CSS, e.g. "DM Sans" (letters, numbers, spaces, dashes).
	 */
	public static function sanitize_font_name( $value ) {
		$value = trim( preg_replace( '/[^\p{L}\p{N} \-]/u', '', (string) $value ) );
		return substr( preg_replace( '/\s+/', ' ', $value ), 0, 60 );
	}

	/**
	 * How much the page behind the "Check availability" panel is dimmed.
	 */
	public static function overlays() {
		return array(
			'light'  => array( __( 'Light', 'flexo-booking' ), 'rgba(15,20,25,.3)' ),
			'medium' => array( __( 'Medium', 'flexo-booking' ), 'rgba(15,20,25,.55)' ),
			'dark'   => array( __( 'Dark', 'flexo-booking' ), 'rgba(15,20,25,.8)' ),
		);
	}

	public static function corners() {
		return array(
			'square'  => array( __( 'Square', 'flexo-booking' ), '0px' ),
			'slight'  => array( __( 'Slightly rounded', 'flexo-booking' ), '6px' ),
			'rounded' => array( __( 'Rounded', 'flexo-booking' ), '16px' ),
		);
	}

	public static function text_sizes() {
		return array(
			'small'  => array( __( 'Small', 'flexo-booking' ), '0.9375em' ),
			'normal' => array( __( 'Normal', 'flexo-booking' ), '' ),
			'large'  => array( __( 'Large', 'flexo-booking' ), '1.0625em' ),
		);
	}

	public static function sanitize_color( $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && '#' !== $value[0] ) {
			$value = '#' . $value;
		}
		$color = sanitize_hex_color( $value );
		return $color ? strtolower( $color ) : '';
	}

	/**
	 * The settings as used by the form ('' = use the website's).
	 *
	 * @param array|null $settings Settings to use instead of the saved ones (preview).
	 */
	public static function values( $settings = null ) {
		$settings = null === $settings ? Flexo_Booking_Settings::all() : array_merge( Flexo_Booking_Settings::all(), $settings );
		$values   = array();
		foreach ( array_keys( self::COLORS ) as $key ) {
			$values[ $key ] = self::sanitize_color( isset( $settings[ $key ] ) ? $settings[ $key ] : '' );
		}
		$fonts   = self::fonts();
		$corners = self::corners();
		$sizes   = self::text_sizes();
		foreach ( array( 'appearance_heading_font', 'appearance_body_font' ) as $key ) {
			$values[ $key ]           = isset( $settings[ $key ] ) ? self::sanitize_font_choice( $settings[ $key ] ) : '';
			$values[ $key . '_name' ] = isset( $settings[ $key . '_name' ] ) ? self::sanitize_font_name( $settings[ $key . '_name' ] ) : '';
			if ( 'custom' === $values[ $key ] && '' === $values[ $key . '_name' ] ) {
				$values[ $key ] = '';
			}
		}
		$values['appearance_panel_bg'] = self::sanitize_color( isset( $settings['appearance_panel_bg'] ) ? $settings['appearance_panel_bg'] : '' );
		$values['appearance_overlay']  = isset( $settings['appearance_overlay'] ) && array_key_exists( $settings['appearance_overlay'], self::overlays() ) ? $settings['appearance_overlay'] : 'medium';
		$values['appearance_corners']   = isset( $settings['appearance_corners'], $corners[ $settings['appearance_corners'] ] ) ? $settings['appearance_corners'] : '';
		$values['appearance_text_size'] = isset( $settings['appearance_text_size'], $sizes[ $settings['appearance_text_size'] ] ) ? $settings['appearance_text_size'] : 'normal';
		return $values;
	}

	/**
	 * CSS for Custom mode ('' in "Match my website").
	 *
	 * @param array|null $settings Unsaved settings (preview), or null for the saved ones.
	 * @param bool       $force    Build it even in "Match" mode (preview of Custom).
	 */
	public static function css( $settings = null, $force = false ) {
		if ( ! $force && 'custom' !== self::mode() ) {
			return '';
		}
		$v     = self::values( $settings );
		$props = array();
		foreach ( self::COLORS as $key => $property ) {
			if ( '' !== $v[ $key ] ) {
				$props[] = $property . ':' . $v[ $key ];
			}
		}
		if ( '' !== $v['appearance_bg'] ) {
			$props[] = '--fb-field-bg:' . $v['appearance_bg'];
		}
		if ( '' !== $v['appearance_corners'] ) {
			$props[] = '--fb-radius:' . self::corners()[ $v['appearance_corners'] ][1];
		}
		if ( '' !== $v['appearance_body_font'] ) {
			$props[] = '--fb-font:' . self::family( $v['appearance_body_font'], $v['appearance_body_font_name'] );
		}
		if ( '' !== $v['appearance_heading_font'] ) {
			$props[] = '--fb-heading-font:' . self::family( $v['appearance_heading_font'], $v['appearance_heading_font_name'] );
		}
		if ( '' !== $v['appearance_panel_bg'] ) {
			$props[] = '--fb-panel-bg:' . $v['appearance_panel_bg'];
		}
		$props[] = '--fb-overlay:' . self::overlays()[ $v['appearance_overlay'] ][1];
		$size = self::text_sizes()[ $v['appearance_text_size'] ][1];
		if ( '' !== $size ) {
			$props[] = '--fb-font-size:' . $size;
		}
		$css = self::font_faces( array_unique( array_filter( array( $v['appearance_body_font'], $v['appearance_heading_font'] ) ) ) );
		if ( $props ) {
			$css .= '.flexo-booking{' . implode( ';', $props ) . '}';
		}
		return $css;
	}

	/**
	 * font-family value for a bundled font (named "Flexo …" so it never
	 * clashes with a copy of the same font loaded by the theme).
	 */
	public static function family( $key, $name = '' ) {
		if ( 'custom' === $key ) {
			return '"' . self::sanitize_font_name( $name ) . '",system-ui,sans-serif';
		}
		if ( 0 === strpos( (string) $key, 'el:' ) ) {
			// Elementor's variable for the global font; the website's font when it is missing.
			return 'var(--e-global-typography-' . substr( $key, 3 ) . '-font-family,inherit)';
		}
		$fonts = self::fonts();
		return '"Flexo ' . $fonts[ $key ][0] . '",' . $fonts[ $key ][1];
	}

	/**
	 * @font-face rules for the chosen bundled fonts.
	 *
	 * @param string[] $keys
	 */
	public static function font_faces( array $keys ) {
		$ranges = array(
			'cyrillic'  => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
			'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
			'latin'     => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
		);
		$fonts  = self::fonts();
		$css    = '';
		foreach ( $keys as $key ) {
			if ( ! isset( $fonts[ $key ] ) ) {
				continue;
			}
			foreach ( $ranges as $subset => $range ) {
				$url  = FLEXO_BOOKING_URL . 'assets/fonts/' . $key . '/' . $key . '-' . $subset . '-wght-normal.woff2';
				$css .= '@font-face{font-family:"Flexo ' . $fonts[ $key ][0] . '";font-style:normal;font-display:swap;font-weight:100 900;src:url(' . esc_url_raw( $url ) . ') format("woff2");unicode-range:' . $range . '}';
			}
		}
		return $css;
	}

	/**
	 * Adds the Custom CSS after booking.css (pages with the form only).
	 */
	public static function enqueue() {
		$css = self::css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'flexo-booking', $css );
		}
	}

	/**
	 * Main colour for emails: the Custom main colour, else the email colour setting.
	 */
	public static function email_color() {
		$primary = 'custom' === self::mode() ? self::values()['appearance_primary'] : '';
		if ( '' !== $primary ) {
			return $primary;
		}
		$color = sanitize_hex_color( (string) Flexo_Booking_Settings::get( 'email_color' ) );
		return $color ? $color : '#1f6f5c';
	}

	/* ---------------------------------------------------------------------
	 * Contrast (WCAG 2.x)
	 * ------------------------------------------------------------------- */

	public static function luminance( $hex ) {
		$hex = ltrim( self::sanitize_color( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return null;
		}
		$rgb = array();
		foreach ( array( 0, 2, 4 ) as $i ) {
			$c     = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$rgb[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	/**
	 * Contrast ratio 1–21, or null when a colour is missing.
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		if ( null === $la || null === $lb ) {
			return null;
		}
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Pairs below 4.5:1 (WCAG AA for normal text).
	 *
	 * @return array[] Each: pair key, ratio, suggestion.
	 */
	public static function contrast_problems( $settings = null ) {
		$v      = self::values( $settings );
		$pairs  = array(
			'text_bg'     => array( '' !== $v['appearance_text'] ? $v['appearance_text'] : '#1f2933', '' !== $v['appearance_bg'] ? $v['appearance_bg'] : '#ffffff' ),
			'button_text' => array( '' !== $v['appearance_button_text'] ? $v['appearance_button_text'] : '#ffffff', $v['appearance_primary'] ),
		);
		$issues = array();
		foreach ( $pairs as $key => $pair ) {
			if ( 'text_bg' === $key && '' === $v['appearance_text'] && '' === $v['appearance_bg'] ) {
				continue;
			}
			if ( 'button_text' === $key && '' === $v['appearance_primary'] ) {
				continue;
			}
			$ratio = self::contrast( $pair[0], $pair[1] );
			if ( null !== $ratio && $ratio < 4.5 ) {
				$issues[] = array(
					'pair'       => $key,
					'ratio'      => round( $ratio, 2 ),
					'suggestion' => self::contrast( '#ffffff', $pair[1] ) >= self::contrast( '#1f2933', $pair[1] ) ? '#ffffff' : '#1f2933',
				);
			}
		}
		return $issues;
	}

	/* ---------------------------------------------------------------------
	 * Live preview (front end, so the site's own styles apply)
	 * ------------------------------------------------------------------- */

	public static function preview_url() {
		return add_query_arg(
			array(
				'flexo_booking_preview' => 1,
				'_wpnonce'              => wp_create_nonce( 'flexo_booking_preview' ),
			),
			home_url( '/' )
		);
	}

	/**
	 * A realistic sample of the form (search bar, room card, rates, summary,
	 * buttons, an error) inside the site's theme, for the Appearance screen.
	 */
	public static function maybe_render_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below.
		if ( empty( $_GET['flexo_booking_preview'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'flexo_manage_settings' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'flexo-booking' ), 403 );
		}
		check_admin_referer( 'flexo_booking_preview' );
		show_admin_bar( false );
		wp_enqueue_style( 'flexo-booking' );
		// Match-mode preview shows the website style; Custom preview CSS is set by the screen's script.
		$flexo_currency = Flexo_Booking_Money::currency();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<meta name="robots" content="noindex">
			<?php wp_head(); ?>
			<style id="flexo-preview-fonts"><?php echo self::font_faces( array_keys( self::fonts() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from constants. ?></style>
			<style id="flexo-preview-custom"><?php echo self::css( null, 'custom' === self::mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised values. ?></style>
			<style>body{margin:0;padding:16px;background:#fff}.flexo-preview-label{font:12px/1.4 system-ui,sans-serif;color:#646970;margin:18px 0 6px}.flexo-booking.flexo-preview-backdrop{background:var(--fb-overlay,rgba(15,20,25,.55));padding:24px 16px;border-radius:0}.flexo-booking.flexo-preview-panel{display:block;position:static;margin:0 auto;max-height:none;width:100%;max-width:520px}</style>
		</head>
		<body <?php body_class( 'flexo-booking-preview' ); ?>>
		<div class="flexo-booking flexo-booking--full" data-flexo-preview>
			<h3 class="fb-title"><?php esc_html_e( 'Book your stay', 'flexo-booking' ); ?></h3>
			<form class="fb-search" onsubmit="return false">
				<div class="fb-field"><label for="pv-in"><?php esc_html_e( 'Check-in', 'flexo-booking' ); ?></label><input id="pv-in" type="text" value="<?php echo esc_attr( Flexo_Booking_I18n::format_date( wp_date( 'Y-m-d', strtotime( '+14 days' ) ) ) ); ?>"></div>
				<div class="fb-field"><label for="pv-out"><?php esc_html_e( 'Check-out', 'flexo-booking' ); ?></label><input id="pv-out" type="text" value="<?php echo esc_attr( Flexo_Booking_I18n::format_date( wp_date( 'Y-m-d', strtotime( '+17 days' ) ) ) ); ?>"></div>
				<div class="fb-field fb-field--small"><label for="pv-ad"><?php esc_html_e( 'Adults', 'flexo-booking' ); ?></label><select id="pv-ad"><option>2</option></select></div>
				<div class="fb-field fb-field--action"><button type="button" class="fb-button"><?php esc_html_e( 'Check availability', 'flexo-booking' ); ?></button></div>
			</form>
			<div class="fb-results">
				<div class="fb-rooms">
					<article class="fb-room">
						<div class="fb-room__body">
							<h4 class="fb-room__title"><?php esc_html_e( 'Double Room with Sea View', 'flexo-booking' ); ?></h4>
							<p class="fb-room__meta"><?php echo esc_html( sprintf( /* translators: %d: guests */ __( 'Up to %d guests', 'flexo-booking' ), 2 ) . ' · 24 m²' ); ?></p>
							<p class="fb-room__urgency"><?php echo esc_html( sprintf( /* translators: %d: rooms left */ __( 'Only %d left!', 'flexo-booking' ), 2 ) ); ?></p>
						</div>
						<div class="fb-room__side">
							<span class="fb-room__total-label"><?php echo esc_html( sprintf( /* translators: %d: nights */ _n( 'Total for %d night', 'Total for %d nights', 3, 'flexo-booking' ), 3 ) ); ?></span>
							<strong class="fb-room__total"><?php echo esc_html( Flexo_Booking_Money::format( 360, $flexo_currency ) ); ?></strong>
							<button type="button" class="fb-button"><?php esc_html_e( 'Select', 'flexo-booking' ); ?></button>
						</div>
						<div class="fb-plans">
							<h5 class="fb-plans__title"><?php esc_html_e( 'Choose your rate', 'flexo-booking' ); ?></h5>
							<div class="fb-plan"><div class="fb-plan__info"><strong class="fb-plan__name"><?php esc_html_e( 'Breakfast Included', 'flexo-booking' ); ?></strong><span class="fb-plan__policy">✓ <?php esc_html_e( 'Free cancellation', 'flexo-booking' ); ?></span></div><div class="fb-plan__price"><strong><?php echo esc_html( Flexo_Booking_Money::format( 420, $flexo_currency ) ); ?></strong><button type="button" class="fb-button"><?php esc_html_e( 'Choose', 'flexo-booking' ); ?></button></div></div>
						</div>
					</article>
				</div>
			</div>
			<div class="fb-summary">
				<div class="fb-summary__row fb-summary__room"><span><?php esc_html_e( 'Double Room with Sea View', 'flexo-booking' ); ?></span><span><?php echo esc_html( Flexo_Booking_Money::format( 420, $flexo_currency ) ); ?></span></div>
				<div class="fb-summary__row fb-summary__total"><span><?php esc_html_e( 'Total', 'flexo-booking' ); ?></span><span><?php echo esc_html( Flexo_Booking_Money::format( 420, $flexo_currency ) ); ?></span></div>
				<div class="fb-summary__row fb-summary__discount"><span><?php esc_html_e( 'Discount', 'flexo-booking' ); ?></span><span>−42.00</span></div>
			</div>
			<div class="fb-field"><label for="pv-email"><?php esc_html_e( 'Email', 'flexo-booking' ); ?></label><input id="pv-email" type="email" value="maria@" aria-invalid="true"><p class="fb-field__error"><?php esc_html_e( 'Enter an email address like name@example.com.', 'flexo-booking' ); ?></p></div>
			<div class="fb-notice fb-notice--error"><?php esc_html_e( 'Sorry, this room is no longer available for the selected dates.', 'flexo-booking' ); ?></div>
			<div class="fb-actions">
				<button type="button" class="fb-button fb-button--ghost"><?php esc_html_e( 'Back', 'flexo-booking' ); ?></button>
				<button type="button" class="fb-button"><?php esc_html_e( 'Confirm booking', 'flexo-booking' ); ?></button>
			</div>
		</div>
		<p class="flexo-preview-label"><?php esc_html_e( '"Check availability" panel', 'flexo-booking' ); ?></p>
		<div class="flexo-booking flexo-preview-backdrop">
			<div class="flexo-booking flexo-book-dialog flexo-preview-panel">
				<div class="flexo-book-dialog__head"><div><p class="flexo-book-dialog__eyebrow"><?php esc_html_e( 'Check availability', 'flexo-booking' ); ?></p><h2 class="flexo-book-dialog__title"><?php esc_html_e( 'Double Room with Sea View', 'flexo-booking' ); ?></h2></div><span class="flexo-book-dialog__close" aria-hidden="true">×</span></div>
				<div class="flexo-booking flexo-booking--box">
					<p class="fb-box__from"><?php echo esc_html( sprintf( /* translators: %s: price per night, e.g. €95 */ __( 'from %s', 'flexo-booking' ), Flexo_Booking_Money::format( 120, $flexo_currency, true ) ) ); ?></p>
					<div class="fb-box__answer is-available">
						<p class="fb-box__status is-ok"><?php esc_html_e( 'Available for your dates', 'flexo-booking' ); ?></p>
						<p class="fb-box__total"><span class="fb-box__total-label"><?php esc_html_e( 'Total', 'flexo-booking' ); ?></span> <strong class="fb-box__total-amount"><?php echo esc_html( Flexo_Booking_Money::format( 360, $flexo_currency ) ); ?></strong></p>
						<button type="button" class="fb-button fb-box__book"><?php esc_html_e( 'Book now', 'flexo-booking' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<script>
		window.addEventListener( 'message', function ( e ) {
			if ( e.origin !== window.location.origin || ! e.data || e.data.type !== 'flexo-appearance' ) { return; }
			document.getElementById( 'flexo-preview-custom' ).textContent = e.data.css;
		} );
		</script>
		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		exit;
	}
}
