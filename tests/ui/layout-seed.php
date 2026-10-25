<?php
// Layout check (tests/ui/layout-check.js): pages with the booking form in a frame whose width the
// check sets, the Azure contact page's two columns, two forms on one page (one in a hidden tab),
// the search bar, the room box and a content-sized row; plus a room with a long name, a long
// description and many amenities. Run with `wp eval-file` after tests/ui/audit-seed.php.

$rooms = Flexo_Booking_Rooms::all();
$model = null;
foreach ( $rooms as $room ) {
	if ( 'demo-family-suite' === $room->post_name ) {
		$model = $room;
	}
}
$model = $model ? $model : $rooms[0];

$long = get_page_by_path( 'panoramic-suite', OBJECT, Flexo_Booking_Rooms::POST_TYPE );
if ( ! $long ) {
	$id = wp_insert_post(
		array(
			'post_type'    => Flexo_Booking_Rooms::POST_TYPE,
			'post_status'  => 'publish',
			'post_name'    => 'panoramic-suite',
			'post_title'   => 'Deluxe Panoramic Sea View Suite with Private Terrace and Outdoor Jacuzzi',
			'post_excerpt' => 'Our largest suite on the top floor: a bright living room with floor-to-ceiling windows, a separate bedroom with a king-size bed, a rain shower, and a private terrace with an outdoor jacuzzi and sun loungers looking straight out over the bay and the old town.',
			'post_content' => str_repeat( 'Wake up to the sound of the sea and breakfast on your own terrace. ', 12 ),
			'menu_order'   => 1,
		)
	);
	foreach ( array( '_flexo_price', '_flexo_weekend_price', '_flexo_capacity' ) as $key ) {
		update_post_meta( $id, $key, get_post_meta( $model->ID, $key, true ) );
	}
	update_post_meta( $id, '_flexo_units', 2 );
	update_post_meta( $id, '_flexo_size', 64 );
	update_post_meta( $id, '_flexo_beds', '1 king-size bed + 1 sofa bed' );
	$items = array();
	foreach ( array( 'sea_view', 'terrace', 'wifi', 'air_conditioning', 'private_bathroom', 'kitchenette', 'balcony', 'garden_view' ) as $key ) {
		$items[] = array( 'key' => $key, 'label' => '', 'icon' => '' );
	}
	$items[] = array( 'key' => 'custom-jacuzzi', 'label' => 'Outdoor jacuzzi', 'icon' => 'bath' );
	$items[] = array( 'key' => 'custom-coffee', 'label' => 'Espresso machine', 'icon' => 'coffee' );
	$items[] = array( 'key' => 'custom-safe', 'label' => 'In-room safe', 'icon' => 'lock' );
	update_post_meta( $id, Flexo_Booking_Room_Content::AMENITY_ITEMS, Flexo_Booking_Room_Content::sanitize_amenity_items( $items ) );
	update_post_meta( $id, '_flexo_amenities', array( 'sea_view', 'terrace', 'wifi', 'air_conditioning', 'private_bathroom', 'kitchenette', 'balcony', 'garden_view' ) );
	$thumb = get_post_thumbnail_id( $model->ID );
	if ( $thumb ) {
		set_post_thumbnail( $id, $thumb );
	}
}

$block = static function ( $html ) {
	return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
};
$full  = static function ( $inner ) {
	return '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"24px","bottom":"24px","left":"0","right":"0"}}},"layout":{"type":"default"}} --><div class="wp-block-group alignfull" style="padding-top:24px;padding-right:0;padding-bottom:24px;padding-left:0">' . $inner . '</div><!-- /wp:group -->';
};
$contact = '<h2>Contact us</h2><p>Hotel Azure Bay<br>12 Sea Garden Street, 9000 Varna</p><p><strong>Phone:</strong> +359 52 123 456<br><strong>Email:</strong> reception@azurebay.example</p><p>Our reception is open around the clock. Send us a message or book your stay directly – the best price is always here.</p>';

// The Azure contact section as the client's site has it (Elementor): a cream section with two
// columns; on the left "Booking Form | Contact Form" pills above a beige box titled "Make A
// Reservation" holding the form; on the right the contact details. The CSS is what such a site
// adds to every page: the Elementor kit (global colours and fonts, Buttons and Form fields of
// Site Settings, with :hover/:focus rules more specific than a plain class) and the Hello theme's
// button reset. Fonts are local stand-ins (no web fonts).
$kit = '<style id="fxl-kit">'
	. '.elementor-kit-9{--e-global-color-primary:#212B2F;--e-global-color-secondary:#B57A5A;--e-global-color-text:#B57A5A;--e-global-color-accent:#212B2F;--e-global-typography-primary-font-family:Georgia,serif;--e-global-typography-text-font-family:system-ui,sans-serif;color:#B57A5A;font-family:system-ui,sans-serif;font-size:16px;line-height:1.6}'
	. '.elementor-kit-9 h1,.elementor-kit-9 h2,.elementor-kit-9 h3,.elementor-kit-9 h4,.elementor-kit-9 h5{font-family:Georgia,serif;font-weight:400;color:#212B2F}'
	. '</style><style id="fxl-kit-controls">' // Buttons and fields: the layout check removes these to compare.
	. '[type=button],[type=submit],button{background-color:transparent;border:1px solid #c36;border-radius:3px;color:#c36;display:inline-block;font-size:1rem;font-weight:400;padding:.5rem 1rem;text-align:center;transition:all .3s;user-select:none;white-space:nowrap}'
	. '[type=button]:focus,[type=button]:hover,[type=submit]:focus,[type=submit]:hover,button:focus,button:hover{background-color:#c36;color:#fff;text-decoration:none}'
	. '.elementor-kit-9 button,.elementor-kit-9 input[type="button"],.elementor-kit-9 input[type="submit"],.elementor-kit-9 .elementor-button{font-size:15px;font-weight:600;letter-spacing:1px;color:#FFFFFF;background-color:#212B2F;border-style:solid;border-width:1px;border-color:#212B2F;border-radius:6px;padding:14px 28px}'
	. '.elementor-kit-9 button:hover,.elementor-kit-9 button:focus,.elementor-kit-9 input[type="button"]:hover,.elementor-kit-9 input[type="button"]:focus,.elementor-kit-9 input[type="submit"]:hover,.elementor-kit-9 input[type="submit"]:focus,.elementor-kit-9 .elementor-button:hover,.elementor-kit-9 .elementor-button:focus{color:#FFFFFF;background-color:#B57A5A;border-color:#B57A5A}'
	. '.elementor-kit-9 label{color:#B57A5A}'
	. '.elementor-kit-9 input:not([type="button"]):not([type="submit"]),.elementor-kit-9 textarea,.elementor-kit-9 .elementor-field-textual{color:#B57A5A;background-color:#FFFFFF;border-style:solid;border-width:1px;border-color:#E8DCCB;border-radius:6px;padding:12px 16px}'
	. '.elementor-kit-9 input:focus:not([type="button"]):not([type="submit"]),.elementor-kit-9 textarea:focus,.elementor-kit-9 .elementor-field-textual:focus{border-color:#212B2F}'
	. '</style><style>'
	. '.fxl-az{background:#F7F2E7;padding:56px 4%}.fxl-az__row{display:flex;flex-wrap:wrap;gap:48px;max-width:1140px;margin:0 auto}.fxl-az__book{flex:0 1 44%;min-width:0}.fxl-az__info{flex:1 1 0;min-width:280px}'
	. '.fxl-tabs{display:flex;justify-content:center;gap:10px;margin:0 0 14px}.elementor-kit-9 .fxl-tab{padding:8px 18px;border-radius:999px;border:0;background:#EDE1D3;color:#B57A5A;font-size:14px;letter-spacing:0}.elementor-kit-9 .fxl-tab.is-active{background:#B57A5A;color:#fff}'
	. '.fxl-box{background:#EDE1D3;border-radius:18px;padding:18px}.fxl-box>h3{margin:0 0 14px;text-align:center;font-size:24px}'
	. '.fxl-eyebrow{font-size:12px;letter-spacing:3px;text-transform:uppercase}.fxl-az__info h2{font-size:44px;line-height:1.15;margin:8px 0 16px}'
	. '@media (max-width:767px){.fxl-az__book{flex:1 1 100%}}'
	. '</style>';
$azure = $kit . '<div class="elementor-kit-9"><section class="fxl-az"><div class="fxl-az__row">'
	. '<div class="fxl-az__book" id="fxl-az-book"><div class="fxl-tabs"><button type="button" class="fxl-tab is-active">Booking Form</button><button type="button" class="fxl-tab">Contact Form</button></div>'
	. '<div class="fxl-box"><h3>Make A Reservation</h3><div class="elementor-widget-container">[flexo_booking]</div></div></div>'
	. '<div class="fxl-az__info" id="fxl-az-info"><p class="fxl-eyebrow">Contact</p><h2>Make a Reservation or Get in Touch</h2><p>Use the switch below to choose between our booking form and contact form. Whether you\'re ready to reserve your escape or simply have a question, we\'re here to help.</p><p><strong>Contact:</strong> +30 2892 000 000<br><strong>Location:</strong> Kalo Nero Beach Road, Rethymno, Crete, Greece</p></div>'
	. '</div></section></div>';

$pages = array(
	'fxl-lab'     => $full( $block( '<div id="fxl-frame" style="margin:0 auto">[flexo_booking]</div>' ) ),
	'fxl-azure'   => '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"40px","bottom":"40px","left":"24px","right":"24px"}}},"layout":{"type":"constrained","wideSize":"1240px"}} --><div class="wp-block-group alignfull" style="padding-top:40px;padding-right:24px;padding-bottom:40px;padding-left:24px">'
		. '<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"48px"}}}} --><div class="wp-block-columns alignwide">'
		. '<!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%">' . $block( '<div id="fxl-contact">' . $contact . '</div>' ) . '</div><!-- /wp:column -->'
		. '<!-- wp:column {"width":"50%"} --><div class="wp-block-column" style="flex-basis:50%">' . $block( '[flexo_booking title="Book your stay"]' ) . '</div><!-- /wp:column -->'
		. '</div><!-- /wp:columns --></div><!-- /wp:group -->',
	'fxl-two'     => $full( $block( '<div id="fxl-two" style="display:grid;grid-template-columns:1fr 1fr;gap:32px;max-width:1240px;margin:0 auto"><div id="fxl-a">[flexo_booking title="Form A"]</div><div id="fxl-b">[flexo_booking title="Form B"]</div></div><div style="max-width:1240px;margin:32px auto 0"><div role="tablist"><button type="button" id="fxl-tab-1" role="tab">Booking</button><button type="button" id="fxl-tab-2" role="tab">Search</button></div><div id="fxl-panel-1" role="tabpanel">[flexo_booking layout="search" title="Panel one"]</div><div id="fxl-panel-2" role="tabpanel" hidden>[flexo_booking title="Panel two"]</div></div>' ) ),
	'fxl-search'  => $full( $block( '<div id="fxl-frame" style="margin:0 auto">[flexo_booking layout="search"]</div>' ) ),
	'fxl-box'     => $full( $block( '<div id="fxl-frame" style="margin:0 auto">[flexo_room_booking room="panoramic-suite"]</div>' ) ),
	'fxl-stacked'    => $full( $block( '<div id="fxl-frame" style="margin:0 auto">[flexo_booking results="stacked"]</div>' ) ),
	'fxl-horizontal' => $full( $block( '<div id="fxl-frame" style="margin:0 auto">[flexo_booking results="horizontal"]</div>' ) ),
	'fxl-azure-kit'  => $full( $block( $azure ) ),
	'fxl-flexrow' => $full( $block( '<div id="fxl-row" style="display:flex;gap:24px;align-items:flex-start;max-width:1240px;margin:0 auto"><div id="fxl-item" style="flex:0 1 auto">[flexo_booking]</div><div style="flex:1 1 0">' . $contact . '</div></div>' ) ),
);
foreach ( $pages as $slug => $content ) {
	$page = get_page_by_path( $slug );
	$args = array(
		'post_type'     => 'page',
		'post_status'   => 'publish',
		'post_name'     => $slug,
		'post_title'    => $slug,
		'post_content'  => $content,
		'page_template' => 'page-no-title',
	);
	if ( $page ) {
		$args['ID'] = $page->ID;
		wp_update_post( $args );
	} else {
		wp_insert_post( $args );
	}
}
echo 'layout pages=', count( $pages ), "\n";
