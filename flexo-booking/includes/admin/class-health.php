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

		// Room pages: another post type or child pages using the same address.
		$base    = Flexo_Booking_Room_Pages::base();
		$clashes = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( Flexo_Booking_Rooms::POST_TYPE !== $type->name && is_array( $type->rewrite ) && isset( $type->rewrite['slug'] ) && trim( $type->rewrite['slug'], '/' ) === $base ) {
				$clashes[] = $type->labels->name;
			}
		}
		$parent   = get_page_by_path( $base );
		$children = $parent ? get_pages( array( 'child_of' => $parent->ID, 'parent' => $parent->ID ) ) : array();
		$covered  = array();
		foreach ( $children as $child ) {
			if ( get_page_by_path( $child->post_name, OBJECT, Flexo_Booking_Rooms::POST_TYPE ) ) {
				$covered[] = $child->post_title;
			}
		}
		if ( $clashes ) {
			/* translators: 1: address part, 2: post type names */
			$add( 'room_pages', 'warning', __( 'Room pages', 'flexo-booking' ), sprintf( __( 'Another kind of content also uses /%1$s/ in its addresses (%2$s), so some room pages may show the wrong content. Switch it off (for example the JetEngine rooms post type) or change the room page address.', 'flexo-booking' ), $base, implode( ', ', $clashes ) ), array( __( 'Room pages settings', 'flexo-booking' ), admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=room_pages' ) ) );
		} elseif ( $covered ) {
			/* translators: 1: address part, 2: page names */
			$add( 'room_pages', 'warning', __( 'Room pages', 'flexo-booking' ), sprintf( __( 'These pages under /%1$s/ have the same address as a room and are no longer shown: %2$s. You can delete them.', 'flexo-booking' ), $base, implode( ', ', $covered ) ) );
		} else {
			/* translators: %s: address part */
			$add( 'room_pages', 'ok', __( 'Room pages', 'flexo-booking' ), sprintf( __( 'Rooms have their own pages at /%s/…', 'flexo-booking' ), $base ) );
		}

		// System pages (1.9.0): Booking, Thank You, Contact.
		foreach ( self::system_page_checks() as $check ) {
			$add( $check['id'], $check['status'], $check['title'], $check['text'], $check['action'] );
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
			'Booking page: ' . wp_make_link_relative( Flexo_Booking_Guest::guest_page_url() ) . ( '' === Flexo_Booking_Guest::booking_page_url() ? ' (built-in)' : ' (own page)' ),
			'Thank You page: ' . ( Flexo_Booking_Confirmation::separate() ? wp_make_link_relative( Flexo_Booking_System_Pages::url( 'thank_you' ) ) : 'in the booking form' ),
			'Header style: ' . Flexo_Booking_System_Pages::get_header_style(),
			'Page caches: ' . ( Flexo_Booking_Page_Cache::detected() ? implode( ', ', wp_list_pluck( Flexo_Booking_Page_Cache::detected(), 'name' ) ) : 'none found' ),
			'',
			'Checks:',
		);
		foreach ( self::checks() as $check ) {
			$lines[] = '- [' . strtoupper( $check['status'] ) . '] ' . $check['title'] . ': ' . $check['text'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * Booking, Thank You and Contact pages, address clashes, page caches
	 * and the header (1.9.0).
	 *
	 * @return array[] Same shape as checks().
	 */
	public static function system_page_checks() {
		$out   = array();
		$pages = admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=pages' );
		$add   = static function ( $id, $status, $title, $text, $action = null ) use ( &$out ) {
			$out[] = compact( 'id', 'status', 'title', 'text', 'action' );
		};
		$all   = Flexo_Booking_System_Pages::all();

		// Booking.
		$title = __( 'Booking page', 'flexo-booking' );
		$page  = $all['booking'];
		Flexo_Booking_Guest::forget_booking_page();
		if ( 'page' === $page['source'] && '' !== Flexo_Booking_System_Pages::own_page_url( 'booking' ) ) {
			$url = Flexo_Booking_System_Pages::own_page_url( 'booking' );
			$id  = (int) $page['page_id'];
			$has = $id ? self::page_has_form( $id ) : '' !== Flexo_Booking_Guest::detect_booking_page();
			if ( ! $has ) {
				$add( 'booking_page', 'warning', $title, __( 'Your booking page does not seem to contain the booking form (the Flexo Booking Form widget or the [flexo_booking] shortcode). Add it, or use the built-in booking page.', 'flexo-booking' ), array( __( 'Open the page', 'flexo-booking' ), $url ) );
			} else {
				/* translators: %s: page address */
				$add( 'booking_page', 'ok', $title, sprintf( __( 'Your own page: %s', 'flexo-booking' ), wp_make_link_relative( $url ) ), array( __( 'Open', 'flexo-booking' ), $url ) );
			}
		} elseif ( 'page' === $page['source'] ) {
			/* translators: %s: address */
			$add( 'booking_page', 'warning', $title, sprintf( __( 'The page chosen as booking page is missing or not published. Meanwhile guests get the built-in booking page at %s. Publish the page or choose another one.', 'flexo-booking' ), wp_make_link_relative( Flexo_Booking_System_Pages::builtin_url( 'booking' ) ) ), array( __( 'Settings → Pages', 'flexo-booking' ), $pages ) );
		} else {
			$hit = Flexo_Booking_System_Pages::collision( 'booking' );
			if ( $hit ) {
				$add( 'booking_page', 'warning', $title, Flexo_Booking_Pages_Admin::collision_text( 'booking', $hit, Flexo_Booking_System_Pages::slug( 'booking' ) ), array( __( 'Settings → Pages', 'flexo-booking' ), $pages ) );
			} else {
				$url  = Flexo_Booking_System_Pages::builtin_url( 'booking' );
				/* translators: %s: address */
				$text = sprintf( __( 'The built-in booking page works at %s, with your header and footer.', 'flexo-booking' ), wp_make_link_relative( $url ) );
				$found = Flexo_Booking_Guest::detect_booking_page();
				if ( '' !== $found ) {
					/* translators: %s: page address */
					$text .= ' ' . sprintf( __( 'Your page %s also has the booking form – to use it instead, choose it under Settings → Pages.', 'flexo-booking' ), wp_make_link_relative( $found ) );
				}
				$add( 'booking_page', 'ok', $title, $text, array( __( 'Open', 'flexo-booking' ), $url ) );
			}
		}

		// Thank You.
		$title = __( 'Thank You page', 'flexo-booking' );
		$page  = $all['thank_you'];
		if ( 'separate' !== $all['after_booking'] ) {
			$add( 'thank_you_page', 'ok', $title, __( 'Guests see their confirmation in the booking form.', 'flexo-booking' ) );
		} elseif ( 'page' === $page['source'] ) {
			$url = Flexo_Booking_System_Pages::own_page_url( 'thank_you' );
			if ( '' === $url ) {
				$add( 'thank_you_page', 'warning', $title, __( 'The page chosen as Thank You page is missing or not published, so guests see their confirmation in the booking form. Publish the page, choose another one or use the built-in Thank You page.', 'flexo-booking' ), array( __( 'Settings → Pages', 'flexo-booking' ), $pages ) );
			} else {
				/* translators: %s: page address */
				$add( 'thank_you_page', 'ok', $title, sprintf( __( 'Your own page: %s', 'flexo-booking' ), wp_make_link_relative( $url ) ), array( __( 'Open', 'flexo-booking' ), $url ) );
			}
		} elseif ( empty( $page['enabled'] ) ) {
			$add( 'thank_you_page', 'warning', $title, __( '"Separate Thank You page" is chosen, but the built-in Thank You page is switched off, so guests see their confirmation in the booking form. Switch the page on, or choose your own page.', 'flexo-booking' ), array( __( 'Settings → Pages', 'flexo-booking' ), $pages ) );
		} else {
			$hit = Flexo_Booking_System_Pages::collision( 'thank_you' );
			if ( $hit ) {
				$add( 'thank_you_page', 'warning', $title, Flexo_Booking_Pages_Admin::collision_text( 'thank_you', $hit, Flexo_Booking_System_Pages::slug( 'thank_you' ) ) . ' ' . __( 'Until then guests see their confirmation in the booking form.', 'flexo-booking' ), array( __( 'Settings → Pages', 'flexo-booking' ), $pages ) );
			} else {
				/* translators: %s: address */
				$add( 'thank_you_page', 'ok', $title, sprintf( __( 'Guests go to %s after booking. It shows their booking securely for 60 minutes and is never cached or indexed.', 'flexo-booking' ), wp_make_link_relative( Flexo_Booking_System_Pages::builtin_url( 'thank_you' ) ) ), array( __( 'Preview', 'flexo-booking' ), Flexo_Booking_Confirmation::preview_url( 'instant' ) ) );
			}
		}

		// Page caches.
		$caches = Flexo_Booking_Page_Cache::detected();
		$manual = array_values( array_filter( $caches, static function ( $cache ) {
			return empty( $cache['automatic'] );
		} ) );
		$rules  = implode( '  ', Flexo_Booking_Page_Cache::manual_rules() );
		if ( $manual ) {
			/* translators: 1: caching plugin names, 2: addresses to exclude */
			$add( 'page_cache', 'warning', __( 'Page caching', 'flexo-booking' ), sprintf( __( '%1$s can\'t be told automatically which pages must never be cached. In its settings, exclude these addresses from caching: %2$s', 'flexo-booking' ), implode( ', ', wp_list_pluck( $manual, 'name' ) ), $rules ) );
		} elseif ( $caches ) {
			/* translators: %s: caching plugin names */
			$add( 'page_cache', 'ok', __( 'Page caching', 'flexo-booking' ), sprintf( __( '%s: the Thank You page and booking links are kept out of the cache automatically.', 'flexo-booking' ), implode( ', ', wp_list_pluck( $caches, 'name' ) ) ) );
		} else {
			/* translators: %s: addresses to exclude */
			$add( 'page_cache', 'ok', __( 'Page caching', 'flexo-booking' ), sprintf( __( 'No caching plugin found. Private pages tell caches not to store them. If your hosting or Cloudflare caches pages, exclude: %s', 'flexo-booking' ), $rules ) );
		}

		// Header lying over the pages.
		$overlay = Flexo_Booking_System_Pages::overlay_detected();
		if ( $overlay && 'normal' === $all['header_style'] ) {
			/* translators: %s: page address */
			$add( 'overlay_header', 'ok', __( 'Header above Flexo pages', 'flexo-booking' ), sprintf( __( 'Your header lies over the page content (seen on %s); the page is moved below it automatically. For a look like your other pages, choose "Title band behind the header".', 'flexo-booking' ), $overlay['path'] ), array( __( 'Header style', 'flexo-booking' ), $pages . '#flexo-page-header' ) );
		}

		return apply_filters( 'flexo_booking_system_page_checks', $out );
	}

	/**
	 * Whether a page contains the full booking form (shortcode or widget).
	 */
	private static function page_has_form( $id ) {
		if ( preg_match( '/\[flexo_booking(?![^\]]*layout\s*=\s*["\']?search)[^\]]*\]/', (string) get_post_field( 'post_content', $id ) ) ) {
			return true;
		}
		$data = (string) get_post_meta( $id, '_elementor_data', true );
		return false !== strpos( $data, 'flexo-booking-form' );
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
