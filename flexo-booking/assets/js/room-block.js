/**
 * "Room page" block for block themes' Single room template (Site Editor).
 * Rendered by PHP on the website; the editor shows a placeholder.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var t = window.FlexoRoomBlock || {};
	wp.blocks.registerBlockType( 'flexo-booking/room', {
		apiVersion: 3,
		title: t.title || 'Room page',
		description: t.description || '',
		category: 'theme',
		icon: 'building',
		supports: { html: false, multiple: false },
		edit: function () {
			var props = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps( { className: 'flexo-room-block-placeholder' } ) : {};
			return el(
				'div',
				props,
				el( 'strong', null, t.title || 'Room page' ),
				el( 'p', null, t.description || '' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
