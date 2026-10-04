<?php
/**
 * Elementor dynamic tags for room content (group "Flexo Booking").
 *
 * Every tag works on "the current room" – the room page, the Theme Builder
 * single template, each Loop Grid / Loop Carousel item – or on a room picked
 * in the tag's settings. Outside rooms they output nothing (Elementor's
 * Fallback setting can fill in).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

/**
 * Shared parts: the room setting and reading the room.
 */
trait Flexo_Booking_Room_Tag_Base {

	public function get_group() {
		return 'flexo-booking';
	}

	protected function register_room_control() {
		$this->add_control(
			'room',
			array(
				'label'   => __( 'Room', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => Flexo_Booking_Elementor::room_options( __( 'Current room (automatic)', 'flexo-booking' ) ),
			)
		);
	}

	/**
	 * @return array|null The room view (see Flexo_Booking_Room_Content::room()).
	 */
	protected function room() {
		return Flexo_Booking_Room_Content::room( Flexo_Booking_Room_Content::current_id( (string) $this->get_settings( 'room' ) ) );
	}
}

/**
 * A text tag: subclasses return the text (HTML allowed) in text().
 */
abstract class Flexo_Booking_Room_Text_Tag extends Tag {

	use Flexo_Booking_Room_Tag_Base;

	public function get_categories() {
		return array( TagsModule::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->register_room_control();
		$this->register_tag_controls();
	}

	protected function register_tag_controls() {}

	/**
	 * @param array $room
	 * @return string HTML, escaped by text() (the description is the room's
	 *                post content, shown like WordPress shows post content).
	 */
	abstract protected function text( array $room );

	public function render() {
		$room = $this->room();
		if ( $room ) {
			echo $this->text( $room ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in text().
		}
	}
}

class Flexo_Booking_Room_Name_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-name';
	}
	public function get_title() {
		return __( 'Room name', 'flexo-booking' );
	}
	protected function text( array $room ) {
		return esc_html( $room['title'] );
	}
}

class Flexo_Booking_Room_Type_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-type';
	}
	public function get_title() {
		return __( 'Room type', 'flexo-booking' );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'separator',
			array(
				'label'   => __( 'Between types', 'flexo-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => ', ',
			)
		);
	}
	protected function text( array $room ) {
		return esc_html( implode( (string) $this->get_settings( 'separator' ), $room['types'] ) );
	}
}

class Flexo_Booking_Room_Excerpt_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-excerpt';
	}
	public function get_title() {
		return __( 'Short description', 'flexo-booking' );
	}
	protected function text( array $room ) {
		return esc_html( $room['excerpt'] );
	}
}

class Flexo_Booking_Room_Description_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-description';
	}
	public function get_title() {
		return __( 'Full description', 'flexo-booking' );
	}
	protected function text( array $room ) {
		return Flexo_Booking_Room_Content::description_html( $room );
	}
}

class Flexo_Booking_Room_Size_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-size';
	}
	public function get_title() {
		return __( 'Room size', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::TEXT_CATEGORY, TagsModule::NUMBER_CATEGORY );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'format',
			array(
				'label'   => __( 'Show', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'text',
				'options' => array(
					'text'   => __( 'With unit (24 m²)', 'flexo-booking' ),
					'number' => __( 'Number only (24)', 'flexo-booking' ),
				),
			)
		);
	}
	protected function text( array $room ) {
		return esc_html( Flexo_Booking_Room_Content::size_text( $room, 'number' === $this->get_settings( 'format' ) ) );
	}
}

class Flexo_Booking_Room_Beds_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-beds';
	}
	public function get_title() {
		return __( 'Beds', 'flexo-booking' );
	}
	protected function text( array $room ) {
		return esc_html( $room['beds'] );
	}
}

class Flexo_Booking_Room_View_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-view';
	}
	public function get_title() {
		return __( 'View', 'flexo-booking' );
	}
	protected function text( array $room ) {
		return esc_html( $room['view'] );
	}
}

class Flexo_Booking_Room_Guests_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-guests';
	}
	public function get_title() {
		return __( 'Guests (capacity)', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::TEXT_CATEGORY, TagsModule::NUMBER_CATEGORY );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'format',
			array(
				'label'   => __( 'Show', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'summary',
				'options' => array(
					'summary'      => __( 'Up to 4 guests (max. 2 adults)', 'flexo-booking' ),
					'guests'       => __( '4 guests', 'flexo-booking' ),
					'max_guests'   => __( 'Max guests (number)', 'flexo-booking' ),
					'max_adults'   => __( 'Max adults (number)', 'flexo-booking' ),
					'max_children' => __( 'Max children (number)', 'flexo-booking' ),
				),
			)
		);
	}
	protected function text( array $room ) {
		return esc_html( Flexo_Booking_Room_Content::guests_text( $room, (string) $this->get_settings( 'format' ) ) );
	}
}

class Flexo_Booking_Room_Price_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-price';
	}
	public function get_title() {
		return __( 'Room price', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::TEXT_CATEGORY, TagsModule::NUMBER_CATEGORY );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'price',
			array(
				'label'   => __( 'Price', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'base',
				'options' => self::price_options(),
			)
		);
		$this->add_control(
			'format',
			array(
				'label'   => __( 'Show', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'money',
				'options' => array(
					'money'  => __( 'With currency (€120)', 'flexo-booking' ),
					'number' => __( 'Number only (120)', 'flexo-booking' ),
				),
			)
		);
	}

	/**
	 * Which price: key => label.
	 */
	public static function price_options() {
		return apply_filters(
			'flexo_booking_room_price_options',
			array(
				'base'    => __( 'Price per night', 'flexo-booking' ),
				'weekend' => __( 'Weekend price per night', 'flexo-booking' ),
			)
		);
	}

	protected function text( array $room ) {
		$which  = (string) $this->get_settings( 'price' );
		$amount = apply_filters( 'flexo_booking_room_price_tag', null, $which, $room );
		if ( null === $amount ) {
			$amount = 'weekend' === $which && $room['weekend_price'] > 0 ? $room['weekend_price'] : $room['price'];
		}
		if ( ! is_numeric( $amount ) || $amount <= 0 ) {
			return '';
		}
		if ( 'number' === $this->get_settings( 'format' ) ) {
			return esc_html( Flexo_Booking_Money::format_number( (float) $amount, true ) );
		}
		return esc_html( Flexo_Booking_Money::format( (float) $amount, null, true ) );
	}
}

class Flexo_Booking_Room_Amenities_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-amenities';
	}
	public function get_title() {
		return __( 'Amenities', 'flexo-booking' );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'format',
			array(
				'label'   => __( 'Show', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list'  => __( 'List with icons', 'flexo-booking' ),
					'comma' => __( 'Names, separated by commas', 'flexo-booking' ),
					'lines' => __( 'Names, one per line', 'flexo-booking' ),
					'nth'   => __( 'One amenity (by position)', 'flexo-booking' ),
				),
			)
		);
		$this->add_control(
			'position',
			array(
				'label'     => __( 'Position', 'flexo-booking' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 60,
				'default'   => 1,
				'condition' => array( 'format' => 'nth' ),
			)
		);
		$this->add_control(
			'limit',
			array(
				'label'       => __( 'How many', 'flexo-booking' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 60,
				'default'     => 0,
				'description' => __( '0 = all.', 'flexo-booking' ),
				'condition'   => array( 'format!' => 'nth' ),
			)
		);
	}
	protected function text( array $room ) {
		$items = $room['amenity_list'];
		$s     = $this->get_settings();
		if ( 'nth' === $s['format'] ) {
			$i = max( 1, (int) $s['position'] ) - 1;
			return isset( $items[ $i ] ) ? esc_html( $items[ $i ]['label'] ) : '';
		}
		if ( (int) $s['limit'] > 0 ) {
			$items = array_slice( $items, 0, (int) $s['limit'] );
		}
		if ( ! $items ) {
			return '';
		}
		$names = array_map( 'esc_html', wp_list_pluck( $items, 'label' ) );
		if ( 'comma' === $s['format'] ) {
			return implode( ', ', $names );
		}
		if ( 'lines' === $s['format'] ) {
			return implode( '<br>', $names );
		}
		Flexo_Booking_Room_Render::enqueue();
		return Flexo_Booking_Room_Render::amenities( $items );
	}
}

class Flexo_Booking_Room_Detail_Tag extends Flexo_Booking_Room_Text_Tag {
	public function get_name() {
		return 'flexo-room-detail';
	}
	public function get_title() {
		return __( 'Room detail (More details)', 'flexo-booking' );
	}
	protected function register_tag_controls() {
		$this->add_control(
			'label',
			array(
				'label'       => __( 'Detail name', 'flexo-booking' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => __( 'e.g. Floor', 'flexo-booking' ),
				'description' => __( 'The name as written in the room\'s "More details". Or leave empty and choose a position.', 'flexo-booking' ),
			)
		);
		$this->add_control(
			'position',
			array(
				'label'     => __( 'Position', 'flexo-booking' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 30,
				'default'   => 1,
				'condition' => array( 'label' => '' ),
			)
		);
		$this->add_control(
			'part',
			array(
				'label'   => __( 'Show', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'value',
				'options' => array(
					'value' => __( 'Value', 'flexo-booking' ),
					'both'  => __( 'Name: value', 'flexo-booking' ),
					'label' => __( 'Name', 'flexo-booking' ),
				),
			)
		);
	}
	protected function text( array $room ) {
		$s    = $this->get_settings();
		$row  = null;
		$name = trim( (string) $s['label'] );
		foreach ( $room['details'] as $i => $detail ) {
			if ( '' !== $name ? 0 === strcasecmp( $detail['label'], $name ) : $i === max( 1, (int) $s['position'] ) - 1 ) {
				$row = $detail;
				break;
			}
		}
		if ( ! $row ) {
			return '';
		}
		if ( 'label' === $s['part'] ) {
			return esc_html( $row['label'] );
		}
		if ( 'both' === $s['part'] && '' !== $row['label'] ) {
			/* translators: 1: detail name, 2: detail value */
			return esc_html( sprintf( __( '%1$s: %2$s', 'flexo-booking' ), $row['label'], $row['value'] ) );
		}
		return esc_html( $row['value'] );
	}
}

/**
 * Main photo (image tag).
 */
class Flexo_Booking_Room_Image_Tag extends Data_Tag {

	use Flexo_Booking_Room_Tag_Base;

	public function get_name() {
		return 'flexo-room-image';
	}
	public function get_title() {
		return __( 'Room main photo', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::IMAGE_CATEGORY );
	}
	protected function register_controls() {
		$this->register_room_control();
		$this->add_control(
			'photo',
			array(
				'label'   => __( 'Photo', 'flexo-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '1',
				'options' => array(
					'1' => __( 'Main photo', 'flexo-booking' ),
					'2' => __( '2nd photo', 'flexo-booking' ),
					'3' => __( '3rd photo', 'flexo-booking' ),
					'4' => __( '4th photo', 'flexo-booking' ),
				),
			)
		);
		$this->add_control(
			'placeholder',
			array(
				'label'        => __( 'No photo: show a neutral placeholder', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
	}
	public function get_value( array $options = array() ) {
		$room  = $this->room();
		$index = max( 1, (int) $this->get_settings( 'photo' ) ) - 1;
		if ( $room && isset( $room['gallery'][ $index ] ) ) {
			$id = $room['gallery'][ $index ];
			return array(
				'id'  => $id,
				'url' => (string) wp_get_attachment_image_url( $id, 'full' ),
			);
		}
		if ( 'yes' === $this->get_settings( 'placeholder' ) ) {
			return array(
				'id'  => '',
				'url' => Flexo_Booking_Room_Content::placeholder_url(),
			);
		}
		return array();
	}
}

/**
 * Gallery: main photo + gallery, in the owner's order. For Elementor's
 * Gallery, Basic Gallery and Image Carousel widgets.
 */
class Flexo_Booking_Room_Gallery_Tag extends Data_Tag {

	use Flexo_Booking_Room_Tag_Base;

	public function get_name() {
		return 'flexo-room-gallery';
	}
	public function get_title() {
		return __( 'Room gallery', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::GALLERY_CATEGORY );
	}
	protected function register_controls() {
		$this->register_room_control();
		$this->add_control(
			'with_main',
			array(
				'label'        => __( 'Start with the main photo', 'flexo-booking' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);
	}
	public function get_value( array $options = array() ) {
		$room = $this->room();
		if ( ! $room ) {
			return array();
		}
		$ids = 'yes' === $this->get_settings( 'with_main' ) ? $room['gallery'] : Flexo_Booking_Room_Content::gallery( $room['id'], false );
		return array_map(
			static function ( $id ) {
				return array( 'id' => $id );
			},
			$ids
		);
	}
}

/**
 * The room's own page (for "View room" buttons in room lists).
 */
class Flexo_Booking_Room_Url_Tag extends Data_Tag {

	use Flexo_Booking_Room_Tag_Base;

	public function get_name() {
		return 'flexo-room-url';
	}
	public function get_title() {
		return __( 'Room page link', 'flexo-booking' );
	}
	public function get_categories() {
		return array( TagsModule::URL_CATEGORY );
	}
	protected function register_controls() {
		$this->register_room_control();
	}
	public function get_value( array $options = array() ) {
		$room = $this->room();
		return $room ? esc_url( $room['url'] ) : '';
	}
}
