/**
 * Widget controls check (1.10.0, Part 10), after tests/ui/widgets-seed.php
 * (which wrote OUT/controls.json):
 *
 *   BASE=http://localhost:8092 OUT=flexo-booking/ui-audit/part-10 node tests/ui/widgets-check.js
 *
 * Elementor can't run here, so the widgets are rendered on plain pages
 * against a stand-in for its base classes (tests/ui/elementor-stub.php), in
 * an Elementor kit with its own button and field rules. For every control of
 * every Flexo widget the check does what Elementor does with it:
 *
 * - Style controls: the CSS Elementor would generate ({{WRAPPER}} →
 *   ".elementor-99 .elementor-element.elementor-element-fxw1", {{VALUE}},
 *   {{SIZE}}{{UNIT}}, {{TOP}}…, selectors_dictionary, group controls,
 *   prefix_class), with a test value; the control works when it changes the
 *   computed style of something in the widget (in its state: hover).
 * - Content controls: the widget rendered again with another value; the
 *   control works when the widget's markup changes.
 * - Conditions are met first (content settings, or the prefix class of the
 *   control they depend on).
 *
 * Writes OUT/widgets-check.json, OUT/widget-controls.md (the checklist of
 * every widget's sections and controls) and screenshots of each widget as
 * it comes and with every style control set.
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE = process.env.BASE || 'http://localhost:8092';
const OUT = process.env.OUT || 'flexo-booking/ui-audit/part-10';
const ONLY = process.env.ONLY ? process.env.ONLY.split( ',' ) : null;
const WRAP = '.elementor-99 .elementor-element.elementor-element-fxw1';
const data = JSON.parse( fs.readFileSync( process.env.CONTROLS || path.join( OUT, 'controls.json' ), 'utf8' ) );

const day = ( n ) => {
	const d = new Date();
	d.setUTCDate( d.getUTCDate() + n );
	return d.toISOString().slice( 0, 10 );
};
const STAY = `check_in=${ day( 30 ) }&check_out=${ day( 33 ) }&adults=2`;

let pass = 0, fail = 0;
const results = [];
const ok = ( cond, name, detail ) => {
	cond ? pass++ : fail++;
	results.push( { name, ok: !! cond, detail: detail || '' } );
	if ( ! cond || process.env.VERBOSE ) {
		console.log( ( cond ? '  PASS ' : '  FAIL ' ) + name + ( detail ? ' – ' + detail : '' ) );
	}
};

// How each widget is shown: base settings and what to do once loaded.
const room = data.room;
const contact = { title: 'Write to us', intro: 'We reply within a day.' };
// Results, then the calendar opened on the chosen dates ("Change"): chosen days, the nights between.
const FORM = [ { query: STAY, wait: '.fb-room' }, { query: STAY, wait: '.fb-room', click: '.fb-stay__change' } ];
const SETUP = {
	'flexo-booking-form': FORM,
	'flexo-room-booking-box': [ { settings: { room: data.box_room, title: 'Check availability' }, query: STAY, open: '.fb-date' } ],
	'flexo-contact-form': [ { settings: contact }, { settings: contact, click: '.fb-contact [type=submit]' } ],
	'flexo-forms-tabs': FORM,
	'flexo-room-amenities': [ { settings: { room } } ],
	'flexo-room-details': [ { settings: { room } } ],
	'flexo-room-gallery': [ { settings: { room } } ],
};
// Elements to hover for hover colours set as tokens on the widget's root.
const HOVER = [
	[ /^button_outline_hover/, '.fb-button--ghost' ],
	[ /^button_hover/, '.fb-button:not(.fb-button--ghost)' ],
	[ /^tab_hover/, '.fb-tab[aria-selected="false"]' ],
	[ /^arrow_hover/, '.flexo-room-gallery__arrow--next' ],
	[ /^day_hover/, '.fb-day:not([aria-disabled="true"]):not(.is-start):not(.is-end):not(.is-range)' ],
	[ /^photo_zoom/, '.flexo-room-gallery__link' ],
];
const PROPS = [ 'color', 'backgroundColor', 'backgroundImage', 'borderTopColor', 'borderTopWidth', 'borderTopStyle', 'borderLeftWidth', 'borderLeftStyle', 'borderTopLeftRadius', 'boxShadow', 'paddingTop', 'paddingLeft', 'marginTop', 'width', 'height', 'minHeight', 'fontSize', 'fontWeight', 'letterSpacing', 'lineHeight', 'textAlign', 'opacity', 'transform', 'flexDirection', 'alignItems', 'justifyContent', 'rowGap', 'columnGap', 'display', 'objectFit', 'whiteSpace', 'textOverflow', 'top', 'left', 'right', 'gridTemplateColumns', 'webkitMaskImage', 'flexGrow', 'outlineColor' ];

function fill( template, values ) {
	return template.replace( /\{\{([A-Z_]+)\}\}/g, ( m, k ) => ( k in values ? values[ k ] : m ) );
}

// Another choice than the one the widget starts with. Without a default, the
// last choice (the first is usually what the CSS does anyway: left, beside, solid).
function otherOption( control ) {
	const options = control.options || [];
	if ( control.default === undefined || '' === control.default ) {
		return [ ...options ].reverse().find( ( o ) => o !== '' );
	}
	const def = String( control.default );
	return options.find( ( o ) => o !== def && o !== '' ) || options.find( ( o ) => o !== def );
}

// Controls whose colour shows only while a field has focus.
const FOCUS = [ [ /^field_focus/, 'input[type="text"], input[type="email"], textarea' ] ];

// Controls this check can't see on its test pages, and why.
const NOT_HERE = {
	success_color: 'shown after a booking or a sent message',
	original_colours: 'only for uploaded icon files; the test room uses the bundled icons',
	fact_icon_type: 'the test room has no room type, so the fact is not shown',
};

// The CSS Elementor writes for one control with a test value (null: nothing to write).
function cssFor( control ) {
	const rule = ( selector, body ) => selector.split( ',' ).map( ( s ) => s.trim().replace( '{{WRAPPER}}', WRAP ) ).join( ',' ) + '{' + body + '}';
	if ( control.group ) {
		const sel = control.selector;
		if ( ! sel ) {
			return null;
		}
		const body = {
			typography: 'font-size:23px;letter-spacing:1.7px;font-weight:300;',
			border: 'border-style:dashed;border-width:3px;border-color:#c0392b;',
			'box-shadow': 'box-shadow:0 0 0 5px #c0392b;',
		}[ control.group ];
		return rule( sel, body );
	}
	if ( ! control.selectors ) {
		return null;
	}
	let values = {};
	switch ( control.type ) {
		case 'color':
			values = { VALUE: '#c0392b' };
			break;
		case 'slider': {
			const unit = ( control.size_units || [ 'px' ] )[ 0 ];
			const range = ( control.range || {} )[ unit ] || { min: 0, max: 50 };
			const size = Math.round( range.min + ( range.max - range.min ) * 0.62 );
			values = { SIZE: String( size ), UNIT: unit };
			break;
		}
		case 'dimensions':
			values = { TOP: '7', RIGHT: '11', BOTTOM: '13', LEFT: '17', UNIT: 'px' };
			break;
		case 'select':
		case 'choose': {
			const opt = otherOption( control );
			if ( opt === undefined ) {
				return null;
			}
			const dict = control.selectors_dictionary || {};
			values = { VALUE: opt in dict ? dict[ opt ] : opt };
			break;
		}
		default:
			return null;
	}
	return Object.entries( control.selectors ).map( ( [ sel, body ] ) => rule( sel, fill( body, values ) ) ).join( '\n' );
}

// For the "styled" screenshots: every style control set as a designer might (a sand and
// charcoal palette, like the Azure site), instead of the check's test values.
function designCss( control ) {
	const rule = ( selector, body ) => selector.split( ',' ).map( ( x ) => x.trim().replace( '{{WRAPPER}}', WRAP ) ).join( ',' ) + '{' + body + '}';
	if ( control.group ) {
		const body = {
			typography: 'font-weight:600;',
			border: 'border-style:solid;border-width:1px;border-color:#B57A5A;',
			'box-shadow': 'box-shadow:0 8px 20px -10px rgba(33,43,47,.35);',
		}[ control.group ];
		return control.selector ? rule( control.selector, body ) : null;
	}
	if ( ! control.selectors || [ 'select', 'choose' ].includes( control.type ) ) {
		return null;
	}
	const id = control.id;
	let values = {};
	if ( 'color' === control.type ) {
		let color = '#212B2F';
		const strong = { button_bg_color: '#212B2F', button_border_color: '#212B2F', button_text_color: '#ffffff', tab_active_bg: '#B57A5A', tab_active_text: '#ffffff', tab_active_border: '#B57A5A', day_selected_bg: '#212B2F', day_selected_text: '#ffffff', color_primary: '#212B2F', color_primary_contrast: '#ffffff', button_outline_bg: 'transparent' };
		if ( id in strong ) {
			color = strong[ id ];
		} else if ( /hover/.test( id ) ) {
			color = /text|color$/.test( id ) && ! /bg/.test( id ) ? '#ffffff' : '#B57A5A';
		} else if ( /(_bg|background|summary_bg|calendar_bg|card_bg|item_bg|fraction_bg)/.test( id ) ) {
			color = /item_bg|card_bg/.test( id ) ? '#FBF7F0' : '#F3EBDD';
		} else if ( /border|divider/.test( id ) ) {
			color = '#D9C3A8';
		} else if ( /icon|dot|accent|primary|selected_bg|active_bg|range/.test( id ) ) {
			color = '#B57A5A';
		} else if ( /contrast|selected_text|active_text/.test( id ) ) {
			color = '#ffffff';
		}
		values = { VALUE: color };
	} else if ( 'slider' === control.type ) {
		const unit = ( control.size_units || [ 'px' ] )[ 0 ];
		const range = ( control.range || {} )[ unit ] || { min: 0, max: 50 };
		values = { SIZE: String( Math.round( range.min + ( range.max - range.min ) * 0.3 ) ), UNIT: unit };
	} else if ( 'dimensions' === control.type ) {
		values = { TOP: '10', RIGHT: '18', BOTTOM: '10', LEFT: '18', UNIT: 'px' };
	} else {
		return null;
	}
	return Object.entries( control.selectors ).map( ( [ sel, body ] ) => rule( sel, fill( body, values ) ) ).join( '\n' );
}

// The checklist name of a control: its label, the tab it sits in (Normal, Hover…).
function controlName( c ) {
	const groups = { typography: 'Typography', border: 'Border', 'box-shadow': 'Shadow' };
	let name = c.label || groups[ c.group ] || c.id;
	if ( c.group && c.label && c.label !== groups[ c.group ] ) {
		name = c.label + ' (' + groups[ c.group ].toLowerCase() + ')';
	}
	const tab = c.control_tab || '';
	const where = /outline/.test( tab ) ? ( /hover/.test( tab ) ? 'outline, hover' : 'outline' ) : /hover/.test( tab ) ? 'hover' : /active/.test( tab ) ? 'selected' : '';
	return name + ( where ? ` (${ where })` : '' ) + ( c.responsive ? ' ⟂' : '' );
}

// A test value for a content control (re-render), or undefined when none.
function contentValue( control ) {
	const def = control.default !== undefined ? control.default : '';
	switch ( control.type ) {
		case 'text':
		case 'textarea':
			return 'Fxw ' + control.id;
		case 'number':
			return def ? Number( def ) + 2 : 1;
		case 'switcher':
			return 'yes' === def ? '' : 'yes';
		case 'select':
		case 'choose':
			return otherOption( control );
		case 'select2':
			return [ ( control.options || [] )[ 0 ] ];
		case 'icons':
			return { value: 'fas fa-star', library: 'fa-solid' };
	}
	return undefined;
}

// What a control needs before it shows: content settings and wrapper classes.
function needs( control, all ) {
	const settings = {};
	const classes = [];
	Object.entries( control.condition || {} ).forEach( ( [ key, want ] ) => {
		if ( key.endsWith( '!' ) ) {
			return;
		}
		const value = Array.isArray( want ) ? want[ 0 ] : want;
		const dep = all.find( ( c ) => c.id === key );
		if ( dep && dep.prefix_class ) {
			classes.push( dep.prefix_class + value );
		} else {
			settings[ key ] = value;
		}
	} );
	return { settings, classes };
}

async function snapshot( page ) {
	return page.evaluate( ( props ) => {
		const root = document.querySelector( '.elementor-element-fxw1' );
		const out = [];
		if ( ! root ) {
			return out;
		}
		root.querySelectorAll( '*' ).forEach( ( el, i ) => {
			[ null, '::before', '::after' ].forEach( ( pseudo ) => {
				const cs = getComputedStyle( el, pseudo );
				if ( pseudo && cs.content === 'none' ) {
					return;
				}
				out.push( i + ( pseudo || '' ) + '|' + props.map( ( p ) => cs[ p ] ).join( '|' ) );
			} );
		} );
		return out;
	}, PROPS );
}

const diff = ( a, b ) => {
	let n = 0;
	const len = Math.max( a.length, b.length );
	for ( let i = 0; i < len; i++ ) {
		n += a[ i ] !== b[ i ] ? 1 : 0;
	}
	return n;
};

const b64 = ( obj ) => Buffer.from( JSON.stringify( obj ) ).toString( 'base64' );

async function load( page, name, step, settings, classes ) {
	const all = Object.assign( {}, step.settings || {}, settings || {} );
	let url = `${ BASE }/fxw-ctl-${ name }/?fxw=${ encodeURIComponent( b64( all ) ) }`;
	if ( classes && classes.length ) {
		url += '&fxw_class=' + encodeURIComponent( classes.join( ' ' ) );
	}
	if ( step.query ) {
		url += '&' + step.query;
	}
	await page.goto( url, { waitUntil: 'networkidle' } );
	if ( step.wait ) {
		await page.waitForSelector( step.wait, { timeout: 15000 } ).catch( () => {} );
	}
	if ( step.open ) {
		await page.locator( `.elementor-element-fxw1 ${ step.open }` ).first().click( { timeout: 5000 } ).catch( () => {} );
	}
	if ( step.click ) {
		await page.locator( `.elementor-element-fxw1 ${ step.click }` ).first().click( { timeout: 5000 } ).catch( () => {} );
	}
	if ( ! await page.locator( '.elementor-element-fxw1' ).count() ) {
		console.log( '  (widget not on the page: ' + url + ')' );
	}
	await page.addStyleTag( { content: '*,*::before,*::after{transition:none!important;animation:none!important}' } );
	await page.waitForTimeout( 250 );
}

async function hoverTarget( page, control ) {
	let sel = null;
	const selectors = control.selectors ? Object.keys( control.selectors ) : [];
	const withHover = selectors.find( ( s ) => s.includes( ':hover' ) );
	if ( withHover ) {
		sel = withHover.replace( '{{WRAPPER}}', '' ).split( ':hover' )[ 0 ].trim();
	} else {
		const hit = HOVER.find( ( [ re ] ) => re.test( control.id ) );
		sel = hit ? hit[ 1 ] : null;
	}
	if ( ! sel ) {
		return false;
	}
	// Something that can be hovered: shown, and not a disabled button (the first arrow at the start).
	const target = await visible( page, `.elementor-element-fxw1 ${ sel }:not([disabled])` );
	if ( ! target ) {
		return false;
	}
	await target.hover( { timeout: 3000, force: true } ).catch( () => {} );
	return true;
}

async function visible( page, sel ) {
	const all = page.locator( sel );
	const n = await all.count();
	for ( let i = 0; i < n; i++ ) {
		if ( await all.nth( i ).isVisible() ) {
			return all.nth( i );
		}
	}
	return null;
}

async function focusTarget( page, control ) {
	const hit = FOCUS.find( ( [ re ] ) => re.test( control.id ) );
	const target = hit ? await visible( page, `.elementor-element-fxw1 ${ hit[ 1 ] }` ) : null;
	if ( target ) {
		await target.focus();
	}
	return !! target;
}

async function run() {
	const browser = await chromium.launch();
	const page = await browser.newPage( { viewport: { width: 1280, height: 1000 } } );
	page.on( 'pageerror', ( e ) => ok( false, 'no script errors', e.message ) );
	const checklist = [];
	for ( const [ name, widget ] of Object.entries( data.widgets ) ) {
		if ( ONLY && ! ONLY.includes( name ) ) {
			continue;
		}
		console.log( `### ${ widget.title } (${ name }): ${ widget.controls.length } controls` );
		ok( ! widget.problems.length, `${ name }: controls registered without clashes`, widget.problems.join( '; ' ) );
		const controls = widget.controls.filter( ( c ) => ! [ 'heading', 'raw_html' ].includes( c.type ) );
		const status = {};
		const steps = SETUP[ name ] || [ {} ];

		// Style controls, grouped by what they need first.
		const groups = new Map();
		controls.forEach( ( c ) => {
			const css = cssFor( c );
			if ( ! css && ! c.prefix_class ) {
				return;
			}
			const need = needs( c, controls );
			const key = JSON.stringify( need );
			if ( ! groups.has( key ) ) {
				groups.set( key, { need, list: [] } );
			}
			groups.get( key ).list.push( { c, css } );
		} );
		for ( const { need, list } of groups.values() ) {
			for ( const step of steps ) {
				await load( page, name, step, need.settings, need.classes );
				for ( const { c, css } of list ) {
					if ( status[ c.id ] && status[ c.id ].ok ) {
						continue;
					}
					// The open calendar closes when focus leaves it: focus moves only for "field in use".
					await page.mouse.move( 0, 0 );
					const hovered = await hoverTarget( page, c );
					const focused = await focusTarget( page, c );
					const before = await snapshot( page );
					if ( css ) {
						await page.evaluate( ( text ) => {
							document.getElementById( 'fxw-ctl' ).textContent = text;
						}, css );
					}
					let extra = '';
					if ( c.prefix_class ) {
						const def = c.default !== undefined ? String( c.default ) : '';
						const opt = ( c.options || [] ).find( ( o ) => o !== def && o !== '' ) || ( 'switcher' === c.type ? 'yes' : '' );
						extra = c.prefix_class + opt;
						await page.evaluate( ( cls ) => document.querySelector( '.elementor-element-fxw1' ).classList.add( cls ), extra );
					}
					if ( hovered ) {
						await hoverTarget( page, c );
					}
					if ( focused ) {
						await focusTarget( page, c );
					}
					await page.waitForTimeout( 30 );
					const after = await snapshot( page );
					const changed = diff( before, after );
					await page.evaluate( ( cls ) => {
						document.getElementById( 'fxw-ctl' ).textContent = '';
						if ( cls ) {
							document.querySelector( '.elementor-element-fxw1' ).classList.remove( cls );
						}
					}, extra );
					if ( changed ) {
						status[ c.id ] = { ok: true, how: `style${ hovered ? ' (hover)' : '' }: ${ changed } changes` };
					} else if ( ! status[ c.id ] ) {
						status[ c.id ] = { ok: false, how: `style${ hovered ? ' (hover)' : '' }: no change`, css };
					}
				}
			}
		}

		// Content controls: render again with another value.
		const markup = async () => page.evaluate( () => ( document.querySelector( '.elementor-element-fxw1' ) || { innerHTML: '' } ).innerHTML.replace( /fb-[a-z0-9-]*\d+/g, 'fb-x' ).replace( /flexo-room-\d+-\d+/g, 'flexo-room-x' ) );
		for ( const c of controls ) {
			if ( status[ c.id ] || cssFor( c ) || c.prefix_class || c.group ) {
				continue;
			}
			const value = contentValue( c );
			if ( undefined === value ) {
				status[ c.id ] = { ok: true, how: 'not checked (no test value)', skip: true };
				continue;
			}
			const need = needs( c, controls );
			const step = steps[ 0 ];
			await load( page, name, step, need.settings, need.classes );
			const before = await markup();
			await load( page, name, step, Object.assign( {}, need.settings, { [ c.id ]: value } ), need.classes );
			const after = await markup();
			status[ c.id ] = before !== after ? { ok: true, how: 'content: markup changes' } : { ok: false, how: 'content: no change', value };
		}

		controls.forEach( ( c ) => {
			if ( NOT_HERE[ c.id ] && status[ c.id ] && ! status[ c.id ].ok ) {
				status[ c.id ] = { ok: true, how: 'not checked: ' + NOT_HERE[ c.id ], skip: true };
			}
			const st = status[ c.id ] || { ok: false, how: 'not checked' };
			if ( ! st.skip ) {
				ok( st.ok, `${ name }: ${ c.id }${ c.label ? ' ("' + c.label + '")' : '' }`, st.ok ? st.how : st.how + ( st.css ? ' – ' + st.css.slice( 0, 160 ) : '' ) );
			}
		} );

		// Screenshots: as it comes, and with every style control set.
		fs.mkdirSync( path.join( OUT, 'after' ), { recursive: true } );
		await load( page, name, steps[ 0 ], {}, [] );
		await page.locator( '.elementor-element-fxw1' ).screenshot( { path: path.join( OUT, 'after', `${ name }-default.png` ) } ).catch( () => {} );
		const allCss = controls.map( ( c ) => designCss( c ) ).filter( Boolean ).join( '\n' );
		await page.evaluate( ( text ) => {
			document.getElementById( 'fxw-ctl' ).textContent = text;
		}, allCss );
		await page.waitForTimeout( 200 );
		await page.locator( '.elementor-element-fxw1' ).screenshot( { path: path.join( OUT, 'after', `${ name }-styled.png` ) } ).catch( () => {} );

		// The checklist.
		if ( ! checklist.length ) {
			checklist.push( '⟂ = can be set per device (desktop, tablet, phone). ✗ = no visible effect found.\n' );
		}
		checklist.push( `### ${ widget.title } (\`${ name }\`)\n` );
		widget.sections.forEach( ( sec ) => {
			const own = widget.controls.filter( ( c ) => c.section === sec.id && ! [ 'heading', 'raw_html' ].includes( c.type ) );
			if ( ! own.length ) {
				return;
			}
			const items = own.map( ( c ) => {
				const st = status[ c.id ] || {};
				return controlName( c ) + ( st.skip ? '' : st.ok ? '' : ' ✗' );
			} );
			checklist.push( `- **${ sec.label }** (${ 'style' === sec.tab ? 'Style' : 'Content' }): ${ items.join( ', ' ) }` );
		} );
		checklist.push( '' );
	}
	await browser.close();
	fs.mkdirSync( OUT, { recursive: true } );
	fs.writeFileSync( path.join( OUT, 'widgets-check.json' ), JSON.stringify( { pass, fail, results }, null, 1 ) );
	fs.writeFileSync( path.join( OUT, 'widget-controls.md' ), checklist.join( '\n' ) );
	console.log( `Result: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
}

run().catch( ( e ) => {
	console.error( e );
	process.exit( 2 );
} );
