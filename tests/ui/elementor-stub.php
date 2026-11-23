<?php
/**
 * Test-only stand-in for the parts of Elementor that Flexo's widgets use
 * (1.10.0 widget controls check). Loaded only when Elementor itself is not
 * active. It records every section and control a widget registers and can
 * render the widget with chosen settings – no editor, no CSS generation (the
 * check does that the way Elementor does: {{WRAPPER}} → .elementor-N
 * .elementor-element.elementor-element-ID).
 *
 * Never part of the plugin zip.
 */

namespace Elementor {

	if ( ! class_exists( '\Elementor\Widget_Base' ) ) {

		class Controls_Manager {
			const TAB_CONTENT = 'content';
			const TAB_STYLE   = 'style';
			const TEXT        = 'text';
			const TEXTAREA    = 'textarea';
			const NUMBER      = 'number';
			const SELECT      = 'select';
			const SELECT2     = 'select2';
			const SWITCHER    = 'switcher';
			const COLOR       = 'color';
			const SLIDER      = 'slider';
			const DIMENSIONS  = 'dimensions';
			const CHOOSE      = 'choose';
			const ICONS       = 'icons';
			const HEADING     = 'heading';
			const RAW_HTML    = 'raw_html';
			const MEDIA       = 'media';
			const URL         = 'url';
			const HIDDEN      = 'hidden';
		}

		class Group_Control_Typography {
			public static function get_type() {
				return 'typography';
			}
		}

		class Group_Control_Border {
			public static function get_type() {
				return 'border';
			}
		}

		class Group_Control_Box_Shadow {
			public static function get_type() {
				return 'box-shadow';
			}
		}

		class Icons_Manager {
			public static function render_icon( $icon, $attrs = array() ) {
				echo '<i class="' . esc_attr( trim( ( isset( $icon['value'] ) ? $icon['value'] : '' ) . ' ' . ( isset( $attrs['class'] ) ? $attrs['class'] : '' ) ) ) . '" aria-hidden="true"></i>';
			}
		}

		abstract class Widget_Base {
			/** @var array Recorded controls: id => args (+ section, tab, tabs_group, control_tab). */
			public $flexo_controls = array();
			/** @var array Sections: id => args. */
			public $flexo_sections = array();
			/** @var string[] Problems found while registering (duplicate ids). */
			public $flexo_problems = array();
			/** @var array Settings used by render(). */
			public $flexo_settings = array();
			private $section = '';
			private $tab     = 'content';
			private $ctab    = '';

			public function __construct( $data = array(), $args = null ) {}

			abstract public function get_name();

			public function get_title() {
				return '';
			}

			public function get_script_depends() {
				return array();
			}

			public function get_style_depends() {
				return array();
			}

			public function start_controls_section( $id, $args = array() ) {
				$this->section = $id;
				$this->tab     = isset( $args['tab'] ) ? $args['tab'] : 'content';
				if ( isset( $this->flexo_sections[ $id ] ) ) {
					$this->flexo_problems[] = 'duplicate section ' . $id;
				}
				$this->flexo_sections[ $id ] = array_merge( $args, array( 'tab' => $this->tab ) );
			}

			public function end_controls_section() {
				$this->section = '';
			}

			public function start_controls_tabs( $id, $args = array() ) {}

			public function end_controls_tabs() {}

			public function start_controls_tab( $id, $args = array() ) {
				$this->ctab = $id;
			}

			public function end_controls_tab() {
				$this->ctab = '';
			}

			public function add_control( $id, $args, $options = array() ) {
				if ( isset( $this->flexo_controls[ $id ] ) ) {
					$this->flexo_problems[] = 'duplicate control ' . $id;
				}
				if ( '' === $this->section ) {
					$this->flexo_problems[] = 'control outside a section: ' . $id;
				}
				$this->flexo_controls[ $id ] = array_merge(
					$args,
					array(
						'id'          => $id,
						'section'     => $this->section,
						'tab'         => $this->tab,
						'control_tab' => $this->ctab,
					)
				);
			}

			public function add_responsive_control( $id, $args, $options = array() ) {
				$args['responsive'] = true;
				$this->add_control( $id, $args, $options );
			}

			public function add_group_control( $type, $args = array(), $options = array() ) {
				$args['group'] = $type;
				$this->add_control( $args['name'] . '__' . $type, $args, $options );
			}

			public function get_settings_for_display( $key = null ) {
				$settings = array();
				foreach ( $this->flexo_controls as $id => $control ) {
					if ( isset( $control['default'] ) ) {
						$settings[ $id ] = $control['default'];
					} elseif ( ! isset( $control['group'] ) ) {
						$settings[ $id ] = '';
					}
				}
				$settings = array_merge( $settings, $this->flexo_settings );
				if ( null !== $key ) {
					return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
				}
				return $settings;
			}

			public function get_settings( $key = null ) {
				return $this->get_settings_for_display( $key );
			}

			public function flexo_register() {
				$this->register_controls();
			}

			public function flexo_render() {
				ob_start();
				$this->render();
				return (string) ob_get_clean();
			}

			protected function is_dynamic_content(): bool {
				return false;
			}

			protected function register_controls() {}

			protected function render() {}
		}
	}
}

namespace Elementor\Core\Kits\Documents\Tabs {

	if ( ! class_exists( '\Elementor\Core\Kits\Documents\Tabs\Global_Colors' ) ) {
		class Global_Colors {
			const COLOR_PRIMARY   = 'globals/colors?id=primary';
			const COLOR_SECONDARY = 'globals/colors?id=secondary';
			const COLOR_TEXT      = 'globals/colors?id=text';
			const COLOR_ACCENT    = 'globals/colors?id=accent';
		}

		class Global_Typography {
			const TYPOGRAPHY_PRIMARY   = 'globals/typography?id=primary';
			const TYPOGRAPHY_SECONDARY = 'globals/typography?id=secondary';
			const TYPOGRAPHY_TEXT      = 'globals/typography?id=text';
			const TYPOGRAPHY_ACCENT    = 'globals/typography?id=accent';
		}
	}
}

namespace {

	/**
	 * The Flexo widgets, loaded against the stand-in.
	 *
	 * @return array name => widget
	 */
	function flexo_stub_widgets() {
		$dir = WP_PLUGIN_DIR . '/flexo-booking/includes/elementor/';
		// Files and widgets missing in older versions are skipped (before/after captures).
		foreach ( array( 'class-controls.php', 'class-booking-widget.php', 'class-room-widgets.php', 'class-room-box-widget.php', 'class-contact-widget.php' ) as $file ) {
			if ( file_exists( $dir . $file ) ) {
				require_once $dir . $file;
			}
		}
		$widgets = array();
		foreach ( array( 'Flexo_Booking_Elementor_Widget', 'Flexo_Booking_Room_Box_Widget', 'Flexo_Booking_Contact_Widget', 'Flexo_Booking_Forms_Tabs_Widget', 'Flexo_Booking_Room_Amenities_Widget', 'Flexo_Booking_Room_Details_Widget', 'Flexo_Booking_Room_Gallery_Widget' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$widget = new $class();
			$widget->flexo_register();
			$widgets[ $widget->get_name() ] = $widget;
		}
		return $widgets;
	}
}
