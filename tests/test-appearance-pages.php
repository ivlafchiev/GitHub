<?php
/**
 * 1.9.0 Appearance for the built-in pages: no change without new settings,
 * global settings reach the Booking and Thank You pages, a page's own look
 * wins and resets, everything is scoped, Elementor still wins, status
 * colours per state, Match / Custom, contrast warnings, Import / Export.
 * Needs the site served over HTTP (BASE, else the site address).
 *
 *   BASE=http://localhost:8092 wp eval-file tests/test-appearance-pages.php
 *
 * The preview matching the real page and the screen widths are checked in
 * the browser: tests/e2e/day8.js (Appearance part).
 */
require __DIR__ . '/lib.php';

$base     = getenv( 'BASE' ) ? rtrim( getenv( 'BASE' ), '/' ) : home_url();
$ap_saved = array(
	'pages'    => get_option( Flexo_Booking_System_Pages::OPTION ),
	'settings' => get_option( Flexo_Booking_Settings::OPTION ),
	'features' => Flexo_Booking_Features::stored_enabled(),
);
$ap_attachments = array();
register_shutdown_function(
	static function () use ( $ap_saved, &$ap_attachments ) {
		Flexo_Booking_Features::set_enabled( $ap_saved['features'] );
		false === $ap_saved['pages'] ? delete_option( Flexo_Booking_System_Pages::OPTION ) : update_option( Flexo_Booking_System_Pages::OPTION, $ap_saved['pages'] );
		update_option( Flexo_Booking_Settings::OPTION, $ap_saved['settings'] );
		foreach ( $ap_attachments as $id ) {
			wp_delete_attachment( $id, true );
		}
	}
);
function ap_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function ap_reset_settings() {
	$clean = Flexo_Booking_Settings::all();
	foreach ( Flexo_Booking_Settings::defaults() as $key => $value ) {
		if ( 0 === strpos( $key, 'appearance_' ) ) {
			$clean[ $key ] = $value;
		}
	}
	update_option( Flexo_Booking_Settings::OPTION, $clean );
}
function ap_get( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 30 ) );
	return is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
}
/** Inline CSS of a built-in page, as the website sends it. */
function ap_page_css( $html ) {
	preg_match_all( '#<style[^>]*>(.*?)</style>#s', $html, $m );
	return implode( "\n", $m[1] );
}
/** Every selector of a stylesheet. */
function ap_selectors( $css ) {
	preg_match_all( '/(?:^|\})\s*([^{}@]+)\{/', $css, $m );
	$out = array();
	foreach ( $m[1] as $group ) {
		foreach ( explode( ',', $group ) as $selector ) {
			$out[] = trim( $selector );
		}
	}
	return array_filter( $out );
}
/** Specificity (ids, classes, elements) as one comparable number. */
function ap_specificity( $selector ) {
	$classes  = preg_match_all( '/\.[a-z0-9_-]+|\[[^\]]+\]|:(?!:)[a-z-]+/i', $selector );
	$elements = preg_match_all( '/(?:^|[\s>+~])[a-z][a-z0-9]*/i', $selector );
	return $classes * 100 + $elements;
}
$page_css = static function ( $key ) {
	return Flexo_Booking_Appearance::page_css( $key );
};
$own = static function ( $key, array $look ) {
	Flexo_Booking_System_Pages::update( array( $key => array( 'appearance' => $look ) ) );
};

delete_option( Flexo_Booking_System_Pages::OPTION );
ap_reset_settings();
Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'custom_appearance' ) ) );
Flexo_Booking_System_Pages::update( array( 'booking' => array( 'source' => 'builtin', 'slug' => 'ap-book' ), 'thank_you' => array( 'source' => 'builtin', 'enabled' => 1, 'slug' => 'ap-thanks' ) ) );

/* ------------------------------------------------------------------------- */
t_section( 'No change without the new settings (upgrade)' );
t_eq( 'match', Flexo_Booking_Appearance::mode(), 'default mode: Match my website' );
t_eq( '', Flexo_Booking_Appearance::css(), 'Match: no CSS at all' );
t_eq( '', $page_css( 'booking' ) . $page_css( 'thank_you' ), 'pages use the global appearance: no page CSS' );
foreach ( array( 'booking', 'thank_you', 'contact' ) as $key ) {
	t_eq( 1, Flexo_Booking_System_Pages::get( $key )['appearance']['use_global'], $key . ': "Use global appearance" is on by default' );
}
ap_settings( array( 'appearance_mode' => 'custom', 'appearance_primary' => '#225577' ) );
$css = Flexo_Booking_Appearance::css();
foreach ( array( '--fb-page', '--fb-card-', '--fb-sp-', '--fb-status-', '--fb-heading-color', '--fb-step-', '--fb-summary-bg', '--fb-control-h', 'background-color', 'flexo-page{' ) as $new ) {
	t_ok( false === strpos( $css, $new ), 'Custom with only the old settings: nothing new in the CSS (' . $new . ')' );
}
t_ok( false !== strpos( $css, '.flexo-booking{--fb-primary:#225577' ), 'Custom: the old variables, as before' );
t_ok( false !== strpos( $css, '.flexo-system-page{--fb-primary:#225577' ), 'Custom: the same variables reach the whole built-in page' );
$old_keys = array( 'appearance_mode' => 'custom', 'appearance_primary' => '#225577', 'appearance_corners' => 'rounded' );
update_option( Flexo_Booking_Settings::OPTION, array_merge( get_option( Flexo_Booking_Settings::OPTION ), $old_keys ) );
$stored = get_option( Flexo_Booking_Settings::OPTION );
foreach ( Flexo_Booking_Appearance::PAGE_COLORS as $key ) {
	unset( $stored[ $key ] );
}
update_option( Flexo_Booking_Settings::OPTION, $stored );
t_eq( '', Flexo_Booking_Settings::all()['appearance_page_bg'], 'a 1.8 settings record reads the new colours as empty (website / built-in)' );

/* ------------------------------------------------------------------------- */
t_section( 'Global appearance reaches the pages' );
ap_settings(
	array(
		'appearance_mode'             => 'custom',
		'appearance_page_bg'          => '#f4ede2',
		'appearance_card_bg'          => '#fffdf8',
		'appearance_card_border'      => '#e0d6c6',
		'appearance_heading_color'    => '#3a2a1a',
		'appearance_max_width'        => 'narrow',
		'appearance_spacing'          => 'spacious',
		'appearance_card_shadow'      => 'soft',
		'appearance_control_size'     => 'large',
		'appearance_step_active'      => '#aa5500',
		'appearance_summary_bg'       => '#f0e8dc',
		'appearance_status_confirmed' => '#2e7d32',
		'appearance_status_awaiting'  => '#f4c430',
		'appearance_tab_active_bg'    => '#123456',
	)
);
$css = Flexo_Booking_Appearance::css();
t_ok( false !== strpos( $css, 'body.flexo-system-page-body .flexo-page{max-width:none;width:100%;margin-block:0;background-color:#f4ede2}' ), 'page background on the whole area between header and footer' );
t_ok( 1 === preg_match( '/\.flexo-system-page\{[^}]*--fb-card-bg:#fffdf8[^}]*--fb-card-border:#e0d6c6;--fb-card-border-w:1px/', $css ), 'cards: background and border' );
t_ok( 1 === preg_match( '/\.flexo-system-page\{[^}]*--fb-sp-max:960px/', $css ) && 1 === preg_match( '/--fb-sp-gap:36px;--fb-sp-gap-s:24px;--fb-sp-pad-top:56px;--fb-sp-pad-bottom:88px/', $css ), 'width and spacing on the page' );
t_ok( false !== strpos( $css, '--fb-card-shadow:0 18px 40px -28px rgba(15,20,25,.35)' ), 'card shadow' );
t_ok( 1 === preg_match( '/\.flexo-booking\{[^}]*--fb-control-h:52px/', $css ), 'large fields and buttons' );
t_ok( 1 === preg_match( '/\.flexo-booking\{[^}]*--fb-step-active:#aa5500/', $css ) && false !== strpos( $css, '--fb-summary-bg:#f0e8dc' ) && false !== strpos( $css, '--fb-tab-active-bg:#123456' ), 'steps, summary and tabs (form variables)' );
t_ok( false !== strpos( $css, '--fb-heading-color:#3a2a1a' ), 'heading colour' );
t_ok( false !== strpos( $css, '--fb-status-confirmed-bg:#2e7d32;--fb-status-confirmed-text:#ffffff' ), 'status confirmed: colour and readable text' );
t_ok( false !== strpos( $css, '--fb-status-awaiting-bg:#f4c430;--fb-status-awaiting-text:#1f2933' ), 'status awaiting: a light colour gets dark text' );
t_ok( false === strpos( $css, '--fb-status-request' ), 'only what differs from the defaults is written' );
$booking_css = (string) file_get_contents( FLEXO_BOOKING_DIR . 'assets/css/booking.css' );
foreach ( array( 'confirmed' => 'fb-ty-badge', 'awaiting' => 'fb-ty-badge--transfer', 'request' => 'fb-ty-badge--request' ) as $state => $class ) {
	t_ok( 1 === preg_match( '/\.' . preg_quote( $class, '/' ) . '[ ,{][^{]*\{[^}]*var\(--fb-status-' . $state . '-bg/', $booking_css ), 'status ' . $state . ': its own badge colour (' . $class . ')' );
}
t_ok( 1 === preg_match( '/\.fb-ty-badge--pending[^{]*\{[^}]*var\(--fb-status-awaiting-bg/', $booking_css ) || 1 === preg_match( '/\.fb-ty-badge--transfer,\s*\.[^{]*fb-ty-badge--pending/', $booking_css ), 'card payment pending uses the "awaiting" colour too' );
$html = ap_get( Flexo_Booking_System_Pages::builtin_url( 'booking' ) );
t_ok( false !== strpos( ap_page_css( $html ), 'body.flexo-system-page-body .flexo-page{' ) && false !== strpos( $html, 'flexo-system-page-body--booking' ), 'Booking page: the global look is on the page' );
$html = ap_get( Flexo_Booking_Confirmation::preview_url( 'transfer' ) );
$html = '' !== $html ? $html : ap_get( Flexo_Booking_System_Pages::builtin_url( 'thank_you' ) );
t_ok( false !== strpos( ap_page_css( $html ), '--fb-status-awaiting-bg:#f4c430' ), 'Thank You page: the global look is on the page' );

/* ------------------------------------------------------------------------- */
t_section( 'A page\'s own look wins, and resets' );
$own(
	'booking',
	array(
		'use_global' => '0',
		'page_bg'    => '#e9dcc7',
		'card_bg'    => '#fffaf3',
		'primary'    => '#8a4b2a',
		'heading'    => '#2b1d12',
		'text'       => '#3b3026',
		'corners'    => 'rounded',
		'max_width'  => 'custom',
		'max_width_px' => '1100',
		'spacing'    => 'compact',
		'success'    => '#00ff00',
	)
);
$look = Flexo_Booking_System_Pages::get( 'booking' )['appearance'];
t_eq( 0, $look['use_global'], 'own look saved' );
t_eq( '', $look['success'], 'Thank You colours are not stored for the Booking page' );
$css = $page_css( 'booking' );
t_ok( 0 === strpos( $css, '.flexo-system-page--booking,.flexo-system-page--booking .flexo-booking{' ), 'own look: scoped to the Booking page' );
foreach ( array( '--fb-card-bg:#fffaf3', '--fb-primary:#8a4b2a', '--fb-primary-contrast:#ffffff', '--fb-heading-color:#2b1d12', '--fb-text:#3b3026', '--fb-sp-max:1100px', '--fb-sp-gap:16px' ) as $decl ) {
	t_ok( false !== strpos( $css, $decl ), 'own look: ' . $decl );
}
t_ok( false !== strpos( $css, 'body.flexo-system-page-body--booking .flexo-page{max-width:none;width:100%;margin-block:0;background-color:#e9dcc7}' ), 'own page background' );
t_eq( '', $page_css( 'thank_you' ), 'the Thank You page keeps the global look' );
$global_sel = ap_selectors( Flexo_Booking_Appearance::css() );
$page_sel   = ap_selectors( $css );
t_ok( ap_specificity( '.flexo-system-page--booking .flexo-booking' ) > ap_specificity( '.flexo-booking' ) && ap_specificity( '.flexo-system-page--booking' ) > 0 && ap_specificity( 'body.flexo-system-page-body--booking .flexo-page' ) >= ap_specificity( 'body.flexo-system-page-body .flexo-page' ), 'own look is more specific than the global one (wins in any order)' );
$html  = ap_get( Flexo_Booking_System_Pages::builtin_url( 'booking' ) );
$sheet = ap_page_css( $html );
t_ok( false !== strpos( $sheet, '.flexo-system-page--booking,.flexo-system-page--booking .flexo-booking{' ) && strpos( $sheet, 'body.flexo-system-page-body--booking' ) > strpos( $sheet, 'body.flexo-system-page-body .flexo-page' ), 'Booking page sends the global and its own look (own after global)' );
t_eq( 1, preg_match_all( '/(^|\}|\n)\.flexo-booking\{--fb-/', $sheet ), 'the global CSS is printed once' );

ap_settings( array( 'appearance_mode' => 'match' ) );
t_eq( '', Flexo_Booking_Appearance::css(), 'Match: no global CSS' );
t_ok( '' !== $page_css( 'booking' ), 'Match: a page\'s own look still applies' );
ap_settings( array( 'appearance_mode' => 'custom' ) );
Flexo_Booking_Features::set_enabled( array_diff( Flexo_Booking_Features::stored_enabled(), array( 'custom_appearance' ) ) );
t_eq( '', Flexo_Booking_Appearance::css() . $page_css( 'booking' ), '"Appearance settings" switched off under Features: no global or page CSS' );
Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'custom_appearance' ) ) );

Flexo_Booking_System_Pages::update( array( 'booking' => array( 'intro' => 'Our intro' ) ) );
t_eq( '#e9dcc7', Flexo_Booking_System_Pages::get( 'booking' )['appearance']['page_bg'], 'saving other page settings keeps the look' );
$own( 'booking', array( 'accent' => '#ff8800' ) );
t_eq( '#e9dcc7', Flexo_Booking_System_Pages::get( 'booking' )['appearance']['page_bg'], 'saving one colour keeps the others' );
$own( 'booking', array( 'use_global' => '1' ) );
t_eq( '', $page_css( 'booking' ), 'switching back to the global look: no own CSS…' );
t_eq( '#e9dcc7', Flexo_Booking_System_Pages::get( 'booking' )['appearance']['page_bg'], '…and the own colours are kept for later' );
$own( 'booking', array( 'use_global' => '0' ) );
$own( 'booking', array( 'reset' => '1', 'page_bg' => '#000000' ) );
t_eq( Flexo_Booking_System_Pages::appearance_defaults(), Flexo_Booking_System_Pages::get( 'booking' )['appearance'], '"Reset to global": the defaults again' );
t_eq( '', $page_css( 'booking' ), 'after reset: no own CSS' );

/* ------------------------------------------------------------------------- */
t_section( 'Thank You: success and status colours per state' );
$own( 'thank_you', array( 'use_global' => '0', 'success' => '#1b5e20', 'status_confirmed' => '#1b5e20', 'status_awaiting' => '#fff3c4', 'status_request' => '#0d47a1' ) );
$css = $page_css( 'thank_you' );
t_ok( 0 === strpos( $css, '.flexo-system-page--thank-you,.flexo-system-page--thank-you .flexo-booking{' ), 'scoped to the Thank You page' );
t_ok( false !== strpos( $css, '--fb-success:#1b5e20' ), 'success icon' );
t_ok( false !== strpos( $css, '--fb-status-confirmed-bg:#1b5e20;--fb-status-confirmed-text:#ffffff' ), 'confirmed' );
t_ok( false !== strpos( $css, '--fb-status-awaiting-bg:#fff3c4;--fb-status-awaiting-text:#1f2933' ), 'awaiting payment' );
t_ok( false !== strpos( $css, '--fb-status-request-bg:#0d47a1;--fb-status-request-text:#ffffff' ), 'request received' );
$own( 'thank_you', array( 'reset' => '1' ) );

/* ------------------------------------------------------------------------- */
t_section( 'Validation' );
$own(
	'booking',
	array(
		'use_global'        => '0',
		'page_bg'           => 'red;}body{display:none',
		'primary'           => 'ABC',
		'corners'           => 'giant',
		'max_width'         => 'custom',
		'max_width_px'      => '99999',
		'spacing'           => 'x',
		'page_image'        => '999999',
		'page_image_effect' => 'blur',
	)
);
$look = Flexo_Booking_System_Pages::get( 'booking' )['appearance'];
t_eq( '', $look['page_bg'], 'a colour that is not a colour is dropped' );
t_eq( '#abc', $look['primary'], 'short hex colours are accepted' );
t_eq( '', $look['corners'], 'unknown corners: as in Appearance' );
t_eq( 2000, $look['max_width_px'], 'width limited to 600–2000 px' );
t_eq( '', $look['spacing'], 'unknown spacing: as in Appearance' );
t_eq( 0, $look['page_image'], 'a picture must be in the media library' );
t_eq( 'none', $look['page_image_effect'], 'unknown picture effect: none' );
t_ok( false === strpos( $page_css( 'booking' ), 'display:none' ), 'nothing written into the CSS that is not a value' );
ap_settings( array( 'appearance_page_bg' => 'url(javascript:alert(1))', 'appearance_max_width_px' => '10', 'appearance_card_shadow' => 'huge' ) );
$s = Flexo_Booking_Settings::all();
t_ok( '' === $s['appearance_page_bg'] && 600 === $s['appearance_max_width_px'] && '' === $s['appearance_card_shadow'], 'global settings validated the same way' );
$own( 'booking', array( 'reset' => '1' ) );

/* ------------------------------------------------------------------------- */
t_section( 'Background picture' );
$upload = wp_upload_bits( 'flexo-ap-bg.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==' ) );
$image  = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'flexo-ap-bg', 'post_status' => 'inherit' ), $upload['file'] );
$ap_attachments[] = $image;
$own( 'thank_you', array( 'use_global' => '0', 'page_bg' => '#eef4f7', 'page_image' => (string) $image, 'page_image_effect' => 'light' ) );
$css = $page_css( 'thank_you' );
t_ok( false !== strpos( $css, 'background-image:linear-gradient(rgba(255,255,255,.65),rgba(255,255,255,.65)),url("' . esc_url_raw( wp_get_attachment_url( $image ) ) ), 'picture with a light wash over it' );
t_ok( false !== strpos( $css, 'background-size:cover' ) && false !== strpos( $css, 'background-color:#eef4f7' ), 'covers the page; the colour shows while it loads' );
t_eq( array(), array_filter( Flexo_Booking_Appearance::page_contrast_problems( 'thank_you', Flexo_Booking_System_Pages::get( 'thank_you' )['appearance'] ), static function ( $p ) { return 'page_text' === $p['pair']; } ), 'no text/background warning on a picture (cannot be measured)' );

/* ------------------------------------------------------------------------- */
t_section( 'Contrast warnings (never blocking)' );
$problems = Flexo_Booking_Appearance::page_contrast_problems( 'booking', array( 'use_global' => 0, 'text' => '#d0d0d0', 'card_bg' => '#ffffff', 'page_bg' => '#efe4d2', 'primary' => '#ffeb3b' ) );
$pairs    = wp_list_pluck( $problems, 'pair' );
t_ok( in_array( 'card_text', $pairs, true ) && in_array( 'page_text', $pairs, true ), 'light text on light cards and page: warned' );
t_ok( ! in_array( 'button_text', $pairs, true ), 'button text is chosen automatically (dark on yellow): no warning' );
$first = $problems[0];
t_ok( $first['ratio'] < 4.5 && '#1f2933' === $first['suggestion'] && '' !== $first['label'], 'ratio, a suggested colour and a plain label' );
t_eq( array(), Flexo_Booking_Appearance::page_contrast_problems( 'booking', array( 'use_global' => 0, 'text' => '#1f2933', 'card_bg' => '#ffffff' ) ), 'readable colours: no warnings' );
t_eq( array(), Flexo_Booking_Appearance::page_contrast_problems( 'booking', array( 'use_global' => 1, 'text' => '#eeeeee' ) ), 'global look: the global screen warns instead' );
$own( 'booking', array( 'use_global' => '0', 'text' => '#d0d0d0' ) );
t_eq( '#d0d0d0', Flexo_Booking_System_Pages::get( 'booking' )['appearance']['text'], 'a hard-to-read colour can still be saved' );
ap_settings( array( 'appearance_label' => '#eeeeee', 'appearance_card_bg' => '#ffffff' ) );
t_ok( in_array( 'label_bg', wp_list_pluck( Flexo_Booking_Appearance::contrast_problems(), 'pair' ), true ), 'global: field labels checked too' );
$own( 'booking', array( 'reset' => '1' ) );

/* ------------------------------------------------------------------------- */
t_section( 'Scoped: no global CSS' );
ap_settings( array( 'appearance_card_corners' => 'square', 'appearance_tab_corners' => 'pill', 'appearance_focus' => '#ff0000', 'appearance_page_image' => $image, 'appearance_page_image_effect' => 'dark' ) );
$own( 'booking', array( 'use_global' => '0', 'page_bg' => '#e9dcc7', 'corners' => 'slight' ) );
$all_css = Flexo_Booking_Appearance::css() . $page_css( 'booking' );
$bad     = array_filter(
	ap_selectors( $all_css ),
	static function ( $selector ) {
		return 1 !== preg_match( '/^(\.flexo-booking|\.flexo-system-page(--[a-z-]+)?( \.flexo-booking)?|body\.flexo-system-page-body(--[a-z-]+)? \.flexo-page)$/', $selector );
	}
);
t_eq( array(), array_values( $bad ), 'every selector is a Flexo element (no body, h1, button, …)' );
t_ok( false === strpos( $all_css, '!important' ), 'no !important' );
t_ok( false === strpos( $all_css, 'fonts.googleapis' ) && false === strpos( $all_css, '@import' ), 'no outside fonts or files' );
$own( 'booking', array( 'reset' => '1' ) );

/* ------------------------------------------------------------------------- */
t_section( 'Elementor widget Style still wins' );
$widget = (string) file_get_contents( FLEXO_BOOKING_DIR . 'includes/elementor/class-booking-widget.php' );
t_ok( false !== strpos( $widget, "'{{WRAPPER}} .flexo-booking' => \$color[1]" ), 'widget colours set the same variables on .flexo-booking' );
$elementor = '.elementor-12 .elementor-element.elementor-element-abc123 .flexo-booking';
foreach ( array( '.flexo-booking', '.flexo-system-page', '.flexo-system-page--booking .flexo-booking', '.flexo-system-page--thank-you .flexo-booking' ) as $ours ) {
	t_ok( ap_specificity( $elementor ) > ap_specificity( $ours ), 'Elementor\'s rule beats ' . $ours );
}
t_ok( false === strpos( (string) file_get_contents( FLEXO_BOOKING_DIR . 'includes/class-appearance.php' ), 'elementor-element' ), 'our CSS never targets Elementor elements' );

/* ------------------------------------------------------------------------- */
t_section( 'Import / Export' );
ap_reset_settings();
ap_settings( array( 'appearance_mode' => 'custom', 'appearance_page_bg' => '#f4ede2', 'appearance_page_image' => $image, 'appearance_spacing' => 'compact' ) );
$own( 'thank_you', array( 'use_global' => '0', 'page_bg' => '#eef4f7', 'status_request' => '#0d47a1', 'page_image' => (string) $image ) );
$export = Flexo_Booking_Portability::export();
$url    = wp_get_attachment_url( $image );
t_eq( 0, $export['settings']['appearance_page_image'], 'export: no site-specific picture IDs' );
t_eq( $url, $export['appearance']['page_image'], 'export: the global picture as an address' );
t_eq( '#0d47a1', $export['appearance']['pages']['thank_you']['status_request'], 'export: the Thank You page\'s own look' );
t_eq( $url, $export['appearance']['pages']['thank_you']['page_image_url'], 'export: its picture as an address' );
t_eq( 0, $export['appearance']['pages']['thank_you']['page_image'], 'export: no picture ID' );
t_eq( 1, $export['appearance']['pages']['booking']['use_global'], 'export: the Booking page uses the global look' );
$json = json_decode( wp_json_encode( $export ), true );

ap_reset_settings();
delete_option( Flexo_Booking_System_Pages::OPTION );
Flexo_Booking_System_Pages::update( array( 'booking' => array( 'source' => 'builtin', 'slug' => 'ap-book' ), 'thank_you' => array( 'source' => 'builtin', 'enabled' => 1, 'slug' => 'ap-thanks' ) ) );
$stats = Flexo_Booking_Portability::import( $json, array( 'rooms' => false, 'rate_plans' => false, 'promo_codes' => false, 'closures' => false, 'seasons' => false ) );
t_eq( 1, $stats['settings'], 'import: settings' );
$s = Flexo_Booking_Settings::all();
t_ok( '#f4ede2' === $s['appearance_page_bg'] && 'compact' === $s['appearance_spacing'], 'import: the new global settings' );
t_eq( 0, $s['appearance_page_image'], 'import without pictures: no picture (this site had none)' );
$look = Flexo_Booking_System_Pages::get( 'thank_you' )['appearance'];
t_ok( 0 === $look['use_global'] && '#eef4f7' === $look['page_bg'] && '#0d47a1' === $look['status_request'], 'import: the page\'s own look' );
t_eq( 0, $look['page_image'], 'import without pictures: page picture not set' );
// The picture is "already downloaded" from that address: the import finds it.
update_post_meta( $image, '_flexo_source_url', $url );
Flexo_Booking_Portability::import( $json, array( 'rooms' => false, 'rate_plans' => false, 'promo_codes' => false, 'closures' => false, 'seasons' => false, 'images' => true ) );
t_eq( $image, Flexo_Booking_Settings::all()['appearance_page_image'], 'import with pictures: the global picture' );
t_eq( $image, Flexo_Booking_System_Pages::get( 'thank_you' )['appearance']['page_image'], 'import with pictures: the page picture' );
$older = $json;
unset( $older['appearance'] );
foreach ( Flexo_Booking_Appearance::PAGE_COLORS as $key ) {
	unset( $older['settings'][ $key ] );
}
Flexo_Booking_Portability::import( $older, array( 'rooms' => false, 'rate_plans' => false, 'promo_codes' => false, 'closures' => false, 'seasons' => false ) );
t_ok( '#0d47a1' === Flexo_Booking_System_Pages::get( 'thank_you' )['appearance']['status_request'] && $image === Flexo_Booking_Settings::all()['appearance_page_image'], 'a file from an older version changes nothing of the new settings' );

/* ------------------------------------------------------------------------- */
t_section( 'Emails unchanged' );
ap_settings( array( 'appearance_page_bg' => '#123123', 'appearance_card_bg' => '#321321', 'appearance_heading_color' => '#456456' ) );
$own( 'thank_you', array( 'use_global' => '0', 'primary' => '#654654' ) );
$email_files = glob( FLEXO_BOOKING_DIR . 'includes/class-email*.php' );
$email_src   = '';
foreach ( $email_files as $file ) {
	$email_src .= (string) file_get_contents( $file );
}
t_ok( '' !== $email_src && false === strpos( $email_src, 'appearance_page_bg' ) && false === strpos( $email_src, 'page_css' ) && false === strpos( $email_src, "['appearance']" ), 'emails do not use the page appearance' );

t_done();
