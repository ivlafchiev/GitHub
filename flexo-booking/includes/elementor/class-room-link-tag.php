<?php
/**
 * Elementor dynamic tag: "Room booking link".
 *
 * Lets any Elementor button or link point to the booking page with a room
 * preselected, e.g. /booking/?room=deluxe-double. Because the link is built
 * from a path and a slug at render time, it keeps working after the template
 * is imported into another site.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

class Flexo_Booking_Room_Link_Tag extends Data_Tag {

	public function get_name() {
		return 'flexo-room-booking-link';
	}

	public function get_title() {
		return __( 'Room booking link', 'flexo-booking' );
	}

	public function get_group() {
		return 'flexo-booking';
	}

	public function get_categories() {
		return array( TagsModule::URL_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'room',
			array(
				'label'   => __( 'Room', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => Flexo_Booking_Elementor::room_options( __( 'No room (guest chooses)', 'flexo-booking' ) ),
			)
		);

		$this->add_control(
			'booking_page',
			array(
				'label'   => __( 'Booking page path', 'flexo-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '/booking/',
			)
		);
	}

	public function get_value( array $options = array() ) {
		$path = $this->get_settings( 'booking_page' );
		$path = $path ? $path : '/booking/';
		$url  = preg_match( '#^https?://#i', $path ) ? $path : home_url( '/' . ltrim( $path, '/' ) );
		$room = $this->get_settings( 'room' );

		return esc_url( $room ? add_query_arg( 'room', rawurlencode( $room ), $url ) : $url );
	}
}
