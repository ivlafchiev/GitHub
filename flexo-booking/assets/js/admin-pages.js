/**
 * Settings → Pages: Up / Down ordering (sections, form fields), parts that
 * depend on a choice (own page / built-in, header style), the title band
 * picture, each page's own Appearance with its live preview, and
 * remembering which "Customize" panels are open.
 */
( function () {
	'use strict';

	var cfg = window.FlexoPagesAdmin || { i18n: {} };
	var t = cfg.i18n;

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	/* Up / Down ------------------------------------------------------- */

	function sortable( list ) {
		var live = document.createElement( 'p' );
		live.className = 'screen-reader-text';
		live.setAttribute( 'aria-live', 'polite' );
		list.parentNode.insertBefore( live, list.nextSibling );

		function items() {
			return Array.prototype.slice.call( list.children ).filter( function ( li ) {
				return li.classList.contains( 'flexo-sortable__item' );
			} );
		}

		function refresh() {
			var all = items();
			all.forEach( function ( li, i ) {
				li.querySelector( '[data-dir="up"]' ).disabled = 0 === i;
				li.querySelector( '[data-dir="down"]' ).disabled = all.length - 1 === i;
			} );
		}

		items().forEach( function ( li ) {
			var label = li.getAttribute( 'data-flexo-label' ) || '';
			var slot = li.querySelector( '.flexo-sortable__buttons' );
			[ [ 'up', '↑', t.moveUp ], [ 'down', '↓', t.moveDown ] ].forEach( function ( def ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'button flexo-sortable__move';
				b.setAttribute( 'data-dir', def[ 0 ] );
				b.setAttribute( 'aria-label', format( def[ 2 ], label ) );
				b.textContent = def[ 1 ];
				slot.appendChild( b );
			} );
		} );

		list.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '.flexo-sortable__move' );
			if ( ! b ) {
				return;
			}
			var li = b.closest( '.flexo-sortable__item' );
			if ( 'up' === b.getAttribute( 'data-dir' ) && li.previousElementSibling ) {
				list.insertBefore( li, li.previousElementSibling );
			} else if ( 'down' === b.getAttribute( 'data-dir' ) && li.nextElementSibling ) {
				list.insertBefore( li.nextElementSibling, li );
			}
			refresh();
			var all = items();
			live.textContent = format( t.moved, li.getAttribute( 'data-flexo-label' ) || '', all.indexOf( li ) + 1, all.length );
			// Keep the focus on the button that was pressed (or its partner at the ends).
			var target = b.disabled ? li.querySelector( '.flexo-sortable__move:not([disabled])' ) : b;
			if ( target ) {
				target.focus();
			}
		} );
		refresh();
	}

	/* Parts that depend on a choice ------------------------------------ */

	function bindSource( fieldset ) {
		var key = fieldset.getAttribute( 'data-flexo-source' );
		function update() {
			var checked = fieldset.querySelector( 'input:checked' );
			var value = checked ? checked.value : 'builtin';
			document.querySelectorAll( '[data-flexo-for="' + key + '"]' ).forEach( function ( node ) {
				if ( node.classList.contains( 'flexo-if-source--page' ) ) {
					node.hidden = 'page' !== value;
				} else if ( node.classList.contains( 'flexo-if-source--builtin' ) || node.classList.contains( 'flexo-if-builtin' ) ) {
					node.hidden = 'builtin' !== value;
				}
			} );
		}
		fieldset.addEventListener( 'change', update );
		update();
	}

	function bindHeader( fieldset ) {
		function update() {
			var checked = fieldset.querySelector( 'input:checked' );
			var value = checked ? checked.value : 'normal';
			document.querySelectorAll( '.flexo-if-header' ).forEach( function ( node ) {
				node.hidden = ! node.classList.contains( 'flexo-if-header--' + value );
			} );
		}
		fieldset.addEventListener( 'change', update );
		update();
	}

	/* Title band picture ----------------------------------------------- */

	function bindMedia( box ) {
		var input = box.querySelector( '[data-flexo-media-id]' );
		var preview = box.querySelector( '[data-flexo-media-preview]' );
		var remove = box.querySelector( '[data-flexo-media-remove]' );
		var frame;
		box.querySelector( '[data-flexo-media-choose]' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( { title: t.chooseImage, button: { text: t.useImage }, library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var item = frame.state().get( 'selection' ).first().toJSON();
					var size = item.sizes && item.sizes.thumbnail ? item.sizes.thumbnail : item;
					input.value = item.id;
					preview.innerHTML = '';
					var img = document.createElement( 'img' );
					img.src = size.url;
					img.alt = '';
					preview.appendChild( img );
					remove.hidden = false;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			}
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			input.value = '0';
			preview.innerHTML = '';
			remove.hidden = true;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	/* A page's own Appearance and its live preview ---------------------- */

	function normalise( value ) {
		value = String( value || '' ).trim();
		if ( value && value.charAt( 0 ) !== '#' ) {
			value = '#' + value;
		}
		return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( value ) ? value.toLowerCase() : '';
	}

	function bindLook( box ) {
		var key = box.getAttribute( 'data-flexo-look' );
		var global = box.querySelector( '[data-flexo-look-global]' );
		var own = box.querySelector( '[data-flexo-look-own]' );
		var frame = box.querySelector( '[data-flexo-look-frame]' );
		var warnings = box.querySelector( '.flexo-appearance__warnings' );
		var lastCss = null;
		var timer = null;

		function send( css ) {
			lastCss = css;
			if ( frame.contentWindow && frame.src ) {
				frame.contentWindow.postMessage( { type: 'flexo-appearance', css: css }, window.location.origin );
			}
		}

		function showProblems( problems ) {
			warnings.innerHTML = '';
			( problems || [] ).forEach( function ( p ) {
				var note = document.createElement( 'div' );
				note.className = 'notice notice-warning inline flexo-contrast-warning';
				var text = document.createElement( 'p' );
				text.textContent = format( t.contrast, p.label, p.ratio, p.suggestion );
				note.appendChild( text );
				warnings.appendChild( note );
			} );
		}

		function refresh() {
			own.hidden = global.checked;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				var data = new FormData();
				data.append( 'action', 'flexo_booking_page_look_css' );
				data.append( '_ajax_nonce', cfg.nonce );
				data.append( 'page', key );
				data.append( 'look[use_global]', global.checked ? '1' : '0' );
				own.querySelectorAll( '[name*="[appearance]["]' ).forEach( function ( field ) {
					var name = field.name.replace( /^.*\[appearance\]\[([a-z_]+)\]$/, '$1' );
					if ( 'reset' !== name && 'use_global' !== name ) {
						data.append( 'look[' + name + ']', field.value );
					}
				} );
				fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						if ( res && res.success ) {
							send( res.data.css );
							showProblems( global.checked ? [] : res.data.problems );
						}
					} )
					.catch( function () {} );
			}, 250 );
		}

		// Colour rows: picker ↔ hex, and "Use global colour".
		own.querySelectorAll( '.flexo-color-row' ).forEach( function ( row ) {
			var inherit = row.querySelector( '.flexo-color-website' );
			var picker = row.querySelector( '.flexo-color-picker' );
			var hex = row.querySelector( '.flexo-color-hex' );
			function sync() {
				row.querySelector( '.flexo-color-pick' ).classList.toggle( 'is-off', inherit.checked );
			}
			inherit.addEventListener( 'change', function () {
				hex.value = inherit.checked ? '' : picker.value;
				sync();
			} );
			picker.addEventListener( 'input', function () {
				hex.value = picker.value;
				inherit.checked = false;
				sync();
				refresh();
			} );
			hex.addEventListener( 'input', function () {
				var v = normalise( hex.value );
				if ( v ) {
					if ( v.length === 7 ) {
						picker.value = v;
					}
					inherit.checked = false;
					sync();
					refresh();
				}
			} );
			hex.addEventListener( 'blur', function () {
				var v = normalise( hex.value );
				hex.value = v;
				inherit.checked = v === '';
				sync();
				refresh();
			} );
			sync();
		} );

		// A field shown only for one choice ("Exact width" → pixels).
		own.querySelectorAll( '[data-flexo-show-when]' ).forEach( function ( node ) {
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

		// "Reset to global": forget this page's own look and save.
		box.querySelector( '[data-flexo-look-reset]' ).addEventListener( 'click', function () {
			if ( window.confirm( this.getAttribute( 'data-confirm' ) ) ) {
				box.querySelector( '[data-flexo-look-reset-field]' ).value = '1';
				var form = box.closest( 'form' );
				if ( form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			}
		} );

		box.addEventListener( 'change', refresh );
		frame.addEventListener( 'load', function () {
			if ( null !== lastCss ) {
				send( lastCss );
			}
			refresh();
		} );

		// Preview width: a real desktop (1280 px, scaled down to fit) or a phone.
		var wrap = frame.parentNode;
		var width = 1280;
		function fit() {
			var scale = Math.min( 1, ( wrap.clientWidth || width ) / width );
			frame.style.width = width + 'px';
			frame.style.height = Math.round( 760 / scale ) + 'px';
			frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
			frame.style.marginInline = scale < 1 ? '0' : 'auto';
		}
		box.querySelectorAll( '[data-look-width]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				box.querySelectorAll( '[data-look-width]' ).forEach( function ( b ) {
					b.classList.toggle( 'is-active', b === btn );
					b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' );
				} );
				width = parseInt( btn.getAttribute( 'data-look-width' ), 10 ) || 1280;
				fit();
			} );
		} );
		window.addEventListener( 'resize', fit );
		box.closest( 'details' ) && box.closest( 'details' ).addEventListener( 'toggle', fit );
		global.addEventListener( 'change', function () {
			window.setTimeout( fit, 0 );
		} );
		fit();

		// The preview loads only once it comes into view (a whole page each).
		function load() {
			if ( ! frame.src ) {
				frame.src = frame.getAttribute( 'data-src' );
			}
		}
		if ( 'IntersectionObserver' in window ) {
			var seen = new window.IntersectionObserver( function ( entries ) {
				if ( entries.some( function ( e ) {
					return e.isIntersecting;
				} ) ) {
					load();
					seen.disconnect();
				}
			} );
			seen.observe( frame );
		} else {
			load();
		}
		own.hidden = global.checked;
	}

	/* Open "Customize" panels stay open after saving ------------------- */

	function remember( details ) {
		var key = 'flexo_pages_open_' + details.getAttribute( 'data-flexo-remember' );
		try {
			if ( '1' === window.sessionStorage.getItem( key ) ) {
				details.open = true;
			}
		} catch ( e ) {}
		details.addEventListener( 'toggle', function () {
			try {
				window.sessionStorage.setItem( key, details.open ? '1' : '0' );
			} catch ( e ) {}
		} );
	}

	document.querySelectorAll( '[data-flexo-sortable]' ).forEach( sortable );
	document.querySelectorAll( '[data-flexo-source]' ).forEach( bindSource );
	document.querySelectorAll( '[data-flexo-header-style]' ).forEach( bindHeader );
	document.querySelectorAll( '[data-flexo-media]' ).forEach( bindMedia );
	document.querySelectorAll( '[data-flexo-remember]' ).forEach( remember );
	document.querySelectorAll( '[data-flexo-look]' ).forEach( bindLook );
} )();
