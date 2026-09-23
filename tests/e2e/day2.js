/**
 * Day 2 browser tests: admin calendar, Calendar Sync screen, conflicts,
 * bookings list sources, feature switch.
 *
 *   wp eval-file tests/e2e/seed-day2.php      (prints D0)
 *   BASE=http://localhost:8092 D0=<D0> SHOTS=/tmp/shots node tests/e2e/day2.js
 */
const { chromium } = require( 'playwright' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const D0 = new Date( process.env.D0 + 'T12:00:00Z' );
const day = ( n ) => { const d = new Date( D0 ); d.setUTCDate( d.getUTCDate() + n ); return d.toISOString().slice( 0, 10 ); };
const month = ( n ) => day( n ).slice( 0, 7 );

let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };
const section = ( t ) => console.log( '\n== ' + t );

( async () => {
	const browser = await chromium.launch();
	const ctx = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
	await ctx.grantPermissions( [ 'clipboard-read', 'clipboard-write' ], { origin: BASE } );
	const p = await ctx.newPage();
	const errors = [];
	p.on( 'pageerror', ( e ) => errors.push( e.message ) );
	p.on( 'dialog', ( d ) => d.accept() );
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', 'admin' );
	await p.fill( '#user_pass', 'admin' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	const cal = ( m, extra = '' ) => p.goto( `${ BASE }/wp-admin/admin.php?page=flexo-booking-calendar&month=${ m }${ extra }` );
	const menu = () => p.$$eval( '#toplevel_page_flexo-booking .wp-submenu a', ( els ) => els.map( ( e ) => e.textContent.trim().replace( /\s*\d+$/, '' ) ) );

	section( 'Menu' );
	await p.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const items = await menu();
	console.log( '    ' + items.join( ' | ' ) );
	ok( items[ 1 ] === 'Calendar', 'Calendar right after All bookings' );
	ok( items.includes( 'Calendar Sync' ), 'Calendar Sync in the menu' );

	section( 'Bookings list: sources and conflicts' );
	ok( await p.isVisible( '#flexo-conflicts' ), 'conflict box shown' );
	ok( ( await p.textContent( '#flexo-conflicts' ) ).includes( 'Airbnb' ), 'conflict names the calendar' );
	const maria = p.locator( 'tr', { hasText: 'Maria Website' } );
	ok( await maria.locator( '.flexo-badge--conflict' ).count() === 1, 'the overlapping website booking carries a Conflict badge' );
	ok( await p.locator( 'tr', { hasText: 'Petya Phone' } ).locator( '.flexo-badge--staff' ).count() === 1, 'staff booking labelled "Added by staff"' );
	ok( await p.locator( 'tr', { hasText: 'Painting' } ).locator( '.flexo-badge--blocked' ).count() === 1, 'block labelled "Blocked dates"' );
	ok( await p.locator( 'tr', { hasText: 'Boris Confirmed' } ).locator( '.flexo-badge--website' ).count() === 1, 'website booking labelled "Website"' );
	await p.screenshot( { path: SHOTS + '/d2-list.png', fullPage: true } );
	await Promise.all( [ p.waitForNavigation(), p.click( 'a:has-text("From external calendars")' ) ] );
	const ext = await p.textContent( 'table.widefat.striped' );
	ok( ext.includes( 'Booking.com' ) && ext.includes( 'Vrbo' ) && ext.includes( 'Room no. 2' ), 'external view lists synced bookings with their calendar and unit' );

	section( 'Calendar' );
	await cal( month( 5 ) );
	await p.screenshot( { path: SHOTS + '/d2-calendar.png', fullPage: true } );
	const deluxeRow = p.locator( '.fbc-row', { has: p.locator( '.fbc-room', { hasText: 'Deluxe Double' } ) } );
	ok( ( await deluxeRow.locator( '.fbc-room' ).textContent() ).includes( '3 rooms' ), 'multi-unit room shows its count' );
	ok( await deluxeRow.locator( '.fbc-item--pending' ).count() >= 1, 'pending booking shown' );
	ok( await deluxeRow.locator( '.fbc-item--confirmed.fbc-item--staff' ).count() === 1, 'staff booking shown as such' );
	ok( await deluxeRow.locator( '.fbc-item--external' ).count() === 1, 'external booking shown' );
	const full = await deluxeRow.locator( `.fbc-cell[data-day="${ day( 3 ) }"]` );
	ok( ( await full.getAttribute( 'class' ) ).includes( 'is-full' ) && ( await full.textContent() ).trim() === '0/3', 'day with 3 bookings: 0/3 free, marked full' );
	ok( ( await deluxeRow.locator( `.fbc-cell[data-day="${ day( 6 ) }"]` ).textContent() ).trim() === '2/3', 'day with one unit-linked external booking: 2/3 free' );
	const suiteRow = p.locator( '.fbc-row', { has: p.locator( '.fbc-room', { hasText: 'Family Suite' } ) } );
	ok( await suiteRow.locator( '.fbc-item--external.fbc-item--conflict' ).count() === 1, 'conflicting external booking marked' );
	ok( ( await suiteRow.locator( '.fbc-item', { hasText: 'Maria Website' } ).textContent() ).includes( '⚠' ), 'website booking in conflict marked with ⚠' );
	const studioRow = p.locator( '.fbc-row', { has: p.locator( '.fbc-room', { hasText: 'Garden Studio' } ) } );
	ok( await studioRow.locator( '.fbc-item--blocked' ).count() === 1 && await studioRow.locator( '.fbc-item--closed' ).count() === 1, 'blocked dates and closed period shown' );
	ok( await studioRow.locator( '.fbc-cell.is-closed' ).count() === 3, 'closed nights shaded (3)' );
	ok( await p.locator( '.fbc-item--cancelled' ).count() === 0, 'cancelled hidden by default' );
	const legend = await p.textContent( '.fbc-legend' );
	ok( legend.includes( 'external calendar' ) && legend.includes( 'Conflict' ) && legend.includes( 'Added by staff' ), 'legend explains every type' );

	await deluxeRow.locator( '.fbc-item', { hasText: 'Boris Confirmed' } ).click();
	await p.waitForSelector( '#fbc-dialog[open]' );
	const dlg = await p.textContent( '#fbc-dialog' );
	ok( dlg.includes( 'Website booking' ) && dlg.includes( 'Confirmed' ) && dlg.includes( 'Cancel booking' ), 'click booking → details with actions' );
	await p.screenshot( { path: SHOTS + '/d2-dialog.png' } );
	await p.click( '.fbc-dialog__close button' );
	await suiteRow.locator( '.fbc-item--external.fbc-item--conflict' ).click();
	await p.waitForSelector( '#fbc-dialog[open]' );
	const cdlg = await p.textContent( '#fbc-dialog' );
	ok( cdlg.includes( 'Overlaps' ) && cdlg.includes( 'Mark as reviewed' ), 'click conflict → note and "Mark as reviewed"' );
	await p.click( '.fbc-dialog__close button' );

	// Actions inside the dialog really work (URLs travel as JSON).
	await deluxeRow.locator( '.fbc-item--pending' ).first().click();
	await p.waitForSelector( '#fbc-dialog[open]' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#fbc-dialog a:has-text("Confirm")' ) ] );
	ok( p.url().includes( 'flexo-booking-calendar' ) && await deluxeRow.locator( '.fbc-item--pending' ).count() === 0, 'dialog "Confirm" confirms the booking and returns to the calendar' );
	await studioRow.locator( '.fbc-item--blocked' ).click();
	await Promise.all( [ p.waitForNavigation(), p.click( '#fbc-dialog a:has-text("Remove block")' ) ] );
	ok( await studioRow.locator( '.fbc-item--blocked' ).count() === 0, 'dialog "Remove block" removes it' );

	await studioRow.locator( `.fbc-cell[data-day="${ day( 20 ) }"]` ).click();
	await p.waitForSelector( '#fbc-dialog[open]' );
	ok( ( await p.textContent( '#fbc-dialog' ) ).includes( '1 of 1 free' ), 'click empty day → availability and quick actions' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#fbc-dialog a:has-text("New booking")' ) ] );
	ok( await p.inputValue( '[name=check_in]' ) === day( 20 ) && await p.inputValue( '[name=check_out]' ) === day( 21 ), 'Add booking prefilled with the dates' );
	ok( await p.$eval( '#fb-room', ( s ) => s.options[ s.selectedIndex ].text ) === 'Garden Studio', 'Add booking prefilled with the room' );
	await p.fill( '#fb-name', 'Quick Guest' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#submit' ) ] );
	await cal( month( 20 ) );
	ok( await p.locator( '.fbc-item', { hasText: 'Quick Guest' } ).count() === 1, 'new booking appears in the calendar' );
	await p.locator( '.fbc-row', { has: p.locator( '.fbc-room', { hasText: 'Garden Studio' } ) } ).locator( `.fbc-cell[data-day="${ day( 23 ) }"]` ).click();
	await Promise.all( [ p.waitForNavigation(), p.click( '#fbc-dialog a:has-text("Block dates")' ) ] );
	ok( await p.inputValue( '#fb-status' ) === 'blocked', 'Block dates → Add booking with "Block dates" selected' );

	await cal( month( 5 ), '&cancelled=1' );
	ok( await p.locator( '.fbc-item--cancelled' ).count() >= 1, '"Show cancelled" shows cancelled bookings' );
	await cal( month( 5 ) );
	const title = await p.textContent( '.fbc-month' );
	await Promise.all( [ p.waitForNavigation(), p.click( '.fbc-toolbar a[aria-label="Next month"]' ) ] );
	const next = await p.textContent( '.fbc-month' );
	await Promise.all( [ p.waitForNavigation(), p.click( '.fbc-toolbar a:has-text("Today")' ) ] );
	const now = await p.textContent( '.fbc-month' );
	ok( title !== next && now.length > 0 && await p.locator( '.fbc-day.is-today' ).count() === 1, `month navigation: ${ title } → ${ next } → Today (${ now })` );

	section( 'Calendar at phone width' );
	const phone = await browser.newContext( { viewport: { width: 390, height: 844 }, storageState: await ctx.storageState() } );
	const m = await phone.newPage();
	await m.goto( `${ BASE }/wp-admin/admin.php?page=flexo-booking-calendar&month=${ month( 5 ) }` );
	const widths = await m.evaluate( () => ( { doc: document.documentElement.scrollWidth, view: window.innerWidth, inner: document.querySelector( '.fbc-scroll' ).scrollWidth, box: document.querySelector( '.fbc-scroll' ).clientWidth } ) );
	ok( widths.doc <= widths.view && widths.inner > widths.box, `page does not scroll sideways (${ widths.doc } ≤ ${ widths.view }); only the calendar does (${ widths.inner } > ${ widths.box })` );
	await m.locator( '.fbc-item' ).first().click();
	await m.waitForSelector( '#fbc-dialog[open]' );
	const dbox = await m.$eval( '#fbc-dialog', ( d ) => d.getBoundingClientRect().width );
	ok( dbox <= 390, 'details fit on the phone screen' );
	await m.screenshot( { path: SHOTS + '/d2-phone.png', fullPage: true } );
	await phone.close();

	section( 'Calendar Sync screen' );
	await p.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-sync' );
	await p.screenshot( { path: SHOTS + '/d2-sync.png', fullPage: true } );
	const suiteCard = p.locator( '.flexo-sync-room', { hasText: 'Family Suite' } );
	ok( ( await suiteCard.locator( 'tr', { hasText: 'Booking.com' } ).textContent() ).includes( 'OK' ), 'Booking.com status OK' );
	const studioCard = p.locator( '.flexo-sync-room', { hasText: 'Garden Studio' } );
	ok( ( await studioCard.locator( 'tr', { hasText: 'Old Airbnb link' } ).textContent() ).includes( 'no longer exists' ), 'broken feed shows a readable reason' );
	ok( await p.locator( '.flexo-sync-room', { hasText: 'Deluxe Double' } ).locator( 'select[name=unit]' ).count() === 1 && await suiteCard.locator( 'select[name=unit]' ).count() === 0, '"Counts as" only for rooms with several units' );
	ok( ( await p.textContent( '.flexo-sync-help' ) ).includes( 'Booking requests' ), 'delay note recommends booking requests' );
	await suiteCard.locator( '[data-flexo-copy]' ).click();
	const clip = await p.evaluate( () => navigator.clipboard.readText() );
	const oldUrl = await suiteCard.locator( '.flexo-copy input' ).inputValue();
	ok( clip === oldUrl && oldUrl.includes( 'token=' ), 'Copy link copies the export URL' );
	await Promise.all( [ p.waitForNavigation(), suiteCard.locator( '[data-flexo-reset]' ).click() ] );
	const newUrl = await p.locator( '.flexo-sync-room', { hasText: 'Family Suite' } ).locator( '.flexo-copy input' ).inputValue();
	const oldStatus = ( await p.request.get( oldUrl ) ).status();
	const newResp = await p.request.get( newUrl );
	ok( newUrl !== oldUrl && oldStatus === 403 && newResp.status() === 200 && ( await newResp.text() ).startsWith( 'BEGIN:VCALENDAR' ), 'Reset link: old link refused (403), new one works' );

	await studioCard.locator( 'input[name=name]' ).fill( 'Booking.com' );
	await studioCard.locator( 'input[name=url]' ).fill( BASE + '/feeds/suite-booking.ics' );
	await Promise.all( [ p.waitForNavigation(), studioCard.locator( 'form.flexo-sync-add #submit' ).click() ] );
	ok( ( await p.textContent( '.notice-info' ) ).includes( 'Synced: 2 bookings' ), 'adding a calendar syncs it immediately' );
	await Promise.all( [ p.waitForNavigation(), p.locator( '.flexo-sync-room', { hasText: 'Garden Studio' } ).locator( 'tr', { hasText: 'Booking.com' } ).locator( 'a:has-text("Sync now")' ).click() ] );
	ok( ( await p.textContent( '.notice-info' ) ).includes( '0 new' ), 'Sync now re-syncs without duplicates' );
	await Promise.all( [ p.waitForNavigation(), p.locator( '.flexo-sync-room', { hasText: 'Garden Studio' } ).locator( 'tr', { hasText: 'Booking.com' } ).locator( 'a:has-text("Remove")' ).click() ] );
	ok( await p.locator( '.flexo-sync-room', { hasText: 'Garden Studio' } ).locator( 'tr', { hasText: 'Booking.com' } ).count() === 0, 'calendar removed' );

	section( 'Repeated failures are never silent' );
	for ( let i = 0; i < 2; i++ ) {
		await Promise.all( [ p.waitForNavigation(), p.click( 'a:has-text("Sync all now")' ) ] );
	}
	await p.goto( BASE + '/wp-admin/index.php' );
	const dash = await p.textContent( '#wpbody-content' );
	ok( dash.includes( 'Calendar sync is failing' ) && dash.includes( 'Old Airbnb link' ), 'dashboard shows a failing-calendar notice' );
	ok( dash.includes( 'possible double booking' ), 'dashboard shows the conflict notice' );
	await p.screenshot( { path: SHOTS + '/d2-notices.png' } );

	section( 'Mark conflict as reviewed (from the calendar)' );
	await cal( month( 5 ) );
	await p.locator( '.fbc-item--external.fbc-item--conflict' ).click();
	await Promise.all( [ p.waitForNavigation(), p.click( '#fbc-dialog a:has-text("Mark as reviewed")' ) ] );
	await p.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( await p.locator( '#flexo-conflicts' ).count() === 0, 'conflict box gone after review' );

	section( 'Feature switched off' );
	await p.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	await p.uncheck( 'input[name="features[]"][value=calendar_sync]' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#submit' ) ] );
	ok( ! ( await menu() ).includes( 'Calendar Sync' ), 'Calendar Sync gone from the menu' );
	await cal( month( 5 ) );
	ok( await p.locator( '.fbc-item--external' ).count() === 0 && ! ( await p.textContent( '.fbc-legend' ) ).includes( 'external calendar' ), 'calendar shows no external bookings' );
	ok( ( await p.request.get( newUrl ) ).status() === 404, 'export link answers 404 while off' );
	await p.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	await p.check( 'input[name="features[]"][value=calendar_sync]' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#submit' ) ] );
	await cal( month( 5 ) );
	ok( await p.locator( '.fbc-item--external' ).count() >= 1, 'switched on again: external bookings back' );

	// A source checkout of Elementor has no compiled scripts; the server answers
	// with HTML, which the browser reports as "Unexpected token '<'".
	const elementorBuilt = ( await p.request.get( BASE + '/wp-content/plugins/elementor/assets/js/frontend.min.js', { maxRedirects: 0 } ) ).status() === 200;
	const relevant = [ ...new Set( errors ) ].filter( ( e ) => elementorBuilt || ! e.includes( "Unexpected token '<'" ) );
	if ( ! elementorBuilt && errors.length ) {
		console.log( '    (ignored errors from Elementor\'s missing compiled scripts in this test install)' );
	}
	ok( relevant.length === 0, 'no JavaScript errors' + ( relevant.length ? ': ' + relevant.join( '; ' ) : '' ) );
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	await browser.close();
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
