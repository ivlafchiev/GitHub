/**
 * Admin UI audit screenshots: every Flexo Booking admin screen and state at
 * the given widths, plus measurements (overflow, sidebar styles, font sizes,
 * button heights, radii) in <OUT>/../<REPORT>-metrics.json.
 *
 *   wp eval-file tests/ui/audit-seed.php    (realistic data, every feature on)
 *   OUT=flexo-booking/ui-audit/before PENDING=<booking id> ROOM=<room id> \
 *   WIDTHS=1280,1440,1920,390 [ONLY=today,…] [PREFIX=bg-] node tests/ui/audit-shots.js
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const BASE = 'http://localhost:8092';
const OUT = process.env.OUT;
const WIDTHS = ( process.env.WIDTHS || '1280,1440,1920' ).split( ',' ).map( Number );
const ONLY = process.env.ONLY ? process.env.ONLY.split( ',' ) : null;
const A = 'wp-admin/admin.php?page=';
const P = process.env.PENDING, R = process.env.ROOM;
// [ name, url, mobile?, action? ]
const screens = [
	[ 'wp-dashboard', 'wp-admin/index.php' ],
	[ 'today', A + 'flexo-booking', 1 ],
	[ 'bookings-list', A + 'flexo-booking-list', 1 ],
	[ 'bookings-list-empty-search', A + 'flexo-booking-list&s=zzzz-nobody' ],
	[ 'booking-details', A + 'flexo-booking-list&booking=' + P, 1 ],
	[ 'booking-details-cancel-dialog', A + 'flexo-booking-list&booking=' + P, 0, async ( p ) => { const b = p.locator( '[data-flexo-confirm]' ).filter( { hasText: /Cancel/ } ).first(); if ( await b.count() ) { await b.click(); await p.waitForTimeout( 400 ); } } ],
	[ 'booking-not-found', A + 'flexo-booking-list&booking=99999999' ],
	[ 'add-booking', A + 'flexo-booking-new' ],
	[ 'calendar', A + 'flexo-booking-calendar', 1 ],
	[ 'calendar-dialog', A + 'flexo-booking-calendar', 0, async ( p ) => { const b = p.locator( '.fbc-bar, [data-fbc-booking], .fbc-booking' ).first(); if ( await b.count() ) { await b.click(); await p.waitForTimeout( 500 ); } } ],
	[ 'rooms-list', 'wp-admin/edit.php?post_type=flexo_room' ],
	[ 'room-editor', 'wp-admin/post.php?action=edit&post=' + R ],
	[ 'room-editor-icon-dialog', 'wp-admin/post.php?action=edit&post=' + R, 0, async ( p ) => { const b = p.locator( '.flexo-amenity__icon, [data-flexo-icon-pick], .flexo-amenities button[aria-haspopup]' ).first(); if ( await b.count() ) { await b.click(); await p.waitForTimeout( 500 ); } } ],
	[ 'add-room', 'wp-admin/post-new.php?post_type=flexo_room' ],
	[ 'seasonal-prices', A + 'flexo-booking-seasons' ],
	[ 'closed-dates', A + 'flexo-booking-closures' ],
	[ 'rates', A + 'flexo-booking-rate-plans' ],
	[ 'promo-codes', A + 'flexo-booking-promo-codes' ],
	[ 'calendar-sync', A + 'flexo-booking-sync' ],
	[ 'emails', A + 'flexo-booking-emails' ],
	[ 'emails-edit-open', A + 'flexo-booking-emails', 0, async ( p ) => { const s = p.locator( 'details summary' ).first(); if ( await s.count() ) { await s.click(); await p.waitForTimeout( 300 ); } } ],
	[ 'appearance-match', A + 'flexo-booking-appearance' ],
	[ 'appearance-custom', A + 'flexo-booking-appearance', 0, async ( p ) => { await p.locator( '[name$="[appearance_mode]"][value=custom]' ).check( { force: true } ); await p.waitForTimeout( 1500 ); } ],
	[ 'appearance-custom-booking-page-preview', A + 'flexo-booking-appearance', 0, async ( p ) => { await p.locator( '[name$="[appearance_mode]"][value=custom]' ).check( { force: true } ); const s = p.locator( '[data-scene*="preview=booking"]' ); if ( await s.count() ) await s.click(); await p.waitForTimeout( 2500 ); } ],
	[ 'settings-hotel', A + 'flexo-booking-settings&tab=general' ],
	[ 'settings-hotel-saved-notice', A + 'flexo-booking-settings&tab=general&settings-updated=true' ],
	[ 'settings-booking-rules', A + 'flexo-booking-settings&tab=rules' ],
	[ 'settings-room-pages', A + 'flexo-booking-settings&tab=room_pages' ],
	[ 'settings-pages', A + 'flexo-booking-settings&tab=pages' ],
	[ 'settings-pages-customize-open', A + 'flexo-booking-settings&tab=pages', 0, async ( p ) => { await p.click( '[data-flexo-remember="booking"] > summary' ); await p.waitForTimeout( 2500 ); } ],
	[ 'settings-payments', A + 'flexo-booking-settings&tab=payments' ],
	[ 'settings-taxes', A + 'flexo-booking-settings&tab=taxes' ],
	[ 'settings-privacy', A + 'flexo-booking-settings&tab=privacy' ],
	[ 'settings-tracking', A + 'flexo-booking-settings&tab=tracking' ],
	[ 'settings-features', A + 'flexo-booking-settings&tab=features' ],
	[ 'settings-health', A + 'flexo-booking-settings&tab=health' ],
	[ 'import-export', A + 'flexo-booking-tools' ],
	[ 'bring-in-rooms', A + 'flexo-booking-import-rooms' ],
	[ 'help', A + 'flexo-booking-help' ],
	[ 'agency', A + 'flexo-booking-agency' ],
	[ 'wizard-start', A + 'flexo-booking-wizard' ],
	...[ 'hotel', 'mode', 'room', 'page', 'emails', 'look', 'test' ].map( ( s ) => [ 'wizard-' + s, A + 'flexo-booking-wizard&step=' + s ] ),
];

const measure = () => {
	const vw = document.documentElement.clientWidth;
	const body = document.getElementById( 'wpbody-content' ) || document.body;
	const over = [];
	body.querySelectorAll( '*' ).forEach( ( n ) => {
		const r = n.getBoundingClientRect();
		if ( r.width > 0 && r.right > vw + 1 && getComputedStyle( n ).position !== 'fixed' ) {
			over.push( ( n.className && typeof n.className === 'string' ? n.tagName.toLowerCase() + '.' + n.className.trim().split( /\s+/ ).slice( 0, 2 ).join( '.' ) : n.tagName.toLowerCase() ) + '→' + Math.round( r.right - vw ) );
		}
	} );
	const sub = document.querySelector( '#adminmenu .wp-submenu a' );
	const ui = document.querySelector( '#wpbody-content' );
	const sizes = new Set(), radii = new Set(), btnH = new Set(), fonts = new Set();
	if ( ui ) {
		ui.querySelectorAll( 'h1,h2,h3,h4,p,label,td,th,a,span,button,input,select' ).forEach( ( n ) => { const c = getComputedStyle( n ); if ( n.offsetParent ) { sizes.add( c.fontSize ); fonts.add( c.fontFamily.split( ',' )[ 0 ] ); } } );
		ui.querySelectorAll( '.button, button, input[type=submit]' ).forEach( ( n ) => { if ( n.offsetParent ) { btnH.add( Math.round( n.getBoundingClientRect().height ) ); radii.add( getComputedStyle( n ).borderRadius ); } } );
	}
	return {
		docOverflow: document.documentElement.scrollWidth - vw,
		over: [ ...new Set( over ) ].slice( 0, 12 ),
		submenuPad: sub ? getComputedStyle( sub ).paddingLeft + '/' + getComputedStyle( sub ).fontSize : null,
		fontSizes: [ ...sizes ].sort(),
		buttonHeights: [ ...btnH ].sort( ( a, b ) => a - b ),
		buttonRadii: [ ...radii ],
		fonts: [ ...fonts ],
		notices: document.querySelectorAll( '#wpbody-content .notice' ).length,
	};
};

( async () => {
	const b = await chromium.launch();
	const report = {};
	for ( const w of WIDTHS ) {
		const ctx = await b.newContext( { viewport: { width: w, height: w <= 390 ? 844 : 1000 }, deviceScaleFactor: 1 } );
		const p = await ctx.newPage();
		const errs = [];
		p.on( 'pageerror', ( e ) => { if ( ! /Unexpected token '<'/.test( e.message ) ) errs.push( e.message ); } );
		await p.goto( BASE + '/wp-login.php' );
		await p.fill( '#user_login', 'admin' ); await p.fill( '#user_pass', 'admin' );
		await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
		for ( const [ name, url, mobile, action ] of screens ) {
			if ( ONLY && ! ONLY.includes( name ) ) continue;
			if ( w <= 390 && ! mobile ) continue;
			errs.length = 0;
			try {
				await p.goto( BASE + '/' + url, { waitUntil: 'load', timeout: 45000 } );
				await p.waitForTimeout( 600 );
				if ( action ) await action( p );
				const m = await p.evaluate( measure );
				m.errors = [ ...errs ];
				report[ name + '@' + w ] = m;
				await p.screenshot( { path: `${ OUT }/${ process.env.PREFIX || '' }${ name }@${ w }.jpg`, fullPage: true, type: 'jpeg', quality: 62 } );
			} catch ( e ) {
				report[ name + '@' + w ] = { error: e.message.split( '\n' )[ 0 ] };
			}
		}
		await ctx.close();
	}
	fs.writeFileSync( `${ OUT }/../${ process.env.REPORT || 'before' }-metrics.json`, JSON.stringify( report, null, 1 ) );
	await b.close();
} )();
