/**
 * Day 3 browser tests: children's ages, rate plans, tourist tax, promo codes
 * (guest flow on desktop and phone), and the admin screens.
 *
 *   BASE=http://localhost:8092 D0=<from seed-day3.php> SHOTS=/tmp/shots node tests/e2e/day3.js
 */
const { chromium } = require( 'playwright' );

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp';
const D0 = new Date( process.env.D0 + 'T12:00:00Z' );
const day = ( n ) => { const d = new Date( D0 ); d.setUTCDate( d.getUTCDate() + n ); return d.toISOString().slice( 0, 10 ); };

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
const text = async ( p, sel ) => ( await p.innerText( sel ) ).replace( /\s+/g, ' ' );
const noOverflow = ( p ) => p.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 );

async function fillGuest( p, name ) {
	await p.fill( '[name=guest_name]', name );
	await p.fill( '[name=guest_email]', 'family@example.com' );
	await p.fill( '[name=guest_phone]', '+359 888 222 333' );
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const guest = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	guest.on( 'pageerror', ( e ) => errors.push( e.message ) );
	const admin = await login( browser, 'admin' );

	section( 'Guest: children\'s ages' );
	await guest.goto( BASE + '/booking/' );
	await guest.fill( '.fb-search [name=check_in]', day( 30 ) );
	await guest.dispatchEvent( '.fb-search [name=check_in]', 'change' );
	await guest.fill( '.fb-search [name=check_out]', day( 33 ) );
	await guest.selectOption( '.fb-search [name=adults]', '2' );
	ok( ( await guest.$$( '[name="children_ages[]"]' ) ).length === 0, 'no age fields without children' );
	await guest.selectOption( '.fb-search [name=children]', '2' );
	ok( ( await guest.$$( '[name="children_ages[]"]' ) ).length === 2, 'two children → two age selectors' );
	ok( ( await guest.textContent( '.fb-ages' ) ).includes( 'Age of child 2' ), 'labelled "Age of child 2"' );
	await guest.click( '.fb-search .fb-button' );
	await guest.waitForTimeout( 600 );
	ok( ( await guest.$$( '.fb-room' ) ).length === 0, 'search waits until every age is chosen' );
	await guest.selectOption( '[name="children_ages[]"] >> nth=0', '5' );
	await guest.selectOption( '[name="children_ages[]"] >> nth=1', '10' );
	await guest.click( '.fb-search .fb-button' );
	await guest.waitForSelector( '.fb-room' );
	const family = guest.locator( '.fb-room', { hasText: 'Garden Family Room' } );
	const studio = guest.locator( '.fb-room', { hasText: 'Simple Studio' } );
	ok( ( await studio.textContent() ).includes( 'Fits up to 2 guests' ), 'children count towards max guests (studio for 2 refused)' );
	// Cheapest: Non-refundable 300 − 30 + tax (2 adults × 3 × 1.50 + child 10 × 3 × 1.50; child 5 exempt) = 283.50.
	ok( ( await family.locator( '.fb-room__total' ).textContent() ).includes( 'from 283.50' ), 'room card: "from 283.50 €" (cheapest rate plan)' );
	await guest.screenshot( { path: SHOTS + '/d3-results.png', fullPage: true } );

	section( 'Guest: rate plan choice' );
	await family.locator( '.fb-room__side .fb-button' ).click();
	await guest.waitForSelector( '.fb-plans' );
	const plans = await guest.$$eval( '.fb-plan__name', ( els ) => els.map( ( e ) => e.textContent ) );
	ok( plans.join( '|' ) === 'Room Only|Breakfast Included|Half Board|Non-refundable', 'four switched-on plans in order: ' + plans.join( ', ' ) );
	ok( ( await guest.textContent( '.fb-plan:has-text("Non-refundable")' ) ).includes( '✕ Non-refundable' ), 'non-refundable marked with text, not only colour' );
	ok( ( await guest.textContent( '.fb-plan:has-text("Half Board")' ) ).includes( '475.50' ), 'Half Board for 2 adults + children 5 and 10: 475.50 €' );
	await guest.screenshot( { path: SHOTS + '/d3-plans.png', fullPage: true } );
	await guest.locator( '.fb-plan', { hasText: 'Half Board' } ).locator( 'button' ).click();
	await guest.waitForSelector( '.fb-details:not([hidden])' );
	let summary = await text( guest, '.fb-summary' );
	ok( summary.includes( 'Rate Half Board' ), 'summary: rate plan' );
	ok( summary.includes( '2 adults, 2 children (ages 5, 10)' ), 'summary: guests with ages' );
	ok( summary.includes( 'Accommodation, 3 nights 300.00 €' ) && summary.includes( 'Half Board 162.00 €' ), 'summary: room price and plan as separate lines' );
	ok( summary.includes( 'Child, age 5 × 3 nights (50%) × 9.00 € = 27.00 €' ), 'summary: child supplement by age' );
	ok( summary.includes( 'Tourist tax 13.50 €' ) && summary.includes( 'Child, age 5: free' ), 'summary: tourist tax line, young child exempt' );
	ok( summary.includes( 'Total 475.50 €' ), 'summary: total 475.50 €' );
	ok( summary.includes( 'Cancellation: Free cancellation up to 7 days' ), 'summary: cancellation text' );
	ok( await guest.isHidden( '.fb-promo__fields' ), 'promo field collapsed behind "Have a promo code?"' );

	section( 'Guest: promo code' );
	await guest.click( '.fb-promo__toggle' );
	await guest.fill( '.fb-promo__row input', 'old' );
	await guest.click( '.fb-promo__row button' );
	await guest.waitForSelector( '.fb-promo__message.is-error' );
	ok( ( await guest.textContent( '.fb-promo__message' ) ).includes( 'This promo code has expired.' ), 'expired code: clear message' );
	ok( ( await text( guest, '.fb-summary' ) ).includes( 'Total 475.50 €' ), 'price unchanged' );
	await guest.fill( '.fb-promo__row input', 'direct10' );
	await guest.click( '.fb-promo__row button' );
	await guest.waitForFunction( () => document.querySelector( '.fb-summary' ).textContent.includes( 'applied' ) );
	summary = await text( guest, '.fb-summary' );
	ok( summary.includes( 'Promo code DIRECT10 applied (−10%)' ), 'code applied (typed in small letters)' );
	ok( summary.includes( 'Subtotal 462.00 €' ) && summary.includes( 'Discount −46.20 €' ) && summary.includes( 'Final total 429.30 €' ), 'Subtotal 462.00, Discount −46.20 (not on the tax), Final total 429.30' );
	await guest.screenshot( { path: SHOTS + '/d3-summary.png', fullPage: true } );
	await fillGuest( guest, 'Family Guest' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	ok( ( await guest.textContent( '.fb-success' ) ).includes( '429.30' ), 'booking request sent: 429.30 €' );

	section( 'Guest: one plan skips the choice; price change is caught' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2&children=0` );
	await guest.waitForSelector( '.fb-room' );
	const studio2 = guest.locator( '.fb-room', { hasText: 'Simple Studio' } );
	ok( ( await studio2.locator( '.fb-room__total' ).textContent() ).trim() === '267.00 €', 'studio (one plan): 267.00 €, no "from"' );
	await studio2.locator( '.fb-room__side .fb-button' ).click();
	await guest.waitForSelector( '.fb-details:not([hidden])' );
	ok( ( await guest.$$( '.fb-plans' ) ).length === 0 && ( await text( guest, '.fb-summary' ) ).includes( 'Rate Breakfast Included' ), 'went straight to details with Breakfast Included' );
	await fillGuest( guest, 'Studio Guest' );

	// Meanwhile the hotel raises the breakfast price (Rate plans screen).
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-rate-plans' );
	ok( ( await admin.$$( '.flexo-plans-table tbody tr' ) ).length === 6, 'rate plans screen lists the 6 ready-made plans' );
	ok( ( await text( admin, '.flexo-plans-table tr:has-text("Full Board")' ) ).includes( 'Switched off' ), 'unused ready-made plans are switched off' );
	ok( ( await text( admin, '.flexo-plans-table tr:has-text("Half Board")' ) ).includes( 'Garden Family Room' ), '"Offered for" lists the rooms' );
	await admin.locator( '.flexo-plans-table tr', { hasText: 'Breakfast Included' } ).locator( 'a', { hasText: 'Edit' } ).click();
	await admin.waitForSelector( '#flexo-plan-form' );
	ok( ( await admin.$$( '#flexo-plan-form input[name$="[on]"]:checked' ) ).length === 2, 'edit form ticks the two rooms offering the plan' );
	await admin.fill( '[name=adjustment_value]', '10' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-plan-form [type=submit]' ) ] );
	ok( ( await text( admin, '.flexo-plans-table tr:has-text("Breakfast Included")' ) ).includes( '+10.00 € per guest per night' ), 'breakfast now +10.00 € per guest per night' );

	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-notice--error:not([hidden])' );
	ok( ( await guest.textContent( '.fb-notice' ) ).includes( 'The price for this stay is now 279.00 €' ), 'booking refused: "The price for this stay is now 279.00 €"' );
	await guest.waitForFunction( () => document.querySelector( '.fb-summary' ).textContent.includes( '279.00' ) );
	ok( true, 'summary refreshed to the new price' );
	await guest.click( '.fb-details [type=submit]' );
	await guest.waitForSelector( '.fb-success:not([hidden])' );
	ok( ( await guest.textContent( '.fb-success' ) ).includes( '279.00' ), 'second attempt books at 279.00 €' );

	section( 'Guest: room without rate plans' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2&children=0` );
	await guest.waitForSelector( '.fb-room' );
	await guest.locator( '.fb-room', { hasText: 'Plain Double' } ).locator( '.fb-room__side .fb-button' ).click();
	await guest.waitForSelector( '.fb-details:not([hidden])' );
	summary = await text( guest, '.fb-summary' );
	ok( ! summary.includes( 'Rate ' ) && summary.includes( 'Total 249.00 €' ), 'no rate line; 240 + tax 9 = 249.00 €' );

	section( 'Guest: search bar keeps the ages' );
	await guest.goto( BASE + '/home/' );
	await guest.fill( '.flexo-booking--search [name=check_in]', day( 30 ) );
	await guest.dispatchEvent( '.flexo-booking--search [name=check_in]', 'change' );
	await guest.fill( '.flexo-booking--search [name=check_out]', day( 33 ) );
	await guest.selectOption( '.flexo-booking--search [name=children]', '1' );
	await guest.selectOption( '.flexo-booking--search [name="children_ages[]"]', '4' );
	await Promise.all( [ guest.waitForNavigation(), guest.click( '.flexo-booking--search .fb-button' ) ] );
	await guest.waitForSelector( '.fb-room' );
	ok( decodeURIComponent( guest.url() ).includes( 'children_ages[]=4' ), 'ages passed to the booking page' );
	ok( '4' === await guest.inputValue( '.fb-search [name="children_ages[]"]' ), 'booking page pre-fills the age' );

	section( 'Guest: phone' );
	const phone = await browser.newPage( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
	phone.on( 'pageerror', ( e ) => errors.push( 'phone: ' + e.message ) );
	await phone.goto( `${ BASE }/booking/?check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2&children=2&children_ages[]=5&children_ages[]=10` );
	await phone.waitForSelector( '.fb-room' );
	ok( await noOverflow( phone ), 'results fit a 390 px screen' );
	await phone.locator( '.fb-room', { hasText: 'Garden Family Room' } ).locator( '.fb-room__side .fb-button' ).click();
	await phone.waitForSelector( '.fb-plans' );
	ok( await noOverflow( phone ), 'rate plan choice fits' );
	await phone.screenshot( { path: SHOTS + '/d3-phone-plans.png', fullPage: true } );
	await phone.locator( '.fb-plan', { hasText: 'Breakfast Included' } ).locator( 'button' ).click();
	await phone.waitForSelector( '.fb-details:not([hidden])' );
	await phone.click( '.fb-promo__toggle' );
	ok( await noOverflow( phone ), 'summary and promo field fit' );
	await phone.screenshot( { path: SHOTS + '/d3-phone-summary.png', fullPage: true } );

	section( 'Admin: bookings list and details' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const row = admin.locator( '.flexo-bookings-table tr', { hasText: 'Family Guest' } );
	const rowText = ( await row.textContent() ).replace( /\s+/g, ' ' );
	ok( rowText.includes( 'Half Board' ) && rowText.includes( 'DIRECT10' ) && rowText.includes( 'ages 5, 10' ), 'list: plan, promo code, children\'s ages' );
	ok( rowText.includes( 'Promo code DIRECT10: −46.20 €' ) && rowText.includes( 'Tourist tax: 13.50 €' ), 'list: price lines' );
	await Promise.all( [ admin.waitForNavigation(), row.locator( 'strong a' ).click() ] );
	const details = await text( admin, '.flexo-booking-view' );
	ok( details.includes( '2 adults, 2 children (ages 5, 10)' ) && details.includes( 'Half Board' ) && details.includes( 'Free cancellation up to 7 days' ), 'details: guests, plan, cancellation' );
	ok( details.includes( 'Subtotal 462.00 €' ) && details.includes( 'Discount −46.20 €' ) && details.includes( 'Total 429.30 €' ), 'details: full breakdown' );
	await admin.screenshot( { path: SHOTS + '/d3-admin-booking.png', fullPage: true } );

	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-promo-codes' );
	ok( ( await text( admin, '.flexo-promos-table tr:has-text("DIRECT10")' ) ).includes( '0 confirmed bookings' ), 'promo list: a pending request is not a use' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	await Promise.all( [ admin.waitForNavigation(), admin.locator( '.flexo-bookings-table tr', { hasText: 'Family Guest' } ).locator( 'a', { hasText: 'Confirm' } ).click() ] );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-promo-codes' );
	ok( ( await text( admin, '.flexo-promos-table tr:has-text("DIRECT10")' ) ).includes( '1 confirmed bookings' ), 'after confirming: used 1' );
	ok( ( await text( admin, '.flexo-promos-table tr:has-text("OLD")' ) ).includes( 'Expired' ), 'expired code marked "Expired"' );

	section( 'Admin: promo code form' );
	await admin.click( '.page-title-action' );
	await admin.waitForSelector( '#flexo-promo-form' );
	await admin.fill( '[name=code]', 'spring5' );
	await admin.fill( '[name=discount_value]', '150' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-promo-form [type=submit]' ) ] );
	ok( ( await text( admin, '.notice-error' ) ).includes( 'between 0 and 100%' ), 'invalid discount: clear error' );
	ok( 'spring5' === await admin.inputValue( '[name=code]' ), 'form keeps what was typed' );
	await admin.fill( '[name=discount_value]', '5' );
	await admin.selectOption( '[name=discount_type]', 'fixed' );
	await admin.fill( '[name=max_uses]', '20' );
	await admin.check( '.flexo-check:has-text("Garden Family Room") input' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#flexo-promo-form [type=submit]' ) ] );
	const spring = await text( admin, '.flexo-promos-table tr:has-text("SPRING5")' );
	ok( spring.includes( '−5.00 €' ) && spring.includes( 'Rooms: Garden Family Room' ) && spring.includes( '0 of 20' ), 'saved in capitals with its conditions: ' + spring.slice( 0, 90 ) );

	section( 'Admin: add booking with plan, ages and code' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-new' );
	await admin.selectOption( '#fb-room', { label: 'Garden Family Room' } );
	await admin.fill( '[name=check_in]', day( 40 ) );
	await admin.fill( '[name=check_out]', day( 42 ) );
	await admin.fill( '[name=children_ages]', '8' );
	await admin.selectOption( '#fb-plan', { label: 'Room Only' } );
	await admin.fill( '#fb-promo', 'spring5' );
	await admin.fill( '#fb-name', 'Phone Guest' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '[type=submit]' ) ] );
	const added = await text( admin, '.flexo-booking-view' );
	// 2 nights × 100 − 5 + tax (2 adults × 2 × 1.50 + child 8 × 2 × 1.50) = 204.00.
	ok( added.includes( 'Booking added' ) && added.includes( '2 adults, 1 child (ages 8)' ) && added.includes( 'Room Only' ) && added.includes( 'SPRING5' ) && added.includes( 'Total 204.00 €' ), 'staff booking: ages, plan, code, 204.00 €' );

	section( 'Admin: rooms, settings, CSV, calendar' );
	await admin.goto( BASE + '/wp-admin/edit.php?post_type=flexo_room' );
	await admin.locator( 'a.row-title', { hasText: 'Garden Family Room' } ).click();
	await admin.waitForSelector( '#flexo-room-details' );
	const box = await text( admin, '#flexo-room-details' );
	ok( '2' === await admin.inputValue( '#flexo-max-adults' ) && box.includes( 'Use different child prices' ) && box.includes( 'Half Board' ), 'room screen: max adults, child prices, its rate plans' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings' );
	const tabs = await text( admin, '.nav-tab-wrapper' );
	ok( tabs.includes( 'Children' ) && tabs.includes( 'Tourist tax' ), 'settings tabs: Children, Tourist tax' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=children' );
	await admin.fill( '#fb-child-free', '2' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	ok( ( await text( admin, '.form-table' ) ).includes( 'Now: Under 2: free · 2–11: 50%' ), 'children tab saved, explained in plain words' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=tourist_tax' );
	ok( 'exempt' === await admin.$eval( '[name="flexo_booking_settings[tourist_tax_children]"]:checked', ( e ) => e.value ) && '7' === await admin.inputValue( '[name="flexo_booking_settings[tourist_tax_exempt_under]"]' ), 'tourist tax tab shows the saved exemption' );
	const menuItems = await menu( admin );
	ok( menuItems.includes( 'Rate plans' ) && menuItems.includes( 'Promo codes' ), 'menu: Rate plans, Promo codes' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	const csvHref = await admin.getAttribute( 'a.page-title-action:has-text("Export CSV")', 'href' );
	const csv = await ( await admin.request.get( csvHref ) ).text();
	const header = csv.split( '\n' )[ 0 ];
	ok( [ 'Children ages', 'Rate plan', 'Promo code', 'Discount', 'Tourist tax' ].every( ( h ) => header.includes( h ) ) && csv.includes( 'DIRECT10' ) && csv.includes( '"5, 10"' ), 'CSV: new columns and values' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-calendar&month=' + day( 30 ).slice( 0, 7 ) );
	const dialogs = await admin.$$eval( '.fbc-item[data-dialog]', ( els ) => els.map( ( e ) => e.getAttribute( 'data-dialog' ) ).join( ' ' ) );
	ok( dialogs.includes( 'Half Board' ) && dialogs.includes( 'DIRECT10' ) && dialogs.includes( 'ages 5, 10' ), 'calendar details show plan, code and ages' );

	section( 'Features off: invisible' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	for ( const f of [ 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ] ) {
		await admin.uncheck( `input[name="features[]"][value=${ f }]` );
	}
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	const menuOff = await menu( admin );
	ok( ! menuOff.includes( 'Rate plans' ) && ! menuOff.includes( 'Promo codes' ), 'menu items gone' );
	ok( ! ( await text( admin, '.nav-tab-wrapper' ) ).includes( 'Children' ), 'settings tabs gone' );
	await guest.goto( `${ BASE }/booking/?check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2&children=0` );
	await guest.waitForSelector( '.fb-room' );
	ok( ( await guest.$$( '.fb-ages' ) ).length === 0, 'guest form: adults and children counts only, as before' );
	await guest.locator( '.fb-room', { hasText: 'Garden Family Room' } ).locator( '.fb-room__side .fb-button' ).click();
	await guest.waitForSelector( '.fb-details:not([hidden])' );
	summary = await text( guest, '.fb-summary' );
	ok( ( await guest.$$( '.fb-plans, .fb-promo' ) ).length === 0 && ! summary.includes( 'Tourist tax' ) && summary.includes( '300.00 €' ), 'no plan choice, no promo field, no tax: dates → room → details (300.00 €)' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking' );
	ok( ( await text( admin, '.flexo-bookings-table' ) ).includes( 'Promo code DIRECT10: −46.20 €' ), 'existing bookings keep their stored breakdown' );
	await admin.goto( BASE + '/wp-admin/admin.php?page=flexo-booking-settings&tab=features' );
	for ( const f of [ 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ] ) {
		await admin.check( `input[name="features[]"][value=${ f }]` );
	}
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#submit' ) ] );
	ok( ( await menu( admin ) ).includes( 'Rate plans' ), 'switched back on' );

	ok( errors.length === 0, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( '; ' ) : '' ) );
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	await browser.close();
	process.exit( fail ? 1 : 0 );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
