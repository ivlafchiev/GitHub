<?php
/**
 * Room pages for search engines.
 *
 * - Title and description from the room's "Search engines" card, only
 *   when Yoast SEO and Rank Math are not active (they have their own).
 * - Structured data (schema.org): the room as a HotelRoom in the hotel,
 *   with an Offer for the "from" price. Printed on its own without an SEO
 *   plugin; with Yoast SEO or Rank Math it is added to their schema graph,
 *   so there is one graph and nothing is duplicated.
 * - Rooms not shown on the website are left out of Yoast's and Rank Math's
 *   sitemaps too (WordPress's own sitemap: Flexo_Booking_Room_Pages).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_SEO {

	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 2 );
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast_graph' ), 20 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math_graph' ), 99 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'sitemap_exclude' ) );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'rank_math_sitemap_entry' ), 10, 3 );
	}

	/**
	 * Yoast SEO or Rank Math writes titles, descriptions and schema.
	 */
	public static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * The room shown on this page, if it may be shown to search engines.
	 */
	private static function current_room() {
		if ( ! is_singular( Flexo_Booking_Rooms::POST_TYPE ) ) {
			return null;
		}
		$id = (int) get_queried_object_id();
		if ( Flexo_Booking_Room_Content::is_hidden( $id ) || Flexo_Booking_Room_Content::is_demo( $id ) ) {
			return null;
		}
		return Flexo_Booking_Room_Content::room( $id );
	}

	public static function title( $title ) {
		if ( self::seo_plugin_active() || ! is_singular( Flexo_Booking_Rooms::POST_TYPE ) ) {
			return $title;
		}
		$own = trim( (string) get_post_meta( get_queried_object_id(), Flexo_Booking_Room_Content::SEO_TITLE, true ) );
		return '' !== $own ? $own : $title;
	}

	/**
	 * Description, social preview tags and structured data (without an SEO plugin).
	 */
	public static function head() {
		if ( self::seo_plugin_active() ) {
			return;
		}
		$room = self::current_room();
		if ( ! $room ) {
			return;
		}
		$description = self::description( $room );
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		echo '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => self::graph( $room ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		) . '</script>' . "\n";
	}

	private static function description( array $room ) {
		$own = trim( (string) get_post_meta( $room['id'], Flexo_Booking_Room_Content::SEO_DESCRIPTION, true ) );
		$text = '' !== $own ? $own : wp_strip_all_tags( $room['excerpt'] );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 320 ) : substr( $text, 0, 320 );
	}

	/**
	 * schema.org nodes: the room, the hotel it is in and the offer.
	 *
	 * @param array $room Room view.
	 * @return array[]
	 */
	public static function graph( array $room ) {
		$url      = $room['url'];
		$hotel_id = home_url( '/#flexo-hotel' );
		$node     = array(
			'@type'            => 'HotelRoom',
			'@id'              => $url . '#room',
			'name'             => $room['title'],
			'url'              => $url,
			'containedInPlace' => array( '@id' => $hotel_id ),
			'occupancy'        => array(
				'@type'    => 'QuantitativeValue',
				'maxValue' => (int) $room['capacity'],
				'unitCode' => 'C62',
			),
		);
		$description = self::description( $room );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}
		$images = array();
		foreach ( array_slice( $room['gallery'], 0, 6 ) as $id ) {
			$image = wp_get_attachment_image_url( $id, 'full' );
			if ( $image ) {
				$images[] = $image;
			}
		}
		if ( $images ) {
			$node['image'] = $images;
		}
		if ( '' !== $room['beds'] ) {
			$node['bed'] = $room['beds'];
		}
		if ( $room['size'] > 0 ) {
			$node['floorSize'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (int) $room['size'],
				'unitCode' => 'MTK',
			);
		}
		if ( $room['amenity_list'] ) {
			$node['amenityFeature'] = array();
			foreach ( $room['amenity_list'] as $amenity ) {
				$node['amenityFeature'][] = array(
					'@type' => 'LocationFeatureSpecification',
					'name'  => $amenity['label'],
					'value' => true,
				);
			}
		}

		$settings = Flexo_Booking_Settings::all();
		$hotel    = array(
			'@type' => 'Hotel',
			'@id'   => $hotel_id,
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
		if ( '' !== trim( (string) $settings['hotel_address'] ) ) {
			$hotel['address'] = trim( preg_replace( '/\s*\n\s*/', ', ', $settings['hotel_address'] ) );
		}
		if ( '' !== trim( (string) $settings['hotel_phone'] ) ) {
			$hotel['telephone'] = trim( $settings['hotel_phone'] );
		}
		$nodes = array( $node, $hotel );

		$price = Flexo_Booking_Room_Prices::shown() ? Flexo_Booking_Room_Prices::get( $room['id'] ) : null;
		if ( $price ) {
			$nodes[] = array(
				'@type'              => 'Offer',
				'@id'                => $url . '#offer',
				'url'                => $room['booking_url'],
				'itemOffered'        => array( '@id' => $url . '#room' ),
				'offeredBy'          => array( '@id' => $hotel_id ),
				'availability'       => 'https://schema.org/InStock',
				'priceSpecification' => array(
					'@type'         => 'UnitPriceSpecification',
					'price'         => round( (float) $price['amount'], 2 ),
					'priceCurrency' => Flexo_Booking_Money::currency(),
					'unitText'      => 'NIGHT',
					'minPrice'      => round( (float) $price['amount'], 2 ),
				),
			);
		}
		return apply_filters( 'flexo_booking_room_schema', $nodes, $room );
	}

	/**
	 * Yoast SEO: add the room to Yoast's graph.
	 */
	public static function yoast_graph( $graph ) {
		$room = self::current_room();
		if ( ! $room || ! is_array( $graph ) || self::has_type( $graph, 'HotelRoom' ) ) {
			return $graph;
		}
		return array_merge( $graph, self::graph( $room ) );
	}

	/**
	 * Rank Math: add the room, unless a HotelRoom or Product schema is
	 * already set on this room in Rank Math.
	 */
	public static function rank_math_graph( $data ) {
		$room = self::current_room();
		if ( ! $room || ! is_array( $data ) || self::has_type( $data, 'HotelRoom' ) || self::has_type( $data, 'Product' ) ) {
			return $data;
		}
		foreach ( self::graph( $room ) as $i => $node ) {
			$data[ 'flexo_room_' . $i ] = $node;
		}
		return $data;
	}

	/**
	 * Whether a schema graph already has a node of this type.
	 */
	private static function has_type( array $nodes, $type ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
				continue;
			}
			if ( in_array( $type, (array) $node['@type'], true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function sitemap_exclude( $ids ) {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), Flexo_Booking_Room_Pages::not_public_ids() ) ) );
	}

	/**
	 * Rank Math: drop sitemap entries of rooms not shown on the website.
	 */
	public static function rank_math_sitemap_entry( $url, $type = '', $object = null ) {
		if ( 'post' === $type && $object instanceof WP_Post && Flexo_Booking_Rooms::POST_TYPE === $object->post_type && in_array( (int) $object->ID, Flexo_Booking_Room_Pages::not_public_ids(), true ) ) {
			return false;
		}
		return $url;
	}
}
