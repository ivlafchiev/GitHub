<?php
/**
 * The look of the plugin's own admin screens: an app bar (brand, booking
 * search, what needs attention, quick actions), a page header with the
 * section tabs, a small icon set and the footer line.
 *
 * Everything is scoped to the plugin's screens through the body class
 * "flexo-admin-ui" (assets/css/admin-ui.css); the rest of the WordPress
 * admin is never touched.
 *
 * @package FlexoBooking
 */

defined( 'ABSPATH' ) || exit;

class Flexo_Booking_Admin_UI {

	public static function init() {
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'appbar' ) );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 20 );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 20 );
	}

	/**
	 * Whether the current admin screen belongs to the plugin (its pages and
	 * the room list / editor).
	 */
	public static function is_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return Flexo_Booking_Rooms::POST_TYPE === $screen->post_type || false !== strpos( (string) $screen->id, Flexo_Booking_Admin::MENU_SLUG );
	}

	public static function body_class( $classes ) {
		return self::is_screen() ? $classes . ' flexo-admin-ui' : $classes;
	}

	/* ---------------------------------------------------------------------
	 * Icons (24 × 24, drawn with the current text colour)
	 * ------------------------------------------------------------------- */

	private static function paths() {
		return array(
			'logo'      => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4M9 15.2l2 2 4-4.2"/>',
			'calendar'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
			'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
			'plus'      => '<path d="M12 5v14M5 12h14"/>',
			'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
			'help'      => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 0 1 4.8 1c0 1.7-2.4 2.1-2.4 3.6M12 17.2v.1"/>',
			'bell'      => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
			'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
			'list'      => '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
			'bed'       => '<path d="M3 19V6M3 15h18v4M21 15v-3a3 3 0 0 0-3-3h-7v6"/><circle cx="7" cy="11.5" r="2"/>',
			'tag'       => '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.4 1.4 0 0 1 0 2l-6.3 6.3a1.4 1.4 0 0 1-2 0z"/><circle cx="8" cy="8" r="1.4"/>',
			'mail'      => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
			'palette'   => '<path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.2 0 1.8-.8 1.8-1.7 0-1.2-1-1.6-1-2.6s.8-1.7 1.8-1.7h2.2a3.7 3.7 0 0 0 3.7-3.7c0-4.1-3.8-7.3-8.5-7.3z"/><path d="M7.5 11h.01M10 7.5h.01M14.5 7.5h.01"/>',
			'sliders'   => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
			'key'       => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16 7l2.5 2.5M14 9l2 2"/>',
			'arrive'    => '<path d="M10 17l5-5-5-5M15 12H3M14 4h4.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H14"/>',
			'depart'    => '<path d="M15 17l5-5-5-5M20 12H8M10 4H5.5A1.5 1.5 0 0 0 4 5.5v13A1.5 1.5 0 0 0 5.5 20H10"/>',
			'moon'      => '<path d="M19.5 14.5a7.5 7.5 0 0 1-10-10 7.5 7.5 0 1 0 10 10z"/>',
			'chart'     => '<path d="M4 19.5h16M7 16v-4M12 16V7M17 16v-6"/>',
			'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'alert'     => '<path d="M10.3 4.5 2.9 17.5A2 2 0 0 0 4.6 20.5h14.8a2 2 0 0 0 1.7-3L13.7 4.5a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
			'info'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
			'x'         => '<path d="M6 6l12 12M18 6 6 18"/>',
			'card'      => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'bank'      => '<path d="M3 9.5 12 4l9 5.5M5 10v7M9.5 10v7M14.5 10v7M19 10v7M3.5 20h17"/>',
			'users'     => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0M16 5.6a3 3 0 0 1 0 5.8M17.5 14.3a5 5 0 0 1 3 5.2"/>',
			'sync'      => '<path d="M20 11a8 8 0 0 0-14.6-4.4M4 4v3.5h3.5M4 13a8 8 0 0 0 14.6 4.4M20 20v-3.5h-3.5"/>',
			'pulse'     => '<path d="M3 12h4l2.5-6 5 12 2.5-6h4"/>',
			'shield'    => '<path d="M12 3.5 5 6v5.5c0 4.3 3 7.7 7 9 4-1.3 7-4.7 7-9V6z"/>',
			'receipt'   => '<path d="M6 3.5h12v17l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/>',
			'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
			'toggle'    => '<rect x="2.5" y="7" width="19" height="10" rx="5"/><circle cx="16.5" cy="12" r="2.6"/>',
			'hotel'     => '<path d="M4 20.5V5a1.5 1.5 0 0 1 1.5-1.5h13A1.5 1.5 0 0 1 20 5v15.5M2.5 20.5h19M9.5 20.5v-4h5v4M8 7.5h2M14 7.5h2M8 11.5h2M14 11.5h2"/>',
			'transfer'  => '<path d="M7 4v13M3.5 13.5 7 17l3.5-3.5M17 20V7M13.5 10.5 17 7l3.5 3.5"/>',
			'sparkle'   => '<path d="m12 3.5 1.8 4.7 4.7 1.8-4.7 1.8-1.8 4.7-1.8-4.7L5.5 10l4.7-1.8z"/><path d="m18.5 15.5.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
			'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'back'      => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
			'phone'     => '<path d="M6.5 3.5h3l1.5 4.5-2 1.5a11 11 0 0 0 5.5 5.5l1.5-2 4.5 1.5v3a2 2 0 0 1-2 2A15 15 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
			'note'      => '<path d="M5 3.5h10l4 4v13H5z"/><path d="M14.5 3.5v4.5H19M8.5 12h7M8.5 16h5"/>',
			'history'   => '<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6M3.5 4v4h4"/><path d="M12 8v4.5l3 1.5"/>',
			'lock'      => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
			'globe'     => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.3 3.5 5.2 3.5 8.5s-1.2 6.2-3.5 8.5c-2.3-2.3-3.5-5.2-3.5-8.5s1.2-6.2 3.5-8.5z"/>',
			'door'      => '<path d="M6 20.5V4.5a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v16M3.5 20.5h17M14.5 12.5h.01"/>',
			'eye'       => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
			'trash'     => '<path d="M4 6.5h16M9.5 6.5V4h5v2.5M6 6.5l1 14h10l1-14"/>',
			'coffee'    => '<path d="M4 9h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5zM17 10.5h1.5a2.5 2.5 0 0 1 0 5H17M8 3.5v2.5M12 3.5v2.5"/>',
			'child'     => '<circle cx="12" cy="5.5" r="2.5"/><path d="M8 10h8M12 10v5M9 21l3-6 3 6"/>',
		);
	}

	/**
	 * An inline SVG icon (decorative: hidden from screen readers).
	 */
	public static function icon( $name, $class = '' ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return '<svg class="flexo-icon' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Icon for a menu entry.
	 */
	private static function menu_icon( $slug ) {
		$map = array(
			Flexo_Booking_Admin::MENU_SLUG                         => 'sun',
			'flexo-booking-calendar'                               => 'calendar',
			Flexo_Booking_Admin::MENU_SLUG . '-list'               => 'list',
			Flexo_Booking_Admin::MENU_SLUG . '-new'                => 'plus',
			'edit.php?post_type=' . Flexo_Booking_Rooms::POST_TYPE => 'bed',
			'flexo-booking-promo-codes'                            => 'tag',
			Flexo_Booking_Admin::MENU_SLUG . '-emails'             => 'mail',
			'flexo-booking-appearance'                             => 'palette',
			Flexo_Booking_Admin::MENU_SLUG . '-settings'           => 'sliders',
			Flexo_Booking_Admin::MENU_SLUG . '-help'               => 'help',
			Flexo_Booking_Features::AGENCY_SLUG                    => 'key',
		);
		return isset( $map[ $slug ] ) ? $map[ $slug ] : 'arrow';
	}

	/* ---------------------------------------------------------------------
	 * App bar
	 * ------------------------------------------------------------------- */

	/**
	 * The plugin's visible menu entries the user may open, with the current one marked.
	 *
	 * @return array[] { label, url, icon, current }
	 */
	public static function nav_items() {
		global $submenu, $submenu_file, $plugin_page;
		$items = array();
		if ( empty( $submenu[ Flexo_Booking_Admin::MENU_SLUG ] ) ) {
			return $items;
		}
		$current = $submenu_file ? $submenu_file : $plugin_page;
		foreach ( $submenu[ Flexo_Booking_Admin::MENU_SLUG ] as $item ) {
			$classes = isset( $item[4] ) ? (string) $item[4] : '';
			if ( false !== strpos( $classes, 'flexo-menu-hidden' ) || ! current_user_can( $item[1] ) ) {
				continue;
			}
			$slug    = $item[2];
			$items[] = array(
				'label'   => trim( wp_strip_all_tags( $item[0] ) ),
				'url'     => false !== strpos( $slug, '.php' ) ? admin_url( $slug ) : admin_url( 'admin.php?page=' . $slug ),
				'icon'    => self::menu_icon( $slug ),
				'current' => $slug === $current,
			);
		}
		return $items;
	}

	public static function appbar() {
		if ( ! self::is_screen() ) {
			return;
		}
		$can_book = current_user_can( Flexo_Booking_Admin::capability() );
		$count    = $can_book ? Flexo_Booking_Today_Admin::attention_count() : 0;
		$page     = $can_book ? Flexo_Booking_Guest::booking_page_url() : '';
		$site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: keeps the search text on the list.
		$search   = isset( $_GET['s'], $_GET['page'] ) && Flexo_Booking_Admin::MENU_SLUG . '-list' === $_GET['page'] ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$items    = self::nav_items();
		?>
		<div class="flexo-appbar">
			<div class="flexo-appbar__row">
				<a class="flexo-appbar__brand" href="<?php echo esc_url( $can_book ? Flexo_Booking_Admin::page_url() : admin_url() ); ?>">
					<span class="flexo-appbar__logo"><?php echo self::icon( 'logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<span class="flexo-appbar__titles">
						<span class="flexo-appbar__product"><?php esc_html_e( 'Flexo Booking', 'flexo-booking' ); ?></span>
						<?php if ( '' !== $site ) : ?>
							<span class="flexo-appbar__site"><?php echo esc_html( $site ); ?></span>
						<?php endif; ?>
					</span>
				</a>
				<?php if ( $can_book ) : ?>
					<form class="flexo-appbar__search" role="search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( Flexo_Booking_Admin::MENU_SLUG . '-list' ); ?>">
						<label class="screen-reader-text" for="flexo-appbar-search"><?php esc_html_e( 'Search bookings', 'flexo-booking' ); ?></label>
						<?php echo self::icon( 'search', 'flexo-appbar__search-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
						<input id="flexo-appbar-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search bookings…', 'flexo-booking' ); ?>" autocomplete="off">
					</form>
				<?php endif; ?>
				<div class="flexo-appbar__actions">
					<?php if ( $can_book ) : ?>
						<?php
						/* translators: %d: number of things that need attention */
						$attention = $count ? sprintf( _n( '%d thing needs your attention', '%d things need your attention', $count, 'flexo-booking' ), $count ) : __( 'Nothing needs your attention', 'flexo-booking' );
						?>
						<a class="flexo-appbar__icon<?php echo $count ? ' has-count' : ''; ?>" href="<?php echo esc_url( Flexo_Booking_Admin::page_url() . '#flexo-attention' ); ?>" title="<?php echo esc_attr( $attention ); ?>">
							<?php echo self::icon( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span class="screen-reader-text"><?php echo esc_html( $attention ); ?></span>
							<?php if ( $count ) : ?>
								<span class="flexo-appbar__badge" aria-hidden="true"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							<?php endif; ?>
						</a>
						<?php if ( $page ) : ?>
							<a class="flexo-appbar__icon" href="<?php echo esc_url( $page ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open your booking form in a new tab', 'flexo-booking' ); ?>">
								<?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
								<span class="screen-reader-text"><?php esc_html_e( 'Open your booking form in a new tab', 'flexo-booking' ); ?></span>
							</a>
						<?php endif; ?>
						<a class="flexo-appbar__icon" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-help' ) ); ?>" title="<?php esc_attr_e( 'Help', 'flexo-booking' ); ?>">
							<?php echo self::icon( 'help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span class="screen-reader-text"><?php esc_html_e( 'Help', 'flexo-booking' ); ?></span>
						</a>
						<a class="flexo-btn flexo-btn--primary flexo-appbar__new" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-new' ) ); ?>">
							<?php echo self::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span><?php esc_html_e( 'New booking', 'flexo-booking' ); ?></span>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( count( $items ) > 1 ) : ?>
				<nav class="flexo-appbar__nav" aria-label="<?php esc_attr_e( 'Bookings menu', 'flexo-booking' ); ?>">
					<?php foreach ( $items as $item ) : ?>
						<a href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $item['current'] ? ' class="is-current" aria-current="page"' : ''; ?>>
							<?php echo self::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?>
							<span><?php echo esc_html( $item['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page header
	 * ------------------------------------------------------------------- */

	/**
	 * Title, short introduction, actions and (for Rooms & prices and
	 * Settings) the section tabs, followed by the marker WordPress uses to
	 * place notices.
	 *
	 * @param array $args {
	 *     @type string $title       Page title (plain text).
	 *     @type string $icon        Icon name.
	 *     @type string $after_title HTML after the title (e.g. a status badge).
	 *     @type string $intro       HTML introduction (already escaped).
	 *     @type string $help        Help guide topic.
	 *     @type array  $back        array( label, url ).
	 *     @type array  $actions     List of { label, url, primary, icon, attrs, class }.
	 *     @type string $tools       HTML shown with the actions (already escaped).
	 *     @type array  $tabs        array( section, current ) for Flexo_Booking_Admin::section_nav().
	 *     @type bool   $header_end  Print the marker for notices (false where WordPress prints its own).
	 * }
	 */
	public static function page_head( array $args ) {
		$args = array_merge(
			array(
				'title'       => '',
				'icon'        => '',
				'after_title' => '',
				'intro'       => '',
				'help'        => '',
				'back'        => array(),
				'actions'     => array(),
				'tools'       => '',
				'tabs'        => array(),
				'header_end'  => true,
			),
			$args
		);
		?>
		<div class="flexo-page-head">
			<?php if ( $args['back'] ) : ?>
				<a class="flexo-page-head__back" href="<?php echo esc_url( $args['back'][1] ); ?>"><?php echo self::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><?php echo esc_html( $args['back'][0] ); ?></a>
			<?php endif; ?>
			<div class="flexo-page-head__row">
				<div class="flexo-page-head__main">
					<?php if ( $args['icon'] ) : ?>
						<span class="flexo-page-head__icon"><?php echo self::icon( $args['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
					<?php endif; ?>
					<div class="flexo-page-head__text">
						<h1 class="flexo-page-head__title"><?php echo esc_html( $args['title'] ); ?><?php echo $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?><?php echo $args['help'] ? Flexo_Booking_Help::link( $args['help'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in link(). ?></h1>
						<?php if ( '' !== $args['intro'] ) : ?>
							<p class="flexo-page-head__intro"><?php echo $args['intro']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $args['actions'] || '' !== $args['tools'] ) : ?>
					<div class="flexo-page-head__actions">
						<?php echo $args['tools']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?>
						<?php foreach ( $args['actions'] as $action ) : ?>
							<?php
							$attrs = '';
							foreach ( isset( $action['attrs'] ) ? $action['attrs'] : array() as $key => $value ) {
								$attrs .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
							}
							$class = 'button page-title-action' . ( ! empty( $action['primary'] ) ? ' button-primary' : '' ) . ( ! empty( $action['class'] ) ? ' ' . $action['class'] : '' );
							?>
							<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $action['url'] ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>><?php echo ! empty( $action['icon'] ) ? self::icon( $action['icon'] ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?><span><?php echo esc_html( $action['label'] ); ?></span></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
			if ( $args['tabs'] ) {
				Flexo_Booking_Admin::section_nav( $args['tabs'][0], $args['tabs'][1] );
			}
			?>
		</div>
		<?php if ( $args['header_end'] ) : ?>
			<hr class="wp-header-end">
		<?php endif; ?>
		<?php
	}

	/**
	 * An on/off switch (a real checkbox, styled).
	 *
	 * @param string $attrs Extra attributes for the input (already escaped).
	 */
	public static function switch_html( $label, $attrs, $checked = false ) {
		return '<label class="flexo-switch"><input type="checkbox" role="switch" ' . $attrs . ( $checked ? ' checked' : '' ) . '><span class="flexo-switch__track" aria-hidden="true"></span><span class="flexo-switch__label">' . esc_html( $label ) . '</span></label>';
	}

	/* ---------------------------------------------------------------------
	 * Footer
	 * ------------------------------------------------------------------- */

	public static function footer_text( $text ) {
		if ( ! self::is_screen() ) {
			return $text;
		}
		$support = Flexo_Booking_Help::support();
		$out     = '<span class="flexo-footer">' . esc_html( sprintf( /* translators: %s: version */ __( 'Flexo Booking %s', 'flexo-booking' ), FLEXO_BOOKING_VERSION ) );
		$out    .= ' · <a href="' . esc_url( admin_url( 'admin.php?page=' . Flexo_Booking_Admin::MENU_SLUG . '-help' ) ) . '">' . esc_html__( 'Help', 'flexo-booking' ) . '</a>';
		if ( '' !== $support['email'] ) {
			$out .= ' · ' . esc_html( '' !== $support['name'] ? $support['name'] : __( 'Support', 'flexo-booking' ) ) . ': <a href="mailto:' . esc_attr( $support['email'] ) . '">' . esc_html( $support['email'] ) . '</a>';
		}
		return $out . '</span>';
	}

	public static function footer_version( $text ) {
		return self::is_screen() ? '' : $text;
	}
}
