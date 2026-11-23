<?php
/**
 * Elementor widgets (1.10.0): "Flexo Contact Form" and "Booking & Contact
 * tabs". Both use the booking form's look and the shared Style sections
 * (colours, layout, buttons, fields), so a contact form next to a booking
 * form matches it.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Flexo Contact Form: the hotel's contact form ([flexo_contact]).
 */
class Flexo_Booking_Contact_Widget extends Flexo_Booking_Elementor_Widget {

	public function get_name() {
		return 'flexo-contact-form';
	}

	public function get_title() {
		return __( 'Flexo Contact Form', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	public function get_keywords() {
		return array( 'contact', 'form', 'message', 'enquiry', 'email', 'hotel', 'flexo' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Contact form', 'flexo-booking' ) )
		);
		$this->contact_text_controls( '' );
		$this->add_control(
			'contact_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Fields, phone and subject, the reply to the guest and the standard texts: Bookings → Settings → Pages → Contact form. Messages arrive by email and on Today.', 'flexo-booking' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$this->end_controls_section();

		$this->register_style_controls();
	}

	/**
	 * Heading, intro, button and success texts ($prefix '' or 'contact_').
	 */
	protected function contact_text_controls( $prefix ) {
		$this->add_control(
			$prefix . 'title',
			array(
				'label'   => __( 'Heading', 'flexo-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => '',
			)
		);
		$this->add_control(
			$prefix . 'intro',
			array(
				'label'   => __( 'Text above the fields', 'flexo-booking' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => '',
			)
		);
		$this->add_control(
			$prefix . 'button_text',
			array(
				'label'       => __( 'Button text', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => Flexo_Booking_Forms::contact_text( 'button' ),
			)
		);
		$this->add_control(
			$prefix . 'success_text',
			array(
				'label'       => __( 'Text after sending', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => '',
				'placeholder' => Flexo_Booking_Forms::contact_text( 'success' ),
			)
		);
	}

	protected function style_parts() {
		return array( 'buttons', 'fields' );
	}

	/**
	 * The contact form has one button and no outline buttons.
	 */
	protected function button_args() {
		return array( 'outline' => false );
	}

	protected function contact_atts( array $settings, $prefix ) {
		$atts = array();
		foreach ( array( 'title', 'intro', 'button_text', 'success_text' ) as $key ) {
			$atts[ $key ] = isset( $settings[ $prefix . $key ] ) ? (string) $settings[ $prefix . $key ] : '';
		}
		return $atts;
	}

	protected function render() {
		// Markup is escaped inside the template.
		echo Flexo_Booking_Contact::render( $this->contact_atts( $this->get_settings_for_display(), '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Booking & Contact tabs: the booking form and the contact form under two
 * tabs, like the "Booking Form | Contact Form" switch on many hotel sites.
 */
class Flexo_Booking_Forms_Tabs_Widget extends Flexo_Booking_Contact_Widget {

	public function get_name() {
		return 'flexo-forms-tabs';
	}

	public function get_title() {
		return __( 'Booking & Contact tabs', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-tabs';
	}

	public function get_keywords() {
		return array( 'tabs', 'booking', 'contact', 'form', 'reservation', 'hotel', 'flexo' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_tabs',
			array( 'label' => __( 'Tabs', 'flexo-booking' ) )
		);
		$this->add_control(
			'booking_label',
			array(
				'label'       => __( 'Booking tab', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Booking', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'contact_label',
			array(
				'label'       => __( 'Contact tab', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Contact', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'default_tab',
			array(
				'label'       => __( 'Shown first', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'booking',
				'options'     => array(
					'booking' => __( 'Booking', 'flexo-booking' ),
					'contact' => __( 'Contact', 'flexo-booking' ),
				),
				'description' => __( 'A link ending in #contact opens the contact tab.', 'flexo-booking' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_booking',
			array( 'label' => __( 'Booking form', 'flexo-booking' ) )
		);
		$this->add_control(
			'room',
			array(
				'label'   => __( 'Room', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => Flexo_Booking_Elementor::room_options( __( 'Let the guest choose', 'flexo-booking' ) ),
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
				'label'   => __( 'Room results', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => $layouts,
			)
		);
		$this->add_control(
			'amenity_icons',
			array(
				'label'   => __( 'Amenities on the room cards', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => Flexo_Booking_Elementor_Controls::amenity_icon_options(),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_contact',
			array( 'label' => __( 'Contact form', 'flexo-booking' ) )
		);
		$this->contact_text_controls( 'contact_' );
		$this->end_controls_section();

		$this->tabs_style_controls();
		$this->register_style_controls();
	}

	protected function style_parts() {
		return array( 'buttons', 'fields', 'steps', 'calendar', 'cards' );
	}

	protected function button_args() {
		return array();
	}

	/**
	 * Style → Tabs: colours (normal, hover, selected), corners, spacing, alignment, text.
	 */
	protected function tabs_style_controls() {
		$this->start_controls_section(
			'section_style_tabs',
			array(
				'label' => __( 'Tabs', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'tabs_align',
			array(
				'label'     => __( 'Alignment', 'flexo-booking' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'flexo-booking' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Center', 'flexo-booking' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'flexo-booking' ),
						'icon'  => 'eicon-h-align-right',
					),
					'stretch'    => array(
						'title' => __( 'Full width', 'flexo-booking' ),
						'icon'  => 'eicon-h-align-stretch',
					),
				),
				'selectors_dictionary' => array(
					'flex-start' => '--fb-tabs-align: flex-start;',
					'center'     => '--fb-tabs-align: center;',
					'flex-end'   => '--fb-tabs-align: flex-end;',
					'stretch'    => '--fb-tabs-align: stretch; --fb-tab-flex: 1 1 0;',
				),
				'selectors' => array( '{{WRAPPER}} .flexo-forms-tabs' => '{{VALUE}}' ),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'tab_typography',
				'selector' => '{{WRAPPER}} .flexo-forms-tabs .fb-tab',
			)
		);
		$this->start_controls_tabs( 'tab_colors' );
		$this->start_controls_tab( 'tab_colors_normal', array( 'label' => __( 'Normal', 'flexo-booking' ) ) );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_bg', __( 'Background', 'flexo-booking' ), '--fb-tab-bg: {{VALUE}};', '.flexo-forms-tabs' );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_text', __( 'Text colour', 'flexo-booking' ), '--fb-tab-text: {{VALUE}};', '.flexo-forms-tabs' );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_border', __( 'Border colour', 'flexo-booking' ), '--fb-tab-border: {{VALUE}};', '.flexo-forms-tabs' );
		$this->end_controls_tab();
		$this->start_controls_tab( 'tab_colors_hover', array( 'label' => __( 'Hover', 'flexo-booking' ) ) );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_hover_bg', __( 'Background', 'flexo-booking' ), '--fb-tab-hover-bg: {{VALUE}};', '.flexo-forms-tabs' );
		$this->end_controls_tab();
		$this->start_controls_tab( 'tab_colors_active', array( 'label' => __( 'Selected', 'flexo-booking' ) ) );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_active_bg', __( 'Background', 'flexo-booking' ), '--fb-tab-active-bg: {{VALUE}};', '.flexo-forms-tabs' );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_active_text', __( 'Text colour', 'flexo-booking' ), '--fb-tab-active-text: {{VALUE}};', '.flexo-forms-tabs' );
		Flexo_Booking_Elementor_Controls::color( $this, 'tab_active_border', __( 'Border colour', 'flexo-booking' ), '--fb-tab-active-border: {{VALUE}};', '.flexo-forms-tabs' );
		$this->end_controls_tab();
		$this->end_controls_tabs();
		Flexo_Booking_Elementor_Controls::slider( $this, 'tab_border_width', __( 'Border width', 'flexo-booking' ), '--fb-tab-border-w', 8, '.flexo-forms-tabs', array( 'separator' => 'before' ) );
		Flexo_Booking_Elementor_Controls::slider( $this, 'tab_radius', __( 'Corner radius', 'flexo-booking' ), '--fb-tab-radius', 100, '.flexo-forms-tabs', array( 'description' => __( '100 px gives the tabs fully round ends (pill).', 'flexo-booking' ) ) );
		$this->add_responsive_control(
			'tab_padding',
			array(
				'label'      => __( 'Padding', 'flexo-booking' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .flexo-forms-tabs' => '--fb-tab-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		Flexo_Booking_Elementor_Controls::slider( $this, 'tabs_gap', __( 'Space between tabs', 'flexo-booking' ), '--fb-tabs-gap', 40, '.flexo-forms-tabs' );
		Flexo_Booking_Elementor_Controls::slider( $this, 'tabs_space', __( 'Space below the tabs', 'flexo-booking' ), '--fb-tabs-space', 80, '.flexo-forms-tabs' );
		$this->end_controls_section();
	}

	protected function render() {
		$s = $this->get_settings_for_display();
		// Markup is escaped inside the templates.
		echo Flexo_Booking_Contact::tabs( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'booking_label' => isset( $s['booking_label'] ) ? $s['booking_label'] : '',
				'contact_label' => isset( $s['contact_label'] ) ? $s['contact_label'] : '',
				'default'       => isset( $s['default_tab'] ) ? $s['default_tab'] : 'booking',
				'booking'       => array(
					'room'          => isset( $s['room'] ) ? $s['room'] : '',
					'title'         => isset( $s['title'] ) ? $s['title'] : '',
					'button_text'   => isset( $s['button_text'] ) ? $s['button_text'] : '',
					'results'       => isset( $s['results_layout'] ) ? $s['results_layout'] : '',
					'amenity_icons' => isset( $s['amenity_icons'] ) ? $s['amenity_icons'] : '',
				),
				'contact'       => $this->contact_atts( $s, 'contact_' ),
			)
		);
	}
}
