/**
 * Day 4 browser tests: privacy consent, invoice request, conversion tracking
 * (dataLayer and optional Meta Pixel), emails screen, anonymising, phone
 * width, features off.
 *
 *   BASE=http://localhost:8092 D0=<from seed-day4.php> SHOTS=/tmp/shots WP="php wp-cli.phar --path=<site>" node tests/e2e/day4.js
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
const wp = ( code ) => execSync( `${ WP } eval '${ code }' 2>/dev/null` ).toString();

// Records every dataLayer push and Meta Pixel call, across page loads.
const RECORDER = `
	(function () {
		var store = function ( key, item ) {
			var list = JSON.parse( sessionStorage.getItem( key ) || '[]' );
			list.push( item );
			sessionStorage.setItem( key, JSON.stringify( list ) );
		};
		var dl = [];
		dl.push = function () {
			for ( var i = 0; i < arguments.length; i++ ) {
				var copy = {};
				for ( var k in arguments[ i ] ) { if ( typeof arguments[ i ][ k ] !== 'function' ) { copy[ k ] = arguments[ i ][ k ]; } }
				store( 'e2e_events', copy );
			}
			return Array.prototype.push.apply( this, arguments );
		};
		window.dataLayer = dl;
		window.fbq = function () { store( 'e2e_fbq', Array.prototype.slice.call( arguments ) ); };
	})();
`;
const events = async ( p ) => ( await p.evaluate( () => JSON.parse( sessionStorage.getItem( 'e2e_events' ) || '[]' ) ) ).filter( ( e ) => e.event );
const fbq = ( p ) => p.evaluate( () => JSON.parse( sessionStorage.getItem( 'e2e_fbq' ) || '[]' ) );

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

async function toDetails( p, from, to ) {
	await p.goto( `${ BASE }/booking/?check_in=${ day( from ) }&check_out=${ day( to ) }&adults=2&children=0` );
	await p.waitForSelector( '.fb-room' );
	await p.locator( '.fb-room', { hasText: 'Lake Room' } ).locator( '.fb-room__side .fb-button' ).click();
	await p.waitForSelector( '.fb-details:not([hidden])' );
}

async function fillGuest( p, name, email ) {
	await p.fill( '[name=guest_name]', name );
	await p.fill( '[name=guest_email]', email );
	await p.fill( '[name=guest_phone]', '+359 888 555 666' );
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 900 } } );
	await ctx.addInitScript( RECORDER );
	const guest = await ctx.newPage();
	guest.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const admin = await login( browser, 'admin' );

	section( 'Guest: privacy consent' );
	await toDetails( guest, 30, 32 );
	const consent = guest.locator( '[name=privacy_consent]' );
	ok( await consent.isVisible() && ! ( await consent.isChecked() ), 'consent checkbox shown, not ticked' );
	ok( ( await text( guest, '.fb-consent' ) ).includes( 'I agree to the Privacy Policy and consent to my information being processed' ), 'consent text' );
	ok( ( await guest.getAttribute( '.fb-consent a', 'href' ) ).endsWith( '/privacy-policy/' ), 'links to the WordPress privacy page' );
	await fillGuest( guest, 'Elena Georgieva', 'elena@example.com' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForTimeout( 800 );
	ok( await guest.isHidden( '.fb-success' ), 'booking not sent without consent' );

	section( 'Guest: invoice request' );
	ok( await guest.isHidden( '.fb-invoice__fields' ), 'invoice fields hidden until "I would like an invoice" is ticked' );
	await guest.check( '[data-fb-invoice-toggle]' );
	ok( await guest.isVisible( '[name=invoice_full_name]' ) && await guest.isHidden( '[name=invoice_company_name]' ), 'person: name and address' );
	await guest.check( '[name=invoice_type][value=company]' );
	ok( await guest.isVisible( '[name=invoice_company_name]' ) && await guest.isVisible( '[name=invoice_vat_number]' ) && await guest.isHidden( '[name=invoice_full_name]' ), 'company: company fields' );
	await guest.fill( '[name=invoice_company_name]', 'Lake Tours OOD' );
	await guest.fill( '[name=invoice_company_id]', '12345' );
	await guest.fill( '[name=invoice_company_address]', 'ul. Rakovski 5, Sofia' );
	await guest.fill( '[name=invoice_vat_number]', 'BG201234567' );
	await guest.check( '[name=privacy_consent]' );
	await guest.screenshot( { path: SHOTS + '/d4-details.png', fullPage: true } );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-notice--error:not([hidden])' );
	ok( ( await guest.textContent( '.fb-notice' ) ).includes( '9 or 13 digits' ), 'EIK checked on the server: ' + ( await guest.textContent( '.fb-notice' ) ) );
	await guest.fill( '[name=invoice_company_id]', '201234567' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	const ref = ( await guest.textContent( '.fb-success__reference strong' ) ).trim();
	ok( /^FB-/.test( ref ), 'booking request sent: ' + ref );

	section( 'Tracking: dataLayer events' );
	let ev = await events( guest );
	const names = ev.map( ( e ) => e.event );
	ok( names.filter( ( n ) => n === 'search' ).length >= 1 && names.includes( 'room_select' ) && names.includes( 'begin_checkout' ), 'search, room_select, begin_checkout pushed: ' + names.join( ', ' ) );
	const search = ev.find( ( e ) => e.event === 'search' );
	ok( search.check_in === day( 30 ) && search.nights === 2 && search.adults === 2, 'search: dates, nights, guests' );
	const select = ev.find( ( e ) => e.event === 'room_select' );
	ok( select.room === 'Lake Room' && select.rate_plan === 'Breakfast Included' && select.value === 200 && select.currency === 'EUR', 'room_select: room, rate plan, value 200, EUR' );
	const done = ev.filter( ( e ) => e.event === 'booking_complete' );
	ok( done.length === 1, 'booking_complete fired once' );
	ok( done[ 0 ].booking_reference === ref && done[ 0 ].value === 200 && done[ 0 ].currency === 'EUR' && done[ 0 ].room === 'Lake Room' && done[ 0 ].rate_plan === 'Breakfast Included', 'booking_complete: reference, value, currency, room, rate plan' );
	ok( done[ 0 ].ecommerce && done[ 0 ].ecommerce.transaction_id === ref && done[ 0 ].ecommerce.items[ 0 ].item_variant === 'Breakfast Included', 'GA4 ecommerce data (transaction_id, items)' );
	const raw = await guest.evaluate( () => JSON.parse( sessionStorage.getItem( 'e2e_events' ) || '[]' ) );
	ok( raw.filter( ( e ) => ! e.event && e.ecommerce === null ).length === 3, 'ecommerce cleared before each of the 3 ecommerce events (GA4)' );
	const all = JSON.stringify( await guest.evaluate( () => sessionStorage.getItem( 'e2e_events' ) ) );
	ok( ! /Elena|Georgieva|elena@|888 555|Lake Tours|201234567/.test( all ), 'no personal data in any event' );
	ok( ( await fbq( guest ) ).length === 0, 'Meta Pixel not called directly (off by default)' );
	await guest.reload();
	await guest.waitForSelector( '.fb-room' );
	ev = await events( guest );
	ok( ev.filter( ( e ) => e.event === 'booking_complete' ).length === 1, 'reloading the page does not fire booking_complete again' );
	ok( '1' === await guest.evaluate( ( r ) => localStorage.getItem( 'flexo_tracked_' + r ), ref ), 'the booking is remembered as tracked' );

	section( 'Tracking: thank-you page redirect and Meta Pixel' );
	wp( 'update_option( "flexo_booking_settings", array_merge( Flexo_Booking_Settings::all(), array( "thank_you_url" => "/thank-you/", "tracking_meta_pixel" => 1 ) ) );' );
	await guest.evaluate( () => { sessionStorage.removeItem( 'e2e_events' ); sessionStorage.removeItem( 'e2e_fbq' ); } );
	await toDetails( guest, 40, 42 );
	await fillGuest( guest, 'Petar Petrov', 'petar@example.com' );
	await guest.check( '[name=privacy_consent]' );
	const started = Date.now();
	await Promise.all( [ guest.waitForURL( /thank-you/, { timeout: 8000 } ), guest.click( '.fb-details [type=submit]' ) ] );
	ok( guest.url().includes( '/thank-you/?booking=FB-' ), 'redirected to the thank-you page after ' + ( Date.now() - started ) + ' ms' );
	ev = await events( guest );
	ok( ev.filter( ( e ) => e.event === 'booking_complete' ).length === 1, 'booking_complete recorded before leaving the page' );
	const calls = ( await fbq( guest ) ).map( ( c ) => c[ 1 ] );
	ok( calls.includes( 'Search' ) && calls.includes( 'InitiateCheckout' ) && calls.includes( 'Purchase' ), 'Meta Pixel (setting on): Search, InitiateCheckout, Purchase' );
	await guest.reload();
	ok( ( await events( guest ) ).filter( ( e ) => e.event === 'booking_complete' ).length === 1, 'reloading the thank-you page fires nothing' );
	wp( 'update_option( "flexo_booking_settings", array_merge( Flexo_Booking_Settings::all(), array( "thank_you_url" => "", "tracking_meta_pixel" => 0 ) ) );' );

	section( 'Guest: phone' );
	const phoneCtx = await browser.newContext( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
	const phone = await phoneCtx.newPage();
	phone.on( 'pageerror', ( e ) => errors.push( 'phone: ' + e.message ) );
	await toDetails( phone, 50, 52 );
	await phone.check( '[data-fb-invoice-toggle]' );
	await phone.check( '[name=invoice_type][value=company]' );
	ok( await noOverflow( phone ), 'invoice fields and consent fit a 390 px screen' );
	await phone.screenshot( { path: SHOTS + '/d4-phone.png', fullPage: true } );

	section( 'Admin: booking with invoice and consent' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const row = admin.locator( '.flexo-bookings-table tr', { hasText: ref } );
	ok( ( await row.textContent() ).includes( 'Invoice' ), 'list: 🧾 Invoice badge' );
	await Promise.all( [ admin.waitForNavigation(), row.locator( 'strong a' ).click() ] );
	const view = await text( admin, '.flexo-booking-view' );
	ok( view.includes( 'Invoice requested' ) && view.includes( 'Lake Tours OOD' ) && view.includes( '201234567' ) && view.includes( 'BG201234567' ), 'details: invoice (company, EIK, VAT)' );
	ok( view.includes( 'Privacy consent' ) && view.includes( 'Given on' ) && view.includes( 'Text version' ), 'details: consent with time and text version' );
	ok( view.includes( 'To the guest: Booking request received' ) && view.includes( 'To the hotel: New booking' ), 'details: emails sent for this booking' );
	await admin.screenshot( { path: SHOTS + '/d4-admin-booking.png', fullPage: true } );
	const mail = execSync( `grep -h "${ ref }" ${ process.env.MAIL_LOG } || true` ).toString();
	ok( mail.includes( 'desk@hotel.test' ) && mail.includes( 'INVOICE REQUESTED' ) && mail.includes( 'Lake Tours OOD' ), 'hotel email includes the invoice details' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const csv = await ( await admin.request.get( await admin.getAttribute( 'a.page-title-action:has-text("Export CSV")', 'href' ) ) ).text();
	ok( csv.split( '\n' )[ 0 ].includes( 'Invoice name / company' ) && csv.includes( 'Lake Tours OOD' ) && csv.includes( 'BG201234567' ) && csv.includes( 'company' ), 'CSV: invoice columns' );

	section( 'Admin: anonymise' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	await Promise.all( [ admin.waitForNavigation(), admin.locator( '.flexo-bookings-table tr', { hasText: ref } ).locator( 'strong a' ).click() ] );
	await Promise.all( [ admin.waitForNavigation(), admin.click( 'a:has-text("Anonymise")' ) ] );
	const anon = await text( admin, '.flexo-booking-view' );
	ok( anon.includes( 'personal data was removed' ) && anon.includes( 'Anonymised guest' ) && ! anon.includes( 'Elena' ) && ! anon.includes( 'Lake Tours' ), 'name, contact and invoice removed' );
	ok( anon.includes( '200.00 €' ) && anon.includes( 'Lake Room' ), 'room and price kept' );

	section( 'Admin: settings and emails' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings' );
	const tabs = await text( admin, '.nav-tab-wrapper' );
	ok( [ 'Privacy', 'Invoices', 'Emails', 'Tracking' ].every( ( t ) => tabs.includes( t ) ), 'tabs: Privacy, Invoices, Emails, Tracking' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=emails' );
	ok( ( await admin.$$( '#flexo-email-pre_arrival, #flexo-email-review' ) ).length === 2, 'pre-arrival and review templates' );
	ok( ! ( await admin.content() ).includes( 'email_awaiting_deposit' ), 'Day 5 payment templates not shown yet' );
	await admin.check( '[name="flexo_booking_settings[email_review_enabled]"][type=checkbox]' );
	await admin.fill( '[name="flexo_booking_settings[review_link]"]', 'https://g.page/r/lake/review' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	ok( 'https://g.page/r/lake/review' === await admin.inputValue( '[name="flexo_booking_settings[review_link]"]' ) && await admin.isChecked( '[name="flexo_booking_settings[email_review_enabled]"][type=checkbox]' ), 'review request switched on with its link' );
	await admin.fill( '[name=test_email]', 'owner@hotel.test' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-test-email [type=submit]' ) ] );
	ok( ( await text( admin, '.notice-info' ) ).includes( 'Test email sent to owner@hotel.test' ), 'test email: confirmation notice' );
	const log = await text( admin, '#flexo-email-log' );
	ok( log.includes( 'Test email' ) && log.includes( 'owner@hotel.test' ) && log.includes( 'Sent' ), 'email log shows it' );
	ok( ( await admin.$$( '.flexo-smtp-notice' ) ).length === 0, 'no SMTP warning while a mail plugin is active' );
	await admin.screenshot( { path: SHOTS + '/d4-emails.png', fullPage: true } );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=privacy' );
	ok( ( await text( admin, '.form-table' ) ).includes( 'many hotels choose 24 months' ), 'privacy tab explains the retention period' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=tracking' );
	ok( ( await text( admin, '.flexo-tracking-events' ) ).includes( 'booking_complete' ), 'tracking tab lists the events for Tag Manager' );
	await admin.goto( BASE + '/wp-admin/options-privacy.php?tab=policyguide' );
	ok( ( await admin.content() ).includes( 'Room bookings' ), 'privacy policy guide contains the suggested text' );

	section( 'Features off: invisible' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	for ( const f of [ 'privacy_consent', 'invoice_request', 'tracking' ] ) {
		await admin.uncheck( `input[name="features[]"][value=${ f }]` );
	}
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	const tabsOff = await text( admin, '.nav-tab-wrapper' );
	ok( ! tabsOff.includes( 'Privacy' ) && ! tabsOff.includes( 'Invoices' ) && ! tabsOff.includes( 'Tracking' ), 'tabs gone' );
	await guest.evaluate( () => sessionStorage.removeItem( 'e2e_events' ) );
	await toDetails( guest, 60, 62 );
	ok( ( await guest.$$( '[name=privacy_consent], [data-fb-invoice]' ) ).length === 0, 'guest form: no consent, no invoice fields' );
	ok( ( await events( guest ) ).length === 0, 'no tracking events' );
	await fillGuest( guest, 'Plain Guest', 'plain@example.com' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	ok( true, 'booking works without them: dates → room → details → request' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	for ( const f of [ 'privacy_consent', 'invoice_request', 'tracking' ] ) {
		await admin.check( `input[name="features[]"][value=${ f }]` );
	}
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );

	ok( errors.length === 0, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( '; ' ) : '' ) );
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	await browser.close();
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
