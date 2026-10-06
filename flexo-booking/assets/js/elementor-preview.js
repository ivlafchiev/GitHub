/**
 * Elementor editor preview: an Icon List item showing the Amenities tag
 * becomes one item per amenity with its icon – as on the website, where the
 * plugin does the same on the server (Flexo_Booking_Elementor::icon_list()).
 */
( function () {
	'use strict';

	function expand( scope ) {
		scope.querySelectorAll( '.elementor-icon-list-item' ).forEach( function ( item ) {
			var list = item.querySelector( 'ul.flexo-room-amenities' );
			if ( ! list ) {
				return;
			}
			list.querySelectorAll( 'li.flexo-room-list__item' ).forEach( function ( amenity ) {
				var row = item.cloneNode( false );
				var icon = document.createElement( 'span' );
				var mark = document.createElement( 'i' );
				var text = document.createElement( 'span' );
				var label = amenity.querySelector( '.flexo-room-list__text' );
				var url = amenity.getAttribute( 'data-flexo-icon' ) || '';
				icon.className = 'elementor-icon-list-icon';
				mark.className = 'flexo-icon-list-icon';
				mark.setAttribute( 'aria-hidden', 'true' );
				if ( /^(data:image\/svg\+xml,|https?:\/\/)/.test( url ) ) {
					mark.style.setProperty( '--flexo-icon', 'url("' + url.replace( /"/g, '%22' ) + '")' );
				}
				icon.appendChild( mark );
				text.className = 'elementor-icon-list-text';
				text.textContent = ( label ? label.textContent : amenity.textContent ).trim();
				row.appendChild( icon );
				row.appendChild( text );
				item.parentNode.insertBefore( row, item );
			} );
			item.parentNode.removeChild( item );
		} );
	}

	var ready = false;
	function register() {
		if ( ready || ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		ready = true;
		window.elementorFrontend.hooks.addAction( 'frontend/element_ready/icon-list.default', function ( $scope ) {
			expand( $scope[ 0 ] );
		} );
	}
	window.addEventListener( 'elementor/frontend/init', register );
	register();
} )();
