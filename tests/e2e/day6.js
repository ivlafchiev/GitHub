/**
 * Day 6 browser tests: the guest flow (steps, date picker, address state,
 * back/refresh, no double booking, nothing-free help and enquiry,
 * keyboard only, labels, contrast, add to calendar, guest booking page),
 * phone and desktop widths, a minimal setup, the hotel's screens (Today,
 * list filters and quick actions, history, notes, resend, roles, phone),
 * and Appearance (upgrade keeps "Match", preview, contrast warning, reset,
 * own fonts only, Cyrillic, priority with Elementor).
 *
 *   wp eval-file tests/e2e/seed-day6.php      → prints D0
 *   BASE=http://localhost:8092 D0=<D0> SHOTS=/tmp/shots MAIL_LOG=<site>/wp-content/mail.log \
 *   WP="php wp-cli.phar --allow-root --path=<site>" node tests/e2e/day6.js
 *
 * Users: admin/admin, staff/staff (Hotel Staff), manager/manager (Hotel Manager).
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const { execSync } = require( 'child_process' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const MAIL_LOG = process.env.MAIL_LOG;
const WP = process.env.WP;
const D0 = new Date( process.env.D0 + 'T12:00:00Z' );
const day = ( n ) => { const d = new Date( D0 ); d.setUTCDate( d.getUTCDate() + n ); return d.toISOString().slice( 0, 10 ); };

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };
const section = ( t ) => console.log( '\n== ' + t );
// Output between markers (other plugins may print notices around it).
const wp = ( php ) => {
	const code = 'ob_start(); ' + php + ' $flexo_out = ob_get_clean(); echo "@@FLEXO@@" . $flexo_out . "@@FLEXO@@";';
	const out = execSync( `${ WP } eval '${ code.replace( /'/g, "'\\''" ) }' 2>/dev/null` ).toString();
	const m = /@@FLEXO@@([\s\S]*?)@@FLEXO@@/.exec( out );
	return m ? m[ 1 ].trim() : '';
};
const mails = () => ( fs.existsSync( MAIL_LOG ) ? fs.readFileSync( MAIL_LOG, 'utf8' ).trim().split( '\n' ).filter( Boolean ).map( ( l ) => JSON.parse( l ) ) : [] );
const bookingCount = () => parseInt( wp( 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM " . Flexo_Booking_Install::table() );' ), 10 );

async function login( browser, user, width = 1400 ) {
	const p = await browser.newPage( { viewport: { width, height: 1000 } } );
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', user );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	return p;
}
const noOverflow = ( p ) => p.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 );
const text = async ( p, sel ) => ( await p.innerText( sel ) ).replace( /\s+/g, ' ' );

async function fillGuest( p, name, email ) {
	await p.fill( '[name=guest_name]', name );
	await p.fill( '[name=guest_email]', email );
	await p.fill( '[name=guest_phone]', '888 123 456' );
	const consent = await p.$( '.fb-details [name=privacy_consent]' );
	if ( consent ) {
		await consent.check();
	}
}

/** WCAG contrast of an element's text on its background. */
const contrastOf = ( p, sel ) => p.evaluate( ( s ) => {
	const el = document.querySelector( s );
	if ( ! el ) {
		return 0;
	}
	const rgb = ( c ) => ( c.match( /[\d.]+/g ) || [ 0, 0, 0 ] ).slice( 0, 3 ).map( Number );
	const lum = ( c ) => {
		const v = rgb( c ).map( ( x ) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 ); } );
		return 0.2126 * v[ 0 ] + 0.7152 * v[ 1 ] + 0.0722 * v[ 2 ];
	};
	let bgEl = el;
	let bg = getComputedStyle( bgEl ).backgroundColor;
	while ( bgEl && ( /rgba\(.*, 0\)$/.test( bg ) || bg === 'transparent' ) ) {
		bgEl = bgEl.parentElement;
		bg = bgEl ? getComputedStyle( bgEl ).backgroundColor : 'rgb(255,255,255)';
	}
	const a = lum( getComputedStyle( el ).color ), b = lum( bg );
	return ( Math.max( a, b ) + 0.05 ) / ( Math.min( a, b ) + 0.05 );
}, sel );

( async () => {
	const browser = await chromium.launch();
	const errors = [];

	/* ------------------------------------------------------------------ */
	section( 'Guest: steps, picker, address state (phone)' );
	const g = await browser.newPage( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, locale: 'en-GB' } );
	g.on( 'pageerror', ( e ) => errors.push( 'guest: ' + e.message ) );
	await g.goto( BASE + '/booking/' );
	ok( ( await g.$$( '.fb-steps__item' ) ).length >= 4 && ( await g.getAttribute( '.fb-steps__item[aria-current=step]', 'data-step' ) ) === 'dates', 'step bar, "Dates" is the current step' );
	await g.click( '.fb-search [type=submit]' );
	ok( ( await text( g, '.fb-search' ) ).includes( 'Please choose your check-in and check-out dates.' ), 'missing dates explained under the field' );
	await g.click( '.fb-date' );
	await g.waitForSelector( '.fb-picker:not([hidden]) .fb-day' );
	ok( await g.evaluate( () => document.activeElement.classList.contains( 'fb-day' ) ), 'picker opens with focus on a day' );
	// Navigate to D0+10 with the keyboard.
	const from = await g.evaluate( () => document.activeElement.getAttribute( 'data-date' ) );
	const steps = Math.round( ( new Date( day( 10 ) ) - new Date( from ) ) / 86400000 );
	for ( let i = 0; i < steps; i++ ) {
		await g.keyboard.press( 'ArrowRight' );
	}
	await g.keyboard.press( 'Enter' );
	const pickStatus = await g.textContent( '.fb-picker__status' );
	ok( pickStatus.includes( 'Now choose your check-out date' ), 'after check-in: asks for check-out (' + pickStatus + ', from ' + from + ', ' + steps + ' steps)' );
	// Focus is already on the first possible check-out (arrival + minimum stay).
	ok( ( await g.evaluate( () => document.activeElement.getAttribute( 'data-date' ) ) ) === day( 11 ), 'focus moves to the first possible check-out' );
	await g.keyboard.press( 'ArrowRight' );
	await g.keyboard.press( 'Enter' );
	ok( ( await g.inputValue( '[name=check_in]' ) ) === day( 10 ) && ( await g.inputValue( '[name=check_out]' ) ) === day( 12 ), 'dates written into the form fields' );
	await g.click( '.fb-search [type=submit]' );
	await g.waitForSelector( '.fb-room' );
	ok( g.url().includes( 'check_in=' + day( 10 ) ) && g.url().includes( 'check_out=' + day( 12 ) ), 'dates in the address' );
	ok( await g.isVisible( '.fb-stay' ) && ( await g.textContent( '.fb-stay' ) ).includes( '2 nights' ), 'stay line with "Change"' );
	const card = await text( g, '.fb-room:has-text("Garden Family")' );
	ok( card.includes( '36 m²' ) && card.includes( '1 double + 2 single' ) && card.includes( 'Up to 4 guests' ) && card.includes( 'Free Wi-Fi' ) && card.includes( '+2 more' ), 'room card: size, beds, guests, 5 amenities + more' );
	ok( await noOverflow( g ), 'results fit 390 px' );
	await g.locator( '.fb-room', { hasText: 'Garden Family' } ).locator( '.fb-room__side .fb-button' ).click();
	const plans = await text( g, '.fb-plans' );
	ok( plans.includes( 'Breakfast included · ✓' ) && plans.includes( 'No meals · ✕ Non-refundable' ), 'rates compared: meals and cancellation in one line' );
	await g.locator( '.fb-plan', { hasText: 'Bed & Breakfast' } ).locator( '.fb-button' ).click();
	await g.waitForSelector( '.fb-details:not([hidden])' );
	ok( g.url().includes( 'fb_step=details' ) && g.url().includes( 'fb_room=garden-family' ), 'step and room in the address (no personal data)' );
	ok( await g.isVisible( '.fb-aside__toggle' ) && ! ( await g.isVisible( '.fb-aside .fb-summary' ) ), 'phone: summary is a collapsed bar' );
	await g.click( '.fb-aside__toggle' );
	ok( await g.isVisible( '.fb-aside .fb-summary' ), 'tap opens the price details' );
	await g.click( '.fb-aside__toggle' );
	ok( ( await g.getAttribute( '[name=guest_email]', 'type' ) ) === 'email' && ( await g.getAttribute( '[name=guest_phone]', 'inputmode' ) ) === 'tel' && ( await g.getAttribute( '[name=guest_name]', 'autocomplete' ) ) === 'name', 'mobile keyboards and autocomplete' );
	ok( ( await text( g, '.fb-details' ) ).includes( 'Special requests (optional)' ), 'optional fields marked' );
	ok( ( await g.textContent( '.fb-phone__code' ) ).trim() === '+359', 'phone country code defaults to Bulgaria' );
	await g.click( '.fb-details [type=submit]' );
	const errs = await g.$$eval( '.fb-details .fb-field__error', ( e ) => e.map( ( x ) => x.textContent ) );
	ok( errs.includes( 'Please enter your full name.' ) && errs.includes( 'Please enter your email address.' ), 'inline messages under the fields' );
	ok( await g.evaluate( () => document.activeElement.name === 'guest_name' ), 'focus on the first field with a problem' );
	const top = await g.evaluate( () => document.activeElement.getBoundingClientRect().top );
	ok( top > 60, 'the field is not hidden behind the sticky bar (' + Math.round( top ) + 'px)' );
	await g.fill( '[name=guest_email]', 'maria@' );
	await g.press( '[name=guest_email]', 'Tab' );
	ok( ( await text( g, '.fb-details' ) ).includes( 'like name@example.com' ), 'friendly email hint while typing' );
	const before = await text( g, '.fb-before' );
	ok( before.includes( 'Cancellation: Free cancellation up to 7 days' ) && before.includes( '+359 52 111 222' ), '"Before you book": cancellation and hotel contact' );
	await fillGuest( g, 'Maria Ivanova', 'maria@example.com' );
	ok( /Continue to secure payment|Confirm and pay|Confirm booking/.test( await g.textContent( '.fb-details [type=submit]' ) ), 'final button says what happens: ' + ( await g.textContent( '.fb-details [type=submit]' ) ) );

	section( 'Guest: refresh, Back, Forward' );
	await g.reload();
	await g.waitForSelector( '.fb-details:not([hidden])' );
	ok( true, 'refresh keeps the details step' );
	ok( ( await g.inputValue( '[name=guest_name]' ) ) === '', 'personal details are not kept in the browser' );
	await g.goBack();
	await g.waitForTimeout( 800 );
	ok( await g.isVisible( '.fb-results' ) && ! ( await g.isVisible( '.fb-details' ) ), 'Back: room list' );
	await g.goForward();
	await g.waitForTimeout( 800 );
	ok( await g.isVisible( '.fb-details' ), 'Forward: details again' );

	section( 'Guest: no double booking, confirmation, calendar, guest page' );
	await fillGuest( g, 'Maria Ivanova', 'maria@example.com' );
	const bank = await g.$( '[name=payment_method][value=bank_transfer]' );
	if ( bank ) {
		await bank.check();
	}
	const count0 = bookingCount();
	await g.evaluate( () => { const b = document.querySelector( '.fb-details [type=submit]' ); b.click(); b.click(); document.querySelector( '.fb-details' ).requestSubmit(); } );
	await g.waitForSelector( '.fb-done__title', { timeout: 15000 } );
	const count1 = bookingCount();
	ok( count1 === count0 + 1, `three quick submits → one booking (${ count0 } → ${ count1 })` );
	const done = await text( g, '.fb-success' );
	ok( /FB-[A-Z0-9]{6}/.test( done ) && done.includes( 'What happens next' ) && done.includes( 'Contact' ) && done.includes( 'Bed & Breakfast' ), 'confirmation: reference, full summary, next steps, contact' );
	// 1.9.0 (decision 3): the guest key stays in the tab, not in the address.
	ok( g.url().includes( 'fb_done=' ) && ! g.url().includes( 'fb_key=' ), 'confirmation address survives a refresh, without the guest key' );
	await g.reload();
	await g.waitForSelector( '.fb-done__title' );
	ok( ( await text( g, '.fb-success' ) ).includes( 'Bed & Breakfast' ), 'confirmation shown again after refresh' );
	const icsUrl = await g.getAttribute( '.fb-done__ics', 'href' );
	const ics = await g.request.get( icsUrl );
	const icsText = await ics.text();
	ok( ics.headers()[ 'content-type' ].includes( 'text/calendar' ) && icsText.includes( 'DTSTART;VALUE=DATE:' + day( 10 ).replace( /-/g, '' ) ), 'Add to calendar: .ics for the stay' );
	ok( ( await g.getAttribute( '.fb-done__directions', 'href' ) || '' ).includes( 'google.com/maps' ), 'directions link' );
	ok( await noOverflow( g ), 'confirmation fits 390 px' );
	await g.screenshot( { path: SHOTS + '/d6-done-390.png', fullPage: true } );
	const manage = await g.getAttribute( '.fb-done__manage', 'href' );
	ok( !! manage && manage.includes( 'fb_manage=' ), 'link to the guest booking page' );
	await g.goto( manage );
	await g.waitForSelector( '.fb-manage__form' );
	ok( ( await text( g, '.fb-success' ) ).includes( 'Your booking' ), 'guest booking page opens' );
	await g.check( '.fb-manage__form [value=cancel]' );
	await g.fill( '.fb-manage__form [name=message]', 'Our plans changed.' );
	fs.writeFileSync( MAIL_LOG, '' );
	await g.click( '.fb-manage__form [type=submit]' );
	await g.waitForSelector( '.fb-manage__done' );
	ok( mails().some( ( m ) => m.subject.includes( 'Cancellation request for booking' ) && [].concat( m.to ).includes( 'reception@sunrise.test' ) ), 'cancellation request reaches the hotel' );
	const refDone = /FB-[A-Z0-9]{6}/.exec( done )[ 0 ];
	ok( wp( `echo Flexo_Booking_Bookings::get_by_reference( "${ refDone }" )["status"];` ) !== 'cancelled', 'the booking is not cancelled automatically' );
	const badKey = await g.request.get( BASE + '/wp-json/flexo-booking/v1/guest-booking?reference=' + refDone + '&key=nope' );
	ok( badKey.status() === 404, 'wrong key: not found' );

	section( 'Guest: nothing free → nearby dates, other rooms, enquiry' );
	await g.goto( `${ BASE }/booking/?check_in=${ day( 40 ) }&check_out=${ day( 42 ) }&adults=2` );
	await g.waitForSelector( '.fb-nothing' );
	await g.waitForSelector( '.fb-alt', { timeout: 10000 } ).catch( () => {} );
	const nothing = await text( g, '.fb-nothing' );
	ok( nothing.includes( 'Nothing is free for these dates' ) && nothing.includes( 'closed' ), 'plain reason' );
	ok( ( await g.$$( '.fb-alt' ) ).length >= 1, 'nearby dates offered: ' + ( await g.$$( '.fb-alt' ) ).length );
	await g.click( '.fb-nothing__ask .fb-button' );
	await g.fill( '.fb-enquiry [name=name]', 'Ivan Petrov' );
	await g.fill( '.fb-enquiry [name=email]', 'ivan@example.com' );
	await g.fill( '.fb-enquiry [name=message]', 'Any room around these dates?' );
	const enqConsent = await g.$( '.fb-enquiry [name=privacy_consent]' );
	if ( enqConsent ) {
		await enqConsent.check();
	}
	fs.writeFileSync( MAIL_LOG, '' );
	await g.click( '.fb-enquiry [type=submit]' );
	await g.waitForSelector( '.fb-enquiry__done' );
	ok( mails().some( ( m ) => m.subject.includes( 'Enquiry from Ivan Petrov' ) ), 'enquiry emailed to the hotel' );
	await g.click( '.fb-alt' );
	await g.waitForSelector( '.fb-room:not(.is-unavailable)' );
	ok( true, 'a nearby date shows free rooms' );
	await g.goto( `${ BASE }/booking/?room=garden-family&check_in=${ day( 10 ) }&check_out=${ day( 12 ) }&adults=2` );
	await g.waitForSelector( '.fb-nothing' );
	await g.waitForSelector( '.fb-show-all', { timeout: 10000 } );
	ok( ( await g.textContent( '.fb-show-all' ) ).includes( 'other room' ), 'booked room from a "Book now" link: other rooms offered' );

	/* ------------------------------------------------------------------ */
	section( 'Guest: keyboard only, labels, contrast (desktop)' );
	const k = await browser.newPage( { viewport: { width: 1280, height: 900 }, locale: 'en-GB' } );
	k.on( 'pageerror', ( e ) => errors.push( 'keyboard: ' + e.message ) );
	await k.goto( BASE + '/booking/' );
	const tabTo = async ( pred, max = 60 ) => {
		for ( let i = 0; i < max; i++ ) {
			await k.keyboard.press( 'Tab' );
			if ( await k.evaluate( pred ) ) {
				return true;
			}
		}
		return false;
	};
	ok( await tabTo( () => document.activeElement.classList.contains( 'fb-date' ) ), 'Tab reaches the check-in field' );
	await k.keyboard.press( 'Enter' );
	await k.waitForSelector( '.fb-picker:not([hidden])' );
	const kFrom = await k.evaluate( () => document.activeElement.getAttribute( 'data-date' ) );
	for ( let i = 0; i < Math.round( ( new Date( day( 60 ) ) - new Date( kFrom ) ) / 86400000 ); i++ ) {
		await k.keyboard.press( 'ArrowRight' );
	}
	await k.keyboard.press( 'Enter' );
	await k.keyboard.press( 'Enter' );
	ok( ( await k.inputValue( '[name=check_out]' ) ) === day( 61 ), 'dates chosen with arrow keys and Enter' );
	ok( await tabTo( () => document.activeElement.type === 'submit' && !! document.activeElement.closest( '.fb-search' ) ), 'Tab reaches "Check availability"' );
	await k.keyboard.press( 'Enter' );
	await k.waitForSelector( '.fb-room' );
	ok( await k.evaluate( () => document.activeElement.classList.contains( 'fb-results__title' ) ), 'focus moves to the room list heading' );
	ok( await tabTo( () => /^Select this room:/.test( document.activeElement.getAttribute( 'aria-label' ) || '' ) ), 'Tab reaches "Select this room"' );
	await k.keyboard.press( 'Enter' );
	await k.waitForSelector( '.fb-details:not([hidden])' );
	ok( await k.evaluate( () => document.activeElement.classList.contains( 'fb-step-title' ) ), 'focus moves to "Your details"' );
	await k.keyboard.press( 'Tab' );
	await k.keyboard.type( 'Keyboard User' );
	await k.keyboard.press( 'Tab' );
	await k.keyboard.type( 'keys@example.com' );
	ok( await tabTo( () => document.activeElement.name === 'guest_phone' ), 'Tab to the phone' );
	await k.keyboard.type( '888 999 000' );
	ok( await tabTo( () => document.activeElement.name === 'privacy_consent' ), 'Tab to the consent' );
	await k.keyboard.press( 'Space' );
	ok( await tabTo( () => document.activeElement.type === 'submit' && !! document.activeElement.closest( '.fb-details' ) ), 'Tab to the final button' );
	await k.keyboard.press( 'Enter' );
	await k.waitForSelector( '.fb-done__title', { timeout: 15000 } );
	ok( await k.evaluate( () => document.activeElement.classList.contains( 'fb-done__title' ) ), 'booked with the keyboard only; focus on the confirmation' );
	await k.goto( `${ BASE }/booking/?check_in=${ day( 70 ) }&check_out=${ day( 72 ) }&adults=2` );
	await k.waitForSelector( '.fb-room' );
	await k.locator( '.fb-room', { hasText: 'Sea Double' } ).locator( '.fb-room__side .fb-button' ).click();
	await k.waitForSelector( '.fb-details:not([hidden])' );
	const unlabeled = await k.evaluate( () => [ ...document.querySelectorAll( '.flexo-booking input, .flexo-booking select, .flexo-booking textarea, .flexo-booking button' ) ]
		.filter( ( el ) => el.offsetParent !== null && el.type !== 'hidden' && ! el.closest( '.fb-hp' ) && ! el.classList.contains( 'fb-native-date' ) )
		.filter( ( el ) => ! ( el.labels && el.labels.length ) && ! el.getAttribute( 'aria-label' ) && ! el.getAttribute( 'aria-labelledby' ) && ! el.textContent.trim() )
		.map( ( el ) => el.name || el.className ) );
	ok( unlabeled.length === 0, 'every field and button has a name for screen readers' + ( unlabeled.length ? ': ' + unlabeled.join( ', ' ) : '' ) );
	ok( ( await k.getAttribute( '.fb-steps__item[aria-current=step]', 'data-step' ) ) === 'details' && await k.$( '.flexo-booking [aria-live=polite]' ), 'current step marked, changes announced' );
	const cText = await contrastOf( k, '.fb-step-title' );
	const cBtn = await contrastOf( k, '.fb-details [type=submit]' );
	ok( cText >= 4.5 && cBtn >= 4.5, `contrast AA: text ${ cText.toFixed( 1 ) }:1, button ${ cBtn.toFixed( 1 ) }:1` );
	ok( await k.isVisible( '.fb-aside .fb-summary' ), 'desktop: summary open next to / above the form' );

	/* ------------------------------------------------------------------ */
	section( 'Layout at 360 / 414 / 768 / 1024 / 1280' );
	for ( const width of [ 360, 414, 768, 1024, 1280 ] ) {
		const p = await browser.newPage( { viewport: { width, height: 900 }, locale: 'en-GB' } );
		p.on( 'pageerror', ( e ) => errors.push( width + ': ' + e.message ) );
		await p.goto( `${ BASE }/booking/?check_in=${ day( 80 ) }&check_out=${ day( 83 ) }&adults=2` );
		await p.waitForSelector( '.fb-room' );
		const r1 = await noOverflow( p );
		await p.locator( '.fb-room', { hasText: 'Sea Double' } ).locator( '.fb-room__side .fb-button' ).click();
		await p.waitForSelector( '.fb-details:not([hidden])' );
		const r2 = await noOverflow( p );
		const imgShift = await p.evaluate( () => [ ...document.querySelectorAll( '.fb-room__image' ) ].every( ( i ) => getComputedStyle( i ).aspectRatio !== 'auto' ) );
		ok( r1 && r2, `${ width }px: rooms and details without horizontal scrolling` );
		ok( imgShift, `${ width }px: room photos keep their space (no layout shift)` );
		await p.screenshot( { path: `${ SHOTS }/d6-${ width }-details.png`, fullPage: true } );
		await p.close();
	}

	section( 'Minimal setup (only booking requests)' );
	const savedFeatures = wp( 'echo wp_json_encode( get_option( Flexo_Booking_Features::ENABLED_OPTION ) );' );
	wp( 'Flexo_Booking_Features::set_available( array( "booking_request" ) ); Flexo_Booking_Features::set_enabled( array( "booking_request" ) );' );
	const m = await browser.newPage( { viewport: { width: 390, height: 844 }, locale: 'en-GB' } );
	m.on( 'pageerror', ( e ) => errors.push( 'minimal: ' + e.message ) );
	await m.goto( `${ BASE }/booking/?check_in=${ day( 90 ) }&check_out=${ day( 92 ) }&adults=2` );
	await m.waitForSelector( '.fb-room' );
	await m.locator( '.fb-room', { hasText: 'Sea Double' } ).locator( '.fb-room__side .fb-button' ).click();
	await m.waitForSelector( '.fb-details:not([hidden])' );
	const minLabel = ( await m.textContent( '.fb-details [type=submit]' ) ).trim();
	ok( minLabel === 'Send booking request', 'request mode: "Send booking request" (' + minLabel + ')' );
	await m.fill( '[name=guest_name]', 'Minimal Guest' );
	await m.fill( '[name=guest_email]', 'min@example.com' );
	await m.fill( '[name=guest_phone]', '888 111 000' );
	await m.click( '.fb-details [type=submit]' );
	await m.waitForSelector( '.fb-done__title' );
	ok( ( await m.textContent( '.fb-done__title' ) ).includes( 'request has been sent' ) && ( await text( m, '.fb-success' ) ).includes( 'within 24 hours' ), 'request sent; reply time explained' );
	ok( ! ( await m.$( '.fb-done__manage' ) ), 'guest booking page off: no link' );
	wp( `Flexo_Booking_Features::set_available( null ); Flexo_Booking_Features::set_enabled( json_decode( '${ savedFeatures }', true ) );` );

	/* ------------------------------------------------------------------ */
	section( 'Hotel: Today, list, details (admin)' );
	wp( 'Flexo_Booking_Bookings::create( array( "room" => "sea-double", "check_in" => wp_date( "Y-m-d" ), "check_out" => wp_date( "Y-m-d", strtotime( "+2 days" ) ), "adults" => 2, "guest_name" => "Arriving Today", "guest_email" => "today@example.com", "guest_phone" => "+359 888 1", "source" => "admin", "status" => "confirmed" ) );' );
	const reqRef = wp( '$b = Flexo_Booking_Bookings::create( array( "room" => "sea-double", "check_in" => "' + day( 100 ) + '", "check_out" => "' + day( 102 ) + '", "adults" => 2, "guest_name" => "Request Guest", "guest_email" => "req@example.com", "guest_phone" => "+359 888 2", "source" => "admin", "status" => "pending" ) ); echo $b["reference"];' );
	const a = await login( browser, 'admin' );
	a.on( 'dialog', ( d ) => d.accept() );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( ( await a.textContent( 'h1' ) ).includes( 'Today' ), 'first screen: Today' );
	ok( ( await text( a, '#flexo-arrivals' + ' ~ *' ).catch( () => '' ) ).includes( 'Arriving Today' ) || ( await text( a, '.flexo-today__grid' ) ).includes( 'Arriving Today' ), 'arriving today listed' );
	const attention = await text( a, '.flexo-today__attention' );
	ok( attention.includes( reqRef ) && attention.includes( 'Enquiry from Ivan Petrov' ) && attention.includes( 'Guest asks' ), 'needs attention: request, enquiry, guest request' );
	const badge = await a.textContent( '#toplevel_page_flexo-booking .awaiting-mod' ).catch( () => '0' );
	ok( parseInt( badge, 10 ) >= 3, 'menu badge counts what needs attention: ' + badge );
	await Promise.all( [ a.waitForNavigation(), a.locator( '.flexo-attention__item', { hasText: reqRef } ).locator( 'a', { hasText: 'Confirm' } ).click() ] );
	ok( wp( `echo Flexo_Booking_Bookings::get_by_reference( "${ reqRef }" )["status"];` ) === 'confirmed', 'one click confirms the request' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&s=' + reqRef );
	ok( a.url().includes( 'page=flexo-booking-list' ), 'old list address opens All bookings' );
	ok( ( await a.$$( '.flexo-bookings-table tbody tr.flexo-booking-row' ) ).length === 1, 'search by reference' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-list&source=website' );
	ok( ! ( await text( a, '.flexo-bookings-table' ) ).includes( 'Request Guest' ), 'filter: website bookings only' );
	await a.goto( BASE + `/wp-admin/admin.php?page=flexo-booking-list&arrival_from=${ day( 99 ) }&arrival_to=${ day( 101 ) }` );
	ok( ( await text( a, '.flexo-bookings-table' ) ).includes( 'Request Guest' ), 'filter: arrival dates' );
	await a.locator( '.flexo-bookings-table tr', { hasText: reqRef } ).locator( 'a', { hasText: 'Cancel' } ).click();
	await a.waitForSelector( 'dialog[open]' );
	ok( ( await a.textContent( 'dialog[open] p' ) ).includes( 'The guest receives a cancellation email' ), 'cancel dialog states the consequence' );
	await Promise.all( [ a.waitForNavigation(), a.click( 'dialog[open] [data-yes]' ) ] );
	ok( wp( `echo Flexo_Booking_Bookings::get_by_reference( "${ reqRef }" )["status"];` ) === 'cancelled', 'quick action: cancelled' );
	const reqId = wp( `echo Flexo_Booking_Bookings::get_by_reference( "${ reqRef }" )["id"];` );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&booking=' + reqId );
	const history = await text( a, '.flexo-history' );
	ok( history.includes( 'Waiting for confirmation → Confirmed' ) && history.includes( 'Confirmed → Cancelled' ) && history.includes( '– admin' ), 'history: who changed what' );
	await a.fill( '#flexo-staff-note', 'Called the guest.' );
	await Promise.all( [ a.waitForNavigation(), a.click( '#flexo-staff-notes [type=submit]' ) ] );
	ok( ( await a.inputValue( '#flexo-staff-note' ) ) === 'Called the guest.' && ( await text( a, '.flexo-history' ) ).includes( 'Staff note: Called the guest.' ), 'internal note saved and logged' );
	fs.writeFileSync( MAIL_LOG, '' );
	await a.click( '.flexo-resend [type=submit]' );
	await a.waitForSelector( 'dialog[open]' );
	await Promise.all( [ a.waitForNavigation(), a.click( 'dialog[open] [data-yes]' ) ] );
	ok( mails().some( ( mm ) => [].concat( mm.to ).includes( 'req@example.com' ) ) && ( await text( a, '.flexo-history' ) ).includes( 'Email sent again' ), 'email resent and logged' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-new' );
	await a.selectOption( '#fb-room', { label: 'Garden Family' } );
	ok( ( await a.$$eval( '#fb-plan option:not([hidden])', ( o ) => o.map( ( x ) => x.textContent ) ) ).join( '|' ) === 'Bed & Breakfast|Saver', 'Add booking: only the room\'s rates, first chosen' );

	section( 'Hotel: health, help, settings' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=health' );
	ok( ( await a.$$( '.flexo-health__item' ) ).length >= 6 && ( await a.inputValue( '#flexo-report' ) ).includes( 'Flexo Booking:' ), 'health checks and system report' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-help#help-transfer' );
	ok( await a.evaluate( () => document.getElementById( 'help-transfer' ).open ), 'help link opens the right guide' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=children' );
	ok( ( await a.textContent( '.nav-tab-active' ) ).includes( 'Booking rules' ), 'old tab address opens the new tab' );

	section( 'Roles' );
	const st = await login( browser, 'staff', 390 );
	ok( st.url().includes( 'page=flexo-booking' ), 'Hotel Staff lands on Today' );
	const stMenu = await st.$$eval( '#toplevel_page_flexo-booking .wp-submenu a', ( e ) => e.filter( ( x ) => getComputedStyle( x.closest( 'li' ) ).display !== 'none' ).map( ( x ) => x.textContent.trim() ) );
	ok( ! stMenu.includes( 'Settings' ) && ! stMenu.includes( 'Rooms & prices' ) && stMenu.includes( 'All bookings' ), 'staff menu: ' + stMenu.join( ', ' ) );
	for ( const pg of [ 'flexo-booking-settings', 'flexo-booking-seasons', 'flexo-booking-emails', 'flexo-booking-tools' ] ) {
		const r = await st.goto( BASE + '/wp-admin/admin.php?page=' + pg );
		ok( r.status() === 403, 'staff blocked: ' + pg );
	}
	await st.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( await noOverflow( st ), 'Today on a phone: no horizontal scroll' );
	await st.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-list' );
	ok( await noOverflow( st ) && ( await st.evaluate( () => getComputedStyle( document.querySelector( '.flexo-bookings-table thead' ) ).display ) ) === 'none', 'bookings as cards on a phone' );
	await st.goto( BASE + '/wp-admin/admin.php?page=flexo-booking&booking=' + reqId );
	ok( await noOverflow( st ) && ( await st.evaluate( () => getComputedStyle( document.querySelector( '.flexo-actionbar' ) ).position ) ) === 'sticky', 'details on a phone: actions stay in reach' );
	await st.screenshot( { path: SHOTS + '/d6-staff-details-390.png', fullPage: true } );
	const mg = await login( browser, 'manager' );
	for ( const [ pg, allowed ] of [ [ 'flexo-booking-closures', true ], [ 'flexo-booking-emails', true ], [ 'flexo-booking-appearance', true ], [ 'flexo-booking-settings', false ], [ 'flexo-booking-tools', false ] ] ) {
		const r = await mg.goto( BASE + '/wp-admin/admin.php?page=' + pg );
		ok( ( r.status() === 200 ) === allowed, `manager ${ allowed ? 'can open' : 'blocked' }: ${ pg }` );
	}
	const newRoom = await mg.goto( BASE + '/wp-admin/post-new.php?post_type=flexo_room' );
	ok( newRoom.status() === 200, 'manager can add a room' );

	/* ------------------------------------------------------------------ */
	section( 'Appearance' );
	ok( wp( 'echo Flexo_Booking_Appearance::mode();' ) === 'match', 'default / upgraded sites: "Match my website"' );
	const front = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const fontRequests = [];
	front.on( 'request', ( r ) => { if ( /\.(woff2?|ttf)(\?|$)/.test( r.url() ) || r.url().includes( 'fonts.g' ) ) { fontRequests.push( r.url() ); } } );
	await front.goto( BASE + '/booking/' );
	const matchBtn = await front.evaluate( () => getComputedStyle( document.querySelector( '.fb-search [type=submit]' ) ).backgroundColor );
	ok( ! ( await front.$( '#flexo-booking-inline-css' ) ), 'Match: no extra CSS on the page' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-appearance' );
	await a.check( '[name$="[appearance_mode]"][value=custom]' );
	await a.fill( '.flexo-color-row:has([name$="[appearance_text]"]) .flexo-color-hex', '#dddddd' );
	await a.fill( '.flexo-color-row:has([name$="[appearance_bg]"]) .flexo-color-hex', '#ffffff' );
	await a.waitForSelector( '.flexo-contrast-warning', { timeout: 5000 } ).catch( () => {} );
	ok( ( await text( a, '.flexo-appearance__warnings' ) ).includes( 'hard to read' ), 'contrast warning with a suggestion' );
	await a.fill( '.flexo-color-row:has([name$="[appearance_text]"]) .flexo-color-hex', '#1f2933' );
	await a.fill( '.flexo-color-row:has([name$="[appearance_primary]"]) .flexo-color-hex', '#1f5f8b' );
	await a.selectOption( '[name$="[appearance_heading_font]"]', 'lora' );
	await a.waitForTimeout( 1200 );
	ok( ( await text( a, '.flexo-appearance__warnings' ) ) === '', 'warning gone with readable colours' );
	const frame = a.frameLocator( '#flexo-appearance-frame' );
	const previewBtn = await frame.locator( '.flexo-booking .fb-button' ).first().evaluate( ( b ) => getComputedStyle( b ).backgroundColor );
	ok( previewBtn === 'rgb(31, 95, 139)', 'live preview uses the new main colour' );
	await Promise.all( [ a.waitForNavigation(), a.click( '#flexo-appearance-form [type=submit]' ) ] );
	await front.goto( BASE + '/booking/' );
	const customBtn = await front.evaluate( () => getComputedStyle( document.querySelector( '.fb-search [type=submit]' ) ).backgroundColor );
	ok( customBtn === previewBtn && customBtn !== matchBtn, 'front end matches the preview' );
	const linkColor = await front.evaluate( () => { const l = document.querySelector( 'header a, .wp-site-blocks a' ); return l ? getComputedStyle( l ).color : ''; } );
	ok( linkColor !== customBtn, 'the rest of the website is unchanged' );
	ok( await front.evaluate( () => [ ...document.styleSheets ].some( ( s ) => { try { return [ ...s.cssRules ].some( ( r ) => r.cssText.includes( 'Flexo Lora' ) && /U\+0?400/i.test( r.cssText ) ); } catch ( e ) { return false; } } ) ), 'chosen font with Cyrillic, from the plugin' );
	ok( fontRequests.every( ( u ) => u.startsWith( BASE ) ) && ! fontRequests.some( ( u ) => u.includes( 'fonts.g' ) ), 'no font requests to Google' );
	const eb = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	await eb.goto( BASE + '/elementor-form/' );
	const eColors = await eb.$$eval( '.flexo-booking .fb-search [type=submit]', ( bs ) => bs.map( ( b ) => getComputedStyle( b ).backgroundColor ) );
	ok( eColors[ 0 ] === 'rgb(176, 141, 87)' && eColors[ 1 ] === 'rgb(31, 95, 139)', 'Elementor widget colour beats Appearance; Appearance beats the website: ' + eColors.join( ' / ' ) );
	const exported = wp( 'echo wp_json_encode( Flexo_Booking_Portability::export()["settings"]["appearance_primary"] );' );
	ok( exported.includes( '#1f5f8b' ), 'Appearance in Import/Export' );
	await a.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-appearance' );
	await Promise.all( [ a.waitForNavigation(), a.click( '.flexo-reset-appearance' ) ] );
	ok( wp( 'echo Flexo_Booking_Appearance::mode();' ) === 'match', 'reset (after confirmation) goes back to "Match"' );

	const ignored = errors.filter( ( e ) => /Unexpected token|elementor/i.test( e ) );
	ok( errors.length - ignored.length === 0, 'no JavaScript errors' + ( errors.length - ignored.length ? ': ' + errors.join( ' | ' ) : '' ) );
	await browser.close();
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )();
