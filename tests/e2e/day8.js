/**
 * Day 8 (1.9.0) browser tests: the built-in Booking page, booking → clean
 * Thank You page (instant, bank transfer, card through the Stripe mock),
 * booking_complete exactly once (refresh, Back), inline mode without the
 * guest key in the address, an overlay header and the title band, widths
 * 360–desktop.
 *
 *   STRIPE_MOCK=… STRIPE_MOCK_DIR=… wp eval-file tests/e2e/seed-day8.php   → D0
 *   BASE=http://localhost:8092 D0=<D0> SHOTS=/tmp/shots MOCK=http://localhost:12111 \
 *   WP="php wp-cli.phar --allow-root --path=<site>" node tests/e2e/day8.js
 */
const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const WP = process.env.WP;
const D0 = new Date( process.env.D0 + 'T12:00:00Z' );
const day = ( n ) => { const d = new Date( D0 ); d.setUTCDate( d.getUTCDate() + n ); return d.toISOString().slice( 0, 10 ); };

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };
const section = ( t ) => console.log( '\n== ' + t );
const text = async ( p, sel ) => ( await p.innerText( sel ) ).replace( /\s+/g, ' ' );
const noOverflow = ( p ) => p.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 );
const wp = ( code ) => execSync( `${ WP } eval '${ code }' 2>/dev/null` ).toString().trim();
const pages = ( php ) => wp( `Flexo_Booking_System_Pages::update( ${ php } );` );
const settings = ( php ) => wp( `update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), ${ php } ) ) );` );

const RECORDER = `
	(function () {
		var dl = [];
		dl.push = function () {
			for ( var i = 0; i < arguments.length; i++ ) {
				var copy = {};
				for ( var k in arguments[ i ] ) { if ( typeof arguments[ i ][ k ] !== 'function' ) { copy[ k ] = arguments[ i ][ k ]; } }
				var list = JSON.parse( sessionStorage.getItem( 'e2e_events' ) || '[]' );
				list.push( copy );
				sessionStorage.setItem( 'e2e_events', JSON.stringify( list ) );
			}
			return Array.prototype.push.apply( this, arguments );
		};
		window.dataLayer = dl;
	})();
`;
const completes = async ( p ) => ( await p.evaluate( () => JSON.parse( sessionStorage.getItem( 'e2e_events' ) || '[]' ) ) ).filter( ( e ) => 'booking_complete' === e.event ).length;

async function toDetails( p, from, to, room = 'Garden Room' ) {
	await p.goto( `${ BASE }/booking/?check_in=${ day( from ) }&check_out=${ day( to ) }&adults=2&children=0` );
	await p.waitForSelector( '.fb-room' );
	await p.locator( '.fb-room', { hasText: room } ).locator( '.fb-room__side .fb-button' ).first().click();
	const plan = p.locator( '.fb-plan .fb-button' ).first();
	if ( await plan.count() && await plan.isVisible() ) {
		await plan.click();
	}
	await p.waitForSelector( '.fb-details:not([hidden])' );
	await p.fill( '[name=guest_name]', 'Day Eight Guest' );
	await p.fill( '[name=guest_email]', 'day8@example.com' );
	const phone = p.locator( '[name=guest_phone]' );
	if ( await phone.count() ) {
		await phone.fill( '+359 888 555 666' );
	}
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const context = async ( width = 1280, script = RECORDER ) => {
		const ctx = await browser.newContext( { viewport: { width, height: 900 } } );
		await ctx.addInitScript( script );
		return ctx;
	};

	section( 'Built-in Booking page' );
	let ctx = await context();
	let p = await ctx.newPage();
	p.on( 'pageerror', ( e ) => errors.push( e.message ) );
	let res = await p.goto( BASE + '/booking/' );
	ok( 200 === res.status(), '/booking/ answers 200 without a WordPress page' );
	ok( 'Book your stay' === ( await p.textContent( 'h1.flexo-sp-title' ) ).trim(), 'page title' );
	ok( await p.isVisible( '.flexo-sp-booking .flexo-booking--full' ), 'the booking form' );
	ok( /Book your stay – /.test( await p.title() ), 'document title "Book your stay – Site name"' );
	for ( const w of [ 360, 390, 414, 768, 1024, 1440 ] ) {
		await p.setViewportSize( { width: w, height: 900 } );
		await p.goto( `${ BASE }/booking/?check_in=${ day( 10 ) }&check_out=${ day( 12 ) }&adults=2` );
		await p.waitForSelector( '.fb-room' );
		ok( await noOverflow( p ), `${ w }px: no horizontal scroll (booking page with results)` );
		if ( 360 === w || 1440 === w ) {
			await p.screenshot( { path: `${ SHOTS }/d8-booking-${ w }.png`, fullPage: true } );
		}
	}
	await ctx.close();

	section( 'Bank transfer → clean Thank You page' );
	ctx = await context( 390 );
	p = await ctx.newPage();
	p.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await toDetails( p, 10, 13 );
	await p.check( '[name=payment_method][value=bank_transfer]' );
	await Promise.all( [ p.waitForURL( /\/thank-you\/$/, { timeout: 20000 } ), p.click( '.fb-details [type=submit]' ) ] );
	ok( p.url() === BASE + '/thank-you/', 'clean address: ' + p.url() );
	let body = await text( p, '.flexo-confirmation' );
	ok( body.includes( 'Your reservation is awaiting payment' ), 'bank transfer heading' );
	ok( body.includes( 'BG80BNBG96611020345678' ) || body.includes( 'BG80 BNBG 9661 1020 3456 78' ), 'IBAN shown' );
	ok( /FB-[A-Z0-9]{6}/.test( body ), 'booking reference' );
	ok( 1 === await completes( p ), 'booking_complete sent once' );
	ok( await noOverflow( p ), '390px: no horizontal scroll' );
	await p.screenshot( { path: SHOTS + '/d8-ty-bank-390.png', fullPage: true } );
	for ( const w of [ 360, 414, 768, 1024, 1440 ] ) {
		await p.setViewportSize( { width: w, height: 900 } );
		ok( await noOverflow( p ), `Thank You ${ w }px: no horizontal scroll` );
	}
	await p.reload();
	ok( ( await text( p, '.flexo-confirmation' ) ).includes( 'awaiting payment' ), 'refresh: still shown (secure cookie)' );
	ok( 1 === await completes( p ), 'refresh: booking_complete not sent again' );
	await p.goBack();
	await p.waitForTimeout( 1500 );
	ok( 1 === await completes( p ), 'Back: booking_complete not sent again' );
	const other = await browser.newPage();
	await other.goto( BASE + '/thank-you/' );
	ok( ! ( await other.innerText( 'body' ) ).includes( 'BG80' ) && ( await other.innerText( 'body' ) ).includes( 'Thank you' ), 'another visitor: general text, no booking' );
	await other.close();
	await ctx.close();

	section( 'Card payment (Stripe mock) → Thank You only when confirmed' );
	ctx = await context();
	p = await ctx.newPage();
	p.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await toDetails( p, 20, 23 );
	await p.check( '[name=payment_method][value=stripe]' );
	await Promise.all( [ p.waitForURL( /localhost:12111\/pay\// ), p.click( '.fb-details [type=submit]' ) ] );
	ok( 0 === await completes( p ), 'nothing counted before paying' );
	await Promise.all( [ p.waitForURL( /fb_payment=return/ ), p.click( '#pay' ) ] );
	await p.waitForURL( /\/thank-you\/$/, { timeout: 30000 } );
	body = await text( p, '.flexo-confirmation' );
	ok( body.includes( 'Your booking is confirmed' ) && body.includes( 'Payment received' ), 'paid: confirmed, payment received' );
	ok( 1 === await completes( p ), 'booking_complete sent once' );
	const hist = await p.evaluate( () => history.length );
	await p.goBack();
	await p.waitForTimeout( 2500 );
	ok( ! p.url().includes( 'fb_key=' ), 'Back to the payment return: the key is no longer in the address' );
	ok( 1 === await completes( p ), 'Back: booking_complete not sent again' );
	ok( hist > 1, 'history kept' );
	await p.screenshot( { path: SHOTS + '/d8-ty-card.png', fullPage: true } );
	await ctx.close();

	section( 'Instant booking without payment' );
	settings( 'array( "payment_mode" => "property" )' );
	ctx = await context();
	p = await ctx.newPage();
	await toDetails( p, 30, 32 );
	await Promise.all( [ p.waitForURL( /\/thank-you\/$/, { timeout: 20000 } ), p.click( '.fb-details [type=submit]' ) ] );
	body = await text( p, '.flexo-confirmation' );
	ok( body.includes( 'Your booking is confirmed' ) && body.includes( 'Garden Room' ), 'confirmed booking with the room' );
	ok( ! body.includes( 'Bank transfer details' ) && ! body.includes( 'Payment received' ), 'no payment details' );
	await ctx.close();

	section( 'Inline mode (upgraded sites): no guest key in the address' );
	pages( 'array( "after_booking" => "inline" )' );
	ctx = await context();
	p = await ctx.newPage();
	await toDetails( p, 40, 42 );
	await p.click( '.fb-details [type=submit]' );
	await p.waitForSelector( '.fb-done__title', { timeout: 15000 } );
	ok( p.url().includes( '/booking/' ) && p.url().includes( 'fb_done=' ) && ! p.url().includes( 'fb_key=' ), 'stays on the booking page; fb_done without fb_key' );
	ok( 1 === await completes( p ), 'booking_complete sent once' );
	await p.reload();
	await p.waitForSelector( '.fb-done__title' );
	ok( ( await text( p, '.fb-success' ) ).includes( 'Garden Room' ), 'refresh: confirmation shown again (key kept in the tab)' );
	ok( 1 === await completes( p ), 'refresh: not counted again' );
	const shared = await ( await browser.newContext() ).newPage();
	await shared.goto( p.url() );
	await shared.waitForSelector( '.fb-success:not([hidden])' );
	const sharedText = await text( shared, '.fb-success' );
	ok( sharedText.includes( 'confirmation email' ) && ! sharedText.includes( 'Garden Room' ), 'the address opened elsewhere shows no booking' );
	await ctx.close();
	pages( 'array( "after_booking" => "separate" )' );

	section( 'Overlay header and title band' );
	const OVERLAY = RECORDER + `
		document.addEventListener( 'DOMContentLoaded', function () {
			var h = document.createElement( 'header' );
			h.className = 'site-header';
			h.style.cssText = 'position:fixed;top:0;left:0;right:0;height:96px;background:rgba(0,0,0,.45);z-index:9999';
			document.body.insertBefore( h, document.body.firstChild );
		} );
	`;
	for ( const w of [ 1440, 1024, 768, 390 ] ) {
		ctx = await context( w, OVERLAY );
		p = await ctx.newPage();
		await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
		await p.waitForTimeout( 300 );
		const top = await p.evaluate( () => document.querySelector( '.flexo-sp-title' ).getBoundingClientRect().top );
		ok( top >= 96, `${ w }px Normal: the page title is below the overlay header (${ Math.round( top ) }px)` );
		await ctx.close();
	}
	pages( 'array( "header_style" => "band", "header_band_bg" => "#204060" )' );
	ctx = await context( 1440, OVERLAY );
	p = await ctx.newPage();
	await p.goto( BASE + '/booking/', { waitUntil: 'load' } );
	await p.waitForTimeout( 300 );
	const band = await p.evaluate( () => {
		const b = document.querySelector( '.flexo-title-band' );
		const t = document.querySelector( '.flexo-title-band__title' ).getBoundingClientRect();
		return { top: b.getBoundingClientRect().top, titleTop: t.top, bg: getComputedStyle( b ).backgroundColor };
	} );
	ok( band.top < 96 && band.titleTop >= 96, `Band: behind the header, title below it (${ Math.round( band.titleTop ) }px)` );
	ok( 'rgb(32, 64, 96)' === band.bg, 'band colour' );
	await p.screenshot( { path: SHOTS + '/d8-band.png' } );
	await p.setViewportSize( { width: 390, height: 800 } );
	await p.waitForTimeout( 400 );
	ok( await noOverflow( p ), 'band at 390px: no horizontal scroll' );
	await ctx.close();
	pages( 'array( "header_style" => "normal" )' );

	ok( 0 === errors.length, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( ' | ' ) : '' ) );
	await browser.close();
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )();
