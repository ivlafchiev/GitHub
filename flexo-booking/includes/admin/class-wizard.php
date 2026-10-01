<?php
/**
 * Setup wizard: from a fresh install to the first test booking in a few
 * minutes. Opens once after activation on a site without rooms and
 * bookings; every step can be skipped, progress is saved, and it can be
 * opened again from Help.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Wizard {

	const SLUG     = 'flexo-booking-wizard';
	const OPTION   = 'flexo_booking_wizard';
	const REDIRECT = 'flexo_booking_wizard_redirect';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 12 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_post_flexo_booking_wizard', array( __CLASS__, 'handle_step' ) );
		add_action( 'admin_post_flexo_booking_create_page', array( __CLASS__, 'handle_create_page' ) );
		add_action( 'wp_ajax_flexo_booking_wizard_check', array( __CLASS__, 'ajax_check' ) );
	}

	public static function menu() {
		add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Setup wizard', 'flexo-booking' ), __( 'Setup wizard', 'flexo-booking' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function url( $step = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . ( '' !== $step ? '&step=' . $step : '' ) );
	}

	/**
	 * A site with no rooms and no bookings.
	 */
	public static function is_fresh() {
		global $wpdb;
		if ( Flexo_Booking_Rooms::all( 'any' ) ) {
			return false;
		}
		return ! Flexo_Booking_Schema::table_exists( 'bookings' ) || 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Flexo_Booking_Install::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Called on activation: open the wizard once on fresh sites.
	 */
	public static function on_activate() {
		if ( self::is_fresh() && ! get_option( self::OPTION ) ) {
			update_option( self::REDIRECT, 1, false );
		}
	}

	public static function maybe_redirect() {
		if ( ! get_option( self::REDIRECT ) || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( self::REDIRECT );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk activation shows the plugins list.
		if ( isset( $_GET['activate-multi'] ) || ! self::is_fresh() ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function steps() {
		return array(
			'hotel'   => __( 'Your hotel', 'flexo-booking' ),
			'mode'    => __( 'How guests book', 'flexo-booking' ),
			'room'    => __( 'First room', 'flexo-booking' ),
			'page'    => __( 'Booking page', 'flexo-booking' ),
			'emails'  => __( 'Emails', 'flexo-booking' ),
			'look'    => __( 'Look', 'flexo-booking' ),
			'test'    => __( 'Test booking', 'flexo-booking' ),
		);
	}

	public static function state() {
		$state = get_option( self::OPTION );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'step'     => 'hotel',
				'done'     => array(),
				'started'  => 0,
				'finished' => false,
			)
		);
	}

	private static function save_state( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * "Continue setting up" on Today while the wizard is unfinished.
	 */
	public static function resume_notice() {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) || ! empty( $state['finished'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$steps = self::steps();
		?>
		<div class="notice notice-info flexo-wizard-resume">
			<p>
				<?php
				/* translators: 1: steps done, 2: all steps */
				echo esc_html( sprintf( __( 'Setup: %1$d of %2$d steps done.', 'flexo-booking' ), count( $state['done'] ), count( $steps ) ) );
				?>
				<a class="button button-primary" href="<?php echo esc_url( self::url( $state['step'] ) ); ?>"><?php esc_html_e( 'Continue setting up', 'flexo-booking' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function create_page_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=flexo_booking_create_page' ), 'flexo_booking_create_page' );
	}

	/**
	 * Creates "Book your stay" with the booking form and makes it the booking page.
	 *
	 * @return int|WP_Error Page ID.
	 */
	public static function create_page() {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Book your stay', 'flexo-booking' ),
				'post_name'    => sanitize_title( _x( 'booking', 'page address', 'flexo-booking' ) ),
				'post_content' => '<!-- wp:shortcode -->[flexo_booking]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$settings                 = Flexo_Booking_Settings::all();
		$settings['booking_page'] = wp_make_link_relative( get_permalink( $id ) );
		update_option( Flexo_Booking_Settings::OPTION, $settings );
		Flexo_Booking_Guest::forget_booking_page();
		return $id;
	}

	public static function handle_create_page() {
		check_admin_referer( 'flexo_booking_create_page' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flexo-booking' ) );
		}
		self::create_page();
		$back = wp_get_referer() ? wp_get_referer() : Flexo_Booking_Health::url();
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Saves one step and moves on (or skips it).
	 */
	public static function handle_step() {
		check_admin_referer( 'flexo_booking_wizard' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'flexo-booking' ) );
		}
		$steps = array_keys( self::steps() );
		$step  = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : '';
		if ( ! in_array( $step, $steps, true ) ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		$state = self::state();
		if ( ! $state['started'] ) {
			$state['started'] = time();
		}
		$go    = isset( $_POST['go'] ) ? sanitize_key( $_POST['go'] ) : 'next';
		$error = '';
		if ( 'save' === $go || 'next' === $go || 'test' === $go ) {
			$error = self::save_step( $step );
			if ( '' === $error && ! in_array( $step, $state['done'], true ) ) {
				$state['done'][] = $step;
			}
		}
		if ( 'later' === $go ) {
			self::save_state( $state );
			wp_safe_redirect( Flexo_Booking_Today_Admin::url() );
			exit;
		}
		if ( 'finish' === $go ) {
			$state['finished'] = true;
			self::save_state( $state );
			wp_safe_redirect( add_query_arg( 'flexo_msg', 'setup_done', Flexo_Booking_Today_Admin::url() ) );
			exit;
		}
		if ( '' !== $error ) {
			self::save_state( $state );
			wp_safe_redirect( add_query_arg( 'flexo_error', rawurlencode( $error ), self::url( $step ) ) );
			exit;
		}
		if ( 'test' === $go ) {
			self::save_state( $state );
			wp_safe_redirect( add_query_arg( 'flexo_msg', 'test_sent', self::url( $step ) ) );
			exit;
		}
		$index         = array_search( $step, $steps, true );
		$next          = isset( $steps[ $index + 1 ] ) ? $steps[ $index + 1 ] : 'test';
		$state['step'] = $next;
		self::save_state( $state );
		wp_safe_redirect( self::url( $next ) );
		exit;
	}

	/**
	 * @return string Error message, or ''.
	 */
	private static function save_step( $step ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_step().
		$post     = wp_unslash( $_POST );
		$settings = Flexo_Booking_Settings::all();
		switch ( $step ) {
			case 'hotel':
				if ( ! empty( $post['hotel_name'] ) ) {
					update_option( 'blogname', sanitize_text_field( $post['hotel_name'] ) );
				}
				foreach ( array( 'hotel_address', 'hotel_phone', 'hotel_email' ) as $key ) {
					$settings[ $key ] = isset( $post[ $key ] ) ? $post[ $key ] : '';
				}
				break;
			case 'mode':
				$settings['booking_mode'] = isset( $post['booking_mode'] ) && 'instant' === $post['booking_mode'] ? 'instant' : 'request';
				$enabled                  = get_option( Flexo_Booking_Features::ENABLED_OPTION, Flexo_Booking_Features::default_enabled() );
				$enabled                  = is_array( $enabled ) ? $enabled : array();
				$feature                  = 'instant' === $settings['booking_mode'] ? 'instant_booking' : 'booking_request';
				if ( ! in_array( $feature, $enabled, true ) && Flexo_Booking_Features::is_available( $feature ) ) {
					$enabled[] = $feature;
					Flexo_Booking_Features::set_enabled( $enabled );
				}
				break;
			case 'room':
				$name  = isset( $post['room_name'] ) ? sanitize_text_field( $post['room_name'] ) : '';
				$price = isset( $post['room_price'] ) ? (float) str_replace( ',', '.', (string) $post['room_price'] ) : 0;
				if ( '' === $name ) {
					return __( 'Please enter the room\'s name.', 'flexo-booking' );
				}
				if ( $price <= 0 ) {
					return __( 'Please enter the price per night – a room without a price would be offered for free.', 'flexo-booking' );
				}
				$id = wp_insert_post(
					array(
						'post_type'   => Flexo_Booking_Rooms::POST_TYPE,
						'post_status' => 'publish',
						'post_title'  => $name,
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return $id->get_error_message();
				}
				Flexo_Booking_Rooms::save_meta_values(
					$id,
					array(
						'_flexo_price'    => $price,
						'_flexo_capacity' => max( 1, isset( $post['room_capacity'] ) ? (int) $post['room_capacity'] : 2 ),
						'_flexo_units'    => max( 1, isset( $post['room_units'] ) ? (int) $post['room_units'] : 1 ),
					)
				);
				if ( ! empty( $post['room_image'] ) ) {
					set_post_thumbnail( $id, absint( $post['room_image'] ) );
				}
				break;
			case 'page':
				$choice = isset( $post['page_choice'] ) ? sanitize_key( $post['page_choice'] ) : 'create';
				if ( 'create' === $choice ) {
					$created = self::create_page();
					if ( is_wp_error( $created ) ) {
						return $created->get_error_message();
					}
					return '';
				}
				$page = absint( isset( $post['page_id'] ) ? $post['page_id'] : 0 );
				if ( ! $page || 'page' !== get_post_type( $page ) ) {
					return __( 'Please choose a page.', 'flexo-booking' );
				}
				// The chosen page gets the booking form if it doesn't have it yet.
				$content = (string) get_post_field( 'post_content', $page );
				if ( false === strpos( $content, '[flexo_booking' ) && false === strpos( (string) get_post_meta( $page, '_elementor_data', true ), 'flexo-booking-form' ) ) {
					wp_update_post(
						array(
							'ID'           => $page,
							'post_content' => $content . "\n\n<!-- wp:shortcode -->[flexo_booking]<!-- /wp:shortcode -->",
						)
					);
				}
				$settings['booking_page'] = wp_make_link_relative( get_permalink( $page ) );
				Flexo_Booking_Guest::forget_booking_page();
				break;
			case 'emails':
				$settings['notification_email'] = isset( $post['notification_email'] ) ? $post['notification_email'] : '';
				update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( $settings, array( 'notification_email' => $settings['notification_email'] ) ) ) );
				if ( ! empty( $post['send_test'] ) ) {
					$to = Flexo_Booking_Settings::notification_email();
					if ( ! Flexo_Booking_Emails::send_test( $to ) ) {
						/* translators: %s: email address */
						return sprintf( __( 'The test email to %s could not be sent. Install an SMTP plugin with your hotel\'s mailbox and try again.', 'flexo-booking' ), $to );
					}
				}
				return '';
			case 'look':
				$settings['appearance_mode'] = isset( $post['appearance_mode'] ) && 'custom' === $post['appearance_mode'] ? 'custom' : 'match';
				if ( 'custom' === $settings['appearance_mode'] ) {
					$settings['appearance_primary'] = isset( $post['appearance_primary'] ) ? $post['appearance_primary'] : '';
				}
				break;
		}
		// phpcs:enable
		update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( $settings ) );
		return '';
	}

	/**
	 * Has a booking arrived since the wizard's test step opened?
	 */
	public static function ajax_check() {
		check_ajax_referer( 'flexo_booking_wizard' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		global $wpdb;
		$since = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '';
		$table = Flexo_Booking_Install::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, reference FROM {$table} WHERE source = 'website' AND created_at >= %s ORDER BY id DESC LIMIT 1", $since ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_success( array( 'reference' => '' ) );
		}
		wp_send_json_success(
			array(
				'reference' => $row['reference'],
				/* translators: %s: booking reference */
				'message'   => sprintf( __( 'It works! Test booking %s has arrived.', 'flexo-booking' ), $row['reference'] ),
				'url'       => Flexo_Booking_Admin::page_url( array( 'booking' => (int) $row['id'] ) ),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$steps = self::steps();
		$state = self::state();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : $state['step'];
		$step = isset( $steps[ $step ] ) ? $step : 'hotel';
		$keys = array_keys( $steps );
		$num  = array_search( $step, $keys, true ) + 1;
		$s    = Flexo_Booking_Settings::all();
		if ( 'room' === $step ) {
			wp_enqueue_media();
		}
		?>
		<div class="wrap flexo-admin flexo-wizard">
			<?php
			Flexo_Booking_Admin_UI::page_head(
				array(
					'title' => __( 'Set up your booking system', 'flexo-booking' ),
					'icon'  => 'sparkle',
					'intro' => esc_html__( 'A few short steps from an empty site to your first test booking. You can skip any step and come back later.', 'flexo-booking' ),
				)
			);
			?>
			<?php Flexo_Booking_Admin::notices(); ?>
			<?php if ( isset( $_GET['flexo_msg'] ) && 'test_sent' === $_GET['flexo_msg'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Test email sent. Check your inbox (and the spam folder).', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>
			<ol class="flexo-wizard__steps" aria-label="<?php esc_attr_e( 'Setup steps', 'flexo-booking' ); ?>">
				<?php foreach ( $steps as $key => $label ) : ?>
					<li class="<?php echo esc_attr( trim( ( in_array( $key, $state['done'], true ) ? 'is-done ' : '' ) . ( $key === $step ? 'is-current' : '' ) ) ); ?>"<?php echo $key === $step ? ' aria-current="step"' : ''; ?>>
						<a href="<?php echo esc_url( self::url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ol>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-tools-card flexo-wizard__card">
				<input type="hidden" name="action" value="flexo_booking_wizard">
				<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">
				<?php wp_nonce_field( 'flexo_booking_wizard' ); ?>
				<p class="flexo-muted">
					<?php
					/* translators: 1: step number, 2: number of steps */
					echo esc_html( sprintf( __( 'Step %1$d of %2$d', 'flexo-booking' ), $num, count( $steps ) ) );
					?>
				</p>
				<h2><?php echo esc_html( $steps[ $step ] ); ?></h2>

				<?php if ( 'hotel' === $step ) : ?>
					<p><?php esc_html_e( 'Guests see these details before they book, on the confirmation and in emails.', 'flexo-booking' ); ?></p>
					<p><label for="w-name"><?php esc_html_e( 'Hotel name', 'flexo-booking' ); ?></label><br><input id="w-name" class="regular-text" name="hotel_name" value="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></p>
					<p><label for="w-address"><?php esc_html_e( 'Address', 'flexo-booking' ); ?></label><br><textarea id="w-address" class="regular-text" rows="2" name="hotel_address"><?php echo esc_textarea( $s['hotel_address'] ); ?></textarea></p>
					<p><label for="w-phone"><?php esc_html_e( 'Phone', 'flexo-booking' ); ?></label><br><input id="w-phone" type="tel" class="regular-text" name="hotel_phone" value="<?php echo esc_attr( $s['hotel_phone'] ); ?>" placeholder="+359 …"></p>
					<p><label for="w-email"><?php esc_html_e( 'Email for guests', 'flexo-booking' ); ?></label><br><input id="w-email" type="email" class="regular-text" name="hotel_email" value="<?php echo esc_attr( $s['hotel_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>

				<?php elseif ( 'mode' === $step ) : ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'How guests book', 'flexo-booking' ); ?></legend>
						<label class="flexo-choice-card"><input type="radio" name="booking_mode" value="request" <?php checked( 'instant' !== $s['booking_mode'] ); ?>>
							<strong><?php esc_html_e( 'Booking requests', 'flexo-booking' ); ?></strong>
							<span><?php esc_html_e( 'Guests send a request; you confirm each one (one click on Today). Best if you also sell rooms elsewhere without calendar sync.', 'flexo-booking' ); ?></span></label>
						<label class="flexo-choice-card"><input type="radio" name="booking_mode" value="instant" <?php checked( 'instant', $s['booking_mode'] ); ?>>
							<strong><?php esc_html_e( 'Instant booking', 'flexo-booking' ); ?></strong>
							<span><?php esc_html_e( 'Guests book free rooms straight away and get their confirmation at once. Best when your availability is always up to date.', 'flexo-booking' ); ?></span></label>
					</fieldset>

				<?php elseif ( 'room' === $step ) : ?>
					<?php $rooms = Flexo_Booking_Rooms::all(); ?>
					<?php if ( $rooms ) : ?>
						<p>
							<?php
							/* translators: %d: number of rooms */
							echo esc_html( sprintf( _n( 'You have %d room. Add another one here, or skip this step.', 'You have %d rooms. Add another one here, or skip this step.', count( $rooms ), 'flexo-booking' ), count( $rooms ) ) );
							?>
						</p>
					<?php endif; ?>
					<p><label for="w-room"><?php esc_html_e( 'Room name', 'flexo-booking' ); ?> <span aria-hidden="true">*</span></label><br><input id="w-room" class="regular-text" name="room_name" placeholder="<?php esc_attr_e( 'e.g. Double Room with Sea View', 'flexo-booking' ); ?>"></p>
					<p><label for="w-price"><?php esc_html_e( 'Price per night', 'flexo-booking' ); ?> <span aria-hidden="true">*</span></label><br><input id="w-price" type="text" inputmode="decimal" class="small-text" name="room_price"> <?php echo esc_html( $s['currency'] ); ?></p>
					<p>
						<label for="w-cap"><?php esc_html_e( 'Guests', 'flexo-booking' ); ?></label> <input id="w-cap" type="number" min="1" class="small-text" name="room_capacity" value="2">
						<label for="w-units"><?php esc_html_e( 'Rooms of this type', 'flexo-booking' ); ?></label> <input id="w-units" type="number" min="1" class="small-text" name="room_units" value="1">
					</p>
					<p>
						<input type="hidden" name="room_image" id="w-image" value="">
						<button type="button" class="button" id="w-image-btn"><?php esc_html_e( 'Choose a photo', 'flexo-booking' ); ?></button>
						<span id="w-image-name" class="flexo-muted"></span>
					</p>
					<script>
						document.getElementById( 'w-image-btn' ).addEventListener( 'click', function () {
							if ( ! window.wp || ! wp.media ) { return; }
							var frame = wp.media( { library: { type: 'image' }, multiple: false } );
							frame.on( 'select', function () {
								var a = frame.state().get( 'selection' ).first().toJSON();
								document.getElementById( 'w-image' ).value = a.id;
								document.getElementById( 'w-image-name' ).textContent = a.filename;
							} );
							frame.open();
						} );
					</script>
					<p class="description"><?php esc_html_e( 'Seasons, rates, size and amenities can be added later under Rooms & prices.', 'flexo-booking' ); ?></p>

				<?php elseif ( 'page' === $step ) : ?>
					<?php $current = Flexo_Booking_Guest::booking_page_url(); ?>
					<?php if ( $current ) : ?>
						<p>
							<?php esc_html_e( 'Your booking page:', 'flexo-booking' ); ?>
							<a href="<?php echo esc_url( $current ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_make_link_relative( $current ) ); ?></a>
						</p>
					<?php endif; ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Booking page', 'flexo-booking' ); ?></legend>
						<label class="flexo-choice-card"><input type="radio" name="page_choice" value="create" <?php checked( ! $current ); ?>>
							<strong><?php esc_html_e( 'Create a "Book your stay" page for me', 'flexo-booking' ); ?></strong>
							<span><?php esc_html_e( 'You can design it later with Elementor – the form is the "Flexo Booking Form" widget.', 'flexo-booking' ); ?></span></label>
						<label class="flexo-choice-card"><input type="radio" name="page_choice" value="existing" <?php checked( (bool) $current ); ?>>
							<strong><?php esc_html_e( 'Use an existing page', 'flexo-booking' ); ?></strong>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'page_id',
									'show_option_none'  => __( '— Choose a page —', 'flexo-booking' ),
									'option_none_value' => 0,
									'selected'          => $current ? url_to_postid( $current ) : 0,
								)
							);
							?>
							<span><?php esc_html_e( 'The booking form is added to the page if it isn\'t there yet.', 'flexo-booking' ); ?></span></label>
					</fieldset>

				<?php elseif ( 'emails' === $step ) : ?>
					<p><label for="w-notify"><?php esc_html_e( 'Send new bookings to', 'flexo-booking' ); ?></label><br><input id="w-notify" class="regular-text" name="notification_email" value="<?php echo esc_attr( $s['notification_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>
					<p class="description"><?php esc_html_e( 'Several addresses can be separated by commas. Guests\' replies go to the first one.', 'flexo-booking' ); ?></p>
					<p><label><input type="checkbox" name="send_test" value="1" checked> <?php esc_html_e( 'Send me a test email now', 'flexo-booking' ); ?></label></p>

				<?php elseif ( 'look' === $step ) : ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Look', 'flexo-booking' ); ?></legend>
						<label class="flexo-choice-card"><input type="radio" name="appearance_mode" value="match" <?php checked( 'custom' !== $s['appearance_mode'] ); ?>>
							<strong><?php esc_html_e( 'Match my website', 'flexo-booking' ); ?></strong>
							<span><?php esc_html_e( 'The form uses your website\'s colours and fonts (recommended).', 'flexo-booking' ); ?></span></label>
						<label class="flexo-choice-card"><input type="radio" name="appearance_mode" value="custom" <?php checked( 'custom', $s['appearance_mode'] ); ?>>
							<strong><?php esc_html_e( 'Choose a main colour', 'flexo-booking' ); ?></strong>
							<input type="color" name="appearance_primary" value="<?php echo esc_attr( $s['appearance_primary'] ? $s['appearance_primary'] : '#1f6f5c' ); ?>" aria-label="<?php esc_attr_e( 'Main colour', 'flexo-booking' ); ?>">
							<span><?php esc_html_e( 'More options (fonts, corners, preview) under Bookings → Appearance.', 'flexo-booking' ); ?></span></label>
					</fieldset>

				<?php else : ?>
					<?php $page = Flexo_Booking_Guest::booking_page_url(); ?>
					<?php if ( ! $page ) : ?>
						<p><?php esc_html_e( 'First create the booking page (step "Booking page").', 'flexo-booking' ); ?></p>
					<?php else : ?>
						<ol>
							<li><?php esc_html_e( 'Open your booking page in a new tab.', 'flexo-booking' ); ?> <a class="button" href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open booking page', 'flexo-booking' ); ?></a></li>
							<li><?php esc_html_e( 'Book a room with your own email address.', 'flexo-booking' ); ?></li>
							<li><?php esc_html_e( 'Come back here – the booking appears below. You can cancel or delete it afterwards.', 'flexo-booking' ); ?></li>
						</ol>
						<div class="flexo-wizard__wait" data-flexo-wait-booking="<?php echo esc_attr( wp_date( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ); ?>" aria-live="polite">
							<p class="flexo-muted"><span class="spinner is-active" style="float:none;margin:0 6px 0 0"></span><?php esc_html_e( 'Waiting for your test booking…', 'flexo-booking' ); ?></p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<p class="flexo-wizard__buttons">
					<?php if ( 'test' === $step ) : ?>
						<button type="submit" name="go" value="finish" class="button button-primary"><?php esc_html_e( 'Finish setup', 'flexo-booking' ); ?></button>
					<?php else : ?>
						<button type="submit" name="go" value="next" class="button button-primary"><?php esc_html_e( 'Save and continue', 'flexo-booking' ); ?></button>
						<button type="submit" name="go" value="skip" class="button" formnovalidate><?php esc_html_e( 'Skip this step', 'flexo-booking' ); ?></button>
					<?php endif; ?>
					<button type="submit" name="go" value="later" class="button-link" formnovalidate><?php esc_html_e( 'Finish later', 'flexo-booking' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
