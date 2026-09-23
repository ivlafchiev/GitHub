<?php
/**
 * Bookings → Calendar Sync: connect Booking.com, Airbnb and other iCal
 * calendars per room, and copy each room's export link. Shown only while the
 * "Calendar sync" feature is on.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Sync_Admin {

	const SLUG = 'flexo-booking-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		foreach ( array( 'add', 'remove', 'sync', 'sync_room', 'sync_all', 'interval', 'reset_link', 'review' ) as $action ) {
			add_action( 'admin_post_flexo_booking_ical_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function menu() {
		if ( Flexo_Booking_ICal::enabled() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Calendar Sync', 'flexo-booking' ), __( 'Calendar Sync', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			wp_enqueue_style( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/css/admin.css', array(), FLEXO_BOOKING_VERSION );
			wp_enqueue_script( 'flexo-booking-admin-sync', FLEXO_BOOKING_URL . 'assets/js/admin-sync.js', array(), FLEXO_BOOKING_VERSION, true );
			wp_localize_script(
				'flexo-booking-admin-sync',
				'FlexoBookingSync',
				array(
					'copied'       => __( 'Copied!', 'flexo-booking' ),
					'confirmReset' => __( 'Reset this link? Booking.com, Airbnb and other sites using the old link will stop receiving updates until you paste the new link there.', 'flexo-booking' ),
				)
			);
		}
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Unescaped nonce URL: echo it with esc_url(); it is also passed as JSON.
	 */
	private static function action_url( $action, $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'action'   => 'flexo_booking_ical_' . $action,
					'_wpnonce' => wp_create_nonce( 'flexo_booking_ical_' . $action ),
				),
				$args
			),
			admin_url( 'admin-post.php' )
		);
	}

	private static function check( $action ) {
		check_admin_referer( 'flexo_booking_ical_' . $action );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) || ! Flexo_Booking_ICal::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to manage calendar sync.', 'flexo-booking' ) );
		}
	}

	private static function back( $args, $anchor = '' ) {
		wp_safe_redirect( self::page_url( $args ) . ( $anchor ? '#' . $anchor : '' ) );
		exit;
	}

	/**
	 * "5 minutes ago" for a UTC MySQL date.
	 */
	public static function ago( $utc ) {
		if ( ! $utc ) {
			return __( 'never', 'flexo-booking' );
		}
		/* translators: %s: time difference, e.g. "5 mins" */
		return sprintf( __( '%s ago', 'flexo-booking' ), human_time_diff( strtotime( $utc . ' UTC' ), time() ) );
	}

	/**
	 * Sync failures and conflicts are never silent: shown on every admin
	 * screen to the people who manage bookings.
	 */
	public static function notices() {
		if ( ! Flexo_Booking_ICal::enabled() || ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$failing = Flexo_Booking_ICal::failing_calendars();
		if ( $failing ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Calendar sync is failing', 'flexo-booking' ) . '</strong> – ' . esc_html__( 'bookings from these calendars may be missing, so double bookings are possible:', 'flexo-booking' ) . '</p><ul class="flexo-notice-list">';
			foreach ( $failing as $calendar ) {
				printf(
					'<li><strong>%1$s</strong> (%2$s): %3$s <em>%4$s</em></li>',
					esc_html( $calendar['name'] ),
					esc_html( get_the_title( $calendar['room_id'] ) ),
					esc_html( $calendar['last_error'] ),
					/* translators: %d: number of failed attempts */
					esc_html( sprintf( _n( '(failed %d time in a row)', '(failed %d times in a row)', $calendar['fail_count'], 'flexo-booking' ), $calendar['fail_count'] ) )
				);
			}
			echo '</ul><p><a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open Calendar Sync', 'flexo-booking' ) . '</a></p></div>';
		}

		$conflicts = Flexo_Booking_ICal::open_conflicts();
		if ( $conflicts ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				/* translators: %d: number of conflicts */
				esc_html( sprintf( _n( '%d possible double booking', '%d possible double bookings', count( $conflicts ), 'flexo-booking' ), count( $conflicts ) ) ),
				esc_html__( 'from external calendars needs your attention.', 'flexo-booking' ),
				esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '#flexo-conflicts' ) ),
				esc_html__( 'Review', 'flexo-booking' )
			);
		}
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$rooms    = Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private' ) );
		$interval = Flexo_Booking_ICal::interval();
		$next     = wp_next_scheduled( Flexo_Booking_ICal::CRON_HOOK );
		?>
		<div class="wrap flexo-admin flexo-sync">
			<h1><?php esc_html_e( 'Calendar Sync', 'flexo-booking' ); ?></h1>
			<?php Flexo_Booking_Seasons_Admin::notices(); ?>

			<div class="flexo-tools-card flexo-sync-help">
				<h2><?php esc_html_e( 'How it works', 'flexo-booking' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Copy each room\'s link below into Booking.com, Airbnb or other sites, so they block dates booked on this website.', 'flexo-booking' ); ?></li>
					<li><?php esc_html_e( 'Paste the calendar (iCal) link from those sites under "Calendars imported into this room", so their bookings block dates here.', 'flexo-booking' ); ?></li>
				</ol>
				<p class="description">
					<?php
					printf(
						/* translators: %d: minutes */
						esc_html__( 'Good to know: calendar links are not instant. This website checks the other calendars every %d minutes, and Booking.com or Airbnb read this website\'s link on their own schedule (often every few hours). A guest could book the same dates in between. If you also sell on Booking.com or Airbnb, we recommend "Booking requests" (Settings → Features), so you confirm each website booking yourself.', 'flexo-booking' ),
						(int) $interval
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-inline-form">
					<input type="hidden" name="action" value="flexo_booking_ical_interval">
					<?php wp_nonce_field( 'flexo_booking_ical_interval' ); ?>
					<label for="flexo-interval"><strong><?php esc_html_e( 'Check other calendars', 'flexo-booking' ); ?></strong></label>
					<select id="flexo-interval" name="interval">
						<?php foreach ( Flexo_Booking_ICal::intervals() as $minutes => $label ) : ?>
							<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $interval, $minutes ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Save', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
					<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'sync_all' ) ); ?>"><?php esc_html_e( 'Sync all now', 'flexo-booking' ); ?></a>
					<?php if ( $next ) : ?>
						<span class="description">
							<?php
							/* translators: %s: time until the next automatic check */
							printf( esc_html__( 'Next automatic check in %s.', 'flexo-booking' ), esc_html( human_time_diff( time(), max( time(), $next ) ) ) );
							?>
						</span>
					<?php endif; ?>
				</form>
			</div>

			<?php if ( ! $rooms ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add your first room', 'flexo-booking' ); ?></a></p>
			<?php endif; ?>

			<?php foreach ( $rooms as $post ) : ?>
				<?php
				$room      = Flexo_Booking_Rooms::to_array( $post );
				$calendars = Flexo_Booking_ICal::calendars( $room['id'] );
				$anchor    = 'room-' . $room['id'];
				?>
				<div class="flexo-tools-card flexo-sync-room" id="<?php echo esc_attr( $anchor ); ?>">
					<div class="flexo-sync-room__head">
						<h2>
							<?php echo esc_html( $room['title'] ); ?>
							<?php if ( $room['units'] > 1 ) : ?>
								<span class="flexo-badge"><?php echo esc_html( sprintf( /* translators: %d: units */ __( '%d rooms of this type', 'flexo-booking' ), $room['units'] ) ); ?></span>
							<?php endif; ?>
						</h2>
						<?php if ( $calendars ) : ?>
							<a class="button" href="<?php echo esc_url( self::action_url( 'sync_room', array( 'room' => $room['id'] ) ) ); ?>"><?php esc_html_e( 'Sync this room now', 'flexo-booking' ); ?></a>
						<?php endif; ?>
					</div>

					<h3><?php esc_html_e( 'This room\'s calendar link (give it to Booking.com, Airbnb…)', 'flexo-booking' ); ?></h3>
					<div class="flexo-copy">
						<input type="text" readonly class="large-text code" value="<?php echo esc_attr( Flexo_Booking_ICal::export_url( $room['id'] ) ); ?>" aria-label="<?php esc_attr_e( 'Calendar link', 'flexo-booking' ); ?>" onclick="this.select()">
						<button type="button" class="button button-primary" data-flexo-copy><?php esc_html_e( 'Copy link', 'flexo-booking' ); ?></button>
						<a class="button" data-flexo-reset href="<?php echo esc_url( self::action_url( 'reset_link', array( 'room' => $room['id'] ) ) ); ?>"><?php esc_html_e( 'Reset link', 'flexo-booking' ); ?></a>
					</div>
					<p class="description">
						<?php esc_html_e( 'Booking.com: Extranet → Rates & Availability → Sync calendars → Import calendar → paste the link. Airbnb: Calendar → Availability → Connect to another website (Import calendar) → paste the link. The link contains only dates – no guest details or prices. Keep it private; "Reset link" makes the old one stop working.', 'flexo-booking' ); ?>
					</p>

					<h3><?php esc_html_e( 'Calendars imported into this room', 'flexo-booking' ); ?></h3>
					<?php if ( $calendars ) : ?>
						<table class="widefat striped flexo-sync-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Calendar', 'flexo-booking' ); ?></th>
									<?php if ( $room['units'] > 1 ) : ?>
										<th><?php esc_html_e( 'Counts as', 'flexo-booking' ); ?></th>
									<?php endif; ?>
									<th><?php esc_html_e( 'Last sync', 'flexo-booking' ); ?></th>
									<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
									<th><?php esc_html_e( 'Bookings', 'flexo-booking' ); ?></th>
									<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $calendars as $calendar ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $calendar['name'] ); ?></strong>
											<div class="flexo-muted flexo-url" title="<?php echo esc_attr( $calendar['import_url'] ); ?>"><?php echo esc_html( self::short_url( $calendar['import_url'] ) ); ?></div>
										</td>
										<?php if ( $room['units'] > 1 ) : ?>
											<td><?php echo esc_html( self::unit_label( $calendar['unit'] ) ); ?></td>
										<?php endif; ?>
										<td><?php echo esc_html( self::ago( $calendar['last_synced_at'] ) ); ?></td>
										<td>
											<?php if ( 'error' === $calendar['last_status'] ) : ?>
												<span class="flexo-status flexo-status--error">✕ <?php esc_html_e( 'Error', 'flexo-booking' ); ?></span>
												<div class="flexo-sync-error"><?php echo esc_html( $calendar['last_error'] ); ?></div>
												<?php if ( $calendar['fail_count'] > 1 ) : ?>
													<div class="flexo-muted"><?php echo esc_html( sprintf( /* translators: %d: attempts */ _n( 'Failed %d time in a row', 'Failed %d times in a row', $calendar['fail_count'], 'flexo-booking' ), $calendar['fail_count'] ) ); ?></div>
												<?php endif; ?>
											<?php elseif ( 'ok' === $calendar['last_status'] ) : ?>
												<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'OK', 'flexo-booking' ); ?></span>
											<?php else : ?>
												<span class="flexo-status"><?php esc_html_e( 'Not synced yet', 'flexo-booking' ); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $calendar['event_count'] ); ?></td>
										<td class="flexo-actions">
											<a class="button button-small" href="<?php echo esc_url( self::action_url( 'sync', array( 'id' => $calendar['id'] ) ) ); ?>"><?php esc_html_e( 'Sync now', 'flexo-booking' ); ?></a>
											<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'remove', array( 'id' => $calendar['id'] ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this calendar? Dates blocked by its bookings become available again on this website.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Remove', 'flexo-booking' ); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="flexo-muted"><?php esc_html_e( 'No calendars connected yet.', 'flexo-booking' ); ?></p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-sync-add">
						<input type="hidden" name="action" value="flexo_booking_ical_add">
						<input type="hidden" name="room_id" value="<?php echo esc_attr( $room['id'] ); ?>">
						<?php wp_nonce_field( 'flexo_booking_ical_add' ); ?>
						<label><span><?php esc_html_e( 'Name', 'flexo-booking' ); ?></span>
							<input type="text" name="name" placeholder="<?php esc_attr_e( 'e.g. Booking.com', 'flexo-booking' ); ?>" maxlength="100">
						</label>
						<label class="flexo-sync-add__url"><span><?php esc_html_e( 'Calendar link (iCal)', 'flexo-booking' ); ?></span>
							<input type="url" name="url" required placeholder="https://…/calendar.ics">
						</label>
						<?php if ( $room['units'] > 1 ) : ?>
							<label><span><?php esc_html_e( 'Counts as', 'flexo-booking' ); ?></span>
								<select name="unit">
									<option value="0"><?php esc_html_e( 'One room of this type', 'flexo-booking' ); ?></option>
									<?php for ( $u = 1; $u <= $room['units']; $u++ ) : ?>
										<option value="<?php echo esc_attr( $u ); ?>"><?php echo esc_html( self::unit_label( $u ) ); ?></option>
									<?php endfor; ?>
								</select>
							</label>
						<?php endif; ?>
						<?php submit_button( __( 'Add calendar', 'flexo-booking' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php if ( $room['units'] > 1 ) : ?>
						<p class="description"><?php esc_html_e( '"One room of this type": each imported booking takes one of the identical rooms. Choose a room number when the calendar belongs to one specific room (e.g. its own Airbnb listing) – bookings from calendars with the same number are then never counted twice.', 'flexo-booking' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function unit_label( $unit ) {
		/* translators: %d: room number */
		return $unit ? sprintf( __( 'Room no. %d', 'flexo-booking' ), $unit ) : __( 'One room of this type', 'flexo-booking' );
	}

	private static function short_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $host . ( strlen( $path ) > 28 ? substr( $path, 0, 25 ) . '…' : $path );
	}

	private static function result_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return array( 'flexo_error' => rawurlencode( $result->get_error_message() ) );
		}
		/* translators: 1: imported bookings, 2: new, 3: changed, 4: removed */
		return array( 'flexo_info' => rawurlencode( sprintf( __( 'Synced: %1$d bookings (%2$d new, %3$d changed, %4$d removed).', 'flexo-booking' ), $result['total'], $result['added'], $result['updated'], $result['removed'] ) ) );
	}

	public static function handle_add() {
		self::check( 'add' );
		$room_id = isset( $_POST['room_id'] ) ? absint( $_POST['room_id'] ) : 0;
		$id      = Flexo_Booking_ICal::add_calendar(
			$room_id,
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ), array( 'http', 'https', 'webcal', 'webcals' ) ) : '',
			isset( $_POST['unit'] ) ? absint( $_POST['unit'] ) : 0
		);
		if ( is_wp_error( $id ) ) {
			self::back( array( 'flexo_error' => rawurlencode( $id->get_error_message() ) ), 'room-' . $room_id );
		}
		self::back( self::result_message( Flexo_Booking_ICal::sync_calendar( $id ) ), 'room-' . $room_id );
	}

	public static function handle_remove() {
		self::check( 'remove' );
		$calendar = Flexo_Booking_ICal::get_calendar( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( $calendar ) {
			Flexo_Booking_ICal::delete_calendar( $calendar['id'] );
		}
		self::back( array( 'flexo_msg' => 'deleted' ), $calendar ? 'room-' . $calendar['room_id'] : '' );
	}

	public static function handle_sync() {
		self::check( 'sync' );
		$calendar = Flexo_Booking_ICal::get_calendar( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		if ( ! $calendar ) {
			self::back( array() );
		}
		self::back( self::result_message( Flexo_Booking_ICal::sync_calendar( $calendar['id'] ) ), 'room-' . $calendar['room_id'] );
	}

	public static function handle_sync_room() {
		self::check( 'sync_room' );
		$room_id = isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0;
		self::back( self::summary( Flexo_Booking_ICal::sync_room( $room_id ) ), 'room-' . $room_id );
	}

	public static function handle_sync_all() {
		self::check( 'sync_all' );
		self::back( self::summary( Flexo_Booking_ICal::sync_all() ) );
	}

	private static function summary( array $results ) {
		$failed = count( array_filter( $results, 'is_wp_error' ) );
		$ok     = count( $results ) - $failed;
		if ( $failed ) {
			/* translators: 1: calendars synced, 2: calendars failed */
			return array( 'flexo_error' => rawurlencode( sprintf( __( '%1$d calendars synced, %2$d failed – see the status column.', 'flexo-booking' ), $ok, $failed ) ) );
		}
		/* translators: %d: calendars synced */
		return array( 'flexo_info' => rawurlencode( sprintf( _n( '%d calendar synced.', '%d calendars synced.', $ok, 'flexo-booking' ), $ok ) ) );
	}

	public static function handle_interval() {
		self::check( 'interval' );
		$settings                  = Flexo_Booking_Settings::all();
		$settings['ical_interval'] = isset( $_POST['interval'] ) ? absint( $_POST['interval'] ) : 30;
		update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( $settings ) );
		Flexo_Booking_ICal::maybe_schedule();
		self::back( array( 'flexo_msg' => 'saved' ) );
	}

	public static function handle_reset_link() {
		self::check( 'reset_link' );
		$room_id = isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0;
		$room    = get_post( $room_id );
		if ( $room && Flexo_Booking_Rooms::POST_TYPE === $room->post_type ) {
			Flexo_Booking_ICal::regenerate_token( $room_id );
		}
		self::back( array( 'flexo_info' => rawurlencode( __( 'New link created. Paste it into Booking.com, Airbnb and the other sites – the old link no longer works.', 'flexo-booking' ) ) ), 'room-' . $room_id );
	}

	public static function handle_review() {
		self::check( 'review' );
		Flexo_Booking_ICal::mark_reviewed( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG );
		wp_safe_redirect( remove_query_arg( array( 'flexo_msg', 'flexo_error', 'flexo_info' ), $back ) );
		exit;
	}

	/**
	 * Nonce-protected URL to mark a conflict as reviewed (bookings list, calendar).
	 */
	public static function review_url( $event_id ) {
		return self::action_url( 'review', array( 'id' => (int) $event_id ) );
	}
}
