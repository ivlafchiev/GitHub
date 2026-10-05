<?php
/**
 * Keeps private pages out of page caches (1.9.0): the Thank You page, the
 * confirmation and the guest's booking page must never be stored and shown
 * to another visitor.
 *
 * Uses what each cache offers: the DONOTCACHEPAGE constant (WP Super
 * Cache, W3 Total Cache, WP Rocket, most others), LiteSpeed Cache's
 * action, WP Rocket's excluded addresses, and no-store headers for
 * browsers, proxies and CDNs. Health lists caches it can't instruct.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Page_Cache {

	/**
	 * @var string Why the current response is not cached ('' when it may be).
	 */
	private static $reason = '';

	public static function init() {
		add_filter( 'rocket_cache_reject_uri', array( __CLASS__, 'rocket_reject' ) );
	}

	/**
	 * Marks the current response as private.
	 *
	 * @param string $reason Shown in debugging headers of some caches.
	 */
	public static function protect( $reason = 'private' ) {
		self::$reason = (string) $reason;
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true );
			}
		}
		// LiteSpeed Cache.
		do_action( 'litespeed_control_set_nocache', 'flexo-booking: ' . self::$reason );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private' );
			// Cloudflare and other CDNs that read their own header.
			header( 'CDN-Cache-Control: no-store' );
		}
		add_filter( 'nocache_headers', array( __CLASS__, 'private_headers' ) );
	}

	public static function private_headers( $headers ) {
		$headers['Cache-Control'] = 'no-cache, no-store, must-revalidate, max-age=0, private';
		return $headers;
	}

	/**
	 * Whether this response was marked private.
	 */
	public static function is_protected() {
		return '' !== self::$reason;
	}

	/**
	 * Addresses page caches should never store (paths, as regular
	 * expressions for WP Rocket).
	 *
	 * @return string[]
	 */
	public static function private_paths() {
		$paths = array();
		$url   = Flexo_Booking_System_Pages::url( 'thank_you' );
		if ( '' !== $url ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = $path;
			}
		}
		foreach ( array( 'booking', 'contact' ) as $key ) {
			if ( Flexo_Booking_System_Pages::supported( $key ) && ! Flexo_Booking_System_Pages::cacheable( $key ) ) {
				$url  = Flexo_Booking_System_Pages::url( $key );
				$path = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
				if ( '' !== $path && '/' !== $path ) {
					$paths[] = $path;
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * WP Rocket: never cache the Thank You page (in every language).
	 */
	public static function rocket_reject( $uris ) {
		$uris = is_array( $uris ) ? $uris : array();
		foreach ( self::private_paths() as $path ) {
			$uris[] = '(.*)' . preg_quote( untrailingslashit( $path ), '#' ) . '/?(.*)';
		}
		return $uris;
	}

	/**
	 * Page caches that are active, and whether the plugin can keep private
	 * pages out of them by itself.
	 *
	 * @return array[] { name, automatic }
	 */
	public static function detected() {
		$found = array();
		if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ) ) {
			$found[] = array( 'name' => 'LiteSpeed Cache', 'automatic' => true );
		}
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			$found[] = array( 'name' => 'WP Rocket', 'automatic' => true );
		}
		if ( defined( 'W3TC' ) ) {
			$found[] = array( 'name' => 'W3 Total Cache', 'automatic' => true );
		}
		if ( defined( 'WPCACHEHOME' ) || function_exists( 'wp_cache_phase2' ) ) {
			$found[] = array( 'name' => 'WP Super Cache', 'automatic' => true );
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			$found[] = array( 'name' => 'WP Fastest Cache', 'automatic' => false );
		}
		if ( defined( 'BREEZE_VERSION' ) ) {
			$found[] = array( 'name' => 'Breeze', 'automatic' => false );
		}
		if ( class_exists( 'SiteGround_Optimizer\Loader\Loader' ) || defined( '\SiteGround_Optimizer\VERSION' ) ) {
			$found[] = array( 'name' => 'SiteGround Speed Optimizer', 'automatic' => false );
		}
		if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) ) {
			$found[] = array( 'name' => 'Cloudflare', 'automatic' => false );
		}
		return apply_filters( 'flexo_booking_page_caches', $found );
	}

	/**
	 * Rules to add by hand in a cache that can't be told automatically.
	 *
	 * @return string[]
	 */
	public static function manual_rules() {
		$rules = array();
		foreach ( self::private_paths() as $path ) {
			$rules[] = $path . '*';
		}
		$rules[] = '*fb_key=*';
		$rules[] = '*fb_t=*';
		$rules[] = '*fb_manage=*';
		$rules[] = '*fb_payment=*';
		return $rules;
	}
}
