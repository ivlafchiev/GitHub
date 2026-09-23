<?php
/**
 * Feature switches in two levels.
 *
 * AVAILABLE – decided by FlexoHotels, in this order:
 *   1. FLEXO_BOOKING_FEATURES constant in wp-config.php ('all', a comma list or an array);
 *   2. otherwise the Agency screen (option flexo_booking_available_features);
 *   3. otherwise every feature.
 *   The result passes through the `flexo_booking_available_features` filter –
 *   the hook a future licence/package module uses.
 *
 * ENABLED – chosen by the hotel admin on Settings → Features, only among the
 * available ones. Unavailable features never show in the hotel admin;
 * disabled ones never show to guests. Stored data is never deleted.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Features {

	const ENABLED_OPTION   = 'flexo_booking_enabled_features';
	const AVAILABLE_OPTION = 'flexo_booking_available_features';
	const AGENCY_SLUG      = 'flexo-booking-agency';

	/**
	 * @var array|null Per-request cache of the available list.
	 */
	private static $available = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'agency_menu' ), 12 );
		add_action( 'admin_post_flexo_booking_save_features', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_flexo_booking_save_agency', array( __CLASS__, 'handle_agency_save' ) );
	}

	/**
	 * Every feature of the 5-day plan. `ready` is false until its
	 * functionality ships; such features can be made available but not
	 * switched on yet.
	 */
	public static function definitions() {
		return array(
			'booking_request'  => array(
				'label'       => __( 'Booking requests', 'flexo-booking' ),
				'description' => __( 'Guests send a request and you confirm it.', 'flexo-booking' ),
				'group'       => 'booking',
				'ready'       => true,
			),
			'instant_booking'  => array(
				'label'       => __( 'Instant booking', 'flexo-booking' ),
				'description' => __( 'Bookings are confirmed immediately, without your approval.', 'flexo-booking' ),
				'group'       => 'booking',
				'ready'       => true,
			),
			'seasonal_pricing' => array(
				'label'       => __( 'Seasonal prices', 'flexo-booking' ),
				'description' => __( 'Different prices and minimum stays for high and low season.', 'flexo-booking' ),
				'group'       => 'prices',
				'ready'       => true,
			),
			'calendar_sync'    => array(
				'label'       => __( 'Calendar sync', 'flexo-booking' ),
				'description' => __( 'Keep availability in sync with Booking.com, Airbnb and others (iCal). Switching it off stops syncing and releases dates blocked by external calendars; your connections are kept.', 'flexo-booking' ),
				'group'       => 'booking',
				'ready'       => true,
			),
			'rate_plans'       => array(
				'label'       => __( 'Rate plans', 'flexo-booking' ),
				'description' => __( 'Offer options such as "Breakfast included" or "Non-refundable".', 'flexo-booking' ),
				'group'       => 'prices',
				'ready'       => false,
			),
			'children'         => array(
				'label'       => __( 'Children & ages', 'flexo-booking' ),
				'description' => __( 'Ask for children\'s ages and charge by age.', 'flexo-booking' ),
				'group'       => 'prices',
				'ready'       => false,
			),
			'tourist_tax'      => array(
				'label'       => __( 'Tourist tax', 'flexo-booking' ),
				'description' => __( 'Add the municipal tourist tax per person per night.', 'flexo-booking' ),
				'group'       => 'prices',
				'ready'       => false,
			),
			'promo_codes'      => array(
				'label'       => __( 'Promo codes', 'flexo-booking' ),
				'description' => __( 'Give discounts with codes such as SUMMER10.', 'flexo-booking' ),
				'group'       => 'prices',
				'ready'       => false,
			),
			'privacy_consent'  => array(
				'label'       => __( 'Privacy consent', 'flexo-booking' ),
				'description' => __( 'Ask guests to accept your privacy policy and remove old guest data automatically.', 'flexo-booking' ),
				'group'       => 'guests',
				'ready'       => false,
			),
			'invoice_request'  => array(
				'label'       => __( 'Invoice request', 'flexo-booking' ),
				'description' => __( 'Let guests ask for an invoice with company details.', 'flexo-booking' ),
				'group'       => 'guests',
				'ready'       => false,
			),
			'guest_emails'     => array(
				'label'       => __( 'Guest emails', 'flexo-booking' ),
				'description' => __( 'Email guests when their booking is received, confirmed or cancelled.', 'flexo-booking' ),
				'group'       => 'guests',
				'ready'       => true,
			),
			'tracking'         => array(
				'label'       => __( 'Conversion tracking', 'flexo-booking' ),
				'description' => __( 'Send booking events to Google Analytics, Tag Manager or Meta.', 'flexo-booking' ),
				'group'       => 'guests',
				'ready'       => false,
			),
			'online_payment'   => array(
				'label'       => __( 'Online card payment', 'flexo-booking' ),
				'description' => __( 'Guests pay by card when booking (Stripe).', 'flexo-booking' ),
				'group'       => 'payments',
				'ready'       => false,
			),
			'deposit'          => array(
				'label'        => __( 'Deposits', 'flexo-booking' ),
				'description'  => __( 'Take part of the price when booking and the rest at the property.', 'flexo-booking' ),
				'group'        => 'payments',
				'ready'        => false,
				'requires_any' => array( 'online_payment', 'bank_transfer' ),
			),
			'bank_transfer'    => array(
				'label'       => __( 'Bank transfer', 'flexo-booking' ),
				'description' => __( 'Guests pay a deposit or the full amount by bank transfer.', 'flexo-booking' ),
				'group'       => 'payments',
				'ready'       => false,
			),
		);
	}

	public static function groups() {
		return array(
			'booking'  => __( 'Bookings', 'flexo-booking' ),
			'prices'   => __( 'Prices', 'flexo-booking' ),
			'guests'   => __( 'Guests & communication', 'flexo-booking' ),
			'payments' => __( 'Payments', 'flexo-booking' ),
		);
	}

	public static function keys() {
		return array_keys( self::definitions() );
	}

	/**
	 * Enabled features for a new or upgraded site: today's behaviour.
	 */
	public static function default_enabled() {
		return array( 'booking_request', 'instant_booking', 'guest_emails' );
	}

	/**
	 * Parses 'all', a comma list or an array into feature keys.
	 */
	public static function parse_list( $value ) {
		if ( is_string( $value ) ) {
			if ( 'all' === strtolower( trim( $value ) ) ) {
				return self::keys();
			}
			$value = explode( ',', $value );
		}
		$value = array_map( 'sanitize_key', array_map( 'trim', (array) $value ) );
		return array_values( array_intersect( self::keys(), $value ) );
	}

	/**
	 * Where the available list comes from: 'constant', 'agency' or 'default'.
	 */
	public static function available_source() {
		if ( defined( 'FLEXO_BOOKING_FEATURES' ) ) {
			return 'constant';
		}
		return false !== get_option( self::AVAILABLE_OPTION, false ) ? 'agency' : 'default';
	}

	public static function available_list() {
		if ( null !== self::$available ) {
			return self::$available;
		}
		switch ( self::available_source() ) {
			case 'constant':
				$list = self::parse_list( FLEXO_BOOKING_FEATURES );
				break;
			case 'agency':
				$list = self::parse_list( get_option( self::AVAILABLE_OPTION, array() ) );
				break;
			default:
				$list = self::keys();
		}
		$list            = self::parse_list( apply_filters( 'flexo_booking_available_features', $list ) );
		self::$available = $list;
		return $list;
	}

	public static function is_available( $key ) {
		return in_array( $key, self::available_list(), true );
	}

	/**
	 * Raw enabled choices as stored (may include unavailable features, whose
	 * setting is kept for when they become available again).
	 */
	public static function stored_enabled() {
		$stored = get_option( self::ENABLED_OPTION, false );
		return false === $stored ? self::default_enabled() : self::parse_list( $stored );
	}

	/**
	 * Available, ready and switched on. The booking modes are a single
	 * choice, so they are enabled when they are the effective mode.
	 */
	public static function is_enabled( $key ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $key ] ) || ! self::is_available( $key ) || empty( $defs[ $key ]['ready'] ) ) {
			return false;
		}
		if ( 'booking_request' === $key ) {
			return 'request' === self::booking_mode();
		}
		if ( 'instant_booking' === $key ) {
			return 'instant' === self::booking_mode();
		}
		if ( ! in_array( $key, self::stored_enabled(), true ) ) {
			return false;
		}
		if ( ! empty( $defs[ $key ]['requires_any'] ) ) {
			foreach ( $defs[ $key ]['requires_any'] as $required ) {
				if ( self::is_enabled( $required ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Booking modes the hotel can choose from.
	 *
	 * @return string[] 'request' and/or 'instant'. Never empty: the core flow
	 *                  falls back to requests.
	 */
	public static function booking_modes() {
		$modes = array();
		if ( self::is_available( 'booking_request' ) ) {
			$modes[] = 'request';
		}
		if ( self::is_available( 'instant_booking' ) ) {
			$modes[] = 'instant';
		}
		return $modes ? $modes : array( 'request' );
	}

	/**
	 * The mode actually used for new website bookings.
	 */
	public static function booking_mode() {
		$modes  = self::booking_modes();
		$chosen = Flexo_Booking_Settings::get( 'booking_mode' );
		return in_array( $chosen, $modes, true ) ? $chosen : $modes[0];
	}

	public static function set_enabled( array $keys ) {
		update_option( self::ENABLED_OPTION, self::parse_list( $keys ) );
	}

	public static function set_available( $keys ) {
		if ( null === $keys ) {
			delete_option( self::AVAILABLE_OPTION );
		} else {
			update_option( self::AVAILABLE_OPTION, self::parse_list( $keys ), false );
		}
		self::reset_cache();
	}

	public static function reset_cache() {
		self::$available = null;
	}

	/**
	 * Agency users are listed in FLEXO_BOOKING_AGENCY_USERS (logins or
	 * emails, comma separated). Nobody else sees the Agency screen.
	 */
	public static function is_agency_user( $user = null ) {
		if ( ! defined( 'FLEXO_BOOKING_AGENCY_USERS' ) ) {
			return false;
		}
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			return false;
		}
		$allowed = array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', (string) FLEXO_BOOKING_AGENCY_USERS ) ) ) );
		return in_array( strtolower( $user->user_login ), $allowed, true ) || in_array( strtolower( $user->user_email ), $allowed, true );
	}

	/* ---------------------------------------------------------------------
	 * Hotel admin: Settings → Features tab
	 * ------------------------------------------------------------------- */

	public static function render_tab() {
		$defs    = self::definitions();
		$enabled = self::stored_enabled();
		$modes   = self::booking_modes();
		$mode    = self::booking_mode();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-features">
			<input type="hidden" name="action" value="flexo_booking_save_features">
			<?php wp_nonce_field( 'flexo_booking_save_features' ); ?>

			<p class="description"><?php esc_html_e( 'Switch on only what your property needs. Switching a feature off hides it from guests; your settings and existing bookings are kept.', 'flexo-booking' ); ?></p>

			<?php foreach ( self::groups() as $group => $group_label ) : ?>
				<?php
				$in_group = array();
				foreach ( $defs as $key => $def ) {
					if ( $def['group'] === $group && self::is_available( $key ) && ! in_array( $key, array( 'booking_request', 'instant_booking' ), true ) ) {
						$in_group[ $key ] = $def;
					}
				}
				if ( 'booking' !== $group && ! $in_group ) {
					continue;
				}
				?>
				<h2><?php echo esc_html( $group_label ); ?></h2>
				<table class="form-table" role="presentation">
					<?php if ( 'booking' === $group ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'How do guests book?', 'flexo-booking' ); ?></th>
							<td>
								<?php if ( in_array( 'request', $modes, true ) ) : ?>
									<label class="flexo-feature-choice"><input type="radio" name="booking_mode" value="request" <?php checked( $mode, 'request' ); ?>> <strong><?php echo esc_html( $defs['booking_request']['label'] ); ?></strong> – <?php echo esc_html( $defs['booking_request']['description'] ); ?></label>
								<?php endif; ?>
								<?php if ( in_array( 'instant', $modes, true ) ) : ?>
									<label class="flexo-feature-choice"><input type="radio" name="booking_mode" value="instant" <?php checked( $mode, 'instant' ); ?>> <strong><?php echo esc_html( $defs['instant_booking']['label'] ); ?></strong> – <?php echo esc_html( $defs['instant_booking']['description'] ); ?></label>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php foreach ( $in_group as $key => $def ) : ?>
						<tr class="<?php echo $def['ready'] ? '' : 'flexo-feature--soon'; ?>">
							<th scope="row"><?php echo esc_html( $def['label'] ); ?></th>
							<td>
								<?php if ( $def['ready'] ) : ?>
									<label><input type="checkbox" name="features[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $enabled, true ) ); ?>> <?php esc_html_e( 'On', 'flexo-booking' ); ?></label>
								<?php else : ?>
									<span class="flexo-badge"><?php esc_html_e( 'Coming soon', 'flexo-booking' ); ?></span>
								<?php endif; ?>
								<p class="description"><?php echo esc_html( $def['description'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save features', 'flexo-booking' ) ); ?>
		</form>
		<?php
	}

	public static function handle_save() {
		check_admin_referer( 'flexo_booking_save_features' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change booking features.', 'flexo-booking' ) );
		}

		$defs   = self::definitions();
		$posted = isset( $_POST['features'] ) ? self::parse_list( array_map( 'sanitize_key', (array) wp_unslash( $_POST['features'] ) ) ) : array();

		// Only features shown on the screen can change; choices for features
		// that are unavailable or not ready yet are kept as they were.
		$enabled = array();
		foreach ( self::stored_enabled() as $key ) {
			if ( ! self::is_available( $key ) || empty( $defs[ $key ]['ready'] ) ) {
				$enabled[] = $key;
			}
		}
		foreach ( $posted as $key ) {
			if ( self::is_available( $key ) && ! empty( $defs[ $key ]['ready'] ) ) {
				$enabled[] = $key;
			}
		}
		// The booking modes are a single choice stored in settings.
		$enabled = array_unique( array_merge( $enabled, array( 'booking_request', 'instant_booking' ) ) );
		self::set_enabled( $enabled );

		$mode = isset( $_POST['booking_mode'] ) ? sanitize_key( $_POST['booking_mode'] ) : '';
		if ( in_array( $mode, self::booking_modes(), true ) ) {
			$settings                 = Flexo_Booking_Settings::all();
			$settings['booking_mode'] = $mode;
			update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( $settings ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => Flexo_Booking_Admin::MENU_SLUG . '-settings', 'tab' => 'features', 'settings-updated' => 'true' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Agency screen (FlexoHotels only)
	 * ------------------------------------------------------------------- */

	public static function agency_menu() {
		if ( self::is_agency_user() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Agency: available features', 'flexo-booking' ), __( 'Agency', 'flexo-booking' ), 'manage_options', self::AGENCY_SLUG, array( __CLASS__, 'render_agency' ) );
		}
	}

	public static function render_agency() {
		if ( ! self::is_agency_user() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'flexo-booking' ) );
		}
		$source    = self::available_source();
		$available = self::available_list();
		$locked    = 'constant' === $source;
		?>
		<div class="wrap flexo-admin">
			<h1><?php esc_html_e( 'Agency: available features', 'flexo-booking' ); ?></h1>
			<p><?php esc_html_e( 'Choose which features this hotel can see and switch on under Settings → Features. Hotel administrators never see this screen.', 'flexo-booking' ); ?></p>
			<?php if ( $locked ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'Controlled by FLEXO_BOOKING_FEATURES in wp-config.php:', 'flexo-booking' ); ?>
					<code><?php echo esc_html( is_array( FLEXO_BOOKING_FEATURES ) ? implode( ',', FLEXO_BOOKING_FEATURES ) : FLEXO_BOOKING_FEATURES ); ?></code>
				</p></div>
			<?php elseif ( 'default' === $source ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No restriction set: all features are available.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Available features saved.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="flexo_booking_save_agency">
				<?php wp_nonce_field( 'flexo_booking_save_agency' ); ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr>
						<th><?php esc_html_e( 'Available', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Feature', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Key', 'flexo-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'flexo-booking' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( self::definitions() as $key => $def ) : ?>
						<tr>
							<td><input type="checkbox" name="available[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $available, true ) ); ?> <?php disabled( $locked ); ?> aria-label="<?php echo esc_attr( $def['label'] ); ?>"></td>
							<td><strong><?php echo esc_html( $def['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $def['description'] ); ?></span></td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo $def['ready'] ? esc_html( self::is_enabled( $key ) ? __( 'Ready · on', 'flexo-booking' ) : __( 'Ready · off', 'flexo-booking' ) ) : esc_html__( 'Coming soon', 'flexo-booking' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! $locked ) : ?>
					<p>
						<?php submit_button( __( 'Save available features', 'flexo-booking' ), 'primary', 'save', false ); ?>
						<?php submit_button( __( 'Reset: make everything available', 'flexo-booking' ), 'secondary', 'reset', false ); ?>
					</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	public static function handle_agency_save() {
		check_admin_referer( 'flexo_booking_save_agency' );
		if ( ! self::is_agency_user() || 'constant' === self::available_source() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flexo-booking' ) );
		}
		if ( isset( $_POST['reset'] ) ) {
			self::set_available( null );
		} else {
			self::set_available( isset( $_POST['available'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['available'] ) ) : array() );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::AGENCY_SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Manage Flexo Booking features.
	 */
	class Flexo_Booking_Features_CLI {

		/**
		 * Lists features with their available / enabled state.
		 *
		 * @subcommand list
		 */
		public function list_( $args, $assoc_args ) {
			$rows = array();
			foreach ( Flexo_Booking_Features::definitions() as $key => $def ) {
				$rows[] = array(
					'feature'   => $key,
					'available' => Flexo_Booking_Features::is_available( $key ) ? 'yes' : 'no',
					'enabled'   => Flexo_Booking_Features::is_enabled( $key ) ? 'yes' : 'no',
					'ready'     => $def['ready'] ? 'yes' : 'no',
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'feature', 'available', 'enabled', 'ready' ) );
			WP_CLI::line( 'Available list source: ' . Flexo_Booking_Features::available_source() );
		}

		/**
		 * Sets the available features (agency level).
		 *
		 * ## OPTIONS
		 *
		 * <features>
		 * : Comma-separated keys, "all", or "reset" to remove the restriction.
		 *
		 * ## EXAMPLES
		 *
		 *     wp flexo-booking features available booking_request,guest_emails,seasonal_pricing
		 */
		public function available( $args ) {
			if ( 'constant' === Flexo_Booking_Features::available_source() ) {
				WP_CLI::error( 'FLEXO_BOOKING_FEATURES is defined in wp-config.php; change it there.' );
			}
			Flexo_Booking_Features::set_available( 'reset' === $args[0] ? null : Flexo_Booking_Features::parse_list( $args[0] ) );
			WP_CLI::success( 'Available: ' . implode( ', ', Flexo_Booking_Features::available_list() ) );
		}

		/**
		 * Switches features on (hotel level).
		 *
		 * <features>...
		 * : Feature keys.
		 */
		public function enable( $args ) {
			Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), $args ) );
			WP_CLI::success( 'Enabled: ' . implode( ', ', Flexo_Booking_Features::stored_enabled() ) );
		}

		/**
		 * Switches features off (hotel level).
		 *
		 * <features>...
		 * : Feature keys.
		 */
		public function disable( $args ) {
			Flexo_Booking_Features::set_enabled( array_diff( Flexo_Booking_Features::stored_enabled(), $args ) );
			WP_CLI::success( 'Enabled: ' . implode( ', ', Flexo_Booking_Features::stored_enabled() ) );
		}
	}

	WP_CLI::add_command( 'flexo-booking features', 'Flexo_Booking_Features_CLI' );
}
