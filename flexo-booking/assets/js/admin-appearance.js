/**
 * Bookings → Appearance: live preview (the CSS comes from the server, built
 * exactly like on the real form), contrast warnings, what the preview shows
 * and at which width. Colour fields, saving and the reset confirmation come
 * from the shared admin script (flexo-admin.js).
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
			if ( p.pair === 'text_bg' || p.pair === 'button_text' ) {
				var template = p.pair === 'text_bg' ? cfg.i18n.textBg : cfg.i18n.buttonText;
				text.textContent = String( template ).replace( '%1$s', p.ratio ).replace( '%2$s', p.suggestion );
			} else {
				text.textContent = String( cfg.i18n.contrast ).replace( '%1$s', p.label ).replace( '%2$s', p.ratio ).replace( '%3$s', p.suggestion );
			}
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

	form.addEventListener( 'change', refresh );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.classList.contains( 'fx-color__hex' ) ) {
			refresh();
		}
	} );
	frame.addEventListener( 'load', function () {
		send( lastCss );
		refresh();
	} );

	// What the preview shows: the booking form, the Booking page or the Thank You page.
	var bar = document.querySelector( '.flexo-appearance__preview-bar' );
	var wrap = frame.parentNode;
	var openLink = bar.querySelector( '.flexo-preview-open' );
	var scenes = Array.prototype.slice.call( bar.querySelectorAll( '[data-scene]' ) );
	var sceneSelect = bar.querySelector( '[data-fx-scene-select]' );
	var width = 1280;
	scenes.forEach( function ( btn, i ) {
		btn.addEventListener( 'click', function () {
			if ( frame.getAttribute( 'src' ) !== btn.getAttribute( 'data-scene' ) ) {
				frame.src = btn.getAttribute( 'data-scene' );
			}
			if ( sceneSelect ) {
				sceneSelect.value = String( i );
			}
			if ( openLink ) {
				openLink.hidden = ! btn.getAttribute( 'data-open' );
				openLink.href = btn.getAttribute( 'data-open' ) || '#';
			}
		} );
	} );
	if ( sceneSelect ) {
		sceneSelect.addEventListener( 'change', function () {
			var btn = scenes[ parseInt( sceneSelect.value, 10 ) ];
			if ( btn ) {
				btn.click();
			}
		} );
	}

	// Preview width: a real desktop (1280 px, scaled to fit) or a phone (390 px).
	function fit() {
		if ( window.FlexoUI && window.FlexoUI.fitFrame ) {
			window.FlexoUI.fitFrame( wrap, frame, width );
		}
	}
	bar.querySelectorAll( '[data-width]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			bar.querySelectorAll( '[data-width]' ).forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' );
			} );
			width = '390px' === btn.getAttribute( 'data-width' ) ? 390 : 1280;
			fit();
		} );
	} );
	window.addEventListener( 'resize', fit );
	fit();

	// Background picture from the media library.
	form.querySelectorAll( '[data-flexo-media]' ).forEach( function ( box ) {
		var input = box.querySelector( '[data-flexo-media-id]' );
		var preview = box.querySelector( '[data-flexo-media-preview]' );
		var remove = box.querySelector( '[data-flexo-media-remove]' );
		var media;
		box.querySelector( '[data-flexo-media-choose]' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! media ) {
				media = window.wp.media( { title: cfg.i18n.chooseImage, button: { text: cfg.i18n.useImage }, library: { type: 'image' }, multiple: false } );
				media.on( 'select', function () {
					var item = media.state().get( 'selection' ).first().toJSON();
					var size = item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail : item;
					input.value = item.id;
					preview.innerHTML = '';
					var img = document.createElement( 'img' );
					img.src = size.url;
					img.alt = '';
					preview.appendChild( img );
					remove.hidden = false;
					refresh();
				} );
			}
			media.open();
		} );
		remove.addEventListener( 'click', function () {
			input.value = '0';
			preview.innerHTML = '';
			remove.hidden = true;
			refresh();
		} );
	} );

	// A field shown only for one choice ("Exact width" → pixels).
	form.querySelectorAll( '[data-flexo-show-when]' ).forEach( function ( node ) {
		var rule = node.getAttribute( 'data-flexo-show-when' ).split( '=' );
		var select = document.getElementById( rule[ 0 ] );
		function update() {
			node.hidden = ! select || select.value !== rule[ 1 ];
		}
		if ( select ) {
			select.addEventListener( 'change', update );
		}
		update();
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

	// The email colour's code next to its picker.
	var emailColor = form.querySelector( '#fb-email-color' );
	var emailHex = form.querySelector( '[data-flexo-email-hex]' );
	if ( emailColor && emailHex ) {
		emailColor.addEventListener( 'input', function () {
			emailHex.textContent = emailColor.value.toUpperCase();
		} );
	}

	refresh();
}() );
