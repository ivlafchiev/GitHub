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
			// The shared confirmation dialog (admin-bookings.js) asks first.
			a.setAttribute( 'data-flexo-confirm', item.confirm );
			if ( item.danger ) {
				a.setAttribute( 'data-flexo-confirm-tone', 'danger' );
				a.className = 'button fx-btn-danger-quiet';
			}
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

	document.querySelectorAll( '.fbc-item, .fbc-agenda__item' ).forEach( function ( button ) {
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

	// Earlier / later days when the month does not fit (a week at a time).
	var scrollers = document.querySelector( '.fbc-scrollers' );
	function updateScrollers() {
		if ( ! scroll || ! scrollers ) {
			return;
		}
		var max = scroll.scrollWidth - scroll.clientWidth;
		scrollers.hidden = max <= 1 || 'agenda' === page.getAttribute( 'data-view' );
		scrollers.querySelector( '[data-fbc-scroll="-1"]' ).disabled = scroll.scrollLeft <= 0;
		scrollers.querySelector( '[data-fbc-scroll="1"]' ).disabled = scroll.scrollLeft >= max - 1;
	}
	if ( scrollers ) {
		scrollers.querySelectorAll( '[data-fbc-scroll]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var day = document.querySelector( '.fbc-row--head .fbc-day' );
				var step = ( day ? day.offsetWidth : 40 ) * 7;
				scroll.scrollBy( { left: step * Number( btn.getAttribute( 'data-fbc-scroll' ) ), behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			} );
		} );
		scroll.addEventListener( 'scroll', updateScrollers, { passive: true } );
		window.addEventListener( 'resize', updateScrollers );
	}

	// Grid or agenda (agenda by default on phones; the choice is remembered).
	var page = document.querySelector( '.flexo-calendar-page' );
	var agenda = document.querySelector( '.fbc-agenda' );
	var views = document.querySelectorAll( '[data-fbc-view]' );
	function setView( view ) {
		page.setAttribute( 'data-view', view );
		if ( scroll ) {
			scroll.hidden = 'agenda' === view;
		}
		if ( agenda ) {
			agenda.hidden = 'agenda' !== view;
		}
		views.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-fbc-view' ) === view ? 'true' : 'false' );
		} );
		updateScrollers();
	}
	if ( page && views.length ) {
		var saved = null;
		try {
			saved = window.localStorage.getItem( 'flexoCalendarView' );
		} catch ( e ) {} // eslint-disable-line no-empty
		setView( saved || ( window.matchMedia( '(max-width: 600px)' ).matches ? 'agenda' : 'grid' ) );
		views.forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				var view = b.getAttribute( 'data-fbc-view' );
				setView( view );
				try {
					window.localStorage.setItem( 'flexoCalendarView', view );
				} catch ( e ) {} // eslint-disable-line no-empty
			} );
		} );
	} else {
		updateScrollers();
	}
}() );
