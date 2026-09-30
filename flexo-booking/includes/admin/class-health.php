<?php
/**
 * Settings → Health: is everything a hotel needs working? Each check says
 * ✓ / ⚠ / ✕ and how to fix it. "Copy system report" gives FlexoHotels
 * support the technical facts – without keys, bank details or guest data.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Health {

	const CRON_OPTION   = 'flexo_booking_cron_last';
	const STRIPE_OPTION = 'flexo_booking_stripe_check';

	public static function init() {
		// The hourly job leaves a time stamp, so a stopped WP-Cron is noticed.
		add_action( Flexo_Booking_Emails::HOURLY, array( __CLASS__, 'cron_ran' ), 1 );
		add_action( 'admin_post_flexo_booking_check_stripe', array( __CLASS__, 'handle_check_stripe' ) );
	}

	public static function cron_ran() {
		update_option( self::CRON_OPTION, time(), false );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=health' );
	}

	/**
	 * @return array[] { id, status: ok|warning|error, title, text, action: array|null { label, url } }
	 */
	public static function checks() {
		global $wpdb;
		$checks   = array();
		$settings = Flexo_Booking_Settings::all();
		$add      = static function ( $id, $status, $title, $text, $action = null ) use ( &$checks ) {
			$checks[] = compact( 'id', 'status', 'title', 'text', 'action' );
		};

		// Database update.
		$error = get_option( Flexo_Booking_Migrations::ERROR_OPTION );
		if ( $error ) {
			$add( 'database', 'error', __( 'Database update', 'flexo-booking' ), __( 'The last database update did not finish. It is retried automatically; if this stays, contact FlexoHotels support.', 'flexo-booking' ) . ' (' . $error . ')' );
		} else {
			$add( 'database', 'ok', __( 'Database update', 'flexo-booking' ), __( 'Up to date.', 'flexo-booking' ) );
		}

		// Rooms.
		$rooms  = Flexo_Booking_Rooms::all();
		$priced = 0;
		foreach ( $rooms as $post ) {
			$priced += Flexo_Booking_Rooms::to_array( $post )['price'] > 0 ? 1 : 0;
		}
		if ( ! $rooms ) {
			$add( 'rooms', 'error', __( 'Rooms', 'flexo-booking' ), __( 'No rooms yet – guests can\'t book anything.', 'flexo-booking' ), array( __( 'Add a room', 'flexo-booking' ), admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ) );
		} elseif ( $priced < count( $rooms ) ) {
			$add( 'rooms', 'warning', __( 'Rooms', 'flexo-booking' ), __( 'Some rooms have no price per night, so they are offered for free.', 'flexo-booking' ), array( __( 'Check the rooms', 'flexo-booking' ), admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ) );
		} else {
			/* translators: %d: number of rooms */
			$add( 'rooms', 'ok', __( 'Rooms', 'flexo-booking' ), sprintf( _n( '%d room with a price.', '%d rooms with prices.', count( $rooms ), 'flexo-booking' ), count( $rooms ) ) );
		}

		// Booking page.
		Flexo_Booking_Guest::forget_booking_page();
		$detected = Flexo_Booking_Guest::detect_booking_page();
		$page     = Flexo_Booking_Guest::booking_page_url();
		if ( '' === $page ) {
			$add( 'booking_page', 'error', __( 'Booking page', 'flexo-booking' ), __( 'No published page has the booking form, so guests can\'t book and email links (calendar, manage booking) have nowhere to go.', 'flexo-booking' ), array( __( 'Create the booking page', 'flexo-booking' ), Flexo_Booking_Wizard::create_page_url() ) );
		} elseif ( '' === $detected ) {
			$add( 'booking_page', 'warning', __( 'Booking page', 'flexo-booking' ), __( 'The booking page set under Settings → Hotel does not seem to contain the booking form (shortcode [flexo_booking] or the Elementor widget). Check that the page is published and has the form.', 'flexo-booking' ), array( __( 'Open the page', 'flexo-booking' ), $page ) );
		} else {
			$add( 'booking_page', 'ok', __( 'Booking page', 'flexo-booking' ), wp_make_link_relative( $page ), array( __( 'Open', 'flexo-booking' ), $page ) );
		}

		// Email sending.
		$test   = get_option( 'flexo_booking_last_test_email' );
		$failed = 0;
		if ( Flexo_Booking_Schema::table_exists( 'email_log' ) ) {
			$log = Flexo_Booking_Schema::table( 'email_log' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE status = 'failed' AND created_at >= %s", wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		}
		$emails_url = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-emails' );
		if ( $failed ) {
			/* translators: %d: number of emails */
			$add( 'email', 'error', __( 'Email sending', 'flexo-booking' ), sprintf( _n( '%d email failed in the last 7 days. Guests may not have received their confirmation. Set up an SMTP plugin (e.g. WP Mail SMTP) with your hotel\'s mailbox and send a test email.', '%d emails failed in the last 7 days. Guests may not have received their confirmation. Set up an SMTP plugin (e.g. WP Mail SMTP) with your hotel\'s mailbox and send a test email.', $failed, 'flexo-booking' ), $failed ), array( __( 'Open the email log', 'flexo-booking' ), $emails_url . '#flexo-email-log' ) );
		} elseif ( is_array( $test ) && empty( $test['sent'] ) ) {
			$add( 'email', 'error', __( 'Email sending', 'flexo-booking' ), __( 'The last test email could not be sent.', 'flexo-booking' ), array( __( 'Send a test email', 'flexo-booking' ), $emails_url . '#flexo-test-email' ) );
		} elseif ( ! Flexo_Booking_Emails::smtp_detected() ) {
			$add( 'email', 'warning', __( 'Email sending', 'flexo-booking' ), __( 'No email sending plugin (SMTP) found. Emails from websites often end up in spam; an SMTP plugin with your hotel\'s mailbox makes them arrive.', 'flexo-booking' ), array( __( 'Send a test email', 'flexo-booking' ), $emails_url . '#flexo-test-email' ) );
		} else {
			$text = __( 'An email sending plugin (SMTP) is active.', 'flexo-booking' );
			if ( is_array( $test ) ) {
				/* translators: %s: date and time */
				$text .= ' ' . sprintf( __( 'Last test email sent %s.', 'flexo-booking' ), wp_date( 'd.m.Y H:i', $test['time'] ) );
			}
			$add( 'email', 'ok', __( 'Email sending', 'flexo-booking' ), $text );
		}

		// Scheduled jobs.
		$last = (int) get_option( self::CRON_OPTION );
		if ( ! $last ) {
			$add( 'cron', 'warning', __( 'Scheduled jobs (WP-Cron)', 'flexo-booking' ), __( 'Not run yet. Reminders, payment deadlines and calendar sync run hourly; this check turns green after the first run (a visit to the site starts it).', 'flexo-booking' ) );
		} elseif ( time() - $last > 3 * HOUR_IN_SECONDS ) {
			/* translators: %s: date and time */
			$add( 'cron', 'error', __( 'Scheduled jobs (WP-Cron)', 'flexo-booking' ), sprintf( __( 'Last run %s. Reminders, payment deadlines and calendar sync are not running. If DISABLE_WP_CRON is set, ask your host for a real cron job every 5–15 minutes.', 'flexo-booking' ), wp_date( 'd.m.Y H:i', $last ) ) );
		} else {
			/* translators: %s: date and time */
			$add( 'cron', 'ok', __( 'Scheduled jobs (WP-Cron)', 'flexo-booking' ), sprintf( __( 'Last run %s.', 'flexo-booking' ), wp_date( 'd.m.Y H:i', $last ) ) );
		}

		// Calendar sync.
		if ( Flexo_Booking_ICal::enabled() ) {
			$calendars = array_filter(
				Flexo_Booking_ICal::calendars(),
				static function ( $c ) {
					return $c['active'];
				}
			);
			$failing   = array();
			foreach ( $calendars as $calendar ) {
				if ( 'error' === $calendar['last_status'] || $calendar['fail_count'] > 0 ) {
					$failing[] = $calendar['name'] . ( $calendar['last_error'] ? ' – ' . $calendar['last_error'] : '' );
				}
			}
			if ( ! $calendars ) {
				$add( 'ical', 'warning', __( 'Calendar sync', 'flexo-booking' ), __( 'Switched on, but no calendars connected yet.', 'flexo-booking' ), array( __( 'Connect a calendar', 'flexo-booking' ), Flexo_Booking_Sync_Admin::page_url() ) );
			} elseif ( $failing ) {
				$add( 'ical', 'error', __( 'Calendar sync', 'flexo-booking' ), __( 'Not updating:', 'flexo-booking' ) . ' ' . implode( '; ', $failing ) . '. ' . __( 'Copy the calendar link again from Booking.com / Airbnb.', 'flexo-booking' ), array( __( 'Open Calendar sync', 'flexo-booking' ), Flexo_Booking_Sync_Admin::page_url() ) );
			} else {
				/* translators: %d: number of calendars */
				$add( 'ical', 'ok', __( 'Calendar sync', 'flexo-booking' ), sprintf( _n( '%d calendar updating normally.', '%d calendars updating normally.', count( $calendars ), 'flexo-booking' ), count( $calendars ) ) );
			}
		}

		// Online payment.
		if ( Flexo_Booking_Features::is_enabled( 'online_payment' ) ) {
			$stripe  = Flexo_Booking_Payments::gateway( 'stripe' );
			$payurl  = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=payments' );
			$check   = get_option( self::STRIPE_OPTION );
			$mode    = 'live' === $settings['stripe_mode'] ? __( 'live mode (real payments)', 'flexo-booking' ) : __( 'test mode (no real payments)', 'flexo-booking' );
			$webhook = '';
			if ( Flexo_Booking_Schema::table_exists( 'webhook_events' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$webhook = (string) $wpdb->get_var( 'SELECT MAX(created_at) FROM ' . Flexo_Booking_Schema::table( 'webhook_events' ) . " WHERE gateway = 'stripe'" );
			}
			$details = $mode . '. ' . ( '' !== $webhook ? sprintf( /* translators: %s: date and time */ __( 'Last notification from Stripe: %s.', 'flexo-booking' ), mysql2date( 'd.m.Y H:i', $webhook ) ) : __( 'No notification from Stripe received yet.', 'flexo-booking' ) );
			if ( ! $stripe || ! $stripe->is_ready() ) {
				$add( 'stripe', 'error', __( 'Card payments (Stripe)', 'flexo-booking' ), __( 'The secret key or the webhook signing secret is missing for the chosen mode, so guests can\'t pay by card.', 'flexo-booking' ), array( __( 'Enter the keys', 'flexo-booking' ), $payurl ) );
			} elseif ( is_array( $check ) && empty( $check['ok'] ) && $check['mode'] === $settings['stripe_mode'] ) {
				$add( 'stripe', 'error', __( 'Card payments (Stripe)', 'flexo-booking' ), __( 'Stripe did not accept the secret key:', 'flexo-booking' ) . ' ' . $check['message'], array( __( 'Enter the keys', 'flexo-booking' ), $payurl ) );
			} else {
				$verified = is_array( $check ) && ! empty( $check['ok'] ) && $check['mode'] === $settings['stripe_mode'];
				$add( 'stripe', $verified ? ( 'live' === $settings['stripe_mode'] ? 'ok' : 'warning' ) : 'warning', __( 'Card payments (Stripe)', 'flexo-booking' ), ( $verified ? __( 'Keys accepted by Stripe;', 'flexo-booking' ) . ' ' : __( 'Keys entered (not checked yet);', 'flexo-booking' ) . ' ' ) . $details, array( __( 'Check keys', 'flexo-booking' ), wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_check_stripe' ), 'flexo_booking_check_stripe' ) ) );
			}
		}
		if ( Flexo_Booking_Features::is_enabled( 'bank_transfer' ) ) {
			$bank = Flexo_Booking_Payments::gateway( 'bank_transfer' );
			if ( ! $bank || ! $bank->is_available() ) {
				$add( 'bank', 'error', __( 'Bank transfer', 'flexo-booking' ), __( 'The beneficiary or IBAN is missing, so guests can\'t choose bank transfer.', 'flexo-booking' ), array( __( 'Enter the bank details', 'flexo-booking' ), admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=payments' ) ) );
			} else {
				$add( 'bank', 'ok', __( 'Bank transfer', 'flexo-booking' ), __( 'Bank details entered.', 'flexo-booking' ) );
			}
		}

		// Privacy.
		if ( Flexo_Booking_Privacy::enabled() ) {
			$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
			if ( '' === $settings['privacy_page'] && ( ! $privacy || 'publish' !== get_post_status( $privacy ) ) ) {
				$add( 'privacy', 'warning', __( 'Privacy policy', 'flexo-booking' ), __( 'No published privacy policy page. The consent text links to it.', 'flexo-booking' ), array( __( 'Set the page', 'flexo-booking' ), admin_url( 'options-privacy.php' ) ) );
			} else {
				$add( 'privacy', 'ok', __( 'Privacy policy', 'flexo-booking' ), __( 'Privacy policy page set.', 'flexo-booking' ) );
			}
		}

		// Time zone and currency.
		$tz = (string) get_option( 'timezone_string' );
		if ( '' === $tz ) {
			$add( 'timezone', 'warning', __( 'Time zone', 'flexo-booking' ), __( 'The site uses a UTC offset instead of a city. Choose your city (e.g. Sofia) so arrival days and deadlines follow summer time.', 'flexo-booking' ), array( __( 'Choose the time zone', 'flexo-booking' ), admin_url( 'options-general.php' ) ) );
		} else {
			/* translators: 1: time zone, 2: currency */
			$add( 'timezone', 'ok', __( 'Time zone and currency', 'flexo-booking' ), sprintf( __( '%1$s · %2$s', 'flexo-booking' ), $tz, $settings['currency'] ) );
		}

		return apply_filters( 'flexo_booking_health_checks', $checks );
	}

	public static function handle_check_stripe() {
		check_admin_referer( 'flexo_booking_check_stripe' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flexo-booking' ) );
		}
		$stripe = Flexo_Booking_Payments::gateway( 'stripe' );
		$result = $stripe instanceof Flexo_Booking_Gateway_Stripe ? $stripe->check_keys() : new WP_Error( 'flexo_stripe', __( 'Card payments are switched off.', 'flexo-booking' ) );
		update_option(
			self::STRIPE_OPTION,
			array(
				'ok'      => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
				'mode'    => Flexo_Booking_Settings::get( 'stripe_mode' ),
				'time'    => time(),
			),
			false
		);
		wp_safe_redirect( self::url() . '#flexo-health-stripe' );
		exit;
	}

	/**
	 * Technical facts for support. No keys, bank details or guest data.
	 */
	public static function report() {
		global $wpdb, $wp_version;
		$s       = Flexo_Booking_Settings::all();
		$theme   = wp_get_theme();
		$count   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Install::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$secrets = Flexo_Booking_Payments::secrets();
		$lines   = array(
			'### Flexo Booking system report – ' . gmdate( 'Y-m-d H:i' ) . ' UTC',
			'Site: ' . home_url(),
			'Flexo Booking: ' . FLEXO_BOOKING_VERSION . ' (database ' . Flexo_Booking_Migrations::current_version() . ')',
			'WordPress: ' . $wp_version . ( is_multisite() ? ' (multisite)' : '' ),
			'PHP: ' . PHP_VERSION . ' · MySQL: ' . $wpdb->db_version(),
			'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'Elementor: ' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '–' ) . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' · Pro ' . ELEMENTOR_PRO_VERSION : '' ),
			'Language: ' . get_locale() . ' · Time zone: ' . wp_timezone_string(),
			'Multilingual: ' . ( function_exists( 'pll_languages_list' ) ? 'Polylang' : ( defined( 'ICL_SITEPRESS_VERSION' ) ? 'WPML' : '–' ) ),
			'SMTP plugin: ' . ( Flexo_Booking_Emails::smtp_detected() ? 'yes' : 'no' ),
			'WP-Cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (external cron)' : 'on' ) . ' · last run ' . ( get_option( self::CRON_OPTION ) ? gmdate( 'Y-m-d H:i', (int) get_option( self::CRON_OPTION ) ) . ' UTC' : 'never' ),
			'Features on: ' . implode( ', ', array_filter( Flexo_Booking_Features::keys(), array( 'Flexo_Booking_Features', 'is_enabled' ) ) ),
			'Booking mode: ' . Flexo_Booking_Features::booking_mode() . ' · Currency: ' . $s['currency'] . ' · Stays: ' . $s['min_nights'] . '–' . $s['max_nights'] . ' nights, up to ' . $s['max_advance_days'] . ' days ahead',
			'Payments: ' . ( Flexo_Booking_Payments::enabled() ? $s['payment_mode'] . ' · Stripe ' . $s['stripe_mode'] . ' · keys ' . ( '' !== $secrets[ 'stripe_' . $s['stripe_mode'] . '_secret_key' ] ? 'entered' : 'missing' ) : 'off' ),
			'Appearance: ' . Flexo_Booking_Appearance::mode(),
			'Rooms: ' . count( Flexo_Booking_Rooms::all() ) . ' · Bookings: ' . $count,
			'Booking page: ' . ( Flexo_Booking_Guest::booking_page_url() ? wp_make_link_relative( Flexo_Booking_Guest::booking_page_url() ) : 'missing' ),
			'',
			'Checks:',
		);
		foreach ( self::checks() as $check ) {
			$lines[] = '- [' . strtoupper( $check['status'] ) . '] ' . $check['title'] . ': ' . $check['text'];
		}
		return implode( "\n", $lines );
	}

	public static function render() {
		$checks = self::checks();
		$icons  = array(
			'ok'      => '✓',
			'warning' => '⚠',
			'error'   => '✕',
		);
		$words  = array(
			'ok'      => __( 'OK', 'flexo-booking' ),
			'warning' => __( 'Check', 'flexo-booking' ),
			'error'   => __( 'Problem', 'flexo-booking' ),
		);
		$problems = count(
			array_filter(
				$checks,
				static function ( $c ) {
					return 'ok' !== $c['status'];
				}
			)
		);
		?>
		<p class="flexo-tab-intro">
			<?php echo esc_html( $problems ? sprintf( /* translators: %d: number of items */ _n( '%d thing to check. Each item says how to fix it.', '%d things to check. Each item says how to fix it.', $problems, 'flexo-booking' ), $problems ) : __( 'Everything looks good.', 'flexo-booking' ) ); ?>
		</p>
		<ul class="flexo-health">
			<?php foreach ( $checks as $check ) : ?>
				<li class="flexo-health__item flexo-health__item--<?php echo esc_attr( $check['status'] ); ?>" id="flexo-health-<?php echo esc_attr( $check['id'] ); ?>">
					<span class="flexo-health__icon" aria-hidden="true"><?php echo esc_html( $icons[ $check['status'] ] ); ?></span>
					<div class="flexo-health__body">
						<strong><?php echo esc_html( $check['title'] ); ?></strong>
						<span class="screen-reader-text"><?php echo esc_html( $words[ $check['status'] ] ); ?>:</span>
						<span><?php echo esc_html( $check['text'] ); ?></span>
					</div>
					<?php if ( $check['action'] ) : ?>
						<a class="button" href="<?php echo esc_url( $check['action'][1] ); ?>"><?php echo esc_html( $check['action'][0] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="flexo-tools-card">
			<h2><?php esc_html_e( 'System report', 'flexo-booking' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Send this to FlexoHotels support when you ask for help. It contains no passwords, keys, bank details or guest data.', 'flexo-booking' ); ?></p>
			<label for="flexo-report" class="screen-reader-text"><?php esc_html_e( 'System report', 'flexo-booking' ); ?></label>
			<textarea id="flexo-report" class="large-text code" rows="12" readonly><?php echo esc_textarea( self::report() ); ?></textarea>
			<p><button type="button" class="button button-primary" data-flexo-copy="flexo-report"><?php esc_html_e( 'Copy system report', 'flexo-booking' ); ?></button></p>
		</div>
		<?php
	}
}
