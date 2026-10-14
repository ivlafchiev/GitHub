/**
 * Admin UI checks (run after tests/ui/audit-seed.php):
 * - no horizontal overflow on any plugin screen (1024, 1280, 1440; phones at 390),
 * - WordPress's own menu, toolbar and footer look the same on plugin screens
 *   as on the Dashboard (nothing leaks out of the plugin's styles),
 * - the plugin's admin stylesheet and script are not loaded on other screens,
 * - the stylesheet has no !important and no selectors for WordPress's chrome.
 *
 *   PENDING=<booking id> ROOM=<room id> node tests/ui/ui-check.js
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const path = require( 'path' );
const BASE = process.env.BASE || 'http://localhost:8092';
const A = 'wp-admin/admin.php?page=';
const P = process.env.PENDING, R = process.env.ROOM;
let pass = 0, fail = 0;
const ok = ( cond, msg ) => { cond ? pass++ : fail++; console.log( ( cond ? '  PASS ' : '  FAIL ' ) + msg ); };

// [ name, url, phone? ]
const screens = [
	[ 'today', A + 'flexo-booking', 1 ],
	[ 'bookings', A + 'flexo-booking-list', 1 ],
	[ 'booking', A + 'flexo-booking-list&booking=' + P, 1 ],
	[ 'add booking', A + 'flexo-booking-new', 1 ],
	[ 'calendar', A + 'flexo-booking-calendar', 1 ],
	[ 'rooms', 'wp-admin/edit.php?post_type=flexo_room' ],
	[ 'room editor', 'wp-admin/post.php?action=edit&post=' + R ],
	[ 'seasons', A + 'flexo-booking-seasons' ],
	[ 'closed dates', A + 'flexo-booking-closures' ],
	[ 'rates', A + 'flexo-booking-rate-plans' ],
	[ 'promo codes', A + 'flexo-booking-promo-codes' ],
	[ 'calendar sync', A + 'flexo-booking-sync' ],
	[ 'emails', A + 'flexo-booking-emails' ],
	[ 'appearance', A + 'flexo-booking-appearance' ],
	...[ 'general', 'rules', 'room_pages', 'pages', 'payments', 'taxes', 'privacy', 'tracking', 'features', 'health' ].map( ( t ) => [ 'settings ' + t, A + 'flexo-booking-settings&tab=' + t ] ),
	[ 'import & export', A + 'flexo-booking-tools' ],
	[ 'bring in rooms', A + 'flexo-booking-import-rooms' ],
	[ 'help', A + 'flexo-booking-help' ],
	[ 'wizard', A + 'flexo-booking-wizard' ],
];

// Computed styles of WordPress's own chrome.
const chrome = () => {
	const pick = ( sel, props ) => {
		const n = document.querySelector( sel );
		if ( ! n ) {
			return null;
		}
		const c = getComputedStyle( n );
		return props.map( ( p ) => c[ p ] ).join( '|' );
	};
	const props = [ 'fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'color', 'backgroundColor', 'paddingTop', 'paddingLeft', 'marginTop', 'height', 'borderTopWidth' ];
	// The same kind of element in the same state on both screens: the menu,
	// a top item that isn't current (Posts), a link that isn't current inside
	// the open submenu, the toolbar.
	return {
		menu: pick( '#adminmenu', [ 'fontFamily', 'fontSize', 'color', 'backgroundColor', 'width', 'paddingTop', 'marginTop' ] ),
		menuLink: pick( '#menu-posts > a', props ),
		submenuLink: pick( '#adminmenu .wp-has-current-submenu .wp-submenu li:not(.current):not(.wp-submenu-head) a', props ),
		toolbar: pick( '#wpadminbar', props ),
		toolbarLink: pick( '#wpadminbar .ab-item', props ),
		collapse: pick( '#collapse-button', props ),
	};
};

( async () => {
	// Static checks on the stylesheet.
	const css = fs.readFileSync( path.join( __dirname, '../../flexo-booking/assets/css/flexo-admin.css' ), 'utf8' ).replace( /\/\*[\s\S]*?\*\//g, '' );
	ok( ! css.includes( '!important' ), 'stylesheet: no !important' );
	const core = css.match( /#(adminmenu|adminmenuwrap|adminmenuback|wpadminbar|wpbody|wpcontent|wpfooter|wpwrap)\b/g ) || [];
	ok( 0 === core.length, 'stylesheet: no selectors for WordPress\'s menu, toolbar, body or footer' + ( core.length ? ': ' + [ ...new Set( core ) ].join( ', ' ) : '' ) );
	const rules = [ ...css.matchAll( /([^{};]+)\{/g ) ].map( ( m ) => m[ 1 ].trim() ).filter( ( sel ) => sel && ! sel.startsWith( '@' ) && ! /^(from|to|\d+%)$/.test( sel ) );
	// Selector lists split at top-level commas (not inside :is() / :where()).
	const parts = ( sel ) => {
		const out = [];
		let depth = 0, cur = '';
		for ( const ch of sel ) {
			depth += '(' === ch ? 1 : ')' === ch ? -1 : 0;
			if ( ',' === ch && 0 === depth ) {
				out.push( cur );
				cur = '';
			} else {
				cur += ch;
			}
		}
		return out.concat( cur );
	};
	const unscoped = rules.filter( ( sel ) => parts( sel ).some( ( one ) => ! /flexo-admin-ui/.test( one ) ) );
	ok( 0 === unscoped.length, 'stylesheet: every rule is scoped to the plugin\'s screens' + ( unscoped.length ? ': ' + unscoped.slice( 0, 3 ).join( ' | ' ) : '' ) );

	const b = await chromium.launch();
	const ctx = await b.newContext( { viewport: { width: 1280, height: 900 } } );
	const p = await ctx.newPage();
	await p.goto( BASE + '/wp-login.php' );
	await p.fill( '#user_login', 'admin' );
	await p.fill( '#user_pass', 'admin' );
	await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );

	// Other screens: nothing of the plugin's admin look is loaded.
	for ( const url of [ 'wp-admin/index.php', 'wp-admin/edit.php', 'wp-admin/options-general.php', 'wp-admin/plugins.php' ] ) {
		await p.goto( BASE + '/' + url );
		const loaded = await p.evaluate( () => [ ...document.querySelectorAll( 'link[rel=stylesheet], script[src]' ) ].map( ( n ) => n.href || n.src ).filter( ( u ) => /flexo-admin\.(css|js)/.test( u ) ) );
		const cls = await p.evaluate( () => document.body.classList.contains( 'flexo-admin-ui' ) );
		ok( 0 === loaded.length && ! cls, url + ': no plugin admin styles or scripts, no plugin body class' );
	}
	// Reference: a core screen with its submenu open (Settings → General).
	await p.goto( BASE + '/wp-admin/options-general.php' );
	const reference = await p.evaluate( chrome );

	for ( const w of [ 1024, 1280, 1440 ] ) {
		await p.setViewportSize( { width: w, height: 900 } );
		for ( const [ name, url ] of screens ) {
			await p.goto( BASE + '/' + url, { waitUntil: 'load' } );
			await p.waitForTimeout( 150 );
			const over = await p.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
			ok( over <= 0, `${ name } @${ w }: no horizontal scroll` + ( over > 0 ? ` (${ over }px)` : '' ) );
			if ( 1280 === w ) {
				const mine = await p.evaluate( chrome );
				const diff = Object.keys( reference ).filter( ( k ) => reference[ k ] !== mine[ k ] );
				ok( 0 === diff.length, `${ name }: WordPress menu and toolbar unchanged` + ( diff.length ? ': ' + diff.map( ( k ) => `${ k } (${ reference[ k ] } → ${ mine[ k ] })` ).join( ', ' ) : '' ) );
			}
		}
	}
	await p.setViewportSize( { width: 390, height: 844 } );
	for ( const [ name, url, phone ] of screens ) {
		if ( ! phone ) {
			continue;
		}
		await p.goto( BASE + '/' + url, { waitUntil: 'load' } );
		await p.waitForTimeout( 150 );
		const over = await p.evaluate( () => document.documentElement.scrollWidth - document.documentElement.clientWidth );
		ok( over <= 0, `${ name } @390: no horizontal scroll` + ( over > 0 ? ` (${ over }px)` : '' ) );
	}
	await b.close();
	console.log( `\nResult: ${ pass } passed, ${ fail } failed` );
	process.exit( fail ? 1 : 0 );
} )();
