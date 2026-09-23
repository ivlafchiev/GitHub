<?php
/**
 * Bookings → Closed dates: periods when the property or a room takes no
 * bookings. Core feature (always available).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Closures_Admin {

	const SLUG = 'flexo-booking-closures';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_post_flexo_booking_save_closure', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_flexo_booking_delete_closure', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_copy_closures', array( __CLASS__, 'handle_copy' ) );
	}

	public static function menu() {
		add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Closed dates', 'flexo-booking' ), __( 'Closed dates', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check_access( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage closed dates.', 'flexo-booking' ) );
		}
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$rooms    = Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) );
		$closures = Flexo_Booking_Closures::all();
		$today    = wp_date( 'Y-m-d' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing = $edit_id ? Flexo_Booking_Closures::get( $edit_id ) : null;
		$form    = get_transient( 'flexo_closure_form_' . get_current_user_id() );
		delete_transient( 'flexo_closure_form_' . get_current_user_id() );
		if ( ! is_array( $form ) && $editing ) {
			$form = array(
				'id'        => $editing['id'],
				'room_id'   => $editing['room_id'],
				'date_from' => Flexo_Booking_Dates::display( $editing['date_from'] ),
				'date_to'   => Flexo_Booking_Dates::display( $editing['date_to'] ),
				'label'     => $editing['label'],
			);
		}
		$form  = wp_parse_args(
			is_array( $form ) ? $form : array(),
			array(
				'id'        => 0,
				'room_id'   => 0,
				'date_from' => '',
				'date_to'   => '',
				'label'     => '',
			)
		);
		$years = array( (int) wp_date( 'Y' ) );
		foreach ( $closures as $closure ) {
			$years[] = (int) substr( $closure['date_from'], 0, 4 );
		}
		$years = array_unique( $years );
		sort( $years );
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Closed dates', 'flexo-booking' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Close the whole property (e.g. for the winter) or a single room type. Guests cannot book nights inside a closed period and see a clear message instead. To take just one room out of service, use Add booking → Block dates.', 'flexo-booking' ); ?></p>
			<?php Flexo_Booking_Seasons_Admin::notices(); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Applies to', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'From', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'To (last night)', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Message for guests', 'flexo-booking' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $closures ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No closed dates. The property is open all year.', 'flexo-booking' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $closures as $closure ) : ?>
						<tr class="<?php echo $closure['date_to'] < $today ? 'flexo-past' : ''; ?>">
							<td><strong><?php echo $closure['room_id'] ? esc_html( get_the_title( $closure['room_id'] ) ) : esc_html__( 'Whole property', 'flexo-booking' ); ?></strong></td>
							<td><?php echo esc_html( Flexo_Booking_Dates::display( $closure['date_from'] ) ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Dates::display( $closure['date_to'] ) ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Closures::guest_message( $closure ) ); ?></td>
							<td class="flexo-actions">
								<a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'edit' => $closure['id'] ) ) . '#flexo-closure-form' ); ?>"><?php esc_html_e( 'Edit', 'flexo-booking' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'flexo_booking_delete_closure', 'id' => $closure['id'] ), admin_url( 'admin-post.php' ) ), 'flexo_booking_delete_closure_' . $closure['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this closed period? The dates become bookable again.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="flexo-tools-card" id="flexo-closure-form">
				<h2><?php echo $form['id'] ? esc_html__( 'Edit closed period', 'flexo-booking' ) : esc_html__( 'Add a closed period', 'flexo-booking' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_save_closure">
					<input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>">
					<?php wp_nonce_field( 'flexo_booking_save_closure' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fc-room"><?php esc_html_e( 'Applies to', 'flexo-booking' ); ?></label></th>
							<td>
								<select id="fc-room" name="room_id">
									<option value="0"><?php esc_html_e( 'Whole property', 'flexo-booking' ); ?></option>
									<?php foreach ( $rooms as $room ) : ?>
										<option value="<?php echo esc_attr( $room->ID ); ?>" <?php selected( (int) $form['room_id'], $room->ID ); ?>><?php echo esc_html( get_the_title( $room ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dates', 'flexo-booking' ); ?></th>
							<td>
								<label><?php esc_html_e( 'From', 'flexo-booking' ); ?> <input type="text" name="date_from" class="flexo-date" required autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['date_from'] ); ?>"></label>
								<label><?php esc_html_e( 'To (last night)', 'flexo-booking' ); ?> <input type="text" name="date_to" class="flexo-date" required autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['date_to'] ); ?>"></label>
								<p class="description"><?php esc_html_e( 'Guests can still check out on the first closed day.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fc-label"><?php esc_html_e( 'Message for guests', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fc-label" type="text" name="label" class="large-text" value="<?php echo esc_attr( $form['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. We are closed for the winter season', 'flexo-booking' ); ?>">
								<p class="description"><?php esc_html_e( 'Optional. The dates are added automatically.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( $form['id'] ? __( 'Save closed period', 'flexo-booking' ) : __( 'Add closed period', 'flexo-booking' ), 'primary', 'submit', false ); ?>
					<?php if ( $form['id'] ) : ?>
						<a class="button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( $closures ) : ?>
				<div class="flexo-tools-card">
					<h2><?php esc_html_e( 'Copy closed dates to next year', 'flexo-booking' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="flexo_booking_copy_closures">
						<?php wp_nonce_field( 'flexo_booking_copy_closures' ); ?>
						<label><?php esc_html_e( 'Copy closed periods starting in', 'flexo-booking' ); ?>
							<select name="year">
								<?php foreach ( $years as $year ) : ?>
									<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $year, (int) wp_date( 'Y' ) ); ?>><?php echo esc_html( $year ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php submit_button( __( 'Copy to next year', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_save() {
		self::check_access( 'flexo_booking_save_closure' );
		$input = array();
		foreach ( array( 'room_id', 'date_from', 'date_to', 'label' ) as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = Flexo_Booking_Closures::save( $input, $id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'flexo_closure_form_' . get_current_user_id(), array_merge( $input, array( 'id' => $id ) ), 120 );
			wp_safe_redirect( self::page_url( array( 'flexo_error' => rawurlencode( $result->get_error_message() ) ) ) . '#flexo-closure-form' );
			exit;
		}
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_delete_closure_' . $id );
		Flexo_Booking_Closures::delete( $id );
		wp_safe_redirect( self::page_url( array( 'flexo_msg' => 'deleted' ) ) );
		exit;
	}

	public static function handle_copy() {
		self::check_access( 'flexo_booking_copy_closures' );
		$year   = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) wp_date( 'Y' );
		$result = Flexo_Booking_Closures::copy_to_next_year( $year );
		$info   = sprintf(
			/* translators: 1: number copied, 2: year, 3: number skipped */
			__( '%1$d closed periods copied to %2$d (%3$d already existed).', 'flexo-booking' ),
			$result['copied'],
			$year + 1,
			$result['skipped']
		);
		wp_safe_redirect( self::page_url( array( 'flexo_info' => rawurlencode( $info ) ) ) );
		exit;
	}
}
