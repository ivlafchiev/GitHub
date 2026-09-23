/**
 * Flexo Booking – front-end booking flow.
 * Vanilla JS, no dependencies. Works with the shortcode and the Elementor widget.
 */
( function () {
	'use strict';

	var cfg = window.FlexoBookingConfig || { restUrl: '/wp-json/flexo-booking/v1/', minNights: 1, i18n: {} };
	var t = cfg.i18n;

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
						throw new Error( body && body.message ? body.message : t.genericError );
					}
					return body;
				} );
			} );
	}

	function fmt( str, n ) {
		return String( str ).replace( '%d', n ).replace( '%s', n );
	}

	function parseDate( ymd ) {
		var p = ymd.split( '-' );
		return new Date( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] );
	}

	function toYmd( date ) {
		var m = String( date.getMonth() + 1 ).padStart( 2, '0' );
		var d = String( date.getDate() ).padStart( 2, '0' );
		return date.getFullYear() + '-' + m + '-' + d;
	}

	function humanDate( ymd ) {
		var lang = document.documentElement.lang || undefined;
		try {
			return parseDate( ymd ).toLocaleDateString( lang, { day: 'numeric', month: 'short', year: 'numeric' } );
		} catch ( e ) {
			return ymd;
		}
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

	/**
	 * Keeps check-out after check-in on both the full form and the search bar.
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

	function BookingForm( root ) {
		this.root = root;
		this.searchForm = root.querySelector( '.fb-search' );
		this.detailsForm = root.querySelector( '.fb-details' );
		this.results = root.querySelector( '.fb-results' );
		this.notice = root.querySelector( '.fb-notice' );
		this.success = root.querySelector( '.fb-success' );
		this.summary = root.querySelector( '.fb-summary' );
		this.room = root.getAttribute( 'data-room' ) || '';
		this.showAll = false;
		this.stay = null;
		this.selected = null;

		bindDates( this.searchForm );

		this.searchForm.addEventListener( 'submit', this.onSearch.bind( this ) );
		this.detailsForm.addEventListener( 'submit', this.onBook.bind( this ) );
		this.detailsForm.querySelector( '[data-fb-back]' ).addEventListener( 'click', this.back.bind( this ) );

		if ( root.getAttribute( 'data-autosearch' ) === '1' ) {
			this.search();
		}
	}

	BookingForm.prototype.setNotice = function ( message, isError ) {
		this.notice.textContent = message || '';
		this.notice.classList.toggle( 'fb-notice--error', !! isError );
		this.notice.hidden = ! message;
	};

	BookingForm.prototype.values = function () {
		var f = this.searchForm;
		var children = f.querySelector( '[name="children"]' );
		return {
			check_in: f.querySelector( '[name="check_in"]' ).value,
			check_out: f.querySelector( '[name="check_out"]' ).value,
			adults: f.querySelector( '[name="adults"]' ).value,
			children: children ? children.value : 0,
		};
	};

	BookingForm.prototype.onSearch = function ( e ) {
		e.preventDefault();
		this.showAll = false;
		this.search();
	};

	BookingForm.prototype.search = function () {
		var self = this;
		var v = this.values();

		this.detailsForm.hidden = true;
		this.success.hidden = true;

		if ( ! this.searchForm.checkValidity() ) {
			this.searchForm.reportValidity();
			return;
		}
		if ( v.check_out <= v.check_in ) {
			this.setNotice( t.datesInvalid, true );
			return;
		}

		var params = Object.assign( {}, v );
		if ( this.room && ! this.showAll ) {
			params.room = this.room;
		}

		this.setNotice( t.checking );
		this.results.hidden = true;
		this.root.classList.add( 'is-loading' );

		request( apiUrl( 'availability', params ) )
			.then( function ( data ) {
				self.stay = { check_in: data.check_in, check_out: data.check_out, nights: data.nights, nightsLabel: data.nights_label, adults: v.adults, children: v.children };
				self.setNotice( '' );
				self.closedNotice = data.notice || '';
				self.renderRooms( data.rooms );
			} )
			.catch( function ( err ) {
				self.setNotice( err.message, true );
			} )
			.finally( function () {
				self.root.classList.remove( 'is-loading' );
			} );
	};

	BookingForm.prototype.renderRooms = function ( rooms ) {
		var self = this;
		var list = el( 'div', 'fb-rooms' );
		var anyAvailable = rooms.some( function ( r ) {
			return r.available;
		} );

		this.results.innerHTML = '';

		rooms.forEach( function ( room ) {
			var card = el( 'article', 'fb-room' + ( room.available ? '' : ' is-unavailable' ) );

			if ( room.image ) {
				var img = el( 'img', 'fb-room__image' );
				img.src = room.image;
				img.alt = '';
				img.loading = 'lazy';
				card.appendChild( img );
			}

			var body = el( 'div', 'fb-room__body' );
			body.appendChild( el( 'h4', 'fb-room__title', room.title ) );
			if ( room.excerpt ) {
				body.appendChild( el( 'p', 'fb-room__excerpt', room.excerpt ) );
			}
			var nightly = room.price_varies && t.avgPerNight
				? fmt( t.avgPerNight, room.price_average_formatted )
				: ( room.price_average_formatted || room.price_formatted ) + ' ' + t.perNight;
			body.appendChild( el( 'p', 'fb-room__meta', fmt( t.upTo, room.capacity ) + ' · ' + nightly ) );
			if ( room.available && room.units_left > 0 && room.units_left <= 2 ) {
				body.appendChild( el( 'p', 'fb-room__urgency', fmt( t.onlyLeft, room.units_left ) ) );
			}
			if ( ! room.available && room.reason ) {
				body.appendChild( el( 'p', 'fb-room__reason', room.reason ) );
			}
			card.appendChild( body );

			var side = el( 'div', 'fb-room__side' );
			side.appendChild( el( 'span', 'fb-room__total-label', self.stay.nightsLabel ) );
			side.appendChild( el( 'strong', 'fb-room__total', room.total_formatted ) );
			var btn = el( 'button', 'fb-button', room.available ? t.select : t.unavailable );
			btn.type = 'button';
			btn.disabled = ! room.available;
			btn.addEventListener( 'click', function () {
				self.select( room );
			} );
			side.appendChild( btn );
			card.appendChild( side );

			list.appendChild( card );
		} );

		if ( ! anyAvailable ) {
			this.setNotice( this.closedNotice || t.noRooms, true );
		}

		this.results.appendChild( list );

		if ( this.room && ! this.showAll && ! anyAvailable ) {
			var more = el( 'button', 'fb-button fb-button--ghost', t.showAll );
			more.type = 'button';
			more.addEventListener( 'click', function () {
				self.showAll = true;
				self.search();
			} );
			this.results.appendChild( more );
		}

		this.results.hidden = false;
	};

	BookingForm.prototype.select = function ( room ) {
		this.selected = room;
		this.summary.innerHTML = '';

		var s = this.stay;
		var rows = [
			[ room.title, room.total_formatted ],
			[ humanDate( s.check_in ) + ' → ' + humanDate( s.check_out ), s.nightsLabel ],
		];
		rows.forEach( function ( row ) {
			var line = el( 'div', 'fb-summary__row' );
			line.appendChild( el( 'span', '', row[ 0 ] ) );
			line.appendChild( el( 'span', '', row[ 1 ] ) );
			this.summary.appendChild( line );
		}, this );

		// Itemised prices, e.g. "Low season: 2 nights × 100 €" when they vary.
		( room.breakdown || [] ).forEach( function ( item ) {
			( item.details || [] ).forEach( function ( detail ) {
				this.summary.appendChild( el( 'div', 'fb-summary__detail', detail ) );
			}, this );
		}, this );

		this.results.hidden = true;
		this.detailsForm.hidden = false;
		this.setNotice( '' );
		this.detailsForm.querySelector( 'input' ).focus();
	};

	BookingForm.prototype.back = function () {
		this.detailsForm.hidden = true;
		this.results.hidden = false;
		this.setNotice( '' );
	};

	BookingForm.prototype.onBook = function ( e ) {
		e.preventDefault();
		var self = this;
		var f = this.detailsForm;

		if ( ! f.checkValidity() ) {
			f.reportValidity();
			return;
		}

		var terms = f.querySelector( '[name="terms"]' );
		var payload = {
			room: this.selected.slug || String( this.selected.id ),
			check_in: this.stay.check_in,
			check_out: this.stay.check_out,
			adults: parseInt( this.stay.adults, 10 ),
			children: parseInt( this.stay.children, 10 ) || 0,
			guest_name: f.querySelector( '[name="guest_name"]' ).value,
			guest_email: f.querySelector( '[name="guest_email"]' ).value,
			guest_phone: f.querySelector( '[name="guest_phone"]' ).value,
			notes: f.querySelector( '[name="notes"]' ).value,
			terms: terms ? terms.checked : false,
			fb_website: f.querySelector( '[name="fb_website"]' ).value,
		};

		var submit = f.querySelector( '[type="submit"]' );
		var label = submit.textContent;
		submit.disabled = true;
		submit.textContent = t.sending;
		this.setNotice( '' );

		request( apiUrl( 'bookings' ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
			body: JSON.stringify( payload ),
		} )
			.then( function ( data ) {
				if ( data.redirect ) {
					window.location.href = data.redirect;
					return;
				}
				self.showSuccess( data );
			} )
			.catch( function ( err ) {
				self.setNotice( err.message, true );
			} )
			.finally( function () {
				submit.disabled = false;
				submit.textContent = label;
			} );
	};

	BookingForm.prototype.showSuccess = function ( data ) {
		this.success.innerHTML = '';
		this.success.appendChild( el( 'p', 'fb-success__message', data.message ) );
		var ref = el( 'p', 'fb-success__reference' );
		ref.appendChild( document.createTextNode( t.reference + ': ' ) );
		ref.appendChild( el( 'strong', '', data.reference ) );
		this.success.appendChild( ref );
		this.success.appendChild( el( 'p', 'fb-success__stay', data.room + ' · ' + humanDate( data.check_in ) + ' → ' + humanDate( data.check_out ) + ' · ' + data.total_formatted ) );

		this.searchForm.hidden = true;
		this.detailsForm.hidden = true;
		this.results.hidden = true;
		this.success.hidden = false;
		this.success.focus();
	};

	function init( scope ) {
		var ctx = scope || document;
		ctx.querySelectorAll( '[data-flexo-booking]:not([data-fb-ready])' ).forEach( function ( node ) {
			node.setAttribute( 'data-fb-ready', '1' );
			new BookingForm( node ); // eslint-disable-line no-new
		} );
		ctx.querySelectorAll( '[data-flexo-booking-search]:not([data-fb-ready])' ).forEach( function ( node ) {
			node.setAttribute( 'data-fb-ready', '1' );
			bindDates( node.querySelector( '.fb-search' ) );
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
	}
	hookElementor();
	// Elementor triggers this event through jQuery, not as a native DOM event.
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}
}() );
