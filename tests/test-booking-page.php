<?php
/**
 * Built-in booking page and the look of the "Check availability" panel
 * (1.8.3). Needs the site served over HTTP (BASE, else the site address).
 *
 *   BASE=http://localhost:8092 wp eval-file tests/test-booking-page.php
 */
require __DIR__ . '/lib.php';

$base     = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();
$bp_saved = get_option( Flexo_Booking_Settings::OPTION );
$drafted  = array();
$bp_features = null;
register_shutdown_function(
	static function () use ( $bp_saved, &$drafted, &$bp_features ) {
		update_option( Flexo_Booking_Settings::OPTION, $bp_saved );
		foreach ( $drafted as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		}
		Flexo_Booking_Guest::forget_booking_page();
		if ( null !== $bp_features ) {
			Flexo_Booking_Features::set_enabled( $bp_features );
		}
	}
);
$get = static function ( $path ) use ( $base ) {
	$r = wp_remote_get( $base . $path, array( 'timeout' => 30, 'redirection' => 0 ) );
	return is_wp_error( $r ) ? array( 0, '', '' ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), (string) wp_remote_retrieve_header( $r, 'location' ) );
};
$settings = static function ( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
};

t_room( 'bp-double', 'BP Double', array( 'price' => 100, 'capacity' => 2, 'units' => 1 ) );

// No page with the booking form.
$form_pages = array_merge(
	get_posts( array( 'post_type' => 'page', 's' => '[flexo_booking', 'posts_per_page' => -1, 'post_status' => 'publish' ) ),
	get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_elementor_data', 'meta_value' => 'flexo-booking-form', 'meta_compare' => 'LIKE' ) )
);
foreach ( $form_pages as $p ) {
	wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
	$drafted[] = $p->ID;
}
$settings( array( 'booking_page' => '' ) );
Flexo_Booking_Guest::forget_booking_page();

t_section( 'No booking page yet: the built-in one' );
t_eq( '', Flexo_Booking_Guest::booking_page_url(), 'no real booking page' );
t_eq( home_url( '/booking/' ), Flexo_Booking_Guest::guest_page_url(), 'guests are sent to /booking/' );
t_ok( 0 === strpos( Flexo_Booking_Room_Content::booking_url( 'bp-double' ), home_url( '/booking/?room=bp-double' ) ), 'Book now links lead there' );
list( $code, $html ) = $get( '/booking/?room=bp-double&check_in=' . t_day( 3 ) . '&check_out=' . t_day( 5 ) . '&adults=2' );
t_eq( 200, $code, '/booking/ answers 200 instead of "Page not found"' );
t_ok( false !== strpos( $html, 'flexo-booking--full' ) && false !== strpos( $html, 'flexo-builtin-booking' ), 'with the full booking form' );
t_ok( false !== strpos( $html, '<title>' . esc_html( __( 'Book your stay', 'flexo-booking' ) ) ) && false === strpos( $html, 'error404' ), 'its own title, no 404 body class' );
t_ok( false !== strpos( $html, 'noindex' ), 'kept out of search results' );
t_ok( 1 === preg_match( '/data-room="bp-double"/', $html ) || false !== strpos( $html, 'bp-double' ), 'room from the link is used' );
list( $code ) = $get( '/bookingx/' );
t_eq( 404, $code, 'other missing addresses still 404' );
$health = wp_list_pluck( Flexo_Booking_Health::checks(), 'status', 'id' );
t_eq( 'warning', $health['booking_page'], 'Health: a warning (built-in page in use), not an error' );
t_reset_inventory();
$bp_features = Flexo_Booking_Features::stored_enabled();
Flexo_Booking_Features::set_enabled( array_merge( $bp_features, array( 'guest_booking_page' ) ) );
$made = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'bp-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 5 ), 'privacy_consent' => 1 ) ) );
$links = is_wp_error( $made ) ? array() : Flexo_Booking_Guest::links( Flexo_Booking_Bookings::get( (int) $made['id'] ) );
t_ok( 0 === strpos( (string) ( $links['manage'] ?? '' ), home_url( '/booking/' ) ), 'email link "Manage your booking" uses the built-in page' );
t_reset_inventory();

t_section( 'A real booking page elsewhere' );
$real          = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Reservations', 'post_name' => 'bp-reservations', 'post_status' => 'publish', 'post_content' => '[flexo_booking]' ) );
Flexo_Booking_Guest::forget_booking_page();
t_eq( get_permalink( $real ), Flexo_Booking_Guest::booking_page_url(), 'found automatically' );
list( $code, , $location ) = $get( '/booking/?room=bp-double&adults=2' );
t_ok( 302 === $code && get_permalink( $real ) . '?room=bp-double&adults=2' === $location, 'old /booking/ links go to it, keeping room and dates' );
wp_delete_post( $real, true );
Flexo_Booking_Guest::forget_booking_page();

t_section( 'Look of the panel (Appearance)' );
$settings(
	array(
		'appearance_mode'              => 'custom',
		'appearance_panel_bg'          => '#F6EFE6',
		'appearance_overlay'           => 'dark',
		'appearance_heading_font'      => 'el:primary',
		'appearance_body_font'         => 'custom',
		'appearance_body_font_name'    => 'DM Sans;}body{color:red',
	)
);
$s = Flexo_Booking_Settings::all();
t_eq( 'DM Sansbodycolorred', $s['appearance_body_font_name'], 'typed font name cleaned (no CSS can be injected)' );
$css = Flexo_Booking_Appearance::css( null, true );
t_ok( false !== strpos( $css, '--fb-panel-bg:#f6efe6' ), 'panel background' );
t_ok( false !== strpos( $css, '--fb-overlay:rgba(15,20,25,.8)' ), 'darker page behind the panel' );
t_ok( false !== strpos( $css, '--fb-heading-font:var(--e-global-typography-primary-font-family,inherit)' ), 'Elementor global font for headings' );
t_ok( false !== strpos( $css, '--fb-font:"DM Sansbodycolorred",system-ui,sans-serif' ), 'typed font for text' );
t_eq( '', Flexo_Booking_Appearance::sanitize_font_choice( 'el:x;}' ), 'unknown font choices are refused' );
$settings( array( 'appearance_body_font' => 'custom', 'appearance_body_font_name' => '' ) );
t_ok( false === strpos( Flexo_Booking_Appearance::css( null, true ), '--fb-font:' ), '"Another font" without a name = website font' );

t_done();
