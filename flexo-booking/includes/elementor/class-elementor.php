<?php
/**
 * Elementor integration: widget category, the booking form widget, the
 * room widgets (amenities, details, gallery) and the room dynamic tags.
 * Loaded only when Elementor is active.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Elementor {

	public static function init() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( __CLASS__, 'register_tags' ) );
		add_filter( 'flexo_booking_current_room', array( __CLASS__, 'sample_room' ) );
		add_action( 'elementor/preview/enqueue_styles', array( 'Flexo_Booking_Room_Render', 'enqueue' ) );
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'icon_list' ), 10, 2 );
		add_action( 'elementor/preview/enqueue_scripts', array( __CLASS__, 'preview_scripts' ) );
	}

	/**
	 * Elementor's Icon List with the Amenities tag in an item: that item
	 * becomes one list item per amenity, each with the icon chosen for it in
	 * the room editor – drawn in the list's own icon colour and size, with the
	 * list's text style and spacing (1.9.0). The list escapes its texts, which
	 * removes the icons from the tag's output, so they travel as data.
	 *
	 * @param string                 $content Widget HTML.
	 * @param \Elementor\Widget_Base $widget
	 */
	public static function icon_list( $content, $widget ) {
		if ( 'icon-list' !== $widget->get_name() || false === strpos( $content, 'flexo-room-amenities' ) ) {
			return $content;
		}
		$offset = 0;
		while ( preg_match( '#<ul class="flexo-room-list flexo-room-amenities[^"]*"[^>]*>(.*?)</ul>#s', $content, $list, PREG_OFFSET_CAPTURE, $offset ) ) {
			$list_start = $list[0][1];
			$list_end   = $list_start + strlen( $list[0][0] );
			$item_start = strrpos( substr( $content, 0, $list_start ), '<li class="elementor-icon-list-item' );
			$item_end   = strpos( $content, '</li>', $list_end );
			if ( false === $item_start || false === $item_end ) {
				break;
			}
			$item_end += 5;
			$before    = substr( $content, $item_start, $list_start - $item_start );
			preg_match( '#^<li[^>]*>#', $before, $open );
			$link = preg_match( '#<a\s[^>]*>#', $before, $a ) ? $a[0] : '';
			$rows = self::icon_list_rows( $list[1][0], $open[0], $link );
			$content = substr( $content, 0, $item_start ) . $rows . substr( $content, $item_end );
			$offset  = $item_start + strlen( $rows );
		}
		Flexo_Booking_Room_Render::enqueue();
		return $content;
	}

	/**
	 * Icon List items for the amenities of one tag.
	 *
	 * @param string $list_html The amenities list's items.
	 * @param string $open      The Icon List item's opening tag.
	 * @param string $link      The item's link opening tag, or ''.
	 */
	private static function icon_list_rows( $list_html, $open, $link ) {
		preg_match_all( '#<li class="flexo-room-list__item"(?:\s+data-flexo-icon="([^"]*)")?[^>]*>.*?<span class="flexo-room-list__text">(.*?)</span>#s', $list_html, $items, PREG_SET_ORDER );
		$out = '';
		foreach ( $items as $item ) {
			$label = trim( wp_strip_all_tags( html_entity_decode( $item[2], ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $label ) {
				continue;
			}
			$out .= $open . $link . '<span class="elementor-icon-list-icon">' . self::icon_list_icon( html_entity_decode( $item[1], ENT_QUOTES, 'UTF-8' ) ) . '</span>'
				. '<span class="elementor-icon-list-text">' . esc_html( $label ) . '</span>' . ( '' !== $link ? '</a>' : '' ) . '</li>';
		}
		return $out;
	}

	/**
	 * The icon as an element Elementor colours and sizes like its own icons.
	 *
	 * @param string $url Bundled icon (SVG data address) or uploaded icon address.
	 */
	public static function icon_list_icon( $url ) {
		if ( 1 === preg_match( '#^data:image/svg\+xml,[A-Za-z0-9%._~!*()\'-]+$#', $url ) ) {
			$safe = $url;
		} else {
			$safe = esc_url_raw( $url, array( 'http', 'https' ) );
		}
		$style = '' !== $safe ? '--flexo-icon:url("' . $safe . '")' : '';
		return '<i class="flexo-icon-list-icon" style="' . esc_attr( $style ) . '" aria-hidden="true"></i>';
	}

	/**
	 * The same in the editor's preview, where Elementor draws the Icon List
	 * in the browser.
	 */
	public static function preview_scripts() {
		wp_enqueue_script( 'flexo-booking-elementor-preview', FLEXO_BOOKING_URL . 'assets/js/elementor-preview.js', array( 'elementor-frontend' ), FLEXO_BOOKING_VERSION, true );
		// The editor draws widgets through its own requests, so the booking
		// form's texts and settings are added to the preview page here –
		// without them its buttons were empty and prices read "undefined".
		if ( ! wp_script_is( 'flexo-booking', 'registered' ) ) {
			Flexo_Booking_Frontend::register_assets();
		}
		Flexo_Booking_Frontend::localize();
	}

	public static function register_category( $elements_manager ) {
		$elements_manager->add_category(
			'flexo-hotels',
			array(
				'title' => __( 'FlexoHotels', 'flexo-booking' ),
				'icon'  => 'eicon-calendar',
			)
		);
	}

	public static function register_widgets( $widgets_manager ) {
		require_once __DIR__ . '/class-booking-widget.php';
		require_once __DIR__ . '/class-room-widgets.php';
		require_once __DIR__ . '/class-room-box-widget.php';
		$widgets_manager->register( new Flexo_Booking_Elementor_Widget() );
		$widgets_manager->register( new Flexo_Booking_Room_Box_Widget() );
		$widgets_manager->register( new Flexo_Booking_Room_Amenities_Widget() );
		$widgets_manager->register( new Flexo_Booking_Room_Details_Widget() );
		$widgets_manager->register( new Flexo_Booking_Room_Gallery_Widget() );
	}

	/**
	 * @return string[] Tag classes, in the order shown in Elementor's list.
	 */
	public static function tag_classes() {
		return array(
			'Flexo_Booking_Room_Name_Tag',
			'Flexo_Booking_Room_Type_Tag',
			'Flexo_Booking_Room_Excerpt_Tag',
			'Flexo_Booking_Room_Description_Tag',
			'Flexo_Booking_Room_Price_Tag',
			'Flexo_Booking_Room_Size_Tag',
			'Flexo_Booking_Room_Guests_Tag',
			'Flexo_Booking_Room_Beds_Tag',
			'Flexo_Booking_Room_View_Tag',
			'Flexo_Booking_Room_Amenities_Tag',
			'Flexo_Booking_Room_Detail_Tag',
			'Flexo_Booking_Room_Availability_Tag',
			'Flexo_Booking_Room_Image_Tag',
			'Flexo_Booking_Room_Gallery_Tag',
			'Flexo_Booking_Room_Url_Tag',
			'Flexo_Booking_Room_Link_Tag',
		);
	}

	public static function register_tags( $dynamic_tags ) {
		require_once __DIR__ . '/class-room-link-tag.php';
		require_once __DIR__ . '/class-room-tags.php';
		$dynamic_tags->register_group( 'flexo-booking', array( 'title' => __( 'Flexo Booking: room', 'flexo-booking' ) ) );
		foreach ( self::tag_classes() as $class ) {
			$dynamic_tags->register( new $class() );
		}
	}

	/**
	 * Room options for Elementor select controls, keyed by slug so the
	 * choice survives exporting the template to another site.
	 */
	public static function room_options( $empty_label ) {
		$options = array( '' => $empty_label );
		foreach ( Flexo_Booking_Rooms::all() as $post ) {
			$options[ $post->post_name ] = get_the_title( $post );
		}
		return $options;
	}

	/**
	 * Whether the Elementor editor (or its preview) is showing the page.
	 */
	public static function is_editing() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$elementor = \Elementor\Plugin::$instance;
		if ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}
		return isset( $elementor->preview ) && $elementor->preview->is_preview_mode();
	}

	/**
	 * While a template (Theme Builder single, Loop item) is designed and no
	 * preview room is chosen, show the first room as sample content.
	 */
	public static function sample_room( $room_id ) {
		if ( $room_id || ! self::is_editing() ) {
			return $room_id;
		}
		$post_id = get_the_ID();
		if ( $post_id && 'elementor_library' !== get_post_type( $post_id ) ) {
			return $room_id;
		}
		$rooms = Flexo_Booking_Rooms::all();
		return $rooms ? (int) $rooms[0]->ID : $room_id;
	}
}
