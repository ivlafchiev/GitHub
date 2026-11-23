<?php
/**
 * Elementor widget: "Flexo Booking Form".
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

class Flexo_Booking_Elementor_Widget extends Widget_Base {

	public function get_name() {
		return 'flexo-booking-form';
	}

	public function get_title() {
		return __( 'Flexo Booking Form', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'flexo-hotels' );
	}

	public function get_keywords() {
		return array( 'booking', 'hotel', 'reservation', 'room', 'availability', 'flexo' );
	}

	public function get_script_depends() {
		return array( 'flexo-booking' );
	}

	public function get_style_depends() {
		return array( 'flexo-booking' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Booking form', 'flexo-booking' ) )
		);

		$this->add_control(
			'layout',
			array(
				'label'       => __( 'Layout', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'full',
				'options'     => array(
					'full'   => __( 'Full booking form', 'flexo-booking' ),
					'search' => __( 'Search bar (sends visitors to the booking page)', 'flexo-booking' ),
				),
				'description' => __( 'Use the search bar in hero sections and the full form on your booking page.', 'flexo-booking' ),
			)
		);

		$this->add_control(
			'booking_page',
			array(
				'label'       => __( 'Booking page path', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '/booking/',
				'placeholder' => '/booking/',
				'description' => __( 'The page that contains the full booking form. Use a path like /booking/ (not a full URL) so the template keeps working on other domains.', 'flexo-booking' ),
				'condition'   => array( 'layout' => 'search' ),
			)
		);

		$this->add_control(
			'room',
			array(
				'label'       => __( 'Room', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => Flexo_Booking_Elementor::room_options( __( 'Let the guest choose', 'flexo-booking' ) ),
				'description' => __( 'Preselect a room, e.g. on that room’s page. A ?room= link parameter takes priority.', 'flexo-booking' ),
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
			'button_text',
			array(
				'label'       => __( 'Button text', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Check availability', 'flexo-booking' ),
			)
		);

		$layouts = array( '' => __( 'As in Settings → Pages → Booking', 'flexo-booking' ) );
		foreach ( Flexo_Booking_System_Pages::results_layouts() as $value => $layout ) {
			$layouts[ $value ] = $layout[0];
		}
		$this->add_control(
			'results_layout',
			array(
				'label'       => __( 'Room results', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => $layouts,
				'description' => __( 'Automatic follows the space this widget has: stacked cards in a narrow column, a row of photo, details and price when it is wide.', 'flexo-booking' ),
				'condition'   => array( 'layout' => 'full' ),
			)
		);

		$this->add_control(
			'amenity_icons',
			array(
				'label'       => __( 'Amenities on the room cards', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => Flexo_Booking_Elementor_Controls::amenity_icon_options(),
				'description' => __( 'The icon in front of each amenity, such as Free Wi-Fi.', 'flexo-booking' ),
				'condition'   => array( 'layout' => 'full' ),
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Style sections of this widget (the room box has no steps or room cards).
	 *
	 * @return string[]
	 */
	protected function style_parts() {
		return array( 'buttons', 'fields', 'steps', 'calendar', 'cards' );
	}

	/**
	 * Options of the shared Buttons section (Flexo_Booking_Elementor_Controls::buttons()).
	 */
	protected function button_args() {
		return array();
	}

	/**
	 * Colours, spacing and typography (also used by the Room booking box).
	 */
	protected function register_style_controls() {
		$this->start_controls_section(
			'section_style_colors',
			array(
				'label' => __( 'Colors', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'colors_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Leave empty to use the site’s Global Colors, so the form matches each template automatically.', 'flexo-booking' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$colors = array(
			'primary'          => array( __( 'Accent / buttons', 'flexo-booking' ), '--fb-primary' ),
			'primary_contrast' => array( __( 'Button text', 'flexo-booking' ), '--fb-primary-contrast' ),
			'text'             => array( __( 'Text', 'flexo-booking' ), '--fb-text' ),
			'background'       => array( __( 'Background', 'flexo-booking' ), '--fb-bg' ),
			'field_background' => array( __( 'Field background', 'flexo-booking' ), '--fb-field-bg' ),
			'border'           => array( __( 'Borders', 'flexo-booking' ), '--fb-border' ),
		);
		foreach ( $colors as $id => $color ) {
			$this->add_control(
				'color_' . $id,
				array(
					'label'     => $color[0],
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .flexo-booking' => $color[1] . ': {{VALUE}};' ),
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => __( 'Layout & typography', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => __( 'Padding', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-booking' => '--fb-padding: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Spacing', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-booking' => '--fb-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'      => __( 'Corner radius', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-booking' => '--fb-radius: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'label'    => __( 'Headings', 'flexo-booking' ),
				'selector' => '{{WRAPPER}} .fb-title, {{WRAPPER}} .fb-step-title, {{WRAPPER}} .fb-room__title',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'label'    => __( 'Text & fields', 'flexo-booking' ),
				'selector' => '{{WRAPPER}} .flexo-booking, {{WRAPPER}} .flexo-booking input, {{WRAPPER}} .flexo-booking select, {{WRAPPER}} .flexo-booking textarea',
			)
		);

		$this->end_controls_section();

		// 1.10.0: the shared sections (the button typography above moved into Buttons, same setting).
		$parts = $this->style_parts();
		if ( in_array( 'buttons', $parts, true ) ) {
			Flexo_Booking_Elementor_Controls::buttons( $this, $this->button_args() );
		}
		if ( in_array( 'fields', $parts, true ) ) {
			Flexo_Booking_Elementor_Controls::fields( $this );
		}
		if ( in_array( 'steps', $parts, true ) ) {
			Flexo_Booking_Elementor_Controls::steps( $this );
		}
		if ( in_array( 'calendar', $parts, true ) ) {
			Flexo_Booking_Elementor_Controls::calendar( $this );
		}
		if ( in_array( 'cards', $parts, true ) ) {
			Flexo_Booking_Elementor_Controls::cards( $this );
		}
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Markup is escaped inside the templates.
		echo Flexo_Booking_Frontend::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'layout'       => $settings['layout'],
				'room'         => $settings['room'],
				// Empty: the Booking page of Settings → Pages.
				'booking_page' => $settings['booking_page'],
				'title'        => $settings['title'],
				'button_text'  => $settings['button_text'],
				'results'      => isset( $settings['results_layout'] ) ? $settings['results_layout'] : '',
				'amenity_icons' => isset( $settings['amenity_icons'] ) ? $settings['amenity_icons'] : '',
			)
		);
	}
}
