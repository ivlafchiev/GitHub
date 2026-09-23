/**
 * Flexo Booking – Calendar Sync screen: copy and reset export links.
 */
( function () {
	'use strict';
	var t = window.FlexoBookingSync || {};

	document.querySelectorAll( '[data-flexo-copy]' ).forEach( function ( button ) {
		button.addEventListener( 'click', function () {
			var input = button.parentNode.querySelector( 'input' );
			var label = button.textContent;
			var done = function () {
				button.textContent = t.copied || 'Copied!';
				setTimeout( function () {
					button.textContent = label;
				}, 2000 );
			};
			input.select();
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( input.value ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	document.querySelectorAll( '[data-flexo-reset]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( t.confirmReset ) ) {
				e.preventDefault();
			}
		} );
	} );
}() );
