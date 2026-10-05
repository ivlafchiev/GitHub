<?php
/**
 * 1.9.0 system pages: routing, precedence, collisions, slugs and old
 * addresses, own pages, migration 9, caching, SEO, header styles and
 * settings that never reset each other. Needs the site served over HTTP
 * (BASE, else the site address).
 *
 *   BASE=http://localhost:8092 wp eval-file tests/test-system-pages.php
 *
 * Brief items: 1, 2, 4, 5, 7–16, 45, 47–49, 73–75 (Contact, 3 and 6, come
 * with the contact form in Session B).
 */
require __DIR__ . '/lib.php';

$base      = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();
$sp_saved  = array(
	'pages'    => get_option( Flexo_Booking_System_Pages::OPTION ),
	'forms'    => get_option( Flexo_Booking_Forms::OPTION ),
	'settings' => get_option( Flexo_Booking_Settings::OPTION ),
	'features' => Flexo_Booking_Features::stored_enabled(),
	'version'  => get_option( Flexo_Booking_Migrations::OPTION ),
);
$drafted   = array();
$created   = array();
register_shutdown_function(
	static function () use ( $sp_saved, &$drafted, &$created ) {
		foreach ( array( Flexo_Booking_System_Pages::OPTION => 'pages', Flexo_Booking_Forms::OPTION => 'forms', Flexo_Booking_Settings::OPTION => 'settings' ) as $option => $key ) {
			false === $sp_saved[ $key ] ? delete_option( $option ) : update_option( $option, $sp_saved[ $key ] );
		}
		update_option( Flexo_Booking_Migrations::OPTION, $sp_saved['version'] );
		Flexo_Booking_Features::set_enabled( $sp_saved['features'] );
		foreach ( $drafted as $id ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		}
		foreach ( $created as $id ) {
			wp_delete_post( $id, true );
		}
		Flexo_Booking_Guest::forget_booking_page();
	}
);
// Raw option writes (no sanitize callback is registered outside the admin).
$pages = static function ( array $changes ) {
	Flexo_Booking_System_Pages::update( $changes );
};
$fresh_pages = static function ( array $changes = array() ) use ( $pages ) {
	delete_option( Flexo_Booking_System_Pages::OPTION );
	$pages( $changes );
};
$get = static function ( $path, array $cookies = array() ) use ( $base ) {
	$url = 0 === strpos( $path, 'http' ) ? $path : $base . $path;
	$r   = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'cookies' => $cookies ) );
	if ( is_wp_error( $r ) ) {
		return array( 'code' => 0, 'body' => '', 'location' => '', 'headers' => array(), 'cookies' => array() );
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $r ),
		'body'     => (string) wp_remote_retrieve_body( $r ),
		'location' => (string) wp_remote_retrieve_header( $r, 'location' ),
		'headers'  => wp_remote_retrieve_headers( $r ),
		'cookies'  => wp_remote_retrieve_cookies( $r ),
	);
};
$header = static function ( array $r, $name ) {
	$value = isset( $r['headers'][ $name ] ) ? $r['headers'][ $name ] : '';
	return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
};

// Pages at the default addresses and pages with the form are set aside.
foreach ( array( 'booking', 'thank-you', 'contact', 'sp-reserve', 'sp-clash' ) as $path ) {
	$page = get_page_by_path( $path );
	if ( $page && 'publish' === $page->post_status ) {
		wp_update_post( array( 'ID' => $page->ID, 'post_status' => 'draft' ) );
		$drafted[] = $page->ID;
	}
}
foreach ( array_merge(
	get_posts( array( 'post_type' => 'page', 's' => '[flexo_booking', 'posts_per_page' => -1, 'post_status' => 'publish' ) ),
	get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_elementor_data', 'meta_value' => 'flexo-booking-form', 'meta_compare' => 'LIKE' ) )
) as $p ) {
	wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
	$drafted[] = $p->ID;
}
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'booking_page' => '', 'thank_you_url' => '' ) ) ) );
Flexo_Booking_Guest::forget_booking_page();
t_reset_inventory();
t_room( 'sp-double', 'SP Double', array( 'price' => 100, 'capacity' => 2, 'units' => 2 ) );
$rules_before = get_option( 'rewrite_rules' );

/* ------------------------------------------------------------------------- */
t_section( 'Built-in pages (fresh install defaults)' );
$fresh_pages();
$all = Flexo_Booking_System_Pages::all();
t_eq( 'separate', $all['after_booking'], 'bookings end on a separate Thank You page' );
t_ok( Flexo_Booking_System_Pages::route_active( 'booking' ) && Flexo_Booking_System_Pages::route_active( 'thank_you' ), 'Booking and Thank You are active' );
t_eq( home_url( '/booking/' ), Flexo_Booking_Guest::guest_page_url(), 'guests book at /booking/' );

$r = $get( '/booking/?room=sp-double' );
t_eq( 200, $r['code'], '1. /booking/ answers 200' );
t_ok( false !== strpos( $r['body'], 'flexo-sp-booking' ) && false !== strpos( $r['body'], 'flexo-booking--full' ), '1. with the booking page and the shared booking form' );
t_ok( false !== strpos( $r['body'], 'flexo-builtin-booking' ), '1.8.3 class kept for themes' );
t_ok( false !== strpos( $r['body'], '<h1 class="flexo-sp-title">' . esc_html__( 'Book your stay', 'flexo-booking' ) ), 'page title as the main heading' );
t_ok( false !== strpos( $r['body'], esc_html__( 'Choose your dates and find the perfect room for your stay.', 'flexo-booking' ) ), 'introduction' );
t_ok( false !== strpos( $r['body'], 'flexo-sp-help' ) && false !== strpos( $r['body'], 'flexo-sp-reassure' ), 'help and reassurance' );
t_eq( 'same-origin', $header( $r, 'referrer-policy' ), 'Referrer-Policy: same-origin' );
t_ok( false === strpos( $header( $r, 'cache-control' ), 'no-store' ), 'decision 1: Booking may be cached (no private data)' );
t_ok( false === strpos( $r['body'], 'error404' ), 'no "not found" body class' );

$r = $get( '/thank-you/' );
t_eq( 200, $r['code'], '2. /thank-you/ answers 200' );
t_ok( false !== strpos( $r['body'], 'flexo-confirmation' ) && false !== strpos( $r['body'], esc_html__( 'Thank you', 'flexo-booking' ) ), '2. general thank-you text without a booking' );

/* ------------------------------------------------------------------------- */
t_section( 'Caching, referrer and search engines (45, 48)' );
t_ok( false !== strpos( $header( $r, 'cache-control' ), 'no-store' ) && false !== strpos( $header( $r, 'cache-control' ), 'private' ), '45. Thank You: Cache-Control no-store, private' );
t_eq( 'no-store', $header( $r, 'cdn-cache-control' ), '45. CDN-Cache-Control: no-store' );
t_eq( 'no-referrer', $header( $r, 'referrer-policy' ), '33. Thank You: Referrer-Policy no-referrer' );
t_ok( false !== strpos( $header( $r, 'x-robots-tag' ), 'noindex' ) && 1 === preg_match( "/<meta name='robots' content='[^']*noindex, nofollow/", $r['body'] ), '48. Thank You: noindex, nofollow (header and meta)' );
t_ok( false === strpos( $r['body'], 'rel="canonical"' ), '48. Thank You: no canonical' );
$pages( array( 'booking' => array( 'cacheable' => 0 ) ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $header( $r, 'cache-control' ), 'no-store' ), 'Booking: caching switched off by the hotel' );
$pages( array( 'booking' => array( 'cacheable' => 1, 'meta_description' => 'Book the SP hotel directly.' ) ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], '<title>' . esc_html__( 'Book your stay', 'flexo-booking' ) . ' – ' . esc_html( get_bloginfo( 'name' ) ) . '</title>' ) || false !== strpos( $r['body'], '<title>' . esc_html__( 'Book your stay', 'flexo-booking' ) . ' &#8211; ' ), '48. title "Book your stay – Site name"' );
t_ok( false !== strpos( $r['body'], '<link rel="canonical" href="' . esc_url( home_url( '/booking/' ) ) . '">' ), '48. canonical address' );
t_ok( false !== strpos( $r['body'], '<meta name="description" content="Book the SP hotel directly.">' ), '48. editable meta description' );
t_ok( 0 === preg_match( "/<meta name='robots' content='[^']*noindex/", $r['body'] ), '48. Booking indexable by default' );
$pages( array( 'booking' => array( 'indexable' => 0 ) ) );
$r = $get( '/booking/' );
t_ok( 1 === preg_match( "/<meta name='robots' content='[^']*noindex, follow/", $r['body'] ), 'indexing can be switched off' );
$pages( array( 'booking' => array( 'indexable' => 1 ) ) );
$r = $get( '/wp-sitemap-flexobooking-pages-1.xml' );
t_ok( 200 === $r['code'] && false !== strpos( $r['body'], home_url( '/booking/' ) ) && false === strpos( $r['body'], 'thank-you' ), '48. WordPress sitemap lists Booking, never Thank You' );
// Yoast SEO / Rank Math filters (the plugins are not installed: their filters are called directly).
Flexo_Booking_Page_SEO::hook( 'booking' );
t_ok( 0 === strpos( apply_filters( 'wpseo_title', 'Page not found' ), __( 'Book your stay', 'flexo-booking' ) ), 'Yoast title filter' );
t_eq( home_url( '/booking/' ), apply_filters( 'rank_math/frontend/canonical', '' ), 'Rank Math canonical filter' );
t_ok( false !== strpos( Flexo_Booking_System_Pages::yoast_sitemap( '' ), home_url( '/booking/' ) ), 'Yoast page sitemap gets Booking' );
Flexo_Booking_Page_SEO::hook( 'thank_you' );
t_eq( 'noindex, nofollow', apply_filters( 'wpseo_robots', 'index, follow' ), 'Yoast robots: Thank You noindex, nofollow' );
t_eq( 'noindex', apply_filters( 'rank_math/frontend/robots', array() )['index'], 'Rank Math robots: Thank You noindex' );
// Plugin APIs and constants.
$litespeed = 0;
add_action( 'litespeed_control_set_nocache', static function () use ( &$litespeed ) {
	++$litespeed;
} );
Flexo_Booking_Page_Cache::protect( 'test' );
t_ok( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && defined( 'DONOTCACHEOBJECT' ), '45. DONOTCACHEPAGE and DONOTCACHEOBJECT' );
t_eq( 1, $litespeed, '45. LiteSpeed Cache told not to cache' );
$reject = apply_filters( 'rocket_cache_reject_uri', array() );
t_ok( in_array( '(.*)' . preg_quote( '/thank-you', '#' ) . '/?(.*)', $reject, true ), '45. WP Rocket: Thank You path excluded' );
t_ok( in_array( '*fb_key=*', Flexo_Booking_Page_Cache::manual_rules(), true ), 'manual rules for other caches' );

/* ------------------------------------------------------------------------- */
t_section( 'Guest links never cached or passed on' );
$r = $get( '/?fb_manage=FB-X&fb_key=abc' );
t_ok( false !== strpos( $header( $r, 'cache-control' ), 'no-store' ) && 'no-referrer' === $header( $r, 'referrer-policy' ), 'any page with a guest key: no-store, no-referrer' );
t_ok( false !== strpos( $r['body'], '<meta name="referrer" content="no-referrer">' ), 'and the referrer meta' );

/* ------------------------------------------------------------------------- */
t_section( 'Submissions on cached pages (47)' );
$r = $get( '/booking/' );
t_ok( false === strpos( $r['body'], 'wp_rest' ) && 0 === preg_match( '/"nonce"\s*:/', $r['body'] ), '47. the booking page has no nonce that could expire in a cache' );
$post = wp_remote_post(
	$base . '/wp-json/flexo-booking/v1/bookings',
	array(
		'timeout' => 30,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( array_merge( t_guest(), array( 'room' => 'sp-double', 'check_in' => t_day( 40 ), 'check_out' => t_day( 42 ), 'privacy_consent' => true, 'terms' => true ) ) ),
	)
);
t_eq( 201, (int) wp_remote_retrieve_response_code( $post ), '47. booking sent without any nonce (as from a cached page)' );
if ( 201 !== (int) wp_remote_retrieve_response_code( $post ) ) {
	echo '    ' . wp_remote_retrieve_body( $post ) . "\n";
}
t_reset_inventory();

/* ------------------------------------------------------------------------- */
t_section( 'Own pages (4, 5, 7)' );
$own_booking = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'SP Reservations', 'post_name' => 'sp-own-booking', 'post_status' => 'publish', 'post_content' => '[flexo_booking]' ) );
$own_thanks  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'SP Thanks', 'post_name' => 'sp-own-thanks', 'post_status' => 'publish', 'post_content' => 'Thanks for booking with us.' ) );
$created[]   = $own_booking;
$created[]   = $own_thanks;
$pages( array( 'booking' => array( 'source' => 'page', 'page_id' => $own_booking ) ) );
t_eq( get_permalink( $own_booking ), Flexo_Booking_Guest::guest_page_url(), '4. own booking page used for links' );
t_ok( 0 === strpos( Flexo_Booking_Room_Content::booking_url( 'sp-double' ), get_permalink( $own_booking ) ), '4. "Book now" goes there' );
$r = $get( '/booking/?room=sp-double&adults=2' );
t_ok( 302 === $r['code'] && get_permalink( $own_booking ) . '?room=sp-double&adults=2' === $r['location'], '4. /booking/ leads to it, keeping the search' );
wp_trash_post( $own_booking );
t_eq( home_url( '/booking/' ), Flexo_Booking_Guest::guest_page_url(), '7. own page deleted: built-in page instead' );
t_eq( 'missing', Flexo_Booking_System_Pages::own_page_problem( 'booking' ), '7. the problem is known' );
$r = $get( '/booking/' );
t_ok( 200 === $r['code'] && false !== strpos( $r['body'], 'flexo-sp-booking' ), '7. /booking/ still works' );
$health = wp_list_pluck( Flexo_Booking_Health::system_page_checks(), 'status', 'id' );
t_eq( 'warning', $health['booking_page'], '7. Health warns' );
wp_untrash_post( $own_booking );
wp_update_post( array( 'ID' => $own_booking, 'post_status' => 'publish' ) );
$pages( array( 'booking' => array( 'source' => 'builtin' ) ) );

$pages( array( 'thank_you' => array( 'source' => 'page', 'page_id' => $own_thanks ) ) );
t_eq( get_permalink( $own_thanks ), Flexo_Booking_System_Pages::url( 'thank_you' ), '5. own Thank You page' );
$made = Flexo_Booking_Bookings::create( array_merge( t_guest(), array( 'room' => 'sp-double', 'check_in' => t_day( 3 ), 'check_out' => t_day( 5 ), 'privacy_consent' => 1 ) ) );
$link = Flexo_Booking_Confirmation::redirect_url( Flexo_Booking_Bookings::get( $made['id'] ) );
t_ok( 0 === strpos( $link, get_permalink( $own_thanks ) ) && false !== strpos( $link, 'booking=' . $made['reference'] ) && false !== strpos( $link, 'fb_t=' ), '5. as before 1.9.0 (?booking=REF), plus the one-time link' );
$r = $get( $link );
t_ok( 303 === $r['code'] && get_permalink( $own_thanks ) . '?booking=' . $made['reference'] === $r['location'], '5. token exchanged, then the page without it' );
t_ok( false !== strpos( $header( $r, 'cache-control' ), 'no-store' ), '5. own Thank You page never cached' );
t_eq( '', Flexo_Booking_Confirmation::redirect_url( Flexo_Booking_Bookings::get( $made['id'] ), true ), '5. bank details stay in the form on an own page (as before)' );
wp_update_post( array( 'ID' => $own_thanks, 'post_status' => 'draft' ) );
t_eq( '', Flexo_Booking_Confirmation::redirect_url( Flexo_Booking_Bookings::get( $made['id'] ) ), '7. own Thank You page unpublished: confirmation in the form' );
wp_update_post( array( 'ID' => $own_thanks, 'post_status' => 'publish' ) );
$pages( array( 'thank_you' => array( 'source' => 'builtin' ) ) );
t_reset_inventory();

/* ------------------------------------------------------------------------- */
t_section( 'Collisions and precedence (8, 9)' );
$clash     = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'SP Clash Page', 'post_name' => 'sp-clash', 'post_status' => 'publish', 'post_content' => 'Our own clash page.' ) );
$created[] = $clash;
$pages( array( 'booking' => array( 'slug' => 'sp-clash' ) ) );
$hit = Flexo_Booking_System_Pages::collision( 'booking' );
t_ok( $hit && 'page' === $hit['type'] && $clash === $hit['id'], '8. collision with a page found' );
t_ok( ! Flexo_Booking_System_Pages::route_active( 'booking' ), '8. built-in page inactive there' );
$r = $get( '/sp-clash/' );
t_ok( 200 === $r['code'] && false !== strpos( $r['body'], 'Our own clash page.' ) && false === strpos( $r['body'], 'flexo-sp-booking' ), '9. the existing page always wins' );
$health = wp_list_pluck( Flexo_Booking_Health::system_page_checks(), 'text', 'id' );
t_ok( false !== strpos( $health['booking_page'], 'SP Clash Page' ), 'Health explains the clash' );
$r = $get( '/booking/' );
t_ok( 301 === $r['code'] && home_url( '/sp-clash/' ) === $r['location'], 'old address leads to the new one' );
t_eq( 'rooms', Flexo_Booking_System_Pages::collision( 'booking', Flexo_Booking_Room_Pages::base() )['type'], '8. collision with the room pages' );
t_eq( 'system', Flexo_Booking_System_Pages::collision( 'booking', 'thank-you' )['type'], '8. collision with another Flexo page' );
register_post_type( 'sp_event', array( 'public' => true, 'has_archive' => 'sp-events', 'rewrite' => array( 'slug' => 'sp-events' ) ) );
t_eq( 'post_type', Flexo_Booking_System_Pages::collision( 'booking', 'sp-events' )['type'], '8. collision with another content type' );
$pages( array( 'booking' => array( 'slug' => 'booking', 'old_slugs' => array() ) ) );

/* ------------------------------------------------------------------------- */
t_section( 'Addresses (10, 11, 12)' );
t_eq( 'reservations-ete', Flexo_Booking_System_Pages::sanitize_slug( ' Réservations Été! ' ), '10. slug cleaned' );
t_eq( '', Flexo_Booking_System_Pages::sanitize_slug( 'wp-admin' ), '10. WordPress addresses refused' );
t_eq( '', Flexo_Booking_System_Pages::sanitize_slug( '///' ), '10. empty refused' );
$pages( array( 'booking' => array( 'slug' => 'wp-json' ) ) );
t_eq( 'booking', Flexo_Booking_System_Pages::slug( 'booking' ), '10. a refused slug keeps the old one' );

$pages( array( 'booking' => array( 'slug' => 'sp-reserve' ) ) );
t_eq( array( 'booking' ), Flexo_Booking_System_Pages::get( 'booking' )['old_slugs'], '12. old address remembered' );
$r = $get( '/booking/?room=sp-double&adults=2' );
t_ok( 301 === $r['code'] && home_url( '/sp-reserve/' ) . '?room=sp-double&adults=2' === $r['location'], '12. old address → new (301, search kept)' );
$r = $get( '/en/booking/' );
t_ok( 301 === $r['code'] && home_url( '/en/sp-reserve/' ) === $r['location'], '12. with a language in front too' );
$r = $get( '/sp-reserve/' );
t_eq( 200, $r['code'], '12. new address works' );
$pages( array( 'booking' => array( 'slug' => 'booking' ) ) );
t_eq( array( 'sp-reserve' ), Flexo_Booking_System_Pages::get( 'booking' )['old_slugs'], '12. changing back: no loop (the current address is never an old one)' );
$r = $get( '/booking/' );
t_eq( 200, $r['code'], '12. /booking/ works again' );
$r = $get( '/sp-reserve/' );
t_ok( 301 === $r['code'] && home_url( '/booking/' ) === $r['location'], '12. and the other way round' );
$pages( array( 'thank_you' => array( 'slug' => 'sp-reserve' ) ) );
t_eq( array(), Flexo_Booking_System_Pages::get( 'booking' )['old_slugs'], '12. an address in use is no other page\'s old address' );
$pages( array( 'thank_you' => array( 'slug' => 'thank-you', 'old_slugs' => array() ), 'booking' => array( 'old_slugs' => array() ) ) );
t_ok( $rules_before === get_option( 'rewrite_rules' ), '11. no rewrite rules added or flushed (requests and address changes)' );
$rules = (array) get_option( 'rewrite_rules' );
t_ok( ! array_filter( array_keys( $rules ), static function ( $rule ) {
	return false !== strpos( $rule, 'thank-you' ) || 0 === strpos( $rule, 'booking' );
} ), '11. no rules for the system pages' );

/* ------------------------------------------------------------------------- */
t_section( 'Switched off and plain permalinks' );
$pages( array( 'thank_you' => array( 'enabled' => 0 ) ) );
$r = $get( '/thank-you/' );
t_eq( 404, $r['code'], 'Thank You switched off: no page' );
t_eq( '', Flexo_Booking_Confirmation::redirect_url( array( 'id' => 1, 'reference' => 'X', 'locale' => '', 'status' => 'confirmed' ) ), 'and bookings show the confirmation in the form' );
$pages( array( 'booking' => array( 'enabled' => 0 ) ) );
t_ok( Flexo_Booking_System_Pages::enabled( 'booking' ), '(decision 5) Booking can\'t be switched off' );
$pages( array( 'thank_you' => array( 'enabled' => 1 ) ) );
$r = $get( '/?flexo_page=booking' );
t_ok( 200 === $r['code'] && false !== strpos( $r['body'], 'flexo-sp-booking' ), 'plain permalinks: ?flexo_page=booking' );

/* ------------------------------------------------------------------------- */
t_section( 'Header styles (49)' );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], 'data-fb-clear-header' ) && false !== strpos( $r['body'], 'flexo-header-normal' ), '49. Normal: as before (moved below an overlay header when there is one)' );
$pages( array( 'header_style' => 'space', 'header_space_desktop' => 120, 'header_space_tablet' => 100, 'header_space_mobile' => 80 ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], '@media (min-width:1025px){.flexo-system-page--space,.flexo-room-page-wrap{padding-top:120px}}' ) && false !== strpos( $r['body'], '.flexo-system-page--space,.flexo-room-page-wrap{padding-top:80px}' ), '49. Space: per-device top space' );
t_ok( false !== strpos( $r['body'], 'flexo-header-space-fixed' ) && false === strpos( $r['body'], 'data-fb-clear-header' ), '49. fixed spaces: nothing measured in the browser' );
$pages( array( 'header_space_tablet' => '' ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], 'data-fb-clear-header' ), '49. Space with an empty device: measured automatically' );
$pages( array( 'header_style' => 'band', 'header_band_bg' => '#123456', 'header_band_height_desktop' => 400 ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], 'flexo-title-band' ) && false !== strpos( $r['body'], '<h1 class="flexo-title-band__title">' . esc_html__( 'Book your stay', 'flexo-booking' ) ), '49. Band: the page title in the band' );
t_ok( false === strpos( $r['body'], 'flexo-sp-title' ), '49. the title is shown once' );
t_ok( false !== strpos( $r['body'], '--fb-band-bg:#123456' ) && false !== strpos( $r['body'], '--fb-band-h:400px' ), '49. band colour and height' );
$r = $get( '/thank-you/' );
t_ok( false !== strpos( $r['body'], 'flexo-title-band' ) && 1 === substr_count( $r['body'], '<h1' ), '49. Thank You with the band: one main heading' );
$pages( array( 'header_style' => 'normal' ) );

/* ------------------------------------------------------------------------- */
t_section( 'Booking page content and layouts (C)' );
$pages( array( 'booking' => array( 'show_title' => 0, 'show_intro' => 0, 'show_help' => 0, 'show_reassurance' => 0, 'layout' => 'wide', 'show_steps' => 0, 'show_summary' => 0 ) ) );
$r = $get( '/booking/' );
t_ok( false === strpos( $r['body'], 'flexo-sp-title' ) && false === strpos( $r['body'], 'flexo-sp-intro' ) && false === strpos( $r['body'], 'flexo-sp-help' ) && false === strpos( $r['body'], 'flexo-sp-reassure' ), 'title, introduction, help and reassurance can be hidden' );
t_ok( false !== strpos( $r['body'], 'flexo-sp-booking--wide' ) && false !== strpos( $r['body'], 'flexo-sp-booking--no-steps' ) && false !== strpos( $r['body'], 'flexo-sp-booking--no-summary' ), 'layout Wide; steps and summary hidden' );
$pages( array( 'booking' => array( 'show_title' => 1, 'title' => 'Reserve at {hotel_name}', 'show_intro' => 1, 'intro' => '<strong>Best rates</strong><script>alert(1)</script>', 'show_help' => 1, 'layout' => 'classic', 'show_steps' => 1, 'show_summary' => 1, 'show_reassurance' => 1, 'reassurance' => "Free parking\nLate check-out" ) ) );
$r = $get( '/booking/' );
t_ok( false !== strpos( $r['body'], 'Reserve at {hotel_name}' ), 'own title (placeholders are for the Thank You page only)' );
t_ok( false !== strpos( $r['body'], '<strong>Best rates</strong>' ) && false === strpos( $r['body'], '<script>alert(1)' ), 'introduction: simple formatting kept, scripts removed' );
t_ok( false !== strpos( $r['body'], '<li>Free parking</li>' ) && false !== strpos( $r['body'], '<li>Late check-out</li>' ), 'own "why book here" lines' );
$pages( array( 'booking' => array( 'title' => __( 'Book your stay', 'flexo-booking' ) ) ) );
t_eq( '', Flexo_Booking_System_Pages::get( 'booking' )['title'], 'the default text is not stored (stays translated)' );
$pages( array( 'booking' => array( 'intro' => '', 'reassurance' => '' ) ) );

/* ------------------------------------------------------------------------- */
t_section( 'Settings never reset each other (73, 74)' );
Flexo_Booking_Pages_Admin::register();
$settings_before = get_option( Flexo_Booking_Settings::OPTION );
$forms_before    = Flexo_Booking_Forms::all();
$ty_before       = Flexo_Booking_System_Pages::get( 'thank_you' );
// What options.php does when the Pages tab is saved: posted options get the
// submitted values; options of the group that were not posted get null.
update_option( Flexo_Booking_System_Pages::OPTION, array( 'booking' => array( 'title' => 'Only the title' ) ) );
t_eq( 'Only the title', Flexo_Booking_System_Pages::get( 'booking' )['title'], '73. the field saved' );
t_eq( $ty_before, Flexo_Booking_System_Pages::get( 'thank_you' ), '73. other pages unchanged' );
update_option( Flexo_Booking_Forms::OPTION, null );
t_eq( $forms_before, Flexo_Booking_Forms::all(), '73. form fields unchanged by a Pages save' );
register_setting( 'flexo_booking', Flexo_Booking_Settings::OPTION, array( 'sanitize_callback' => array( 'Flexo_Booking_Settings', 'sanitize' ) ) );
update_option( Flexo_Booking_Settings::OPTION, array( 'field_phone' => 'optional' ) );
$after = get_option( Flexo_Booking_Settings::OPTION );
t_eq( 'optional', $after['field_phone'], '74. phone setting saved from the Pages tab' );
$same = true;
foreach ( $settings_before as $key => $value ) {
	if ( 'field_phone' !== $key && ( ! array_key_exists( $key, $after ) || $after[ $key ] !== $value ) ) {
		$same = false;
		echo "    changed: {$key}\n";
	}
}
t_ok( $same, '73/74. all other settings (appearance, emails, hotel…) unchanged' );
update_option( Flexo_Booking_Forms::OPTION, array( 'booking_fields' => array( 'phone' => array( 'label' => 'Mobile' ) ) ) );
t_eq( 'Mobile', Flexo_Booking_Forms::all()['booking_fields']['phone']['label'], '74. field label saved' );
t_eq( $settings_before['appearance_mode'], Flexo_Booking_Settings::get( 'appearance_mode' ), '74. saving fields keeps Appearance' );
t_eq( 'Only the title', Flexo_Booking_System_Pages::get( 'booking' )['title'], '74. and the pages' );
remove_all_filters( 'sanitize_option_' . Flexo_Booking_Settings::OPTION );
remove_all_filters( 'sanitize_option_' . Flexo_Booking_System_Pages::OPTION );
remove_all_filters( 'sanitize_option_' . Flexo_Booking_Forms::OPTION );

/* ------------------------------------------------------------------------- */
t_section( 'Migration 9: fresh install and upgrades (13–16, 75)' );
$migrate = static function ( $fresh ) {
	$class = new ReflectionClass( 'Flexo_Booking_Migrations' );
	$prop  = $class->getProperty( 'fresh' );
	$prop->setAccessible( true );
	$prop->setValue( null, $fresh );
	$method = $class->getMethod( 'migrate_9_system_pages' );
	$method->setAccessible( true );
	delete_option( Flexo_Booking_System_Pages::OPTION );
	Flexo_Booking_Guest::forget_booking_page();
	return $method->invoke( null );
};
$set = static function ( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
};

$set( array( 'booking_page' => '', 'thank_you_url' => '' ) );
$migrate( true );
$all = Flexo_Booking_System_Pages::all();
t_ok( 'builtin' === $all['booking']['source'] && 'separate' === $all['after_booking'] && 1 === $all['thank_you']['enabled'] && 1 === $all['contact']['enabled'], '16. fresh install: built-in Booking, Thank You (separate) and Contact on' );

wp_update_post( array( 'ID' => $own_booking, 'post_status' => 'draft' ) );
$migrate( false );
$all = Flexo_Booking_System_Pages::all();
wp_update_post( array( 'ID' => $own_booking, 'post_status' => 'publish' ) );
t_ok( 'builtin' === $all['booking']['source'] && route_check_booking(), '13. upgrade relying on the built-in /booking/: built-in Booking page at the same address' );
t_ok( 'inline' === $all['after_booking'], '15. upgrade: the confirmation stays inside the booking form' );
t_ok( 0 === $all['thank_you']['enabled'] && 0 === $all['contact']['enabled'], '16. upgrade: Thank You and Contact start switched off' );

$set( array( 'booking_page' => wp_make_link_relative( get_permalink( $own_booking ) ) ) );
$migrate( false );
$all = Flexo_Booking_System_Pages::all();
t_ok( 'page' === $all['booking']['source'] && $own_booking === $all['booking']['page_id'], '14. upgrade with a booking page set: kept (own page)' );
t_eq( get_permalink( $own_booking ), Flexo_Booking_Guest::guest_page_url(), '14. guests still book there' );

$set( array( 'booking_page' => '' ) );
$migrate( false );
t_eq( $own_booking, Flexo_Booking_System_Pages::get( 'booking' )['page_id'], '14. upgrade with a page found automatically: kept' );

$set( array( 'thank_you_url' => wp_make_link_relative( get_permalink( $own_thanks ) ) ) );
$migrate( false );
$all = Flexo_Booking_System_Pages::all();
t_ok( 'separate' === $all['after_booking'] && 'page' === $all['thank_you']['source'] && $own_thanks === $all['thank_you']['page_id'], 'upgrade with a thank-you page: kept' );
$set( array( 'thank_you_url' => 'https://other.example.com/thanks/' ) );
$migrate( false );
$all = Flexo_Booking_System_Pages::all();
t_ok( 'page' === $all['thank_you']['source'] && 0 === $all['thank_you']['page_id'] && 0 === strpos( Flexo_Booking_System_Pages::url( 'thank_you' ), 'https://other.example.com/thanks/' ), 'an external thank-you address keeps working' );
update_option( Flexo_Booking_System_Pages::OPTION, array( 'after_booking' => 'separate', 'thank_you' => array( 'enabled' => 1 ) ) );
$rerun = new ReflectionMethod( 'Flexo_Booking_Migrations', 'migrate_9_system_pages' );
$rerun->setAccessible( true );
t_ok( true === $rerun->invoke( null ) && 'separate' === Flexo_Booking_System_Pages::all()['after_booking'], 'idempotent: a second run changes nothing' );
$set( array( 'thank_you_url' => '', 'booking_page' => '', 'field_phone' => 'hidden', 'field_notes' => 'hidden' ) );
t_eq( 'hidden', Flexo_Booking_Forms::booking_field_mode( 'phone' ), '75. old phone setting used by the field configuration' );
t_eq( 'hidden', Flexo_Booking_Forms::booking_field_mode( 'notes' ), '75. old special requests setting too' );

t_done();

/**
 * The built-in /booking/ answers.
 */
function route_check_booking() {
	return Flexo_Booking_System_Pages::route_active( 'booking' ) && home_url( '/booking/' ) === Flexo_Booking_Guest::guest_page_url();
}
