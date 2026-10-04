<?php
/**
 * Icons for room amenities and details: a bundled set of line icons
 * (inline SVG, no icon font, nothing loaded from elsewhere) plus icons the
 * hotel uploads to the media library.
 *
 * An icon reference is stored as a string: "wifi" (bundled icon) or
 * "media:123" (attachment 123, an SVG or PNG from the media library).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Icons {

	/**
	 * @var array|null name => inner SVG markup.
	 */
	private static $paths = null;

	private static function paths() {
		if ( null === self::$paths ) {
			self::$paths = require FLEXO_BOOKING_DIR . 'includes/data/room-icons.php';
		}
		return self::$paths;
	}

	/**
	 * Icon groups for the picker: group label => icon name => icon label.
	 */
	public static function groups() {
		return array(
			__( 'Room', 'flexo-booking' )               => array(
				'bed'           => __( 'Bed', 'flexo-booking' ),
				'bed_double'    => __( 'Double bed', 'flexo-booking' ),
				'bed_single'    => __( 'Single bed', 'flexo-booking' ),
				'baby'          => __( 'Baby', 'flexo-booking' ),
				'size'          => __( 'Size', 'flexo-booking' ),
				'ruler'         => __( 'Ruler', 'flexo-booking' ),
				'users'         => __( 'Guests', 'flexo-booking' ),
				'user'          => __( 'Guest', 'flexo-booking' ),
				'door'          => __( 'Door', 'flexo-booking' ),
				'key'           => __( 'Key', 'flexo-booking' ),
				'eye'           => __( 'View', 'flexo-booking' ),
				'layers'        => __( 'Floor', 'flexo-booking' ),
				'lift'          => __( 'Lift', 'flexo-booking' ),
				'building'      => __( 'Building', 'flexo-booking' ),
				'house'         => __( 'House', 'flexo-booking' ),
				'hotel'         => __( 'Hotel', 'flexo-booking' ),
				'sofa'          => __( 'Sofa', 'flexo-booking' ),
				'armchair'      => __( 'Armchair', 'flexo-booking' ),
				'lamp'          => __( 'Lamp', 'flexo-booking' ),
				'desk'          => __( 'Desk', 'flexo-booking' ),
				'blinds'        => __( 'Curtains', 'flexo-booking' ),
				'luggage'       => __( 'Luggage', 'flexo-booking' ),
				'accessibility' => __( 'Accessibility', 'flexo-booking' ),
			),
			__( 'Comfort', 'flexo-booking' )            => array(
				'wifi'             => __( 'Wi-Fi', 'flexo-booking' ),
				'air_conditioning' => __( 'Air conditioning', 'flexo-booking' ),
				'snowflake'        => __( 'Snowflake', 'flexo-booking' ),
				'heater'           => __( 'Heating', 'flexo-booking' ),
				'thermometer'      => __( 'Thermometer', 'flexo-booking' ),
				'fan'              => __( 'Fan', 'flexo-booking' ),
				'flame'            => __( 'Fireplace', 'flexo-booking' ),
				'plug'             => __( 'Socket', 'flexo-booking' ),
				'lightbulb'        => __( 'Light', 'flexo-booking' ),
				'tv'               => __( 'TV', 'flexo-booking' ),
				'monitor'          => __( 'Screen', 'flexo-booking' ),
				'laptop'           => __( 'Laptop', 'flexo-booking' ),
				'phone'            => __( 'Phone', 'flexo-booking' ),
				'speaker'          => __( 'Speaker', 'flexo-booking' ),
				'music'            => __( 'Music', 'flexo-booking' ),
				'quiet'            => __( 'Quiet', 'flexo-booking' ),
				'gamepad'          => __( 'Games', 'flexo-booking' ),
				'book'             => __( 'Book', 'flexo-booking' ),
				'newspaper'        => __( 'Newspaper', 'flexo-booking' ),
				'battery'          => __( 'Charger', 'flexo-booking' ),
			),
			__( 'Bathroom', 'flexo-booking' )           => array(
				'shower'          => __( 'Shower', 'flexo-booking' ),
				'bath'            => __( 'Bathtub', 'flexo-booking' ),
				'toilet'          => __( 'Toilet', 'flexo-booking' ),
				'droplets'        => __( 'Water', 'flexo-booking' ),
				'bubbles'         => __( 'Hot tub', 'flexo-booking' ),
				'toiletries'      => __( 'Toiletries', 'flexo-booking' ),
				'hairdryer'       => __( 'Hairdryer', 'flexo-booking' ),
				'shirt'           => __( 'Clothes', 'flexo-booking' ),
				'washing_machine' => __( 'Washing machine', 'flexo-booking' ),
			),
			__( 'Food & drink', 'flexo-booking' )       => array(
				'kitchen'      => __( 'Kitchen', 'flexo-booking' ),
				'chef'         => __( 'Chef', 'flexo-booking' ),
				'microwave'    => __( 'Microwave', 'flexo-booking' ),
				'utensils'     => __( 'Cutlery', 'flexo-booking' ),
				'fridge'       => __( 'Fridge', 'flexo-booking' ),
				'coffee'       => __( 'Coffee', 'flexo-booking' ),
				'wine'         => __( 'Wine', 'flexo-booking' ),
				'beer'         => __( 'Beer', 'flexo-booking' ),
				'cocktail'     => __( 'Cocktail', 'flexo-booking' ),
				'glass'        => __( 'Glass of water', 'flexo-booking' ),
				'croissant'    => __( 'Breakfast', 'flexo-booking' ),
				'egg'          => __( 'Fried egg', 'flexo-booking' ),
				'room_service' => __( 'Room service', 'flexo-booking' ),
				'bell'         => __( 'Reception bell', 'flexo-booking' ),
				'cake'         => __( 'Cake', 'flexo-booking' ),
				'soup'         => __( 'Soup', 'flexo-booking' ),
				'salad'        => __( 'Salad', 'flexo-booking' ),
				'apple'        => __( 'Fruit', 'flexo-booking' ),
			),
			__( 'Outdoors & views', 'flexo-booking' )   => array(
				'fence'         => __( 'Balcony', 'flexo-booking' ),
				'sun'           => __( 'Sun', 'flexo-booking' ),
				'waves'         => __( 'Sea', 'flexo-booking' ),
				'mountain'      => __( 'Mountain', 'flexo-booking' ),
				'mountain_snow' => __( 'Snowy mountain', 'flexo-booking' ),
				'trees'         => __( 'Trees', 'flexo-booking' ),
				'tree'          => __( 'Pine tree', 'flexo-booking' ),
				'flower'        => __( 'Flower', 'flexo-booking' ),
				'leaf'          => __( 'Leaf', 'flexo-booking' ),
				'palm'          => __( 'Palm tree', 'flexo-booking' ),
				'umbrella'      => __( 'Umbrella', 'flexo-booking' ),
				'sunrise'       => __( 'Sunrise', 'flexo-booking' ),
				'sunset'        => __( 'Sunset', 'flexo-booking' ),
				'moon'          => __( 'Moon', 'flexo-booking' ),
				'cloud_sun'     => __( 'Weather', 'flexo-booking' ),
			),
			__( 'Activities & travel', 'flexo-booking' ) => array(
				'pool'      => __( 'Swimming pool', 'flexo-booking' ),
				'gym'       => __( 'Gym', 'flexo-booking' ),
				'wellness'  => __( 'Wellness', 'flexo-booking' ),
				'bike'      => __( 'Bike', 'flexo-booking' ),
				'boat'      => __( 'Boat', 'flexo-booking' ),
				'anchor'    => __( 'Anchor', 'flexo-booking' ),
				'fish'      => __( 'Fishing', 'flexo-booking' ),
				'cable_car' => __( 'Ski lift', 'flexo-booking' ),
				'tent'      => __( 'Tent', 'flexo-booking' ),
				'map_pin'   => __( 'Location', 'flexo-booking' ),
				'compass'   => __( 'Compass', 'flexo-booking' ),
				'plane'     => __( 'Plane', 'flexo-booking' ),
				'bus'       => __( 'Bus', 'flexo-booking' ),
				'car'       => __( 'Car', 'flexo-booking' ),
				'parking'   => __( 'Parking', 'flexo-booking' ),
				'party'     => __( 'Party', 'flexo-booking' ),
				'toys'      => __( 'Toys', 'flexo-booking' ),
			),
			__( 'Services & safety', 'flexo-booking' )  => array(
				'safe'       => __( 'Safe', 'flexo-booking' ),
				'lock'       => __( 'Lock', 'flexo-booking' ),
				'shield'     => __( 'Security', 'flexo-booking' ),
				'first_aid'  => __( 'First aid', 'flexo-booking' ),
				'clock'      => __( 'Clock', 'flexo-booking' ),
				'alarm'      => __( 'Alarm clock', 'flexo-booking' ),
				'calendar'   => __( 'Calendar', 'flexo-booking' ),
				'bell_ring'  => __( 'Bell', 'flexo-booking' ),
				'cleaning'   => __( 'Cleaning', 'flexo-booking' ),
				'sparkles'   => __( 'Sparkles', 'flexo-booking' ),
				'paw'        => __( 'Pets', 'flexo-booking' ),
				'dog'        => __( 'Dog', 'flexo-booking' ),
				'cat'        => __( 'Cat', 'flexo-booking' ),
				'no_smoking' => __( 'No smoking', 'flexo-booking' ),
				'shop'       => __( 'Shop', 'flexo-booking' ),
				'card'       => __( 'Card', 'flexo-booking' ),
				'banknote'   => __( 'Cash', 'flexo-booking' ),
				'percent'    => __( 'Discount', 'flexo-booking' ),
				'tag'        => __( 'Tag', 'flexo-booking' ),
				'gift'       => __( 'Gift', 'flexo-booking' ),
				'check'      => __( 'Tick', 'flexo-booking' ),
				'star'       => __( 'Star', 'flexo-booking' ),
				'heart'      => __( 'Heart', 'flexo-booking' ),
				'info'       => __( 'Info', 'flexo-booking' ),
			),
		);
	}

	/**
	 * All bundled icons: name => label.
	 */
	public static function labels() {
		$out = array();
		foreach ( self::groups() as $icons ) {
			$out += $icons;
		}
		return $out;
	}

	public static function exists( $name ) {
		return isset( self::paths()[ $name ] );
	}

	/**
	 * Cleans a stored icon reference: a bundled icon name or "media:{id}".
	 */
	public static function sanitize( $ref ) {
		$ref = trim( (string) $ref );
		if ( preg_match( '/^media:(\d+)$/', $ref, $m ) ) {
			return wp_attachment_is_image( (int) $m[1] ) || 'image/svg+xml' === get_post_mime_type( (int) $m[1] ) ? 'media:' . (int) $m[1] : '';
		}
		$ref = sanitize_key( $ref );
		return self::exists( $ref ) ? $ref : '';
	}

	/**
	 * Inline SVG of a bundled icon. Decorative (aria-hidden): the text next
	 * to it says what it is.
	 */
	public static function svg( $name, $class = 'flexo-icon' ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			$name = 'check';
		}
		return '<svg class="' . esc_attr( $class ) . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * HTML for any icon reference.
	 *
	 * Uploaded icons are shown as a mask filled with the text colour, so
	 * they take the icon colour like the bundled ones (and an uploaded SVG
	 * never runs inside the page). With $original_colours they are shown as
	 * a plain image instead.
	 *
	 * @param string $ref              Icon reference.
	 * @param string $class            CSS class.
	 * @param bool   $original_colours Show uploaded icons in their own colours.
	 */
	public static function html( $ref, $class = 'flexo-icon', $original_colours = false ) {
		if ( preg_match( '/^media:(\d+)$/', (string) $ref, $m ) ) {
			$url = wp_get_attachment_image_url( (int) $m[1], 'thumbnail' );
			$url = $url ? $url : wp_get_attachment_url( (int) $m[1] );
			if ( $url ) {
				if ( $original_colours ) {
					return '<img class="' . esc_attr( $class . ' flexo-icon--image' ) . '" src="' . esc_url( $url ) . '" alt="" width="24" height="24" loading="lazy" decoding="async">';
				}
				return '<span class="' . esc_attr( $class . ' flexo-icon--mask' ) . '" style="' . esc_attr( '--flexo-icon:url("' . esc_url( $url ) . '")' ) . '" aria-hidden="true"></span>';
			}
			$ref = 'check';
		}
		return self::svg( (string) $ref, $class );
	}

	/**
	 * Data for the admin icon picker: groups with icons (name, label, svg).
	 */
	public static function picker_data() {
		$out = array();
		foreach ( self::groups() as $group => $icons ) {
			$items = array();
			foreach ( $icons as $name => $label ) {
				$items[] = array(
					'name'  => $name,
					'label' => $label,
					'svg'   => self::svg( $name ),
				);
			}
			$out[] = array(
				'label' => $group,
				'icons' => $items,
			);
		}
		return $out;
	}
}
