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

	/**
	 * 1.9.0 colour settings (pages, forms, steps, confirmation, tabs).
	 */
	const PAGE_COLORS = array(
		'appearance_page_bg',
		'appearance_card_bg',
		'appearance_card_border',
		'appearance_heading_color',
		'appearance_muted',
		'appearance_button_hover',
		'appearance_secondary',
		'appearance_field_bg',
		'appearance_field_border',
		'appearance_focus',
		'appearance_label',
		'appearance_step_active',
		'appearance_step_done',
		'appearance_step_inactive',
		'appearance_summary_bg',
		'appearance_success',
		'appearance_status_confirmed',
		'appearance_status_awaiting',
		'appearance_status_request',
		'appearance_stay_bg',
		'appearance_bank_bg',
		'appearance_tab_bg',
		'appearance_tab_active_bg',
		'appearance_tab_text',
		'appearance_tab_active_text',
	);

	/**
	 * Simple colours: setting => CSS custom property (on the booking form and the page).
	 */
	const SIMPLE_VARS = array(
		'appearance_heading_color' => '--fb-heading-color',
		'appearance_muted'         => '--fb-muted',
		'appearance_secondary'     => '--fb-secondary',
		'appearance_field_bg'      => '--fb-field-bg',
		'appearance_field_border'  => '--fb-field-border',
		'appearance_focus'         => '--fb-focus',
		'appearance_label'         => '--fb-label',
		'appearance_step_active'   => '--fb-step-active',
		'appearance_step_done'     => '--fb-step-done',
		'appearance_step_inactive' => '--fb-step-inactive',
		'appearance_summary_bg'    => '--fb-summary-bg',
		'appearance_success'       => '--fb-success',
		'appearance_bank_bg'       => '--fb-bank-bg',
		'appearance_card_bg'       => '--fb-card-bg',
		'appearance_tab_bg'        => '--fb-tab-bg',
		'appearance_tab_active_bg' => '--fb-tab-active-bg',
		'appearance_tab_text'      => '--fb-tab-text',
		'appearance_tab_active_text' => '--fb-tab-active-text',
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

	/**
	 * Choices of the 1.9.0 page settings: key => array( value => array( label, CSS ) ).
	 * '' always means "as before / website".
	 */
	public static function choices( $key ) {
		$choices = array(
			'page_image_effect' => array(
				'none'  => array( __( 'Picture as it is', 'flexo-booking' ), '' ),
				'light' => array( __( 'Soften (lighter, better for dark text)', 'flexo-booking' ), 'rgba(255,255,255,.65)' ),
				'dark'  => array( __( 'Darken (better for light text)', 'flexo-booking' ), 'rgba(15,20,25,.5)' ),
			),
			'max_width'         => array(
				''       => array( __( 'Normal', 'flexo-booking' ), '' ),
				'narrow' => array( __( 'Narrow', 'flexo-booking' ), '960px' ),
				'wide'   => array( __( 'Wide', 'flexo-booking' ), '1440px' ),
				'custom' => array( __( 'Exact width', 'flexo-booking' ), '' ),
			),
			'card_corners'      => array(
				''        => array( __( 'As the booking form', 'flexo-booking' ), '' ),
				'square'  => array( __( 'Square', 'flexo-booking' ), '0px' ),
				'slight'  => array( __( 'Slightly rounded', 'flexo-booking' ), '6px' ),
				'rounded' => array( __( 'Rounded', 'flexo-booking' ), '16px' ),
			),
			'card_shadow'       => array(
				''     => array( __( 'As designed', 'flexo-booking' ), '' ),
				'none' => array( __( 'No shadow', 'flexo-booking' ), 'none' ),
				'soft' => array( __( 'Soft shadow', 'flexo-booking' ), '0 18px 40px -28px rgba(15,20,25,.35)' ),
			),
			'spacing'           => array(
				''         => array( __( 'Normal', 'flexo-booking' ), '' ),
				'compact'  => array( __( 'Compact', 'flexo-booking' ), '16px|12px|20px|32px' ),
				'spacious' => array( __( 'Spacious', 'flexo-booking' ), '36px|24px|56px|88px' ),
			),
			'control_size'      => array(
				''      => array( __( 'Normal', 'flexo-booking' ), '' ),
				'large' => array( __( 'Large', 'flexo-booking' ), '52px' ),
			),
			'tab_corners'       => array(
				''        => array( __( 'As the booking form', 'flexo-booking' ), '' ),
				'square'  => array( __( 'Square', 'flexo-booking' ), '0px' ),
				'slight'  => array( __( 'Slightly rounded', 'flexo-booking' ), '6px' ),
				'pill'    => array( __( 'Round ends', 'flexo-booking' ), '999px' ),
			),
		);
		return isset( $choices[ $key ] ) ? $choices[ $key ] : array();
	}

	public static function sanitize_choice( $key, $value ) {
		$choices = self::choices( $key );
		$value   = (string) $value;
		if ( isset( $choices[ $value ] ) ) {
			return $value;
		}
		return 'page_image_effect' === $key ? 'none' : '';
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
		foreach ( self::PAGE_COLORS as $key ) {
			$values[ $key ] = self::sanitize_color( isset( $settings[ $key ] ) ? $settings[ $key ] : '' );
		}
		foreach ( array( 'page_image_effect', 'max_width', 'card_corners', 'card_shadow', 'spacing', 'control_size', 'tab_corners' ) as $choice ) {
			$values[ 'appearance_' . $choice ] = self::sanitize_choice( $choice, isset( $settings[ 'appearance_' . $choice ] ) ? $settings[ 'appearance_' . $choice ] : '' );
		}
		$values['appearance_page_image']     = isset( $settings['appearance_page_image'] ) ? absint( $settings['appearance_page_image'] ) : 0;
		$values['appearance_max_width_px']   = isset( $settings['appearance_max_width_px'] ) ? max( 600, min( 2000, absint( $settings['appearance_max_width_px'] ) ) ) : 1200;
		$values['appearance_card_no_border'] = empty( $settings['appearance_card_no_border'] ) ? 0 : 1;
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
		// 1.9.0: forms, steps, summary, confirmation, tabs and the system pages.
		$parts = self::declarations( $v );
		$props = array_merge( $props, $parts['form'] );
		$css   = self::font_faces( array_unique( array_filter( array( $v['appearance_body_font'], $v['appearance_heading_font'] ) ) ) );
		if ( $props ) {
			$css .= '.flexo-booking{' . implode( ';', $props ) . '}';
		}
		// The Booking and Thank You pages around the form: same values, plus the page and cards.
		$page = array_merge( $props, $parts['page'] );
		if ( $page ) {
			$css .= '.flexo-system-page{' . implode( ';', $page ) . '}';
		}
		if ( $parts['body'] ) {
			$css .= 'body.flexo-system-page-body .flexo-page{' . implode( ';', $parts['body'] ) . '}';
		}
		return $css;
	}

	/**
	 * CSS declarations of the 1.9.0 settings, from values() keys.
	 *
	 * @return array { form: string[] (booking form and page), page: string[] (page only), body: string[] (page background) }
	 */
	public static function declarations( array $v ) {
		$form = array();
		$page = array();
		$body = array();
		$get  = static function ( $key ) use ( $v ) {
			return isset( $v[ $key ] ) ? $v[ $key ] : '';
		};
		foreach ( self::SIMPLE_VARS as $key => $property ) {
			if ( '' !== $get( $key ) ) {
				$form[] = $property . ':' . $get( $key );
			}
		}
		if ( '' !== $get( 'appearance_button_hover' ) ) {
			$form[] = '--fb-button-hover:' . $get( 'appearance_button_hover' );
			$form[] = '--fb-button-hover-filter:none';
		}
		if ( '' !== $get( 'appearance_stay_bg' ) ) {
			$form[] = '--fb-stay-bg:' . $get( 'appearance_stay_bg' );
			$form[] = '--fb-stay-pad:4px 16px';
		}
		// Status badges: the hotel's background, text in black or white (whichever reads better).
		foreach ( array( 'confirmed', 'awaiting', 'request' ) as $status ) {
			$bg = $get( 'appearance_status_' . $status );
			if ( '' !== $bg ) {
				$form[] = '--fb-status-' . $status . '-bg:' . $bg;
				$form[] = '--fb-status-' . $status . '-text:' . self::readable_on( $bg );
			}
		}
		$size = self::choices( 'control_size' );
		if ( '' !== $get( 'appearance_control_size' ) && isset( $size[ $get( 'appearance_control_size' ) ] ) ) {
			$form[] = '--fb-control-h:' . $size[ $get( 'appearance_control_size' ) ][1];
		}
		$tabs = self::choices( 'tab_corners' );
		if ( '' !== $get( 'appearance_tab_corners' ) && isset( $tabs[ $get( 'appearance_tab_corners' ) ] ) ) {
			$form[] = '--fb-tab-radius:' . $tabs[ $get( 'appearance_tab_corners' ) ][1];
		}

		// Page and cards.
		if ( $get( 'appearance_card_no_border' ) ) {
			$page[] = '--fb-card-border:transparent';
		} elseif ( '' !== $get( 'appearance_card_border' ) ) {
			$page[] = '--fb-card-border:' . $get( 'appearance_card_border' );
			$page[] = '--fb-card-border-w:1px';
		}
		$corners = self::choices( 'card_corners' );
		if ( '' !== $get( 'appearance_card_corners' ) && isset( $corners[ $get( 'appearance_card_corners' ) ] ) ) {
			$page[] = '--fb-card-radius:' . $corners[ $get( 'appearance_card_corners' ) ][1];
		}
		$shadow = self::choices( 'card_shadow' );
		if ( '' !== $get( 'appearance_card_shadow' ) && isset( $shadow[ $get( 'appearance_card_shadow' ) ] ) ) {
			$page[] = '--fb-card-shadow:' . $shadow[ $get( 'appearance_card_shadow' ) ][1];
		}
		$width = $get( 'appearance_max_width' );
		if ( 'custom' === $width ) {
			$page[] = '--fb-sp-max:' . max( 600, min( 2000, (int) $get( 'appearance_max_width_px' ) ) ) . 'px';
		} elseif ( '' !== $width ) {
			$widths = self::choices( 'max_width' );
			if ( isset( $widths[ $width ] ) && '' !== $widths[ $width ][1] ) {
				$page[] = '--fb-sp-max:' . $widths[ $width ][1];
			}
		}
		$spacing = self::choices( 'spacing' );
		if ( '' !== $get( 'appearance_spacing' ) && isset( $spacing[ $get( 'appearance_spacing' ) ] ) ) {
			list( $gap, $small, $top, $bottom ) = explode( '|', $spacing[ $get( 'appearance_spacing' ) ][1] );
			$page[] = '--fb-sp-gap:' . $gap;
			$page[] = '--fb-sp-gap-s:' . $small;
			$page[] = '--fb-sp-pad-top:' . $top;
			$page[] = '--fb-sp-pad-bottom:' . $bottom;
		}

		// Page background: colour and / or picture, across the whole page area.
		$image = (int) $get( 'appearance_page_image' );
		$url   = $image ? wp_get_attachment_image_url( $image, 'full' ) : '';
		if ( '' !== $get( 'appearance_page_bg' ) || $url ) {
			$body[] = 'max-width:none';
			$body[] = 'width:100%';
			$body[] = 'margin-block:0';
			if ( '' !== $get( 'appearance_page_bg' ) ) {
				$body[] = 'background-color:' . $get( 'appearance_page_bg' );
			}
			if ( $url ) {
				$effects = self::choices( 'page_image_effect' );
				$effect  = $get( 'appearance_page_image_effect' );
				$wash    = isset( $effects[ $effect ] ) ? $effects[ $effect ][1] : '';
				$layers  = '' !== $wash ? 'linear-gradient(' . $wash . ',' . $wash . '),' : '';
				$body[]  = 'background-image:' . $layers . 'url("' . esc_url_raw( $url ) . '")';
				$body[]  = 'background-size:cover';
				$body[]  = 'background-position:center';
			}
		}
		return array(
			'form' => $form,
			'page' => $page,
			'body' => $body,
		);
	}

	/**
	 * Dark or white text, whichever reads better on a colour.
	 */
	public static function readable_on( $color ) {
		return self::contrast( '#ffffff', $color ) >= self::contrast( '#1f2933', $color ) ? '#ffffff' : '#1f2933';
	}

	/**
	 * CSS of a system page's own appearance ("Use global appearance" off):
	 * wins over the global appearance on that page only.
	 *
	 * @param string     $key       booking | thank_you | contact.
	 * @param array|null $overrides The page's appearance settings (unsaved: preview), or null for the saved ones.
	 */
	public static function page_css( $key, $overrides = null ) {
		// "Appearance settings" switched off under Features: the website's look only.
		if ( ! self::enabled() ) {
			return '';
		}
		if ( null === $overrides ) {
			$page      = Flexo_Booking_System_Pages::get( $key );
			$overrides = isset( $page['appearance'] ) && is_array( $page['appearance'] ) ? $page['appearance'] : array();
		}
		$o = Flexo_Booking_System_Pages::sanitize_appearance( $overrides, $key );
		if ( ! empty( $o['use_global'] ) ) {
			return '';
		}
		$v = array(
			'appearance_page_bg'           => $o['page_bg'],
			'appearance_page_image'        => $o['page_image'],
			'appearance_page_image_effect' => $o['page_image_effect'],
			'appearance_card_bg'           => $o['card_bg'],
			'appearance_heading_color'     => $o['heading'],
			'appearance_card_corners'      => $o['corners'],
			'appearance_max_width'         => $o['max_width'],
			'appearance_max_width_px'      => $o['max_width_px'],
			'appearance_spacing'           => $o['spacing'],
			'appearance_success'           => $o['success'],
			'appearance_status_confirmed'  => $o['status_confirmed'],
			'appearance_status_awaiting'   => $o['status_awaiting'],
			'appearance_status_request'    => $o['status_request'],
		);
		$parts = self::declarations( $v );
		$props = $parts['form'];
		foreach ( array( 'primary' => '--fb-primary', 'accent' => '--fb-accent', 'text' => '--fb-text' ) as $name => $property ) {
			if ( '' !== $o[ $name ] ) {
				$props[] = $property . ':' . $o[ $name ];
			}
		}
		if ( '' !== $o['primary'] ) {
			// Text on buttons in the page's own main colour stays readable.
			$props[] = '--fb-primary-contrast:' . self::readable_on( $o['primary'] );
		}
		if ( '' !== $o['corners'] ) {
			$props[] = '--fb-radius:' . self::corners()[ $o['corners'] ][1];
		}
		$slug  = str_replace( '_', '-', $key );
		$css   = '';
		$all   = array_merge( $props, $parts['page'] );
		if ( $all ) {
			$css .= '.flexo-system-page--' . $slug . ',.flexo-system-page--' . $slug . ' .flexo-booking{' . implode( ';', $all ) . '}';
		}
		if ( $parts['body'] ) {
			$css .= 'body.flexo-system-page-body--' . $slug . ' .flexo-page{' . implode( ';', $parts['body'] ) . '}';
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
		static $done = false;
		if ( $done || self::$skip ) {
			return; // Once per page: a later copy would come after a page's own appearance.
		}
		$done = true;
		$css  = self::css();
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
	 * @return array[] Each: pair key, ratio, suggestion, label (what is hard to read).
	 */
	public static function contrast_problems( $settings = null ) {
		$v     = self::values( $settings );
		$text  = '' !== $v['appearance_text'] ? $v['appearance_text'] : '#1f2933';
		$bg    = '' !== $v['appearance_bg'] ? $v['appearance_bg'] : '#ffffff';
		$pairs = array();
		if ( '' !== $v['appearance_text'] || '' !== $v['appearance_bg'] ) {
			$pairs['text_bg'] = array( $text, $bg );
		}
		if ( '' !== $v['appearance_primary'] ) {
			$pairs['button_text'] = array( '' !== $v['appearance_button_text'] ? $v['appearance_button_text'] : '#ffffff', $v['appearance_primary'] );
		}
		if ( '' !== $v['appearance_field_bg'] ) {
			$pairs['field_text'] = array( $text, $v['appearance_field_bg'] );
		}
		if ( '' !== $v['appearance_page_bg'] && ! $v['appearance_page_image'] ) {
			$pairs['page_text'] = array( '' !== $v['appearance_heading_color'] ? $v['appearance_heading_color'] : $text, $v['appearance_page_bg'] );
		}
		if ( '' !== $v['appearance_card_bg'] ) {
			$pairs['card_text'] = array( $text, $v['appearance_card_bg'] );
		}
		if ( '' !== $v['appearance_label'] ) {
			$pairs['label_bg'] = array( $v['appearance_label'], '' !== $v['appearance_card_bg'] ? $v['appearance_card_bg'] : $bg );
		}
		foreach ( array( 'confirmed', 'awaiting', 'request' ) as $status ) {
			$color = $v[ 'appearance_status_' . $status ];
			if ( '' !== $color ) {
				$pairs[ 'status_' . $status ] = array( self::readable_on( $color ), $color );
			}
		}
		return self::problems( $pairs );
	}

	/**
	 * Readability of a page's own appearance (with the global settings it keeps).
	 */
	public static function page_contrast_problems( $key, $overrides ) {
		$o = Flexo_Booking_System_Pages::sanitize_appearance( $overrides, $key );
		if ( ! empty( $o['use_global'] ) ) {
			return array();
		}
		$v     = 'custom' === self::mode() ? self::values() : self::values( array_fill_keys( array_keys( self::COLORS ), '' ) );
		$text  = '' !== $o['text'] ? $o['text'] : ( '' !== $v['appearance_text'] ? $v['appearance_text'] : '#1f2933' );
		$card  = '' !== $o['card_bg'] ? $o['card_bg'] : ( '' !== $v['appearance_bg'] ? $v['appearance_bg'] : '#ffffff' );
		$pairs = array();
		if ( '' !== $o['text'] || '' !== $o['card_bg'] ) {
			$pairs['card_text'] = array( $text, $card );
		}
		if ( '' !== $o['page_bg'] && ! $o['page_image'] ) {
			$pairs['page_text'] = array( '' !== $o['heading'] ? $o['heading'] : $text, $o['page_bg'] );
		}
		if ( '' !== $o['primary'] ) {
			$pairs['button_text'] = array( self::readable_on( $o['primary'] ), $o['primary'] );
		}
		foreach ( array( 'confirmed', 'awaiting', 'request' ) as $status ) {
			if ( '' !== $o[ 'status_' . $status ] ) {
				$pairs[ 'status_' . $status ] = array( self::readable_on( $o[ 'status_' . $status ] ), $o[ 'status_' . $status ] );
			}
		}
		return self::problems( $pairs );
	}

	/**
	 * What each checked pair is, in the hotel's words.
	 */
	public static function pair_labels() {
		return array(
			'text_bg'          => __( 'Text on the booking form background', 'flexo-booking' ),
			'button_text'      => __( 'Button text on the main colour', 'flexo-booking' ),
			'field_text'       => __( 'Text in the fields', 'flexo-booking' ),
			'page_text'        => __( 'Page title and introduction on the page background', 'flexo-booking' ),
			'card_text'        => __( 'Text on the cards', 'flexo-booking' ),
			'label_bg'         => __( 'Field labels', 'flexo-booking' ),
			'status_confirmed' => __( 'The "confirmed" status', 'flexo-booking' ),
			'status_awaiting'  => __( 'The "awaiting payment" status', 'flexo-booking' ),
			'status_request'   => __( 'The "request received" status', 'flexo-booking' ),
		);
	}

	/**
	 * @param array $pairs key => array( foreground, background ).
	 */
	private static function problems( array $pairs ) {
		$labels = self::pair_labels();
		$issues = array();
		foreach ( $pairs as $key => $pair ) {
			$ratio = self::contrast( $pair[0], $pair[1] );
			if ( null === $ratio || $ratio >= 4.5 ) {
				continue;
			}
			if ( 0 === strpos( $key, 'status_' ) ) {
				// The text colour is chosen automatically: a lighter or darker badge helps.
				$suggestion = self::luminance( $pair[1] ) > 0.18 ? self::mix( $pair[1], '#ffffff', 0.6 ) : self::mix( $pair[1], '#000000', 0.4 );
			} else {
				$suggestion = self::readable_on( $pair[1] );
			}
			$issues[] = array(
				'pair'       => $key,
				'ratio'      => round( $ratio, 2 ),
				'suggestion' => $suggestion,
				'label'      => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
			);
		}
		return $issues;
	}

	/**
	 * A colour mixed with another (share 0–1 of the other).
	 */
	public static function mix( $color, $other, $share ) {
		$a = ltrim( self::sanitize_color( $color ), '#' );
		$b = ltrim( self::sanitize_color( $other ), '#' );
		if ( 3 === strlen( $a ) ) {
			$a = $a[0] . $a[0] . $a[1] . $a[1] . $a[2] . $a[2];
		}
		if ( 3 === strlen( $b ) ) {
			$b = $b[0] . $b[0] . $b[1] . $b[1] . $b[2] . $b[2];
		}
		if ( 6 !== strlen( $a ) || 6 !== strlen( $b ) ) {
			return '';
		}
		$out = '#';
		foreach ( array( 0, 2, 4 ) as $i ) {
			$out .= sprintf( '%02x', (int) round( hexdec( substr( $a, $i, 2 ) ) * ( 1 - $share ) + hexdec( substr( $b, $i, 2 ) ) * $share ) );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Live preview (front end, so the site's own styles apply)
	 * ------------------------------------------------------------------- */

	/**
	 * Address of the live preview.
	 *
	 * @param string $scene form (the booking form and panel), booking or thank_you (the real pages).
	 * @param string $scope global (Bookings → Appearance) or a page key (that page's own appearance).
	 */
	public static function preview_url( $scene = 'form', $scope = 'global' ) {
		return add_query_arg(
			array(
				'flexo_booking_preview' => 'form' === $scene ? 1 : $scene,
				'flexo_scope'           => $scope,
				'_wpnonce'              => wp_create_nonce( 'flexo_booking_preview' ),
			),
			home_url( '/' )
		);
	}

	/**
	 * @var bool The global appearance is not added (the preview brings its own CSS).
	 */
	private static $skip = false;

	/**
	 * Prints the real Booking or Thank You page – the website's header and
	 * footer, the page's own template and components – for the preview.
	 * The appearance CSS comes from the admin screen (unsaved values).
	 */
	private static function render_page_preview( $scene, $scope ) {
		self::$skip = true;
		$css        = 'global' === $scope
			? self::css( null, 'custom' === self::mode() )
			: self::css() . self::page_css( $scope );
		add_action(
			'wp_head',
			static function () use ( $css ) {
				echo '<style id="flexo-preview-custom">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from sanitised values.
				echo '<meta name="robots" content="noindex">' . "\n";
			},
			100
		);
		add_action(
			'wp_footer',
			static function () {
				?>
				<script>
				window.addEventListener( 'message', function ( e ) {
					if ( e.origin !== window.location.origin || ! e.data || e.data.type !== 'flexo-appearance' ) { return; }
					document.getElementById( 'flexo-preview-custom' ).textContent = e.data.css;
				} );
				document.addEventListener( 'click', function ( e ) {
					var link = e.target.closest && e.target.closest( 'a, button[type=submit]' );
					if ( link ) { e.preventDefault(); }
				} );
				</script>
				<?php
			},
			100
		);
		if ( 'thank_you' === $scene ) {
			Flexo_Booking_Confirmation::force_sample( 'transfer' );
			add_action( 'flexo_booking_thank_you_bottom', array( __CLASS__, 'preview_statuses' ) );
		} else {
			add_action( 'flexo_booking_page_bottom', array( __CLASS__, 'preview_booking_steps' ) );
		}
		Flexo_Booking_System_Pages::preview( $scene );
		exit;
	}

	/**
	 * Booking page preview: the later steps (room, summary, details, an
	 * error), with the classes the booking form uses for them.
	 */
	public static function preview_booking_steps() {
		$currency = Flexo_Booking_Money::currency();
		$uid      = 'fb-preview';
		?>
		<div class="flexo-sp-booking__form flexo-preview-steps">
			<div class="flexo-booking flexo-booking--full">
				<?php echo Flexo_Booking_Frontend::steps(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in steps(). ?>
				<script>document.currentScript.previousElementSibling.querySelectorAll( '[data-step]' ).forEach( function ( li, i ) { li.classList.toggle( 'is-done', i < 2 ); li.classList.toggle( 'is-current', i === 2 ); } );</script>
				<div class="fb-results">
					<article class="fb-room">
						<div class="fb-room__body">
							<h4 class="fb-room__title"><?php esc_html_e( 'Double Room with Sea View', 'flexo-booking' ); ?></h4>
							<p class="fb-room__meta"><?php echo esc_html( sprintf( /* translators: %d: guests */ __( 'Up to %d guests', 'flexo-booking' ), 2 ) . ' · 24 m²' ); ?></p>
							<p class="fb-room__urgency"><?php echo esc_html( sprintf( /* translators: %d: rooms left */ __( 'Only %d left!', 'flexo-booking' ), 2 ) ); ?></p>
						</div>
						<div class="fb-room__side">
							<span class="fb-room__total-label"><?php echo esc_html( sprintf( /* translators: %d: nights */ _n( 'Total for %d night', 'Total for %d nights', 3, 'flexo-booking' ), 3 ) ); ?></span>
							<strong class="fb-room__total"><?php echo esc_html( Flexo_Booking_Money::format( 360, $currency ) ); ?></strong>
							<button type="button" class="fb-button"><?php esc_html_e( 'Select', 'flexo-booking' ); ?></button>
						</div>
					</article>
				</div>
				<div class="fb-summary">
					<div class="fb-summary__row fb-summary__room"><span><?php esc_html_e( 'Double Room with Sea View', 'flexo-booking' ); ?></span><span><?php echo esc_html( Flexo_Booking_Money::format( 360, $currency ) ); ?></span></div>
					<div class="fb-summary__row fb-summary__total"><span><?php esc_html_e( 'Total', 'flexo-booking' ); ?></span><span><?php echo esc_html( Flexo_Booking_Money::format( 360, $currency ) ); ?></span></div>
				</div>
				<form class="fb-details" onsubmit="return false">
					<h4 class="fb-step-title"><?php esc_html_e( 'Your details', 'flexo-booking' ); ?></h4>
					<div class="fb-grid"><?php echo Flexo_Booking_Forms::booking_fields_html( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in booking_fields_html(). ?></div>
					<div class="fb-notice fb-notice--error"><?php esc_html_e( 'Please enter an email address like name@example.com.', 'flexo-booking' ); ?></div>
					<div class="fb-actions">
						<button type="button" class="fb-button fb-button--ghost"><?php esc_html_e( 'Back', 'flexo-booking' ); ?></button>
						<button type="button" class="fb-button"><?php esc_html_e( 'Confirm booking', 'flexo-booking' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Thank You preview: the three status badges.
	 */
	public static function preview_statuses() {
		?>
		<div class="flexo-booking flexo-preview-statuses" aria-label="<?php esc_attr_e( 'Status colours', 'flexo-booking' ); ?>">
			<span class="fb-ty-badge fb-ty-badge--paid"><?php esc_html_e( 'Confirmed', 'flexo-booking' ); ?></span>
			<span class="fb-ty-badge fb-ty-badge--transfer"><?php esc_html_e( 'Waiting for your bank transfer', 'flexo-booking' ); ?></span>
			<span class="fb-ty-badge fb-ty-badge--request"><?php esc_html_e( 'Waiting for confirmation', 'flexo-booking' ); ?></span>
		</div>
		<?php
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above.
		$scene = sanitize_key( wp_unslash( $_GET['flexo_booking_preview'] ) );
		$scope = isset( $_GET['flexo_scope'] ) ? sanitize_key( wp_unslash( $_GET['flexo_scope'] ) ) : 'global';
		// phpcs:enable
		$scope = in_array( $scope, Flexo_Booking_System_Pages::keys(), true ) ? $scope : 'global';
		if ( in_array( $scene, array( 'booking', 'thank_you' ), true ) ) {
			self::render_page_preview( $scene, $scope );
		}
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
