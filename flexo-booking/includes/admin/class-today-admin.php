<?php
/**
 * "Today": the first screen for the hotel team – who arrives, leaves and
 * stays, what needs attention (with one-click actions), the next 7 days
 * and this month's occupancy.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Today_Admin {

	/**
	 * Statuses of bookings that hold a room (a card payment in progress too).
	 */
	const ACTIVE = array( 'confirmed', 'pending', 'awaiting_payment', 'pending_payment' );

	/**
	 * @var array|null Cached for the request (menu badge and page).
	 */
	private static $attention = null;

	public static function init() {
		add_action( 'admin_post_flexo_booking_handled', array( __CLASS__, 'handle_handled' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
	}

	public static function url() {
		return admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG );
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $where SQL with placeholders.
	 * @return array[]
	 */
	private static function bookings( $where, array $params ) {
		global $wpdb;
		$table    = Flexo_Booking_Install::table();
		$statuses = implode( ',', array_fill( 0, count( self::ACTIVE ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ({$statuses}) AND {$where} ORDER BY check_in ASC, id ASC", array_merge( self::ACTIVE, $params ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$booking = Flexo_Booking_Bookings::get( $row['id'] );
			// A card payment whose time ran out doesn't hold the room any more.
			if ( $booking && Flexo_Booking_Inventory::is_occupying( $booking ) ) {
				$out[] = $booking;
			}
		}
		return $out;
	}

	public static function arrivals( $date ) {
		return self::bookings( 'check_in = %s', array( $date ) );
	}

	public static function departures( $date ) {
		return self::bookings( 'check_out = %s', array( $date ) );
	}

	public static function staying( $date ) {
		return self::bookings( 'check_in < %s AND check_out > %s', array( $date, $date ) );
	}

	/**
	 * Arrivals, departures and nights sold between two dates (end excluded).
	 */
	public static function period( $from, $to ) {
		$stays    = self::bookings( 'check_in < %s AND check_out >= %s', array( $to, $from ) );
		$result   = array(
			'arrivals'   => 0,
			'departures' => 0,
			'nights'     => 0,
		);
		foreach ( $stays as $b ) {
			if ( 'blocked' === $b['status'] ) {
				continue;
			}
			$result['arrivals']   += $b['check_in'] >= $from && $b['check_in'] < $to ? 1 : 0;
			$result['departures'] += $b['check_out'] >= $from && $b['check_out'] < $to ? 1 : 0;
			$start                 = max( $b['check_in'], $from );
			$end                   = min( $b['check_out'], $to );
			$result['nights']     += max( 0, count( Flexo_Booking_Dates::nights( $start, $end ) ) );
		}
		return $result;
	}

	/**
	 * Share of room-nights sold this month (all rooms, all units).
	 *
	 * @return array { percent, sold, available }
	 */
	public static function occupancy( $month_start ) {
		$next  = ( new DateTimeImmutable( $month_start, wp_timezone() ) )->modify( 'first day of next month' )->format( 'Y-m-d' );
		$days  = count( Flexo_Booking_Dates::nights( $month_start, $next ) );
		$units = 0;
		foreach ( Flexo_Booking_Rooms::all() as $post ) {
			$units += max( 0, Flexo_Booking_Rooms::to_array( $post )['units'] );
		}
		$sold      = self::period( $month_start, $next )['nights'];
		$available = $units * $days;
		return array(
			'percent'   => $available ? (int) round( 100 * $sold / $available ) : 0,
			'sold'      => $sold,
			'available' => $available,
		);
	}

	/**
	 * Everything that needs someone to act, most urgent first.
	 *
	 * @return array[] { type, title, text, booking (array|null), actions: array[] { label, url, primary, confirm } }
	 */
	public static function attention() {
		if ( null !== self::$attention ) {
			return self::$attention;
		}
		global $wpdb;
		$items = array();
		$table = Flexo_Booking_Install::table();
		$today = wp_date( 'Y-m-d' );
		$open  = static function ( $b ) {
			return array(
				'label' => __( 'Open', 'flexo-booking' ),
				'url'   => Flexo_Booking_Admin::page_url( array( 'booking' => $b['id'] ) ),
			);
		};

		// Paid, but the room was taken meanwhile.
		foreach ( Flexo_Booking_Payments::open_conflicts() as $b ) {
			$items[] = array(
				'type'    => 'payment_conflict',
				'title'   => __( 'Paid, but the room is no longer free', 'flexo-booking' ),
				'text'    => __( 'Offer another room or dates, or refund the payment.', 'flexo-booking' ),
				'booking' => $b,
				'actions' => array( $open( $b ) ),
			);
		}

		// Possible double bookings from Booking.com, Airbnb …
		foreach ( Flexo_Booking_ICal::enabled() ? Flexo_Booking_ICal::open_conflicts() : array() as $conflict ) {
			$items[] = array(
				'type'    => 'ical_conflict',
				/* translators: %s: calendar name, e.g. Booking.com */
				'title'   => sprintf( __( 'Possible double booking from %s', 'flexo-booking' ), $conflict['calendar_name'] ),
				'text'    => get_the_title( $conflict['room_id'] ) . ' · ' . Flexo_Booking_Dates::display( $conflict['date_from'] ) . ' → ' . Flexo_Booking_Dates::display( $conflict['date_to'] ),
				'booking' => null,
				'actions' => array(
					array(
						'label' => __( 'Show in calendar', 'flexo-booking' ),
						'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG . '&month=' . substr( $conflict['date_from'], 0, 7 ) ),
					),
					array(
						'label'   => __( 'Mark as reviewed', 'flexo-booking' ),
						'url'     => Flexo_Booking_Sync_Admin::review_url( $conflict['id'] ),
						'primary' => true,
					),
				),
			);
		}

		// Booking requests waiting for an answer.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC LIMIT 50" ) as $id ) {
			$b = Flexo_Booking_Bookings::get( $id );
			if ( ! $b ) {
				continue;
			}
			$items[] = array(
				'type'    => 'request',
				'title'   => __( 'Booking request waiting for your answer', 'flexo-booking' ),
				'text'    => self::summary( $b ),
				'booking' => $b,
				'actions' => array(
					array(
						'label'   => Flexo_Booking_Admin::confirm_label( $b ),
						'url'     => Flexo_Booking_Admin::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ),
						'primary' => true,
					),
					array(
						'label'   => __( 'Decline', 'flexo-booking' ),
						'url'     => Flexo_Booking_Admin::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ),
						'confirm' => Flexo_Booking_Admin::cancel_message( $b ),
					),
					$open( $b ),
				),
			);
		}

		// Waiting for a bank transfer.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'awaiting_payment' ORDER BY payment_due_at ASC LIMIT 50" ) as $id ) {
			$b = Flexo_Booking_Bookings::get( $id );
			if ( ! $b ) {
				continue;
			}
			$due     = $b['payment_due_at'] ? substr( $b['payment_due_at'], 0, 10 ) : '';
			$balance = Flexo_Booking_Payments::balance( $b );
			$items[] = array(
				'type'    => $due && $due < $today ? 'payment_overdue' : 'payment_waiting',
				'title'   => $due && $due < $today ? __( 'Bank transfer not received in time', 'flexo-booking' ) : __( 'Waiting for bank transfer', 'flexo-booking' ),
				'text'    => self::summary( $b ) . ( $due ? ' · ' . sprintf( /* translators: 1: amount, 2: date */ __( '%1$s due by %2$s', 'flexo-booking' ), Flexo_Booking_Money::format( $balance['due_now'], $b['currency'] ), Flexo_Booking_Dates::display( $due ) ) : '' ),
				'booking' => $b,
				'actions' => array(
					array(
						'label'   => __( 'Payment received', 'flexo-booking' ),
						'url'     => Flexo_Booking_Admin::page_url( array( 'booking' => $b['id'] ) ) . '#flexo-payments',
						'primary' => true,
					),
					$open( $b ),
				),
			);
		}

		// Change or cancellation requests from guests.
		foreach ( Flexo_Booking_Log::recent( 'guest_request', '', 50 ) as $entry ) {
			if ( empty( $entry['details']['status'] ) || 'open' !== $entry['details']['status'] ) {
				continue;
			}
			$b = Flexo_Booking_Bookings::get( $entry['booking_id'] );
			if ( ! $b ) {
				continue;
			}
			$types   = Flexo_Booking_Guest::request_types();
			$type    = isset( $types[ $entry['details']['type'] ] ) ? $types[ $entry['details']['type'] ] : '';
			$items[] = array(
				'type'    => 'guest_request',
				/* translators: %s: e.g. "Cancel my booking" */
				'title'   => sprintf( __( 'Guest asks: "%s"', 'flexo-booking' ), $type ),
				'text'    => self::summary( $b ) . ( '' !== (string) $entry['details']['message'] ? ' · "' . $entry['details']['message'] . '"' : '' ),
				'booking' => $b,
				'actions' => array(
					$open( $b ),
					array(
						'label' => __( 'Mark as answered', 'flexo-booking' ),
						'url'   => self::handled_url( $entry['id'] ),
					),
				),
			);
		}

		// Enquiries from the booking form.
		foreach ( Flexo_Booking_Log::recent( 'enquiry', '', 50 ) as $entry ) {
			$d = $entry['details'];
			if ( empty( $d['status'] ) || 'open' !== $d['status'] ) {
				continue;
			}
			$stay    = ! empty( $d['check_in'] ) && ! empty( $d['check_out'] ) ? Flexo_Booking_Dates::display( $d['check_in'] ) . ' → ' . Flexo_Booking_Dates::display( $d['check_out'] ) : '';
			$items[] = array(
				'type'    => 'enquiry',
				/* translators: %s: guest name */
				'title'   => sprintf( __( 'Enquiry from %s', 'flexo-booking' ), $d['name'] ),
				'text'    => trim( $stay . ( '' !== (string) $d['message'] ? ' · "' . $d['message'] . '"' : '' ), ' ·' ),
				'booking' => null,
				'actions' => array(
					array(
						'label'   => __( 'Reply by email', 'flexo-booking' ),
						'url'     => 'mailto:' . $d['email'],
						'primary' => true,
					),
					array(
						'label' => __( 'Mark as answered', 'flexo-booking' ),
						'url'   => self::handled_url( $entry['id'] ),
					),
				),
			);
		}

		// Calendar connections that keep failing.
		foreach ( Flexo_Booking_ICal::enabled() ? Flexo_Booking_ICal::failing_calendars() : array() as $calendar ) {
			$items[] = array(
				'type'    => 'sync_error',
				/* translators: %s: calendar name */
				'title'   => sprintf( __( 'Calendar "%s" is not updating', 'flexo-booking' ), $calendar['name'] ),
				'text'    => (string) $calendar['last_error'],
				'booking' => null,
				'actions' => array(
					array(
						'label' => __( 'Open Calendar sync', 'flexo-booking' ),
						'url'   => Flexo_Booking_Sync_Admin::page_url(),
					),
				),
			);
		}

		// Emails that could not be sent in the last 7 days.
		if ( Flexo_Booking_Schema::table_exists( 'email_log' ) ) {
			$log = Flexo_Booking_Schema::table( 'email_log' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE status = 'failed' AND created_at >= %s", wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
			if ( $failed ) {
				$items[] = array(
					'type'    => 'email_failed',
					/* translators: %d: number of emails */
					'title'   => sprintf( _n( '%d email could not be sent this week', '%d emails could not be sent this week', $failed, 'flexo-booking' ), $failed ),
					'text'    => __( 'Guests may be missing their confirmation. Check the email log and the email sending set-up.', 'flexo-booking' ),
					'booking' => null,
					'actions' => current_user_can( 'manage_options' ) ? array(
						array(
							'label' => __( 'Open the email log', 'flexo-booking' ),
							'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-emails#flexo-email-log' ),
						),
					) : array(),
				);
			}
		}

		self::$attention = apply_filters( 'flexo_booking_attention', $items );
		return self::$attention;
	}

	/**
	 * For the menu badge.
	 */
	public static function attention_count() {
		return count( self::attention() );
	}

	private static function summary( array $b ) {
		return $b['reference'] . ' · ' . ( $b['guest_name'] ? $b['guest_name'] . ' · ' : '' ) . $b['room_title'] . ' · ' . Flexo_Booking_Dates::display( $b['check_in'] ) . ' → ' . Flexo_Booking_Dates::display( $b['check_out'] );
	}

	public static function handled_url( $log_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_handled&id=' . (int) $log_id ), 'flexo_booking_handled_' . (int) $log_id );
	}

	/**
	 * "Mark as answered" for a guest request or an enquiry.
	 */
	public static function handle_handled() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'flexo_booking_handled_' . $id );
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}
		$entry = Flexo_Booking_Log::get( $id );
		if ( $entry && in_array( $entry['action'], array( 'guest_request', 'enquiry' ), true ) ) {
			$details            = $entry['details'];
			$details['status']  = 'handled';
			$details['handled'] = array(
				'user' => get_current_user_id(),
				'at'   => current_time( 'mysql' ),
			);
			Flexo_Booking_Log::update_details( $id, $details );
			if ( $entry['booking_id'] ) {
				Flexo_Booking_Log::add( $entry['booking_id'], 'request_answered', array( 'request' => $id ) );
			}
		}
		$back = wp_get_referer() ? wp_get_referer() : self::url();
		wp_safe_redirect( add_query_arg( 'flexo_msg', 'handled', remove_query_arg( array( 'flexo_msg', 'flexo_error' ), $back ) ) );
		exit;
	}

	/**
	 * "Preview booking form" in the admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_admin() || ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		$url = Flexo_Booking_Guest::booking_page_url();
		if ( '' === $url ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'flexo-booking-preview',
				'title' => '<span class="ab-icon dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Booking form', 'flexo-booking' ) . '</span>',
				'href'  => $url,
				'meta'  => array(
					'target' => '_blank',
					'title'  => __( 'Open your booking form in a new tab', 'flexo-booking' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	private static function guest_rows( array $bookings, $empty, $kind ) {
		if ( ! $bookings ) {
			echo '<p class="flexo-today__empty">' . esc_html( $empty ) . '</p>';
			return;
		}
		echo '<ul class="flexo-today__list">';
		foreach ( $bookings as $b ) {
			$balance = Flexo_Booking_Payments::balance( $b );
			$name    = $b['guest_name'] ? $b['guest_name'] : $b['reference'];
			echo '<li class="flexo-today__item">';
			echo '<span class="flexo-avatar" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
			echo '<div class="flexo-today__who"><a href="' . esc_url( Flexo_Booking_Admin::page_url( array( 'booking' => $b['id'] ) ) ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
			echo '<span class="flexo-muted">' . esc_html( $b['room_title'] . ' · ' . sprintf( /* translators: %d: nights */ _n( '%d night', '%d nights', $b['nights'], 'flexo-booking' ), $b['nights'] ) . ' · ' . Flexo_Booking_Children::guests_text( $b['adults'], $b['children'], $b['children_ages'] ) ) . '</span>';
			if ( 'staying' === $kind ) {
				/* translators: %s: date */
				echo '<span class="flexo-muted">' . esc_html( sprintf( __( 'Leaves %s', 'flexo-booking' ), Flexo_Booking_Dates::display( $b['check_out'] ) ) ) . '</span>';
			}
			echo '</div><div class="flexo-today__meta">';
			if ( 'pending' === $b['status'] ) {
				echo '<span class="flexo-status flexo-status--pending">' . esc_html( Flexo_Booking_Bookings::status_label( 'pending' ) ) . '</span> ';
			}
			if ( '' !== $b['payment_method'] && $balance['outstanding'] > 0 ) {
				/* translators: %s: amount */
				echo '<span class="flexo-badge flexo-badge--due">' . esc_html( sprintf( __( 'To pay: %s', 'flexo-booking' ), Flexo_Booking_Money::format( $balance['outstanding'], $b['currency'] ) ) ) . '</span> ';
			} elseif ( $balance['net'] > 0 && $balance['outstanding'] <= 0 ) {
				echo '<span class="flexo-badge flexo-badge--paid">✓ ' . esc_html__( 'Paid', 'flexo-booking' ) . '</span> ';
			}
			if ( $b['guest_phone'] ) {
				echo '<a class="button button-small" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $b['guest_phone'] ) ) . '">' . Flexo_Booking_Admin_UI::icon( 'phone' ) . esc_html__( 'Call', 'flexo-booking' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG.
			}
			echo '</div></li>';
		}
		echo '</ul>';
	}

	/**
	 * Up to two initials for the guest's avatar.
	 */
	private static function initials( $name ) {
		$parts = preg_split( '/\s+/u', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( array_filter( $parts ), 0, 2 ) as $part ) {
			$out .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $part, 0, 1 ) ) : strtoupper( substr( $part, 0, 1 ) );
		}
		return '' !== $out ? $out : '?';
	}

	/**
	 * Icon and tone of a "Needs your attention" item.
	 */
	private static function attention_look( $type ) {
		$looks = array(
			'payment_conflict' => array( 'alert', 'danger' ),
			'ical_conflict'    => array( 'alert', 'danger' ),
			'payment_overdue'  => array( 'alert', 'danger' ),
			'email_failed'     => array( 'mail', 'danger' ),
			'sync_error'       => array( 'sync', 'danger' ),
			'payment_waiting'  => array( 'bank', 'warning' ),
			'request'          => array( 'clock', 'info' ),
			'guest_request'    => array( 'note', 'info' ),
			'enquiry'          => array( 'mail', 'info' ),
		);
		return isset( $looks[ $type ] ) ? $looks[ $type ] : array( 'info', 'info' );
	}

	/**
	 * A guest list card (arriving, leaving, staying).
	 */
	private static function guest_card( $id, $icon, $title, array $bookings, $empty, $kind ) {
		?>
		<section class="flexo-card flexo-today__card" aria-labelledby="<?php echo esc_attr( $id ); ?>">
			<div class="flexo-card__head">
				<h2 id="<?php echo esc_attr( $id ); ?>"><?php echo Flexo_Booking_Admin_UI::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><?php echo esc_html( $title ); ?></h2>
				<span class="flexo-chip"><?php echo esc_html( number_format_i18n( count( $bookings ) ) ); ?></span>
			</div>
			<?php self::guest_rows( $bookings, $empty, $kind ); ?>
		</section>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		// Holds whose time is up are released before counting.
		Flexo_Booking_Payments::release_expired_holds();
		$today      = wp_date( 'Y-m-d' );
		$tomorrow   = Flexo_Booking_Dates::add_days( $today, 1 );
		$week       = self::period( $today, Flexo_Booking_Dates::add_days( $today, 7 ) );
		$month      = self::occupancy( wp_date( 'Y-m-01' ) );
		$items      = self::attention();
		$page       = Flexo_Booking_Guest::booking_page_url();
		$arrivals   = self::arrivals( $today );
		$departures = self::departures( $today );
		$staying    = self::staying( $today );
		$hour       = (int) wp_date( 'G' );
		$user       = wp_get_current_user();
		$first      = $user->first_name ? $user->first_name : $user->display_name;
		if ( $hour < 12 ) {
			/* translators: %s: first name */
			$greeting = __( 'Good morning, %s', 'flexo-booking' );
		} elseif ( $hour < 18 ) {
			/* translators: %s: first name */
			$greeting = __( 'Good afternoon, %s', 'flexo-booking' );
		} else {
			/* translators: %s: first name */
			$greeting = __( 'Good evening, %s', 'flexo-booking' );
		}
		$actions = array();
		if ( $page ) {
			$actions[] = array(
				'label' => __( 'Preview booking form', 'flexo-booking' ),
				'url'   => $page,
				'icon'  => 'external',
				'attrs' => array(
					'target' => '_blank',
					'rel'    => 'noopener',
				),
			);
		}
		$actions[] = array(
			'label' => __( 'Calendar', 'flexo-booking' ),
			'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG ),
			'icon'  => 'calendar',
		);
		$actions[] = array(
			'label' => __( 'All bookings', 'flexo-booking' ),
			'url'   => Flexo_Booking_Admin::list_url(),
			'icon'  => 'list',
		);
		?>
		<div class="wrap flexo-admin flexo-today">
			<?php
			Flexo_Booking_Admin_UI::page_head(
				array(
					'title'   => __( 'Today', 'flexo-booking' ),
					'icon'    => 'sun',
					'intro'   => esc_html( sprintf( $greeting, $first ) ) . ' · <span class="flexo-today__date">' . esc_html( wp_date( 'l, ' . get_option( 'date_format' ) ) ) . '</span>',
					'actions' => $actions,
				)
			);
			?>
			<?php Flexo_Booking_Admin::notices(); ?>
			<?php Flexo_Booking_Wizard::resume_notice(); ?>

			<?php if ( ! Flexo_Booking_Rooms::all() ) : ?>
				<div class="flexo-card flexo-empty">
					<div class="flexo-blank">
						<span class="flexo-blank__icon"><?php echo Flexo_Booking_Admin_UI::icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
						<h2><?php esc_html_e( 'Welcome! Let\'s get your booking form ready', 'flexo-booking' ); ?></h2>
						<p><?php esc_html_e( 'Add your rooms and prices, and guests can book on your website.', 'flexo-booking' ); ?></p>
						<div class="flexo-blank__actions">
							<?php if ( current_user_can( 'manage_options' ) ) : ?>
								<a class="button button-primary button-hero" href="<?php echo esc_url( Flexo_Booking_Wizard::url() ); ?>"><?php esc_html_e( 'Start the setup wizard', 'flexo-booking' ); ?></a>
							<?php endif; ?>
							<?php if ( current_user_can( Flexo_Booking_Admin::cap( 'prices' ) ) ) : ?>
								<a class="button button-hero" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add a room', 'flexo-booking' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<section class="flexo-kpis" aria-labelledby="flexo-stats-title">
				<h2 id="flexo-stats-title" class="screen-reader-text"><?php esc_html_e( 'At a glance', 'flexo-booking' ); ?></h2>
				<a class="flexo-kpi flexo-kpi--arrive" href="#flexo-arrivals">
					<span class="flexo-kpi__icon"><?php echo Flexo_Booking_Admin_UI::icon( 'arrive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<span class="flexo-kpi__body"><span class="flexo-kpi__value"><?php echo esc_html( number_format_i18n( count( $arrivals ) ) ); ?></span><span class="flexo-kpi__label"><?php esc_html_e( 'Arriving today', 'flexo-booking' ); ?></span></span>
				</a>
				<a class="flexo-kpi flexo-kpi--depart" href="#flexo-departures">
					<span class="flexo-kpi__icon"><?php echo Flexo_Booking_Admin_UI::icon( 'depart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<span class="flexo-kpi__body"><span class="flexo-kpi__value"><?php echo esc_html( number_format_i18n( count( $departures ) ) ); ?></span><span class="flexo-kpi__label"><?php esc_html_e( 'Leaving today', 'flexo-booking' ); ?></span></span>
				</a>
				<a class="flexo-kpi flexo-kpi--stay" href="#flexo-staying">
					<span class="flexo-kpi__icon"><?php echo Flexo_Booking_Admin_UI::icon( 'moon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<span class="flexo-kpi__body"><span class="flexo-kpi__value"><?php echo esc_html( number_format_i18n( count( $staying ) ) ); ?></span><span class="flexo-kpi__label"><?php esc_html_e( 'Staying tonight', 'flexo-booking' ); ?></span></span>
				</a>
				<div class="flexo-kpi flexo-kpi--occupancy">
					<span class="flexo-kpi__icon"><?php echo Flexo_Booking_Admin_UI::icon( 'chart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<span class="flexo-kpi__body">
						<span class="flexo-kpi__value"><?php echo esc_html( $month['percent'] ); ?>%</span>
						<span class="flexo-kpi__label"><?php esc_html_e( 'Occupancy this month', 'flexo-booking' ); ?></span>
						<span class="flexo-kpi__sub">
							<?php
							/* translators: 1: nights sold, 2: nights available */
							echo esc_html( sprintf( __( '%1$s of %2$s room-nights', 'flexo-booking' ), number_format_i18n( $month['sold'] ), number_format_i18n( $month['available'] ) ) );
							?>
						</span>
						<span class="flexo-meter" aria-hidden="true"><span style="width:<?php echo esc_attr( min( 100, $month['percent'] ) ); ?>%"></span></span>
					</span>
				</div>
			</section>

			<div class="flexo-today__layout">
				<div class="flexo-today__main">
					<section id="flexo-attention" class="flexo-card flexo-today__attention" aria-labelledby="flexo-attention-title">
						<div class="flexo-card__head">
							<h2 id="flexo-attention-title">
								<?php echo Flexo_Booking_Admin_UI::icon( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
								<?php esc_html_e( 'Needs your attention', 'flexo-booking' ); ?>
								<?php if ( $items ) : ?>
									<span class="flexo-count"><?php echo esc_html( number_format_i18n( count( $items ) ) ); ?></span>
								<?php endif; ?>
							</h2>
						</div>
						<?php if ( ! $items ) : ?>
							<div class="flexo-today__allclear">
								<span class="flexo-today__allclear-icon"><?php echo Flexo_Booking_Admin_UI::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
								<span><?php esc_html_e( 'All clear – nothing is waiting for you.', 'flexo-booking' ); ?></span>
							</div>
						<?php else : ?>
							<ul class="flexo-attention">
								<?php foreach ( $items as $item ) : ?>
									<?php $look = self::attention_look( $item['type'] ); ?>
									<li class="flexo-attention__item flexo-attention__item--<?php echo esc_attr( $item['type'] ); ?> is-<?php echo esc_attr( $look[1] ); ?>">
										<span class="flexo-attention__icon"><?php echo Flexo_Booking_Admin_UI::icon( $look[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
										<div class="flexo-attention__text">
											<strong><?php echo esc_html( $item['title'] ); ?></strong>
											<?php if ( '' !== $item['text'] ) : ?>
												<span><?php echo esc_html( $item['text'] ); ?></span>
											<?php endif; ?>
										</div>
										<div class="flexo-attention__actions">
											<?php foreach ( $item['actions'] as $action ) : ?>
												<a class="button button-small<?php echo empty( $action['primary'] ) ? '' : ' button-primary'; ?>" href="<?php echo esc_url( $action['url'] ); ?>"<?php echo empty( $action['confirm'] ) ? '' : ' data-flexo-confirm="' . esc_attr( $action['confirm'] ) . '"'; ?>><?php echo esc_html( $action['label'] ); ?></a>
											<?php endforeach; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</section>

					<div class="flexo-today__grid">
						<?php
						self::guest_card( 'flexo-arrivals', 'arrive', __( 'Arriving today', 'flexo-booking' ), $arrivals, __( 'No arrivals today.', 'flexo-booking' ), 'arrival' );
						self::guest_card( 'flexo-departures', 'depart', __( 'Leaving today', 'flexo-booking' ), $departures, __( 'No departures today.', 'flexo-booking' ), 'departure' );
						self::guest_card( 'flexo-staying', 'moon', __( 'Staying tonight', 'flexo-booking' ), $staying, __( 'No guests staying over.', 'flexo-booking' ), 'staying' );
						self::guest_card( 'flexo-tomorrow', 'calendar', __( 'Arriving tomorrow', 'flexo-booking' ), self::arrivals( $tomorrow ), __( 'No arrivals tomorrow.', 'flexo-booking' ), 'arrival' );
						?>
					</div>
				</div>

				<aside class="flexo-today__side">
					<section class="flexo-card flexo-today__week" aria-labelledby="flexo-week-title">
						<div class="flexo-card__head">
							<h2 id="flexo-week-title"><?php echo Flexo_Booking_Admin_UI::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><?php esc_html_e( 'Next 7 days', 'flexo-booking' ); ?></h2>
						</div>
						<dl class="flexo-today__stats">
							<div class="flexo-stat"><dt class="flexo-stat__label"><?php esc_html_e( 'arrivals in the next 7 days', 'flexo-booking' ); ?></dt><dd class="flexo-stat__value"><?php echo esc_html( number_format_i18n( $week['arrivals'] ) ); ?></dd></div>
							<div class="flexo-stat"><dt class="flexo-stat__label"><?php esc_html_e( 'departures in the next 7 days', 'flexo-booking' ); ?></dt><dd class="flexo-stat__value"><?php echo esc_html( number_format_i18n( $week['departures'] ) ); ?></dd></div>
							<div class="flexo-stat"><dt class="flexo-stat__label"><?php esc_html_e( 'room-nights booked in the next 7 days', 'flexo-booking' ); ?></dt><dd class="flexo-stat__value"><?php echo esc_html( number_format_i18n( $week['nights'] ) ); ?></dd></div>
						</dl>
					</section>
					<nav class="flexo-card flexo-shortcuts" aria-labelledby="flexo-shortcuts-title">
						<h2 id="flexo-shortcuts-title"><?php esc_html_e( 'Shortcuts', 'flexo-booking' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG ) ); ?>"><?php echo Flexo_Booking_Admin_UI::icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php esc_html_e( 'Open the calendar', 'flexo-booking' ); ?></span></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-new' ) ); ?>"><?php echo Flexo_Booking_Admin_UI::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php esc_html_e( 'Add booking', 'flexo-booking' ); ?></span></a>
						<?php if ( current_user_can( Flexo_Booking_Admin::cap( 'prices' ) ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php echo Flexo_Booking_Admin_UI::icon( 'bed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php esc_html_e( 'Rooms & prices', 'flexo-booking' ); ?></span></a>
						<?php endif; ?>
						<a href="<?php echo esc_url( Flexo_Booking_Help::url() ); ?>"><?php echo Flexo_Booking_Admin_UI::icon( 'help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php esc_html_e( 'Help', 'flexo-booking' ); ?></span></a>
					</nav>
				</aside>
			</div>
		</div>
		<?php
	}
}
