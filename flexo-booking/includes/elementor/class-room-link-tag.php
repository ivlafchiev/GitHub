<?php
/**
 * Elementor dynamic tag: "Room booking link".
 *
 * Points any Elementor button or link at the booking page with a room
 * chosen, e.g. /booking/?room=deluxe-double. By default it uses the current
 * room (room page, room template, Loop item); on other pages without a
 * chosen room it opens the booking page for the guest to choose. Built
 * from a path and a slug at render time, so it keeps working after the
 * template is imported into another site.
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
				'label'       => __( 'Room', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => Flexo_Booking_Elementor::room_options( __( 'Current room (automatic)', 'flexo-booking' ) ),
				'description' => __( 'Automatic: the room of the page. On pages that are not about one room, the guest chooses.', 'flexo-booking' ),
			)
		);

		$this->add_control(
			'booking_page',
			array(
				'label'       => __( 'Booking page path', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'From Settings → Hotel', 'flexo-booking' ),
				'description' => __( 'Leave empty to use the booking page from the settings.', 'flexo-booking' ),
			)
		);

		$this->add_control(
			'pass_search',
			array(
				'label'        => __( 'Keep the dates and guests from the page address', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'When a guest came from a search, the booking form opens with their dates.', 'flexo-booking' ),
			)
		);
	}

	public function get_value( array $options = array() ) {
		$room_id = Flexo_Booking_Room_I18n::canonical_id( Flexo_Booking_Room_Content::current_id( (string) $this->get_settings( 'room' ) ) );
		$post    = $room_id ? get_post( $room_id ) : null;
		$slug    = $post && 'publish' === $post->post_status && ! Flexo_Booking_Room_Content::is_demo( $post->ID ) ? $post->post_name : '';
		$args    = 'yes' === $this->get_settings( 'pass_search' ) ? Flexo_Booking_Room_Content::search_args() : array();

		return esc_url( Flexo_Booking_Room_Content::booking_url( $slug, $args, (string) $this->get_settings( 'booking_page' ) ) );
	}
}
