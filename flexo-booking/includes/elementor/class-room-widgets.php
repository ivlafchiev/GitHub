<?php
/**
 * Elementor widgets for room pages (category "FlexoHotels"): Room
 * amenities, Room details and Room gallery. Each shows the current room
 * (room page, Theme Builder template, Loop item) or a room picked in its
 * settings, and inherits the site's fonts and colours unless styled.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Icons_Manager;
use Elementor\Widget_Base;

/**
 * Shared parts of the room widgets.
 */
abstract class Flexo_Booking_Room_Widget extends Widget_Base {

	public function get_categories() {
		return array( 'flexo-hotels' );
	}

	public function get_style_depends(): array {
		return array( Flexo_Booking_Room_Render::STYLE );
	}

	/**
	 * Room content changes per room: never cache the output.
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	protected function room_section() {
		$this->start_controls_section(
			'section_room',
			array(
				'label' => __( 'Room', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'room',
			array(
				'label'       => __( 'Room', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => '',
				'options'     => Flexo_Booking_Elementor::room_options( __( 'Current room (automatic)', 'flexo-booking' ) ),
				'description' => __( 'Automatic: the room of the page – in a room template or a Loop item, each room shows its own.', 'flexo-booking' ),
			)
		);
	}

	/**
	 * @return array|null
	 */
	protected function current_room() {
		return Flexo_Booking_Room_Content::room( Flexo_Booking_Room_Content::current_id( (string) $this->get_settings_for_display( 'room' ) ) );
	}

	/**
	 * In the editor, say why nothing shows.
	 */
	protected function editor_note( $text ) {
		if ( Flexo_Booking_Elementor::is_editing() ) {
			echo '<div class="flexo-room-note">' . esc_html( $text ) . '</div>';
		}
	}

	protected function icon_html( $icon ) {
		if ( empty( $icon['value'] ) ) {
			return '';
		}
		ob_start();
		Icons_Manager::render_icon(
			$icon,
			array(
				'aria-hidden' => 'true',
				'class'       => 'flexo-room-icon',
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Layout controls shared by the icon lists.
	 */
	protected function list_layout_controls( $default_layout = 'list' ) {
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => $default_layout,
				'options' => array(
					'list'   => __( 'List', 'flexo-booking' ),
					'inline' => __( 'In a row', 'flexo-booking' ),
					'grid'   => __( 'Columns', 'flexo-booking' ),
				),
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'flexo-booking' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '2',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'condition'      => array( 'layout' => 'grid' ),
				'selectors'      => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-columns: {{VALUE}};' ),
			)
		);
	}

	/**
	 * Style tab: list items, icon and text.
	 */
	protected function list_style_controls() {
		$this->start_controls_section(
			'section_style_list',
			array(
				'label' => __( 'List', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'space_between',
			array(
				'label'      => __( 'Space between', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'align',
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
				),
				'selectors' => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-align: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'divider',
			array(
				'label'        => __( 'Divider', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'prefix_class' => 'flexo-room-divider-',
			)
		);
		$this->add_control(
			'divider_color',
			array(
				'label'     => __( 'Divider colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'condition' => array( 'divider' => 'yes' ),
				'selectors' => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-divider: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_icon',
			array(
				'label' => __( 'Icon', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'icon_color',
			array(
				'label'     => __( 'Colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY ),
				'selectors' => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-icon-color: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => __( 'Size', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 80 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-icon-size: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'icon_gap',
			array(
				'label'      => __( 'Space after the icon', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-list' => '--flexo-icon-gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'original_colours',
			array(
				'label'        => __( 'Uploaded icons keep their own colours', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_text',
			array(
				'label' => __( 'Text', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_control(
			'text_color',
			array(
				'label'     => __( 'Colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_TEXT ),
				'selectors' => array( '{{WRAPPER}} .flexo-room-list__text' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'text_typography',
				'global'   => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Typography::TYPOGRAPHY_TEXT ),
				'selector' => '{{WRAPPER}} .flexo-room-list__text',
			)
		);
		$this->end_controls_section();
	}
}

/**
 * Room amenities: the room's amenities, each with its own icon.
 */
class Flexo_Booking_Room_Amenities_Widget extends Flexo_Booking_Room_Widget {

	public function get_name() {
		return 'flexo-room-amenities';
	}

	public function get_title() {
		return __( 'Room amenities', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-bullet-list';
	}

	public function get_keywords() {
		return array( 'room', 'amenities', 'facilities', 'icons', 'hotel', 'flexo' );
	}

	protected function register_controls() {
		$this->room_section();
		$this->list_layout_controls( 'grid' );
		$this->add_control(
			'limit',
			array(
				'label'       => __( 'How many', 'flexo-booking' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 60,
				'default'     => 0,
				'description' => __( '0 = all, in the order set in the room.', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'show_icons',
			array(
				'label'        => __( 'Icons', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'same_icon',
			array(
				'label'       => __( 'Same icon for all (optional)', 'flexo-booking' ),
				'type'        => Controls_Manager::ICONS,
				'default'     => array(
					'value'   => '',
					'library' => '',
				),
				'skin'        => 'inline',
				'description' => __( 'Leave empty to use each amenity\'s own icon.', 'flexo-booking' ),
				'condition'   => array( 'show_icons' => 'yes' ),
			)
		);
		$this->end_controls_section();
		$this->list_style_controls();
	}

	protected function render() {
		$room = $this->current_room();
		if ( ! $room ) {
			$this->editor_note( __( 'Room amenities: no room here. Use it in your room template, or choose a room.', 'flexo-booking' ) );
			return;
		}
		$s    = $this->get_settings_for_display();
		$html = Flexo_Booking_Room_Render::amenities(
			$room['amenity_list'],
			array(
				'layout'           => $s['layout'],
				'icons'            => 'yes' === $s['show_icons'],
				'icon_html'        => $this->icon_html( $s['same_icon'] ),
				'original_colours' => 'yes' === $s['original_colours'],
				'limit'            => (int) $s['limit'],
			)
		);
		if ( '' === $html ) {
			$this->editor_note( __( 'This room has no amenities yet – add them in Rooms & prices.', 'flexo-booking' ) );
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
	}
}

/**
 * Room details: facts (size, guests, beds, view, type) and "More details".
 */
class Flexo_Booking_Room_Details_Widget extends Flexo_Booking_Room_Widget {

	public function get_name() {
		return 'flexo-room-details';
	}

	public function get_title() {
		return __( 'Room details', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-post-info';
	}

	public function get_keywords() {
		return array( 'room', 'size', 'guests', 'beds', 'view', 'details', 'facts', 'hotel', 'flexo' );
	}

	private static function facts() {
		return array(
			'size'   => __( 'Size', 'flexo-booking' ),
			'guests' => __( 'Guests', 'flexo-booking' ),
			'beds'   => __( 'Beds', 'flexo-booking' ),
			'view'   => __( 'View', 'flexo-booking' ),
			'type'   => __( 'Room type', 'flexo-booking' ),
		);
	}

	protected function register_controls() {
		$this->room_section();
		$this->add_control(
			'facts',
			array(
				'label'       => __( 'Facts', 'flexo-booking' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::facts(),
				'default'     => array( 'size', 'guests', 'beds', 'view' ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'more',
			array(
				'label'        => __( 'Add the room\'s "More details"', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'show',
			array(
				'label'   => __( 'Text', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'both',
				'options' => array(
					'both'    => __( 'Name: value', 'flexo-booking' ),
					'stacked' => __( 'Name above value', 'flexo-booking' ),
					'value'   => __( 'Value only', 'flexo-booking' ),
				),
			)
		);
		$this->add_control(
			'guests_format',
			array(
				'label'   => __( 'Guests as', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'summary',
				'options' => array(
					'summary' => __( 'Up to 4 guests (max. 2 adults)', 'flexo-booking' ),
					'guests'  => __( '4 guests', 'flexo-booking' ),
				),
			)
		);
		$this->list_layout_controls( 'list' );
		$this->add_control(
			'show_icons',
			array(
				'label'        => __( 'Icons', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_icons',
			array(
				'label'     => __( 'Your own icons (optional)', 'flexo-booking' ),
				'condition' => array( 'show_icons' => 'yes' ),
			)
		);
		foreach ( self::facts() as $key => $label ) {
			$this->add_control(
				'icon_' . $key,
				array(
					'label'   => $label,
					'type'    => Controls_Manager::ICONS,
					'skin'    => 'inline',
					'default' => array(
						'value'   => '',
						'library' => '',
					),
				)
			);
		}
		$this->end_controls_section();

		$this->list_style_controls();

		$this->start_controls_section(
			'section_style_label',
			array(
				'label'     => __( 'Names', 'flexo-booking' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show!' => 'value' ),
			)
		);
		$this->add_control(
			'label_color',
			array(
				'label'     => __( 'Colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .flexo-room-details__label' => 'color: {{VALUE}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .flexo-room-details__label',
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$room = $this->current_room();
		if ( ! $room ) {
			$this->editor_note( __( 'Room details: no room here. Use it in your room template, or choose a room.', 'flexo-booking' ) );
			return;
		}
		$s     = $this->get_settings_for_display();
		$icons = array();
		foreach ( array_keys( self::facts() ) as $key ) {
			$html = isset( $s[ 'icon_' . $key ] ) ? $this->icon_html( $s[ 'icon_' . $key ] ) : '';
			if ( '' !== $html ) {
				$icons[ $key ] = $html;
			}
		}
		$html = Flexo_Booking_Room_Render::details(
			$room,
			array(
				'facts'            => array_values( array_intersect( (array) $s['facts'], array_keys( self::facts() ) ) ),
				'more'             => 'yes' === $s['more'],
				'layout'           => $s['layout'],
				'show'             => $s['show'],
				'guests_format'    => $s['guests_format'],
				'icons'            => 'yes' === $s['show_icons'],
				'fact_icons'       => $icons,
				'original_colours' => 'yes' === $s['original_colours'],
			)
		);
		if ( '' === $html ) {
			$this->editor_note( __( 'Nothing to show yet – fill in the room\'s facts in Rooms & prices.', 'flexo-booking' ) );
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
	}
}

/**
 * Room gallery: the room's photos as a carousel or a grid, with a lightbox.
 */
class Flexo_Booking_Room_Gallery_Widget extends Flexo_Booking_Room_Widget {

	public function get_name() {
		return 'flexo-room-gallery';
	}

	public function get_title() {
		return __( 'Room gallery', 'flexo-booking' );
	}

	public function get_icon() {
		return 'eicon-media-carousel';
	}

	public function get_keywords() {
		return array( 'room', 'gallery', 'photos', 'carousel', 'slider', 'images', 'hotel', 'flexo' );
	}

	public function get_script_depends(): array {
		return array( Flexo_Booking_Room_Render::SCRIPT );
	}

	protected function register_controls() {
		$this->room_section();
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'carousel',
				'options' => array(
					'carousel' => __( 'Carousel', 'flexo-booking' ),
					'grid'     => __( 'Grid', 'flexo-booking' ),
				),
			)
		);
		$this->add_responsive_control(
			'per_view',
			array(
				'label'          => __( 'Photos side by side', 'flexo-booking' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'selectors'      => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-per-view: {{VALUE}};' ),
			)
		);
		$this->add_responsive_control(
			'height',
			array(
				'label'      => __( 'Photo height', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array(
					'px' => array( 'min' => 80, 'max' => 900 ),
					'vh' => array( 'min' => 10, 'max' => 100 ),
				),
				'default'    => array(
					'size' => 260,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-height: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_control(
			'with_main',
			array(
				'label'        => __( 'Start with the main photo', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'limit',
			array(
				'label'       => __( 'How many photos', 'flexo-booking' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 100,
				'default'     => 0,
				'description' => __( '0 = all.', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'image_size',
			array(
				'label'   => __( 'Image size', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'large',
				'options' => array(
					'medium'       => __( 'Medium', 'flexo-booking' ),
					'medium_large' => __( 'Medium large', 'flexo-booking' ),
					'large'        => __( 'Large', 'flexo-booking' ),
					'full'         => __( 'Full', 'flexo-booking' ),
				),
			)
		);
		$this->add_control(
			'lightbox',
			array(
				'label'        => __( 'Open photos in a lightbox', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
		$this->add_control(
			'arrows',
			array(
				'label'        => __( 'Arrows', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'layout' => 'carousel' ),
			)
		);
		$this->add_control(
			'dots',
			array(
				'label'        => __( 'Dots', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'layout' => 'carousel' ),
			)
		);
		$this->add_control(
			'autoplay',
			array(
				'label'       => __( 'Autoplay every (seconds)', 'flexo-booking' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 30,
				'default'     => 0,
				'description' => __( '0 = off. Stops when a guest touches the photos and for visitors who prefer less motion.', 'flexo-booking' ),
				'condition'   => array( 'layout' => 'carousel' ),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_photos',
			array(
				'label' => __( 'Photos', 'flexo-booking' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_responsive_control(
			'gap',
			array(
				'label'      => __( 'Space between', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'default'    => array(
					'size' => 16,
					'unit' => 'px',
				),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-gap: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control(
			'radius',
			array(
				'label'      => __( 'Corner radius', 'flexo-booking' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-radius: {{SIZE}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'photo_border',
				'selector' => '{{WRAPPER}} .flexo-room-gallery__img',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_nav',
			array(
				'label'     => __( 'Arrows and dots', 'flexo-booking' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'carousel' ),
			)
		);
		$this->add_control(
			'arrow_color',
			array(
				'label'     => __( 'Arrow colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-arrow-color: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'arrow_bg',
			array(
				'label'     => __( 'Arrow background', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-arrow-bg: {{VALUE}};' ),
			)
		);
		$this->add_control(
			'dot_color',
			array(
				'label'     => __( 'Dot colour', 'flexo-booking' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => array( 'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY ),
				'selectors' => array( '{{WRAPPER}} .flexo-room-gallery' => '--flexo-dot: {{VALUE}};' ),
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		$room = $this->current_room();
		if ( ! $room ) {
			$this->editor_note( __( 'Room gallery: no room here. Use it in your room template, or choose a room.', 'flexo-booking' ) );
			return;
		}
		$s = $this->get_settings_for_display();
		echo Flexo_Booking_Room_Render::gallery( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the renderer.
			$room,
			array(
				'with_main' => 'yes' === $s['with_main'],
				'layout'    => $s['layout'],
				'limit'     => (int) $s['limit'],
				'size'      => $s['image_size'],
				'lightbox'  => 'yes' === $s['lightbox'] ? 'elementor' : 'none',
				'arrows'    => 'yes' === $s['arrows'],
				'dots'      => 'yes' === $s['dots'],
				'autoplay'  => (int) $s['autoplay'] * 1000,
			)
		);
	}
}
