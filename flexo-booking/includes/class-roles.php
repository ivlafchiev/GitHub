<?php
/**
 * Who can do what: two roles for hotel teams and three capabilities.
 *
 * - flexo_manage_bookings: Today, calendar, bookings, manual bookings and
 *   blocks, notes, resending emails, recording payments received.
 * - flexo_manage_prices:   rooms, seasons, closed dates, rate plans,
 *   promotions, calendar sync.
 * - flexo_manage_settings: guest emails and the form's appearance.
 *
 * Administrators have everything, plus Settings, Health and Import/Export.
 * Editors keep the access they had before 1.6.0 (bookings and prices) and
 * get emails and appearance, i.e. the Hotel Manager's rights.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Roles {

	const CAPS = array( 'flexo_manage_bookings', 'flexo_manage_prices', 'flexo_manage_settings' );

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'room_caps' ), 10, 4 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), 999 );
	}

	/**
	 * @return array role => array( name, caps )
	 */
	public static function roles() {
		return array(
			'hotel_staff'   => array(
				'name' => __( 'Hotel Staff', 'flexo-booking' ),
				'caps' => array( 'read', 'flexo_manage_bookings' ),
			),
			'hotel_manager' => array(
				'name' => __( 'Hotel Manager', 'flexo-booking' ),
				'caps' => array( 'read', 'upload_files', 'flexo_manage_bookings', 'flexo_manage_prices', 'flexo_manage_settings' ),
			),
		);
	}

	/**
	 * Adds the roles and gives administrators and editors the capabilities
	 * (migration 7 and plugin activation; safe to run again).
	 */
	public static function install() {
		foreach ( self::roles() as $key => $role ) {
			$existing = get_role( $key );
			if ( ! $existing ) {
				add_role( $key, $role['name'], array_fill_keys( $role['caps'], true ) );
			} else {
				foreach ( $role['caps'] as $cap ) {
					$existing->add_cap( $cap );
				}
			}
		}
		foreach ( array( 'administrator', 'editor' ) as $key ) {
			$role = get_role( $key );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function uninstall() {
		foreach ( array_keys( self::roles() ) as $key ) {
			remove_role( $key );
		}
		foreach ( array( 'administrator', 'editor' ) as $key ) {
			$role = get_role( $key );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Rooms are edited by whoever manages prices (the room post type uses
	 * its own capabilities, e.g. edit_flexo_rooms).
	 */
	public static function room_caps( $allcaps, $caps, $args, $user ) {
		$prices = Flexo_Booking_Admin::cap( 'prices' );
		if ( empty( $allcaps[ $prices ] ) ) {
			return $allcaps;
		}
		foreach ( $caps as $cap ) {
			if ( false !== strpos( $cap, 'flexo_rooms' ) || false !== strpos( $cap, 'flexo_room' ) ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Hotel teams without content rights start on Today.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && user_can( $user, Flexo_Booking_Admin::capability() ) && ! user_can( $user, 'edit_posts' ) && ( '' === $requested || admin_url() === $requested || false !== strpos( $requested, 'wp-admin/index.php' ) ) ) {
			return admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG );
		}
		return $redirect_to;
	}

	/**
	 * Hotel teams without content rights don't need the Dashboard or Profile clutter.
	 */
	public static function trim_menu() {
		if ( current_user_can( 'edit_posts' ) || ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		remove_menu_page( 'index.php' );
	}
}
