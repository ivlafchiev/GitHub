/**
 * Flexo Booking admin: shared behaviour of the design system
 * (assets/css/flexo-admin.css, includes/admin/class-admin-components.php).
 *
 * - Toasts (FlexoUI.toast).
 * - Forms with a save bar: unsaved state, "Discard changes", a warning
 *   before leaving, and saving in the background (data-fx-save="ajax") or
 *   with the scroll position kept (data-fx-save="reload"). Without scripts
 *   the forms submit normally.
 * - "⋯" menus, tabs, the settings section select.
 * - Colour fields ("Website colour | Custom").
 * - Date and time fields: typed in the site's order with a pop-over
 *   calendar or time list; the submitted values stay Y-m-d and H:i.
 */
( function () {
	'use strict';
	var cfg = window.FlexoUI || {};
	var t = cfg.i18n || {};
	var api = window.FlexoUI = cfg;

	function qsa( sel, el ) {
		return Array.prototype.slice.call( ( el || document ).querySelectorAll( sel ) );
	}

	function fire( el, type ) {
		var ev;
		try {
			ev = new Event( type, { bubbles: true } );
		} catch ( err ) {
			ev = document.createEvent( 'Event' );
			ev.initEvent( type, true, false );
		}
		el.dispatchEvent( ev );
	}

	var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	/* ---------------------------------------------------------------------
	 * Toasts
	 * ------------------------------------------------------------------- */
	var toastBox = null;
	var ICONS = {
		check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		alert: '<path d="M10.3 4.5 2.9 17.5A2 2 0 0 0 4.6 20.5h14.8a2 2 0 0 0 1.7-3L13.7 4.5a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17h.01"/>',
		calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
		clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
	};

	function icon( name ) {
		return '<svg class="flexo-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[ name ] + '</svg>';
	}

	api.toast = function ( message, type ) {
		if ( ! message ) {
			return;
		}
		if ( ! toastBox ) {
			toastBox = document.createElement( 'div' );
			toastBox.className = 'fx-toasts';
			toastBox.setAttribute( 'role', 'status' );
			toastBox.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toastBox );
		}
		var box = document.createElement( 'div' );
		box.className = 'fx-toast' + ( 'error' === type ? ' fx-toast--error' : '' );
		box.innerHTML = icon( 'error' === type ? 'alert' : 'check' ) + '<span class="fx-toast__text"></span><button type="button" class="fx-toast__close"><span aria-hidden="true">×</span></button>';
		box.querySelector( '.fx-toast__text' ).textContent = message;
		box.querySelector( '.fx-toast__close' ).setAttribute( 'aria-label', t.dismiss || 'Dismiss' );
		var remove = function () {
			if ( box.parentNode ) {
				box.parentNode.removeChild( box );
			}
		};
		box.querySelector( '.fx-toast__close' ).addEventListener( 'click', remove );
		toastBox.appendChild( box );
		window.setTimeout( remove, 'error' === type ? 9000 : 4000 );
	};

	/* ---------------------------------------------------------------------
	 * Preview frames: a real device width, scaled to fit the box, so only
	 * the page inside the frame scrolls.
	 * ------------------------------------------------------------------- */
	api.fitFrame = function ( wrap, frame, width ) {
		var phone = width < 600;
		wrap.classList.toggle( 'is-phone', phone );
		var pad = phone ? 32 : 0;
		var w = Math.max( 0, wrap.clientWidth - pad );
		var h = Math.max( 0, wrap.clientHeight - pad );
		if ( ! w || ! h ) {
			return;
		}
		var scale = Math.min( 1, w / width );
		var height = phone ? Math.min( 844, h / scale ) : h / scale;
		frame.style.width = width + 'px';
		frame.style.height = Math.round( height ) + 'px';
		frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
		frame.style.marginLeft = phone && scale === 1 ? Math.round( ( w - width ) / 2 ) + 'px' : '0';
	};

	// A placeholder until the page inside the frame has loaded (again after
	// the frame is pointed at another page).
	function watchFrame( frame ) {
		var wrap = frame.parentNode;
		var loading = function () {
			wrap.classList.toggle( 'is-loading', !! frame.getAttribute( 'src' ) );
		};
		frame.addEventListener( 'load', function () {
			wrap.classList.remove( 'is-loading' );
		} );
		if ( window.MutationObserver ) {
			new window.MutationObserver( loading ).observe( frame, { attributes: true, attributeFilter: [ 'src' ] } );
		}
		loading();
		try {
			if ( frame.contentDocument && 'complete' === frame.contentDocument.readyState && frame.contentWindow.location.href !== 'about:blank' ) {
				wrap.classList.remove( 'is-loading' );
			}
		} catch ( err ) {}
	}

	/* ---------------------------------------------------------------------
	 * Forms: unsaved state, save bar, leave warning, saving
	 * ------------------------------------------------------------------- */
	var tracked = [];
	var leaving = false;

	function snapshot( form ) {
		var parts = [];
		qsa( 'input, select, textarea', form ).forEach( function ( el ) {
			if ( ! el.name || el.disabled || el.form !== form || 'file' === el.type || 'submit' === el.type || 'button' === el.type ) {
				return;
			}
			if ( ( 'checkbox' === el.type || 'radio' === el.type ) && ! el.checked ) {
				return;
			}
			if ( 'SELECT' === el.tagName && el.multiple ) {
				qsa( 'option', el ).forEach( function ( o ) {
					if ( o.selected ) {
						parts.push( el.name + '=' + o.value );
					}
				} );
				return;
			}
			parts.push( el.name + '=' + el.value );
		} );
		return parts.join( '&' );
	}

	// After saving, what the page shows is the saved state again.
	function keepAsDefaults( form ) {
		qsa( 'input, select, textarea', form ).forEach( function ( el ) {
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				el.defaultChecked = el.checked;
			} else if ( 'SELECT' === el.tagName ) {
				qsa( 'option', el ).forEach( function ( o ) {
					o.defaultSelected = o.selected;
				} );
			} else if ( 'file' !== el.type ) {
				el.defaultValue = el.value;
			}
		} );
	}

	function Tracker( form ) {
		var self = this;
		this.form = form;
		this.bar = form.querySelector( '[data-fx-savebar]' );
		this.text = this.bar ? this.bar.querySelector( '[data-fx-savebar-text]' ) : null;
		this.discard = this.bar ? this.bar.querySelector( '[data-fx-discard]' ) : null;
		this.initial = snapshot( form );
		this.dirty = false;
		this.busy = false;
		var queued = false;
		var check = function () {
			if ( queued ) {
				return;
			}
			queued = true;
			window.requestAnimationFrame( function () {
				queued = false;
				self.update();
			} );
		};
		form.addEventListener( 'input', check );
		form.addEventListener( 'change', check );
		form.addEventListener( 'fx:changed', check );
		if ( this.discard ) {
			this.discard.addEventListener( 'click', function () {
				form.reset();
				window.setTimeout( function () {
					fire( form, 'fx:reset' );
					qsa( 'input, select, textarea', form ).forEach( function ( el ) {
						if ( el.name ) {
							fire( el, 'change' );
						}
					} );
					self.initial = snapshot( form );
					self.update();
				}, 0 );
			} );
		}
		form.addEventListener( 'submit', function ( e ) {
			if ( e.defaultPrevented ) {
				return;
			}
			var mode = form.getAttribute( 'data-fx-save' );
			if ( 'ajax' === mode && window.fetch && window.DOMParser && form.id ) {
				e.preventDefault();
				self.save( e.submitter || null );
				return;
			}
			if ( 'reload' === mode ) {
				remember();
			}
			leaving = true;
			self.setBusy( true );
		} );
	}

	Tracker.prototype.update = function () {
		var dirty = ! this.busy && snapshot( this.form ) !== this.initial;
		this.dirty = dirty;
		this.form.classList.toggle( 'is-dirty', dirty );
		if ( this.bar ) {
			this.bar.classList.toggle( 'is-dirty', dirty );
			if ( this.text && ! this.busy ) {
				this.text.textContent = this.text.getAttribute( dirty ? 'data-dirty' : 'data-saved' );
			}
		}
		if ( this.discard ) {
			this.discard.disabled = ! dirty || this.busy;
		}
	};

	Tracker.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		var submit = this.bar ? this.bar.querySelector( '[type=submit]' ) : this.form.querySelector( '[type=submit]' );
		if ( submit ) {
			submit.classList.toggle( 'is-loading', busy );
			submit.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
		if ( this.text && busy ) {
			this.text.textContent = this.text.getAttribute( 'data-saving' );
		}
		if ( ! busy ) {
			this.update();
		}
	};

	Tracker.prototype.save = function ( submitter ) {
		var self = this;
		var form = this.form;
		if ( this.busy ) {
			return;
		}
		if ( form.checkValidity && ! form.checkValidity() ) {
			if ( form.reportValidity ) {
				form.reportValidity();
			}
			return;
		}
		var data = new FormData( form );
		if ( submitter && submitter.name ) {
			data.append( submitter.name, submitter.value );
		}
		var fallback = function () {
			leaving = true;
			HTMLFormElement.prototype.submit.call( form );
		};
		this.setBusy( true );
		fetch( form.getAttribute( 'action' ) || window.location.href, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( String( r.status ) );
				}
				return r.text();
			} )
			.then( function ( html ) {
				var doc = new window.DOMParser().parseFromString( html, 'text/html' );
				var fresh = doc.getElementById( form.id );
				if ( ! fresh || ! self.sync( fresh ) ) {
					fallback();
					return;
				}
				var box = notices( form );
				box.innerHTML = '';
				var errors = qsa( '.wrap .notice-error, .wrap .error.settings-error, .wrap .notice-warning.settings-error', doc );
				errors.forEach( function ( n ) {
					n.classList.remove( 'is-dismissible' );
					box.appendChild( document.importNode( n, true ) );
				} );
				var ok = doc.querySelector( '#setting-error-settings_updated, .wrap .notice-success' );
				self.initial = snapshot( form );
				self.setBusy( false );
				fire( form, 'fx:saved' );
				if ( errors.length ) {
					api.toast( errors[ 0 ].textContent.trim(), 'error' );
				} else {
					api.toast( ok ? ok.textContent.trim() : ( t.saved || 'Saved' ) );
				}
			} )
			.catch( function () {
				self.setBusy( false );
				api.toast( t.saveFailed || 'Not saved', 'error' );
			} );
	};

	// Values from the saved page (the server may have tidied them up).
	Tracker.prototype.sync = function ( fresh ) {
		var form = this.form;
		var mine = qsa( '[name]', form ).filter( function ( el ) {
			return el.form === form && 'file' !== el.type;
		} );
		var theirs = qsa( '[name]', fresh ).filter( function ( el ) {
			return ! el.hasAttribute( 'form' ) && 'file' !== el.type;
		} );
		if ( mine.length !== theirs.length ) {
			return false;
		}
		for ( var i = 0; i < mine.length; i++ ) {
			if ( mine[ i ].name !== theirs[ i ].name ) {
				return false;
			}
		}
		mine.forEach( function ( el, i ) {
			var src = theirs[ i ];
			var before = 'checkbox' === el.type || 'radio' === el.type ? el.checked : el.value;
			if ( 'checkbox' === el.type || 'radio' === el.type ) {
				el.checked = src.hasAttribute( 'checked' );
			} else if ( 'SELECT' === el.tagName ) {
				qsa( 'option', el ).forEach( function ( o, j ) {
					var so = src.options[ j ];
					o.selected = !! so && so.hasAttribute( 'selected' );
				} );
				if ( ! el.multiple && ! src.querySelector( 'option[selected]' ) && el.options.length ) {
					el.selectedIndex = 0;
				}
			} else if ( 'TEXTAREA' === el.tagName ) {
				el.value = src.value;
			} else {
				el.value = src.getAttribute( 'value' ) || '';
			}
			var after = 'checkbox' === el.type || 'radio' === el.type ? el.checked : el.value;
			if ( before !== after ) {
				fire( el, 'change' );
			}
		} );
		keepAsDefaults( form );
		// Texts that describe the saved values ("Now: …", examples) follow them.
		var texts = '.description, .fx-help, .fx-row__desc, .fx-card__desc, .flexo-muted';
		var oldTexts = qsa( texts, form );
		var newTexts = qsa( texts, fresh );
		if ( oldTexts.length === newTexts.length ) {
			oldTexts.forEach( function ( el, i ) {
				if ( ! el.querySelector( 'input, select, textarea, button' ) && el.innerHTML !== newTexts[ i ].innerHTML ) {
					el.innerHTML = newTexts[ i ].innerHTML;
				}
			} );
		}
		fire( form, 'fx:reset' );
		return true;
	};

	function notices( form ) {
		var box = form.parentNode.querySelector( '[data-fx-notices]' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.setAttribute( 'data-fx-notices', '' );
			box.setAttribute( 'role', 'alert' );
			form.parentNode.insertBefore( box, form );
		}
		return box;
	}

	// "Reload" saving: come back to the same place, with a toast.
	var RESTORE = 'flexoUiRestore';

	function pageKey() {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'settings-updated' );
		url.hash = '';
		return url.pathname + url.search;
	}

	function remember() {
		try {
			window.sessionStorage.setItem( RESTORE, JSON.stringify( { page: pageKey(), y: window.scrollY } ) );
		} catch ( err ) {}
	}

	function restore() {
		var saved = null;
		try {
			saved = JSON.parse( window.sessionStorage.getItem( RESTORE ) || 'null' );
			window.sessionStorage.removeItem( RESTORE );
		} catch ( err ) {}
		var url = new URL( window.location.href );
		var updated = url.searchParams.has( 'settings-updated' );
		if ( updated && window.history.replaceState ) {
			url.searchParams.delete( 'settings-updated' );
			window.history.replaceState( null, '', url.toString() );
		}
		if ( ! saved || saved.page !== pageKey() ) {
			return;
		}
		var errors = qsa( '.wrap .notice-error, .wrap .error.settings-error' );
		var ok = qsa( '.wrap #setting-error-settings_updated, .wrap .notice-success' );
		if ( ! errors.length && ok.length ) {
			ok.forEach( function ( n ) {
				n.parentNode.removeChild( n );
			} );
			api.toast( ok[ 0 ].textContent.trim() || t.saved );
		} else if ( errors.length ) {
			api.toast( errors[ 0 ].textContent.trim(), 'error' );
		}
		window.scrollTo( 0, saved.y );
	}

	function initForms() {
		qsa( '.flexo-admin-ui form' ).forEach( function ( form ) {
			if ( form.querySelector( '[data-fx-savebar]' ) || form.hasAttribute( 'data-fx-track' ) ) {
				tracked.push( new Tracker( form ) );
			}
		} );
		window.addEventListener( 'beforeunload', function ( e ) {
			if ( leaving ) {
				return;
			}
			var dirty = tracked.some( function ( tr ) {
				return tr.dirty;
			} );
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
		// Links inside the page clear the "leaving" flag only when they leave.
		window.addEventListener( 'pageshow', function () {
			leaving = false;
		} );
	}

	/* ---------------------------------------------------------------------
	 * Menus
	 * ------------------------------------------------------------------- */
	function menuItems( menu ) {
		return qsa( '[role=menuitem]', menu ).filter( function ( el ) {
			return ! el.disabled;
		} );
	}

	function closeMenu( menu, focus ) {
		var button = menu.querySelector( '[aria-haspopup]' );
		var panel = menu.querySelector( '.fx-menu__panel' );
		if ( panel.hidden ) {
			return;
		}
		panel.hidden = true;
		button.setAttribute( 'aria-expanded', 'false' );
		if ( focus ) {
			button.focus();
		}
	}

	function openMenu( menu ) {
		qsa( '[data-fx-menu]' ).forEach( function ( other ) {
			if ( other !== menu ) {
				closeMenu( other );
			}
		} );
		var button = menu.querySelector( '[aria-haspopup]' );
		var panel = menu.querySelector( '.fx-menu__panel' );
		panel.hidden = false;
		button.setAttribute( 'aria-expanded', 'true' );
		// Fixed to the window, so tables and cards that clip their content
		// don't cut the menu off; it opens upwards when there is no room below.
		var r = button.getBoundingClientRect();
		var start = menu.classList.contains( 'fx-menu--start' );
		panel.style.position = 'fixed';
		panel.style.left = start ? r.left + 'px' : 'auto';
		panel.style.right = start ? 'auto' : Math.max( 8, document.documentElement.clientWidth - r.right ) + 'px';
		var below = window.innerHeight - r.bottom;
		var up = below < panel.offsetHeight + 12 && r.top > below;
		panel.style.top = up ? Math.max( 8, r.top - panel.offsetHeight - 6 ) + 'px' : ( r.bottom + 6 ) + 'px';
		var items = menuItems( menu );
		if ( items[ 0 ] ) {
			items[ 0 ].focus();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-fx-menu] > [aria-haspopup]' );
		if ( button ) {
			var menu = button.parentNode;
			if ( 'true' === button.getAttribute( 'aria-expanded' ) ) {
				closeMenu( menu );
			} else {
				openMenu( menu );
			}
			return;
		}
		var item = e.target.closest( '[data-fx-menu] [role=menuitem]' );
		qsa( '[data-fx-menu]' ).forEach( function ( menu ) {
			if ( ! item || ! menu.contains( item ) || ! item.hasAttribute( 'data-flexo-confirm' ) ) {
				closeMenu( menu );
			}
		} );
		if ( item && item.hasAttribute( 'data-flexo-confirm' ) ) {
			window.setTimeout( function () {
				closeMenu( item.closest( '[data-fx-menu]' ) );
			}, 0 );
		}
	} );

	window.addEventListener( 'scroll', function () {
		qsa( '[data-fx-menu]' ).forEach( function ( menu ) {
			closeMenu( menu );
		} );
	}, { passive: true } );

	document.addEventListener( 'keydown', function ( e ) {
		var menu = e.target.closest ? e.target.closest( '[data-fx-menu]' ) : null;
		if ( ! menu ) {
			return;
		}
		var panel = menu.querySelector( '.fx-menu__panel' );
		var button = menu.querySelector( '[aria-haspopup]' );
		if ( e.target === button && ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) ) {
			e.preventDefault();
			openMenu( menu );
			return;
		}
		if ( panel.hidden ) {
			return;
		}
		var items = menuItems( menu );
		var i = items.indexOf( document.activeElement );
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			closeMenu( menu, true );
		} else if ( 'ArrowDown' === e.key ) {
			e.preventDefault();
			items[ ( i + 1 ) % items.length ].focus();
		} else if ( 'ArrowUp' === e.key ) {
			e.preventDefault();
			items[ ( i - 1 + items.length ) % items.length ].focus();
		} else if ( 'Home' === e.key ) {
			e.preventDefault();
			items[ 0 ].focus();
		} else if ( 'End' === e.key ) {
			e.preventDefault();
			items[ items.length - 1 ].focus();
		} else if ( 'Tab' === e.key ) {
			closeMenu( menu );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Tabs (arrow keys) and the section select on narrow screens
	 * ------------------------------------------------------------------- */
	document.addEventListener( 'keydown', function ( e ) {
		var tab = e.target.closest ? e.target.closest( '[role=tablist] > [role=tab]' ) : null;
		if ( ! tab || -1 === [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].indexOf( e.key ) ) {
			return;
		}
		var tabs = qsa( '[role=tab]', tab.parentNode );
		var i = tabs.indexOf( tab );
		var next = 'Home' === e.key ? 0 : 'End' === e.key ? tabs.length - 1 : ( i + ( 'ArrowRight' === e.key ? 1 : -1 ) + tabs.length ) % tabs.length;
		e.preventDefault();
		tabs[ next ].focus();
		tabs[ next ].click();
	} );

	document.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest ? e.target.closest( '[role=tablist] > [role=tab]' ) : null;
		if ( ! tab ) {
			return;
		}
		qsa( '[role=tab]', tab.parentNode ).forEach( function ( other ) {
			other.setAttribute( 'aria-selected', other === tab ? 'true' : 'false' );
			other.setAttribute( 'tabindex', other === tab ? '0' : '-1' );
		} );
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches && e.target.matches( 'select[data-fx-navigate]' ) && e.target.value ) {
			window.location.href = e.target.value;
		}
	} );

	/* ---------------------------------------------------------------------
	 * Colour fields
	 * ------------------------------------------------------------------- */
	function normalise( value ) {
		value = String( value || '' ).trim();
		if ( value && '#' !== value.charAt( 0 ) ) {
			value = '#' + value;
		}
		if ( /^#[0-9a-f]{3}$/i.test( value ) ) {
			value = '#' + value.charAt( 1 ) + value.charAt( 1 ) + value.charAt( 2 ) + value.charAt( 2 ) + value.charAt( 3 ) + value.charAt( 3 );
		}
		return /^#[0-9a-f]{6}$/i.test( value ) ? value.toLowerCase() : '';
	}

	function mix( hex, pct ) {
		var n = parseInt( hex.slice( 1 ), 16 );
		var p = pct / 100;
		var c = [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ].map( function ( v ) {
			return Math.round( v * p + 255 * ( 1 - p ) );
		} );
		return '#' + c.map( function ( v ) {
			return ( '0' + v.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function colorBox( key ) {
		return key ? document.querySelector( '[data-fx-color][data-fx-key="' + key + '"]' ) : null;
	}

	function inherited( box, depth ) {
		var from = box.getAttribute( 'data-fx-from' );
		if ( from && depth < 6 ) {
			var base = colorBox( from );
			var hex = base ? resolved( base, depth + 1 ) : '';
			var pct = parseInt( box.getAttribute( 'data-fx-mix' ) || '0', 10 );
			return hex && pct ? mix( hex, pct ) : hex;
		}
		return box.getAttribute( 'data-fx-hex' ) || '';
	}

	function resolved( box, depth ) {
		return normalise( box.querySelector( '.fx-color__hex' ).value ) || inherited( box, depth || 0 );
	}

	function paintColors() {
		qsa( '[data-fx-color]' ).forEach( function ( box ) {
			var hex = inherited( box, 0 );
			var swatch = box.querySelector( '[data-fx-color-website] .fx-swatch' );
			var label = box.querySelector( '[data-fx-color-website] .fx-color__hexlabel' );
			if ( swatch ) {
				swatch.hidden = ! hex;
				swatch.style.setProperty( '--fx-swatch', hex || 'transparent' );
			}
			if ( label ) {
				label.hidden = ! hex;
				label.textContent = hex.toUpperCase();
			}
		} );
	}

	function initColor( box ) {
		var radios = qsa( '[data-fx-color-mode]', box );
		var website = box.querySelector( '[data-fx-color-website]' );
		var custom = box.querySelector( '[data-fx-color-custom]' );
		var picker = box.querySelector( '.fx-color__picker' );
		var hex = box.querySelector( '.fx-color__hex' );
		var reset = box.querySelector( '[data-fx-color-reset]' );
		var changed = function () {
			fire( hex, 'input' );
			fire( hex, 'change' );
			paintColors();
		};
		var show = function ( mode ) {
			radios.forEach( function ( r ) {
				r.checked = r.value === mode;
			} );
			website.hidden = 'custom' === mode;
			custom.hidden = 'custom' !== mode;
		};
		radios.forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				if ( ! r.checked ) {
					return;
				}
				show( r.value );
				if ( 'custom' === r.value ) {
					var start = normalise( hex.value ) || resolved( box ) || picker.value;
					picker.value = start;
					hex.value = start;
					hex.focus();
				} else {
					hex.value = '';
				}
				changed();
			} );
		} );
		picker.addEventListener( 'input', function () {
			hex.value = picker.value;
			changed();
		} );
		hex.addEventListener( 'input', function () {
			var v = normalise( hex.value );
			if ( v ) {
				picker.value = v;
				paintColors();
			}
		} );
		hex.addEventListener( 'blur', function () {
			var v = normalise( hex.value );
			if ( v !== hex.value ) {
				hex.value = v;
				fire( hex, 'change' );
			}
			if ( ! v ) {
				show( 'website' );
			}
			paintColors();
		} );
		if ( reset ) {
			reset.addEventListener( 'click', function () {
				hex.value = '';
				show( 'website' );
				changed();
				var first = radios[ 0 ];
				if ( first ) {
					first.focus();
				}
			} );
		}
		var form = hex.form;
		if ( form ) {
			form.addEventListener( 'fx:reset', function () {
				var v = normalise( hex.value );
				if ( v ) {
					picker.value = v;
				}
				show( v ? 'custom' : 'website' );
				paintColors();
			} );
		}
	}

	/* ---------------------------------------------------------------------
	 * Date and time fields
	 * ------------------------------------------------------------------- */
	var DATE = cfg.dateFormat || 'Y-m-d';
	var locale = ( document.documentElement.lang || 'en' ).replace( '_', '-' );
	var startOfWeek = parseInt( cfg.startOfWeek || '1', 10 ) || 0;
	var picker = null;
	var current = null;

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function iso( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	function fromIso( value ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( value || '' ) );
		if ( ! m ) {
			return null;
		}
		var d = new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] );
		return d.getMonth() === +m[ 2 ] - 1 ? d : null;
	}

	function format( d, fmt ) {
		if ( ! d ) {
			return '';
		}
		return fmt.replace( 'Y', d.getFullYear() ).replace( 'm', pad( d.getMonth() + 1 ) ).replace( 'd', pad( d.getDate() ) );
	}

	function parse( value, fmt ) {
		value = String( value || '' ).trim();
		if ( ! value ) {
			return null;
		}
		var direct = fromIso( value );
		if ( direct ) {
			return direct;
		}
		var nums = value.split( /[^0-9]+/ ).filter( Boolean ).map( Number );
		if ( nums.length !== 3 ) {
			return null;
		}
		var order = fmt.replace( /[^Ymd]/g, '' );
		var y = nums[ order.indexOf( 'Y' ) ];
		var m = nums[ order.indexOf( 'm' ) ];
		var dd = nums[ order.indexOf( 'd' ) ];
		if ( y < 100 ) {
			y += 2000;
		}
		var d = new Date( y, m - 1, dd );
		return d.getMonth() === m - 1 && d.getDate() === dd ? d : null;
	}

	function placeholder( fmt ) {
		return ( t.datePlaceholder && t.datePlaceholder[ fmt ] ) || fmt.replace( 'Y', 'yyyy' ).replace( 'm', 'mm' ).replace( 'd', 'dd' );
	}

	function closePicker( focus ) {
		if ( ! picker || picker.hidden ) {
			return;
		}
		picker.hidden = true;
		if ( current ) {
			current.button.setAttribute( 'aria-expanded', 'false' );
			if ( focus ) {
				current.input.focus();
			}
		}
		current = null;
	}

	function place( field ) {
		var r = field.input.getBoundingClientRect();
		var top = r.bottom + window.scrollY + 4;
		var left = r.left + window.scrollX;
		picker.style.top = top + 'px';
		picker.style.left = '0px';
		var w = picker.offsetWidth;
		left = Math.max( 8, Math.min( left, document.documentElement.clientWidth - w - 8 ) );
		picker.style.left = left + 'px';
	}

	function ensurePicker() {
		if ( picker ) {
			return;
		}
		picker = document.createElement( 'div' );
		picker.className = 'fx-picker';
		picker.hidden = true;
		picker.setAttribute( 'role', 'dialog' );
		document.body.appendChild( picker );
		document.addEventListener( 'mousedown', function ( e ) {
			if ( current && ! picker.contains( e.target ) && ! current.wrap.contains( e.target ) ) {
				closePicker();
			}
		} );
		picker.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				e.stopPropagation();
				closePicker( true );
			}
		} );
		window.addEventListener( 'resize', function () {
			closePicker();
		} );
	}

	function setDate( field, d ) {
		field.input.value = format( d, field.fmt );
		field.input.setCustomValidity( '' );
		if ( field.hidden ) {
			field.hidden.value = d ? iso( d ) : '';
			fire( field.hidden, 'change' );
		}
		fire( field.input, 'input' );
		fire( field.input, 'change' );
	}

	function renderMonth( field, view, focusDay ) {
		var selected = parse( field.input.value, field.fmt );
		var min = fromIso( field.min );
		var max = fromIso( field.max );
		var today = new Date();
		today.setHours( 0, 0, 0, 0 );
		var month = new Intl.DateTimeFormat( locale, { month: 'long', year: 'numeric' } ).format( view );
		var wd = new Intl.DateTimeFormat( locale, { weekday: 'short' } );
		var html = '<div class="fx-picker__head"><button type="button" class="fx-picker__nav" data-step="-1" aria-label="' + ( t.prevMonth || 'Previous month' ) + '">‹</button><span class="fx-picker__month" aria-live="polite">' + month + '</span><button type="button" class="fx-picker__nav" data-step="1" aria-label="' + ( t.nextMonth || 'Next month' ) + '">›</button></div><div class="fx-picker__grid">';
		for ( var i = 0; i < 7; i++ ) {
			var day = new Date( 2024, 0, 7 + ( ( startOfWeek + i ) % 7 ) );
			html += '<span class="fx-picker__dow" aria-hidden="true">' + wd.format( day ) + '</span>';
		}
		var first = new Date( view.getFullYear(), view.getMonth(), 1 );
		var offset = ( first.getDay() - startOfWeek + 7 ) % 7;
		var start = new Date( first.getFullYear(), first.getMonth(), 1 - offset );
		var full = new Intl.DateTimeFormat( locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
		for ( var k = 0; k < 42; k++ ) {
			var d = new Date( start.getFullYear(), start.getMonth(), start.getDate() + k );
			var cls = 'fx-picker__day' + ( d.getMonth() !== view.getMonth() ? ' is-other' : '' ) + ( d.getTime() === today.getTime() ? ' is-today' : '' );
			var on = selected && d.getTime() === selected.getTime();
			var off = ( min && d < min ) || ( max && d > max );
			html += '<button type="button" class="' + cls + '" data-day="' + iso( d ) + '" aria-label="' + full.format( d ) + '" aria-pressed="' + ( on ? 'true' : 'false' ) + '" tabindex="-1"' + ( off ? ' disabled' : '' ) + '>' + d.getDate() + '</button>';
		}
		html += '</div><div class="fx-picker__foot"><button type="button" data-today>' + ( t.today || 'Today' ) + '</button>' + ( field.required ? '' : '<button type="button" data-clear>' + ( t.clear || 'Clear' ) + '</button>' ) + '</div>';
		picker.innerHTML = html;
		picker.className = 'fx-picker';
		picker.setAttribute( 'aria-label', t.chooseDate || 'Choose a date' );
		var target = focusDay || selected || ( today.getMonth() === view.getMonth() && today.getFullYear() === view.getFullYear() ? today : first );
		var btn = picker.querySelector( '[data-day="' + iso( target ) + '"]' ) || picker.querySelector( '.fx-picker__day:not(.is-other)' );
		if ( btn ) {
			btn.tabIndex = 0;
		}
		picker.onclick = function ( e ) {
			var nav = e.target.closest( '[data-step]' );
			if ( nav ) {
				renderMonth( field, new Date( view.getFullYear(), view.getMonth() + Number( nav.getAttribute( 'data-step' ) ), 1 ) );
				picker.querySelector( '[data-step="' + nav.getAttribute( 'data-step' ) + '"]' ).focus();
				return;
			}
			var dayBtn = e.target.closest( '[data-day]' );
			if ( dayBtn && ! dayBtn.disabled ) {
				setDate( field, fromIso( dayBtn.getAttribute( 'data-day' ) ) );
				closePicker( true );
				return;
			}
			if ( e.target.closest( '[data-today]' ) ) {
				setDate( field, today );
				closePicker( true );
				return;
			}
			if ( e.target.closest( '[data-clear]' ) ) {
				setDate( field, null );
				closePicker( true );
			}
		};
		picker.onkeydown = function ( e ) {
			var dayBtn = e.target.closest ? e.target.closest( '[data-day]' ) : null;
			if ( ! dayBtn ) {
				return;
			}
			var d = fromIso( dayBtn.getAttribute( 'data-day' ) );
			var move = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[ e.key ];
			var next = null;
			if ( move ) {
				next = new Date( d.getFullYear(), d.getMonth(), d.getDate() + move );
			} else if ( 'PageUp' === e.key || 'PageDown' === e.key ) {
				next = new Date( d.getFullYear(), d.getMonth() + ( 'PageUp' === e.key ? -1 : 1 ), d.getDate() );
			} else if ( 'Home' === e.key || 'End' === e.key ) {
				var dow = ( d.getDay() - startOfWeek + 7 ) % 7;
				next = new Date( d.getFullYear(), d.getMonth(), d.getDate() + ( 'Home' === e.key ? -dow : 6 - dow ) );
			}
			if ( ! next ) {
				return;
			}
			e.preventDefault();
			if ( next.getMonth() !== view.getMonth() || next.getFullYear() !== view.getFullYear() ) {
				renderMonth( field, new Date( next.getFullYear(), next.getMonth(), 1 ), next );
			}
			var nb = picker.querySelector( '[data-day="' + iso( next ) + '"]' );
			if ( nb ) {
				qsa( '.fx-picker__day', picker ).forEach( function ( b ) {
					b.tabIndex = -1;
				} );
				nb.tabIndex = 0;
				nb.focus();
			}
		};
		return btn;
	}

	function openDate( field, focusPicker ) {
		ensurePicker();
		if ( current === field && ! picker.hidden ) {
			return;
		}
		closePicker();
		current = field;
		var sel = parse( field.input.value, field.fmt ) || fromIso( field.min ) || new Date();
		var btn = renderMonth( field, new Date( sel.getFullYear(), sel.getMonth(), 1 ) );
		picker.hidden = false;
		field.button.setAttribute( 'aria-expanded', 'true' );
		place( field );
		if ( focusPicker && btn ) {
			btn.focus();
		}
	}

	function openTime( field, focusPicker ) {
		ensurePicker();
		if ( current === field && ! picker.hidden ) {
			return;
		}
		closePicker();
		current = field;
		var step = field.step || 30;
		var html = '';
		var value = normaliseTime( field.input.value );
		for ( var m = 0; m < 1440; m += step ) {
			var v = pad( Math.floor( m / 60 ) ) + ':' + pad( m % 60 );
			html += '<button type="button" role="option" data-time="' + v + '" aria-selected="' + ( v === value ? 'true' : 'false' ) + '" tabindex="-1">' + v + '</button>';
		}
		picker.className = 'fx-picker fx-times';
		picker.setAttribute( 'aria-label', t.chooseTime || 'Choose a time' );
		picker.innerHTML = '<div role="listbox" aria-label="' + ( t.chooseTime || 'Choose a time' ) + '">' + html + '</div>';
		picker.hidden = false;
		field.button.setAttribute( 'aria-expanded', 'true' );
		place( field );
		var on = picker.querySelector( '[aria-selected=true]' ) || picker.querySelector( '[data-time="' + ( value ? value.slice( 0, 2 ) : '12' ) + ':00"]' ) || picker.querySelector( '[data-time]' );
		on.tabIndex = 0;
		picker.scrollTop = on.offsetTop - 80;
		if ( focusPicker ) {
			on.focus();
		}
		picker.onclick = function ( e ) {
			var b = e.target.closest( '[data-time]' );
			if ( b ) {
				field.input.value = b.getAttribute( 'data-time' );
				fire( field.input, 'input' );
				fire( field.input, 'change' );
				closePicker( true );
			}
		};
		picker.onkeydown = function ( e ) {
			var b = e.target.closest ? e.target.closest( '[data-time]' ) : null;
			if ( ! b || ( 'ArrowDown' !== e.key && 'ArrowUp' !== e.key ) ) {
				return;
			}
			e.preventDefault();
			var next = 'ArrowDown' === e.key ? b.nextElementSibling : b.previousElementSibling;
			if ( next ) {
				b.tabIndex = -1;
				next.tabIndex = 0;
				next.focus();
			}
		};
	}

	function normaliseTime( value ) {
		value = String( value || '' ).trim();
		if ( ! value ) {
			return '';
		}
		var m = /^(\d{1,2})(?:[:.h ]?(\d{2}))?$/.exec( value );
		if ( ! m ) {
			return null;
		}
		var h = +m[ 1 ];
		var min = m[ 2 ] ? +m[ 2 ] : 0;
		return h < 24 && min < 60 ? pad( h ) + ':' + pad( min ) : null;
	}

	function addButton( field, kind ) {
		var wrap = field.input.parentNode;
		if ( ! wrap.classList.contains( 'fx-datefield' ) ) {
			wrap = document.createElement( 'span' );
			wrap.className = 'fx-datefield' + ( 'time' === kind ? ' fx-datefield--time' : '' );
			field.input.parentNode.insertBefore( wrap, field.input );
			wrap.appendChild( field.input );
		}
		field.wrap = wrap;
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'fx-datefield__btn';
		button.setAttribute( 'aria-label', 'time' === kind ? ( t.chooseTime || 'Choose a time' ) : ( t.chooseDate || 'Choose a date' ) );
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.innerHTML = icon( 'time' === kind ? 'clock' : 'calendar' );
		wrap.appendChild( button );
		field.button = button;
		var open = 'time' === kind ? openTime : openDate;
		button.addEventListener( 'click', function () {
			if ( current === field && ! picker.hidden ) {
				closePicker( true );
			} else {
				open( field, true );
			}
		} );
		field.input.addEventListener( 'click', function () {
			open( field, false );
		} );
		field.input.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key && e.altKey ) {
				e.preventDefault();
				open( field, true );
			} else if ( 'Escape' === e.key ) {
				closePicker();
			} else if ( 'Tab' === e.key ) {
				closePicker();
			}
		} );
	}

	function initDate( input ) {
		var field = { fmt: input.getAttribute( 'data-fx-date' ) || DATE, input: input, hidden: null, required: input.required, min: input.min || '', max: input.max || '' };
		if ( input.classList.contains( 'flexo-date' ) && ! input.getAttribute( 'data-fx-date' ) ) {
			field.fmt = 'd.m.Y';
		}
		if ( 'date' === input.type ) {
			var text = document.createElement( 'input' );
			text.type = 'text';
			text.className = input.className;
			text.id = input.id;
			text.required = input.required;
			text.autocomplete = 'off';
			text.inputMode = 'numeric';
			text.placeholder = placeholder( field.fmt );
			text.setAttribute( 'data-fx-date-for', input.name );
			[ 'aria-label', 'aria-describedby', 'form' ].forEach( function ( a ) {
				if ( input.hasAttribute( a ) ) {
					text.setAttribute( a, input.getAttribute( a ) );
				}
			} );
			if ( input.getAttribute( 'form' ) ) {
				text.setAttribute( 'form', 'fx-no-submit' );
			}
			text.value = text.defaultValue = format( fromIso( input.value ), field.fmt );
			input.removeAttribute( 'id' );
			input.required = false;
			input.type = 'hidden';
			input.parentNode.insertBefore( text, input );
			field.hidden = input;
			field.input = text;
			var syncHidden = function () {
				var d = parse( text.value, field.fmt );
				var value = d ? iso( d ) : '';
				text.setCustomValidity( text.value.trim() && ! d ? ( t.badDate || 'Enter a date like ' ) + placeholder( field.fmt ) : '' );
				if ( input.value !== value ) {
					input.value = value;
				}
			};
			text.addEventListener( 'input', syncHidden );
			text.addEventListener( 'change', function () {
				syncHidden();
				var d = parse( text.value, field.fmt );
				if ( d ) {
					text.value = format( d, field.fmt );
				}
			} );
			var form = text.form;
			if ( form ) {
				form.addEventListener( 'fx:reset', syncHidden );
				form.addEventListener( 'reset', function () {
					window.setTimeout( syncHidden, 0 );
				} );
			}
		} else {
			input.addEventListener( 'change', function () {
				var d = parse( input.value, field.fmt );
				if ( d ) {
					input.value = format( d, field.fmt );
				}
			} );
		}
		addButton( field, 'date' );
	}

	function initTime( input ) {
		if ( 'time' === input.type ) {
			input.type = 'text';
			input.inputMode = 'numeric';
			input.maxLength = 5;
			input.autocomplete = 'off';
			input.placeholder = input.placeholder || '14:00';
			input.classList.add( 'fx-datefield__input' );
		}
		var field = { input: input, step: parseInt( input.getAttribute( 'data-fx-time' ) || '30', 10 ) || 30 };
		input.addEventListener( 'change', function () {
			var v = normaliseTime( input.value );
			input.setCustomValidity( null === v ? ( t.badTime || 'Enter a time like 14:00' ) : '' );
			if ( v ) {
				input.value = v;
			}
		} );
		addButton( field, 'time' );
	}

	/* ---------------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------------- */
	function start() {
		if ( ! document.body.classList.contains( 'flexo-admin-ui' ) ) {
			return;
		}
		qsa( '.flexo-appearance__frame-wrap > iframe' ).forEach( watchFrame );
		qsa( '[data-fx-color]' ).forEach( initColor );
		paintColors();
		qsa( '.wrap input[type=date], .wrap input[data-fx-date], .wrap input.flexo-date' ).forEach( initDate );
		qsa( '.wrap input[type=time], .wrap input[data-fx-time]' ).forEach( initTime );
		// Snapshots after the screen's own scripts have set their fields up.
		window.setTimeout( initForms, 0 );
		restore();
		if ( reduced ) {
			document.documentElement.classList.add( 'fx-reduced-motion' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
