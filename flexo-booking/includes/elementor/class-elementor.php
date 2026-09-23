<?php
/**
 * Elementor integration: widget category, booking form widget and the
 * "Room booking link" dynamic tag. Loaded only when Elementor is active.
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
		$widgets_manager->register( new Flexo_Booking_Elementor_Widget() );
	}

	public static function register_tags( $dynamic_tags ) {
		require_once __DIR__ . '/class-room-link-tag.php';
		$dynamic_tags->register_group( 'flexo-booking', array( 'title' => __( 'Flexo Booking', 'flexo-booking' ) ) );
		$dynamic_tags->register( new Flexo_Booking_Room_Link_Tag() );
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
}
