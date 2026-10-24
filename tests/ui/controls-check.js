/**
 * Custom form controls: geometry check and zoomed screenshots.
 *
 * Every Flexo control is rendered in every state – admin: radio, checkbox,
 * radio card (also the real ones on Bookings → Appearance), switch,
 * segmented control; front end: the booking form's checkboxes and radios –
 * then cropped from a screenshot and measured from its pixels:
 *   radio      the dot's centre = the ring's centre
 *   checkbox   the tick's centre (its bounding box) = the box's centre
 *   switch     knob centred vertically; the same inset off (left), on (right)
 *              and at the top
 *   segmented  the selected segment centred vertically in its track
 * An offset of more than 0.5 CSS px fails. Every area is checked a second
 * time with "hostile" theme/plugin CSS for radios and checkboxes (the kind
 * WordPress core, themes and Elementor ship; on the front end also Twenty
 * Twenty-One's real stylesheet when the site has it), which must not move
 * anything.
 *
 * Scales – Chromium: device scale factors 1 … 6, i.e. browser zoom 100/125/
 * 150/200 % on a 1× screen, on Windows display scaling 125 % and 150 %, and
 * on a 3× screen. WebKit (WebKitGTK's WebDriver, when WEBKIT=1; run under
 * xvfb-run): 1×, 2× and 3× screens, each with page zoom 100/125/150/200 %.
 *
 * Output in OUT/LABEL: report.json, sheets/ (every control of one engine,
 * area and scale on one zoomed sheet, red cross = ring/box centre, green
 * cross = dot/tick/knob centre) and the raw element crops for the 3× screen.
 *
 * WordPress's own screens (Settings → General, Discussion, Reading, Posts) are
 * checked too: their checkboxes and radios must have the same computed styles
 * and the same pixels as on the LABEL=before run (no CSS leak).
 *
 *   BASE=http://localhost:8092 OUT=flexo-booking/ui-audit/controls LABEL=after \
 *   [WEBKIT=1] [QUICK=1] [CORE_ONLY=1] [AREAS=admin,front] [ENGINES=webkit] xvfb-run -a node tests/ui/controls-check.js
 */
const { chromium } = require( 'playwright' );
const { spawn } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.BASE || 'http://localhost:8092';
const OUT = path.join( process.env.OUT || 'flexo-booking/ui-audit/controls', process.env.LABEL || 'after' );
const LIMIT = 0.5;
const QUICK = !! process.env.QUICK;
// Browser zoom 100/125/150/200 % × screens 1×, 1.25×, 1.5× (Windows scaling) and 3×.
const CHROMIUM_SCALES = QUICK ? [ 1, 1.25, 3 ] : [ 1, 1.25, 1.5, 1.5625, 1.875, 2, 2.25, 2.5, 3, 3.75, 4.5, 6 ];
const RAW_CHROMIUM = [ 3, 3.75, 4.5, 6 ];
const list = ( v, d ) => ( v ? v.split( ',' ).map( Number ) : d );
const WEBKIT_SCREENS = list( process.env.WEBKIT_SCREENS, QUICK ? [ 1, 3 ] : [ 1, 2, 3 ] );
const WEBKIT_ZOOMS = list( process.env.WEBKIT_ZOOMS, QUICK ? [ 1, 1.25 ] : [ 1, 1.25, 1.5, 2 ] );
const AREAS = ( process.env.AREAS || 'admin,front' ).split( ',' );
// ENGINES=webkit re-runs one engine and keeps the other engine's results in an existing report.json.
const ENGINES = ( process.env.ENGINES || 'chromium' + ( process.env.WEBKIT ? ',webkit' : '' ) ).split( ',' );

/* ------------------------------------------------------------------ */
/* Fixtures: the same markup the plugin prints (helpers / templates).  */
/* ------------------------------------------------------------------ */

const sw = ( state, attrs ) => `<label class="flexo-switch"><input type="checkbox" role="switch"${ attrs }><span class="flexo-switch__track" aria-hidden="true" data-q="switch|${ state }"></span><span class="flexo-switch__label">Switch</span></label>`;
const seg = ( state, first ) => `<span class="fx-seg" role="radiogroup" aria-label="Corners" data-q="segmented|${ state }"><label class="fx-seg__opt"><input type="radio" name="fxq-seg-${ state }"${ first ? ' checked' : '' }><span>Square</span></label><label class="fx-seg__opt"><input type="radio" name="fxq-seg-${ state }"${ first ? '' : ' checked' }><span>Rounded</span></label></span>`;
const CAL = '<span id="fxq-cal" aria-hidden="true" style="display:block;flex:0 0 auto;width:24px;height:24px;background:#ff00ff"></span>';
// A second square at the far end: the scale comes from the distance between the two (a single
// small square's rounding would grow into several pixels further down the page).
const CAL2 = '<span style="display:flex;flex:1 1 100%;justify-content:flex-end"><span id="fxq-cal2" aria-hidden="true" style="display:block;width:24px;height:24px;background:#00ffff"></span></span>';
const ADMIN_FIXTURE = `<div id="fxq" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:24px 32px;margin:0 0 20px;padding:20px;background:#fff;border:1px solid #e1e6eb;border-radius:8px">${ CAL }
${ [ 'radio', 'checkbox' ].map( ( t ) => [ [ 'off', '' ], [ 'on', ' checked' ], [ 'disabled-off', ' disabled' ], [ 'disabled-on', ' disabled checked' ] ].map( ( [ s, a ] ) => `<label class="fx-check"><input type="${ t }" name="fxq-${ t }-${ s }"${ a } data-q="${ t }|${ s }"> ${ t } ${ s }</label>` ).join( '' ) ).join( '' ) }
${ sw( 'off', '' ) }${ sw( 'on', ' checked' ) }${ sw( 'disabled-off', ' disabled' ) }${ sw( 'disabled-on', ' disabled checked' ) }
${ seg( 'first', true ) }${ seg( 'second', false ) }
<p><label><input type="checkbox" checked data-q="checkbox|in-paragraph"> A checkbox in a paragraph label</label></p>
<fieldset class="fx-choices" style="flex:1 1 100%"><legend class="screen-reader-text">Style</legend>
<label class="fx-choice"><input type="radio" name="fxq-card" data-q="card|off"><span class="fx-choice__body"><span class="fx-choice__title">Match my website</span><span class="fx-choice__desc">The form uses your website's colours and fonts.</span></span></label>
<label class="fx-choice"><input type="radio" name="fxq-card" checked data-q="card|on"><span class="fx-choice__body"><span class="fx-choice__title">Custom</span><span class="fx-choice__desc">Choose a few colours and fonts.</span></span></label>
</fieldset>${ CAL2 }</div>`;

const FRONT_FIXTURE = `<div id="fxq" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:20px 28px;margin:0 0 20px;padding:16px;background:#fff">${ CAL }
<label class="fb-terms"><input type="checkbox" data-q="terms|off"> <span>I accept the booking terms</span></label>
<label class="fb-terms fb-consent"><input type="checkbox" checked data-q="terms|on"> <span>I agree to the privacy policy</span></label>
<label class="fb-check"><input type="checkbox" data-q="fcheck|off"> I would like an invoice</label>
<label class="fb-check"><input type="checkbox" checked data-q="fcheck|on"> I would like an invoice</label>
<label class="fb-check"><input type="checkbox" disabled checked data-q="fcheck|disabled-on"> Disabled</label>
<label class="fb-check"><input type="radio" name="fxq-inv-a" data-q="fradio|off"> A person</label>
<label class="fb-check"><input type="radio" name="fxq-inv-b" checked data-q="fradio|on"> A company</label>
<label class="fb-check"><input type="radio" name="fxq-inv-c" disabled checked data-q="fradio|disabled-on"> Disabled</label>
<div style="flex:1 1 100%;max-width:420px"><label class="fb-choice"><input type="radio" name="fxq-pay" checked data-q="fchoice|on"><span class="fb-choice__text"><strong>Card</strong><span>Pay securely now</span></span></label>
<label class="fb-choice"><input type="radio" name="fxq-pay" data-q="fchoice|off"><span class="fb-choice__text"><strong>Bank transfer</strong><span>Pay within 3 days</span></span></label></div>
${ CAL2 }</div>`;

// Rules of the kind WordPress core, themes (e.g. Twenty Twenty-One) and the
// Elementor kit ship for radios and checkboxes, at their usual specificity.
const HOSTILE = {
	admin: `.wp-core-ui input[type=radio]:checked::before, body.wp-admin input[type=radio]:checked::before { content:""; display:inline-block; float:left; vertical-align:baseline; width:10px; height:10px; margin:4px; line-height:2; border-radius:50%; background:#d63638; }
.wp-core-ui input[type=checkbox]:checked::before { content:"\\2713"; display:inline-block; float:left; margin:-3px 0 0 -4px; width:20px; height:20px; font:20px/1 sans-serif; color:#d63638; }
body.wp-admin input:is([type=radio],[type=checkbox]) { line-height:20px; padding:2px; box-sizing:content-box; border-width:3px; vertical-align:baseline; }`,
	front: `input[type=checkbox], input[type=radio] { -webkit-appearance:none; appearance:none; position:relative; width:25px; height:25px; border:2px solid #39414d; border-radius:0; background:#fff; padding:3px; box-sizing:content-box; line-height:2; vertical-align:baseline; }
input[type=checkbox]::after { content:""; position:absolute; left:7px; top:2px; width:7px; height:13px; border:solid #d63638; border-width:0 3px 3px 0; transform:rotate(45deg); }
input[type=radio]:checked::before { content:""; position:absolute; top:4px; left:4px; width:13px; height:13px; border-radius:50%; background:#d63638; }
body input:not([type=button]):not([type=submit]) { border:3px solid #39414d; background-color:#fffbe6; box-shadow:0 2px 0 #39414d; }
.elementor-kit-7 input:not([type=button]):not([type=submit]) { border:2px solid #6ec1e4; border-radius:0; padding:8px; background-color:#fffbe6; line-height:1.9; }`,
};

// Dynamic states: [ item key, interaction ]. Hover moves the pointer, focus uses the keyboard.
const DYNAMIC = {
	admin: [ [ 'radio|on', 'hover' ], [ 'radio|off', 'hover' ], [ 'radio|on', 'focus' ], [ 'checkbox|on', 'hover' ], [ 'checkbox|off', 'hover' ], [ 'checkbox|on', 'focus' ], [ 'switch|on', 'hover' ], [ 'switch|on', 'focus' ], [ 'switch|off', 'focus' ], [ 'segmented|first', 'focus' ], [ 'card|on', 'hover' ], [ 'card|on', 'focus' ] ],
	front: [ [ 'terms|on', 'hover' ], [ 'terms|on', 'focus' ], [ 'fcheck|on', 'focus' ], [ 'fradio|on', 'hover' ], [ 'fradio|on', 'focus' ], [ 'fchoice|on', 'focus' ] ],
};

const TYPE = { radio: 'radio', card: 'radio', 'real-card': 'radio', fradio: 'radio', fchoice: 'radio', checkbox: 'checkbox', terms: 'checkbox', fcheck: 'checkbox', switch: 'switch', segmented: 'segmented' };

/* ------------------------------------------------------------------ */
/* In-page helpers (run in the browser under test).                    */
/* ------------------------------------------------------------------ */

// Inserts the fixture, marks the real Appearance radio cards, returns the boxes.
function prepare( { fixture, css, area } ) {
	document.getElementById( 'fxq' )?.remove();
	document.getElementById( 'fxq-css' )?.remove();
	if ( css ) {
		const st = document.createElement( 'style' );
		st.id = 'fxq-css';
		st.textContent = css;
		document.head.appendChild( st );
		if ( 'front' === area ) {
			// A real theme stylesheet that draws its own checkboxes and radios (when the site has it),
			// and the Elementor kit's body class.
			const tt1 = document.createElement( 'link' );
			tt1.rel = 'stylesheet';
			tt1.href = '/wp-content/themes/twentytwentyone/style.css';
			document.head.appendChild( tt1 );
			document.body.classList.add( 'elementor-kit-7' );
		}
	}
	const host = 'admin' === area ? document.querySelector( '#wpbody-content .wrap' ) : document.querySelector( '.flexo-booking' );
	const box = document.createElement( 'div' );
	box.innerHTML = fixture;
	const el = box.firstElementChild;
	if ( 'admin' === area ) {
		const head = host.querySelector( '.flexo-page-head' );
		head ? head.after( el ) : host.prepend( el );
		document.querySelectorAll( '.flexo-appearance__modes input[type=radio]' ).forEach( ( i ) => i.setAttribute( 'data-q', 'real-card|' + ( i.checked ? 'on' : 'off' ) ) );
		document.querySelectorAll( '.notice, .flexo-notice, .update-nag' ).forEach( ( n ) => n.remove() );
	} else {
		host.prepend( el );
	}
	window.scrollTo( 0, 0 );
	return true;
}

function boxes() {
	const out = {};
	// Page zoom applied as zoom on the root (WebKit): rects come zoomed, computed borders don't.
	const zoom = parseFloat( getComputedStyle( document.documentElement ).zoom ) || 1;
	document.querySelectorAll( '[data-q]' ).forEach( ( n ) => {
		const r = n.getBoundingClientRect();
		const cs = getComputedStyle( n );
		out[ n.getAttribute( 'data-q' ) ] = { x: r.x, y: r.y, w: r.width, h: r.height, border: parseFloat( cs.borderTopWidth ) || 0, zoom, checked: !! n.checked };
	} );
	return out;
}

/* ------------------------------------------------------------------ */
/* Pixel analysis (runs in a Chromium "lab" page for every engine).   */
/* ------------------------------------------------------------------ */

const LAB = `
window.fxqAnalyze = async function ( b64, items ) {
	const img = new Image();
	img.src = 'data:image/png;base64,' + b64;
	await img.decode();
	const cv = document.createElement( 'canvas' );
	cv.width = img.naturalWidth; cv.height = img.naturalHeight;
	const ctx = cv.getContext( '2d', { willReadFrequently: true } );
	ctx.drawImage( img, 0, 0 );
	const results = [];
	for ( const it of items ) {
		const x0 = Math.max( 0, Math.floor( it.box.x - it.pad ) ), y0 = Math.max( 0, Math.floor( it.box.y - it.pad ) );
		const x1 = Math.min( cv.width, Math.ceil( it.box.x + it.box.w + it.pad ) ), y1 = Math.min( cv.height, Math.ceil( it.box.y + it.box.h + it.pad ) );
		if ( x1 - x0 < 4 || y1 - y0 < 4 || it.box.x < 0 || it.box.y < 0 || it.box.x + it.box.w > cv.width || it.box.y + it.box.h > cv.height ) {
			results.push( { key: it.key, state: it.state, error: 'outside the screenshot', png: '' } );
			continue;
		}
		const data = ctx.getImageData( x0, y0, x1 - x0, y1 - y0 );
		const b = { x: it.box.x - x0, y: it.box.y - y0, w: it.box.w, h: it.box.h };
		let r;
		try { r = MEASURE[ it.type ]( it, data, b ); } catch ( e ) { r = { error: String( e ) }; }
		r.key = it.key; r.state = it.state;
		const c = document.createElement( 'canvas' ); c.width = data.width; c.height = data.height;
		c.getContext( '2d' ).putImageData( data, 0, 0 );
		r.png = c.toDataURL( 'image/png' ).split( ',' )[ 1 ];
		results.push( r );
	}
	return results;
};

// One zoomed sheet: every crop scaled up (pixelated), red cross = ring/box centre, green = dot/tick/knob centre.
window.fxqSheet = async function ( list, title ) {
	const cell = 132, lab = 34, cols = 6, rows = Math.ceil( list.length / cols );
	const c = document.createElement( 'canvas' );
	c.width = cols * cell; c.height = 30 + rows * ( cell + lab );
	const g = c.getContext( '2d' );
	g.fillStyle = '#fff'; g.fillRect( 0, 0, c.width, c.height );
	g.fillStyle = '#111'; g.font = '13px sans-serif'; g.fillText( title, 8, 20 );
	g.imageSmoothingEnabled = false;
	for ( let i = 0; i < list.length; i++ ) {
		const r = list[ i ];
		const ox = ( i % cols ) * cell, oy = 30 + Math.floor( i / cols ) * ( cell + lab );
		if ( ! r.png ) { g.fillStyle = '#c00'; g.font = '11px sans-serif'; g.fillText( r.key + ' · ' + r.state + ': not captured', ox + 4, oy + cell + 12 ); continue; }
		const img = new Image(); img.src = 'data:image/png;base64,' + r.png; await img.decode();
		const k = Math.max( 1, Math.floor( ( cell - 8 ) / Math.max( img.naturalWidth, img.naturalHeight ) ) );
		const dx = ox + Math.floor( ( cell - img.naturalWidth * k ) / 2 ), dy = oy + Math.floor( ( cell - img.naturalHeight * k ) / 2 );
		g.drawImage( img, dx, dy, img.naturalWidth * k, img.naturalHeight * k );
		const cross = ( p, col, len ) => { if ( ! p ) return; const X = dx + p.x * k, Y = dy + p.y * k; g.strokeStyle = col; g.lineWidth = 1; g.beginPath(); g.moveTo( X - len, Y ); g.lineTo( X + len, Y ); g.moveTo( X, Y - len ); g.lineTo( X, Y + len ); g.stroke(); };
		cross( r.center, 'rgba(230,0,0,.85)', 62 );
		cross( r.inner, 'rgba(0,170,0,.95)', 9 );
		g.fillStyle = r.bad ? '#c00' : '#111'; g.font = '11px sans-serif';
		g.fillText( r.key + ( 'static' === r.state ? '' : ' · ' + r.state ), ox + 4, oy + cell + 12 );
		const m = undefined !== r.dy ? 'dx ' + ( r.dx || 0 ).toFixed( 2 ) + '  dy ' + r.dy.toFixed( 2 ) : ( r.error ? 'error' : '' );
		g.fillText( m + ( r.bad ? '   FAIL' : '' ), ox + 4, oy + cell + 26 );
	}
	return c.toDataURL( 'image/png' ).split( ',' )[ 1 ];
};


// Before/after pairs: [ title, beforePng, beforeMarks, afterPng, afterMarks ] per row.
window.fxqCompare = async function ( rows, title ) {
	const cell = 150, lab = 30, head = 34;
	const c = document.createElement( 'canvas' );
	c.width = 2 * cell + 260; c.height = head + rows.length * ( cell + lab );
	const g = c.getContext( '2d' );
	g.fillStyle = '#fff'; g.fillRect( 0, 0, c.width, c.height );
	g.fillStyle = '#111'; g.font = '13px sans-serif'; g.fillText( title, 8, 20 );
	g.imageSmoothingEnabled = false;
	for ( let i = 0; i < rows.length; i++ ) {
		const r = rows[ i ], oy = head + i * ( cell + lab );
		g.fillStyle = '#111'; g.font = '12px sans-serif'; g.fillText( r.label, 2 * cell + 16, oy + 40 );
		for ( const [ side, col ] of [ [ r.before, 0 ], [ r.after, 1 ] ] ) {
			const ox = col * cell;
			if ( ! side ) { g.fillStyle = '#999'; g.fillText( 'n/a', ox + 60, oy + 70 ); continue; }
			const img = new Image(); img.src = 'data:image/png;base64,' + side.png; await img.decode();
			const k = Math.max( 1, Math.floor( ( cell - 10 ) / Math.max( img.naturalWidth, img.naturalHeight ) ) );
			const dx = ox + Math.floor( ( cell - img.naturalWidth * k ) / 2 ), dy = oy + Math.floor( ( cell - img.naturalHeight * k ) / 2 );
			g.drawImage( img, dx, dy, img.naturalWidth * k, img.naturalHeight * k );
			const cross = ( p, colr, len ) => { if ( ! p ) return; const X = dx + p.x * k, Y = dy + p.y * k; g.strokeStyle = colr; g.lineWidth = 1; g.beginPath(); g.moveTo( X - len, Y ); g.lineTo( X + len, Y ); g.moveTo( X, Y - len ); g.lineTo( X, Y + len ); g.stroke(); };
			cross( side.center, 'rgba(230,0,0,.85)', 70 );
			cross( side.inner, 'rgba(0,170,0,.95)', 10 );
			g.fillStyle = side.bad ? '#c00' : '#111'; g.font = '11px sans-serif';
			g.fillText( ( col ? 'after' : 'before' ) + ( undefined !== side.dy ? '  dx ' + ( side.dx || 0 ).toFixed( 2 ) + ' dy ' + side.dy.toFixed( 2 ) : '' ), ox + 6, oy + cell + 14 );
		}
	}
	return c.toDataURL( 'image/png' ).split( ',' )[ 1 ];
};

const px = ( d, x, y ) => { const i = ( y * d.width + x ) * 4; return [ d.data[ i ], d.data[ i + 1 ], d.data[ i + 2 ] ]; };
const dist = ( a, b ) => Math.hypot( a[ 0 ] - b[ 0 ], a[ 1 ] - b[ 1 ], a[ 2 ] - b[ 2 ] );
const median = ( list ) => [ 0, 1, 2 ].map( ( c ) => { const v = list.map( ( p ) => p[ c ] ).sort( ( a, b ) => a - b ); return v[ v.length >> 1 ]; } );
const proj = ( p, from, to ) => { const v = [ to[ 0 ] - from[ 0 ], to[ 1 ] - from[ 1 ], to[ 2 ] - from[ 2 ] ]; const n = v[ 0 ] * v[ 0 ] + v[ 1 ] * v[ 1 ] + v[ 2 ] * v[ 2 ] || 1; return Math.max( 0, Math.min( 1, ( ( p[ 0 ] - from[ 0 ] ) * v[ 0 ] + ( p[ 1 ] - from[ 1 ] ) * v[ 1 ] + ( p[ 2 ] - from[ 2 ] ) * v[ 2 ] ) / n ) ); };
const frameBg = ( d ) => { const s = []; for ( let x = 0; x < d.width; x++ ) { s.push( px( d, x, 0 ), px( d, x, d.height - 1 ) ); } for ( let y = 0; y < d.height; y++ ) { s.push( px( d, 0, y ), px( d, d.width - 1, y ) ); } return median( s ); };
const sample = ( d, x, y ) => px( d, Math.max( 0, Math.min( d.width - 1, Math.floor( x ) ) ), Math.max( 0, Math.min( d.height - 1, Math.floor( y ) ) ) );
// Where a profile (values at pixel centres) crosses 0.5, from the start or the end, sub-pixel.
function edge( p, fromEnd ) {
	const n = p.length;
	if ( ! fromEnd ) { for ( let i = 0; i < n; i++ ) if ( p[ i ] >= 0.5 ) return i === 0 ? 0 : ( i - 0.5 ) + ( 0.5 - p[ i - 1 ] ) / ( p[ i ] - p[ i - 1 ] ); }
	else { for ( let i = n - 1; i >= 0; i-- ) if ( p[ i ] >= 0.5 ) return i === n - 1 ? n : ( i + 0.5 ) + ( p[ i ] - 0.5 ) / ( p[ i ] - p[ i + 1 ] ); }
	return NaN;
}
function bbox( d, w, x0, y0, x1, y1, mode ) {
	const cols = [], rows = [];
	for ( let x = x0; x < x1; x++ ) { let m = 0, f = 0; for ( let y = y0; y < y1; y++ ) { const v = w( x, y ); m = Math.max( m, v ); f += v > 0.5 ? 1 : 0; } cols.push( 'frac' === mode ? f / ( y1 - y0 ) * 2 : m ); }
	for ( let y = y0; y < y1; y++ ) { let m = 0, f = 0; for ( let x = x0; x < x1; x++ ) { const v = w( x, y ); m = Math.max( m, v ); f += v > 0.5 ? 1 : 0; } rows.push( m ); }
	const l = x0 + edge( cols, false ), r = x0 + edge( cols, true ), t = y0 + edge( rows, false ), b = y0 + edge( rows, true );
	return { l, r, t, b, cx: ( l + r ) / 2, cy: ( t + b ) / 2 };
}

const MEASURE = {
	radio( it, d, b ) {
		const s = it.scale, bg = frameBg( d );
		const R = b.w / 2, bw = Math.max( it.border, 1 ) * s;
		let c = { x: b.x + b.w / 2, y: b.y + b.h / 2 };
		for ( let k = 0; k < 2; k++ ) {
			let sx = 0, sy = 0, sw = 0;
			for ( let y = 0; y < d.height; y++ ) for ( let x = 0; x < d.width; x++ ) {
				const r = Math.hypot( x + 0.5 - c.x, y + 0.5 - c.y );
				if ( r < R - bw - 0.75 * s || r > R + 0.75 * s ) continue;
				const w = dist( px( d, x, y ), bg );
				sx += w * ( x + 0.5 ); sy += w * ( y + 0.5 ); sw += w;
			}
			if ( sw ) c = { x: sx / sw, y: sy / sw };
		}
		// The colour between ring and dot, then the dot as everything different from it.
		const rs = ( ( R - bw ) + R * 0.5 ) / 2, ring = [];
		for ( let a = 0; a < 24; a++ ) ring.push( sample( d, c.x + rs * Math.cos( a * Math.PI / 12 ), c.y + rs * Math.sin( a * Math.PI / 12 ) ) );
		const inside = median( ring ), rIn = R - bw - 0.6 * s;
		let max = 0;
		for ( let y = 0; y < d.height; y++ ) for ( let x = 0; x < d.width; x++ ) if ( Math.hypot( x + 0.5 - c.x, y + 0.5 - c.y ) < rIn ) max = Math.max( max, dist( px( d, x, y ), inside ) );
		let sx = 0, sy = 0, sw = 0, area = 0;
		for ( let y = 0; y < d.height; y++ ) for ( let x = 0; x < d.width; x++ ) {
			if ( Math.hypot( x + 0.5 - c.x, y + 0.5 - c.y ) >= rIn ) continue;
			const v = dist( px( d, x, y ), inside );
			const w = Math.max( 0, v - 0.15 * max );
			sx += w * ( x + 0.5 ); sy += w * ( y + 0.5 ); sw += w;
			if ( v > 0.5 * max ) area++;
		}
		const dot = max > 40 && area / ( s * s ) > 3;
		const out = { center: c, dot, dotArea: +( area / ( s * s ) ).toFixed( 1 ) };
		if ( dot ) { out.inner = { x: sx / sw, y: sy / sw }; out.dx = ( out.inner.x - c.x ) / s; out.dy = ( out.inner.y - c.y ) / s; }
		return out;
	},
	checkbox( it, d, b ) {
		const s = it.scale, bg = frameBg( d ), bw = Math.max( it.border, 1 ) * s;
		let maxBg = 0;
		for ( let y = 0; y < d.height; y++ ) for ( let x = 0; x < d.width; x++ ) maxBg = Math.max( maxBg, dist( px( d, x, y ), bg ) );
		const box = bbox( d, ( x, y ) => dist( px( d, x, y ), bg ) / ( maxBg || 1 ), 0, 0, d.width, d.height );
		const ins = bw + 1.2 * s;
		const fill = median( [ sample( d, box.l + ins, box.t + ins ), sample( d, box.r - ins, box.t + ins ), sample( d, box.l + ins, box.b - ins ), sample( d, box.r - ins, box.b - ins ) ] );
		// The tick keeps ≥ 2 px from the border; 1 px of margin keeps the border's soft edge out.
		const x0 = Math.ceil( box.l + bw + s ), x1 = Math.floor( box.r - bw - s ), y0 = Math.ceil( box.t + bw + s ), y1 = Math.floor( box.b - bw - s );
		let tick = fill, far = 0;
		for ( let y = y0; y < y1; y++ ) for ( let x = x0; x < x1; x++ ) { const p = px( d, x, y ), v = dist( p, fill ); if ( v > far ) { far = v; tick = p; } }
		const out = { center: { x: box.cx, y: box.cy }, tick: far > 60 };
		if ( out.tick ) {
			const t = bbox( d, ( x, y ) => proj( px( d, x, y ), fill, tick ), x0, y0, x1, y1 );
			out.inner = { x: t.cx, y: t.cy }; out.dx = ( t.cx - box.cx ) / s; out.dy = ( t.cy - box.cy ) / s;
		}
		return out;
	},
	switch( it, d, b ) {
		// Gaps between knob and track along the lines through the knob's centre: top vs bottom
		// (vertical centring) and the side it rests on vs top/bottom. A focus ring around the
		// track shifts every track edge alike, so the comparison is unaffected.
		const s = it.scale, bg = frameBg( d ), white = [ 255, 255, 255 ];
		const tc = sample( d, it.on ? b.x + b.h / 2 : b.x + b.w - b.h / 2, b.y + 1.2 * s );
		const capX = it.on ? b.x + b.w - b.h / 2 : b.x + b.h / 2, capY = b.y + b.h / 2, rad = b.h / 2 - 1.0 * s;
		let sx = 0, sy = 0, sw = 0;
		for ( let y = 0; y < d.height; y++ ) for ( let x = 0; x < d.width; x++ ) {
			if ( Math.hypot( x + 0.5 - capX, y + 0.5 - capY ) > rad ) continue;
			const w = proj( px( d, x, y ), tc, white );
			sx += w * ( x + 0.5 ); sy += w * ( y + 0.5 ); sw += w;
		}
		const kx = Math.floor( sx / sw ), ky = Math.floor( sy / sw );
		const col = ( f ) => { const a = []; for ( let y = 0; y < d.height; y++ ) a.push( f( px( d, kx, y ) ) ); return a; };
		const row = ( f ) => { const a = []; for ( let x = 0; x < d.width; x++ ) a.push( f( px( d, x, ky ) ) ); return a; };
		const near = dist( bg, tc ) || 1;
		const trackM = ( p ) => Math.max( 0, 1 - dist( p, tc ) / near ), knobM = ( p ) => proj( p, tc, white );
		// From index i, walk in direction dir while arr ≥ thr; the sub-pixel position where it drops below.
		const walk = ( arr, i, dir, thr ) => { while ( i + dir >= 0 && i + dir < arr.length && arr[ i + dir ] >= thr ) i += dir; const j = i + dir; if ( j < 0 || j >= arr.length ) return dir > 0 ? arr.length : 0; return ( i + 0.5 ) + dir * ( arr[ i ] - thr ) / ( arr[ i ] - arr[ j ] ); };
		const cK = col( knobM ), rK = row( knobM ), cT = col( trackM ), rT = row( trackM );
		const kt = walk( cK, ky, -1, 0.5 ), kb = walk( cK, ky, 1, 0.5 ), kl = walk( rK, kx, -1, 0.5 ), kr = walk( rK, kx, 1, 0.5 );
		// The track runs from the knob's edge outward until its colour ends (a focus ring beyond it is not track).
		const tt = walk( cT, Math.floor( kt ) - 1, -1, 0.75 ), tb = walk( cT, Math.ceil( kb ), 1, 0.75 );
		const tl = walk( rT, Math.floor( kl ) - 1, -1, 0.75 ), tr = walk( rT, Math.ceil( kr ), 1, 0.75 );
		const top = kt - tt, bottom = tb - kb, side = it.on ? tr - kr : kl - tl;
		return { center: { x: it.on ? tr - ( tb - tt ) / 2 : tl + ( tb - tt ) / 2, y: ( tt + tb ) / 2 }, inner: { x: ( kl + kr ) / 2, y: ( kt + kb ) / 2 },
			dx: 0, dy: ( top - bottom ) / 2 / s, gaps: { top: top / s, bottom: bottom / s, side: side / s }, knobArea: +( sw / ( s * s ) ).toFixed( 1 ) };
	},
	segmented( it, d, b ) {
		const s = it.scale, bg = frameBg( d );
		const tc = sample( d, b.x + 1.5 * s, b.y + b.h / 2 );
		const track = bbox( d, ( x, y ) => proj( px( d, x, y ), bg, tc ), 0, 0, d.width, d.height );
		const white = [ 255, 255, 255 ];
		const sel = bbox( d, ( x, y ) => proj( px( d, x, y ), tc, white ), Math.ceil( track.l ), Math.ceil( track.t ), Math.floor( track.r ), Math.floor( track.b ), 'frac' );
		return { center: { x: track.cx, y: track.cy }, inner: { x: sel.cx, y: sel.cy }, dx: 0, dy: ( sel.cy - track.cy ) / s, insetTop: ( sel.t - track.t ) / s, insetBottom: ( track.b - sel.b ) / s };
	},
};

`;

/* ------------------------------------------------------------------ */
/* Checks                                                              */
/* ------------------------------------------------------------------ */

const isOn = ( key ) => /\|(on|first|second|disabled-on|in-paragraph)$/.test( key );

function judge( r ) {
	const type = TYPE[ r.key.split( '|' )[ 0 ] ];
	const on = isOn( r.key );
	const fails = [];
	if ( r.error ) fails.push( 'could not measure: ' + r.error );
	else if ( 'radio' === type && on ) {
		if ( ! r.dot ) fails.push( 'no dot' );
		else if ( Math.abs( r.dx ) > LIMIT || Math.abs( r.dy ) > LIMIT ) fails.push( `dot off-centre by ${ r.dx.toFixed( 2 ) }, ${ r.dy.toFixed( 2 ) } px` );
	} else if ( 'radio' === type && r.dot ) fails.push( 'dot shown while unchecked' );
	else if ( 'checkbox' === type && on ) {
		if ( ! r.tick ) fails.push( 'no tick' );
		else if ( Math.abs( r.dx ) > LIMIT || Math.abs( r.dy ) > LIMIT ) fails.push( `tick off-centre by ${ r.dx.toFixed( 2 ) }, ${ r.dy.toFixed( 2 ) } px` );
	} else if ( 'switch' === type ) {
		if ( ! ( r.knobArea > 20 ) ) fails.push( 'no knob' );
		else if ( Math.abs( r.dy ) > LIMIT ) fails.push( `knob not centred vertically (gap ${ r.gaps.top.toFixed( 2 ) } above, ${ r.gaps.bottom.toFixed( 2 ) } below)` );
	} else if ( 'segmented' === type ) {
		if ( Math.abs( r.insetTop - r.insetBottom ) > 2 * LIMIT ) fails.push( `selected segment not centred (top ${ r.insetTop.toFixed( 2 ) }, bottom ${ r.insetBottom.toFixed( 2 ) })` );
	}
	return fails;
}

/* ------------------------------------------------------------------ */
/* Drivers                                                             */
/* ------------------------------------------------------------------ */

const ensure = ( dir ) => fs.mkdirSync( dir, { recursive: true } );
const report = { limit: LIMIT, runs: [] };
let failures = 0;

async function analyse( lab, png, rects, map, state, only ) {
	const items = Object.entries( rects ).filter( ( [ k ] ) => ! only || only === k ).map( ( [ key, r ] ) => ( {
		key, state, type: TYPE[ key.split( '|' )[ 0 ] ], on: /\|(on|disabled-on)$/.test( key ),
		box: { x: ( map.ax || map.a ) * r.x + map.bx, y: ( map.ay || map.a ) * r.y + map.by, w: ( map.ax || map.a ) * r.w, h: ( map.ay || map.a ) * r.h },
		// Image pixels per CSS px of the design (screen scale × page zoom): limits and offsets are in these.
		scale: map.a * ( r.zoom || 1 ), border: r.border, pad: Math.ceil( 6 * map.a * ( r.zoom || 1 ) ),
	} ) );
	return lab.evaluate( ( [ b64, its ] ) => window.fxqAnalyze( b64, its ), [ png.toString( 'base64' ), items ] );
}

function record( engine, area, mode, scaleLabel, results, raw ) { // eslint-disable-line max-params
	const run = { engine, area, mode, scale: scaleLabel, results: [] };
	for ( const r of results ) {
		r.fail = judge( r );
	}
	// The switch knob keeps the same gap to the end of the track off (left) and on (right):
	// both ends are round, so their edges are measured alike (a round end vs the straight top would not be).
	for ( const [ off, on ] of [ [ 'switch|off', 'switch|on' ], [ 'switch|disabled-off', 'switch|disabled-on' ] ] ) {
		const a = results.find( ( r ) => r.key === off && 'static' === r.state && r.gaps ), b = results.find( ( r ) => r.key === on && 'static' === r.state && r.gaps );
		if ( a && b ) {
			const d = b.gaps.side - a.gaps.side;
			a.dx = -d / 2; b.dx = d / 2;
			if ( Math.abs( d ) > LIMIT ) [ a, b ].forEach( ( r ) => r.fail.push( `knob gap at the end ${ a.gaps.side.toFixed( 2 ) } off vs ${ b.gaps.side.toFixed( 2 ) } on` ) );
		}
	}
	for ( const r of results ) {
		r.bad = r.fail.length > 0;
		if ( raw && r.png ) {
			const dir = path.join( OUT, engine, area + ( 'hostile' === mode ? '-hostile' : '' ), scaleLabel );
			ensure( dir );
			fs.writeFileSync( path.join( dir, r.key.replace( '|', '--' ) + ( 'static' === r.state ? '' : '--' + r.state ) + '.png' ), Buffer.from( r.png, 'base64' ) );
		}
		const { png, ...keep } = r;
		run.results.push( keep );
	}
	report.runs.push( run );
}

async function sheet( lab, engine, area, mode, scaleLabel, results ) {
	const title = `${ engine } · ${ area }${ 'hostile' === mode ? ' + hostile theme/plugin CSS' : '' } · ${ scaleLabel } · limit ${ LIMIT } CSS px`;
	const b64 = await lab.evaluate( ( [ list, t ] ) => window.fxqSheet( list, t ), [ results, title ] );
	const dir = path.join( OUT, 'sheets' );
	ensure( dir );
	fs.writeFileSync( path.join( dir, `${ engine }-${ area }${ 'hostile' === mode ? '-hostile' : '' }-${ scaleLabel }.png` ), Buffer.from( b64, 'base64' ) );
}

const PAGE = { admin: '/wp-admin/admin.php?page=flexo-booking-appearance', front: '/booking/' };
// The element to hover or focus for an item: the input itself, or the input of the switch / segmented control.
const targetOf = ( key ) => `input[data-q="${ key }"], label:has(> [data-q="${ key }"]) > input, [data-q="${ key }"] input`;
const hoverOf = ( key ) => `input[data-q="${ key }"], label:has(> [data-q="${ key }"])`;

async function runChromium( lab ) {
	const browser = await chromium.launch();
	const login = await browser.newContext();
	const lp = await login.newPage();
	await lp.goto( BASE + '/wp-login.php' );
	await lp.fill( '#user_login', 'admin' );
	await lp.fill( '#user_pass', 'admin' );
	await Promise.all( [ lp.waitForNavigation(), lp.click( '#wp-submit' ) ] );
	const state = await login.storageState();
	await login.close();
	for ( const scale of CHROMIUM_SCALES ) {
		const ctx = await browser.newContext( { viewport: { width: 1280, height: 1100 }, deviceScaleFactor: scale, storageState: state } );
		const p = await ctx.newPage();
		for ( const area of AREAS ) {
			for ( const mode of [ 'normal', 'hostile' ] ) {
				await p.goto( BASE + PAGE[ area ], { waitUntil: 'load' } );
				await p.evaluate( prepare, { fixture: 'admin' === area ? ADMIN_FIXTURE : FRONT_FIXTURE, css: 'hostile' === mode ? HOSTILE[ area ] : '', area } );
				await p.mouse.move( 1275, 1095 );
				await p.waitForTimeout( 250 );
				const results = [];
				const grab = async ( only, st ) => {
					const rects = await p.evaluate( boxes );
					for ( const [ key, r ] of Object.entries( rects ) ) {
						if ( only && only !== key ) continue;
						const clip = { x: r.x - 6, y: r.y - 6, width: r.w + 12, height: r.h + 12 };
						const png = await p.screenshot( { clip, animations: 'disabled' } );
						results.push( ...await analyse( lab, png, { [ key ]: { ...r, x: 6, y: 6 } }, { a: scale, bx: 0, by: 0 }, st ) );
					}
				};
				await grab( null, 'static' );
				for ( const [ key, how ] of DYNAMIC[ area ] ) {
					if ( 'hover' === how ) {
						await p.hover( hoverOf( key ) );
					} else {
						await p.keyboard.press( 'Shift' );
						await p.focus( targetOf( key ) );
					}
					await p.waitForTimeout( 220 );
					await grab( key, how );
					await p.mouse.move( 1275, 1095 );
					await p.evaluate( () => document.activeElement && document.activeElement.blur() );
				}
				record( 'chromium', area, mode, 'x' + scale, results, RAW_CHROMIUM.includes( scale ) );
				await sheet( lab, 'chromium', area, mode, 'x' + scale, results );
			}
		}
		await ctx.close();
	}
	await browser.close();
}

/* WordPress's own screens: their checkboxes and radios must look exactly as before (no leak). */
async function runCore() {
	const crypto = require( 'crypto' );
	const browser = await chromium.launch();
	const ctx = await browser.newContext( { viewport: { width: 1280, height: 1100 }, deviceScaleFactor: 2 } );
	const p = await ctx.newPage();
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', 'admin' );
	await p.fill( '#user_pass', 'admin' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
	const sig = {};
	for ( const url of [ 'wp-admin/options-general.php', 'wp-admin/options-discussion.php', 'wp-admin/options-reading.php', 'wp-admin/edit.php' ] ) {
		await p.goto( BASE + '/' + url, { waitUntil: 'load' } );
		await p.mouse.move( 1275, 1095 );
		const list = await p.evaluate( () => [ ...document.querySelectorAll( '#wpbody-content input:is([type=checkbox],[type=radio])' ) ].filter( ( i ) => i.offsetParent ).slice( 0, 8 ).map( ( i, n ) => {
			i.setAttribute( 'data-core-q', n );
			const pick = ( c, k ) => k.map( ( x ) => c.getPropertyValue( x ) ).join( '|' );
			const k = [ 'appearance', 'width', 'height', 'margin', 'padding', 'border', 'border-radius', 'background', 'box-shadow', 'line-height', 'vertical-align', 'display' ];
			return { n, checked: i.checked, type: i.type, input: pick( getComputedStyle( i ), k ), before: pick( getComputedStyle( i, '::before' ), [ 'content', 'width', 'height', 'margin', 'background-color', 'float', 'display' ] ), flexo: !! document.querySelector( 'link[href*="flexo-admin"]' ) };
		} ) );
		for ( const it of list ) {
			const png = await p.locator( `[data-core-q="${ it.n }"]` ).screenshot();
			sig[ url + '#' + it.n + ' ' + it.type + ( it.checked ? ' checked' : '' ) ] = { ...it, pixels: crypto.createHash( 'sha1' ).update( png ).digest( 'hex' ) };
		}
	}
	await browser.close();
	fs.writeFileSync( path.join( OUT, 'core-controls.json' ), JSON.stringify( sig, null, 1 ) );
	const run = { engine: 'chromium', area: 'core', mode: 'normal', scale: 'x2', results: [] };
	const basePath = path.join( path.dirname( OUT ), 'before', 'core-controls.json' );
	const base = OUT.endsWith( 'before' ) || ! fs.existsSync( basePath ) ? null : JSON.parse( fs.readFileSync( basePath, 'utf8' ) );
	for ( const [ key, v ] of Object.entries( sig ) ) {
		const fail = [];
		if ( v.flexo ) fail.push( 'the plugin\'s admin stylesheet is loaded on a WordPress screen' );
		if ( base && ! base[ key ] ) fail.push( 'not on the earlier run' );
		if ( base && base[ key ] && ( base[ key ].input !== v.input || base[ key ].before !== v.before ) ) fail.push( 'computed style changed' );
		if ( base && base[ key ] && base[ key ].pixels !== v.pixels ) fail.push( 'pixels changed' );
		run.results.push( { key: 'core|' + key, state: 'static', fail, bad: fail.length > 0 } );
	}
	report.runs.push( run );
}

/* WebKit through WebKitGTK's WebDriver (MiniBrowser), on 1×/2×/3× screens with page zoom. */
async function runWebKit( lab ) {
	const bin = '/usr/lib/x86_64-linux-gnu/webkit2gtk-4.1/MiniBrowser';
	for ( const screen of WEBKIT_SCREENS ) {
		const port = 4600 + screen;
		const drv = spawn( 'WebKitWebDriver', [ '--port=' + port ], { env: { ...process.env, GDK_SCALE: String( screen ) }, stdio: 'ignore' } );
		await new Promise( ( r ) => setTimeout( r, 1000 ) );
		const W = `http://127.0.0.1:${ port }`;
		const call = async ( method, url, body ) => { const r = await fetch( W + url, { method, headers: { 'content-type': 'application/json' }, body: body ? JSON.stringify( body ) : undefined } ); const j = await r.json(); if ( j.value && j.value.error ) throw new Error( j.value.error + ': ' + j.value.message ); return j.value; };
		const sess = await call( 'POST', '/session', { capabilities: { alwaysMatch: { 'webkitgtk:browserOptions': { binary: bin, args: [ '--automation' ] } } } } );
		const S = `/session/${ sess.sessionId }`;
		const run = ( script, args = [] ) => call( 'POST', S + '/execute/sync', { script, args } );
		const pointer = ( move ) => call( 'POST', S + '/actions', { actions: [ { type: 'pointer', id: 'mouse', parameters: { pointerType: 'mouse' }, actions: [ { type: 'pointerMove', duration: 0, ...move } ] } ] } );
		await call( 'POST', S + '/window/rect', { width: 1280, height: 2000 } );
		await call( 'POST', S + '/url', { url: BASE + '/wp-login.php' } );
		await run( "document.getElementById('user_login').value='admin';document.getElementById('user_pass').value='admin';document.getElementById('loginform').submit();" );
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
		for ( const zoom of WEBKIT_ZOOMS ) {
			const label = `x${ screen }-zoom${ Math.round( zoom * 100 ) }`;
			for ( const area of AREAS ) {
				for ( const mode of [ 'normal', 'hostile' ] ) {
					await call( 'POST', S + '/url', { url: BASE + PAGE[ area ] } );
					await run( `return (${ prepare.toString() })(arguments[0])`, [ { fixture: 'admin' === area ? ADMIN_FIXTURE : FRONT_FIXTURE, css: 'hostile' === mode ? HOSTILE[ area ] : '', area } ] );
					// Page zoom (WebKit applies it as zoom on the root); two squares at opposite ends of the fixture calibrate rect → image pixels.
					await run( "document.documentElement.style.zoom=arguments[0];window.scrollTo(0,0);if(arguments[0]!=='1')document.getElementById('fxq').scrollIntoView({block:'start'});", [ String( zoom ) ] );
					const park = await run( 'return {x:innerWidth-4,y:innerHeight-4}' );
					await pointer( park );
					await new Promise( ( r ) => setTimeout( r, 300 ) );
					const results = [];
					const grab = async ( only, st ) => {
						const rects = await run( `return (${ boxes.toString() })()` );
						if ( 1 !== zoom ) Object.keys( rects ).filter( ( k ) => k.startsWith( 'real-card' ) ).forEach( ( k ) => delete rects[ k ] );
						const cal = await run( "const a=document.getElementById('fxq-cal').getBoundingClientRect(),b=document.getElementById('fxq-cal2').getBoundingClientRect();return {x1:a.x,y1:a.y,x2:b.x,y2:b.y}" );
						const png = Buffer.from( await call( 'GET', S + '/screenshot' ), 'base64' );
						const map = await lab.evaluate( async ( [ b64, c ] ) => {
							const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
							const cv = document.createElement( 'canvas' ); cv.width = img.naturalWidth; cv.height = img.naturalHeight;
							const g = cv.getContext( '2d', { willReadFrequently: true } ); g.drawImage( img, 0, 0 );
							const d = g.getImageData( 0, 0, cv.width, cv.height ).data;
							// Top-left corner of each square (magenta, cyan).
							const find = ( test ) => { let x0 = 1e9, y0 = 1e9; for ( let y = 0; y < cv.height; y++ ) for ( let x = 0; x < cv.width; x++ ) { const i = ( y * cv.width + x ) * 4; if ( test( d[ i ], d[ i + 1 ], d[ i + 2 ] ) ) { if ( y < y0 ) y0 = y; if ( x < x0 ) x0 = x; } } return { x: x0, y: y0 }; };
							const m = find( ( r, gr, bl ) => r > 230 && gr < 30 && bl > 230 ), cy = find( ( r, gr, bl ) => r < 30 && gr > 230 && bl > 230 );
							const ax = ( cy.x - m.x ) / ( c.x2 - c.x1 ), ay = ( cy.y - m.y ) / ( c.y2 - c.y1 );
							return { a: ( ax + ay ) / 2, ax, ay, bx: m.x - ax * c.x1, by: m.y - ay * c.y1 };
						}, [ png.toString( 'base64' ), cal ] );
						results.push( ...await analyse( lab, png, rects, map, st, only ) );
					};
					await grab( null, 'static' );
					for ( const [ key, how ] of DYNAMIC[ area ] ) {
						if ( 'hover' === how ) {
							const el = await call( 'POST', S + '/element', { using: 'css selector', value: hoverOf( key ) } );
							try {
								await pointer( { origin: { 'element-6066-11e4-a52e-4f735466cecf': Object.values( el )[ 0 ] }, x: 0, y: 0 } );
							} catch ( e ) {
								results.push( { key, state: how, error: 'pointer could not reach it: ' + e.message, png: '' } );
								continue;
							}
						} else {
							await call( 'POST', S + '/actions', { actions: [ { type: 'key', id: 'kbd', actions: [ { type: 'keyDown', value: '\uE008' }, { type: 'keyUp', value: '\uE008' } ] } ] } );
							await run( 'document.querySelector(arguments[0]).focus()', [ targetOf( key ) ] );
						}
						await new Promise( ( r ) => setTimeout( r, 300 ) );
						await grab( key, how );
						await call( 'DELETE', S + '/actions' );
						await pointer( park );
						await run( 'document.activeElement && document.activeElement.blur()' );
					}
					record( 'webkit', area, mode, label, results, 3 === screen );
					await sheet( lab, 'webkit', area, mode, label, results );
				}
			}
		}
		await call( 'DELETE', S );
		drv.kill();
	}
}

// A before/after sheet of the controls that matter most, when a LABEL=before run exists.
async function compare( lab ) {
	const beforeDir = path.join( path.dirname( OUT ), 'before' );
	if ( OUT === beforeDir || ! fs.existsSync( path.join( beforeDir, 'report.json' ) ) ) {
		return;
	}
	const before = JSON.parse( fs.readFileSync( path.join( beforeDir, 'report.json' ), 'utf8' ) );
	const find = ( rep, engine, area, scale, key ) => {
		const run = rep.runs.find( ( r ) => r.engine === engine && r.area === area && r.mode === 'normal' && r.scale === scale );
		return run && run.results.find( ( r ) => r.key === key && 'static' === r.state );
	};
	const picks = [];
	for ( const [ engine, scale ] of [ [ 'chromium', 'x3' ], [ 'chromium', 'x3.75' ], [ 'webkit', 'x3-zoom125' ], [ 'webkit', 'x3-zoom150' ] ] ) {
		for ( const [ area, key ] of [ [ 'admin', 'real-card|on' ], [ 'admin', 'card|on' ], [ 'admin', 'radio|on' ], [ 'admin', 'checkbox|on' ], [ 'admin', 'switch|on' ], [ 'admin', 'switch|off' ], [ 'front', 'terms|on' ], [ 'front', 'fradio|on' ], [ 'front', 'fchoice|on' ] ] ) {
			const file = ( label ) => path.join( path.dirname( OUT ), label, engine, area, scale, key.replace( '|', '--' ) + '.png' );
			const b = find( before, engine, area, scale, key ), a = find( report, engine, area, scale, key );
			if ( ! fs.existsSync( file( 'before' ) ) && ! fs.existsSync( file( path.basename( OUT ) ) ) ) continue;
			const side = ( r, f ) => ( r && fs.existsSync( f ) ? { png: fs.readFileSync( f ).toString( 'base64' ), center: r.center, inner: r.inner, dx: r.dx, dy: r.dy, bad: r.bad } : null );
			picks.push( { label: `${ engine } ${ scale } · ${ area } ${ key }`, before: side( b, file( 'before' ) ), after: side( a, file( path.basename( OUT ) ) ) } );
		}
	}
	for ( let i = 0; i < picks.length; i += 18 ) {
		const b64 = await lab.evaluate( ( [ rows, t ] ) => window.fxqCompare( rows, t ), [ picks.slice( i, i + 18 ), 'Before / after (red cross = ring or box centre, green = dot, tick or knob centre)' ] );
		fs.writeFileSync( path.join( path.dirname( OUT ), `before-after-${ 1 + i / 18 }.png` ), Buffer.from( b64, 'base64' ) );
	}
}

( async () => {
	ensure( OUT );
	const labBrowser = await chromium.launch();
	const lab = await labBrowser.newPage();
	await lab.setContent( '<!doctype html><title>lab</title>' );
	await lab.addScriptTag( { content: LAB } );
	const existing = path.join( OUT, 'report.json' );
	if ( process.env.ENGINES && fs.existsSync( existing ) ) {
		// Keeps every run this invocation doesn't repeat (other engines, other WebKit screens/zooms, other areas).
		const redo = ( r ) => ENGINES.includes( r.engine ) && AREAS.includes( r.area ) && ( 'webkit' !== r.engine || ( WEBKIT_SCREENS.some( ( sc ) => r.scale.startsWith( `x${ sc }-` ) ) && WEBKIT_ZOOMS.some( ( z ) => r.scale.endsWith( `-zoom${ Math.round( z * 100 ) }` ) ) ) );
		report.runs = JSON.parse( fs.readFileSync( existing, 'utf8' ) ).runs.filter( ( r ) => 'core' !== r.area && ! redo( r ) );
	}
	if ( ENGINES.includes( 'chromium' ) && ! process.env.CORE_ONLY ) {
		await runChromium( lab );
	}
	await runCore();
	if ( ENGINES.includes( 'webkit' ) && ! process.env.CORE_ONLY ) {
		await runWebKit( lab );
	}
	fs.writeFileSync( path.join( OUT, 'report.json' ), JSON.stringify( report, null, 1 ) );
	await compare( lab );
	await labBrowser.close();
	// Summary: worst offset per engine, area and control type.
	const worst = {};
	for ( const run of report.runs ) for ( const r of run.results ) {
		// Offsets of checked controls (an unchecked radio or checkbox has no dot or tick to place).
		if ( 'core' === run.area || ! ( isOn( r.key ) || 'switch' === TYPE[ r.key.split( '|' )[ 0 ] ] ) ) continue;
		const k = `${ run.engine } ${ run.area }${ 'hostile' === run.mode ? ' (hostile CSS)' : '' } ${ TYPE[ r.key.split( '|' )[ 0 ] ] }`;
		const v = 'segmented' === TYPE[ r.key.split( '|' )[ 0 ] ] ? Math.abs( ( r.insetTop || 0 ) - ( r.insetBottom || 0 ) ) / 2 : Math.max( Math.abs( r.dx || 0 ), Math.abs( r.dy || 0 ) );
		if ( ! worst[ k ] || v > worst[ k ].v ) worst[ k ] = { v, at: `${ run.scale } ${ r.key } ${ r.state }` };
	}
	for ( const [ k, w ] of Object.entries( worst ) ) console.log( `${ k.padEnd( 44 ) } worst ${ w.v.toFixed( 2 ) } px  (${ w.at })` );
	const list = report.runs.flatMap( ( run ) => run.results.filter( ( r ) => r.fail.length ).map( ( r ) => `  FAIL ${ run.engine } ${ run.area }${ 'hostile' === run.mode ? '+hostile' : '' } ${ run.scale } ${ r.key } ${ r.state }: ${ r.fail.join( '; ' ) }` ) );
	list.slice( 0, 60 ).forEach( ( l ) => console.log( l ) );
	if ( list.length > 60 ) console.log( `  … ${ list.length - 60 } more` );
	console.log( `\nResult: ${ report.runs.reduce( ( n, r ) => n + r.results.length, 0 ) - list.length } passed, ${ list.length } failed` );
	process.exit( list.length ? 1 : 0 );
} )();
