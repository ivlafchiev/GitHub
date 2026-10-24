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
