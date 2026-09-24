/**
 * Day 1 browser tests: seasonal prices, closed dates, features, agency screen,
 * plus the 1.0 regression flow (search bar, booking, admin, CSV, Elementor).
 *
 *   BASE=http://localhost:8092 D0=2026-09-28 SHOTS=/tmp/shots node tests/e2e/day1.js
 *
 * D0 must be the Monday used by the seed (t_day(0)). Users: admin/admin (hotel),
 * agency/agency (listed in FLEXO_BOOKING_AGENCY_USERS).
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const D0 = new Date( process.env.D0 + 'T12:00:00Z' );
const day = ( n ) => { const d = new Date( D0 ); d.setUTCDate( d.getUTCDate() + n ); return d.toISOString().slice( 0, 10 ); };
const dmy = ( n ) => day( n ).split( '-' ).reverse().join( '.' );

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };
const section = ( t ) => console.log( '\n== ' + t );

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
const menu = ( p ) => p.$$eval( '#toplevel_page_flexo-booking .wp-submenu a', ( els ) => els.map( ( e ) => e.textContent.trim().replace( /\s*\d+$/, '' ) ) );

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const guest = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	guest.on( 'pageerror', ( e ) => errors.push( e.message ) );

	section( 'Guest: stay crossing two seasons' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 12 ) }&check_out=${ day( 17 ) }&adults=2` );
	await guest.waitForSelector( '.fb-room' );
	const deluxe = guest.locator( '.fb-room', { hasText: 'Deluxe Double' } );
	ok( ( await deluxe.locator( '.fb-room__total' ).textContent() ).includes( '610.00' ), 'total 2 × 80 + 3 × 150 = 610.00 €' );
	const meta = await deluxe.locator( '.fb-room__meta' ).textContent();
	ok( meta.includes( 'avg.' ) && meta.includes( '122.00' ), 'average per night shown when prices vary: ' + meta );
	await guest.screenshot( { path: SHOTS + '/d1-results.png', fullPage: true } );
	await deluxe.locator( '.fb-button' ).click();
	await guest.waitForSelector( '.fb-details:not([hidden])' );
	const summary = await guest.textContent( '.fb-summary' );
	ok( summary.includes( 'Low season: 2 nights × 80.00 €' ) && summary.includes( 'High season: 3 nights × 150.00 €' ), 'itemised seasons in the summary' );
	await guest.fill( '[name=guest_name]', 'Season Guest' );
	await guest.fill( '[name=guest_email]', 'season@example.com' );
	await guest.fill( '[name=guest_phone]', '+359 888 111 222' );
	await guest.check( '[name=terms]' );
	await guest.screenshot( { path: SHOTS + '/d1-details.png', fullPage: true } );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	ok( ( await guest.textContent( '.fb-success' ) ).includes( '610.00' ), 'booking confirmed on screen with 610.00 €' );

	section( 'Guest: season minimum stay and closed dates' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 14 ) }&check_out=${ day( 16 ) }&adults=2` );
	await guest.waitForSelector( '.fb-room' );
	const reason = await guest.locator( '.fb-room', { hasText: 'Deluxe Double' } ).locator( '.fb-room__reason' ).textContent();
	ok( reason.includes( 'Minimum stay 3 nights for arrivals in High season' ), 'season minimum explained: ' + reason );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 41 ) }&check_out=${ day( 43 ) }&adults=2` );
	await guest.waitForSelector( '.fb-room' );
	const notice = await guest.textContent( '.fb-notice' );
	ok( /closed from .* to .*Please choose other dates/.test( notice ), 'closed-dates notice: ' + notice );
	ok( ( await guest.$$( '.fb-room:not(.is-unavailable)' ) ).length === 0, 'no room bookable while closed' );
	await guest.screenshot( { path: SHOTS + '/d1-closed.png', fullPage: true } );

	section( 'Guest: search bar → booking page (regression)' );
	await guest.goto( BASE + '/home/' );
	await guest.fill( '.flexo-booking--search [name=check_in]', day( 1 ) );
	await guest.dispatchEvent( '.flexo-booking--search [name=check_in]', 'change' );
	await guest.fill( '.flexo-booking--search [name=check_out]', day( 3 ) );
	await Promise.all( [ guest.waitForNavigation(), guest.click( '.flexo-booking--search .fb-button' ) ] );
	await guest.waitForSelector( '.fb-room' );
	ok( guest.url().includes( '/booking/?check_in=' ), 'search bar lands on the booking page with results' );
	const lowTotal = await guest.locator( '.fb-room', { hasText: 'Deluxe Double' } ).locator( '.fb-room__total' ).textContent();
	ok( lowTotal.includes( '160.00' ), 'low season 2 × 80: ' + lowTotal.trim() );

	const mobile = await browser.newPage( { viewport: { width: 390, height: 844 } } );
	mobile.on( 'pageerror', ( e ) => errors.push( 'mobile: ' + e.message ) );
	await mobile.goto( `${ BASE }/booking/?room=deluxe-double&check_in=${ day( 12 ) }&check_out=${ day( 17 ) }` );
	await mobile.waitForSelector( '.fb-room' );
	await mobile.locator( '.fb-room .fb-button' ).click();
	await mobile.waitForSelector( '.fb-details:not([hidden])' );
	await mobile.screenshot( { path: SHOTS + '/d1-mobile.png', fullPage: true } );
	ok( ( await mobile.textContent( '.fb-summary' ) ).includes( 'High season' ), 'mobile: breakdown visible' );

	section( 'Hotel admin: menu and Features tab' );
	const admin = await login( browser, 'admin' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	let items = await menu( admin );
	console.log( '    menu: ' + items.join( ' | ' ) );
	ok( items.includes( 'Seasonal prices' ) && items.includes( 'Closed dates' ), 'Seasonal prices and Closed dates in the menu' );
	ok( ! items.includes( 'Agency' ), 'hotel admin does not see Agency' );
	const breakdown = await admin.locator( 'tr', { hasText: 'Season Guest' } ).locator( '.flexo-breakdown' ).textContent();
	ok( breakdown.includes( 'Low season' ) && breakdown.includes( 'High season' ), 'bookings list shows the price breakdown' );
	await admin.screenshot( { path: SHOTS + '/d1-admin-list.png', fullPage: true } );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-agency' );
	ok( ( await admin.content() ).includes( 'not allowed' ), 'Agency screen refused for hotel admin' );

	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	await admin.screenshot( { path: SHOTS + '/d1-features.png', fullPage: true } );
	// Since 1.5.0 every feature is built: none is shown as "Coming soon".
	ok( ! ( await admin.content() ).includes( 'Coming soon' ) && ( await admin.content() ).includes( 'value="online_payment"' ), 'all features can be switched on (payments included)' );
	await admin.uncheck( 'input[name="features[]"][value=seasonal_pricing]' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	items = await menu( admin );
	ok( ! items.includes( 'Seasonal prices' ), 'switching Seasonal prices off removes its screen' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 12 ) }&check_out=${ day( 17 ) }&adults=2` );
	await guest.waitForSelector( '.fb-room' );
	const plain = await guest.locator( '.fb-room', { hasText: 'Deluxe Double' } ).locator( '.fb-room__total' ).textContent();
	ok( plain.includes( '550.00' ), 'guests now get room prices (Sat 150 + 4 × 100): ' + plain.trim() );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	await admin.check( 'input[name="features[]"][value=seasonal_pricing]' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );

	section( 'Hotel admin: Seasonal prices screen' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-seasons' );
	const rows = await admin.$$eval( '.flexo-seasons-table tbody tr', ( trs ) => trs.map( ( tr ) => tr.innerText.replace( /\s+/g, ' ' ).trim() ) );
	ok( rows.length === 2 && rows[ 0 ].includes( dmy( 0 ) ) && rows[ 0 ].includes( dmy( 13 ) ), 'table shows DD.MM.YYYY: ' + rows[ 0 ] );
	await admin.click( 'input[name=date_from]' );
	ok( await admin.isVisible( '.ui-datepicker' ), 'date picker opens' );
	await admin.screenshot( { path: SHOTS + '/d1-datepicker.png' } );
	await admin.keyboard.press( 'Escape' );
	await admin.fill( 'input[name=name]', 'Overlapping' );
	await admin.fill( 'input[name=date_from]', dmy( 10 ) );
	await admin.fill( 'input[name=date_to]', dmy( 16 ) );
	await admin.fill( 'input[name=price]', '99' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-season-form #submit' ) ] );
	const err = await admin.textContent( '.notice-error' );
	ok( err.includes( 'overlap with "Low season"' ), 'overlap error shown: ' + err.trim() );
	ok( 'Overlapping' === await admin.inputValue( 'input[name=name]' ), 'form keeps what was typed' );
	await admin.screenshot( { path: SHOTS + '/d1-seasons-error.png', fullPage: true } );
	await admin.fill( 'input[name=name]', 'Autumn' );
	await admin.fill( 'input[name=date_from]', dmy( 28 ) );
	await admin.fill( 'input[name=date_to]', dmy( 35 ) );
	await admin.fill( 'input[name=price]', '95,50' );
	await admin.fill( 'input[name=min_nights]', '2' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-season-form #submit' ) ] );
	const added = ( await admin.textContent( '.flexo-seasons-table' ) ).includes( 'Autumn' );
	ok( added, 'new season added' + ( added ? '' : ': ' + ( await admin.$$eval( '.notice', ( n ) => n.map( ( x ) => x.innerText ).join( ' | ' ) ) ) + ' @ ' + admin.url() ) );
	await admin.locator( 'tr', { hasText: 'Autumn' } ).locator( 'a', { hasText: 'Edit' } ).click();
	await admin.waitForSelector( 'input[name=name][value=Autumn]' );
	await admin.fill( 'input[name=price]', '97' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-season-form #submit' ) ] );
	ok( ( await admin.locator( 'tr', { hasText: 'Autumn' } ).textContent() ).includes( '97.00' ), 'season edited' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( 'form:has([name=action][value=flexo_booking_copy_seasons]) #submit' ) ] );
	const info = await admin.textContent( '.notice-info' );
	ok( /seasons? copied to \d{4}/.test( info ), 'copy to next year: ' + info.trim() );
	await admin.screenshot( { path: SHOTS + '/d1-seasons.png', fullPage: true } );
	await Promise.all( [ admin.waitForNavigation(), admin.locator( 'tr', { hasText: 'Autumn' } ).first().locator( 'a', { hasText: 'Delete' } ).click() ] );
	ok( ( await admin.textContent( '.notice-success' ) ).includes( 'Deleted' ), 'season deleted' );

	section( 'Hotel admin: Closed dates screen' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-closures' );
	await admin.selectOption( '#fc-room', { label: 'Family Suite' } );
	await admin.fill( '#flexo-closure-form input[name=date_from]', dmy( 60 ) );
	await admin.fill( '#flexo-closure-form input[name=date_to]', dmy( 62 ) );
	await admin.fill( '#fc-label', 'Renovation' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-closure-form #submit' ) ] );
	const closureRow = await admin.locator( 'tr', { hasText: 'Renovation' } ).innerText();
	ok( closureRow.includes( 'Family Suite' ) && closureRow.includes( dmy( 60 ) ), 'closure added: ' + closureRow.replace( /\s+/g, ' ' ) );
	await admin.screenshot( { path: SHOTS + '/d1-closures.png', fullPage: true } );

	section( 'CSV export' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const [ dl ] = await Promise.all( [ admin.waitForEvent( 'download' ), admin.click( 'a:has-text("Export CSV")' ) ] );
	await dl.saveAs( SHOTS + '/bookings.csv' );
	const csv = fs.readFileSync( SHOTS + '/bookings.csv', 'utf8' );
	ok( csv.split( '\n' )[ 0 ].includes( 'Price details' ) && csv.includes( 'High season: 3 nights' ), 'CSV has price details' );

	section( 'Agency screen' );
	const agency = await login( browser, 'agency' );
	await agency.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( ( await menu( agency ) ).includes( 'Agency' ), 'agency user sees Agency' );
	await agency.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-agency' );
	await agency.screenshot( { path: SHOTS + '/d1-agency.png', fullPage: true } );
	await agency.uncheck( 'input[name="available[]"][value=seasonal_pricing]' );
	await agency.uncheck( 'input[name="available[]"][value=promo_codes]' );
	await Promise.all( [ agency.waitForNavigation(), agency.click( '#save' ) ] );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	const tab = await admin.content();
	ok( ! tab.includes( 'Seasonal prices' ) && ! tab.includes( 'Promo codes' ), 'unavailable features gone from the hotel Features tab' );
	ok( ! ( await menu( admin ) ).includes( 'Seasonal prices' ), 'and from the menu' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-tools' );
	ok( ! ( await admin.content() ).includes( 'import_seasons' ), 'and from Import / Export' );
	await agency.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-agency' );
	await Promise.all( [ agency.waitForNavigation(), agency.click( '#reset' ) ] );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( ( await menu( admin ) ).includes( 'Seasonal prices' ), 'reset: everything available again, hotel choice restored' );

	section( 'Elementor (regression)' );
	const el = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	el.on( 'pageerror', ( e ) => errors.push( 'elementor: ' + e.message ) );
	await el.goto( BASE + '/elementor-booking/' );
	const full = '.elementor-element-f2f2f2f';
	await el.fill( `${ full } [name=check_in]`, day( 12 ) );
	await el.dispatchEvent( `${ full } [name=check_in]`, 'change' );
	await el.fill( `${ full } [name=check_out]`, day( 17 ) );
	await el.click( `${ full } .fb-search .fb-button` );
	await el.waitForSelector( `${ full } .fb-room` );
	ok( ( await el.textContent( `${ full } .fb-room__total` ) ).includes( '610.00' ), 'widget uses seasonal prices' );
	ok( 'rgb(176, 141, 87)' === await el.$eval( '.elementor-element-e1e1e1e .fb-button', ( b ) => getComputedStyle( b ).backgroundColor ), 'widget colour control' );
	const inherited = await el.$eval( `${ full } .fb-search .fb-button`, ( b ) => getComputedStyle( b ).backgroundColor );
	ok( 'rgb(97, 206, 112)' === inherited, 'unstyled widget inherits Elementor global accent: ' + inherited );
	const href = await el.getAttribute( '.elementor-element-b3b3b3b a', 'href' );
	ok( href && href.endsWith( '/booking/?room=family-suite' ), 'booking link dynamic tag: ' + href );

	// A source checkout of Elementor has no compiled frontend scripts; the server
	// answers with an HTML page, which the browser reports as "Unexpected token '<'".
	const elementorBuilt = ( await el.request.get( BASE + '/wp-content/plugins/elementor/assets/js/frontend.min.js', { maxRedirects: 0 } ) ).status() === 200;
	const relevant = errors.filter( ( e ) => elementorBuilt || ! e.includes( "Unexpected token '<'" ) );
	if ( relevant.length !== errors.length ) {
		console.log( '    (ignored ' + ( errors.length - relevant.length ) + ' errors from Elementor\'s missing compiled scripts in this test install)' );
	}
	ok( relevant.length === 0, 'no JavaScript errors' + ( relevant.length ? ': ' + relevant.join( '; ' ) : '' ) );
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	await browser.close();
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
