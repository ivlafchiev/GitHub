<?php
/**
 * Bookings → Appearance: "Match my website" or Custom colours, corners,
 * fonts and text size, with a live preview of the real form and contrast
 * warnings. Emails use the logo and (in Custom mode) the main colour.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Appearance_Admin {

	const SLUG = 'flexo-booking-appearance';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_flexo_booking_appearance_css', array( __CLASS__, 'ajax_css' ) );
		add_action( 'admin_post_flexo_booking_appearance_reset', array( __CLASS__, 'handle_reset' ) );
	}

	public static function capability() {
		return Flexo_Booking_Admin::cap( 'appearance' );
	}

	public static function menu() {
		if ( Flexo_Booking_Appearance::enabled() ) {
			add_submenu_page( Flexo_Booking_Admin::MENU_SLUG, __( 'Appearance of the booking form', 'flexo-booking' ), __( 'Appearance', 'flexo-booking' ), self::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'flexo-booking-appearance', FLEXO_BOOKING_URL . 'assets/js/admin-appearance.js', array(), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			'flexo-booking-appearance',
			'FlexoAppearance',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'flexo_booking_appearance_css' ),
				'i18n'    => array(
					/* translators: 1: contrast ratio, 2: suggested colour */
					'textBg'      => __( 'Text and background colours are hard to read together (contrast %1$s:1, at least 4.5:1 is needed). Try a darker text colour, e.g. %2$s.', 'flexo-booking' ),
					/* translators: 1: contrast ratio, 2: suggested colour */
					'buttonText'  => __( 'Button text is hard to read on the main colour (contrast %1$s:1, at least 4.5:1 is needed). Use %2$s for the button text, or a darker main colour.', 'flexo-booking' ),
					/* translators: 1: what is hard to read, 2: contrast ratio, 3: suggested colour */
					'contrast'    => __( '%1$s: hard to read (contrast %2$s:1, at least 4.5:1 is needed). Try %3$s.', 'flexo-booking' ),
					'chooseImage' => __( 'Choose a picture', 'flexo-booking' ),
					'useImage'    => __( 'Use this picture', 'flexo-booking' ),
					'resetSure'   => __( 'Go back to your website\'s colours and fonts? Your custom choices are removed.', 'flexo-booking' ),
					'chooseLogo'  => __( 'Choose the logo for emails', 'flexo-booking' ),
				),
			)
		);
	}

	/**
	 * CSS and contrast problems for the unsaved form values (live preview).
	 * Built by the same code as the real form, so the preview matches it.
	 */
	public static function ajax_css() {
		check_ajax_referer( 'flexo_booking_appearance_css' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$raw      = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitised in values().
		$settings = array();
		foreach ( array_keys( Flexo_Booking_Settings::defaults() ) as $key ) {
			if ( 0 === strpos( $key, 'appearance_' ) && 'appearance_mode' !== $key ) {
				$settings[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
			}
		}
		$custom = isset( $raw['appearance_mode'] ) && 'custom' === $raw['appearance_mode'];
		wp_send_json_success(
			array(
				'css'      => $custom ? Flexo_Booking_Appearance::css( $settings, true ) : '',
				'problems' => $custom ? Flexo_Booking_Appearance::contrast_problems( $settings ) : array(),
			)
		);
	}

	public static function handle_reset() {
		check_admin_referer( 'flexo_booking_appearance_reset' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'flexo-booking' ) );
		}
		$defaults = Flexo_Booking_Settings::defaults();
		$settings = Flexo_Booking_Settings::all();
		foreach ( $defaults as $key => $value ) {
			if ( 0 === strpos( $key, 'appearance_' ) ) {
				$settings[ $key ] = $value;
			}
		}
		update_option( Flexo_Booking_Settings::OPTION, $settings );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&reset=1' ) );
		exit;
	}

	private static function color_row( $key, $label, $help, array $s, $name ) {
		$value = Flexo_Booking_Appearance::sanitize_color( $s[ $key ] );
		$id    = 'fb-' . str_replace( '_', '-', $key );
		?>
		<tr class="flexo-color-row" data-key="<?php echo esc_attr( $key ); ?>">
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<label class="flexo-inline-check"><input type="checkbox" class="flexo-color-website" <?php checked( '' === $value ); ?>> <?php esc_html_e( 'Use website colour', 'flexo-booking' ); ?></label>
				<span class="flexo-color-pick">
					<input type="color" class="flexo-color-picker" value="<?php echo esc_attr( '' !== $value ? $value : '#1f6f5c' ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
					<input id="<?php echo esc_attr( $id ); ?>" type="text" class="flexo-color-hex code" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="#1f6f5c" maxlength="7" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
				</span>
				<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * A choice (select) row for the 1.9.0 settings.
	 */
	private static function choice_row( $key, $label, $help, array $s, $name ) {
		$choice = substr( $key, 11 );
		$id     = 'fb-' . str_replace( '_', '-', $key );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>">
					<?php foreach ( Flexo_Booking_Appearance::choices( $choice ) as $value => $option ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $s[ $key ], (string) $value ); ?>><?php echo esc_html( $option[0] ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( 'appearance_max_width' === $key ) : ?>
					<span class="flexo-width-px" data-flexo-show-when="<?php echo esc_attr( $id ); ?>=custom"><input type="number" min="600" max="2000" step="10" class="small-text" name="<?php echo esc_attr( $name ); ?>[appearance_max_width_px]" value="<?php echo esc_attr( $s['appearance_max_width_px'] ); ?>" aria-label="<?php esc_attr_e( 'Width in pixels', 'flexo-booking' ); ?>"> px</span>
				<?php endif; ?>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * A picture from the media library (attachment ID), with "Remove".
	 */
	public static function image_field( $field_name, $image, $label ) {
		$image = (int) $image;
		?>
		<span class="flexo-band-image" data-flexo-media>
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $image ); ?>" data-flexo-media-id>
			<span class="flexo-band-image__preview" data-flexo-media-preview><?php echo $image ? wp_get_attachment_image( $image, 'thumbnail' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core output. ?></span>
			<button type="button" class="button" data-flexo-media-choose><?php echo esc_html( $label ); ?></button>
			<button type="button" class="button-link" data-flexo-media-remove <?php echo $image ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove picture', 'flexo-booking' ); ?></button>
		</span>
		<?php
	}

	/**
	 * Booking and Thank You pages, text, buttons and fields, steps,
	 * summary, confirmation and tabs (1.9.0). The pages' group is open,
	 * the others are folded.
	 */
	private static function render_page_groups( array $s, $name ) {
		?>
		<details class="flexo-appearance__group" open>
			<summary><?php esc_html_e( 'Booking and Thank You pages', 'flexo-booking' ); ?></summary>
			<p class="description"><?php esc_html_e( 'The whole page area between your header and footer on the built-in Booking and Thank You pages. Each page can also have its own look under Settings → Pages.', 'flexo-booking' ); ?></p>
			<table class="form-table" role="presentation">
				<?php self::color_row( 'appearance_page_bg', __( 'Page background', 'flexo-booking' ), __( 'Behind everything on the page – for example a pale sand or sea colour.', 'flexo-booking' ), $s, $name ); ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Background picture', 'flexo-booking' ); ?></th>
					<td>
						<?php self::image_field( $name . '[appearance_page_image]', $s['appearance_page_image'], __( 'Choose a picture', 'flexo-booking' ) ); ?>
						<p><label for="fb-appearance-page-image-effect"><?php esc_html_e( 'Picture effect', 'flexo-booking' ); ?></label>
							<select id="fb-appearance-page-image-effect" name="<?php echo esc_attr( $name ); ?>[appearance_page_image_effect]">
								<?php foreach ( Flexo_Booking_Appearance::choices( 'page_image_effect' ) as $value => $option ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['appearance_page_image_effect'], $value ); ?>><?php echo esc_html( $option[0] ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p class="description"><?php esc_html_e( 'Covers the page area behind the cards. Soften or darken it so the text stays easy to read.', 'flexo-booking' ); ?></p>
					</td>
				</tr>
				<?php
				self::choice_row( 'appearance_max_width', __( 'Content width', 'flexo-booking' ), __( 'How wide the booking area and the confirmation can get on large screens.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_card_bg', __( 'Card background', 'flexo-booking' ), __( 'The booking form, the help boxes and the confirmation sit on cards.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_card_border', __( 'Card border', 'flexo-booking' ), __( 'The line around the cards.', 'flexo-booking' ), $s, $name );
				?>
				<tr>
					<th scope="row"></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>[appearance_card_no_border]" value="0">
						<label class="flexo-inline-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[appearance_card_no_border]" value="1" <?php checked( ! empty( $s['appearance_card_no_border'] ) ); ?>> <?php esc_html_e( 'No border around the cards', 'flexo-booking' ); ?></label>
					</td>
				</tr>
				<?php
				self::choice_row( 'appearance_card_corners', __( 'Card corners', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_card_shadow', __( 'Card shadow', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_spacing', __( 'Space between sections', 'flexo-booking' ), '', $s, $name );
				?>
			</table>
		</details>

		<details class="flexo-appearance__group">
			<summary><?php esc_html_e( 'Text', 'flexo-booking' ); ?></summary>
			<table class="form-table" role="presentation">
				<?php
				self::color_row( 'appearance_heading_color', __( 'Heading colour', 'flexo-booking' ), __( 'Page titles, "Your details", room names and the confirmation heading.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_muted', __( 'Muted text colour', 'flexo-booking' ), __( 'Introductions, notes and small print.', 'flexo-booking' ), $s, $name );
				?>
			</table>
			<p class="description"><?php esc_html_e( 'The main text colour and the fonts are set above.', 'flexo-booking' ); ?></p>
		</details>

		<details class="flexo-appearance__group">
			<summary><?php esc_html_e( 'Buttons and fields', 'flexo-booking' ); ?></summary>
			<table class="form-table" role="presentation">
				<?php
				self::color_row( 'appearance_button_hover', __( 'Button colour under the mouse', 'flexo-booking' ), __( 'Empty: the main colour, slightly lighter.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_secondary', __( 'Outline buttons', 'flexo-booking' ), __( 'Buttons such as "Back" and "Add to calendar".', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_field_bg', __( 'Field background', 'flexo-booking' ), __( 'Where guests type their details. Empty: the background colour above.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_field_border', __( 'Field border', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_focus', __( 'Field in use', 'flexo-booking' ), __( 'The outline of the field the guest is typing in.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_label', __( 'Field labels', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_control_size', __( 'Field and button height', 'flexo-booking' ), __( 'Large is easier to tap on phones.', 'flexo-booking' ), $s, $name );
				?>
			</table>
		</details>

		<details class="flexo-appearance__group">
			<summary><?php esc_html_e( 'Booking steps and summary', 'flexo-booking' ); ?></summary>
			<table class="form-table" role="presentation">
				<?php
				self::color_row( 'appearance_step_active', __( 'Current step', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_step_done', __( 'Completed steps', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_step_inactive', __( 'Next steps', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_summary_bg', __( 'Reservation summary', 'flexo-booking' ), __( 'Background of the stay and price summary.', 'flexo-booking' ), $s, $name );
				?>
			</table>
		</details>

		<details class="flexo-appearance__group">
			<summary><?php esc_html_e( 'Confirmation (Thank You page)', 'flexo-booking' ); ?></summary>
			<table class="form-table" role="presentation">
				<?php
				self::color_row( 'appearance_success', __( 'Success icon', 'flexo-booking' ), __( 'The tick at the top of the confirmation.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_status_confirmed', __( 'Status: confirmed', 'flexo-booking' ), __( 'The status label\'s colour; its text turns dark or white by itself.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_status_awaiting', __( 'Status: awaiting payment', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_status_request', __( 'Status: request received', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_stay_bg', __( 'Stay summary', 'flexo-booking' ), __( 'Background of the room, dates and price list.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_bank_bg', __( 'Bank transfer box', 'flexo-booking' ), '', $s, $name );
				?>
			</table>
		</details>

		<details class="flexo-appearance__group">
			<summary><?php esc_html_e( 'Tabs (Booking & Contact)', 'flexo-booking' ); ?></summary>
			<p class="description"><?php esc_html_e( 'For the Booking & Contact tabs that come with the Contact page.', 'flexo-booking' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				self::color_row( 'appearance_tab_bg', __( 'Tab background', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_text', __( 'Tab text', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_active_bg', __( 'Selected tab background', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_active_text', __( 'Selected tab text', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_tab_corners', __( 'Tab corners', 'flexo-booking' ), '', $s, $name );
				?>
			</table>
		</details>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$s    = Flexo_Booking_Settings::all();
		$name = Flexo_Booking_Settings::OPTION;
		?>
		<div class="wrap flexo-admin flexo-appearance">
			<?php
			Flexo_Booking_Admin_UI::page_head(
				array(
					'title' => __( 'Appearance of the booking form', 'flexo-booking' ),
					'icon'  => 'palette',
					'intro' => esc_html__( 'Let the booking form match your website, or give it your own colours and fonts. Only the booking form and your emails change.', 'flexo-booking' ),
				)
			);
			?>
			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance saved.', 'flexo-booking' ); ?></p></div>
			<?php elseif ( isset( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The booking form matches your website again.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>
			<div class="flexo-appearance__layout">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="flexo-appearance__form" id="flexo-appearance-form">
					<?php settings_fields( 'flexo_booking' ); ?>
					<h2><?php esc_html_e( 'Style', 'flexo-booking' ); ?></h2>
					<fieldset class="flexo-appearance__modes">
						<legend class="screen-reader-text"><?php esc_html_e( 'Style', 'flexo-booking' ); ?></legend>
						<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[appearance_mode]" value="match" <?php checked( $s['appearance_mode'], 'match' ); ?>> <strong><?php esc_html_e( 'Match my website', 'flexo-booking' ); ?></strong> <span class="flexo-badge"><?php esc_html_e( 'Recommended', 'flexo-booking' ); ?></span><br><span class="description"><?php esc_html_e( 'The form uses your website\'s colours and fonts (Elementor global colours and fonts, or your theme\'s). It changes automatically when your website does.', 'flexo-booking' ); ?></span></label>
						<label class="flexo-feature-choice"><input type="radio" name="<?php echo esc_attr( $name ); ?>[appearance_mode]" value="custom" <?php checked( $s['appearance_mode'], 'custom' ); ?>> <strong><?php esc_html_e( 'Custom', 'flexo-booking' ); ?></strong><br><span class="description"><?php esc_html_e( 'Choose a few colours and fonts for the booking form only. The rest of your website is not changed.', 'flexo-booking' ); ?></span></label>
					</fieldset>

					<div class="flexo-appearance__custom">
						<div class="flexo-appearance__warnings" role="status" aria-live="polite"></div>
						<table class="form-table" role="presentation">
							<?php
							self::color_row( 'appearance_primary', __( 'Main colour', 'flexo-booking' ), __( 'Buttons, selected options and links.', 'flexo-booking' ), $s, $name );
							self::color_row( 'appearance_accent', __( 'Accent colour', 'flexo-booking' ), __( 'Badges and highlights such as "Only 2 left!" and discounts.', 'flexo-booking' ), $s, $name );
							self::color_row( 'appearance_text', __( 'Text colour', 'flexo-booking' ), __( 'Text in the booking area.', 'flexo-booking' ), $s, $name );
							self::color_row( 'appearance_bg', __( 'Background colour', 'flexo-booking' ), __( 'Background of the booking area and its fields.', 'flexo-booking' ), $s, $name );
							self::color_row( 'appearance_button_text', __( 'Button text colour', 'flexo-booking' ), __( 'Text on buttons in the main colour.', 'flexo-booking' ), $s, $name );
							?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Corners', 'flexo-booking' ); ?></th>
								<td>
									<?php foreach ( Flexo_Booking_Appearance::corners() as $flexo_key => $flexo_corner ) : ?>
										<label class="flexo-inline-check flexo-corner flexo-corner--<?php echo esc_attr( $flexo_key ); ?>"><input type="radio" name="<?php echo esc_attr( $name ); ?>[appearance_corners]" value="<?php echo esc_attr( $flexo_key ); ?>" <?php checked( $s['appearance_corners'], $flexo_key ); ?>> <span class="flexo-corner__sample" style="border-radius:<?php echo esc_attr( $flexo_corner[1] ); ?>"></span> <?php echo esc_html( $flexo_corner[0] ); ?></label>
									<?php endforeach; ?>
								</td>
							</tr>
							<?php
							$flexo_font_rows = array(
								'appearance_heading_font' => __( 'Headings font', 'flexo-booking' ),
								'appearance_body_font'    => __( 'Text font', 'flexo-booking' ),
							);
							foreach ( $flexo_font_rows as $flexo_key => $flexo_label ) :
								?>
								<tr>
									<th scope="row"><label for="fb-<?php echo esc_attr( $flexo_key ); ?>"><?php echo esc_html( $flexo_label ); ?></label></th>
									<td>
										<select id="fb-<?php echo esc_attr( $flexo_key ); ?>" name="<?php echo esc_attr( $name . '[' . $flexo_key . ']' ); ?>" data-flexo-font-select>
											<option value=""><?php esc_html_e( 'Website font', 'flexo-booking' ); ?></option>
											<?php $flexo_el_fonts = Flexo_Booking_Appearance::elementor_fonts(); ?>
											<?php if ( $flexo_el_fonts ) : ?>
												<optgroup label="<?php esc_attr_e( 'Your website\'s fonts (Elementor)', 'flexo-booking' ); ?>">
													<?php foreach ( $flexo_el_fonts as $flexo_id => $flexo_def ) : ?>
														<option value="<?php echo esc_attr( 'el:' . $flexo_id ); ?>" <?php selected( $s[ $flexo_key ], 'el:' . $flexo_id ); ?>><?php echo esc_html( $flexo_def[0] . ( '' !== $flexo_def[1] ? ' – ' . $flexo_def[1] : '' ) ); ?></option>
													<?php endforeach; ?>
												</optgroup>
											<?php endif; ?>
											<optgroup label="<?php esc_attr_e( 'Included with the plugin', 'flexo-booking' ); ?>">
												<?php foreach ( Flexo_Booking_Appearance::fonts() as $flexo_font => $flexo_def ) : ?>
													<option value="<?php echo esc_attr( $flexo_font ); ?>" <?php selected( $s[ $flexo_key ], $flexo_font ); ?>><?php echo esc_html( $flexo_def[0] ); ?></option>
												<?php endforeach; ?>
											</optgroup>
											<option value="custom" <?php selected( $s[ $flexo_key ], 'custom' ); ?>><?php esc_html_e( 'Another font (type its name)…', 'flexo-booking' ); ?></option>
										</select>
										<input type="text" class="regular-text flexo-font-name" name="<?php echo esc_attr( $name . '[' . $flexo_key . '_name]' ); ?>" value="<?php echo esc_attr( $s[ $flexo_key . '_name' ] ); ?>" placeholder="<?php esc_attr_e( 'e.g. DM Sans', 'flexo-booking' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Headings font / Text font */ __( '%s: font name', 'flexo-booking' ), $flexo_label ) ); ?>" data-flexo-font-name <?php echo 'custom' === $s[ $flexo_key ] ? '' : 'hidden'; ?>>
									</td>
								</tr>
							<?php endforeach; ?>
							<tr>
								<th scope="row"></th>
								<td><p class="description"><?php esc_html_e( 'Your website\'s fonts are the ones set in Elementor (Site Settings → Global Fonts). The fonts included with the plugin work with Cyrillic and are loaded from your own website – never from Google (GDPR). "Another font" uses a font your website already loads (theme, Elementor or a fonts plugin) – type its name exactly.', 'flexo-booking' ); ?></p></td>
							</tr>
							<tr>
								<th scope="row"><label for="fb-appearance-size"><?php esc_html_e( 'Text size', 'flexo-booking' ); ?></label></th>
								<td>
									<select id="fb-appearance-size" name="<?php echo esc_attr( $name ); ?>[appearance_text_size]">
										<?php foreach ( Flexo_Booking_Appearance::text_sizes() as $flexo_key => $flexo_size ) : ?>
											<option value="<?php echo esc_attr( $flexo_key ); ?>" <?php selected( $s['appearance_text_size'], $flexo_key ); ?>><?php echo esc_html( $flexo_size[0] ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>
						<h3><?php esc_html_e( '"Check availability" panel', 'flexo-booking' ); ?></h3>
						<p class="description"><?php esc_html_e( 'The panel that opens over a room page with the room\'s calendar. Its text, fonts, buttons and corners follow the settings above.', 'flexo-booking' ); ?></p>
						<table class="form-table" role="presentation">
							<?php self::color_row( 'appearance_panel_bg', __( 'Panel background', 'flexo-booking' ), __( 'Leave empty to use the background colour above.', 'flexo-booking' ), $s, $name ); ?>
							<tr>
								<th scope="row"><label for="fb-appearance-overlay"><?php esc_html_e( 'Page behind the panel', 'flexo-booking' ); ?></label></th>
								<td>
									<select id="fb-appearance-overlay" name="<?php echo esc_attr( $name ); ?>[appearance_overlay]">
										<?php foreach ( Flexo_Booking_Appearance::overlays() as $flexo_key => $flexo_overlay ) : ?>
											<option value="<?php echo esc_attr( $flexo_key ); ?>" <?php selected( $s['appearance_overlay'], $flexo_key ); ?>><?php echo esc_html( $flexo_overlay[0] ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'How much the page is darkened while the panel is open.', 'flexo-booking' ); ?></p>
								</td>
							</tr>
						</table>
						<?php self::render_page_groups( $s, $name ); ?>
						<p class="description"><?php esc_html_e( 'Which setting wins: an Elementor Flexo Booking widget\'s Style tab, then a page\'s own look (Settings → Pages), then these settings, then your website\'s global colours and fonts.', 'flexo-booking' ); ?></p>
					</div>

					<h2><?php esc_html_e( 'Emails', 'flexo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="fb-email-logo"><?php esc_html_e( 'Logo', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fb-email-logo" type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[email_logo]" value="<?php echo esc_attr( $s['email_logo'] ); ?>" placeholder="<?php echo esc_attr( Flexo_Booking_Emails::logo_url() ); ?>">
								<button type="button" class="button flexo-choose-logo"><?php esc_html_e( 'Choose image', 'flexo-booking' ); ?></button>
								<p class="description"><?php esc_html_e( 'Shown at the top of every email. Leave empty to use your website logo.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
						<tr class="flexo-email-color-row">
							<th scope="row"><label for="fb-email-color"><?php esc_html_e( 'Email colour', 'flexo-booking' ); ?></label></th>
							<td>
								<input id="fb-email-color" type="color" name="<?php echo esc_attr( $name ); ?>[email_color]" value="<?php echo esc_attr( $s['email_color'] ); ?>">
								<p class="description"><?php esc_html_e( 'In Custom style, emails use your main colour instead.', 'flexo-booking' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save appearance', 'flexo-booking' ) ); ?>
				</form>

				<div class="flexo-appearance__preview">
					<div class="flexo-appearance__preview-bar">
						<strong><?php esc_html_e( 'Preview', 'flexo-booking' ); ?></strong>
						<span class="flexo-segmented" role="group" aria-label="<?php esc_attr_e( 'What to preview', 'flexo-booking' ); ?>">
							<?php
							$flexo_scenes = array(
								'form'      => array( __( 'Booking form', 'flexo-booking' ), '' ),
								'booking'   => array( __( 'Booking page', 'flexo-booking' ), Flexo_Booking_System_Pages::uses_builtin( 'booking' ) ? Flexo_Booking_System_Pages::builtin_url( 'booking' ) : '' ),
								'thank_you' => array( __( 'Thank You page', 'flexo-booking' ), Flexo_Booking_System_Pages::uses_builtin( 'thank_you' ) ? Flexo_Booking_Confirmation::preview_url( 'transfer' ) : '' ),
							);
							foreach ( $flexo_scenes as $flexo_scene => $flexo_def ) :
								?>
								<button type="button" class="button<?php echo 'form' === $flexo_scene ? ' is-active' : ''; ?>" data-scene="<?php echo esc_attr( Flexo_Booking_Appearance::preview_url( $flexo_scene ) ); ?>" data-open="<?php echo esc_attr( $flexo_def[1] ); ?>" aria-pressed="<?php echo 'form' === $flexo_scene ? 'true' : 'false'; ?>"><?php echo esc_html( $flexo_def[0] ); ?></button>
							<?php endforeach; ?>
						</span>
						<a class="button flexo-preview-open" href="#" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Open page', 'flexo-booking' ); ?></a>
						<span class="flexo-segmented" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'flexo-booking' ); ?>">
							<button type="button" class="button is-active" data-width="100%" aria-pressed="true"><?php esc_html_e( 'Desktop', 'flexo-booking' ); ?></button>
							<button type="button" class="button" data-width="390px" aria-pressed="false"><?php esc_html_e( 'Phone', 'flexo-booking' ); ?></button>
						</span>
					</div>
					<div class="flexo-appearance__frame-wrap">
						<iframe id="flexo-appearance-frame" title="<?php esc_attr_e( 'Preview of the booking form', 'flexo-booking' ); ?>" src="<?php echo esc_url( Flexo_Booking_Appearance::preview_url() ); ?>"></iframe>
					</div>
					<p class="description"><?php esc_html_e( 'The preview uses your real website styles and the booking form\'s own design. Changes show here before you save.', 'flexo-booking' ); ?></p>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flexo-appearance__reset">
				<input type="hidden" name="action" value="flexo_booking_appearance_reset">
				<?php wp_nonce_field( 'flexo_booking_appearance_reset' ); ?>
				<button type="submit" class="button flexo-reset-appearance"><?php esc_html_e( 'Reset to website style', 'flexo-booking' ); ?></button>
			</form>
		</div>
		<?php
	}
}
