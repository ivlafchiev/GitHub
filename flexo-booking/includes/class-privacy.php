<?php
/**
 * Privacy / GDPR.
 *
 * Feature "privacy_consent": consent checkbox with stored evidence, automatic
 * anonymisation after a retention period, and a manual "Anonymise" action.
 *
 * Always on, because guests' rights don't depend on a feature switch: the
 * WordPress "Export Personal Data" / "Erase Personal Data" tools and the
 * suggested text for the privacy policy guide.
 *
 * Anonymising keeps dates, room, prices and status (statistics) and removes
 * the name, email, phone, special requests and invoice details.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Privacy {

	const PER_PAGE = 50;

	public static function init() {
		add_action( Flexo_Booking_Emails::DAILY, array( __CLASS__, 'run_retention' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
		add_action( 'admin_post_flexo_booking_anonymise', array( __CLASS__, 'handle_anonymise' ) );
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'privacy_consent' );
	}

	/* ---------------------------------------------------------------------
	 * Consent
	 * ------------------------------------------------------------------- */

	public static function consent_required() {
		return self::enabled() && (bool) Flexo_Booking_Settings::get( 'privacy_consent_required' );
	}

	/**
	 * The privacy policy page in the given language.
	 */
	public static function policy_url( $locale = '' ) {
		$url = Flexo_Booking_Settings::site_url_setting( 'privacy_page' );
		if ( '' === $url ) {
			$url = (string) get_privacy_policy_url();
		}
		return Flexo_Booking_I18n::page_url( $url, $locale );
	}

	/**
	 * The consent text in the current language, with {privacy_policy}
	 * replaced by $link (HTML) or by "Privacy Policy (URL)" for the record.
	 */
	public static function consent_text( $html = true, $locale = '' ) {
		$locale = $locale ? $locale : determine_locale();
		$stored = (string) Flexo_Booking_Settings::get( 'privacy_consent_text' );
		if ( '' === trim( $stored ) || Flexo_Booking_Emails::is_default_text( 'privacy_consent_text', $stored ) ) {
			$defaults = Flexo_Booking_Settings::defaults();
			$text     = $defaults['privacy_consent_text'];
		} else {
			$text = Flexo_Booking_I18n::translate( $stored, 'privacy_consent_text', $locale );
		}
		$url   = self::policy_url( $locale );
		$label = __( 'Privacy Policy', 'flexo-booking' );
		if ( ! $html ) {
			return str_replace( '{privacy_policy}', $url ? $label . ' (' . $url . ')' : $label, $text );
		}
		$link = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>' : esc_html( $label );
		return str_replace( esc_html( '{privacy_policy}' ), $link, esc_html( $text ) );
	}

	/**
	 * Stores what the guest agreed to and when.
	 */
	public static function record_consent( $booking_id, $locale ) {
		global $wpdb;
		$text = Flexo_Booking_I18n::with_locale(
			$locale,
			static function () use ( $locale ) {
				return self::consent_text( false, $locale );
			}
		);
		$wpdb->insert(
			Flexo_Booking_Schema::table( 'consents' ),
			array(
				'booking_id'   => (int) $booking_id,
				'consent_type' => 'privacy',
				'granted'      => 1,
				'text_hash'    => sha1( $text ),
				'consent_text' => $text,
				'locale'       => (string) $locale,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @return array|null The booking's consent record.
	 */
	public static function consent_for( $booking_id ) {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::table_exists( 'consents' ) ) {
			return null;
		}
		$table = Flexo_Booking_Schema::table( 'consents' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id DESC LIMIT 1", $booking_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/* ---------------------------------------------------------------------
	 * Anonymising
	 * ------------------------------------------------------------------- */

	/**
	 * Removes a booking's personal data; keeps dates, room, prices, status.
	 *
	 * @return bool False when the booking doesn't exist.
	 */
	public static function anonymise( $booking_id ) {
		global $wpdb;
		$booking = Flexo_Booking_Bookings::get( $booking_id );
		if ( ! $booking ) {
			return false;
		}
		$wpdb->update(
			Flexo_Booking_Install::table(),
			array(
				'guest_name'    => '',
				'guest_email'   => '',
				'guest_phone'   => '',
				'notes'         => '',
				'anonymized_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking_id )
		);
		Flexo_Booking_Invoices::delete( $booking_id );
		if ( Flexo_Booking_Schema::table_exists( 'email_log' ) && '' !== $booking['guest_email'] ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Flexo_Booking_Schema::table( 'email_log' ) . " SET recipient = '' WHERE booking_id = %d AND recipient = %s", $booking_id, $booking['guest_email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		do_action( 'flexo_booking_anonymised', (int) $booking_id );
		return true;
	}

	/**
	 * Anonymises bookings whose check-out is older than the retention
	 * period (daily WP-Cron). Off while the period is 0.
	 *
	 * @param string|null $today Y-m-d (tests).
	 * @return int Bookings anonymised.
	 */
	public static function run_retention( $today = null ) {
		global $wpdb;
		$months = (int) Flexo_Booking_Settings::get( 'retention_months' );
		if ( ! self::enabled() || $months < 1 ) {
			return 0;
		}
		$today  = $today ? $today : wp_date( 'Y-m-d' );
		$before = ( new DateTimeImmutable( $today, wp_timezone() ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d' );
		$table  = Flexo_Booking_Install::table();
		$count  = 0;
		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE check_out < %s AND anonymized_at IS NULL AND status <> 'blocked' LIMIT 200", $before ) );
			foreach ( $ids as $id ) {
				self::anonymise( $id );
				++$count;
			}
		} while ( count( $ids ) === 200 );
		return $count;
	}

	public static function handle_anonymise() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'flexo_booking_anonymise_' . $id );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) || ! self::enabled() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flexo-booking' ) );
		}
		self::anonymise( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . $id . '&flexo_msg=anonymised' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * WordPress personal data tools
	 * ------------------------------------------------------------------- */

	public static function register_exporter( $exporters ) {
		$exporters['flexo-booking'] = array(
			'exporter_friendly_name' => __( 'Hotel bookings', 'flexo-booking' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['flexo-booking'] = array(
			'eraser_friendly_name' => __( 'Hotel bookings', 'flexo-booking' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	private static function bookings_for_email( $email, $offset ) {
		global $wpdb;
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE guest_email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, self::PER_PAGE, $offset ) );
		return array_filter( array_map( array( 'Flexo_Booking_Bookings', 'get' ), $ids ) );
	}

	/**
	 * Everything stored about a guest email: bookings (with consent),
	 * invoice details and emails sent.
	 */
	public static function export( $email, $page = 1 ) {
		$email    = sanitize_email( $email );
		$page     = max( 1, (int) $page );
		$items    = array();
		$bookings = '' === $email ? array() : self::bookings_for_email( $email, ( $page - 1 ) * self::PER_PAGE );

		foreach ( $bookings as $b ) {
			$snapshot = Flexo_Booking_Pricing::snapshot( $b );
			$data     = array(
				array( 'name' => __( 'Reference', 'flexo-booking' ), 'value' => $b['reference'] ),
				array( 'name' => __( 'Room', 'flexo-booking' ), 'value' => $b['room_title'] ),
				array( 'name' => __( 'Check-in', 'flexo-booking' ), 'value' => Flexo_Booking_I18n::format_date( $b['check_in'] ) ),
				array( 'name' => __( 'Check-out', 'flexo-booking' ), 'value' => Flexo_Booking_I18n::format_date( $b['check_out'] ) ),
				array( 'name' => __( 'Guests', 'flexo-booking' ), 'value' => Flexo_Booking_Children::guests_text( $b['adults'], $b['children'], $b['children_ages'] ) ),
				array( 'name' => __( 'Name', 'flexo-booking' ), 'value' => $b['guest_name'] ),
				array( 'name' => __( 'Email', 'flexo-booking' ), 'value' => $b['guest_email'] ),
				array( 'name' => __( 'Phone', 'flexo-booking' ), 'value' => $b['guest_phone'] ),
				array( 'name' => __( 'Special requests', 'flexo-booking' ), 'value' => $b['notes'] ),
				array( 'name' => __( 'Price', 'flexo-booking' ), 'value' => 'blocked' === $b['status'] ? '' : Flexo_Booking_Pricing::summary_text( $snapshot ) ),
				array( 'name' => __( 'Status', 'flexo-booking' ), 'value' => Flexo_Booking_Bookings::status_label( $b['status'] ) ),
				array( 'name' => __( 'Booked on', 'flexo-booking' ), 'value' => $b['created_at'] ),
				array( 'name' => __( 'Language', 'flexo-booking' ), 'value' => Flexo_Booking_I18n::language_name( $b['locale'] ) ),
			);
			$consent = self::consent_for( $b['id'] );
			if ( $consent ) {
				$data[] = array(
					'name'  => __( 'Privacy consent', 'flexo-booking' ),
					'value' => $consent['created_at'] . ' – ' . $consent['consent_text'],
				);
			}
			$items[] = array(
				'group_id'    => 'flexo-bookings',
				'group_label' => __( 'Hotel bookings', 'flexo-booking' ),
				'item_id'     => 'flexo-booking-' . $b['id'],
				'data'        => array_values( array_filter( $data, static function ( $row ) { return '' !== (string) $row['value']; } ) ),
			);

			$invoice = Flexo_Booking_Invoices::get( $b['id'] );
			if ( $invoice ) {
				$rows = array( array( 'name' => __( 'Reference', 'flexo-booking' ), 'value' => $b['reference'] ) );
				foreach ( Flexo_Booking_Invoices::rows( $invoice ) as $label => $value ) {
					$rows[] = array( 'name' => $label, 'value' => $value );
				}
				$items[] = array(
					'group_id'    => 'flexo-booking-invoices',
					'group_label' => __( 'Invoice details for hotel bookings', 'flexo-booking' ),
					'item_id'     => 'flexo-invoice-' . $b['id'],
					'data'        => $rows,
				);
			}
		}

		if ( 1 === $page && '' !== $email ) {
			foreach ( Flexo_Booking_Emails::log_entries( array( 'recipient' => $email, 'limit' => 500 ) ) as $entry ) {
				$items[] = array(
					'group_id'    => 'flexo-booking-emails',
					'group_label' => __( 'Emails sent about hotel bookings', 'flexo-booking' ),
					'item_id'     => 'flexo-email-' . $entry['id'],
					'data'        => array(
						array( 'name' => __( 'Sent', 'flexo-booking' ), 'value' => $entry['created_at'] ),
						array( 'name' => __( 'Subject', 'flexo-booking' ), 'value' => $entry['subject'] ),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => count( $bookings ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymises every booking of a guest email and removes it from the email log.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		// Anonymised bookings lose the email, so the first page is always the next batch.
		$bookings = self::bookings_for_email( $email, 0 );
		foreach ( $bookings as $b ) {
			self::anonymise( $b['id'] );
		}
		$log = 0;
		if ( Flexo_Booking_Schema::table_exists( 'email_log' ) ) {
			$log = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'email_log' ) . ' WHERE recipient = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$messages = array();
		if ( $bookings ) {
			/* translators: %d: number of bookings */
			$messages[] = sprintf( _n( '%d hotel booking was anonymised. Its dates, room and price are kept for the hotel\'s records, without personal data.', '%d hotel bookings were anonymised. Their dates, rooms and prices are kept for the hotel\'s records, without personal data.', count( $bookings ), 'flexo-booking' ), count( $bookings ) );
		}
		return array(
			'items_removed'  => count( $bookings ) > 0 || $log > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $bookings ) < self::PER_PAGE,
		);
	}

	/**
	 * Suggested text for Settings → Privacy → Policy Guide.
	 */
	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<h2>' . esc_html__( 'Room bookings', 'flexo-booking' ) . '</h2>';
		$text .= '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text – adapt it to your hotel, your retention period and the services you really use.', 'flexo-booking' ) . '</p>';
		$text .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'flexo-booking' ) . ' </strong>' . esc_html__( 'When you book a room on this website, we collect your name, email address and phone number, the dates of your stay, the number of guests and the ages of children, and any special requests you send us. If you ask for an invoice, we also collect the details needed for it (name and address, or company name, company ID, VAT number, registered address and contact person). We use this information only to handle your booking, to contact you about your stay and, if you asked for one, to issue your invoice. The legal basis is the performance of the booking contract and our legal obligations.', 'flexo-booking' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When you book, we record that you accepted this privacy policy, with the date and time and the text you agreed to. We send you emails about your booking (confirmation, cancellation and, if the hotel uses them, a reminder before arrival and a request for a review after your stay).', 'flexo-booking' ) . '</p>';
		$text .= '<p>' . esc_html__( 'If you pay by card, you pay on the secure payment page of our payment provider Stripe (Stripe Payments Europe Ltd.). Your card details go directly to Stripe and are never stored on this website; we receive only a confirmation of the payment, its amount and a transaction number. Stripe processes your payment under its own privacy policy (stripe.com/privacy).', 'flexo-booking' ) . '</p>';
		$text .= '<p>' . esc_html__( 'We keep booking details for [X] months after your stay; after that the personal details are removed and only anonymous information (dates, room, price) is kept for statistics. Invoices we issue are kept as long as accounting law requires. You can ask us for a copy of your data or ask us to delete it at any time.', 'flexo-booking' ) . '</p>';
		wp_add_privacy_policy_content( 'Flexo Booking', wp_kses_post( $text ) );
	}
}
