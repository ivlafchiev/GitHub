/**
 * Flexo Booking – front-end booking flow.
 * Vanilla JS, no dependencies. Works with the shortcode and the Elementor widget.
 *
 * Steps: Dates → Room → Your details → (Payment) → Confirmation. The step,
 * dates, guests, room, rate and promo code are kept in the address (no
 * personal data), so Back/Forward move between steps and a refresh keeps
 * the place. Guest details stay in the page only.
 */
( function () {
	'use strict';

	var cfg = window.FlexoBookingConfig || { restUrl: '/wp-json/flexo-booking/v1/', minNights: 1, i18n: {} };
	var t = cfg.i18n || {};
	var urlOwner = null; // Only the first full form on a page uses the address.

	function apiUrl( path, params ) {
		var url = cfg.restUrl + path;
		var qs = params ? new URLSearchParams( params ).toString() : '';
		if ( ! qs ) {
			return url;
		}
		// With plain permalinks restUrl already contains "?rest_route=".
		return url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs;
	}

	function request( path, options ) {
		return fetch( path, Object.assign( { credentials: 'same-origin', headers: { Accept: 'application/json' } }, options ) )
			.then( function ( res ) {
				return res.json().catch( function () {
					return {};
				} ).then( function ( body ) {
					if ( ! res.ok ) {
						var err = new Error( body && body.message ? body.message : t.genericError );
						err.code = body && body.code ? body.code : '';
						err.data = body && body.data ? body.data : {};
						throw err;
					}
					return body;
				} );
			} );
	}

	function postJson( path, payload ) {
		return request( apiUrl( path ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( payload ),
		} );
	}

	function fmt( str, n ) {
		return String( str || '' ).replace( '%d', n ).replace( '%s', n );
	}

	function fmtN( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( str || '' ).replace( /%(\d)\$[sd]/g, function ( m, i ) {
			return args[ i - 1 ] !== undefined ? args[ i - 1 ] : m;
		} );
	}

	function parseDate( ymd ) {
		var p = String( ymd ).split( '-' );
		return new Date( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] );
	}

	function toYmd( date ) {
		var m = String( date.getMonth() + 1 ).padStart( 2, '0' );
		var d = String( date.getDate() ).padStart( 2, '0' );
		return date.getFullYear() + '-' + m + '-' + d;
	}

	function addDays( ymd, n ) {
		var d = parseDate( ymd );
		d.setDate( d.getDate() + n );
		return toYmd( d );
	}

	function diffDays( a, b ) {
		return Math.round( ( parseDate( b ) - parseDate( a ) ) / 86400000 );
	}

	function lang( locale ) {
		return locale ? locale.replace( '_', '-' ) : ( document.documentElement.lang || undefined );
	}

	/**
	 * Date for guests: DD.MM.YYYY where the language writes it so (e.g.
	 * Bulgarian), otherwise the browser's format for the page language.
	 */
	function humanDate( ymd, format, locale ) {
		if ( ! ymd ) {
			return '';
		}
		if ( format === 'd.m.Y' ) {
			var p = ymd.split( '-' );
			return p[ 2 ] + '.' + p[ 1 ] + '.' + p[ 0 ];
		}
		try {
			return parseDate( ymd ).toLocaleDateString( lang( locale ), { day: 'numeric', month: 'short', year: 'numeric' } );
		} catch ( e ) {
			return ymd;
		}
	}

	/**
	 * Short date with the weekday, e.g. "Mon, 19 Oct".
	 */
	function shortDate( ymd, locale ) {
		try {
			return parseDate( ymd ).toLocaleDateString( lang( locale ), { weekday: 'short', day: 'numeric', month: 'short' } );
		} catch ( e ) {
			return ymd;
		}
	}

	function nightsText( n ) {
		return n === 1 ? t.night1 : fmt( t.nightsN, n );
	}

	/**
	 * Conversion tracking: events go only into the Tag Manager data layer,
	 * so consent plugins and Tag Manager decide what is sent. No personal data.
	 */
	function track( payload, ecommerce ) {
		if ( ! cfg.tracking ) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		if ( ecommerce ) {
			window.dataLayer.push( { ecommerce: null } ); // Clears the previous ecommerce object (GA4).
		}
		window.dataLayer.push( payload );
	}

	function pixel( name, data ) {
		if ( cfg.tracking && cfg.tracking.metaPixel && typeof window.fbq === 'function' ) {
			window.fbq( 'track', name, data );
		}
	}

	function storageGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function storageSet( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	/**
	 * A guest's key for a booking, kept in this tab only (1.9.0): it leaves
	 * the address bar, so it is not in history, shared links or referrers,
	 * and a refresh still shows the booking.
	 */
	function guestKeyGet( reference ) {
		try {
			return window.sessionStorage.getItem( 'flexo_key_' + reference );
		} catch ( e ) {
			return null;
		}
	}

	function guestKeySet( reference, key ) {
		try {
			window.sessionStorage.setItem( 'flexo_key_' + reference, key );
		} catch ( e ) {}
	}

	/**
	 * Removes the guest key from the address bar (keeps the rest).
	 */
	function dropKeyFromAddress() {
		if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
			return;
		}
		var url = new URL( window.location.href );
		if ( ! url.searchParams.has( 'fb_key' ) ) {
			return;
		}
		url.searchParams.delete( 'fb_key' );
		window.history.replaceState( window.history.state, '', url.toString() );
	}

	var uidCounter = 0;
	function uid( prefix ) {
		uidCounter++;
		return ( prefix || 'fb' ) + '-' + Math.random().toString( 36 ).slice( 2, 7 ) + uidCounter;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function button( className, text ) {
		var b = el( 'button', className, text );
		b.type = 'button';
		return b;
	}

	/**
	 * Moves focus without jumping behind a sticky bar.
	 */
	function focusEl( node ) {
		if ( ! node ) {
			return;
		}
		if ( ! node.hasAttribute( 'tabindex' ) && ! /^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test( node.tagName ) ) {
			node.setAttribute( 'tabindex', '-1' );
		}
		try {
			node.focus( { preventScroll: true } );
		} catch ( e ) {
			node.focus();
		}
		var rect = node.getBoundingClientRect();
		var top = 90;
		if ( rect.top < top || rect.bottom > window.innerHeight - 120 ) {
			window.scrollTo( { top: Math.max( 0, window.pageYOffset + rect.top - top ), behavior: 'auto' } );
		}
	}

	/* ---------------------------------------------------------------------
	 * Field validation: messages under the field, in the page's language.
	 * ------------------------------------------------------------------- */

	var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

	function isShown( field ) {
		return ! field.disabled && ! field.closest( '[hidden]' ) && field.type !== 'hidden';
	}

	function errorAnchor( field ) {
		if ( field.type === 'checkbox' || field.type === 'radio' ) {
			return field.closest( 'label, fieldset' ) || field;
		}
		return field.closest( '.fb-phone' ) || field;
	}

	function setError( field, message ) {
		if ( ! field ) {
			return;
		}
		clearError( field );
		if ( ! message ) {
			return;
		}
		var id = ( field.id || uid( 'fb-f' ) ) + '-error';
		var p = el( 'p', 'fb-field__error', message );
		p.id = id;
		errorAnchor( field ).insertAdjacentElement( 'afterend', p );
		field.setAttribute( 'aria-invalid', 'true' );
		var described = ( field.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
		described.push( id );
		field.setAttribute( 'aria-describedby', described.join( ' ' ) );
		field._fbError = p;
	}

	function clearError( field ) {
		if ( ! field || ! field._fbError ) {
			return;
		}
		var id = field._fbError.id;
		field._fbError.remove();
		field._fbError = null;
		field.removeAttribute( 'aria-invalid' );
		var described = ( field.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( function ( d ) {
			return d && d !== id;
		} );
		if ( described.length ) {
			field.setAttribute( 'aria-describedby', described.join( ' ' ) );
		} else {
			field.removeAttribute( 'aria-describedby' );
		}
	}

	/**
	 * @return {string} Error message, or '' when the field is fine.
	 */
	function checkField( field ) {
		if ( ! isShown( field ) ) {
			return '';
		}
		var name = field.name;
		var value = field.type === 'checkbox' ? ( field.checked ? '1' : '' ) : String( field.value || '' ).trim();
		var messages = {
			guest_name: t.nameMissing,
			name: t.nameMissing,
			guest_email: t.emailMissing,
			email: t.emailMissing,
			guest_phone: t.phoneMissing,
			phone: t.phoneMissing,
			terms: t.termsMissing,
			privacy_consent: t.consent,
			'children_ages[]': t.ageMissing,
		};
		if ( field.required && ! value ) {
			return field.getAttribute( 'data-fb-message' ) || messages[ name ] || t.required;
		}
		if ( value && field.type === 'email' && ! EMAIL.test( value ) ) {
			return t.emailInvalid;
		}
		if ( value && field.type === 'tel' ) {
			var digits = value.replace( /\D/g, '' ).length;
			if ( digits < 6 || digits > 15 ) {
				return t.phoneInvalid;
			}
		}
		return '';
	}

	/**
	 * Checks on leaving a field; clears the message while correcting it.
	 */
	function bindValidation( form ) {
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'focusout', function ( e ) {
			var f = e.target;
			if ( ! f.name || ! /^(INPUT|SELECT|TEXTAREA)$/.test( f.tagName ) || f.type === 'checkbox' || f.type === 'radio' ) {
				return;
			}
			if ( f.value !== '' || f._fbTouched ) {
				f._fbTouched = true;
				setError( f, checkField( f ) );
			}
		} );
		function recheck( e ) {
			var f = e.target;
			if ( f && f._fbError ) {
				var msg = checkField( f );
				if ( ! msg ) {
					clearError( f );
				} else if ( f.type === 'checkbox' || f.tagName === 'SELECT' ) {
					setError( f, msg );
				}
			}
		}
		form.addEventListener( 'input', recheck );
		form.addEventListener( 'change', recheck );
	}

	/**
	 * @return {Element|null} The first field with a problem.
	 */
	function validateForm( form ) {
		var first = null;
		form.querySelectorAll( 'input, select, textarea' ).forEach( function ( f ) {
			if ( ! f.name || f.name === 'fb_website' ) {
				return;
			}
			var msg = checkField( f );
			setError( f, msg );
			if ( msg && ! first ) {
				first = f;
			}
		} );
		return first;
	}

	/**
	 * The invoice part of the details form: shown when "I would like an
	 * invoice" is ticked, with the fields for a person or a company.
	 */
	function bindInvoice( form ) {
		var box = form ? form.querySelector( '[data-fb-invoice]' ) : null;
		if ( ! box ) {
			return;
		}
		var toggle = box.querySelector( '[data-fb-invoice-toggle]' );
		var fields = box.querySelector( '.fb-invoice__fields' );
		function sync() {
			var on = toggle.checked;
			var type = box.querySelector( '[name="invoice_type"]:checked' );
			type = type ? type.value : 'individual';
			fields.hidden = ! on;
			box.querySelectorAll( '[data-fb-invoice-for]' ).forEach( function ( field ) {
				var show = on && field.getAttribute( 'data-fb-invoice-for' ) === type;
				var input = field.querySelector( 'input' );
				field.hidden = ! show;
				input.disabled = ! show;
				input.required = show && input.getAttribute( 'data-required' ) === '1';
				if ( ! show ) {
					clearError( input );
				}
			} );
		}
		box.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Keeps check-out after check-in when the native date fields are used
	 * (no date picker).
	 */
	function bindDates( form ) {
		var checkIn = form.querySelector( '[name="check_in"]' );
		var checkOut = form.querySelector( '[name="check_out"]' );
		if ( ! checkIn || ! checkOut ) {
			return;
		}
		function sync() {
			if ( ! checkIn.value ) {
				return;
			}
			var min = parseDate( checkIn.value );
			min.setDate( min.getDate() + 1 );
			checkOut.min = toYmd( min );
			if ( ! checkOut.value || checkOut.value <= checkIn.value ) {
				var def = parseDate( checkIn.value );
				def.setDate( def.getDate() + Math.max( 1, cfg.minNights || 1 ) );
				checkOut.value = toYmd( def );
			}
		}
		checkIn.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * One age selector per child ("Children & ages" feature). Works in the
	 * full form and in the search bar (the selects are submitted as
	 * children_ages[]).
	 */
	function bindAges( form ) {
		var children = form.querySelector( '[name="children"]' );
		if ( ! cfg.children || ! children ) {
			return;
		}
		var box = form.querySelector( '[data-fb-ages]' );
		if ( ! box ) {
			// Theme template overrides made before 1.3 have no container.
			box = el( 'div', 'fb-ages' );
			box.setAttribute( 'data-fb-ages', '' );
			children.closest( '.fb-field' ).insertAdjacentElement( 'afterend', box );
		}
		var initial = ( box.getAttribute( 'data-fb-ages' ) || '' ).split( ',' ).filter( function ( v ) {
			return v !== '';
		} );
		var id = uid( 'fb-age' );

		function render() {
			var count = parseInt( children.value, 10 ) || 0;
			var current = Array.prototype.map.call( box.querySelectorAll( 'select' ), function ( sel ) {
				return sel.value;
			} );
			if ( ! current.length ) {
				current = box._fbAges || initial;
			}
			box._fbAges = null;
			box.innerHTML = '';
			for ( var i = 0; i < count; i++ ) {
				var field = el( 'div', 'fb-field fb-field--small fb-field--age' );
				var label = el( 'label', '', fmt( t.childAge, i + 1 ) );
				label.htmlFor = id + '-' + i;
				var select = el( 'select' );
				select.id = id + '-' + i;
				select.name = 'children_ages[]';
				select.required = true;
				var pick = el( 'option', '', t.agePick );
				pick.value = '';
				select.appendChild( pick );
				for ( var age = 0; age <= ( cfg.maxAge || 17 ); age++ ) {
					var opt = el( 'option', '', age === 0 ? t.ageUnder1 : String( age ) );
					opt.value = String( age );
					select.appendChild( opt );
				}
				if ( current[ i ] !== undefined ) {
					select.value = current[ i ];
				}
				field.appendChild( label );
				field.appendChild( select );
				box.appendChild( field );
			}
			box.hidden = count === 0;
		}
		children.addEventListener( 'change', render );
		box._fbRender = function ( ages ) {
			box._fbAges = ages;
			box.innerHTML = '';
			render();
		};
		render();
	}

	/* ---------------------------------------------------------------------
	 * Date picker: an accessible range calendar on top of the check-in and
	 * check-out fields (which stay in the form with the same names). It
	 * greys out and strikes through days that can't be booked, and shows
	 * the minimum stay. The server still checks everything.
	 * ------------------------------------------------------------------- */

	function DatePicker( form, options ) {
		this.form = form;
		this.options = options || {};
		this.inInput = form.querySelector( '[name="check_in"]' );
		this.outInput = form.querySelector( '[name="check_out"]' );
		this.enhanced = false;
		if ( ! this.inInput || ! this.outInput || ! window.fetch || ! window.URLSearchParams ) {
			return;
		}
		this.enhanced = true;
		this.locale = this.options.locale || '';
		this.cache = {};
		this.data = null;
		this.mode = 'in';
		var self = this;

		this.buttons = {};
		[ [ 'in', this.inInput ], [ 'out', this.outInput ] ].forEach( function ( pair ) {
			var input = pair[ 1 ];
			var btn = button( 'fb-date' );
			btn.id = ( input.id || uid( 'fb-date' ) ) + '-btn';
			btn.setAttribute( 'aria-haspopup', 'dialog' );
			btn.setAttribute( 'aria-expanded', 'false' );
			var label = input.id ? form.querySelector( 'label[for="' + input.id + '"]' ) : null;
			if ( label ) {
				label.htmlFor = btn.id;
				label.id = label.id || btn.id + '-label';
			}
			input.insertAdjacentElement( 'afterend', btn );
			input.classList.add( 'fb-native-date' );
			input.tabIndex = -1;
			input.setAttribute( 'aria-hidden', 'true' );
			btn.addEventListener( 'click', function () {
				if ( self.isOpen() && self.mode === pair[ 0 ] ) {
					self.close();
				} else {
					self.open( pair[ 0 ] );
				}
			} );
			self.buttons[ pair[ 0 ] ] = btn;
		} );

		this.panel = el( 'div', 'fb-picker' );
		this.panel.id = uid( 'fb-picker' );
		this.panel.setAttribute( 'role', 'dialog' );
		this.panel.setAttribute( 'aria-label', t.datesDialog );
		this.panel.hidden = true;
		this.buttons.in.setAttribute( 'aria-controls', this.panel.id );
		this.buttons.out.setAttribute( 'aria-controls', this.panel.id );

		var head = el( 'div', 'fb-picker__head' );
		this.prev = button( 'fb-picker__nav fb-picker__nav--prev', '‹' );
		this.prev.setAttribute( 'aria-label', t.prevMonth );
		this.next = button( 'fb-picker__nav fb-picker__nav--next', '›' );
		this.next.setAttribute( 'aria-label', t.nextMonth );
		this.monthsBox = el( 'div', 'fb-picker__months' );
		head.appendChild( this.prev );
		head.appendChild( this.monthsBox );
		head.appendChild( this.next );
		this.status = el( 'p', 'fb-picker__status' );
		this.status.setAttribute( 'aria-live', 'polite' );
		var foot = el( 'div', 'fb-picker__foot' );
		this.clearBtn = button( 'fb-link', t.clear );
		this.doneBtn = button( 'fb-button fb-button--ghost', t.done );
		foot.appendChild( this.clearBtn );
		foot.appendChild( this.doneBtn );
		this.panel.appendChild( head );
		this.panel.appendChild( this.status );
		this.panel.appendChild( foot );
		form.appendChild( this.panel );

		this.prev.addEventListener( 'click', function () {
			self.shift( -1 );
		} );
		this.next.addEventListener( 'click', function () {
			self.shift( 1 );
		} );
		this.clearBtn.addEventListener( 'click', function () {
			self.start = '';
			self.end = '';
			self.mode = 'in';
			self.commit();
			self.render();
			self.focusDay( self.focusDate );
		} );
		this.doneBtn.addEventListener( 'click', function () {
			self.close( true );
		} );
		this.monthsBox.addEventListener( 'click', function ( e ) {
			var day = e.target.closest( '.fb-day' );
			if ( day ) {
				self.pick( day.getAttribute( 'data-date' ) );
			}
		} );
		this.monthsBox.addEventListener( 'keydown', this.onKey.bind( this ) );
		this.panel.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				self.close( true );
			}
		} );
		// Values from a link or the browser keep working.
		[ this.inInput, this.outInput ].forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				self.sync();
			} );
		} );
		this.sync();
	}

	DatePicker.prototype.isOpen = function () {
		return ! this.panel.hidden;
	};

	DatePicker.prototype.today = function () {
		return this.data ? this.data.today : toYmd( new Date() );
	};

	DatePicker.prototype.sync = function () {
		this.start = this.inInput.value || '';
		this.end = this.outInput.value && this.outInput.value > this.start ? this.outInput.value : '';
		this.updateButtons();
	};

	DatePicker.prototype.updateButtons = function () {
		var self = this;
		[ [ 'in', this.start ], [ 'out', this.end ] ].forEach( function ( pair ) {
			var btn = self.buttons[ pair[ 0 ] ];
			btn.textContent = pair[ 1 ] ? humanDate( pair[ 1 ], self.options.dateFormat, self.locale ) : t.chooseDates;
			btn.classList.toggle( 'is-empty', ! pair[ 1 ] );
		} );
	};

	/**
	 * Writes the chosen dates into the form fields.
	 */
	DatePicker.prototype.commit = function () {
		this.inInput.value = this.start || '';
		this.outInput.value = this.end || '';
		this.updateButtons();
		clearError( this.buttons.in );
		clearError( this.buttons.out );
		if ( this.options.onChange ) {
			this.options.onChange( this.start, this.end );
		}
	};

	DatePicker.prototype.open = function ( which ) {
		this.sync();
		this.mode = which === 'out' && this.start ? 'out' : 'in';
		var anchor = ( this.mode === 'out' ? this.end || this.start : this.start ) || this.today();
		this.focusDate = this.mode === 'out' ? ( this.end || addDays( this.start, 1 ) ) : anchor;
		var first = parseDate( anchor );
		this.view = new Date( first.getFullYear(), first.getMonth(), 1 );
		this.panel.hidden = false;
		this.buttons.in.setAttribute( 'aria-expanded', 'true' );
		this.buttons.out.setAttribute( 'aria-expanded', 'true' );
		this.months = this.panel.offsetWidth >= 560 ? 2 : 1;
		this.panel.classList.toggle( 'is-double', this.months === 2 );
		this.render();
		this.load();
		this.focusDay( this.focusDate );
	};

	DatePicker.prototype.close = function ( returnFocus ) {
		if ( ! this.isOpen() ) {
			return;
		}
		this.panel.hidden = true;
		this.buttons.in.setAttribute( 'aria-expanded', 'false' );
		this.buttons.out.setAttribute( 'aria-expanded', 'false' );
		if ( returnFocus ) {
			this.buttons[ this.end ? 'out' : 'in' ].focus();
		}
	};

	DatePicker.prototype.shift = function ( months ) {
		var v = new Date( this.view.getFullYear(), this.view.getMonth() + months, 1 );
		var today = parseDate( this.today() );
		if ( v < new Date( today.getFullYear(), today.getMonth(), 1 ) ) {
			return;
		}
		this.view = v;
		this.render();
		this.load();
	};

	DatePicker.prototype.key = function () {
		var g = this.options.guests ? this.options.guests() : { adults: 2, children: 0 };
		var room = this.options.room ? this.options.room() : '';
		var month = toYmd( this.view ).slice( 0, 7 );
		return [ room, g.adults, g.children, month, this.months ].join( '|' );
	};

	/**
	 * Availability of the months shown (cached per month, room and guests).
	 */
	DatePicker.prototype.load = function () {
		var self = this;
		var key = this.key();
		if ( this.cache[ key ] ) {
			this.data = this.cache[ key ] === 'failed' ? null : this.cache[ key ];
			this.render();
			return;
		}
		var g = this.options.guests ? this.options.guests() : { adults: 2, children: 0 };
		var params = { month: toYmd( this.view ).slice( 0, 7 ), months: this.months, adults: g.adults, children: g.children };
		var room = this.options.room ? this.options.room() : '';
		if ( room ) {
			params.room = room;
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		this.loading = true;
		this.panel.classList.add( 'is-loading' );
		this.setStatus();
		request( apiUrl( 'calendar', params ) )
			.then( function ( data ) {
				self.cache[ key ] = data;
				if ( self.key() === key ) {
					self.data = data;
				}
			} )
			.catch( function () {
				// Without availability every day can be chosen; the search tells.
				self.cache[ key ] = 'failed';
				self.data = null;
			} )
			.finally( function () {
				self.loading = false;
				self.panel.classList.remove( 'is-loading' );
				if ( self.key() === key ) {
					var focused = self.monthsBox.contains( document.activeElement );
					self.render();
					if ( focused ) {
						self.focusDay( self.focusDate );
					}
				}
			} );
	};

	/** Guests or room changed: availability is loaded again. */
	DatePicker.prototype.reset = function () {
		this.data = null;
		if ( this.isOpen() ) {
			this.render();
			this.load();
		}
	};

	DatePicker.prototype.index = function ( ymd ) {
		if ( ! this.data || ! this.data.rooms.length ) {
			return -1;
		}
		var i = diffDays( this.data.from, ymd );
		return i >= 0 && i < this.data.rooms[ 0 ].free.length ? i : -1;
	};

	/**
	 * Free nights of one room from index i for n nights (unknown = free).
	 */
	function runFree( room, i, n ) {
		for ( var k = i; k < i + n; k++ ) {
			if ( k < room.free.length && room.free.charAt( k ) !== '1' ) {
				return false;
			}
		}
		return true;
	}

	DatePicker.prototype.inRange = function ( ymd ) {
		var today = this.today();
		var last = this.data ? this.data.last : '';
		return ymd >= today && ( ! last || ymd <= last );
	};

	/**
	 * @return {{ok: boolean, reason: string, min: number}}
	 */
	DatePicker.prototype.arrival = function ( ymd ) {
		if ( ! this.inRange( ymd ) ) {
			return { ok: false, reason: '', min: 0 };
		}
		var i = this.index( ymd );
		if ( i < 0 ) {
			// No room fits the guests: nothing can be booked. Otherwise unknown = allowed.
			var none = this.data && this.data.rooms && ! this.data.rooms.length;
			return { ok: ! none, reason: none ? t.dayUnavailable : '', min: 0 };
		}
		var free = false;
		var closed = true;
		var minOk = 0;
		var minAll = 0;
		this.data.rooms.forEach( function ( room ) {
			var c = room.free.charAt( i );
			free = free || c === '1';
			closed = closed && c === 'c';
			if ( c === '1' ) {
				var m = room.min[ i ] || 1;
				minAll = minAll ? Math.min( minAll, m ) : m;
				if ( runFree( room, i, m ) ) {
					minOk = minOk ? Math.min( minOk, m ) : m;
				}
			}
		} );
		if ( minOk ) {
			return { ok: true, reason: '', min: minOk };
		}
		if ( ! free ) {
			return { ok: false, reason: closed ? t.dayClosed : t.dayFull, min: 0 };
		}
		return { ok: false, reason: minAll > 1 ? fmt( t.dayMinStay, minAll ) : t.dayNoArrival, min: minAll };
	};

	/**
	 * Whether a stay from the chosen check-in to this day can be booked.
	 */
	DatePicker.prototype.departure = function ( ymd ) {
		var nights = diffDays( this.start, ymd );
		var max = cfg.maxNights || ( this.data ? this.data.max_nights : 0 );
		if ( nights < 1 || ( max && nights > max ) ) {
			return { ok: false, reason: '' };
		}
		var i = this.index( this.start );
		if ( i < 0 ) {
			return { ok: true, reason: '' };
		}
		var ok = false;
		var min = 0;
		this.data.rooms.forEach( function ( room ) {
			if ( room.free.charAt( i ) !== '1' ) {
				return;
			}
			var m = room.min[ i ] || 1;
			min = min ? Math.min( min, m ) : m;
			if ( nights >= m && runFree( room, i, nights ) ) {
				ok = true;
			}
		} );
		if ( ok ) {
			return { ok: true, reason: '' };
		}
		return { ok: false, reason: min > nights ? fmt( t.dayMinStay, min ) : t.dayUnavailable };
	};

	DatePicker.prototype.dayState = function ( ymd ) {
		if ( this.mode === 'out' && this.start && ymd > this.start ) {
			return this.departure( ymd );
		}
		return this.arrival( ymd );
	};

	DatePicker.prototype.setStatus = function ( message ) {
		if ( message ) {
			this.status.textContent = message;
			return;
		}
		if ( this.mode === 'out' && this.start ) {
			var a = this.arrival( this.start );
			this.status.textContent = fmt( t.pickDeparture, humanDate( this.start, this.options.dateFormat, this.locale ) ) + ( a.min > 1 ? ' ' + fmt( t.minStayHint, a.min ) : '' );
		} else {
			this.status.textContent = this.loading ? t.loadingDates : t.pickArrival;
		}
	};

	DatePicker.prototype.render = function () {
		var self = this;
		this.monthsBox.innerHTML = '';
		var today = parseDate( this.today() );
		this.prev.disabled = this.view <= new Date( today.getFullYear(), today.getMonth(), 1 );
		var weekdays = [];
		for ( var w = 0; w < 7; w++ ) {
			// 5 Jan 1970 was a Monday.
			var d = new Date( 1970, 0, 5 + w );
			weekdays.push( {
				short: d.toLocaleDateString( lang( this.locale ), { weekday: 'short' } ),
				long: d.toLocaleDateString( lang( this.locale ), { weekday: 'long' } ),
			} );
		}
		for ( var m = 0; m < this.months; m++ ) {
			var first = new Date( this.view.getFullYear(), this.view.getMonth() + m, 1 );
			var month = el( 'div', 'fb-month' );
			var title = el( 'h5', 'fb-month__title', first.toLocaleDateString( lang( this.locale ), { month: 'long', year: 'numeric' } ) );
			title.id = uid( 'fb-month' );
			month.appendChild( title );
			var table = el( 'table', 'fb-month__grid' );
			table.setAttribute( 'role', 'grid' );
			table.setAttribute( 'aria-labelledby', title.id );
			var thead = el( 'thead' );
			var hr = el( 'tr' );
			weekdays.forEach( function ( wd ) {
				var th = el( 'th', '', wd.short );
				th.scope = 'col';
				th.abbr = wd.long;
				hr.appendChild( th );
			} );
			thead.appendChild( hr );
			table.appendChild( thead );
			var tbody = el( 'tbody' );
			var offset = ( first.getDay() + 6 ) % 7;
			var days = new Date( first.getFullYear(), first.getMonth() + 1, 0 ).getDate();
			var row = el( 'tr' );
			for ( var b = 0; b < offset; b++ ) {
				row.appendChild( el( 'td', 'fb-month__blank' ) );
			}
			for ( var day = 1; day <= days; day++ ) {
				var ymd = toYmd( new Date( first.getFullYear(), first.getMonth(), day ) );
				row.appendChild( this.dayCell( ymd, day ) );
				if ( ( offset + day ) % 7 === 0 ) {
					tbody.appendChild( row );
					row = el( 'tr' );
				}
			}
			if ( row.childNodes.length ) {
				while ( row.childNodes.length < 7 ) {
					row.appendChild( el( 'td', 'fb-month__blank' ) );
				}
				tbody.appendChild( row );
			}
			table.appendChild( tbody );
			month.appendChild( table );
			this.monthsBox.appendChild( month );
		}
		// Roving tabindex: one day in the tab order.
		var target = this.monthsBox.querySelector( '[data-date="' + this.focusDate + '"]' ) || this.monthsBox.querySelector( '.fb-day:not([aria-disabled="true"])' ) || this.monthsBox.querySelector( '.fb-day' );
		if ( target ) {
			target.tabIndex = 0;
		}
		this.setStatus();
		self.updateButtons();
	};

	DatePicker.prototype.dayCell = function ( ymd, day ) {
		var td = el( 'td' );
		td.setAttribute( 'role', 'gridcell' );
		var state = this.dayState( ymd );
		var btn = button( 'fb-day', String( day ) );
		btn.tabIndex = -1;
		btn.setAttribute( 'data-date', ymd );
		var past = ymd < this.today();
		var parts = [ parseDate( ymd ).toLocaleDateString( lang( this.locale ), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } ) ];
		if ( ymd === this.start ) {
			btn.classList.add( 'is-start' );
			parts.push( t.dayCheckIn );
		}
		if ( ymd === this.end ) {
			btn.classList.add( 'is-end' );
			parts.push( t.dayCheckOut );
		}
		if ( this.start && this.end && ymd > this.start && ymd < this.end ) {
			btn.classList.add( 'is-range' );
		}
		if ( ymd === this.today() ) {
			btn.classList.add( 'is-today' );
		}
		if ( ! state.ok ) {
			btn.setAttribute( 'aria-disabled', 'true' );
			btn.classList.add( past ? 'is-past' : 'is-blocked' );
			if ( state.reason ) {
				parts.push( state.reason );
				btn.title = state.reason;
			}
		} else if ( ! past ) {
			parts.push( t.dayAvailable );
		}
		if ( ymd === this.start || ymd === this.end ) {
			btn.setAttribute( 'aria-pressed', 'true' );
		}
		var price = this.data && this.data.prices && ! Array.isArray( this.data.prices ) ? this.data.prices[ ymd ] : '';
		if ( price && state.ok && this.mode === 'in' ) {
			btn.appendChild( el( 'small', 'fb-day__price', price ) );
			parts.push( price );
		}
		btn.setAttribute( 'aria-label', parts.join( ', ' ) );
		td.appendChild( btn );
		return td;
	};

	DatePicker.prototype.focusDay = function ( ymd ) {
		if ( ! ymd ) {
			return;
		}
		var first = new Date( this.view.getFullYear(), this.view.getMonth(), 1 );
		var after = new Date( this.view.getFullYear(), this.view.getMonth() + this.months, 1 );
		var date = parseDate( ymd );
		if ( date < first || date >= after ) {
			var today = parseDate( this.today() );
			if ( date < new Date( today.getFullYear(), today.getMonth(), 1 ) ) {
				return;
			}
			this.view = date < first ? new Date( date.getFullYear(), date.getMonth(), 1 ) : new Date( date.getFullYear(), date.getMonth() - this.months + 1, 1 );
			this.focusDate = ymd;
			this.render();
			this.load();
		}
		this.focusDate = ymd;
		this.monthsBox.querySelectorAll( '.fb-day' ).forEach( function ( b ) {
			b.tabIndex = b.getAttribute( 'data-date' ) === ymd ? 0 : -1;
		} );
		var btn = this.monthsBox.querySelector( '[data-date="' + ymd + '"]' );
		if ( btn ) {
			btn.focus();
		}
	};

	DatePicker.prototype.onKey = function ( e ) {
		var day = e.target.closest( '.fb-day' );
		if ( ! day ) {
			return;
		}
		var ymd = day.getAttribute( 'data-date' );
		var date = parseDate( ymd );
		var moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
		var target = null;
		if ( moves[ e.key ] ) {
			target = addDays( ymd, moves[ e.key ] );
		} else if ( e.key === 'Home' ) {
			target = addDays( ymd, -( ( date.getDay() + 6 ) % 7 ) );
		} else if ( e.key === 'End' ) {
			target = addDays( ymd, 6 - ( ( date.getDay() + 6 ) % 7 ) );
		} else if ( e.key === 'PageUp' || e.key === 'PageDown' ) {
			var step = ( e.key === 'PageUp' ? -1 : 1 ) * ( e.shiftKey ? 12 : 1 );
			var moved = new Date( date.getFullYear(), date.getMonth() + step, 1 );
			var lastDay = new Date( moved.getFullYear(), moved.getMonth() + 1, 0 ).getDate();
			target = toYmd( new Date( moved.getFullYear(), moved.getMonth(), Math.min( date.getDate(), lastDay ) ) );
		}
		if ( target ) {
			e.preventDefault();
			if ( target >= this.today() ) {
				this.focusDay( target );
			}
		}
	};

	DatePicker.prototype.pick = function ( ymd ) {
		var state = this.dayState( ymd );
		if ( this.mode === 'out' && this.start && ymd > this.start ) {
			if ( ! state.ok ) {
				this.setStatus( state.reason || t.dayUnavailable );
				return;
			}
			this.end = ymd;
			this.commit();
			this.setStatus( humanDate( this.start, this.options.dateFormat, this.locale ) + ' – ' + humanDate( this.end, this.options.dateFormat, this.locale ) + ' · ' + nightsText( diffDays( this.start, this.end ) ) );
			this.close( true );
			if ( this.options.onDone ) {
				this.options.onDone( this.start, this.end );
			}
			return;
		}
		if ( ! state.ok ) {
			this.setStatus( state.reason || t.dayUnavailable );
			return;
		}
		this.start = ymd;
		this.mode = 'out';
		// A check-out that no longer fits is cleared.
		if ( this.end && ( this.end <= ymd || ! this.departure( this.end ).ok ) ) {
			this.end = '';
		}
		this.commit();
		this.focusDate = this.end || addDays( ymd, Math.max( 1, state.min || 1 ) );
		this.render();
		this.focusDay( this.focusDate );
	};

	/* ---------------------------------------------------------------------
	 * The booking form
	 * ------------------------------------------------------------------- */

	var STATE_KEYS = [ 'check_in', 'check_out', 'adults', 'children', 'children_ages', 'children_ages[]', 'fb_step', 'fb_room', 'fb_plan', 'fb_promo', 'fb_all', 'fb_done', 'fb_key', 'fb_manage', 'fb_payment', 'fb_ref' ];

	function BookingForm( root ) {
		var self = this;
		this.root = root;
		this.searchForm = root.querySelector( '.fb-search' );
		this.detailsForm = root.querySelector( '.fb-details' );
		this.results = root.querySelector( '.fb-results' );
		this.notice = root.querySelector( '.fb-notice' );
		this.success = root.querySelector( '.fb-success' );
		this.summary = root.querySelector( '.fb-summary' );
		this.aside = root.querySelector( '[data-fb-aside]' );
		this.room = root.getAttribute( 'data-room' ) || '';
		this.locale = root.getAttribute( 'data-locale' ) || '';
		this.dateFormat = root.getAttribute( 'data-date-format' ) || '';
		this.showAll = false;
		this.stay = null;
		this.rooms = [];
		this.selected = null;
		this.view = null;
		this.promo = '';
		this.step = '';
		this.useUrl = ! urlOwner && !! ( window.history && window.history.pushState && window.URL );
		if ( this.useUrl ) {
			urlOwner = this;
		}

		this.buildChrome();

		this.picker = new DatePicker( this.searchForm, {
			locale: this.locale,
			dateFormat: this.dateFormat,
			room: function () {
				return self.room && ! self.showAll ? self.room : '';
			},
			guests: function () {
				var v = self.values();
				return { adults: v.adults, children: v.children || 0 };
			},
		} );
		if ( ! this.picker.enhanced ) {
			bindDates( this.searchForm );
		}
		bindAges( this.searchForm );
		bindInvoice( this.detailsForm );
		bindValidation( this.detailsForm );
		bindValidation( this.searchForm );
		this.bindPayment();
		this.bindPhone();

		this.searchForm.addEventListener( 'change', function ( e ) {
			if ( e.target && ( e.target.name === 'adults' || e.target.name === 'children' ) ) {
				self.picker.reset && self.picker.reset();
			}
		} );
		this.searchForm.addEventListener( 'submit', this.onSearch.bind( this ) );
		this.detailsForm.addEventListener( 'submit', this.onBook.bind( this ) );
		this.detailsForm.querySelector( '[data-fb-back]' ).addEventListener( 'click', this.back.bind( this ) );

		if ( this.useUrl ) {
			window.addEventListener( 'popstate', function () {
				self.route( false );
			} );
		}
		this.route( true );
	}

	/**
	 * Step bar, stay line, summary toggle and the screen-reader announcer,
	 * added when a theme's template override doesn't have them.
	 */
	BookingForm.prototype.buildChrome = function () {
		var self = this;
		var root = this.root;
		this.stepsEl = root.querySelector( '[data-fb-steps]' );
		if ( ! this.stepsEl && cfg.steps ) {
			this.stepsEl = el( 'ol', 'fb-steps' );
			this.stepsEl.setAttribute( 'data-fb-steps', '' );
			Object.keys( cfg.steps ).forEach( function ( key, i ) {
				var li = el( 'li', 'fb-steps__item' );
				li.setAttribute( 'data-step', key );
				var num = el( 'span', 'fb-steps__num', String( i + 1 ) );
				num.setAttribute( 'aria-hidden', 'true' );
				li.appendChild( num );
				li.appendChild( el( 'span', 'fb-steps__label', cfg.steps[ key ] ) );
				self.stepsEl.appendChild( li );
			} );
			var title = root.querySelector( '.fb-title' );
			root.insertBefore( this.stepsEl, title ? title.nextSibling : root.firstChild );
		}
		this.stayBar = root.querySelector( '[data-fb-stay]' );
		if ( ! this.stayBar ) {
			this.stayBar = el( 'div', 'fb-stay' );
			this.stayBar.setAttribute( 'data-fb-stay', '' );
			this.stayBar.hidden = true;
			this.searchForm.insertAdjacentElement( 'afterend', this.stayBar );
		}
		this.live = el( 'p', 'screen-reader-text' );
		this.live.setAttribute( 'aria-live', 'polite' );
		root.appendChild( this.live );

		if ( this.aside ) {
			var toggle = this.aside.querySelector( '.fb-aside__toggle' );
			this.asideToggle = toggle;
			this.asideShort = this.aside.querySelector( '[data-fb-aside-short]' );
			toggle.addEventListener( 'click', function () {
				if ( self.root.classList.contains( 'fb-wide' ) ) {
					return;
				}
				var open = ! self.aside.classList.contains( 'is-open' );
				self.aside.classList.toggle( 'is-open', open );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		}
		// Wide forms show the summary next to the steps; narrow ones above them.
		function measure() {
			var wide = root.clientWidth >= 900;
			root.classList.toggle( 'fb-wide', wide );
			root.classList.toggle( 'fb-narrow', root.clientWidth < 600 );
			if ( self.asideToggle ) {
				self.asideToggle.setAttribute( 'aria-expanded', wide || self.aside.classList.contains( 'is-open' ) ? 'true' : 'false' );
			}
		}
		if ( window.ResizeObserver ) {
			new window.ResizeObserver( measure ).observe( root );
		} else {
			window.addEventListener( 'resize', measure );
		}
		measure();
	};

	BookingForm.prototype.announce = function ( text ) {
		var live = this.live;
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = text;
		}, 60 );
	};

	/**
	 * Shows one step: dates, rooms, details, done or manage.
	 */
	BookingForm.prototype.show = function ( step, focus ) {
		var r = this.root;
		this.step = step;
		var hasStay = !! this.stay;
		this.searchForm.hidden = ! ( step === 'dates' || ( step === 'rooms' && ! hasStay ) );
		this.stayBar.hidden = ! ( hasStay && ( step === 'rooms' || step === 'details' ) );
		this.results.hidden = step !== 'rooms' || ! hasStay;
		this.detailsForm.hidden = step !== 'details';
		this.success.hidden = step !== 'done' && step !== 'manage';
		if ( this.aside ) {
			this.aside.hidden = ! ( hasStay && ( step === 'rooms' || step === 'details' ) );
			r.classList.toggle( 'has-aside', ! this.aside.hidden );
			// Phones: a collapsed bar that opens on tap. Larger screens: open.
			var open = step === 'details' && ( r.classList.contains( 'fb-wide' ) || window.innerWidth > 767 );
			this.aside.classList.toggle( 'is-open', open );
			if ( this.asideToggle ) {
				this.asideToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			}
		}
		if ( this.picker.close && step !== 'dates' ) {
			this.picker.close();
		}
		[ 'dates', 'rooms', 'details', 'done', 'manage' ].forEach( function ( s ) {
			r.classList.toggle( 'fb-step--' + s, s === step );
		} );
		if ( this.stepsEl ) {
			this.stepsEl.hidden = step === 'manage';
			var items = Array.prototype.slice.call( this.stepsEl.querySelectorAll( '[data-step]' ) );
			var current = step === 'manage' ? 'done' : step;
			var index = items.findIndex( function ( li ) {
				return li.getAttribute( 'data-step' ) === current;
			} );
			items.forEach( function ( li, i ) {
				li.classList.toggle( 'is-done', i < index );
				li.classList.toggle( 'is-current', i === index );
				if ( i === index ) {
					li.setAttribute( 'aria-current', 'step' );
				} else {
					li.removeAttribute( 'aria-current' );
				}
			} );
			if ( index >= 0 && step !== 'manage' ) {
				this.announce( fmtN( t.stepOf, index + 1, items.length, items[ index ].textContent.replace( /^\d+/, '' ) ) );
			}
		}
		if ( ! focus ) {
			return;
		}
		var target = null;
		if ( step === 'dates' ) {
			target = this.picker.enhanced ? this.picker.buttons.in : this.searchForm.querySelector( 'input, select' );
		} else if ( step === 'rooms' ) {
			target = this.results.querySelector( '.fb-results__title' );
		} else if ( step === 'details' ) {
			target = this.detailsForm.querySelector( '.fb-step-title' );
		} else {
			target = this.success.querySelector( '.fb-done__title' ) || this.success;
		}
		focusEl( target );
	};

	/* ---- Address (URL) state ---- */

	BookingForm.prototype.stayParams = function () {
		var s = this.stay;
		var p = { check_in: s.check_in, check_out: s.check_out, adults: String( s.adults ) };
		if ( parseInt( s.children, 10 ) > 0 ) {
			p.children = String( s.children );
			if ( s.ages && s.ages.length ) {
				p.children_ages = s.ages.join( ',' );
			}
		}
		if ( this.showAll && this.room ) {
			p.fb_all = '1';
		}
		return p;
	};

	BookingForm.prototype.setUrl = function ( params, replace ) {
		if ( ! this.useUrl ) {
			return;
		}
		var url = new URL( window.location.href );
		STATE_KEYS.forEach( function ( k ) {
			url.searchParams.delete( k );
		} );
		Object.keys( params ).forEach( function ( k ) {
			if ( params[ k ] !== '' && params[ k ] !== null && params[ k ] !== undefined ) {
				url.searchParams.set( k, params[ k ] );
			}
		} );
		if ( url.toString() === window.location.href ) {
			return;
		}
		window.history[ replace ? 'replaceState' : 'pushState' ]( { fb: true }, '', url.toString() );
	};

	BookingForm.prototype.detailsParams = function () {
		var p = this.stayParams();
		p.fb_step = 'details';
		p.fb_room = this.selected.slug || String( this.selected.id );
		if ( this.view && this.view.rate_plan ) {
			p.fb_plan = String( this.view.rate_plan.id );
		}
		if ( this.promo ) {
			p.fb_promo = this.promo;
		}
		return p;
	};

	/**
	 * Puts the page in the state the address describes (first load, Back,
	 * Forward, refresh).
	 */
	BookingForm.prototype.route = function ( initial ) {
		var self = this;
		var q = new URLSearchParams( window.location.search );
		if ( ! this.useUrl && ! initial ) {
			return;
		}
		var doneRef = q.get( 'fb_done' ) || ( q.get( 'fb_payment' ) ? q.get( 'fb_ref' ) : '' );
		if ( doneRef && q.get( 'fb_key' ) ) {
			// Kept in this tab; the address no longer shows it.
			guestKeySet( doneRef, q.get( 'fb_key' ) );
			dropKeyFromAddress();
		}
		if ( q.get( 'fb_payment' ) && q.get( 'fb_ref' ) ) {
			// Back from the payment page: show the payment result.
			var payKey = q.get( 'fb_key' ) || guestKeyGet( q.get( 'fb_ref' ) );
			if ( payKey ) {
				this.resumePayment( q.get( 'fb_payment' ), q.get( 'fb_ref' ), payKey );
			} else {
				this.showKeyMissing();
			}
			return;
		}
		if ( q.get( 'fb_manage' ) && q.get( 'fb_key' ) ) {
			this.loadBooking( q.get( 'fb_manage' ), q.get( 'fb_key' ), true );
			return;
		}
		if ( q.get( 'fb_done' ) ) {
			var doneKey = q.get( 'fb_key' ) || guestKeyGet( q.get( 'fb_done' ) );
			if ( doneKey ) {
				this.loadBooking( q.get( 'fb_done' ), doneKey, false );
			} else {
				this.showKeyMissing();
			}
			return;
		}
		this.showAll = q.get( 'fb_all' ) === '1';
		var ci = q.get( 'check_in' ) || '';
		var co = q.get( 'check_out' ) || '';
		if ( ! ci || ! co ) {
			if ( initial && this.root.getAttribute( 'data-autosearch' ) === '1' ) {
				this.search( { replace: true } );
				return;
			}
			this.stay = null;
			this.show( 'dates', ! initial );
			return;
		}
		if ( ! initial ) {
			this.fillSearch( q );
		}
		var ages = q.get( 'children_ages' ) || q.getAll( 'children_ages[]' ).join( ',' );
		var same = this.stay && this.stay.check_in === ci && this.stay.check_out === co && String( this.stay.adults ) === String( q.get( 'adults' ) || this.stay.adults ) && String( this.stay.children || 0 ) === String( q.get( 'children' ) || 0 ) && ( this.stay.ages || [] ).join( ',' ) === ages;
		var ready = same ? Promise.resolve( true ) : this.search( { fromUrl: true } );
		ready.then( function ( ok ) {
			if ( ! ok ) {
				return;
			}
			if ( q.get( 'fb_step' ) === 'details' && q.get( 'fb_room' ) ) {
				self.restoreSelection( q.get( 'fb_room' ), q.get( 'fb_plan' ), q.get( 'fb_promo' ) || '', ! initial );
			} else if ( initial && self.room && ! self.showAll ) {
				self.skipToRoom();
			} else {
				self.show( 'rooms', ! initial );
			}
		} );
	};

	/**
	 * Back/Forward to another stay: the search form shows it again.
	 */
	BookingForm.prototype.fillSearch = function ( q ) {
		var f = this.searchForm;
		var set = function ( name, value ) {
			var field = f.querySelector( '[name="' + name + '"]' );
			if ( field && value !== null ) {
				field.value = value;
			}
		};
		set( 'check_in', q.get( 'check_in' ) );
		set( 'check_out', q.get( 'check_out' ) );
		set( 'adults', q.get( 'adults' ) || '2' );
		var children = f.querySelector( '[name="children"]' );
		if ( children ) {
			children.value = q.get( 'children' ) || '0';
			var box = f.querySelector( '[data-fb-ages]' );
			if ( box && box._fbRender ) {
				box._fbRender( ( q.get( 'children_ages' ) || '' ).split( ',' ).filter( Boolean ) );
			}
		}
		if ( this.picker.enhanced ) {
			this.picker.sync();
		}
	};

	/**
	 * Came from a room's "Book now" link: one rate → straight to the
	 * details; several rates → the rates of that room.
	 */
	BookingForm.prototype.skipToRoom = function () {
		var self = this;
		var room = this.rooms.filter( function ( r ) {
			return r.slug === self.room || String( r.id ) === self.room;
		} )[ 0 ];
		if ( ! room || ! room.available ) {
			this.show( 'rooms' );
			return;
		}
		var plans = room.plans || [];
		if ( plans.length > 1 ) {
			this.show( 'rooms' );
			var card = this.results.querySelector( '[data-room-id="' + room.id + '"]' );
			if ( card ) {
				this.showPlans( card, room, false );
			}
			return;
		}
		this.select( room, plans[ 0 ] || room.quote || null );
	};

	BookingForm.prototype.restoreSelection = function ( slug, planId, promo, focus ) {
		var self = this;
		var room = this.rooms.filter( function ( r ) {
			return r.slug === slug || String( r.id ) === slug;
		} )[ 0 ];
		if ( ! room || ! room.available ) {
			this.show( 'rooms', focus );
			if ( room ) {
				this.setNotice( room.reason || t.noRooms, true );
			}
			return;
		}
		var plan = null;
		( room.plans || [] ).forEach( function ( p ) {
			if ( p.rate_plan && String( p.rate_plan.id ) === String( planId ) ) {
				plan = p;
			}
		} );
		if ( ! plan && ( room.plans || [] ).length > 1 ) {
			this.show( 'rooms', focus );
			return;
		}
		this.select( room, plan || ( room.plans || [] )[ 0 ] || room.quote || null, { restore: true, focus: focus } );
		if ( promo && cfg.promo ) {
			this.applyPromo( promo ).then( function () {
				self.setUrl( self.detailsParams(), true );
			} );
		}
	};

	/* ---- Payment choice and the final button ---- */

	/**
	 * Payment method choice and the submit button text that goes with it,
	 * plus the total shown next to the button (kept visible on phones).
	 */
	BookingForm.prototype.bindPayment = function () {
		var self = this;
		var actions = this.detailsForm.querySelector( '.fb-actions' );
		var submit = this.detailsForm.querySelector( '[type="submit"]' );
		this.submitLabel = submit ? submit.textContent.trim() : '';
		if ( actions ) {
			this.actionsTotal = el( 'div', 'fb-actions__total' );
			this.actionsTotal.setAttribute( 'aria-hidden', 'true' );
			actions.insertBefore( this.actionsTotal, actions.firstChild );
			this.before = el( 'section', 'fb-before' );
			this.before.hidden = true;
			actions.insertAdjacentElement( 'beforebegin', this.before );
			// On phones the summary is a collapsed bar: the promo code field is in the form.
			this.promoSlot = el( 'div', 'fb-promo-slot' );
			this.before.insertAdjacentElement( 'beforebegin', this.promoSlot );
		}
		this.detailsForm.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.name === 'payment_method' ) {
				self.updateSubmit();
				self.renderBefore();
			}
		} );
		this.updateSubmit();
	};

	BookingForm.prototype.paymentMethod = function () {
		var checked = this.detailsForm.querySelector( '[name="payment_method"]:checked' );
		return checked ? checked.value : '';
	};

	/**
	 * The final button says exactly what happens next.
	 */
	BookingForm.prototype.updateSubmit = function () {
		var submit = this.detailsForm.querySelector( '[type="submit"]' );
		if ( ! submit || this.sending ) {
			return;
		}
		var pay = cfg.payments;
		var method = this.paymentMethod();
		var now = this.view && this.view.payment && this.view.payment.now > 0 ? this.view.payment.now_formatted : '';
		var label = cfg.instant === undefined ? this.submitLabel : ( cfg.instant ? t.confirmBooking : t.sendRequest );
		if ( pay && method && pay.methods[ method ] && now ) {
			if ( pay.methods[ method ].hosted ) {
				label = fmt( t.payCard, now );
			} else if ( pay.instant ) {
				label = fmt( t.payBank, now );
			}
		}
		submit.textContent = label;
	};

	/**
	 * Phone numbers: "+359" shown next to the number, updated with the country.
	 */
	BookingForm.prototype.bindPhone = function () {
		this.root.querySelectorAll( '.fb-phone__cc select' ).forEach( function ( select ) {
			var code = select.parentNode.querySelector( '.fb-phone__code' );
			function sync() {
				var opt = select.options[ select.selectedIndex ];
				if ( code && opt ) {
					code.textContent = '+' + opt.getAttribute( 'data-code' );
				}
			}
			select.addEventListener( 'change', sync );
			sync();
		} );
	};

	BookingForm.prototype.setNotice = function ( message, isError ) {
		this.notice.textContent = message || '';
		this.notice.classList.toggle( 'fb-notice--error', !! isError );
		this.notice.hidden = ! message;
	};

	BookingForm.prototype.values = function () {
		var f = this.searchForm;
		var children = f.querySelector( '[name="children"]' );
		var v = {
			check_in: f.querySelector( '[name="check_in"]' ).value,
			check_out: f.querySelector( '[name="check_out"]' ).value,
			adults: f.querySelector( '[name="adults"]' ).value,
			children: children ? children.value : 0,
		};
		var ages = f.querySelectorAll( '[name="children_ages[]"]' );
		if ( cfg.children && ages.length ) {
			v.children_ages = Array.prototype.map.call( ages, function ( sel ) {
				return sel.value;
			} ).join( ',' );
		}
		return v;
	};

	/* ---- Search ---- */

	BookingForm.prototype.onSearch = function ( e ) {
		e.preventDefault();
		this.showAll = false;
		this.search( { focus: true } );
	};

	/**
	 * @param {Object} opts fromUrl: the address already has this stay; replace: replace the address; focus: move focus to the results.
	 * @return {Promise<boolean>} Whether rooms were found (or shown).
	 */
	BookingForm.prototype.search = function ( opts ) {
		var self = this;
		opts = opts || {};
		var v = this.values();
		this.setNotice( '' );

		var invalid = null;
		if ( ! v.check_in || ! v.check_out ) {
			var dates = this.picker.enhanced ? this.picker.buttons[ v.check_in ? 'out' : 'in' ] : this.searchForm.querySelector( '[name="' + ( v.check_in ? 'check_out' : 'check_in' ) + '"]' );
			setError( dates, t.datesMissing );
			invalid = dates;
		} else if ( v.check_out <= v.check_in ) {
			this.setNotice( t.datesInvalid, true );
			return Promise.resolve( false );
		}
		this.searchForm.querySelectorAll( '[name="children_ages[]"]' ).forEach( function ( sel ) {
			var msg = checkField( sel );
			setError( sel, msg );
			invalid = invalid || ( msg ? sel : null );
		} );
		if ( invalid ) {
			this.show( 'dates' );
			if ( opts.focus || ! opts.fromUrl ) {
				focusEl( invalid );
			}
			return Promise.resolve( false );
		}

		var params = Object.assign( {}, v );
		if ( this.room && ! this.showAll ) {
			params.room = this.room;
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}

		this.root.classList.add( 'is-loading' );
		this.renderSkeleton();
		this.announce( t.checking );

		return request( apiUrl( 'availability', params ) )
			.then( function ( data ) {
				self.stay = { check_in: data.check_in, check_out: data.check_out, nights: data.nights, nightsLabel: data.nights_label, adults: v.adults, children: v.children, ages: data.children_ages || [] };
				self.selected = null;
				self.view = null;
				self.closedNotice = data.notice || '';
				self.rooms = data.rooms;
				self.renderStay();
				self.renderStaySummary();
				self.renderRooms( data.rooms );
				if ( ! opts.fromUrl ) {
					self.setUrl( self.stayParams(), !! opts.replace );
				}
				if ( opts.focus ) {
					self.show( 'rooms', true );
				}
				track( {
					event: 'search',
					search_term: data.check_in + ' – ' + data.check_out,
					check_in: data.check_in,
					check_out: data.check_out,
					nights: data.nights,
					adults: parseInt( v.adults, 10 ),
					children: parseInt( v.children, 10 ) || 0,
				} );
				pixel( 'Search', { search_string: data.check_in + ' – ' + data.check_out } );
				if ( opts.replace && ! opts.focus ) {
					self.show( 'rooms' );
				}
				return true;
			} )
			.catch( function ( err ) {
				self.results.innerHTML = '';
				self.stay = null;
				self.show( 'dates' );
				self.setNotice( err.message, true );
				return false;
			} )
			.finally( function () {
				self.root.classList.remove( 'is-loading' );
			} );
	};

	/**
	 * Grey placeholders while searching, so the page doesn't jump.
	 */
	BookingForm.prototype.renderSkeleton = function () {
		this.results.innerHTML = '';
		var list = el( 'div', 'fb-rooms-loading' );
		list.setAttribute( 'aria-hidden', 'true' );
		for ( var i = 0; i < 2; i++ ) {
			var card = el( 'div', 'fb-skeleton-card' );
			card.appendChild( el( 'div', 'fb-skeleton fb-skeleton--image' ) );
			var body = el( 'div', 'fb-skeleton-card__body' );
			body.appendChild( el( 'div', 'fb-skeleton fb-skeleton--title' ) );
			body.appendChild( el( 'div', 'fb-skeleton fb-skeleton--line' ) );
			body.appendChild( el( 'div', 'fb-skeleton fb-skeleton--line' ) );
			card.appendChild( body );
			list.appendChild( card );
		}
		this.results.appendChild( list );
		if ( this.step === 'dates' || ! this.step ) {
			this.results.hidden = false;
		}
	};

	BookingForm.prototype.guestsText = function () {
		var s = this.stay;
		var adults = parseInt( s.adults, 10 ) || 1;
		var children = parseInt( s.children, 10 ) || 0;
		var text = fmt( adults === 1 ? t.adult : t.adults, adults );
		if ( children ) {
			text += ', ' + fmt( children === 1 ? t.child : t.childrenN, children );
			if ( s.ages && s.ages.length ) {
				text += ' ' + fmt( t.agesList, s.ages.map( function ( a ) {
					return a === 0 ? t.ageUnder1 : a;
				} ).join( ', ' ) );
			}
		}
		return text;
	};

	BookingForm.prototype.date = function ( ymd ) {
		return humanDate( ymd, this.dateFormat, this.locale );
	};

	BookingForm.prototype.rangeText = function () {
		var s = this.stay;
		return this.date( s.check_in ) + ' – ' + this.date( s.check_out );
	};

	/**
	 * "15.10.2026 – 18.10.2026 · 3 nights · 2 adults  [Change]"
	 */
	BookingForm.prototype.renderStay = function () {
		var self = this;
		var s = this.stay;
		this.stayBar.innerHTML = '';
		var text = el( 'p', 'fb-stay__text' );
		text.appendChild( el( 'strong', '', this.rangeText() ) );
		text.appendChild( document.createTextNode( ' · ' + nightsText( s.nights ) + ' · ' + this.guestsText() ) );
		this.stayBar.appendChild( text );
		var change = button( 'fb-link fb-stay__change', t.change );
		change.setAttribute( 'aria-label', t.changeSearch );
		change.addEventListener( 'click', function () {
			self.setNotice( '' );
			self.show( 'dates', true );
			if ( self.picker.enhanced ) {
				self.picker.open( 'in' );
			}
		} );
		this.stayBar.appendChild( change );
	};

	/**
	 * The summary before a room is chosen: the stay only.
	 */
	BookingForm.prototype.renderStaySummary = function () {
		if ( ! this.aside ) {
			return;
		}
		var summary = this.summary;
		summary.innerHTML = '';
		var dl = el( 'dl', 'fb-summary__stay' );
		[ [ t.dates, this.rangeText() + ' · ' + nightsText( this.stay.nights ) ], [ t.guests, this.guestsText() ] ].forEach( function ( row ) {
			var line = el( 'div', 'fb-summary__row' );
			line.appendChild( el( 'dt', '', row[ 0 ] ) );
			line.appendChild( el( 'dd', '', row[ 1 ] ) );
			dl.appendChild( line );
		} );
		summary.appendChild( dl );
		summary.appendChild( el( 'p', 'fb-summary__hint', t.chooseRoom ) );
		if ( this.asideShort ) {
			this.asideShort.textContent = this.rangeText();
		}
	};

	/* ---- Rooms and rates ---- */

	BookingForm.prototype.renderRooms = function ( rooms ) {
		var self = this;
		this.results.innerHTML = '';
		var available = rooms.filter( function ( r ) {
			return r.available;
		} );
		var title = el( 'h4', 'fb-results__title fb-step-title', t.chooseRoom );
		title.tabIndex = -1;
		this.results.appendChild( title );

		var list = el( 'div', 'fb-rooms' );
		// Rooms that can be booked first.
		available.concat( rooms.filter( function ( r ) {
			return ! r.available;
		} ) ).forEach( function ( room ) {
			list.appendChild( self.roomCard( room ) );
		} );
		this.results.appendChild( list );

		if ( ! available.length ) {
			this.renderNothing();
		} else {
			this.announce( available.length === 1 ? t.roomFound : fmt( t.roomsFound, available.length ) );
		}
		if ( this.room && ! this.showAll && ! available.length ) {
			this.results.appendChild( this.showAllButton( t.showAll ) );
		}
	};

	BookingForm.prototype.showAllButton = function ( label ) {
		var self = this;
		var more = button( 'fb-button fb-button--ghost fb-show-all', label );
		more.addEventListener( 'click', function () {
			self.showAll = true;
			self.search( { focus: true } );
		} );
		return more;
	};

	BookingForm.prototype.roomCard = function ( room ) {
		var self = this;
		var card = el( 'article', 'fb-room' + ( room.available ? '' : ' is-unavailable' ) );
		card.setAttribute( 'data-room-id', room.id );
		var titleId = uid( 'fb-room' );
		card.setAttribute( 'aria-labelledby', titleId );

		if ( room.image ) {
			var img = el( 'img', 'fb-room__image' );
			img.src = room.image;
			img.alt = fmt( t.roomPhoto, room.title );
			img.loading = 'lazy';
			img.decoding = 'async';
			card.appendChild( img );
		} else {
			var ph = el( 'div', 'fb-room__image fb-room__image--empty' );
			ph.setAttribute( 'aria-hidden', 'true' );
			card.appendChild( ph );
		}

		var body = el( 'div', 'fb-room__body' );
		var h = el( 'h4', 'fb-room__title', room.title );
		h.id = titleId;
		body.appendChild( h );
		var facts = el( 'ul', 'fb-room__facts' );
		if ( room.size ) {
			facts.appendChild( el( 'li', '', fmt( t.sqm, room.size ) ) );
		}
		if ( room.beds ) {
			facts.appendChild( el( 'li', '', room.beds ) );
		}
		facts.appendChild( el( 'li', '', fmt( t.upTo, room.capacity ) ) );
		body.appendChild( facts );
		var amenities = room.amenities || [];
		if ( amenities.length ) {
			var am = el( 'ul', 'fb-room__amenities' );
			amenities.slice( 0, 5 ).forEach( function ( a ) {
				am.appendChild( el( 'li', '', a ) );
			} );
			if ( amenities.length > 5 ) {
				var more = el( 'li', 'fb-room__amenities-more', fmt( t.moreAmenities, amenities.length - 5 ) );
				more.title = amenities.slice( 5 ).join( ', ' );
				am.appendChild( more );
			}
			body.appendChild( am );
		}
		if ( room.excerpt ) {
			body.appendChild( el( 'p', 'fb-room__excerpt', room.excerpt ) );
		}
		if ( room.available && room.units_left > 0 && room.units_left <= 2 ) {
			body.appendChild( el( 'p', 'fb-room__urgency', fmt( t.onlyLeft, room.units_left ) ) );
		}
		if ( ! room.available && room.reason ) {
			body.appendChild( el( 'p', 'fb-room__reason', room.reason ) );
		}
		card.appendChild( body );

		var side = el( 'div', 'fb-room__side' );
		side.appendChild( el( 'span', 'fb-room__total-label', this.stay.nightsLabel ) );
		side.appendChild( el( 'strong', 'fb-room__total', room.price_from ? fmt( t.from, room.total_formatted ) : room.total_formatted ) );
		var nightly = room.price_varies && t.avgPerNight
			? fmt( t.avgPerNight, room.price_average_formatted )
			: ( room.price_average_formatted || room.price_formatted ) + ' ' + t.perNight;
		side.appendChild( el( 'span', 'fb-room__meta fb-room__avg', nightly ) );
		var plans = room.plans || [];
		var btn = button( 'fb-button', room.available ? ( plans.length > 1 ? t.chooseRate : t.select ) : t.unavailable );
		btn.disabled = ! room.available;
		if ( room.available ) {
			btn.setAttribute( 'aria-label', ( plans.length > 1 ? t.chooseRate : t.select ) + ': ' + room.title );
		}
		if ( plans.length > 1 ) {
			btn.setAttribute( 'aria-expanded', 'false' );
		}
		btn.addEventListener( 'click', function () {
			if ( plans.length > 1 ) {
				self.showPlans( card, room, true );
			} else {
				self.select( room, plans[ 0 ] || room.quote || null );
			}
		} );
		side.appendChild( btn );
		card.appendChild( side );
		return card;
	};

	/**
	 * "Breakfast included · ✓ Free cancellation" – rates compared at a glance.
	 */
	function planTerms( rate ) {
		var parts = [];
		if ( rate.meals_label ) {
			parts.push( rate.meals_label );
		}
		parts.push( ( rate.refundable ? '✓ ' : '✕ ' ) + rate.refundable_label );
		return parts.join( ' · ' );
	}

	/**
	 * Rooms with several rate plans: the guest chooses one inside the card.
	 */
	BookingForm.prototype.showPlans = function ( card, room, focus ) {
		var self = this;
		var toggle = card.querySelector( '.fb-room__side .fb-button' );
		var open = card.querySelector( '.fb-plans' );
		if ( open ) {
			open.remove();
			toggle.setAttribute( 'aria-expanded', 'false' );
			return;
		}
		var box = el( 'div', 'fb-plans' );
		box.id = uid( 'fb-plans' );
		toggle.setAttribute( 'aria-controls', box.id );
		toggle.setAttribute( 'aria-expanded', 'true' );
		box.appendChild( el( 'h5', 'fb-plans__title', t.chooseRate ) );
		room.plans.forEach( function ( plan ) {
			var rate = plan.rate_plan || {};
			var row = el( 'div', 'fb-plan' );
			var info = el( 'div', 'fb-plan__info' );
			info.appendChild( el( 'strong', 'fb-plan__name', rate.name ) );
			info.appendChild( el( 'span', 'fb-plan__policy' + ( rate.refundable ? '' : ' is-strict' ), planTerms( rate ) ) );
			if ( rate.description ) {
				info.appendChild( el( 'span', 'fb-plan__desc', rate.description ) );
			}
			row.appendChild( info );
			var price = el( 'div', 'fb-plan__price' );
			price.appendChild( el( 'strong', '', plan.total_formatted ) );
			var choose = button( 'fb-button', t.choose );
			choose.setAttribute( 'aria-label', t.choose + ': ' + rate.name + ', ' + plan.total_formatted );
			choose.addEventListener( 'click', function () {
				self.select( room, plan );
			} );
			price.appendChild( choose );
			row.appendChild( price );
			box.appendChild( row );
		} );
		card.appendChild( box );
		if ( focus ) {
			box.querySelector( 'button' ).focus();
		}
	};

	/* ---- Nothing free: nearby dates, other rooms, enquiry ---- */

	BookingForm.prototype.renderNothing = function () {
		var self = this;
		var box = el( 'section', 'fb-nothing' );
		var h = el( 'h4', 'fb-nothing__title', t.nothingTitle );
		h.id = uid( 'fb-nothing' );
		box.setAttribute( 'aria-labelledby', h.id );
		box.appendChild( h );
		box.appendChild( el( 'p', 'fb-nothing__text', this.closedNotice || t.noRooms ) );
		var alt = el( 'div', 'fb-nothing__alternatives' );
		alt.appendChild( el( 'p', 'fb-nothing__loading', t.loading ) );
		box.appendChild( alt );

		var ask = el( 'div', 'fb-nothing__ask' );
		ask.appendChild( el( 'p', '', t.askUs ) );
		var open = button( 'fb-button fb-button--ghost', t.sendEnquiry );
		open.setAttribute( 'aria-expanded', 'false' );
		ask.appendChild( open );
		box.appendChild( ask );
		open.addEventListener( 'click', function () {
			var form = box.querySelector( '.fb-enquiry' ) || self.enquiryForm( box );
			form.hidden = ! form.hidden;
			open.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );
			if ( ! form.hidden ) {
				form.querySelector( 'input' ).focus();
			}
		} );
		this.results.appendChild( box );
		this.announce( t.nothingTitle );

		var s = this.stay;
		var params = { check_in: s.check_in, check_out: s.check_out, adults: s.adults, children: s.children || 0 };
		if ( s.ages && s.ages.length ) {
			params.children_ages = s.ages.join( ',' );
		}
		if ( this.room && ! this.showAll ) {
			params.room = this.room;
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		request( apiUrl( 'alternatives', params ) )
			.then( function ( data ) {
				alt.innerHTML = '';
				if ( data.dates && data.dates.length ) {
					alt.appendChild( el( 'h5', 'fb-nothing__subtitle', t.nearbyDates ) );
					var ul = el( 'ul', 'fb-alternatives' );
					data.dates.forEach( function ( d ) {
						var li = el( 'li' );
						var b = button( 'fb-alt' );
						b.appendChild( el( 'strong', '', shortDate( d.check_in, self.locale ) + ' – ' + shortDate( d.check_out, self.locale ) ) );
						b.appendChild( el( 'span', '', ( d.rooms === 1 ? t.oneRoom : fmt( t.nRooms, d.rooms ) ) + ' · ' + d.from ) );
						b.addEventListener( 'click', function () {
							self.useDates( d.check_in, d.check_out );
						} );
						li.appendChild( b );
						ul.appendChild( li );
					} );
					alt.appendChild( ul );
				}
				if ( data.other_rooms > 0 && ! self.results.querySelector( '.fb-show-all' ) ) {
					alt.appendChild( self.showAllButton( data.other_rooms === 1 ? t.otherRoom : fmt( t.otherRooms, data.other_rooms ) ) );
				} else if ( data.other_rooms > 0 ) {
					self.results.querySelector( '.fb-show-all' ).textContent = data.other_rooms === 1 ? t.otherRoom : fmt( t.otherRooms, data.other_rooms );
				}
			} )
			.catch( function () {
				alt.innerHTML = '';
			} );
	};

	/**
	 * A nearby stay from the suggestions: search it like a new search.
	 */
	BookingForm.prototype.useDates = function ( checkIn, checkOut ) {
		this.searchForm.querySelector( '[name="check_in"]' ).value = checkIn;
		this.searchForm.querySelector( '[name="check_out"]' ).value = checkOut;
		if ( this.picker.enhanced ) {
			this.picker.sync();
		}
		this.search( { focus: true } );
	};

	function field( form, opts ) {
		var wrap = el( 'div', 'fb-field' + ( opts.wide ? ' fb-field--wide' : '' ) );
		var id = uid( 'fb-enq' );
		var label = el( 'label', '', opts.label + ' ' );
		label.htmlFor = id;
		if ( opts.required ) {
			var req = el( 'span', 'fb-req', '*' );
			req.setAttribute( 'aria-hidden', 'true' );
			label.appendChild( req );
		} else {
			label.appendChild( el( 'span', 'fb-optional', t.optional ) );
		}
		var input = el( opts.tag || 'input' );
		input.id = id;
		input.name = opts.name;
		if ( opts.type ) {
			input.type = opts.type;
		}
		if ( opts.autocomplete ) {
			input.setAttribute( 'autocomplete', opts.autocomplete );
		}
		if ( opts.inputmode ) {
			input.setAttribute( 'inputmode', opts.inputmode );
		}
		if ( opts.placeholder ) {
			input.placeholder = opts.placeholder;
		}
		if ( opts.tag === 'textarea' ) {
			input.rows = 4;
		}
		input.required = !! opts.required;
		wrap.appendChild( label );
		wrap.appendChild( input );
		form.appendChild( wrap );
		return input;
	}

	/**
	 * "Send us an enquiry": emailed to the hotel, not a booking.
	 */
	BookingForm.prototype.enquiryForm = function ( box ) {
		var self = this;
		var form = el( 'form', 'fb-enquiry' );
		form.noValidate = true;
		form.appendChild( el( 'h5', 'fb-enquiry__title', t.enquiryTitle ) );
		form.appendChild( el( 'p', 'fb-enquiry__note', t.enquiryNote ) );
		var grid = el( 'div', 'fb-grid' );
		form.appendChild( grid );
		field( grid, { name: 'name', label: t.yourName, required: true, autocomplete: 'name' } );
		field( grid, { name: 'email', label: t.email, required: true, type: 'email', autocomplete: 'email', inputmode: 'email' } );
		var phone = field( grid, { name: 'phone', label: t.phone, type: 'tel', autocomplete: 'tel', inputmode: 'tel' } );
		var cc = this.detailsForm.querySelector( '.fb-phone__cc' );
		if ( cc ) {
			var wrap = el( 'div', 'fb-phone' );
			var copy = cc.cloneNode( true );
			copy.querySelector( 'select' ).id = uid( 'fb-enq-cc' );
			copy.querySelector( 'select' ).value = cc.querySelector( 'select' ).value;
			phone.parentNode.insertBefore( wrap, phone );
			wrap.appendChild( copy );
			wrap.appendChild( phone );
		}
		field( grid, { name: 'message', label: t.message, tag: 'textarea', wide: true, placeholder: t.enquiryPlaceholder } );
		if ( cfg.consent ) {
			var consent = el( 'label', 'fb-terms fb-consent' );
			var box2 = el( 'input' );
			box2.type = 'checkbox';
			box2.name = 'privacy_consent';
			box2.value = '1';
			box2.required = !! cfg.consent.required;
			box2.setAttribute( 'data-fb-message', t.consentMessage );
			consent.appendChild( box2 );
			var span = el( 'span' );
			span.innerHTML = ' ' + cfg.consent.html; // Built and escaped on the server.
			consent.appendChild( span );
			form.appendChild( consent );
		}
		var hp = el( 'div', 'fb-hp' );
		hp.setAttribute( 'aria-hidden', 'true' );
		var hpInput = el( 'input' );
		hpInput.type = 'text';
		hpInput.name = 'fb_website';
		hpInput.tabIndex = -1;
		hpInput.autocomplete = 'off';
		hp.appendChild( hpInput );
		form.appendChild( hp );
		var actions = el( 'div', 'fb-actions' );
		var submit = el( 'button', 'fb-button', t.sendMessage );
		submit.type = 'submit';
		actions.appendChild( submit );
		form.appendChild( actions );
		var status = el( 'p', 'fb-enquiry__status' );
		status.setAttribute( 'role', 'status' );
		form.appendChild( status );
		form.hidden = true;
		box.appendChild( form );
		bindValidation( form );
		this.bindPhone();

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( form._sending ) {
				return;
			}
			var bad = validateForm( form );
			if ( bad ) {
				focusEl( bad );
				return;
			}
			var get = function ( name ) {
				var f = form.querySelector( '[name="' + name + '"]' );
				return f ? ( f.type === 'checkbox' ? f.checked : f.value ) : '';
			};
			var s = self.stay || {};
			form._sending = true;
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
			status.textContent = t.sending;
			postJson( 'enquiry', {
				name: get( 'name' ),
				email: get( 'email' ),
				phone: get( 'phone' ),
				phone_country: get( 'phone_country' ),
				message: get( 'message' ),
				check_in: s.check_in || '',
				check_out: s.check_out || '',
				adults: s.adults || 1,
				children: s.children || 0,
				room: self.room && ! self.showAll ? self.room : '',
				locale: self.locale,
				privacy_consent: get( 'privacy_consent' ),
				fb_website: get( 'fb_website' ),
			} )
				.then( function ( data ) {
					form.innerHTML = '';
					var done = el( 'p', 'fb-enquiry__done', data.message );
					done.setAttribute( 'role', 'status' );
					form.appendChild( done );
					focusEl( done );
				} )
				.catch( function ( err ) {
					var target = err.data && err.data.field ? form.querySelector( '[name="' + err.data.field + '"]' ) : null;
					if ( target ) {
						setError( target, err.message );
						focusEl( target );
						status.textContent = '';
					} else {
						status.textContent = err.message;
					}
				} )
				.finally( function () {
					form._sending = false;
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				} );
		} );
		return form;
	};

	/* ---- Room chosen: details ---- */

	/**
	 * Tracking data for the chosen room and plan (no personal data).
	 */
	BookingForm.prototype.trackData = function ( total ) {
		var room = this.selected;
		var plan = this.view && this.view.rate_plan ? this.view.rate_plan.name : '';
		var currency = cfg.tracking ? cfg.tracking.currency : '';
		return {
			room: room.title,
			rate_plan: plan,
			value: total,
			currency: currency,
			ecommerce: {
				currency: currency,
				value: total,
				items: [ { item_id: room.slug || String( room.id ), item_name: room.title, item_variant: plan, price: total, quantity: 1 } ],
			},
		};
	};

	BookingForm.prototype.select = function ( room, view, opts ) {
		opts = opts || {};
		this.selected = room;
		this.view = view || room.quote || null;
		this.promo = '';
		this.promoError = '';
		this.setNotice( '' );
		this.renderSummary();
		if ( ! opts.restore ) {
			this.setUrl( this.detailsParams(), false );
		}
		this.show( 'details', opts.restore ? !! opts.focus : true );

		var total = this.view && typeof this.view.total === 'number' ? this.view.total : room.total;
		track( Object.assign( { event: 'room_select' }, this.trackData( total ) ), true );
		track( Object.assign( { event: 'begin_checkout' }, this.trackData( total ) ), true );
		pixel( 'InitiateCheckout', { value: total, currency: cfg.tracking ? cfg.tracking.currency : '' } );
	};

	/**
	 * booking_complete, once per booking reference (a reload or a second
	 * form never counts it twice). Calls done() when Tag Manager has
	 * received it, or after 1.5 s without Tag Manager.
	 */
	BookingForm.prototype.trackComplete = function ( data, done ) {
		var key = 'flexo_tracked_' + data.reference;
		if ( ! cfg.tracking || storageGet( key ) || ! this.selected ) {
			done();
			return;
		}
		storageSet( key, '1' );
		var total = data.quote && typeof data.quote.total === 'number' ? data.quote.total : 0;
		var payload = Object.assign( { event: 'booking_complete', booking_reference: data.reference, booking_status: data.status }, this.trackData( total ) );
		payload.ecommerce.transaction_id = data.reference;
		var finished = false;
		function finish() {
			if ( ! finished ) {
				finished = true;
				done();
			}
		}
		payload.eventCallback = finish;
		payload.eventTimeout = 1500;
		track( payload, true );
		pixel( 'Purchase', { value: total, currency: payload.currency } );
		window.setTimeout( finish, 1600 );
	};

	/**
	 * The itemised price the guest confirms: room, rate plan, nights, each
	 * price line, discount, tourist tax and the total.
	 */
	BookingForm.prototype.renderSummary = function () {
		var room = this.selected;
		var view = this.view || {};
		var s = this.stay;
		var summary = this.summary;
		summary.innerHTML = '';

		function row( label, value, className ) {
			var line = el( 'div', 'fb-summary__row' + ( className ? ' ' + className : '' ) );
			line.appendChild( el( 'span', '', label ) );
			line.appendChild( el( 'span', '', value || '' ) );
			summary.appendChild( line );
			return line;
		}

		row( room.title, view.total_formatted || room.total_formatted, 'fb-summary__room' );
		if ( view.rate_plan ) {
			row( t.rate, view.rate_plan.name + ( view.rate_plan.meals_label ? ' · ' + view.rate_plan.meals_label : '' ), 'fb-summary__rate' );
		}
		row( this.date( s.check_in ) + ' → ' + this.date( s.check_out ), s.nightsLabel );
		row( t.guests, this.guestsText() );

		var lines = view.lines || room.breakdown || [];
		var itemised = lines.length > 1 || lines.some( function ( l ) {
			return l.details && l.details.length;
		} );
		if ( itemised ) {
			var list = el( 'div', 'fb-summary__lines' );
			lines.forEach( function ( item ) {
				var line = el( 'div', 'fb-summary__row fb-summary__line fb-summary__line--' + item.type );
				line.appendChild( el( 'span', '', item.label ) );
				line.appendChild( el( 'span', '', item.formatted ) );
				list.appendChild( line );
				( item.details || [] ).forEach( function ( detail ) {
					list.appendChild( el( 'div', 'fb-summary__detail', detail ) );
				} );
			} );
			summary.appendChild( list );
		}

		if ( view.discount_total > 0 ) {
			row( t.subtotal, view.subtotal_formatted, 'fb-summary__subtotal' );
			row( t.discount, view.discount_formatted, 'fb-summary__discount' );
		}
		if ( itemised || view.discount_total > 0 ) {
			row( view.discount_total > 0 ? t.finalTotal : t.total, view.total_formatted, 'fb-summary__total' );
		}
		var pay = view.payment || null;
		if ( pay && pay.now > 0 ) {
			row( pay.now_label + ' – ' + t.toPayNow.toLowerCase(), pay.now_formatted, 'fb-summary__paynow' );
			if ( pay.at_property > 0 ) {
				row( t.atPropertyRest, pay.at_property_formatted, 'fb-summary__property' );
			}
		} else if ( pay && pay.mode === 'property' ) {
			row( t.payAtProperty, t.payAtPropertyText + ( pay.at_property > 0 ? ' · ' + pay.at_property_formatted : '' ), 'fb-summary__property' );
		} else if ( view.due_at_property > 0 ) {
			row( t.atProperty, view.due_at_property_formatted, 'fb-summary__property' );
		}
		if ( this.actionsTotal ) {
			this.actionsTotal.innerHTML = '';
			var payNow = pay && pay.now > 0;
			this.actionsTotal.appendChild( el( 'span', '', payNow ? t.toPayNow : t.total ) );
			this.actionsTotal.appendChild( el( 'strong', '', payNow ? pay.now_formatted : ( view.total_formatted || room.total_formatted ) ) );
		}
		if ( view.rate_plan && view.rate_plan.cancellation_policy ) {
			var policy = el( 'p', 'fb-summary__policy' );
			policy.appendChild( el( 'strong', '', t.cancellation + ': ' ) );
			policy.appendChild( document.createTextNode( view.rate_plan.cancellation_policy ) );
			summary.appendChild( policy );
		}

		if ( this.promoSlot ) {
			this.promoSlot.innerHTML = '';
		}
		if ( cfg.promo ) {
			var inForm = this.aside && this.promoSlot && window.innerWidth <= 767 && ! this.root.classList.contains( 'fb-wide' );
			this.renderPromo( inForm ? this.promoSlot : summary, view );
		}
		if ( this.asideShort ) {
			this.asideShort.textContent = room.title + ' · ' + ( view.total_formatted || room.total_formatted );
		}
		this.updateSubmit();
		this.renderBefore();
	};

	/**
	 * "Before you book": cancellation, what is paid now and at the
	 * property, and how to reach the hotel – right above the final button.
	 */
	BookingForm.prototype.renderBefore = function () {
		var box = this.before;
		if ( ! box || ! this.selected ) {
			return;
		}
		var view = this.view || {};
		var pay = view.payment || null;
		box.innerHTML = '';
		box.appendChild( el( 'h5', 'fb-before__title', t.beforeYouBook ) );
		var ul = el( 'ul', 'fb-before__list' );
		if ( view.rate_plan ) {
			ul.appendChild( el( 'li', '', t.cancellation + ': ' + ( view.rate_plan.cancellation_policy || view.rate_plan.refundable_label ) ) );
		}
		var method = this.paymentMethod();
		if ( pay && pay.now > 0 && method ) {
			ul.appendChild( el( 'li', '', t.payNowText + ': ' + pay.now_formatted + ( pay.at_property > 0 ? ' · ' + t.payLaterText + ': ' + pay.at_property_formatted : '' ) ) );
		} else if ( ! cfg.instant ) {
			ul.appendChild( el( 'li', '', fmt( t.requestNote, cfg.replyTime || '' ) ) );
		} else {
			ul.appendChild( el( 'li', '', t.nothingNow ) );
		}
		var c = cfg.contact || {};
		if ( c.phone || c.email ) {
			var li = el( 'li', 'fb-before__contact' );
			li.appendChild( document.createTextNode( t.questions + ': ' ) );
			if ( c.phone ) {
				var tel = el( 'a', '', c.phone );
				tel.href = c.phone_link;
				li.appendChild( tel );
			}
			if ( c.phone && c.email ) {
				li.appendChild( document.createTextNode( ' · ' ) );
			}
			if ( c.email ) {
				var mail = el( 'a', '', c.email );
				mail.href = 'mailto:' + c.email;
				li.appendChild( mail );
			}
			ul.appendChild( li );
		}
		box.appendChild( ul );
		box.hidden = false;
	};

	/**
	 * "Have a promo code?" – collapsed until the guest asks for it.
	 */
	BookingForm.prototype.renderPromo = function ( summary, view ) {
		var self = this;
		var box = el( 'div', 'fb-promo' );
		var message = el( 'p', 'fb-promo__message' );
		message.setAttribute( 'role', 'status' );

		if ( view.promo ) {
			message.textContent = String( t.promoApplied ).replace( '%1$s', view.promo.code ).replace( '%2$s', view.promo.label );
			box.appendChild( message );
			var remove = button( 'fb-link', t.remove );
			remove.addEventListener( 'click', function () {
				self.applyPromo( '' );
			} );
			box.appendChild( remove );
			summary.appendChild( box );
			return;
		}

		var toggle = button( 'fb-link fb-promo__toggle', t.havePromo );
		toggle.setAttribute( 'aria-expanded', 'false' );
		var fields = el( 'div', 'fb-promo__fields' );
		fields.hidden = true;
		var id = uid( 'fb-promo' );
		toggle.setAttribute( 'aria-controls', id + '-box' );
		fields.id = id + '-box';
		var label = el( 'label', 'fb-promo__label', t.promoLabel );
		label.htmlFor = id;
		var input = el( 'input' );
		input.type = 'text';
		input.id = id;
		input.autocomplete = 'off';
		input.setAttribute( 'autocapitalize', 'characters' );
		input.value = this.promoTried || '';
		var apply = button( 'fb-button fb-button--ghost', t.apply );
		var row = el( 'div', 'fb-promo__row' );
		row.appendChild( input );
		row.appendChild( apply );
		fields.appendChild( label );
		fields.appendChild( row );

		toggle.addEventListener( 'click', function () {
			fields.hidden = ! fields.hidden;
			toggle.setAttribute( 'aria-expanded', fields.hidden ? 'false' : 'true' );
			if ( ! fields.hidden ) {
				input.focus();
			}
		} );
		function submit() {
			if ( input.value.trim() ) {
				self.applyPromo( input.value.trim() );
			}
		}
		apply.addEventListener( 'click', submit );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				submit();
			}
		} );

		box.appendChild( toggle );
		box.appendChild( fields );
		if ( this.promoError ) {
			fields.hidden = false;
			toggle.setAttribute( 'aria-expanded', 'true' );
			message.textContent = this.promoError;
			message.classList.add( 'is-error' );
			fields.appendChild( message );
		}
		summary.appendChild( box );
	};

	BookingForm.prototype.quoteParams = function ( promo ) {
		var s = this.stay;
		var params = {
			room: this.selected.slug || String( this.selected.id ),
			check_in: s.check_in,
			check_out: s.check_out,
			adults: s.adults,
			children: s.children || 0,
		};
		if ( s.ages && s.ages.length ) {
			params.children_ages = s.ages.join( ',' );
		}
		if ( this.view && this.view.rate_plan ) {
			params.rate_plan = this.view.rate_plan.id;
		}
		if ( promo ) {
			params.promo_code = promo;
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		return params;
	};

	/**
	 * Re-prices the stay on the server with (or without) a promo code.
	 */
	BookingForm.prototype.applyPromo = function ( code ) {
		var self = this;
		this.promoTried = code;
		this.promoError = '';
		return request( apiUrl( 'quote', this.quoteParams( code ) ) )
			.then( function ( view ) {
				if ( code && view.promo_error ) {
					self.promoError = view.promo_error;
				} else {
					self.view = view;
					self.promo = view.promo ? view.promo.code : '';
				}
				self.renderSummary();
				self.setUrl( self.detailsParams(), true );
			} )
			.catch( function ( err ) {
				self.promoError = err.message;
				self.renderSummary();
			} );
	};

	BookingForm.prototype.back = function () {
		var q = new URLSearchParams( window.location.search );
		if ( this.useUrl && q.get( 'fb_step' ) === 'details' && window.history.state && window.history.state.fb ) {
			window.history.back();
			return;
		}
		this.setUrl( this.stayParams(), true );
		this.setNotice( '' );
		this.show( 'rooms', true );
	};

	/* ---- Booking ---- */

	var FIELD_FOR_CODE = {
		flexo_missing_name: 'guest_name',
		flexo_invalid_email: 'guest_email',
		flexo_missing_phone: 'guest_phone',
		flexo_invalid_phone: 'guest_phone',
		flexo_terms: 'terms',
		flexo_consent: 'privacy_consent',
	};

	BookingForm.prototype.onBook = function ( e ) {
		e.preventDefault();
		var self = this;
		var f = this.detailsForm;
		// One booking per click: a second click or Enter does nothing.
		if ( this.sending ) {
			return;
		}
		var bad = validateForm( f );
		if ( bad ) {
			this.setNotice( t.checkFields, true );
			focusEl( bad );
			return;
		}

		var terms = f.querySelector( '[name="terms"]' );
		var consent = f.querySelector( '[name="privacy_consent"]' );
		function val( name ) {
			var field = f.querySelector( '[name="' + name + '"]' );
			return field && ! field.disabled ? field.value : '';
		}
		var invoice = null;
		var invoiceToggle = f.querySelector( '[data-fb-invoice-toggle]' );
		if ( invoiceToggle && invoiceToggle.checked ) {
			var type = f.querySelector( '[name="invoice_type"]:checked' );
			invoice = { requested: true, type: type ? type.value : 'individual' };
			f.querySelectorAll( '[name^="invoice_"]' ).forEach( function ( field ) {
				if ( field.type === 'text' && ! field.disabled ) {
					invoice[ field.name.replace( 'invoice_', '' ) ] = field.value;
				}
			} );
		}
		var payload = {
			room: this.selected.slug || String( this.selected.id ),
			check_in: this.stay.check_in,
			check_out: this.stay.check_out,
			adults: parseInt( this.stay.adults, 10 ),
			children: parseInt( this.stay.children, 10 ) || 0,
			children_ages: ( this.stay.ages || [] ).join( ',' ),
			rate_plan: this.view && this.view.rate_plan ? this.view.rate_plan.id : 0,
			promo_code: this.promo || '',
			// The server refuses the booking if its price differs from this.
			expected_total: this.view && typeof this.view.total === 'number' ? this.view.total : null,
			guest_name: f.querySelector( '[name="guest_name"]' ).value,
			guest_email: f.querySelector( '[name="guest_email"]' ).value,
			guest_phone: val( 'guest_phone' ),
			phone_country: val( 'phone_country' ),
			notes: val( 'notes' ),
			terms: terms ? terms.checked : false,
			privacy_consent: consent ? consent.checked : false,
			invoice: invoice,
			locale: this.locale,
			fb_website: f.querySelector( '[name="fb_website"]' ).value,
			// Only the method: amounts are always calculated by the server.
			payment_method: this.paymentMethod(),
			return_url: this.returnUrl(),
		};

		var submit = f.querySelector( '[type="submit"]' );
		var label = submit.textContent;
		this.sending = true;
		submit.disabled = true;
		submit.setAttribute( 'aria-busy', 'true' );
		submit.classList.add( 'is-busy' );
		submit.textContent = t.sending;
		this.setNotice( '' );

		postJson( 'bookings', payload )
			.then( function ( data ) {
				if ( data.payment && data.payment.redirect ) {
					// Card: continue on the secure payment page.
					self.leaving = true;
					self.setNotice( t.redirecting );
					window.location.href = data.payment.redirect;
					return;
				}
				if ( data.redirect ) {
					// Leave the page only after the conversion was recorded.
					self.leaving = true;
					self.trackComplete( data, function () {
						window.location.href = data.redirect;
					} );
					return;
				}
				if ( data.view ) {
					guestKeySet( data.reference, data.key );
					self.setUrl( { fb_done: data.reference }, true );
					self.showDone( data.view, false );
				} else {
					self.showSuccess( data );
				}
				self.trackComplete( data, function () {} );
			} )
			.catch( function ( err ) {
				var name = err.data && err.data.field ? err.data.field : FIELD_FOR_CODE[ err.code ];
				var target = name ? f.querySelector( '[name="' + name + '"]' ) : null;
				if ( target && isShown( target ) ) {
					setError( target, err.message );
					self.setNotice( t.checkFields, true );
					focusEl( target );
				} else {
					self.setNotice( err.message, true );
					focusEl( self.notice );
				}
				// Show the current price (or why the promo code no longer applies).
				if ( err.code === 'flexo_price_changed' || ( err.code && err.code.indexOf( 'flexo_promo' ) === 0 ) ) {
					self.applyPromo( err.code === 'flexo_price_changed' ? self.promo : '' );
				}
			} )
			.finally( function () {
				if ( self.leaving ) {
					return;
				}
				self.sending = false;
				submit.disabled = false;
				submit.removeAttribute( 'aria-busy' );
				submit.classList.remove( 'is-busy' );
				submit.textContent = label;
			} );
	};

	/**
	 * The page to come back to from the payment page: this page without
	 * the step in the address.
	 */
	BookingForm.prototype.returnUrl = function () {
		var url = new URL( window.location.href.split( '#' )[ 0 ] );
		STATE_KEYS.forEach( function ( k ) {
			url.searchParams.delete( k );
		} );
		return url.toString();
	};

	/**
	 * Fallback confirmation (bookings made before the full view existed).
	 */
	BookingForm.prototype.showSuccess = function ( data ) {
		this.success.innerHTML = '';
		this.success.appendChild( el( 'p', 'fb-success__message', data.message ) );
		var ref = el( 'p', 'fb-success__reference' );
		ref.appendChild( document.createTextNode( t.reference + ': ' ) );
		ref.appendChild( el( 'strong', '', data.reference ) );
		this.success.appendChild( ref );
		this.success.appendChild( el( 'p', 'fb-success__stay', data.room + ' · ' + this.date( data.check_in ) + ' → ' + this.date( data.check_out ) + ' · ' + data.total_formatted ) );
		if ( data.payment ) {
			this.renderPayment( data.payment );
		}
		this.show( 'done', true );
	};

	function section( parent, title, className ) {
		var box = el( 'section', 'fb-done__section' + ( className ? ' ' + className : '' ) );
		var h = el( 'h4', 'fb-done__subtitle', title );
		h.id = uid( 'fb-sec' );
		box.setAttribute( 'aria-labelledby', h.id );
		box.appendChild( h );
		parent.appendChild( box );
		return box;
	}

	function dlRow( dl, label, value, className ) {
		var row = el( 'div', 'fb-done__row' + ( className ? ' ' + className : '' ) );
		row.appendChild( el( 'dt', '', label ) );
		row.appendChild( el( 'dd', '', value ) );
		dl.appendChild( row );
	}

	/**
	 * The confirmation: reference, the whole booking, what happens next,
	 * the hotel's contact details, add to calendar and directions. Also the
	 * guest booking page (manage = true).
	 */
	BookingForm.prototype.showDone = function ( v, manage ) {
		var self = this;
		var box = this.success;
		box.innerHTML = '';
		box.setAttribute( 'data-state', v.state );
		var titles = { confirmed: t.doneConfirmed, request: t.doneRequest, awaiting_transfer: t.doneTransfer };
		var head = el( 'div', 'fb-done__head fb-done__head--' + v.state );
		if ( ! manage && titles[ v.state ] ) {
			var icon = el( 'span', 'fb-done__icon', '✓' );
			icon.setAttribute( 'aria-hidden', 'true' );
			head.appendChild( icon );
		}
		var title = el( 'h3', 'fb-done__title', manage ? t.yourBooking : ( titles[ v.state ] || t.doneOther ) );
		title.tabIndex = -1;
		head.appendChild( title );
		box.appendChild( head );
		if ( manage ) {
			var status = el( 'p', 'fb-done__status' );
			status.appendChild( document.createTextNode( t.status + ': ' ) );
			status.appendChild( el( 'strong', 'fb-badge fb-badge--' + v.state, v.state_label ) );
			box.appendChild( status );
		} else {
			box.appendChild( el( 'p', 'fb-success__message', v.message ) );
		}
		var ref = el( 'p', 'fb-success__reference' );
		ref.appendChild( document.createTextNode( t.reference + ': ' ) );
		ref.appendChild( el( 'strong', '', v.reference ) );
		box.appendChild( ref );
		box.appendChild( el( 'p', 'fb-success__stay', v.room + ' · ' + v.check_in_text + ' → ' + v.check_out_text + ' · ' + v.total_formatted ) );

		if ( v.payment ) {
			this.renderPayment( v.payment );
		}

		var details = section( box, t.yourBooking, 'fb-done__booking' );
		var dl = el( 'dl', 'fb-done__list' );
		dlRow( dl, t.room, v.room + ( v.rate ? ' · ' + v.rate.name + ( v.rate.meals ? ' · ' + v.rate.meals : '' ) : '' ) );
		dlRow( dl, t.dates, v.check_in_text + ' → ' + v.check_out_text + ' · ' + v.nights_label );
		dlRow( dl, t.guests, v.guests );
		var q = v.quote || {};
		( q.lines || [] ).forEach( function ( line ) {
			if ( ( q.lines || [] ).length > 1 ) {
				dlRow( dl, line.label, line.formatted, 'fb-done__line' );
			}
		} );
		if ( q.discount_total > 0 ) {
			dlRow( dl, t.discount, q.discount_formatted, 'fb-done__line' );
		}
		dlRow( dl, t.total, v.total_formatted, 'fb-done__total' );
		if ( v.payment && v.payment.paid_formatted ) {
			dlRow( dl, t.paid, v.payment.paid_formatted );
		}
		if ( v.payment && v.payment.at_property_formatted && [ 'confirmed' ].indexOf( v.state ) !== -1 ) {
			dlRow( dl, t.atPropertyRest, v.payment.at_property_formatted );
		}
		if ( v.rate && v.rate.cancellation ) {
			dlRow( dl, t.cancellation, v.rate.cancellation );
		}
		details.appendChild( dl );

		if ( v.next_steps && v.next_steps.length && ! manage ) {
			var next = section( box, t.nextSteps, 'fb-done__next' );
			var ul = el( 'ul' );
			v.next_steps.forEach( function ( s ) {
				ul.appendChild( el( 'li', '', s ) );
			} );
			next.appendChild( ul );
		}

		var c = v.contact || {};
		if ( c.phone || c.email || c.address ) {
			var contact = section( box, t.contactUs, 'fb-done__contact' );
			var p = el( 'p' );
			if ( c.phone ) {
				var tel = el( 'a', '', c.phone );
				tel.href = c.phone_link;
				p.appendChild( tel );
			}
			if ( c.email ) {
				if ( p.childNodes.length ) {
					p.appendChild( document.createTextNode( ' · ' ) );
				}
				var mail = el( 'a', '', c.email );
				mail.href = 'mailto:' + c.email;
				p.appendChild( mail );
			}
			if ( p.childNodes.length ) {
				contact.appendChild( p );
			}
			if ( c.address ) {
				var addr = el( 'p', 'fb-done__address', c.address );
				contact.appendChild( addr );
			}
		}

		var actions = el( 'div', 'fb-actions fb-actions--result' );
		if ( v.ics ) {
			var ics = el( 'a', 'fb-button fb-button--ghost fb-done__ics', t.addCalendar );
			ics.href = v.ics;
			ics.setAttribute( 'download', 'booking-' + v.reference + '.ics' );
			actions.appendChild( ics );
		}
		if ( c.directions ) {
			var dir = el( 'a', 'fb-button fb-button--ghost fb-done__directions', t.directions );
			dir.href = c.directions;
			dir.target = '_blank';
			dir.rel = 'noopener';
			actions.appendChild( dir );
		}
		if ( v.manage && ! manage ) {
			var mg = el( 'a', 'fb-link fb-done__manage', t.manageBooking );
			mg.href = v.manage;
			actions.appendChild( mg );
		}
		if ( actions.childNodes.length ) {
			box.appendChild( actions );
		}
		if ( manage ) {
			this.renderRequests( v );
		}
		this.show( manage ? 'manage' : 'done', true );
		void self;
	};

	/**
	 * Guest booking page: requests sent, and a form to ask for a change
	 * or cancellation (the hotel decides; nothing changes by itself).
	 */
	BookingForm.prototype.renderRequests = function ( v ) {
		var self = this;
		var box = section( this.success, t.askChange, 'fb-manage' );
		if ( v.requests && v.requests.length ) {
			var list = el( 'ul', 'fb-manage__requests' );
			v.requests.forEach( function ( r ) {
				var li = el( 'li' );
				li.appendChild( el( 'strong', '', r.type ) );
				li.appendChild( document.createTextNode( ' · ' + r.date + ' · ' + ( r.handled ? t.requestHandled : t.requestOpen ) ) );
				if ( r.message ) {
					li.appendChild( el( 'p', '', r.message ) );
				}
				list.appendChild( li );
			} );
			box.appendChild( el( 'h5', '', t.requests ) );
			box.appendChild( list );
		}
		if ( ! v.can_request ) {
			box.appendChild( el( 'p', '', t.noRequests ) );
			return;
		}
		box.appendChild( el( 'p', 'fb-manage__note', t.askChangeNote ) );
		var form = el( 'form', 'fb-manage__form' );
		form.noValidate = true;
		var fs = el( 'fieldset', 'fb-manage__types' );
		fs.appendChild( el( 'legend', '', t.requestType ) );
		Object.keys( v.request_types || {} ).forEach( function ( key ) {
			var label = el( 'label', 'fb-choice' );
			var radio = el( 'input' );
			radio.type = 'radio';
			radio.name = 'type';
			radio.value = key;
			radio.required = true;
			label.appendChild( radio );
			label.appendChild( el( 'span', 'fb-choice__text', v.request_types[ key ] ) );
			fs.appendChild( label );
		} );
		form.appendChild( fs );
		var msg = field( form, { name: 'message', label: t.requestMessage, tag: 'textarea', wide: true, placeholder: t.changePlaceholder } );
		var actions = el( 'div', 'fb-actions' );
		var submit = el( 'button', 'fb-button', t.sendRequestBtn );
		submit.type = 'submit';
		actions.appendChild( submit );
		form.appendChild( actions );
		var status = el( 'p', 'fb-manage__status' );
		status.setAttribute( 'role', 'status' );
		form.appendChild( status );
		box.appendChild( form );
		bindValidation( form );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( form._sending ) {
				return;
			}
			var type = form.querySelector( '[name="type"]:checked' );
			if ( ! type ) {
				setError( form.querySelector( '[name="type"]' ), t.chooseRequest );
				focusEl( form.querySelector( '[name="type"]' ) );
				return;
			}
			clearError( form.querySelector( '[name="type"]' ) );
			form._sending = true;
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
			postJson( 'guest-booking/request', { reference: self.guestRef, key: self.guestKey, type: type.value, message: msg.value, locale: self.locale } )
				.then( function ( data ) {
					form.innerHTML = '';
					var done = el( 'p', 'fb-manage__done', data.message );
					done.setAttribute( 'role', 'status' );
					form.appendChild( done );
					focusEl( done );
				} )
				.catch( function ( err ) {
					var target = err.data && err.data.field ? form.querySelector( '[name="' + err.data.field + '"]' ) : null;
					if ( target ) {
						setError( target, err.message );
						focusEl( target );
					} else {
						status.textContent = err.message;
					}
				} )
				.finally( function () {
					form._sending = false;
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				} );
		} );
	};

	/**
	 * The confirmation after a refresh, or the guest booking page.
	 */
	/**
	 * A confirmation address opened without its key (another tab or
	 * device, a shared link): nothing is shown, only how to see it.
	 */
	BookingForm.prototype.showKeyMissing = function () {
		this.searchForm.hidden = true;
		this.stayBar.hidden = true;
		this.results.hidden = true;
		this.detailsForm.hidden = true;
		this.success.hidden = false;
		this.success.innerHTML = '';
		this.success.appendChild( el( 'p', 'fb-success__message', t.keyMissing ) );
		this.addRestart();
	};

	BookingForm.prototype.loadBooking = function ( reference, key, manage ) {
		var self = this;
		this.guestRef = reference;
		this.guestKey = key;
		this.searchForm.hidden = true;
		this.stayBar.hidden = true;
		this.results.hidden = true;
		this.detailsForm.hidden = true;
		if ( this.stepsEl ) {
			this.stepsEl.hidden = manage;
		}
		this.success.hidden = false;
		this.success.innerHTML = '';
		this.success.appendChild( el( 'p', 'fb-success__message fb-success__message--pending', t.loading ) );
		var params = { reference: reference, key: key };
		if ( manage ) {
			params.manage = 1;
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		return request( apiUrl( 'guest-booking', params ) )
			.then( function ( v ) {
				self.showDone( v, manage );
			} )
			.catch( function ( err ) {
				self.success.innerHTML = '';
				self.success.appendChild( el( 'p', 'fb-success__message is-error', err.message ) );
				self.addRestart();
			} );
	};

	/**
	 * Paid / still to pay, and the bank details with copy buttons.
	 */
	BookingForm.prototype.renderPayment = function ( pay ) {
		var box = this.success;
		if ( pay.paid_formatted || pay.at_property_formatted ) {
			var sums = el( 'div', 'fb-success__amounts' );
			if ( pay.paid_formatted ) {
				sums.appendChild( el( 'p', '', t.paid + ': ' + pay.paid_formatted ) );
			}
			if ( pay.at_property_formatted && pay.state !== 'awaiting_transfer' && pay.state !== 'request' ) {
				sums.appendChild( el( 'p', '', t.atPropertyRest + ': ' + pay.at_property_formatted ) );
			}
			box.appendChild( sums );
		}
		if ( pay.instructions && pay.instructions.rows ) {
			var bank = el( 'div', 'fb-bank' );
			bank.appendChild( el( 'h4', 'fb-bank__title', t.bankDetails ) );
			var list = el( 'dl', 'fb-bank__list' );
			pay.instructions.rows.forEach( function ( item ) {
				var row = el( 'div', 'fb-bank__row fb-bank__row--' + item.key );
				row.appendChild( el( 'dt', '', item.label ) );
				var dd = el( 'dd' );
				dd.appendChild( el( 'span', 'fb-bank__value', item.value ) );
				if ( [ 'iban', 'reference', 'amount' ].indexOf( item.key ) !== -1 && navigator.clipboard ) {
					var copy = button( 'fb-link fb-bank__copy', t.copy );
					copy.setAttribute( 'aria-label', t.copy + ': ' + item.label );
					copy.addEventListener( 'click', function () {
						navigator.clipboard.writeText( item.key === 'iban' ? item.value.replace( /\s+/g, '' ) : item.value ).then( function () {
							copy.textContent = t.copied;
							window.setTimeout( function () {
								copy.textContent = t.copy;
							}, 2000 );
						} );
					} );
					dd.appendChild( copy );
				}
				row.appendChild( dd );
				list.appendChild( row );
			} );
			bank.appendChild( list );
			if ( pay.instructions.note ) {
				bank.appendChild( el( 'p', 'fb-bank__note', pay.instructions.note ) );
			}
			box.appendChild( bank );
		}
	};

	/**
	 * The guest came back from the payment page. The booking is confirmed by
	 * the payment provider's notification to the website, so the page asks
	 * the website until it knows the result.
	 */
	BookingForm.prototype.resumePayment = function ( kind, reference, key ) {
		var self = this;
		this.paymentRef = reference;
		this.paymentKey = key;
		this.searchForm.hidden = true;
		this.detailsForm.hidden = true;
		this.results.hidden = true;
		this.stayBar.hidden = true;
		this.success.hidden = false;
		this.success.innerHTML = '';
		this.success.appendChild( el( 'p', 'fb-success__message fb-success__message--pending', t.checkingPayment ) );
		this.root.classList.add( 'is-loading' );
		if ( this.stepsEl ) {
			this.markStep( 'payment' );
		}

		var started = Date.now();
		var params = { reference: reference, key: key };
		if ( this.locale ) {
			params.locale = this.locale;
		}
		function poll() {
			request( apiUrl( 'payment', params ) )
				.then( function ( view ) {
					var waiting = view.state === 'processing' || ( kind === 'return' && view.state === 'held' );
					if ( waiting && Date.now() - started < 45000 ) {
						window.setTimeout( poll, Date.now() - started < 10000 ? 1500 : 3000 );
						return;
					}
					self.root.classList.remove( 'is-loading' );
					if ( waiting && view.state === 'held' ) {
						// Still no word from the payment provider.
						view.message = t.stillChecking;
						view.can_retry = false;
					}
					self.showPaymentResult( view );
				} )
				.catch( function ( err ) {
					self.root.classList.remove( 'is-loading' );
					self.success.innerHTML = '';
					self.success.appendChild( el( 'p', 'fb-success__message is-error', err.message ) );
					self.addRestart();
				} );
		}
		poll();
	};

	BookingForm.prototype.markStep = function ( step ) {
		var items = Array.prototype.slice.call( this.stepsEl.querySelectorAll( '[data-step]' ) );
		var index = items.findIndex( function ( li ) {
			return li.getAttribute( 'data-step' ) === step;
		} );
		items.forEach( function ( li, i ) {
			li.classList.toggle( 'is-done', i < index );
			li.classList.toggle( 'is-current', i === index );
			if ( i === index ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
		} );
	};

	BookingForm.prototype.showPaymentResult = function ( view ) {
		var self = this;
		var box = this.success;

		if ( view.state === 'confirmed' ) {
			this.selected = { title: view.room, slug: '', id: view.reference };
			this.view = view.quote || null;
			this.trackComplete( { reference: view.reference, status: 'confirmed', quote: view.quote }, function () {
				if ( view.redirect ) {
					window.location.href = view.redirect;
					return;
				}
				// The full confirmation, which also survives a refresh (the key stays in this tab).
				guestKeySet( self.paymentRef, self.paymentKey );
				self.setUrl( { fb_done: self.paymentRef }, true );
				self.loadBooking( self.paymentRef, self.paymentKey, false );
			} );
			return;
		}

		box.innerHTML = '';
		box.setAttribute( 'data-state', view.state );
		box.appendChild( el( 'p', 'fb-success__message' + ( [ 'expired', 'failed', 'conflict', 'cancelled' ].indexOf( view.state ) !== -1 ? ' is-error' : '' ), view.message ) );
		var ref = el( 'p', 'fb-success__reference' );
		ref.appendChild( document.createTextNode( t.reference + ': ' ) );
		ref.appendChild( el( 'strong', '', view.reference ) );
		box.appendChild( ref );
		box.appendChild( el( 'p', 'fb-success__stay', view.room + ' · ' + this.date( view.check_in ) + ' → ' + this.date( view.check_out ) + ' · ' + view.total_formatted ) );
		this.renderPayment( view );

		var actions = el( 'div', 'fb-actions fb-actions--result' );
		if ( view.can_retry ) {
			var retry = button( 'fb-button', t.payAgain );
			retry.addEventListener( 'click', function () {
				self.retryPayment( retry );
			} );
			actions.appendChild( retry );
		}
		if ( [ 'expired', 'failed', 'held', 'cancelled' ].indexOf( view.state ) !== -1 ) {
			actions.appendChild( this.restartButton() );
		}
		if ( actions.childNodes.length ) {
			box.appendChild( actions );
		}
		box.focus();
	};

	BookingForm.prototype.restartButton = function () {
		var self = this;
		var again = button( 'fb-button fb-button--ghost', t.searchAgain );
		again.addEventListener( 'click', function () {
			self.setUrl( {}, true );
			if ( ! self.useUrl ) {
				var url = new URL( window.location.href );
				[ 'fb_payment', 'fb_ref', 'fb_key' ].forEach( function ( k ) {
					url.searchParams.delete( k );
				} );
				window.history.replaceState( null, '', url.toString() );
			}
			self.stay = null;
			self.show( 'dates', true );
		} );
		return again;
	};

	BookingForm.prototype.addRestart = function () {
		var actions = el( 'div', 'fb-actions fb-actions--result' );
		actions.appendChild( this.restartButton() );
		this.success.appendChild( actions );
	};

	BookingForm.prototype.retryPayment = function ( btn ) {
		var self = this;
		btn.disabled = true;
		postJson( 'payment/retry', { reference: this.paymentRef, key: this.paymentKey, locale: this.locale, return_url: this.returnUrl() } )
			.then( function ( data ) {
				if ( data.redirect ) {
					btn.textContent = t.redirecting;
					window.location.href = data.redirect;
				}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				self.setNotice( err.message, true );
			} );
	};

	/**
	 * The compact search bar: the same date picker, then off to the booking page.
	 */
	function SearchBar( node ) {
		var form = node.querySelector( '.fb-search' );
		var picker = new DatePicker( form, {
			locale: node.getAttribute( 'data-locale' ) || '',
			dateFormat: node.getAttribute( 'data-date-format' ) || '',
			guests: function () {
				var a = form.querySelector( '[name="adults"]' );
				var c = form.querySelector( '[name="children"]' );
				return { adults: a ? a.value : 2, children: c ? c.value : 0 };
			},
		} );
		if ( ! picker.enhanced ) {
			bindDates( form );
		}
		bindAges( form );
		form.addEventListener( 'submit', function ( e ) {
			var inInput = form.querySelector( '[name="check_in"]' );
			var outInput = form.querySelector( '[name="check_out"]' );
			if ( picker.enhanced && ( ! inInput.value || ! outInput.value ) ) {
				e.preventDefault();
				var target = picker.buttons[ inInput.value ? 'out' : 'in' ];
				setError( target, t.datesMissing );
				picker.open( inInput.value ? 'out' : 'in' );
			}
		} );
	}

	/**
	 * Room booking box: one room, dates and guests → availability and total
	 * → "Book now" opens the booking page with everything chosen (Day 6's
	 * form then goes straight to the room's rates or the guest details).
	 */
	function RoomBox( node ) {
		var self = this;
		this.root = node;
		node.flexoRoomBox = this;
		this.form = node.querySelector( '.fb-search' );
		this.result = node.querySelector( '[data-fb-box-result]' );
		this.room = node.getAttribute( 'data-room' ) || '';
		this.bookingUrl = node.getAttribute( 'data-booking-url' ) || '';
		this.bookText = node.getAttribute( 'data-book-text' ) || t.bookNow;
		this.locale = node.getAttribute( 'data-locale' ) || '';
		this.picker = new DatePicker( this.form, {
			locale: this.locale,
			dateFormat: node.getAttribute( 'data-date-format' ) || '',
			room: function () {
				return self.room;
			},
			guests: function () {
				var v = self.values();
				return { adults: v.adults, children: v.children };
			},
			onChange: function () {
				self.clear();
			},
			// Both dates chosen: availability and price straight away.
			onDone: function () {
				self.check( false );
			},
		} );
		if ( ! this.picker.enhanced ) {
			bindDates( this.form );
		}
		bindAges( this.form );
		if ( ! window.fetch ) {
			return; // The form opens the booking page instead.
		}
		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.check( true );
		} );
		this.form.addEventListener( 'change', function ( e ) {
			if ( 'adults' === e.target.name || 'children' === e.target.name || 'children_ages[]' === e.target.name ) {
				// Same dates, other guests: checked again (the calendar asks for these guests next time).
				self.clear();
				var v = self.values();
				var ageMissing = Array.prototype.some.call( self.form.querySelectorAll( '[name="children_ages[]"]' ), function ( sel ) {
					return '' === sel.value;
				} );
				if ( v.check_in && v.check_out && ! ageMissing ) {
					self.check( false );
				}
			}
		} );
		if ( '1' === node.getAttribute( 'data-autosearch' ) ) {
			this.check( false );
		}
	}

	RoomBox.prototype.values = function () {
		var f = this.form;
		var get = function ( name, def ) {
			var field = f.querySelector( '[name="' + name + '"]' );
			return field && field.value !== '' ? field.value : def;
		};
		var ages = [];
		f.querySelectorAll( '[name="children_ages[]"]' ).forEach( function ( sel ) {
			ages.push( sel.value );
		} );
		return {
			check_in: get( 'check_in', '' ),
			check_out: get( 'check_out', '' ),
			adults: get( 'adults', '2' ),
			children: get( 'children', '0' ),
			ages: ages,
		};
	};

	RoomBox.prototype.clear = function () {
		this.result.innerHTML = '';
		this.root.classList.remove( 'is-checked' );
	};

	RoomBox.prototype.message = function ( text, isError ) {
		this.result.innerHTML = '';
		var p = el( 'p', 'fb-box__status' + ( isError ? ' is-no' : '' ), text );
		if ( isError ) {
			p.setAttribute( 'role', 'alert' );
		}
		this.result.appendChild( p );
	};

	/** Booking page address with the room, dates and guests. */
	RoomBox.prototype.bookUrl = function ( v, withRoom ) {
		var url = new URL( this.bookingUrl, window.location.href );
		if ( withRoom ) {
			url.searchParams.set( 'room', this.room );
		}
		url.searchParams.set( 'check_in', v.check_in );
		url.searchParams.set( 'check_out', v.check_out );
		url.searchParams.set( 'adults', v.adults );
		if ( parseInt( v.children, 10 ) > 0 ) {
			url.searchParams.set( 'children', v.children );
			if ( v.ages.length ) {
				url.searchParams.set( 'children_ages', v.ages.join( ',' ) );
			}
		}
		return url.toString();
	};

	RoomBox.prototype.guestsText = function ( v ) {
		var a = parseInt( v.adults, 10 ) || 1;
		var c = parseInt( v.children, 10 ) || 0;
		var text = fmt( a === 1 ? t.adult : t.adults, a );
		if ( c > 0 ) {
			text += ', ' + fmt( c === 1 ? t.child : t.childrenN, c );
		}
		return text;
	};

	RoomBox.prototype.check = function ( focus ) {
		var self = this;
		var v = this.values();
		if ( ! v.check_in || ! v.check_out ) {
			if ( ! focus ) {
				return;
			}
			if ( this.picker.enhanced ) {
				var which = v.check_in ? 'out' : 'in';
				setError( this.picker.buttons[ which ], t.datesMissing );
				this.picker.open( which );
			} else {
				this.message( t.datesMissing, true );
			}
			return;
		}
		if ( v.check_out <= v.check_in ) {
			this.message( t.datesInvalid, true );
			return;
		}
		var invalid = null;
		this.form.querySelectorAll( '[name="children_ages[]"]' ).forEach( function ( sel ) {
			var msg = checkField( sel );
			setError( sel, msg );
			invalid = invalid || ( msg ? sel : null );
		} );
		if ( invalid ) {
			focusEl( invalid );
			return;
		}
		var params = { check_in: v.check_in, check_out: v.check_out, adults: v.adults, children: v.children, room: this.room };
		if ( v.ages.length ) {
			params.children_ages = v.ages.join( ',' );
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		this.root.classList.add( 'is-loading' );
		this.message( t.checking );
		request( apiUrl( 'availability', params ) )
			.then( function ( data ) {
				var room = ( data.rooms || [] )[ 0 ];
				if ( ! room ) {
					self.message( data.notice || t.noRooms, true );
					return;
				}
				self.render( data, room, v, focus );
				track( {
					event: 'search',
					search_term: data.check_in + ' – ' + data.check_out,
					check_in: data.check_in,
					check_out: data.check_out,
					nights: data.nights,
					adults: parseInt( v.adults, 10 ),
					children: parseInt( v.children, 10 ) || 0,
				} );
			} )
			.catch( function ( err ) {
				self.message( err.message || t.genericError, true );
			} )
			.finally( function () {
				self.root.classList.remove( 'is-loading' );
			} );
	};

	RoomBox.prototype.render = function ( data, room, v, focus ) {
		var self = this;
		this.result.innerHTML = '';
		this.root.classList.add( 'is-checked' );
		var box = el( 'div', 'fb-box__answer' + ( room.available ? ' is-available' : ' is-unavailable' ) );
		var stay = el( 'p', 'fb-box__stay', nightsText( data.nights ) + ' · ' + this.guestsText( v ) );
		if ( room.available ) {
			box.appendChild( el( 'p', 'fb-box__status is-ok', t.boxAvailable ) );
			box.appendChild( stay );
			var total = el( 'p', 'fb-box__total' );
			total.appendChild( el( 'span', 'fb-box__total-label', t.total ) );
			total.appendChild( el( 'strong', 'fb-box__total-amount', room.price_from ? fmt( t.from, room.total_formatted ) : room.total_formatted ) );
			box.appendChild( total );
			if ( data.nights > 1 && room.price_average_formatted ) {
				box.appendChild( el( 'p', 'fb-box__avg', fmt( t.avgPerNight, room.price_average_formatted ) ) );
			}
			if ( room.units_left > 0 && room.units_left <= 2 ) {
				box.appendChild( el( 'p', 'fb-box__left', fmt( t.onlyLeft, room.units_left ) ) );
			}
			var book = el( 'a', 'fb-button fb-box__book', this.bookText );
			book.href = this.bookUrl( v, true );
			box.appendChild( book );
			this.result.appendChild( box );
			if ( focus ) {
				book.focus();
			}
			return;
		}
		box.appendChild( el( 'p', 'fb-box__status is-no', room.reason || data.notice || t.boxUnavailable ) );
		box.appendChild( stay );
		var alt = el( 'div', 'fb-box__alternatives' );
		box.appendChild( alt );
		var others = el( 'a', 'fb-link fb-box__others', t.boxOtherRooms );
		others.href = this.bookUrl( v, false );
		box.appendChild( others );
		this.result.appendChild( box );
		box.querySelector( '.fb-box__status' ).setAttribute( 'tabindex', '-1' );
		if ( focus ) {
			box.querySelector( '.fb-box__status' ).focus();
		}

		var params = { check_in: v.check_in, check_out: v.check_out, adults: v.adults, children: v.children, room: this.room };
		if ( v.ages.length ) {
			params.children_ages = v.ages.join( ',' );
		}
		if ( this.locale ) {
			params.locale = this.locale;
		}
		request( apiUrl( 'alternatives', params ) )
			.then( function ( res ) {
				if ( ! res.dates || ! res.dates.length ) {
					return;
				}
				alt.appendChild( el( 'p', 'fb-box__subtitle', t.nearbyDates ) );
				var ul = el( 'ul', 'fb-alternatives' );
				res.dates.slice( 0, 3 ).forEach( function ( d ) {
					var li = el( 'li' );
					var b = button( 'fb-alt' );
					b.appendChild( el( 'strong', '', shortDate( d.check_in, self.locale ) + ' – ' + shortDate( d.check_out, self.locale ) ) );
					b.appendChild( el( 'span', '', d.from ) );
					b.addEventListener( 'click', function () {
						self.form.querySelector( '[name="check_in"]' ).value = d.check_in;
						self.form.querySelector( '[name="check_out"]' ).value = d.check_out;
						if ( self.picker.enhanced ) {
							self.picker.sync();
						}
						self.check( true );
					} );
					li.appendChild( b );
					ul.appendChild( li );
				} );
				alt.appendChild( ul );
			} )
			.catch( function () {} );
	};

	/* ---------------------------------------------------------------------
	 * "Check availability" panel: links ending in #check-availability open
	 * the room's booking box on this page with its calendar (see
	 * Flexo_Booking_Room_Render::request_panel()).
	 * ------------------------------------------------------------------- */

	var PANEL_HASH = '#check-availability';
	var reducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function isVisible( node ) {
		return !! ( node.offsetWidth || node.offsetHeight || node.getClientRects().length );
	}

	/** Calendar open, or – with dates already chosen – the answer. */
	function startBox( node ) {
		var box = node && node.flexoRoomBox;
		if ( ! box ) {
			return;
		}
		var v = box.values();
		if ( v.check_in && v.check_out ) {
			if ( ! node.classList.contains( 'is-checked' ) ) {
				box.check( false );
			}
			var status = node.querySelector( '.fb-box__status' ) || node.querySelector( '.fb-date' );
			if ( status ) {
				if ( ! status.hasAttribute( 'tabindex' ) && ! /^(BUTTON|A|INPUT|SELECT)$/.test( status.tagName ) ) {
					status.setAttribute( 'tabindex', '-1' );
				}
				status.focus( { preventScroll: true } );
			}
		} else if ( box.picker.enhanced ) {
			box.picker.open( v.check_in ? 'out' : 'in' );
		} else {
			var field = node.querySelector( 'input[name="check_in"]' );
			if ( field ) {
				field.focus();
			}
		}
	}

	/**
	 * Opens the room's booking box: the one on the page, else its dialog.
	 *
	 * @param {string} slug Room address ('' = the room of this page).
	 * @return {boolean} Whether something opened (otherwise the link is followed).
	 */
	function openPanel( slug ) {
		var onPage = null;
		document.querySelectorAll( '[data-flexo-booking-box]' ).forEach( function ( node ) {
			if ( ! onPage && ! node.closest( 'dialog' ) && isVisible( node ) && ( ! slug || node.getAttribute( 'data-room' ) === slug ) ) {
				onPage = node;
			}
		} );
		if ( onPage ) {
			onPage.scrollIntoView( { behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' } );
			window.setTimeout( function () {
				startBox( onPage );
			}, reducedMotion ? 0 : 450 );
			return true;
		}
		var dialog = null;
		document.querySelectorAll( 'dialog[data-flexo-book-dialog]' ).forEach( function ( node ) {
			if ( ! dialog && ( slug ? node.getAttribute( 'data-flexo-book-dialog' ) === slug : node.hasAttribute( 'data-flexo-book-current' ) ) ) {
				dialog = node;
			}
		} );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			return false;
		}
		dialog.showModal();
		document.documentElement.classList.add( 'flexo-book-dialog-open' );
		var inner = dialog.querySelector( '[data-flexo-booking-box]' );
		// After layout, so the calendar knows its width (one or two months).
		window.requestAnimationFrame( function () {
			startBox( inner );
		} );
		return true;
	}

	function panelSlug( link ) {
		var url;
		try {
			url = new URL( link.href, window.location.href );
		} catch ( err ) {
			return null;
		}
		if ( url.hash !== PANEL_HASH ) {
			return null;
		}
		return url.searchParams.get( 'room' ) || '';
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || ! e.target.closest ) {
			return;
		}
		var link = e.target.closest( 'a[href]' );
		var slug = link ? panelSlug( link ) : null;
		if ( null !== slug && openPanel( slug ) ) {
			e.preventDefault();
		}
	} );

	function bindDialogs() {
		document.querySelectorAll( 'dialog[data-flexo-book-dialog]:not([data-fb-ready])' ).forEach( function ( dialog ) {
			dialog.setAttribute( 'data-fb-ready', '1' );
			var close = function () {
				dialog.close();
			};
			dialog.querySelectorAll( '[data-flexo-book-close]' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', close );
			} );
			// A click on the dimmed page around the dialog closes it.
			dialog.addEventListener( 'click', function ( e ) {
				if ( e.target === dialog ) {
					close();
				}
			} );
			// Escape closes an open calendar first.
			dialog.addEventListener( 'cancel', function ( e ) {
				var node = dialog.querySelector( '[data-flexo-booking-box]' );
				if ( node && node.flexoRoomBox && node.flexoRoomBox.picker.enhanced && node.flexoRoomBox.picker.isOpen() ) {
					e.preventDefault();
					node.flexoRoomBox.picker.close( true );
				}
			} );
			dialog.addEventListener( 'close', function () {
				document.documentElement.classList.remove( 'flexo-book-dialog-open' );
			} );
		} );
	}

	/**
	 * The plugin's own pages (built-in booking page, plain room page) under
	 * a header that lies over the page (transparent headers made for a big
	 * photo): the content is moved down so the header doesn't cover it.
	 */
	/**
	 * Plugin pages under a header that lies over them (transparent headers
	 * made for a hero picture): the page moves below the header, or the
	 * title band leaves room for it. An administrator's visit is reported,
	 * so Settings → Pages can suggest a header style.
	 */
	function clearOverlayHeader() {
		var body = document.body;
		var band = document.querySelector( '[data-fb-title-band]' );
		var content = band ? null : document.querySelector( '[data-fb-clear-header], .flexo-builtin-booking, .flexo-room-page-wrap' );
		var header = document.querySelector( '.elementor-location-header, header.site-header, #masthead, body > header' );
		var target = band || content;
		if ( ! target || ! header ) {
			return;
		}
		// Page coordinates; a fixed header always sits at the top of the page.
		var scrollY = window.pageYOffset || 0;
		var isFixed = function ( node ) {
			for ( var n = node; n && n !== header.parentNode; n = n.parentNode ) {
				if ( 'fixed' === window.getComputedStyle( n ).position ) {
					return true;
				}
			}
			return false;
		};
		var bottom = 0;
		[ header ].concat( Array.prototype.slice.call( header.querySelectorAll( ':scope > *, :scope > * > *, :scope > * > * > *' ) ) ).forEach( function ( node ) {
			var rect = node.getBoundingClientRect();
			if ( rect.height > 0 && rect.width > 0 ) {
				bottom = Math.max( bottom, rect.bottom + ( isFixed( node ) ? 0 : scrollY ) );
			}
		} );
		var top = target.getBoundingClientRect().top + scrollY;
		var overlay = bottom > top + 1;
		reportOverlay( overlay );
		if ( band ) {
			band.style.setProperty( '--fb-band-pad', overlay ? Math.ceil( bottom - top ) + 'px' : '0px' );
			return;
		}
		if ( body.classList.contains( 'flexo-header-space-fixed' ) ) {
			return; // The hotel set the space for every device.
		}
		content.style.paddingTop = '';
		top = content.getBoundingClientRect().top + scrollY;
		if ( bottom > top + 1 ) {
			var base = parseFloat( window.getComputedStyle( content ).paddingTop ) || 0;
			content.style.paddingTop = Math.ceil( base + bottom - top + 16 ) + 'px';
		}
	}

	function reportOverlay( overlay ) {
		var report = cfg.overlayReport;
		if ( ! report || reportOverlay.sent ) {
			return;
		}
		reportOverlay.sent = true;
		try {
			if ( window.sessionStorage.getItem( 'flexo_overlay_' + location.pathname ) === String( overlay ) ) {
				return;
			}
			window.sessionStorage.setItem( 'flexo_overlay_' + location.pathname, String( overlay ) );
		} catch ( e ) {}
		window.fetch( report.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': report.nonce },
			body: JSON.stringify( { overlay: overlay, path: location.pathname } ),
		} ).catch( function () {} );
	}

	/**
	 * The Thank You page: copy buttons, and a page that re-checks by itself
	 * while a card payment is being confirmed (every 5 s, up to 2 minutes).
	 */
	function initConfirmation( ctx ) {
		ctx.querySelectorAll( '[data-fb-copy]' ).forEach( function ( copy ) {
			if ( ! navigator.clipboard || copy.getAttribute( 'data-fb-ready' ) ) {
				return;
			}
			copy.setAttribute( 'data-fb-ready', '1' );
			copy.hidden = false;
			copy.addEventListener( 'click', function () {
				navigator.clipboard.writeText( copy.getAttribute( 'data-fb-copy' ) ).then( function () {
					copy.textContent = t.copied;
					window.setTimeout( function () {
						copy.textContent = t.copy;
					}, 2000 );
				} );
			} );
		} );
		var pending = ctx.querySelector( '[data-fb-ty-refresh]' );
		var counter = 'flexo_ty_refresh_' + location.pathname;
		var count = 0;
		try {
			count = parseInt( window.sessionStorage.getItem( counter ) || '0', 10 ) || 0;
			if ( ! pending ) {
				window.sessionStorage.removeItem( counter );
			}
		} catch ( e ) {}
		if ( ! pending ) {
			return;
		}
		var max = parseInt( pending.getAttribute( 'data-fb-ty-max' ), 10 ) || 24;
		var wait = ( parseInt( pending.getAttribute( 'data-fb-ty-refresh' ), 10 ) || 5 ) * 1000;
		if ( count >= max ) {
			pending.appendChild( el( 'span', 'fb-ty-muted', ' ' + t.stillChecking ) );
			return;
		}
		window.setTimeout( function () {
			try {
				window.sessionStorage.setItem( counter, String( count + 1 ) );
			} catch ( e ) {}
			window.location.reload();
		}, wait );
	}

	function init( scope ) {
		var ctx = scope || document;
		bindDialogs();
		initConfirmation( ctx );
		if ( ! scope ) {
			clearOverlayHeader();
			window.addEventListener( 'load', clearOverlayHeader );
			window.addEventListener( 'resize', function () {
				window.clearTimeout( clearOverlayHeader.timer );
				clearOverlayHeader.timer = window.setTimeout( clearOverlayHeader, 150 );
			} );
		}
		ctx.querySelectorAll( '[data-flexo-booking-box]:not([data-fb-ready])' ).forEach( function ( node ) {
			node.setAttribute( 'data-fb-ready', '1' );
			new RoomBox( node ); // eslint-disable-line no-new
		} );
		ctx.querySelectorAll( '[data-flexo-booking]:not([data-fb-ready])' ).forEach( function ( node ) {
			node.setAttribute( 'data-fb-ready', '1' );
			new BookingForm( node ); // eslint-disable-line no-new
		} );
		ctx.querySelectorAll( '[data-flexo-booking-search]:not([data-fb-ready])' ).forEach( function ( node ) {
			node.setAttribute( 'data-fb-ready', '1' );
			new SearchBar( node ); // eslint-disable-line no-new
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}

	// Elementor renders widgets dynamically in the editor preview and in popups.
	var elementorHooked = false;
	function hookElementor() {
		if ( elementorHooked || ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		elementorHooked = true;
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/flexo-booking-form.default', function ( $scope ) {
			init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
		} );
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/flexo-room-booking-box.default', function ( $scope ) {
			init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
		} );
	}
	hookElementor();
	// Elementor triggers this event through jQuery, not as a native DOM event.
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}
}() );
