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
		'_flexo_max_adults'    => 'int',
		// Day 6: shown on the room cards.
		'_flexo_size'          => 'int',
		'_flexo_beds'          => 'text',
		'_flexo_amenities'     => 'list',
	);

	/**
	 * Amenities a room can list (key => label). Room cards show up to 5.
	 */
	public static function amenities() {
		return apply_filters(
			'flexo_booking_amenities',
			array(
				'wifi'            => __( 'Free Wi-Fi', 'flexo-booking' ),
				'air_conditioning' => __( 'Air conditioning', 'flexo-booking' ),
				'private_bathroom' => __( 'Private bathroom', 'flexo-booking' ),
				'balcony'         => __( 'Balcony', 'flexo-booking' ),
				'terrace'         => __( 'Terrace', 'flexo-booking' ),
				'sea_view'        => __( 'Sea view', 'flexo-booking' ),
				'mountain_view'   => __( 'Mountain view', 'flexo-booking' ),
				'garden_view'     => __( 'Garden view', 'flexo-booking' ),
				'kitchen'         => __( 'Kitchen', 'flexo-booking' ),
				'kitchenette'     => __( 'Kitchenette', 'flexo-booking' ),
				'tv'              => __( 'TV', 'flexo-booking' ),
				'minibar'         => __( 'Minibar', 'flexo-booking' ),
				'fridge'          => __( 'Fridge', 'flexo-booking' ),
				'coffee'          => __( 'Coffee / tea maker', 'flexo-booking' ),
				'safe'            => __( 'Safe', 'flexo-booking' ),
				'bathtub'         => __( 'Bathtub', 'flexo-booking' ),
				'heating'         => __( 'Heating', 'flexo-booking' ),
				'washing_machine' => __( 'Washing machine', 'flexo-booking' ),
				'parking'         => __( 'Free parking', 'flexo-booking' ),
				'pets'            => __( 'Pets allowed', 'flexo-booking' ),
				'accessible'      => __( 'Step-free access', 'flexo-booking' ),
				'non_smoking'     => __( 'Non-smoking', 'flexo-booking' ),
			)
		);
	}

	/**
	 * Names of a room's amenities, in the order of the amenity list.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	public static function amenity_labels( array $keys ) {
		$all = self::amenities();
		$out = array();
		foreach ( $all as $key => $label ) {
			if ( in_array( $key, $keys, true ) ) {
				$out[] = $label;
			}
		}
		return $out;
	}

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
					'all_items'     => __( 'Rooms & prices', 'flexo-booking' ),
					'search_items'  => __( 'Search rooms', 'flexo-booking' ),
					'not_found'     => __( 'No rooms yet. Add your first room – guests can only book the rooms listed here.', 'flexo-booking' ),
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
				// Own capabilities, given to whoever manages prices (see Flexo_Booking_Roles).
				'capability_type'    => array( 'flexo_room', 'flexo_rooms' ),
			)
		);
	}

	public static function add_meta_box() {
		add_meta_box( 'flexo-room-details', __( 'Booking details', 'flexo-booking' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		$room = self::to_array( $post );
		// Sensible starting values for a new room (a free, one-guest room is never what a hotel wants).
		if ( 'auto-draft' === $post->post_status ) {
			$room['capacity'] = 2;
			$room['price']    = '';
		}
		wp_nonce_field( 'flexo_room_meta', 'flexo_room_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="flexo-price"><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?></label></th>
				<td><input id="flexo-price" type="number" min="0" step="0.01" name="_flexo_price" value="<?php echo esc_attr( $room['price'] ); ?>" required> <?php echo esc_html( Flexo_Booking_Settings::get( 'currency' ) ); ?></td>
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
				<td>
					<input id="flexo-capacity" type="number" min="1" name="_flexo_capacity" value="<?php echo esc_attr( $room['capacity'] ); ?>">
					<?php if ( Flexo_Booking_Children::enabled() ) : ?>
						<p class="description"><?php esc_html_e( 'Adults and children together, babies included.', 'flexo-booking' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( Flexo_Booking_Children::enabled() ) : ?>
				<tr>
					<th scope="row"><label for="flexo-max-adults"><?php esc_html_e( 'Max adults', 'flexo-booking' ); ?></label></th>
					<td>
						<input id="flexo-max-adults" type="number" min="0" name="_flexo_max_adults" value="<?php echo esc_attr( $room['max_adults'] ? $room['max_adults'] : '' ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. E.g. a family room for 4 guests but at most 2 adults. Leave empty for no separate limit.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
				<?php $flexo_own_rules = Flexo_Booking_Children::room_rules( $post->ID ); ?>
				<?php $flexo_rules = $flexo_own_rules ? $flexo_own_rules : Flexo_Booking_Children::global_rules(); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Child prices', 'flexo-booking' ); ?></th>
					<td>
						<label><input type="checkbox" name="_flexo_child_rules_custom" value="1" <?php checked( (bool) $flexo_own_rules ); ?>> <?php esc_html_e( 'Use different child prices for this room', 'flexo-booking' ); ?></label>
						<p class="flexo-inline-form">
							<label><?php esc_html_e( 'Free under age', 'flexo-booking' ); ?> <input type="number" min="0" max="18" class="small-text" name="_flexo_child_free_under" value="<?php echo esc_attr( $flexo_rules['free_under'] ); ?>"></label>
							<label><?php esc_html_e( 'then pay', 'flexo-booking' ); ?> <input type="number" min="0" max="100" step="0.01" class="small-text" name="_flexo_child_percent" value="<?php echo esc_attr( Flexo_Booking_Children::percent_text( $flexo_rules['percent'] ) ); ?>"> %</label>
							<label><?php esc_html_e( 'adult price from age', 'flexo-booking' ); ?> <input type="number" min="0" max="18" class="small-text" name="_flexo_child_adult_from" value="<?php echo esc_attr( $flexo_rules['adult_from'] ); ?>"></label>
						</p>
						<p class="description">
							<?php
							/* translators: %s: summary of the general child prices */
							printf( esc_html__( 'Otherwise the general rules apply (Settings → Booking rules): %s.', 'flexo-booking' ), esc_html( Flexo_Booking_Children::describe( Flexo_Booking_Children::global_rules() ) ) );
							?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
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
				<th scope="row"><label for="flexo-size"><?php esc_html_e( 'Size', 'flexo-booking' ); ?></label></th>
				<td><input id="flexo-size" type="number" min="0" class="small-text" name="_flexo_size" value="<?php echo esc_attr( $room['size'] ? $room['size'] : '' ); ?>"> m² <span class="description"><?php esc_html_e( 'Optional. Shown on the room card.', 'flexo-booking' ); ?></span></td>
			</tr>
			<tr>
				<th scope="row"><label for="flexo-beds"><?php esc_html_e( 'Beds', 'flexo-booking' ); ?></label></th>
				<td><input id="flexo-beds" type="text" class="regular-text" name="_flexo_beds" value="<?php echo esc_attr( $room['beds'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1 double bed or 2 single beds', 'flexo-booking' ); ?>"> <p class="description"><?php esc_html_e( 'Optional. Shown on the room card.', 'flexo-booking' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Amenities', 'flexo-booking' ); ?></th>
				<td>
					<input type="hidden" name="_flexo_amenities[]" value="">
					<div class="flexo-amenities">
						<?php foreach ( self::amenities() as $flexo_key => $flexo_label ) : ?>
							<label><input type="checkbox" name="_flexo_amenities[]" value="<?php echo esc_attr( $flexo_key ); ?>" <?php checked( in_array( $flexo_key, $room['amenities'], true ) ); ?>> <?php echo esc_html( $flexo_label ); ?></label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'The room card shows the first five you tick, in this order.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<?php if ( Flexo_Booking_Rate_Plans::enabled() ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rates', 'flexo-booking' ); ?></th>
					<td>
						<?php
						$flexo_offers = Flexo_Booking_Rate_Plans::room_assignments( $post->ID );
						$flexo_names  = array();
						foreach ( Flexo_Booking_Rate_Plans::all() as $flexo_plan ) {
							if ( array_key_exists( $flexo_plan['id'], $flexo_offers ) ) {
								$flexo_value   = null === $flexo_offers[ $flexo_plan['id'] ] ? $flexo_plan['adjustment_value'] : $flexo_offers[ $flexo_plan['id'] ];
								$flexo_names[] = $flexo_plan['name'] . ' (' . Flexo_Booking_Rate_Plans::describe_adjustment( $flexo_plan['adjustment_type'], $flexo_value ) . ( $flexo_plan['active'] ? '' : ', ' . __( 'switched off', 'flexo-booking' ) ) . ')';
							}
						}
						echo $flexo_names ? esc_html( implode( ', ', $flexo_names ) ) : esc_html__( 'No rate plans – guests book the room at its normal price.', 'flexo-booking' );
						?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Rate_Plans_Admin::SLUG ) ); ?>"><?php esc_html_e( 'Choose rate plans for this room', 'flexo-booking' ); ?></a></p>
					</td>
				</tr>
			<?php endif; ?>
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
			if ( '_flexo_max_adults' === $key && ! isset( $_POST[ $key ] ) ) {
				continue; // Field hidden while "Children & ages" is off: keep the saved value.
			}
			if ( 'list' === self::META[ $key ] ) {
				$values[ $key ] = isset( $_POST[ $key ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $key ] ) ) : null;
				continue;
			}
			$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}
		self::save_meta_values( $post_id, $values );

		if ( isset( $_POST['_flexo_child_free_under'] ) ) {
			Flexo_Booking_Children::save_room_rules(
				$post_id,
				empty( $_POST['_flexo_child_rules_custom'] ) ? null : array(
					'free_under' => absint( $_POST['_flexo_child_free_under'] ),
					'percent'    => isset( $_POST['_flexo_child_percent'] ) ? (float) sanitize_text_field( wp_unslash( $_POST['_flexo_child_percent'] ) ) : 100,
					'adult_from' => isset( $_POST['_flexo_child_adult_from'] ) ? absint( $_POST['_flexo_child_adult_from'] ) : 18,
				)
			);
		}
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
			if ( 'list' === $type ) {
				$value = array_values( array_intersect( array_keys( self::amenities() ), array_map( 'sanitize_key', (array) $value ) ) );
			}
			if ( '' === $value || null === $value || array() === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}
			if ( 'text' === $type ) {
				$value = substr( sanitize_text_field( $value ), 0, 190 );
			} elseif ( 'float' === $type ) {
				$value = max( 0, round( (float) $value, 2 ) );
			} elseif ( 'int' === $type ) {
				$value = absint( $value );
			}
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
			'max_adults'          => (int) get_post_meta( $post->ID, '_flexo_max_adults', true ),
			'size'                => (int) get_post_meta( $post->ID, '_flexo_size', true ),
			'beds'                => (string) get_post_meta( $post->ID, '_flexo_beds', true ),
			'amenities'           => array_values( array_filter( (array) get_post_meta( $post->ID, '_flexo_amenities', true ) ) ),
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
