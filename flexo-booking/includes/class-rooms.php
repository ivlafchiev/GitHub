<?php
/**
 * Room types (custom post type "flexo_room") and their booking details.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Rooms {

	const POST_TYPE = 'flexo_room';

	/**
	 * Meta keys and their sanitizers. Also used by the import/export tool.
	 */
	const META = array(
		'_flexo_price'         => 'float',
		'_flexo_weekend_price' => 'float',
		'_flexo_capacity'      => 'int',
		'_flexo_units'         => 'int',
		'_flexo_min_nights'    => 'int',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'          => __( 'Rooms', 'flexo-booking' ),
					'singular_name' => __( 'Room', 'flexo-booking' ),
					'add_new'       => __( 'Add room', 'flexo-booking' ),
					'add_new_item'  => __( 'Add new room', 'flexo-booking' ),
					'edit_item'     => __( 'Edit room', 'flexo-booking' ),
					'all_items'     => __( 'Rooms', 'flexo-booking' ),
					'search_items'  => __( 'Search rooms', 'flexo-booking' ),
					'not_found'     => __( 'No rooms yet.', 'flexo-booking' ),
				),
				// The room pages themselves are designed in Elementor, so the
				// post type is only a data source for the booking engine.
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => Flexo_Booking_Admin::MENU_SLUG,
				'show_in_rest'       => false,
				'publicly_queryable' => false,
				'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'map_meta_cap'       => true,
				'capability_type'    => 'page',
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box( 'flexo-room-details', __( 'Booking details', 'flexo-booking' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		$room = self::to_array( $post );
		wp_nonce_field( 'flexo_room_meta', 'flexo_room_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="flexo-price"><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?></label></th>
				<td><input id="flexo-price" type="number" min="0" step="0.01" name="_flexo_price" value="<?php echo esc_attr( $room['price'] ); ?>"> <?php echo esc_html( Flexo_Booking_Settings::get( 'currency' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="flexo-weekend-price"><?php esc_html_e( 'Weekend price per night', 'flexo-booking' ); ?></label></th>
				<td>
					<input id="flexo-weekend-price" type="number" min="0" step="0.01" name="_flexo_weekend_price" value="<?php echo esc_attr( $room['weekend_price'] ? $room['weekend_price'] : '' ); ?>"> <?php echo esc_html( Flexo_Booking_Settings::get( 'currency' ) ); ?>
					<p class="description"><?php esc_html_e( 'Optional. Applies to Friday and Saturday nights.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="flexo-capacity"><?php esc_html_e( 'Max guests', 'flexo-booking' ); ?></label></th>
				<td><input id="flexo-capacity" type="number" min="1" name="_flexo_capacity" value="<?php echo esc_attr( $room['capacity'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="flexo-units"><?php esc_html_e( 'Number of rooms of this type', 'flexo-booking' ); ?></label></th>
				<td>
					<input id="flexo-units" type="number" min="0" name="_flexo_units" value="<?php echo esc_attr( $room['units'] ); ?>">
					<p class="description"><?php esc_html_e( 'How many identical rooms can be booked for the same night. Set to 0 to stop taking bookings for this room.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="flexo-min-nights"><?php esc_html_e( 'Minimum nights', 'flexo-booking' ); ?></label></th>
				<td>
					<input id="flexo-min-nights" type="number" min="0" name="_flexo_min_nights" value="<?php echo esc_attr( $room['min_nights_override'] ? $room['min_nights_override'] : '' ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Leave empty to use the global setting.', 'flexo-booking' ); ?><?php echo Flexo_Booking_Seasons::enabled() ? ' ' . esc_html__( 'Seasons can set their own minimum.', 'flexo-booking' ) : ''; ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '"Book now" link', 'flexo-booking' ); ?></th>
				<td>
					<code>/booking/?room=<?php echo esc_html( $post->post_name ? $post->post_name : '…' ); ?></code>
					<p class="description"><?php esc_html_e( 'Point a button on your room page to your booking page with ?room=<slug> to preselect this room. Replace /booking/ with your booking page path.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['flexo_room_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['flexo_room_nonce'] ), 'flexo_room_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$values = array();
		foreach ( array_keys( self::META ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		self::save_meta_values( $post_id, $values );
	}

	/**
	 * Stores room meta from a raw key => value array (form or import).
	 */
	public static function save_meta_values( $post_id, array $values ) {
		foreach ( self::META as $key => $type ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];
			if ( '' === $value || null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}
			$value = 'float' === $type ? max( 0, round( (float) $value, 2 ) ) : absint( $value );
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Finds a published room by ID or slug. Slugs are preferred in links and
	 * widget settings because they survive moving content between sites.
	 *
	 * @param int|string $id_or_slug Room ID or slug.
	 * @return WP_Post|null
	 */
	public static function find( $id_or_slug ) {
		if ( empty( $id_or_slug ) ) {
			return null;
		}
		if ( is_numeric( $id_or_slug ) ) {
			$post = get_post( (int) $id_or_slug );
		} else {
			$posts = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'name'           => sanitize_title( $id_or_slug ),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
				)
			);
			$post  = $posts ? $posts[0] : null;
		}
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}

	/**
	 * @return WP_Post[]
	 */
	public static function all( $status = 'publish' ) {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => $status,
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
	}

	public static function to_array( WP_Post $post ) {
		$min = (int) get_post_meta( $post->ID, '_flexo_min_nights', true );
		return array(
			'id'                  => $post->ID,
			'slug'                => $post->post_name,
			'title'               => get_the_title( $post ),
			'excerpt'             => has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
			'image'               => get_the_post_thumbnail_url( $post, 'medium_large' ),
			'price'               => (float) get_post_meta( $post->ID, '_flexo_price', true ),
			'weekend_price'       => (float) get_post_meta( $post->ID, '_flexo_weekend_price', true ),
			'capacity'            => max( 1, (int) get_post_meta( $post->ID, '_flexo_capacity', true ) ),
			'units'               => '' === get_post_meta( $post->ID, '_flexo_units', true ) ? 1 : (int) get_post_meta( $post->ID, '_flexo_units', true ),
			'min_nights_override' => $min,
			'min_nights'          => $min > 0 ? $min : (int) Flexo_Booking_Settings::get( 'min_nights' ),
		);
	}

	public static function columns( $columns ) {
		$date = $columns['date'];
		unset( $columns['date'] );
		$columns['flexo_price']    = __( 'Price / night', 'flexo-booking' );
		$columns['flexo_capacity'] = __( 'Max guests', 'flexo-booking' );
		$columns['flexo_units']    = __( 'Rooms', 'flexo-booking' );
		$columns['flexo_slug']     = __( 'Slug (for links)', 'flexo-booking' );
		$columns['date']           = $date;
		return $columns;
	}

	public static function column_content( $column, $post_id ) {
		$room = self::to_array( get_post( $post_id ) );
		switch ( $column ) {
			case 'flexo_price':
				echo esc_html( Flexo_Booking_Money::format( $room['price'] ) );
				break;
			case 'flexo_capacity':
				echo esc_html( $room['capacity'] );
				break;
			case 'flexo_units':
				echo esc_html( $room['units'] );
				break;
			case 'flexo_slug':
				echo '<code>' . esc_html( $room['slug'] ) . '</code>';
				break;
		}
	}
}
