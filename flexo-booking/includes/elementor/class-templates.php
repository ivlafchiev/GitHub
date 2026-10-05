<?php
/**
 * Starter Elementor templates for rooms (assets/elementor/):
 *
 * - "Single room" – Theme Builder single template for every room page.
 * - "Room card" – Loop item for Loop Grid / Loop Carousel room lists.
 *
 * With Elementor Pro active, Settings → Room pages adds both in one click
 * (the single template gets the condition "Rooms" unless another room
 * template is already set). Without Pro the files can be downloaded and
 * imported under Templates → Import.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Elementor_Templates {

	const ACTION = 'flexo_booking_install_templates';

	const FILES = array(
		'card'   => 'flexo-room-card.json',
		'single' => 'flexo-single-room.json',
	);

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Elementor Pro with Theme Builder and Loop items is active.
	 */
	public static function can_install() {
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) || ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance->documents ) ) {
			return false;
		}
		$documents = \Elementor\Plugin::$instance->documents;
		return (bool) $documents->get_document_type( 'single-post', false ) && (bool) $documents->get_document_type( 'loop-item', false );
	}

	/**
	 * Theme Builder template types for single pages (headers, footers,
	 * archives, popups and loop items are not room templates).
	 */
	const SINGLE_TYPES = array( 'single', 'single-post', 'single-page' );

	/**
	 * Published Theme Builder single templates with display conditions.
	 *
	 * @return WP_Post[]
	 */
	private static function single_templates() {
		return get_posts(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				// phpcs:ignore WordPress.DB.SlowDBQuery -- admin screen only.
				'meta_query'     => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => self::SINGLE_TYPES,
						'compare' => 'IN',
					),
					array(
						'key'     => '_elementor_conditions',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	/**
	 * A Theme Builder single template already shown on room pages, if any.
	 *
	 * @return WP_Post|null
	 */
	public static function room_template() {
		foreach ( self::single_templates() as $post ) {
			foreach ( (array) get_post_meta( $post->ID, '_elementor_conditions', true ) as $condition ) {
				$condition = (string) $condition;
				if ( 0 === strpos( $condition, 'include/singular/' . Flexo_Booking_Rooms::POST_TYPE ) || 'include/singular' === $condition || 'include/general' === $condition ) {
					return $post;
				}
			}
		}
		return null;
	}

	/**
	 * Single templates meant for rooms that are not Flexo Booking rooms –
	 * e.g. made for JetEngine's "rooms", or for a post type this site no
	 * longer has.
	 *
	 * @return array[] { post: WP_Post, type: string }
	 */
	public static function other_room_templates() {
		$found = array();
		foreach ( self::single_templates() as $post ) {
			foreach ( (array) get_post_meta( $post->ID, '_elementor_conditions', true ) as $condition ) {
				if ( ! preg_match( '#^include/singular/([a-z0-9_-]+)#', (string) $condition, $m ) ) {
					continue;
				}
				$type = preg_replace( '/_by_author$/', '', $m[1] );
				if ( 0 === strpos( $type, 'in_' ) || Flexo_Booking_Rooms::POST_TYPE === $type ) {
					continue;
				}
				if ( ! post_type_exists( $type ) || false !== stripos( $type, 'room' ) ) {
					$found[] = array(
						'post' => $post,
						'type' => $type,
					);
					break;
				}
			}
		}
		return $found;
	}

	public static function file_url( $key ) {
		return FLEXO_BOOKING_URL . 'assets/elementor/' . self::FILES[ $key ];
	}

	/**
	 * Texts of the starter templates in the site's language.
	 */
	private static function texts() {
		return array(
			'Room type'                   => __( 'Room type', 'flexo-booking' ),
			'Room name'                   => __( 'Room name', 'flexo-booking' ),
			'Full description'            => __( 'Full description', 'flexo-booking' ),
			'Amenities'                   => __( 'Amenities', 'flexo-booking' ),
			'Good to know'                => __( 'Good to know', 'flexo-booking' ),
			'Check availability'          => __( 'Check availability', 'flexo-booking' ),
			'Other rooms'                 => __( 'Other rooms', 'flexo-booking' ),
			'View room'                   => __( 'View room', 'flexo-booking' ),
			'Book now'                    => __( 'Book now', 'flexo-booking' ),
			'Single room (Flexo Booking)' => __( 'Single room (Flexo Booking)', 'flexo-booking' ),
			'Room card (Flexo Booking)'   => __( 'Room card (Flexo Booking)', 'flexo-booking' ),
		);
	}

	/**
	 * A starter template ready to save: texts translated, placeholders filled.
	 *
	 * @param string $key     card | single.
	 * @param array  $replace Placeholder => value.
	 * @return array|WP_Error
	 */
	public static function data( $key, array $replace = array() ) {
		$json = file_get_contents( FLEXO_BOOKING_DIR . 'assets/elementor/' . self::FILES[ $key ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || empty( $data['content'] ) ) {
			return new WP_Error( 'flexo_template', __( 'The starter template file could not be read.', 'flexo-booking' ) );
		}
		$texts = self::texts();
		$walk  = static function ( &$value ) use ( &$walk, $texts, $replace ) {
			if ( is_array( $value ) ) {
				foreach ( $value as &$item ) {
					$walk( $item );
				}
				return;
			}
			if ( is_string( $value ) ) {
				if ( isset( $replace[ $value ] ) ) {
					$value = $replace[ $value ];
				} elseif ( isset( $texts[ $value ] ) ) {
					$value = $texts[ $value ];
				}
			}
		};
		$walk( $data['content'] );
		$data['title'] = isset( $texts[ $data['title'] ] ) ? $texts[ $data['title'] ] : $data['title'];
		return $data;
	}

	/**
	 * Adds both templates to Elementor's library.
	 *
	 * @param bool $activate Show the single template on all room pages
	 *                       (only when no other room template is set).
	 * @return array|WP_Error { single: int, card: int, active: bool }
	 */
	public static function install( $activate = true ) {
		if ( ! self::can_install() ) {
			return new WP_Error( 'flexo_template', __( 'Elementor Pro is needed for room templates.', 'flexo-booking' ) );
		}
		$source = \Elementor\Plugin::$instance->templates_manager->get_source( 'local' );
		$card   = self::data( 'card' );
		if ( is_wp_error( $card ) ) {
			return $card;
		}
		$card_id = $source->save_item( $card );
		if ( is_wp_error( $card_id ) ) {
			return $card_id;
		}
		$single = self::data( 'single', array( '{{flexo_room_card}}' => (string) $card_id ) );
		if ( is_wp_error( $single ) ) {
			return $single;
		}
		$single_id = $source->save_item( $single );
		if ( is_wp_error( $single_id ) ) {
			return $single_id;
		}
		$active = $activate && ! self::room_template() && self::show_on_rooms( (int) $single_id );
		return array(
			'single' => (int) $single_id,
			'card'   => (int) $card_id,
			'active' => $active,
		);
	}

	/**
	 * Display condition "Rooms" (all room pages) for a single template.
	 */
	private static function show_on_rooms( $template_id ) {
		if ( ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
			return false;
		}
		try {
			$manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
			if ( method_exists( $manager, 'save_conditions' ) ) {
				return (bool) $manager->save_conditions(
					$template_id,
					array(
						array(
							'type'     => 'include',
							'name'     => 'singular',
							'sub_name' => Flexo_Booking_Rooms::POST_TYPE,
							'sub_id'   => '',
						),
					)
				);
			}
			update_post_meta( $template_id, '_elementor_conditions', array( 'include/singular/' . Flexo_Booking_Rooms::POST_TYPE ) );
			if ( method_exists( $manager, 'get_cache' ) && method_exists( $manager->get_cache(), 'regenerate' ) ) {
				$manager->get_cache()->regenerate();
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'flexo-booking' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$result = self::install( true );
		$back   = add_query_arg(
			array(
				'page' => Flexo_Booking_Admin::MENU_SLUG . '-settings',
				'tab'  => 'room_pages',
			),
			admin_url( 'admin.php' )
		);
		if ( is_wp_error( $result ) ) {
			set_transient( 'flexo_booking_templates_notice', array( 'error', $result->get_error_message() ), 60 );
		} else {
			set_transient( 'flexo_booking_templates_notice', array( 'success', $result ), 60 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * The "Elementor templates for rooms" part of Settings → Room pages.
	 */
	public static function render_settings() {
		$notice = get_transient( 'flexo_booking_templates_notice' );
		delete_transient( 'flexo_booking_templates_notice' );
		$current = self::room_template();
		?>
		<h2 class="title"><?php esc_html_e( 'Elementor templates for rooms', 'flexo-booking' ); ?></h2>
		<?php if ( is_array( $notice ) && 'error' === $notice[0] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $notice[1] ); ?></p></div>
		<?php elseif ( is_array( $notice ) && 'success' === $notice[0] ) : ?>
			<div class="notice notice-success inline"><p>
				<?php echo esc_html( $notice[1]['active'] ? __( 'The room templates were added. Every room page now uses the "Single room" template.', 'flexo-booking' ) : __( 'The room templates were added to Elementor. Your current room template stays in use; switch whenever you like under Templates → Theme Builder.', 'flexo-booking' ) ); ?>
				<?php $doc = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::$instance->documents->get( $notice[1]['single'] ) : null; ?>
				<?php if ( $doc ) : ?>
					<a href="<?php echo esc_url( $doc->get_edit_url() ); ?>"><?php esc_html_e( 'Edit it with Elementor', 'flexo-booking' ); ?></a>
				<?php endif; ?>
			</p></div>
		<?php endif; ?>
		<p class="flexo-tab-intro">
			<?php esc_html_e( 'Design one room page and one room card in Elementor; every room fills them with its own name, photos, description, amenities and prices. Start from your own template (connect its widgets to the "Flexo Booking: room" dynamic tags) or from the starter templates.', 'flexo-booking' ); ?>
		</p>
		<?php $design = Flexo_Booking_Room_Design::id(); ?>
		<?php if ( $design ) : ?>
			<p>
				<?php
				/* translators: %s: page or template name */
				printf( esc_html__( 'Room pages use the design "%s" chosen above.', 'flexo-booking' ), esc_html( get_the_title( $design ) ) );
				?>
			</p>
		<?php elseif ( $current ) : ?>
			<p>
				<?php
				/* translators: %s: template name */
				printf( esc_html__( 'Room pages use your Theme Builder template "%s".', 'flexo-booking' ), esc_html( get_the_title( $current ) ) );
				?>
				<?php $doc = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ? \Elementor\Plugin::$instance->documents->get( $current->ID ) : null; ?>
				<?php if ( $doc ) : ?>
					<a href="<?php echo esc_url( $doc->get_edit_url() ); ?>"><?php esc_html_e( 'Edit it with Elementor', 'flexo-booking' ); ?></a>
				<?php endif; ?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'No Elementor template is set for rooms yet, so room pages use the plugin\'s own room page.', 'flexo-booking' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $design ? array() : self::other_room_templates() as $other ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: 1: template name, 2: post type name, e.g. rooms */
				printf( esc_html__( 'Your template "%1$s" is set to show on "%2$s", which are not the Flexo Booking rooms (for example JetEngine rooms), so room pages don\'t use it. Choose it above as the room page design, or change its display condition to Rooms in Elementor.', 'flexo-booking' ), esc_html( get_the_title( $other['post'] ) ), esc_html( $other['type'] ) );
				?>
			</p></div>
		<?php endforeach; ?>
		<?php if ( self::can_install() ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ); ?>"><?php echo esc_html( $current ? __( 'Add the starter templates (keep my template in use)', 'flexo-booking' ) : __( 'Add the starter room templates', 'flexo-booking' ) ); ?></a>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Room templates need Elementor Pro (Theme Builder and Loop Grid). You can still download the starter templates and import them later under Templates → Import:', 'flexo-booking' ); ?></p>
		<?php endif; ?>
		<p class="flexo-downloads">
			<a href="<?php echo esc_url( self::file_url( 'single' ) ); ?>" download><?php esc_html_e( 'Single room template (.json)', 'flexo-booking' ); ?></a>
			·
			<a href="<?php echo esc_url( self::file_url( 'card' ) ); ?>" download><?php esc_html_e( 'Room card for Loop Grid (.json)', 'flexo-booking' ); ?></a>
		</p>
		<?php
	}
}
