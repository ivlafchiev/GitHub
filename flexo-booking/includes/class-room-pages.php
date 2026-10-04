<?php
/**
 * Public room pages: /{base}/{room-slug}/.
 *
 * - The base comes from Settings → Room pages (default "rooms"); earlier
 *   bases keep redirecting to the new addresses.
 * - Old room slugs redirect (WordPress keeps them in _wp_old_slug).
 * - A static page at the same address (e.g. an old /rooms/deluxe/ child
 *   page) is still served when no room has that slug.
 * - Rooms switched off for the website are 404 for guests and left out of
 *   lists, search and sitemaps; they stay bookable. Demo rooms are only
 *   visible to staff.
 * - Rewrite rules are flushed once, on the next request, after activation,
 *   an update or a change of the base – never on every load.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Room_Pages {

	const FLUSH_OPTION = 'flexo_booking_flush_rewrite';
	const BASES_OPTION = 'flexo_booking_room_bases';
	const PAGE_CACHE   = 'flexo_booking_rooms_page';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'request', array( __CLASS__, 'request' ) );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 1 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'redirect_canonical' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_args' ), 10, 2 );
		add_action( 'update_option_' . Flexo_Booking_Settings::OPTION, array( __CLASS__, 'settings_saved' ), 10, 2 );
		add_action( 'save_post_page', array( __CLASS__, 'forget_rooms_page' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'init', array( __CLASS__, 'register_block_template' ), 20 );
		add_filter( 'the_content', array( __CLASS__, 'block_theme_content' ), 20 );
	}

	/* ------------------------------------------------------------------ *
	 * Default room page
	 * ------------------------------------------------------------------ */

	/**
	 * Whether an Elementor Pro Theme Builder single template applies to this
	 * page (then Pro shows the room and the default page steps aside).
	 */
	public static function pro_template_applies() {
		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return false;
		}
		try {
			$documents = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_documents_for_location( 'single' );
			return ! empty( $documents );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Classic themes: the plugin's room page, unless the theme has its own
	 * (single-flexo_room.php or flexo-booking/single-room.php) or an
	 * Elementor Pro single template applies.
	 */
	public static function template( $template ) {
		if ( ! is_singular( Flexo_Booking_Rooms::POST_TYPE ) || is_404() || self::pro_template_applies() || wp_is_block_theme() ) {
			return $template;
		}
		if ( 'single-' . Flexo_Booking_Rooms::POST_TYPE . '.php' === basename( (string) $template ) ) {
			return $template;
		}
		$theme = locate_template( 'flexo-booking/single-room.php' );
		return $theme ? $theme : FLEXO_BOOKING_DIR . 'templates/single-room.php';
	}

	/**
	 * Block themes: a "Single room" template with the theme's header and
	 * footer around the room page (editable in the Site Editor).
	 */
	public static function register_block_template() {
		// The block that shows the room page (no automatic paragraphs, unlike the Shortcode block).
		wp_register_script( 'flexo-booking-room-block', FLEXO_BOOKING_URL . 'assets/js/room-block.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor' ), FLEXO_BOOKING_VERSION, true );
		wp_localize_script(
			'flexo-booking-room-block',
			'FlexoRoomBlock',
			array(
				'title'       => __( 'Room page', 'flexo-booking' ),
				'description' => __( 'Each room\'s photos, description, amenities, details, rates and booking box (Flexo Booking).', 'flexo-booking' ),
			)
		);
		register_block_type(
			'flexo-booking/room',
			array(
				'api_version'     => 3,
				'title'           => __( 'Room page', 'flexo-booking' ),
				'category'        => 'theme',
				'editor_script'   => 'flexo-booking-room-block',
				'render_callback' => array( 'Flexo_Booking_Room_Render', 'room_shortcode' ),
				'supports'        => array( 'html' => false ),
			)
		);
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}
		register_block_template(
			'flexo-booking//single-' . Flexo_Booking_Rooms::POST_TYPE,
			array(
				'title'       => __( 'Single room', 'flexo-booking' ),
				'description' => __( 'A room\'s page: photos, description, amenities and the booking box (Flexo Booking).', 'flexo-booking' ),
				'post_types'  => array( Flexo_Booking_Rooms::POST_TYPE ),
				'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
					. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained","contentSize":"1200px"}} --><main class="wp-block-group">'
					. '<!-- wp:flexo-booking/room /-->'
					. '</main><!-- /wp:group -->'
					. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
			)
		);
	}

	/**
	 * Block themes on WordPress before 6.7 (no plugin templates): the
	 * theme's single template shows the name and description; the rest of
	 * the room page follows the description.
	 */
	public static function block_theme_content( $content ) {
		if ( function_exists( 'register_block_template' ) || ! wp_is_block_theme() || ! is_singular( Flexo_Booking_Rooms::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || self::pro_template_applies() ) {
			return $content;
		}
		$room = Flexo_Booking_Room_Content::room( get_the_ID() );
		if ( ! $room ) {
			return $content;
		}
		Flexo_Booking_Room_Render::enqueue( true );
		return $content
			. Flexo_Booking_Room_Render::gallery( $room, array( 'lightbox' => 'flexo', 'placeholder' => false ) )
			. Flexo_Booking_Room_Render::details( $room, array( 'layout' => 'inline', 'show' => 'value' ) )
			. Flexo_Booking_Room_Render::amenities( $room['amenity_list'], array( 'layout' => 'grid' ) )
			. Flexo_Booking_Frontend::render( array( 'layout' => 'box', 'room' => (string) $room['id'] ) );
	}

	/**
	 * The first part of every room address, e.g. "rooms" in /rooms/deluxe-double/.
	 */
	public static function base() {
		$base = sanitize_title( (string) Flexo_Booking_Settings::get( 'room_base' ) );
		return '' !== $base ? $base : 'rooms';
	}

	/**
	 * Bases that are not allowed because WordPress or the site uses them.
	 */
	public static function reserved_bases() {
		return array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'type', 'embed', 'attachment' );
	}

	public static function schedule_flush() {
		update_option( self::FLUSH_OPTION, 1 );
	}

	/**
	 * Flushes the rewrite rules once when asked to, or when the room rules
	 * are missing (e.g. the plugin was updated by copying files).
	 */
	public static function maybe_flush() {
		$flush = (bool) get_option( self::FLUSH_OPTION );
		if ( ! $flush && get_option( 'permalink_structure' ) ) {
			$rules = get_option( 'rewrite_rules' );
			$flush = is_array( $rules ) && ! isset( $rules[ self::base() . '/([^/]+)(?:/([0-9]+))?/?$' ] );
		}
		if ( $flush ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Keeps the previous base for redirects and flushes the rules when the
	 * base changes.
	 */
	public static function settings_saved( $old, $new ) {
		$old_base = is_array( $old ) && ! empty( $old['room_base'] ) ? sanitize_title( $old['room_base'] ) : 'rooms';
		$new_base = is_array( $new ) && ! empty( $new['room_base'] ) ? sanitize_title( $new['room_base'] ) : 'rooms';
		if ( $old_base === $new_base ) {
			return;
		}
		$bases   = array_diff( (array) get_option( self::BASES_OPTION, array() ), array( $new_base, $old_base ) );
		$bases[] = $old_base;
		update_option( self::BASES_OPTION, array_slice( array_values( $bases ), -10 ), false );
		delete_transient( self::PAGE_CACHE );
		self::schedule_flush();
	}

	/**
	 * /{base}/{slug}/ with no room of that slug but a published page at that
	 * path: serve the page.
	 */
	public static function request( $vars ) {
		$type = Flexo_Booking_Rooms::POST_TYPE;
		if ( empty( $vars[ $type ] ) || ! is_string( $vars[ $type ] ) || is_admin() ) {
			return $vars;
		}
		$slug = $vars[ $type ];
		if ( self::slug_in_use( $slug ) ) {
			return $vars;
		}
		$path = self::base() . '/' . $slug;
		$page = get_page_by_path( $path );
		if ( $page && 'publish' === $page->post_status ) {
			return array( 'pagename' => $path );
		}
		return $vars;
	}

	/**
	 * Whether a room has (or had) this slug.
	 */
	private static function slug_in_use( $slug ) {
		global $wpdb;
		$slug = sanitize_title( rawurldecode( $slug ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one indexed lookup on a room address.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status <> 'trash' LIMIT 1", Flexo_Booking_Rooms::POST_TYPE, $slug ) );
		if ( ! $found ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND m.meta_key = '_wp_old_slug' AND m.meta_value = %s LIMIT 1", Flexo_Booking_Rooms::POST_TYPE, $slug ) );
		}
		// phpcs:enable
		return (bool) $found;
	}

	public static function template_redirect() {
		if ( is_singular( Flexo_Booking_Rooms::POST_TYPE ) ) {
			$id = (int) get_queried_object_id();
			if ( ( Flexo_Booking_Room_Content::is_hidden( $id ) || Flexo_Booking_Room_Content::is_demo( $id ) ) && ! current_user_can( 'edit_post', $id ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
			return;
		}
		if ( is_404() ) {
			self::redirect_old_base();
		}
	}

	/**
	 * WordPress guesses the page for a mistyped address; never guess a room
	 * guests may not see.
	 */
	public static function redirect_canonical( $url ) {
		if ( ! $url || ! is_404() ) {
			return $url;
		}
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( ! preg_match( '#(?:^|/)' . preg_quote( self::base(), '#' ) . '/([^/]+)$#', $path, $m ) ) {
			return $url;
		}
		$ids = get_posts(
			array(
				'post_type'       => Flexo_Booking_Rooms::POST_TYPE,
				'name'            => sanitize_title( rawurldecode( $m[1] ) ),
				'post_status'     => 'any',
				'fields'          => 'ids',
				'posts_per_page'  => 1,
				'flexo_all_rooms' => true,
			)
		);
		$id  = $ids ? (int) $ids[0] : 0;
		if ( $id && ( Flexo_Booking_Room_Content::is_hidden( $id ) || Flexo_Booking_Room_Content::is_demo( $id ) ) && ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}
		return $url;
	}

	/**
	 * /{old base}/{slug}/ → the room's current address.
	 */
	private static function redirect_old_base() {
		$bases = array_filter( (array) get_option( self::BASES_OPTION, array() ) );
		if ( ! $bases || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = trim( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ), '/' );
		foreach ( $bases as $old ) {
			if ( preg_match( '#(?:^|/)' . preg_quote( $old, '#' ) . '/([^/]+)/?$#', $path, $m ) ) {
				$post = Flexo_Booking_Rooms::find( rawurldecode( $m[1] ) );
				$url  = $post ? Flexo_Booking_Room_Content::page_url( $post ) : '';
				if ( $url && ! Flexo_Booking_Room_Content::is_demo( $post->ID ) ) {
					wp_safe_redirect( $url, 301 );
					exit;
				}
			}
		}
	}

	/**
	 * Leaves hidden rooms (and demo rooms, except for staff) out of
	 * front-end lists: Loop Grid, Loop Carousel, search, menus. The booking
	 * engine's own queries set "flexo_all_rooms" and still see them.
	 *
	 * @param WP_Query $query
	 */
	public static function pre_get_posts( $query ) {
		if ( $query->get( 'flexo_all_rooms' ) || ( is_admin() && ! self::is_elementor_request() ) ) {
			return;
		}
		$types = $query->get( 'post_type' );
		if ( empty( $types ) ) {
			if ( ! $query->is_search() ) {
				return;
			}
		} elseif ( 'any' !== $types && ! in_array( Flexo_Booking_Rooms::POST_TYPE, (array) $types, true ) ) {
			return;
		}
		if ( $query->is_main_query() && $query->is_singular() ) {
			return; // A room's own page: see template_redirect().
		}
		$exclude = Flexo_Booking_Room_Content::excluded_ids();
		if ( $exclude ) {
			$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $exclude ) ) ) );
		}
	}

	/**
	 * Elementor renders widgets in the editor through admin-ajax.
	 */
	private static function is_elementor_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reads which action runs.
		return wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'elementor_ajax' === $_REQUEST['action'];
	}

	public static function robots( $robots ) {
		if ( is_singular( Flexo_Booking_Rooms::POST_TYPE ) ) {
			$id = (int) get_queried_object_id();
			if ( Flexo_Booking_Room_Content::is_hidden( $id ) || Flexo_Booking_Room_Content::is_demo( $id ) ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
			}
		}
		return $robots;
	}

	public static function sitemap_args( $args, $post_type ) {
		if ( Flexo_Booking_Rooms::POST_TYPE === $post_type ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), self::not_public_ids() );
		}
		return $args;
	}

	/**
	 * Hidden and demo rooms (for sitemaps and search engines, whoever asks).
	 *
	 * @return int[]
	 */
	public static function not_public_ids() {
		$ids = array();
		foreach ( Flexo_Booking_Rooms::all( array( 'publish', 'private', 'draft', 'pending', 'future' ) ) as $post ) {
			if ( Flexo_Booking_Room_Content::is_hidden( $post->ID ) || Flexo_Booking_Room_Content::is_demo( $post->ID ) ) {
				$ids[] = (int) $post->ID;
			}
		}
		return $ids;
	}

	/* ------------------------------------------------------------------ *
	 * The "All rooms" page
	 * ------------------------------------------------------------------ */

	/**
	 * Address of the page listing all rooms: the one in Settings, else a
	 * page found automatically (a page at /{base}/, or a page whose
	 * Elementor Loop Grid / Loop Carousel lists rooms).
	 */
	public static function rooms_page_url() {
		$url = Flexo_Booking_Settings::site_url_setting( 'rooms_page' );
		if ( '' !== $url ) {
			return Flexo_Booking_I18n::page_url( $url );
		}
		$url = self::detect_rooms_page();
		return '' !== $url ? Flexo_Booking_I18n::page_url( $url ) : '';
	}

	public static function detect_rooms_page() {
		$cached = get_transient( self::PAGE_CACHE );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$url  = '';
		$page = get_page_by_path( self::base() );
		if ( $page && 'publish' === $page->post_status ) {
			$url = get_permalink( $page );
		}
		if ( '' === $url ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- cached below.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_type = 'page' AND p.post_status = 'publish' AND m.meta_value LIKE %s ORDER BY p.menu_order, p.ID LIMIT 1", '%' . $wpdb->esc_like( '"post_query_post_type":"' . Flexo_Booking_Rooms::POST_TYPE . '"' ) . '%' ) );
			$url = $id ? get_permalink( (int) $id ) : '';
		}
		set_transient( self::PAGE_CACHE, (string) $url, 12 * HOUR_IN_SECONDS );
		return (string) $url;
	}

	public static function forget_rooms_page() {
		delete_transient( self::PAGE_CACHE );
	}

	/* ------------------------------------------------------------------ *
	 * Settings → Room pages
	 * ------------------------------------------------------------------ */

	/**
	 * @param array  $s    Current settings.
	 * @param string $name Option name for the form fields.
	 */
	public static function render_settings( array $s, $name ) {
		$home     = trailingslashit( home_url() );
		$rooms    = Flexo_Booking_Rooms::all();
		$detected = self::detect_rooms_page();
		?>
		<p class="flexo-tab-intro"><?php esc_html_e( 'Every room has its own page with its photos, description, amenities and a booking box. The design comes from your Elementor single room template.', 'flexo-booking' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fb-room-base"><?php esc_html_e( 'Room page address', 'flexo-booking' ); ?></label></th>
				<td>
					<span class="flexo-url-field"><span class="flexo-url-field__home"><?php echo esc_html( $home ); ?></span><input id="fb-room-base" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[room_base]" value="<?php echo esc_attr( self::base() ); ?>" pattern="[A-Za-z0-9\-]+" required><span class="flexo-url-field__rest">/<?php echo esc_html( $rooms ? $rooms[0]->post_name : 'deluxe-double' ); ?>/</span></span>
					<p class="description"><?php esc_html_e( 'The word before each room\'s name in its address: Latin letters, numbers and dashes, e.g. rooms or stai. If you change it, the old addresses keep working and lead to the new ones.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fb-rooms-page"><?php esc_html_e( 'All rooms page', 'flexo-booking' ); ?></label></th>
				<td>
					<input id="fb-rooms-page" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[rooms_page]" value="<?php echo esc_attr( $s['rooms_page'] ); ?>" placeholder="<?php echo esc_attr( $detected ? wp_make_link_relative( $detected ) : '/' . self::base() . '/' ); ?>">
					<p class="description">
						<?php esc_html_e( 'The page that lists your rooms. Room pages link back to it.', 'flexo-booking' ); ?>
						<?php if ( '' === $s['rooms_page'] && $detected ) : ?>
							<?php
							/* translators: %s: page address */
							printf( esc_html__( 'Found automatically: %s', 'flexo-booking' ), '<code>' . esc_html( wp_make_link_relative( $detected ) ) . '</code>' );
							?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fb-room-price"><?php esc_html_e( 'Prices on room pages', 'flexo-booking' ); ?></label></th>
				<td>
					<select id="fb-room-price" name="<?php echo esc_attr( $name ); ?>[room_price_display]">
						<option value="from" <?php selected( $s['room_price_display'], 'from' ); ?>><?php esc_html_e( 'Show the "from" price per night', 'flexo-booking' ); ?></option>
						<option value="none" <?php selected( $s['room_price_display'], 'none' ); ?>><?php esc_html_e( 'Don\'t show prices before guests choose dates', 'flexo-booking' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The "from" price is the lowest price per night in the next 12 months for 2 adults, with seasons, weekend prices and the room\'s first rate, without tourist tax and promo codes. It updates by itself when prices change.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On phones', 'flexo-booking' ); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[room_sticky_bar]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[room_sticky_bar]" value="1" <?php checked( ! empty( $s['room_sticky_bar'] ) ); ?>> <?php esc_html_e( 'Show a "Check availability" bar at the bottom of room pages', 'flexo-booking' ); ?></label>
					<p class="description"><?php esc_html_e( 'It takes guests to the room\'s booking box, and hides while the box is on screen.', 'flexo-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<?php if ( $rooms ) : ?>
			<h2 class="title"><?php esc_html_e( 'Your room pages', 'flexo-booking' ); ?></h2>
			<table class="widefat striped flexo-room-pages-table">
				<thead><tr><th><?php esc_html_e( 'Room', 'flexo-booking' ); ?></th><th><?php esc_html_e( 'Page', 'flexo-booking' ); ?></th><th><?php esc_html_e( '"From" price', 'flexo-booking' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $rooms as $post ) : ?>
					<?php $url = Flexo_Booking_Room_Content::page_url( $post ); ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post ) ); ?>"><?php echo esc_html( get_the_title( $post ) ); ?></a></td>
						<td>
							<?php if ( Flexo_Booking_Room_Content::is_demo( $post->ID ) ) : ?>
								<span class="flexo-badge"><?php esc_html_e( 'Demo – only you see it', 'flexo-booking' ); ?></span>
							<?php elseif ( '' === $url ) : ?>
								<span class="flexo-badge flexo-badge--muted"><?php esc_html_e( 'Not on the website', 'flexo-booking' ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_make_link_relative( $url ) ); ?></a>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$from = Flexo_Booking_Room_Prices::get( $post->ID );
							if ( $from ) {
								/* translators: 1: price per night, 2: number of nights, 3: arrival date */
								echo esc_html( sprintf( _n( '%1$s (%2$d night from %3$s)', '%1$s (%2$d nights from %3$s)', $from['nights'], 'flexo-booking' ), Flexo_Booking_Money::format( $from['amount'], null, true ), $from['nights'], Flexo_Booking_I18n::format_date( $from['check_in'] ) ) );
							} else {
								echo '<span class="flexo-muted">' . esc_html__( 'None – check the price and closed dates', 'flexo-booking' ) . '</span>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
		Flexo_Booking_Elementor_Templates::render_settings();
	}
}
