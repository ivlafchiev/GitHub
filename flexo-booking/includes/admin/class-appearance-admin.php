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

	/**
	 * Where each colour comes from when it is not set (shown in the
	 * "Website colour" state of its field): array( from, mix %, note ) or
	 * array( '', 0, note, hex ).
	 */
	private static function inherits() {
		$main  = __( 'Same as the main colour', 'flexo-booking' );
		$text  = __( 'Same as the text colour', 'flexo-booking' );
		$bg    = __( 'Same as the background colour', 'flexo-booking' );
		$line  = __( 'A light line in the text colour', 'flexo-booking' );
		return array(
			'appearance_accent'           => array( 'appearance_primary', 0, $main ),
			'appearance_heading_color'    => array( 'appearance_text', 0, $text ),
			'appearance_muted'            => array( 'appearance_text', 65, __( 'The text colour, lighter', 'flexo-booking' ) ),
			'appearance_button_hover'     => array( 'appearance_primary', 0, $main ),
			'appearance_secondary'        => array( 'appearance_primary', 0, $main ),
			'appearance_field_bg'         => array( 'appearance_bg', 0, $bg ),
			'appearance_field_border'     => array( 'appearance_text', 18, $line ),
			'appearance_focus'            => array( 'appearance_primary', 0, $main ),
			'appearance_label'            => array( 'appearance_text', 0, $text ),
			'appearance_step_active'      => array( 'appearance_primary', 0, $main ),
			'appearance_step_done'        => array( 'appearance_primary', 0, $main ),
			'appearance_step_inactive'    => array( 'appearance_text', 18, $line ),
			'appearance_summary_bg'       => array( 'appearance_primary', 8, __( 'The main colour, very pale', 'flexo-booking' ) ),
			'appearance_success'          => array( 'appearance_primary', 0, $main ),
			'appearance_status_confirmed' => array( 'appearance_primary', 14, __( 'The main colour, pale', 'flexo-booking' ) ),
			'appearance_status_awaiting'  => array( '', 0, __( 'Pale orange (built in)', 'flexo-booking' ), '#fff4e5' ),
			'appearance_status_request'   => array( '', 0, __( 'Pale orange (built in)', 'flexo-booking' ), '#fff4e5' ),
			'appearance_stay_bg'          => array( '', 0, __( 'No background', 'flexo-booking' ), '' ),
			'appearance_bank_bg'          => array( 'appearance_field_bg', 0, __( 'Same as the field background', 'flexo-booking' ) ),
			'appearance_tab_bg'           => array( 'appearance_text', 6, __( 'The text colour, very pale', 'flexo-booking' ) ),
			'appearance_tab_text'         => array( 'appearance_text', 0, $text ),
			'appearance_tab_active_bg'    => array( 'appearance_primary', 0, $main ),
			'appearance_tab_active_text'  => array( 'appearance_button_text', 0, __( 'Same as the button text colour', 'flexo-booking' ) ),
			'appearance_page_bg'          => array( '', 0, __( 'Your website\'s page background', 'flexo-booking' ), '' ),
			'appearance_card_bg'          => array( 'appearance_bg', 0, $bg ),
			'appearance_card_border'      => array( 'appearance_text', 12, $line ),
			'appearance_panel_bg'         => array( 'appearance_bg', 0, $bg ),
		);
	}

	/**
	 * A colour setting: label and help on the left, "Website colour | Custom" on the right.
	 */
	private static function color_row( $key, $label, $help, array $s, $name ) {
		$id      = 'fb-' . str_replace( '_', '-', $key );
		$web     = Flexo_Booking_UI::website_colors();
		$inherit = self::inherits();
		$args    = array(
			'name'        => $name . '[' . $key . ']',
			'value'       => $s[ $key ],
			'id'          => $id,
			'label'       => $label,
			'key'         => $key,
			'describedby' => '' !== $help ? $id . '-help' : '',
		);
		if ( 'appearance_primary' === $key ) {
			$args['inherit'] = $web['primary'];
		} elseif ( 'appearance_text' === $key ) {
			$args['inherit'] = $web['text'];
		} elseif ( in_array( $key, array( 'appearance_bg', 'appearance_button_text' ), true ) ) {
			$args['inherit'] = $web['white'];
		} elseif ( isset( $inherit[ $key ] ) ) {
			$def            = $inherit[ $key ];
			$args['from']   = $def[0];
			$args['mix']    = $def[1];
			$args['note']   = $def[2];
			$args['inherit'] = array( isset( $def[3] ) ? $def[3] : '', '' );
		}
		Flexo_Booking_UI::row_open(
			array(
				'label' => $label,
				'for'   => '',
				'desc'  => $help,
				'class' => 'flexo-color-row',
				'attrs' => array( 'data-key' => $key ),
			)
		);
		if ( '' !== $help ) {
			echo '<span class="screen-reader-text" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</span>';
		}
		Flexo_Booking_UI::color_field( $args );
		Flexo_Booking_UI::row_close();
	}

	/**
	 * A choice (select) row for the 1.9.0 settings.
	 */
	private static function choice_row( $key, $label, $help, array $s, $name ) {
		$choice = substr( $key, 11 );
		$id     = 'fb-' . str_replace( '_', '-', $key );
		Flexo_Booking_UI::row_open( array( 'label' => $label, 'for' => $id, 'desc' => $help ) );
		?>
		<span class="fx-inline">
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>">
				<?php foreach ( Flexo_Booking_Appearance::choices( $choice ) as $value => $option ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $s[ $key ], (string) $value ); ?>><?php echo esc_html( $option[0] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( 'appearance_max_width' === $key ) : ?>
				<span class="flexo-width-px flexo-input-unit" data-flexo-show-when="<?php echo esc_attr( $id ); ?>=custom"><input type="number" min="600" max="2000" step="10" class="small-text" name="<?php echo esc_attr( $name ); ?>[appearance_max_width_px]" value="<?php echo esc_attr( $s['appearance_max_width_px'] ); ?>" aria-label="<?php esc_attr_e( 'Width in pixels', 'flexo-booking' ); ?>"><span>px</span></span>
			<?php endif; ?>
		</span>
		<?php
		Flexo_Booking_UI::row_close();
	}

	/**
	 * A select row (fonts, text size, overlay).
	 */
	private static function select_row( $id, $field, $label, $help, array $options, $current ) {
		Flexo_Booking_UI::row_open( array( 'label' => $label, 'for' => $id, 'desc' => $help ) );
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $field ) . '">';
		foreach ( $options as $value => $option ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $option[0] ) . '</option>';
		}
		echo '</select>';
		Flexo_Booking_UI::row_close();
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
	 * A folded group of settings (a collapsible card).
	 */
	private static function group( $title, $desc, $open, $callback ) {
		Flexo_Booking_UI::card_open(
			array(
				'title'       => $title,
				'desc'        => $desc,
				'collapsible' => true,
				'open'        => $open,
				'class'       => 'flexo-appearance__group',
			)
		);
		call_user_func( $callback );
		Flexo_Booking_UI::card_close();
	}

	/**
	 * Custom style: Brand, Text, Buttons & forms, Booking steps,
	 * Confirmation, Tabs, Pages & cards, availability panel.
	 */
	private static function render_custom( array $s, $name ) {
		self::group(
			__( 'Brand', 'flexo-booking' ),
			__( 'Main colours, corners and fonts.', 'flexo-booking' ),
			true,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_primary', __( 'Main colour', 'flexo-booking' ), __( 'Buttons, selected options and links.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_accent', __( 'Accent colour', 'flexo-booking' ), __( 'Badges and highlights such as "Only 2 left!" and discounts.', 'flexo-booking' ), $s, $name );
				Flexo_Booking_UI::row_open( array( 'label' => __( 'Corners', 'flexo-booking' ) ) );
				$corners = array();
				foreach ( Flexo_Booking_Appearance::corners() as $key => $corner ) {
					$corners[ $key ] = $corner[0];
				}
				echo Flexo_Booking_UI::segmented_html( $name . '[appearance_corners]', $corners, $s['appearance_corners'], __( 'Corners', 'flexo-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
				Flexo_Booking_UI::row_close();
				$fonts = array(
					'appearance_heading_font' => __( 'Headings font', 'flexo-booking' ),
					'appearance_body_font'    => __( 'Text font', 'flexo-booking' ),
				);
				$el_fonts = Flexo_Booking_Appearance::elementor_fonts();
				foreach ( $fonts as $key => $label ) {
					$id = 'fb-' . $key;
					Flexo_Booking_UI::row_open( array( 'label' => $label, 'for' => $id ) );
					?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" data-flexo-font-select>
						<option value=""><?php esc_html_e( 'Website font', 'flexo-booking' ); ?></option>
						<?php if ( $el_fonts ) : ?>
							<optgroup label="<?php esc_attr_e( 'Your website\'s fonts (Elementor)', 'flexo-booking' ); ?>">
								<?php foreach ( $el_fonts as $font_id => $def ) : ?>
									<option value="<?php echo esc_attr( 'el:' . $font_id ); ?>" <?php selected( $s[ $key ], 'el:' . $font_id ); ?>><?php echo esc_html( $def[0] . ( '' !== $def[1] ? ' – ' . $def[1] : '' ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
						<optgroup label="<?php esc_attr_e( 'Included with the plugin', 'flexo-booking' ); ?>">
							<?php foreach ( Flexo_Booking_Appearance::fonts() as $font => $def ) : ?>
								<option value="<?php echo esc_attr( $font ); ?>" <?php selected( $s[ $key ], $font ); ?>><?php echo esc_html( $def[0] ); ?></option>
							<?php endforeach; ?>
						</optgroup>
						<option value="custom" <?php selected( $s[ $key ], 'custom' ); ?>><?php esc_html_e( 'Another font (type its name)…', 'flexo-booking' ); ?></option>
					</select>
					<input type="text" class="regular-text flexo-font-name" name="<?php echo esc_attr( $name . '[' . $key . '_name]' ); ?>" value="<?php echo esc_attr( $s[ $key . '_name' ] ); ?>" placeholder="<?php esc_attr_e( 'e.g. DM Sans', 'flexo-booking' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Headings font / Text font */ __( '%s: font name', 'flexo-booking' ), $label ) ); ?>" data-flexo-font-name <?php echo 'custom' === $s[ $key ] ? '' : 'hidden'; ?>>
					<?php
					Flexo_Booking_UI::row_close();
				}
				self::select_row( 'fb-appearance-size', $name . '[appearance_text_size]', __( 'Text size', 'flexo-booking' ), '', Flexo_Booking_Appearance::text_sizes(), $s['appearance_text_size'] );
				echo '<p class="fx-help flexo-appearance__fonts-note">' . esc_html__( 'Your website\'s fonts are the ones set in Elementor (Site Settings → Global Fonts). The fonts included with the plugin work with Cyrillic and are loaded from your own website – never from Google (GDPR). "Another font" uses a font your website already loads (theme, Elementor or a fonts plugin) – type its name exactly.', 'flexo-booking' ) . '</p>';
			}
		);

		self::group(
			__( 'Text', 'flexo-booking' ),
			__( 'Text, headings and small print.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_text', __( 'Text colour', 'flexo-booking' ), __( 'Text in the booking area.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_heading_color', __( 'Heading colour', 'flexo-booking' ), __( 'Page titles, "Your details", room names and the confirmation heading.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_muted', __( 'Muted text colour', 'flexo-booking' ), __( 'Introductions, notes and small print.', 'flexo-booking' ), $s, $name );
			}
		);

		self::group(
			__( 'Buttons and fields', 'flexo-booking' ),
			__( 'Buttons, fields and their labels.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_button_text', __( 'Button text colour', 'flexo-booking' ), __( 'Text on buttons in the main colour.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_button_hover', __( 'Button colour under the mouse', 'flexo-booking' ), __( 'Empty: the main colour, slightly lighter.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_secondary', __( 'Outline buttons', 'flexo-booking' ), __( 'Buttons such as "Back" and "Add to calendar".', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_field_bg', __( 'Field background', 'flexo-booking' ), __( 'Where guests type their details. Empty: the background colour above.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_field_border', __( 'Field border', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_focus', __( 'Field in use', 'flexo-booking' ), __( 'The outline of the field the guest is typing in.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_label', __( 'Field labels', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_control_size', __( 'Field and button height', 'flexo-booking' ), __( 'Large is easier to tap on phones.', 'flexo-booking' ), $s, $name );
			}
		);

		self::group(
			__( 'Booking steps and summary', 'flexo-booking' ),
			__( 'The steps at the top of the form and the reservation summary.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_step_active', __( 'Current step', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_step_done', __( 'Completed steps', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_step_inactive', __( 'Next steps', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_summary_bg', __( 'Reservation summary', 'flexo-booking' ), __( 'Background of the stay and price summary.', 'flexo-booking' ), $s, $name );
			}
		);

		self::group(
			__( 'Confirmation (Thank You page)', 'flexo-booking' ),
			__( 'What guests see once they have booked.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_success', __( 'Success icon', 'flexo-booking' ), __( 'The tick at the top of the confirmation.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_status_confirmed', __( 'Status: confirmed', 'flexo-booking' ), __( 'The status label\'s colour; its text turns dark or white by itself.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_status_awaiting', __( 'Status: awaiting payment', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_status_request', __( 'Status: request received', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_stay_bg', __( 'Stay summary', 'flexo-booking' ), __( 'Background of the room, dates and price list.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_bank_bg', __( 'Bank transfer box', 'flexo-booking' ), '', $s, $name );
			}
		);

		self::group(
			__( 'Tabs (Booking & Contact)', 'flexo-booking' ),
			__( 'For the Booking & Contact tabs that come with the Contact page.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_tab_bg', __( 'Tab background', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_text', __( 'Tab text', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_active_bg', __( 'Selected tab background', 'flexo-booking' ), '', $s, $name );
				self::color_row( 'appearance_tab_active_text', __( 'Selected tab text', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_tab_corners', __( 'Tab corners', 'flexo-booking' ), '', $s, $name );
			}
		);

		self::group(
			__( 'Booking and Thank You pages', 'flexo-booking' ),
			__( 'The whole page area between your header and footer on the built-in Booking and Thank You pages. Each page can also have its own look under Settings → Pages.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_bg', __( 'Background colour', 'flexo-booking' ), __( 'Background of the booking area and its fields.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_page_bg', __( 'Page background', 'flexo-booking' ), __( 'Behind everything on the page – for example a pale sand or sea colour.', 'flexo-booking' ), $s, $name );
				Flexo_Booking_UI::row_open( array( 'label' => __( 'Background picture', 'flexo-booking' ), 'desc' => __( 'Covers the page area behind the cards. Soften or darken it so the text stays easy to read.', 'flexo-booking' ) ) );
				self::image_field( $name . '[appearance_page_image]', $s['appearance_page_image'], __( 'Choose a picture', 'flexo-booking' ) );
				?>
				<label class="fx-inline" for="fb-appearance-page-image-effect"><span><?php esc_html_e( 'Picture effect', 'flexo-booking' ); ?></span>
					<select id="fb-appearance-page-image-effect" name="<?php echo esc_attr( $name ); ?>[appearance_page_image_effect]">
						<?php foreach ( Flexo_Booking_Appearance::choices( 'page_image_effect' ) as $value => $option ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['appearance_page_image_effect'], $value ); ?>><?php echo esc_html( $option[0] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php
				Flexo_Booking_UI::row_close();
				self::choice_row( 'appearance_max_width', __( 'Content width', 'flexo-booking' ), __( 'How wide the booking area and the confirmation can get on large screens.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_card_bg', __( 'Card background', 'flexo-booking' ), __( 'The booking form, the help boxes and the confirmation sit on cards.', 'flexo-booking' ), $s, $name );
				self::color_row( 'appearance_card_border', __( 'Card border', 'flexo-booking' ), __( 'The line around the cards.', 'flexo-booking' ), $s, $name );
				Flexo_Booking_UI::row_open( array( 'label' => '' ) );
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[appearance_card_no_border]" value="0">
				<label class="fx-check"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[appearance_card_no_border]" value="1" <?php checked( ! empty( $s['appearance_card_no_border'] ) ); ?>> <?php esc_html_e( 'No border around the cards', 'flexo-booking' ); ?></label>
				<?php
				Flexo_Booking_UI::row_close();
				self::choice_row( 'appearance_card_corners', __( 'Card corners', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_card_shadow', __( 'Card shadow', 'flexo-booking' ), '', $s, $name );
				self::choice_row( 'appearance_spacing', __( 'Space between sections', 'flexo-booking' ), '', $s, $name );
			}
		);

		self::group(
			__( '"Check availability" panel', 'flexo-booking' ),
			__( 'The panel that opens over a room page with the room\'s calendar. Its text, fonts, buttons and corners follow the settings above.', 'flexo-booking' ),
			false,
			static function () use ( $s, $name ) {
				self::color_row( 'appearance_panel_bg', __( 'Panel background', 'flexo-booking' ), __( 'Leave empty to use the background colour above.', 'flexo-booking' ), $s, $name );
				self::select_row( 'fb-appearance-overlay', $name . '[appearance_overlay]', __( 'Page behind the panel', 'flexo-booking' ), __( 'How much the page is darkened while the panel is open.', 'flexo-booking' ), Flexo_Booking_Appearance::overlays(), $s['appearance_overlay'] );
			}
		);
	}

	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$s     = Flexo_Booking_Settings::all();
		$name  = Flexo_Booking_Settings::OPTION;
		$reset = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="flexo-appearance__reset"><input type="hidden" name="action" value="flexo_booking_appearance_reset">' . wp_nonce_field( 'flexo_booking_appearance_reset', '_wpnonce', true, false ) . '<button type="submit" class="button flexo-reset-appearance" data-flexo-confirm="' . esc_attr__( 'Go back to your website\'s colours and fonts? Your custom choices are removed.', 'flexo-booking' ) . '" data-flexo-confirm-button="' . esc_attr__( 'Reset to website style', 'flexo-booking' ) . '" data-flexo-confirm-tone="danger">' . Flexo_Booking_Admin_UI::icon( 'reset' ) . '<span>' . esc_html__( 'Reset to website style', 'flexo-booking' ) . '</span></button></form>';
		?>
		<div class="wrap flexo-admin flexo-appearance">
			<?php
			Flexo_Booking_UI::page_header(
				array(
					'title' => __( 'Appearance of the booking form', 'flexo-booking' ),
					'icon'  => 'palette',
					'intro' => esc_html__( 'Let the booking form match your website, or give it your own colours and fonts. Only the booking form and your emails change.', 'flexo-booking' ),
					'tools' => $reset,
				)
			);
			?>
			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Appearance saved.', 'flexo-booking' ); ?></p></div>
			<?php elseif ( isset( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The booking form matches your website again.', 'flexo-booking' ); ?></p></div>
			<?php endif; ?>
			<div class="flexo-appearance__layout">
				<div class="flexo-appearance__settings">
					<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="flexo-appearance__form" id="flexo-appearance-form" data-fx-save="ajax">
						<?php settings_fields( 'flexo_booking' ); ?>
						<?php
						Flexo_Booking_UI::card_open( array( 'title' => __( 'Style', 'flexo-booking' ) ) );
						Flexo_Booking_UI::radio_cards(
							$name . '[appearance_mode]',
							array(
								'match'  => array(
									'title' => __( 'Match my website', 'flexo-booking' ),
									'badge' => __( 'Recommended', 'flexo-booking' ),
									'desc'  => __( 'The form uses your website\'s colours and fonts (Elementor global colours and fonts, or your theme\'s). It changes automatically when your website does.', 'flexo-booking' ),
								),
								'custom' => array(
									'title' => __( 'Custom', 'flexo-booking' ),
									'desc'  => __( 'Choose a few colours and fonts for the booking form only. The rest of your website is not changed.', 'flexo-booking' ),
								),
							),
							$s['appearance_mode'],
							__( 'Style', 'flexo-booking' ),
							array( 'class' => 'flexo-appearance__modes' )
						);
						Flexo_Booking_UI::card_close();
						?>

						<div class="flexo-appearance__custom">
							<div class="flexo-appearance__warnings" role="status" aria-live="polite"></div>
							<?php self::render_custom( $s, $name ); ?>
							<p class="fx-help flexo-appearance__order"><?php esc_html_e( 'Which setting wins: an Elementor Flexo Booking widget\'s Style tab, then a page\'s own look (Settings → Pages), then these settings, then your website\'s global colours and fonts.', 'flexo-booking' ); ?></p>
						</div>

						<?php Flexo_Booking_UI::card_open( array( 'title' => __( 'Emails', 'flexo-booking' ), 'desc' => __( 'The logo and colour at the top of every email.', 'flexo-booking' ) ) ); ?>
						<?php Flexo_Booking_UI::row_open( array( 'label' => __( 'Logo', 'flexo-booking' ), 'for' => 'fb-email-logo', 'desc' => __( 'Shown at the top of every email. Leave empty to use your website logo.', 'flexo-booking' ) ) ); ?>
							<span class="fx-inline fx-w-full">
								<input id="fb-email-logo" type="url" class="regular-text" name="<?php echo esc_attr( $name ); ?>[email_logo]" value="<?php echo esc_attr( $s['email_logo'] ); ?>" placeholder="<?php echo esc_attr( Flexo_Booking_Emails::logo_url() ); ?>">
								<button type="button" class="button flexo-choose-logo"><?php esc_html_e( 'Choose image', 'flexo-booking' ); ?></button>
							</span>
						<?php Flexo_Booking_UI::row_close(); ?>
						<?php Flexo_Booking_UI::row_open( array( 'label' => __( 'Email colour', 'flexo-booking' ), 'for' => 'fb-email-color', 'desc' => __( 'In Custom style, emails use your main colour instead.', 'flexo-booking' ), 'class' => 'flexo-email-color-row' ) ); ?>
							<span class="fx-inline">
								<input id="fb-email-color" type="color" class="fx-color__picker" name="<?php echo esc_attr( $name ); ?>[email_color]" value="<?php echo esc_attr( $s['email_color'] ); ?>">
								<span class="fx-color__hexlabel" data-flexo-email-hex><?php echo esc_html( strtoupper( $s['email_color'] ) ); ?></span>
							</span>
						<?php Flexo_Booking_UI::row_close(); ?>
						<?php Flexo_Booking_UI::card_close(); ?>

						<?php Flexo_Booking_UI::save_bar( array( 'label' => __( 'Save appearance', 'flexo-booking' ) ) ); ?>
					</form>
				</div>

				<aside class="flexo-appearance__preview" aria-label="<?php esc_attr_e( 'Preview', 'flexo-booking' ); ?>">
					<div class="flexo-appearance__preview-bar">
						<?php
						$scenes = array(
							'form'      => array( __( 'Booking form', 'flexo-booking' ), '' ),
							'booking'   => array( __( 'Booking page', 'flexo-booking' ), Flexo_Booking_System_Pages::uses_builtin( 'booking' ) ? Flexo_Booking_System_Pages::builtin_url( 'booking' ) : '' ),
							'thank_you' => array( __( 'Thank You page', 'flexo-booking' ), Flexo_Booking_System_Pages::uses_builtin( 'thank_you' ) ? Flexo_Booking_Confirmation::preview_url( 'transfer' ) : '' ),
						);
						$tabs   = array();
						foreach ( $scenes as $scene => $def ) {
							$tabs[] = array( $def[0], array( 'data-scene' => Flexo_Booking_Appearance::preview_url( $scene ), 'data-open' => $def[1], 'aria-controls' => 'flexo-appearance-frame' ), 'form' === $scene );
						}
						echo Flexo_Booking_UI::preview_toolbar_html( $tabs, __( 'What to preview', 'flexo-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
						?>
					</div>
					<div class="flexo-appearance__frame-wrap" data-fx-frame-wrap>
						<iframe id="flexo-appearance-frame" title="<?php esc_attr_e( 'Preview of the booking form', 'flexo-booking' ); ?>" src="<?php echo esc_url( Flexo_Booking_Appearance::preview_url() ); ?>"></iframe>
					</div>
					<p class="flexo-appearance__preview-note"><?php esc_html_e( 'The preview uses your real website styles and the booking form\'s own design. Changes show here before you save.', 'flexo-booking' ); ?></p>
				</aside>
			</div>
		</div>
		<?php
	}
}
