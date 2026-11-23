<?php
/**
 * Shared Elementor Style sections (1.10.0) for the Flexo widgets: buttons,
 * fields and labels, steps, calendar, room cards and messages. Each widget
 * calls the groups it needs, so the same element has the same controls
 * everywhere.
 *
 * Colours, sizes and corners are written as the form's custom properties
 * (--fb-*) on "{{WRAPPER}} <root>". The form's own rules use them in every
 * state (hover, focus, disabled), so a widget setting wins over Appearance and
 * the website's styles without fighting them on specificity. Typography and
 * shadows are set on the elements themselves.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;

class Flexo_Booking_Elementor_Controls {

	/**
	 * A colour control that sets custom properties on the widget's root.
	 *
	 * @param \Elementor\Controls_Stack $w      Widget.
	 * @param string                    $id     Control ID.
	 * @param string                    $label  Label.
	 * @param string                    $css    Declarations with {{VALUE}}, e.g. '--fb-button-bg: {{VALUE}};'.
	 * @param string                    $root   Root selector inside the widget.
	 * @param array                     $extra  More control arguments.
	 */
	public static function color( $w, $id, $label, $css, $root = '.flexo-booking', array $extra = array() ) {
		$w->add_control(
			$id,
			array_merge(
				array(
					'label'     => $label,
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} ' . $root => $css ),
				),
				$extra
			)
		);
	}

	/**
	 * A pixel slider that sets a custom property.
	 */
	public static function slider( $w, $id, $label, $property, $max, $root = '.flexo-booking', array $extra = array(), $responsive = true ) {
		$args = array_merge(
			array(
				'label'      => $label,
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => $max ) ),
				'selectors'  => array( '{{WRAPPER}} ' . $root => $property . ': {{SIZE}}{{UNIT}};' ),
			),
			$extra
		);
		if ( $responsive ) {
			$w->add_responsive_control( $id, $args );
		} else {
			$w->add_control( $id, $args );
		}
	}

	/**
	 * Buttons: typography, colours (normal / hover), border, corners, padding,
	 * height, shadow; outline buttons (Back, Done, Add to calendar).
	 *
	 * @param \Elementor\Controls_Stack $w    Widget.
	 * @param array                     $args root: root selector; button: the button selector; outline: has outline buttons.
	 */
	public static function buttons( $w, array $args = array() ) {
		$args   = array_merge(
			array(
				'root'    => '.flexo-booking',
				'button'  => '.fb-button',
				'outline' => true,
				'prefix'  => 'button',
			),
			$args
		);
		$root   = $args['root'];
		$p      = $args['prefix'];
		$w->start_controls_section(
			'section_style_' . $p . 's',
			array(
				'label' => __( 'Buttons', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$w->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $p . '_typography',
				'label'    => __( 'Typography', 'flexo-booking' ),
				'selector' => '{{WRAPPER}} ' . $args['button'],
			)
		);
		$w->start_controls_tabs( $p . '_colors' );
		$w->start_controls_tab( $p . '_colors_normal', array( 'label' => __( 'Normal', 'flexo-booking' ) ) );
		self::color( $w, $p . '_text_color', __( 'Text colour', 'flexo-booking' ), '--fb-button-text: {{VALUE}};', $root );
		self::color( $w, $p . '_bg_color', __( 'Background', 'flexo-booking' ), '--fb-button-bg: {{VALUE}};', $root );
		self::color( $w, $p . '_border_color', __( 'Border colour', 'flexo-booking' ), '--fb-button-border: {{VALUE}};', $root );
		$w->end_controls_tab();
		$w->start_controls_tab( $p . '_colors_hover', array( 'label' => __( 'Hover', 'flexo-booking' ) ) );
		self::color( $w, $p . '_hover_text_color', __( 'Text colour', 'flexo-booking' ), '--fb-button-hover-text: {{VALUE}};', $root );
		self::color( $w, $p . '_hover_bg_color', __( 'Background', 'flexo-booking' ), '--fb-button-hover-bg: {{VALUE}}; --fb-button-hover-filter: none;', $root );
		self::color( $w, $p . '_hover_border_color', __( 'Border colour', 'flexo-booking' ), '--fb-button-hover-border: {{VALUE}};', $root );
		$w->end_controls_tab();
		$w->end_controls_tabs();

		self::slider( $w, $p . '_border_width', __( 'Border width', 'flexo-booking' ), '--fb-button-border-w', 8, $root, array( 'separator' => 'before' ) );
		self::slider(
			$w,
			$p . '_radius',
			__( 'Corner radius', 'flexo-booking' ),
			'--fb-button-radius',
			100,
			$root,
			array( 'description' => __( '100 px gives buttons fully round ends (pill).', 'flexo-booking' ) )
		);
		$w->add_responsive_control(
			$p . '_padding',
			array(
				'label'      => __( 'Padding', 'flexo-booking' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} ' . $root => '--fb-button-padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		self::slider( $w, $p . '_min_height', __( 'Height', 'flexo-booking' ), '--fb-button-min-h', 90, $root, array( 'description' => __( 'At least this tall; long texts wrap onto more lines.', 'flexo-booking' ) ) );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => $p . '_shadow',
				'selector' => '{{WRAPPER}} ' . $root . ' ' . $args['button'],
			)
		);

		if ( $args['outline'] ) {
			$w->add_control(
				$p . '_outline_heading',
				array(
					'label'     => __( 'Outline buttons ("Back", "Done", "Add to calendar")', 'flexo-booking' ),
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);
			$w->start_controls_tabs( $p . '_outline_colors' );
			$w->start_controls_tab( $p . '_outline_normal', array( 'label' => __( 'Normal', 'flexo-booking' ) ) );
			self::color( $w, $p . '_outline_text', __( 'Text colour', 'flexo-booking' ), '--fb-ghost-text: {{VALUE}};', $root );
			self::color( $w, $p . '_outline_bg', __( 'Background', 'flexo-booking' ), '--fb-ghost-bg: {{VALUE}};', $root );
			self::color( $w, $p . '_outline_border', __( 'Border colour', 'flexo-booking' ), '--fb-ghost-border: {{VALUE}};', $root );
			$w->end_controls_tab();
			$w->start_controls_tab( $p . '_outline_hover', array( 'label' => __( 'Hover', 'flexo-booking' ) ) );
			self::color( $w, $p . '_outline_hover_text', __( 'Text colour', 'flexo-booking' ), '--fb-ghost-hover-text: {{VALUE}};', $root );
			self::color( $w, $p . '_outline_hover_bg', __( 'Background', 'flexo-booking' ), '--fb-ghost-hover-bg: {{VALUE}};', $root );
			self::color( $w, $p . '_outline_hover_border', __( 'Border colour', 'flexo-booking' ), '--fb-ghost-hover-border: {{VALUE}};', $root );
			$w->end_controls_tab();
			$w->end_controls_tabs();
		}
		$w->end_controls_section();
	}

	/**
	 * Fields and labels: colours, corners, height, label typography.
	 */
	public static function fields( $w, array $args = array() ) {
		$args = array_merge( array( 'root' => '.flexo-booking' ), $args );
		$root = $args['root'];
		$w->start_controls_section(
			'section_style_fields',
			array(
				'label' => __( 'Fields and labels', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		self::color( $w, 'field_text_color', __( 'Text in fields', 'flexo-booking' ), '--fb-field-text: {{VALUE}};', $root );
		self::color( $w, 'field_border_color', __( 'Field border', 'flexo-booking' ), '--fb-field-border: {{VALUE}};', $root );
		self::color( $w, 'field_focus_color', __( 'Field in use', 'flexo-booking' ), '--fb-focus: {{VALUE}};', $root, array( 'description' => __( 'The line around the field the guest is typing in.', 'flexo-booking' ) ) );
		self::slider( $w, 'field_radius', __( 'Field corners', 'flexo-booking' ), '--fb-field-radius', 60, $root, array( 'separator' => 'before' ) );
		self::slider( $w, 'field_height', __( 'Field and button height', 'flexo-booking' ), '--fb-control-h', 80, $root );
		$w->add_control(
			'label_heading',
			array(
				'label'     => __( 'Labels', 'flexo-booking' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		self::color( $w, 'label_color', __( 'Colour', 'flexo-booking' ), '--fb-label: {{VALUE}};', $root );
		$w->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} ' . $root . ' label',
			)
		);
		$w->add_control(
			'messages_heading',
			array(
				'label'     => __( 'Messages', 'flexo-booking' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		self::color( $w, 'error_color', __( 'Errors', 'flexo-booking' ), '--fb-error: {{VALUE}};', $root, array( 'description' => __( 'Fields to correct and their messages. Keep it dark enough to read on your background.', 'flexo-booking' ) ) );
		self::color( $w, 'success_color', __( 'Success', 'flexo-booking' ), '--fb-success: {{VALUE}};', $root );
		$w->end_controls_section();
	}

	/**
	 * Steps (Dates · Room · Details · Done) and the stay summary.
	 */
	public static function steps( $w ) {
		$w->start_controls_section(
			'section_style_steps',
			array(
				'label' => __( 'Steps and summary', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		self::color( $w, 'step_active_color', __( 'Current step', 'flexo-booking' ), '--fb-step-active: {{VALUE}};' );
		self::color( $w, 'step_done_color', __( 'Completed steps', 'flexo-booking' ), '--fb-step-done: {{VALUE}};' );
		self::color( $w, 'step_inactive_color', __( 'Next steps', 'flexo-booking' ), '--fb-step-inactive: {{VALUE}};' );
		$w->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'step_typography',
				'label'    => __( 'Step names', 'flexo-booking' ),
				'selector' => '{{WRAPPER}} .fb-steps',
			)
		);
		self::color( $w, 'summary_bg_color', __( 'Summary background', 'flexo-booking' ), '--fb-summary-bg: {{VALUE}};', '.flexo-booking', array( 'separator' => 'before' ) );
		$w->end_controls_section();
	}

	/**
	 * Calendar: chosen days, the stay between them, arrows.
	 */
	public static function calendar( $w, array $args = array() ) {
		$args = array_merge( array( 'root' => '.flexo-booking' ), $args );
		$root = $args['root'];
		$w->start_controls_section(
			'section_style_calendar',
			array(
				'label' => __( 'Calendar', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		self::color( $w, 'calendar_bg', __( 'Background', 'flexo-booking' ), '--fb-picker-bg: {{VALUE}};', $root );
		self::color( $w, 'day_selected_bg', __( 'Check-in and check-out', 'flexo-booking' ), '--fb-day-selected-bg: {{VALUE}};', $root );
		self::color( $w, 'day_selected_text', __( 'Their text', 'flexo-booking' ), '--fb-day-selected-text: {{VALUE}};', $root );
		self::color( $w, 'day_range_bg', __( 'Nights in between', 'flexo-booking' ), '--fb-day-range-bg: {{VALUE}};', $root );
		self::color( $w, 'day_hover_border', __( 'Day under the mouse', 'flexo-booking' ), '--fb-day-hover: {{VALUE}};', $root );
		self::color( $w, 'calendar_arrow_color', __( 'Arrows', 'flexo-booking' ), '--fb-picker-nav: {{VALUE}};', $root );
		self::slider( $w, 'day_radius', __( 'Day corners', 'flexo-booking' ), '--fb-day-radius', 30, $root );
		$w->end_controls_section();
	}

	/**
	 * Room cards in the results.
	 */
	public static function cards( $w ) {
		$w->start_controls_section(
			'section_style_cards',
			array(
				'label' => __( 'Room cards', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		self::color( $w, 'card_bg_color', __( 'Background', 'flexo-booking' ), '--fb-room-bg: {{VALUE}};' );
		self::color( $w, 'card_border_color', __( 'Border colour', 'flexo-booking' ), '--fb-room-border: {{VALUE}};' );
		self::slider( $w, 'card_radius', __( 'Corner radius', 'flexo-booking' ), '--fb-room-radius', 40 );
		self::slider( $w, 'card_padding', __( 'Padding', 'flexo-booking' ), '--fb-room-pad', 48 );
		$w->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .flexo-booking .fb-room',
			)
		);
		self::color( $w, 'card_price_color', __( 'Price', 'flexo-booking' ), '--fb-price-color: {{VALUE}};', '.flexo-booking', array( 'separator' => 'before' ) );
		$w->add_control(
			'card_icons_heading',
			array(
				'label'     => __( 'Icons (facts and amenities)', 'flexo-booking' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		self::color( $w, 'card_icon_color', __( 'Amenity icon colour', 'flexo-booking' ), '--fb-icon-color: {{VALUE}};' );
		self::slider( $w, 'card_icon_size', __( 'Icon size', 'flexo-booking' ), '--fb-icon-size', 40 );
		self::slider( $w, 'card_icon_gap', __( 'Space after the icon', 'flexo-booking' ), '--fb-icon-gap', 24 );
		$w->end_controls_section();
	}

	/**
	 * Choices for the amenity icons of the room cards ('' = as in Appearance).
	 */
	public static function amenity_icon_options() {
		$choices = Flexo_Booking_Appearance::choices( 'amenity_icons' );
		return array(
			''      => __( 'As in Bookings → Appearance', 'flexo-booking' ),
			'icons' => $choices[''][0],
			'tick'  => $choices['tick'][0],
			'none'  => $choices['none'][0],
		);
	}
}
