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
	 * Ready-made amenities a room can list (key => label). Their icons and
	 * the owner's own amenities are in Flexo_Booking_Room_Content.
	 */
	public static function amenities() {
		$labels = array();
		foreach ( Flexo_Booking_Room_Content::amenity_presets() as $key => $preset ) {
			$labels[ $key ] = $preset[0];
		}
		return apply_filters( 'flexo_booking_amenities', $labels );
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
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Rooms', 'flexo-booking' ),
					'singular_name' => __( 'Room', 'flexo-booking' ),
					'add_new'       => __( 'Add room', 'flexo-booking' ),
					'add_new_item'  => __( 'Add new room', 'flexo-booking' ),
					'view_item'     => __( 'View room page', 'flexo-booking' ),
					'item_updated'  => __( 'Room saved.', 'flexo-booking' ),
					'edit_item'     => __( 'Edit room', 'flexo-booking' ),
					'all_items'     => __( 'Rooms & prices', 'flexo-booking' ),
					'search_items'  => __( 'Search rooms', 'flexo-booking' ),
					'not_found'     => __( 'No rooms yet. Add your first room – guests can only book the rooms listed here.', 'flexo-booking' ),
				),
				// Each room has its own page (/rooms/deluxe-double/), designed
				// with an Elementor Pro single template. The room screen stays
				// the classic editor: the description lives in post_content.
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => Flexo_Booking_Admin::MENU_SLUG,
				'show_in_nav_menus'   => true,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => Flexo_Booking_Room_Pages::base(),
					'with_front' => false,
					'feeds'      => false,
					'pages'      => false,
				),
				'query_var'           => self::POST_TYPE,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes' ),
				'taxonomies'          => array( Flexo_Booking_Room_Content::TAXONOMY ),
				'map_meta_cap'        => true,
				// Own capabilities, given to whoever manages prices (see Flexo_Booking_Roles).
				'capability_type'     => array( 'flexo_room', 'flexo_rooms' ),
			)
		);
	}

	/**
	 * The room screen's cards are added by Flexo_Booking_Room_Editor; this
	 * is the "Prices" card.
	 */
	public static function render_meta_box( $post ) {
		$room = self::to_array( $post );
		if ( 'auto-draft' === $post->post_status ) {
			$room['price'] = '';
		}
		$currency = Flexo_Booking_Settings::get( 'currency' );
		?>
		<input type="hidden" name="flexo_room_cards[]" value="prices">
		<div class="flexo-fields">
			<p class="flexo-field">
				<label for="flexo-price"><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?></label>
				<span class="flexo-input-unit"><input id="flexo-price" type="number" min="0" step="0.01" name="_flexo_price" value="<?php echo esc_attr( $room['price'] ); ?>" required><span><?php echo esc_html( $currency ); ?></span></span>
			</p>
			<p class="flexo-field">
				<label for="flexo-weekend-price"><?php esc_html_e( 'Weekend price per night', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></label>
				<span class="flexo-input-unit"><input id="flexo-weekend-price" type="number" min="0" step="0.01" name="_flexo_weekend_price" value="<?php echo esc_attr( $room['weekend_price'] ? $room['weekend_price'] : '' ); ?>"><span><?php echo esc_html( $currency ); ?></span></span>
				<span class="description"><?php esc_html_e( 'Applies to Friday and Saturday nights.', 'flexo-booking' ); ?></span>
			</p>
		</div>
		<?php if ( Flexo_Booking_Children::enabled() ) : ?>
			<?php $flexo_own_rules = Flexo_Booking_Children::room_rules( $post->ID ); ?>
			<?php $flexo_rules = $flexo_own_rules ? $flexo_own_rules : Flexo_Booking_Children::global_rules(); ?>
			<div class="flexo-subsection">
				<h4><?php esc_html_e( 'Child prices', 'flexo-booking' ); ?></h4>
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
			</div>
		<?php endif; ?>
		<?php if ( Flexo_Booking_Rate_Plans::enabled() ) : ?>
			<div class="flexo-subsection">
				<h4><?php esc_html_e( 'Rates', 'flexo-booking' ); ?></h4>
				<p>
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
				</p>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Rate_Plans_Admin::SLUG ) ); ?>"><?php esc_html_e( 'Choose rate plans for this room', 'flexo-booking' ); ?></a></p>
			</div>
		<?php endif; ?>
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
			// A field that is not on the screen (e.g. max adults while
			// "Children & ages" is off) keeps its saved value. Amenities are
			// saved by the Amenities card.
			if ( ! isset( $_POST[ $key ] ) || 'list' === self::META[ $key ] ) {
				continue;
			}
			$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
		self::save_meta_values( $post_id, $values );
		Flexo_Booking_Room_Editor::save( $post_id );

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
			$slug  = sanitize_title( $id_or_slug );
			$posts = get_posts(
				array(
					'post_type'       => self::POST_TYPE,
					'name'            => $slug,
					'post_status'     => 'publish',
					'posts_per_page'  => 1,
					'flexo_all_rooms' => true,
				)
			);
			if ( ! $posts ) {
				// A link made before the room's slug was changed.
				$posts = get_posts(
					array(
						'post_type'       => self::POST_TYPE,
						'post_status'     => 'publish',
						'posts_per_page'  => 1,
						'meta_key'        => '_wp_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery -- only when the slug is not found.
						'meta_value'      => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
						'flexo_all_rooms' => true,
					)
				);
			}
			$post = $posts ? $posts[0] : null;
		}
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}

	/**
	 * All rooms, including those not shown on the website (the booking
	 * engine and the admin need them).
	 *
	 * @return WP_Post[]
	 */
	public static function all( $status = 'publish' ) {
		return get_posts(
			array(
				'post_type'       => self::POST_TYPE,
				'post_status'     => $status,
				'posts_per_page'  => -1,
				'orderby'         => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'flexo_all_rooms' => true,
			)
		);
	}

	/**
	 * Rooms guests can book from the booking form's list: published, shown
	 * on the website and not demo rooms. (Hidden rooms can still be booked
	 * through a link with their slug, or by staff.)
	 *
	 * @return WP_Post[]
	 */
	public static function bookable() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $post ) {
					return ! Flexo_Booking_Room_Content::is_hidden( $post->ID ) && ! Flexo_Booking_Room_Content::is_demo( $post->ID );
				}
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
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}
			if ( 'title' === $key ) {
				$out['flexo_photo'] = '<span class="screen-reader-text">' . esc_html__( 'Photo', 'flexo-booking' ) . '</span>';
			}
			$out[ $key ] = $label;
		}
		$out['flexo_price']    = __( 'Price / night', 'flexo-booking' );
		$out['flexo_capacity'] = __( 'Max guests', 'flexo-booking' );
		$out['flexo_units']    = __( 'Rooms', 'flexo-booking' );
		$out['flexo_website']  = __( 'On the website', 'flexo-booking' );
		return $out;
	}

	public static function column_content( $column, $post_id ) {
		$post = get_post( $post_id );
		$room = self::to_array( $post );
		switch ( $column ) {
			case 'flexo_photo':
				$image = Flexo_Booking_Room_Content::gallery( $post_id );
				echo $image ? wp_get_attachment_image( $image[0], 'thumbnail', false, array( 'class' => 'flexo-room-thumb', 'alt' => '' ) ) : '<span class="flexo-room-thumb" aria-hidden="true"></span>';
				break;
			case 'flexo_price':
				echo esc_html( Flexo_Booking_Money::format( $room['price'] ) );
				break;
			case 'flexo_capacity':
				echo esc_html( $room['capacity'] );
				break;
			case 'flexo_units':
				echo esc_html( $room['units'] );
				break;
			case 'flexo_website':
				$url = Flexo_Booking_Room_Content::page_url( $post );
				if ( Flexo_Booking_Room_Content::is_demo( $post_id ) ) {
					echo '<span class="flexo-badge flexo-badge--pay">' . esc_html__( 'Demo room', 'flexo-booking' ) . '</span>';
				} elseif ( Flexo_Booking_Room_Content::is_hidden( $post_id ) ) {
					echo '<span class="flexo-badge flexo-badge--blocked">' . esc_html__( 'Not shown', 'flexo-booking' ) . '</span>';
				} elseif ( $url ) {
					echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( wp_make_link_relative( $url ) ) . '</a>';
				} else {
					echo '<span class="flexo-muted">' . esc_html__( 'After publishing', 'flexo-booking' ) . '</span>';
				}
				if ( $room['slug'] && 'publish' === $post->post_status ) {
					echo '<div class="row-actions visible"><button type="button" class="button-link" data-flexo-copy-text="' . esc_attr( Flexo_Booking_Room_Content::booking_url( $room['slug'] ) ) . '">' . esc_html__( 'Copy booking link', 'flexo-booking' ) . '</button>';
					if ( $url ) {
						echo ' | <button type="button" class="button-link" data-flexo-copy-text="' . esc_attr( $url ) . '">' . esc_html__( 'Copy page link', 'flexo-booking' ) . '</button>';
					}
					echo '</div>';
				}
				break;
		}
	}
}
