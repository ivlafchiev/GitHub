<?php
/**
 * Bookings → Calendar: a month timeline with rooms as rows and days as
 * columns. Shows website bookings, staff bookings, blocked dates, closed
 * periods, bookings imported from other calendars and conflicts, plus
 * today's arrivals and departures. Inventory and reservations only – no
 * housekeeping or room assignment.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Calendar_Admin {

	const SLUG = 'flexo-booking-calendar';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_order' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Booking calendar', 'flexo-booking' ), __( 'Calendar', 'flexo-booking' ), Flexo_Booking_Admin::capability(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Puts "Calendar" right after "All bookings".
	 */
	public static function menu_order() {
		global $submenu;
		if ( empty( $submenu[ Flexo_Booking_Admin::MENU_SLUG ] ) ) {
			return;
		}
		$items    = $submenu[ Flexo_Booking_Admin::MENU_SLUG ];
		$calendar = null;
		foreach ( $items as $i => $item ) {
			if ( self::SLUG === $item[2] ) {
				$calendar = $item;
				unset( $items[ $i ] );
			}
		}
		if ( $calendar ) {
			$items = array_values( $items );
			array_splice( $items, 1, 0, array( $calendar ) );
			$submenu[ Flexo_Booking_Admin::MENU_SLUG ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/css/admin.css', array(), FLEXO_BOOKING_VERSION );
		wp_enqueue_style( 'flexo-booking-admin-calendar', FLEXO_BOOKING_URL . 'assets/css/admin-calendar.css', array(), FLEXO_BOOKING_VERSION );
		wp_enqueue_script( 'flexo-booking-admin-calendar', FLEXO_BOOKING_URL . 'assets/js/admin-calendar.js', array(), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			'flexo-booking-admin-calendar',
			'FlexoBookingCalendar',
			array(
				'newUrl' => admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-new' ),
				'i18n'   => array(
					'newBooking' => __( 'New booking', 'flexo-booking' ),
					'blockDates' => __( 'Block dates', 'flexo-booking' ),
					'close'      => __( 'Close', 'flexo-booking' ),
					'closed'     => __( 'Closed', 'flexo-booking' ),
				),
			)
		);
	}

	private static function month( $value ) {
		return preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', (string) $value ) ? $value : wp_date( 'Y-m' );
	}

	/**
	 * Everything the month view needs, built with a few queries.
	 */
	public static function data( $month, $show_cancelled = false ) {
		global $wpdb;

		$first = $month . '-01';
		$to    = ( new DateTimeImmutable( $first, wp_timezone() ) )->modify( 'first day of next month' )->format( 'Y-m-d' );
		$days  = Flexo_Booking_Dates::nights( $first, $to );
		$index = array_flip( $days );
		$today = wp_date( 'Y-m-d' );
		$rooms = Flexo_Booking_Rooms::all( array( 'publish', 'draft', 'private' ) );
		$sync  = Flexo_Booking_ICal::enabled();

		$statuses = Flexo_Booking_Inventory::occupying_statuses();
		if ( $show_cancelled ) {
			$statuses[] = 'cancelled';
		}
		$table        = Flexo_Booking_Install::table();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bookings = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE check_in < %s AND check_out > %s AND status IN ({$placeholders}) ORDER BY check_in ASC", array_merge( array( $to, $first ), $statuses ) ), ARRAY_A );
		$events   = $sync ? Flexo_Booking_ICal::events_between( $first, $to ) : array();
		$closures = array_filter(
			Flexo_Booking_Closures::all(),
			static function ( $c ) use ( $first, $to ) {
				return $c['date_from'] < $to && $c['date_to'] >= $first;
			}
		);

		// Flexo bookings that collide with a flagged imported booking.
		$conflicted = array();
		foreach ( $events as $event ) {
			if ( $event['conflict'] ) {
				foreach ( $bookings as $b ) {
					if ( (int) $b['room_id'] === $event['room_id'] && $b['check_in'] < $event['date_to'] && $b['check_out'] > $event['date_from'] && 'cancelled' !== $b['status'] ) {
						$conflicted[ (int) $b['id'] ] = true;
					}
				}
			}
		}

		$rows = array();
		foreach ( $rooms as $post ) {
			$room  = Flexo_Booking_Rooms::to_array( $post );
			$items = array();

			foreach ( $bookings as $b ) {
				if ( (int) $b['room_id'] === $room['id'] ) {
					$items[] = self::booking_item( $b, $room, isset( $conflicted[ (int) $b['id'] ] ) );
				}
			}
			foreach ( $events as $event ) {
				if ( $event['room_id'] === $room['id'] ) {
					$items[] = self::event_item( $event, $room );
				}
			}
			$closed = array();
			foreach ( $closures as $closure ) {
				if ( 0 === $closure['room_id'] || $room['id'] === $closure['room_id'] ) {
					$items[] = self::closure_item( $closure );
					foreach ( $days as $day ) {
						if ( $day >= $closure['date_from'] && $day <= $closure['date_to'] ) {
							$closed[ $day ] = Flexo_Booking_Closures::guest_message( $closure );
						}
					}
				}
			}

			// Grid geometry: two columns per day, so a stay starts in the
			// afternoon of arrival and ends in the morning of departure.
			$count = count( $days );
			foreach ( $items as $i => $item ) {
				$start                 = isset( $index[ $item['start'] ] ) ? 2 * $index[ $item['start'] ] + 2 : 1;
				$end                   = isset( $index[ $item['end'] ] ) ? 2 * $index[ $item['end'] ] + 2 : 2 * $count + 1;
				$items[ $i ]['col']    = array( $start, max( $start + 1, $end ) );
				$items[ $i ]['before'] = $item['start'] < $first;
				$items[ $i ]['after']  = $item['end'] > $to;
			}
			usort(
				$items,
				static function ( $a, $b ) {
					return $a['col'][0] - $b['col'][0] ?: $b['col'][1] - $a['col'][1];
				}
			);
			$lanes = array();
			foreach ( $items as $i => $item ) {
				$lane = 0;
				while ( isset( $lanes[ $lane ] ) && $lanes[ $lane ] > $item['col'][0] ) {
					++$lane;
				}
				$lanes[ $lane ]      = $item['col'][1];
				$items[ $i ]['lane'] = $lane;
			}

			$usage = Flexo_Booking_Inventory::nightly_usage( $room, $first, $to );
			$free  = array();
			foreach ( $days as $day ) {
				$free[ $day ] = isset( $closed[ $day ] ) ? 0 : max( 0, $room['units'] - $usage[ $day ] );
			}

			$rows[] = array(
				'room'   => $room,
				'items'  => $items,
				'lanes'  => max( 1, count( $lanes ) ),
				'free'   => $free,
				'closed' => $closed,
			);
		}

		return array(
			'month'    => $month,
			'first'    => $first,
			'days'     => $days,
			'today'    => $today,
			'rows'     => $rows,
			'sync'     => $sync,
			'overview' => self::today_overview( $today ),
		);
	}

	/**
	 * Nonce-protected admin-post URL, unescaped (it travels as JSON to the
	 * details dialog; wp_nonce_url() would HTML-escape the ampersands).
	 */
	private static function action_url( $action, $id, $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'action'   => $action,
					'id'       => (int) $id,
					'_wpnonce' => wp_create_nonce( $action . '_' . (int) $id ),
				),
				$extra
			),
			admin_url( 'admin-post.php' )
		);
	}

	private static function nights_text( $from, $to ) {
		$n = count( Flexo_Booking_Dates::nights( $from, $to ) );
		/* translators: %d: nights */
		return sprintf( _n( '%d night', '%d nights', $n, 'flexo-booking' ), $n );
	}

	private static function booking_item( array $b, array $room, $conflict ) {
		$staff   = 'admin' === $b['source'];
		$blocked = 'blocked' === $b['status'];
		$name    = '' !== $b['guest_name'] ? $b['guest_name'] : $b['reference'];

		if ( $blocked ) {
			$kind  = 'blocked';
			$icon  = '⛔';
			$label = __( 'Blocked', 'flexo-booking' ) . ( $b['notes'] ? ' – ' . $b['notes'] : '' );
			$type  = __( 'Blocked dates (by staff)', 'flexo-booking' );
		} else {
			$kind  = $b['status'];
			$icons = array(
				'confirmed' => '✓',
				'pending'   => '⏳',
				'cancelled' => '⊘',
			);
			$icon  = isset( $icons[ $b['status'] ] ) ? $icons[ $b['status'] ] : '•';
			$label = ( $staff ? '✎ ' : '' ) . $name;
			$type  = $staff ? __( 'Booking added by staff', 'flexo-booking' ) : __( 'Website booking', 'flexo-booking' );
		}
		if ( $conflict ) {
			$icon = '⚠ ' . $icon;
		}

		$lines = array(
			array( __( 'Type', 'flexo-booking' ), $type ),
			array( __( 'Reference', 'flexo-booking' ), $b['reference'] ),
			array( __( 'Status', 'flexo-booking' ), Flexo_Booking_Bookings::status_label( $b['status'] ) ),
		);
		if ( ! $blocked ) {
			$lines[] = array( __( 'Guest', 'flexo-booking' ), $b['guest_name'] );
			if ( $b['guest_email'] ) {
				$lines[] = array( __( 'Email', 'flexo-booking' ), $b['guest_email'] );
			}
			if ( $b['guest_phone'] ) {
				$lines[] = array( __( 'Phone', 'flexo-booking' ), $b['guest_phone'] );
			}
		}
		$lines[] = array( __( 'Room', 'flexo-booking' ), $room['title'] );
		$lines[] = array( __( 'Arrival', 'flexo-booking' ), Flexo_Booking_Dates::display( $b['check_in'] ) );
		$lines[] = array( __( 'Departure', 'flexo-booking' ), Flexo_Booking_Dates::display( $b['check_out'] ) . ' · ' . self::nights_text( $b['check_in'], $b['check_out'] ) );
		if ( ! $blocked ) {
			$lines[] = array( __( 'Guests', 'flexo-booking' ), Flexo_Booking_Children::guests_text( (int) $b['adults'], (int) $b['children'], isset( $b['children_ages'] ) ? $b['children_ages'] : '' ) );
			$plan = Flexo_Booking_Admin::rate_plan_name( $b );
			if ( $plan ) {
				$lines[] = array( __( 'Rate plan', 'flexo-booking' ), $plan );
			}
			if ( ! empty( $b['promo_code'] ) ) {
				$lines[] = array( __( 'Promo code', 'flexo-booking' ), $b['promo_code'] );
			}
			$lines[] = array( __( 'Total', 'flexo-booking' ), Flexo_Booking_Money::format( $b['total'], $b['currency'] ) );
		}
		if ( $b['notes'] ) {
			$lines[] = array( __( 'Notes', 'flexo-booking' ), $b['notes'] );
		}
		if ( $conflict ) {
			$lines[] = array( __( 'Warning', 'flexo-booking' ), __( 'Overlaps a booking from an external calendar – possible double booking.', 'flexo-booking' ) );
		}

		$actions = array();
		if ( 'pending' === $b['status'] ) {
			$actions[] = array(
				'label'   => __( 'Confirm', 'flexo-booking' ),
				'url'     => self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ),
				'primary' => true,
			);
		}
		if ( in_array( $b['status'], array( 'pending', 'confirmed' ), true ) ) {
			$actions[] = array(
				'label'   => __( 'Cancel booking', 'flexo-booking' ),
				'url'     => self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ),
				'confirm' => __( 'Cancel this booking? The guest will be notified by email.', 'flexo-booking' ),
			);
		}
		if ( $blocked ) {
			$actions[] = array(
				'label'   => __( 'Remove block', 'flexo-booking' ),
				'url'     => self::action_url( 'flexo_booking_delete', $b['id'] ),
				'confirm' => __( 'Remove this block? The dates become available again.', 'flexo-booking' ),
			);
		}
		if ( ! $blocked ) {
			$actions[] = array(
				'label' => __( 'Booking details', 'flexo-booking' ),
				'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&booking=' . (int) $b['id'] ),
			);
		}
		$actions[] = array(
			'label' => __( 'Show in bookings list', 'flexo-booking' ),
			'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '&s=' . rawurlencode( $b['reference'] ) ),
		);

		return array(
			'kind'     => $kind,
			'staff'    => $staff,
			'conflict' => $conflict,
			'start'    => $b['check_in'],
			'end'      => $b['check_out'],
			'icon'     => $icon,
			'label'    => $label,
			'title'    => $type . ': ' . $name . ', ' . Flexo_Booking_Dates::display( $b['check_in'] ) . ' – ' . Flexo_Booking_Dates::display( $b['check_out'] ) . ' (' . Flexo_Booking_Bookings::status_label( $b['status'] ) . ')',
			'dialog'   => array(
				'heading' => $blocked ? __( 'Blocked dates', 'flexo-booking' ) : $name,
				'lines'   => $lines,
				'actions' => $actions,
			),
		);
	}

	private static function event_item( array $event, array $room ) {
		$name     = $event['calendar_name'] ? $event['calendar_name'] : __( 'External calendar', 'flexo-booking' );
		$conflict = $event['conflict'] && ! $event['conflict_reviewed'];
		$lines    = array(
			array( __( 'Type', 'flexo-booking' ), __( 'Booking from an external calendar (synced)', 'flexo-booking' ) ),
			array( __( 'Calendar', 'flexo-booking' ), $name ),
			array( __( 'Room', 'flexo-booking' ), $room['title'] . ( $event['unit'] ? ' – ' . Flexo_Booking_Sync_Admin::unit_label( $event['unit'] ) : '' ) ),
			array( __( 'Arrival', 'flexo-booking' ), Flexo_Booking_Dates::display( $event['date_from'] ) ),
			array( __( 'Departure', 'flexo-booking' ), Flexo_Booking_Dates::display( $event['date_to'] ) . ' · ' . self::nights_text( $event['date_from'], $event['date_to'] ) ),
		);
		if ( '' !== $event['summary'] ) {
			$lines[] = array( __( 'Text from the calendar', 'flexo-booking' ), $event['summary'] );
		}
		$actions = array();
		if ( $event['conflict'] ) {
			$lines[] = array( __( 'Conflict', 'flexo-booking' ), $event['conflict_note'] . ( $event['conflict_reviewed'] ? ' ' . __( '(marked as reviewed)', 'flexo-booking' ) : '' ) );
			if ( ! $event['conflict_reviewed'] ) {
				$actions[] = array(
					'label'   => __( 'Mark as reviewed', 'flexo-booking' ),
					'url'     => Flexo_Booking_Sync_Admin::review_url( $event['id'] ),
					'primary' => true,
				);
			}
		}
		$lines[]   = array( __( 'Note', 'flexo-booking' ), __( 'Details and changes are managed on the booking website. It disappears here when it is cancelled there.', 'flexo-booking' ) );
		$actions[] = array(
			'label' => __( 'Calendar Sync', 'flexo-booking' ),
			'url'   => Flexo_Booking_Sync_Admin::page_url() . '#room-' . $event['room_id'],
		);

		return array(
			'kind'     => 'external',
			'staff'    => false,
			'conflict' => (bool) $event['conflict'],
			'start'    => $event['date_from'],
			'end'      => $event['date_to'],
			'icon'     => $conflict ? '⚠ ⇄' : '⇄',
			'label'    => $name,
			'title'    => ( $event['conflict'] ? __( 'Conflict', 'flexo-booking' ) . ' – ' : '' ) . __( 'External booking', 'flexo-booking' ) . ': ' . $name . ', ' . Flexo_Booking_Dates::display( $event['date_from'] ) . ' – ' . Flexo_Booking_Dates::display( $event['date_to'] ),
			'dialog'   => array(
				'heading' => $name,
				'lines'   => $lines,
				'actions' => $actions,
			),
		);
	}

	private static function closure_item( array $closure ) {
		$message = Flexo_Booking_Closures::guest_message( $closure );
		return array(
			'kind'     => 'closed',
			'staff'    => false,
			'conflict' => false,
			'start'    => $closure['date_from'],
			'end'      => Flexo_Booking_Dates::add_days( $closure['date_to'], 1 ),
			'icon'     => '✕',
			'label'    => '' !== $closure['label'] ? $closure['label'] : __( 'Closed', 'flexo-booking' ),
			'title'    => __( 'Closed', 'flexo-booking' ) . ': ' . $message,
			'dialog'   => array(
				'heading' => __( 'Closed period', 'flexo-booking' ),
				'lines'   => array(
					array( __( 'Applies to', 'flexo-booking' ), $closure['room_id'] ? get_the_title( $closure['room_id'] ) : __( 'Whole property', 'flexo-booking' ) ),
					array( __( 'From', 'flexo-booking' ), Flexo_Booking_Dates::display( $closure['date_from'] ) ),
					array( __( 'To (last night)', 'flexo-booking' ), Flexo_Booking_Dates::display( $closure['date_to'] ) ),
					array( __( 'Guests see', 'flexo-booking' ), $message ),
				),
				'actions' => array(
					array(
						'label' => __( 'Edit closed dates', 'flexo-booking' ),
						'url'   => admin_url( 'admin.php?page=' . Flexo_Booking_Closures_Admin::SLUG . '&edit=' . $closure['id'] ) . '#flexo-closure-form',
					),
				),
			),
		);
	}

	/**
	 * Arrivals, departures and stays for today, across all rooms.
	 */
	private static function today_overview( $today ) {
		global $wpdb;
		$table    = Flexo_Booking_Install::table();
		$occupied = Flexo_Booking_Inventory::occupying_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE check_in <= %s AND check_out >= %s AND status <> 'blocked' AND {$occupied['sql']} ORDER BY check_in", array_merge( array( $today, $today ), $occupied['params'] ) ), ARRAY_A );

		$out = array(
			'arrivals'   => array(),
			'departures' => array(),
			'staying'    => array(),
		);
		foreach ( $rows as $b ) {
			$text = ( '' !== $b['guest_name'] ? $b['guest_name'] : $b['reference'] ) . ' – ' . get_the_title( $b['room_id'] );
			if ( $b['check_in'] === $today ) {
				$out['arrivals'][] = $text;
			} elseif ( $b['check_out'] === $today ) {
				$out['departures'][] = $text;
			}
			if ( $b['check_in'] <= $today && $b['check_out'] > $today && $b['check_in'] !== $today ) {
				$out['staying'][] = $text;
			}
		}
		if ( Flexo_Booking_ICal::enabled() ) {
			foreach ( Flexo_Booking_ICal::events_between( Flexo_Booking_Dates::add_days( $today, -1 ), Flexo_Booking_Dates::add_days( $today, 1 ) ) as $event ) {
				$text = '⇄ ' . $event['calendar_name'] . ' – ' . get_the_title( $event['room_id'] );
				if ( $event['date_from'] === $today ) {
					$out['arrivals'][] = $text;
				} elseif ( $event['date_to'] === $today ) {
					$out['departures'][] = $text;
				} elseif ( $event['date_from'] < $today && $event['date_to'] > $today ) {
					$out['staying'][] = $text;
				}
			}
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( Flexo_Booking_Admin::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view settings.
		$month          = self::month( isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '' );
		$show_cancelled = ! empty( $_GET['cancelled'] );
		// phpcs:enable
		$data  = self::data( $month, $show_cancelled );
		$first = new DateTimeImmutable( $data['first'], wp_timezone() );
		$base  = array(
			'page'      => self::SLUG,
			'cancelled' => $show_cancelled ? 1 : null,
		);
		$url   = static function ( $m ) use ( $base ) {
			return add_query_arg( array_filter( array_merge( $base, array( 'month' => $m ) ) ), admin_url( 'admin.php' ) );
		};
		$count = count( $data['days'] );
		?>
		<div class="wrap flexo-admin flexo-calendar-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Booking calendar', 'flexo-booking' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add booking', 'flexo-booking' ); ?></a>
			<hr class="wp-header-end">

			<div class="fbc-today" aria-label="<?php esc_attr_e( 'Today', 'flexo-booking' ); ?>">
				<?php
				$panels = array(
					'arrivals'   => __( 'Arriving today', 'flexo-booking' ),
					'departures' => __( 'Leaving today', 'flexo-booking' ),
					'staying'    => __( 'Staying tonight', 'flexo-booking' ),
				);
				foreach ( $panels as $key => $title ) :
					?>
					<div class="fbc-today__panel">
						<h2><?php echo esc_html( $title ); ?> <span class="fbc-count"><?php echo esc_html( count( $data['overview'][ $key ] ) ); ?></span></h2>
						<?php if ( $data['overview'][ $key ] ) : ?>
							<ul>
								<?php foreach ( $data['overview'][ $key ] as $line ) : ?>
									<li><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="flexo-muted"><?php esc_html_e( 'Nobody', 'flexo-booking' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="fbc-toolbar">
				<a class="button" href="<?php echo esc_url( $url( $first->modify( '-1 month' )->format( 'Y-m' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'flexo-booking' ); ?>">‹</a>
				<a class="button" href="<?php echo esc_url( $url( wp_date( 'Y-m' ) ) ); ?>"><?php esc_html_e( 'Today', 'flexo-booking' ); ?></a>
				<a class="button" href="<?php echo esc_url( $url( $first->modify( '+1 month' )->format( 'Y-m' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'flexo-booking' ); ?>">›</a>
				<h2 class="fbc-month"><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></h2>
				<form method="get" class="fbc-options">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
					<label><input type="checkbox" name="cancelled" value="1" <?php checked( $show_cancelled ); ?> onchange="this.form.submit()"> <?php esc_html_e( 'Show cancelled', 'flexo-booking' ); ?></label>
				</form>
			</div>

			<?php if ( ! $data['rows'] ) : ?>
				<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Add your first room', 'flexo-booking' ); ?></a></p>
			<?php else : ?>
			<div class="fbc-scroll" tabindex="0" aria-label="<?php esc_attr_e( 'Booking calendar – scroll sideways to see all days', 'flexo-booking' ); ?>">
				<div class="fbc" style="--fbc-days: <?php echo (int) $count; ?>">
					<div class="fbc-row fbc-row--head">
						<div class="fbc-room"><?php esc_html_e( 'Room', 'flexo-booking' ); ?></div>
						<div class="fbc-track">
							<?php foreach ( $data['days'] as $i => $day ) : ?>
								<?php $date = new DateTimeImmutable( $day, wp_timezone() ); ?>
								<div class="fbc-day <?php echo esc_attr( self::day_classes( $day, $data['today'] ) ); ?>" style="grid-column: <?php echo (int) ( 2 * $i + 1 ); ?> / span 2">
									<span class="fbc-day__name"><?php echo esc_html( wp_date( 'D', $date->getTimestamp() ) ); ?></span>
									<span class="fbc-day__num"><?php echo esc_html( $date->format( 'j' ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<?php foreach ( $data['rows'] as $row ) : ?>
						<?php $room = $row['room']; ?>
						<div class="fbc-row" style="--fbc-lanes: <?php echo (int) $row['lanes']; ?>">
							<div class="fbc-room">
								<strong><?php echo esc_html( $room['title'] ); ?></strong>
								<span class="flexo-muted">
									<?php
									echo esc_html(
										1 === $room['units']
											? __( '1 room', 'flexo-booking' )
											/* translators: %d: identical rooms */
											: sprintf( __( '%d rooms', 'flexo-booking' ), $room['units'] )
									);
									?>
								</span>
							</div>
							<div class="fbc-track">
								<?php foreach ( $data['days'] as $i => $day ) : ?>
									<?php
									$free      = $row['free'][ $day ];
									$closed    = isset( $row['closed'][ $day ] );
									$free_text = $closed
										? __( 'Closed', 'flexo-booking' )
										/* translators: 1: free units, 2: total units */
										: sprintf( __( '%1$d of %2$d free', 'flexo-booking' ), $free, $room['units'] );
									?>
									<button type="button" class="fbc-cell <?php echo esc_attr( self::day_classes( $day, $data['today'] ) . ( 0 === $free ? ' is-full' : '' ) . ( $closed ? ' is-closed' : '' ) ); ?>"
										style="grid-column: <?php echo (int) ( 2 * $i + 1 ); ?> / span 2"
										data-room="<?php echo esc_attr( $room['id'] ); ?>"
										data-room-title="<?php echo esc_attr( $room['title'] ); ?>"
										data-day="<?php echo esc_attr( $day ); ?>"
										data-day-label="<?php echo esc_attr( wp_date( 'l, ' . get_option( 'date_format' ), ( new DateTimeImmutable( $day, wp_timezone() ) )->getTimestamp() ) ); ?>"
										data-next="<?php echo esc_attr( Flexo_Booking_Dates::add_days( $day, 1 ) ); ?>"
										data-free="<?php echo esc_attr( $free_text ); ?>"
										aria-label="<?php echo esc_attr( $room['title'] . ', ' . Flexo_Booking_Dates::display( $day ) . ': ' . $free_text ); ?>">
										<?php if ( $room['units'] > 1 && ! $closed ) : ?>
											<span class="fbc-free" aria-hidden="true"><?php echo esc_html( $free . '/' . $room['units'] ); ?></span>
										<?php endif; ?>
									</button>
								<?php endforeach; ?>
								<?php foreach ( $row['items'] as $item ) : ?>
									<button type="button"
										class="fbc-item fbc-item--<?php echo esc_attr( $item['kind'] ); ?><?php echo $item['staff'] ? ' fbc-item--staff' : ''; ?><?php echo $item['conflict'] ? ' fbc-item--conflict' : ''; ?><?php echo $item['before'] ? ' fbc-item--before' : ''; ?><?php echo $item['after'] ? ' fbc-item--after' : ''; ?>"
										style="grid-column: <?php echo (int) $item['col'][0]; ?> / <?php echo (int) $item['col'][1]; ?>; grid-row: <?php echo (int) ( $item['lane'] + 1 ); ?>"
										title="<?php echo esc_attr( $item['title'] ); ?>"
										data-dialog="<?php echo esc_attr( wp_json_encode( $item['dialog'] ) ); ?>">
										<span class="fbc-item__icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span>
										<span class="fbc-item__label"><?php echo esc_html( $item['label'] ); ?></span>
										<span class="screen-reader-text"><?php echo esc_html( $item['title'] ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<ul class="fbc-legend" aria-label="<?php esc_attr_e( 'Legend', 'flexo-booking' ); ?>">
				<li><span class="fbc-swatch fbc-item--confirmed">✓</span> <?php esc_html_e( 'Confirmed', 'flexo-booking' ); ?></li>
				<li><span class="fbc-swatch fbc-item--pending">⏳</span> <?php esc_html_e( 'Pending (waiting for you)', 'flexo-booking' ); ?></li>
				<li><span class="fbc-swatch fbc-item--confirmed">✎</span> <?php esc_html_e( 'Added by staff', 'flexo-booking' ); ?></li>
				<li><span class="fbc-swatch fbc-item--blocked">⛔</span> <?php esc_html_e( 'Blocked by staff', 'flexo-booking' ); ?></li>
				<?php if ( $data['sync'] ) : ?>
					<li><span class="fbc-swatch fbc-item--external">⇄</span> <?php esc_html_e( 'From an external calendar (Booking.com, Airbnb…)', 'flexo-booking' ); ?></li>
					<li><span class="fbc-swatch fbc-item--conflict">⚠</span> <?php esc_html_e( 'Conflict – possible double booking', 'flexo-booking' ); ?></li>
				<?php endif; ?>
				<li><span class="fbc-swatch fbc-item--closed">✕</span> <?php esc_html_e( 'Closed period', 'flexo-booking' ); ?></li>
				<?php if ( $show_cancelled ) : ?>
					<li><span class="fbc-swatch fbc-item--cancelled">⊘</span> <?php esc_html_e( 'Cancelled', 'flexo-booking' ); ?></li>
				<?php endif; ?>
				<li><span class="fbc-swatch fbc-swatch--full"></span> <?php esc_html_e( 'Fully booked day', 'flexo-booking' ); ?></li>
				<li class="flexo-muted"><?php esc_html_e( 'Click a booking for details, or an empty day to add a booking or block dates.', 'flexo-booking' ); ?></li>
			</ul>

			<dialog class="fbc-dialog" id="fbc-dialog" aria-labelledby="fbc-dialog-title">
				<form method="dialog" class="fbc-dialog__close"><button class="button-link" aria-label="<?php esc_attr_e( 'Close', 'flexo-booking' ); ?>">✕</button></form>
				<h2 id="fbc-dialog-title"></h2>
				<p class="fbc-dialog__sub"></p>
				<dl class="fbc-dialog__lines"></dl>
				<div class="fbc-dialog__actions"></div>
			</dialog>
		</div>
		<?php
	}

	private static function day_classes( $day, $today ) {
		$classes = array();
		if ( (int) ( new DateTimeImmutable( $day, wp_timezone() ) )->format( 'N' ) >= 6 ) {
			$classes[] = 'is-weekend';
		}
		if ( $day === $today ) {
			$classes[] = 'is-today';
		}
		if ( $day < $today ) {
			$classes[] = 'is-past';
		}
		return implode( ' ', $classes );
	}
}
