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
		add_action( 'admin_menu', array( __CLASS__, 'menu_order' ), 999 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ), 10, 2 );
		add_action( 'load-toplevel_page_' . self::MENU_SLUG, array( __CLASS__, 'old_list_urls' ) );
		add_action( 'admin_notices', array( __CLASS__, 'rooms_nav' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'menu_css' ) );
		add_action( 'admin_post_flexo_booking_note', array( __CLASS__, 'handle_note' ) );
		add_action( 'admin_post_flexo_booking_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_flexo_booking_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_flexo_booking_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_flexo_booking_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_flexo_booking_csv', array( __CLASS__, 'handle_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FLEXO_BOOKING_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Capability required to see and manage bookings: Hotel Staff, Hotel
	 * Manager, editors and administrators (see Flexo_Booking_Roles).
	 */
	public static function capability() {
		return apply_filters( 'flexo_booking_manage_capability', 'flexo_manage_bookings' );
	}

	/**
	 * Capability for an area of the plugin: bookings (daily work), prices
	 * (rooms, seasons, rate plans, promotions, calendar sync), appearance /
	 * emails, settings.
	 */
	public static function cap( $area ) {
		switch ( $area ) {
			case 'bookings':
				return self::capability();
			case 'prices':
				return apply_filters( 'flexo_booking_prices_capability', 'flexo_manage_prices' );
			case 'appearance':
			case 'emails':
				return apply_filters( 'flexo_booking_emails_capability', 'flexo_manage_settings' );
			default:
				return 'manage_options';
		}
	}

	public static function menu() {
		// The badge counts everything under "Needs your attention" on Today.
		$count  = current_user_can( self::capability() ) ? Flexo_Booking_Today_Admin::attention_count() : 0;
		$bubble = $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>' : '';

		add_menu_page( __( 'Bookings', 'flexo-booking' ), __( 'Bookings', 'flexo-booking' ) . $bubble, self::capability(), self::MENU_SLUG, array( __CLASS__, 'render_main' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::MENU_SLUG, __( 'Today', 'flexo-booking' ), __( 'Today', 'flexo-booking' ), self::capability(), self::MENU_SLUG, array( __CLASS__, 'render_main' ) );
		add_submenu_page( self::MENU_SLUG, __( 'All bookings', 'flexo-booking' ), __( 'All bookings', 'flexo-booking' ), self::capability(), self::MENU_SLUG . '-list', array( __CLASS__, 'render_list' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Add booking', 'flexo-booking' ), __( 'Add booking', 'flexo-booking' ), self::capability(), self::MENU_SLUG . '-new', array( __CLASS__, 'render_add' ) );
		// Hidden entry, so WordPress lets Hotel Managers open "Add room" (not only users who can write posts).
		add_submenu_page( self::MENU_SLUG, __( 'Add room', 'flexo-booking' ), __( 'Add room', 'flexo-booking' ), 'edit_flexo_rooms', 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE );
	}

	public static function menu_late() {
		add_submenu_page( self::MENU_SLUG, __( 'Guest emails', 'flexo-booking' ), __( 'Emails', 'flexo-booking' ), self::cap( 'emails' ), self::MENU_SLUG . '-emails', array( 'Flexo_Booking_Settings', 'render_emails_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Booking settings', 'flexo-booking' ), __( 'Settings', 'flexo-booking' ), 'manage_options', self::MENU_SLUG . '-settings', array( 'Flexo_Booking_Settings', 'render_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Import & export', 'flexo-booking' ), __( 'Import & export', 'flexo-booking' ), 'manage_options', self::MENU_SLUG . '-tools', array( 'Flexo_Booking_Portability', 'render_page' ) );
	}

	/**
	 * Pages reached through the tabs of "Rooms & prices" or "Settings"
	 * rather than the menu. They keep their addresses.
	 */
	public static function hidden_pages() {
		return array(
			'flexo-booking-seasons'   => 'rooms',
			'flexo-booking-closures'  => 'rooms',
			'flexo-booking-rate-plans' => 'rooms',
			'flexo-booking-sync'      => 'rooms',
			self::MENU_SLUG . '-tools' => 'settings',
			'flexo-booking-wizard'    => 'settings',
		);
	}

	/**
	 * Menu in the order of a hotel's day: Today · Calendar · All bookings ·
	 * Add booking · Rooms & prices · Promotions · Emails · Appearance ·
	 * Settings · Help · Agency.
	 */
	public static function menu_order() {
		global $submenu;
		if ( empty( $submenu[ self::MENU_SLUG ] ) ) {
			return;
		}
		$order = array( self::MENU_SLUG, Flexo_Booking_Calendar_Admin::SLUG, self::MENU_SLUG . '-list', self::MENU_SLUG . '-new', 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE, 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE, 'flexo-booking-promo-codes', self::MENU_SLUG . '-emails', 'flexo-booking-appearance', self::MENU_SLUG . '-settings', self::MENU_SLUG . '-help', Flexo_Booking_Features::AGENCY_SLUG );
		$items  = array();
		$others = array();
		foreach ( $submenu[ self::MENU_SLUG ] as $item ) {
			// Kept registered (WordPress checks access through the menu), only not shown.
			if ( isset( self::hidden_pages()[ $item[2] ] ) || 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE === $item[2] ) {
				$item[4]  = trim( ( isset( $item[4] ) ? $item[4] : '' ) . ' flexo-menu-hidden' );
				$others[] = $item;
				continue;
			}
			$pos = array_search( $item[2], $order, true );
			if ( false === $pos ) {
				$others[] = $item;
			} else {
				$items[ $pos ] = $item;
			}
		}
		ksort( $items );
		$submenu[ self::MENU_SLUG ] = array_merge( array_values( $items ), $others ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public static function menu_css() {
		echo '<style>#adminmenu .flexo-menu-hidden{display:none}</style>';
	}

	/**
	 * Tabs pages and the room editor highlight their menu entry.
	 */
	public static function parent_file( $parent ) {
		global $plugin_page;
		if ( $plugin_page && isset( self::hidden_pages()[ $plugin_page ] ) ) {
			return self::MENU_SLUG;
		}
		return $parent;
	}

	public static function submenu_file( $file, $parent ) {
		global $plugin_page;
		$hidden = self::hidden_pages();
		if ( $plugin_page && isset( $hidden[ $plugin_page ] ) ) {
			return 'rooms' === $hidden[ $plugin_page ] ? 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE : self::MENU_SLUG . '-settings';
		}
		if ( 'post-new.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE === $file ) {
			return 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE;
		}
		return $file;
	}

	/**
	 * Tabs of "Rooms & prices" (only what is switched on and allowed).
	 */
	public static function rooms_tabs() {
		$tabs = array();
		if ( current_user_can( self::cap( 'prices' ) ) ) {
			$tabs['rooms'] = array( __( 'Rooms', 'flexo-booking' ), admin_url( 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE ) );
			if ( Flexo_Booking_Seasons::enabled() ) {
				$tabs['flexo-booking-seasons'] = array( __( 'Seasonal prices', 'flexo-booking' ), admin_url( 'admin.php?page=flexo-booking-seasons' ) );
			}
			$tabs['flexo-booking-closures'] = array( __( 'Closed dates', 'flexo-booking' ), admin_url( 'admin.php?page=flexo-booking-closures' ) );
			if ( Flexo_Booking_Rate_Plans::enabled() ) {
				$tabs['flexo-booking-rate-plans'] = array( __( 'Rates', 'flexo-booking' ), admin_url( 'admin.php?page=flexo-booking-rate-plans' ) );
			}
			if ( Flexo_Booking_ICal::enabled() ) {
				$tabs['flexo-booking-sync'] = array( __( 'Calendar sync', 'flexo-booking' ), admin_url( 'admin.php?page=flexo-booking-sync' ) );
			}
		}
		return $tabs;
	}

	/**
	 * Tab bar for a section ("rooms" or "settings").
	 */
	public static function section_nav( $section, $current ) {
		$tabs = 'rooms' === $section ? self::rooms_tabs() : Flexo_Booking_Settings::nav_tabs();
		if ( count( $tabs ) < 2 ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper flexo-section-nav" aria-label="' . esc_attr( 'rooms' === $section ? __( 'Rooms & prices', 'flexo-booking' ) : __( 'Settings', 'flexo-booking' ) ) . '">';
		foreach ( $tabs as $key => $tab ) {
			echo '<a href="' . esc_url( $tab[1] ) . '" class="nav-tab' . ( $key === $current ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $tab[0] ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * The tab bar above the room list and editor.
	 */
	public static function rooms_nav() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Flexo_Booking_Rooms::POST_TYPE === $screen->post_type && 'edit' === $screen->base ) {
			echo '<div class="flexo-admin flexo-section-nav-wrap">';
			self::section_nav( 'rooms', 'rooms' );
			echo '</div>';
		}
	}

	/**
	 * The bookings list used to be the first page: its addresses (search,
	 * filters, pages) still work and open the list.
	 */
	public static function old_list_urls() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect.
		if ( isset( $_GET['booking'] ) ) {
			return;
		}
		$keys = array( 's', 'status', 'room_id', 'upcoming', 'paged', 'view' );
		$args = array();
		foreach ( $keys as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		if ( $args ) {
			wp_safe_redirect( add_query_arg( $args, self::list_url() ) );
			exit;
		}
	}

	public static function list_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG . '-list' ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The first page: a booking's details (old links from emails), else Today.
	 */
	public static function render_main() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$booking_id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		if ( $booking_id ) {
			if ( current_user_can( self::capability() ) ) {
				self::render_booking( $booking_id );
			}
			return;
		}
		Flexo_Booking_Today_Admin::render();
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, self::MENU_SLUG ) || ( function_exists( 'get_current_screen' ) && get_current_screen() && Flexo_Booking_Rooms::POST_TYPE === get_current_screen()->post_type ) ) {
			wp_enqueue_style( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/css/admin.css', array(), FLEXO_BOOKING_VERSION );
			wp_enqueue_script( 'flexo-booking-admin', FLEXO_BOOKING_URL . 'assets/js/admin-bookings.js', array(), FLEXO_BOOKING_VERSION, true );
			wp_localize_script(
				'flexo-booking-admin',
				'FlexoAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'flexo_booking_wizard' ),
					'i18n'    => array(
						'areYouSure'  => __( 'Please confirm', 'flexo-booking' ),
						'goBack'      => __( 'Go back', 'flexo-booking' ),
						'copied'      => __( 'Copied', 'flexo-booking' ),
						'openBooking' => __( 'Open it', 'flexo-booking' ),
					),
				)
			);
		}
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) ) . '">' . esc_html__( 'Settings', 'flexo-booking' ) . '</a>'
		);
		return $links;
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_url( $action, $id, $extra = array() ) {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => $action, 'id' => $id ), $extra ), admin_url( 'admin-post.php' ) ),
			$action . '_' . $id
		);
	}

	private static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		return array(
			'status'         => isset( $_GET['status'] ) && array_key_exists( sanitize_key( $_GET['status'] ), Flexo_Booking_Bookings::statuses() ) ? sanitize_key( $_GET['status'] ) : '',
			'room_id'        => isset( $_GET['room_id'] ) ? absint( $_GET['room_id'] ) : 0,
			'search'         => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'from'           => ! empty( $_GET['upcoming'] ) ? wp_date( 'Y-m-d' ) : '',
			// Day 6: payment status, where the booking came from, arrival between two dates.
			'payment_status' => isset( $_GET['payment_status'] ) && array_key_exists( sanitize_key( $_GET['payment_status'] ), self::payment_statuses() ) ? sanitize_key( $_GET['payment_status'] ) : '',
			'source'         => isset( $_GET['source'] ) && in_array( $_GET['source'], array( 'website', 'admin' ), true ) ? sanitize_key( $_GET['source'] ) : '',
			'arrival_from'   => isset( $_GET['arrival_from'] ) ? (string) Flexo_Booking_Dates::parse( sanitize_text_field( wp_unslash( $_GET['arrival_from'] ) ) ) : '',
			'arrival_to'     => isset( $_GET['arrival_to'] ) ? (string) Flexo_Booking_Dates::parse( sanitize_text_field( wp_unslash( $_GET['arrival_to'] ) ) ) : '',
		);
		// phpcs:enable
	}

	public static function notices() {
		self::notice();
	}

	/**
	 * Payment states for the list filter.
	 */
	public static function payment_statuses() {
		return array(
			'unpaid'   => __( 'Not paid yet', 'flexo-booking' ),
			'deposit_paid' => __( 'Deposit received', 'flexo-booking' ),
			'paid'     => __( 'Payment received', 'flexo-booking' ),
			'failed'   => __( 'Payment failed', 'flexo-booking' ),
			'refunded' => __( 'Refunded', 'flexo-booking' ),
			'property' => __( 'Paid on site', 'flexo-booking' ),
		);
	}

	public static function restore_message( array $b ) {
		/* translators: %s: booking reference */
		$text = sprintf( __( 'Restore booking %s as confirmed? The dates are taken again (if still free).', 'flexo-booking' ), $b['reference'] );
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) && '' !== $b['guest_email'] ) {
			$text .= ' ' . __( 'The guest receives a confirmation email.', 'flexo-booking' );
		}
		return $text;
	}

	public static function delete_message( array $b ) {
		/* translators: %s: booking reference */
		return sprintf( __( 'Delete booking %s for good? It disappears from the list, the calendar and reports, and cannot be restored. No email is sent. To keep a record, cancel it instead.', 'flexo-booking' ), $b['reference'] );
	}

	/**
	 * What cancelling does, said before it happens.
	 */
	public static function cancel_message( array $b ) {
		/* translators: %s: booking reference */
		$text = sprintf( __( 'Cancel booking %s?', 'flexo-booking' ), $b['reference'] );
		if ( Flexo_Booking_Features::is_enabled( 'guest_emails' ) && '' !== $b['guest_email'] ) {
			$text .= ' ' . __( 'The guest receives a cancellation email.', 'flexo-booking' );
		}
		$net = isset( $b['amount_paid'] ) ? (float) $b['amount_paid'] - (float) $b['amount_refunded'] : 0;
		if ( $net > 0 ) {
			/* translators: %s: amount */
			$text .= ' ' . sprintf( __( 'The %s already paid is not refunded automatically – refund it yourself if needed.', 'flexo-booking' ), Flexo_Booking_Money::format( $net, $b['currency'] ) );
		}
		return $text . ' ' . __( 'The dates become free for other guests.', 'flexo-booking' );
	}

	private static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code     = isset( $_GET['flexo_msg'] ) ? sanitize_key( $_GET['flexo_msg'] ) : '';
		$messages = array(
			'updated' => array( 'success', __( 'Booking updated.', 'flexo-booking' ) ),
			'deleted' => array( 'success', __( 'Booking deleted.', 'flexo-booking' ) ),
			'added'   => array( 'success', __( 'Booking added.', 'flexo-booking' ) ),
			'anonymised' => array( 'success', __( 'The guest\'s personal data was removed from this booking.', 'flexo-booking' ) ),
			'payment'    => array( 'success', __( 'Payment recorded.', 'flexo-booking' ) ),
			'handled'    => array( 'success', __( 'Marked as answered.', 'flexo-booking' ) ),
			'note'       => array( 'success', __( 'Note saved.', 'flexo-booking' ) ),
			'resent'     => array( 'success', __( 'Email sent again.', 'flexo-booking' ) ),
			'setup_done' => array( 'success', __( 'Setup finished – your booking system is ready.', 'flexo-booking' ) ),
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

		// Holds whose time is up are released before the list is shown.
		if ( Flexo_Booking_Schema::column_exists( 'bookings', 'hold_expires_at' ) ) {
			Flexo_Booking_Payments::release_expired_holds();
		}
		$filters  = self::current_filters();
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = Flexo_Booking_Bookings::query( array_merge( $filters, array( 'per_page' => $per_page, 'page' => $paged, 'order' => 'upcoming' ) ) );
		$rooms  = Flexo_Booking_Rooms::all( 'any' );
		$format = get_option( 'date_format' );

		$csv_args = array_filter(
			array(
				'action'   => 'flexo_booking_csv',
				'status'   => $filters['status'],
				'room_id'  => $filters['room_id'],
				's'        => $filters['search'],
				'upcoming' => $filters['from'] ? 1 : 0,
				'payment_status' => $filters['payment_status'],
				'source'   => $filters['source'],
				'arrival_from' => $filters['arrival_from'],
				'arrival_to'   => $filters['arrival_to'],
			)
		);
		$filtered = $filters['status'] || $filters['room_id'] || $filters['search'] || $filters['from'] || $filters['payment_status'] || $filters['source'] || $filters['arrival_from'] || $filters['arrival_to'];
		?>
		<div class="wrap flexo-admin flexo-bookings-page">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'All bookings', 'flexo-booking' ); ?></h1>
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

			<?php Flexo_Booking_Payments_Admin::render_conflicts(); ?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$external_view = Flexo_Booking_ICal::enabled() && isset( $_GET['view'] ) && 'external' === $_GET['view'];
			if ( Flexo_Booking_ICal::enabled() ) :
				?>
				<ul class="subsubsub flexo-views">
					<li><a href="<?php echo esc_url( self::list_url() ); ?>" class="<?php echo $external_view ? '' : 'current'; ?>"><?php esc_html_e( 'Bookings on this website', 'flexo-booking' ); ?></a> |</li>
					<li><a href="<?php echo esc_url( self::list_url( array( 'view' => 'external' ) ) ); ?>" class="<?php echo $external_view ? 'current' : ''; ?>">⇄ <?php esc_html_e( 'From external calendars', 'flexo-booking' ); ?></a></li>
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
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG . '-list' ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Reference, name, email or phone', 'flexo-booking' ); ?>" aria-label="<?php esc_attr_e( 'Search bookings', 'flexo-booking' ); ?>">
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
				<?php if ( Flexo_Booking_Payments::enabled() ) : ?>
					<select name="payment_status" aria-label="<?php esc_attr_e( 'Payment', 'flexo-booking' ); ?>">
						<option value=""><?php esc_html_e( 'Any payment', 'flexo-booking' ); ?></option>
						<?php foreach ( self::payment_statuses() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filters['payment_status'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<select name="source" aria-label="<?php esc_attr_e( 'Booked through', 'flexo-booking' ); ?>">
					<option value=""><?php esc_html_e( 'Website and staff', 'flexo-booking' ); ?></option>
					<option value="website" <?php selected( $filters['source'], 'website' ); ?>><?php esc_html_e( 'Website', 'flexo-booking' ); ?></option>
					<option value="admin" <?php selected( $filters['source'], 'admin' ); ?>><?php esc_html_e( 'Added by staff', 'flexo-booking' ); ?></option>
				</select>
				<span class="flexo-filters__dates">
					<label for="flexo-arrival-from"><?php esc_html_e( 'Arrival from', 'flexo-booking' ); ?></label>
					<input id="flexo-arrival-from" type="date" name="arrival_from" value="<?php echo esc_attr( $filters['arrival_from'] ); ?>">
					<label for="flexo-arrival-to"><?php esc_html_e( 'to', 'flexo-booking' ); ?></label>
					<input id="flexo-arrival-to" type="date" name="arrival_to" value="<?php echo esc_attr( $filters['arrival_to'] ); ?>">
				</span>
				<label><input type="checkbox" name="upcoming" value="1" <?php checked( (bool) $filters['from'] ); ?>> <?php esc_html_e( 'Current & upcoming only', 'flexo-booking' ); ?></label>
				<?php submit_button( __( 'Filter', 'flexo-booking' ), 'secondary', '', false ); ?>
				<?php if ( $filtered ) : ?>
					<a class="button-link" href="<?php echo esc_url( self::list_url() ); ?>"><?php esc_html_e( 'Clear filters', 'flexo-booking' ); ?></a>
				<?php endif; ?>
			</form>
			<p class="flexo-muted flexo-results-count">
				<?php
				/* translators: %d: number of bookings */
				echo esc_html( sprintf( _n( '%d booking', '%d bookings', $result['total'], 'flexo-booking' ), $result['total'] ) );
				?>
			</p>

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
					<tr><td colspan="8" class="flexo-empty-row">
						<?php if ( $filtered ) : ?>
							<?php esc_html_e( 'No bookings match these filters.', 'flexo-booking' ); ?>
							<a href="<?php echo esc_url( self::list_url() ); ?>"><?php esc_html_e( 'Show all bookings', 'flexo-booking' ); ?></a>
						<?php else : ?>
							<?php esc_html_e( 'No bookings yet. Bookings from your website appear here; phone and walk-in bookings can be added with "Add booking".', 'flexo-booking' ); ?>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<?php foreach ( $result['items'] as $b ) : ?>
					<tr class="flexo-booking-row flexo-booking-row--<?php echo esc_attr( $b['status'] ); ?>">
						<td data-colname="<?php esc_attr_e( 'Reference', 'flexo-booking' ); ?>">
							<strong><a href="<?php echo esc_url( self::page_url( array( 'booking' => $b['id'] ) ) ); ?>"><?php echo esc_html( $b['reference'] ); ?></a></strong>
							<div>
								<?php echo self::source_badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in source_badge(). ?>
								<?php if ( isset( $invoices[ $b['id'] ] ) ) : ?>
									<span class="flexo-badge flexo-badge--invoice">🧾 <?php esc_html_e( 'Invoice', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( $b['anonymized_at'] ) : ?>
									<span class="flexo-badge"><?php esc_html_e( 'Personal data removed', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<?php if ( isset( $conflicted[ $b['id'] ] ) ) : ?>
									<a class="flexo-badge flexo-badge--conflict" href="#flexo-conflicts">⚠ <?php esc_html_e( 'Conflict', 'flexo-booking' ); ?></a>
								<?php endif; ?>
							</div>
							<div class="flexo-muted"><?php echo esc_html( mysql2date( $format . ' H:i', $b['created_at'] ) ); ?></div>
						</td>
						<td data-colname="<?php esc_attr_e( 'Guest', 'flexo-booking' ); ?>">
							<?php echo esc_html( $b['guest_name'] ? $b['guest_name'] : ( $b['anonymized_at'] ? __( 'Guest (personal data removed)', 'flexo-booking' ) : '—' ) ); ?>
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
						<td data-colname="<?php esc_attr_e( 'Room', 'flexo-booking' ); ?>">
							<?php echo esc_html( $b['room_title'] ); ?>
							<?php $flexo_plan = self::rate_plan_name( $b ); ?>
							<?php if ( $flexo_plan ) : ?>
								<div class="flexo-muted"><?php echo esc_html( $flexo_plan ); ?></div>
							<?php endif; ?>
							<?php if ( $b['promo_code'] ) : ?>
								<div><span class="flexo-badge flexo-badge--promo">% <?php echo esc_html( $b['promo_code'] ); ?></span></div>
							<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Stay', 'flexo-booking' ); ?>">
							<?php echo esc_html( mysql2date( $format, $b['check_in'] ) . ' → ' . mysql2date( $format, $b['check_out'] ) ); ?>
							<div class="flexo-muted">
								<?php
								/* translators: %d: number of nights */
								echo esc_html( sprintf( _n( '%d night', '%d nights', $b['nights'], 'flexo-booking' ), $b['nights'] ) );
								?>
							</div>
						</td>
						<td data-colname="<?php esc_attr_e( 'Guests', 'flexo-booking' ); ?>">
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
						<td data-colname="<?php esc_attr_e( 'Total', 'flexo-booking' ); ?>">
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
						<td data-colname="<?php esc_attr_e( 'Status', 'flexo-booking' ); ?>">
							<span class="flexo-status flexo-status--<?php echo esc_attr( $b['status'] ); ?>"><?php echo esc_html( Flexo_Booking_Bookings::status_label( $b['status'] ) ); ?></span>
							<?php $flexo_badge = Flexo_Booking_Payments_Admin::badge( $b ); ?>
							<?php if ( $flexo_badge ) : ?>
								<div><?php echo $flexo_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?></div>
							<?php endif; ?>
						</td>
						<td class="flexo-actions" data-colname="<?php esc_attr_e( 'Actions', 'flexo-booking' ); ?>">
							<?php if ( 'pending' === $b['status'] ) : ?>
								<a class="button button-primary button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php echo esc_html( self::confirm_label( $b ) ); ?></a>
							<?php endif; ?>
							<?php if ( 'awaiting_payment' === $b['status'] ) : ?>
								<a class="button button-primary button-small" href="<?php echo esc_url( self::page_url( array( 'booking' => $b['id'] ) ) . '#flexo-payments' ); ?>"><?php esc_html_e( 'Payment received', 'flexo-booking' ); ?></a>
							<?php endif; ?>
							<?php if ( in_array( $b['status'], array( 'cancelled', 'expired' ), true ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>" data-flexo-confirm="<?php echo esc_attr( self::restore_message( $b ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Restore booking', 'flexo-booking' ); ?>"><?php esc_html_e( 'Restore booking', 'flexo-booking' ); ?></a>
							<?php elseif ( 'blocked' !== $b['status'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ) ); ?>" data-flexo-confirm="<?php echo esc_attr( self::cancel_message( $b ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Cancel booking', 'flexo-booking' ); ?>"><?php esc_html_e( 'Cancel', 'flexo-booking' ); ?></a>
							<?php endif; ?>
							<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'flexo_booking_delete', $b['id'] ) ); ?>" data-flexo-confirm="<?php echo esc_attr( self::delete_message( $b ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Delete for good', 'flexo-booking' ); ?>"><?php esc_html_e( 'Delete', 'flexo-booking' ); ?></a>
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
			<p><a href="<?php echo esc_url( self::list_url() ); ?>">← <?php esc_html_e( 'All bookings', 'flexo-booking' ); ?></a> · <a href="<?php echo esc_url( Flexo_Booking_Today_Admin::url() ); ?>"><?php esc_html_e( 'Today', 'flexo-booking' ); ?></a></p>
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
				<?php echo Flexo_Booking_Payments_Admin::badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge(). ?>
			</h1>
			<?php self::notice(); ?>
			<div class="flexo-actionbar flexo-actions" role="group" aria-label="<?php esc_attr_e( 'Actions', 'flexo-booking' ); ?>">
				<?php if ( 'pending' === $b['status'] ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>"><?php echo esc_html( self::confirm_label( $b ) ); ?></a>
				<?php endif; ?>
				<?php if ( 'awaiting_payment' === $b['status'] || ( 'pending' === $b['status'] && 'bank_transfer' === $b['payment_method'] ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed', 'force' => 1 ) ) ); ?>" data-flexo-confirm="<?php echo esc_attr( __( 'Confirm this booking without waiting for the payment? The guest receives a confirmation email; the amount due stays open.', 'flexo-booking' ) ); ?>"><?php esc_html_e( 'Confirm without payment', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<?php if ( in_array( $b['status'], array( 'cancelled', 'expired' ), true ) ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'confirmed' ) ) ); ?>" data-flexo-confirm="<?php echo esc_attr( self::restore_message( $b ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Restore booking', 'flexo-booking' ); ?>"><?php esc_html_e( 'Restore booking', 'flexo-booking' ); ?></a>
				<?php elseif ( 'blocked' !== $b['status'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::action_url( 'flexo_booking_status', $b['id'], array( 'status' => 'cancelled' ) ) ); ?>" data-flexo-confirm="<?php echo esc_attr( self::cancel_message( $b ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Cancel booking', 'flexo-booking' ); ?>"><?php esc_html_e( 'Cancel booking', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<?php if ( 'awaiting_payment' === $b['status'] || ( $b['amount_due'] > 0 && $b['amount_paid'] < $b['amount_due'] && ! in_array( $b['status'], array( 'cancelled', 'expired', 'blocked' ), true ) ) ) : ?>
					<a class="button" href="#flexo-payments"><?php esc_html_e( 'Payment received', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<a class="button" href="#flexo-staff-notes"><?php esc_html_e( 'Add a note', 'flexo-booking' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Calendar_Admin::SLUG . '&month=' . substr( $b['check_in'], 0, 7 ) ) ); ?>"><?php esc_html_e( 'Show in calendar', 'flexo-booking' ); ?></a>
			</div>
			<p><?php echo self::source_badge( $b ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in source_badge(). ?></p>
			<?php self::render_open_requests( $b ); ?>

			<div class="flexo-booking-view__grid">
				<div class="flexo-tools-card">
					<h2><?php esc_html_e( 'Stay', 'flexo-booking' ); ?></h2>
					<table class="form-table flexo-detail-table" role="presentation">
						<tr><th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th><td><?php echo esc_html( $b['room_title'] ); ?></td></tr>
						<?php if ( $view['rate_plan'] ) : ?>
							<tr><th><?php esc_html_e( 'Rate', 'flexo-booking' ); ?></th><td>
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
						<tr><th><?php esc_html_e( 'Name', 'flexo-booking' ); ?></th><td><?php echo esc_html( $b['guest_name'] ? $b['guest_name'] : ( $b['anonymized_at'] ? __( 'Guest (personal data removed)', 'flexo-booking' ) : '—' ) ); ?></td></tr>
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
						<p><a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_anonymise&id=' . $b['id'] ), 'flexo_booking_anonymise_' . $b['id'] ) ); ?>" data-flexo-confirm="<?php echo esc_attr( __( 'Remove this guest\'s name, email, phone, special requests, messages and invoice details from the booking? Dates, room and price stay. This cannot be undone.', 'flexo-booking' ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Remove personal data', 'flexo-booking' ); ?>"><?php esc_html_e( 'Remove personal data', 'flexo-booking' ); ?></a></p>
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

				<?php Flexo_Booking_Payments_Admin::render_booking_card( $b ); ?>
			</div>

			<?php $emails = Flexo_Booking_Emails::log_entries( array( 'booking_id' => $b['id'], 'limit' => 20 ) ); ?>
			<?php if ( $emails || self::resend_types( $b ) ) : ?>
				<div class="flexo-tools-card flexo-booking-emails" id="flexo-emails">
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
					<?php self::render_resend( $b ); ?>
				</div>
			<?php endif; ?>

			<?php self::render_staff_card( $b ); ?>
			<?php self::render_history( $b ); ?>
		</div>
		<?php
	}

	/**
	 * Open change / cancellation requests from the guest booking page.
	 */
	private static function render_open_requests( array $b ) {
		$types = Flexo_Booking_Guest::request_types();
		foreach ( Flexo_Booking_Guest::requests( $b ) as $entry ) {
			if ( empty( $entry['details']['status'] ) || 'open' !== $entry['details']['status'] ) {
				continue;
			}
			?>
			<div class="notice notice-warning inline flexo-guest-request">
				<p>
					<strong>
						<?php
						/* translators: %s: e.g. "Cancel my booking" */
						echo esc_html( sprintf( __( 'The guest asks: "%s"', 'flexo-booking' ), isset( $types[ $entry['details']['type'] ] ) ? $types[ $entry['details']['type'] ] : '' ) );
						?>
					</strong>
					<span class="flexo-muted">· <?php echo esc_html( mysql2date( 'd.m.Y H:i', $entry['created_at'] ) ); ?></span>
				</p>
				<?php if ( '' !== (string) $entry['details']['message'] ) : ?>
					<p><?php echo esc_html( $entry['details']['message'] ); ?></p>
				<?php endif; ?>
				<p>
					<?php esc_html_e( 'Nothing has changed yet. Reply to the guest, change or cancel the booking yourself if you agree, then mark the request as answered.', 'flexo-booking' ); ?>
					<?php if ( $b['guest_email'] ) : ?>
						<a class="button button-small" href="mailto:<?php echo esc_attr( $b['guest_email'] ); ?>?subject=<?php echo rawurlencode( $b['reference'] ); ?>"><?php esc_html_e( 'Reply by email', 'flexo-booking' ); ?></a>
					<?php endif; ?>
					<a class="button button-small button-primary" href="<?php echo esc_url( Flexo_Booking_Today_Admin::handled_url( $entry['id'] ) ); ?>"><?php esc_html_e( 'Mark as answered', 'flexo-booking' ); ?></a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Internal notes for the team – never shown to the guest.
	 */
	private static function render_staff_card( array $b ) {
		?>
		<div class="flexo-tools-card flexo-staff-notes" id="flexo-staff-notes">
			<h2><?php esc_html_e( 'Internal notes', 'flexo-booking' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Only your team sees these notes – never the guest. E.g. "arrives late, key at the bar".', 'flexo-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="flexo_booking_note">
				<input type="hidden" name="id" value="<?php echo esc_attr( $b['id'] ); ?>">
				<?php wp_nonce_field( 'flexo_booking_note_' . $b['id'] ); ?>
				<label class="screen-reader-text" for="flexo-staff-note"><?php esc_html_e( 'Internal notes', 'flexo-booking' ); ?></label>
				<textarea id="flexo-staff-note" name="staff_notes" rows="3" class="large-text"><?php echo esc_textarea( $b['staff_notes'] ); ?></textarea>
				<p><button type="submit" class="button"><?php esc_html_e( 'Save note', 'flexo-booking' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Who did what and when, with the emails sent.
	 */
	private static function render_history( array $b ) {
		$rows = array();
		foreach ( Flexo_Booking_Log::for_booking( $b['id'] ) as $entry ) {
			$user   = $entry['user_id'] ? get_userdata( $entry['user_id'] ) : null;
			$rows[] = array(
				'time' => $entry['created_at'],
				'text' => Flexo_Booking_Log::describe( $entry ),
				'who'  => $user ? $user->display_name : ( 'guest_request' === $entry['action'] || ( 'created' === $entry['action'] && 'website' === $b['source'] ) ? __( 'Guest', 'flexo-booking' ) : __( 'Automatic', 'flexo-booking' ) ),
			);
		}
		foreach ( Flexo_Booking_Emails::log_entries( array( 'booking_id' => $b['id'], 'limit' => 50 ) ) as $email ) {
			$rows[] = array(
				'time' => $email['created_at'],
				/* translators: %s: email name */
				'text' => sprintf( 'sent' === $email['status'] ? __( 'Email sent: %s', 'flexo-booking' ) : __( 'Email failed: %s', 'flexo-booking' ), Flexo_Booking_Emails::type_label( $email['email_type'] ) ),
				'who'  => __( 'Automatic', 'flexo-booking' ),
			);
		}
		usort(
			$rows,
			static function ( $x, $y ) {
				return strcmp( $y['time'], $x['time'] );
			}
		);
		?>
		<div class="flexo-tools-card flexo-history" id="flexo-history">
			<h2><?php esc_html_e( 'History', 'flexo-booking' ); ?></h2>
			<?php if ( ! $rows ) : ?>
				<p class="flexo-muted"><?php esc_html_e( 'Changes made from now on are listed here.', 'flexo-booking' ); ?></p>
			<?php else : ?>
				<ol class="flexo-history__list">
					<?php foreach ( $rows as $row ) : ?>
						<li><time datetime="<?php echo esc_attr( mysql2date( 'c', $row['time'] ) ); ?>"><?php echo esc_html( mysql2date( 'd.m.Y H:i', $row['time'] ) ); ?></time> <span><?php echo esc_html( $row['text'] ); ?></span> <span class="flexo-muted">– <?php echo esc_html( $row['who'] ); ?></span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Guest emails that make sense to send again for this booking.
	 *
	 * @return array type => label
	 */
	public static function resend_types( array $b ) {
		if ( ! Flexo_Booking_Features::is_enabled( 'guest_emails' ) || '' === $b['guest_email'] || ! empty( $b['anonymized_at'] ) || 'blocked' === $b['status'] ) {
			return array();
		}
		$types = array();
		$all   = Flexo_Booking_Emails::guest_types();
		switch ( $b['status'] ) {
			case 'pending':
				$types[] = 'request';
				break;
			case 'confirmed':
				$types[] = 'confirmed';
				if ( $b['amount_paid'] > 0 ) {
					$types[] = 'payment_received';
				}
				$types[] = 'pre_arrival';
				break;
			case 'awaiting_payment':
				$types[] = 'awaiting_deposit';
				$types[] = 'payment_reminder';
				break;
			case 'cancelled':
				$types[] = 'cancelled';
				break;
		}
		$out = array();
		foreach ( $types as $type ) {
			if ( isset( $all[ $type ] ) ) {
				$out[ $type ] = $all[ $type ]['label'];
			}
		}
		return $out;
	}

	private static function render_resend( array $b ) {
		$types = self::resend_types( $b );
		if ( ! $types ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-resend">
			<input type="hidden" name="action" value="flexo_booking_resend">
			<input type="hidden" name="id" value="<?php echo esc_attr( $b['id'] ); ?>">
			<?php wp_nonce_field( 'flexo_booking_resend_' . $b['id'] ); ?>
			<label for="flexo-resend-type"><?php esc_html_e( 'Send an email again', 'flexo-booking' ); ?></label>
			<select id="flexo-resend-type" name="type">
				<?php foreach ( $types as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php /* translators: %s: guest email address */ ?>
			<button type="submit" class="button" data-flexo-confirm="<?php echo esc_attr( sprintf( __( 'Send this email to %s now?', 'flexo-booking' ), $b['guest_email'] ) ); ?>" data-flexo-confirm-button="<?php esc_attr_e( 'Send email', 'flexo-booking' ); ?>"><?php esc_html_e( 'Send', 'flexo-booking' ); ?></button>
		</form>
		<?php
	}

	public static function handle_note() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'flexo_booking_note_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}
		global $wpdb;
		$note = isset( $_POST['staff_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['staff_notes'] ) ) : '';
		if ( Flexo_Booking_Bookings::get( $id ) ) {
			$wpdb->update( Flexo_Booking_Install::table(), array( 'staff_notes' => $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			Flexo_Booking_Log::add( $id, 'note', array( 'text' => mb_substr( $note, 0, 500 ) ) );
		}
		self::redirect( self::page_url( array( 'booking' => $id, 'flexo_msg' => 'note' ) ) . '#flexo-staff-notes' );
	}

	public static function handle_resend() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'flexo_booking_resend_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage bookings.', 'flexo-booking' ) );
		}
		$b    = Flexo_Booking_Bookings::get( $id );
		$type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		if ( ! $b || ! array_key_exists( $type, self::resend_types( $b ) ) ) {
			self::redirect( self::page_url( array( 'booking' => $id, 'flexo_error' => rawurlencode( __( 'This email can\'t be sent for this booking.', 'flexo-booking' ) ) ) ) );
		}
		$sent = Flexo_Booking_Emails::send_guest( $b, $type );
		Flexo_Booking_Log::add( $id, 'email_resent', array( 'type' => $type, 'sent' => (bool) $sent ) );
		self::redirect( self::page_url( $sent ? array( 'booking' => $id, 'flexo_msg' => 'resent' ) : array( 'booking' => $id, 'flexo_error' => rawurlencode( __( 'The email could not be sent. See the email log.', 'flexo-booking' ) ) ) ) . '#flexo-emails' );
	}

	/**
	 * "Confirm", or for a request paid by bank transfer "Confirm & ask for payment".
	 */
	public static function confirm_label( array $b ) {
		if ( 'bank_transfer' === $b['payment_method'] && $b['amount_due'] > $b['amount_paid'] && Flexo_Booking_Payments::gateway( 'bank_transfer' ) && Flexo_Booking_Payments::gateway( 'bank_transfer' )->is_available() ) {
			return __( 'Confirm & ask for payment', 'flexo-booking' );
		}
		return __( 'Confirm', 'flexo-booking' );
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
			<h1><?php esc_html_e( 'Add booking', 'flexo-booking' ); ?> <?php echo Flexo_Booking_Help::link( 'block' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link(). ?></h1>
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
							<th scope="row"><label for="fb-plan"><?php esc_html_e( 'Rate', 'flexo-booking' ); ?></label></th>
							<td>
								<?php
								// Each room lists only its own rates (the first one is chosen).
								$flexo_room_plans = array();
								foreach ( $rooms as $flexo_room ) {
									$flexo_room_plans[ $flexo_room->ID ] = array_map( 'intval', wp_list_pluck( Flexo_Booking_Rate_Plans::for_room( $flexo_room->ID ), 'id' ) );
								}
								?>
								<select id="fb-plan" name="rate_plan" data-room-plans="<?php echo esc_attr( wp_json_encode( $flexo_room_plans ) ); ?>">
									<option value="0"><?php esc_html_e( 'Normal price (no rate)', 'flexo-booking' ); ?></option>
									<?php foreach ( Flexo_Booking_Rate_Plans::all() as $flexo_plan ) : ?>
										<?php if ( $flexo_plan['active'] ) : ?>
											<option value="<?php echo esc_attr( $flexo_plan['id'] ); ?>"><?php echo esc_html( $flexo_plan['name'] ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Only the rates offered for the chosen room are listed. The price is calculated automatically.', 'flexo-booking' ); ?></p>
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

		$result = Flexo_Booking_Bookings::update_status( $id, $status, array( 'force' => ! empty( $_GET['force'] ) ) );
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
		// No rate chosen for a room that offers rates: its first rate.
		if ( empty( $data['rate_plan'] ) && Flexo_Booking_Rate_Plans::enabled() ) {
			$flexo_plans = Flexo_Booking_Rate_Plans::for_room( absint( $data['room'] ) );
			if ( $flexo_plans ) {
				$data['rate_plan'] = $flexo_plans[0]['id'];
			}
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
		fputcsv( $out, array( 'Reference', 'Status', 'Room', 'Check-in', 'Check-out', 'Nights', 'Adults', 'Children', 'Guest', 'Email', 'Phone', 'Notes', 'Total', 'Currency', 'Source', 'Created', 'Price details', 'Children ages', 'Rate plan', 'Refundable', 'Promo code', 'Discount', 'Tourist tax', 'Payable at property', 'Language', 'Privacy consent', 'Invoice', 'Invoice name / company', 'Company ID', 'VAT number', 'Invoice address', 'Contact person', 'Anonymised', 'Payment method', 'Payment status', 'Due when booking', 'Paid', 'Refunded', 'Balance', 'Payment deadline', 'Transaction IDs' ), ',', '"', '\\' );
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
			$pay  = Flexo_Booking_Payments::balance( $b );
			$txns = array();
			foreach ( Flexo_Booking_Payments::history( $b['id'] ) as $flexo_payment ) {
				if ( 'attempt' !== $flexo_payment['type'] && '' !== $flexo_payment['transaction_id'] ) {
					$txns[] = $flexo_payment['transaction_id'];
				}
			}
			$row = array_merge(
				$row,
				array(
					$b['payment_method'],
					$b['payment_status'],
					$b['amount_due'] ? $b['amount_due'] : '',
					$pay['paid'] ? $pay['paid'] : '',
					$pay['refunded'] ? $pay['refunded'] : '',
					'' !== $b['payment_method'] && 'blocked' !== $b['status'] ? $pay['outstanding'] : '',
					$b['payment_due_at'] ? $b['payment_due_at'] : '',
					implode( ' ', $txns ),
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
