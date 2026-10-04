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
		$widgets_manager->register( new Flexo_Booking_Elementor_Widget() );
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
