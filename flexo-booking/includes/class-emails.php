<?php
/**
 * Guest and hotel emails.
 *
 * - Guest emails (feature "guest_emails"): request received, confirmed,
 *   cancelled, pre-arrival reminder and review request, each an editable
 *   template, sent in the language the guest booked in.
 * - Hotel notifications (always on, each can be switched off): new booking,
 *   cancellation, possible double booking from an external calendar.
 * - Every email is simple responsive HTML with a plain-text part, and is
 *   written to the email log.
 * - Scheduled emails run on WP-Cron (hourly), never twice for the same
 *   booking, never for cancelled bookings.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Emails {

	const HOURLY = 'flexo_booking_hourly';
	const DAILY  = 'flexo_booking_daily';

	/**
	 * @var string Last wp_mail error, captured from wp_mail_failed.
	 */
	private static $last_error = '';

	/**
	 * @var array|null Default texts per locale, for "is this still the default?" checks.
	 */
	private static $defaults = array();

	public static function init() {
		add_action( 'flexo_booking_created', array( __CLASS__, 'on_created' ) );
		add_action( 'flexo_booking_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::HOURLY, array( __CLASS__, 'send_scheduled' ) );
		add_action( self::DAILY, array( __CLASS__, 'cleanup_log' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );
		add_action( 'admin_notices', array( __CLASS__, 'smtp_notice' ) );
		add_action( 'admin_post_flexo_booking_test_email', array( __CLASS__, 'handle_test_email' ) );
		add_action( 'admin_post_flexo_booking_dismiss_smtp', array( __CLASS__, 'handle_dismiss_smtp' ) );
	}

	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( time() + 600, 'daily', self::DAILY );
		}
	}

	/**
	 * Guest email types. "reserved" ones are used by online payments (Day 5).
	 */
	public static function guest_types() {
		return array(
			'request'          => array( 'label' => __( 'Booking request received', 'flexo-booking' ) ),
			'confirmed'        => array( 'label' => __( 'Booking confirmed', 'flexo-booking' ) ),
			'cancelled'        => array( 'label' => __( 'Booking cancelled', 'flexo-booking' ) ),
			'pre_arrival'      => array(
				'label'     => __( 'Before arrival (reminder)', 'flexo-booking' ),
				'scheduled' => true,
			),
			'review'           => array(
				'label'     => __( 'After the stay (review request)', 'flexo-booking' ),
				'scheduled' => true,
			),
			'awaiting_deposit' => array(
				'label'    => __( 'Waiting for deposit', 'flexo-booking' ),
				'reserved' => true,
			),
			'payment_received' => array(
				'label'    => __( 'Payment received', 'flexo-booking' ),
				'reserved' => true,
			),
			'payment_failed'   => array(
				'label'    => __( 'Payment failed', 'flexo-booking' ),
				'reserved' => true,
			),
		);
	}

	/**
	 * Hotel notification types, each with its on/off setting.
	 */
	public static function hotel_types() {
		return array(
			'new_booking'   => array(
				'label'   => __( 'New booking or booking request', 'flexo-booking' ),
				'setting' => 'notify_new',
			),
			'cancelled'     => array(
				'label'   => __( 'Booking cancelled', 'flexo-booking' ),
				'setting' => 'notify_cancelled',
			),
			'ical_conflict' => array(
				'label'   => __( 'Possible double booking from an external calendar', 'flexo-booking' ),
				'setting' => 'notify_conflict',
			),
		);
	}

	public static function type_label( $type ) {
		if ( 'test' === $type ) {
			return __( 'Test email', 'flexo-booking' );
		}
		if ( 0 === strpos( $type, 'hotel_' ) ) {
			$types = self::hotel_types();
			$key   = substr( $type, 6 );
			/* translators: %s: email type */
			return isset( $types[ $key ] ) ? sprintf( __( 'To the hotel: %s', 'flexo-booking' ), $types[ $key ]['label'] ) : $type;
		}
		$types = self::guest_types();
		$key   = 0 === strpos( $type, 'guest_' ) ? substr( $type, 6 ) : $type;
		/* translators: %s: email type */
		return isset( $types[ $key ] ) ? sprintf( __( 'To the guest: %s', 'flexo-booking' ), $types[ $key ]['label'] ) : $type;
	}

	/* ---------------------------------------------------------------------
	 * Triggers
	 * ------------------------------------------------------------------- */

	public static function on_created( $booking ) {
		if ( 'website' !== $booking['source'] ) {
			return;
		}
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			self::send_guest( $booking, 'confirmed' === $booking['status'] ? 'confirmed' : 'request' );
		}
		self::send_admin( $booking );
	}

	public static function on_status_changed( $booking, $old_status, $new_status ) {
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) && in_array( $new_status, array( 'confirmed', 'cancelled' ), true ) ) {
			self::send_guest( $booking, $new_status );
		}
		if ( 'cancelled' === $new_status && 'blocked' !== $old_status ) {
			self::send_hotel_cancelled( $booking );
		}
	}

	/* ---------------------------------------------------------------------
	 * Guest emails
	 * ------------------------------------------------------------------- */

	/**
	 * @return bool Sent.
	 */
	public static function send_guest( $booking, $type ) {
		if ( ! is_email( $booking['guest_email'] ) || ! empty( $booking['anonymized_at'] ) ) {
			return false;
		}
		$locale = ! empty( $booking['locale'] ) ? $booking['locale'] : Flexo_Booking_I18n::site_locale();
		return Flexo_Booking_I18n::with_locale(
			$locale,
			static function () use ( $booking, $type, $locale ) {
				$vars    = self::placeholders( $booking );
				$subject = strtr( self::template( $type, 'subject', $locale ), $vars );
				$body    = strtr( self::template( $type, 'body', $locale ), $vars );
				$headers = array( 'Reply-To: ' . Flexo_Booking_Settings::notification_email() );

				$email = apply_filters( 'flexo_booking_guest_email', compact( 'subject', 'body', 'headers' ), $booking, $type );

				return self::send(
					$booking['guest_email'],
					$email['subject'],
					$email['body'],
					array(
						'headers'    => $email['headers'],
						'type'       => 'guest_' . $type,
						'booking_id' => (int) $booking['id'],
						'locale'     => $locale,
					)
				);
			}
		);
	}

	/**
	 * The subject or body of a guest email in a language. Texts the hotel
	 * never changed are the built-in texts in that language; changed texts
	 * are the hotel's own (translated with Polylang / WPML when available).
	 */
	public static function template( $type, $part, $locale ) {
		$key    = 'email_' . $type . '_' . $part;
		$stored = (string) Flexo_Booking_Settings::get( $key );
		if ( '' === trim( $stored ) || self::is_default_text( $key, $stored ) ) {
			return Flexo_Booking_I18n::with_locale(
				$locale,
				static function () use ( $key ) {
					$defaults = Flexo_Booking_Settings::defaults();
					return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
				}
			);
		}
		return Flexo_Booking_I18n::translate( $stored, $key, $locale );
	}

	/**
	 * Whether a stored text is still a built-in default (in English or in
	 * the site language), i.e. the hotel never changed it.
	 */
	public static function is_default_text( $key, $value ) {
		foreach ( array_unique( array( 'en_US', Flexo_Booking_I18n::site_locale() ) ) as $locale ) {
			if ( ! isset( self::$defaults[ $locale ] ) ) {
				self::$defaults[ $locale ] = Flexo_Booking_I18n::with_locale( $locale, array( 'Flexo_Booking_Settings', 'defaults' ) );
			}
			if ( isset( self::$defaults[ $locale ][ $key ] ) && self::normalize( self::$defaults[ $locale ][ $key ] ) === self::normalize( $value ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize( $text ) {
		return trim( str_replace( "\r\n", "\n", (string) $text ) );
	}

	/**
	 * Pre-arrival reminders and review requests (hourly WP-Cron). Sent
	 * between 08:00 and 21:00 hotel time, once per booking, only for
	 * confirmed bookings.
	 *
	 * @param string|null $now Y-m-d H:i:s (tests), default now.
	 * @return int Emails sent.
	 */
	public static function send_scheduled( $now = null ) {
		global $wpdb;
		if ( ! Flexo_Booking_Features::is_enabled( 'guest_emails' ) ) {
			return 0;
		}
		$now  = $now ? $now : current_time( 'mysql' );
		$hour = (int) substr( $now, 11, 2 );
		$from = (int) apply_filters( 'flexo_booking_email_hours_from', 8 );
		$to   = (int) apply_filters( 'flexo_booking_email_hours_to', 21 );
		if ( $hour < $from || $hour >= $to ) {
			return 0;
		}
		if ( ! Flexo_Booking_Lock::acquire( 'scheduled_emails', 0 ) ) {
			return 0;
		}

		$sent = 0;
		try {
			$settings = Flexo_Booking_Settings::all();
			$today    = substr( $now, 0, 10 );
			$table    = Flexo_Booking_Install::table();
			$jobs     = array();

			if ( ! empty( $settings['email_pre_arrival_enabled'] ) ) {
				$days = max( 1, (int) $settings['email_pre_arrival_days'] );
				// Arrival within the next N days (not today: too late for a reminder).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$jobs['pre_arrival'] = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'confirmed' AND anonymized_at IS NULL AND guest_email <> '' AND check_in > %s AND check_in <= %s AND emails_sent NOT LIKE %s", $today, Flexo_Booking_Dates::add_days( $today, $days ), '%pre_arrival%' ) );
			}
			if ( ! empty( $settings['email_review_enabled'] ) && '' !== $settings['review_link'] ) {
				$days = max( 0, (int) $settings['email_review_days'] );
				$due  = Flexo_Booking_Dates::add_days( $today, -$days );
				// Due today, or in the last 2 days if WP-Cron didn't run; older stays are skipped.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$jobs['review'] = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'confirmed' AND anonymized_at IS NULL AND guest_email <> '' AND check_out <= %s AND check_out >= %s AND emails_sent NOT LIKE %s", $due, Flexo_Booking_Dates::add_days( $due, -2 ), '%review%' ) );
			}

			foreach ( $jobs as $type => $ids ) {
				foreach ( $ids as $id ) {
					$booking = Flexo_Booking_Bookings::get( $id );
					// Checked again just before sending.
					if ( ! $booking || 'confirmed' !== $booking['status'] || self::was_sent( $booking, $type ) ) {
						continue;
					}
					// Marked first, so a failure is logged but never repeated every hour.
					self::mark_sent( $booking, $type );
					if ( self::send_guest( $booking, $type ) ) {
						++$sent;
					}
				}
			}
		} finally {
			Flexo_Booking_Lock::release( 'scheduled_emails' );
		}
		return $sent;
	}

	public static function was_sent( array $booking, $type ) {
		return in_array( $type, array_filter( explode( ',', (string) $booking['emails_sent'] ) ), true );
	}

	private static function mark_sent( array $booking, $type ) {
		global $wpdb;
		$list   = array_filter( explode( ',', (string) $booking['emails_sent'] ) );
		$list[] = $type;
		$wpdb->update( Flexo_Booking_Install::table(), array( 'emails_sent' => implode( ',', array_unique( $list ) ) ), array( 'id' => (int) $booking['id'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Hotel emails (always in the site language)
	 * ------------------------------------------------------------------- */

	private static function hotel_enabled( $type ) {
		$types = self::hotel_types();
		return ! isset( $types[ $type ] ) || ! empty( Flexo_Booking_Settings::get( $types[ $type ]['setting'] ) );
	}

	/**
	 * New booking or request, to the hotel.
	 */
	public static function send_admin( $booking ) {
		if ( ! self::hotel_enabled( 'new_booking' ) ) {
			return false;
		}
		return Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking ) {
				$vars = self::placeholders( $booking );
				/* translators: 1: booking reference, 2: room name */
				$subject = sprintf( 'pending' === $booking['status'] ? __( 'New booking request %1$s – %2$s', 'flexo-booking' ) : __( 'New booking %1$s – %2$s', 'flexo-booking' ), $booking['reference'], $booking['room_title'] );
				$body    = ( 'pending' === $booking['status'] ? __( 'A new booking request was made on your website. Please confirm or decline it.', 'flexo-booking' ) : __( 'A new booking was made on your website.', 'flexo-booking' ) ) . "\n\n" . $vars['{booking_details}'] . "\n\n";
				$body   .= self::guest_contact_text( $booking );
				$invoice = Flexo_Booking_Invoices::text( (int) $booking['id'] );
				if ( '' !== $invoice ) {
					$body .= "\n" . $invoice . "\n";
				}
				$body .= "\n" . __( 'Open the booking:', 'flexo-booking' ) . ' ' . self::booking_admin_url( $booking );

				$headers = array();
				if ( is_email( $booking['guest_email'] ) ) {
					$headers[] = 'Reply-To: ' . str_replace( array( "\r", "\n", '<', '>', ',' ), '', $booking['guest_name'] ) . ' <' . $booking['guest_email'] . '>';
				}

				$email = apply_filters( 'flexo_booking_admin_email', compact( 'subject', 'body', 'headers' ), $booking );

				return self::send(
					Flexo_Booking_Settings::notification_emails(),
					$email['subject'],
					$email['body'],
					array(
						'headers'    => $email['headers'],
						'type'       => 'hotel_new_booking',
						'booking_id' => (int) $booking['id'],
					)
				);
			}
		);
	}

	public static function send_hotel_cancelled( $booking ) {
		if ( ! self::hotel_enabled( 'cancelled' ) ) {
			return false;
		}
		return Flexo_Booking_I18n::with_locale(
			Flexo_Booking_I18n::site_locale(),
			static function () use ( $booking ) {
				$vars = self::placeholders( $booking );
				/* translators: 1: booking reference, 2: room name */
				$subject = sprintf( __( 'Booking cancelled %1$s – %2$s', 'flexo-booking' ), $booking['reference'], $booking['room_title'] );
				$body    = __( 'This booking has been cancelled. The dates are available again.', 'flexo-booking' ) . "\n\n" . $vars['{booking_details}'] . "\n\n" . self::guest_contact_text( $booking );
				$body   .= "\n" . __( 'Open the booking:', 'flexo-booking' ) . ' ' . self::booking_admin_url( $booking );
				return self::send(
					Flexo_Booking_Settings::notification_emails(),
					$subject,
					$body,
					array(
						'type'       => 'hotel_cancelled',
						'booking_id' => (int) $booking['id'],
					)
				);
			}
		);
	}

	/**
	 * A notification to the hotel from another part of the plugin (e.g.
	 * calendar sync conflicts). The caller builds the text in the site language.
	 */
	public static function send_hotel( $type, $subject, $body, $booking_id = 0 ) {
		if ( ! self::hotel_enabled( $type ) ) {
			return false;
		}
		return self::send(
			Flexo_Booking_Settings::notification_emails(),
			$subject,
			$body,
			array(
				'type'       => 'hotel_' . $type,
				'booking_id' => (int) $booking_id,
			)
		);
	}

	private static function guest_contact_text( $booking ) {
		$text = sprintf(
			"%s: %s\n%s: %s\n%s: %s\n",
			__( 'Guest', 'flexo-booking' ),
			$booking['guest_name'],
			__( 'Email', 'flexo-booking' ),
			$booking['guest_email'],
			__( 'Phone', 'flexo-booking' ),
			$booking['guest_phone']
		);
		if ( ! empty( $booking['locale'] ) ) {
			$text .= __( 'Language', 'flexo-booking' ) . ': ' . Flexo_Booking_I18n::language_name( $booking['locale'] ) . "\n";
		}
		if ( $booking['notes'] ) {
			$text .= __( 'Special requests', 'flexo-booking' ) . ': ' . $booking['notes'] . "\n";
		}
		return $text;
	}

	private static function booking_admin_url( $booking ) {
		return admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $booking['id'] );
	}

	/* ---------------------------------------------------------------------
	 * Sending, layout and log
	 * ------------------------------------------------------------------- */

	/**
	 * Sends one email as HTML with a plain-text part and logs the result.
	 *
	 * @param string|string[] $to
	 * @param array           $args { headers, type, booking_id, locale }
	 * @return bool
	 */
	public static function send( $to, $subject, $text, array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'headers'    => array(),
				'type'       => '',
				'booking_id' => 0,
				'locale'     => determine_locale(),
			)
		);
		$to      = array_values( array_filter( (array) $to, 'is_email' ) );
		$subject = wp_specialchars_decode( str_replace( array( "\r", "\n" ), ' ', (string) $subject ), ENT_QUOTES );
		if ( ! $to ) {
			self::log( $args['booking_id'], $args['type'], '', $subject, 'failed', __( 'No valid recipient address.', 'flexo-booking' ) );
			return false;
		}

		$html    = self::html( $subject, $text, $args['locale'] );
		$headers = array_merge( array( 'Content-Type: text/html; charset=UTF-8' ), (array) $args['headers'] );
		$message = apply_filters(
			'flexo_booking_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'html'    => $html,
				'text'    => $text,
				'headers' => $headers,
				'type'    => $args['type'],
			)
		);

		// Plain-text part for email programs that don't show HTML.
		$alt = static function ( $phpmailer ) use ( $message ) {
			$phpmailer->AltBody = $message['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		self::$last_error = '';
		$ok               = wp_mail( $message['to'], $message['subject'], $message['html'], $message['headers'] );
		remove_action( 'phpmailer_init', $alt );

		self::log( $args['booking_id'], $args['type'], implode( ', ', $message['to'] ), $message['subject'], $ok ? 'sent' : 'failed', $ok ? '' : ( self::$last_error ? self::$last_error : __( 'The email could not be sent (wp_mail returned an error).', 'flexo-booking' ) ) );
		return (bool) $ok;
	}

	public static function capture_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/**
	 * Simple responsive HTML: logo or hotel name on the hotel colour, the
	 * text, and a footer with the hotel's contact details.
	 */
	public static function html( $subject, $text, $locale = '' ) {
		$settings = Flexo_Booking_Settings::all();
		$color    = sanitize_hex_color( $settings['email_color'] ) ? $settings['email_color'] : '#1f6f5c';
		$name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$logo     = self::logo_url();
		$header   = $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" style="max-height:56px;max-width:220px;height:auto;border:0;display:block;">'
			: '<span style="font-size:20px;font-weight:bold;color:#ffffff;">' . esc_html( $name ) . '</span>';

		$paragraphs = array();
		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r\n", "\n", trim( (string) $text ) ) ) as $block ) {
			$lines = array();
			foreach ( explode( "\n", $block ) as $line ) {
				$indent  = strlen( $line ) - strlen( ltrim( $line, ' ' ) );
				$lines[] = str_repeat( '&nbsp;', $indent ) . make_clickable( esc_html( ltrim( $line, ' ' ) ) );
			}
			$paragraphs[] = '<p style="margin:0 0 16px;">' . implode( '<br>', $lines ) . '</p>';
		}

		$footer = array( esc_html( $name ) );
		if ( '' !== $settings['hotel_phone'] ) {
			$footer[] = esc_html( $settings['hotel_phone'] );
		}
		$footer[] = '<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $color ) . ';">' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a>';

		$lang = str_replace( '_', '-', $locale ? $locale : determine_locale() );
		$html = '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;"><tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">'
			. '<tr><td style="background:' . esc_attr( $color ) . ';padding:20px 24px;border-radius:8px 8px 0 0;">' . $header . '</td></tr>'
			. '<tr><td style="padding:24px;font-size:15px;line-height:1.6;">' . implode( '', $paragraphs ) . '</td></tr>'
			. '<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280;">' . implode( ' · ', $footer ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
		return apply_filters( 'flexo_booking_email_html', $html, $subject, $text );
	}

	/**
	 * Logo for emails: the one set under Settings → Emails, else the site logo.
	 */
	public static function logo_url() {
		$logo = (string) Flexo_Booking_Settings::get( 'email_logo' );
		if ( '' !== $logo ) {
			return preg_match( '#^https?://#i', $logo ) ? $logo : home_url( '/' . ltrim( $logo, '/' ) );
		}
		$id = (int) get_theme_mod( 'custom_logo' );
		return $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
	}

	public static function log( $booking_id, $type, $recipient, $subject, $status, $error = '' ) {
		global $wpdb;
		if ( ! Flexo_Booking_Schema::table_exists( 'email_log' ) ) {
			return;
		}
		$wpdb->insert(
			Flexo_Booking_Schema::table( 'email_log' ),
			array(
				'booking_id' => (int) $booking_id,
				'email_type' => substr( (string) $type, 0, 40 ),
				'recipient'  => substr( (string) $recipient, 0, 255 ),
				'subject'    => substr( (string) $subject, 0, 255 ),
				'status'     => 'sent' === $status ? 'sent' : 'failed',
				'error'      => '' === $error ? null : (string) $error,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @return array[] Newest first.
	 */
	public static function log_entries( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'booking_id' => 0,
				'recipient'  => '',
				'limit'      => 50,
			)
		);
		$table = Flexo_Booking_Schema::table( 'email_log' );
		$where = '1=1';
		$param = array();
		if ( $args['booking_id'] ) {
			$where  .= ' AND booking_id = %d';
			$param[] = (int) $args['booking_id'];
		}
		if ( '' !== $args['recipient'] ) {
			$where  .= ' AND recipient LIKE %s';
			$param[] = '%' . $wpdb->esc_like( $args['recipient'] ) . '%';
		}
		$param[] = (int) $args['limit'];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d", $param ), ARRAY_A );
	}

	/**
	 * Removes log entries older than the kept period (daily).
	 *
	 * @return int Entries removed.
	 */
	public static function cleanup_log() {
		global $wpdb;
		$days   = max( 1, (int) Flexo_Booking_Settings::get( 'email_log_days' ) );
		$before = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = Flexo_Booking_Schema::table( 'email_log' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $before ) );
	}

	/* ---------------------------------------------------------------------
	 * Deliverability
	 * ------------------------------------------------------------------- */

	/**
	 * Sends the "Booking confirmed" email with example details.
	 */
	public static function send_test( $to ) {
		$sample = array(
			'id'              => 0,
			'reference'       => 'FB-TEST01',
			'room_id'         => 0,
			'room_title'      => __( 'Double Room with Sea View', 'flexo-booking' ),
			'check_in'        => wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
			'check_out'       => wp_date( 'Y-m-d', strtotime( '+33 days' ) ),
			'nights'          => 3,
			'adults'          => 2,
			'children'        => 0,
			'children_ages'   => '',
			'guest_name'      => __( 'Maria Ivanova', 'flexo-booking' ),
			'guest_email'     => $to,
			'guest_phone'     => '+359 888 123 456',
			'notes'           => '',
			'total'           => 360.0,
			'currency'        => Flexo_Booking_Money::currency(),
			'status'          => 'confirmed',
			'source'          => 'website',
			'price_breakdown' => null,
			'promo_code'      => '',
			'locale'          => determine_locale(),
		);
		$vars    = self::placeholders( $sample );
		$subject = __( 'Test email', 'flexo-booking' ) . ': ' . strtr( self::template( 'confirmed', 'subject', determine_locale() ), $vars );
		$body    = __( 'This is a test email from your booking system. If you can read it, emails to guests and to you are working.', 'flexo-booking' ) . "\n\n---\n\n" . strtr( self::template( 'confirmed', 'body', determine_locale() ), $vars );
		return self::send( $to, $subject, $body, array( 'type' => 'test' ) );
	}

	public static function handle_test_email() {
		check_admin_referer( 'flexo_booking_test_email' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flexo-booking' ) );
		}
		$to   = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=emails' );
		if ( ! is_email( $to ) ) {
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( __( 'Please enter a valid email address.', 'flexo-booking' ) ), $back ) . '#flexo-test-email' );
			exit;
		}
		$ok = self::send_test( $to );
		wp_safe_redirect(
			$ok
				/* translators: %s: email address */
				? add_query_arg( 'flexo_info', rawurlencode( sprintf( __( 'Test email sent to %s. If it doesn\'t arrive within a few minutes, check the spam folder and the email log below.', 'flexo-booking' ), $to ) ), $back ) . '#flexo-email-log'
				/* translators: %s: email address */
				: add_query_arg( 'flexo_error', rawurlencode( sprintf( __( 'The test email to %s could not be sent. See the email log below and set up an SMTP plugin.', 'flexo-booking' ), $to ) ), $back ) . '#flexo-email-log'
		);
		exit;
	}

	/**
	 * Whether a plugin that sends email through a proper mail server is
	 * active (WP Mail SMTP, FluentSMTP, Post SMTP, …).
	 */
	public static function smtp_detected() {
		$known = defined( 'WPMS_PLUGIN_VER' ) || function_exists( 'wp_mail_smtp' ) || defined( 'FLUENTMAIL' ) || defined( 'POST_SMTP_VER' ) || class_exists( 'PostmanWpMail' ) || defined( 'EasyWPSMTP_PLUGIN_VERSION' ) || class_exists( 'EasyWPSMTP' ) || defined( 'SMTP_MAILER_VERSION' ) || defined( 'WP_SES_VERSION' ) || class_exists( 'SendGrid_Tools' ) || defined( 'MAILGUN_VERSION' );
		// Anything else that takes over wp_mail() or configures PHPMailer.
		$known = $known || has_filter( 'pre_wp_mail' ) || has_action( 'phpmailer_init' );
		return (bool) apply_filters( 'flexo_booking_smtp_detected', $known );
	}

	public static function smtp_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'flexo-booking' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::smtp_detected() || get_user_meta( get_current_user_id(), 'flexo_booking_smtp_notice', true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_dismiss_smtp' ), 'flexo_booking_dismiss_smtp' );
		echo '<div class="notice notice-warning flexo-smtp-notice"><p><strong>' . esc_html__( 'Booking emails may land in spam.', 'flexo-booking' ) . '</strong> ';
		esc_html_e( 'This website sends email without a mail server login (SMTP). Many email providers then treat booking confirmations as spam or drop them. Install an SMTP plugin such as "WP Mail SMTP" or "FluentSMTP" and connect it to the hotel\'s email account, then use "Send test email" under Bookings → Settings → Emails.', 'flexo-booking' );
		echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Hide this message', 'flexo-booking' ) . '</a></p></div>';
	}

	public static function handle_dismiss_smtp() {
		check_admin_referer( 'flexo_booking_dismiss_smtp' );
		update_user_meta( get_current_user_id(), 'flexo_booking_smtp_notice', 1 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Placeholders
	 * ------------------------------------------------------------------- */

	public static function placeholders( $booking ) {
		$check_in  = Flexo_Booking_I18n::format_date( $booking['check_in'] );
		$check_out = Flexo_Booking_I18n::format_date( $booking['check_out'] );
		$guests    = Flexo_Booking_Children::guests_text( $booking['adults'], $booking['children'], isset( $booking['children_ages'] ) ? $booking['children_ages'] : '' );
		$total     = Flexo_Booking_Money::format( $booking['total'], $booking['currency'] );
		$snapshot  = Flexo_Booking_Pricing::snapshot( $booking );
		$rows      = Flexo_Booking_Pricing::format_lines( $snapshot );
		$plan      = empty( $snapshot['rate_plan'] ) ? null : $snapshot['rate_plan'];
		$locale    = determine_locale();
		$plan_name = $plan ? Flexo_Booking_I18n::translate( $plan['name'], 'rate_plan_' . $plan['id'] . '_name', $locale ) : '';
		$policy    = $plan ? Flexo_Booking_I18n::translate( $plan['cancellation_policy'], 'rate_plan_' . $plan['id'] . '_cancellation', $locale ) : '';
		$prices    = array();
		foreach ( $rows as $row ) {
			// Several price lines (rate plan, discount, tax): list each one.
			if ( count( $rows ) > 1 ) {
				$prices[] = '  ' . $row['label'] . ': ' . $row['formatted'];
			}
			foreach ( $row['details'] as $detail ) {
				$prices[] = ( count( $rows ) > 1 ? '    ' : '  ' ) . $detail;
			}
		}
		if ( ! empty( $snapshot['due_at_property'] ) ) {
			$prices[] = '  ' . __( 'Payable at the property', 'flexo-booking' ) . ': ' . Flexo_Booking_Money::format( $snapshot['due_at_property'], $booking['currency'] );
		}

		$details = array(
			__( 'Reference', 'flexo-booking' ) . ': ' . $booking['reference'],
			__( 'Room', 'flexo-booking' ) . ': ' . $booking['room_title'],
			__( 'Check-in', 'flexo-booking' ) . ': ' . $check_in,
			__( 'Check-out', 'flexo-booking' ) . ': ' . $check_out,
			__( 'Nights', 'flexo-booking' ) . ': ' . $booking['nights'],
			__( 'Guests', 'flexo-booking' ) . ': ' . $guests,
		);
		if ( $plan ) {
			$details[] = __( 'Rate', 'flexo-booking' ) . ': ' . $plan_name;
		}
		if ( ! empty( $booking['promo_code'] ) ) {
			$details[] = __( 'Promo code', 'flexo-booking' ) . ': ' . $booking['promo_code'];
		}
		$details[] = __( 'Total', 'flexo-booking' ) . ': ' . $total;
		// Nights priced differently (seasons, weekends) and other price lines are listed under the total.
		$details = array_merge( $details, $prices );
		if ( $plan && '' !== $policy ) {
			$details[] = __( 'Cancellation', 'flexo-booking' ) . ': ' . $policy;
		}
		$details[] = __( 'Status', 'flexo-booking' ) . ': ' . Flexo_Booking_Bookings::status_label( $booking['status'] );
		$details   = implode( "\n", $details );

		$settings = Flexo_Booking_Settings::all();
		return apply_filters(
			'flexo_booking_email_placeholders',
			array(
				'{reference}'           => $booking['reference'],
				'{booking_ref}'         => $booking['reference'],
				'{guest_name}'          => $booking['guest_name'],
				'{guest_email}'         => $booking['guest_email'],
				'{guest_phone}'         => $booking['guest_phone'],
				'{room}'                => $booking['room_title'],
				'{check_in}'            => $check_in,
				'{check_out}'           => $check_out,
				'{nights}'              => $booking['nights'],
				'{guests}'              => $guests,
				'{total}'               => $total,
				'{price_breakdown}'     => Flexo_Booking_Pricing::summary_text( $snapshot ),
				'{rate_plan}'           => $plan_name,
				'{cancellation_policy}' => $policy,
				'{promo_code}'          => isset( $booking['promo_code'] ) ? $booking['promo_code'] : '',
				'{status}'              => Flexo_Booking_Bookings::status_label( $booking['status'] ),
				'{booking_details}'     => $details,
				'{check_in_time}'       => $settings['check_in_time'],
				'{check_out_time}'      => $settings['check_out_time'],
				'{site_name}'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{hotel_name}'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{hotel_phone}'         => $settings['hotel_phone'],
				'{hotel_email}'         => Flexo_Booking_Settings::notification_email(),
				'{review_link}'         => $settings['review_link'],
			),
			$booking
		);
	}

	/**
	 * Placeholders for the Emails settings screen.
	 */
	public static function placeholder_names() {
		return array( '{guest_name}', '{booking_ref}', '{room}', '{check_in}', '{check_out}', '{nights}', '{guests}', '{rate_plan}', '{total}', '{price_breakdown}', '{cancellation_policy}', '{promo_code}', '{status}', '{booking_details}', '{check_in_time}', '{check_out_time}', '{hotel_name}', '{hotel_phone}', '{hotel_email}', '{review_link}', '{guest_email}', '{guest_phone}' );
	}
}
