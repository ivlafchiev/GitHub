<?php
/**
 * Room content shown on the website: gallery, view, amenities with icons,
 * "more details" rows, room types, show on website, demo rooms and search
 * engine texts – plus one view of a room that every room page, Elementor
 * tag, widget and shortcode reads.
 *
 * The booking data (prices, guests, identical rooms…) stays in
 * Flexo_Booking_Rooms; this class only adds what guests see.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Content {

	const TAXONOMY        = 'flexo_room_type';
	const GALLERY         = '_flexo_gallery';
	const VIEW            = '_flexo_view';
	const AMENITY_ITEMS   = '_flexo_amenity_items';
	const DETAILS         = '_flexo_details';
	const HIDDEN          = '_flexo_hidden';
	const DEMO            = '_flexo_demo';
	const SEO_TITLE       = '_flexo_seo_title';
	const SEO_DESCRIPTION = '_flexo_seo_description';

	const MAX_AMENITIES = 60;
	const MAX_DETAILS   = 30;
	const MAX_GALLERY   = 100;

	/**
	 * @var array Room views built in this request, by ID.
	 */
	private static $cache = array();

	/**
	 * @var array|null IDs of hidden and demo rooms.
	 */
	private static $flags = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 9 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'meta_changed' ), 10, 3 );
		}
		add_action( 'clean_post_cache', array( __CLASS__, 'forget' ) );
	}

	/**
	 * Room types (Double room, Suite, Apartment…): one or more per room,
	 * usable in Elementor Pro's Loop Grid query and Taxonomy Filter.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			Flexo_Booking_Rooms::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Room types', 'flexo-booking' ),
					'singular_name' => __( 'Room type', 'flexo-booking' ),
					'search_items'  => __( 'Search room types', 'flexo-booking' ),
					'all_items'     => __( 'All room types', 'flexo-booking' ),
					'edit_item'     => __( 'Edit room type', 'flexo-booking' ),
					'update_item'   => __( 'Save room type', 'flexo-booking' ),
					'add_new_item'  => __( 'Add a room type', 'flexo-booking' ),
					'new_item_name' => __( 'New room type', 'flexo-booking' ),
					'not_found'     => __( 'No room types yet.', 'flexo-booking' ),
					'back_to_items' => __( '← Back to room types', 'flexo-booking' ),
				),
				'description'        => __( 'For example Double room, Suite, Apartment or Family room.', 'flexo-booking' ),
				'public'             => true,
				'publicly_queryable' => false,
				'hierarchical'       => true,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => true,
				'show_in_rest'       => false,
				'show_admin_column'  => true,
				'show_tagcloud'      => false,
				'rewrite'            => false,
				'query_var'          => false,
				// Picked in the room editor's "Room facts" card.
				'meta_box_cb'        => false,
				'capabilities'       => array(
					'manage_terms' => 'edit_flexo_rooms',
					'edit_terms'   => 'edit_flexo_rooms',
					'delete_terms' => 'edit_flexo_rooms',
					'assign_terms' => 'edit_flexo_rooms',
				),
			)
		);
	}

	/**
	 * Ready-made amenities: key => array( label, icon ). The owner adds them
	 * with one click and can add their own.
	 */
	public static function amenity_presets() {
		$presets = array(
			'wifi'             => array( __( 'Free Wi-Fi', 'flexo-booking' ), 'wifi' ),
			'air_conditioning' => array( __( 'Air conditioning', 'flexo-booking' ), 'air_conditioning' ),
			'private_bathroom' => array( __( 'Private bathroom', 'flexo-booking' ), 'shower' ),
			'balcony'          => array( __( 'Balcony', 'flexo-booking' ), 'fence' ),
			'terrace'          => array( __( 'Terrace', 'flexo-booking' ), 'sun' ),
			'sea_view'         => array( __( 'Sea view', 'flexo-booking' ), 'waves' ),
			'mountain_view'    => array( __( 'Mountain view', 'flexo-booking' ), 'mountain' ),
			'garden_view'      => array( __( 'Garden view', 'flexo-booking' ), 'trees' ),
			'kitchen'          => array( __( 'Kitchen', 'flexo-booking' ), 'kitchen' ),
			'kitchenette'      => array( __( 'Kitchenette', 'flexo-booking' ), 'microwave' ),
			'tv'               => array( __( 'TV', 'flexo-booking' ), 'tv' ),
			'minibar'          => array( __( 'Minibar', 'flexo-booking' ), 'wine' ),
			'fridge'           => array( __( 'Fridge', 'flexo-booking' ), 'fridge' ),
			'coffee'           => array( __( 'Coffee / tea maker', 'flexo-booking' ), 'coffee' ),
			'safe'             => array( __( 'Safe', 'flexo-booking' ), 'safe' ),
			'bathtub'          => array( __( 'Bathtub', 'flexo-booking' ), 'bath' ),
			'heating'          => array( __( 'Heating', 'flexo-booking' ), 'heater' ),
			'washing_machine'  => array( __( 'Washing machine', 'flexo-booking' ), 'washing_machine' ),
			'parking'          => array( __( 'Free parking', 'flexo-booking' ), 'parking' ),
			'pets'             => array( __( 'Pets allowed', 'flexo-booking' ), 'paw' ),
			'accessible'       => array( __( 'Step-free access', 'flexo-booking' ), 'accessibility' ),
			'non_smoking'      => array( __( 'Non-smoking', 'flexo-booking' ), 'no_smoking' ),
			// 1.8.0.
			'city_view'        => array( __( 'City view', 'flexo-booking' ), 'building' ),
			'pool_view'        => array( __( 'Pool view', 'flexo-booking' ), 'pool' ),
			'private_pool'     => array( __( 'Private pool', 'flexo-booking' ), 'pool' ),
			'hot_tub'          => array( __( 'Hot tub', 'flexo-booking' ), 'bubbles' ),
			'garden'           => array( __( 'Private garden', 'flexo-booking' ), 'flower' ),
			'fireplace'        => array( __( 'Fireplace', 'flexo-booking' ), 'flame' ),
			'hairdryer'        => array( __( 'Hairdryer', 'flexo-booking' ), 'hairdryer' ),
			'toiletries'       => array( __( 'Free toiletries', 'flexo-booking' ), 'toiletries' ),
			'iron'             => array( __( 'Iron', 'flexo-booking' ), 'shirt' ),
			'desk'             => array( __( 'Work desk', 'flexo-booking' ), 'desk' ),
			'sofa'             => array( __( 'Sofa', 'flexo-booking' ), 'sofa' ),
			'dining_area'      => array( __( 'Dining area', 'flexo-booking' ), 'utensils' ),
			'streaming'        => array( __( 'Streaming services', 'flexo-booking' ), 'monitor' ),
			'soundproof'       => array( __( 'Soundproofing', 'flexo-booking' ), 'quiet' ),
			'blackout'         => array( __( 'Blackout curtains', 'flexo-booking' ), 'blinds' ),
			'baby_cot'         => array( __( 'Baby cot on request', 'flexo-booking' ), 'baby' ),
			'room_service'     => array( __( 'Room service', 'flexo-booking' ), 'room_service' ),
			'daily_cleaning'   => array( __( 'Daily cleaning', 'flexo-booking' ), 'cleaning' ),
			'private_entrance' => array( __( 'Private entrance', 'flexo-booking' ), 'door' ),
			'lift'             => array( __( 'Lift', 'flexo-booking' ), 'lift' ),
		);
		/**
		 * Ready-made amenities: key => array( label, bundled icon name ).
		 */
		return apply_filters( 'flexo_booking_amenity_presets', $presets );
	}

	/**
	 * Words that suggest an icon for an amenity the owner types in
	 * (English and Bulgarian), most specific first: word => icon.
	 */
	public static function icon_keywords() {
		return apply_filters(
			'flexo_booking_amenity_icon_keywords',
			array(
				'hot tub'      => 'bubbles',
				'jacuzzi'      => 'bubbles',
				'джакузи'      => 'bubbles',
				'room service' => 'room_service',
				'рум сървис'   => 'room_service',
				'smart tv'     => 'tv',
				'streaming'    => 'monitor',
				'netflix'      => 'monitor',
				'wi-fi'        => 'wifi',
				'wifi'         => 'wifi',
				'wi fi'        => 'wifi',
				'internet'     => 'wifi',
				'интернет'     => 'wifi',
				'уай-фай'      => 'wifi',
				'air con'      => 'air_conditioning',
				'air-con'      => 'air_conditioning',
				'климати'      => 'air_conditioning',
				'shower'       => 'shower',
				'душ'          => 'shower',
				'bathtub'      => 'bath',
				'bath tub'     => 'bath',
				'вана'         => 'bath',
				'terrace'      => 'sun',
				'тераса'       => 'sun',
				'balcon'       => 'fence',
				'balkon'       => 'fence',
				'балкон'       => 'fence',
				'pool'         => 'pool',
				'басейн'       => 'pool',
				'sea'          => 'waves',
				'море'         => 'waves',
				'морск'        => 'waves',
				'beach'        => 'umbrella',
				'плаж'         => 'umbrella',
				'mountain'     => 'mountain',
				'планин'       => 'mountain',
				'garden'       => 'trees',
				'градин'       => 'trees',
				'kitchenette'  => 'microwave',
				'microwave'    => 'microwave',
				'микровълн'    => 'microwave',
				'kitchen'      => 'kitchen',
				'кухн'         => 'kitchen',
				'fridge'       => 'fridge',
				'refrigerator' => 'fridge',
				'хладилник'    => 'fridge',
				'minibar'      => 'wine',
				'минибар'      => 'wine',
				'coffee'       => 'coffee',
				'tea'          => 'coffee',
				'кафе'         => 'coffee',
				'чай'          => 'coffee',
				'breakfast'    => 'croissant',
				'закуска'      => 'croissant',
				'tv'           => 'tv',
				'телевизор'    => 'tv',
				'safe'         => 'safe',
				'сейф'         => 'safe',
				'parking'      => 'parking',
				'паркинг'      => 'parking',
				'pet'          => 'paw',
				'любимц'       => 'paw',
				'non-smoking'  => 'no_smoking',
				'smoking'      => 'no_smoking',
				'пушене'       => 'no_smoking',
				'wheelchair'   => 'accessibility',
				'step-free'    => 'accessibility',
				'достъп'       => 'accessibility',
				'elevator'     => 'lift',
				'lift'         => 'lift',
				'асансьор'     => 'lift',
				'washing'      => 'washing_machine',
				'перална'      => 'washing_machine',
				'hairdryer'    => 'hairdryer',
				'hair dryer'   => 'hairdryer',
				'сешоар'       => 'hairdryer',
				'toiletr'      => 'toiletries',
				'козметик'     => 'toiletries',
				'iron'         => 'shirt',
				'ютия'         => 'shirt',
				'housekeeping' => 'cleaning',
				'cleaning'     => 'cleaning',
				'почистване'   => 'cleaning',
				'heating'      => 'heater',
				'отопление'    => 'heater',
				'fireplace'    => 'flame',
				'камина'       => 'flame',
				'barbecue'     => 'flame',
				'bbq'          => 'flame',
				'барбекю'      => 'flame',
				'soundproof'   => 'quiet',
				'шумоизол'     => 'quiet',
				'blackout'     => 'blinds',
				'curtain'      => 'blinds',
				'завеси'       => 'blinds',
				'baby'         => 'baby',
				'cot'          => 'baby',
				'бебе'         => 'baby',
				'spa'          => 'wellness',
				'wellness'     => 'wellness',
				'спа'          => 'wellness',
				'gym'          => 'gym',
				'fitness'      => 'gym',
				'фитнес'       => 'gym',
				'bike'         => 'bike',
				'bicycle'      => 'bike',
				'велосипед'    => 'bike',
				'speaker'      => 'speaker',
				'bluetooth'    => 'speaker',
				'desk'         => 'desk',
				'бюро'         => 'desk',
				'sofa'         => 'sofa',
				'диван'        => 'sofa',
				'bed'          => 'bed',
				'легл'         => 'bed',
				'phone'        => 'phone',
				'телефон'      => 'phone',
				'view'         => 'eye',
				'изглед'       => 'eye',
			)
		);
	}

	/**
	 * Icon for an amenity text: a ready-made amenity with the same name,
	 * else the first matching word, else a tick.
	 */
	public static function guess_icon( $label ) {
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $label ) ) : strtolower( trim( (string) $label ) );
		foreach ( self::amenity_presets() as $preset ) {
			if ( ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $preset[0] ) : strtolower( $preset[0] ) ) === $label ) {
				return $preset[1];
			}
		}
		foreach ( self::icon_keywords() as $word => $icon ) {
			if ( preg_match( '/(^|[^\p{L}])' . preg_quote( $word, '/' ) . '/u', $label ) ) {
				return $icon;
			}
		}
		return 'check';
	}

	/**
	 * The ready-made amenity with this name (in the site's language), or ''.
	 */
	public static function preset_for_label( $label ) {
		$label = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $label ) ) : strtolower( trim( (string) $label ) );
		foreach ( Flexo_Booking_Rooms::amenities() as $key => $name ) {
			if ( ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name ) ) === $label ) {
				return $key;
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Stored values
	 * ------------------------------------------------------------------ */

	/**
	 * A room's amenities as stored: list of array( key, label, icon ).
	 * Rooms saved before 1.8.0 only have ticked keys: those are read as
	 * ready-made amenities in the order of the list.
	 */
	public static function amenity_items( $room_id ) {
		$items = get_post_meta( $room_id, self::AMENITY_ITEMS, true );
		if ( is_array( $items ) ) {
			return self::sanitize_amenity_items( $items );
		}
		$keys  = array_filter( (array) get_post_meta( $room_id, '_flexo_amenities', true ) );
		$items = array();
		foreach ( array_keys( Flexo_Booking_Rooms::amenities() ) as $key ) {
			if ( in_array( $key, $keys, true ) ) {
				$items[] = array(
					'key'   => $key,
					'label' => '',
					'icon'  => '',
				);
			}
		}
		return $items;
	}

	/**
	 * Amenities to show: list of array( key, label, icon ), in the owner's order.
	 */
	public static function amenities( $room_id ) {
		$labels  = Flexo_Booking_Rooms::amenities();
		$presets = self::amenity_presets();
		$out     = array();
		foreach ( self::amenity_items( $room_id ) as $item ) {
			$key   = $item['key'];
			$label = '' !== $item['label'] ? $item['label'] : ( isset( $labels[ $key ] ) ? $labels[ $key ] : '' );
			if ( '' === $label ) {
				continue;
			}
			$icon  = '' !== $item['icon'] ? $item['icon'] : ( isset( $presets[ $key ][1] ) ? $presets[ $key ][1] : 'check' );
			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'icon'  => $icon,
			);
		}
		return $out;
	}

	/**
	 * @param array $items Raw list of array( key, label, icon ).
	 * @return array Clean list: ready-made amenities need no label, own ones need one.
	 */
	public static function sanitize_amenity_items( array $items ) {
		$known = Flexo_Booking_Rooms::amenities();
		$out   = array();
		$seen  = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key   = isset( $item['key'] ) ? sanitize_key( $item['key'] ) : '';
			$key   = isset( $known[ $key ] ) ? $key : '';
			$label = isset( $item['label'] ) ? self::clean_text( $item['label'], 80 ) : '';
			if ( $key && $label === $known[ $key ] ) {
				$label = ''; // Unchanged name: keep following the translation.
			}
			if ( '' === $key && '' === $label ) {
				continue;
			}
			$id = $key ? 'k:' . $key : 'l:' . strtolower( $label );
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'key'   => $key,
				'label' => $label,
				'icon'  => isset( $item['icon'] ) ? Flexo_Booking_Room_Icons::sanitize( $item['icon'] ) : '',
			);
			if ( count( $out ) >= self::MAX_AMENITIES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * "More details" rows: list of array( icon, label, value ).
	 */
	public static function details( $room_id ) {
		$rows = get_post_meta( $room_id, self::DETAILS, true );
		return is_array( $rows ) ? self::sanitize_details( $rows ) : array();
	}

	public static function sanitize_details( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? self::clean_text( $row['label'], 80 ) : '';
			$value = isset( $row['value'] ) ? self::clean_text( $row['value'], 200 ) : '';
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$out[] = array(
				'icon'  => isset( $row['icon'] ) ? Flexo_Booking_Room_Icons::sanitize( $row['icon'] ) : '',
				'label' => $label,
				'value' => $value,
			);
			if ( count( $out ) >= self::MAX_DETAILS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Gallery images (attachment IDs, in the owner's order). Deleted images are skipped.
	 *
	 * @param int  $room_id
	 * @param bool $with_featured Start with the main photo.
	 * @return int[]
	 */
	public static function gallery( $room_id, $with_featured = true ) {
		$ids = array_map( 'absint', (array) get_post_meta( $room_id, self::GALLERY, true ) );
		if ( $with_featured ) {
			array_unshift( $ids, (int) get_post_thumbnail_id( $room_id ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( $id && ! in_array( $id, $out, true ) && wp_attachment_is_image( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public static function sanitize_gallery( $ids ) {
		$ids = is_array( $ids ) ? $ids : explode( ',', (string) $ids );
		$out = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $out, true ) && wp_attachment_is_image( $id ) ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, self::MAX_GALLERY );
	}

	private static function clean_text( $text, $max ) {
		$text = trim( sanitize_text_field( (string) $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/* ------------------------------------------------------------------ *
	 * Show on website, demo rooms
	 * ------------------------------------------------------------------ */

	public static function is_hidden( $room_id ) {
		$flags = self::flagged()['hidden'];
		return in_array( (int) $room_id, $flags, true ) || ( $flags && in_array( Flexo_Booking_Room_I18n::canonical_id( $room_id ), $flags, true ) );
	}

	public static function is_demo( $room_id ) {
		$flags = self::flagged()['demo'];
		return in_array( (int) $room_id, $flags, true ) || ( $flags && in_array( Flexo_Booking_Room_I18n::canonical_id( $room_id ), $flags, true ) );
	}

	/**
	 * Rooms guests must not see in lists: hidden ones, and demo rooms for
	 * everyone except staff who edit rooms.
	 *
	 * @return int[]
	 */
	public static function excluded_ids() {
		$flags = self::flagged();
		return current_user_can( 'edit_flexo_rooms' ) ? $flags['hidden'] : array_values( array_unique( array_merge( $flags['hidden'], $flags['demo'] ) ) );
	}

	/**
	 * @return array{hidden:int[],demo:int[]}
	 */
	private static function flagged() {
		if ( null === self::$flags ) {
			global $wpdb;
			self::$flags = array(
				'hidden' => array(),
				'demo'   => array(),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small indexed lookup, kept for the request.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value = '1'", self::HIDDEN, self::DEMO ) );
			foreach ( (array) $rows as $row ) {
				self::$flags[ self::HIDDEN === $row->meta_key ? 'hidden' : 'demo' ][] = (int) $row->post_id;
			}
		}
		return self::$flags;
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( in_array( $key, array( self::HIDDEN, self::DEMO ), true ) ) {
			self::$flags = null;
		}
		unset( self::$cache[ (int) $post_id ] );
	}

	public static function forget( $post_id ) {
		unset( self::$cache[ (int) $post_id ] );
	}

	/* ------------------------------------------------------------------ *
	 * The current room
	 * ------------------------------------------------------------------ */

	/**
	 * The room a tag, widget or shortcode is about: the room it names
	 * (slug or ID), else the room being shown (room page, Theme Builder
	 * template, Loop item), else 0.
	 *
	 * @param int|string $explicit Room slug or ID chosen in the settings.
	 */
	public static function current_id( $explicit = '' ) {
		if ( '' !== (string) $explicit && '0' !== (string) $explicit ) {
			$post = Flexo_Booking_Rooms::find( $explicit, array( 'publish', 'private', 'draft', 'future', 'pending' ) );
			return $post ? (int) $post->ID : 0;
		}
		$id = get_the_ID();
		if ( $id && Flexo_Booking_Rooms::POST_TYPE === get_post_type( $id ) ) {
			return (int) $id;
		}
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post && Flexo_Booking_Rooms::POST_TYPE === $queried->post_type ) {
			return (int) $queried->ID;
		}
		/**
		 * The room to use when nothing names one (e.g. a sample room while
		 * a room template is edited in Elementor).
		 */
		return (int) apply_filters( 'flexo_booking_current_room', 0 );
	}

	/**
	 * Everything about a room that pages, tags and widgets show.
	 *
	 * @param int $room_id
	 * @return array|null
	 */
	public static function room( $room_id ) {
		$room_id = (int) $room_id;
		if ( isset( self::$cache[ $room_id ] ) ) {
			return self::$cache[ $room_id ];
		}
		$post = $room_id ? get_post( $room_id ) : null;
		if ( ! $post || Flexo_Booking_Rooms::POST_TYPE !== $post->post_type ) {
			return null;
		}
		// A translation shows its own texts; booking data comes from the main room.
		$main_id = Flexo_Booking_Room_I18n::canonical_id( $room_id );
		$main    = $main_id !== $room_id ? get_post( $main_id ) : $post;
		$main    = $main ? $main : $post;
		$room    = Flexo_Booking_Rooms::to_array( $main );
		$terms   = get_the_terms( $post, self::TAXONOMY );
		$terms   = is_array( $terms ) && $terms ? $terms : ( $main !== $post ? get_the_terms( $main, self::TAXONOMY ) : array() );
		$terms   = is_array( $terms ) ? $terms : array();
		$gallery = self::gallery( $room_id );
		$gallery = $gallery || $main === $post ? $gallery : self::gallery( $main->ID );
		$own     = static function ( $value, $fallback ) {
			return ( is_array( $value ) ? $value : trim( (string) $value ) ) ? $value : $fallback;
		};
		if ( $main !== $post ) {
			$room['title']   = get_the_title( $post );
			$room['excerpt'] = has_excerpt( $post ) ? get_the_excerpt( $post ) : ( '' !== trim( $post->post_content ) ? wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ) : $room['excerpt'] );
			$room['beds']    = $own( (string) get_post_meta( $room_id, '_flexo_beds', true ), $room['beds'] );
			$room['image']   = $gallery ? wp_get_attachment_image_url( $gallery[0], 'medium_large' ) : $room['image'];
		}

		$max_children = 0;
		if ( Flexo_Booking_Children::enabled() ) {
			$max_children = min( max( 0, $room['capacity'] - 1 ), (int) Flexo_Booking_Settings::get( 'max_children' ) );
		}

		$room = array_merge(
			$room,
			array(
				'id'           => $room_id,
				'main_id'      => (int) $main->ID,
				'url'          => self::page_url( $post ),
				'booking_url'  => self::booking_url( $main->post_name ),
				'types'        => wp_list_pluck( $terms, 'name' ),
				'type_slugs'   => wp_list_pluck( $terms, 'slug' ),
				'description'  => $own( $post->post_content, $main->post_content ),
				'view'         => $own( (string) get_post_meta( $room_id, self::VIEW, true ), (string) get_post_meta( $main->ID, self::VIEW, true ) ),
				'image_id'     => $gallery ? $gallery[0] : 0,
				'gallery'      => $gallery,
				'amenity_list' => $own( self::amenities( $room_id ), $main !== $post ? self::amenities( $main->ID ) : array() ),
				'details'      => $own( self::details( $room_id ), $main !== $post ? self::details( $main->ID ) : array() ),
				'max_children' => $max_children,
				'hidden'       => self::is_hidden( $room_id ),
				'demo'         => self::is_demo( $room_id ),
			)
		);

		self::$cache[ $room_id ] = $room;
		return $room;
	}

	/**
	 * The room's page address, or '' when it has none (hidden, draft).
	 */
	public static function page_url( $post ) {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status || self::is_hidden( $post->ID ) ) {
			return '';
		}
		return (string) get_permalink( $post );
	}

	/**
	 * Booking page with the room chosen, e.g. /booking/?room=deluxe-double.
	 *
	 * @param string $slug Room slug ('' = no room).
	 * @param array  $args Extra query arguments (check_in, adults…).
	 * @param string $page Booking page path or address ('' = the one in Settings).
	 */
	public static function booking_url( $slug, array $args = array(), $page = '' ) {
		$page = trim( (string) $page );
		if ( '' === $page ) {
			$url = Flexo_Booking_Guest::booking_page_url();
			$url = '' !== $url ? $url : home_url( '/booking/' );
		} else {
			$url = preg_match( '#^https?://#i', $page ) ? $page : home_url( '/' . ltrim( $page, '/' ) );
		}
		if ( '' !== (string) $slug ) {
			$args = array_merge( array( 'room' => $slug ), $args );
		}
		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
	}

	/**
	 * Dates and guests from the current address (?check_in=…&adults=…),
	 * checked so only valid values are passed on.
	 */
	public static function search_args() {
		$args = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only values passed on in a link.
		foreach ( array( 'check_in', 'check_out' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$date = Flexo_Booking_Dates::parse( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) );
				if ( $date ) {
					$args[ $key ] = $date;
				}
			}
		}
		foreach ( array( 'adults', 'children' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = min( 99, absint( $_GET[ $key ] ) );
			}
		}
		if ( isset( $_GET['children_ages'] ) ) {
			$ages = preg_replace( '/[^0-9,]/', '', sanitize_text_field( wp_unslash( $_GET['children_ages'] ) ) );
			if ( '' !== $ages ) {
				$args['children_ages'] = substr( $ages, 0, 60 );
			}
		}
		// phpcs:enable
		return $args;
	}

	/**
	 * The full description as HTML (paragraphs, shortcodes, responsive images).
	 */
	public static function description_html( array $room ) {
		$html = (string) $room['description'];
		if ( '' === trim( $html ) ) {
			return '';
		}
		$html = do_shortcode( shortcode_unautop( wpautop( wptexturize( $html ) ) ) );
		return function_exists( 'wp_filter_content_tags' ) ? wp_filter_content_tags( $html ) : $html;
	}

	/* ------------------------------------------------------------------ *
	 * Texts for guests
	 * ------------------------------------------------------------------ */

	/**
	 * "24 m²", or the number alone.
	 */
	public static function size_text( array $room, $number_only = false ) {
		if ( $room['size'] < 1 ) {
			return '';
		}
		if ( $number_only ) {
			return (string) $room['size'];
		}
		/* translators: %s: room size in square metres */
		return sprintf( __( '%s m²', 'flexo-booking' ), number_format_i18n( $room['size'] ) );
	}

	/**
	 * Guests in words.
	 *
	 * @param array  $room
	 * @param string $format summary ("Up to 4 guests (max. 2 adults)"), guests ("4 guests"),
	 *                       max_guests, max_adults, max_children (numbers).
	 */
	public static function guests_text( array $room, $format = 'summary' ) {
		$adults = $room['max_adults'] > 0 && $room['max_adults'] < $room['capacity'] && Flexo_Booking_Children::enabled() ? $room['max_adults'] : 0;
		switch ( $format ) {
			case 'max_guests':
				return (string) $room['capacity'];
			case 'max_adults':
				return (string) ( $adults ? $adults : $room['capacity'] );
			case 'max_children':
				return (string) $room['max_children'];
			case 'guests':
				/* translators: %d: number of guests */
				return sprintf( _n( '%d guest', '%d guests', $room['capacity'], 'flexo-booking' ), $room['capacity'] );
		}
		/* translators: %d: number of guests */
		$text = sprintf( _n( 'Up to %d guest', 'Up to %d guests', $room['capacity'], 'flexo-booking' ), $room['capacity'] );
		if ( $adults ) {
			/* translators: 1: "Up to 4 guests", 2: number of adults */
			$text = sprintf( _n( '%1$s (max. %2$d adult)', '%1$s (max. %2$d adults)', $adults, 'flexo-booking' ), $text, $adults );
		}
		return $text;
	}

	/**
	 * Image data for a gallery or photo: id, url, full, alt, width, height.
	 */
	public static function image( $attachment_id, $size = 'large' ) {
		$src  = wp_get_attachment_image_src( $attachment_id, $size );
		$full = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'id'     => (int) $attachment_id,
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'full'   => $full ? $full[0] : $src[0],
			'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ),
		);
	}

	/**
	 * Neutral placeholder for a room without photos (bundled, no request elsewhere).
	 */
	public static function placeholder_url() {
		return FLEXO_BOOKING_URL . 'assets/images/room-placeholder.svg';
	}
}
