<?php
/**
 * The room screen (Rooms & prices → a room): cards in hotel language for
 * everything guests see on the room page and everything the booking form
 * needs.
 *
 * Still the WordPress room screen, so Polylang, WPML and SEO plugins add
 * their boxes as usual. WordPress's own Excerpt, Featured image and
 * Attributes boxes are replaced by the Short description, Photos and On the
 * website cards (same data: post_excerpt, _thumbnail_id, menu_order).
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Editor {

	public static function init() {
		add_action( 'add_meta_boxes_' . Flexo_Booking_Rooms::POST_TYPE, array( __CLASS__, 'meta_boxes' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_action( 'admin_footer-post.php', array( __CLASS__, 'icon_dialog' ) );
		add_action( 'admin_footer-post-new.php', array( __CLASS__, 'icon_dialog' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
	}

	private static function is_room_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && Flexo_Booking_Rooms::POST_TYPE === $screen->post_type;
	}

	public static function title_placeholder( $text, $post ) {
		return Flexo_Booking_Rooms::POST_TYPE === $post->post_type ? __( 'Room name, e.g. Deluxe Double Room with Sea View', 'flexo-booking' ) : $text;
	}

	/**
	 * The heading above the description editor, and the nonce for all cards.
	 */
	public static function after_title( $post ) {
		if ( Flexo_Booking_Rooms::POST_TYPE !== $post->post_type ) {
			return;
		}
		wp_nonce_field( 'flexo_room_meta', 'flexo_room_nonce' );
		echo '<div class="flexo-editor-label"><h2>' . esc_html__( 'Full description', 'flexo-booking' ) . '</h2><p>' . esc_html__( 'Tell guests about the room: the feeling, the view, the bed, the bathroom. Shown on the room page.', 'flexo-booking' ) . '</p></div>';
	}

	public static function meta_boxes( $post ) {
		$type = Flexo_Booking_Rooms::POST_TYPE;
		remove_meta_box( 'postexcerpt', $type, 'normal' );
		remove_meta_box( 'postimagediv', $type, 'side' );
		remove_meta_box( 'pageparentdiv', $type, 'side' );

		add_meta_box( 'flexo-room-summary', __( 'Short description', 'flexo-booking' ), array( __CLASS__, 'summary_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-photos', __( 'Photos', 'flexo-booking' ), array( __CLASS__, 'photos_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-facts', __( 'Room facts', 'flexo-booking' ), array( __CLASS__, 'facts_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-amenities', __( 'Amenities', 'flexo-booking' ), array( __CLASS__, 'amenities_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-more', __( 'More details', 'flexo-booking' ), array( __CLASS__, 'details_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-details', __( 'Prices', 'flexo-booking' ), array( 'Flexo_Booking_Rooms', 'render_meta_box' ), $type, 'normal', 'high' );
		add_meta_box( 'flexo-room-seo', __( 'Search engines', 'flexo-booking' ), array( __CLASS__, 'seo_box' ), $type, 'normal', 'low' );
		add_meta_box( 'flexo-room-website', __( 'On the website', 'flexo-booking' ), array( __CLASS__, 'website_box' ), $type, 'side', 'default' );
	}

	/* ------------------------------------------------------------------ *
	 * Cards
	 * ------------------------------------------------------------------ */

	public static function summary_box( $post ) {
		?>
		<input type="hidden" name="flexo_room_cards[]" value="summary">
		<label class="screen-reader-text" for="excerpt"><?php esc_html_e( 'Short description', 'flexo-booking' ); ?></label>
		<textarea id="excerpt" name="excerpt" rows="3" class="large-text" data-flexo-count="160" placeholder="<?php esc_attr_e( 'e.g. Bright double room with a sea-view balcony, two minutes from the beach.', 'flexo-booking' ); ?>"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
		<p class="description flexo-count-line"><span><?php esc_html_e( 'One or two sentences for room cards, room lists and the booking form. Leave empty to use the first words of the full description.', 'flexo-booking' ); ?></span> <span class="flexo-counter" aria-live="polite"></span></p>
		<?php
	}

	public static function photos_box( $post ) {
		$main    = (int) get_post_thumbnail_id( $post );
		$gallery = Flexo_Booking_Room_Content::gallery( $post->ID, false );
		?>
		<input type="hidden" name="flexo_room_cards[]" value="photos">
		<div class="flexo-photos">
			<div class="flexo-photos__main">
				<h4><?php esc_html_e( 'Main photo', 'flexo-booking' ); ?></h4>
				<div class="flexo-photo-slot<?php echo $main ? ' has-photo' : ''; ?>" data-flexo-main>
					<button type="button" class="flexo-photo-slot__pick" data-flexo-main-pick aria-label="<?php esc_attr_e( 'Choose the main photo', 'flexo-booking' ); ?>">
						<?php
						if ( $main ) {
							echo wp_get_attachment_image( $main, 'medium', false, array( 'alt' => '' ) );
						}
						?>
						<span class="flexo-photo-slot__empty"><?php echo Flexo_Booking_Admin_UI::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><?php esc_html_e( 'Choose the main photo', 'flexo-booking' ); ?></span>
					</button>
					<button type="button" class="button-link flexo-photo-slot__remove" data-flexo-main-remove><?php esc_html_e( 'Remove', 'flexo-booking' ); ?></button>
				</div>
				<input type="hidden" name="flexo_main_photo" value="<?php echo esc_attr( $main ? $main : '' ); ?>">
				<p class="description"><?php esc_html_e( 'Used on room cards and at the top of the room page.', 'flexo-booking' ); ?></p>
			</div>
			<div class="flexo-photos__gallery">
				<h4><?php esc_html_e( 'Gallery', 'flexo-booking' ); ?> <span class="flexo-muted" data-flexo-gallery-count></span></h4>
				<ul class="flexo-gallery" data-flexo-gallery>
					<?php foreach ( $gallery as $id ) : ?>
						<li class="flexo-gallery__item" data-id="<?php echo esc_attr( $id ); ?>">
							<?php echo wp_get_attachment_image( $id, 'thumbnail', false, array( 'alt' => '' ) ); ?>
							<button type="button" class="flexo-gallery__remove" data-flexo-gallery-remove aria-label="<?php esc_attr_e( 'Remove this photo', 'flexo-booking' ); ?>">&times;</button>
						</li>
					<?php endforeach; ?>
					<li class="flexo-gallery__add">
						<button type="button" data-flexo-gallery-add><?php echo Flexo_Booking_Admin_UI::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php esc_html_e( 'Add photos', 'flexo-booking' ); ?></span></button>
					</li>
				</ul>
				<input type="hidden" name="flexo_gallery" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>">
				<p class="description"><?php esc_html_e( 'Drag the photos to change their order. The room page shows the main photo first, then these.', 'flexo-booking' ); ?></p>
			</div>
		</div>
		<?php
	}

	public static function facts_box( $post ) {
		$room     = Flexo_Booking_Rooms::to_array( $post );
		$is_new   = 'auto-draft' === $post->post_status;
		$types    = get_terms(
			array(
				'taxonomy'   => Flexo_Booking_Room_Content::TAXONOMY,
				'hide_empty' => false,
			)
		);
		$types    = is_array( $types ) ? $types : array();
		$selected = wp_get_object_terms( $post->ID, Flexo_Booking_Room_Content::TAXONOMY, array( 'fields' => 'ids' ) );
		$selected = is_array( $selected ) ? array_map( 'intval', $selected ) : array();
		?>
		<input type="hidden" name="flexo_room_cards[]" value="facts">
		<div class="flexo-subsection flexo-subsection--first">
			<h4 id="flexo-types-label"><?php esc_html_e( 'Room type', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></h4>
			<div class="flexo-type-list" role="group" aria-labelledby="flexo-types-label" data-flexo-types>
				<?php foreach ( $types as $term ) : ?>
					<label class="flexo-check-chip"><input type="checkbox" name="flexo_room_types[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $selected, true ) ); ?>> <span><?php echo esc_html( $term->name ); ?></span></label>
				<?php endforeach; ?>
				<span class="flexo-type-add">
					<input type="text" class="regular-text" data-flexo-type-new placeholder="<?php esc_attr_e( 'New type, e.g. Suite', 'flexo-booking' ); ?>" aria-label="<?php esc_attr_e( 'New room type', 'flexo-booking' ); ?>" maxlength="60">
					<button type="button" class="button" data-flexo-type-add><?php esc_html_e( 'Add', 'flexo-booking' ); ?></button>
				</span>
			</div>
			<p class="description">
				<?php esc_html_e( 'E.g. Double room, Suite, Apartment. Used to group and filter rooms in room lists.', 'flexo-booking' ); ?>
				<?php if ( $types ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . Flexo_Booking_Room_Content::TAXONOMY . '&post_type=' . Flexo_Booking_Rooms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Rename or delete room types', 'flexo-booking' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<div class="flexo-fields flexo-fields--3">
			<p class="flexo-field">
				<label for="flexo-size"><?php esc_html_e( 'Size', 'flexo-booking' ); ?></label>
				<span class="flexo-input-unit"><input id="flexo-size" type="number" min="0" max="100000" name="_flexo_size" value="<?php echo esc_attr( $room['size'] ? $room['size'] : '' ); ?>"><span>m²</span></span>
			</p>
			<p class="flexo-field">
				<label for="flexo-beds"><?php esc_html_e( 'Beds', 'flexo-booking' ); ?></label>
				<input id="flexo-beds" type="text" name="_flexo_beds" value="<?php echo esc_attr( $room['beds'] ); ?>" maxlength="190" placeholder="<?php esc_attr_e( 'e.g. 1 double bed', 'flexo-booking' ); ?>">
			</p>
			<p class="flexo-field">
				<label for="flexo-view"><?php esc_html_e( 'View', 'flexo-booking' ); ?></label>
				<input id="flexo-view" type="text" name="flexo_view" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, Flexo_Booking_Room_Content::VIEW, true ) ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Sea view', 'flexo-booking' ); ?>">
			</p>
			<p class="flexo-field">
				<label for="flexo-capacity"><?php esc_html_e( 'Max guests', 'flexo-booking' ); ?></label>
				<input id="flexo-capacity" type="number" min="1" max="100" name="_flexo_capacity" value="<?php echo esc_attr( $is_new ? 2 : $room['capacity'] ); ?>">
				<?php if ( Flexo_Booking_Children::enabled() ) : ?>
					<span class="description"><?php esc_html_e( 'Adults and children together, babies included.', 'flexo-booking' ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( Flexo_Booking_Children::enabled() ) : ?>
				<p class="flexo-field">
					<label for="flexo-max-adults"><?php esc_html_e( 'Max adults', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></label>
					<input id="flexo-max-adults" type="number" min="0" max="100" name="_flexo_max_adults" value="<?php echo esc_attr( $room['max_adults'] ? $room['max_adults'] : '' ); ?>">
					<span class="description"><?php esc_html_e( 'E.g. a family room for 4 guests but at most 2 adults.', 'flexo-booking' ); ?></span>
				</p>
			<?php endif; ?>
			<p class="flexo-field">
				<label for="flexo-units"><?php esc_html_e( 'Identical rooms', 'flexo-booking' ); ?></label>
				<input id="flexo-units" type="number" min="0" max="1000" name="_flexo_units" value="<?php echo esc_attr( $room['units'] ); ?>">
				<span class="description"><?php esc_html_e( 'How many of these rooms can be booked for the same night. 0 stops bookings.', 'flexo-booking' ); ?></span>
			</p>
			<p class="flexo-field">
				<label for="flexo-min-nights"><?php esc_html_e( 'Minimum nights', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></label>
				<input id="flexo-min-nights" type="number" min="0" max="365" name="_flexo_min_nights" value="<?php echo esc_attr( $room['min_nights_override'] ? $room['min_nights_override'] : '' ); ?>" placeholder="<?php echo esc_attr( (string) Flexo_Booking_Settings::get( 'min_nights' ) ); ?>">
				<span class="description"><?php esc_html_e( 'Leave empty to use the general rule.', 'flexo-booking' ); ?><?php echo Flexo_Booking_Seasons::enabled() ? ' ' . esc_html__( 'Seasons can set their own minimum.', 'flexo-booking' ) : ''; ?></span>
			</p>
		</div>
		<?php
	}

	public static function amenities_box( $post ) {
		$items   = Flexo_Booking_Room_Content::amenity_items( $post->ID );
		$labels  = Flexo_Booking_Rooms::amenities();
		$presets = Flexo_Booking_Room_Content::amenity_presets();
		$chosen  = wp_list_pluck( $items, 'key' );
		?>
		<input type="hidden" name="flexo_room_cards[]" value="amenities">
		<p class="flexo-card-intro"><?php esc_html_e( 'What the room has. Drag to change the order – the first five are also shown on the room cards in the booking form. Click an icon to change it.', 'flexo-booking' ); ?></p>
		<ul class="flexo-sort-list flexo-amenity-list" data-flexo-amenities aria-label="<?php esc_attr_e( 'Amenities of this room', 'flexo-booking' ); ?>">
			<?php
			foreach ( $items as $item ) {
				$key   = $item['key'];
				$label = '' !== $item['label'] ? $item['label'] : ( isset( $labels[ $key ] ) ? $labels[ $key ] : '' );
				$icon  = '' !== $item['icon'] ? $item['icon'] : ( isset( $presets[ $key ][1] ) ? $presets[ $key ][1] : 'check' );
				self::amenity_row( $key, $label, $item['icon'], $icon );
			}
			?>
		</ul>
		<p class="flexo-empty-note" data-flexo-amenities-empty <?php echo $items ? 'hidden' : ''; ?>><?php esc_html_e( 'No amenities yet. Click the ones the room has below, or add your own.', 'flexo-booking' ); ?></p>

		<div class="flexo-subsection">
			<h4><?php esc_html_e( 'Add amenities', 'flexo-booking' ); ?></h4>
			<div class="flexo-preset-chips" data-flexo-presets>
				<?php foreach ( $presets as $key => $preset ) : ?>
					<?php if ( ! isset( $labels[ $key ] ) ) { continue; } ?>
					<button type="button" class="flexo-preset-chip" data-key="<?php echo esc_attr( $key ); ?>" data-label="<?php echo esc_attr( $labels[ $key ] ); ?>" data-icon="<?php echo esc_attr( $preset[1] ); ?>" aria-pressed="<?php echo in_array( $key, $chosen, true ) ? 'true' : 'false'; ?>">
						<?php echo Flexo_Booking_Room_Icons::svg( $preset[1], 'flexo-preset-chip__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG. ?>
						<span><?php echo esc_html( $labels[ $key ] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="flexo-own-add">
				<label for="flexo-own-amenity"><?php esc_html_e( 'Your own amenity', 'flexo-booking' ); ?></label>
				<span class="flexo-own-add__row">
					<input id="flexo-own-amenity" type="text" class="regular-text" maxlength="80" data-flexo-own-amenity placeholder="<?php esc_attr_e( 'e.g. Rain shower with a view', 'flexo-booking' ); ?>">
					<button type="button" class="button" data-flexo-own-amenity-add><?php esc_html_e( 'Add', 'flexo-booking' ); ?></button>
				</span>
			</div>
		</div>
		<template id="flexo-amenity-row"><?php self::amenity_row( '', '', '', 'check' ); ?></template>
		<?php
	}

	/**
	 * One amenity in the room's list.
	 *
	 * @param string $key      Ready-made amenity key ('' for the owner's own).
	 * @param string $label    Text shown to guests.
	 * @param string $icon     Stored icon ('' = the amenity's usual icon).
	 * @param string $show     Icon to show now.
	 */
	private static function amenity_row( $key, $label, $icon, $show ) {
		?>
		<li class="flexo-sort-item flexo-amenity" data-key="<?php echo esc_attr( $key ); ?>">
			<span class="flexo-drag" aria-hidden="true"><?php echo Flexo_Booking_Admin_UI::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<button type="button" class="flexo-icon-pick" data-flexo-icon-pick aria-label="<?php esc_attr_e( 'Change icon', 'flexo-booking' ); ?>" title="<?php esc_attr_e( 'Change icon', 'flexo-booking' ); ?>"><?php echo Flexo_Booking_Room_Icons::html( $show, 'flexo-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html(). ?></button>
			<input type="text" class="flexo-sort-item__text" name="flexo_amenity_label[]" value="<?php echo esc_attr( $label ); ?>" maxlength="80" aria-label="<?php esc_attr_e( 'Amenity', 'flexo-booking' ); ?>">
			<input type="hidden" name="flexo_amenity_key[]" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="flexo_amenity_icon[]" value="<?php echo esc_attr( $icon ); ?>" data-flexo-icon-value>
			<span class="flexo-sort-item__moves">
				<button type="button" class="flexo-move" data-flexo-move="-1" aria-label="<?php esc_attr_e( 'Move up', 'flexo-booking' ); ?>">&uarr;</button>
				<button type="button" class="flexo-move" data-flexo-move="1" aria-label="<?php esc_attr_e( 'Move down', 'flexo-booking' ); ?>">&darr;</button>
			</span>
			<button type="button" class="flexo-remove" data-flexo-remove aria-label="<?php esc_attr_e( 'Remove', 'flexo-booking' ); ?>" title="<?php esc_attr_e( 'Remove', 'flexo-booking' ); ?>">&times;</button>
		</li>
		<?php
	}

	/**
	 * Suggestions for "More details": label => icon.
	 */
	private static function detail_suggestions() {
		return array(
			__( 'Floor', 'flexo-booking' )                 => 'layers',
			__( 'Bathroom', 'flexo-booking' )              => 'shower',
			__( 'Bed linen and towels', 'flexo-booking' )  => 'bed',
			__( 'Check-in', 'flexo-booking' )              => 'key',
			__( 'Distance to the beach', 'flexo-booking' ) => 'umbrella',
			__( 'Extra bed', 'flexo-booking' )             => 'bed_single',
			__( 'Cleaning', 'flexo-booking' )              => 'cleaning',
			__( 'Breakfast', 'flexo-booking' )             => 'croissant',
		);
	}

	public static function details_box( $post ) {
		$rows = Flexo_Booking_Room_Content::details( $post->ID );
		?>
		<input type="hidden" name="flexo_room_cards[]" value="details">
		<p class="flexo-card-intro"><?php esc_html_e( 'Anything else guests should know about this room, as short lines with a name and a value – e.g. "Floor: 2nd, with lift".', 'flexo-booking' ); ?></p>
		<ul class="flexo-sort-list flexo-detail-list" data-flexo-details aria-label="<?php esc_attr_e( 'More details', 'flexo-booking' ); ?>">
			<?php
			foreach ( $rows as $row ) {
				self::detail_row( $row['icon'], $row['label'], $row['value'] );
			}
			?>
		</ul>
		<p class="flexo-empty-note" data-flexo-details-empty <?php echo $rows ? 'hidden' : ''; ?>><?php esc_html_e( 'No details yet.', 'flexo-booking' ); ?></p>
		<p class="flexo-detail-add">
			<button type="button" class="button" data-flexo-detail-add><?php echo Flexo_Booking_Admin_UI::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?> <?php esc_html_e( 'Add a detail', 'flexo-booking' ); ?></button>
			<span class="flexo-muted"><?php esc_html_e( 'or start from:', 'flexo-booking' ); ?></span>
			<?php foreach ( self::detail_suggestions() as $label => $icon ) : ?>
				<button type="button" class="flexo-suggest-chip" data-flexo-detail-suggest data-label="<?php echo esc_attr( $label ); ?>" data-icon="<?php echo esc_attr( $icon ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</p>
		<template id="flexo-detail-row"><?php self::detail_row( '', '', '' ); ?></template>
		<?php
	}

	private static function detail_row( $icon, $label, $value ) {
		?>
		<li class="flexo-sort-item flexo-detail">
			<span class="flexo-drag" aria-hidden="true"><?php echo Flexo_Booking_Admin_UI::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
			<button type="button" class="flexo-icon-pick" data-flexo-icon-pick data-default-icon="info" aria-label="<?php esc_attr_e( 'Change icon', 'flexo-booking' ); ?>" title="<?php esc_attr_e( 'Change icon', 'flexo-booking' ); ?>"><?php echo Flexo_Booking_Room_Icons::html( '' !== $icon ? $icon : 'info', 'flexo-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html(). ?></button>
			<input type="text" class="flexo-detail__label" name="flexo_detail_label[]" value="<?php echo esc_attr( $label ); ?>" maxlength="80" placeholder="<?php esc_attr_e( 'Name, e.g. Floor', 'flexo-booking' ); ?>" aria-label="<?php esc_attr_e( 'Name', 'flexo-booking' ); ?>">
			<input type="text" class="flexo-detail__value" name="flexo_detail_value[]" value="<?php echo esc_attr( $value ); ?>" maxlength="200" placeholder="<?php esc_attr_e( 'Value, e.g. 2nd floor, with lift', 'flexo-booking' ); ?>" aria-label="<?php esc_attr_e( 'Value', 'flexo-booking' ); ?>">
			<input type="hidden" name="flexo_detail_icon[]" value="<?php echo esc_attr( $icon ); ?>" data-flexo-icon-value>
			<span class="flexo-sort-item__moves">
				<button type="button" class="flexo-move" data-flexo-move="-1" aria-label="<?php esc_attr_e( 'Move up', 'flexo-booking' ); ?>">&uarr;</button>
				<button type="button" class="flexo-move" data-flexo-move="1" aria-label="<?php esc_attr_e( 'Move down', 'flexo-booking' ); ?>">&darr;</button>
			</span>
			<button type="button" class="flexo-remove" data-flexo-remove aria-label="<?php esc_attr_e( 'Remove', 'flexo-booking' ); ?>" title="<?php esc_attr_e( 'Remove', 'flexo-booking' ); ?>">&times;</button>
		</li>
		<?php
	}

	/**
	 * Which SEO plugin writes the title and description, if any.
	 */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}
		return '';
	}

	public static function seo_box( $post ) {
		$plugin = self::seo_plugin();
		if ( '' !== $plugin ) {
			/* translators: %s: SEO plugin name */
			echo '<p>' . esc_html( sprintf( __( '%s is active: set this room\'s title and description for search engines in its box on this screen.', 'flexo-booking' ), $plugin ) ) . '</p>';
			return;
		}
		$title = (string) get_post_meta( $post->ID, Flexo_Booking_Room_Content::SEO_TITLE, true );
		$desc  = (string) get_post_meta( $post->ID, Flexo_Booking_Room_Content::SEO_DESCRIPTION, true );
		$url   = Flexo_Booking_Room_Content::page_url( $post );
		?>
		<input type="hidden" name="flexo_room_cards[]" value="seo">
		<div class="flexo-seo">
			<div class="flexo-seo__fields">
				<p class="flexo-field">
					<label for="flexo-seo-title"><?php esc_html_e( 'Title in search results', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></label>
					<input id="flexo-seo-title" type="text" class="large-text" name="flexo_seo_title" value="<?php echo esc_attr( $title ); ?>" maxlength="120" data-flexo-count="60" placeholder="<?php echo esc_attr( ( $post->post_title ? $post->post_title : __( 'Room name', 'flexo-booking' ) ) . ' – ' . get_bloginfo( 'name' ) ); ?>">
					<span class="description flexo-count-line"><span><?php esc_html_e( 'Leave empty to use the room name and your site name.', 'flexo-booking' ); ?></span> <span class="flexo-counter" aria-live="polite"></span></span>
				</p>
				<p class="flexo-field">
					<label for="flexo-seo-description"><?php esc_html_e( 'Description in search results', 'flexo-booking' ); ?> <span class="flexo-optional"><?php esc_html_e( 'optional', 'flexo-booking' ); ?></span></label>
					<textarea id="flexo-seo-description" class="large-text" rows="2" name="flexo_seo_description" maxlength="320" data-flexo-count="160" placeholder="<?php echo esc_attr( $post->post_excerpt ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
					<span class="description flexo-count-line"><span><?php esc_html_e( 'Leave empty to use the short description.', 'flexo-booking' ); ?></span> <span class="flexo-counter" aria-live="polite"></span></span>
				</p>
			</div>
			<div class="flexo-seo__preview" aria-hidden="true">
				<span class="flexo-seo__label"><?php esc_html_e( 'Preview', 'flexo-booking' ); ?></span>
				<span class="flexo-seo__url"><?php echo esc_html( $url ? $url : home_url( '/' . Flexo_Booking_Room_Pages::base() . '/…/' ) ); ?></span>
				<span class="flexo-seo__title" data-flexo-seo-title></span>
				<span class="flexo-seo__desc" data-flexo-seo-desc></span>
			</div>
		</div>
		<?php
	}

	public static function website_box( $post ) {
		$hidden  = Flexo_Booking_Room_Content::is_hidden( $post->ID );
		$demo    = Flexo_Booking_Room_Content::is_demo( $post->ID );
		$url     = 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
		$booking = $post->post_name ? Flexo_Booking_Room_Content::booking_url( $post->post_name ) : '';
		?>
		<input type="hidden" name="flexo_room_cards[]" value="website">
		<div class="flexo-website">
			<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Show on the website', 'flexo-booking' ), 'name="flexo_show" value="1"', ! $hidden ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<p class="description"><?php esc_html_e( 'Off: no room page and not in room lists. Guests can still book it through its booking link, and you can book it from the admin.', 'flexo-booking' ); ?></p>

			<?php if ( $demo ) : ?>
				<div class="flexo-website__demo">
					<?php echo Flexo_Booking_Admin_UI::switch_html( __( 'Demo room', 'flexo-booking' ), 'name="flexo_demo" value="1"', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
					<p class="description"><?php esc_html_e( 'Only you see it on the website and it cannot be booked. Switch it off once the room is real.', 'flexo-booking' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="flexo-field">
				<label for="flexo-menu-order"><?php esc_html_e( 'Display order', 'flexo-booking' ); ?></label>
				<input id="flexo-menu-order" type="number" name="menu_order" class="small-text" value="<?php echo esc_attr( (string) $post->menu_order ); ?>">
				<span class="description"><?php esc_html_e( 'Lower numbers come first in room lists and the booking form.', 'flexo-booking' ); ?></span>
			</p>

			<div class="flexo-link-row">
				<span class="flexo-link-row__label"><?php esc_html_e( 'Room page', 'flexo-booking' ); ?></span>
				<?php if ( $url && ! $hidden ) : ?>
					<code class="flexo-link-row__url"><?php echo esc_html( wp_make_link_relative( $url ) ); ?></code>
					<span class="flexo-link-row__actions">
						<button type="button" class="button button-small" data-flexo-copy-text="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy link', 'flexo-booking' ); ?></button>
						<a class="button button-small" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'flexo-booking' ); ?></a>
					</span>
				<?php elseif ( $hidden ) : ?>
					<span class="flexo-muted"><?php esc_html_e( 'None – the room is not shown on the website.', 'flexo-booking' ); ?></span>
				<?php else : ?>
					<span class="flexo-muted"><?php esc_html_e( 'Publish the room to get its page.', 'flexo-booking' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $booking ) : ?>
				<div class="flexo-link-row">
					<span class="flexo-link-row__label"><?php esc_html_e( 'Booking link', 'flexo-booking' ); ?></span>
					<code class="flexo-link-row__url"><?php echo esc_html( wp_make_link_relative( $booking ) ); ?></code>
					<span class="flexo-link-row__actions">
						<button type="button" class="button button-small" data-flexo-copy-text="<?php echo esc_attr( $booking ); ?>"><?php esc_html_e( 'Copy link', 'flexo-booking' ); ?></button>
					</span>
					<span class="description"><?php esc_html_e( 'Opens the booking form with this room chosen. In Elementor use the "Room booking link" dynamic tag instead.', 'flexo-booking' ); ?></span>
				</div>
			<?php endif; ?>

			<?php self::checklist( $post ); ?>
		</div>
		<?php
	}

	/**
	 * What the room page still misses.
	 */
	private static function checklist( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		$amenities = count( Flexo_Booking_Room_Content::amenity_items( $post->ID ) );
		$photos    = count( Flexo_Booking_Room_Content::gallery( $post->ID ) );
		$items     = array(
			array( has_post_thumbnail( $post ), __( 'Main photo', 'flexo-booking' ) ),
			/* translators: %d: number of photos */
			array( $photos > 1, $photos > 1 ? sprintf( _n( 'Gallery (%d photo)', 'Gallery (%d photos)', $photos, 'flexo-booking' ), $photos ) : __( 'Gallery', 'flexo-booking' ) ),
			array( '' !== trim( $post->post_excerpt ), __( 'Short description', 'flexo-booking' ) ),
			array( '' !== trim( wp_strip_all_tags( $post->post_content ) ), __( 'Full description', 'flexo-booking' ) ),
			/* translators: %d: number of amenities */
			array( $amenities > 0, $amenities ? sprintf( _n( '%d amenity', '%d amenities', $amenities, 'flexo-booking' ), $amenities ) : __( 'Amenities', 'flexo-booking' ) ),
			array( (int) get_post_meta( $post->ID, '_flexo_size', true ) > 0, __( 'Size', 'flexo-booking' ) ),
		);
		$done = count( array_filter( wp_list_pluck( $items, 0 ) ) );
		?>
		<div class="flexo-checklist">
			<p class="flexo-checklist__head">
				<?php
				/* translators: 1: items done, 2: all items */
				echo esc_html( sprintf( __( 'Room page: %1$d of %2$d filled in', 'flexo-booking' ), $done, count( $items ) ) );
				?>
			</p>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li class="<?php echo $item[0] ? 'is-done' : 'is-missing'; ?>"><?php echo Flexo_Booking_Admin_UI::icon( $item[0] ? 'check' : 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php echo esc_html( $item[1] ); ?></span><?php echo $item[0] ? '' : '<span class="screen-reader-text">' . esc_html__( '(missing)', 'flexo-booking' ) . '</span>'; ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Icon picker (one dialog for the whole screen)
	 * ------------------------------------------------------------------ */

	public static function icon_dialog() {
		if ( ! self::is_room_screen() ) {
			return;
		}
		?>
		<dialog class="flexo-icon-dialog" id="flexo-icon-dialog" aria-labelledby="flexo-icon-dialog-title">
			<form method="dialog" class="flexo-icon-dialog__inner">
				<header class="flexo-icon-dialog__head">
					<h2 id="flexo-icon-dialog-title"><?php esc_html_e( 'Choose an icon', 'flexo-booking' ); ?></h2>
					<button type="submit" value="cancel" class="flexo-icon-dialog__close" aria-label="<?php esc_attr_e( 'Close', 'flexo-booking' ); ?>">&times;</button>
				</header>
				<div class="flexo-icon-dialog__tools">
					<input type="search" class="regular-text" data-flexo-icon-search placeholder="<?php esc_attr_e( 'Search icons…', 'flexo-booking' ); ?>" aria-label="<?php esc_attr_e( 'Search icons', 'flexo-booking' ); ?>">
					<button type="button" class="button" data-flexo-icon-upload><?php esc_html_e( 'Use my own icon (SVG or PNG)', 'flexo-booking' ); ?></button>
					<button type="button" class="button-link" data-flexo-icon-reset><?php esc_html_e( 'Back to the usual icon', 'flexo-booking' ); ?></button>
				</div>
				<div class="flexo-icon-dialog__groups">
					<?php foreach ( Flexo_Booking_Room_Icons::groups() as $group => $icons ) : ?>
						<section class="flexo-icon-group">
							<h3><?php echo esc_html( $group ); ?></h3>
							<div class="flexo-icon-grid">
								<?php foreach ( $icons as $name => $label ) : ?>
									<button type="button" class="flexo-icon-choice" data-icon="<?php echo esc_attr( $name ); ?>" data-search="<?php echo esc_attr( strtolower( $label . ' ' . str_replace( '_', ' ', $name ) ) ); ?>" title="<?php echo esc_attr( $label ); ?>">
										<?php echo Flexo_Booking_Room_Icons::svg( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bundled SVG. ?>
										<span><?php echo esc_html( $label ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>
				</div>
			</form>
		</dialog>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Saves the cards that were on the screen (the nonce and rights are
	 * checked by Flexo_Booking_Rooms::save_meta()).
	 */
	public static function save( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Flexo_Booking_Rooms::save_meta().
		$cards = isset( $_POST['flexo_room_cards'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['flexo_room_cards'] ) ) : array();

		if ( in_array( 'photos', $cards, true ) ) {
			$main = isset( $_POST['flexo_main_photo'] ) ? absint( $_POST['flexo_main_photo'] ) : 0;
			if ( $main && wp_attachment_is_image( $main ) ) {
				set_post_thumbnail( $post_id, $main );
			} else {
				delete_post_thumbnail( $post_id );
			}
			$gallery = Flexo_Booking_Room_Content::sanitize_gallery( isset( $_POST['flexo_gallery'] ) ? sanitize_text_field( wp_unslash( $_POST['flexo_gallery'] ) ) : '' );
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::GALLERY, $gallery );
		}

		if ( in_array( 'facts', $cards, true ) ) {
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::VIEW, isset( $_POST['flexo_view'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['flexo_view'] ) ), 0, 100 ) : '' );
			self::save_types( $post_id );
		}

		if ( in_array( 'amenities', $cards, true ) ) {
			$keys   = isset( $_POST['flexo_amenity_key'] ) ? (array) wp_unslash( $_POST['flexo_amenity_key'] ) : array();
			$labels = isset( $_POST['flexo_amenity_label'] ) ? (array) wp_unslash( $_POST['flexo_amenity_label'] ) : array();
			$icons  = isset( $_POST['flexo_amenity_icon'] ) ? (array) wp_unslash( $_POST['flexo_amenity_icon'] ) : array();
			$items  = array();
			foreach ( $labels as $i => $label ) {
				$items[] = array(
					'key'   => isset( $keys[ $i ] ) ? (string) $keys[ $i ] : '',
					'label' => (string) $label,
					'icon'  => isset( $icons[ $i ] ) ? (string) $icons[ $i ] : '',
				);
			}
			self::save_amenities( $post_id, $items );
		}

		if ( in_array( 'details', $cards, true ) ) {
			$labels = isset( $_POST['flexo_detail_label'] ) ? (array) wp_unslash( $_POST['flexo_detail_label'] ) : array();
			$values = isset( $_POST['flexo_detail_value'] ) ? (array) wp_unslash( $_POST['flexo_detail_value'] ) : array();
			$icons  = isset( $_POST['flexo_detail_icon'] ) ? (array) wp_unslash( $_POST['flexo_detail_icon'] ) : array();
			$rows   = array();
			foreach ( $labels as $i => $label ) {
				$rows[] = array(
					'icon'  => isset( $icons[ $i ] ) ? (string) $icons[ $i ] : '',
					'label' => (string) $label,
					'value' => isset( $values[ $i ] ) ? (string) $values[ $i ] : '',
				);
			}
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::DETAILS, Flexo_Booking_Room_Content::sanitize_details( $rows ) );
		}

		if ( in_array( 'website', $cards, true ) ) {
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::HIDDEN, empty( $_POST['flexo_show'] ) ? '1' : '' );
			if ( Flexo_Booking_Room_Content::is_demo( $post_id ) && empty( $_POST['flexo_demo'] ) ) {
				delete_post_meta( $post_id, Flexo_Booking_Room_Content::DEMO );
			}
		}

		if ( in_array( 'seo', $cards, true ) ) {
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::SEO_TITLE, isset( $_POST['flexo_seo_title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['flexo_seo_title'] ) ), 0, 120 ) : '' );
			self::update_or_delete( $post_id, Flexo_Booking_Room_Content::SEO_DESCRIPTION, isset( $_POST['flexo_seo_description'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['flexo_seo_description'] ) ), 0, 320 ) : '' );
		}
		// phpcs:enable
	}

	/**
	 * Stores the amenity list and keeps the ready-made keys (used before
	 * 1.8.0 and by older export files) in step.
	 */
	public static function save_amenities( $post_id, array $items ) {
		foreach ( $items as $i => $item ) {
			// The owner's own amenity without an icon: pick one from its words.
			if ( is_array( $item ) && empty( $item['key'] ) && empty( $item['icon'] ) && ! empty( $item['label'] ) ) {
				$items[ $i ]['icon'] = Flexo_Booking_Room_Content::guess_icon( $item['label'] );
			}
		}
		$items = Flexo_Booking_Room_Content::sanitize_amenity_items( $items );
		update_post_meta( $post_id, Flexo_Booking_Room_Content::AMENITY_ITEMS, $items );
		$keys = array_values( array_filter( wp_list_pluck( $items, 'key' ) ) );
		self::update_or_delete( $post_id, '_flexo_amenities', $keys );
	}

	private static function save_types( $post_id ) {
		if ( ! current_user_can( 'edit_flexo_rooms' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in Flexo_Booking_Rooms::save_meta().
		$ids = isset( $_POST['flexo_room_types'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['flexo_room_types'] ) ) : array();
		$new = isset( $_POST['flexo_new_room_types'] ) ? (array) wp_unslash( $_POST['flexo_new_room_types'] ) : array();
		// phpcs:enable
		foreach ( array_slice( $new, 0, 10 ) as $name ) {
			$name = mb_substr( sanitize_text_field( $name ), 0, 60 );
			if ( '' === $name ) {
				continue;
			}
			$term = term_exists( $name, Flexo_Booking_Room_Content::TAXONOMY );
			if ( ! $term ) {
				$term = wp_insert_term( $name, Flexo_Booking_Room_Content::TAXONOMY );
			}
			if ( ! is_wp_error( $term ) && $term ) {
				$ids[] = (int) $term['term_id'];
			}
		}
		wp_set_object_terms( $post_id, array_values( array_unique( array_filter( $ids ) ) ), Flexo_Booking_Room_Content::TAXONOMY );
	}

	private static function update_or_delete( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Screen details
	 * ------------------------------------------------------------------ */

	public static function messages( $messages ) {
		global $post;
		if ( ! $post || Flexo_Booking_Rooms::POST_TYPE !== $post->post_type ) {
			return $messages;
		}
		$url  = Flexo_Booking_Room_Content::page_url( $post );
		$view = $url ? ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'View room page', 'flexo-booking' ) . '</a>' : '';
		$messages[ Flexo_Booking_Rooms::POST_TYPE ] = array(
			0  => '',
			1  => esc_html__( 'Room saved.', 'flexo-booking' ) . $view,
			4  => esc_html__( 'Room saved.', 'flexo-booking' ),
			6  => esc_html__( 'Room published – guests can book it now.', 'flexo-booking' ) . $view,
			7  => esc_html__( 'Room saved.', 'flexo-booking' ),
			8  => esc_html__( 'Room submitted.', 'flexo-booking' ),
			9  => esc_html__( 'Room scheduled.', 'flexo-booking' ),
			10 => esc_html__( 'Draft saved.', 'flexo-booking' ),
		);
		return $messages;
	}

	/**
	 * Room types screen: keep "Rooms & prices" highlighted in the menu.
	 */
	public static function parent_file( $parent ) {
		global $taxonomy;
		return Flexo_Booking_Room_Content::TAXONOMY === $taxonomy ? Flexo_Booking_Admin::MENU_SLUG : $parent;
	}

	public static function submenu_file( $file ) {
		global $taxonomy;
		return Flexo_Booking_Room_Content::TAXONOMY === $taxonomy ? 'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE : $file;
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'edit.php' === $hook && $screen && Flexo_Booking_Rooms::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'flexo-booking-admin-room', FLEXO_BOOKING_URL . 'assets/css/admin-room.css', array( 'flexo-booking-admin' ), FLEXO_BOOKING_VERSION );
			return;
		}
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! self::is_room_screen() ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'flexo-booking-admin-room', FLEXO_BOOKING_URL . 'assets/css/admin-room.css', array( 'flexo-booking-admin' ), FLEXO_BOOKING_VERSION );
		wp_enqueue_script( 'flexo-booking-admin-room', FLEXO_BOOKING_URL . 'assets/js/admin-room.js', array( 'jquery', 'jquery-ui-sortable' ), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			'flexo-booking-admin-room',
			'FlexoRoom',
			array(
				'i18n' => array(
					'mainPhoto'    => __( 'Main photo', 'flexo-booking' ),
					'usePhoto'     => __( 'Use this photo', 'flexo-booking' ),
					'gallery'      => __( 'Add photos to the gallery', 'flexo-booking' ),
					'addPhotos'    => __( 'Add to gallery', 'flexo-booking' ),
					'chooseIcon'   => __( 'Your own icon', 'flexo-booking' ),
					'useIcon'      => __( 'Use this icon', 'flexo-booking' ),
					/* translators: %d: number of photos */
					'photos'       => __( '(%d)', 'flexo-booking' ),
					/* translators: 1: characters typed, 2: recommended maximum */
					'count'        => __( '%1$d / %2$d', 'flexo-booking' ),
					'tooLong'      => __( 'longer than search engines show', 'flexo-booking' ),
					'typeExists'   => __( 'This type is already in the list.', 'flexo-booking' ),
					'amenityAdded' => __( 'Already in the list.', 'flexo-booking' ),
					'siteName'     => get_bloginfo( 'name' ),
				),
				'iconWords' => Flexo_Booking_Room_Content::icon_keywords(),
			)
		);
	}
}
