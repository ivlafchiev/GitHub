/**
 * Flexo Booking – admin calendar: details for a booking, quick actions for an
 * empty day. Content comes from data attributes rendered (and escaped) by PHP.
 */
( function () {
	'use strict';

	var cfg = window.FlexoBookingCalendar || { i18n: {} };
	var t = cfg.i18n;
	var dialog = document.getElementById( 'fbc-dialog' );
	if ( ! dialog ) {
		return;
	}
	var title = dialog.querySelector( '#fbc-dialog-title' );
	var sub = dialog.querySelector( '.fbc-dialog__sub' );
	var lines = dialog.querySelector( '.fbc-dialog__lines' );
	var actions = dialog.querySelector( '.fbc-dialog__actions' );

	function action( item ) {
		var a = document.createElement( 'a' );
		a.className = 'button' + ( item.primary ? ' button-primary' : '' );
		a.href = item.url;
		a.textContent = item.label;
		if ( item.confirm ) {
			a.addEventListener( 'click', function ( e ) {
				if ( ! window.confirm( item.confirm ) ) {
					e.preventDefault();
				}
			} );
		}
		return a;
	}

	function open( data ) {
		title.textContent = data.heading || '';
		sub.textContent = data.sub || '';
		sub.hidden = ! data.sub;
		lines.innerHTML = '';
		( data.lines || [] ).forEach( function ( pair ) {
			if ( pair[ 1 ] === '' || pair[ 1 ] === null || pair[ 1 ] === undefined ) {
				return;
			}
			var dt = document.createElement( 'dt' );
			var dd = document.createElement( 'dd' );
			dt.textContent = pair[ 0 ];
			dd.textContent = pair[ 1 ];
			lines.appendChild( dt );
			lines.appendChild( dd );
		} );
		actions.innerHTML = '';
		( data.actions || [] ).forEach( function ( item ) {
			actions.appendChild( action( item ) );
		} );
		if ( typeof dialog.showModal === 'function' ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
	}

	function newUrl( cell, type ) {
		var params = new URLSearchParams( {
			room: cell.getAttribute( 'data-room' ),
			check_in: cell.getAttribute( 'data-day' ),
			check_out: cell.getAttribute( 'data-next' ),
			type: type,
		} );
		return cfg.newUrl + ( cfg.newUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + params.toString();
	}

	document.querySelectorAll( '.fbc-item' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			try {
				open( JSON.parse( button.getAttribute( 'data-dialog' ) ) );
			} catch ( e ) {} // eslint-disable-line no-empty
		} );
	} );

	document.querySelectorAll( '.fbc-cell' ).forEach( function ( cell ) {
		cell.addEventListener( 'click', function () {
			var closed = cell.classList.contains( 'is-closed' );
			open( {
				heading: cell.getAttribute( 'data-room-title' ),
				sub: cell.getAttribute( 'data-day-label' ) + ' · ' + cell.getAttribute( 'data-free' ),
				lines: [],
				actions: [
					{ label: t.newBooking, url: newUrl( cell, 'confirmed' ), primary: ! closed },
					{ label: t.blockDates, url: newUrl( cell, 'blocked' ) },
				],
			} );
		} );
	} );

	// Close when clicking the backdrop.
	dialog.addEventListener( 'click', function ( e ) {
		if ( e.target === dialog ) {
			dialog.close();
		}
	} );

	// Start the view at today on narrow screens.
	var scroll = document.querySelector( '.fbc-scroll' );
	var today = document.querySelector( '.fbc-row--head .fbc-day.is-today' );
	if ( scroll && today && scroll.scrollWidth > scroll.clientWidth ) {
		scroll.scrollLeft = Math.max( 0, today.offsetLeft - 140 );
	}
}() );
