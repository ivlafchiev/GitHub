<?php
/**
 * Bookings → Seasonal prices: a simple table of seasons per room.
 * Shown only when the "Seasonal prices" feature is available and switched on.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Seasons_Admin {

	const SLUG = 'flexo-booking-seasons';

	public static function init() {
		// Priority 10 runs after WordPress adds the Rooms submenu.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_' . Flexo_Booking_Rooms::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_flexo_booking_save_season', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_flexo_booking_delete_season', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_copy_seasons', array( __CLASS__, 'handle_copy' ) );
	}

	public static function menu() {
		if ( Flexo_Booking_Seasons::enabled() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Seasonal prices', 'flexo-booking' ), __( 'Seasonal prices', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::SLUG ) || false !== strpos( $hook, Flexo_Booking_Closures_Admin::SLUG ) ) {
			self::enqueue_datepicker();
		}
	}

	/**
	 * jQuery UI datepicker (bundled with WordPress) in DD.MM.YYYY.
	 */
	public static function enqueue_datepicker() {
		wp_enqueue_style( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/css/admin.css', array(), FLEXO_BOOKING_VERSION );
		wp_enqueue_script( 'flexo-booking-admin-dates', FLEXO_BOOKING_URL . 'assets/js/admin-dates.js', array( 'jquery', 'jquery-ui-datepicker' ), FLEXO_BOOKING_VERSION, true );
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function rooms() {
		return Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private', 'pending', 'future' ) );
	}

	private static function check_access( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) || ! Flexo_Booking_Seasons::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to manage seasonal prices.', 'flexo-booking' ) );
		}
	}

	/**
	 * Keeps the submitted form for one page load after a validation error.
	 */
	private static function remember_form( array $data ) {
		set_transient( 'flexo_season_form_' . get_current_user_id(), $data, 120 );
	}

	private static function recall_form() {
		$key  = 'flexo_season_form_' . get_current_user_id();
		$data = get_transient( $key );
		delete_transient( $key );
		return is_array( $data ) ? $data : null;
	}

	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg   = isset( $_GET['flexo_msg'] ) ? sanitize_key( $_GET['flexo_msg'] ) : '';
		$error = isset( $_GET['flexo_error'] ) ? sanitize_text_field( wp_unslash( $_GET['flexo_error'] ) ) : '';
		$info  = isset( $_GET['flexo_info'] ) ? sanitize_text_field( wp_unslash( $_GET['flexo_info'] ) ) : '';
		// phpcs:enable
		$messages = array(
			'saved'   => __( 'Saved.', 'flexo-booking' ),
			'deleted' => __( 'Deleted.', 'flexo-booking' ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $msg ] ) );
		}
		if ( $info ) {
			printf( '<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html( $info ) );
		}
		if ( $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$rooms = self::rooms();
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Seasonal prices', 'flexo-booking' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Set different prices for high and low season. Each night is priced by the season it falls in; nights outside any season use the room\'s normal price.', 'flexo-booking' ); ?></p>
			<?php self::notices(); ?>

			<?php if ( ! $rooms ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add your first room', 'flexo-booking' ); ?></a></p>
				</div>
				<?php
				return;
			endif;

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$room_id = isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0;
			$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
			// phpcs:enable
			$room_ids = wp_list_pluck( $rooms, 'ID' );
			if ( ! in_array( $room_id, $room_ids, true ) ) {
				$room_id = $room_ids[0];
			}
			$room    = Flexo_Booking_Rooms::to_array( get_post( $room_id ) );
			$seasons = Flexo_Booking_Seasons::for_room( $room_id );
			$editing = $edit_id ? Flexo_Booking_Seasons::get( $edit_id ) : null;
			$form    = self::recall_form();
			if ( ! $form && $editing && $editing['room_id'] === $room_id ) {
				$form = array(
					'id'            => $editing['id'],
					'name'          => $editing['name'],
					'date_from'     => Flexo_Booking_Dates::display( $editing['date_from'] ),
					'date_to'       => Flexo_Booking_Dates::display( $editing['date_to'] ),
					'price'         => $editing['price'],
					'weekend_price' => null === $editing['weekend_price'] ? '' : $editing['weekend_price'],
					'min_nights'    => $editing['min_nights'] ? $editing['min_nights'] : '',
				);
			}
			$form  = wp_parse_args(
				$form ? $form : array(),
				array(
					'id'            => 0,
					'name'          => '',
					'date_from'     => '',
					'date_to'       => '',
					'price'         => '',
					'weekend_price' => '',
					'min_nights'    => '',
				)
			);
			$today = wp_date( 'Y-m-d' );
			$years = array( (int) wp_date( 'Y' ) );
			foreach ( $seasons as $season ) {
				$years[] = (int) substr( $season['date_from'], 0, 4 );
			}
			$years = array_unique( $years );
			sort( $years );
			?>

			<form method="get" class="flexo-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label for="flexo-season-room"><strong><?php esc_html_e( 'Room', 'flexo-booking' ); ?></strong></label>
				<select id="flexo-season-room" name="room" onchange="this.form.submit()">
					<?php foreach ( $rooms as $post ) : ?>
						<option value="<?php echo esc_attr( $post->ID ); ?>" <?php selected( $room_id, $post->ID ); ?>><?php echo esc_html( get_the_title( $post ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Show', 'flexo-booking' ), 'secondary', '', false ); ?></noscript>
			</form>

			<p>
				<?php
				printf(
					/* translators: 1: price per night, 2: weekend price */
					esc_html__( 'Normal price: %1$s per night%2$s. Used on dates without a season.', 'flexo-booking' ),
					'<strong>' . esc_html( Flexo_Booking_Money::format( $room['price'] ) ) . '</strong>',
					$room['weekend_price'] > 0 ? esc_html( sprintf( /* translators: %s: price */ __( ', weekends %s', 'flexo-booking' ), Flexo_Booking_Money::format( $room['weekend_price'] ) ) ) : ''
				);
				?>
				<a href="<?php echo esc_url( get_edit_post_link( $room_id ) ); ?>"><?php esc_html_e( 'Edit room', 'flexo-booking' ); ?></a>
			</p>

			<table class="widefat striped flexo-seasons-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Season', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'From', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'To (last night)', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Weekend price', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Minimum stay', 'flexo-booking' ); ?></th>
						<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $seasons ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No seasons yet – this room uses its normal price all year.', 'flexo-booking' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $seasons as $season ) : ?>
						<tr class="<?php echo $season['date_to'] < $today ? 'flexo-past' : ''; ?>">
							<td><strong><?php echo esc_html( $season['name'] ); ?></strong></td>
							<td><?php echo esc_html( Flexo_Booking_Dates::display( $season['date_from'] ) ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Dates::display( $season['date_to'] ) ); ?></td>
							<td><?php echo esc_html( Flexo_Booking_Money::format( $season['price'] ) ); ?></td>
							<td><?php echo null === $season['weekend_price'] ? '—' : esc_html( Flexo_Booking_Money::format( $season['weekend_price'] ) ); ?></td>
							<td>
								<?php
								echo $season['min_nights']
									? esc_html( sprintf( /* translators: %d: nights */ _n( '%d night', '%d nights', $season['min_nights'], 'flexo-booking' ), $season['min_nights'] ) )
									: '—';
								?>
							</td>
							<td class="flexo-actions">
								<a class="button button-small" href="<?php echo esc_url( self::page_url( array( 'room' => $room_id, 'edit' => $season['id'] ) ) . '#flexo-season-form' ); ?>"><?php esc_html_e( 'Edit', 'flexo-booking' ); ?></a>
								<a class="button button-small button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'flexo_booking_delete_season', 'id' => $season['id'] ), admin_url( 'admin-post.php' ) ), 'flexo_booking_delete_season_' . $season['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this season?', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="flexo-tools-card" id="flexo-season-form">
				<h2><?php echo $form['id'] ? esc_html__( 'Edit season', 'flexo-booking' ) : esc_html__( 'Add a season', 'flexo-booking' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_save_season">
					<input type="hidden" name="room_id" value="<?php echo esc_attr( $room_id ); ?>">
					<input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>">
					<?php wp_nonce_field( 'flexo_booking_save_season' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fs-name"><?php esc_html_e( 'Name', 'flexo-booking' ); ?></label></th>
							<td><input id="fs-name" type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $form['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. High season', 'flexo-booking' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dates', 'flexo-booking' ); ?></th>
							<td>
								<label><?php esc_html_e( 'From', 'flexo-booking' ); ?> <input type="text" name="date_from" class="flexo-date" required autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['date_from'] ); ?>"></label>
								<label><?php esc_html_e( 'To (last night)', 'flexo-booking' ); ?> <input type="text" name="date_to" class="flexo-date" required autocomplete="off" placeholder="DD.MM.YYYY" value="<?php echo esc_attr( $form['date_to'] ); ?>"></label>
								<p class="description"><?php esc_html_e( 'Both dates are nights inside the season. Example: 01.07.2026 – 31.08.2026 includes the night of 31 August (check-out 1 September).', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fs-price"><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?></label></th>
							<td><input id="fs-price" type="text" inputmode="decimal" name="price" class="small-text" required value="<?php echo esc_attr( $form['price'] ); ?>"> <?php echo esc_html( Flexo_Booking_Money::currency() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="fs-weekend"><?php esc_html_e( 'Weekend price per night', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fs-weekend" type="text" inputmode="decimal" name="weekend_price" class="small-text" value="<?php echo esc_attr( $form['weekend_price'] ); ?>"> <?php echo esc_html( Flexo_Booking_Money::currency() ); ?>
								<p class="description"><?php esc_html_e( 'Optional. Friday and Saturday nights in this season. Leave empty to use the price above every night.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="fs-min"><?php esc_html_e( 'Minimum stay', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fs-min" type="number" min="1" name="min_nights" class="small-text" value="<?php echo esc_attr( $form['min_nights'] ); ?>"> <?php esc_html_e( 'nights', 'flexo-booking' ); ?>
								<p class="description"><?php esc_html_e( 'Optional. Applies to guests arriving during this season. Leave empty to use the room\'s minimum.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( $form['id'] ? __( 'Save season', 'flexo-booking' ) : __( 'Add season', 'flexo-booking' ), 'primary', 'submit', false ); ?>
					<?php if ( $form['id'] ) : ?>
						<a class="button" href="<?php echo esc_url( self::page_url( array( 'room' => $room_id ) ) ); ?>"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
					<?php endif; ?>
				</form>
			</div>

			<div class="flexo-tools-card">
				<h2><?php esc_html_e( 'Copy seasons to next year', 'flexo-booking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Creates the same seasons one year later with the same prices. Seasons that would overlap an existing one are skipped.', 'flexo-booking' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="flexo_booking_copy_seasons">
					<input type="hidden" name="room_id" value="<?php echo esc_attr( $room_id ); ?>">
					<?php wp_nonce_field( 'flexo_booking_copy_seasons' ); ?>
					<label><?php esc_html_e( 'Copy seasons starting in', 'flexo-booking' ); ?>
						<select name="year">
							<?php foreach ( $years as $year ) : ?>
								<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $year, (int) wp_date( 'Y' ) ); ?>><?php echo esc_html( $year ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><input type="radio" name="scope" value="room" checked> <?php echo esc_html( sprintf( /* translators: %s: room name */ __( 'for %s', 'flexo-booking' ), $room['title'] ) ); ?></label>
					<label><input type="radio" name="scope" value="all"> <?php esc_html_e( 'for all rooms', 'flexo-booking' ); ?></label>
					<?php submit_button( __( 'Copy to next year', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public static function handle_save() {
		self::check_access( 'flexo_booking_save_season' );

		$fields = array( 'room_id', 'name', 'date_from', 'date_to', 'price', 'weekend_price', 'min_nights' );
		$input  = array();
		foreach ( $fields as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$room_id = absint( $input['room_id'] );

		$result = Flexo_Booking_Seasons::save( $input, $id );
		if ( is_wp_error( $result ) ) {
			self::remember_form( array_merge( $input, array( 'id' => $id ) ) );
			$args = array(
				'room'        => $room_id,
				'flexo_error' => rawurlencode( $result->get_error_message() ),
			);
			if ( $id ) {
				$args['edit'] = $id;
			}
			wp_safe_redirect( self::page_url( $args ) . '#flexo-season-form' );
			exit;
		}
		wp_safe_redirect( self::page_url( array( 'room' => $room_id, 'flexo_msg' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::check_access( 'flexo_booking_delete_season_' . $id );
		$season = Flexo_Booking_Seasons::get( $id );
		Flexo_Booking_Seasons::delete( $id );
		wp_safe_redirect( self::page_url( array( 'room' => $season ? $season['room_id'] : 0, 'flexo_msg' => 'deleted' ) ) );
		exit;
	}

	public static function handle_copy() {
		self::check_access( 'flexo_booking_copy_seasons' );
		$room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
		$year    = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) wp_date( 'Y' );
		$all     = isset( $_POST['scope'] ) && 'all' === $_POST['scope'];

		$result = Flexo_Booking_Seasons::copy_to_next_year( $all ? 0 : $room_id, $year );
		$info   = sprintf(
			/* translators: 1: number of seasons, 2: year */
			_n( '%1$d season copied to %2$d.', '%1$d seasons copied to %2$d.', $result['copied'], 'flexo-booking' ),
			$result['copied'],
			$year + 1
		);
		if ( $result['skipped'] ) {
			/* translators: %s: list of skipped seasons */
			$info .= ' ' . sprintf( __( 'Skipped because they overlap an existing season: %s.', 'flexo-booking' ), implode( ', ', $result['skipped'] ) );
		}
		wp_safe_redirect( self::page_url( array( 'room' => $room_id, 'flexo_info' => rawurlencode( $info ) ) ) );
		exit;
	}

	/* Room edit screen: read-only summary. */

	public static function add_meta_box() {
		if ( Flexo_Booking_Seasons::enabled() ) {
			add_meta_box( 'flexo-room-seasons', __( 'Seasonal prices', 'flexo-booking' ), array( __CLASS__, 'render_meta_box' ), Flexo_Booking_Rooms::POST_TYPE, 'normal', 'default' );
		}
	}

	public static function render_meta_box( $post ) {
		$seasons = 'auto-draft' === $post->post_status ? array() : Flexo_Booking_Seasons::for_room( $post->ID );
		$today   = wp_date( 'Y-m-d' );
		$seasons = array_filter(
			$seasons,
			static function ( $season ) use ( $today ) {
				return $season['date_to'] >= $today;
			}
		);
		if ( $seasons ) {
			echo '<ul class="flexo-season-summary">';
			foreach ( $seasons as $season ) {
				printf(
					'<li><strong>%s</strong> %s – %s: %s</li>',
					esc_html( $season['name'] ),
					esc_html( Flexo_Booking_Dates::display( $season['date_from'] ) ),
					esc_html( Flexo_Booking_Dates::display( $season['date_to'] ) ),
					esc_html( Flexo_Booking_Money::format( $season['price'] ) )
				);
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No current or upcoming seasons – the normal price applies all year.', 'flexo-booking' ) . '</p>';
		}
		if ( 'auto-draft' !== $post->post_status ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( self::page_url( array( 'room' => $post->ID ) ) ), esc_html__( 'Edit seasonal prices', 'flexo-booking' ) );
		} else {
			echo '<p class="description">' . esc_html__( 'Save the room first, then add its seasons.', 'flexo-booking' ) . '</p>';
		}
	}
}
