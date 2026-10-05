/**
 * Bookings → Appearance: colour pickers with hex inputs, "Use website
 * colour", live preview (the CSS comes from the server, built exactly like
 * on the real form), contrast warnings, preview width and reset.
 */
( function () {
	'use strict';
	var cfg = window.FlexoAppearance || { i18n: {} };
	var form = document.getElementById( 'flexo-appearance-form' );
	var frame = document.getElementById( 'flexo-appearance-frame' );
	if ( ! form || ! frame ) {
		return;
	}
	var custom = form.querySelector( '.flexo-appearance__custom' );
	var warnings = form.querySelector( '.flexo-appearance__warnings' );
	var lastCss = '';
	var timer = null;

	function normalise( value ) {
		value = String( value || '' ).trim();
		if ( value && value.charAt( 0 ) !== '#' ) {
			value = '#' + value;
		}
		return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( value ) ? value.toLowerCase() : '';
	}

	function mode() {
		var checked = form.querySelector( '[name$="[appearance_mode]"]:checked' );
		return checked ? checked.value : 'match';
	}

	function values() {
		var data = new FormData();
		data.append( 'action', 'flexo_booking_appearance_css' );
		data.append( '_ajax_nonce', cfg.nonce );
		form.querySelectorAll( '[name*="[appearance_"]' ).forEach( function ( field ) {
			if ( ( field.type === 'radio' || field.type === 'checkbox' ) && ! field.checked ) {
				return;
			}
			var key = field.name.replace( /^.*\[(appearance_[a-z_]+)\]$/, '$1' );
			data.append( 'settings[' + key + ']', field.value );
		} );
		return data;
	}

	function send( css ) {
		lastCss = css;
		if ( frame.contentWindow ) {
			frame.contentWindow.postMessage( { type: 'flexo-appearance', css: css }, window.location.origin );
		}
	}

	function showProblems( problems ) {
		warnings.innerHTML = '';
		( problems || [] ).forEach( function ( p ) {
			var box = document.createElement( 'div' );
			box.className = 'notice notice-warning inline flexo-contrast-warning';
			var text = document.createElement( 'p' );
			var template = p.pair === 'text_bg' ? cfg.i18n.textBg : cfg.i18n.buttonText;
			text.textContent = String( template ).replace( '%1$s', p.ratio ).replace( '%2$s', p.suggestion );
			box.appendChild( text );
			warnings.appendChild( box );
		} );
	}

	function refresh() {
		custom.hidden = mode() !== 'custom';
		form.querySelector( '.flexo-email-color-row' ).hidden = mode() === 'custom';
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: values() } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.success ) {
						send( res.data.css );
						showProblems( res.data.problems );
					}
				} )
				.catch( function () {} );
		}, 250 );
	}

	// Colour rows: picker ↔ hex, and "Use website colour".
	form.querySelectorAll( '.flexo-color-row' ).forEach( function ( row ) {
		var website = row.querySelector( '.flexo-color-website' );
		var picker = row.querySelector( '.flexo-color-picker' );
		var hex = row.querySelector( '.flexo-color-hex' );
		function sync() {
			// Typing or picking a colour unticks "Use website colour".
			row.querySelector( '.flexo-color-pick' ).classList.toggle( 'is-off', website.checked );
		}
		website.addEventListener( 'change', function () {
			if ( website.checked ) {
				hex.value = '';
			} else {
				hex.value = picker.value;
			}
			sync();
			refresh();
		} );
		picker.addEventListener( 'input', function () {
			hex.value = picker.value;
			website.checked = false;
			sync();
			refresh();
		} );
		hex.addEventListener( 'input', function () {
			var v = normalise( hex.value );
			if ( v ) {
				if ( v.length === 7 ) {
					picker.value = v;
				}
				website.checked = false;
				sync();
				refresh();
			}
		} );
		hex.addEventListener( 'blur', function () {
			var v = normalise( hex.value );
			hex.value = v;
			website.checked = v === '';
			sync();
			refresh();
		} );
		sync();
	} );

	form.addEventListener( 'change', refresh );
	frame.addEventListener( 'load', function () {
		send( lastCss );
		refresh();
	} );

	// Preview width.
	document.querySelectorAll( '.flexo-appearance__preview-bar [data-width]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			document.querySelectorAll( '.flexo-appearance__preview-bar [data-width]' ).forEach( function ( b ) {
				b.classList.toggle( 'is-active', b === btn );
				b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' );
			} );
			frame.style.width = btn.getAttribute( 'data-width' );
		} );
	} );

	// Email logo from the media library.
	var logoBtn = form.querySelector( '.flexo-choose-logo' );
	if ( logoBtn && window.wp && window.wp.media ) {
		logoBtn.addEventListener( 'click', function () {
			var media = window.wp.media( { title: cfg.i18n.chooseLogo, library: { type: 'image' }, multiple: false } );
			media.on( 'select', function () {
				form.querySelector( '#fb-email-logo' ).value = media.state().get( 'selection' ).first().toJSON().url;
			} );
			media.open();
		} );
	}

	// "Another font": the name field shows only for that choice.
	form.querySelectorAll( '[data-flexo-font-select]' ).forEach( function ( select ) {
		var nameField = select.parentNode.querySelector( '[data-flexo-font-name]' );
		select.addEventListener( 'change', function () {
			if ( nameField ) {
				nameField.hidden = 'custom' !== select.value;
				if ( ! nameField.hidden ) {
					nameField.focus();
				}
			}
		} );
	} );

	var reset = document.querySelector( '.flexo-reset-appearance' );
	if ( reset ) {
		reset.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( cfg.i18n.resetSure ) ) {
				e.preventDefault();
			}
		} );
	}

	refresh();
}() );
