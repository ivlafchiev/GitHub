<?php
/**
 * Building blocks for the plugin's admin screens: the same markup and
 * classes everywhere (assets/css/flexo-admin.css, assets/js/flexo-admin.js).
 *
 * Helpers ending in _html return markup; the others print it. Text passed
 * in is escaped here unless the parameter says "HTML" (then the caller has
 * escaped it).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_UI {

	/** Open cards (card_open() / card_close()). */
	private static $cards = array();

	/**
	 * Page header: back link, title, one-line description, actions on the
	 * right, optional tabs. See Flexo_Booking_Admin_UI::page_head().
	 */
	public static function page_header( array $args ) {
		Flexo_Booking_Admin_UI::page_head( $args );
	}

	/**
	 * Attributes from an array (keys and values escaped; true = bare attribute).
	 */
	public static function attrs( array $attrs ) {
		$out = '';
		foreach ( $attrs as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}
			$out .= ' ' . esc_attr( $key ) . ( true === $value ? '' : '="' . esc_attr( $value ) . '"' );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Cards and rows
	 * ------------------------------------------------------------------- */

	/**
	 * A card with a section title.
	 *
	 * @param array $args {
	 *     @type string $title       Section title (plain text, sentence case).
	 *     @type string $desc        One line under the title (plain text).
	 *     @type string $desc_html   Same, as HTML.
	 *     @type string $icon        Icon name before the title.
	 *     @type string $actions     HTML at the right of the title.
	 *     @type string $id          Element id.
	 *     @type string $class       Extra classes.
	 *     @type bool   $collapsible A <details> card (title is the summary).
	 *     @type bool   $open        Collapsible card starts open.
	 *     @type bool   $flush       No inner padding (tables).
	 *     @type array  $attrs       Extra attributes.
	 * }
	 */
	public static function card_open( array $args = array() ) {
		$args = array_merge(
			array(
				'title'       => '',
				'desc'        => '',
				'desc_html'   => '',
				'icon'        => '',
				'actions'     => '',
				'id'          => '',
				'class'       => '',
				'collapsible' => false,
				'open'        => false,
				'flush'       => false,
				'attrs'       => array(),
				'heading'     => 'h2',
			),
			$args
		);
		$tag   = $args['collapsible'] ? 'details' : 'section';
		$class = 'fx-card' . ( $args['flush'] ? ' fx-card--flush' : '' ) . ( '' !== $args['class'] ? ' ' . $args['class'] : '' );
		$attrs = array_merge( array( 'class' => $class, 'id' => '' !== $args['id'] ? $args['id'] : false ), $args['attrs'] );
		if ( $args['collapsible'] && $args['open'] ) {
			$attrs['open'] = true;
		}
		$h    = in_array( $args['heading'], array( 'h2', 'h3' ), true ) ? $args['heading'] : 'h2';
		$desc = '' !== $args['desc_html'] ? $args['desc_html'] : esc_html( $args['desc'] );
		self::$cards[] = $tag;
		echo '<' . $tag . self::attrs( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed tag, attributes escaped.
		if ( '' !== $args['title'] ) {
			$title = ( $args['icon'] ? Flexo_Booking_Admin_UI::icon( $args['icon'] ) : '' ) . '<span>' . esc_html( $args['title'] ) . '</span>';
			if ( $args['collapsible'] ) {
				echo '<summary class="fx-card__summary"><span class="fx-card__summary-text"><' . $h . ' class="fx-card__title">' . $title . '</' . $h . '>' . ( '' !== $desc ? '<span class="fx-card__desc">' . $desc . '</span>' : '' ) . '</span></summary><div class="fx-card__body">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			} else {
				echo '<div class="fx-card__head"><div class="fx-card__headtext"><' . $h . ' class="fx-card__title">' . $title . '</' . $h . '>' . ( '' !== $desc ? '<p class="fx-card__desc">' . $desc . '</p>' : '' ) . '</div>' . ( '' !== $args['actions'] ? '<div class="fx-card__actions">' . $args['actions'] . '</div>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above; actions escaped by the caller.
			}
		} elseif ( $args['collapsible'] ) {
			echo '<div class="fx-card__body">';
		}
	}

	public static function card_close() {
		$tag = array_pop( self::$cards );
		if ( 'details' === $tag ) {
			echo '</div>';
		}
		echo 'details' === $tag ? '</details>' : '</section>';
	}

	/**
	 * A settings row: label and description on the left, control on the right.
	 *
	 * @param array $args {
	 *     @type string $label     Label text.
	 *     @type string $for       Id of the control the label is for ('' = a group, uses a heading).
	 *     @type string $desc      Description under the label (plain text).
	 *     @type string $desc_html Same, as HTML.
	 *     @type string $class     Extra classes (e.g. "flexo-advanced").
	 *     @type array  $attrs     Extra attributes.
	 *     @type bool   $stack     Label above the control (wide controls).
	 * }
	 */
	public static function row_open( array $args ) {
		$args  = array_merge( array( 'label' => '', 'for' => '', 'desc' => '', 'desc_html' => '', 'class' => '', 'attrs' => array(), 'stack' => false, 'id' => '' ), $args );
		$class = 'fx-row' . ( $args['stack'] ? ' fx-row--stack' : '' ) . ( '' !== $args['class'] ? ' ' . $args['class'] : '' );
		$id    = '' !== $args['id'] ? $args['id'] : ( '' !== $args['for'] ? $args['for'] . '-row' : 'fx-row-' . wp_unique_id() );
		$desc  = '' !== $args['desc_html'] ? $args['desc_html'] : esc_html( $args['desc'] );
		$attrs = array_merge( array( 'class' => $class ), $args['attrs'] );
		if ( '' === $args['for'] && '' !== $args['label'] ) {
			$attrs['role']            = 'group';
			$attrs['aria-labelledby'] = $id . '-label';
		}
		echo '<div' . self::attrs( $attrs ) . '><div class="fx-row__head">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped.
		if ( '' !== $args['label'] ) {
			if ( '' !== $args['for'] ) {
				echo '<label class="fx-row__label" for="' . esc_attr( $args['for'] ) . '">' . esc_html( $args['label'] ) . '</label>';
			} else {
				echo '<span class="fx-row__label" id="' . esc_attr( $id . '-label' ) . '">' . esc_html( $args['label'] ) . '</span>';
			}
		}
		if ( '' !== $desc ) {
			echo '<p class="fx-row__desc">' . $desc . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above or by the caller.
		}
		echo '</div><div class="fx-row__control">';
	}

	public static function row_close() {
		echo '</div></div>';
	}

	/**
	 * A complete row around ready-made control HTML.
	 */
	public static function row( array $args, $control_html ) {
		self::row_open( $args );
		echo $control_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		self::row_close();
	}

	/* ---------------------------------------------------------------------
	 * Choices
	 * ------------------------------------------------------------------- */

	/**
	 * Radio cards: the whole card is the label, the indicator sits on the
	 * title's first line.
	 *
	 * @param string $name    Field name.
	 * @param array  $options value => array( 'title' => …, 'desc' => …, 'badge' => …, 'attrs' => array() ).
	 * @param string $current Selected value.
	 * @param string $legend  Accessible name of the group.
	 */
	public static function radio_cards( $name, array $options, $current, $legend, array $args = array() ) {
		$args = array_merge( array( 'class' => '', 'legend_visible' => false ), $args );
		echo '<fieldset class="fx-choices' . ( '' !== $args['class'] ? ' ' . esc_attr( $args['class'] ) : '' ) . '">';
		echo '<legend class="' . ( $args['legend_visible'] ? 'fx-label' : 'screen-reader-text' ) . '">' . esc_html( $legend ) . '</legend>';
		foreach ( $options as $value => $option ) {
			$option = array_merge( array( 'title' => '', 'desc' => '', 'badge' => '', 'attrs' => array() ), $option );
			$id     = 'fx-choice-' . sanitize_html_class( $name ) . '-' . sanitize_html_class( (string) $value );
			echo '<label class="fx-choice" for="' . esc_attr( $id ) . '">';
			echo '<input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( (string) $current, (string) $value, false ) . self::attrs( $option['attrs'] ) . ( '' !== $option['desc'] ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
			echo '<span class="fx-choice__body"><span class="fx-choice__title">' . esc_html( $option['title'] ) . ( '' !== $option['badge'] ? ' <span class="flexo-badge flexo-badge--ok">' . esc_html( $option['badge'] ) . '</span>' : '' ) . '</span>';
			if ( '' !== $option['desc'] ) {
				echo '<span class="fx-choice__desc" id="' . esc_attr( $id ) . '-desc">' . esc_html( $option['desc'] ) . '</span>';
			}
			echo '</span></label>';
		}
		echo '</fieldset>';
	}

	/**
	 * Segmented control made of radios (submits like any radio group).
	 *
	 * @param string $name    Field name.
	 * @param array  $options value => label (or array( label, icon )).
	 * @param string $current Selected value.
	 * @param string $label   Accessible name.
	 * @param array  $attrs   Extra attributes for each input (e.g. form="…").
	 */
	public static function segmented_html( $name, array $options, $current, $label, array $attrs = array() ) {
		$out = '<span class="fx-seg" role="radiogroup" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $options as $value => $option ) {
			$option = (array) $option;
			$icon   = isset( $option[1] ) ? Flexo_Booking_Admin_UI::icon( $option[1] ) : '';
			$out   .= '<label class="fx-seg__opt"><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( (string) $current, (string) $value, false ) . self::attrs( $attrs ) . '><span>' . $icon . esc_html( $option[0] ) . '</span></label>';
		}
		return $out . '</span>';
	}

	/**
	 * Segmented control made of buttons (for scripts: aria-pressed).
	 *
	 * @param array $buttons List of array( 'label', 'attrs', 'icon', 'pressed', 'icon_only' ).
	 */
	public static function seg_buttons_html( array $buttons, $label, $class = '' ) {
		$out = '<span class="fx-seg' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" role="group" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $buttons as $button ) {
			$button = array_merge( array( 'label' => '', 'attrs' => array(), 'icon' => '', 'pressed' => false, 'icon_only' => false ), $button );
			$attrs  = array_merge( array( 'type' => 'button', 'class' => 'fx-seg__btn', 'aria-pressed' => $button['pressed'] ? 'true' : 'false' ), $button['attrs'] );
			if ( $button['icon_only'] ) {
				$attrs['aria-label']  = $button['label'];
				$attrs['data-fx-tip'] = $button['label'];
			}
			$out .= '<button' . self::attrs( $attrs ) . '>' . ( $button['icon'] ? Flexo_Booking_Admin_UI::icon( $button['icon'] ) : '' ) . ( $button['icon_only'] ? '' : '<span>' . esc_html( $button['label'] ) . '</span>' ) . '</button>';
		}
		return $out . '</span>';
	}

	/**
	 * On/off switch (a real checkbox).
	 */
	public static function toggle_html( $label, array $attrs, $checked = false ) {
		return Flexo_Booking_Admin_UI::switch_html( $label, ltrim( self::attrs( $attrs ) ), $checked );
	}

	/* ---------------------------------------------------------------------
	 * Colour field: "Website colour | Custom"
	 * ------------------------------------------------------------------- */

	/**
	 * The website's own colours (Elementor global colours), as the booking
	 * form resolves them: main = accent → primary → Flexo default; text =
	 * text → Flexo default.
	 *
	 * @return array key => array( hex, name )
	 */
	public static function website_colors() {
		static $colors = null;
		if ( null !== $colors ) {
			return $colors;
		}
		$kit_colors = array();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
			if ( $kit ) {
				foreach ( (array) $kit->get_settings( 'system_colors' ) as $item ) {
					if ( ! empty( $item['_id'] ) && ! empty( $item['color'] ) ) {
						$hex = Flexo_Booking_Appearance::sanitize_color( substr( (string) $item['color'], 0, 7 ) );
						if ( '' !== $hex ) {
							/* translators: %s: name of an Elementor global colour, e.g. Accent */
							$kit_colors[ (string) $item['_id'] ] = array( $hex, sprintf( __( 'Elementor: %s', 'flexo-booking' ), isset( $item['title'] ) ? (string) $item['title'] : (string) $item['_id'] ) );
						}
					}
				}
			}
		}
		$default = __( 'Flexo default', 'flexo-booking' );
		$colors  = array(
			'primary' => isset( $kit_colors['accent'] ) ? $kit_colors['accent'] : ( isset( $kit_colors['primary'] ) ? $kit_colors['primary'] : array( '#1f6f5c', $default ) ),
			'text'    => isset( $kit_colors['text'] ) ? $kit_colors['text'] : array( '#1f2933', $default ),
			'white'   => array( '#ffffff', __( 'White', 'flexo-booking' ) ),
		);
		return $colors;
	}

	/**
	 * A colour setting. Empty value = not set (the inherited colour is used).
	 *
	 * @param array $args {
	 *     @type string $name     Field name.
	 *     @type string $value    Saved hex or ''.
	 *     @type string $id       Id of the hex field.
	 *     @type string $label    Accessible name.
	 *     @type string $key      Key other fields refer to (data-fx-key).
	 *     @type string $source   Label of the first option ("Website colour").
	 *     @type string $from     Key of another colour field this one follows when not set.
	 *     @type int    $mix      With $from: percentage of that colour over white (pale tints).
	 *     @type array  $inherit array( hex, name ): the colour used when not set ('' hex: only the note).
	 *     @type string $note     Text shown when not set (default: name and hex of the inherited colour).
	 *     @type string $start    Picker start colour when switching to Custom.
	 *     @type string $describedby Id of a description.
	 * }
	 */
	public static function color_field( array $args ) {
		$args  = array_merge(
			array(
				'name'        => '',
				'value'       => '',
				'id'          => '',
				'label'       => '',
				'key'         => '',
				'source'      => __( 'Website colour', 'flexo-booking' ),
				'from'        => '',
				'mix'         => 0,
				'inherit'     => array( '', '' ),
				'note'        => '',
				'start'       => '',
				'describedby' => '',
			),
			$args
		);
		$value = Flexo_Booking_Appearance::sanitize_color( $args['value'] );
		$id    = '' !== $args['id'] ? $args['id'] : 'fx-color-' . wp_unique_id();
		$hex   = (string) $args['inherit'][0];
		$start = '' !== $value ? $value : ( '' !== $args['start'] ? $args['start'] : ( '' !== $hex ? $hex : '#1f6f5c' ) );
		$group = 'fx_color_src[' . $id . ']';
		$attrs = array(
			'class'          => 'fx-color',
			'data-fx-color'  => true,
			'data-fx-key'    => '' !== $args['key'] ? $args['key'] : false,
			'data-fx-from'   => '' !== $args['from'] ? $args['from'] : false,
			'data-fx-mix'    => $args['mix'] ? (int) $args['mix'] : false,
			'data-fx-hex'    => $hex,
			'data-fx-name'   => (string) $args['inherit'][1],
		);
		?>
		<div<?php echo self::attrs( $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in attrs(). ?>>
			<?php
			echo self::segmented_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
				$group,
				array(
					'website' => $args['source'],
					'custom'  => __( 'Custom', 'flexo-booking' ),
				),
				'' === $value ? 'website' : 'custom',
				/* translators: %s: name of a colour setting */
				sprintf( __( '%s: where the colour comes from', 'flexo-booking' ), $args['label'] ),
				array( 'data-fx-color-mode' => true, 'form' => 'fx-no-submit' )
			);
			?>
			<span class="fx-color__value" data-fx-color-website<?php echo '' === $value ? '' : ' hidden'; ?>>
				<span class="fx-swatch"<?php echo '' !== $hex ? ' style="--fx-swatch:' . esc_attr( $hex ) . '"' : ' hidden'; ?>></span>
				<span class="fx-color__name"><?php echo esc_html( '' !== $args['note'] ? $args['note'] : ( '' !== $args['inherit'][1] ? $args['inherit'][1] : __( 'Uses your website\'s colour', 'flexo-booking' ) ) ); ?></span>
				<span class="fx-color__hexlabel"<?php echo '' === $hex ? ' hidden' : ''; ?>><?php echo esc_html( strtoupper( $hex ) ); ?></span>
			</span>
			<span class="fx-color__value" data-fx-color-custom<?php echo '' === $value ? ' hidden' : ''; ?>>
				<input type="color" class="fx-color__picker" value="<?php echo esc_attr( $start ); ?>" aria-label="<?php echo esc_attr( $args['label'] ); ?>" form="fx-no-submit">
				<input id="<?php echo esc_attr( $id ); ?>" type="text" class="fx-color__hex code" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $start ); ?>" maxlength="7" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" spellcheck="false" autocomplete="off" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: name of a colour setting */ __( '%s (hex code)', 'flexo-booking' ), $args['label'] ) ); ?>"<?php echo '' !== $args['describedby'] ? ' aria-describedby="' . esc_attr( $args['describedby'] ) . '"' : ''; ?>>
				<button type="button" class="button-link fx-color__reset" data-fx-color-reset><?php esc_html_e( 'Reset', 'flexo-booking' ); ?></button>
			</span>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Navigation, actions, feedback
	 * ------------------------------------------------------------------- */

	/**
	 * Left sub-navigation (Settings), with a select on narrow screens.
	 *
	 * @param array  $groups  Group title => array( key => array( label, url ) ).
	 * @param string $current Current key.
	 * @param string $label   Accessible name.
	 */
	public static function subnav( array $groups, $current, $label ) {
		$select = '';
		echo '<nav class="fx-subnav" aria-label="' . esc_attr( $label ) . '"><div class="fx-subnav__groups">';
		foreach ( $groups as $title => $items ) {
			if ( ! $items ) {
				continue;
			}
			echo '<div class="fx-subnav__group"><p class="fx-subnav__title">' . esc_html( $title ) . '</p><ul>';
			$select .= '<optgroup label="' . esc_attr( $title ) . '">';
			foreach ( $items as $key => $item ) {
				$is = (string) $key === (string) $current;
				echo '<li><a class="fx-subnav__link" href="' . esc_url( $item[1] ) . '"' . ( $is ? ' aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . '</a></li>';
				$select .= '<option value="' . esc_url( $item[1] ) . '"' . selected( $is, true, false ) . '>' . esc_html( $item[0] ) . '</option>';
			}
			echo '</ul></div>';
			$select .= '</optgroup>';
		}
		echo '</div>';
		echo '<label class="fx-subnav__select"><span class="screen-reader-text">' . esc_html( $label ) . '</span><select data-fx-navigate>' . $select . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '</nav>';
	}

	/**
	 * Sticky save bar at the end of a form: unsaved state, Discard, Save.
	 * The Save button keeps the id "submit" (WordPress convention).
	 *
	 * @param array $args {
	 *     @type string $label   Save button text.
	 *     @type bool   $discard Show "Discard changes".
	 *     @type string $name    Submit button name.
	 * }
	 */
	public static function save_bar( array $args = array() ) {
		$args = array_merge( array( 'label' => __( 'Save changes', 'flexo-booking' ), 'discard' => true, 'name' => 'submit', 'extra' => '' ), $args );
		?>
		<div class="fx-savebar" data-fx-savebar>
			<p class="fx-savebar__status" aria-live="polite">
				<span class="fx-savebar__dot" aria-hidden="true"></span>
				<span data-fx-savebar-text data-saved="<?php esc_attr_e( 'All changes saved', 'flexo-booking' ); ?>" data-dirty="<?php esc_attr_e( 'You have unsaved changes', 'flexo-booking' ); ?>" data-saving="<?php esc_attr_e( 'Saving…', 'flexo-booking' ); ?>"><?php esc_html_e( 'All changes saved', 'flexo-booking' ); ?></span>
			</p>
			<div class="fx-savebar__actions">
				<?php echo $args['extra']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?>
				<?php if ( $args['discard'] ) : ?>
					<button type="button" class="button" data-fx-discard disabled><?php esc_html_e( 'Discard changes', 'flexo-booking' ); ?></button>
				<?php endif; ?>
				<?php submit_button( $args['label'], 'primary', $args['name'], false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * "⋯" menu (or a labelled menu button).
	 *
	 * @param array $items List of array( 'label', 'url', 'icon', 'danger', 'attrs', 'sep' ).
	 * @param array $args  { label: accessible name, text: visible button text, class, align: end|start }.
	 */
	public static function menu_html( array $items, array $args = array() ) {
		$args  = array_merge( array( 'label' => __( 'More actions', 'flexo-booking' ), 'text' => '', 'class' => '', 'align' => 'end', 'button_class' => '' ), $args );
		$items = array_filter( $items );
		if ( ! $items ) {
			return '';
		}
		$id  = 'fx-menu-' . wp_unique_id();
		$out = '<div class="fx-menu' . ( 'start' === $args['align'] ? ' fx-menu--start' : '' ) . ( '' !== $args['class'] ? ' ' . esc_attr( $args['class'] ) : '' ) . '" data-fx-menu>';
		if ( '' !== $args['text'] ) {
			$out .= '<button type="button" class="button' . ( '' !== $args['button_class'] ? ' ' . esc_attr( $args['button_class'] ) : '' ) . '" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '"><span>' . esc_html( $args['text'] ) . '</span>' . Flexo_Booking_Admin_UI::icon( 'chevron-down' ) . '</button>';
		} else {
			$out .= '<button type="button" class="button fx-icon-btn fx-icon-btn--bordered" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $args['label'] ) . '" data-fx-tip="' . esc_attr( $args['label'] ) . '" data-fx-tip-end>' . Flexo_Booking_Admin_UI::icon( 'more' ) . '</button>';
		}
		$out .= '<div class="fx-menu__panel" id="' . esc_attr( $id ) . '" role="menu" hidden>';
		foreach ( $items as $item ) {
			if ( ! empty( $item['sep'] ) ) {
				$out .= '<div class="fx-menu__sep" role="separator"></div>';
				continue;
			}
			$item  = array_merge( array( 'label' => '', 'url' => '', 'icon' => '', 'danger' => false, 'attrs' => array() ), $item );
			$attrs = array_merge(
				array(
					'class'    => 'fx-menu__item' . ( $item['danger'] ? ' fx-menu__item--danger' : '' ),
					'role'     => 'menuitem',
					'tabindex' => '-1',
				),
				$item['attrs']
			);
			if ( '' !== $item['url'] ) {
				$out .= '<a href="' . esc_url( $item['url'] ) . '"' . self::attrs( $attrs ) . '>';
				$end  = '</a>';
			} else {
				$out .= '<button type="button"' . self::attrs( $attrs ) . '>';
				$end  = '</button>';
			}
			$out .= ( $item['icon'] ? Flexo_Booking_Admin_UI::icon( $item['icon'] ) : '' ) . '<span>' . esc_html( $item['label'] ) . '</span>' . $end;
		}
		return $out . '</div></div>';
	}

	/**
	 * Booking status pill (icon + label, the same colours everywhere).
	 */
	public static function status_pill_html( $status, $label = '' ) {
		if ( '' === $label ) {
			$statuses = Flexo_Booking_Bookings::statuses();
			$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
		}
		return '<span class="flexo-status flexo-status--' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * An inline notice.
	 *
	 * @param string $type    success|warning|error|info.
	 * @param string $message HTML (escaped by the caller).
	 */
	public static function notice_html( $type, $message, $dismissible = false ) {
		$type = in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';
		return '<div class="notice notice-' . esc_attr( $type ) . ' inline' . ( $dismissible ? ' is-dismissible' : '' ) . '"><p>' . $message . '</p></div>';
	}

	/**
	 * Empty state: icon, what is missing, what to do next.
	 *
	 * @param array $args { icon, title, text, actions: list of array( label, url, primary ) }.
	 */
	public static function empty_state_html( array $args ) {
		$args = array_merge( array( 'icon' => 'info', 'title' => '', 'text' => '', 'actions' => array(), 'class' => '' ), $args );
		$out  = '<div class="flexo-blank' . ( '' !== $args['class'] ? ' ' . esc_attr( $args['class'] ) : '' ) . '"><span class="flexo-blank__icon">' . Flexo_Booking_Admin_UI::icon( $args['icon'] ) . '</span>';
		$out .= '' !== $args['title'] ? '<h2>' . esc_html( $args['title'] ) . '</h2>' : '';
		$out .= '' !== $args['text'] ? '<p>' . esc_html( $args['text'] ) . '</p>' : '';
		if ( $args['actions'] ) {
			$out .= '<p class="flexo-blank__actions">';
			foreach ( $args['actions'] as $action ) {
				$out .= '<a class="button' . ( ! empty( $action[2] ) ? ' button-primary' : '' ) . '" href="' . esc_url( $action[1] ) . '">' . esc_html( $action[0] ) . '</a> ';
			}
			$out .= '</p>';
		}
		return $out . '</div>';
	}

	/**
	 * Loading placeholder lines.
	 */
	public static function skeleton_html( $lines = 3, $label = '' ) {
		$out = '<div class="fx-skeleton-group" role="status" aria-label="' . esc_attr( '' !== $label ? $label : __( 'Loading…', 'flexo-booking' ) ) . '">';
		for ( $i = 0; $i < max( 1, (int) $lines ); $i++ ) {
			$out .= '<span class="fx-skeleton"></span>';
		}
		return $out . '</div>';
	}

	/**
	 * Preview toolbar: what to show on the left; device and "open in a new
	 * tab" on the right. Collapses into a select on narrow widths.
	 *
	 * @param array  $scenes List of array( label, attrs, pressed ).
	 * @param string $label  Accessible name of the scene group.
	 */
	public static function preview_toolbar_html( array $scenes, $label ) {
		$tabs    = '';
		$options = '';
		foreach ( $scenes as $i => $scene ) {
			$on       = ! empty( $scene[2] );
			$attrs    = array_merge( array( 'type' => 'button', 'class' => 'fx-tabs__tab', 'role' => 'tab', 'aria-selected' => $on ? 'true' : 'false', 'tabindex' => $on ? '0' : '-1' ), $scene[1] );
			$tabs    .= '<button' . self::attrs( $attrs ) . '>' . esc_html( $scene[0] ) . '</button>';
			$options .= '<option value="' . esc_attr( $i ) . '"' . selected( $on, true, false ) . '>' . esc_html( $scene[0] ) . '</option>';
		}
		$out  = '<div class="fx-toolbar" data-fx-preview-toolbar><div class="fx-toolbar__start">';
		$out .= '<div class="fx-tabs" role="tablist" aria-label="' . esc_attr( $label ) . '">' . $tabs . '</div>';
		$out .= count( $scenes ) > 1 ? '<label class="fx-toolbar__select"><span class="screen-reader-text">' . esc_html( $label ) . '</span><select data-fx-scene-select>' . $options . '</select></label>' : '';
		$out .= '</div><div class="fx-toolbar__end">';
		$out .= self::seg_buttons_html(
			array(
				array( 'label' => __( 'Desktop', 'flexo-booking' ), 'icon' => 'monitor', 'icon_only' => true, 'pressed' => true, 'attrs' => array( 'data-width' => '100%' ) ),
				array( 'label' => __( 'Phone', 'flexo-booking' ), 'icon' => 'mobile', 'icon_only' => true, 'attrs' => array( 'data-width' => '390px' ) ),
			),
			__( 'Preview width', 'flexo-booking' )
		);
		$out .= '<a class="button fx-icon-btn flexo-preview-open" href="#" target="_blank" rel="noopener" hidden aria-label="' . esc_attr__( 'Open page in a new tab', 'flexo-booking' ) . '" data-fx-tip="' . esc_attr__( 'Open page in a new tab', 'flexo-booking' ) . '" data-fx-tip-end>' . Flexo_Booking_Admin_UI::icon( 'external' ) . '</a>';
		return $out . '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * Dates and times
	 * ------------------------------------------------------------------- */

	/**
	 * Numeric date format for typing, in the order of the site's date format
	 * (e.g. "j F Y" → d.m.Y, "F j, Y" → m/d/Y, "Y-m-d" → Y-m-d).
	 */
	public static function date_input_format() {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$day    = strcspn( $format, 'dj' );
		$month  = strcspn( $format, 'mnFM' );
		$year   = strcspn( $format, 'Yy' );
		if ( $year < $day && $year < $month ) {
			return 'Y-m-d';
		}
		if ( $month < $day ) {
			return 'm/d/Y';
		}
		$sep = preg_match( '/[dj]([.\/\-])[mn]/', $format, $m ) ? $m[1] : '.';
		return 'd' . $sep . 'm' . $sep . 'Y';
	}

	/**
	 * A date field: what the user types (site order) plus the picker; the
	 * submitted value stays Y-m-d in a hidden field with the original name.
	 * Without scripts the visible field is a plain date input.
	 *
	 * @param array $args { name, value (Y-m-d), id, label, required, attrs, min, max }.
	 */
	public static function datefield_html( array $args ) {
		$args  = array_merge( array( 'name' => '', 'value' => '', 'id' => '', 'label' => '', 'required' => false, 'attrs' => array(), 'min' => '', 'max' => '' ), $args );
		$id    = '' !== $args['id'] ? $args['id'] : 'fx-date-' . wp_unique_id();
		$attrs = array_merge(
			array(
				'id'             => $id,
				'type'           => 'date',
				'name'           => $args['name'],
				'value'          => $args['value'],
				'class'          => 'fx-datefield__input',
				'required'       => $args['required'] ? true : false,
				'min'            => '' !== $args['min'] ? $args['min'] : false,
				'max'            => '' !== $args['max'] ? $args['max'] : false,
				'aria-label'     => '' !== $args['label'] ? $args['label'] : false,
				'data-fx-date'   => self::date_input_format(),
				'autocomplete'   => 'off',
			),
			$args['attrs']
		);
		return '<span class="fx-datefield"><input' . self::attrs( $attrs ) . '></span>';
	}

	/**
	 * A 24-hour time field (H:i) with a list of times.
	 */
	public static function timefield_html( array $args ) {
		$args  = array_merge( array( 'name' => '', 'value' => '', 'id' => '', 'label' => '', 'attrs' => array(), 'step' => 30 ), $args );
		$attrs = array_merge(
			array(
				'id'           => '' !== $args['id'] ? $args['id'] : false,
				'type'         => 'text',
				'name'         => $args['name'],
				'value'        => $args['value'],
				'class'        => 'fx-datefield__input fx-w-xs',
				'inputmode'    => 'numeric',
				'pattern'      => '([01]?[0-9]|2[0-3]):[0-5][0-9]',
				'placeholder'  => '14:00',
				'maxlength'    => '5',
				'aria-label'   => '' !== $args['label'] ? $args['label'] : false,
				'data-fx-time' => (int) $args['step'],
				'autocomplete' => 'off',
			),
			$args['attrs']
		);
		return '<span class="fx-datefield fx-datefield--time"><input' . self::attrs( $attrs ) . '></span>';
	}
}
