<?php
/**
 * HTML for room content: amenities, facts and details, photo gallery.
 * Used by the Elementor widgets, the dynamic tags and the default room
 * page, so a room looks the same wherever it is shown.
 *
 * Styles and the small gallery script are registered here and loaded only
 * on pages that show room content.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Render {

	const STYLE  = 'flexo-booking-rooms';
	const SCRIPT = 'flexo-booking-rooms';

	/**
	 * @var int Counter for unique gallery IDs on a page.
	 */
	private static $uid = 0;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'phone_bar' ) );
		add_shortcode( 'flexo_room', array( __CLASS__, 'room_shortcode' ) );
		add_shortcode( 'flexo_room_field', array( __CLASS__, 'field_shortcode' ) );
	}

	public static function register_assets() {
		wp_register_style( self::STYLE, FLEXO_BOOKING_URL . 'assets/css/rooms.css', array(), FLEXO_BOOKING_VERSION );
		wp_register_script( self::SCRIPT, FLEXO_BOOKING_URL . 'assets/js/rooms.js', array(), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'FlexoRooms',
			array(
				'i18n' => array(
					'close'    => __( 'Close', 'flexo-booking' ),
					'previous' => __( 'Previous photo', 'flexo-booking' ),
					'next'     => __( 'Next photo', 'flexo-booking' ),
					/* translators: 1: photo number, 2: number of photos */
					'photoOf'  => __( 'Photo %1$d of %2$d', 'flexo-booking' ),
					/* translators: %d: page of photos */
					'goTo'     => __( 'Show photos, page %d', 'flexo-booking' ),
				),
			)
		);
	}

	public static function enqueue( $script = false ) {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::STYLE );
		if ( $script ) {
			wp_enqueue_script( self::SCRIPT );
		}
	}

	/**
	 * Amenities as an icon list.
	 *
	 * @param array $items Amenities: list of array( key, label, icon ).
	 * @param array $args {
	 *     @type string $layout           list | inline | grid.
	 *     @type bool   $icons            Show icons.
	 *     @type string $icon_html        One icon for every amenity (already rendered HTML).
	 *     @type bool   $original_colours Uploaded icons keep their own colours.
	 *     @type int    $limit            0 = all.
	 * }
	 */
	public static function amenities( array $items, array $args = array() ) {
		$args = array_merge(
			array(
				'layout'           => 'list',
				'icons'            => true,
				'icon_html'        => '',
				'original_colours' => false,
				'limit'            => 0,
			),
			$args
		);
		if ( $args['limit'] > 0 ) {
			$items = array_slice( $items, 0, (int) $args['limit'] );
		}
		if ( ! $items ) {
			return '';
		}
		$out = '<ul class="flexo-room-list flexo-room-amenities flexo-room-list--' . esc_attr( $args['layout'] ) . '">';
		foreach ( $items as $item ) {
			$out .= '<li class="flexo-room-list__item">';
			if ( $args['icons'] ) {
				$icon = '' !== $args['icon_html'] ? $args['icon_html'] : Flexo_Booking_Room_Icons::html( $item['icon'], 'flexo-room-icon', $args['original_colours'] );
				$out .= '<span class="flexo-room-list__icon">' . $icon . '</span>';
			}
			$out .= '<span class="flexo-room-list__text">' . esc_html( $item['label'] ) . '</span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Facts (size, guests, beds, view, room type) and "More details" rows.
	 *
	 * @param array $room Room view.
	 * @param array $args {
	 *     @type string[] $facts         Facts to show, in order: size, guests, beds, view, type.
	 *     @type bool     $more          Add the room's "More details".
	 *     @type string   $layout        list | inline | grid.
	 *     @type string   $show          both (name and value) | value | stacked.
	 *     @type string   $guests_format See Flexo_Booking_Room_Content::guests_text().
	 *     @type bool     $icons         Show icons.
	 *     @type array    $fact_icons    fact => icon HTML replacing the usual icon.
	 *     @type bool     $original_colours Uploaded icons keep their own colours.
	 * }
	 */
	public static function details( array $room, array $args = array() ) {
		$args = array_merge(
			array(
				'facts'            => array( 'size', 'guests', 'beds', 'view' ),
				'more'             => true,
				'layout'           => 'list',
				'show'             => 'both',
				'guests_format'    => 'summary',
				'icons'            => true,
				'fact_icons'       => array(),
				'original_colours' => false,
			),
			$args
		);
		$rows = array();
		foreach ( (array) $args['facts'] as $fact ) {
			$row = self::fact( $room, $fact, $args['guests_format'] );
			if ( $row ) {
				if ( ! empty( $args['fact_icons'][ $fact ] ) ) {
					$row['icon_html'] = $args['fact_icons'][ $fact ];
				}
				$rows[] = $row;
			}
		}
		if ( $args['more'] ) {
			foreach ( $room['details'] as $detail ) {
				$rows[] = array(
					'key'   => 'detail',
					'icon'  => '' !== $detail['icon'] ? $detail['icon'] : 'info',
					'label' => $detail['label'],
					'value' => $detail['value'],
				);
			}
		}
		if ( ! $rows ) {
			return '';
		}
		$out = '<ul class="flexo-room-list flexo-room-details flexo-room-list--' . esc_attr( $args['layout'] ) . ' flexo-room-details--' . esc_attr( $args['show'] ) . '">';
		foreach ( $rows as $row ) {
			$out .= '<li class="flexo-room-list__item flexo-room-details__' . esc_attr( $row['key'] ) . '">';
			if ( $args['icons'] ) {
				$icon = isset( $row['icon_html'] ) ? $row['icon_html'] : Flexo_Booking_Room_Icons::html( $row['icon'], 'flexo-room-icon', $args['original_colours'] );
				$out .= '<span class="flexo-room-list__icon">' . $icon . '</span>';
			}
			$out .= '<span class="flexo-room-list__text">';
			if ( 'value' !== $args['show'] && '' !== $row['label'] ) {
				$out .= '<span class="flexo-room-details__label">' . esc_html( $row['label'] ) . '</span>';
				$out .= 'both' === $args['show'] && '' !== $row['value'] ? '<span class="flexo-room-details__sep">: </span>' : '';
			}
			$out .= '<span class="flexo-room-details__value">' . esc_html( $row['value'] ) . '</span>';
			$out .= '</span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * One fact row, or null when the room has no value for it.
	 */
	private static function fact( array $room, $fact, $guests_format ) {
		switch ( $fact ) {
			case 'size':
				$value = Flexo_Booking_Room_Content::size_text( $room );
				return '' === $value ? null : array(
					'key'   => 'size',
					'icon'  => 'size',
					'label' => __( 'Size', 'flexo-booking' ),
					'value' => $value,
				);
			case 'guests':
				return array(
					'key'   => 'guests',
					'icon'  => 'users',
					'label' => __( 'Guests', 'flexo-booking' ),
					'value' => Flexo_Booking_Room_Content::guests_text( $room, $guests_format ),
				);
			case 'beds':
				return '' === $room['beds'] ? null : array(
					'key'   => 'beds',
					'icon'  => 'bed_double',
					'label' => __( 'Beds', 'flexo-booking' ),
					'value' => $room['beds'],
				);
			case 'view':
				return '' === $room['view'] ? null : array(
					'key'   => 'view',
					'icon'  => 'eye',
					'label' => __( 'View', 'flexo-booking' ),
					'value' => $room['view'],
				);
			case 'type':
				return ! $room['types'] ? null : array(
					'key'   => 'type',
					'icon'  => 'hotel',
					'label' => __( 'Room type', 'flexo-booking' ),
					'value' => implode( ', ', $room['types'] ),
				);
		}
		return null;
	}

	/**
	 * Photo gallery as a carousel or a grid.
	 *
	 * @param array $room Room view.
	 * @param array $args {
	 *     @type bool   $with_main Start with the main photo.
	 *     @type string $layout    carousel | grid.
	 *     @type int    $limit     0 = all.
	 *     @type string $size      Image size.
	 *     @type string $lightbox  elementor | flexo | none.
	 *     @type bool   $arrows    Carousel arrows.
	 *     @type bool   $dots      Carousel dots.
	 *     @type int    $autoplay  Milliseconds between slides, 0 = off.
	 *     @type bool   $placeholder Show the placeholder when there are no photos.
	 * }
	 */
	public static function gallery( array $room, array $args = array() ) {
		$args = array_merge(
			array(
				'with_main'   => true,
				'layout'      => 'carousel',
				'limit'       => 0,
				'size'        => 'large',
				'lightbox'    => 'flexo',
				'arrows'      => true,
				'dots'        => true,
				'autoplay'    => 0,
				'placeholder' => true,
			),
			$args
		);
		$ids = $args['with_main'] ? $room['gallery'] : Flexo_Booking_Room_Content::gallery( $room['id'], false );
		if ( $args['limit'] > 0 ) {
			$ids = array_slice( $ids, 0, (int) $args['limit'] );
		}
		if ( ! $ids ) {
			if ( ! $args['placeholder'] ) {
				return '';
			}
			return '<div class="flexo-room-gallery flexo-room-gallery--empty"><img class="flexo-room-gallery__img" src="' . esc_url( Flexo_Booking_Room_Content::placeholder_url() ) . '" alt="" width="1200" height="800"></div>';
		}
		++self::$uid;
		$group    = 'flexo-room-' . $room['id'] . '-' . self::$uid;
		$carousel = 'carousel' === $args['layout'] && count( $ids ) > 1;
		/* translators: %s: room name */
		$label = sprintf( __( 'Photos of %s', 'flexo-booking' ), $room['title'] );
		$out   = '<div class="flexo-room-gallery flexo-room-gallery--' . ( $carousel ? 'carousel' : 'grid' ) . '"';
		$out  .= $carousel ? ' role="region" aria-roledescription="carousel" aria-label="' . esc_attr( $label ) . '" data-flexo-carousel' . ( $args['autoplay'] > 0 ? ' data-autoplay="' . (int) $args['autoplay'] . '"' : '' ) : '';
		$out  .= 'flexo' === $args['lightbox'] ? ' data-flexo-lightbox' : '';
		$out  .= '><div class="flexo-room-gallery__track"' . ( $carousel ? ' tabindex="0"' : '' ) . '>';
		$count = count( $ids );
		foreach ( $ids as $i => $id ) {
			$alt  = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
			/* translators: 1: room name, 2: photo number */
			$alt  = '' !== $alt ? $alt : sprintf( __( '%1$s – photo %2$d', 'flexo-booking' ), $room['title'], $i + 1 );
			$img  = wp_get_attachment_image(
				$id,
				$args['size'],
				false,
				array(
					'class'    => 'flexo-room-gallery__img',
					'alt'      => $alt,
					'loading'  => 0 === $i ? 'eager' : 'lazy',
					'decoding' => 'async',
				)
			);
			$full = wp_get_attachment_image_url( $id, 'full' );
			$out .= '<figure class="flexo-room-gallery__slide"' . ( $carousel ? ' role="group" aria-roledescription="slide" aria-label="' . esc_attr( sprintf( /* translators: 1: photo number, 2: number of photos */ __( '%1$d of %2$d', 'flexo-booking' ), $i + 1, $count ) ) . '"' : '' ) . '>';
			if ( 'none' !== $args['lightbox'] && $full ) {
				$attrs = 'elementor' === $args['lightbox']
					? ' data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="' . esc_attr( $group ) . '" data-elementor-lightbox-title="' . esc_attr( $alt ) . '"'
					: ' data-elementor-open-lightbox="no"';
				$out  .= '<a class="flexo-room-gallery__link" href="' . esc_url( $full ) . '"' . $attrs . '>' . $img . '</a>';
			} else {
				$out .= $img;
			}
			$out .= '</figure>';
		}
		$out .= '</div>';
		if ( $carousel ) {
			if ( $args['arrows'] ) {
				$out .= '<button type="button" class="flexo-room-gallery__arrow flexo-room-gallery__arrow--prev" data-flexo-dir="-1" aria-label="' . esc_attr__( 'Previous photos', 'flexo-booking' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg></button>';
				$out .= '<button type="button" class="flexo-room-gallery__arrow flexo-room-gallery__arrow--next" data-flexo-dir="1" aria-label="' . esc_attr__( 'Next photos', 'flexo-booking' ) . '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg></button>';
			}
			if ( $args['dots'] ) {
				$out .= '<div class="flexo-room-gallery__dots"></div>';
			}
		}
		return $out . '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Default room page
	 * ------------------------------------------------------------------ */

	/**
	 * The whole room page: name, facts, photos, description, amenities,
	 * details, rates and the booking box. Used when no Elementor Pro single
	 * template is set for rooms, and by [flexo_room].
	 *
	 * @param array $room Room view.
	 */
	public static function page( array $room ) {
		self::enqueue( true );
		$rooms_page = Flexo_Booking_Room_Pages::rooms_page_url();
		$facts      = self::details(
			$room,
			array(
				'facts'         => array( 'size', 'guests', 'beds', 'view' ),
				'more'          => false,
				'layout'        => 'inline',
				'show'          => 'value',
				'guests_format' => 'summary',
			)
		);
		$from = Flexo_Booking_Room_Prices::from_text( $room['id'] );

		$out  = '<article class="flexo-room-page" id="flexo-room-' . (int) $room['id'] . '">';
		$out .= $rooms_page ? '<p class="flexo-room-page__back"><a href="' . esc_url( $rooms_page ) . '">' . esc_html__( '← All rooms', 'flexo-booking' ) . '</a></p>' : '';
		$out .= '<header class="flexo-room-page__head">';
		$out .= $room['types'] ? '<p class="flexo-room-page__type">' . esc_html( implode( ', ', $room['types'] ) ) . '</p>' : '';
		$out .= '<h1 class="flexo-room-page__title">' . esc_html( $room['title'] ) . '</h1>';
		$out .= '' !== $from ? '<p class="flexo-room-page__price">' . esc_html( $from ) . ' <span>' . esc_html__( 'per night', 'flexo-booking' ) . '</span></p>' : '';
		$out .= $facts;
		$out .= '</header>';
		$out .= self::gallery(
			$room,
			array(
				'layout'   => 'carousel',
				'lightbox' => 'flexo',
			)
		);
		$out .= '<div class="flexo-room-page__layout"><div class="flexo-room-page__main">';

		$description = Flexo_Booking_Room_Content::description_html( $room );
		$out        .= '' !== $description ? '<div class="flexo-room-page__description">' . $description . '</div>' : ( '' !== $room['excerpt'] ? '<p class="flexo-room-page__description">' . esc_html( $room['excerpt'] ) . '</p>' : '' );

		$amenities = self::amenities( $room['amenity_list'], array( 'layout' => 'grid' ) );
		if ( '' !== $amenities ) {
			$out .= '<section class="flexo-room-page__section"><h2>' . esc_html__( 'Amenities', 'flexo-booking' ) . '</h2>' . $amenities . '</section>';
		}
		$details = self::details(
			$room,
			array(
				'facts'  => array(),
				'more'   => true,
				'layout' => 'grid',
				'show'   => 'stacked',
			)
		);
		if ( '' !== $details ) {
			$out .= '<section class="flexo-room-page__section"><h2>' . esc_html__( 'Good to know', 'flexo-booking' ) . '</h2>' . $details . '</section>';
		}
		$out .= self::rates( $room );
		$out .= '</div><aside class="flexo-room-page__aside" id="flexo-room-book">';
		$out .= Flexo_Booking_Frontend::render(
			array(
				'layout' => 'box',
				'room'   => (string) $room['id'],
			)
		);
		$out .= '</aside></div></article>';
		return $out;
	}

	/**
	 * Rates the room offers (meals and cancellation), when rate plans are on.
	 */
	private static function rates( array $room ) {
		if ( ! Flexo_Booking_Rate_Plans::enabled() ) {
			return '';
		}
		$plans = Flexo_Booking_Rate_Plans::for_room( $room['id'] );
		if ( ! $plans ) {
			return '';
		}
		$out = '<section class="flexo-room-page__section"><h2>' . esc_html__( 'Rates', 'flexo-booking' ) . '</h2><ul class="flexo-room-rates">';
		foreach ( $plans as $plan ) {
			$out  .= '<li class="flexo-room-rates__item"><strong>' . esc_html( $plan['name'] ) . '</strong>';
			$meals = '' !== $plan['meals'] ? Flexo_Booking_Rate_Plans::meals_label( $plan['meals'] ) : '';
			$terms = array_filter( array( $meals, Flexo_Booking_Rate_Plans::refundable_label( $plan['refundable'] ) ) );
			$out  .= $terms ? '<span class="flexo-room-rates__terms">' . esc_html( implode( ' · ', $terms ) ) . '</span>' : '';
			$out  .= '' !== $plan['description'] ? '<span class="flexo-room-rates__text">' . esc_html( $plan['description'] ) . '</span>' : '';
			$out  .= '' !== $plan['cancellation_policy'] ? '<span class="flexo-room-rates__text">' . esc_html( $plan['cancellation_policy'] ) . '</span>' : '';
			$out  .= '</li>';
		}
		return $out . '</ul></section>';
	}

	/**
	 * [flexo_room room="…"]: the whole room page (default: the room of the page).
	 */
	public static function room_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'room' => '' ), is_array( $atts ) ? $atts : array(), 'flexo_room' );
		$room = Flexo_Booking_Room_Content::room( Flexo_Booking_Room_Content::current_id( $atts['room'] ) );
		return $room ? self::page( $room ) : '';
	}

	/**
	 * [flexo_room_field field="size" room="…"]: one room value as text, for
	 * places without dynamic tags.
	 */
	public static function field_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'field' => 'name',
				'room'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			'flexo_room_field'
		);
		$room = Flexo_Booking_Room_Content::room( Flexo_Booking_Room_Content::current_id( $atts['room'] ) );
		if ( ! $room ) {
			return '';
		}
		switch ( sanitize_key( $atts['field'] ) ) {
			case 'name':
				return esc_html( $room['title'] );
			case 'type':
				return esc_html( implode( ', ', $room['types'] ) );
			case 'excerpt':
				return esc_html( $room['excerpt'] );
			case 'description':
				return Flexo_Booking_Room_Content::description_html( $room );
			case 'size':
				return esc_html( Flexo_Booking_Room_Content::size_text( $room ) );
			case 'guests':
				return esc_html( Flexo_Booking_Room_Content::guests_text( $room ) );
			case 'beds':
				return esc_html( $room['beds'] );
			case 'view':
				return esc_html( $room['view'] );
			case 'price':
				return $room['price'] > 0 ? esc_html( Flexo_Booking_Money::format( $room['price'], null, true ) ) : '';
			case 'from_price':
				return esc_html( Flexo_Booking_Room_Prices::from_text( $room['id'], true ) );
			case 'amenities':
				self::enqueue();
				return self::amenities( $room['amenity_list'] );
			case 'url':
				return esc_url( $room['url'] );
			case 'booking_url':
				return esc_url( $room['booking_url'] );
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * "Check availability" bar on phones
	 * ------------------------------------------------------------------ */

	/**
	 * A bar at the bottom of room pages on phones with the "from" price and
	 * a button to the booking box (or the booking page when the template
	 * has no box). Hidden while the booking box is on screen.
	 */
	public static function phone_bar() {
		if ( ! is_singular( Flexo_Booking_Rooms::POST_TYPE ) || ! Flexo_Booking_Settings::get( 'room_sticky_bar' ) ) {
			return;
		}
		$room = Flexo_Booking_Room_Content::room( get_queried_object_id() );
		if ( ! $room || $room['demo'] || $room['units'] < 1 ) {
			return;
		}
		self::enqueue( true );
		$from = Flexo_Booking_Room_Prices::from_text( $room['id'] );
		echo '<div class="flexo-room-bar" data-flexo-room-bar hidden>';
		echo '<span class="flexo-room-bar__text"><span class="flexo-room-bar__name">' . esc_html( $room['title'] ) . '</span>';
		if ( '' !== $from ) {
			echo '<span class="flexo-room-bar__price">' . esc_html( $from ) . ' <small>' . esc_html__( 'per night', 'flexo-booking' ) . '</small></span>';
		}
		echo '</span><a class="flexo-room-bar__button" href="' . esc_url( $room['booking_url'] ) . '">' . esc_html__( 'Check availability', 'flexo-booking' ) . '</a></div>';
	}
}

