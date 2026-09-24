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
						var err = new Error( body && body.message ? body.message : t.genericError );
						err.code = body && body.code ? body.code : '';
						throw err;
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

	/**
	 * Date for guests: DD.MM.YYYY where the language writes it so (e.g.
	 * Bulgarian), otherwise the browser's format for the page language.
	 */
	function humanDate( ymd, format, locale ) {
		if ( format === 'd.m.Y' ) {
			var p = ymd.split( '-' );
			return p[ 2 ] + '.' + p[ 1 ] + '.' + p[ 0 ];
		}
		var lang = locale ? locale.replace( '_', '-' ) : ( document.documentElement.lang || undefined );
		try {
			return parseDate( ymd ).toLocaleDateString( lang, { day: 'numeric', month: 'short', year: 'numeric' } );
		} catch ( e ) {
			return ymd;
		}
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
	 * The invoice part of the details form: shown when "I would like an
	 * invoice" is ticked, with the fields for a person or a company.
	 */
	function bindInvoice( form ) {
		var box = form.querySelector( '[data-fb-invoice]' );
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
			} );
		}
		box.addEventListener( 'change', sync );
		sync();
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
		var uid = 'fb-age-' + Math.random().toString( 36 ).slice( 2, 8 );

		function render() {
			var count = parseInt( children.value, 10 ) || 0;
			var current = Array.prototype.map.call( box.querySelectorAll( 'select' ), function ( sel ) {
				return sel.value;
			} );
			if ( ! current.length ) {
				current = initial;
			}
			box.innerHTML = '';
			for ( var i = 0; i < count; i++ ) {
				var field = el( 'div', 'fb-field fb-field--small fb-field--age' );
				var label = el( 'label', '', fmt( t.childAge, i + 1 ) );
				label.htmlFor = uid + '-' + i;
				var select = el( 'select' );
				select.id = uid + '-' + i;
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
		render();
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
		this.locale = root.getAttribute( 'data-locale' ) || '';
		this.dateFormat = root.getAttribute( 'data-date-format' ) || '';
		this.showAll = false;
		this.stay = null;
		this.selected = null;
		this.view = null;
		this.promo = '';

		bindDates( this.searchForm );
		bindAges( this.searchForm );
		bindInvoice( this.detailsForm );

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
		if ( this.locale ) {
			params.locale = this.locale;
		}

		this.setNotice( t.checking );
		this.results.hidden = true;
		this.root.classList.add( 'is-loading' );

		request( apiUrl( 'availability', params ) )
			.then( function ( data ) {
				self.stay = { check_in: data.check_in, check_out: data.check_out, nights: data.nights, nightsLabel: data.nights_label, adults: v.adults, children: v.children, ages: data.children_ages || [] };
				self.setNotice( '' );
				self.closedNotice = data.notice || '';
				self.renderRooms( data.rooms );
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
			side.appendChild( el( 'strong', 'fb-room__total', room.price_from ? fmt( t.from, room.total_formatted ) : room.total_formatted ) );
			var btn = el( 'button', 'fb-button', room.available ? t.select : t.unavailable );
			btn.type = 'button';
			btn.disabled = ! room.available;
			var plans = room.plans || [];
			btn.addEventListener( 'click', function () {
				if ( plans.length > 1 ) {
					self.showPlans( card, room );
				} else {
					self.select( room, plans[ 0 ] || room.quote || null );
				}
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

	/**
	 * Rooms with several rate plans: the guest chooses one inside the card.
	 */
	BookingForm.prototype.showPlans = function ( card, room ) {
		var self = this;
		var open = card.querySelector( '.fb-plans' );
		if ( open ) {
			open.remove();
			return;
		}
		var box = el( 'div', 'fb-plans' );
		box.appendChild( el( 'h5', 'fb-plans__title', t.chooseRate ) );
		room.plans.forEach( function ( plan ) {
			var rate = plan.rate_plan || {};
			var row = el( 'div', 'fb-plan' );
			var info = el( 'div', 'fb-plan__info' );
			info.appendChild( el( 'strong', 'fb-plan__name', rate.name ) );
			if ( rate.description ) {
				info.appendChild( el( 'span', 'fb-plan__desc', rate.description ) );
			}
			info.appendChild( el( 'span', 'fb-plan__policy' + ( rate.refundable ? '' : ' is-strict' ), ( rate.refundable ? '✓ ' : '✕ ' ) + rate.refundable_label ) );
			row.appendChild( info );
			var price = el( 'div', 'fb-plan__price' );
			price.appendChild( el( 'strong', '', plan.total_formatted ) );
			var choose = el( 'button', 'fb-button', t.choose );
			choose.type = 'button';
			choose.setAttribute( 'aria-label', t.choose + ': ' + rate.name + ', ' + plan.total_formatted );
			choose.addEventListener( 'click', function () {
				self.select( room, plan );
			} );
			price.appendChild( choose );
			row.appendChild( price );
			box.appendChild( row );
		} );
		card.appendChild( box );
		box.querySelector( 'button' ).focus();
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

	BookingForm.prototype.select = function ( room, view ) {
		this.selected = room;
		this.view = view || room.quote || null;
		this.promo = '';
		this.renderSummary();

		this.results.hidden = true;
		this.detailsForm.hidden = false;
		this.setNotice( '' );
		this.detailsForm.querySelector( 'input' ).focus();

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
		if ( ! cfg.tracking || storageGet( key ) ) {
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
			row( t.rate, view.rate_plan.name, 'fb-summary__rate' );
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
		if ( view.due_at_property > 0 ) {
			row( t.atProperty, view.due_at_property_formatted, 'fb-summary__property' );
		}
		if ( view.rate_plan && view.rate_plan.cancellation_policy ) {
			var policy = el( 'p', 'fb-summary__policy' );
			policy.appendChild( el( 'strong', '', t.cancellation + ': ' ) );
			policy.appendChild( document.createTextNode( view.rate_plan.cancellation_policy ) );
			summary.appendChild( policy );
		}

		if ( cfg.promo ) {
			this.renderPromo( summary, view );
		}
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
			var remove = el( 'button', 'fb-link', t.remove );
			remove.type = 'button';
			remove.addEventListener( 'click', function () {
				self.applyPromo( '' );
			} );
			box.appendChild( remove );
			summary.appendChild( box );
			return;
		}

		var toggle = el( 'button', 'fb-link fb-promo__toggle', t.havePromo );
		toggle.type = 'button';
		toggle.setAttribute( 'aria-expanded', 'false' );
		var fields = el( 'div', 'fb-promo__fields' );
		fields.hidden = true;
		var id = 'fb-promo-' + Math.random().toString( 36 ).slice( 2, 8 );
		var label = el( 'label', 'fb-promo__label', t.promoLabel );
		label.htmlFor = id;
		var input = el( 'input' );
		input.type = 'text';
		input.id = id;
		input.autocomplete = 'off';
		input.setAttribute( 'autocapitalize', 'characters' );
		input.value = this.promoTried || '';
		var apply = el( 'button', 'fb-button fb-button--ghost', t.apply );
		apply.type = 'button';
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
			} )
			.catch( function ( err ) {
				self.promoError = err.message;
				self.renderSummary();
			} );
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
			notes: val( 'notes' ),
			terms: terms ? terms.checked : false,
			privacy_consent: consent ? consent.checked : false,
			invoice: invoice,
			locale: this.locale,
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
					// Leave the page only after the conversion was recorded.
					self.trackComplete( data, function () {
						window.location.href = data.redirect;
					} );
					return;
				}
				self.showSuccess( data );
				self.trackComplete( data, function () {} );
			} )
			.catch( function ( err ) {
				self.setNotice( err.message, true );
				// Show the current price (or why the promo code no longer applies).
				if ( err.code === 'flexo_price_changed' || ( err.code && err.code.indexOf( 'flexo_promo' ) === 0 ) ) {
					self.applyPromo( err.code === 'flexo_price_changed' ? self.promo : '' );
				}
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
		this.success.appendChild( el( 'p', 'fb-success__stay', data.room + ' · ' + this.date( data.check_in ) + ' → ' + this.date( data.check_out ) + ' · ' + data.total_formatted ) );

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
			bindAges( node.querySelector( '.fb-search' ) );
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
