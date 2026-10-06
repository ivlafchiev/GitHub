/**
 * 1.9.0 Appearance for the built-in pages, in the browser: the global look
 * and a page's own look on the real Booking and Thank You pages (page
 * background colour and picture, cards, buttons, status colours), the live
 * preview in Settings → Pages and Bookings → Appearance matching the real
 * page, changes shown before saving, "Reset to global", contrast warnings,
 * widths 360–desktop.
 *
 * Runs after the Day 8 seed (built-in pages at /booking/ and /thank-you/):
 *   wp eval-file tests/e2e/seed-day8.php   → D0
 *   BASE=http://localhost:8092 SHOTS=/tmp/shots \
 *   WP="php wp-cli.phar --allow-root --path=<site>" node tests/e2e/day8-appearance.js
 * Users: admin/admin.
 */
const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const WP = process.env.WP;

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };
const section = ( t ) => console.log( '\n== ' + t );
// First line of the output, without notices other plugins may print.
const wp = ( code ) => execSync( `${ WP } eval '${ code }' 2>/dev/null` ).toString().replace( /PHP: [\s\S]*$/, '' ).split( '\n' )[ 0 ].trim();
const pages = ( php ) => wp( `Flexo_Booking_System_Pages::update( ${ php } );` );
const settings = ( php ) => wp( `update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), ${ php } ) ) );` );
const noOverflow = ( p ) => p.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 );

/** The computed look of the parts the settings change. */
const look = ( frame ) => frame.evaluate( () => {
	const css = ( sel, prop ) => {
		const n = document.querySelector( sel );
		return n ? getComputedStyle( n )[ prop ] : null;
	};
	return {
		pageBg: css( 'main.flexo-page', 'backgroundColor' ),
		pageImage: css( 'main.flexo-page', 'backgroundImage' ),
		title: css( '.flexo-sp-title', 'color' ),
		card: css( '.flexo-sp-booking__form > .flexo-booking', 'backgroundColor' ),
		cardRadius: css( '.flexo-sp-booking__form > .flexo-booking', 'borderTopLeftRadius' ),
		button: css( '.flexo-sp-booking .fb-search .fb-button', 'backgroundColor' ),
		buttonText: css( '.flexo-sp-booking .fb-search .fb-button', 'color' ),
		box: css( '.flexo-sp-box', 'backgroundColor' ),
		width: css( '.flexo-system-page', 'maxWidth' ),
	};
} );

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	// Only our own scripts: test sites may run plugins with missing files.
	const watch = ( p ) => p.on( 'pageerror', ( e ) => {
		if ( /flexo|booking/i.test( String( e.stack ) ) || ! /Unexpected token '<'/.test( e.message ) ) {
			errors.push( e.message );
		}
	} );
	const image = wp( '$u = wp_upload_bits( "flexo-e2e-bg.png", null, base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==" ) ); echo wp_insert_attachment( array( "post_mime_type" => "image/png", "post_title" => "flexo-e2e-bg", "post_status" => "inherit" ), $u["file"] );' );
	ok( /^\d+$/.test( image ), 'test picture in the media library (#' + image + ')' );

	const features = wp( 'echo implode( ",", Flexo_Booking_Features::stored_enabled() );' );
	wp( 'Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( "custom_appearance" ) ) );' );

	section( 'Defaults: Booking page as before (white, no own look)' );
	let ctx = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
	let p = await ctx.newPage();
	watch( p );
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	const before = await look( p );
	ok( 'rgba(0, 0, 0, 0)' === before.pageBg && 'none' === before.pageImage, 'no page background added' );
	ok( 'rgb(255, 255, 255)' === before.card, 'white form card' );
	ok( '1200px' === before.width, 'content width 1200px' );
	ok( 0 === await p.locator( '#flexo-booking-inline-css' ).count() || ! /flexo-page\{/.test( await p.locator( '#flexo-booking-inline-css' ).textContent() ), 'no page CSS' );
	await ctx.close();

	section( 'Global appearance reaches the real pages' );
	settings( 'array( "appearance_mode" => "custom", "appearance_page_bg" => "#f4ede2", "appearance_card_bg" => "#fffdf8", "appearance_heading_color" => "#3a2a1a", "appearance_max_width" => "narrow", "appearance_card_corners" => "rounded", "appearance_status_awaiting" => "#f4c430" )' );
	ctx = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
	p = await ctx.newPage();
	watch( p );
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	let now = await look( p );
	ok( 'rgb(244, 237, 226)' === now.pageBg, 'page background: ' + now.pageBg );
	ok( 'rgb(255, 253, 248)' === now.card && 'rgb(255, 253, 248)' === now.box, 'form card and help boxes: card background' );
	ok( 'rgb(58, 42, 26)' === now.title, 'page title: heading colour' );
	ok( '960px' === now.width, 'narrow content' );
	ok( '16px' === now.cardRadius, 'rounded cards' );
	const mainBox = await p.evaluate( () => {
		const m = document.querySelector( 'main.flexo-page' ).getBoundingClientRect();
		return { left: m.left, width: m.width, vw: document.documentElement.clientWidth };
	} );
	ok( mainBox.left <= 1 && mainBox.width >= mainBox.vw - 1, 'the background covers the full width between header and footer' );
	await p.screenshot( { path: SHOTS + '/d8a-global-booking.png', fullPage: true } );
	await ctx.close();

	section( 'A page\'s own look wins (Booking), picture background' );
	pages( 'array( "booking" => array( "appearance" => array( "use_global" => 0, "page_bg" => "#e9dcc7", "page_image" => ' + image + ', "page_image_effect" => "light", "card_bg" => "#fffaf3", "primary" => "#8a4b2a", "heading" => "#2b1d12", "corners" => "square", "spacing" => "compact" ) ) )' );
	for ( const w of [ 360, 390, 768, 1024, 1440 ] ) {
		ctx = await browser.newContext( { viewport: { width: w, height: 900 } } );
		p = await ctx.newPage();
		watch( p );
		await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
		now = await look( p );
		if ( 1440 === w ) {
			ok( 'rgb(233, 220, 199)' === now.pageBg, 'own page colour beats the global one' );
			ok( /linear-gradient\(rgba\(255, 255, 255, 0\.65\).*url\(.*flexo-e2e-bg/.test( now.pageImage ), 'picture with a light wash' );
			ok( 'rgb(255, 250, 243)' === now.card, 'own card colour' );
			ok( 'rgb(138, 75, 42)' === now.button && 'rgb(255, 255, 255)' === now.buttonText, 'buttons in the page\'s main colour, readable text' );
			ok( 'rgb(43, 29, 18)' === now.title, 'own heading colour' );
			ok( '0px' === now.cardRadius, 'own corners (square)' );
			ok( '960px' === now.width, 'width not set on the page: the global one stays' );
		}
		ok( await noOverflow( p ), `${ w }px: no horizontal scroll` );
		await p.screenshot( { path: `${ SHOTS }/d8a-own-booking-${ w }.png`, fullPage: true } );
		await ctx.close();
	}

	section( 'Thank You: global status colour, own success colour' );
	pages( 'array( "thank_you" => array( "appearance" => array( "use_global" => 0, "page_bg" => "#eef4f7", "status_request" => "#0d47a1" ) ) )' );
	const admin = await browser.newContext( { viewport: { width: 1600, height: 1000 } } );
	const a = await admin.newPage();
	watch( a );
	await a.goto( BASE + '/wp-login.php' );
	await a.fill( '#user_login', 'admin' );
	await a.fill( '#user_pass', 'admin' );
	await Promise.all( [ a.waitForNavigation(), a.click( '#wp-submit' ) ] );
	// The sample-booking link carries a nonce of this browser session.
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=pages', { waitUntil: 'load' } );
	const sample = await a.getAttribute( 'a:has-text("Preview with a sample booking")', 'href' );
	const preview = ( kind ) => sample.replace( /([?&]flexo_preview=)[a-z_]+/, '$1' + kind );
	for ( const [ kind, bg, label ] of [ [ 'transfer', 'rgb(244, 196, 48)', 'awaiting payment: global colour' ], [ 'request', 'rgb(13, 71, 161)', 'request received: the page\'s own colour' ] ] ) {
		await a.goto( preview( kind ), { waitUntil: 'load' } );
		const badge = await a.evaluate( () => {
			const n = document.querySelector( '.fb-ty-badge' );
			return n ? [ getComputedStyle( n ).backgroundColor, getComputedStyle( n ).color ] : [];
		} );
		ok( bg === badge[ 0 ], 'status ' + label + ' (' + badge[ 0 ] + ')' );
		ok( 'request' !== kind || 'rgb(255, 255, 255)' === badge[ 1 ], 'white text on the dark badge' );
		ok( 'rgb(238, 244, 247)' === await a.evaluate( () => getComputedStyle( document.querySelector( 'main.flexo-page' ) ).backgroundColor ), 'Thank You page background' );
	}
	await a.screenshot( { path: SHOTS + '/d8a-ty.png', fullPage: true } );

	section( 'Settings → Pages: preview matches the real page, live changes' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=pages', { waitUntil: 'load' } );
	await a.click( '[data-flexo-remember="booking"] > summary' );
	const box = a.locator( '[data-flexo-look="booking"]' );
	ok( await box.locator( '[data-flexo-look-own]' ).isVisible(), 'own look: its settings are shown' );
	await box.locator( '.flexo-page-look__preview' ).scrollIntoViewIfNeeded();
	const frame = a.frameLocator( '[data-flexo-look="booking"] iframe' );
	await frame.locator( '.flexo-sp-booking' ).waitFor();
	await a.waitForTimeout( 1200 );
	const inFrame = await look( a.frames().find( ( f ) => /flexo_booking_preview=booking/.test( f.url() ) ) );
	ok( inFrame.pageBg === now.pageBg && inFrame.card === 'rgb(255, 250, 243)' && inFrame.title === 'rgb(43, 29, 18)', 'preview shows the saved look (background, card, heading)' );
	ok( 'rgb(138, 75, 42)' === inFrame.button, 'preview: the same button colour as the real page' );
	ok( 0 === await frame.locator( '.flexo-preview-bar' ).count(), 'no sample-booking bar in the preview' );
	await box.locator( '#fb-look-booking-card_bg' ).fill( '#e3f2fd' );
	await box.locator( '#fb-look-booking-text' ).fill( '#cccccc' );
	await box.locator( '#fb-look-booking-text' ).blur();
	await a.waitForTimeout( 1500 );
	const live = await look( a.frames().find( ( f ) => /flexo_booking_preview=booking/.test( f.url() ) ) );
	ok( 'rgb(227, 242, 253)' === live.card, 'a change shows in the preview at once' );
	ok( /hard to read/.test( await box.locator( '.flexo-appearance__warnings' ).innerText() ), 'light text: a contrast warning with a suggestion' );
	ctx = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
	p = await ctx.newPage();
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	ok( 'rgb(255, 250, 243)' === ( await look( p ) ).card, 'the real page changes only after saving' );
	await box.locator( '[data-look-width="390"]' ).click();
	await a.waitForTimeout( 600 );
	ok( 390 === Math.round( await a.locator( '[data-flexo-look="booking"] iframe' ).evaluate( ( n ) => n.getBoundingClientRect().width ) ), 'phone preview 390px' );
	await box.screenshot( { path: SHOTS + '/d8a-pages-preview.png' } );
	await Promise.all( [ a.waitForNavigation(), a.click( '.flexo-pages-form #submit' ) ] );
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	ok( 'rgb(227, 242, 253)' === ( await look( p ) ).card, 'saved: the real page has the new card colour' );
	ok( '#cccccc' === wp( 'echo Flexo_Booking_System_Pages::get( "booking" )["appearance"]["text"];' ), 'a hard-to-read colour is saved anyway (warning only)' );

	if ( ! await a.locator( '[data-flexo-remember="booking"]' ).evaluate( ( d ) => d.open ) ) {
		await a.click( '[data-flexo-remember="booking"] > summary' );
	}
	a.once( 'dialog', ( d ) => d.accept() );
	await Promise.all( [ a.waitForNavigation(), a.locator( '[data-flexo-look="booking"] [data-flexo-look-reset]' ).click() ] );
	ok( '1' === wp( 'echo Flexo_Booking_System_Pages::get( "booking" )["appearance"]["use_global"];' ), '"Reset to global": the page uses the global look again' );
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	now = await look( p );
	ok( 'rgb(244, 237, 226)' === now.pageBg && 'none' === now.pageImage && 'rgb(255, 253, 248)' === now.card, 'after reset: the global look on the real page' );
	ok( '#eef4f7' === wp( 'echo Flexo_Booking_System_Pages::get( "thank_you" )["appearance"]["page_bg"];' ), 'resetting Booking leaves Thank You alone' );
	await ctx.close();

	section( 'Bookings → Appearance: the page scenes' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-appearance', { waitUntil: 'load' } );
	await a.click( '.flexo-appearance__preview-bar [data-scene*="flexo_booking_preview=booking"]' );
	await a.frameLocator( '#flexo-appearance-frame' ).locator( '.flexo-sp-booking' ).waitFor();
	await a.fill( '#fb-appearance-page-bg', '#dff0e6' );
	await a.locator( '#fb-appearance-page-bg' ).blur();
	await a.waitForTimeout( 1500 );
	const scene = a.frames().find( ( f ) => /flexo_booking_preview=booking/.test( f.url() ) );
	ok( scene && 'rgb(223, 240, 230)' === await scene.evaluate( () => getComputedStyle( document.querySelector( 'main.flexo-page' ) ).backgroundColor ), 'global page background shows in the Booking page preview before saving' );
	ok( await a.isVisible( '.flexo-preview-open' ), '"Open page" link for the page scene' );
	await a.click( '.flexo-appearance__preview-bar [data-scene*="flexo_booking_preview=thank_you"]' );
	await a.frameLocator( '#flexo-appearance-frame' ).locator( '.flexo-confirmation' ).waitFor();
	ok( true, 'Thank You page scene' );
	await a.screenshot( { path: SHOTS + '/d8a-appearance.png' } );
	await admin.close();

	settings( 'array( "appearance_mode" => "match", "appearance_page_bg" => "", "appearance_card_bg" => "", "appearance_heading_color" => "", "appearance_max_width" => "", "appearance_card_corners" => "", "appearance_status_awaiting" => "" )' );
	pages( 'array( "booking" => array( "appearance" => array( "reset" => 1 ) ), "thank_you" => array( "appearance" => array( "reset" => 1 ) ) )' );
	wp( 'wp_delete_attachment( ' + image + ', true );' );
	wp( 'Flexo_Booking_Features::set_enabled( explode( ",", "' + features + '" ) );' );

	ok( 0 === errors.length, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( ' | ' ) : '' ) );
	await browser.close();
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )();
