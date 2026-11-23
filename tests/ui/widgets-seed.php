<?php
/**
 * Widget controls check (1.10.0), after tests/ui/audit-seed.php and
 * tests/ui/layout-seed.php:
 *
 *   FXW_OUT=/path/controls.json wp eval-file tests/ui/widgets-seed.php
 *
 * - installs the test-only mu-plugin "fxw-widgets.php": the shortcode
 *   [fxw_widget name="…"] renders a Flexo Elementor widget on a plain page
 *   against tests/ui/elementor-stub.php (only when Elementor is not active),
 *   with settings from the page address (?fxw=<base64 JSON>) and wrapper
 *   classes from ?fxw_class=… (Elementor's "prefix_class" controls);
 * - creates one page per widget (fxw-ctl-…), inside the Azure-like Elementor
 *   kit (its button and field rules), and a page for the booking timeline;
 * - writes every widget's sections and controls to FXW_OUT.
 */

$stub = __DIR__ . '/elementor-stub.php';
$mu   = WP_CONTENT_DIR . '/mu-plugins/fxw-widgets.php';
$code = <<<'PHP'
<?php
// Test only (tests/ui/widgets-seed.php): Flexo's Elementor widgets on plain pages.
add_action( 'init', function () {
	if ( did_action( 'elementor/loaded' ) || ! file_exists( FXW_STUB ) ) {
		return;
	}
	require_once FXW_STUB;
	add_shortcode( 'fxw_widget', function ( $atts ) {
		static $widgets = null;
		$atts = shortcode_atts( array( 'name' => '', 'id' => 'fxw1' ), $atts );
		$widgets = $widgets ? $widgets : flexo_stub_widgets();
		if ( ! isset( $widgets[ $atts['name'] ] ) ) {
			return '';
		}
		$widget = clone $widgets[ $atts['name'] ];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- test pages.
		$own = isset( $_GET['fxw'] ) ? json_decode( base64_decode( sanitize_text_field( wp_unslash( $_GET['fxw'] ) ) ), true ) : array();
		$widget->flexo_settings = is_array( $own ) ? $own : array();
		$class = isset( $_GET['fxw_class'] ) ? ' ' . preg_replace( '/[^a-z0-9 _-]/i', '', wp_unslash( $_GET['fxw_class'] ) ) : '';
		// phpcs:enable
		// As Elementor does: the widget's own styles and scripts.
		if ( ! wp_style_is( 'flexo-booking-rooms', 'registered' ) ) {
			Flexo_Booking_Room_Render::register_assets();
		}
		foreach ( (array) $widget->get_style_depends() as $handle ) {
			wp_enqueue_style( $handle );
		}
		foreach ( (array) $widget->get_script_depends() as $handle ) {
			wp_enqueue_script( $handle );
		}
		return '<div class="elementor-element elementor-element-' . esc_attr( $atts['id'] ) . ' elementor-widget elementor-widget-' . esc_attr( $atts['name'] ) . esc_attr( $class ) . '" data-id="' . esc_attr( $atts['id'] ) . '" data-element_type="widget" data-widget_type="' . esc_attr( $atts['name'] ) . '.default"><div class="elementor-widget-container">' . $widget->flexo_render() . '</div></div>';
	} );
} );
PHP;
file_put_contents( $mu, str_replace( '<?php', "<?php\ndefine( 'FXW_STUB', " . var_export( $stub, true ) . ' );', $code ) );

// A room with photos, amenities and facts for the room widgets.
$best = null;
$most = -1;
foreach ( Flexo_Booking_Rooms::all() as $post ) {
	$room  = Flexo_Booking_Room_Content::room( $post->ID );
	$score = count( $room['gallery'] ) * 10 + count( $room['amenity_list'] );
	if ( $score > $most ) {
		$most = $score;
		$best = $room;
	}
}
// At least five photos for the carousel (the room's own, repeated if needed).
if ( $best && count( $best['gallery'] ) < 5 && $best['gallery'] ) {
	$ids = $best['gallery'];
	while ( count( $ids ) < 5 ) {
		$ids[] = $best['gallery'][ count( $ids ) % count( $best['gallery'] ) ];
	}
	update_post_meta( $best['id'], '_flexo_gallery', array_values( array_slice( $ids, 1 ) ) );
}
$slug = $best ? $best['slug'] : '';

// The site's kit (Elementor Site Settings → Buttons and Form fields), as on the Azure site.
$kit = '<style id="fxl-kit">.elementor-kit-9{--e-global-color-primary:#212B2F;--e-global-color-text:#3b3f42;--e-global-color-accent:#212B2F;color:#3b3f42;font-family:system-ui,sans-serif;font-size:16px;line-height:1.6}</style>'
	. '<style id="fxl-kit-controls">[type=button],[type=submit],button{background-color:transparent;border:1px solid #c36;border-radius:3px;color:#c36;padding:.5rem 1rem}'
	. '[type=button]:focus,[type=button]:hover,[type=submit]:focus,[type=submit]:hover,button:focus,button:hover{background-color:#c36;color:#fff;text-decoration:none}'
	. '.elementor-kit-9 button{font-size:15px;font-weight:600;letter-spacing:1px;color:#fff;background-color:#212B2F;border:1px solid #212B2F;border-radius:6px;padding:14px 28px}'
	. '.elementor-kit-9 button:hover,.elementor-kit-9 button:focus{color:#fff;background-color:#B57A5A;border-color:#B57A5A}'
	. 'svg{max-width:100%;height:auto}img{max-width:100%;height:auto}</style>'
	. '<style id="fxw-ctl"></style>';
$wrap = static function ( $name ) use ( $kit ) {
	return '<!-- wp:html -->' . $kit . '<div class="elementor-kit-9"><div class="elementor elementor-99" style="max-width:1100px;margin:0 auto">[fxw_widget name="' . $name . '"]</div></div><!-- /wp:html -->';
};
$pages = array(
	'fxw-ctl-flexo-booking-form'    => $wrap( 'flexo-booking-form' ),
	'fxw-ctl-flexo-room-booking-box' => $wrap( 'flexo-room-booking-box' ),
	'fxw-ctl-flexo-contact-form'    => $wrap( 'flexo-contact-form' ),
	'fxw-ctl-flexo-forms-tabs'      => $wrap( 'flexo-forms-tabs' ),
	'fxw-ctl-flexo-room-amenities'  => $wrap( 'flexo-room-amenities' ),
	'fxw-ctl-flexo-room-details'    => $wrap( 'flexo-room-details' ),
	'fxw-ctl-flexo-room-gallery'    => $wrap( 'flexo-room-gallery' ),
);
// Pages for tests/ui/release-check.js: room cards with each amenity style, the contact form and tabs.
$frame = static function ( $inner ) {
	return '<!-- wp:html --><div id="fxl-frame" style="max-width:760px;margin:0 auto">' . $inner . '</div><!-- /wp:html -->';
};
$pages['fxr-cards-icons'] = $frame( '[flexo_booking amenity_icons="icons"]' );
$pages['fxr-cards-tick']  = $frame( '[flexo_booking amenity_icons="tick"]' );
$pages['fxr-cards-none']  = $frame( '[flexo_booking amenity_icons="none"]' );
$pages['fxr-contact']     = $frame( '[flexo_contact title="Write to us" intro="We reply within a day."]' );
$pages['fxr-tabs']        = '<!-- wp:html -->' . $kit . '<div class="elementor-kit-9" style="background:#F7F2E7;padding:32px 16px"><div style="max-width:560px;margin:0 auto">[flexo_forms_tabs booking_label="Booking Form" contact_label="Contact Form"]</div></div><!-- /wp:html -->';
foreach ( $pages as $page_slug => $content ) {
	$existing = get_page_by_path( $page_slug );
	$postarr  = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $page_slug,
		'post_title'   => $page_slug,
		'post_content' => $content,
	);
	if ( $existing ) {
		$postarr['ID'] = $existing->ID;
	}
	wp_insert_post( $postarr );
}

// A booking with a long history for the timeline (Part 12).
$booking = null;
foreach ( Flexo_Booking_Bookings::query( array( 'per_page' => 1 ) )['items'] as $row ) {
	$booking = $row;
}
if ( $booking ) {
	$texts = array( 'Booking created on the website', 'Status changed from Request to Confirmed', 'The guest asked for a quiet room on a high floor, away from the lift, and will arrive late in the evening after a long drive from the airport.', 'Email sent again: Booking confirmed', 'Payment received: 120.00 €' );
	if ( count( Flexo_Booking_Log::for_booking( (int) $booking['id'] ) ) < 5 ) {
		foreach ( $texts as $i => $text ) {
			Flexo_Booking_Log::add( (int) $booking['id'], 'note', array( 'text' => $text ), 1 );
		}
	}
	update_option( 'fxw_timeline_booking', (int) $booking['id'] );
}

// Every widget's sections and controls.
if ( getenv( 'FXW_OUT' ) ) {
	require_once $stub;
	// The booking box needs a room guests can book (demo rooms are not).
	$box_room = '';
	foreach ( Flexo_Booking_Rooms::all() as $post ) {
		if ( '' === $box_room && 0 !== strpos( $post->post_name, 'demo-' ) ) {
			$box_room = $post->post_name;
		}
	}
	$out = array(
		'room'     => $slug,
		'box_room' => $box_room,
		'booking' => $booking ? (int) $booking['id'] : 0,
		'widgets' => array(),
	);
	foreach ( flexo_stub_widgets() as $name => $widget ) {
		$controls = array();
		foreach ( $widget->flexo_controls as $id => $control ) {
			$keep = array();
			foreach ( array( 'id', 'type', 'label', 'section', 'tab', 'control_tab', 'selectors', 'selector', 'prefix_class', 'selectors_dictionary', 'condition', 'default', 'range', 'size_units', 'group', 'name', 'responsive', 'options' ) as $key ) {
				if ( isset( $control[ $key ] ) ) {
					$keep[ $key ] = 'options' === $key ? array_map( 'strval', array_keys( $control[ $key ] ) ) : $control[ $key ];
				}
			}
			$controls[] = $keep;
		}
		$sections = array();
		foreach ( $widget->flexo_sections as $id => $section ) {
			$sections[] = array(
				'id'    => $id,
				'label' => isset( $section['label'] ) ? $section['label'] : '',
				'tab'   => $section['tab'],
			);
		}
		$out['widgets'][ $name ] = array(
			'title'    => $widget->get_title(),
			'sections' => $sections,
			'controls' => $controls,
			'problems' => $widget->flexo_problems,
		);
	}
	file_put_contents( getenv( 'FXW_OUT' ), wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}
echo 'test pages=', count( $pages ), ' room=', $slug, "\n";
