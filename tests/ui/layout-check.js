/**
 * Booking form layout check (run after tests/ui/audit-seed.php and tests/ui/layout-seed.php).
 *
 * The booking form, the search bar and the room box in frames of 320–1100 px (on a 1440 px
 * window), the full-width form at window widths 320–1920, the Azure contact page's two columns
 * at 1024–1920, two forms on one page, a form in a tab that starts hidden, and a content-sized
 * row. For every case it measures the rendered page:
 *
 * - nothing wider than the window and nothing outside the form;
 * - room cards: image, details and price/button never overlap, the details column is at least
 *   200 px wide next to an image, no word of a room name is broken, everything inside the card;
 * - steps: every step's track is equally wide (1 px) and keeps its width from step to step;
 * - calendar: right under the date fields (no other field between them), inside the form, and
 *   the date field still in view;
 * - messages: showing them moves or resizes no field in the same row or above (0.5 px); the
 *   message is outside the field cells, as wide as the form row and under the row;
 * - two forms: one form's calendar, messages and steps never show in the other, no repeated ids.
 *
 * Screenshots of each case go to OUT/LABEL/part-N/.
 *
 *   BASE=http://localhost:8092 OUT=flexo-booking/ui-audit LABEL=before node tests/ui/layout-check.js
 *   [QUICK=1] [ONLY=frames,viewports,azure,two,search,box,forced,flexrow] [LANG_SUFFIX=-bg, with the site in Bulgarian]
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.BASE || 'http://localhost:8092';
const LABEL = process.env.LABEL || 'after';
const OUT = process.env.OUT || 'flexo-booking/ui-audit';
const QUICK = !! process.env.QUICK;
const ONLY = ( process.env.ONLY || 'frames,viewports,azure,two,search,box,forced,flexrow' ).split( ',' );
const LANG = process.env.LANG_SUFFIX || '';
const FRAMES = QUICK ? [ 320, 480, 720, 1100 ] : [ 320, 360, 420, 480, 560, 640, 720, 900, 1100 ];
const VIEWPORTS = QUICK ? [ 360, 1024, 1440 ] : [ 320, 360, 390, 430, 768, 1024, 1280, 1440, 1920 ];
const SUB = 0.5;

const ymd = ( d ) => d.toISOString().slice( 0, 10 );
const day = ( n ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() + n );
	return ymd( d );
};
const STAY = `check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2`;

let pass = 0, fail = 0;
const report = { label: LABEL, results: [] };
const ok = ( cond, name, detail ) => {
	cond ? pass++ : fail++;
	report.results.push( { name, ok: !! cond, detail: detail || '' } );
	if ( ! cond || process.env.VERBOSE ) {
		console.log( ( cond ? '  PASS ' : '  FAIL ' ) + name + ( detail ? ' – ' + detail : '' ) );
	}
};

/* ------------------------------------------------------------------ */
/* In-page measurements                                                */
/* ------------------------------------------------------------------ */

// Runs in the page: all helpers in one function so they can be passed to evaluate().
function lab( [ cmd, arg ] ) {
	// Page coordinates (focus may scroll the window between two measurements).
	const R = ( n ) => {
		const r = n.getBoundingClientRect();
		return { x: r.left + scrollX, y: r.top + scrollY, w: r.width, h: r.height, r: r.right + scrollX, b: r.bottom + scrollY };
	};
	const V = ( n ) => n.getBoundingClientRect();
	const shown = ( n ) => !! n && n.getClientRects().length > 0 && getComputedStyle( n ).visibility !== 'hidden' && ! n.closest( '[hidden], .screen-reader-text, .fb-hp, .fb-native-date' );
	const root = ( sel ) => ( sel ? document.querySelector( sel ) : document.querySelector( '.flexo-booking' ) );
	const overlap = ( a, b ) => Math.max( 0, Math.min( a.r, b.r ) - Math.max( a.x, b.x ) ) * Math.max( 0, Math.min( a.b, b.b ) - Math.max( a.y, b.y ) );
	// Fields a guest uses: date buttons, visible inputs and selects, labels, the submit button.
	const fieldsOf = ( form ) => [ ...form.querySelectorAll( '.fb-date, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea, label, button[type=submit], .fb-phone__cc' ) ].filter( shown );
	const key = ( n, i ) => ( n.id || n.name || n.getAttribute( 'for' ) || n.className || n.tagName ) + '#' + i;

	if ( 'overflow' === cmd ) {
		const r = root( arg );
		const box = R( r );
		const out = [];
		r.querySelectorAll( '*' ).forEach( ( n ) => {
			if ( ! shown( n ) || n.closest( 'svg' ) ) {
				return;
			}
			const b = R( n );
			if ( b.w < 1 || b.h < 1 ) {
				return;
			}
			if ( b.x < box.x - 0.5 || b.r > box.r + 0.5 ) {
				out.push( ( n.className || n.tagName ) + ` [${ Math.round( b.x ) }–${ Math.round( b.r ) } in ${ Math.round( box.x ) }–${ Math.round( box.r ) }]` );
			}
		} );
		return { page: document.documentElement.scrollWidth - innerWidth, escaped: out.slice( 0, 5 ), width: box.w };
	}

	if ( 'steps' === cmd ) {
		const items = [ ...root( arg ).querySelectorAll( '.fb-steps__item' ) ].filter( shown ).map( R );
		return items.map( ( i ) => ( { x: i.x, w: i.w } ) );
	}

	if ( 'cards' === cmd ) {
		const r = root( arg );
		const res = r.querySelector( '.fb-results' );
		const out = [];
		r.querySelectorAll( '.fb-room' ).forEach( ( card, ci ) => {
			if ( ! shown( card ) ) {
				return;
			}
			const c = R( card );
			const parts = [ '.fb-room__image', '.fb-room__body', '.fb-room__side' ].map( ( s ) => card.querySelector( s ) ).filter( shown );
			const rects = parts.map( R );
			const problems = [];
			for ( let i = 0; i < rects.length; i++ ) {
				for ( let j = i + 1; j < rects.length; j++ ) {
					const o = overlap( rects[ i ], rects[ j ] );
					if ( o > 1 ) {
						problems.push( `${ parts[ i ].className.split( ' ' )[ 0 ] } overlaps ${ parts[ j ].className.split( ' ' )[ 0 ] } (${ Math.round( o ) } px²)` );
					}
				}
			}
			const img = card.querySelector( '.fb-room__image' );
			const body = card.querySelector( '.fb-room__body' );
			if ( shown( img ) && shown( body ) ) {
				const a = R( img ), b = R( body );
				if ( Math.abs( a.y - b.y ) < a.h && b.x > a.x && b.w < 200 ) {
					problems.push( `details column ${ Math.round( b.w ) } px next to the image` );
				}
			}
			card.querySelectorAll( '*' ).forEach( ( n ) => {
				if ( ! shown( n ) || n.closest( 'svg' ) ) {
					return;
				}
				const b = R( n );
				if ( b.w >= 1 && ( b.x < c.x - 0.5 || b.r > c.r + 0.5 ) ) {
					problems.push( `${ n.className || n.tagName } outside the card` );
				}
			} );
			const title = card.querySelector( '.fb-room__title' );
			if ( title ) {
				// A word split over two lines has two client rects.
				const text = title.firstChild && 3 === title.firstChild.nodeType ? title.firstChild : null;
				if ( text ) {
					const re = /\S+/g;
					let m;
					while ( ( m = re.exec( text.data ) ) ) {
						const range = document.createRange();
						range.setStart( text, m.index );
						range.setEnd( text, m.index + m[ 0 ].length );
						const lines = new Set( [ ...range.getClientRects() ].filter( ( q ) => q.width > 0 ).map( ( q ) => Math.round( q.top ) ) );
						if ( lines.size > 1 ) {
							problems.push( `"${ m[ 0 ] }" broken over two lines` );
						}
					}
				}
			}
			if ( res && ( c.x < R( res ).x - 0.5 || c.r > R( res ).r + 0.5 ) ) {
				problems.push( 'card outside the results' );
			}
			out.push( { i: ci, w: c.w, h: c.h, layout: card.getAttribute( 'data-fb-layout' ) || '', problems: [ ...new Set( problems ) ].slice( 0, 4 ) } );
		} );
		return out;
	}

	if ( 'fields' === cmd ) {
		const form = root( arg.root ).querySelector( arg.form );
		return fieldsOf( form ).map( ( n, i ) => ( { k: key( n, i ), ...R( n ), invalid: n.getAttribute( 'aria-invalid' ) === 'true' || !! n.querySelector?.( '[aria-invalid="true"]' ) } ) );
	}

	if ( 'messages' === cmd ) {
		const form = root( arg.root ).querySelector( arg.form );
		const f = R( form );
		// Each field message with the box of its field (aria-describedby); the form's own notices too.
		const fields = fieldsOf( form );
		return [ ...form.querySelectorAll( '.fb-field__error, .fb-msg, .fb-notice' ) ].filter( shown ).map( ( m ) => {
			const field = m.id ? fields.find( ( n ) => ( n.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).includes( m.id ) ) : null;
			const box = m.closest( '.fb-msgs' ) || m;
			// As wide as the row it is under: the form, or the grid of fields it sits in.
			const row = box.parentElement && box.parentElement !== form && form.contains( box.parentElement ) ? R( box.parentElement ) : f;
			return { ...R( box ), inCell: !! m.closest( '.fb-field' ), notice: ! field, field: field ? fields.indexOf( field ) : -1, text: m.textContent.trim().slice( 0, 60 ), formX: row.x, formW: row.w };
		} );
	}

	if ( 'picker' === cmd ) {
		const r = root( arg );
		const form = r.querySelector( '.fb-search' );
		const panel = form && form.querySelector( '.fb-picker' );
		if ( ! shown( panel ) ) {
			return { open: false };
		}
		const dates = [ ...form.querySelectorAll( '.fb-date' ) ].filter( shown ).map( R );
		const bottom = Math.max( ...dates.map( ( d ) => d.b ) );
		const p = R( panel );
		const between = [ ...form.querySelectorAll( 'select, button[type=submit], .fb-field--small, .fb-field--action' ) ].filter( shown ).map( ( n ) => ( { n: n.name || n.className, ...R( n ) } ) ).filter( ( n ) => n.y >= bottom - 1 && n.y < p.y - 1 );
		const box = R( r );
		const inView = [ ...form.querySelectorAll( '.fb-date' ) ].filter( shown ).map( V ).every( ( d ) => d.top >= 0 && d.bottom <= innerHeight );
		return { open: true, top: p.y, datesBottom: bottom, gap: p.y - bottom, between: between.map( ( n ) => n.n ), inside: p.x >= box.x - 0.5 && p.r <= box.r + 0.5, inView, panelTop: p.y };
	}

	if ( 'align' === cmd ) {
		// Fields of the search row: in each line, labels start at one height and so do the controls.
		const form = root( arg ).querySelector( '.fb-search' );
		const fields = [ ...form.querySelectorAll( '.fb-field' ) ].filter( shown ).map( ( f ) => {
			const label = f.querySelector( 'label' ), control = [ ...f.querySelectorAll( '.fb-date, select, input, button' ) ].find( shown );
			return label && control ? { label: R( label ), control: R( control ), k: label.textContent.trim() } : null;
		} ).filter( Boolean );
		const bad = [];
		fields.forEach( ( a ) => fields.forEach( ( b ) => {
			if ( a.k < b.k && a.control.y < b.control.b && a.control.b > b.control.y && ( Math.abs( a.label.y - b.label.y ) > 0.5 || Math.abs( a.control.y - b.control.y ) > 0.5 ) ) {
				bad.push( `${ a.k } / ${ b.k } (labels ${ ( b.label.y - a.label.y ).toFixed( 1 ) }, fields ${ ( b.control.y - a.control.y ).toFixed( 1 ) })` );
			}
		} ) );
		return bad;
	}

	if ( 'ids' === cmd ) {
		const seen = {};
		document.querySelectorAll( '[id]' ).forEach( ( n ) => {
			seen[ n.id ] = ( seen[ n.id ] || 0 ) + 1;
		} );
		return Object.keys( seen ).filter( ( k ) => seen[ k ] > 1 );
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Steps of a case                                                     */
/* ------------------------------------------------------------------ */

const shotDir = ( part ) => {
	const d = path.join( OUT, `part-${ part }`, LABEL + LANG );
	fs.mkdirSync( d, { recursive: true } );
	return d;
};

async function shot( page, part, name, sel ) {
	const file = path.join( shotDir( part ), name + '.png' );
	try {
		if ( sel ) {
			await page.locator( sel ).first().screenshot( { path: file } );
		} else {
			await page.screenshot( { path: file, fullPage: true } );
		}
	} catch ( e ) {
		console.log( '  (no screenshot ' + name + ': ' + e.message.split( '\n' )[ 0 ] + ')' );
	}
}

async function settle( page, ms = 350 ) {
	await page.waitForTimeout( ms );
}

async function checkOverflow( page, name, sel ) {
	const o = await page.evaluate( lab, [ 'overflow', sel ] );
	ok( o.page <= 0, `${ name }: no sideways scroll`, o.page > 0 ? `page ${ o.page }px wider than the window` : '' );
	ok( ! o.escaped.length, `${ name }: nothing outside the form`, o.escaped.join( '; ' ) );
	return o;
}

async function checkSteps( page, name, sel, prev ) {
	const steps = await page.evaluate( lab, [ 'steps', sel ] );
	if ( ! steps.length ) {
		return prev;
	}
	const ws = steps.map( ( s ) => s.w );
	const spread = Math.max( ...ws ) - Math.min( ...ws );
	ok( spread <= 1, `${ name }: steps equally wide`, `widths ${ ws.map( ( w ) => w.toFixed( 1 ) ).join( ' / ' ) }` );
	if ( prev ) {
		const moved = steps.map( ( s, i ) => Math.abs( s.w - prev[ i ].w ) + Math.abs( s.x - prev[ i ].x ) );
		ok( Math.max( ...moved ) <= 1, `${ name }: steps keep their place from step to step`, `moved ${ Math.max( ...moved ).toFixed( 1 ) }px` );
	}
	return steps;
}

async function checkMessages( page, name, sel, form, click ) {
	const before = await page.evaluate( lab, [ 'fields', { root: sel, form } ] );
	await page.locator( `${ sel || '.flexo-booking' } ${ form } ${ click }` ).first().click();
	await settle( page, 450 );
	const after = await page.evaluate( lab, [ 'fields', { root: sel, form } ] );
	const msgs = await page.evaluate( lab, [ 'messages', { root: sel, form } ] );
	if ( ! msgs.length ) {
		ok( false, `${ name }: a message is shown`, 'no message' );
		return;
	}
	// A field never changes size or moves sideways, and moves down only under a full-width message above it.
	const wide = msgs.filter( ( m ) => m.w >= m.formW - 1 );
	const moved = [];
	before.forEach( ( b, i ) => {
		const a = after[ i ];
		if ( ! a ) {
			return;
		}
		const dx = Math.abs( a.x - b.x ), dw = Math.abs( a.w - b.w ), dh = Math.abs( a.h - b.h ), dy = a.y - b.y;
		const pushed = wide.some( ( m ) => m.b <= a.y + 0.5 );
		if ( dx > SUB || dw > SUB || dh > SUB || dy < -SUB || ( dy > SUB && ! pushed ) ) {
			moved.push( `${ b.k } (${ dx.toFixed( 1 ) },${ dy.toFixed( 1 ) } ${ dw.toFixed( 1 ) }×${ dh.toFixed( 1 ) })` );
		}
	} );
	ok( ! moved.length, `${ name }: no field in the row moves or resizes`, moved.slice( 0, 4 ).join( '; ' ) );
	const own = msgs.filter( ( m ) => ! m.notice );
	const inCell = own.filter( ( m ) => m.inCell );
	ok( ! inCell.length, `${ name }: message outside the field cells`, inCell.map( ( m ) => m.text ).join( '; ' ) );
	const narrow = own.filter( ( m ) => m.w < m.formW - 1 );
	ok( ! narrow.length, `${ name }: message as wide as the form`, narrow.map( ( m ) => `${ Math.round( m.w ) } of ${ Math.round( m.formW ) }px` ).join( '; ' ) );
	// Under its field's row: below every field (before) that shares a line with it.
	const high = own.filter( ( m ) => {
		const f = before[ m.field ];
		if ( ! f ) {
			return false;
		}
		const rowBottom = Math.max( ...before.filter( ( b ) => b.y < f.b && b.b > f.y ).map( ( b ) => b.b ) );
		const shift = after[ m.field ].y - f.y;
		return m.y < rowBottom + shift - 0.5;
	} );
	ok( ! high.length, `${ name }: message under its row`, high.map( ( m ) => m.text ).join( '; ' ) );
}

async function checkAlign( page, name, sel ) {
	const bad = await page.evaluate( lab, [ 'align', sel ] );
	ok( ! bad.length, `${ name }: labels and fields of a row line up`, bad.slice( 0, 3 ).join( '; ' ) );
}

async function checkPicker( page, name, sel, which = 0 ) {
	const y0 = await page.evaluate( () => scrollY );
	await page.locator( `${ sel || '.flexo-booking' } .fb-date` ).nth( which ).click();
	await settle( page, 500 );
	const p = await page.evaluate( lab, [ 'picker', sel ] );
	if ( ! p.open ) {
		ok( false, `${ name }: calendar opens`, 'not open' );
		return p;
	}
	ok( p.gap >= -0.5 && ! p.between.length, `${ name }: calendar right under the date fields`, p.between.length ? `between: ${ p.between.join( ', ' ) }` : `gap ${ p.gap.toFixed( 1 ) }px` );
	ok( p.inside, `${ name }: calendar inside the form` );
	const y1 = await page.evaluate( () => scrollY );
	ok( p.inView, `${ name }: date fields still in view after opening`, `scrolled ${ Math.round( y1 - y0 ) }px` );
	return p;
}

/* ------------------------------------------------------------------ */
/* Cases                                                               */
/* ------------------------------------------------------------------ */

// The full flow in one place: dates (calendar, messages) → rooms (cards) → details (messages).
async function fullFlow( page, url, name, sel, part1Shot, opts = {} ) {
	const root = sel || '.flexo-booking';
	await page.goto( url, { waitUntil: 'networkidle' } );
	await settle( page );
	let steps = await checkSteps( page, `${ name } dates`, sel );
	await checkOverflow( page, `${ name } dates`, sel );
	await checkAlign( page, `${ name } dates`, sel );
	if ( opts.shots ) {
		await shot( page, 3, `${ part1Shot }-steps-dates`, `${ root } .fb-steps` );
		await shot( page, 4, `${ part1Shot }-dates-before-message`, root );
	}
	await checkMessages( page, `${ name } dates`, sel, '.fb-search', 'button[type=submit]' );
	if ( opts.shots ) {
		await shot( page, 4, `${ part1Shot }-dates-message`, root );
	}
	await checkPicker( page, `${ name }`, sel );
	if ( opts.shots ) {
		await shot( page, 2, `${ part1Shot }-calendar`, root );
	}
	await page.keyboard.press( 'Escape' );

	await page.goto( url + ( url.includes( '?' ) ? '&' : '?' ) + STAY, { waitUntil: 'networkidle' } );
	await page.waitForSelector( `${ root } .fb-room`, { timeout: 15000 } ).catch( () => {} );
	await settle( page );
	steps = await checkSteps( page, `${ name } rooms`, sel, steps );
	await checkOverflow( page, `${ name } rooms`, sel );
	const cards = await page.evaluate( lab, [ 'cards', sel ] );
	ok( cards.length > 0, `${ name }: room cards shown` );
	const bad = cards.filter( ( c ) => c.problems.length );
	ok( ! bad.length, `${ name }: room cards readable and contained`, bad.slice( 0, 2 ).map( ( c ) => `card ${ c.i }: ${ c.problems.join( ', ' ) }` ).join( ' | ' ) );
	if ( opts.shots ) {
		await shot( page, 1, `${ part1Shot }-rooms`, root );
	}
	// Details: the first room that can be booked (a rate when it has several).
	const select = page.locator( `${ root } .fb-room__select:not([disabled])` ).first();
	if ( await select.count() ) {
		await select.click();
		await settle( page );
		const plan = page.locator( `${ root } .fb-plan .fb-button` ).first();
		if ( await plan.count() && await plan.isVisible() ) {
			await plan.click();
			await settle( page );
		}
		steps = await checkSteps( page, `${ name } details`, sel, steps );
		await checkOverflow( page, `${ name } details`, sel );
		await checkMessages( page, `${ name } details`, sel, '.fb-details', 'button[type=submit]' );
		if ( opts.shots ) {
			await shot( page, 4, `${ part1Shot }-details-message`, root );
		}
	}
}

// Before/after sheets (when a LABEL=before run exists): part => [ caption, file ].
const PAIRS = {
	1: [ [ 'Azure contact page, 1280 px window: the booking form in its half column', 'azure-1280-page' ], [ 'Form in a 320 px column', 'frame-320-rooms' ], [ 'Form in a 720 px column', 'frame-720-rooms' ] ],
	2: [ [ 'Azure contact page, 1280 px: calendar', 'azure-1280-calendar' ], [ 'Form in a 320 px column: calendar', 'frame-320-calendar' ], [ 'Room box, 320 px: calendar', 'box-320-calendar' ], [ 'Search bar, 480 px: calendar', 'search-480-calendar' ] ],
	3: [ [ 'Azure contact page, 1280 px: steps', 'azure-1280-steps-dates' ], [ 'Form in a 480 px column: steps', 'frame-480-steps-dates' ], [ 'Form in a 1100 px column: steps', 'frame-1100-steps-dates' ] ],
	4: [ [ 'Azure contact page, 1280 px: no dates chosen', 'azure-1280-dates-message' ], [ 'Form in a 1100 px column: no dates chosen', 'frame-1100-dates-message' ], [ 'Form in a 720 px column: guest details sent empty', 'frame-720-details-message' ] ],
	5: [ [ 'Two forms on one page (1280 px)', 'two-forms' ] ],
};

async function compare( browser ) {
	const page = await browser.newPage( { viewport: { width: 1400, height: 800 } } );
	for ( const [ part, pairs ] of Object.entries( PAIRS ) ) {
		const rows = pairs.map( ( [ caption, file ] ) => {
			const b = path.join( OUT, `part-${ part }`, 'before', file + '.png' );
			const a = path.join( OUT, `part-${ part }`, 'after', file + '.png' );
			if ( ! fs.existsSync( b ) || ! fs.existsSync( a ) ) {
				return '';
			}
			const img = ( f ) => 'data:image/png;base64,' + fs.readFileSync( f ).toString( 'base64' );
			return `<section><h2>${ caption }</h2><div class="pair"><figure><figcaption>Before</figcaption><img src="${ img( b ) }"></figure><figure><figcaption>After</figcaption><img src="${ img( a ) }"></figure></div></section>`;
		} ).join( '' );
		if ( ! rows ) {
			continue;
		}
		await page.setContent( `<style>body{margin:16px;font:14px system-ui,sans-serif;color:#1f2933;background:#fff}h2{font-size:15px;margin:24px 0 8px}.pair{display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start}figure{margin:0}figcaption{font-weight:600;margin:0 0 6px}img{max-width:100%;border:1px solid #d5dbe1}</style>${ rows }` );
		await page.waitForTimeout( 300 );
		await page.screenshot( { path: path.join( OUT, `part-${ part }`, 'before-after.png' ), fullPage: true } );
	}
	await page.close();
}

async function run() {
	const browser = await chromium.launch();

	if ( ONLY.includes( 'frames' ) ) {
		console.log( '### frames (form in a frame, window 1440)' );
		for ( const w of FRAMES ) {
			const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
			page.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
			// The frame gets its width before the form starts, on every page load.
			await page.addInitScript( `document.addEventListener('DOMContentLoaded',function(){var f=document.getElementById('fxl-frame');if(f){f.style.width='${ w }px';}});` );
			await fullFlow( page, `${ BASE }/fxl-lab/`, `frame ${ w }`, '#fxl-frame .flexo-booking', `frame-${ w }`, { shots: true } );
			await page.close();
		}
	}

	if ( ONLY.includes( 'viewports' ) ) {
		console.log( '### window widths (full-width form)' );
		const vp = await browser.newPage();
		vp.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
		for ( const w of VIEWPORTS ) {
			await vp.setViewportSize( { width: w, height: 900 } );
			await fullFlow( vp, `${ BASE }/booking/`, `window ${ w }`, '', `window-${ w }`, { shots: [ 360, 768, 1280 ].includes( w ) } );
		}
		await vp.close();
	}

	if ( ONLY.includes( 'azure' ) ) {
		console.log( '### Azure contact page (two columns)' );
		const az = await browser.newPage();
		az.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
		for ( const w of QUICK ? [ 1280 ] : [ 390, 768, 1024, 1280, 1440, 1920 ] ) {
			await az.setViewportSize( { width: w, height: 1000 } );
			await fullFlow( az, `${ BASE }/fxl-azure/`, `azure ${ w }`, '', `azure-${ w }`, { shots: true } );
			await az.goto( `${ BASE }/fxl-azure/?${ STAY }`, { waitUntil: 'networkidle' } );
			await az.waitForSelector( '.fb-room', { timeout: 15000 } ).catch( () => {} );
			await settle( az );
			await shot( az, 1, `azure-${ w }-page`, '.wp-block-columns' );
		}
		await az.close();
	}

	if ( ONLY.includes( 'two' ) ) {
		console.log( '### two forms on one page, and a form in a hidden tab' );
		const two = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
		two.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
		await two.goto( `${ BASE }/fxl-two/`, { waitUntil: 'networkidle' } );
		await settle( two );
		const dup = await two.evaluate( lab, [ 'ids' ] );
		ok( ! dup.length, 'two forms: no repeated ids', dup.slice( 0, 5 ).join( ', ' ) );
		await two.locator( '#fxl-a .fb-date' ).first().click();
		await settle( two );
		const openB = await two.locator( '#fxl-b .fb-picker:not([hidden])' ).count();
		const openA = await two.locator( '#fxl-a .fb-picker:not([hidden])' ).count();
		ok( 1 === openA && 0 === openB, 'two forms: the calendar opens in its own form only', `A ${ openA }, B ${ openB }` );
		await two.keyboard.press( 'Escape' );
		await two.locator( '#fxl-a .fb-search button[type=submit]' ).click();
		await settle( two );
		const errA = await two.locator( '#fxl-a .fb-field__error, #fxl-a .fb-msg' ).count();
		const errB = await two.locator( '#fxl-b .fb-field__error, #fxl-b .fb-msg' ).count();
		ok( errA > 0 && 0 === errB, 'two forms: messages only in the form used', `A ${ errA }, B ${ errB }` );
		const stepA = await two.locator( '#fxl-a .fb-steps__item.is-current' ).count();
		const stepB = await two.locator( '#fxl-b .fb-steps__item.is-current' ).count();
		ok( 1 === stepA && 1 === stepB, 'two forms: each has its own current step', `A ${ stepA }, B ${ stepB }` );
		await checkPicker( two, 'two forms B', '#fxl-b .flexo-booking' );
		const openA2 = await two.locator( '#fxl-a .fb-picker:not([hidden])' ).count();
		ok( 0 === openA2, 'two forms: opening B leaves A closed' );
		await two.keyboard.press( 'Escape' );
		await shot( two, 5, 'two-forms', '#fxl-two' );
		// The tab that starts hidden: shown later, it lays out for its own width.
		await two.evaluate( () => {
			document.getElementById( 'fxl-panel-1' ).hidden = true;
			document.getElementById( 'fxl-panel-2' ).hidden = false;
		} );
		await settle( two );
		await checkOverflow( two, 'hidden tab shown', '#fxl-panel-2 .flexo-booking' );
		await checkSteps( two, 'hidden tab shown', '#fxl-panel-2 .flexo-booking' );
		await checkPicker( two, 'hidden tab shown', '#fxl-panel-2 .flexo-booking' );
		await two.keyboard.press( 'Escape' );
		await shot( two, 5, 'tab-shown-later', '#fxl-panel-2' );
		await two.close();
	}

	if ( ONLY.includes( 'search' ) ) {
		console.log( '### search bar in a frame' );
		const sb = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		sb.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
		for ( const w of FRAMES ) {
			await sb.goto( `${ BASE }/fxl-search/`, { waitUntil: 'networkidle' } );
			await sb.evaluate( ( width ) => {
				document.getElementById( 'fxl-frame' ).style.width = width + 'px';
			}, w );
			await settle( sb );
			await checkOverflow( sb, `search ${ w }`, '#fxl-frame .flexo-booking' );
			await checkAlign( sb, `search ${ w }`, '#fxl-frame .flexo-booking' );
			await checkMessages( sb, `search ${ w }`, '#fxl-frame .flexo-booking', '.fb-search', 'button[type=submit]' );
			await sb.keyboard.press( 'Escape' );
			await sb.goto( `${ BASE }/fxl-search/`, { waitUntil: 'networkidle' } );
			await sb.evaluate( ( width ) => {
				document.getElementById( 'fxl-frame' ).style.width = width + 'px';
			}, w );
			await settle( sb );
			await checkPicker( sb, `search ${ w }`, '#fxl-frame .flexo-booking' );
			await shot( sb, 2, `search-${ w }-calendar`, '#fxl-frame' );
		}
		await sb.close();
	}

	if ( ONLY.includes( 'box' ) ) {
		console.log( '### room box in a frame' );
		const bx = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		bx.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
		for ( const w of QUICK ? [ 320, 420 ] : [ 320, 360, 420, 480, 560 ] ) {
			await bx.goto( `${ BASE }/fxl-box/`, { waitUntil: 'networkidle' } );
			await bx.evaluate( ( width ) => {
				document.getElementById( 'fxl-frame' ).style.width = width + 'px';
			}, w );
			await settle( bx );
			await checkOverflow( bx, `box ${ w }`, '#fxl-frame .flexo-booking' );
			await checkAlign( bx, `box ${ w }`, '#fxl-frame .flexo-booking' );
			await checkPicker( bx, `box ${ w }`, '#fxl-frame .flexo-booking' );
			await shot( bx, 2, `box-${ w }-calendar`, '#fxl-frame' );
		}
		await bx.close();
	}

	if ( ONLY.includes( 'forced' ) ) {
		console.log( '### results layout: always stacked / always horizontal' );
		const fp = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
		for ( const [ slug, w, want ] of [ [ 'fxl-stacked', 1100, 1 ], [ 'fxl-stacked', 640, 1 ], [ 'fxl-horizontal', 640, 3 ], [ 'fxl-horizontal', 420, 1 ] ] ) {
			await fp.goto( `${ BASE }/${ slug }/?${ STAY }`, { waitUntil: 'networkidle' } );
			await fp.evaluate( ( width ) => {
				document.getElementById( 'fxl-frame' ).style.width = width + 'px';
			}, w );
			await fp.waitForSelector( '#fxl-frame .fb-room', { timeout: 15000 } ).catch( () => {} );
			await settle( fp );
			const cols = await fp.evaluate( () => getComputedStyle( document.querySelector( '#fxl-frame .fb-room' ) ).gridTemplateColumns.split( ' ' ).length );
			ok( cols === want, `${ slug } in ${ w }px: ${ want } column(s)`, `got ${ cols }` );
			const cards = await fp.evaluate( lab, [ 'cards', '#fxl-frame .flexo-booking' ] );
			const bad = cards.filter( ( c ) => c.problems.length );
			ok( ! bad.length, `${ slug } in ${ w }px: cards readable and contained`, bad.slice( 0, 2 ).map( ( c ) => c.problems.join( ', ' ) ).join( ' | ' ) );
			await shot( fp, 1, `${ slug }-${ w }`, '#fxl-frame' );
		}
		await fp.close();
	}

	if ( ONLY.includes( 'flexrow' ) ) {
		console.log( '### content-sized row' );
		const fr = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
		await fr.goto( `${ BASE }/fxl-flexrow/`, { waitUntil: 'networkidle' } );
		await settle( fr );
		const w = await fr.evaluate( () => document.querySelector( '#fxl-item .flexo-booking' ).getBoundingClientRect().width );
		ok( w >= 280, 'content-sized row: the form keeps a usable width', `${ Math.round( w ) }px` );
		await checkOverflow( fr, 'content-sized row', '#fxl-item .flexo-booking' );
		await shot( fr, 1, 'content-sized-row', '#fxl-row' );
		await fr.close();
	}

	if ( 'after' === LABEL && ! LANG ) {
		await compare( browser );
	}
	await browser.close();
	fs.mkdirSync( OUT, { recursive: true } );
	fs.writeFileSync( path.join( OUT, `layout-${ LABEL }${ LANG }.json` ), JSON.stringify( report, null, 1 ) );
	console.log( `Result: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
}

run().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
