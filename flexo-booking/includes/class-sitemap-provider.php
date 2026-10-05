<?php
/**
 * WordPress sitemap of the built-in Booking and Contact pages
 * (/wp-sitemap-flexobooking-pages-1.xml). Thank You is never listed.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Sitemap_Provider extends WP_Sitemaps_Provider {

	public function __construct() {
		$this->name        = 'flexobooking';
		$this->object_type = 'flexobooking';
	}

	public function get_object_subtypes() {
		return array( 'pages' => (object) array( 'name' => 'pages' ) );
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( $page_num > 1 ) {
			return array();
		}
		return array_map(
			static function ( $url ) {
				return array( 'loc' => $url );
			},
			Flexo_Booking_System_Pages::indexable_urls()
		);
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return Flexo_Booking_System_Pages::indexable_urls() ? 1 : 0;
	}
}
