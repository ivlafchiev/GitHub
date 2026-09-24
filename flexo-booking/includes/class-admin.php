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
			'anonymised' => array( 'success', __( 'The guest\'s personal data was removed from this booking.', 'flexo-booking' ) ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$booking_id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		if ( $booking_id ) {
			self::render_booking( $booking_id );
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

			<?php
			$conflicts = Flexo_Booking_ICal::open_conflicts();
			if ( $conflicts ) :
				?>
				<div class="flexo-conflicts" id="flexo-conflicts">
					<h2>⚠ <?php esc_html_e( 'Possible double bookings from external calendars', 'flexo-booking' ); ?></h2>
					<p><?php esc_html_e( 'These bookings came from Booking.com, Airbnb or another connected calendar and don\'t fit into your availability. Check them, contact the guest or the booking website, then mark them as reviewed.', 'flexo-booking' ); ?></p>
					<table class="widefat">
						<thead><tr>
							<th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th>
							<th><?php esc_html_e( 'Calendar', 'flexo-booking' ); ?></th>
							<th><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></th>
							<th><?php esc_html_e( 'Details', 'flexo-booking' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'flexo-booking' ); ?></span></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $conflicts as $conflict ) : ?>
							<tr>
								<td><?php echo esc_html( get_the_title( $conflict['room_id'] ) ); ?></td>
								<td>⇄ <?php echo esc_html( $conflict['calendar_name'] ); ?></td>
								<td><?php echo esc_html( Flexo_Booking_Dates::display( $conflict['date_from'] ) . ' → ' . Flexo_Booking_Dates::display( $conflict['date_to'] ) ); ?></td>
								<td><?php echo esc_html( $conflict['conflict_note'] ); ?></td>
								<td class="flexo-actions">
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG . '&month=' . substr( $conflict['date_from'], 0, 7 ) ) ); ?>"><?php esc_html_e( 'Show in calendar', 'flexo-booking' ); ?></a>
									<a class="button button-small button-primary" href="<?php echo esc_url( Flexo_Booking_Sync_Admin::review_url( $conflict['id'] ) ); ?>"><?php esc_html_e( 'Mark as reviewed', 'flexo-booking' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$external_view = Flexo_Booking_ICal::enabled() && isset( $_GET['view'] ) && 'external' === $_GET['view'];
			if ( Flexo_Booking_ICal::enabled() ) :
				?>
				<ul class="subsubsub flexo-views">
					<li><a href="<?php echo esc_url( self::page_url() ); ?>" class="<?php echo $external_view ? '' : 'current'; ?>"><?php esc_html_e( 'Bookings on this website', 'flexo-booking' ); ?></a> |</li>
					<li><a href="<?php echo esc_url( self::page_url( array( 'view' => 'external' ) ) ); ?>" class="<?php echo $external_view ? 'current' : ''; ?>">⇄ <?php esc_html_e( 'From external calendars', 'flexo-booking' ); ?></a></li>
				</ul>
				<br class="clear">
			<?php endif; ?>

			<?php
			if ( $external_view ) {
				self::render_external();
				echo '</div>';
				return;
			}
			$conflicted = self::conflicted_bookings( $conflicts );
			$invoices   = Flexo_Booking_Invoices::for_bookings( wp_list_pluck( $result['items'], 'id' ) );
			?>

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
							<strong><a href="<?php echo esc_url( self::page_url( array( 'booking' => $b['id'] ) ) ); ?>"><?php echo esc_html( $b['reference'] ); ?></a></strong>
							<div>
								<?php echo self::source_badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in source_badge(). ?>
								<?php if ( isset( $invoices[ $b['id'] ] ) ) : ?>
									<span class="flexo-badge flexo-badge--invoice">🧾 <?php esc_html_e( 'Invoice', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( $b['anonymized_at'] ) : ?>
									<span class="flexo-badge"><?php esc_html_e( 'Anonymised', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( isset( $conflicted[ $b['id'] ] ) ) : ?>
									<a class="flexo-badge flexo-badge--conflict" href="#flexo-conflicts">⚠ <?php esc_html_e( 'Conflict', 'flexo-booking' ); ?></a>
								<?php endif; ?>
							</div>
							<div class="flexo-muted"><?php echo esc_html( mysql2date( $format . ' H:i', $b['created_at'] ) ); ?></div>
						</td>
						<td>
							<?php echo esc_html( $b['guest_name'] ? $b['guest_name'] : ( $b['anonymized_at'] ? __( 'Anonymised guest', 'flexo-booking' ) : '—' ) ); ?>
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
						<td>
							<?php echo esc_html( $b['room_title'] ); ?>
							<?php $flexo_plan = self::rate_plan_name( $b ); ?>
							<?php if ( $flexo_plan ) : ?>
								<div class="flexo-muted"><?php echo esc_html( $flexo_plan ); ?></div>
							<?php endif; ?>
							<?php if ( $b['promo_code'] ) : ?>
								<div><span class="flexo-badge flexo-badge--promo">% <?php echo esc_html( $b['promo_code'] ); ?></span></div>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( mysql2date( $format, $b['check_in'] ) . ' → ' . mysql2date( $format, $b['check_out'] ) ); ?>
							<div class="flexo-muted">
								<?php
								/* translators: %d: number of nights */
								echo esc_html( sprintf( _n( '%d night', '%d nights', $b['nights'], 'flexo-booking' ), $b['nights'] ) );
								?>
							</div>
						</td>
						<td>
							<?php echo esc_html( $b['adults'] . ( $b['children'] ? ' + ' . $b['children'] : '' ) ); ?>
							<?php if ( '' !== $b['children_ages'] ) : ?>
								<div class="flexo-muted">
									<?php
									/* translators: %s: children's ages */
									echo esc_html( sprintf( __( 'ages %s', 'flexo-booking' ), str_replace( ',', ', ', $b['children_ages'] ) ) );
									?>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( Flexo_Booking_Money::format( $b['total'], $b['currency'] ) ); ?>
							<?php
							$flexo_rows    = Flexo_Booking_Pricing::format_lines( Flexo_Booking_Pricing::snapshot( $b ) );
							$flexo_details = array();
							foreach ( $flexo_rows as $flexo_row ) {
								if ( count( $flexo_rows ) > 1 ) {
									$flexo_details[] = $flexo_row['label'] . ': ' . $flexo_row['formatted'];
								}
								if ( 'accommodation' === $flexo_row['type'] ) {
									$flexo_details = array_merge( $flexo_details, $flexo_row['details'] );
								}
							}
							if ( $flexo_details ) {
								echo '<div class="flexo-breakdown">' . esc_html( implode( "\n", $flexo_details ) ) . '</div>';
							}
							?>
						</td>
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

	/**
	 * The rate plan name stored with the booking, or ''.
	 */
	public static function rate_plan_name( array $b ) {
		$snapshot = Flexo_Booking_Pricing::snapshot( $b );
		return empty( $snapshot['rate_plan']['name'] ) ? '' : $snapshot['rate_plan']['name'];
	}

	/**
	 * One booking with everything the guest chose and the full price breakdown.
	 */
	private static function render_booking( $id ) {
		$b = Flexo_Booking_Bookings::get( $id );
		?>
		<div class="wrap flexo-admin flexo-booking-view">
			<p><a href="<?php echo esc_url( self::page_url() ); ?>">← <?php esc_html_e( 'All bookings', 'flexo-booking' ); ?></a></p>
			<?php if ( ! $b ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Booking not found.', 'flexo-booking' ); ?></p></div></div>
				<?php
				return;
			endif;
			$snapshot = Flexo_Booking_Pricing::snapshot( $b );
			$view     = Flexo_Booking_Pricing::public_view( $snapshot );
			$format   = get_option( 'date_format' );
			?>
			<h1>
				<?php
				/* translators: %s: booking reference */
				echo esc_html( sprintf( __( 'Booking %s', 'flexo-booking' ), $b['reference'] ) );
				?>
				<span class="flexo-status flexo-status--<?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( Flexo_Booking_Bookings::status_label( $b['status'] ) ); ?></span>
			</h1>
			<?php self::notice(); ?>
			<p><?php echo self::source_badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in source_badge(). ?></p>

			<div class="flexo-booking-view__grid">
				<div class="flexo-tools-card">
					<h2><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></h2>
					<table class="form-table flexo-detail-table" role="presentation">
						<tr><th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th><td><?php echo esc_html( $b['room_title'] ); ?></td></tr>
						<?php if ( $view['rate_plan'] ) : ?>
							<tr><th><?php esc_html_e( 'Rate plan', 'flexo-booking' ); ?></th><td>
								<?php echo esc_html( $view['rate_plan']['name'] ); ?>
								<span class="flexo-badge"><?php echo esc_html( $view['rate_plan']['refundable_label'] ); ?></span>
								<?php if ( $view['rate_plan']['cancellation_policy'] ) : ?>
									<div class="flexo-muted"><?php echo esc_html( $view['rate_plan']['cancellation_policy'] ); ?></div>
								<?php endif; ?>
							</td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Arrival', 'flexo-booking' ); ?></th><td><?php echo esc_html( mysql2date( $format, $b['check_in'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Departure', 'flexo-booking' ); ?></th><td><?php echo esc_html( mysql2date( $format, $b['check_out'] ) ); ?> · <?php echo esc_html( sprintf( /* translators: %d: nights */ _n( '%d night', '%d nights', $b['nights'], 'flexo-booking' ), $b['nights'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Guests', 'flexo-booking' ); ?></th><td><?php echo esc_html( Flexo_Booking_Children::guests_text( $b['adults'], $b['children'], $b['children_ages'] ) ); ?></td></tr>
						<?php if ( $b['promo_code'] ) : ?>
							<tr><th><?php esc_html_e( 'Promo code', 'flexo-booking' ); ?></th><td><span class="flexo-badge flexo-badge--promo">% <?php echo esc_html( $b['promo_code'] ); ?></span></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Booked', 'flexo-booking' ); ?></th><td><?php echo esc_html( mysql2date( $format . ' H:i', $b['created_at'] ) ); ?></td></tr>
					</table>
				</div>

				<div class="flexo-tools-card">
					<h2><?php esc_html_e( 'Guest', 'flexo-booking' ); ?></h2>
					<table class="form-table flexo-detail-table" role="presentation">
						<tr><th><?php esc_html_e( 'Name', 'flexo-booking' ); ?></th><td><?php echo esc_html( $b['guest_name'] ? $b['guest_name'] : ( $b['anonymized_at'] ? __( 'Anonymised guest', 'flexo-booking' ) : '—' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Email', 'flexo-booking' ); ?></th><td><?php echo $b['guest_email'] ? '<a href="mailto:' . esc_attr( $b['guest_email'] ) . '">' . esc_html( $b['guest_email'] ) . '</a>' : '—'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Phone', 'flexo-booking' ); ?></th><td><?php echo $b['guest_phone'] ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $b['guest_phone'] ) ) . '">' . esc_html( $b['guest_phone'] ) . '</a>' : '—'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Special requests', 'flexo-booking' ); ?></th><td><?php echo esc_html( $b['notes'] ? $b['notes'] : '—' ); ?></td></tr>
						<?php if ( $b['locale'] ) : ?>
							<tr><th><?php esc_html_e( 'Language', 'flexo-booking' ); ?></th><td><?php echo esc_html( Flexo_Booking_I18n::language_name( $b['locale'] ) ); ?></td></tr>
						<?php endif; ?>
						<?php $consent = Flexo_Booking_Privacy::consent_for( $b['id'] ); ?>
						<?php if ( $consent ) : ?>
							<tr><th><?php esc_html_e( 'Privacy consent', 'flexo-booking' ); ?></th><td>
								<?php
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'Given on %s', 'flexo-booking' ), mysql2date( 'd.m.Y H:i', $consent['created_at'] ) ) );
								?>
								<div class="flexo-muted"><?php echo esc_html( '"' . $consent['consent_text'] . '"' ); ?></div>
								<div class="flexo-muted"><?php echo esc_html( sprintf( /* translators: %s: short fingerprint of the text */ __( 'Text version %s', 'flexo-booking' ), substr( $consent['text_hash'], 0, 10 ) ) ); ?></div>
							</td></tr>
						<?php endif; ?>
					</table>
					<?php if ( $b['anonymized_at'] ) : ?>
						<p class="flexo-muted">
							<?php
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'Personal data removed on %s.', 'flexo-booking' ), mysql2date( 'd.m.Y', $b['anonymized_at'] ) ) );
							?>
						</p>
					<?php elseif ( Flexo_Booking_Privacy::enabled() && 'blocked' !== $b['status'] ) : ?>
						<p><a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_anonymise&id=' . $b['id'] ), 'flexo_booking_anonymise_' . $b['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this guest\'s name, email, phone, special requests and invoice details from the booking? Dates, room and price stay. This cannot be undone.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Anonymise', 'flexo-booking' ); ?></a></p>
					<?php endif; ?>
				</div>

				<?php $invoice = Flexo_Booking_Invoices::get( $b['id'] ); ?>
				<?php if ( $invoice ) : ?>
				<div class="flexo-tools-card flexo-invoice-card">
					<h2>🧾 <?php esc_html_e( 'Invoice requested', 'flexo-booking' ); ?></h2>
					<table class="form-table flexo-detail-table" role="presentation">
						<?php foreach ( Flexo_Booking_Invoices::rows( $invoice ) as $label => $value ) : ?>
							<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $value ); ?></td></tr>
						<?php endforeach; ?>
					</table>
					<p class="description"><?php esc_html_e( 'Issue the invoice in your accounting software.', 'flexo-booking' ); ?></p>
				</div>
				<?php endif; ?>

				<?php if ( 'blocked' !== $b['status'] ) : ?>
				<div class="flexo-tools-card">
					<h2><?php esc_html_e( 'Price', 'flexo-booking' ); ?></h2>
					<table class="widefat flexo-price-table">
						<tbody>
						<?php foreach ( $view['lines'] as $line ) : ?>
							<tr class="flexo-price-line flexo-price-line--<?php echo esc_attr( $line['type'] ); ?>">
								<td>
									<?php echo esc_html( $line['label'] ); ?>
									<?php if ( $line['details'] ) : ?>
										<div class="flexo-breakdown"><?php echo esc_html( implode( "\n", $line['details'] ) ); ?></div>
									<?php endif; ?>
								</td>
								<td class="flexo-num"><?php echo esc_html( $line['formatted'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot>
							<?php if ( $view['discount_total'] > 0 ) : ?>
								<tr><th><?php esc_html_e( 'Subtotal', 'flexo-booking' ); ?></th><td class="flexo-num"><?php echo esc_html( $view['subtotal_formatted'] ); ?></td></tr>
								<tr><th><?php esc_html_e( 'Discount', 'flexo-booking' ); ?></th><td class="flexo-num"><?php echo esc_html( $view['discount_formatted'] ); ?></td></tr>
							<?php endif; ?>
							<tr><th><?php esc_html_e( 'Total', 'flexo-booking' ); ?></th><td class="flexo-num"><strong><?php echo esc_html( Flexo_Booking_Money::format( $b['total'], $b['currency'] ) ); ?></strong></td></tr>
							<?php if ( $view['due_at_property'] > 0 ) : ?>
								<tr><th><?php esc_html_e( 'Also payable at the property', 'flexo-booking' ); ?></th><td class="flexo-num"><?php echo esc_html( $view['due_at_property_formatted'] ); ?></td></tr>
							<?php endif; ?>
						</tfoot>
					</table>
					<p class="description"><?php esc_html_e( 'This is the price calculated when the booking was made. Later price changes don\'t affect it.', 'flexo-booking' ); ?></p>
				</div>
				<?php endif; ?>
			</div>

			<?php $emails = Flexo_Booking_Emails::log_entries( array( 'booking_id' => $b['id'], 'limit' => 20 ) ); ?>
			<?php if ( $emails ) : ?>
				<div class="flexo-tools-card flexo-booking-emails">
					<h2><?php esc_html_e( 'Emails', 'flexo-booking' ); ?></h2>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $emails as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $entry['created_at'] ) ); ?></td>
								<td><?php echo esc_html( Flexo_Booking_Emails::type_label( $entry['email_type'] ) ); ?></td>
								<td><?php echo 'sent' === $entry['status'] ? '✓ ' . esc_html__( 'Sent', 'flexo-booking' ) : '✕ ' . esc_html__( 'Failed', 'flexo-booking' ) . ' – ' . esc_html( (string) $entry['error'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<p class="flexo-actions">
				<?php if ( 'pending' === $b['status'] ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php esc_html_e( 'Confirm', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<?php if ( 'cancelled' === $b['status'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php esc_html_e( 'Reinstate', 'flexo-booking' ); ?></a>
				<?php elseif ( 'blocked' !== $b['status'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel this booking? The guest will be notified by email.', 'flexo-booking' ) ); ?>');"><?php esc_html_e( 'Cancel booking', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG . '&month=' . substr( $b['check_in'], 0, 7 ) ) ); ?>"><?php esc_html_e( 'Show in calendar', 'flexo-booking' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Where a booking came from – shown as text, not only colour.
	 */
	public static function source_badge( array $b ) {
		if ( 'blocked' === $b['status'] ) {
			return '<span class="flexo-badge flexo-badge--blocked">⛔ ' . esc_html__( 'Blocked dates', 'flexo-booking' ) . '</span>';
		}
		if ( 'admin' === $b['source'] ) {
			return '<span class="flexo-badge flexo-badge--staff">✎ ' . esc_html__( 'Added by staff', 'flexo-booking' ) . '</span>';
		}
		return '<span class="flexo-badge flexo-badge--website">' . esc_html__( 'Website', 'flexo-booking' ) . '</span>';
	}

	/**
	 * Flexo bookings overlapping an open calendar conflict.
	 *
	 * @return array Booking ID => true.
	 */
	private static function conflicted_bookings( array $conflicts ) {
		global $wpdb;
		$ids      = array();
		$table    = Flexo_Booking_Install::table();
		$occupied = Flexo_Booking_Inventory::occupying_sql();
		foreach ( $conflicts as $conflict ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE room_id = %d AND check_in < %s AND check_out > %s AND {$occupied['sql']}", array_merge( array( $conflict['room_id'], $conflict['date_to'], $conflict['date_from'] ), $occupied['params'] ) ) ) as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Bookings imported from external calendars (read-only).
	 */
	private static function render_external() {
		$events = Flexo_Booking_ICal::events_between( wp_date( 'Y-m-d' ), '9999-12-31' );
		?>
		<p class="description"><?php esc_html_e( 'Bookings imported from Booking.com, Airbnb and other connected calendars. They block availability here; to change or cancel them, use the booking website – they update here at the next sync.', 'flexo-booking' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Text from the calendar', 'flexo-booking' ); ?></th>
					<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $events ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No current or upcoming bookings from external calendars.', 'flexo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $events as $event ) : ?>
					<tr>
						<td><span class="flexo-badge flexo-badge--external">⇄ <?php echo esc_html( $event['calendar_name'] ); ?></span></td>
						<td><?php echo esc_html( get_the_title( $event['room_id'] ) . ( $event['unit'] ? ' – ' . Flexo_Booking_Sync_Admin::unit_label( $event['unit'] ) : '' ) ); ?></td>
						<td><?php echo esc_html( Flexo_Booking_Dates::display( $event['date_from'] ) . ' → ' . Flexo_Booking_Dates::display( $event['date_to'] ) ); ?></td>
						<td><?php echo esc_html( $event['summary'] ); ?></td>
						<td>
							<?php if ( $event['conflict'] ) : ?>
								<span class="flexo-badge flexo-badge--conflict">⚠ <?php echo $event['conflict_reviewed'] ? esc_html__( 'Conflict (reviewed)', 'flexo-booking' ) : esc_html__( 'Conflict', 'flexo-booking' ); ?></span>
								<div class="flexo-muted"><?php echo esc_html( $event['conflict_note'] ); ?></div>
							<?php else : ?>
								<span class="flexo-status flexo-status--confirmed">✓ <?php esc_html_e( 'Blocks availability', 'flexo-booking' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_add() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$rooms = Flexo_Booking_Rooms::all();
		// Prefill from the calendar's quick actions.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$pre = array(
			'room'      => isset( $_GET['room'] ) ? absint( $_GET['room'] ) : 0,
			'check_in'  => isset( $_GET['check_in'] ) ? (string) Flexo_Booking_Dates::parse( sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) ) : '',
			'check_out' => isset( $_GET['check_out'] ) ? (string) Flexo_Booking_Dates::parse( sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) ) : '',
			'type'      => isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'confirmed',
		);
		// phpcs:enable
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
								<option value="confirmed" <?php selected( $pre['type'], 'confirmed' ); ?>><?php esc_html_e( 'Confirmed booking', 'flexo-booking' ); ?></option>
								<option value="pending" <?php selected( $pre['type'], 'pending' ); ?>><?php esc_html_e( 'Pending booking', 'flexo-booking' ); ?></option>
								<option value="blocked" <?php selected( $pre['type'], 'blocked' ); ?>><?php esc_html_e( 'Block dates (room closed)', 'flexo-booking' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb-room"><?php esc_html_e( 'Room', 'flexo-booking' ); ?></label></th>
						<td>
							<select id="fb-room" name="room" required>
								<?php foreach ( $rooms as $room ) : ?>
									<option value="<?php echo esc_attr( $room->ID ); ?>" <?php selected( $pre['room'], $room->ID ); ?>><?php echo esc_html( get_the_title( $room ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dates', 'flexo-booking' ); ?></th>
						<td>
							<input type="date" name="check_in" required value="<?php echo esc_attr( $pre['check_in'] ); ?>" aria-label="<?php esc_attr_e( 'Check-in', 'flexo-booking' ); ?>">
							→
							<input type="date" name="check_out" required value="<?php echo esc_attr( $pre['check_out'] ); ?>" aria-label="<?php esc_attr_e( 'Check-out', 'flexo-booking' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guests', 'flexo-booking' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Adults', 'flexo-booking' ); ?> <input type="number" name="adults" min="1" value="2" class="small-text"></label>
							<?php if ( Flexo_Booking_Children::enabled() ) : ?>
								<label><?php esc_html_e( 'Children\'s ages', 'flexo-booking' ); ?> <input type="text" name="children_ages" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 4, 11 – empty if no children', 'flexo-booking' ); ?>"></label>
							<?php else : ?>
								<label><?php esc_html_e( 'Children', 'flexo-booking' ); ?> <input type="number" name="children" min="0" value="0" class="small-text"></label>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( Flexo_Booking_Rate_Plans::enabled() && Flexo_Booking_Rate_Plans::all() ) : ?>
						<tr>
							<th scope="row"><label for="fb-plan"><?php esc_html_e( 'Rate plan', 'flexo-booking' ); ?></label></th>
							<td>
								<select id="fb-plan" name="rate_plan">
									<option value="0"><?php esc_html_e( 'The room\'s only plan / normal price', 'flexo-booking' ); ?></option>
									<?php foreach ( Flexo_Booking_Rate_Plans::all() as $flexo_plan ) : ?>
										<?php if ( $flexo_plan['active'] ) : ?>
											<option value="<?php echo esc_attr( $flexo_plan['id'] ); ?>"><?php echo esc_html( $flexo_plan['name'] ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The plan must be offered for the chosen room. The price is calculated automatically.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( Flexo_Booking_Promo_Codes::enabled() ) : ?>
						<tr>
							<th scope="row"><label for="fb-promo"><?php esc_html_e( 'Promo code', 'flexo-booking' ); ?></label></th>
							<td><input id="fb-promo" type="text" name="promo_code" class="regular-text" autocomplete="off"></td>
						</tr>
					<?php endif; ?>
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
					<?php if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email', 'flexo-booking' ); ?></th>
							<td><label><input type="checkbox" name="notify" value="1"> <?php esc_html_e( 'Send the confirmation email to the guest', 'flexo-booking' ); ?></label></td>
						</tr>
					<?php endif; ?>
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
		$warning = Flexo_Booking_Promo_Codes::over_limit_warning( (array) Flexo_Booking_Bookings::get( $id ) );
		if ( $warning ) {
			self::redirect( add_query_arg( array( 'flexo_msg' => 'updated', 'flexo_error' => rawurlencode( $warning ) ), $back ) );
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

		$fields = array( 'room', 'check_in', 'check_out', 'adults', 'children', 'children_ages', 'rate_plan', 'promo_code', 'guest_name', 'guest_email', 'guest_phone', 'notes', 'status' );
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

		if ( ! empty( $_POST['notify'] ) && 'blocked' !== $booking['status'] && Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			Flexo_Booking_Emails::send_guest( $booking, 'confirmed' === $booking['status'] ? 'confirmed' : 'request' );
		}

		self::redirect( self::page_url( array( 'flexo_msg' => 'added', 'booking' => $booking['id'] ) ) );
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
		// New columns are added at the end, so existing spreadsheets keep working.
		fputcsv( $out, array( 'Reference', 'Status', 'Room', 'Check-in', 'Check-out', 'Nights', 'Adults', 'Children', 'Guest', 'Email', 'Phone', 'Notes', 'Total', 'Currency', 'Source', 'Created', 'Price details', 'Children ages', 'Rate plan', 'Refundable', 'Promo code', 'Discount', 'Tourist tax', 'Payable at property', 'Language', 'Privacy consent', 'Invoice', 'Invoice name / company', 'Company ID', 'VAT number', 'Invoice address', 'Contact person', 'Anonymised' ), ',', '"', '\\' );
		$invoices = Flexo_Booking_Invoices::for_bookings( wp_list_pluck( $result['items'], 'id' ) );
		foreach ( $result['items'] as $b ) {
			$snapshot = Flexo_Booking_Pricing::snapshot( $b );
			$details  = 'blocked' === $b['status'] ? '' : Flexo_Booking_Pricing::summary_text( $snapshot );
			$plan     = empty( $snapshot['rate_plan'] ) ? null : $snapshot['rate_plan'];
			$row      = array( $b['reference'], $b['status'], $b['room_title'], $b['check_in'], $b['check_out'], $b['nights'], $b['adults'], $b['children'], $b['guest_name'], $b['guest_email'], $b['guest_phone'], $b['notes'], $b['total'], $b['currency'], $b['source'], $b['created_at'], $details, str_replace( ',', ', ', $b['children_ages'] ), $plan ? $plan['name'] : '', $plan ? ( $plan['refundable'] ? 'yes' : 'no' ) : '', $b['promo_code'], $b['discount_total'] ? $b['discount_total'] : '', $b['tax_total'] ? $b['tax_total'] : '', empty( $snapshot['due_at_property'] ) ? '' : $snapshot['due_at_property'] );
			$inv      = isset( $invoices[ $b['id'] ] ) ? $invoices[ $b['id'] ] : null;
			$consent  = Flexo_Booking_Privacy::consent_for( $b['id'] );
			$company  = $inv && 'company' === $inv['invoice_type'];
			$row      = array_merge(
				$row,
				array(
					$b['locale'],
					$consent ? $consent['created_at'] : '',
					$inv ? ( $company ? 'company' : 'person' ) : '',
					$inv ? ( $company ? $inv['company_name'] : $inv['full_name'] ) : '',
					$inv ? $inv['company_id'] : '',
					$inv ? $inv['vat_number'] : '',
					$inv ? ( $company ? $inv['company_address'] : $inv['address'] ) : '',
					$inv ? $inv['contact_person'] : '',
					$b['anonymized_at'] ? $b['anonymized_at'] : '',
				)
			);
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
