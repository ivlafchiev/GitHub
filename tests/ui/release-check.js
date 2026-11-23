/**
 * 1.10.0 release check, Parts 6–12 in the browser (after tests/ui/audit-seed.php,
 * tests/ui/layout-seed.php and tests/ui/widgets-seed.php):
 *
 *   BASE=http://localhost:8092 OUT=flexo-booking/ui-audit LABEL=after WP="wp --path=…" node tests/ui/release-check.js
 *   [ONLY=cards,lists,gallery,buttons,contact,tabs,timeline] [MAIL_LOG=…/mail.log]
 *
 * - Part 6, room cards: each amenity's own icon, one tick, or no icon.
 * - Part 7, room lists: the icon box is exactly the chosen size (px and em,
 *   bundled SVG, uploaded file, icon font), also with theme rules for svg/img.
 * - Part 8, gallery: arrows keep their look under the site's button rules,
 *   a ring only for keyboard focus, dots / lines / "2 / 5", dots over the photos.
 * - Part 9, buttons and fields: Appearance corners (pill, fields like the buttons).
 * - Part 11, contact form and tabs: messages under their row, sending, tabs by keyboard and #contact.
 * - Part 12, booking history: every dot on the rail axis and centred on its
 *   first line, the rail only between the first and the last dot (0.5 px), at
 *   100 %, 125 % and 200 % and with theme box-sizing rules.
 *
 * Screenshots to OUT/part-N/LABEL/, results to OUT/release-LABEL.json. With
 * LABEL=before (the previous code) missing features are skipped, not failed.
 */
const { chromium } = require( 'playwright' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.BASE || 'http://localhost:8092';
const OUT = process.env.OUT || 'flexo-booking/ui-audit';
const LABEL = process.env.LABEL || 'after';
const ONLY = ( process.env.ONLY || 'cards,lists,gallery,buttons,contact,tabs,timeline' ).split( ',' );
const WP = process.env.WP || '';
const BEFORE = 'before' === LABEL;
const SUB = 0.5;
const controls = process.env.CONTROLS ? JSON.parse( fs.readFileSync( process.env.CONTROLS, 'utf8' ) ) : { room: 'demo-garden-double', booking: 0 };

const day = ( n ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() + n );
	return d.toISOString().slice( 0, 10 );
};
const STAY = `check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2`;

let pass = 0, fail = 0, skip = 0;
const report = { label: LABEL, results: [] };
const ok = ( cond, name, detail ) => {
	cond ? pass++ : fail++;
	report.results.push( { name, ok: !! cond, detail: detail || '' } );
	if ( ! cond || process.env.VERBOSE ) {
		console.log( ( cond ? '  PASS ' : '  FAIL ' ) + name + ( detail ? ' – ' + detail : '' ) );
	}
};
const skipped = ( name, why ) => {
	skip++;
	report.results.push( { name, ok: null, detail: 'skipped: ' + why } );
};

const shotDir = ( part ) => {
	const d = path.join( OUT, `part-${ part }`, LABEL );
	fs.mkdirSync( d, { recursive: true } );
	return d;
};
async function shot( page, part, name, sel ) {
	const file = path.join( shotDir( part ), name + '.png' );
	try {
		await ( sel ? page.locator( sel ).first().screenshot( { path: file } ) : page.screenshot( { path: file } ) );
	} catch ( e ) {
		console.log( '  (no screenshot ' + name + ')' );
	}
}
const wp = ( args ) => ( WP ? execSync( `${ WP } ${ args }`, { stdio: [ 'ignore', 'pipe', 'ignore' ] } ).toString() : '' );
const b64 = ( obj ) => encodeURIComponent( Buffer.from( JSON.stringify( obj ) ).toString( 'base64' ) );

async function login( page ) {
	await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'networkidle' } );
	if ( await page.locator( '#user_login' ).count() ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'admin' );
		await page.click( '#wp-submit' );
		await page.waitForLoadState( 'networkidle' );
	}
}

/* ------------------------------------------------------------------ */

async function cards( browser ) {
	console.log( '### Part 6: amenities on the room cards' );
	const page = await browser.newPage( { viewport: { width: 1280, height: 1100 } } );
	const masks = {};
	for ( const mode of [ 'icons', 'tick', 'none' ] ) {
		await page.goto( `${ BASE }/fxr-cards-${ mode }/?${ STAY }`, { waitUntil: 'networkidle' } );
		await page.waitForSelector( '.fb-room', { timeout: 15000 } ).catch( () => {} );
		await page.waitForTimeout( 300 );
		masks[ mode ] = await page.evaluate( () => [ ...document.querySelectorAll( '.fb-room__amenity:not(.is-extra) .fb-icon' ) ].map( ( i ) => {
			const cs = getComputedStyle( i );
			return { shown: 'none' !== cs.display && i.getBoundingClientRect().width > 0, mask: cs.webkitMaskImage || cs.maskImage };
		} ) );
		await shot( page, 6, `cards-${ mode }`, '.fb-room' );
	}
	const icons = masks.icons, tick = masks.tick, none = masks.none;
	ok( icons.length > 0 && icons.every( ( m ) => m.shown ) && new Set( icons.map( ( m ) => m.mask ) ).size > 1, 'each amenity\'s own icon', `${ new Set( icons.map( ( m ) => m.mask ) ).size } different icons` );
	if ( BEFORE ) {
		skipped( 'a tick or no icon', 'not in this version' );
	} else {
		ok( tick.length > 0 && tick.every( ( m ) => m.shown ) && 1 === new Set( tick.map( ( m ) => m.mask ) ).size, 'one simple tick for every amenity' );
		ok( none.length > 0 && none.every( ( m ) => ! m.shown ), 'no icon' );
	}
	await page.close();
}

/* ------------------------------------------------------------------ */

async function lists( browser ) {
	console.log( '### Part 7: icon size in the room lists' );
	const page = await browser.newPage( { viewport: { width: 1100, height: 900 } } );
	const cases = [
		[ 'amenities, 32 px', 'flexo-room-amenities', '32px', null ],
		[ 'amenities, 2em', 'flexo-room-amenities', '2em', null ],
		[ 'details, 28 px', 'flexo-room-details', '28px', null ],
		[ 'details, own icon (icon font), 28 px', 'flexo-room-details', '28px', { fact_icon_size: { value: 'fas fa-star', library: 'fa-solid' }, icon_size: { value: 'fas fa-star', library: 'fa-solid' } } ],
	];
	for ( const [ label, widget, size, extra ] of cases ) {
		await page.goto( `${ BASE }/fxw-ctl-${ widget }/?fxw=${ b64( Object.assign( { room: controls.room }, extra || {} ) ) }`, { waitUntil: 'networkidle' } );
		if ( ! await page.locator( '.flexo-room-list' ).count() ) {
			skipped( `room list: ${ label }`, 'no list' );
			continue;
		}
		// What Elementor writes for "Size" (Style → Icon), with the site's svg/img rules on the page.
		await page.evaluate( ( s ) => {
			document.getElementById( 'fxw-ctl' ).textContent = `.elementor-99 .elementor-element.elementor-element-fxw1 .flexo-room-list{--flexo-icon-size:${ s };}`;
		}, size );
		await page.waitForTimeout( 100 );
		const m = await page.evaluate( ( s ) => {
			const list = document.querySelector( '.flexo-room-list' );
			const item = list.querySelector( '.flexo-room-list__item' );
			const fs = parseFloat( getComputedStyle( item ).fontSize );
			const want = s.endsWith( 'em' ) ? parseFloat( s ) * fs : parseFloat( s );
			const bad = [];
			list.querySelectorAll( '.flexo-room-list__icon' ).forEach( ( box ) => {
				const inner = box.firstElementChild;
				[ box, inner ].forEach( ( el ) => {
					if ( ! el ) {
						return;
					}
					const r = el.getBoundingClientRect();
					if ( Math.abs( r.width - want ) > 0.5 || Math.abs( r.height - want ) > 0.5 ) {
						bad.push( `${ el.tagName.toLowerCase() }.${ String( el.className.baseVal !== undefined ? el.className.baseVal : el.className ).split( ' ' )[ 0 ] } ${ r.width.toFixed( 1 ) }×${ r.height.toFixed( 1 ) }` );
					}
				} );
			} );
			return { want, bad, count: list.querySelectorAll( '.flexo-room-list__icon' ).length };
		}, size );
		ok( m.count > 0 && ! m.bad.length, `room list: ${ label } – every icon exactly ${ m.want.toFixed( 1 ) } px`, m.bad.slice( 0, 3 ).join( '; ' ) );
		await shot( page, 7, `list-${ label.replace( /[^a-z0-9]+/gi, '-' ) }`, '.elementor-element-fxw1' );
	}
	// Room details: the Size slider exists only once (it was dropped next to the Size fact's own icon).
	if ( process.env.CONTROLS ) {
		const ids = ( controls.widgets[ 'flexo-room-details' ] || { controls: [] } ).controls.map( ( c ) => c.id );
		ok( 1 === ids.filter( ( id ) => 'icon_size' === id ).length, 'Room details: one "icon_size" control (the icon Size slider)' );
	}
	await page.close();
}

/* ------------------------------------------------------------------ */

async function gallery( browser ) {
	console.log( '### Part 8: gallery arrows and dots' );
	const page = await browser.newPage( { viewport: { width: 1100, height: 900 } } );
	const url = ( extra, cls ) => `${ BASE }/fxw-ctl-flexo-room-gallery/?fxw=${ b64( Object.assign( { room: controls.room }, extra || {} ) ) }${ cls ? '&fxw_class=' + encodeURIComponent( cls ) : '' }`;
	await page.goto( url(), { waitUntil: 'networkidle' } );
	if ( ! await page.locator( '.flexo-room-gallery__arrow--next' ).count() ) {
		skipped( 'gallery', 'no carousel' );
		await page.close();
		return;
	}
	await shot( page, 8, 'gallery-default', '.elementor-element-fxw1' );
	await page.addStyleTag( { content: '*,*::before,*::after{transition:none!important}' } );
	// The arrow under the site's button rules: hover and focus as without them.
	const arrow = '.flexo-room-gallery__arrow--next';
	const look = async () => page.evaluate( ( sel ) => {
		const cs = getComputedStyle( document.querySelector( sel ) );
		return [ cs.backgroundColor, cs.color, cs.borderTopWidth, cs.borderTopLeftRadius, cs.paddingTop, cs.width, cs.height, cs.letterSpacing ].join( '|' );
	}, arrow );
	const states = async () => {
		await page.mouse.move( 0, 0 );
		const rest = await look();
		await page.hover( arrow );
		const hover = await look();
		await page.mouse.move( 0, 0 );
		await page.focus( arrow );
		const focus = await look();
		await page.evaluate( () => document.activeElement.blur() );
		return { rest, hover, focus };
	};
	const withKit = await states();
	const rules = await page.evaluate( () => {
		const st = document.getElementById( 'fxl-kit-controls' );
		const css = st.textContent;
		st.textContent = '';
		return css;
	} );
	const without = await states();
	await page.evaluate( ( css ) => {
		document.getElementById( 'fxl-kit-controls' ).textContent = css;
	}, rules );
	ok( withKit.rest === without.rest && withKit.hover === without.hover && withKit.focus === without.focus, 'arrows keep their look under the site\'s button rules (rest, hover, focus)', JSON.stringify( withKit ) + ' vs ' + JSON.stringify( without ) );
	// A ring only for keyboard focus.
	await page.click( arrow );
	const mouseRing = await page.evaluate( ( sel ) => getComputedStyle( document.querySelector( sel ) ).outlineStyle, arrow );
	await page.evaluate( () => document.activeElement.blur() );
	await page.focus( '.flexo-room-gallery__track' );
	let keyRing = 'none';
	for ( let i = 0; i < 20; i++ ) {
		await page.keyboard.press( 'Tab' );
		const st = await page.evaluate( () => [ document.activeElement.className, getComputedStyle( document.activeElement ).outlineStyle ] );
		if ( String( st[ 0 ] ).includes( 'flexo-room-gallery__arrow' ) ) {
			keyRing = st[ 1 ];
			break;
		}
	}
	if ( BEFORE ) {
		skipped( 'focus ring only for the keyboard', 'checked on the new code' );
	} else {
		ok( 'none' === mouseRing && 'none' !== keyRing, 'a ring only for keyboard focus', `mouse: ${ mouseRing }, keyboard: ${ keyRing }` );
	}
	// Dots: lines, "1 / 2", over the photos.
	if ( BEFORE ) {
		skipped( 'dots as lines, count, over the photos', 'not in this version' );
		await page.close();
		return;
	}
	await page.goto( url( {}, 'flexo-gallery-dots-lines' ), { waitUntil: 'networkidle' } );
	const lineDot = await page.evaluate( () => {
		const r = getComputedStyle( document.querySelector( '.flexo-room-gallery__dot' ), '::before' );
		return [ parseFloat( r.width ), parseFloat( r.height ) ];
	} );
	ok( lineDot[ 0 ] > lineDot[ 1 ] * 3, 'dots as lines', `${ lineDot[ 0 ] }×${ lineDot[ 1 ] }` );
	await shot( page, 8, 'gallery-lines', '.elementor-element-fxw1' );
	await page.goto( url( {}, 'flexo-gallery-dots-fraction flexo-gallery-dots-at-over' ), { waitUntil: 'networkidle' } );
	const count1 = await page.textContent( '.flexo-room-gallery__fraction' );
	await page.click( arrow );
	await page.waitForTimeout( 700 );
	const count2 = await page.textContent( '.flexo-room-gallery__fraction' );
	const dotsHidden = await page.evaluate( () => [ ...document.querySelectorAll( '.flexo-room-gallery__dot' ) ].every( ( d ) => 'none' === getComputedStyle( d ).display ) );
	ok( /^1 \/ \d+$/.test( count1 ) && count1 !== count2 && dotsHidden, 'a count instead of dots ("1 / 2", then "2 / 2")', `${ count1 } → ${ count2 }` );
	const over = await page.evaluate( () => {
		const dots = document.querySelector( '.flexo-room-gallery__dots' ).getBoundingClientRect();
		const photo = document.querySelector( '.flexo-room-gallery__slide' ).getBoundingClientRect();
		return dots.bottom <= photo.bottom + 0.5 && dots.top >= photo.top;
	} );
	ok( over, 'dots over the photos' );
	await shot( page, 8, 'gallery-count-over', '.elementor-element-fxw1' );
	await page.close();
}

/* ------------------------------------------------------------------ */

async function buttons( browser ) {
	console.log( '### Part 9: button and field corners' );
	if ( BEFORE || ! WP ) {
		skipped( 'button and field corners', BEFORE ? 'not in this version' : 'needs WP' );
		return;
	}
	const saved = wp( 'option get flexo_booking_settings --format=json' );
	const set = ( values ) => wp( `eval '$s = Flexo_Booking_Settings::all(); foreach ( json_decode( base64_decode( "${ Buffer.from( JSON.stringify( values ) ).toString( 'base64' ) }" ), true ) as $k => $v ) { $s[ $k ] = $v; } update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( $s ) );'` );
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const radii = async () => page.evaluate( () => {
		const r = ( sel ) => {
			const el = document.querySelector( sel );
			return el ? getComputedStyle( el ).borderTopLeftRadius : '';
		};
		return { button: r( '.fb-search .fb-button' ), date: r( '.fb-date' ), select: r( '.fb-search select' ) };
	} );
	try {
		set( { appearance_mode: 'custom', appearance_corners: 'slight', appearance_button_corners: '', appearance_field_corners: '' } );
		await page.goto( `${ BASE }/fxl-lab/`, { waitUntil: 'networkidle' } );
		const base = await radii();
		set( { appearance_button_corners: 'pill', appearance_field_corners: 'buttons' } );
		await page.goto( `${ BASE }/fxl-lab/`, { waitUntil: 'networkidle' } );
		const pill = await radii();
		await shot( page, 9, 'pill-buttons-and-fields', '#fxl-frame .fb-search' );
		set( { appearance_button_corners: 'custom', appearance_button_corners_px: 20, appearance_field_corners: 'square' } );
		await page.goto( `${ BASE }/fxl-lab/`, { waitUntil: 'networkidle' } );
		const own = await radii();
		await shot( page, 9, 'buttons-20px-fields-square', '#fxl-frame .fb-search' );
		ok( '4.5px' === base.button && base.button === base.date, 'as before: buttons and fields 0.75 × the corners', JSON.stringify( base ) );
		ok( '999px' === pill.button && '999px' === pill.date && '999px' === pill.select, 'pill buttons, fields like the buttons', JSON.stringify( pill ) );
		ok( '20px' === own.button && '0px' === own.date && '0px' === own.select, 'exact button radius, square fields', JSON.stringify( own ) );
	} finally {
		const restore = JSON.parse( saved || '{}' );
		set( { appearance_mode: restore.appearance_mode || 'match', appearance_button_corners: '', appearance_field_corners: '', appearance_corners: restore.appearance_corners || 'slight' } );
		await page.close();
	}
}

/* ------------------------------------------------------------------ */

async function contact( browser ) {
	console.log( '### Part 11: contact form' );
	const page = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
	const res = await page.goto( `${ BASE }/fxr-contact/`, { waitUntil: 'networkidle' } );
	if ( ! await page.locator( '[data-flexo-contact]' ).count() ) {
		skipped( 'contact form', 'not in this version' );
		await page.close();
		return;
	}
	ok( 200 === res.status(), 'contact page loads' );
	const fields = () => page.evaluate( () => [ ...document.querySelectorAll( '.fb-contact .fb-field' ) ].map( ( f ) => {
		const r = f.getBoundingClientRect();
		return [ r.left, r.width, r.height ];
	} ) );
	const before = await fields();
	await shot( page, 11, 'contact', '.flexo-booking--contact' );
	await page.click( '.fb-contact [type=submit]' );
	await page.waitForTimeout( 400 );
	const after = await fields();
	const moved = before.filter( ( b, i ) => Math.abs( b[ 0 ] - after[ i ][ 0 ] ) > SUB || Math.abs( b[ 1 ] - after[ i ][ 1 ] ) > SUB || Math.abs( b[ 2 ] - after[ i ][ 2 ] ) > SUB );
	const msgs = await page.locator( '.fb-contact .fb-msg' ).count();
	ok( msgs >= 3 && ! moved.length, 'messages for the empty fields, no field moved sideways or resized', `${ msgs } messages, ${ moved.length } fields moved` );
	ok( await page.evaluate( () => document.activeElement && 'name' === document.activeElement.name ), 'focus on the first field to correct' );
	await shot( page, 11, 'contact-messages', '.flexo-booking--contact' );
	await page.fill( '.fb-contact [name=name]', 'Elena Test' );
	await page.fill( '.fb-contact [name=email]', 'elena@example.com' );
	await page.fill( '.fb-contact [name=message]', 'Do you have a baby cot?' );
	const consent = page.locator( '.fb-contact [name=privacy_consent]' );
	if ( await consent.count() ) {
		await consent.check();
	}
	const mailsBefore = process.env.MAIL_LOG && fs.existsSync( process.env.MAIL_LOG ) ? fs.readFileSync( process.env.MAIL_LOG, 'utf8' ).split( '\n' ).filter( Boolean ).length : 0;
	await page.click( '.fb-contact [type=submit]' );
	await page.waitForSelector( '.fb-contact__done', { timeout: 10000 } ).catch( () => {} );
	const done = await page.locator( '.fb-contact__done' ).count();
	ok( done && await page.evaluate( () => document.activeElement && document.activeElement.classList.contains( 'fb-contact__done' ) ), 'sent: thank-you message, read out (focus)' );
	if ( process.env.MAIL_LOG ) {
		const lines = fs.readFileSync( process.env.MAIL_LOG, 'utf8' ).split( '\n' ).filter( Boolean );
		const last = lines.length > mailsBefore ? JSON.parse( lines[ lines.length - 1 ] ) : null;
		ok( last && /Message from Elena Test/.test( last.subject ) && last.headers.some( ( h ) => /^Reply-To: Elena Test <elena@example\.com>$/.test( h ) ), 'the hotel gets the message, reply goes to the guest' );
	}
	await shot( page, 11, 'contact-sent', '.flexo-booking--contact' );
	await page.close();
}

async function tabs( browser ) {
	console.log( '### Part 11: Booking & Contact tabs' );
	const page = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
	await page.goto( `${ BASE }/fxr-tabs/`, { waitUntil: 'networkidle' } );
	if ( ! await page.locator( '[data-flexo-tabs]' ).count() ) {
		skipped( 'tabs', 'not in this version' );
		await page.close();
		return;
	}
	await shot( page, 11, 'tabs-booking', '.flexo-forms-tabs' );
	const state = () => page.evaluate( () => [ ...document.querySelectorAll( '.fb-tab' ) ].map( ( t ) => t.getAttribute( 'aria-selected' ) + ':' + document.getElementById( t.getAttribute( 'aria-controls' ) ).hidden ).join( ',' ) );
	ok( 'true:false,false:true' === await state(), 'booking shown first' );
	await page.focus( '.fb-tab[data-key=booking]' );
	await page.keyboard.press( 'ArrowRight' );
	ok( 'false:true,true:false' === await state() && await page.evaluate( () => 'contact' === document.activeElement.getAttribute( 'data-key' ) ), 'arrow key: contact tab, focus moves with it' );
	await page.keyboard.press( 'Home' );
	ok( 'true:false,false:true' === await state(), 'Home: first tab' );
	// The tabs under the site's button rules: the selected tab keeps its colours on hover.
	const look = () => page.evaluate( () => getComputedStyle( document.querySelector( '.fb-tab[aria-selected=true]' ) ).backgroundColor + '|' + getComputedStyle( document.querySelector( '.fb-tab[aria-selected=true]' ) ).color );
	const rest = await look();
	await page.hover( '.fb-tab[aria-selected=true]' );
	ok( rest === await look(), 'the selected tab keeps its colours under the mouse (site button rules)' );
	await page.goto( `${ BASE }/fxr-tabs/#contact`, { waitUntil: 'networkidle' } );
	ok( 'false:true,true:false' === await state(), 'a link to #contact opens the contact tab' );
	await shot( page, 11, 'tabs-contact', '.flexo-forms-tabs' );
	await page.setViewportSize( { width: 390, height: 900 } );
	await page.waitForTimeout( 300 );
	const overflow = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
	ok( overflow <= 0, 'no sideways scroll at 390 px', `${ overflow }px` );
	await shot( page, 11, 'tabs-contact-390', '.flexo-forms-tabs' );
	await page.close();
}

/* ------------------------------------------------------------------ */

async function timeline( browser ) {
	console.log( '### Part 12: booking history' );
	if ( ! controls.booking ) {
		skipped( 'timeline', 'no booking (run tests/ui/widgets-seed.php)' );
		return;
	}
	for ( const [ label, scale, hostile ] of [ [ '100 %', 1, false ], [ '125 %', 1.25, false ], [ '200 %', 2, false ], [ '100 %, theme box-sizing', 1, true ] ] ) {
		const context = await browser.newContext( { viewport: { width: 1280, height: 1000 }, deviceScaleFactor: scale } );
		const page = await context.newPage();
		await login( page );
		await page.goto( `${ BASE }/wp-admin/admin.php?page=flexo-booking&booking=${ controls.booking }`, { waitUntil: 'networkidle' } );
		if ( hostile ) {
			await page.addStyleTag( { content: '*,*::before,*::after{box-sizing:border-box}' } );
		}
		const m = await page.evaluate( () => {
			const list = document.querySelector( '.flexo-history__list' );
			if ( ! list ) {
				return null;
			}
			const items = [ ...list.children ];
			const out = { items: items.length, dots: [], segs: [] };
			items.forEach( ( li ) => {
				const dot = li.querySelector( '.flexo-history__dot' );
				let dx, dy;
				if ( dot ) {
					const r = dot.getBoundingClientRect();
					dx = r.left + r.width / 2;
					dy = r.top + r.height / 2;
				} else {
					// 1.9.0: the dot is li::before.
					const cs = getComputedStyle( li, '::before' );
					const lr = li.getBoundingClientRect();
					const w = parseFloat( cs.width ) + ( 'border-box' === cs.boxSizing ? 0 : 2 * parseFloat( cs.borderLeftWidth ) );
					const h = parseFloat( cs.height ) + ( 'border-box' === cs.boxSizing ? 0 : 2 * parseFloat( cs.borderTopWidth ) );
					dx = lr.left + parseFloat( cs.left ) + w / 2;
					dy = lr.top + parseFloat( cs.top ) + h / 2;
				}
				// The middle of the first line box of the entry's text.
				const text = li.querySelector( '.flexo-history__text' );
				const tr = text.getBoundingClientRect();
				const lh = parseFloat( getComputedStyle( text ).lineHeight );
				out.dots.push( { x: dx, y: dy, line: tr.top + lh / 2 } );
				// The rail: per entry (1.10.0) or one line on the list (1.9.0).
				const seg = getComputedStyle( li, '::before' );
				if ( dot && 'none' !== seg.content && 'absolute' === seg.position ) {
					const lr = li.getBoundingClientRect();
					out.segs.push( { x: lr.left + parseFloat( seg.left ) + parseFloat( seg.width ) / 2, top: lr.top + parseFloat( seg.top ), bottom: lr.bottom - parseFloat( seg.bottom ) } );
				}
			} );
			if ( ! out.segs.length ) {
				const rail = getComputedStyle( list, '::before' );
				const r = list.getBoundingClientRect();
				out.segs.push( { x: r.left + parseFloat( rail.left ) + parseFloat( rail.width ) / 2, top: r.top + parseFloat( rail.top ), bottom: r.bottom - parseFloat( rail.bottom ) } );
				out.single = true;
			}
			return out;
		} );
		if ( ! m || m.items < 3 ) {
			skipped( `timeline ${ label }`, 'not enough history' );
			await context.close();
			continue;
		}
		const axis = m.segs[ 0 ].x;
		const offAxis = m.dots.map( ( d, i ) => [ i, d.x - axis ] ).filter( ( [ , d ] ) => Math.abs( d ) > SUB );
		const offLine = m.dots.map( ( d, i ) => [ i, d.y - d.line ] ).filter( ( [ , d ] ) => Math.abs( d ) > SUB );
		const first = m.dots[ 0 ], last = m.dots[ m.dots.length - 1 ];
		const top = Math.min( ...m.segs.map( ( s ) => s.top ) );
		const bottom = Math.max( ...m.segs.map( ( s ) => s.bottom ) );
		ok( ! offAxis.length, `timeline ${ label }: every dot on the rail axis`, offAxis.slice( 0, 3 ).map( ( [ i, d ] ) => `#${ i } ${ d.toFixed( 2 ) }px` ).join( '; ' ) );
		ok( ! offLine.length, `timeline ${ label }: every dot centred on its first line`, offLine.slice( 0, 3 ).map( ( [ i, d ] ) => `#${ i } ${ d.toFixed( 2 ) }px` ).join( '; ' ) );
		ok( Math.abs( top - first.y ) <= SUB && Math.abs( bottom - last.y ) <= SUB, `timeline ${ label }: the rail runs from the first dot to the last, no further`, `rail ${ top.toFixed( 1 ) }–${ bottom.toFixed( 1 ) }, dots ${ first.y.toFixed( 1 ) }–${ last.y.toFixed( 1 ) }` );
		if ( ! m.single ) {
			const gaps = m.segs.map( ( s, i ) => [ Math.abs( s.top - m.dots[ i ].y ), Math.abs( s.bottom - m.dots[ i + 1 ].y ) ] ).filter( ( [ a, b ] ) => a > SUB || b > SUB );
			ok( ! gaps.length, `timeline ${ label }: each piece of rail joins two dots` );
		}
		if ( 2 === scale || hostile ) {
			await page.locator( '#flexo-history' ).screenshot( { path: path.join( shotDir( 12 ), `history-${ label.replace( /[^a-z0-9]+/gi, '-' ) }.png` ) } );
		}
		await context.close();
	}
}

async function run() {
	const browser = await chromium.launch();
	const parts = { cards, lists, gallery, buttons, contact, tabs, timeline };
	for ( const key of ONLY ) {
		if ( parts[ key ] ) {
			await parts[ key ]( browser );
		}
	}
	await browser.close();
	fs.mkdirSync( OUT, { recursive: true } );
	fs.writeFileSync( path.join( OUT, `release-${ LABEL }.json` ), JSON.stringify( report, null, 1 ) );
	console.log( `Result: ${ pass } passed, ${ fail } failed${ skip ? `, ${ skip } skipped` : '' }` );
	process.exit( fail ? 1 : 0 );
}

run().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
