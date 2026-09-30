<?php
/**
 * Bookings → Help: short guides for everyday tasks and the FlexoHotels
 * support contact. The contact is set by the agency, either in
 * wp-config.php:
 *
 *   define( 'FLEXO_BOOKING_SUPPORT', 'FlexoHotels|support@flexohotels.com|+359 88 000 0000|https://flexohotels.com/support' );
 *
 * (name|email|phone|web page, any part may be empty) or on the Agency screen.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Help {

	const SLUG   = 'flexo-booking-help';
	const OPTION = 'flexo_booking_support';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_post_flexo_booking_save_support', array( __CLASS__, 'handle_save' ) );
	}

	public static function menu() {
		add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Help', 'flexo-booking' ), __( 'Help', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function url( $topic = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG ) . ( '' !== $topic ? '#help-' . $topic : '' );
	}

	/**
	 * A small "?" link to a guide, for next to a setting or a heading.
	 */
	public static function link( $topic ) {
		$guides = self::guides();
		if ( ! isset( $guides[ $topic ] ) ) {
			return '';
		}
		/* translators: %s: guide title */
		return '<a class="flexo-help-link" href="' . esc_url( self::url( $topic ) ) . '" title="' . esc_attr( sprintf( __( 'Help: %s', 'flexo-booking' ), $guides[ $topic ]['title'] ) ) . '"><span aria-hidden="true">?</span><span class="screen-reader-text">' . esc_html( sprintf( __( 'Help: %s', 'flexo-booking' ), $guides[ $topic ]['title'] ) ) . '</span></a>';
	}

	/**
	 * @return array { name, email, phone, url }
	 */
	public static function support() {
		$empty = array(
			'name'  => '',
			'email' => '',
			'phone' => '',
			'url'   => '',
		);
		if ( defined( 'FLEXO_BOOKING_SUPPORT' ) && is_string( FLEXO_BOOKING_SUPPORT ) ) {
			$parts = array_pad( array_map( 'trim', explode( '|', FLEXO_BOOKING_SUPPORT ) ), 4, '' );
			return array(
				'name'  => $parts[0],
				'email' => is_email( $parts[1] ) ? $parts[1] : '',
				'phone' => $parts[2],
				'url'   => esc_url_raw( $parts[3] ),
			);
		}
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? array_merge( $empty, array_intersect_key( $stored, $empty ) ) : $empty;
	}

	/**
	 * Guides: title and steps (short, in hotel language).
	 */
	public static function guides() {
		$guides = array(
			'room'      => array(
				'title' => __( 'Add a room', 'flexo-booking' ),
				'steps' => array(
					__( 'Go to Bookings → Rooms & prices → Add room.', 'flexo-booking' ),
					__( 'Enter the name, a photo (Featured image) and a short description.', 'flexo-booking' ),
					__( 'Under Booking details set the price per night, how many guests fit and how many rooms of this type you have.', 'flexo-booking' ),
					__( 'Optional: size, beds and amenities – guests see them on the room card.', 'flexo-booking' ),
					__( 'Publish. The room appears in the booking form straight away.', 'flexo-booking' ),
				),
			),
			'seasons'   => array(
				'title' => __( 'Set summer or holiday prices', 'flexo-booking' ),
				'steps' => array(
					__( 'Switch on "Seasonal prices" under Settings → Features (if you don\'t see it, ask FlexoHotels).', 'flexo-booking' ),
					__( 'Go to Rooms & prices → Seasonal prices → Add season.', 'flexo-booking' ),
					__( 'Choose the room, the first and last night, the price per night and, if you like, a minimum stay.', 'flexo-booking' ),
					__( 'Nights outside any season use the room\'s normal price.', 'flexo-booking' ),
				),
			),
			'sync'      => array(
				'title' => __( 'Connect Booking.com or Airbnb', 'flexo-booking' ),
				'steps' => array(
					__( 'In Booking.com / Airbnb, find the room\'s calendar export (iCal) link and copy it.', 'flexo-booking' ),
					__( 'Go to Rooms & prices → Calendar sync, paste it next to the room and save.', 'flexo-booking' ),
					__( 'Copy this website\'s calendar link for the room and paste it into Booking.com / Airbnb (calendar import).', 'flexo-booking' ),
					__( 'Bookings from there then block the dates here, and the other way round. Updates run every 30 minutes.', 'flexo-booking' ),
				),
			),
			'request'   => array(
				'title' => __( 'Answer a booking request', 'flexo-booking' ),
				'steps' => array(
					__( 'New requests appear on Today under "Needs your attention" and in your email.', 'flexo-booking' ),
					__( 'Press Confirm – the guest gets the confirmation email. Or Decline – the guest gets a cancellation email.', 'flexo-booking' ),
					__( 'With bank transfer, confirming sends the bank details and the booking waits for the payment.', 'flexo-booking' ),
				),
			),
			'block'     => array(
				'title' => __( 'Block dates (renovation, own use)', 'flexo-booking' ),
				'steps' => array(
					__( 'For one room: Add booking → Type "Block dates", choose the room and the dates.', 'flexo-booking' ),
					__( 'For the whole hotel or a longer period: Rooms & prices → Closed dates.', 'flexo-booking' ),
					__( 'Guests see these dates as not available.', 'flexo-booking' ),
				),
			),
			'transfer'  => array(
				'title' => __( 'A bank transfer arrived', 'flexo-booking' ),
				'steps' => array(
					__( 'Find the booking by the payment reference (search in All bookings, or Today → "Waiting for bank transfer").', 'flexo-booking' ),
					__( 'Open it and press "Payment received". Enter the amount if it differs.', 'flexo-booking' ),
					__( 'The booking is confirmed and the guest gets an email.', 'flexo-booking' ),
				),
			),
			'refund'    => array(
				'title' => __( 'Refund a payment', 'flexo-booking' ),
				'steps' => array(
					__( 'Card payments: refund in your Stripe dashboard – the booking shows the refund automatically.', 'flexo-booking' ),
					__( 'Bank transfers: pay the money back from your bank, then record the refund on the booking (Payments → Record a refund).', 'flexo-booking' ),
					__( 'Cancelling a booking never refunds money by itself.', 'flexo-booking' ),
				),
			),
			'changes'   => array(
				'title' => __( 'A guest asks to change or cancel', 'flexo-booking' ),
				'steps' => array(
					__( 'Requests from the guest booking page appear on Today and on the booking, and you get an email.', 'flexo-booking' ),
					__( 'Nothing changes by itself. Reply to the guest, then cancel, or add a new booking for the new dates.', 'flexo-booking' ),
					__( 'Press "Mark as answered" when done.', 'flexo-booking' ),
				),
			),
			'emails'    => array(
				'title' => __( 'Change the emails guests receive', 'flexo-booking' ),
				'steps' => array(
					__( 'Bookings → Emails: edit the subject and text of each email. Words in {curly brackets} are filled in automatically.', 'flexo-booking' ),
					__( 'Send yourself a test email to see how it looks.', 'flexo-booking' ),
					__( 'If emails don\'t arrive, install an SMTP plugin with your hotel\'s mailbox – see Settings → Health.', 'flexo-booking' ),
				),
			),
			'roles'     => array(
				'title' => __( 'Give your team access', 'flexo-booking' ),
				'steps' => array(
					__( 'Users → Add New. Role "Hotel Staff": bookings, calendar, payments received, notes. Role "Hotel Manager": also rooms, prices, emails and appearance.', 'flexo-booking' ),
					__( 'Only administrators change Settings, payments keys and Import & export.', 'flexo-booking' ),
				),
			),
		);
		return apply_filters( 'flexo_booking_help_guides', $guides );
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$support = self::support();
		?>
		<div class="wrap flexo-admin flexo-help">
			<h1><?php esc_html_e( 'Help', 'flexo-booking' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Support contact saved.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>

			<div class="flexo-tools-card flexo-help__support">
				<h2><?php esc_html_e( 'Need a hand?', 'flexo-booking' ); ?></h2>
				<?php if ( $support['email'] || $support['phone'] || $support['url'] ) : ?>
					<p>
						<?php
						/* translators: %s: support team name, e.g. FlexoHotels */
						echo esc_html( sprintf( __( 'Contact %s – we set up your booking system and are happy to help.', 'flexo-booking' ), $support['name'] ? $support['name'] : 'FlexoHotels' ) );
						?>
					</p>
					<ul class="flexo-help__contact">
						<?php if ( $support['phone'] ) : ?>
							<li>☎ <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $support['phone'] ) ); ?>"><?php echo esc_html( $support['phone'] ); ?></a></li>
						<?php endif; ?>
						<?php if ( $support['email'] ) : ?>
							<li>✉ <a href="mailto:<?php echo esc_attr( $support['email'] ); ?>"><?php echo esc_html( $support['email'] ); ?></a></li>
						<?php endif; ?>
						<?php if ( $support['url'] ) : ?>
							<li>↗ <a href="<?php echo esc_url( $support['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $support['url'], PHP_URL_HOST ) ); ?></a></li>
						<?php endif; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'Contact FlexoHotels, the team that set up your booking system.', 'flexo-booking' ); ?></p>
				<?php endif; ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<p class="description"><?php esc_html_e( 'When you write to support, include the system report from Settings → Health.', 'flexo-booking' ); ?> <a href="<?php echo esc_url( Flexo_Booking_Health::url() ); ?>"><?php esc_html_e( 'Open Health', 'flexo-booking' ); ?></a></p>
					<p><a class="button" href="<?php echo esc_url( Flexo_Booking_Wizard::url() ); ?>"><?php esc_html_e( 'Open the setup wizard', 'flexo-booking' ); ?></a></p>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Short guides', 'flexo-booking' ); ?></h2>
			<div class="flexo-help__guides">
				<?php foreach ( self::guides() as $key => $guide ) : ?>
					<details class="flexo-help__guide" id="help-<?php echo esc_attr( $key ); ?>">
						<summary><?php echo esc_html( $guide['title'] ); ?></summary>
						<ol>
							<?php foreach ( $guide['steps'] as $step ) : ?>
								<li><?php echo esc_html( $step ); ?></li>
							<?php endforeach; ?>
						</ol>
					</details>
				<?php endforeach; ?>
			</div>
			<script>
				( function () {
					var id = window.location.hash.replace( '#', '' );
					var el = id ? document.getElementById( id ) : null;
					if ( el && el.tagName === 'DETAILS' ) {
						el.open = true;
						el.querySelector( 'summary' ).focus();
					}
				}() );
			</script>
		</div>
		<?php
	}

	/**
	 * Support contact fields for the Agency screen.
	 */
	public static function render_support_form() {
		$support = self::support();
		$locked  = defined( 'FLEXO_BOOKING_SUPPORT' );
		?>
		<h2><?php esc_html_e( 'Support contact on the Help page', 'flexo-booking' ); ?></h2>
		<?php if ( $locked ) : ?>
			<p class="description"><?php esc_html_e( 'Set by FLEXO_BOOKING_SUPPORT in wp-config.php.', 'flexo-booking' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="flexo_booking_save_support">
			<?php wp_nonce_field( 'flexo_booking_save_support' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( array( 'name' => __( 'Name', 'flexo-booking' ), 'email' => __( 'Email', 'flexo-booking' ), 'phone' => __( 'Phone', 'flexo-booking' ), 'url' => __( 'Web page', 'flexo-booking' ) ) as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="flexo-support-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input id="flexo-support-<?php echo esc_attr( $key ); ?>" type="<?php echo 'email' === $key ? 'email' : ( 'url' === $key ? 'url' : 'text' ); ?>" class="regular-text" name="support[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $support[ $key ] ); ?>" <?php disabled( $locked ); ?>></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php if ( ! $locked ) : ?>
				<?php submit_button( __( 'Save support contact', 'flexo-booking' ), 'secondary' ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	public static function handle_save() {
		check_admin_referer( 'flexo_booking_save_support' );
		if ( ! Flexo_Booking_Features::is_agency_user() || defined( 'FLEXO_BOOKING_SUPPORT' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flexo-booking' ) );
		}
		$in = isset( $_POST['support'] ) && is_array( $_POST['support'] ) ? wp_unslash( $_POST['support'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		update_option(
			self::OPTION,
			array(
				'name'  => sanitize_text_field( isset( $in['name'] ) ? $in['name'] : '' ),
				'email' => sanitize_email( isset( $in['email'] ) ? $in['email'] : '' ),
				'phone' => sanitize_text_field( isset( $in['phone'] ) ? $in['phone'] : '' ),
				'url'   => esc_url_raw( isset( $in['url'] ) ? $in['url'] : '' ),
			),
			false
		);
		wp_safe_redirect( add_query_arg( array( 'page' => Flexo_Booking_Features::AGENCY_SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
