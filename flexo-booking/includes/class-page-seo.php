<?php
/**
 * Search engines and built-in system pages (1.9.0): document title, meta
 * description, canonical address and robots, also through Yoast SEO and
 * Rank Math (which would otherwise treat the page as "Page not found" or
 * the home page). Thank You is never indexed.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Page_SEO {

	/**
	 * @var string Page key of this request.
	 */
	private static $key = '';

	/**
	 * Adds the hooks for the built-in page served on this request.
	 */
	public static function hook( $key ) {
		self::$key = $key;
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 30 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 30 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		// Yoast SEO.
		add_filter( 'wpseo_title', array( __CLASS__, 'title' ), 30 );
		add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'title' ), 30 );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'description' ), 30 );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ), 30 );
		add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'canonical_or_home' ), 30 );
		add_filter( 'wpseo_robots', array( __CLASS__, 'robots_text' ), 30 );
		// Rank Math.
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'title' ), 30 );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'description' ), 30 );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical' ), 30 );
		add_filter( 'rank_math/frontend/robots', array( __CLASS__, 'rank_math_robots' ), 30 );
	}

	public static function indexable() {
		if ( 'thank_you' === self::$key ) {
			return false;
		}
		$page = Flexo_Booking_System_Pages::get( self::$key );
		return ! empty( $page['indexable'] );
	}

	/**
	 * "Book your stay – Hotel name".
	 */
	public static function title() {
		$title = 'thank_you' === self::$key ? Flexo_Booking_System_Pages::text( 'thank_you', 'generic_title' ) : Flexo_Booking_System_Pages::page_title( self::$key );
		return $title . ' – ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function description() {
		return 'thank_you' === self::$key ? '' : Flexo_Booking_System_Pages::text( self::$key, 'meta_description' );
	}

	/**
	 * Canonical address: the page without search parameters (none for Thank You).
	 */
	public static function canonical() {
		return 'thank_you' === self::$key ? '' : Flexo_Booking_System_Pages::builtin_url( self::$key );
	}

	public static function canonical_or_home( $url ) {
		$canonical = self::canonical();
		return '' !== $canonical ? $canonical : $url;
	}

	public static function robots( $robots ) {
		if ( ! self::indexable() ) {
			$robots['noindex'] = true;
			if ( 'thank_you' === self::$key ) {
				$robots['nofollow'] = true;
				unset( $robots['follow'] );
			} else {
				$robots['follow'] = true;
			}
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function robots_text( $text ) {
		return self::indexable() ? $text : ( 'thank_you' === self::$key ? 'noindex, nofollow' : 'noindex, follow' );
	}

	public static function rank_math_robots( $robots ) {
		if ( self::indexable() ) {
			return $robots;
		}
		$robots           = is_array( $robots ) ? $robots : array();
		$robots['index']  = 'noindex';
		$robots['follow'] = 'thank_you' === self::$key ? 'nofollow' : 'follow';
		return $robots;
	}

	/**
	 * Description and canonical, unless an SEO plugin prints them.
	 */
	public static function head() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}
		$description = self::description();
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		$canonical = self::canonical();
		if ( '' !== $canonical && self::indexable() ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}
	}
}
