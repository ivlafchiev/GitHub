<?php
/**
 * System pages (1.9.0): the Booking, Thank You and Contact pages the plugin
 * provides without WordPress pages, or the hotel's own pages instead.
 *
 * Routing extends the 1.8.3 built-in booking page: a system page is served
 * only where WordPress found nothing ("Page not found"), so an existing
 * page, post or other content at the same address always wins, and no
 * rewrite rules are added or flushed. Changed addresses are remembered and
 * lead to the new one (301).
 *
 * Settings live in their own option (flexo_booking_pages). Saving merges
 * with the saved values, so one card never resets another.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_System_Pages {

	const OPTION = 'flexo_booking_pages';

	/**
	 * Old addresses remembered per page.
	 */
	const MAX_OLD_SLUGS = 10;

	/**
	 * Overlay header seen on a system page (reported by the browser of an administrator).
	 */
	const OVERLAY_OPTION = 'flexo_booking_overlay_header';

	/**
	 * Thank You sections in their default order (labels: thank_you_sections()).
	 */
	const SECTIONS = array( 'status', 'reference', 'room', 'dates', 'guests', 'rate', 'breakdown', 'total', 'payment_status', 'paid', 'remaining', 'bank', 'next', 'contact', 'address', 'calendar', 'directions', 'manage', 'back' );

	/**
	 * @var string The system page shown on this request ('' for none).
	 */
	private static $current = '';

	public static function init() {
		// Before redirect_canonical(), which would guess another page for the missing address.
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'key_referrer_meta' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'wp_sitemaps_init', array( __CLASS__, 'register_sitemap' ) );
		add_filter( 'wpseo_sitemap_page_content', array( __CLASS__, 'yoast_sitemap' ) );
		add_action( 'elementor/theme/register_conditions', array( __CLASS__, 'register_elementor_condition' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'room_page_header_css' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'header_body_class' ) );
	}

	/**
	 * The header style also applies to the plugin's own room pages.
	 */
	public static function room_page_header_css() {
		if ( is_singular( Flexo_Booking_Rooms::POST_TYPE ) && wp_style_is( Flexo_Booking_Room_Render::STYLE, 'registered' ) ) {
			$css = self::header_css();
			if ( '' !== $css ) {
				wp_add_inline_style( Flexo_Booking_Room_Render::STYLE, $css );
			}
		}
	}

	/**
	 * flexo-header-{style}; flexo-header-space-fixed when every device has
	 * a set space (the browser then doesn't measure the header).
	 */
	public static function header_body_class( $classes ) {
		$style     = self::get_header_style();
		$classes[] = 'flexo-header-' . $style;
		if ( 'space' === $style && self::fixed_space() ) {
			$classes[] = 'flexo-header-space-fixed';
		}
		return $classes;
	}

	/**
	 * @return string[] Page keys.
	 */
	public static function keys() {
		return array( 'booking', 'thank_you', 'contact' );
	}

	/**
	 * Pages this version can serve (Contact arrives with its form).
	 */
	public static function supported( $key ) {
		return 'contact' === $key ? class_exists( 'Flexo_Booking_Contact' ) : in_array( $key, self::keys(), true );
	}

	public static function label( $key ) {
		$labels = array(
			'booking'   => __( 'Booking', 'flexo-booking' ),
			'thank_you' => __( 'Thank You', 'flexo-booking' ),
			'contact'   => __( 'Contact', 'flexo-booking' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	public static function default_slug( $key ) {
		$slugs = array(
			'booking'   => 'booking',
			'thank_you' => 'thank-you',
			'contact'   => 'contact',
		);
		return $slugs[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------- */

	/**
	 * Thank You sections, in their default order.
	 *
	 * @return array key => label
	 */
	public static function thank_you_sections() {
		return array(
			'status'         => __( 'Booking status', 'flexo-booking' ),
			'reference'      => __( 'Booking reference', 'flexo-booking' ),
			'room'           => __( 'Room', 'flexo-booking' ),
			'dates'          => __( 'Stay dates', 'flexo-booking' ),
			'guests'         => __( 'Guests', 'flexo-booking' ),
			'rate'           => __( 'Rate plan', 'flexo-booking' ),
			'breakdown'      => __( 'Price breakdown', 'flexo-booking' ),
			'total'          => __( 'Total', 'flexo-booking' ),
			'payment_status' => __( 'Payment status', 'flexo-booking' ),
			'paid'           => __( 'Amount paid', 'flexo-booking' ),
			'remaining'      => __( 'Amount remaining', 'flexo-booking' ),
			'bank'           => __( 'Bank transfer information', 'flexo-booking' ),
			'next'           => __( 'What happens next', 'flexo-booking' ),
			'contact'        => __( 'Hotel contact information', 'flexo-booking' ),
			'address'        => __( 'Hotel address', 'flexo-booking' ),
			'calendar'       => __( 'Add to calendar', 'flexo-booking' ),
			'directions'     => __( 'Get directions', 'flexo-booking' ),
			'manage'         => __( 'Manage booking', 'flexo-booking' ),
			'back'           => __( 'Back to website button', 'flexo-booking' ),
		);
	}

	/**
	 * Settings of a fresh install. Texts are empty: the translated default
	 * is used until the hotel writes its own.
	 */
	public static function defaults() {
		return array(
			// After a booking: "separate" (Thank You page) or "inline" (confirmation in the form).
			'after_booking'    => 'separate',
			// Header style above Flexo pages: normal, space, band.
			'header_style'     => 'normal',
			'header_space_desktop' => '',
			'header_space_tablet'  => '',
			'header_space_mobile'  => '',
			'header_band_bg'       => '#1f2933',
			'header_band_text'     => '#ffffff',
			'header_band_image'    => 0,
			'header_band_height_desktop' => 320,
			'header_band_height_tablet'  => 260,
			'header_band_height_mobile'  => 200,
			'booking'          => array(
				'source'           => 'builtin',
				'page_id'          => 0,
				'slug'             => 'booking',
				'old_slugs'        => array(),
				'layout'           => 'classic',
				'show_title'       => 1,
				'title'            => '',
				'show_intro'       => 1,
				'intro'            => '',
				'show_steps'       => 1,
				'show_summary'     => 1,
				'show_help'        => 1,
				'help_title'       => '',
				'help_text'        => '',
				'show_reassurance' => 1,
				'reassurance'      => '',
				'show_secure'      => 1,
				'indexable'        => 1,
				'meta_description' => '',
				// Page caches may keep a copy (the page holds no private data).
				'cacheable'        => 1,
			),
			'thank_you'        => array(
				'enabled'          => 1,
				'source'           => 'builtin',
				'page_id'          => 0,
				'slug'             => 'thank-you',
				'old_slugs'        => array(),
				'layout'           => 'card',
				'order'            => self::SECTIONS,
				'hidden'           => array(),
				'heading_request'  => '',
				'heading_instant'  => '',
				'heading_paid'     => '',
				'heading_transfer' => '',
				'heading_pending'  => '',
				'intro'            => '',
				'next_title'       => '',
				'next_text'        => '',
				'help_title'       => '',
				'back_label'       => '',
				'manage_label'     => '',
				'calendar_label'   => '',
				'directions_label' => '',
				'generic_title'    => '',
				'generic_text'     => '',
			),
			'contact'          => array(
				'enabled'          => 1,
				'source'           => 'builtin',
				'page_id'          => 0,
				'slug'             => 'contact',
				'old_slugs'        => array(),
				'indexable'        => 1,
				'meta_description' => '',
				'cacheable'        => 1,
			),
		);
	}

	/**
	 * Texts the hotel can change, with their defaults.
	 *
	 * @return array page => array( key => default text )
	 */
	public static function default_texts() {
		return array(
			'booking'   => array(
				'title'            => __( 'Book your stay', 'flexo-booking' ),
				'intro'            => __( 'Choose your dates and find the perfect room for your stay.', 'flexo-booking' ),
				'help_title'       => __( 'Need help with your reservation?', 'flexo-booking' ),
				'help_text'        => __( 'Contact us and we will be happy to assist you.', 'flexo-booking' ),
				'reassurance'      => implode( "\n", self::default_reassurance() ),
				'meta_description' => '',
			),
			'thank_you' => array(
				'heading_request'  => __( 'Your booking request has been received', 'flexo-booking' ),
				'heading_instant'  => __( 'Your booking is confirmed', 'flexo-booking' ),
				'heading_paid'     => __( 'Your booking is confirmed', 'flexo-booking' ),
				'heading_transfer' => __( 'Your reservation is awaiting payment', 'flexo-booking' ),
				'heading_pending'  => __( 'Your payment is being confirmed', 'flexo-booking' ),
				'intro'            => '',
				'next_title'       => __( 'What happens next', 'flexo-booking' ),
				'next_text'        => '',
				'help_title'       => __( 'Questions about your stay?', 'flexo-booking' ),
				'back_label'       => __( 'Back to the website', 'flexo-booking' ),
				'manage_label'     => __( 'Manage your booking', 'flexo-booking' ),
				'calendar_label'   => __( 'Add to calendar', 'flexo-booking' ),
				'directions_label' => __( 'Get directions', 'flexo-booking' ),
				'generic_title'    => __( 'Thank you', 'flexo-booking' ),
				'generic_text'     => __( 'If you have just booked with us, the details of your booking are on their way to your email. The link in that email shows your booking at any time.', 'flexo-booking' ),
			),
		);
	}

	/**
	 * "Why book here" lines of the booking page.
	 */
	private static function default_reassurance() {
		$lines = array( __( 'Book directly with the hotel', 'flexo-booking' ) );
		$lines[] = 'instant' === Flexo_Booking_Features::booking_mode()
			? __( 'Instant confirmation by email', 'flexo-booking' )
			/* translators: %s: e.g. "within 24 hours" */
			: sprintf( __( 'We confirm your request by email %s', 'flexo-booking' ), Flexo_Booking_Guest::reply_time_text() );
		$lines[] = __( 'Your details are only shared with the hotel', 'flexo-booking' );
		return $lines;
	}

	/**
	 * Settings of a page (or the whole settings with $key = '').
	 */
	public static function all() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
		foreach ( self::keys() as $key ) {
			$out[ $key ] = array_merge( $defaults[ $key ], isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array() );
		}
		return $out;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ];
	}

	/**
	 * A text of a page: the hotel's (translated by Polylang / WPML when
	 * there is a translation), else the default in the visitor's language.
	 */
	public static function text( $key, $name, $locale = '' ) {
		$page  = self::get( $key );
		$value = isset( $page[ $name ] ) ? trim( (string) $page[ $name ] ) : '';
		if ( '' !== $value ) {
			return Flexo_Booking_I18n::translate( $value, 'page_' . $key . '_' . $name, $locale );
		}
		$defaults = self::default_texts();
		return isset( $defaults[ $key ][ $name ] ) ? $defaults[ $key ][ $name ] : '';
	}

	/**
	 * Texts the hotel changed, for Polylang / WPML string translation.
	 */
	public static function translatable_strings() {
		$strings = array();
		$all     = self::all();
		foreach ( self::default_texts() as $key => $texts ) {
			foreach ( array_keys( $texts ) as $name ) {
				$value = isset( $all[ $key ][ $name ] ) ? trim( (string) $all[ $key ][ $name ] ) : '';
				if ( '' !== $value ) {
					$strings[ 'page_' . $key . '_' . $name ] = $value;
				}
			}
		}
		return $strings;
	}

	/**
	 * Saves part of the settings: given keys change, everything else keeps
	 * its saved value.
	 */
	public static function update( array $changes ) {
		update_option( self::OPTION, self::sanitize( $changes ) );
	}

	/**
	 * Sanitises submitted settings and merges them with the saved ones.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = $current;

		if ( isset( $input['after_booking'] ) ) {
			$clean['after_booking'] = 'inline' === $input['after_booking'] ? 'inline' : 'separate';
		}
		if ( isset( $input['header_style'] ) ) {
			$clean['header_style'] = in_array( $input['header_style'], array( 'normal', 'space', 'band' ), true ) ? $input['header_style'] : 'normal';
		}
		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			$k = 'header_space_' . $device;
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = '' === trim( (string) $input[ $k ] ) ? '' : min( 400, absint( $input[ $k ] ) );
			}
			$k = 'header_band_height_' . $device;
			if ( isset( $input[ $k ] ) ) {
				$clean[ $k ] = max( 80, min( 800, absint( $input[ $k ] ) ) );
			}
		}
		foreach ( array( 'header_band_bg', 'header_band_text' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$color       = sanitize_hex_color( (string) $input[ $k ] );
				$clean[ $k ] = $color ? $color : $defaults[ $k ];
			}
		}
		if ( isset( $input['header_band_image'] ) ) {
			$image                      = absint( $input['header_band_image'] );
			$clean['header_band_image'] = $image && wp_attachment_is_image( $image ) ? $image : 0;
		}

		$taken = array();
		foreach ( self::keys() as $key ) {
			$page = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$clean[ $key ] = self::sanitize_page( $key, $page, $current[ $key ] );
		}

		// A slug in use by one page is no longer an old address of another.
		foreach ( self::keys() as $key ) {
			$taken[] = $clean[ $key ]['slug'];
		}
		foreach ( self::keys() as $key ) {
			$clean[ $key ]['old_slugs'] = array_values( array_diff( $clean[ $key ]['old_slugs'], $taken ) );
		}
		return $clean;
	}

	private static function sanitize_page( $key, array $input, array $current ) {
		$defaults = self::defaults()[ $key ];
		$clean    = array_merge( $defaults, $current );

		if ( isset( $input['source'] ) ) {
			$clean['source'] = 'page' === $input['source'] ? 'page' : 'builtin';
		}
		if ( isset( $input['page_id'] ) ) {
			$id               = absint( $input['page_id'] );
			$clean['page_id'] = $id && 'page' === get_post_type( $id ) ? $id : 0;
		}
		if ( isset( $input['enabled'] ) && 'booking' !== $key ) {
			$clean['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		}
		if ( isset( $input['slug'] ) ) {
			$slug = self::sanitize_slug( $input['slug'] );
			if ( '' !== $slug && $slug !== $clean['slug'] ) {
				// The old address keeps working and leads to the new one.
				array_unshift( $clean['old_slugs'], $clean['slug'] );
				$clean['slug'] = $slug;
			}
		}
		if ( isset( $input['old_slugs'] ) && is_array( $input['old_slugs'] ) ) {
			$clean['old_slugs'] = $input['old_slugs'];
		}
		$old                = array_filter( array_map( array( __CLASS__, 'sanitize_slug' ), (array) $clean['old_slugs'] ) );
		$clean['old_slugs'] = array_slice( array_values( array_unique( array_diff( $old, array( $clean['slug'] ) ) ) ), 0, self::MAX_OLD_SLUGS );

		if ( isset( $input['layout'] ) ) {
			$layouts         = 'thank_you' === $key ? array_keys( self::thank_you_layouts() ) : array_keys( self::booking_layouts() );
			$clean['layout'] = in_array( $input['layout'], $layouts, true ) ? $input['layout'] : $defaults['layout'];
		}
		foreach ( $defaults as $name => $default ) {
			if ( ! isset( $input[ $name ] ) || in_array( $name, array( 'source', 'page_id', 'enabled', 'slug', 'old_slugs', 'layout', 'order', 'hidden' ), true ) ) {
				continue;
			}
			if ( is_int( $default ) ) {
				$clean[ $name ] = empty( $input[ $name ] ) ? 0 : 1;
			} elseif ( in_array( $name, array( 'intro', 'help_text', 'next_text', 'generic_text' ), true ) ) {
				// Simple formatting (bold, links) only; never scripts or PHP.
				$clean[ $name ] = trim( wp_kses_post( (string) $input[ $name ] ) );
			} elseif ( in_array( $name, array( 'reassurance', 'meta_description' ), true ) ) {
				$clean[ $name ] = trim( sanitize_textarea_field( (string) $input[ $name ] ) );
			} else {
				$clean[ $name ] = trim( sanitize_text_field( (string) $input[ $name ] ) );
			}
			// The default text itself is not stored, so it stays translated.
			$texts = self::default_texts();
			if ( isset( $texts[ $key ][ $name ] ) && '' !== $clean[ $name ] && $clean[ $name ] === $texts[ $key ][ $name ] ) {
				$clean[ $name ] = '';
			}
		}
		if ( 'thank_you' === $key ) {
			$sections = self::SECTIONS;
			if ( isset( $input['order'] ) ) {
				$order          = is_array( $input['order'] ) ? $input['order'] : explode( ',', (string) $input['order'] );
				$order          = array_values( array_intersect( array_unique( array_map( 'sanitize_key', $order ) ), $sections ) );
				$clean['order'] = array_merge( $order, array_values( array_diff( $sections, $order ) ) );
			}
			if ( isset( $input['shown'] ) ) {
				// The admin form sends the ticked sections.
				$shown           = array_map( 'sanitize_key', (array) $input['shown'] );
				$clean['hidden'] = array_values( array_diff( $sections, $shown ) );
			} elseif ( isset( $input['hidden'] ) ) {
				$clean['hidden'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input['hidden'] ), $sections ) );
			}
			$clean['order']  = array_values( array_intersect( (array) $clean['order'], $sections ) );
			$clean['order']  = array_merge( $clean['order'], array_values( array_diff( $sections, $clean['order'] ) ) );
			$clean['hidden'] = array_values( array_intersect( (array) $clean['hidden'], $sections ) );
		}
		return array_intersect_key( $clean, $defaults );
	}

	/**
	 * Addresses: lower-case Latin letters, numbers and dashes, one level,
	 * not a WordPress address.
	 */
	public static function sanitize_slug( $slug ) {
		$slug = sanitize_title( remove_accents( (string) $slug ) );
		$slug = trim( preg_replace( '/[^a-z0-9-]+/', '', $slug ), '-' );
		if ( '' === $slug || in_array( $slug, self::reserved_slugs(), true ) ) {
			return '';
		}
		return substr( $slug, 0, 60 );
	}

	public static function reserved_slugs() {
		return array_merge( Flexo_Booking_Room_Pages::reserved_bases(), array( 'robots-txt', 'sitemap', 'wp-sitemap', 'xmlrpc', 'wp-login', 'login', 'admin', 'index' ) );
	}

	public static function booking_layouts() {
		return array(
			'classic' => array( __( 'Classic', 'flexo-booking' ), __( 'The booking steps with the summary of the stay next to them; one column on phones.', 'flexo-booking' ) ),
			'wide'    => array( __( 'Wide', 'flexo-booking' ), __( 'A wider, centred booking area with the summary under the steps.', 'flexo-booking' ) ),
		);
	}

	public static function thank_you_layouts() {
		return array(
			'card'    => array( __( 'Card', 'flexo-booking' ), __( 'The confirmation centred at the top, the stay on a card below.', 'flexo-booking' ) ),
			'summary' => array( __( 'Summary', 'flexo-booking' ), __( 'The confirmation, a detailed summary of the stay, then actions and contact.', 'flexo-booking' ) ),
			'split'   => array( __( 'Split', 'flexo-booking' ), __( 'Confirmation and next steps on one side, stay and payment on the other.', 'flexo-booking' ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Where each page is
	 * ------------------------------------------------------------------- */

	public static function slug( $key ) {
		$page = self::get( $key );
		return '' !== $page['slug'] ? $page['slug'] : self::default_slug( $key );
	}

	/**
	 * The hotel's own page for a system page, if it chose one and it is
	 * published (the legacy path settings count too).
	 *
	 * @return string URL or ''.
	 */
	public static function own_page_url( $key, $locale = '' ) {
		$page = self::get( $key );
		if ( 'page' !== $page['source'] ) {
			return '';
		}
		$id = (int) $page['page_id'];
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return Flexo_Booking_I18n::page_url( get_permalink( $id ), $locale );
		}
		$legacy = array(
			'booking'   => 'booking_page',
			'thank_you' => 'thank_you_url',
		);
		if ( ! $id && isset( $legacy[ $key ] ) ) {
			$url = Flexo_Booking_Settings::site_url_setting( $legacy[ $key ] );
			if ( '' === $url && 'booking' === $key ) {
				$url = Flexo_Booking_Guest::detect_booking_page();
			}
			return '' === $url ? '' : Flexo_Booking_I18n::page_url( $url, $locale );
		}
		return '';
	}

	/**
	 * Why the hotel's own page can't be used ('' when it can, or none was chosen).
	 *
	 * @return string missing | unpublished | ''
	 */
	public static function own_page_problem( $key ) {
		$page = self::get( $key );
		if ( 'page' !== $page['source'] ) {
			return '';
		}
		$id = (int) $page['page_id'];
		if ( $id ) {
			$status = get_post_status( $id );
			return false === $status || 'trash' === $status ? 'missing' : ( 'publish' === $status ? '' : 'unpublished' );
		}
		return '' === self::own_page_url( $key ) ? 'missing' : '';
	}

	/**
	 * Whether the built-in page is switched on (Booking always is).
	 */
	public static function enabled( $key ) {
		$page = self::get( $key );
		return 'booking' === $key || ! empty( $page['enabled'] );
	}

	/**
	 * Whether the built-in page is what guests get: chosen (or the own page
	 * is missing, for Booking), switched on and supported.
	 */
	public static function uses_builtin( $key ) {
		if ( ! self::supported( $key ) || ! self::enabled( $key ) ) {
			return false;
		}
		$page = self::get( $key );
		if ( 'page' === $page['source'] ) {
			// Guests never end on "Page not found": Booking falls back to the built-in page.
			return 'booking' === $key && '' === self::own_page_url( $key );
		}
		return true;
	}

	/**
	 * Whether the built-in page is reachable at its address: in use and no
	 * other content there (which always wins).
	 */
	public static function route_active( $key ) {
		return self::uses_builtin( $key ) && null === self::collision( $key );
	}

	/**
	 * The address guests get for a system page: the own page, else the
	 * built-in one ('' when neither is available).
	 */
	public static function url( $key, $locale = '' ) {
		$own = self::own_page_url( $key, $locale );
		if ( '' !== $own ) {
			return $own;
		}
		return self::uses_builtin( $key ) ? self::builtin_url( $key, $locale ) : '';
	}

	/**
	 * Address of the built-in page, in the given language's home when the
	 * site is multilingual (/bg/booking/).
	 */
	public static function builtin_url( $key, $locale = '' ) {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'flexo_page', $key, self::home( $locale ) );
		}
		return trailingslashit( self::home( $locale ) ) . user_trailingslashit( self::slug( $key ) );
	}

	private static function home( $locale ) {
		$slug = Flexo_Booking_I18n::slug_for( $locale ? $locale : Flexo_Booking_I18n::current() );
		if ( $slug && function_exists( 'pll_home_url' ) ) {
			$url = pll_home_url( $slug );
			if ( $url ) {
				return $url;
			}
		}
		if ( $slug && has_filter( 'wpml_permalink' ) ) {
			return apply_filters( 'wpml_permalink', home_url( '/' ), $slug );
		}
		return home_url( '/' );
	}

	/**
	 * What already uses the address of a built-in page. Existing content
	 * always wins, so a built-in page stays inactive at an address in use.
	 *
	 * @return array|null { type: page|content|post_type|rooms|system, label, url, id }
	 */
	public static function collision( $key, $slug = null ) {
		$slug = null === $slug ? self::slug( $key ) : (string) $slug;
		if ( '' === $slug ) {
			return null;
		}
		foreach ( self::keys() as $other ) {
			if ( $other !== $key && self::supported( $other ) && self::slug( $other ) === $slug ) {
				return array(
					'type'  => 'system',
					'label' => self::label( $other ),
					'url'   => '',
					'id'    => 0,
				);
			}
		}
		if ( Flexo_Booking_Room_Pages::base() === $slug ) {
			return array(
				'type'  => 'rooms',
				'label' => __( 'Room pages', 'flexo-booking' ),
				'url'   => '',
				'id'    => 0,
			);
		}
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $page && 'publish' === $page->post_status ) {
			return array(
				'type'  => 'page',
				'label' => get_the_title( $page ),
				'url'   => get_permalink( $page ),
				'id'    => (int) $page->ID,
			);
		}
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			$id = url_to_postid( home_url( user_trailingslashit( $slug ) ) );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				return array(
					'type'  => 'page' === get_post_type( $id ) ? 'page' : 'content',
					'label' => get_the_title( $id ),
					'url'   => get_permalink( $id ),
					'id'    => (int) $id,
				);
			}
		}
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( Flexo_Booking_Rooms::POST_TYPE === $type->name ) {
				continue;
			}
			$base = is_array( $type->rewrite ) && ! empty( $type->rewrite['slug'] ) ? trim( $type->rewrite['slug'], '/' ) : '';
			if ( $type->has_archive && is_string( $type->has_archive ) ) {
				$base = trim( $type->has_archive, '/' );
			}
			if ( $base === $slug ) {
				return array(
					'type'  => 'post_type',
					'label' => $type->labels->name,
					'url'   => (string) get_post_type_archive_link( $type->name ),
					'id'    => 0,
				);
			}
		}
		return null;
	}

	/**
	 * The system page shown on this request ('' for none).
	 */
	public static function current() {
		return self::$current;
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------- */

	/**
	 * The requested path relative to the site's home, and a language
	 * prefix in front of it (/en/booking/ → array( 'booking', 'en/' )).
	 */
	public static function request_path() {
		$home    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$path    = trim( rawurldecode( $request ), '/' );
		if ( '' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		$prefix = '';
		if ( preg_match( '#^([a-z]{2}(?:[-_][a-z]{2})?)/(.+)$#i', $path, $m ) ) {
			$prefix = strtolower( $m[1] ) . '/';
			$path   = $m[2];
		}
		return array( strtolower( $path ), $prefix );
	}

	public static function route() {
		Flexo_Booking_Confirmation::maybe_protect_key_request();

		// Plain permalinks: ?flexo_page=booking.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$asked = isset( $_GET['flexo_page'] ) ? sanitize_key( wp_unslash( $_GET['flexo_page'] ) ) : '';
		if ( '' !== $asked && in_array( $asked, self::keys(), true ) && ( is_404() || is_home() || is_front_page() ) && self::route_active( $asked ) ) {
			self::serve( $asked );
			return;
		}

		if ( ! is_404() ) {
			// The hotel's own Thank You page: take over the booking from the link.
			Flexo_Booking_Confirmation::maybe_arrive_on_own_page();
			return;
		}

		list( $path, $prefix ) = self::request_path();
		if ( '' === $path ) {
			return;
		}
		foreach ( self::keys() as $key ) {
			if ( self::slug( $key ) === $path && self::route_active( $key ) ) {
				self::serve( $key );
				return;
			}
		}
		foreach ( self::keys() as $key ) {
			$page = self::get( $key );
			$to   = '';
			if ( self::slug( $key ) === $path || in_array( $path, $page['old_slugs'], true ) ) {
				// The own page elsewhere, or a changed address: lead there, keeping room and dates.
				$to = self::url( $key );
				if ( '' !== $to && '' !== $prefix && '' === self::own_page_url( $key ) ) {
					$to = home_url( '/' . $prefix . user_trailingslashit( self::slug( $key ) ) );
				}
			}
			if ( '' === $to ) {
				continue;
			}
			$to_path = trim( (string) wp_parse_url( $to, PHP_URL_PATH ), '/' );
			$here    = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH ), '/' );
			if ( $to_path === $here ) {
				continue; // Never a loop.
			}
			$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed on to wp_safe_redirect() unchanged.
			$to    = '' !== $query ? $to . ( false === strpos( $to, '?' ) ? '?' : '&' ) . $query : $to;
			// The own page may move again: only a changed built-in address is permanent.
			wp_safe_redirect( $to, self::slug( $key ) === $path ? 302 : 301 );
			exit;
		}
	}

	/**
	 * Shows a built-in page on this request.
	 */
	public static function serve( $key ) {
		if ( 'thank_you' === $key ) {
			// Arriving from the booking form: keep the booking in a cookie, then a clean address.
			Flexo_Booking_Confirmation::maybe_arrive();
		}
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		self::$current = $key;

		if ( 'thank_you' === $key || ! self::cacheable( $key ) ) {
			Flexo_Booking_Page_Cache::protect( 'thank_you' === $key ? 'thank_you' : $key );
		} elseif ( ! headers_sent() && ! Flexo_Booking_Page_Cache::is_protected() ) {
			// WordPress sent "don't cache" for the missing page; this page may be cached.
			foreach ( array( 'Cache-Control', 'Expires', 'Pragma' ) as $name ) {
				header_remove( $name );
			}
		}
		if ( ! headers_sent() ) {
			// No private data in addresses leaves this page; Thank You sends no referrer at all.
			header( 'Referrer-Policy: ' . ( 'thank_you' === $key ? 'no-referrer' : 'same-origin' ) );
			if ( 'thank_you' === $key ) {
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
		}

		add_filter( 'template_include', array( __CLASS__, 'template' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		Flexo_Booking_Page_SEO::hook( $key );
		do_action( 'flexo_booking_system_page', $key );
	}

	/**
	 * Booking and Contact may be kept by page caches (decision 1 of the 1.9.0 plan).
	 */
	public static function cacheable( $key ) {
		$page = self::get( $key );
		return 'thank_you' !== $key && ! empty( $page['cacheable'] );
	}

	public static function template() {
		$key   = self::$current;
		$names = array( 'system-page-' . str_replace( '_', '-', $key ) . '.php' );
		if ( 'booking' === $key ) {
			// Theme copies of the 1.8.3 template keep working.
			array_unshift( $names, 'booking-page.php' );
		}
		foreach ( $names as $name ) {
			$theme = locate_template( 'flexo-booking/' . $name );
			if ( $theme ) {
				return $theme;
			}
		}
		return FLEXO_BOOKING_DIR . 'templates/system-page-' . str_replace( '_', '-', $key ) . '.php';
	}

	public static function enqueue() {
		wp_enqueue_style( 'flexo-booking' );
		Flexo_Booking_Appearance::enqueue();
		wp_enqueue_script( 'flexo-booking' );
		Flexo_Booking_Frontend::localize();
		$css = self::header_css();
		if ( '' !== $css ) {
			wp_add_inline_style( 'flexo-booking', $css );
		}
	}

	public static function body_class( $classes ) {
		$key     = self::$current;
		$classes = array_diff( $classes, array( 'error404' ) );
		$classes[] = 'flexo-system-page-body';
		$classes[] = 'flexo-system-page-body--' . str_replace( '_', '-', $key );
		if ( 'booking' === $key ) {
			$classes[] = 'flexo-booking-page';
		}
		return $classes;
	}

	/* ---------------------------------------------------------------------
	 * Page content
	 * ------------------------------------------------------------------- */

	/**
	 * The heading shown on a page (and in its title band).
	 */
	public static function page_title( $key ) {
		if ( 'thank_you' === $key ) {
			return Flexo_Booking_Confirmation::heading();
		}
		return self::text( $key, 'title' );
	}

	/**
	 * Everything the built-in Booking page shows (templates/system-page-booking.php).
	 */
	public static function booking_view() {
		$page   = self::get( 'booking' );
		$secure = '';
		if ( ! empty( $page['show_secure'] ) && Flexo_Booking_Payments::collects_now() && in_array( 'stripe', Flexo_Booking_Payments::methods(), true ) ) {
			$secure = __( 'Card payments are made on Stripe\'s secure payment page. Your card details never reach us.', 'flexo-booking' );
		}
		$lines = array();
		if ( ! empty( $page['show_reassurance'] ) ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", self::text( 'booking', 'reassurance' ) ) ) ) );
		}
		return array(
			'layout'      => $page['layout'],
			// With a title band the page title is in the band.
			'title'       => ! empty( $page['show_title'] ) && 'band' !== self::get_header_style() ? self::text( 'booking', 'title' ) : '',
			'intro'       => ! empty( $page['show_intro'] ) ? wp_kses_post( self::text( 'booking', 'intro' ) ) : '',
			'form'        => Flexo_Booking_Frontend::render( array() ),
			'steps'       => ! empty( $page['show_steps'] ),
			'summary'     => ! empty( $page['show_summary'] ),
			'reassurance' => $lines,
			'secure'      => $secure,
			'help'        => ! empty( $page['show_help'] ) ? array(
				'title'   => self::text( 'booking', 'help_title' ),
				'text'    => wp_kses_post( self::text( 'booking', 'help_text' ) ),
				'contact' => Flexo_Booking_Guest::contact(),
			) : null,
		);
	}

	/**
	 * Wraps a page's content: the page area, with the title band when the
	 * header style asks for it.
	 *
	 * @param string $key   Page key.
	 * @param string $inner Content HTML (escaped by the caller).
	 */
	public static function wrap( $key, $inner, array $classes = array() ) {
		$style   = self::get_header_style();
		$classes = array_merge( array( 'flexo-system-page', 'flexo-system-page--' . str_replace( '_', '-', $key ) ), $classes );
		$band    = '';
		if ( 'band' === $style ) {
			$classes[] = 'flexo-system-page--band';
			$band      = self::band( self::page_title( $key ) );
		} elseif ( 'space' === $style ) {
			$classes[] = 'flexo-system-page--space';
		}
		return $band . '<div class="' . esc_attr( implode( ' ', $classes ) ) . '"' . self::auto_space_attr() . '>' . $inner . '</div>';
	}

	/**
	 * data-fb-clear-header: the page is moved below a header lying over it
	 * (measured in the browser) – the 1.8.3 behaviour, kept for "Normal"
	 * and for "Space" without a fixed value.
	 */
	public static function auto_space_attr() {
		$style = self::get_header_style();
		if ( 'band' === $style ) {
			return ' data-fb-band-page';
		}
		if ( 'space' === $style && self::fixed_space() ) {
			return '';
		}
		return ' data-fb-clear-header';
	}

	/**
	 * Whether all devices have a fixed top space.
	 */
	private static function fixed_space() {
		$all = self::all();
		return '' !== (string) $all['header_space_desktop'] && '' !== (string) $all['header_space_tablet'] && '' !== (string) $all['header_space_mobile'];
	}

	/**
	 * The title band behind an overlay header.
	 */
	public static function band( $title ) {
		$all   = self::all();
		$image = $all['header_band_image'] ? wp_get_attachment_image_url( (int) $all['header_band_image'], 'full' ) : '';
		$style = $image ? ' style="background-image:url(' . esc_url( $image ) . ')"' : '';
		return '<div class="flexo-title-band' . ( $image ? ' flexo-title-band--image' : '' ) . '"' . $style . ' data-fb-title-band><div class="flexo-title-band__inner"><h1 class="flexo-title-band__title">' . esc_html( $title ) . '</h1></div></div>';
	}

	public static function get_header_style() {
		$all = self::all();
		return $all['header_style'];
	}

	/**
	 * CSS of the header style: fixed top spaces, band colours and heights.
	 */
	public static function header_css() {
		$all   = self::all();
		$style = $all['header_style'];
		$css   = '';
		if ( 'space' === $style ) {
			$rules = array(
				'mobile'  => '',
				'tablet'  => '@media (min-width:768px)',
				'desktop' => '@media (min-width:1025px)',
			);
			foreach ( $rules as $device => $media ) {
				$value = $all[ 'header_space_' . $device ];
				if ( '' === (string) $value ) {
					continue;
				}
				$rule = '.flexo-system-page--space,.flexo-room-page-wrap{padding-top:' . absint( $value ) . 'px}';
				$css .= '' === $media ? $rule : $media . '{' . $rule . '}';
			}
		}
		if ( 'band' === $style ) {
			$css .= '.flexo-title-band{--fb-band-bg:' . $all['header_band_bg'] . ';--fb-band-text:' . $all['header_band_text'] . ';--fb-band-h:' . absint( $all['header_band_height_mobile'] ) . 'px}';
			$css .= '@media (min-width:768px){.flexo-title-band{--fb-band-h:' . absint( $all['header_band_height_tablet'] ) . 'px}}';
			$css .= '@media (min-width:1025px){.flexo-title-band{--fb-band-h:' . absint( $all['header_band_height_desktop'] ) . 'px}}';
		}
		return $css;
	}

	/* ---------------------------------------------------------------------
	 * Private links, overlay header report, sitemaps, Elementor Pro
	 * ------------------------------------------------------------------- */

	/**
	 * Pages opened with a guest key in the address (confirmation, manage
	 * booking, card payment return) never pass it on to other sites.
	 */
	public static function key_referrer_meta() {
		if ( Flexo_Booking_Confirmation::has_key_in_request() ) {
			echo '<meta name="referrer" content="no-referrer">' . "\n";
		}
	}

	public static function register_routes() {
		register_rest_route(
			Flexo_Booking_Rest::NAMESPACE_V1,
			'/overlay-header',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_overlay' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'overlay' => array( 'type' => 'boolean' ),
					'path'    => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * The browser of an administrator reports whether the header lies over
	 * a Flexo page (shown as a hint in Settings → Pages and Health).
	 */
	public static function rest_overlay( WP_REST_Request $request ) {
		update_option(
			self::OVERLAY_OPTION,
			array(
				'overlay' => (bool) $request['overlay'],
				'path'    => substr( sanitize_text_field( (string) $request['path'] ), 0, 200 ),
				'time'    => time(),
			),
			false
		);
		return rest_ensure_response( array( 'saved' => true ) );
	}

	/**
	 * @return array|null The last report, when it found an overlay header.
	 */
	public static function overlay_detected() {
		$seen = get_option( self::OVERLAY_OPTION );
		return is_array( $seen ) && ! empty( $seen['overlay'] ) ? $seen : null;
	}

	/**
	 * Built-in pages search engines may list (Booking, Contact).
	 *
	 * @return string[] URLs
	 */
	public static function indexable_urls() {
		$urls = array();
		foreach ( array( 'booking', 'contact' ) as $key ) {
			$page = self::get( $key );
			if ( ! empty( $page['indexable'] ) && self::route_active( $key ) ) {
				$urls[] = self::builtin_url( $key, Flexo_Booking_I18n::site_locale() );
			}
		}
		return $urls;
	}

	/**
	 * The WordPress sitemap lists the built-in Booking and Contact pages.
	 */
	public static function register_sitemap( $sitemaps ) {
		if ( is_object( $sitemaps ) && isset( $sitemaps->registry ) && class_exists( 'WP_Sitemaps_Provider' ) ) {
			require_once FLEXO_BOOKING_DIR . 'includes/class-sitemap-provider.php';
			$sitemaps->registry->add_provider( 'flexobooking', new Flexo_Booking_Sitemap_Provider() );
		}
		return $sitemaps;
	}

	/**
	 * Yoast SEO: the pages are added to its page sitemap.
	 */
	public static function yoast_sitemap( $content ) {
		foreach ( self::indexable_urls() as $url ) {
			$content .= '<url><loc>' . esc_url( $url ) . '</loc></url>' . "\n";
		}
		return $content;
	}

	/**
	 * Elementor Pro Theme Builder: "Flexo Booking pages" under General, so
	 * headers and footers can target them (they are not WordPress pages).
	 */
	public static function register_elementor_condition( $manager ) {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base' ) || ! is_object( $manager ) || ! method_exists( $manager, 'get_condition' ) ) {
			return;
		}
		require_once FLEXO_BOOKING_DIR . 'includes/elementor/class-system-page-condition.php';
		$general = $manager->get_condition( 'general' );
		if ( $general && method_exists( $general, 'register_sub_condition' ) ) {
			$general->register_sub_condition( new Flexo_Booking_System_Page_Condition() );
		}
	}
}
