/**
 * Day 7 browser tests: the room page (content, gallery, lightbox, "from"
 * price, structured data), the booking box (check, book, nothing free,
 * dates from the address), the phone bar, availability tags, hidden rooms,
 * phone and tablet widths (360/390/768) and the room editor.
 *
 *   wp eval-file tests/e2e/seed-day7.php      → prints D0
 *   BASE=http://localhost:8092 D0=<D0> SHOTS=/tmp/shots \
 *   WP="php wp-cli.phar --allow-root --path=<site>" node tests/e2e/day7.js
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
const wp = ( php ) => {
	const code = 'ob_start(); ' + php + ' $flexo_out = ob_get_clean(); echo "@@FLEXO@@" . $flexo_out . "@@FLEXO@@";';
	const out = execSync( `${ WP } eval '${ code.replace( /'/g, "'\\''" ) }' 2>/dev/null` ).toString();
	const m = /@@FLEXO@@([\s\S]*?)@@FLEXO@@/.exec( out );
	return m ? m[ 1 ].trim() : '';
};
const noOverflow = ( p ) => p.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 1 );
const ROOM = '/rooms/deluxe-sea-view/';

async function login( browser, user, width = 1400 ) {
	const p = await browser.newPage( { viewport: { width, height: 1000 } } );
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', user );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	return p;
}

( async () => {
	const browser = await chromium.launch();
	const errors = [];
	const page = async ( opts = {} ) => {
		const p = await browser.newPage( Object.assign( { viewport: { width: 1280, height: 900 } }, opts ) );
		p.on( 'pageerror', ( e ) => errors.push( e.message ) );
		return p;
	};

	section( 'Room page' );
	let p = await page();
	let res = await p.goto( BASE + ROOM, { waitUntil: 'networkidle' } );
	ok( 200 === res.status(), 'room page answers 200' );
	const body = await p.innerText( 'body' );
	ok( body.includes( 'Deluxe Sea View Suite' ) && body.includes( 'Wake up to the sound of the sea' ), 'name and description' );
	ok( body.includes( 'Rain shower with a view' ) && body.includes( 'Free Wi-Fi' ), 'amenities (own and preset)' );
	ok( body.includes( 'Distance to the beach' ) && body.includes( '150 m' ), 'more details' );
	ok( body.includes( '42 m²' ) && /Up to 4 guests/.test( body ), 'size and guests' );
	ok( /from €\s?180|from 180/.test( body ), '"from" price shown' );
	ok( 4 === await p.locator( '.flexo-room-gallery img' ).count(), 'gallery: main photo + 3' );
	const ld = await p.$$eval( 'script[type="application/ld+json"]', ( s ) => s.map( ( x ) => x.textContent ) );
	const graph = ld.map( ( x ) => JSON.parse( x ) ).flatMap( ( x ) => x[ '@graph' ] || [ x ] );
	ok( 1 === graph.filter( ( n ) => 'HotelRoom' === n[ '@type' ] ).length, 'one HotelRoom in the structured data' );
	ok( /Deluxe Sea View Suite/.test( await p.title() ), 'page title' );

	// Lightbox: opens on a photo, arrow keys move, Escape closes.
	const first = p.locator( '.flexo-room-gallery a' ).first();
	if ( await first.count() ) {
		await first.click();
		await p.waitForTimeout( 300 );
		const lb = p.locator( '.flexo-lightbox' );
		ok( await lb.isVisible(), 'lightbox opens' );
		await p.keyboard.press( 'ArrowRight' );
		await p.keyboard.press( 'Escape' );
		await p.waitForTimeout( 200 );
		ok( ! await lb.isVisible(), 'lightbox closes with Escape' );
	} else {
		ok( false, 'gallery links for the lightbox' );
	}

	section( 'Booking box' );
	const box = p.locator( '[data-flexo-booking-box]' );
	ok( await box.isVisible(), 'booking box on the room page' );
	await box.locator( '.fb-field--action .fb-button' ).click();
	ok( await box.locator( '.fb-picker' ).isVisible(), 'without dates the date picker opens' );
	await p.goto( BASE + ROOM + `?check_in=${ day( 10 ) }&check_out=${ day( 12 ) }&adults=2`, { waitUntil: 'networkidle' } );
	await box.locator( '.fb-box__status' ).first().waitFor();
	await p.waitForTimeout( 500 );
	ok( /Available/.test( await box.locator( '.fb-box__status' ).first().innerText() ), 'dates from the address are checked: available' );
	const total = ( await box.locator( '.fb-box__total-amount' ).innerText() ).replace( /\s+/g, ' ' );
	ok( /\d/.test( total ), 'total shown: ' + total );
	ok( /2 nights/.test( await box.locator( '.fb-box__stay' ).innerText() ), 'stay text: 2 nights' );
	const href = await box.locator( '.fb-box__book' ).getAttribute( 'href' );
	ok( href.includes( 'room=deluxe-sea-view' ) && href.includes( 'check_in=' + day( 10 ) ), 'Book now keeps room and dates' );
	await p.screenshot( { path: SHOTS + '/d7-box-1280.png', clip: await box.boundingBox() } );
	await Promise.all( [ p.waitForNavigation(), box.locator( '.fb-box__book' ).click() ] );
	await p.waitForLoadState( 'networkidle' );
	await p.waitForTimeout( 800 );
	ok( await p.locator( 'input[name="guest_name"]' ).isVisible(), 'booking page opens on the details step' );
	const summary = ( await p.locator( '.fb-summary' ).first().innerText() ).replace( /\s+/g, ' ' );
	ok( summary.includes( 'Deluxe Sea View Suite' ) && summary.includes( total.replace( /^from /i, '' ) ), 'same room and total on the booking page' );
	await p.close();

	section( 'Nothing free' );
	p = await page( { viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true } );
	await p.goto( BASE + `/rooms/sea-double/?check_in=${ day( 20 ) }&check_out=${ day( 22 ) }&adults=2`, { waitUntil: 'networkidle' } );
	const box2 = p.locator( '[data-flexo-booking-box]' );
	await box2.locator( '.fb-box__status' ).first().waitFor();
	await p.waitForTimeout( 1200 );
	ok( /Not available|Sold out/.test( await box2.locator( '.fb-box__status' ).first().innerText() ), 'sold-out dates: not available' );
	ok( ( await box2.locator( '.fb-alt' ).count() ) > 0, 'nearby dates offered' );
	ok( ( await box2.locator( '.fb-box__others' ).getAttribute( 'href' ) ).includes( 'check_in=' + day( 20 ) ), 'link to other rooms keeps the dates' );
	await box2.locator( '.fb-alt' ).first().click();
	await p.waitForTimeout( 1200 );
	ok( /Available/.test( await box2.locator( '.fb-box__status' ).first().innerText() ), 'a nearby date is free' );
	await p.close();

	section( 'Phone bar and widths' );
	for ( const w of [ 360, 390, 768 ] ) {
		p = await page( { viewport: { width: w, height: 800 }, hasTouch: w < 768, isMobile: w < 768 } );
		await p.goto( BASE + ROOM, { waitUntil: 'networkidle' } );
		ok( await noOverflow( p ), `${ w }px: no sideways scrolling` );
		await p.screenshot( { path: `${ SHOTS }/d7-room-${ w }.png`, fullPage: true } );
		const bar = p.locator( '[data-flexo-room-bar]' );
		if ( w < 768 ) {
			ok( await bar.isVisible(), `${ w }px: "Check availability" bar visible` );
			await bar.locator( 'a' ).click();
			await p.waitForTimeout( 1200 );
			ok( await bar.evaluate( ( el ) => el.classList.contains( 'is-away' ) ), `${ w }px: bar hides at the booking box` );
			ok( /fb-date/.test( await p.evaluate( () => document.activeElement.className ) ), `${ w }px: focus on the dates` );
		} else {
			ok( ! await bar.isVisible(), `${ w }px: no phone bar` );
		}
		await p.close();
	}

	section( 'Availability tags' );
	p = await page();
	const before = errors.length;
	res = await p.goto( BASE + '/d7-availability/', { waitUntil: 'networkidle' } );
	if ( 200 === res.status() ) {
		// A source checkout of Elementor has no built frontend scripts; their errors are not ours.
		const built = await p.evaluate( async ( base ) => /javascript/.test( ( await fetch( base + '/wp-content/plugins/elementor/assets/js/frontend.min.js', { redirect: 'manual' } ) ).headers.get( 'content-type' ) || '' ), BASE );
		await p.waitForTimeout( 600 );
		const empty = await p.$$eval( '[data-flexo-availability]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		ok( 2 === empty.length && empty.every( ( t ) => '' === t ), 'without dates: nothing shown' );
		await p.goto( BASE + `/d7-availability/?check_in=${ day( 20 ) }&check_out=${ day( 22 ) }&adults=2`, { waitUntil: 'networkidle' } );
		await p.waitForTimeout( 1200 );
		const texts = await p.$$eval( '[data-flexo-availability]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		ok( /^Available/.test( texts[ 0 ] ) && /€|\d/.test( texts[ 0 ] ), 'free room: available with total – ' + texts[ 0 ] );
		ok( /Not available/.test( texts[ 1 ] ), 'sold-out room: not available' );
		if ( ! built ) {
			errors.splice( before );
		}
	} else {
		console.log( '  (skipped: Elementor not active)' );
	}
	await p.close();

	section( 'Hidden room' );
	p = await page();
	res = await p.goto( BASE + '/rooms/secret-loft/' );
	ok( 404 === res.status(), 'hidden room: 404 for guests' );
	await p.goto( BASE + '/booking/', { waitUntil: 'networkidle' } );
	const rooms = await p.evaluate( async ( base ) => ( await ( await fetch( base + '/wp-json/flexo-booking/v1/rooms' ) ).json() ), BASE );
	const slugs = ( rooms.rooms || rooms ).map( ( r ) => r.slug || r.id );
	ok( ! slugs.includes( 'secret-loft' ), 'hidden room not in the booking form list' );
	await p.goto( BASE + `/booking/?room=secret-loft&check_in=${ day( 10 ) }&check_out=${ day( 12 ) }&adults=2`, { waitUntil: 'networkidle' } );
	await p.waitForTimeout( 1200 );
	ok( ( await p.innerText( 'body' ) ).includes( 'Secret Loft' ), 'still bookable through its booking link' );
	await p.close();
	const admin = await login( browser, 'admin' );
	res = await admin.goto( BASE + '/rooms/secret-loft/' );
	ok( 200 === res.status(), 'hidden room: staff can still see its page' );

	section( 'Room editor' );
	const id = wp( 'echo get_page_by_path( "deluxe-sea-view", OBJECT, "flexo_room" )->ID;' );
	for ( const w of [ 1440, 390 ] ) {
		await admin.setViewportSize( { width: w, height: 1000 } );
		await admin.goto( BASE + `/wp-admin/post.php?post=${ id }&action=edit`, { waitUntil: 'networkidle' } );
		ok( await noOverflow( admin ), `editor ${ w }px: no sideways scrolling` );
		await admin.screenshot( { path: `${ SHOTS }/d7-editor-${ w }.png`, fullPage: true } );
	}
	await admin.setViewportSize( { width: 1440, height: 1000 } );
	ok( 6 === await admin.locator( '[data-flexo-amenities] > li' ).count(), 'six amenities listed' );
	ok( 3 === await admin.locator( '[data-flexo-gallery] .flexo-gallery__item, [data-flexo-gallery] li[data-id]' ).count(), 'three gallery photos' );
	const chip = admin.locator( '.flexo-preset-chip[aria-pressed="false"]' ).first();
	const chipLabel = await chip.getAttribute( 'data-label' );
	await chip.click();
	ok( 7 === await admin.locator( '[data-flexo-amenities] > li' ).count(), 'a preset chip adds the amenity' );
	await admin.fill( '[data-flexo-own-amenity]', 'Telescope on the terrace' );
	await admin.click( '[data-flexo-own-amenity-add]' );
	await admin.click( '[data-flexo-detail-suggest]' );
	await admin.locator( '[data-flexo-details] > li' ).last().locator( 'input[name="flexo_detail_value[]"]' ).fill( 'On request' );
	await Promise.all( [ admin.waitForNavigation(), admin.click( '#publish' ) ] );
	const saved = JSON.parse( wp( `echo wp_json_encode( array( wp_list_pluck( Flexo_Booking_Room_Content::amenity_items( ${ id } ), "label" ), Flexo_Booking_Room_Content::details( ${ id } ) ) );` ) );
	ok( saved[ 0 ].includes( 'Telescope on the terrace' ) && 8 === saved[ 0 ].length, 'saved: preset + own amenity (' + chipLabel + ')' );
	ok( saved[ 1 ].some( ( d ) => 'On request' === d.value ), 'saved: new detail' );
	const icon = wp( `foreach ( Flexo_Booking_Room_Content::amenity_items( ${ id } ) as $i ) { if ( "Telescope on the terrace" === $i["label"] ) { echo $i["icon"]; } }` );
	ok( '' !== icon, 'own amenity got an icon: ' + icon );
	ok( ( await admin.innerText( 'body' ) ).includes( 'Room saved.' ) || ( await admin.innerText( 'body' ) ).includes( 'Room published' ), 'friendly save message' );
	await admin.close();

	ok( 0 === errors.length, 'no JavaScript errors' + ( errors.length ? ': ' + errors.join( ' | ' ) : '' ) );
	await browser.close();
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )();
