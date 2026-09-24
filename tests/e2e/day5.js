/**
 * Day 5 browser tests: card payment (deposit) through the local Stripe mock
 * and its signed webhook, cancel and retry, declined + expired, bank
 * transfer on a phone, request mode, admin payment screens, and the
 * responsive layout at 360 / 390 / 414 / 768 / 1024 / 1280 px.
 *
 *   BASE=http://localhost:8092 D0=<from seed-day5.php> SHOTS=/tmp/shots MOCK=http://localhost:12111 \
 *   WP="php wp-cli.phar --allow-root --path=<site>" node tests/e2e/day5.js
 */
const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );

const BASE = process.env.BASE;
const MOCK = process.env.MOCK || 'http://localhost:12111';
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
const booking = ( ref ) => JSON.parse( wp( `echo wp_json_encode( Flexo_Booking_Bookings::get_by_reference( "${ ref }" ) ) . "\\n";` ).split( "\n" )[ 0 ] );

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
const events = async ( p ) => ( await p.evaluate( () => JSON.parse( sessionStorage.getItem( 'e2e_events' ) || '[]' ) ) ).filter( ( e ) => e.event );

async function login( browser, user ) {
	const ctx = await browser.newContext( { viewport: { width: 1400, height: 1000 }, acceptDownloads: true } );
	const p = await ctx.newPage();
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', user );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	p.on( 'dialog', ( d ) => d.accept() );
	return p;
}

async function toDetails( p, from, to, room = 'Sea View' ) {
	await p.goto( `${ BASE }/booking/?check_in=${ day( from ) }&check_out=${ day( to ) }&adults=2&children=0` );
	await p.waitForSelector( '.fb-room' );
	await p.locator( '.fb-room', { hasText: room } ).locator( '.fb-room__side .fb-button' ).click();
	await p.waitForSelector( '.fb-details:not([hidden])' );
}

async function fillGuest( p, name, email ) {
	await p.fill( '[name=guest_name]', name );
	await p.fill( '[name=guest_email]', email );
	await p.fill( '[name=guest_phone]', '+359 888 555 666' );
}

/** Checks the tablet / phone rules on the visible part of the flow. */
async function layoutChecks( p, width, stage ) {
	const r = await p.evaluate( ( w ) => {
		const root = document.querySelector( '.flexo-booking--full' );
		const cs = ( el ) => ( el ? getComputedStyle( el ) : null );
		const visible = ( el ) => el && el.offsetParent !== null;
		const small = [];
		root.querySelectorAll( '.fb-button, .fb-link, .fb-choice, .fb-search select, .fb-search input' ).forEach( ( el ) => {
			if ( visible( el ) ) {
				const b = el.getBoundingClientRect();
				if ( b.height < 44 ) {
					small.push( ( el.className || el.name || el.tagName ) + ' ' + Math.round( b.height ) );
				}
			}
		} );
		const heading = [ ...root.querySelectorAll( '.fb-title, .fb-step-title, .fb-room__title' ) ].find( visible );
		const input = [ ...root.querySelectorAll( 'input[type=text], input[type=email], input[type=date]' ) ].find( visible );
		const rb = root.getBoundingClientRect();
		return {
			overflow: document.documentElement.scrollWidth > window.innerWidth + 1,
			headingAlign: heading ? cs( heading ).textAlign : '',
			inputAlign: input ? cs( input ).textAlign : '',
			small,
			centred: Math.abs( ( rb.left ) - ( window.innerWidth - rb.right ) ) <= 2 || rb.width >= window.innerWidth - 2,
		};
	}, width );
	ok( ! r.overflow, `${ width }px ${ stage }: no horizontal scroll` );
	if ( width <= 1024 ) {
		ok( r.headingAlign === 'center', `${ width }px ${ stage }: headings centred` );
		ok( r.centred, `${ width }px ${ stage }: form centred` );
	}
	if ( r.inputAlign ) {
		ok( r.inputAlign === 'start' || r.inputAlign === 'left', `${ width }px ${ stage }: text in fields stays left-aligned` );
	}
	if ( width < 768 ) {
		ok( r.small.length === 0, `${ width }px ${ stage }: tap targets at least 44px` + ( r.small.length ? ' – ' + r.small.join( ', ' ) : '' ) );
	}
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	await ctx.addInitScript( RECORDER );
	const guest = await ctx.newPage();
	guest.on( 'pageerror', ( e ) => errors.push( e.message ) );

	section( 'Card payment: deposit through Stripe Checkout' );
	await toDetails( guest, 10, 14 );
	const summary = await text( guest, '.fb-summary' );
	ok( summary.includes( 'Deposit (30%) – to pay now 240.00 €' ), 'summary: deposit 240 to pay now' );
	ok( summary.includes( 'At the property 560.00 €' ), 'summary: 560 at the property' );
	ok( await guest.isVisible( '.fb-choice:has([value=stripe])' ) && await guest.isVisible( '.fb-choice:has([value=bank_transfer])' ), 'guest chooses card or bank transfer' );
	ok( ( await guest.textContent( '.fb-details [type=submit]' ) ).trim() === 'Continue to payment', 'button: Continue to payment' );
	await guest.check( '[name=payment_method][value=bank_transfer]' );
	ok( ( await guest.textContent( '.fb-details [type=submit]' ) ).trim() === 'Confirm booking', 'bank transfer: Confirm booking' );
	await guest.check( '[name=payment_method][value=stripe]' );
	await fillGuest( guest, 'Card Guest', 'card@example.com' );
	await guest.screenshot( { path: SHOTS + '/d5-details-desktop.png', fullPage: true } );
	await Promise.all( [ guest.waitForURL( /localhost:12111\/pay\// ), guest.click( '.fb-details [type=submit]' ) ] );
	ok( ( await guest.textContent( '.amount' ) ).includes( '240.00 EUR' ), 'Stripe page asks for 240.00 EUR (amount from the server)' );
	const refA = ( await guest.textContent( '.product' ) ).match( /FB-[A-Z0-9]+/ )[ 0 ];
	ok( booking( refA ).status === 'pending_payment', 'booking held while paying: ' + refA );
	await Promise.all( [ guest.waitForURL( /fb_payment=return/ ), guest.click( '#pay' ) ] );
	await guest.waitForSelector( '.fb-success[data-state=confirmed]', { timeout: 20000 } );
	const result = await text( guest, '.fb-success' );
	ok( result.includes( 'we received your payment' ) && result.includes( refA ), 'back on the website: confirmed' );
	ok( result.includes( 'Paid: 240.00 €' ) && result.includes( 'At the property: 560.00 €' ), 'shows paid and remaining' );
	ok( await guest.isHidden( '.fb-search' ), 'search form hidden on the result' );
	const bA = booking( refA );
	ok( bA.status === 'confirmed' && bA.payment_status === 'deposit_paid' && Number( bA.amount_paid ) === 240, 'booking confirmed by the webhook, deposit received' );
	let ev = await events( guest );
	ok( ev.filter( ( e ) => e.event === 'booking_complete' && e.booking_reference === refA ).length === 1, 'booking_complete tracked once after payment' );
	await guest.screenshot( { path: SHOTS + '/d5-paid-desktop.png', fullPage: true } );
	await guest.reload();
	await guest.waitForSelector( '.fb-success[data-state=confirmed]' );
	ev = await events( guest );
	ok( ev.filter( ( e ) => e.event === 'booking_complete' && e.booking_reference === refA ).length === 1, 'reload: not tracked again' );
	const mails = wp( 'echo file_get_contents( WP_CONTENT_DIR . "/mail.log" );' );
	ok( mails.includes( 'card@example.com' ) && mails.includes( 'Payment received' ), 'guest email: payment received' );
	ok( mails.includes( 'desk@hotel.test' ) && mails.includes( 'New booking ' + refA ), 'hotel email: new paid booking' );

	section( 'Cancel on the payment page, then pay again' );
	await toDetails( guest, 20, 22 );
	await fillGuest( guest, 'Back Guest', 'back@example.com' );
	await Promise.all( [ guest.waitForURL( /localhost:12111\/pay\// ), guest.click( '.fb-details [type=submit]' ) ] );
	const refB = ( await guest.textContent( '.product' ) ).match( /FB-[A-Z0-9]+/ )[ 0 ];
	await Promise.all( [ guest.waitForURL( /fb_payment=cancel/ ), guest.click( '#cancel' ) ] );
	await guest.waitForSelector( '.fb-success[data-state=held]' );
	ok( ( await text( guest, '.fb-success' ) ).includes( 'reserved for you until' ), 'room still reserved, with the time' );
	ok( await guest.isVisible( '.fb-success .fb-button:not(.fb-button--ghost)' ), '"Pay now" offered' );
	await Promise.all( [ guest.waitForURL( /localhost:12111\/pay\// ), guest.click( '.fb-success .fb-button:not(.fb-button--ghost)' ) ] );
	await Promise.all( [ guest.waitForURL( /fb_payment=return/ ), guest.click( '#pay' ) ] );
	await guest.waitForSelector( '.fb-success[data-state=confirmed]', { timeout: 20000 } );
	ok( booking( refB ).status === 'confirmed', 'paid on the second try: confirmed' );

	section( 'Declined card, then the page expires' );
	await toDetails( guest, 30, 32 );
	await fillGuest( guest, 'Declined Guest', 'declined@example.com' );
	await Promise.all( [ guest.waitForURL( /localhost:12111\/pay\// ), guest.click( '.fb-details [type=submit]' ) ] );
	const refC = ( await guest.textContent( '.product' ) ).match( /FB-[A-Z0-9]+/ )[ 0 ];
	const sessionC = guest.url().split( '/pay/' )[ 1 ];
	await guest.click( '#decline' );
	await guest.waitForSelector( '.declined' );
	ok( booking( refC ).status === 'pending_payment', 'declined card: still held, guest can try another card' );
	execSync( `curl -s -X POST ${ MOCK }/_control/expire/${ sessionC }` );
	const bC = booking( refC );
	ok( bC.status === 'expired' && bC.payment_status === 'failed', 'page expired after a decline: payment failed, room released' );
	await guest.goto( `${ BASE }/booking/?fb_payment=cancel&fb_ref=${ refC }&fb_key=wrong` );
	await guest.waitForSelector( '.fb-success .is-error' );
	ok( ( await text( guest, '.fb-success' ) ).includes( 'Booking not found' ), 'wrong key: nothing shown' );
	ok( await guest.isVisible( '.fb-success .fb-button--ghost' ), '"Search again" offered' );
	await guest.click( '.fb-success .fb-button--ghost' );
	ok( await guest.isVisible( '.fb-search' ) && ! guest.url().includes( 'fb_ref' ), 'back to the search' );

	section( 'Bank transfer on a phone (390px)' );
	const phone = await browser.newPage( { viewport: { width: 390, height: 844 } } );
	phone.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await toDetails( phone, 40, 43 );
	await phone.check( '[name=payment_method][value=bank_transfer]' );
	await fillGuest( phone, 'Bank Guest', 'bank@example.com' );
	await layoutChecks( phone, 390, 'details' );
	await phone.evaluate( () => window.scrollTo( 0, document.querySelector( '.fb-details' ).offsetTop ) );
	const bar = await phone.evaluate( () => { const b = document.querySelector( '.fb-details .fb-actions' ).getBoundingClientRect(); return { bottom: b.bottom, top: b.top, vh: window.innerHeight }; } );
	ok( bar.bottom <= bar.vh + 1 && bar.top < bar.vh, 'total and button stay in view (sticky bar)' );
	ok( ( await text( phone, '.fb-actions__total' ) ).includes( 'To pay now' ) && ( await text( phone, '.fb-actions__total' ) ).includes( '180.00 €' ), 'sticky bar shows the amount to pay now (180)' );
	await phone.screenshot( { path: SHOTS + '/d5-bank-details-390.png' } );
	await phone.click( '.fb-details [type=submit]' );
	await phone.waitForSelector( '.fb-success:not([hidden]) .fb-bank' );
	const bank = await text( phone, '.fb-success' );
	ok( bank.includes( 'please pay 180.00 € by bank transfer' ), 'success: pay 180 by bank transfer' );
	ok( bank.includes( 'BG80 BNBG 9661 1020 3456 78' ) && bank.includes( 'Hotel Sunrise Ltd.' ) && bank.includes( 'BNBGBGSD' ), 'IBAN, beneficiary, BIC' );
	const refD = ( await phone.textContent( '.fb-success__reference strong' ) ).trim();
	ok( ( await text( phone, '.fb-bank__row--reference' ) ).includes( refD ), 'payment reference = booking reference' );
	ok( ( await phone.$$( '.fb-bank__copy' ) ).length === 3, 'copy buttons for amount, IBAN and reference' );
	await layoutChecks( phone, 390, 'bank details' );
	ok( ( await phone.evaluate( () => getComputedStyle( document.querySelector( '.fb-success__message' ) ).textAlign ) ) === 'center', 'result message centred' );
	await phone.screenshot( { path: SHOTS + '/d5-bank-success-390.png', fullPage: true } );
	ok( booking( refD ).status === 'awaiting_payment', 'booking awaiting payment' );

	section( 'Responsive: 360 / 390 / 414 / 768 / 1024 / 1280' );
	for ( const width of [ 360, 390, 414, 768, 1024, 1280 ] ) {
		const p = await browser.newPage( { viewport: { width, height: width < 768 ? 800 : 1000 } } );
		p.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await p.goto( `${ BASE }/booking/` );
		await p.waitForSelector( '.fb-search' );
		await layoutChecks( p, width, 'search' );
		await p.screenshot( { path: `${ SHOTS }/d5-${ width }-1-search.png`, fullPage: true } );
		await p.goto( `${ BASE }/booking/?check_in=${ day( 50 ) }&check_out=${ day( 53 ) }&adults=2&children=0` );
		await p.waitForSelector( '.fb-room' );
		await layoutChecks( p, width, 'results' );
		const card = await p.evaluate( () => {
			const side = document.querySelector( '.fb-room__side' );
			return { cols: getComputedStyle( document.querySelector( '.fb-room' ) ).gridTemplateColumns.split( ' ' ).length, align: getComputedStyle( side ).textAlign };
		} );
		if ( width > 1024 ) {
			ok( card.cols >= 2 && card.align === 'right', '1280px: desktop room card unchanged (price column on the right)' );
		} else {
			ok( card.cols === 1 && card.align === 'center', `${ width }px: room card in one centred column` );
		}
		await p.screenshot( { path: `${ SHOTS }/d5-${ width }-2-results.png`, fullPage: true } );
		await p.locator( '.fb-room', { hasText: 'Garden Room' } ).locator( '.fb-room__side .fb-button' ).click();
		await p.waitForSelector( '.fb-plans' );
		await layoutChecks( p, width, 'rate plans' );
		await p.screenshot( { path: `${ SHOTS }/d5-${ width }-3-plans.png`, fullPage: true } );
		await p.locator( '.fb-plan', { hasText: 'Breakfast Included' } ).locator( '.fb-button' ).click();
		await p.waitForSelector( '.fb-details:not([hidden])' );
		await p.click( '.fb-promo__toggle' );
		await layoutChecks( p, width, 'details' );
		if ( width <= 1024 ) {
			const sum = await p.evaluate( () => {
				const b = document.querySelector( '.fb-summary' ).getBoundingClientRect();
				const f = document.querySelector( '.fb-details' ).getBoundingClientRect();
				return Math.abs( ( b.left - f.left ) - ( f.right - b.right ) );
			} );
			ok( sum <= 2, `${ width }px: price summary centred` );
		}
		await p.screenshot( { path: `${ SHOTS }/d5-${ width }-4-details.png`, fullPage: true } );
		await p.close();
	}

	section( 'Booking requests: no card payment' );
	wp( 'update_option( "flexo_booking_settings", array_merge( Flexo_Booking_Settings::all(), array( "booking_mode" => "request" ) ) );' );
	await toDetails( guest, 60, 62 );
	ok( ( await guest.$$( '[name=payment_method][value=stripe]' ) ).length === 0, 'card not offered' );
	ok( ( await guest.textContent( '.fb-details [type=submit]' ) ).trim() === 'Send booking request', 'button: Send booking request' );
	await fillGuest( guest, 'Request Guest', 'request@example.com' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	ok( ( await text( guest, '.fb-success' ) ).includes( 'Once we confirm it, we will email you the bank details' ), 'request: bank details follow after confirmation' );
	const refE = ( await guest.textContent( '.fb-success__reference strong' ) ).trim();
	wp( 'update_option( "flexo_booking_settings", array_merge( Flexo_Booking_Settings::all(), array( "booking_mode" => "instant" ) ) );' );

	section( 'Minimal package: only booking requests' );
	wp( 'Flexo_Booking_Features::set_available( array( "booking_request" ) );' );
	const mini = await browser.newPage( { viewport: { width: 390, height: 844 } } );
	mini.on( 'pageerror', ( e ) => errors.push( e.message ) );
	await mini.goto( `${ BASE }/booking/?check_in=${ day( 70 ) }&check_out=${ day( 72 ) }&adults=2&children=0` );
	await mini.waitForSelector( '.fb-room' );
	ok( ( await mini.$$( '.fb-ages, .fb-plans' ) ).length === 0 || await mini.isHidden( '.fb-ages' ), 'no ages or rate plans' );
	await mini.locator( '.fb-room', { hasText: 'Garden Room' } ).locator( '.fb-room__side .fb-button' ).click();
	await mini.waitForSelector( '.fb-details:not([hidden])' );
	ok( ( await mini.$$( '.fb-payment, .fb-promo, [name=privacy_consent], [data-fb-invoice]' ) ).length === 0, 'no payment choice, promo code, consent or invoice fields' );
	const miniSummary = await text( mini, '.fb-summary' );
	ok( ! /pay now|property|Deposit|Rate/i.test( miniSummary ), 'summary without payment or rate lines: ' + miniSummary );
	ok( ( await mini.textContent( '.fb-details [type=submit]' ) ).trim() === 'Send booking request', 'button: Send booking request' );
	await fillGuest( mini, 'Mini Guest', 'mini@example.com' );
	await mini.click( '.fb-details [type=submit]' );
	await mini.waitForSelector( '.fb-success:not([hidden])' );
	ok( ( await text( mini, '.fb-success' ) ).includes( 'We received your booking request' ) && ( await mini.$$( '.fb-bank' ) ).length === 0, 'request sent, nothing about payment' );
	await layoutChecks( mini, 390, 'minimal success' );
	await mini.screenshot( { path: SHOTS + '/d5-minimal-390.png', fullPage: true } );
	wp( 'Flexo_Booking_Features::set_available( null );' );

	section( 'Admin: payments' );
	// Admin pages: Elementor's own admin scripts are not built in this test
	// checkout, so JavaScript errors are only counted on the guest pages.
	const admin = await login( browser, 'admin' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const list = await text( admin, '.flexo-bookings-table' );
	ok( list.includes( 'Deposit received' ) && list.includes( 'Awaiting deposit' ), 'bookings list shows payment status' );
	ok( list.includes( 'Not paid (expired)' ) && list.includes( 'Payment failed' ), 'failed payment visible' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&booking=' + bA.id );
	const card = await text( admin, '#flexo-payments' );
	ok( card.includes( 'Card (Stripe)' ) && card.includes( 'Deposit received' ) && card.includes( 'Paid 240.00 €' ) && card.includes( 'Remaining balance 560.00 €' ), 'booking page: method, status, paid, balance' );
	ok( /pi_test_[a-f0-9]+/.test( card ), 'Stripe transaction ID in the history' );
	ok( ( await admin.getAttribute( '#flexo-payments a[href*="dashboard.stripe.com/test/payments/pi_"]', 'href' ) ) !== null, 'link to the payment in Stripe (test mode)' );
	await admin.screenshot( { path: SHOTS + '/d5-admin-booking.png', fullPage: true } );
	const bD = booking( refD );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&booking=' + bD.id );
	ok( ( await admin.inputValue( '#flexo-payments [name=amount]' ) ) === '180', '"Payment received" prefilled with 180' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-payments .flexo-payment-form .button-primary' ) ] );
	ok( ( await text( admin, '.notice-success' ) ).includes( 'Payment recorded' ), 'payment recorded' );
	ok( booking( refD ).status === 'confirmed', 'bank transfer booking confirmed' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&booking=' + booking( refE ).id );
	ok( ( await admin.textContent( '.flexo-actions .button-primary' ) ).includes( 'Confirm & ask for payment' ), 'request: "Confirm & ask for payment"' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '.flexo-actions .button-primary' ) ] );
	ok( booking( refE ).status === 'awaiting_payment', 'accepted: waits for the transfer' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-calendar&month=' + day( 60 ).slice( 0, 7 ) );
	ok( ( await admin.content() ).includes( 'fbc-item--awaiting_payment' ), 'calendar shows the booking awaiting payment' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=payments' );
	const tab = await text( admin, '.wrap' );
	ok( tab.includes( 'Test mode' ) && tab.includes( 'Ready – test mode (no real money)' ), 'settings: test mode, ready' );
	ok( ! ( await admin.content() ).includes( 'sk_test_e2e' ), 'secret key never printed' );
	await admin.screenshot( { path: SHOTS + '/d5-admin-settings.png', fullPage: true } );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const [ download ] = await Promise.all( [ admin.waitForEvent( 'download' ), admin.click( 'a[href*="flexo_booking_csv"]' ) ] );
	const csv = require( 'fs' ).readFileSync( await download.path(), 'utf8' );
	ok( csv.includes( '"Payment method","Payment status","Due when booking",Paid,Refunded,Balance,"Payment deadline","Transaction IDs"' ), 'CSV has the payment columns' );
	ok( new RegExp( 'stripe,deposit_paid,240,240,,560,,pi_test_' ).test( csv ), 'CSV row: stripe, deposit received, 240 paid, 560 balance, transaction ID' );

	ok( errors.length === 0, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( '; ' ) : '' ) );
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	await browser.close();
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
