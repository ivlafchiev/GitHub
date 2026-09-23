<?php
/**
 * Admin screens: bookings list, manual bookings, status actions, CSV export.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Admin {

	const MENU_SLUG = 'flexo-booking';

	public static function init() {
		// Priority 9 so "All bookings" is listed before the Rooms submenu that
		// WordPress adds for the post type at priority 10.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_late' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_flexo_booking_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_flexo_booking_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_flexo_booking_csv', array( __CLASS__, 'handle_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FLEXO_BOOKING_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability required to see and manage bookings. Editors and admins by
	 * default, so front-desk staff don't need full admin rights.
	 */
	public static function capability() {
		return apply_filters( 'flexo_booking_manage_capability', 'edit_others_posts' );
	}

	public static function menu() {
		$pending = Flexo_Booking_Bookings::count_pending();
		$bubble  = $pending ? ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . number_format_i18n( $pending ) . '</span></span>' : '';

		add_menu_page( __( 'Bookings', 'flexo-booking' ), __( 'Bookings', 'flexo-booking' ) . $bubble, self::capability(), self::MENU_SLUG, array( __CLASS__, 'render_list' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::MENU_SLUG, __( 'All bookings', 'flexo-booking' ), __( 'All bookings', 'flexo-booking' ), self::capability(), self::MENU_SLUG, array( __CLASS__, 'render_list' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Add booking', 'flexo-booking' ), __( 'Add booking', 'flexo-booking' ), self::capability(), self::MENU_SLUG . '-new', array( __CLASS__, 'render_add' ) );
	}

	public static function menu_late() {
		add_submenu_page( self::MENU_SLUG, __( 'Booking settings', 'flexo-booking' ), __( 'Settings', 'flexo-booking' ), 'manage_options', self::MENU_SLUG . '-settings', array( 'Flexo_Booking_Settings', 'render_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Import / Export', 'flexo-booking' ), __( 'Import / Export', 'flexo-booking' ), 'manage_options', self::MENU_SLUG . '-tools', array( 'Flexo_Booking_Portability', 'render_page' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::MENU_SLUG ) || ( function_exists( 'get_current_screen' ) && get_current_screen() && Flexo_Booking_Rooms::POST_TYPE === get_current_screen()->post_type ) ) {
			wp_enqueue_style( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/css/admin.css', array(), FLEXO_BOOKING_VERSION );
		}
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ) . '">' . esc_html__( 'Settings', 'flexo-booking' ) . '</a>'
		);
		return $links;
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function action_url( $action, $id, $extra = array() ) {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ),
			$action . '_' . $id
		);
	}

	private static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		return array(
			'status'  => isset( $_GET['status'] ) && array_key_exists( sanitize_key( $_GET['status'] ), Flexo_Booking_Bookings::statuses() ) ? sanitize_key( $_GET['status'] ) : '',
			'room_id' => isset( $_GET['room_id'] ) ? absint( $_GET['room_id'] ) : 0,
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'    => ! empty( $_GET['upcoming'] ) ? wp_date( 'Y-m-d' ) : '',
		);
		// phpcs:enable
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['flexo_msg'] ) ? sanitize_key( $_GET['flexo_msg'] ) : '';
		$messages = array(
			'updated' => array( 'success', __( 'Booking updated.', 'flexo-booking' ) ),
			'deleted' => array( 'success', __( 'Booking deleted.', 'flexo-booking' ) ),
			'added'   => array( 'success', __( 'Booking added.', 'flexo-booking' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error = isset( $_GET['flexo_error'] ) ? sanitize_text_field( wp_unslash( $_GET['flexo_error'] ) ) : '';
		if ( $error ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	public static function render_list() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$filters  = self::current_filters();
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = Flexo_Booking_Bookings::query( array_merge( $filters, array( 'per_page' => $per_page, 'page' => $paged ) ) );
		$rooms  = Flexo_Booking_Rooms::all( 'any' );
		$format = get_option( 'date_format' );

		$csv_args = array_filter(
			array(
				'action'   => 'flexo_booking_csv',
				'status'   => $filters['status'],
				'room_id'  => $filters['room_id'],
				's'        => $filters['search'],
				'upcoming' => $filters['from'] ? 1 : 0,
			)
		);
		?>
		<div class="wrap flexo-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'flexo-booking' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add booking', 'flexo-booking' ); ?></a>
			<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( $csv_args, admin_url( 'admin-post.php' ) ), 'flexo_booking_csv' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'flexo-booking' ); ?></a>
			<hr class="wp-header-end">

			<?php self::notice(); ?>

			<?php if ( ! $rooms ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Start by adding your rooms, then place the booking form on a page with the [flexo_booking] shortcode or the "Flexo Booking Form" Elementor widget.', 'flexo-booking' ); ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add a room', 'flexo-booking' ); ?></a>
				</p></div>
			<?php endif; ?>

			<form method="get" class="flexo-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<select name="status" aria-label="<?php esc_attr_e( 'Status', 'flexo-booking' ); ?>">
					<option value=""><?php esc_html_e( 'All statuses', 'flexo-booking' ); ?></option>
					<?php foreach ( Flexo_Booking_Bookings::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="room_id" aria-label="<?php esc_attr_e( 'Room', 'flexo-booking' ); ?>">
					<option value="0"><?php esc_html_e( 'All rooms', 'flexo-booking' ); ?></option>
					<?php foreach ( $rooms as $room ) : ?>
						<option value="<?php echo esc_attr( $room->ID ); ?>" <?php selected( $filters['room_id'], $room->ID ); ?>><?php echo esc_html( get_the_title( $room ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="upcoming" value="1" <?php checked( (bool) $filters['from'] ); ?>> <?php esc_html_e( 'Current & upcoming only', 'flexo-booking' ); ?></label>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Reference, name, email or phone', 'flexo-booking' ); ?>">
				<?php submit_button( __( 'Filter', 'flexo-booking' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped flexo-bookings-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Reference', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Guest', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Guests', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Total', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $result['items'] ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No bookings found.', 'flexo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $b ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $b['reference'] ); ?></strong>
							<div class="flexo-muted">
								<?php
								echo esc_html( mysql2date( $format . ' H:i', $b['created_at'] ) );
								if ( 'admin' === $b['source'] ) {
									echo ' · ' . esc_html__( 'added by staff', 'flexo-booking' );
								}
								?>
							</div>
						</td>
						<td>
							<?php echo esc_html( $b['guest_name'] ? $b['guest_name'] : '—' ); ?>
							<?php if ( $b['guest_email'] ) : ?>
								<div><a href="mailto:<?php echo esc_attr( $b['guest_email'] ); ?>"><?php echo esc_html( $b['guest_email'] ); ?></a></div>
							<?php endif; ?>
							<?php if ( $b['guest_phone'] ) : ?>
								<div><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $b['guest_phone'] ) ); ?>"><?php echo esc_html( $b['guest_phone'] ); ?></a></div>
							<?php endif; ?>
							<?php if ( $b['notes'] ) : ?>
								<div class="flexo-muted flexo-notes"><?php echo esc_html( $b['notes'] ); ?></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $b['room_title'] ); ?></td>
						<td>
							<?php echo esc_html( mysql2date( $format, $b['check_in'] ) . ' → ' . mysql2date( $format, $b['check_out'] ) ); ?>
							<div class="flexo-muted">
								<?php
								/* translators: %d: number of nights */
								echo esc_html( sprintf( _n( '%d night', '%d nights', $b['nights'], 'flexo-booking' ), $b['nights'] ) );
								?>
							</div>
						</td>
						<td><?php echo esc_html( $b['adults'] . ( $b['children'] ? ' + ' . $b['children'] : '' ) ); ?></td>
						<td><?php echo esc_html( Flexo_Booking_Settings::format_price( $b['total'] ) ); ?></td>
						<td><span class="flexo-status flexo-status--<?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( Flexo_Booking_Bookings::status_label( $b['status'] ) ); ?></span></td>
						<td class="flexo-actions">
							<?php if ( 'pending' === $b['status'] ) : ?>
								<a class="button button-primary button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php esc_html_e( 'Confirm', 'flexo-booking' ); ?></a>
							<?php endif; ?>
							<?php if ( 'cancelled' === $b['status'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php esc_html_e( 'Reinstate', 'flexo-booking' ); ?></a>
							<?php elseif ( 'blocked' !== $b['status'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel this booking? The guest will be notified by email.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
							<?php endif; ?>
							<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'flexo_booking_delete', $b['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this booking?', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $result['total'] / $per_page );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	public static function render_add() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$rooms = Flexo_Booking_Rooms::all();
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Add booking', 'flexo-booking' ); ?></h1>
			<p><?php esc_html_e( 'Record a phone, walk-in or other-channel booking, or block dates (e.g. maintenance) so they can’t be booked online.', 'flexo-booking' ); ?></p>
			<?php self::notice(); ?>
			<?php if ( ! $rooms ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add your first room', 'flexo-booking' ); ?></a></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="flexo_booking_add">
				<?php wp_nonce_field( 'flexo_booking_add' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fb-status"><?php esc_html_e( 'Type', 'flexo-booking' ); ?></label></th>
						<td>
							<select id="fb-status" name="status">
								<option value="confirmed"><?php esc_html_e( 'Confirmed booking', 'flexo-booking' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending booking', 'flexo-booking' ); ?></option>
								<option value="blocked"><?php esc_html_e( 'Block dates (room closed)', 'flexo-booking' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-room"><?php esc_html_e( 'Room', 'flexo-booking' ); ?></label></th>
						<td>
							<select id="fb-room" name="room" required>
								<?php foreach ( $rooms as $room ) : ?>
									<option value="<?php echo esc_attr( $room->ID ); ?>"><?php echo esc_html( get_the_title( $room ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dates', 'flexo-booking' ); ?></th>
						<td>
							<input type="date" name="check_in" required aria-label="<?php esc_attr_e( 'Check-in', 'flexo-booking' ); ?>">
							→
							<input type="date" name="check_out" required aria-label="<?php esc_attr_e( 'Check-out', 'flexo-booking' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guests', 'flexo-booking' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Adults', 'flexo-booking' ); ?> <input type="number" name="adults" min="1" value="2" class="small-text"></label>
							<label><?php esc_html_e( 'Children', 'flexo-booking' ); ?> <input type="number" name="children" min="0" value="0" class="small-text"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-name"><?php esc_html_e( 'Guest name', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-name" type="text" name="guest_name" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-email"><?php esc_html_e( 'Email', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-email" type="email" name="guest_email" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-phone"><?php esc_html_e( 'Phone', 'flexo-booking' ); ?></label></th>
						<td><input id="fb-phone" type="tel" name="guest_phone" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-notes"><?php esc_html_e( 'Notes', 'flexo-booking' ); ?></label></th>
						<td><textarea id="fb-notes" name="notes" rows="3" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email', 'flexo-booking' ); ?></th>
						<td><label><input type="checkbox" name="notify" value="1"> <?php esc_html_e( 'Send the confirmation email to the guest', 'flexo-booking' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save booking', 'flexo-booking' ) ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	public static function handle_status() {
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		check_admin_referer( 'flexo_booking_status_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}

		$result = Flexo_Booking_Bookings::update_status( $id, $status );
		$back   = wp_get_referer() ? wp_get_referer() : self::page_url();
		$back   = remove_query_arg( array( 'flexo_msg', 'flexo_error' ), $back );

		if ( is_wp_error( $result ) ) {
			self::redirect( add_query_arg( 'flexo_error', rawurlencode( $result->get_error_message() ), $back ) );
		}
		self::redirect( add_query_arg( 'flexo_msg', 'updated', $back ) );
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'flexo_booking_delete_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}
		Flexo_Booking_Bookings::delete( $id );
		$back = wp_get_referer() ? wp_get_referer() : self::page_url();
		self::redirect( add_query_arg( 'flexo_msg', 'deleted', remove_query_arg( array( 'flexo_msg', 'flexo_error' ), $back ) ) );
	}

	public static function handle_add() {
		check_admin_referer( 'flexo_booking_add' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}

		$fields = array( 'room', 'check_in', 'check_out', 'adults', 'children', 'guest_name', 'guest_email', 'guest_phone', 'notes', 'status' );
		$data   = array( 'source' => 'admin' );
		foreach ( $fields as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Flexo_Booking_Bookings::create().
		}
		if ( ! in_array( $data['status'], array( 'confirmed', 'pending', 'blocked' ), true ) ) {
			$data['status'] = 'confirmed';
		}

		$booking = Flexo_Booking_Bookings::create( $data );
		$new_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-new' );

		if ( is_wp_error( $booking ) ) {
			self::redirect( add_query_arg( 'flexo_error', rawurlencode( $booking->get_error_message() ), $new_url ) );
		}

		if ( ! empty( $_POST['notify'] ) && 'blocked' !== $booking['status'] ) {
			Flexo_Booking_Emails::send_guest( $booking, 'confirmed' === $booking['status'] ? 'confirmed' : 'request' );
		}

		self::redirect( self::page_url( array( 'flexo_msg' => 'added' ) ) );
	}

	public static function handle_csv() {
		check_admin_referer( 'flexo_booking_csv' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}

		$result = Flexo_Booking_Bookings::query( array_merge( self::current_filters(), array( 'per_page' => 0 ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bookings-' . wp_date( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows accents and Cyrillic correctly.
		fputcsv( $out, array( 'Reference', 'Status', 'Room', 'Check-in', 'Check-out', 'Nights', 'Adults', 'Children', 'Guest', 'Email', 'Phone', 'Notes', 'Total', 'Currency', 'Source', 'Created' ), ',', '"', '\\' );
		foreach ( $result['items'] as $b ) {
			$row = array( $b['reference'], $b['status'], $b['room_title'], $b['check_in'], $b['check_out'], $b['nights'], $b['adults'], $b['children'], $b['guest_name'], $b['guest_email'], $b['guest_phone'], $b['notes'], $b['total'], $b['currency'], $b['source'], $b['created_at'] );
			// Prevent spreadsheet formula injection from guest-entered values.
			$row = array_map(
				static function ( $value ) {
					return is_string( $value ) && preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
				},
				$row
			);
			fputcsv( $out, $row, ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
