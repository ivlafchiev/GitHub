<?php
/**
 * Plugin Name:       Flexo Booking
 * Plugin URI:        https://github.com/ivlafchiev/GitHub
 * Description:       Room & accommodation booking system for FlexoHotels websites. Works with any theme via the [flexo_booking] shortcode and ships a native Elementor widget. Settings and rooms can be exported/imported between sites.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FlexoHotels
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flexo-booking
 * Domain Path:       /languages
 * Elementor tested up to: 3.30
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

define( 'FLEXO_BOOKING_VERSION', '1.0.0' );
define( 'FLEXO_BOOKING_DB_VERSION', '1' );
define( 'FLEXO_BOOKING_FILE', __FILE__ );
define( 'FLEXO_BOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLEXO_BOOKING_URL', plugin_dir_url( __FILE__ ) );

require_once FLEXO_BOOKING_DIR . 'includes/class-install.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-settings.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-rooms.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-bookings.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-emails.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-rest.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-frontend.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-admin.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-portability.php';
require_once FLEXO_BOOKING_DIR . 'includes/elementor/class-elementor.php';

register_activation_hook( __FILE__, array( 'Flexo_Booking_Install', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'flexo-booking', false, dirname( plugin_basename( FLEXO_BOOKING_FILE ) ) . '/languages' );

		Flexo_Booking_Install::maybe_upgrade();
		Flexo_Booking_Rooms::init();
		Flexo_Booking_Emails::init();
		Flexo_Booking_Rest::init();
		Flexo_Booking_Frontend::init();
		Flexo_Booking_Elementor::init();

		if ( is_admin() ) {
			Flexo_Booking_Settings::init();
			Flexo_Booking_Admin::init();
			Flexo_Booking_Portability::init();
		}
	}
);
