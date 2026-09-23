<?php
/**
 * Plugin Name:       Flexo Booking
 * Plugin URI:        https://github.com/ivlafchiev/GitHub
 * Description:       Room & accommodation booking system for FlexoHotels websites. Works with any theme via the [flexo_booking] shortcode and ships a native Elementor widget. Settings and rooms can be exported/imported between sites.
 * Version:           1.1.0
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

define( 'FLEXO_BOOKING_VERSION', '1.1.0' );
define( 'FLEXO_BOOKING_DB_VERSION', '2' ); // Kept for compatibility; see Flexo_Booking_Migrations::LATEST.
define( 'FLEXO_BOOKING_FILE', __FILE__ );
define( 'FLEXO_BOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLEXO_BOOKING_URL', plugin_dir_url( __FILE__ ) );

require_once FLEXO_BOOKING_DIR . 'includes/class-lock.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-schema.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-migrations.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-install.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-features.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-settings.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-money.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-dates.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-rooms.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-seasons.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-closures.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-inventory.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-pricing.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-bookings.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-emails.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-rest.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-frontend.php';
require_once FLEXO_BOOKING_DIR . 'includes/class-admin.php';
require_once FLEXO_BOOKING_DIR . 'includes/admin/class-seasons-admin.php';
require_once FLEXO_BOOKING_DIR . 'includes/admin/class-closures-admin.php';
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
			Flexo_Booking_Migrations::init();
			Flexo_Booking_Features::init();
			Flexo_Booking_Settings::init();
			Flexo_Booking_Admin::init();
			Flexo_Booking_Seasons_Admin::init();
			Flexo_Booking_Closures_Admin::init();
			Flexo_Booking_Portability::init();
		}
	}
);
