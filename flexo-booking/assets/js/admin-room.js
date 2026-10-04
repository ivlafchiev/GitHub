/**
 * Room screen: photos, room types, amenities with icons, more details,
 * search engine preview and copy buttons.
 */
( function ( $ ) {
	'use strict';

	var t = ( window.FlexoRoom && window.FlexoRoom.i18n ) || {};

	function fmt( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( text ).replace( /%(\d+\$)?[sd]/g, function ( m, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : i++;
			return args[ index ] !== undefined ? args[ index ] : '';
		} );
	}

	function qs( sel, root ) {
		return ( root || document ).querySelector( sel );
	}

	function qsa( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	}

	function fromTemplate( id ) {
		var tpl = document.getElementById( id );
		return tpl ? tpl.content.firstElementChild.cloneNode( true ) : null;
	}

	/* ---- Character counters ---- */
	function counter( field ) {
		var max = parseInt( field.getAttribute( 'data-flexo-count' ), 10 );
		var line = field.closest( '.flexo-field, .inside' );
		var out = line ? line.querySelector( '.flexo-counter' ) : null;
		if ( ! out ) {
			return;
		}
		var update = function () {
			var n = field.value.length;
			out.textContent = n ? fmt( t.count || '%1$d / %2$d', n, max ) + ( n > max ? ' – ' + ( t.tooLong || '' ) : '' ) : '';
			out.classList.toggle( 'is-over', n > max );
		};
		field.addEventListener( 'input', update );
		update();
	}
	qsa( '[data-flexo-count]' ).forEach( counter );

	/* ---- Media frames ---- */
	function mediaFrame( options, onSelect ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( options );
		frame.on( 'select', function () {
			onSelect( frame.state().get( 'selection' ).toJSON() );
		} );
		frame.open();
	}

	function thumbUrl( att, size ) {
		if ( att.sizes && att.sizes[ size ] ) {
			return att.sizes[ size ].url;
		}
		if ( att.sizes && att.sizes.thumbnail ) {
			return att.sizes.thumbnail.url;
		}
		return att.url;
	}

	/* ---- Main photo ---- */
	var main = qs( '[data-flexo-main]' );
	if ( main ) {
		var mainInput = qs( 'input[name="flexo_main_photo"]' );
		var mainPick = qs( '[data-flexo-main-pick]', main );
		var setMain = function ( att ) {
			var old = qs( 'img', mainPick );
			if ( old ) {
				old.remove();
			}
			if ( att ) {
				var img = document.createElement( 'img' );
				img.src = thumbUrl( att, 'medium' );
				img.alt = '';
				mainPick.insertBefore( img, mainPick.firstChild );
			}
			mainInput.value = att ? att.id : '';
			main.classList.toggle( 'has-photo', !! att );
		};
		mainPick.addEventListener( 'click', function () {
			mediaFrame( { title: t.mainPhoto, button: { text: t.usePhoto }, library: { type: 'image' }, multiple: false }, function ( list ) {
				setMain( list[ 0 ] );
			} );
		} );
		qs( '[data-flexo-main-remove]', main ).addEventListener( 'click', function () {
			setMain( null );
		} );
	}

	/* ---- Gallery ---- */
	var gallery = qs( '[data-flexo-gallery]' );
	if ( gallery ) {
		var galleryInput = qs( 'input[name="flexo_gallery"]' );
		var galleryCount = qs( '[data-flexo-gallery-count]' );
		var addTile = qs( '.flexo-gallery__add', gallery );
		var syncGallery = function () {
			var ids = qsa( '.flexo-gallery__item', gallery ).map( function ( li ) {
				return li.getAttribute( 'data-id' );
			} );
			galleryInput.value = ids.join( ',' );
			if ( galleryCount ) {
				galleryCount.textContent = ids.length ? fmt( t.photos || '(%d)', ids.length ) : '';
			}
		};
		var addPhoto = function ( att ) {
			if ( qs( '.flexo-gallery__item[data-id="' + att.id + '"]', gallery ) ) {
				return;
			}
			var li = document.createElement( 'li' );
			li.className = 'flexo-gallery__item';
			li.setAttribute( 'data-id', att.id );
			var img = document.createElement( 'img' );
			img.src = thumbUrl( att, 'thumbnail' );
			img.alt = '';
			var rm = document.createElement( 'button' );
			rm.type = 'button';
			rm.className = 'flexo-gallery__remove';
			rm.setAttribute( 'data-flexo-gallery-remove', '' );
			rm.setAttribute( 'aria-label', qs( '[data-flexo-gallery-remove]' ) ? qs( '[data-flexo-gallery-remove]' ).getAttribute( 'aria-label' ) : '×' );
			rm.innerHTML = '&times;';
			li.appendChild( img );
			li.appendChild( rm );
			gallery.insertBefore( li, addTile );
		};
		qs( '[data-flexo-gallery-add]', gallery ).addEventListener( 'click', function () {
			mediaFrame( { title: t.gallery, button: { text: t.addPhotos }, library: { type: 'image' }, multiple: 'add' }, function ( list ) {
				list.forEach( addPhoto );
				syncGallery();
			} );
		} );
		gallery.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '[data-flexo-gallery-remove]' );
			if ( rm ) {
				rm.closest( '.flexo-gallery__item' ).remove();
				syncGallery();
			}
		} );
		if ( $.fn.sortable ) {
			$( gallery ).sortable( {
				items: '> .flexo-gallery__item',
				tolerance: 'pointer',
				placeholder: 'flexo-gallery__placeholder',
				update: syncGallery,
			} );
		}
		syncGallery();
	}

	/* ---- Room types ---- */
	var types = qs( '[data-flexo-types]' );
	if ( types ) {
		var typeInput = qs( '[data-flexo-type-new]', types );
		var addType = function () {
			var name = typeInput.value.trim();
			if ( ! name ) {
				return;
			}
			var exists = qsa( '.flexo-check-chip span', types ).some( function ( s ) {
				return s.textContent.trim().toLowerCase() === name.toLowerCase();
			} );
			if ( exists ) {
				typeInput.setCustomValidity( t.typeExists || '' );
				typeInput.reportValidity();
				return;
			}
			var label = document.createElement( 'label' );
			label.className = 'flexo-check-chip';
			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.name = 'flexo_new_room_types[]';
			box.value = name;
			box.checked = true;
			var span = document.createElement( 'span' );
			span.textContent = name;
			label.appendChild( box );
			label.appendChild( document.createTextNode( ' ' ) );
			label.appendChild( span );
			types.insertBefore( label, qs( '.flexo-type-add', types ) );
			typeInput.value = '';
		};
		typeInput.addEventListener( 'input', function () {
			typeInput.setCustomValidity( '' );
		} );
		qs( '[data-flexo-type-add]', types ).addEventListener( 'click', addType );
		typeInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				addType();
			}
		} );
	}

	/* ---- Sortable lists (amenities, details) ---- */
	function initList( list, empty, onChange ) {
		var refresh = function () {
			if ( empty ) {
				empty.hidden = !! list.children.length;
			}
			if ( onChange ) {
				onChange();
			}
		};
		if ( $.fn.sortable ) {
			$( list ).sortable( {
				handle: '.flexo-drag',
				axis: 'y',
				tolerance: 'pointer',
				placeholder: 'flexo-sort-placeholder',
				update: refresh,
			} );
		}
		list.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '[data-flexo-remove]' );
			var mv = e.target.closest( '[data-flexo-move]' );
			var item = e.target.closest( '.flexo-sort-item' );
			if ( rm && item ) {
				var next = item.nextElementSibling || item.previousElementSibling;
				item.remove();
				refresh();
				if ( next ) {
					( qs( '[data-flexo-remove]', next ) || next ).focus();
				}
			} else if ( mv && item ) {
				if ( '-1' === mv.getAttribute( 'data-flexo-move' ) && item.previousElementSibling ) {
					list.insertBefore( item, item.previousElementSibling );
				} else if ( '1' === mv.getAttribute( 'data-flexo-move' ) && item.nextElementSibling ) {
					list.insertBefore( item.nextElementSibling, item );
				}
				mv.focus();
				refresh();
			}
		} );
		refresh();
		return refresh;
	}

	/* ---- Amenities ---- */
	var amenities = qs( '[data-flexo-amenities]' );
	var presets = qs( '[data-flexo-presets]' );
	if ( amenities ) {
		var syncPresets = function () {
			var keys = qsa( '.flexo-amenity', amenities ).map( function ( li ) {
				return li.getAttribute( 'data-key' );
			} );
			qsa( '.flexo-preset-chip', presets ).forEach( function ( chip ) {
				chip.setAttribute( 'aria-pressed', keys.indexOf( chip.getAttribute( 'data-key' ) ) > -1 ? 'true' : 'false' );
			} );
		};
		var refreshAmenities = initList( amenities, qs( '[data-flexo-amenities-empty]' ), syncPresets );
		var addAmenity = function ( key, label, icon, svg ) {
			var row = fromTemplate( 'flexo-amenity-row' );
			row.setAttribute( 'data-key', key );
			qs( 'input[name="flexo_amenity_label[]"]', row ).value = label;
			qs( 'input[name="flexo_amenity_key[]"]', row ).value = key;
			row.setAttribute( 'data-default-icon', icon );
			if ( svg ) {
				qs( '[data-flexo-icon-pick]', row ).innerHTML = svg;
			}
			amenities.appendChild( row );
			refreshAmenities();
			return row;
		};
		qsa( '.flexo-amenity', amenities ).forEach( function ( li ) {
			var chip = presets ? qs( '.flexo-preset-chip[data-key="' + li.getAttribute( 'data-key' ) + '"]', presets ) : null;
			li.setAttribute( 'data-default-icon', chip ? chip.getAttribute( 'data-icon' ) : 'check' );
		} );
		if ( presets ) {
			presets.addEventListener( 'click', function ( e ) {
				var chip = e.target.closest( '.flexo-preset-chip' );
				if ( ! chip ) {
					return;
				}
				var key = chip.getAttribute( 'data-key' );
				var row = qs( '.flexo-amenity[data-key="' + key + '"]', amenities );
				if ( row ) {
					row.remove();
					refreshAmenities();
				} else {
					var svg = qs( 'svg', chip ).outerHTML.replace( 'flexo-preset-chip__icon', 'flexo-icon' );
					addAmenity( key, chip.getAttribute( 'data-label' ), chip.getAttribute( 'data-icon' ), svg );
				}
			} );
		}
		var own = qs( '[data-flexo-own-amenity]' );
		var addOwn = function () {
			var label = own.value.trim();
			if ( ! label ) {
				return;
			}
			var dup = qsa( 'input[name="flexo_amenity_label[]"]', amenities ).some( function ( input ) {
				return input.value.trim().toLowerCase() === label.toLowerCase();
			} );
			if ( dup ) {
				own.setCustomValidity( t.amenityAdded || '' );
				own.reportValidity();
				return;
			}
			addAmenity( '', label, 'check', '' );
			own.value = '';
			own.focus();
		};
		if ( own ) {
			own.addEventListener( 'input', function () {
				own.setCustomValidity( '' );
			} );
			own.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					addOwn();
				}
			} );
			qs( '[data-flexo-own-amenity-add]' ).addEventListener( 'click', addOwn );
		}
	}

	/* ---- More details ---- */
	var details = qs( '[data-flexo-details]' );
	if ( details ) {
		var refreshDetails = initList( details, qs( '[data-flexo-details-empty]' ) );
		var addDetail = function ( label, icon ) {
			var row = fromTemplate( 'flexo-detail-row' );
			row.setAttribute( 'data-default-icon', 'info' );
			details.appendChild( row );
			if ( label ) {
				qs( '.flexo-detail__label', row ).value = label;
			}
			if ( icon ) {
				var choice = qs( '.flexo-icon-choice[data-icon="' + icon + '"]' );
				qs( '[data-flexo-icon-value]', row ).value = icon;
				if ( choice ) {
					qs( '[data-flexo-icon-pick]', row ).innerHTML = qs( 'svg', choice ).outerHTML;
				}
			}
			refreshDetails();
			qs( label ? '.flexo-detail__value' : '.flexo-detail__label', row ).focus();
		};
		qsa( '.flexo-detail', details ).forEach( function ( li ) {
			li.setAttribute( 'data-default-icon', 'info' );
		} );
		qs( '[data-flexo-detail-add]' ).addEventListener( 'click', function () {
			addDetail( '', '' );
		} );
		qsa( '[data-flexo-detail-suggest]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				addDetail( chip.getAttribute( 'data-label' ), chip.getAttribute( 'data-icon' ) );
			} );
		} );
	}

	/* ---- Icon picker ---- */
	var dialog = document.getElementById( 'flexo-icon-dialog' );
	var target = null;
	if ( dialog && dialog.showModal ) {
		var search = qs( '[data-flexo-icon-search]', dialog );
		var setIcon = function ( value, html ) {
			if ( ! target ) {
				return;
			}
			var item = target.closest( '.flexo-sort-item' );
			qs( '[data-flexo-icon-value]', item ).value = value;
			target.innerHTML = html;
			dialog.close();
			target.focus();
		};
		document.addEventListener( 'click', function ( e ) {
			var pick = e.target.closest( '[data-flexo-icon-pick]' );
			if ( ! pick ) {
				return;
			}
			target = pick;
			search.value = '';
			filterIcons( '' );
			var current = qs( '[data-flexo-icon-value]', pick.closest( '.flexo-sort-item' ) ).value || pick.closest( '.flexo-sort-item' ).getAttribute( 'data-default-icon' );
			qsa( '.flexo-icon-choice', dialog ).forEach( function ( b ) {
				b.classList.toggle( 'is-current', b.getAttribute( 'data-icon' ) === current );
			} );
			dialog.showModal();
			search.focus();
		} );
		var filterIcons = function ( q ) {
			q = q.trim().toLowerCase();
			qsa( '.flexo-icon-group', dialog ).forEach( function ( group ) {
				var any = false;
				qsa( '.flexo-icon-choice', group ).forEach( function ( b ) {
					var show = ! q || b.getAttribute( 'data-search' ).indexOf( q ) > -1;
					b.hidden = ! show;
					any = any || show;
				} );
				group.hidden = ! any;
			} );
		};
		search.addEventListener( 'input', function () {
			filterIcons( search.value );
		} );
		search.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				var first = qsa( '.flexo-icon-choice', dialog ).filter( function ( b ) {
					return ! b.hidden;
				} )[ 0 ];
				if ( first ) {
					first.click();
				}
			}
		} );
		dialog.addEventListener( 'click', function ( e ) {
			var choice = e.target.closest( '.flexo-icon-choice' );
			if ( choice ) {
				setIcon( choice.getAttribute( 'data-icon' ), qs( 'svg', choice ).outerHTML );
			} else if ( e.target === dialog ) {
				dialog.close(); // Click on the backdrop.
			}
		} );
		qs( '[data-flexo-icon-reset]', dialog ).addEventListener( 'click', function () {
			var item = target ? target.closest( '.flexo-sort-item' ) : null;
			var name = item ? item.getAttribute( 'data-default-icon' ) || 'check' : 'check';
			var choice = qs( '.flexo-icon-choice[data-icon="' + name + '"]', dialog );
			setIcon( '', choice ? qs( 'svg', choice ).outerHTML : '' );
		} );
		qs( '[data-flexo-icon-upload]', dialog ).addEventListener( 'click', function () {
			dialog.close();
			mediaFrame( { title: t.chooseIcon, button: { text: t.useIcon }, library: { type: 'image' }, multiple: false }, function ( list ) {
				var att = list[ 0 ];
				if ( ! att ) {
					return;
				}
				var span = document.createElement( 'span' );
				span.className = 'flexo-icon flexo-icon--mask';
				span.setAttribute( 'aria-hidden', 'true' );
				span.style.setProperty( '--flexo-icon', 'url("' + thumbUrl( att, 'thumbnail' ).replace( /"/g, '%22' ) + '")' );
				setIcon( 'media:' + att.id, span.outerHTML );
			} );
		} );
	}

	/* ---- Search engine preview ---- */
	var seoTitle = qs( '#flexo-seo-title' );
	var seoDesc = qs( '#flexo-seo-description' );
	if ( seoTitle && seoDesc ) {
		var outTitle = qs( '[data-flexo-seo-title]' );
		var outDesc = qs( '[data-flexo-seo-desc]' );
		var name = qs( '#title' );
		var excerpt = qs( '#excerpt' );
		var preview = function () {
			outTitle.textContent = seoTitle.value.trim() || ( ( name && name.value.trim() ? name.value.trim() : '' ) + ( t.siteName ? ' – ' + t.siteName : '' ) );
			var d = seoDesc.value.trim() || ( excerpt ? excerpt.value.trim() : '' );
			outDesc.textContent = d.length > 160 ? d.slice( 0, 157 ) + '…' : d;
		};
		[ seoTitle, seoDesc, name, excerpt ].forEach( function ( el ) {
			if ( el ) {
				el.addEventListener( 'input', preview );
			}
		} );
		preview();
	}

	/* ---- Enter in a card field must not save the room by accident ---- */
	qsa( '.flexo-amenity-list, .flexo-detail-list' ).forEach( function ( list ) {
		list.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && 'INPUT' === e.target.tagName ) {
				e.preventDefault();
			}
		} );
	} );
} )( jQuery );
