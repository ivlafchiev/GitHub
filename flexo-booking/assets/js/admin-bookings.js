/**
 * Flexo Booking admin: confirmation dialogs that say what will happen,
 * the rates of the chosen room on "Add booking", "Show advanced settings",
 * "Copy system report" and the setup wizard's test booking check.
 */
( function () {
	'use strict';
	var cfg = window.FlexoAdmin || { i18n: {} };
	var t = cfg.i18n || {};

	/* ---- Confirmation dialog (links and buttons with data-flexo-confirm) ---- */
	var dialog = null;
	var pending = null;
	var opener = null;

	function build() {
		dialog = document.createElement( 'dialog' );
		dialog.className = 'flexo-dialog';
		dialog.setAttribute( 'aria-labelledby', 'flexo-dialog-title' );
		dialog.setAttribute( 'aria-describedby', 'flexo-dialog-text' );
		dialog.innerHTML = '<h2 id="flexo-dialog-title"></h2><p id="flexo-dialog-text"></p><div class="flexo-dialog__actions"><button type="button" class="button" data-no></button><button type="button" class="button button-primary" data-yes></button></div>';
		document.body.appendChild( dialog );
		dialog.querySelector( '[data-no]' ).addEventListener( 'click', function () {
			dialog.close();
		} );
		dialog.querySelector( '[data-yes]' ).addEventListener( 'click', function () {
			var go = pending;
			pending = null;
			dialog.close();
			if ( go ) {
				go();
			}
		} );
		dialog.addEventListener( 'close', function () {
			if ( opener ) {
				opener.focus();
			}
		} );
	}

	function ask( el, onYes ) {
		if ( ! window.HTMLDialogElement ) {
			if ( window.confirm( el.getAttribute( 'data-flexo-confirm' ) ) ) {
				onYes();
			}
			return;
		}
		if ( ! dialog ) {
			build();
		}
		opener = el;
		pending = onYes;
		dialog.querySelector( '#flexo-dialog-title' ).textContent = t.areYouSure || 'Are you sure?';
		dialog.querySelector( '#flexo-dialog-text' ).textContent = el.getAttribute( 'data-flexo-confirm' );
		dialog.querySelector( '[data-no]' ).textContent = t.goBack || 'Go back';
		dialog.querySelector( '[data-yes]' ).textContent = el.getAttribute( 'data-flexo-confirm-button' ) || el.textContent.trim();
		dialog.showModal();
		dialog.querySelector( '[data-no]' ).focus();
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-flexo-confirm]' );
		if ( ! el || el._flexoConfirmed ) {
			return;
		}
		e.preventDefault();
		ask( el, function () {
			if ( el.tagName === 'A' ) {
				window.location.href = el.href;
			} else if ( el.form ) {
				el._flexoConfirmed = true;
				if ( el.form.requestSubmit ) {
					el.form.requestSubmit( el );
				} else {
					el.form.submit();
				}
			}
		} );
	} );

	/* ---- Add booking: rates of the chosen room ---- */
	var plan = document.getElementById( 'fb-plan' );
	var room = document.getElementById( 'fb-room' );
	if ( plan && room && plan.getAttribute( 'data-room-plans' ) ) {
		var map = JSON.parse( plan.getAttribute( 'data-room-plans' ) || '{}' );
		var syncPlans = function () {
			var ids = ( map[ room.value ] || [] ).map( String );
			var first = '';
			Array.prototype.forEach.call( plan.options, function ( opt ) {
				var show = opt.value === '0' ? ids.length === 0 : ids.indexOf( opt.value ) !== -1;
				opt.hidden = ! show;
				opt.disabled = ! show;
				if ( show && ! first ) {
					first = opt.value;
				}
			} );
			if ( plan.selectedOptions[ 0 ] && plan.selectedOptions[ 0 ].disabled ) {
				plan.value = first;
			}
			plan.closest( 'tr' ).hidden = ids.length === 0;
		};
		room.addEventListener( 'change', syncPlans );
		syncPlans();
	}

	/* ---- Settings: show advanced settings ---- */
	var advanced = document.getElementById( 'flexo-show-advanced' );
	if ( advanced ) {
		var key = 'flexoShowAdvanced';
		try {
			advanced.checked = window.localStorage.getItem( key ) === '1';
		} catch ( err ) {}
		var syncAdvanced = function () {
			document.body.classList.toggle( 'flexo-advanced-on', advanced.checked );
			try {
				window.localStorage.setItem( key, advanced.checked ? '1' : '0' );
			} catch ( err ) {}
		};
		advanced.addEventListener( 'change', syncAdvanced );
		syncAdvanced();
	}

	/* ---- Health: copy the system report ---- */
	var copy = document.querySelector( '[data-flexo-copy]' );
	if ( copy ) {
		copy.addEventListener( 'click', function () {
			var area = document.getElementById( copy.getAttribute( 'data-flexo-copy' ) );
			var done = function () {
				copy.textContent = t.copied || 'Copied';
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( area.value ).then( done );
			} else {
				area.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	}

	/* ---- Wizard: wait for the test booking ---- */
	var wait = document.querySelector( '[data-flexo-wait-booking]' );
	if ( wait && cfg.ajaxUrl ) {
		var since = wait.getAttribute( 'data-flexo-wait-booking' );
		var poll = function () {
			var data = new FormData();
			data.append( 'action', 'flexo_booking_wizard_check' );
			data.append( '_ajax_nonce', cfg.nonce );
			data.append( 'since', since );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.success && res.data.reference ) {
						wait.innerHTML = '';
						var p = document.createElement( 'p' );
						p.className = 'flexo-wizard__arrived';
						p.textContent = res.data.message;
						var a = document.createElement( 'a' );
						a.href = res.data.url;
						a.textContent = ' ' + ( t.openBooking || 'Open it' );
						p.appendChild( a );
						wait.appendChild( p );
						return;
					}
					window.setTimeout( poll, 4000 );
				} )
				.catch( function () {
					window.setTimeout( poll, 8000 );
				} );
		};
		poll();
	}
}() );
