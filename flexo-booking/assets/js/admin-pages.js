/**
 * Settings → Pages: Up / Down ordering (sections, form fields), parts that
 * depend on a choice (own page / built-in, header style), the title band
 * picture, and remembering which "Customize" panels are open.
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
				} );
			}
			frame.open();
		} );
		remove.addEventListener( 'click', function () {
			input.value = '0';
			preview.innerHTML = '';
			remove.hidden = true;
		} );
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
} )();
