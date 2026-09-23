<?php
/**
 * Import / export of booking configuration between sites.
 *
 * A single JSON file carries the settings and all rooms (and optionally the
 * bookings), so a template's booking setup can be copied to every site built
 * from it. Rooms are matched by slug, which is what "Book now" links and the
 * Elementor widget reference, so imported pages keep working.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Portability {

	const FORMAT = 'flexo-booking';

	public static function init() {
		add_action( 'admin_post_flexo_booking_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_flexo_booking_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * @param bool $include_bookings Also export bookings (for moving a live site).
	 */
	public static function export( $include_bookings = false ) {
		$settings = Flexo_Booking_Settings::all();
		// Site-specific: the importing site falls back to its own admin email.
		$settings['notification_email'] = '';

		$data = array(
			'format'      => self::FORMAT,
			'version'     => FLEXO_BOOKING_VERSION,
			'exported_at' => gmdate( 'c' ),
			'source'      => home_url(),
			'settings'    => $settings,
			'rooms'       => array(),
		);

		$slugs = array();
		foreach ( Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private' ) ) as $post ) {
			$meta = array();
			foreach ( array_keys( Flexo_Booking_Rooms::META ) as $key ) {
				$meta[ $key ] = get_post_meta( $post->ID, $key, true );
			}
			$data['rooms'][]      = array(
				'slug'       => $post->post_name,
				'title'      => $post->post_title,
				'content'    => $post->post_content,
				'excerpt'    => $post->post_excerpt,
				'status'     => $post->post_status,
				'menu_order' => $post->menu_order,
				'image'      => get_the_post_thumbnail_url( $post, 'full' ),
				'meta'       => $meta,
			);
			$slugs[ $post->ID ] = $post->post_name;
		}

		if ( $include_bookings ) {
			$data['bookings'] = array();
			$result           = Flexo_Booking_Bookings::query( array( 'per_page' => 0 ) );
			foreach ( $result['items'] as $booking ) {
				if ( ! isset( $slugs[ $booking['room_id'] ] ) ) {
					continue;
				}
				$booking['room'] = $slugs[ $booking['room_id'] ];
				unset( $booking['id'], $booking['room_id'], $booking['room_title'] );
				$data['bookings'][] = $booking;
			}
		}

		return $data;
	}

	/**
	 * @param array $data    Decoded export file.
	 * @param array $options {
	 *     @type bool $settings Import settings.
	 *     @type bool $rooms    Import rooms (create new, update same slug).
	 *     @type bool $images   Download room images from the source site.
	 *     @type bool $bookings Import bookings contained in the file.
	 * }
	 * @return array|WP_Error Counts of imported items.
	 */
	public static function import( array $data, array $options ) {
		if ( ! isset( $data['format'] ) || self::FORMAT !== $data['format'] ) {
			return new WP_Error( 'flexo_import_format', __( 'This is not a Flexo Booking export file.', 'flexo-booking' ) );
		}

		$options = wp_parse_args(
			$options,
			array(
				'settings' => true,
				'rooms'    => true,
				'images'   => false,
				'bookings' => false,
			)
		);
		$stats   = array(
			'settings'      => 0,
			'rooms_created' => 0,
			'rooms_updated' => 0,
			'images'        => 0,
			'bookings'      => 0,
		);

		if ( $options['settings'] && ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$current  = Flexo_Booking_Settings::all();
			$incoming = $data['settings'];
			if ( empty( $incoming['notification_email'] ) ) {
				$incoming['notification_email'] = $current['notification_email'];
			}
			update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( $current, $incoming ) ) );
			$stats['settings'] = 1;
		}

		if ( $options['rooms'] && ! empty( $data['rooms'] ) && is_array( $data['rooms'] ) ) {
			foreach ( $data['rooms'] as $room ) {
				if ( empty( $room['slug'] ) || empty( $room['title'] ) ) {
					continue;
				}
				$slug     = sanitize_title( $room['slug'] );
				$existing = get_posts(
					array(
						'post_type'      => Flexo_Booking_Rooms::POST_TYPE,
						'name'           => $slug,
						'post_status'    => 'any',
						'posts_per_page' => 1,
					)
				);
				$postarr  = array(
					'post_type'    => Flexo_Booking_Rooms::POST_TYPE,
					'post_name'    => $slug,
					'post_title'   => sanitize_text_field( $room['title'] ),
					'post_content' => isset( $room['content'] ) ? wp_kses_post( $room['content'] ) : '',
					'post_excerpt' => isset( $room['excerpt'] ) ? sanitize_textarea_field( $room['excerpt'] ) : '',
					'post_status'  => isset( $room['status'] ) && in_array( $room['status'], array( 'publish', 'draft', 'private' ), true ) ? $room['status'] : 'publish',
					'menu_order'   => isset( $room['menu_order'] ) ? (int) $room['menu_order'] : 0,
				);

				if ( $existing ) {
					$postarr['ID'] = $existing[0]->ID;
					$post_id       = wp_update_post( $postarr, true );
					$key           = 'rooms_updated';
				} else {
					$post_id = wp_insert_post( $postarr, true );
					$key     = 'rooms_created';
				}
				if ( is_wp_error( $post_id ) ) {
					continue;
				}
				++$stats[ $key ];

				if ( ! empty( $room['meta'] ) && is_array( $room['meta'] ) ) {
					Flexo_Booking_Rooms::save_meta_values( $post_id, array_intersect_key( $room['meta'], Flexo_Booking_Rooms::META ) );
				}

				if ( $options['images'] && ! empty( $room['image'] ) && ! has_post_thumbnail( $post_id ) ) {
					if ( self::sideload_image( $room['image'], $post_id ) ) {
						++$stats['images'];
					}
				}
			}
		}

		if ( $options['bookings'] && ! empty( $data['bookings'] ) && is_array( $data['bookings'] ) ) {
			$stats['bookings'] = self::import_bookings( $data['bookings'] );
		}

		return $stats;
	}

	private static function import_bookings( array $bookings ) {
		global $wpdb;

		$count    = 0;
		$room_ids = array();
		$allowed  = array( 'reference', 'check_in', 'check_out', 'nights', 'adults', 'children', 'guest_name', 'guest_email', 'guest_phone', 'notes', 'total', 'currency', 'status', 'source', 'created_at', 'updated_at' );

		foreach ( $bookings as $booking ) {
			if ( empty( $booking['reference'] ) || empty( $booking['room'] ) || Flexo_Booking_Bookings::get_by_reference( $booking['reference'] ) ) {
				continue;
			}
			if ( ! isset( $room_ids[ $booking['room'] ] ) ) {
				$post                          = get_posts(
					array(
						'post_type'      => Flexo_Booking_Rooms::POST_TYPE,
						'name'           => sanitize_title( $booking['room'] ),
						'post_status'    => 'any',
						'posts_per_page' => 1,
					)
				);
				$room_ids[ $booking['room'] ] = $post ? $post[0]->ID : 0;
			}
			if ( ! $room_ids[ $booking['room'] ] ) {
				continue;
			}

			$row            = array_intersect_key( $booking, array_flip( $allowed ) );
			$row            = array_map( 'sanitize_text_field', $row );
			$row['notes']   = isset( $booking['notes'] ) ? sanitize_textarea_field( $booking['notes'] ) : '';
			$row['room_id'] = $room_ids[ $booking['room'] ];
			if ( ! array_key_exists( $row['status'] ?? '', Flexo_Booking_Bookings::statuses() ) ) {
				$row['status'] = 'pending';
			}

			if ( $wpdb->insert( Flexo_Booking_Install::table(), $row ) ) {
				++$count;
			}
		}

		return $count;
	}

	private static function sideload_image( $url, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( esc_url_raw( $url ), $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}
		return set_post_thumbnail( $post_id, $attachment_id );
	}

	public static function handle_export() {
		check_admin_referer( 'flexo_booking_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export booking data.', 'flexo-booking' ) );
		}

		$data     = self::export( ! empty( $_POST['include_bookings'] ) );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = 'flexo-booking-' . sanitize_file_name( $host ) . '-' . wp_date( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function handle_import() {
		check_admin_referer( 'flexo_booking_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import booking data.', 'flexo-booking' ) );
		}

		$back = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-tools' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is only read from.
		$file = isset( $_FILES['import_file']['tmp_name'] ) ? $_FILES['import_file']['tmp_name'] : '';
		if ( ! $file || ! is_uploaded_file( $file ) || filesize( $file ) > 5 * MB_IN_BYTES ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( __( 'Please choose a valid export file (max 5 MB).', 'flexo-booking' ) ), $back ) );
			exit;
		}

		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( __( 'The file could not be read.', 'flexo-booking' ) ), $back ) );
			exit;
		}

		$result = self::import(
			$data,
			array(
				'settings' => ! empty( $_POST['import_settings'] ),
				'rooms'    => ! empty( $_POST['import_rooms'] ),
				'images'   => ! empty( $_POST['import_images'] ),
				'bookings' => ! empty( $_POST['import_bookings'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'flexo_imported', rawurlencode( wp_json_encode( $result ) ), $back ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error    = isset( $_GET['flexo_error'] ) ? sanitize_text_field( wp_unslash( $_GET['flexo_error'] ) ) : '';
		$imported = isset( $_GET['flexo_imported'] ) ? json_decode( sanitize_text_field( wp_unslash( $_GET['flexo_imported'] ) ), true ) : null;
		// phpcs:enable
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Import / Export', 'flexo-booking' ); ?></h1>
			<p><?php esc_html_e( 'Copy the booking setup (settings and rooms) from one site to another – for example from a template to a new client site built from it.', 'flexo-booking' ); ?></p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $imported ) ) : ?>
				<div class="notice notice-success"><p>
					<?php
					printf(
						/* translators: 1: rooms created, 2: rooms updated, 3: images, 4: bookings */
						esc_html__( 'Import complete: %1$d rooms created, %2$d rooms updated, %3$d images downloaded, %4$d bookings imported.', 'flexo-booking' ),
						(int) ( $imported['rooms_created'] ?? 0 ),
						(int) ( $imported['rooms_updated'] ?? 0 ),
						(int) ( $imported['images'] ?? 0 ),
						(int) ( $imported['bookings'] ?? 0 )
					);
					if ( ! empty( $imported['settings'] ) ) {
						echo ' ' . esc_html__( 'Settings were updated.', 'flexo-booking' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<div class="flexo-tools-card">
				<h2><?php esc_html_e( 'Export', 'flexo-booking' ); ?></h2>
				<p><?php esc_html_e( 'Download a JSON file with all booking settings and rooms.', 'flexo-booking' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_export">
					<?php wp_nonce_field( 'flexo_booking_export' ); ?>
					<p><label><input type="checkbox" name="include_bookings" value="1"> <?php esc_html_e( 'Also include bookings (only when moving a live hotel site – never for templates)', 'flexo-booking' ); ?></label></p>
					<?php submit_button( __( 'Download export file', 'flexo-booking' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="flexo-tools-card">
				<h2><?php esc_html_e( 'Import', 'flexo-booking' ); ?></h2>
				<p><?php esc_html_e( 'Rooms with the same slug are updated; new rooms are created. Nothing is deleted.', 'flexo-booking' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_import">
					<?php wp_nonce_field( 'flexo_booking_import' ); ?>
					<p><input type="file" name="import_file" accept=".json,application/json" required></p>
					<p><label><input type="checkbox" name="import_settings" value="1" checked> <?php esc_html_e( 'Import settings', 'flexo-booking' ); ?></label></p>
					<p><label><input type="checkbox" name="import_rooms" value="1" checked> <?php esc_html_e( 'Import rooms', 'flexo-booking' ); ?></label></p>
					<p><label><input type="checkbox" name="import_images" value="1"> <?php esc_html_e( 'Download room images from the source site (it must be online)', 'flexo-booking' ); ?></label></p>
					<p><label><input type="checkbox" name="import_bookings" value="1"> <?php esc_html_e( 'Import bookings contained in the file', 'flexo-booking' ); ?></label></p>
					<?php submit_button( __( 'Import', 'flexo-booking' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="flexo-tools-card">
				<h2><?php esc_html_e( 'Command line (WP-CLI)', 'flexo-booking' ); ?></h2>
				<p><?php esc_html_e( 'Useful when setting up many sites at once:', 'flexo-booking' ); ?></p>
				<p><code>wp flexo-booking export &gt; booking-setup.json</code><br><code>wp flexo-booking import booking-setup.json --images</code></p>
			</div>
		</div>
		<?php
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Export and import Flexo Booking settings and rooms.
	 */
	class Flexo_Booking_CLI {

		/**
		 * Prints the booking setup as JSON.
		 *
		 * ## OPTIONS
		 *
		 * [--bookings]
		 * : Include bookings (for moving a live site).
		 *
		 * ## EXAMPLES
		 *
		 *     wp flexo-booking export > booking-setup.json
		 */
		public function export( $args, $assoc_args ) {
			WP_CLI::line( wp_json_encode( Flexo_Booking_Portability::export( ! empty( $assoc_args['bookings'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}

		/**
		 * Imports a booking setup JSON file.
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : Path to the export file.
		 *
		 * [--skip-settings]
		 * : Don't import settings.
		 *
		 * [--skip-rooms]
		 * : Don't import rooms.
		 *
		 * [--images]
		 * : Download room images from the source site.
		 *
		 * [--bookings]
		 * : Import bookings contained in the file.
		 *
		 * ## EXAMPLES
		 *
		 *     wp flexo-booking import booking-setup.json --images
		 */
		public function import( $args, $assoc_args ) {
			$file = $args[0];
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "Cannot read {$file}" );
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $data ) ) {
				WP_CLI::error( 'Invalid JSON file.' );
			}
			$result = Flexo_Booking_Portability::import(
				$data,
				array(
					'settings' => empty( $assoc_args['skip-settings'] ),
					'rooms'    => empty( $assoc_args['skip-rooms'] ),
					'images'   => ! empty( $assoc_args['images'] ),
					'bookings' => ! empty( $assoc_args['bookings'] ),
				)
			);
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Rooms created: %d, updated: %d, images: %d, bookings: %d, settings: %s', $result['rooms_created'], $result['rooms_updated'], $result['images'], $result['bookings'], $result['settings'] ? 'yes' : 'no' ) );
		}
	}

	WP_CLI::add_command( 'flexo-booking', 'Flexo_Booking_CLI' );
}
