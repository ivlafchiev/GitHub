<?php
/**
 * Room page design: an Elementor page or template chosen in Settings →
 * Room pages that every room page uses.
 *
 * The chosen design is printed for each room with that room as the
 * current post – exactly how Elementor Pro prints a Theme Builder single
 * template – so the "Flexo Booking: room" tags and room widgets (and core
 * post tags such as Post Title and Featured Image) show the data of the
 * room being viewed. The theme's header and footer stay around it.
 *
 * Without a chosen design nothing changes: an Elementor Pro single template
 * for rooms is used when one applies, otherwise the plugin's own room page.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Design {

	/**
	 * Elementor template types that describe a whole page or part of one.
	 * Headers, footers, popups, archives and loop items are left out.
	 */
	const TEMPLATE_TYPES = array( 'page', 'section', 'container', 'single', 'single-post', 'single-page' );

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		// Before Flexo_Booking_Elementor::sample_room().
		add_filter( 'flexo_booking_current_room', array( __CLASS__, 'sample_room' ), 5 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'seo_canonical' ) );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'seo_canonical' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/**
	 * The chosen design (in the current language), or 0.
	 */
	public static function id() {
		$id = absint( Flexo_Booking_Settings::get( 'room_design' ) );
		if ( ! $id || ! self::is_valid( $id ) ) {
			return 0;
		}
		if ( function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $id );
			$id         = $translated && self::is_valid( $translated ) ? $translated : $id;
		} elseif ( has_filter( 'wpml_object_id' ) ) {
			$translated = (int) apply_filters( 'wpml_object_id', $id, get_post_type( $id ), true );
			$id         = $translated && self::is_valid( $translated ) ? $translated : $id;
		}
		return $id;
	}

	/**
	 * A page or Elementor template built with Elementor (any status but the
	 * bin, so the design page can stay a draft).
	 */
	public static function is_valid( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'trash' === $post->post_status || ! did_action( 'elementor/loaded' ) ) {
			return false;
		}
		if ( 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return false;
		}
		if ( 'page' === $post->post_type ) {
			return true;
		}
		return 'elementor_library' === $post->post_type && in_array( (string) get_post_meta( $post->ID, '_elementor_template_type', true ), self::TEMPLATE_TYPES, true );
	}

	/**
	 * Whether this request is a room page shown with the chosen design.
	 */
	public static function applies() {
		return is_singular( Flexo_Booking_Rooms::POST_TYPE ) && ! is_404() && self::id() > 0;
	}

	/**
	 * The page template for a room page with a design (see Flexo_Booking_Room_Pages::template()).
	 */
	public static function template_file() {
		$theme = locate_template( 'flexo-booking/room-design.php' );
		return $theme ? $theme : FLEXO_BOOKING_DIR . 'templates/room-design.php';
	}

	/**
	 * Whether the design page hides the theme's header and footer
	 * (Elementor Canvas).
	 */
	public static function is_canvas() {
		$id = self::id();
		return $id && 'elementor_canvas' === get_post_meta( $id, '_wp_page_template', true );
	}

	/**
	 * The design with the current room's data. Call inside the loop.
	 */
	public static function content() {
		$id = self::id();
		if ( ! $id || ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}
		return (string) \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $id );
	}

	/**
	 * The design's styles in the page head (as Elementor Pro does for its
	 * templates), so nothing jumps while the page loads. Dynamic styles,
	 * e.g. a background from the room's photo, are worked out for the room.
	 */
	public static function enqueue() {
		if ( ! self::applies() || ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			return;
		}
		\Elementor\Plugin::instance()->frontend->enqueue_styles();
		\Elementor\Core\Files\CSS\Post::create( self::id() )->enqueue();
	}

	/**
	 * Elementor's page classes for the design, so its page settings apply
	 * and themes such as Hello give it the full width.
	 */
	public static function body_class( $classes ) {
		if ( self::applies() ) {
			$classes[] = 'elementor-page';
			$classes[] = 'elementor-page-' . self::id();
			$classes[] = 'flexo-room-design';
		}
		return $classes;
	}

	/**
	 * The design page itself (opened on its own, or edited in Elementor)
	 * shows a real room: the room with the same address, else the first.
	 */
	public static function sample_room( $room_id ) {
		if ( $room_id ) {
			return $room_id;
		}
		$design = self::id();
		if ( ! $design ) {
			return $room_id;
		}
		$current = (int) get_the_ID();
		if ( $current !== $design && (int) get_queried_object_id() !== $design ) {
			return $room_id;
		}
		$match = self::matching_room( $design );
		if ( $match ) {
			return $match;
		}
		$rooms = Flexo_Booking_Rooms::bookable();
		return $rooms ? (int) $rooms[0]->ID : $room_id;
	}

	/**
	 * The room with the same address (slug) as the design page, if any.
	 */
	public static function matching_room( $design ) {
		$post = get_post( $design );
		if ( ! $post || 'page' !== $post->post_type || '' === $post->post_name ) {
			return 0;
		}
		$room = Flexo_Booking_Rooms::find( $post->post_name );
		return $room ? (int) $room->ID : 0;
	}

	/**
	 * A published design page that matches a room points search engines to
	 * the room page; otherwise it is kept out of search results.
	 */
	public static function canonical( $url, $post ) {
		$room = self::design_page_room( $post );
		return $room ? (string) get_permalink( $room ) : $url;
	}

	public static function seo_canonical( $url ) {
		$room = is_singular() ? self::design_page_room( get_queried_object() ) : 0;
		return $room ? (string) get_permalink( $room ) : $url;
	}

	public static function robots( $robots ) {
		if ( is_singular( 'page' ) && self::id() === (int) get_queried_object_id() && ! self::matching_room( self::id() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	private static function design_page_room( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'page' !== $post->post_type || (int) $post->ID !== self::id() ) {
			return 0;
		}
		return self::matching_room( $post->ID );
	}

	/**
	 * Pages and templates that can be chosen, for the settings.
	 *
	 * @return array { pages: WP_Post[], templates: WP_Post[] }
	 */
	public static function choices() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return array(
				'pages'     => array(),
				'templates' => array(),
			);
		}
		$common = array(
			'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future' ),
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery -- settings screen only.
			'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery
			'lang'           => '',
		);
		$pages     = get_posts( array_merge( $common, array( 'post_type' => 'page' ) ) );
		$templates = array_values(
			array_filter(
				get_posts( array_merge( $common, array( 'post_type' => 'elementor_library' ) ) ),
				static function ( $post ) {
					return in_array( (string) get_post_meta( $post->ID, '_elementor_template_type', true ), self::TEMPLATE_TYPES, true );
				}
			)
		);
		// Only one version per translation group (the chosen one is translated when shown).
		if ( function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post_language' ) ) {
			$default = pll_default_language();
			$main    = static function ( $post ) use ( $default ) {
				$lang = pll_get_post_language( $post->ID );
				return ! $lang || $lang === $default;
			};
			$pages = array_values( array_filter( $pages, $main ) );
		}
		return array(
			'pages'     => $pages,
			'templates' => $templates,
		);
	}

	/**
	 * What the design is connected to: room tags and widgets, and any
	 * JetEngine fields still used (they show nothing without JetEngine).
	 *
	 * @return array { room: int, jet: string[] }
	 */
	public static function scan( $id ) {
		$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		$out  = array(
			'room' => 0,
			'jet'  => array(),
		);
		if ( is_array( $data ) ) {
			self::scan_value( $data, $out );
		}
		$out['jet'] = array_values( array_unique( $out['jet'] ) );
		return $out;
	}

	private static function scan_value( $value, array &$out ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( isset( $value['widgetType'] ) && 0 === strpos( (string) $value['widgetType'], 'flexo-room-' ) ) {
			++$out['room'];
		}
		if ( isset( $value['__dynamic__'] ) && is_array( $value['__dynamic__'] ) ) {
			foreach ( $value['__dynamic__'] as $tag ) {
				if ( ! is_string( $tag ) || ! preg_match_all( '/name="([^"]+)"(?: settings="([^"]*)")?/', $tag, $matches, PREG_SET_ORDER ) ) {
					continue;
				}
				foreach ( $matches as $match ) {
					if ( 0 === strpos( $match[1], 'flexo-room' ) ) {
						++$out['room'];
					} elseif ( 0 === strpos( $match[1], 'jet-' ) ) {
						$settings    = isset( $match[2] ) ? json_decode( rawurldecode( $match[2] ), true ) : null;
						$out['jet'][] = is_array( $settings ) && ! empty( $settings['meta_field'] ) ? (string) $settings['meta_field'] : $match[1];
					}
				}
			}
		}
		foreach ( $value as $key => $child ) {
			if ( '__dynamic__' !== $key ) {
				self::scan_value( $child, $out );
			}
		}
	}
}
