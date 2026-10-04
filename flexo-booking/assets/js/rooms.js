/**
 * Room photo galleries: carousel (scroll-snap with arrows, dots, keyboard,
 * optional autoplay) and a light lightbox for pages without Elementor's.
 * No dependencies.
 */
( function () {
	'use strict';

	var t = ( window.FlexoRooms && window.FlexoRooms.i18n ) || {};
	var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text || '' ).replace( /%(\d+\$)?[sd]/g, function ( m, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return args[ index ] !== undefined ? args[ index ] : '';
		} );
	}

	/* ---- Carousel ---- */
	function carousel( root ) {
		if ( root.getAttribute( 'data-flexo-ready' ) ) {
			return;
		}
		root.setAttribute( 'data-flexo-ready', '1' );
		var track = root.querySelector( '.flexo-room-gallery__track' );
		var slides = Array.prototype.slice.call( track.children );
		var dots = root.querySelector( '.flexo-room-gallery__dots' );
		var prev = root.querySelector( '[data-flexo-dir="-1"]' );
		var next = root.querySelector( '[data-flexo-dir="1"]' );
		var rtl = 'rtl' === getComputedStyle( root ).direction;
		var timer = null;

		function perView() {
			var n = parseInt( getComputedStyle( root ).getPropertyValue( '--flexo-per-view' ), 10 );
			return n > 0 ? n : 1;
		}
		function step() {
			return slides.length > 1 ? Math.abs( slides[ 1 ].offsetLeft - slides[ 0 ].offsetLeft ) : track.clientWidth;
		}
		function index() {
			return Math.round( Math.abs( track.scrollLeft ) / ( step() || 1 ) );
		}
		function pages() {
			return Math.max( 1, Math.ceil( ( slides.length - perView() ) / perView() ) + 1 );
		}
		function goTo( i ) {
			var max = Math.max( 0, slides.length - perView() );
			i = Math.max( 0, Math.min( max, i ) );
			track.scrollTo( { left: ( rtl ? -1 : 1 ) * i * step(), behavior: reduced ? 'auto' : 'smooth' } );
		}
		function update() {
			var i = index();
			var max = Math.max( 0, slides.length - perView() );
			if ( prev ) {
				prev.disabled = i <= 0;
			}
			if ( next ) {
				next.disabled = i >= max;
			}
			if ( dots ) {
				var page = i >= max ? pages() - 1 : Math.floor( i / perView() );
				Array.prototype.forEach.call( dots.children, function ( dot, d ) {
					dot.setAttribute( 'aria-current', d === page ? 'true' : 'false' );
				} );
			}
		}
		function buildDots() {
			if ( ! dots ) {
				return;
			}
			dots.innerHTML = '';
			var count = pages();
			if ( count < 2 ) {
				return;
			}
			for ( var p = 0; p < count; p++ ) {
				var b = document.createElement( 'button' );
				b.type = 'button';
				b.className = 'flexo-room-gallery__dot';
				b.setAttribute( 'aria-label', fmt( t.goTo, p + 1 ) );
				b.setAttribute( 'data-page', p );
				dots.appendChild( b );
			}
		}

		root.addEventListener( 'click', function ( e ) {
			var arrow = e.target.closest( '[data-flexo-dir]' );
			var dot = e.target.closest( '.flexo-room-gallery__dot' );
			if ( arrow ) {
				stop();
				goTo( index() + parseInt( arrow.getAttribute( 'data-flexo-dir' ), 10 ) * perView() );
			} else if ( dot ) {
				stop();
				goTo( parseInt( dot.getAttribute( 'data-page' ), 10 ) * perView() );
			}
		} );
		track.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowRight' === e.key || 'ArrowLeft' === e.key ) {
				e.preventDefault();
				stop();
				var dir = 'ArrowRight' === e.key ? 1 : -1;
				goTo( index() + ( rtl ? -dir : dir ) );
			}
		} );
		var ticking = false;
		track.addEventListener( 'scroll', function () {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( function () {
					ticking = false;
					update();
				} );
			}
		}, { passive: true } );
		window.addEventListener( 'resize', function () {
			buildDots();
			update();
		} );

		// Autoplay: off for visitors who prefer less motion, stops on any touch.
		var delay = parseInt( root.getAttribute( 'data-autoplay' ), 10 );
		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}
		if ( delay > 0 && ! reduced ) {
			timer = window.setInterval( function () {
				var max = Math.max( 0, slides.length - perView() );
				goTo( index() >= max ? 0 : index() + perView() );
			}, Math.max( 2000, delay ) );
			[ 'pointerdown', 'focusin', 'wheel' ].forEach( function ( type ) {
				root.addEventListener( type, stop, { passive: true } );
			} );
		}

		buildDots();
		update();
	}

	/* ---- Lightbox ---- */
	var box = null;
	var items = [];
	var current = 0;

	function lightbox() {
		if ( box ) {
			return box;
		}
		box = document.createElement( 'dialog' );
		box.className = 'flexo-lightbox';
		box.innerHTML =
			'<div class="flexo-lightbox__stage"><img class="flexo-lightbox__img" alt=""></div>' +
			'<span class="flexo-lightbox__count" aria-live="polite"></span>' +
			'<p class="flexo-lightbox__caption"></p>' +
			'<button type="button" class="flexo-lightbox__btn flexo-lightbox__close">&times;</button>' +
			'<button type="button" class="flexo-lightbox__btn flexo-lightbox__prev">&lsaquo;</button>' +
			'<button type="button" class="flexo-lightbox__btn flexo-lightbox__next">&rsaquo;</button>';
		box.querySelector( '.flexo-lightbox__close' ).setAttribute( 'aria-label', t.close || 'Close' );
		box.querySelector( '.flexo-lightbox__prev' ).setAttribute( 'aria-label', t.previous || 'Previous' );
		box.querySelector( '.flexo-lightbox__next' ).setAttribute( 'aria-label', t.next || 'Next' );
		document.body.appendChild( box );
		box.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.flexo-lightbox__close' ) || e.target.classList.contains( 'flexo-lightbox__stage' ) ) {
				box.close();
			} else if ( e.target.closest( '.flexo-lightbox__prev' ) ) {
				show( current - 1 );
			} else if ( e.target.closest( '.flexo-lightbox__next' ) ) {
				show( current + 1 );
			}
		} );
		box.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowRight' === e.key ) {
				show( current + 1 );
			} else if ( 'ArrowLeft' === e.key ) {
				show( current - 1 );
			}
		} );
		var startX = null;
		box.addEventListener( 'pointerdown', function ( e ) {
			startX = e.clientX;
		} );
		box.addEventListener( 'pointerup', function ( e ) {
			if ( null !== startX && Math.abs( e.clientX - startX ) > 50 ) {
				show( current + ( e.clientX < startX ? 1 : -1 ) );
			}
			startX = null;
		} );
		return box;
	}

	function show( i ) {
		current = ( i + items.length ) % items.length;
		var link = items[ current ];
		var img = link.querySelector( 'img' );
		var dialog = lightbox();
		var big = dialog.querySelector( '.flexo-lightbox__img' );
		big.src = link.href;
		big.alt = img ? img.alt : '';
		dialog.querySelector( '.flexo-lightbox__caption' ).textContent = img ? img.alt : '';
		dialog.querySelector( '.flexo-lightbox__count' ).textContent = fmt( t.photoOf || '%1$d / %2$d', current + 1, items.length );
		var single = items.length < 2;
		dialog.querySelector( '.flexo-lightbox__prev' ).hidden = single;
		dialog.querySelector( '.flexo-lightbox__next' ).hidden = single;
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest ? e.target.closest( '[data-flexo-lightbox] .flexo-room-gallery__link' ) : null;
		if ( ! link || e.ctrlKey || e.metaKey || e.shiftKey ) {
			return;
		}
		var dialog = lightbox();
		if ( ! dialog.showModal ) {
			return; // Very old browser: open the photo itself.
		}
		e.preventDefault();
		items = Array.prototype.slice.call( link.closest( '[data-flexo-lightbox]' ).querySelectorAll( '.flexo-room-gallery__link' ) );
		show( items.indexOf( link ) );
		dialog.showModal();
		dialog.querySelector( '.flexo-lightbox__close' ).focus();
	} );

	/* ---- "Check availability" bar on phones ---- */
	function phoneBar() {
		var bar = document.querySelector( '[data-flexo-room-bar]' );
		if ( ! bar ) {
			return;
		}
		var box = document.querySelector( '[data-flexo-booking-box]' );
		bar.hidden = false;
		document.body.classList.add( 'flexo-has-room-bar' );
		if ( box ) {
			// Go to the booking box on this page instead of leaving it.
			bar.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				box.scrollIntoView( { behavior: reduced ? 'auto' : 'smooth', block: 'start' } );
				var first = box.querySelector( '.fb-date' ) || box.querySelector( 'input[name="check_in"]' );
				if ( first ) {
					window.setTimeout( function () {
						first.focus( { preventScroll: true } );
					}, reduced ? 0 : 450 );
				}
			} );
			if ( 'IntersectionObserver' in window ) {
				new IntersectionObserver( function ( entries ) {
					bar.classList.toggle( 'is-away', entries[ 0 ].isIntersecting );
				} ).observe( box );
			}
		}
	}

	/* ---- Start ---- */
	function init( scope ) {
		Array.prototype.forEach.call( ( scope || document ).querySelectorAll( '[data-flexo-carousel]' ), carousel );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
			phoneBar();
		} );
	} else {
		init();
		phoneBar();
	}
	// Elementor editor: widgets are re-rendered while the page is edited.
	function hookElementor() {
		if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
			window.elementorFrontend.hooks.addAction( 'frontend/element_ready/flexo-room-gallery.default', function ( $scope ) {
				init( $scope && $scope[ 0 ] ? $scope[ 0 ] : document );
			} );
		}
	}
	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor/frontend/init', hookElementor );
	}
} )();
