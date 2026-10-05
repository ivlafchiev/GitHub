<?php
/**
 * Demo rooms: three sample rooms with texts, facts, amenities, details and
 * neutral photos, for designing the room template and room lists before
 * the real rooms are entered.
 *
 * Demo rooms are marked "Demo" in the admin, are seen on the website only
 * by staff, and can never be booked. "Remove demo rooms" deletes them and
 * their photos in one click (never a room that has bookings).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Demo_Rooms {

	const ACTION_ADD    = 'flexo_booking_demo_add';
	const ACTION_REMOVE = 'flexo_booking_demo_remove';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION_ADD, array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_' . self::ACTION_REMOVE, array( __CLASS__, 'handle_remove' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * @return int[] IDs of the demo rooms.
	 */
	public static function ids() {
		return array_values(
			array_filter(
				wp_list_pluck( Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) ), 'ID' ),
				array( 'Flexo_Booking_Room_Content', 'is_demo' )
			)
		);
	}

	public static function add_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_ADD ), self::ACTION_ADD );
	}

	public static function remove_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_REMOVE ), self::ACTION_REMOVE );
	}

	private static function rooms() {
		return array(
			array(
				'slug'      => 'demo-garden-double',
				'title'     => __( 'Garden Double Room (demo)', 'flexo-booking' ),
				'excerpt'   => __( 'A calm double room on the ground floor, opening onto the garden.', 'flexo-booking' ),
				'content'   => __( "A calm double room on the ground floor with a door straight onto the garden. Morning light, a comfortable double bed and a small sitting corner.\n\nThe bathroom has a walk-in shower and organic toiletries.", 'flexo-booking' ),
				'type'      => __( 'Double room', 'flexo-booking' ),
				'meta'      => array( '_flexo_price' => 80, '_flexo_weekend_price' => 95, '_flexo_capacity' => 2, '_flexo_units' => 3, '_flexo_size' => 22, '_flexo_beds' => __( '1 double bed', 'flexo-booking' ) ),
				'view'      => __( 'Garden view', 'flexo-booking' ),
				'amenities' => array( 'garden_view', 'wifi', 'air_conditioning', 'private_bathroom', 'tv', 'coffee' ),
				'details'   => array( array( 'layers', __( 'Floor', 'flexo-booking' ), __( 'Ground floor', 'flexo-booking' ) ) ),
				'colors'    => array( array( 205, 214, 190 ), array( 92, 120, 84 ) ),
			),
			array(
				'slug'      => 'demo-sea-view-studio',
				'title'     => __( 'Sea View Studio (demo)', 'flexo-booking' ),
				'excerpt'   => __( 'Bright studio with a kitchenette and a balcony facing the sea.', 'flexo-booking' ),
				'content'   => __( "A bright studio with a balcony facing the sea, a kitchenette for simple meals and a desk by the window.\n\nIdeal for couples who like to come and go as they please.", 'flexo-booking' ),
				'type'      => __( 'Studio', 'flexo-booking' ),
				'meta'      => array( '_flexo_price' => 110, '_flexo_weekend_price' => 130, '_flexo_capacity' => 2, '_flexo_units' => 2, '_flexo_size' => 28, '_flexo_beds' => __( '1 king-size bed', 'flexo-booking' ), '_flexo_min_nights' => 2 ),
				'view'      => __( 'Sea view', 'flexo-booking' ),
				'amenities' => array( 'sea_view', 'balcony', 'kitchenette', 'wifi', 'air_conditioning', 'desk' ),
				'details'   => array( array( 'umbrella', __( 'Distance to the beach', 'flexo-booking' ), __( '200 m', 'flexo-booking' ) ) ),
				'colors'    => array( array( 186, 210, 222 ), array( 44, 92, 122 ) ),
			),
			array(
				'slug'      => 'demo-family-suite',
				'title'     => __( 'Family Suite (demo)', 'flexo-booking' ),
				'excerpt'   => __( 'Two rooms for up to four guests, with space for the children.', 'flexo-booking' ),
				'content'   => __( "Two connected rooms for families: a double bedroom for the parents and a second room with two single beds.\n\nA baby cot is available on request.", 'flexo-booking' ),
				'type'      => __( 'Suite', 'flexo-booking' ),
				'meta'      => array( '_flexo_price' => 150, '_flexo_weekend_price' => 170, '_flexo_capacity' => 4, '_flexo_max_adults' => 2, '_flexo_units' => 1, '_flexo_size' => 40, '_flexo_beds' => __( '1 double bed + 2 single beds', 'flexo-booking' ) ),
				'view'      => '',
				'amenities' => array( 'wifi', 'air_conditioning', 'bathtub', 'fridge', 'tv', 'baby_cot' ),
				'details'   => array( array( 'baby', __( 'Children', 'flexo-booking' ), __( 'Baby cot on request', 'flexo-booking' ) ) ),
				'colors'    => array( array( 232, 214, 196 ), array( 150, 104, 80 ) ),
			),
		);
	}

	/**
	 * Adds the demo rooms (again if they were removed).
	 *
	 * @return int Rooms added.
	 */
	public static function add() {
		$added = 0;
		foreach ( self::rooms() as $i => $demo ) {
			if ( get_page_by_path( $demo['slug'], OBJECT, Flexo_Booking_Rooms::POST_TYPE ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => Flexo_Booking_Rooms::POST_TYPE,
					'post_title'   => $demo['title'],
					'post_name'    => $demo['slug'],
					'post_excerpt' => $demo['excerpt'],
					'post_content' => $demo['content'],
					'post_status'  => 'publish',
					'menu_order'   => 900 + $i,
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, Flexo_Booking_Room_Content::DEMO, '1' );
			Flexo_Booking_Rooms::save_meta_values( $id, $demo['meta'] );
			if ( '' !== $demo['view'] ) {
				update_post_meta( $id, Flexo_Booking_Room_Content::VIEW, $demo['view'] );
			}
			Flexo_Booking_Room_Editor::save_amenities(
				$id,
				array_map(
					static function ( $key ) {
						return array( 'key' => $key );
					},
					$demo['amenities']
				)
			);
			$rows = array();
			foreach ( $demo['details'] as $row ) {
				$rows[] = array(
					'icon'  => $row[0],
					'label' => $row[1],
					'value' => $row[2],
				);
			}
			update_post_meta( $id, Flexo_Booking_Room_Content::DETAILS, $rows );
			wp_set_object_terms( $id, array( $demo['type'] ), Flexo_Booking_Room_Content::TAXONOMY );

			$photos = array();
			for ( $p = 0; $p < 4; $p++ ) {
				$photo = self::photo( $id, $demo['slug'] . '-' . ( $p + 1 ), $demo['colors'], $p );
				if ( $photo ) {
					$photos[] = $photo;
				}
			}
			if ( $photos ) {
				set_post_thumbnail( $id, $photos[0] );
				update_post_meta( $id, Flexo_Booking_Room_Content::GALLERY, array_slice( $photos, 1 ) );
			}
			++$added;
		}
		return $added;
	}

	/**
	 * A neutral sample photo (drawn here, nothing is downloaded).
	 *
	 * @return int Attachment ID, or 0 without the GD image library.
	 */
	private static function photo( $room_id, $name, array $colors, $variant ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			return 0;
		}
		$w  = 1200;
		$h  = 800;
		$im = imagecreatetruecolor( $w, $h );
		list( $top, $bottom ) = $colors;
		if ( $variant % 2 ) {
			list( $top, $bottom ) = array( $bottom, $top );
		}
		for ( $y = 0; $y < $h; $y++ ) {
			$t = $y / $h;
			imageline( $im, 0, $y, $w, $y, imagecolorallocate( $im, (int) ( $top[0] * ( 1 - $t ) + $bottom[0] * $t ), (int) ( $top[1] * ( 1 - $t ) + $bottom[1] * $t ), (int) ( $top[2] * ( 1 - $t ) + $bottom[2] * $t ) ) );
		}
		$light = imagecolorallocatealpha( $im, 255, 255, 255, 70 );
		$dark  = imagecolorallocatealpha( $im, 20, 20, 20, 90 );
		imagefilledrectangle( $im, 160 + 40 * $variant, 440, 1040 - 40 * $variant, 650, $light );
		imagefilledrectangle( $im, 160 + 40 * $variant, 380, 420, 450, $light );
		imagefilledrectangle( $im, 780 - 40 * $variant, 380, 1040 - 40 * $variant, 450, $light );
		imagefilledrectangle( $im, 800, 90, 1090, 300, $dark );
		imagefilledellipse( $im, 300 + 60 * $variant, 170, 130, 130, imagecolorallocatealpha( $im, 255, 244, 210, 50 ) );

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			imagedestroy( $im );
			return 0;
		}
		$file = trailingslashit( $upload['path'] ) . wp_unique_filename( $upload['path'], 'flexo-' . $name . '.jpg' );
		imagejpeg( $im, $file, 80 );
		imagedestroy( $im );
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $name,
				'post_status'    => 'inherit',
			),
			$file,
			$room_id
		);
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		update_post_meta( $id, '_flexo_demo', '1' );
		update_post_meta( $id, '_wp_attachment_image_alt', __( 'Sample photo', 'flexo-booking' ) );
		return (int) $id;
	}

	/**
	 * Deletes the demo rooms and their sample photos. Rooms with bookings
	 * (which demo rooms cannot get, but still) are kept.
	 *
	 * @return array { removed: int, kept: int }
	 */
	public static function remove() {
		global $wpdb;
		$removed = 0;
		$kept    = 0;
		$table   = Flexo_Booking_Schema::table( 'bookings' );
		foreach ( self::ids() as $id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from the schema.
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE room_id = %d", $id ) ) > 0 ) {
				++$kept;
				continue;
			}
			$photos = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_parent'    => $id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_flexo_demo', // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of photos.
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $photos as $photo ) {
				wp_delete_attachment( $photo, true );
			}
			Flexo_Booking_Seasons::delete_for_room( $id );
			wp_delete_post( $id, true );
			++$removed;
		}
		return array(
			'removed' => $removed,
			'kept'    => $kept,
		);
	}

	public static function handle_add() {
		check_admin_referer( self::ACTION_ADD );
		if ( ! current_user_can( 'publish_flexo_rooms' ) || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'flexo-booking' ), 403 );
		}
		set_transient( 'flexo_booking_demo_notice_' . get_current_user_id(), array( 'added', self::add() ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) );
		exit;
	}

	public static function handle_remove() {
		check_admin_referer( self::ACTION_REMOVE );
		if ( ! current_user_can( 'delete_flexo_rooms' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'flexo-booking' ), 403 );
		}
		set_transient( 'flexo_booking_demo_notice_' . get_current_user_id(), array( 'removed', self::remove() ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) );
		exit;
	}

	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . Flexo_Booking_Rooms::POST_TYPE !== $screen->id ) {
			return;
		}
		$key    = 'flexo_booking_demo_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		if ( 'added' === $notice[0] ) {
			/* translators: %d: number of rooms */
			$text = sprintf( _n( '%d demo room added. Only you and your team see it on the website, and it cannot be booked.', '%d demo rooms added. Only you and your team see them on the website, and they cannot be booked.', $notice[1], 'flexo-booking' ), $notice[1] );
		} else {
			/* translators: %d: number of rooms */
			$text = sprintf( _n( '%d demo room removed.', '%d demo rooms removed.', $notice[1]['removed'], 'flexo-booking' ), $notice[1]['removed'] );
			if ( $notice[1]['kept'] ) {
				$text .= ' ' . __( 'Rooms with bookings were kept.', 'flexo-booking' );
			}
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
