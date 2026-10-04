<?php
/**
 * Elementor widget: "Room booking box" – dates and guests for the current
 * room, live availability and total, and "Book now" to the booking page.
 * Same look and style settings as the booking form widget.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;

class Flexo_Booking_Room_Box_Widget extends Flexo_Booking_Elementor_Widget {

	public function get_name() {
		return 'flexo-room-booking-box';
	}

	public function get_title() {
		return __( 'Room booking box', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-price-table';
	}

	public function get_keywords() {
		return array( 'room', 'booking', 'book now', 'availability', 'price', 'dates', 'hotel', 'flexo' );
	}

	/**
	 * Availability and prices change per room: never cache the output.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Room booking box', 'flexo-booking' ) )
		);

		$this->add_control(
			'room',
			array(
				'label'       => __( 'Room', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => Flexo_Booking_Elementor::room_options( __( 'Current room (automatic)', 'flexo-booking' ) ),
				'description' => __( 'Automatic: the room of the page – in your room template each room gets its own box.', 'flexo-booking' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'   => __( 'Heading', 'flexo-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '',
			)
		);

		$this->add_control(
			'show_price',
			array(
				'label'        => __( 'Show the "from" price', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'       => __( 'Button text', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Check availability', 'flexo-booking' ),
			)
		);

		$this->add_control(
			'book_text',
			array(
				'label'       => __( '"Book now" text', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Book now', 'flexo-booking' ),
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

		$this->end_controls_section();

		$this->register_style_controls();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Markup is escaped inside the template.
		echo Flexo_Booking_Frontend::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'layout'       => 'box',
				'room'         => $settings['room'],
				'booking_page' => $settings['booking_page'],
				'title'        => $settings['title'],
				'button_text'  => $settings['button_text'],
				'book_text'    => $settings['book_text'],
				'show_price'   => 'yes' === $settings['show_price'] ? 'yes' : 'no',
			)
		);
	}
}
