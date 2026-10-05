<?php
/**
 * Settings → Pages (1.9.0): the Booking, Thank You and Contact pages the
 * plugin provides, or the hotel's own pages instead. One card per page
 * (status, address, Open, Customize) with Page / Content / Sections /
 * Layout / Appearance, plus the header style and what happens after a
 * booking.
 *
 * Saves flexo_booking_pages, flexo_booking_forms and the booking field
 * settings of the main settings (phone, special requests); every option
 * merges with its saved values, so nothing else is reset.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Pages_Admin {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
	}

	public static function register() {
		register_setting(
			'flexo_booking',
			Flexo_Booking_System_Pages::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_pages' ),
			)
		);
		register_setting(
			'flexo_booking',
			Flexo_Booking_Forms::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Flexo_Booking_Forms', 'sanitize' ),
			)
		);
	}

	/**
	 * Saves the pages and says when a new address is already in use.
	 */
	public static function sanitize_pages( $input ) {
		$before = Flexo_Booking_System_Pages::all();
		$clean  = Flexo_Booking_System_Pages::sanitize( $input );
		if ( ! function_exists( 'add_settings_error' ) || ! is_array( $input ) ) {
			return $clean;
		}
		foreach ( Flexo_Booking_System_Pages::keys() as $key ) {
			if ( ! isset( $input[ $key ]['slug'] ) ) {
				continue;
			}
			$asked = trim( (string) $input[ $key ]['slug'] );
			if ( '' !== $asked && '' === Flexo_Booking_System_Pages::sanitize_slug( $asked ) ) {
				/* translators: %s: the address typed */
				add_settings_error( Flexo_Booking_System_Pages::OPTION, 'flexo-slug-' . $key, sprintf( __( 'The address "%s" can\'t be used (WordPress uses it, or it has no letters or numbers). The previous address was kept.', 'flexo-booking' ), $asked ), 'warning' );
				continue;
			}
			if ( $clean[ $key ]['slug'] !== $before[ $key ]['slug'] ) {
				$hit = self::collision_with( $key, $clean[ $key ]['slug'], $clean );
				if ( $hit ) {
					add_settings_error( Flexo_Booking_System_Pages::OPTION, 'flexo-slug-' . $key, self::collision_text( $key, $hit, $clean[ $key ]['slug'] ), 'warning' );
				}
			}
		}
		return $clean;
	}

	/**
	 * A collision for settings not saved yet (other pages' new addresses count).
	 */
	private static function collision_with( $key, $slug, array $settings ) {
		foreach ( Flexo_Booking_System_Pages::keys() as $other ) {
			if ( $other !== $key && Flexo_Booking_System_Pages::supported( $other ) && $settings[ $other ]['slug'] === $slug ) {
				return array(
					'type'  => 'system',
					'label' => Flexo_Booking_System_Pages::label( $other ),
					'url'   => '',
					'id'    => 0,
				);
			}
		}
		$hit = Flexo_Booking_System_Pages::collision( $key, $slug );
		return $hit && 'system' === $hit['type'] ? null : $hit;
	}

	public static function assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen.
		if ( false === strpos( (string) $hook, Flexo_Booking_Admin::MENU_SLUG . '-settings' ) || ! isset( $_GET['tab'] ) || 'pages' !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'flexo-booking-admin-pages', FLEXO_BOOKING_URL . 'assets/js/admin-pages.js', array(), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			'flexo-booking-admin-pages',
			'FlexoPagesAdmin',
			array(
				'i18n' => array(
					'chooseImage' => __( 'Choose an image', 'flexo-booking' ),
					'useImage'    => __( 'Use this image', 'flexo-booking' ),
					/* translators: %s: section name */
					'moveUp'      => __( 'Move up: %s', 'flexo-booking' ),
					/* translators: %s: section name */
					'moveDown'    => __( 'Move down: %s', 'flexo-booking' ),
					/* translators: 1: section name, 2: position, 3: number of sections */
					'moved'       => __( '%1$s moved to position %2$d of %3$d.', 'flexo-booking' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Status in plain words
	 * ------------------------------------------------------------------- */

	/**
	 * @return array { level: ok|warning|off, text, url }
	 */
	public static function status( $key ) {
		$page = Flexo_Booking_System_Pages::get( $key );
		$all  = Flexo_Booking_System_Pages::all();
		$url  = Flexo_Booking_System_Pages::url( $key );
		$rel  = '' !== $url ? wp_make_link_relative( $url ) : '';

		if ( 'page' === $page['source'] ) {
			$problem = Flexo_Booking_System_Pages::own_page_problem( $key );
			if ( '' !== $problem ) {
				$text = 'unpublished' === $problem
					? __( 'Your page is not published, so guests can\'t open it.', 'flexo-booking' )
					: __( 'Your page was not found (deleted, or no page chosen).', 'flexo-booking' );
				if ( 'booking' === $key ) {
					/* translators: %s: address */
					$text .= ' ' . sprintf( __( 'Meanwhile guests get the built-in booking page at %s.', 'flexo-booking' ), wp_make_link_relative( Flexo_Booking_System_Pages::builtin_url( 'booking' ) ) );
				} elseif ( 'thank_you' === $key ) {
					$text .= ' ' . __( 'Meanwhile guests see their confirmation in the booking form.', 'flexo-booking' );
				}
				return array( 'level' => 'warning', 'text' => $text, 'url' => $url );
			}
			/* translators: %s: page address */
			$text = sprintf( __( 'Your own page: %s', 'flexo-booking' ), $rel );
			if ( 'thank_you' === $key && 'separate' !== $all['after_booking'] ) {
				$text .= ' ' . __( '(not used: bookings show the confirmation in the booking form)', 'flexo-booking' );
			}
			return array( 'level' => 'ok', 'text' => $text, 'url' => $url );
		}

		if ( ! Flexo_Booking_System_Pages::enabled( $key ) ) {
			$text = 'thank_you' === $key ? __( 'Switched off. Guests see their confirmation in the booking form.', 'flexo-booking' ) : __( 'Switched off.', 'flexo-booking' );
			return array( 'level' => 'off', 'text' => $text, 'url' => '' );
		}
		$hit = Flexo_Booking_System_Pages::collision( $key );
		if ( $hit ) {
			return array( 'level' => 'warning', 'text' => self::collision_text( $key, $hit, Flexo_Booking_System_Pages::slug( $key ) ), 'url' => '' );
		}
		/* translators: %s: address */
		$text = sprintf( __( 'Ready at %s', 'flexo-booking' ), $rel );
		if ( 'thank_you' === $key && 'separate' !== $all['after_booking'] ) {
			$text .= ' ' . __( '– not used yet: bookings show the confirmation in the booking form. Choose "Separate Thank You page" below to use it.', 'flexo-booking' );
		}
		return array( 'level' => 'ok', 'text' => $text, 'url' => $url );
	}

	/**
	 * "The address is used by …" with the two ways to fix it.
	 */
	public static function collision_text( $key, array $hit, $slug ) {
		$address = '/' . $slug . '/';
		switch ( $hit['type'] ) {
			case 'system':
				/* translators: 1: address, 2: other Flexo page */
				$what = sprintf( __( 'The address %1$s is also used by the %2$s page of Flexo Booking.', 'flexo-booking' ), $address, $hit['label'] );
				break;
			case 'rooms':
				/* translators: %s: address */
				$what = sprintf( __( 'The address %s is used by your room pages.', 'flexo-booking' ), $address );
				break;
			case 'post_type':
				/* translators: 1: address, 2: content type name */
				$what = sprintf( __( 'The address %1$s is used by "%2$s" of another plugin or your theme.', 'flexo-booking' ), $address, $hit['label'] );
				break;
			default:
				/* translators: 1: address, 2: page title */
				$what = sprintf( __( 'Your website already has a page at %1$s ("%2$s"). Your page always wins, so the built-in page is not shown there.', 'flexo-booking' ), $address, $hit['label'] );
		}
		$fix = 'page' === $hit['type']
			? __( 'To fix it, change the address below, or choose that page under "Use my own page".', 'flexo-booking' )
			: __( 'To fix it, change the address below.', 'flexo-booking' );
		return $what . ' ' . $fix;
	}

	/* ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------- */

	public static function render() {
		$all  = Flexo_Booking_System_Pages::all();
		$opt  = Flexo_Booking_System_Pages::OPTION;
		$s    = Flexo_Booking_Settings::all();
		?>
		<p class="flexo-tab-intro"><?php esc_html_e( 'Flexo Booking provides ready-to-use pages for reservations and guest communication. You do not need to create these pages manually in WordPress.', 'flexo-booking' ); ?></p>
		<form method="post" action="options.php" class="flexo-pages-form">
			<?php settings_fields( 'flexo_booking' ); ?>

			<?php
			self::render_booking_card( $all, $opt, $s );
			self::render_thank_you_card( $all, $opt, $s );
			if ( Flexo_Booking_System_Pages::supported( 'contact' ) ) {
				do_action( 'flexo_booking_pages_admin_contact', $all, $opt );
			}
			self::render_header_card( $all, $opt );
			?>

			<?php submit_button( __( 'Save pages', 'flexo-booking' ) ); ?>
		</form>
		<?php
	}

	private static function card_head( $key, $title, $description ) {
		$status = self::status( $key );
		$badges = array(
			'ok'      => array( 'flexo-badge--ok', __( 'OK', 'flexo-booking' ) ),
			'warning' => array( 'flexo-badge--warning', __( 'Needs attention', 'flexo-booking' ) ),
			'off'     => array( 'flexo-badge--muted', __( 'Off', 'flexo-booking' ) ),
		);
		$badge  = $badges[ $status['level'] ];
		$preview = '';
		if ( 'thank_you' === $key && Flexo_Booking_System_Pages::uses_builtin( 'thank_you' ) ) {
			$preview = Flexo_Booking_Confirmation::preview_url( 'instant' );
		}
		?>
		<div class="flexo-card__head flexo-page-card__head">
			<div>
				<h2><?php echo esc_html( $title ); ?> <span class="flexo-badge <?php echo esc_attr( $badge[0] ); ?>"><?php echo esc_html( $badge[1] ); ?></span></h2>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<p class="flexo-page-card__status flexo-page-card__status--<?php echo esc_attr( $status['level'] ); ?>" data-flexo-page-status="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $status['text'] ); ?></p>
			</div>
			<div class="flexo-page-card__actions">
				<?php if ( '' !== $status['url'] ) : ?>
					<a class="button" href="<?php echo esc_url( $status['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'flexo-booking' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $preview ) : ?>
					<a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview with a sample booking', 'flexo-booking' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Page source, own page and address.
	 */
	private static function page_fields( $key, array $page, $opt, $legacy_name = '', $legacy_value = '' ) {
		$name = $opt . '[' . $key . ']';
		$home = trailingslashit( home_url() );
		?>
		<fieldset class="flexo-mode-choice flexo-page-source" data-flexo-source="<?php echo esc_attr( $key ); ?>">
			<legend><?php esc_html_e( 'Page', 'flexo-booking' ); ?></legend>
			<label class="flexo-choice-card"><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="builtin" <?php checked( $page['source'], 'builtin' ); ?>><strong><?php esc_html_e( 'Flexo built-in page', 'flexo-booking' ); ?></strong><span><?php esc_html_e( 'Ready to use, with your website\'s header and footer. Customize it below.', 'flexo-booking' ); ?></span></label>
			<label class="flexo-choice-card"><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="page" <?php checked( $page['source'], 'page' ); ?>><strong><?php esc_html_e( 'Use my own WordPress page', 'flexo-booking' ); ?></strong><span><?php esc_html_e( 'A page you designed, for example in Elementor.', 'flexo-booking' ); ?></span></label>
		</fieldset>
		<table class="form-table" role="presentation">
			<tr class="flexo-if-source flexo-if-source--page" data-flexo-for="<?php echo esc_attr( $key ); ?>">
				<th scope="row"><label for="fb-page-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Your page', 'flexo-booking' ); ?></label></th>
				<td>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core output.
					echo wp_dropdown_pages(
						array(
							'name'              => $name . '[page_id]',
							'id'                => 'fb-page-' . $key,
							'selected'          => (int) $page['page_id'],
							'show_option_none'  => __( '— Choose a page —', 'flexo-booking' ),
							'option_none_value' => 0,
							'echo'              => 0,
							'post_status'       => array( 'publish', 'draft', 'private' ),
						)
					);
					?>
					<p class="description">
						<?php if ( 'booking' === $key ) : ?>
							<?php esc_html_e( 'The page must contain the booking form (the Flexo Booking Form widget or the [flexo_booking] shortcode). On multilingual websites the page in the guest\'s language is used.', 'flexo-booking' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Guests are sent there after booking. On multilingual websites the page in the guest\'s language is used. Booking details can be shown on it with the Flexo Booking Confirmation widget (coming in a later update); until then it shows the page as you designed it.', 'flexo-booking' ); ?>
						<?php endif; ?>
					</p>
					<?php if ( '' !== $legacy_name ) : ?>
						<p class="flexo-advanced">
							<label for="fb-legacy-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Or an address (used when no page is chosen)', 'flexo-booking' ); ?></label><br>
							<input id="fb-legacy-<?php echo esc_attr( $key ); ?>" type="text" class="regular-text" name="<?php echo esc_attr( Flexo_Booking_Settings::OPTION . '[' . $legacy_name . ']' ); ?>" value="<?php echo esc_attr( $legacy_value ); ?>" placeholder="/thank-you/">
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="flexo-if-source flexo-if-source--builtin" data-flexo-for="<?php echo esc_attr( $key ); ?>">
				<th scope="row"><label for="fb-slug-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Address', 'flexo-booking' ); ?></label></th>
				<td>
					<span class="flexo-url-field"><span class="flexo-url-field__home"><?php echo esc_html( $home ); ?></span><input id="fb-slug-<?php echo esc_attr( $key ); ?>" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( Flexo_Booking_System_Pages::slug( $key ) ); ?>" pattern="[A-Za-z0-9\-]+" required><span class="flexo-url-field__rest">/</span></span>
					<p class="description"><?php esc_html_e( 'Latin letters, numbers and dashes. If you change it, the old address keeps working and leads to the new one. A page of your website at the same address always wins.', 'flexo-booking' ); ?></p>
					<?php if ( $page['old_slugs'] ) : ?>
						<p class="description">
							<?php
							/* translators: %s: list of addresses */
							printf( esc_html__( 'Old addresses that lead here: %s', 'flexo-booking' ), '<code>' . esc_html( implode( '</code>, <code>', array_map( static function ( $slug ) { return '/' . $slug . '/'; }, $page['old_slugs'] ) ) ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped list.
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * A text field showing the default text as placeholder (empty = default).
	 */
	private static function text_row( $key, $field, $label, $opt, $type = 'text', $show = '' ) {
		$page     = Flexo_Booking_System_Pages::get( $key );
		$defaults = Flexo_Booking_System_Pages::default_texts();
		$default  = isset( $defaults[ $key ][ $field ] ) ? $defaults[ $key ][ $field ] : '';
		$name     = $opt . '[' . $key . '][' . $field . ']';
		$id       = 'fb-' . $key . '-' . $field;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( '' !== $show ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $opt . '[' . $key . '][' . $show . ']' ); ?>" value="0">
					<p><?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Show', 'flexo-booking' ), 'name="' . esc_attr( $opt . '[' . $key . '][' . $show . ']' ) . '" value="1"', ! empty( $page[ $show ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in switch_html(). ?></p>
				<?php endif; ?>
				<?php if ( 'textarea' === $type ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( $page[ $field ] ); ?></textarea>
				<?php else : ?>
					<input id="<?php echo esc_attr( $id ); ?>" type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $page[ $field ] ); ?>" placeholder="<?php echo esc_attr( $default ); ?>">
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function switch_row( $key, $field, $label, $opt, $description = '' ) {
		$page = Flexo_Booking_System_Pages::get( $key );
		$name = $opt . '[' . $key . '][' . $field . ']';
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Show', 'flexo-booking' ), 'name="' . esc_attr( $name ) . '" value="1"', ! empty( $page[ $field ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in switch_html(). ?>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function layout_choice( $key, array $layouts, $current, $opt ) {
		?>
		<fieldset class="flexo-mode-choice flexo-layout-choice">
			<legend><?php esc_html_e( 'Layout', 'flexo-booking' ); ?></legend>
			<?php foreach ( $layouts as $value => $layout ) : ?>
				<label class="flexo-choice-card flexo-layout-choice__item flexo-layout-choice__item--<?php echo esc_attr( $value ); ?>"><input type="radio" name="<?php echo esc_attr( $opt . '[' . $key . '][layout]' ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>><strong><?php echo esc_html( $layout[0] ); ?></strong><span><?php echo esc_html( $layout[1] ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<?php
	}

	private static function appearance_note() {
		?>
		<h3 class="flexo-page-section__title"><?php esc_html_e( 'Appearance', 'flexo-booking' ); ?></h3>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Appearance */
				esc_html__( 'Colours and fonts come from %s (or from your website when it is set to match it).', 'flexo-booking' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-appearance' ) ) . '">' . esc_html__( 'Bookings → Appearance', 'flexo-booking' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Booking
	 * ------------------------------------------------------------------- */

	private static function render_booking_card( array $all, $opt, array $s ) {
		$page = $all['booking'];
		?>
		<section class="flexo-card flexo-page-card" id="flexo-page-booking">
			<?php self::card_head( 'booking', __( 'Booking page', 'flexo-booking' ), __( 'Choose what guests see when they make a reservation.', 'flexo-booking' ) ); ?>
			<details class="flexo-page-card__customize" data-flexo-remember="booking">
				<summary><?php esc_html_e( 'Customize', 'flexo-booking' ); ?></summary>
				<?php self::page_fields( 'booking', $page, $opt, 'booking_page', $s['booking_page'] ); ?>

				<div class="flexo-if-builtin" data-flexo-for="booking">
					<h3 class="flexo-page-section__title"><?php esc_html_e( 'Content', 'flexo-booking' ); ?></h3>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'booking', 'title', __( 'Page title', 'flexo-booking' ), $opt, 'text', 'show_title' );
						self::text_row( 'booking', 'intro', __( 'Introduction', 'flexo-booking' ), $opt, 'textarea', 'show_intro' );
						self::switch_row( 'booking', 'show_steps', __( 'Booking steps', 'flexo-booking' ), $opt, __( 'Dates → Room → Your details → Confirmation, above the form.', 'flexo-booking' ) );
						self::switch_row( 'booking', 'show_summary', __( 'Reservation summary', 'flexo-booking' ), $opt, __( 'The stay and its price next to the form (below it on phones).', 'flexo-booking' ) );
						self::text_row( 'booking', 'reassurance', __( 'Why book here', 'flexo-booking' ), $opt, 'textarea', 'show_reassurance' );
						self::switch_row( 'booking', 'show_secure', __( 'Secure payment note', 'flexo-booking' ), $opt, __( 'Shown when guests pay by card when booking.', 'flexo-booking' ) );
						self::text_row( 'booking', 'help_title', __( 'Help heading', 'flexo-booking' ), $opt, 'text', 'show_help' );
						self::text_row( 'booking', 'help_text', __( 'Help text', 'flexo-booking' ), $opt, 'textarea' );
						?>
					</table>
					<p class="description"><?php esc_html_e( 'Leave a text empty to use the standard text shown in grey – it is translated into your guests\' language automatically. "Why book here": one point per line.', 'flexo-booking' ); ?></p>
					<?php self::layout_choice( 'booking', Flexo_Booking_System_Pages::booking_layouts(), $page['layout'], $opt ); ?>
				</div>

				<?php self::render_fields( $s ); ?>

				<div class="flexo-if-builtin" data-flexo-for="booking">
					<?php self::appearance_note(); ?>
					<div class="flexo-advanced">
						<h3 class="flexo-page-section__title"><?php esc_html_e( 'Search engines and caching', 'flexo-booking' ); ?></h3>
						<table class="form-table" role="presentation">
							<?php self::seo_rows( 'booking', $page, $opt ); ?>
						</table>
					</div>
				</div>
			</details>
		</section>
		<?php
	}

	private static function seo_rows( $key, array $page, $opt ) {
		$name = $opt . '[' . $key . ']';
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Search engines', 'flexo-booking' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[indexable]" value="0">
				<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Let search engines show this page', 'flexo-booking' ), 'name="' . esc_attr( $name ) . '[indexable]" value="1"', ! empty( $page['indexable'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in switch_html(). ?>
				<p><label for="fb-<?php echo esc_attr( $key ); ?>-meta"><?php esc_html_e( 'Description for search results', 'flexo-booking' ); ?></label><br>
					<textarea id="fb-<?php echo esc_attr( $key ); ?>-meta" class="large-text" rows="2" maxlength="300" name="<?php echo esc_attr( $name ); ?>[meta_description]"><?php echo esc_textarea( $page['meta_description'] ); ?></textarea></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Page caching', 'flexo-booking' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[cacheable]" value="0">
				<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Caching plugins may keep a copy of this page', 'flexo-booking' ), 'name="' . esc_attr( $name ) . '[cacheable]" value="1"', ! empty( $page['cacheable'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in switch_html(). ?>
				<p class="description"><?php esc_html_e( 'Safe and faster: the page holds no private data (the form loads prices and sends bookings separately). Switch off if your caching plugin shows outdated content here. The Thank You page and booking links from emails are never cached.', 'flexo-booking' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guest details of the booking form: texts, order, phone and requests.
	 */
	private static function render_fields( array $s ) {
		$forms    = Flexo_Booking_Forms::all();
		$defaults = Flexo_Booking_Forms::booking_field_defaults();
		$fname    = Flexo_Booking_Forms::OPTION;
		$sname    = Flexo_Booking_Settings::OPTION;
		?>
		<h3 class="flexo-page-section__title"><?php esc_html_e( 'Guest details form', 'flexo-booking' ); ?></h3>
		<p class="description"><?php esc_html_e( 'What the booking form asks guests. Name and email are always required. Dates, rooms, guests and prices are part of the booking itself. Ask only for what you need (data minimisation).', 'flexo-booking' ); ?></p>
		<ol class="flexo-sortable flexo-field-list" data-flexo-sortable>
			<?php foreach ( $forms['booking_order'] as $key ) : ?>
				<?php
				if ( ! isset( $defaults[ $key ] ) ) {
					continue;
				}
				$field = $forms['booking_fields'][ $key ];
				$label = $defaults[ $key ]['label'];
				?>
				<li class="flexo-sortable__item flexo-field-item" data-flexo-label="<?php echo esc_attr( $label ); ?>">
					<input type="hidden" name="<?php echo esc_attr( $fname ); ?>[booking_order][]" value="<?php echo esc_attr( $key ); ?>">
					<div class="flexo-field-item__head">
						<strong><?php echo esc_html( $label ); ?></strong>
						<?php if ( 'phone' === $key ) : ?>
							<select name="<?php echo esc_attr( $sname ); ?>[field_phone]" aria-label="<?php esc_attr_e( 'Phone', 'flexo-booking' ); ?>">
								<option value="required" <?php selected( $s['field_phone'], 'required' ); ?>><?php esc_html_e( 'Required', 'flexo-booking' ); ?></option>
								<option value="optional" <?php selected( $s['field_phone'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'flexo-booking' ); ?></option>
								<option value="hidden" <?php selected( $s['field_phone'], 'hidden' ); ?>><?php esc_html_e( 'Not asked', 'flexo-booking' ); ?></option>
							</select>
						<?php elseif ( 'notes' === $key ) : ?>
							<select name="<?php echo esc_attr( $sname ); ?>[field_notes]" aria-label="<?php esc_attr_e( 'Special requests', 'flexo-booking' ); ?>">
								<option value="optional" <?php selected( $s['field_notes'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'flexo-booking' ); ?></option>
								<option value="hidden" <?php selected( $s['field_notes'], 'hidden' ); ?>><?php esc_html_e( 'Not asked', 'flexo-booking' ); ?></option>
							</select>
						<?php else : ?>
							<span class="flexo-badge"><?php esc_html_e( 'Always required', 'flexo-booking' ); ?></span>
						<?php endif; ?>
						<span class="flexo-sortable__buttons"></span>
					</div>
					<div class="flexo-field-item__texts">
						<?php foreach ( array( 'label' => __( 'Label', 'flexo-booking' ), 'placeholder' => __( 'Example text in the field', 'flexo-booking' ), 'help' => __( 'Help text', 'flexo-booking' ) ) as $part => $part_label ) : ?>
							<label><span><?php echo esc_html( $part_label ); ?></span>
								<input type="text" name="<?php echo esc_attr( $fname ); ?>[booking_fields][<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $part ); ?>]" value="<?php echo esc_attr( $field[ $part ] ); ?>" placeholder="<?php echo esc_attr( $defaults[ $key ][ $part ] ); ?>"></label>
						<?php endforeach; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="description">
			<?php
			$links = array();
			if ( Flexo_Booking_Features::is_enabled( 'privacy_consent' ) ) {
				$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=privacy' ) ) . '">' . esc_html__( 'privacy consent', 'flexo-booking' ) . '</a>';
			}
			if ( Flexo_Booking_Features::is_enabled( 'invoice_request' ) ) {
				$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-settings&tab=taxes' ) ) . '">' . esc_html__( 'invoice request', 'flexo-booking' ) . '</a>';
			}
			if ( $links ) {
				/* translators: %s: links to settings */
				printf( esc_html__( 'The %s fields keep their own settings.', 'flexo-booking' ), implode( ', ', $links ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped links.
			}
			?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Thank You
	 * ------------------------------------------------------------------- */

	private static function render_thank_you_card( array $all, $opt, array $s ) {
		$page     = $all['thank_you'];
		$sections = Flexo_Booking_System_Pages::thank_you_sections();
		$name     = $opt . '[thank_you]';
		?>
		<section class="flexo-card flexo-page-card" id="flexo-page-thank-you">
			<?php self::card_head( 'thank_you', __( 'Thank You page', 'flexo-booking' ), __( 'What guests see right after booking: their booking, payment and what happens next.', 'flexo-booking' ) ); ?>
			<fieldset class="flexo-mode-choice">
				<legend><?php esc_html_e( 'After a booking', 'flexo-booking' ); ?></legend>
				<label class="flexo-choice-card"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[after_booking]" value="separate" <?php checked( $all['after_booking'], 'separate' ); ?>><strong><?php esc_html_e( 'Separate Thank You page', 'flexo-booking' ); ?></strong><span><?php esc_html_e( 'Guests go to the Thank You page with their booking details.', 'flexo-booking' ); ?></span></label>
				<label class="flexo-choice-card"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[after_booking]" value="inline" <?php checked( $all['after_booking'], 'inline' ); ?>><strong><?php esc_html_e( 'Show confirmation inside booking form', 'flexo-booking' ); ?></strong><span><?php esc_html_e( 'Guests stay on the booking page, which shows their confirmation.', 'flexo-booking' ); ?></span></label>
			</fieldset>
			<details class="flexo-page-card__customize" data-flexo-remember="thank_you">
				<summary><?php esc_html_e( 'Customize', 'flexo-booking' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Built-in Thank You page', 'flexo-booking' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[enabled]" value="0">
							<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Switched on', 'flexo-booking' ), 'name="' . esc_attr( $name ) . '[enabled]" value="1"', ! empty( $page['enabled'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in switch_html(). ?>
						</td>
					</tr>
				</table>
				<?php self::page_fields( 'thank_you', $page, $opt, 'thank_you_url', $s['thank_you_url'] ); ?>

				<div class="flexo-if-builtin" data-flexo-for="thank_you">
					<h3 class="flexo-page-section__title"><?php esc_html_e( 'Content', 'flexo-booking' ); ?></h3>
					<p class="description"><?php esc_html_e( 'The page adapts to each booking by itself: requests, confirmed bookings, card payments and bank transfers each get their own heading and details. Leave a text empty to use the standard text shown in grey (translated automatically). You can use {hotel_name}, {reference}, {check_in} and {check_out}.', 'flexo-booking' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'thank_you', 'heading_request', __( 'Heading: booking request', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'heading_instant', __( 'Heading: confirmed booking', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'heading_paid', __( 'Heading: paid by card', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'heading_transfer', __( 'Heading: bank transfer', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'heading_pending', __( 'Heading: payment being confirmed', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'intro', __( 'Introduction', 'flexo-booking' ), $opt, 'textarea' );
						self::text_row( 'thank_you', 'next_title', __( '"What happens next" title', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'next_text', __( '"What happens next" text', 'flexo-booking' ), $opt, 'textarea' );
						self::text_row( 'thank_you', 'help_title', __( 'Contact title', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'back_label', __( '"Back to the website" button', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'manage_label', __( '"Manage your booking" button', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'calendar_label', __( '"Add to calendar" button', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'directions_label', __( '"Get directions" button', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'generic_title', __( 'Heading without booking details', 'flexo-booking' ), $opt );
						self::text_row( 'thank_you', 'generic_text', __( 'Text without booking details', 'flexo-booking' ), $opt, 'textarea' );
						?>
					</table>
					<p class="description"><?php esc_html_e( '"Without booking details": what visitors see when the page has no booking to show – for example when they open it later, from another device, or the link was shared. Booking details are shown for 60 minutes after booking; the email link "Manage your booking" works at any time.', 'flexo-booking' ); ?></p>

					<h3 class="flexo-page-section__title"><?php esc_html_e( 'Sections', 'flexo-booking' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Each section appears only when it applies to the booking – for example bank details only for bank transfers. Use the arrows to change the order.', 'flexo-booking' ); ?></p>
					<ol class="flexo-sortable flexo-section-list" data-flexo-sortable>
						<?php foreach ( $page['order'] as $section ) : ?>
							<?php
							if ( ! isset( $sections[ $section ] ) ) {
								continue;
							}
							?>
							<li class="flexo-sortable__item" data-flexo-label="<?php echo esc_attr( $sections[ $section ] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[order][]" value="<?php echo esc_attr( $section ); ?>">
								<label class="flexo-section-list__label"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[shown][]" value="<?php echo esc_attr( $section ); ?>" <?php checked( ! in_array( $section, $page['hidden'], true ) ); ?>> <?php echo esc_html( $sections[ $section ] ); ?></label>
								<span class="flexo-sortable__buttons"></span>
							</li>
						<?php endforeach; ?>
					</ol>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[shown][]" value="">

					<?php self::layout_choice( 'thank_you', Flexo_Booking_System_Pages::thank_you_layouts(), $page['layout'], $opt ); ?>
					<?php self::appearance_note(); ?>
					<p class="description"><?php esc_html_e( 'The Thank You page is never kept by caching plugins and never shown in search engines.', 'flexo-booking' ); ?></p>
				</div>
			</details>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Header style
	 * ------------------------------------------------------------------- */

	private static function render_header_card( array $all, $opt ) {
		$overlay = Flexo_Booking_System_Pages::overlay_detected();
		$styles  = array(
			'normal' => array( __( 'Normal', 'flexo-booking' ), __( 'No change. If your header lies over the page, the page is moved below it automatically.', 'flexo-booking' ) ),
			'space'  => array( __( 'Add space for an overlay header', 'flexo-booking' ), __( 'For a transparent header made to sit on a large picture: space above the page, set per device or measured automatically.', 'flexo-booking' ) ),
			'band'   => array( __( 'Title band behind the header', 'flexo-booking' ), __( 'The page title on a colour or a picture, under your transparent header – like your other pages\' hero.', 'flexo-booking' ) ),
		);
		$image = (int) $all['header_band_image'];
		?>
		<section class="flexo-card flexo-page-card" id="flexo-page-header">
			<div class="flexo-card__head"><h2><?php esc_html_e( 'Header style above Flexo pages', 'flexo-booking' ); ?></h2></div>
			<p class="description"><?php esc_html_e( 'Your website\'s header and footer are shown on the Flexo pages (and on the plugin\'s own room pages). Choose how the page fits under the header.', 'flexo-booking' ); ?></p>
			<?php if ( $overlay && 'normal' === $all['header_style'] ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					/* translators: %s: page address */
					printf( esc_html__( 'Your header lies over the page content (seen on %s). The page is moved below it automatically; for a look like your other pages, choose "Title band behind the header".', 'flexo-booking' ), '<code>' . esc_html( $overlay['path'] ) . '</code>' );
					?>
				</p></div>
			<?php endif; ?>
			<fieldset class="flexo-mode-choice" data-flexo-header-style>
				<legend class="screen-reader-text"><?php esc_html_e( 'Header style', 'flexo-booking' ); ?></legend>
				<?php foreach ( $styles as $value => $style ) : ?>
					<label class="flexo-choice-card"><input type="radio" name="<?php echo esc_attr( $opt ); ?>[header_style]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $all['header_style'], $value ); ?>><strong><?php echo esc_html( $style[0] ); ?></strong><span><?php echo esc_html( $style[1] ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
			<table class="form-table" role="presentation">
				<tr class="flexo-if-header flexo-if-header--space">
					<th scope="row"><?php esc_html_e( 'Space above the page', 'flexo-booking' ); ?></th>
					<td class="flexo-device-row">
						<?php foreach ( array( 'desktop' => __( 'Computer', 'flexo-booking' ), 'tablet' => __( 'Tablet', 'flexo-booking' ), 'mobile' => __( 'Phone', 'flexo-booking' ) ) as $device => $label ) : ?>
							<label><span><?php echo esc_html( $label ); ?></span> <input type="number" min="0" max="400" class="small-text" name="<?php echo esc_attr( $opt ); ?>[header_space_<?php echo esc_attr( $device ); ?>]" value="<?php echo esc_attr( $all[ 'header_space_' . $device ] ); ?>" placeholder="<?php esc_attr_e( 'auto', 'flexo-booking' ); ?>"> px</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Leave empty to measure the header automatically.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
				<tr class="flexo-if-header flexo-if-header--band">
					<th scope="row"><?php esc_html_e( 'Title band', 'flexo-booking' ); ?></th>
					<td>
						<p class="flexo-color-pair">
							<label><?php esc_html_e( 'Background', 'flexo-booking' ); ?> <input type="color" name="<?php echo esc_attr( $opt ); ?>[header_band_bg]" value="<?php echo esc_attr( $all['header_band_bg'] ); ?>"></label>
							<label><?php esc_html_e( 'Title', 'flexo-booking' ); ?> <input type="color" name="<?php echo esc_attr( $opt ); ?>[header_band_text]" value="<?php echo esc_attr( $all['header_band_text'] ); ?>"></label>
						</p>
						<p class="flexo-band-image" data-flexo-media>
							<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[header_band_image]" value="<?php echo esc_attr( $image ); ?>" data-flexo-media-id>
							<span class="flexo-band-image__preview" data-flexo-media-preview><?php echo $image ? wp_get_attachment_image( $image, 'thumbnail' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core output. ?></span>
							<button type="button" class="button" data-flexo-media-choose><?php esc_html_e( 'Choose a picture (optional)', 'flexo-booking' ); ?></button>
							<button type="button" class="button-link" data-flexo-media-remove <?php echo $image ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove picture', 'flexo-booking' ); ?></button>
						</p>
						<div class="flexo-device-row">
							<?php foreach ( array( 'desktop' => __( 'Computer', 'flexo-booking' ), 'tablet' => __( 'Tablet', 'flexo-booking' ), 'mobile' => __( 'Phone', 'flexo-booking' ) ) as $device => $label ) : ?>
								<label><span><?php echo esc_html( $label ); ?></span> <input type="number" min="80" max="800" class="small-text" name="<?php echo esc_attr( $opt ); ?>[header_band_height_<?php echo esc_attr( $device ); ?>]" value="<?php echo esc_attr( $all[ 'header_band_height_' . $device ] ); ?>"> px</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Height of the band. Your header lies over its top part; the title sits at the bottom.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'Elementor Pro: header and footer templates shown on "Entire Site" appear on the Flexo pages too. To show a template only there, add the condition "Flexo Booking pages" (under General).', 'flexo-booking' ); ?></p>
		</section>
		<?php
	}
}
