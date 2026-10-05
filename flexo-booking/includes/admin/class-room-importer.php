<?php
/**
 * Rooms & prices → Bring in rooms: copies rooms from another post type
 * (typically a JetEngine "rooms" post type) into Flexo Booking, with the
 * same addresses (slugs), photos, texts, facts and amenities, so the Jet
 * plugins can be removed afterwards.
 *
 * Works with JetEngine switched off: the old posts and their fields are
 * read straight from the database.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Importer {

	const SLUG   = 'flexo-booking-import-rooms';
	const ACTION = 'flexo_booking_import_rooms';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Bring in rooms', 'flexo-booking' ), __( 'Bring in rooms', 'flexo-booking' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Flexo Booking fields a source field can fill: key => label.
	 */
	public static function targets() {
		return array(
			''              => __( '— Leave out —', 'flexo-booking' ),
			'description'   => __( 'Full description', 'flexo-booking' ),
			'excerpt'       => __( 'Short description', 'flexo-booking' ),
			'price'         => __( 'Price per night (only if the room has none)', 'flexo-booking' ),
			'weekend_price' => __( 'Weekend price per night', 'flexo-booking' ),
			'size'          => __( 'Size (m²)', 'flexo-booking' ),
			'capacity'      => __( 'Max guests', 'flexo-booking' ),
			'beds'          => __( 'Beds', 'flexo-booking' ),
			'view'          => __( 'View', 'flexo-booking' ),
			'amenity'       => __( 'Amenity', 'flexo-booking' ),
			'gallery'       => __( 'Gallery', 'flexo-booking' ),
			'units'         => __( 'Identical rooms', 'flexo-booking' ),
			'min_nights'    => __( 'Minimum nights', 'flexo-booking' ),
			'detail'        => __( 'More details (a line with the field\'s name)', 'flexo-booking' ),
		);
	}

	/**
	 * Suggested target for a field name.
	 */
	public static function suggest( $key ) {
		$k = strtolower( $key );
		$map = array(
			'/^(long_)?description$|room_description|^content$|full_description/' => 'description',
			'/short_description|^excerpt$|^summary$|^intro$/'                    => 'excerpt',
			'/weekend/'                                                          => 'weekend_price',
			'/price/'                                                            => 'price',
			'/size|area|square/'                                                 => 'size',
			'/max_guests|guests|capacity|persons|people|occupancy/'              => 'capacity',
			'/bed/'                                                              => 'beds',
			'/view/'                                                             => 'view',
			'/amenit|facilit|feature/'                                           => 'amenity',
			'/gallery|photos|images/'                                            => 'gallery',
			'/units|rooms_count|quantity|number_of_rooms/'                       => 'units',
			'/min(imum)?_?(nights|stay)/'                                        => 'min_nights',
		);
		foreach ( $map as $pattern => $target ) {
			if ( preg_match( $pattern, $k ) ) {
				return $target;
			}
		}
		return '';
	}

	/**
	 * Post types that could hold rooms: not WordPress's own, not ours,
	 * registered or not (JetEngine may be switched off).
	 *
	 * @return array slug => array( label, count )
	 */
	public static function sources() {
		global $wpdb;
		$skip = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'elementor_library', 'e-landing-page', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'jet-engine', 'jet-menu', 'jet-smart-filters', 'jet-theme-core', 'jet-popup', 'jet-woo-builder', 'jet-engine-booking', Flexo_Booking_Rooms::POST_TYPE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin screen.
		$rows = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','pending','future') GROUP BY post_type ORDER BY n DESC" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( in_array( $row->post_type, $skip, true ) || 0 === strpos( $row->post_type, 'jet-' ) || 0 === strpos( $row->post_type, 'wp_' ) ) {
				continue;
			}
			$object = get_post_type_object( $row->post_type );
			$out[ $row->post_type ] = array( $object ? $object->labels->name : $row->post_type, (int) $row->n );
		}
		return $out;
	}

	public static function default_source( array $sources ) {
		foreach ( array( 'rooms', 'room', 'accommodation', 'accommodations', 'villas' ) as $guess ) {
			if ( isset( $sources[ $guess ] ) ) {
				return $guess;
			}
		}
		return $sources ? (string) key( $sources ) : '';
	}

	/**
	 * @return WP_Post[] Posts of the source post type.
	 */
	public static function posts( $type ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- works when the post type is not registered.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','private','pending','future') ORDER BY menu_order ASC, post_title ASC LIMIT 200", $type ) );
		return array_filter( array_map( 'get_post', $ids ) );
	}

	/**
	 * Fields used by the source posts, with an example value.
	 *
	 * @return array key => example
	 */
	public static function fields( array $posts ) {
		$out = array();
		foreach ( $posts as $post ) {
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( isset( $out[ $key ] ) && '' !== $out[ $key ] ) {
					continue;
				}
				if ( '_' === $key[0] || 0 === strpos( $key, 'elementor' ) || 0 === strpos( $key, 'jet_' ) || 0 === strpos( $key, 'rank_math' ) || 0 === strpos( $key, 'pll_' ) ) {
					continue;
				}
				$value       = maybe_unserialize( $values[0] );
				$out[ $key ] = is_scalar( $value ) ? wp_html_excerpt( wp_strip_all_tags( (string) $value ), 60, '…' ) : wp_html_excerpt( wp_json_encode( $value ), 60, '…' );
			}
		}
		uksort( $out, 'strnatcasecmp' );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Reading values
	 * ------------------------------------------------------------------ */

	private static function number( $value, $largest = false ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}
		$text = str_replace( array( "\xC2\xA0", ' ' ), '', (string) $value );
		if ( ! preg_match_all( '/\d+(?:[.,]\d+)?/', $text, $m ) ) {
			return 0;
		}
		$numbers = array_map(
			static function ( $n ) {
				return (float) str_replace( ',', '.', $n );
			},
			$m[0]
		);
		return $largest ? max( $numbers ) : $numbers[0];
	}

	/**
	 * Amenity names from a field: text, list, or a checkbox field
	 * ( option => "true" ).
	 *
	 * @return string[]
	 */
	private static function amenity_names( $value ) {
		if ( is_scalar( $value ) ) {
			$value = (string) $value;
			return '' === trim( $value ) ? array() : array_map( 'trim', preg_split( '/\s*[\n;]\s*/', $value ) );
		}
		$out = array();
		foreach ( (array) $value as $k => $v ) {
			if ( is_string( $k ) && ( 'true' === $v || true === $v || '1' === $v ) ) {
				$out[] = $k;
			} elseif ( is_scalar( $v ) && ! in_array( $v, array( 'true', 'false', '' ), true ) ) {
				$out[] = (string) $v;
			}
		}
		return array_filter( array_map( 'trim', $out ) );
	}

	/**
	 * Attachment IDs from a gallery field: "12,13", array of IDs, array of
	 * { id, url }, or addresses of images in this site's media library.
	 *
	 * @return int[]
	 */
	private static function gallery_ids( $value ) {
		$items = is_array( $value ) ? $value : preg_split( '/\s*,\s*/', (string) $value );
		$out   = array();
		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$item = isset( $item['id'] ) ? $item['id'] : ( isset( $item['url'] ) ? $item['url'] : '' );
			}
			if ( is_numeric( $item ) ) {
				$out[] = (int) $item;
			} elseif ( is_string( $item ) && preg_match( '#^https?://#', $item ) ) {
				$id = attachment_url_to_postid( $item );
				if ( $id ) {
					$out[] = $id;
				}
			}
		}
		return Flexo_Booking_Room_Content::sanitize_gallery( $out );
	}

	/* ------------------------------------------------------------------ *
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Brings the rooms in.
	 *
	 * @param string $type    Source post type.
	 * @param array  $mapping Source field => target.
	 * @param array  $options { content: bool – use the post text when no description field is mapped }.
	 * @return array Results: list of array( title, slug, result, edit ).
	 */
	public static function import( $type, array $mapping, array $options = array() ) {
		$targets = self::targets();
		$results = array();
		foreach ( self::posts( $type ) as $source ) {
			$lang = function_exists( 'pll_get_post_language' ) && function_exists( 'pll_default_language' ) ? pll_get_post_language( $source->ID ) : '';
			if ( $lang && pll_default_language() && $lang !== pll_default_language() ) {
				$results[] = array( $source->post_title, $source->post_name, __( 'Translation – left out (add translations to the Flexo Booking room)', 'flexo-booking' ), '' );
				continue;
			}
			$existing = get_posts(
				array(
					'post_type'       => Flexo_Booking_Rooms::POST_TYPE,
					'name'            => $source->post_name,
					'post_status'     => 'any',
					'posts_per_page'  => 1,
					'flexo_all_rooms' => true,
				)
			);
			$values   = array(
				'description' => '',
				'excerpt'     => '',
				'amenities'   => array(),
				'gallery'     => array(),
				'details'     => array(),
				'meta'        => array(),
				'view'        => '',
			);
			foreach ( $mapping as $field => $target ) {
				if ( '' === $target || ! isset( $targets[ $target ] ) ) {
					continue;
				}
				$raw = maybe_unserialize( get_post_meta( $source->ID, $field, true ) );
				if ( '' === $raw || null === $raw || array() === $raw ) {
					continue;
				}
				switch ( $target ) {
					case 'description':
						$values['description'] = wp_kses_post( is_scalar( $raw ) ? (string) $raw : '' );
						break;
					case 'excerpt':
						$values['excerpt'] = sanitize_textarea_field( is_scalar( $raw ) ? (string) $raw : '' );
						break;
					case 'price':
					case 'weekend_price':
						$values['meta'][ '_flexo_' . $target ] = self::number( $raw );
						break;
					case 'size':
						$values['meta']['_flexo_size'] = (int) round( self::number( $raw ) );
						break;
					case 'capacity':
						$values['meta']['_flexo_capacity'] = (int) self::number( $raw, true );
						break;
					case 'units':
					case 'min_nights':
						$values['meta'][ '_flexo_' . $target ] = (int) self::number( $raw );
						break;
					case 'beds':
						$values['meta']['_flexo_beds'] = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : implode( ', ', self::amenity_names( $raw ) ) );
						break;
					case 'view':
						$values['view'] = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
						break;
					case 'amenity':
						foreach ( self::amenity_names( $raw ) as $name ) {
							$key                   = Flexo_Booking_Room_Content::preset_for_label( $name );
							$values['amenities'][] = array(
								'key'   => $key,
								'label' => $key ? '' : $name,
								'icon'  => $key ? '' : Flexo_Booking_Room_Content::guess_icon( $name ),
							);
						}
						break;
					case 'gallery':
						$values['gallery'] = array_merge( $values['gallery'], self::gallery_ids( $raw ) );
						break;
					case 'detail':
						if ( is_scalar( $raw ) ) {
							$values['details'][] = array(
								'icon'  => '',
								'label' => ucfirst( str_replace( array( '_', '-' ), ' ', $field ) ),
								'value' => sanitize_text_field( (string) $raw ),
							);
						}
						break;
				}
			}
			if ( '' === $values['description'] && ! empty( $options['content'] ) ) {
				$values['description'] = wp_kses_post( $source->post_content );
			}

			$postarr = array(
				'post_type'   => Flexo_Booking_Rooms::POST_TYPE,
				'post_title'  => $source->post_title,
				'post_name'   => $source->post_name,
				'post_status' => in_array( $source->post_status, array( 'publish', 'draft', 'private' ), true ) ? $source->post_status : 'draft',
				'menu_order'  => (int) $source->menu_order,
			);
			if ( '' !== $values['description'] ) {
				$postarr['post_content'] = $values['description'];
			}
			if ( '' !== $values['excerpt'] ) {
				$postarr['post_excerpt'] = $values['excerpt'];
			} elseif ( '' !== $source->post_excerpt ) {
				$postarr['post_excerpt'] = $source->post_excerpt;
			}
			if ( $existing ) {
				$postarr['ID'] = $existing[0]->ID;
				$room_id       = wp_update_post( $postarr, true );
				$result        = __( 'Updated', 'flexo-booking' );
			} else {
				$room_id = wp_insert_post( $postarr, true );
				$result  = __( 'Added', 'flexo-booking' );
			}
			if ( is_wp_error( $room_id ) ) {
				$results[] = array( $source->post_title, $source->post_name, $room_id->get_error_message(), '' );
				continue;
			}

			// Prices only when the room has none yet; the other facts always.
			$meta = $values['meta'];
			foreach ( array( '_flexo_price', '_flexo_weekend_price' ) as $price ) {
				if ( isset( $meta[ $price ] ) && (float) get_post_meta( $room_id, $price, true ) > 0 ) {
					unset( $meta[ $price ] );
				}
			}
			if ( ! $existing && ! isset( $meta['_flexo_capacity'] ) ) {
				$meta['_flexo_capacity'] = 2;
			}
			if ( ! $existing && ! isset( $meta['_flexo_units'] ) ) {
				$meta['_flexo_units'] = 1;
			}
			Flexo_Booking_Rooms::save_meta_values( $room_id, $meta );
			if ( '' !== $values['view'] ) {
				update_post_meta( $room_id, Flexo_Booking_Room_Content::VIEW, mb_substr( $values['view'], 0, 100 ) );
			}
			if ( $values['amenities'] ) {
				Flexo_Booking_Room_Editor::save_amenities( $room_id, $values['amenities'] );
			}
			if ( $values['details'] ) {
				update_post_meta( $room_id, Flexo_Booking_Room_Content::DETAILS, Flexo_Booking_Room_Content::sanitize_details( $values['details'] ) );
			}
			$thumb = (int) get_post_thumbnail_id( $source->ID );
			if ( $thumb && ! has_post_thumbnail( $room_id ) ) {
				set_post_thumbnail( $room_id, $thumb );
			}
			if ( $values['gallery'] ) {
				update_post_meta( $room_id, Flexo_Booking_Room_Content::GALLERY, array_values( array_diff( Flexo_Booking_Room_Content::sanitize_gallery( $values['gallery'] ), array( (int) get_post_thumbnail_id( $room_id ) ) ) ) );
			}
			update_post_meta( $room_id, '_flexo_imported_from', $type . ':' . $source->ID );
			$results[] = array( $source->post_title, $source->post_name, $result, get_edit_post_link( $room_id, 'raw' ) );
		}
		return $results;
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'flexo-booking' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$type    = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$mapping = array();
		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( wp_unslash( $_POST['map'] ) as $field => $target ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
				$mapping[ sanitize_text_field( $field ) ] = sanitize_key( $target );
			}
		}
		$results = '' !== $type && isset( self::sources()[ $type ] ) ? self::import( $type, $mapping, array( 'content' => ! empty( $_POST['use_content'] ) ) ) : array();
		set_transient( 'flexo_booking_room_import_' . get_current_user_id(), array( 'type' => $type, 'results' => $results ), 600 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'done' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Screen
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sources = self::sources();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing what to show.
		$type = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : self::default_source( $sources );
		$done = ! empty( $_GET['done'] ) ? get_transient( 'flexo_booking_room_import_' . get_current_user_id() ) : false;
		// phpcs:enable
		?>
		<div class="wrap flexo-admin flexo-room-import">
			<?php
			Flexo_Booking_Admin_UI::page_head(
				array(
					'title' => __( 'Bring in rooms', 'flexo-booking' ),
					'icon'  => 'transfer',
					'intro' => esc_html__( 'Copy rooms you made with another plugin (for example JetEngine) into Flexo Booking – with the same addresses, photos, texts, facts and amenities. Nothing is deleted.', 'flexo-booking' ),
					'back'  => array( __( 'Rooms & prices', 'flexo-booking' ), admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ),
				)
			);
			?>
			<?php if ( is_array( $done ) ) : ?>
				<?php self::render_results( $done ); ?>
			<?php endif; ?>

			<?php if ( ! $sources ) : ?>
				<div class="flexo-card"><p><?php esc_html_e( 'No other rooms were found on this site. Rooms made with JetEngine show up here even when JetEngine is switched off.', 'flexo-booking' ); ?></p></div>
				</div>
				<?php
				return;
			endif;
			$posts  = isset( $sources[ $type ] ) ? self::posts( $type ) : array();
			$fields = self::fields( $posts );
			?>
			<form method="get" class="flexo-card flexo-room-import__source">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="flexo-import-source"><strong><?php esc_html_e( '1. Where are the rooms now?', 'flexo-booking' ); ?></strong></label>
				<select id="flexo-import-source" name="source" onchange="this.form.submit()">
					<?php foreach ( $sources as $slug => $info ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>>
							<?php
							/* translators: 1: post type name, 2: post type key, 3: number of posts */
							echo esc_html( sprintf( __( '%1$s (%2$s) – %3$d', 'flexo-booking' ), $info[0], $slug, $info[1] ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'Show', 'flexo-booking' ); ?></button></noscript>
			</form>

			<?php if ( $posts ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-card">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="source" value="<?php echo esc_attr( $type ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<h2><?php esc_html_e( '2. Which field goes where?', 'flexo-booking' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Suggestions are filled in from the field names. The room name, address (slug), main photo and order are always copied.', 'flexo-booking' ); ?></p>
					<?php if ( $fields ) : ?>
						<table class="widefat striped flexo-room-import__map">
							<thead><tr><th><?php esc_html_e( 'Field', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Example', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Goes to', 'flexo-booking' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $fields as $field => $example ) : ?>
								<tr>
									<td><code><?php echo esc_html( $field ); ?></code></td>
									<td><?php echo esc_html( $example ); ?></td>
									<td>
										<select name="map[<?php echo esc_attr( $field ); ?>]" aria-label="<?php echo esc_attr( $field ); ?>">
											<?php foreach ( self::targets() as $target => $label ) : ?>
												<option value="<?php echo esc_attr( $target ); ?>" <?php selected( self::suggest( $field ), $target ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<p><label><input type="checkbox" name="use_content" value="1" checked> <?php esc_html_e( 'Use the post\'s own text as the full description when no field is chosen for it', 'flexo-booking' ); ?></label></p>

					<h2>
						<?php
						/* translators: %d: number of rooms */
						echo esc_html( sprintf( _n( '3. Bring in %d room', '3. Bring in %d rooms', count( $posts ), 'flexo-booking' ), count( $posts ) ) );
						?>
					</h2>
					<ul class="flexo-room-import__list">
						<?php foreach ( $posts as $post ) : ?>
							<?php $match = get_page_by_path( $post->post_name, OBJECT, Flexo_Booking_Rooms::POST_TYPE ); ?>
							<li><strong><?php echo esc_html( $post->post_title ); ?></strong> <code>/<?php echo esc_html( Flexo_Booking_Room_Pages::base() . '/' . $post->post_name ); ?>/</code> <span class="flexo-muted"><?php echo esc_html( $match ? __( 'updates the Flexo Booking room with this address (its prices are kept)', 'flexo-booking' ) : __( 'new room', 'flexo-booking' ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<?php submit_button( __( 'Bring in the rooms', 'flexo-booking' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_results( array $done ) {
		$type    = $done['type'];
		$results = $done['results'];
		$clash   = post_type_exists( $type ) && ( $object = get_post_type_object( $type ) ) && is_array( $object->rewrite ) && isset( $object->rewrite['slug'] ) && trim( $object->rewrite['slug'], '/' ) === Flexo_Booking_Room_Pages::base(); // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found
		?>
		<div class="flexo-card flexo-room-import__done">
			<h2><?php esc_html_e( 'Done', 'flexo-booking' ); ?></h2>
			<?php if ( $results ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Address', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Result', 'flexo-booking' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $results as $row ) : ?>
						<tr>
							<td><?php echo $row[3] ? '<a href="' . esc_url( $row[3] ) . '">' . esc_html( $row[0] ) . '</a>' : esc_html( $row[0] ); ?></td>
							<td><code>/<?php echo esc_html( Flexo_Booking_Room_Pages::base() . '/' . $row[1] ); ?>/</code></td>
							<td><?php echo esc_html( $row[2] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No rooms were brought in.', 'flexo-booking' ); ?></p>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Next steps', 'flexo-booking' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Check each room: prices, number of identical rooms and amenity icons.', 'flexo-booking' ); ?></li>
				<li><?php esc_html_e( 'Point your Elementor single room template at Flexo Booking (dynamic tags "Flexo Booking: room") and set its display condition to Rooms.', 'flexo-booking' ); ?></li>
				<li>
					<?php
					if ( $clash ) {
						esc_html_e( 'Important: the old room post type still uses the same address. Switch it off in JetEngine (or switch JetEngine off) so the room pages show the Flexo Booking rooms.', 'flexo-booking' );
					} else {
						esc_html_e( 'When everything looks right, you can switch off and remove JetEngine and its add-ons.', 'flexo-booking' );
					}
					?>
				</li>
			</ol>
		</div>
		<?php
	}
}
