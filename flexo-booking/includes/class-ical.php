<?php
/**
 * Calendar sync (feature "calendar_sync"): iCal import from Booking.com,
 * Airbnb, Vrbo… and one iCal export feed per room. Standard iCal only – no
 * booking-site APIs.
 *
 * Imported bookings live in their own table (calendar_events) and are counted
 * by Flexo_Booking_Inventory::nightly_usage():
 *   - each imported booking takes one unit of the room type;
 *   - a calendar can be linked to one specific unit ("room no. 2"); bookings
 *     from calendars linked to the same unit take that unit once per night.
 * While the feature is off, syncing stops and imported bookings don't block
 * anything; connections and imported data are kept.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_ICal {

	const CRON_HOOK  = 'flexo_booking_ical_sync';
	const TOKEN_META = '_flexo_ical_token';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_all' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		// A cancelled or changed booking can resolve a conflict.
		add_action( 'flexo_booking_status_changed', array( __CLASS__, 'on_booking_changed' ) );
	}

	public static function enabled() {
		return Flexo_Booking_Features::is_enabled( 'calendar_sync' );
	}

	/* ---------------------------------------------------------------------
	 * Scheduling
	 * ------------------------------------------------------------------- */

	public static function intervals() {
		return array(
			15 => __( 'Every 15 minutes', 'flexo-booking' ),
			30 => __( 'Every 30 minutes', 'flexo-booking' ),
			60 => __( 'Every hour', 'flexo-booking' ),
		);
	}

	public static function interval() {
		$minutes = (int) Flexo_Booking_Settings::get( 'ical_interval' );
		return array_key_exists( $minutes, self::intervals() ) ? $minutes : 30;
	}

	public static function cron_schedules( $schedules ) {
		foreach ( self::intervals() as $minutes => $label ) {
			$schedules[ 'flexo_ical_' . $minutes ] = array(
				'interval' => $minutes * MINUTE_IN_SECONDS,
				'display'  => $label,
			);
		}
		return $schedules;
	}

	/**
	 * Keeps the WP-Cron event in line with the feature switch and interval.
	 */
	public static function maybe_schedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! self::enabled() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}
		$want = 'flexo_ical_' . self::interval();
		if ( ! $next || wp_get_schedule( self::CRON_HOOK ) !== $want ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $want, self::CRON_HOOK );
		}
	}

	/**
	 * Cron job: syncs every connected calendar. One failing calendar never
	 * stops the others.
	 */
	public static function sync_all() {
		if ( ! self::enabled() ) {
			return array();
		}
		$results = array();
		foreach ( self::calendars() as $calendar ) {
			if ( ! $calendar['active'] ) {
				continue;
			}
			try {
				$results[ $calendar['id'] ] = self::sync_calendar( $calendar['id'] );
			} catch ( Throwable $e ) {
				self::record_failure( $calendar['id'], __( 'Unexpected error while reading the calendar.', 'flexo-booking' ) . ' ' . $e->getMessage() );
				$results[ $calendar['id'] ] = new WP_Error( 'flexo_ical_exception', $e->getMessage() );
			}
		}
		return $results;
	}

	public static function sync_room( $room_id ) {
		$results = array();
		foreach ( self::calendars( $room_id ) as $calendar ) {
			$results[ $calendar['id'] ] = self::sync_calendar( $calendar['id'] );
		}
		return $results;
	}

	/* ---------------------------------------------------------------------
	 * Connected calendars
	 * ------------------------------------------------------------------- */

	private static function calendars_table() {
		return Flexo_Booking_Schema::table( 'calendars' );
	}

	private static function events_table() {
		return Flexo_Booking_Schema::table( 'calendar_events' );
	}

	private static function hydrate_calendar( $row ) {
		foreach ( array( 'id', 'room_id', 'unit', 'active', 'fail_count', 'event_count' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	private static function hydrate_event( $row ) {
		foreach ( array( 'id', 'calendar_id', 'room_id', 'unit', 'conflict', 'conflict_notified', 'conflict_reviewed' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	/**
	 * @param int $room_id 0 = all rooms.
	 */
	public static function calendars( $room_id = 0 ) {
		global $wpdb;
		$table = self::calendars_table();
		$sql   = "SELECT * FROM {$table}";
		if ( $room_id ) {
			$sql .= $wpdb->prepare( ' WHERE room_id = %d', $room_id );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql . ' ORDER BY room_id ASC, id ASC', ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_calendar' ), $rows ? $rows : array() );
	}

	public static function get_calendar( $id ) {
		global $wpdb;
		$table = self::calendars_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate_calendar( $row ) : null;
	}

	/**
	 * webcal:// links (Apple, some OTAs) are plain https.
	 *
	 * @return string|false
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );
		$url = preg_replace( '#^webcals?://#i', 'https://', $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return $url ? $url : false;
	}

	/**
	 * @return int|WP_Error Calendar ID.
	 */
	public static function add_calendar( $room_id, $name, $url, $unit = 0 ) {
		global $wpdb;

		$room = get_post( (int) $room_id );
		if ( ! $room || Flexo_Booking_Rooms::POST_TYPE !== $room->post_type ) {
			return new WP_Error( 'flexo_ical_room', __( 'Please choose a room.', 'flexo-booking' ) );
		}
		$url = self::normalize_url( $url );
		if ( ! $url ) {
			return new WP_Error( 'flexo_ical_url', __( 'Please paste the full calendar link, starting with https://', 'flexo-booking' ) );
		}
		foreach ( self::calendars( $room->ID ) as $existing ) {
			if ( $existing['import_url'] === $url ) {
				return new WP_Error( 'flexo_ical_duplicate', __( 'This calendar is already connected to this room.', 'flexo-booking' ) );
			}
		}
		$name  = sanitize_text_field( $name );
		$units = Flexo_Booking_Rooms::to_array( $room )['units'];
		$unit  = min( absint( $unit ), max( 0, $units ) );
		$now   = current_time( 'mysql' );

		$wpdb->insert(
			self::calendars_table(),
			array(
				'room_id'    => $room->ID,
				'name'       => '' !== $name ? $name : wp_parse_url( $url, PHP_URL_HOST ),
				'import_url' => $url,
				'unit'       => $unit,
				'active'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Removes a connection and releases the dates it blocked.
	 */
	public static function delete_calendar( $id ) {
		global $wpdb;
		$calendar = self::get_calendar( $id );
		if ( ! $calendar ) {
			return false;
		}
		$wpdb->delete( self::events_table(), array( 'calendar_id' => (int) $id ) );
		$wpdb->delete( self::calendars_table(), array( 'id' => (int) $id ) );
		self::detect_conflicts( $calendar['room_id'], false );
		return true;
	}

	/**
	 * Connections that failed several times in a row (shown as admin notices).
	 */
	public static function failing_calendars( $min_failures = 3 ) {
		return array_values(
			array_filter(
				self::calendars(),
				static function ( $calendar ) use ( $min_failures ) {
					return $calendar['active'] && $calendar['fail_count'] >= $min_failures;
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reading a feed
	 * ------------------------------------------------------------------- */

	/**
	 * Downloads a feed. Local/private addresses are refused (wp_safe_remote_get).
	 *
	 * @return string|WP_Error The iCal text, or an error with a message a
	 *                         hotel owner can act on.
	 */
	public static function fetch( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) apply_filters( 'flexo_booking_ical_timeout', 15 ),
				'redirection'         => 3,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'user-agent'          => 'FlexoBooking/' . FLEXO_BOOKING_VERSION . '; ' . home_url(),
				'headers'             => array( 'Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.5' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			if ( stripos( $message, 'timed out' ) !== false || stripos( $message, 'cURL error 28' ) !== false ) {
				return new WP_Error( 'flexo_ical_timeout', __( 'The calendar website did not answer in time. It may be temporarily down – we will try again automatically.', 'flexo-booking' ) );
			}
			if ( stripos( $message, 'resolve host' ) !== false ) {
				return new WP_Error( 'flexo_ical_dns', __( 'The calendar address could not be found. Check the link for typos.', 'flexo-booking' ) );
			}
			if ( stripos( $message, 'valid URL' ) !== false ) {
				return new WP_Error( 'flexo_ical_unsafe', __( 'This address cannot be used (it is not a public web address).', 'flexo-booking' ) );
			}
			if ( stripos( $message, 'SSL' ) !== false || stripos( $message, 'certificate' ) !== false ) {
				return new WP_Error( 'flexo_ical_ssl', __( 'A secure connection to the calendar website failed (certificate problem).', 'flexo-booking' ) );
			}
			/* translators: %s: technical error message */
			return new WP_Error( 'flexo_ical_connect', sprintf( __( 'Could not connect to the calendar website (%s).', 'flexo-booking' ), $message ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code || 410 === $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'flexo_ical_404', sprintf( __( 'The calendar link no longer exists (HTTP %d). It may have been reset on the booking website – copy the export link again.', 'flexo-booking' ), $code ) );
		}
		if ( 401 === $code || 403 === $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'flexo_ical_denied', sprintf( __( 'Access to the calendar was refused (HTTP %d). Check that you copied the complete link.', 'flexo-booking' ), $code ) );
		}
		if ( $code >= 500 ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'flexo_ical_server', sprintf( __( 'The calendar website had a problem (HTTP %d). We will try again automatically.', 'flexo-booking' ), $code ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'flexo_ical_http', sprintf( __( 'Unexpected answer from the calendar website (HTTP %d).', 'flexo-booking' ), $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( false === stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			return new WP_Error( 'flexo_ical_not_calendar', __( 'The link opened a web page, not a calendar. Copy the iCal / export link (it usually ends in .ics).', 'flexo-booking' ) );
		}
		return $body;
	}

	/**
	 * Parses iCal text into bookings.
	 *
	 * All-day events: DTEND is exclusive (the check-out day stays free).
	 * Timed events use their dates in the hotel's time zone. Cancelled events
	 * are skipped. Repeating events (RRULE) are not expanded – booking
	 * websites don't use them.
	 *
	 * @return array[] Each: uid, date_from, date_to (exclusive), summary.
	 */
	public static function parse( $ics ) {
		$ics    = str_replace( array( "\r\n", "\r" ), "\n", (string) $ics );
		$ics    = preg_replace( "/\n[ \t]/", '', $ics ); // Unfold continued lines.
		$events = array();
		$props  = null;
		$nested = 0;

		foreach ( explode( "\n", $ics ) as $line ) {
			$upper = strtoupper( trim( $line ) );
			if ( 'BEGIN:VEVENT' === $upper ) {
				$props  = array();
				$nested = 0;
				continue;
			}
			if ( null === $props ) {
				continue;
			}
			if ( 'END:VEVENT' === $upper ) {
				$event = self::event_from_props( $props );
				if ( $event ) {
					$events[] = $event;
				}
				$props = null;
				continue;
			}
			// Skip nested components such as VALARM.
			if ( 0 === strpos( $upper, 'BEGIN:' ) ) {
				++$nested;
				continue;
			}
			if ( 0 === strpos( $upper, 'END:' ) ) {
				$nested = max( 0, $nested - 1 );
				continue;
			}
			if ( $nested ) {
				continue;
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$params = explode( ';', substr( $line, 0, $colon ) );
			$name   = strtoupper( trim( array_shift( $params ) ) );
			$args   = array();
			foreach ( $params as $param ) {
				$pair = explode( '=', $param, 2 );
				if ( 2 === count( $pair ) ) {
					$args[ strtoupper( trim( $pair[0] ) ) ] = trim( $pair[1], '"' );
				}
			}
			if ( ! isset( $props[ $name ] ) ) {
				$props[ $name ] = array(
					'value'  => trim( substr( $line, $colon + 1 ) ),
					'params' => $args,
				);
			}
		}
		return $events;
	}

	private static function event_from_props( array $props ) {
		if ( isset( $props['STATUS'] ) && 'CANCELLED' === strtoupper( $props['STATUS']['value'] ) ) {
			return null;
		}
		if ( ! isset( $props['DTSTART'] ) ) {
			return null;
		}
		$start = self::parse_date( $props['DTSTART'] );
		if ( ! $start ) {
			return null;
		}

		$end = isset( $props['DTEND'] ) ? self::parse_date( $props['DTEND'] ) : null;
		if ( ! $end && isset( $props['DURATION'] ) && preg_match( '/P(?:(\d+)W)?(?:(\d+)D)?/', strtoupper( $props['DURATION']['value'] ), $m ) ) {
			$days = ( isset( $m[1] ) ? (int) $m[1] * 7 : 0 ) + ( isset( $m[2] ) ? (int) $m[2] : 0 );
			$end  = Flexo_Booking_Dates::add_days( $start, max( 1, $days ) );
		}
		if ( ! $end || $end <= $start ) {
			$end = Flexo_Booking_Dates::add_days( $start, 1 );
		}

		$summary = isset( $props['SUMMARY'] ) ? self::unescape( $props['SUMMARY']['value'] ) : '';
		$uid     = isset( $props['UID'] ) && '' !== $props['UID']['value'] ? $props['UID']['value'] : md5( $start . '|' . $end . '|' . $summary );

		return array(
			'uid'       => substr( $uid, 0, 255 ),
			'date_from' => $start,
			'date_to'   => $end,
			'summary'   => substr( sanitize_text_field( $summary ), 0, 190 ),
		);
	}

	/**
	 * DATE (20261010) or DATE-TIME (20261010T140000Z, with or without TZID).
	 *
	 * @return string|null Y-m-d in the hotel's time zone.
	 */
	private static function parse_date( array $prop ) {
		$value = strtoupper( trim( $prop['value'] ) );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
		}
		if ( ! preg_match( '/^(\d{8})T(\d{4,6})(Z?)$/', $value, $m ) ) {
			return null;
		}
		try {
			if ( 'Z' === $m[3] ) {
				$source = new DateTimeZone( 'UTC' );
			} elseif ( ! empty( $prop['params']['TZID'] ) ) {
				try {
					$source = new DateTimeZone( $prop['params']['TZID'] );
				} catch ( Exception $e ) {
					$source = wp_timezone();
				}
			} else {
				$source = wp_timezone();
			}
			$date = DateTimeImmutable::createFromFormat( 'Ymd His', $m[1] . ' ' . str_pad( $m[2], 6, '0' ), $source );
			return $date ? $date->setTimezone( wp_timezone() )->format( 'Y-m-d' ) : null;
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function unescape( $text ) {
		return str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( ' ', ' ', ',', ';', '\\' ), $text );
	}

	/* ---------------------------------------------------------------------
	 * Sync
	 * ------------------------------------------------------------------- */

	private static function host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Downloads a calendar and brings its imported bookings up to date:
	 * new ones are added, changed ones updated (matched by UID), and ones
	 * that disappeared from the feed are removed, releasing their dates.
	 * If the download fails, the previously imported bookings are kept.
	 *
	 * @return array|WP_Error { added, updated, removed, total }
	 */
	public static function sync_calendar( $id ) {
		$calendar = self::get_calendar( $id );
		if ( ! $calendar ) {
			return new WP_Error( 'flexo_ical_missing', __( 'Calendar connection not found.', 'flexo-booking' ) );
		}

		$body = self::fetch( $calendar['import_url'] );
		if ( is_wp_error( $body ) ) {
			self::record_failure( $calendar['id'], $body->get_error_message() );
			return $body;
		}
		$parsed = self::parse( $body );

		$stats = Flexo_Booking_Inventory::with_lock(
			$calendar['room_id'],
			static function () use ( $calendar, $parsed ) {
				return self::apply_events( $calendar, $parsed );
			}
		);
		if ( is_wp_error( $stats ) ) {
			self::record_failure( $calendar['id'], $stats->get_error_message() );
			return $stats;
		}

		global $wpdb;
		$wpdb->update(
			self::calendars_table(),
			array(
				'last_synced_at' => current_time( 'mysql', true ),
				'last_status'    => 'ok',
				'last_error'     => '',
				'fail_count'     => 0,
				'event_count'    => $stats['total'],
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $calendar['id'] )
		);

		self::detect_conflicts( $calendar['room_id'], true );
		return $stats;
	}

	private static function apply_events( array $calendar, array $parsed ) {
		global $wpdb;

		$today = wp_date( 'Y-m-d' );
		$own   = '@' . strtolower( self::host() );
		$keep  = array();
		foreach ( $parsed as $event ) {
			if ( $event['date_to'] <= $today ) {
				continue; // Already over.
			}
			if ( '' !== self::host() && substr( strtolower( $event['uid'] ), -strlen( $own ) ) === $own ) {
				continue; // Our own export coming back.
			}
			$uid = $event['uid'];
			if ( isset( $keep[ $uid ] ) ) {
				$uid = substr( $uid, 0, 240 ) . '#' . $event['date_from'];
			}
			$keep[ $uid ] = $event;
		}

		$table    = self::events_table();
		$existing = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE calendar_id = %d", $calendar['id'] ), ARRAY_A ) as $row ) {
			$existing[ $row['uid'] ] = $row;
		}

		$stats = array(
			'added'   => 0,
			'updated' => 0,
			'removed' => 0,
			'total'   => count( $keep ),
		);
		$now   = current_time( 'mysql' );

		foreach ( $keep as $uid => $event ) {
			$data = array(
				'date_from' => $event['date_from'],
				'date_to'   => $event['date_to'],
				'summary'   => $event['summary'],
				'unit'      => $calendar['unit'],
			);
			if ( isset( $existing[ $uid ] ) ) {
				$row = $existing[ $uid ];
				if ( $row['date_from'] !== $data['date_from'] || $row['date_to'] !== $data['date_to'] || $row['summary'] !== $data['summary'] || (int) $row['unit'] !== $data['unit'] ) {
					$data['updated_at'] = $now;
					if ( $row['date_from'] !== $data['date_from'] || $row['date_to'] !== $data['date_to'] ) {
						$data['conflict_notified'] = 0;
						$data['conflict_reviewed'] = 0;
					}
					$wpdb->update( $table, $data, array( 'id' => (int) $row['id'] ) );
					++$stats['updated'];
				}
				unset( $existing[ $uid ] );
			} else {
				$wpdb->insert(
					$table,
					array_merge(
						$data,
						array(
							'calendar_id' => $calendar['id'],
							'room_id'     => $calendar['room_id'],
							'uid'         => $uid,
							'created_at'  => $now,
							'updated_at'  => $now,
						)
					)
				);
				++$stats['added'];
			}
		}

		// Gone from the feed (cancelled on the booking website) or in the past.
		foreach ( $existing as $row ) {
			$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) );
			++$stats['removed'];
		}
		return $stats;
	}

	private static function record_failure( $calendar_id, $message ) {
		global $wpdb;
		$table = self::calendars_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_status = 'error', last_error = %s, fail_count = fail_count + 1, updated_at = %s WHERE id = %d", $message, current_time( 'mysql' ), $calendar_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Imported bookings and conflicts
	 * ------------------------------------------------------------------- */

	/**
	 * Imported bookings of a room touching the given nights.
	 */
	public static function events_for_room( $room_id, $from, $to ) {
		global $wpdb;
		$table = self::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND date_from < %s AND date_to > %s ORDER BY date_from ASC", $room_id, $to, $from ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_event' ), $rows ? $rows : array() );
	}

	/**
	 * Imported bookings of all rooms in a period, with their calendar name.
	 */
	public static function events_between( $from, $to ) {
		global $wpdb;
		$events    = self::events_table();
		$calendars = self::calendars_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.*, c.name AS calendar_name FROM {$events} e LEFT JOIN {$calendars} c ON c.id = e.calendar_id WHERE e.date_from < %s AND e.date_to > %s ORDER BY e.room_id ASC, e.date_from ASC", $to, $from ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_event' ), $rows ? $rows : array() );
	}

	public static function get_event( $id ) {
		global $wpdb;
		$events    = self::events_table();
		$calendars = self::calendars_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT e.*, c.name AS calendar_name FROM {$events} e LEFT JOIN {$calendars} c ON c.id = e.calendar_id WHERE e.id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate_event( $row ) : null;
	}

	/**
	 * Conflicts that still need attention (not marked as reviewed).
	 */
	public static function open_conflicts() {
		global $wpdb;
		if ( ! self::enabled() ) {
			return array();
		}
		$events    = self::events_table();
		$calendars = self::calendars_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT e.*, c.name AS calendar_name FROM {$events} e LEFT JOIN {$calendars} c ON c.id = e.calendar_id WHERE e.conflict = 1 AND e.conflict_reviewed = 0 ORDER BY e.date_from ASC", ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate_event' ), $rows ? $rows : array() );
	}

	public static function mark_reviewed( $event_id ) {
		global $wpdb;
		return (bool) $wpdb->update( self::events_table(), array( 'conflict_reviewed' => 1 ), array( 'id' => (int) $event_id ) );
	}

	public static function on_booking_changed( $booking ) {
		if ( self::enabled() && ! empty( $booking['room_id'] ) ) {
			self::detect_conflicts( (int) $booking['room_id'], false );
		}
	}

	/**
	 * Flags imported bookings that don't fit into the room's units together
	 * with Flexo bookings, blocks and other imported bookings. Newly found
	 * conflicts are emailed to the hotel (once).
	 *
	 * @return array[] Newly found conflicts.
	 */
	public static function detect_conflicts( $room_id, $notify = true ) {
		global $wpdb;

		if ( ! self::enabled() ) {
			return array();
		}
		$post = get_post( $room_id );
		if ( ! $post ) {
			return array();
		}
		$room   = Flexo_Booking_Rooms::to_array( $post );
		$today  = wp_date( 'Y-m-d' );
		$table  = self::events_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$events = array_map( array( __CLASS__, 'hydrate_event' ), (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE room_id = %d AND date_to > %s", $room_id, $today ), ARRAY_A ) );
		$new    = array();

		foreach ( $events as $event ) {
			$over = false;
			$note = '';
			if ( $room['units'] > 0 ) {
				$usage = Flexo_Booking_Inventory::nightly_usage( $room, $event['date_from'], $event['date_to'] );
				$over  = $usage && max( $usage ) > $room['units'];
			}
			if ( $over ) {
				$note = self::conflict_note( $room, $event );
			}
			$changes = array();
			if ( (int) $over !== $event['conflict'] || $note !== $event['conflict_note'] ) {
				$changes['conflict']      = (int) $over;
				$changes['conflict_note'] = substr( $note, 0, 255 );
				if ( ! $over ) {
					$changes['conflict_notified'] = 0;
					$changes['conflict_reviewed'] = 0;
				}
			}
			if ( $over && ! $event['conflict_notified'] && ! $event['conflict_reviewed'] && $notify ) {
				$changes['conflict_notified'] = 1;
				$new[]                        = array_merge( $event, array( 'conflict_note' => $note ) );
			}
			if ( $changes ) {
				$wpdb->update( $table, $changes, array( 'id' => $event['id'] ) );
			}
		}

		if ( $new ) {
			self::send_conflict_email( $room, $new );
		}
		return $new;
	}

	/**
	 * Which Flexo bookings the imported booking collides with.
	 */
	private static function conflict_note( array $room, array $event ) {
		global $wpdb;
		$table    = Flexo_Booking_Install::table();
		$occupied = Flexo_Booking_Inventory::occupying_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT reference, check_in, check_out FROM {$table} WHERE room_id = %d AND check_in < %s AND check_out > %s AND {$occupied['sql']} ORDER BY check_in", array_merge( array( $room['id'], $event['date_to'], $event['date_from'] ), $occupied['params'] ) ) );
		if ( ! $rows ) {
			return __( 'Overlaps other bookings from external calendars.', 'flexo-booking' );
		}
		/* translators: %s: booking references */
		$note = sprintf( __( 'Overlaps %s.', 'flexo-booking' ), implode( ', ', wp_list_pluck( $rows, 'reference' ) ) );
		foreach ( $rows as $row ) {
			if ( $row->check_in === $event['date_from'] && $row->check_out === $event['date_to'] ) {
				/* translators: %s: booking reference */
				$note .= ' ' . sprintf( __( 'Same dates as %s – the other website may be repeating your own booking back.', 'flexo-booking' ), $row->reference );
				break;
			}
		}
		return $note;
	}

	private static function send_conflict_email( array $room, array $conflicts ) {
		$lines = array(
			__( 'A booking imported from an external calendar does not fit into your availability. Please check it as soon as possible.', 'flexo-booking' ),
			'',
			/* translators: 1: room name, 2: number of rooms of this type */
			sprintf( __( 'Room: %1$s (%2$d of this type)', 'flexo-booking' ), $room['title'], $room['units'] ),
		);
		foreach ( $conflicts as $conflict ) {
			$calendar = self::get_calendar( $conflict['calendar_id'] );
			$lines[]  = '';
			/* translators: %s: calendar name */
			$lines[] = sprintf( __( 'Calendar: %s', 'flexo-booking' ), $calendar ? $calendar['name'] : '?' );
			/* translators: 1: arrival, 2: departure */
			$lines[] = sprintf( __( 'Dates: %1$s – %2$s', 'flexo-booking' ), Flexo_Booking_Dates::display( $conflict['date_from'] ), Flexo_Booking_Dates::display( $conflict['date_to'] ) );
			$lines[] = $conflict['conflict_note'];
		}
		$lines[] = '';
		$lines[] = __( 'What to do: compare the bookings, then contact the guest or the booking website to resolve the double booking. When it is handled, click "Mark as reviewed" in Bookings → Calendar.', 'flexo-booking' );
		$lines[] = admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG . '&month=' . substr( $conflicts[0]['date_from'], 0, 7 ) );

		/* translators: 1: room name, 2: arrival date */
		$subject = sprintf( __( 'Possible double booking: %1$s from %2$s', 'flexo-booking' ), $room['title'], Flexo_Booking_Dates::display( $conflicts[0]['date_from'] ) );
		wp_mail( Flexo_Booking_Settings::notification_email(), $subject, implode( "\n", $lines ) );
	}

	/* ---------------------------------------------------------------------
	 * Export feed
	 * ------------------------------------------------------------------- */

	public static function token( $room_id ) {
		$token = (string) get_post_meta( $room_id, self::TOKEN_META, true );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			$token = self::regenerate_token( $room_id );
		}
		return $token;
	}

	public static function regenerate_token( $room_id ) {
		$token = bin2hex( random_bytes( 16 ) );
		update_post_meta( $room_id, self::TOKEN_META, $token );
		return $token;
	}

	public static function check_token( $room_id, $token ) {
		$stored = (string) get_post_meta( $room_id, self::TOKEN_META, true );
		return '' !== $stored && is_string( $token ) && hash_equals( $stored, $token );
	}

	public static function export_url( $room_id ) {
		return add_query_arg( 'token', self::token( $room_id ), rest_url( Flexo_Booking_Rest::NAMESPACE_V1 . '/ical/' . (int) $room_id . '.ics' ) );
	}

	/**
	 * The room's availability as iCal: Flexo bookings, staff bookings and
	 * blocks, closed dates and bookings from other connected calendars.
	 * No guest names, emails, phones or prices.
	 */
	public static function export_feed( $room_id ) {
		global $wpdb;

		$room  = get_post( $room_id );
		$host  = self::host();
		$today = wp_date( 'Y-m-d' );
		$from  = Flexo_Booking_Dates::add_days( $today, -30 );
		$stamp = gmdate( 'Ymd\THis\Z' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//FlexoHotels//Flexo Booking ' . FLEXO_BOOKING_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' – ' . ( $room ? $room->post_title : '' ) ),
		);

		$add = static function ( $uid, $start, $end, $summary ) use ( &$lines, $stamp ) {
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTAMP:' . $stamp;
			$lines[] = 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $start );
			$lines[] = 'DTEND;VALUE=DATE:' . str_replace( '-', '', $end );
			$lines[] = 'SUMMARY:' . self::escape( $summary );
			$lines[] = 'TRANSP:OPAQUE';
			$lines[] = 'END:VEVENT';
		};

		$table    = Flexo_Booking_Install::table();
		$occupied = Flexo_Booking_Inventory::occupying_sql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$bookings = $wpdb->get_results( $wpdb->prepare( "SELECT reference, check_in, check_out, status FROM {$table} WHERE room_id = %d AND check_out >= %s AND {$occupied['sql']} ORDER BY check_in", array_merge( array( $room_id, $from ), $occupied['params'] ) ) );
		foreach ( $bookings as $booking ) {
			$add( 'flexo-' . strtolower( $booking->reference ) . '@' . $host, $booking->check_in, $booking->check_out, 'blocked' === $booking->status ? 'Not available' : 'Booked' );
		}

		foreach ( Flexo_Booking_Closures::all() as $closure ) {
			if ( ( 0 === $closure['room_id'] || (int) $room_id === $closure['room_id'] ) && $closure['date_to'] >= $today ) {
				$add( 'flexo-closed-' . $closure['id'] . '@' . $host, $closure['date_from'], Flexo_Booking_Dates::add_days( $closure['date_to'], 1 ), 'Closed' );
			}
		}

		// Bookings from the other connected calendars, so each booking website
		// also learns about the others.
		if ( self::enabled() ) {
			foreach ( self::events_for_room( $room_id, $from, '9999-12-31' ) as $event ) {
				$add( 'flexo-ext-' . $event['id'] . '@' . $host, $event['date_from'], $event['date_to'], 'Not available' );
			}
		}

		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	private static function escape( $text ) {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n' ), (string) $text );
	}

	/**
	 * Lines longer than 75 octets are folded (RFC 5545 §3.1).
	 */
	private static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$limit = 75;
		while ( strlen( $line ) > $limit ) {
			$cut = $limit;
			// Don't split a multibyte UTF-8 character.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out  .= substr( $line, 0, $cut ) . "\r\n ";
			$line  = substr( $line, $cut );
			$limit = 74; // Continuation lines start with a space.
		}
		return $out . $line;
	}
}
