<?php
/**
 * Day 3: children & ages, rate plans, tourist tax, promo codes, price snapshots.
 * Dates are relative to next week's Monday (t_day(0)), far enough ahead to
 * avoid the other tests' bookings.
 */
require __DIR__ . '/lib.php';
// Many REST bookings in a row from the same (CLI) address: no rate limit here.
add_filter( 'flexo_booking_rate_limit', '__return_zero' );

global $wpdb;
t_reset_inventory();
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'rate_plans' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'promo_codes' ) );
Flexo_Booking_Rate_Plans::flush_cache();
Flexo_Booking_Features::set_available( null );

function t_settings( array $values ) {
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), $values ) ) );
}
function t_features( array $extra ) {
	Flexo_Booking_Features::set_enabled( array_merge( array( 'booking_request', 'instant_booking', 'guest_emails' ), $extra ) );
}
function t_quote( array $args ) {
	return Flexo_Booking_Pricing::quote( $args );
}
function t_line( $quote, $key ) {
	foreach ( $quote['lines'] as $line ) {
		if ( $line['key'] === $key ) {
			return $line['amount'];
		}
	}
	return null;
}

t_settings(
	array(
		'booking_mode'       => 'request',
		'min_nights'         => 1,
		'max_nights'         => 30,
		'terms_url'          => '',
		'child_free_under'   => 3,
		'child_percent'      => 50,
		'child_adult_from'   => 12,
		'tourist_tax_amount' => 0,
	)
);
t_features( array() );

$family = t_room( 'family-room', 'Family Room', array( 'price' => 100, 'weekend_price' => '', 'capacity' => 4, 'units' => 3, 'max_adults' => 2, 'min_nights' => '' ) );
$double = t_room( 'double-room', 'Double Room', array( 'price' => 80, 'weekend_price' => '', 'capacity' => 2, 'units' => 2, 'max_adults' => '', 'min_nights' => '' ) );
$solo   = t_room( 'solo-plan-room', 'Solo Plan Room', array( 'price' => 60, 'weekend_price' => '', 'capacity' => 2, 'units' => 2, 'max_adults' => '', 'min_nights' => '' ) );
Flexo_Booking_Children::save_room_rules( $family, null );
foreach ( array( $family, $double, $solo ) as $r ) {
	Flexo_Booking_Rate_Plans::set_room_assignments( $r, array() );
}
$in  = t_day( 42 ); // Monday.
$out = t_day( 45 ); // 3 nights.
$g   = t_guest();

// ---------------------------------------------------------------------------
t_section( 'Children feature off: today\'s behaviour' );
$r   = Flexo_Booking_Bookings::search( $in, $out, 2, 2, 'family-room' );
t_ok( ! is_wp_error( $r ) && $r['rooms'][0]['available'], 'search with a children count and no ages works' );
t_eq( 300.0, $r['rooms'][0]['total'], 'room price only (children don\'t change it)' );
$r = Flexo_Booking_Bookings::search( $in, $out, 3, 0, 'family-room' );
t_ok( $r['rooms'][0]['available'], '"max adults" is not applied while the feature is off' );
$b = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 1, 'children_ages' => '5' ) ) );
t_ok( is_array( $b ) && 1 === $b['children'] && '' === $b['children_ages'], 'booking keeps the children count, ages ignored' );
Flexo_Booking_Frontend::register_assets();
ob_start();
echo Flexo_Booking_Frontend::render( array() ); // phpcs:ignore
$html   = ob_get_clean();
$config = wp_scripts()->get_data( 'flexo-booking', 'data' );
t_ok( false !== strpos( $config, '"children":""' ), 'booking form is told not to ask ages' );
t_ok( false === strpos( $html, 'data-fb-ages' ) && false !== strpos( $html, 'name="children"' ), 'form shows the adults/children counts as before, no age fields' );
t_reset_inventory();

// ---------------------------------------------------------------------------
t_section( 'Children & ages' );
t_features( array( 'children' ) );
ob_start();
echo Flexo_Booking_Frontend::render( array() ); // phpcs:ignore
$html = ob_get_clean();
t_ok( false !== strpos( $html, 'data-fb-ages' ), 'form gets the age selectors container' );

$e = Flexo_Booking_Bookings::search( $in, $out, 2, 2, 'family-room', '4' );
t_ok( is_wp_error( $e ) && 'flexo_child_ages' === $e->get_error_code(), 'an age for each child is required: ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
$e = Flexo_Booking_Bookings::search( $in, $out, 2, 1, 'family-room', '19' );
t_ok( is_wp_error( $e ), 'age above 17 rejected' );
$r = Flexo_Booking_Bookings::search( $in, $out, 2, 2, 'family-room', '4,11' );
t_ok( $r['rooms'][0]['available'] && array( 4, 11 ) === $r['children_ages'], '2 adults + 2 children (4, 11) fit the family room (4 guests)' );
$r   = Flexo_Booking_Bookings::search( $in, $out, 2, 3, '', array( 1, 4, 11 ) );
$map = array_column( $r['rooms'], null, 'slug' );
t_ok( ! $map['family-room']['available'] && false !== strpos( $map['family-room']['reason'], '4 guests' ), 'capacity counts children: 5 guests refused – ' . $map['family-room']['reason'] );
$r   = Flexo_Booking_Bookings::search( $in, $out, 3, 0 );
$map = array_column( $r['rooms'], null, 'slug' );
t_ok( ! $map['family-room']['available'] && false !== strpos( $map['family-room']['reason'], '2 adults' ), 'max adults: 3 adults refused in a room for max 2 adults – ' . $map['family-room']['reason'] );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 3 ) ) );
t_ok( is_wp_error( $e ) && 'flexo_capacity' === $e->get_error_code(), 'booking with 3 adults refused' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'double-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 1, 'children_ages' => '1' ) ) );
t_ok( is_wp_error( $e ) && 'flexo_capacity' === $e->get_error_code(), 'a baby counts towards max guests' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 2, 'children_ages' => '6' ) ) );
t_ok( is_wp_error( $e ) && 'flexo_child_ages' === $e->get_error_code(), 'booking without every age refused' );
$b = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 2, 'children_ages' => array( '6', '11' ) ) ) );
t_ok( is_array( $b ) && '6,11' === $b['children_ages'] && 2 === $b['children'], 'booking stores the ages' );
t_eq( '2 adults, 2 children (ages 6, 11)', Flexo_Booking_Children::guests_text( $b['adults'], $b['children'], $b['children_ages'] ), 'guests text for admin and emails' );
$staff = Flexo_Booking_Bookings::create( array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children_ages' => '2, 7', 'guest_name' => 'Staff', 'source' => 'admin', 'status' => 'confirmed' ) );
t_ok( is_array( $staff ) && 2 === $staff['children'] && '2,7' === $staff['children_ages'], 'staff booking: children counted from the typed ages' );

$rules = Flexo_Booking_Children::global_rules();
t_eq( 0.0, Flexo_Booking_Children::factor( $rules, 2 ), 'age 2: free (under 3)' );
t_eq( 0.5, Flexo_Booking_Children::factor( $rules, 3 ), 'age 3: 50%' );
t_eq( 0.5, Flexo_Booking_Children::factor( $rules, 11 ), 'age 11: 50%' );
t_eq( 1.0, Flexo_Booking_Children::factor( $rules, 12 ), 'age 12: adult price' );
t_eq( 'Under 3: free · 3–11: 50% of the adult price · 12 and older: adult price', Flexo_Booking_Children::describe( $rules ), 'rules in plain language' );
Flexo_Booking_Children::save_room_rules( $family, array( 'free_under' => 6, 'percent' => 30, 'adult_from' => 14 ) );
$own = Flexo_Booking_Children::rules( $family );
t_ok( $own['custom'] && 0.0 === Flexo_Booking_Children::factor( $own, 5 ) && 0.3 === Flexo_Booking_Children::factor( $own, 13 ), 'per-room override (free under 6, 30% to 13)' );
t_ok( ! Flexo_Booking_Children::rules( $double )['custom'], 'other rooms keep the global rules' );
Flexo_Booking_Children::save_room_rules( $family, null );
t_reset_inventory();

// ---------------------------------------------------------------------------
t_section( 'Rate plans' );
t_features( array( 'children', 'rate_plans' ) );
$rp = static function ( $name, $type, $value, $extra = array() ) {
	return Flexo_Booking_Rate_Plans::save( array_merge( array( 'name' => $name, 'adjustment_type' => $type, 'adjustment_value' => $value, 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free cancellation up to 7 days before arrival.' ), $extra ) );
};
$room_only = $rp( 'Room Only', 'per_night', 0 );
$per_night = $rp( 'Parking', 'per_night', 10 );
$per_stay  = $rp( 'Welcome Pack', 'per_booking', 25 );
$breakfast = $rp( 'Breakfast Included', 'per_guest_night', 8 );
$half      = $rp( 'Half Board', 'per_guest_night', 18 );
$nonref    = $rp( 'Non-refundable', 'percent', -10, array( 'refundable' => 0, 'cancellation_policy' => 'Not refundable.' ) );
$inactive  = $rp( 'Old Plan', 'per_night', 5, array( 'active' => 0 ) );
t_ok( is_wp_error( $rp( 'breakfast included', 'per_night', 1 ) ), 'duplicate plan name refused' );
t_ok( is_wp_error( $rp( 'Bad', 'percent', -150 ) ), 'percentage below -100 refused' );

$base = t_quote( array( 'room' => $double, 'check_in' => $in, 'check_out' => $out, 'adults' => 2 ) );
t_ok( null === $base['rate_plan'] && 240.0 === $base['total'], 'room offering no plan: normal price, no plan (240)' );

Flexo_Booking_Rate_Plans::set_room_assignments( $double, array( $room_only => null, $per_night => null, $per_stay => null, $breakfast => null, $nonref => null, $inactive => null ) );
$q = static function ( $plan, $extra = array() ) use ( $double, $in, $out ) {
	return t_quote( array_merge( array( 'room' => $double, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $plan, 'context' => 'booking' ), $extra ) );
};
t_eq( 240.0, $q( $room_only )['total'], 'base rate plan (Room Only, +0): 240, no extra line' );
t_eq( 1, count( $q( $room_only )['lines'] ), '…and only the accommodation line' );
t_eq( 270.0, $q( $per_night )['total'], 'fixed per night: 240 + 3 × 10' );
t_eq( 265.0, $q( $per_stay )['total'], 'fixed per booking: 240 + 25' );
t_eq( 288.0, $q( $breakfast )['total'], 'per guest per night: 240 + 2 adults × 3 nights × 8' );
t_eq( 216.0, $q( $nonref )['total'], 'percentage (negative): 240 − 10%' );
t_eq( -24.0, t_line( $q( $nonref ), 'rate_plan' ), 'shown as its own line: −24' );
$e = $q( $inactive );
t_ok( is_wp_error( $e ) && 'flexo_rate_plan' === $e->get_error_code(), 'switched-off plan can\'t be booked' );
$e = $q( 0 );
t_ok( is_wp_error( $e ) && 'flexo_rate_plan' === $e->get_error_code(), 'several plans and none chosen: "' . $e->get_error_message() . '"' );
$e = $q( $half );
t_ok( is_wp_error( $e ), 'plan not offered for this room refused' );

// Per guest per night with children (global rules: <3 free, 3–11 50%, 12+ adult).
Flexo_Booking_Rate_Plans::set_room_assignments( $family, array( $breakfast => null, $half => null ) );
$fq = t_quote( array( 'room' => $family, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 2, 'children_ages' => array( 2, 6 ), 'rate_plan_id' => $half, 'context' => 'booking' ) );
t_eq( 300 + 2 * 3 * 18 + 3 * 9.0, $fq['total'], 'Half Board, 2 adults + child 2 (free) + child 6 (50%): 300 + 108 + 27 = 435' );
$details = Flexo_Booking_Pricing::format_lines( $fq )[1]['details'];
t_ok( in_array( 'Child, age 2: free', $details, true ), 'breakdown says the 2-year-old is free' );
echo '    ' . implode( ' | ', $details ) . "\n";
$fq = t_quote( array( 'room' => $family, 'check_in' => $in, 'check_out' => $out, 'adults' => 1, 'children' => 1, 'children_ages' => array( 12 ), 'rate_plan_id' => $half, 'context' => 'booking' ) );
t_eq( 300 + 2 * 3 * 18.0, $fq['total'], 'child aged 12 pays the adult supplement' );
Flexo_Booking_Rate_Plans::set_room_assignments( $family, array( $breakfast => 12.0, $half => null ) );
t_eq( 300 + 2 * 3 * 12.0, t_quote( array( 'room' => $family, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $breakfast, 'context' => 'booking' ) )['total'], 'per-room amount override (breakfast 12 instead of 8)' );

// Stay crossing two seasons with a per-person plan.
Flexo_Booking_Features::set_enabled( array_merge( Flexo_Booking_Features::stored_enabled(), array( 'seasonal_pricing' ) ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $family, 'name' => 'Low', 'date_from' => t_day( 56 ), 'date_to' => t_day( 57 ), 'price' => 100 ) );
Flexo_Booking_Seasons::save( array( 'room_id' => $family, 'name' => 'High', 'date_from' => t_day( 58 ), 'date_to' => t_day( 70 ), 'price' => 150 ) );
Flexo_Booking_Seasons::flush_cache();
$sq = t_quote( array( 'room' => $family, 'check_in' => t_day( 56 ), 'check_out' => t_day( 61 ), 'adults' => 2, 'rate_plan_id' => $half, 'context' => 'booking' ) );
t_eq( 650.0, t_line( $sq, 'accommodation' ), 'crossing seasons: 2 × 100 + 3 × 150 = 650' );
t_eq( 180.0, t_line( $sq, 'rate_plan' ), 'Half Board per person per night over both seasons: 2 × 5 × 18 = 180' );
t_eq( 830.0, $sq['total'], 'total 830' );
Flexo_Booking_Rate_Plans::set_room_assignments( $family, array( $breakfast => 12.0, $half => null, $nonref => null ) );
t_eq( -65.0, t_line( t_quote( array( 'room' => $family, 'check_in' => t_day( 56 ), 'check_out' => t_day( 61 ), 'adults' => 2, 'rate_plan_id' => $nonref, 'context' => 'booking' ) ), 'rate_plan' ), 'percentage across seasons applies to the room price only: −10% of 650' );

// Search: plans per room, cheapest first price, single plan skips the choice.
Flexo_Booking_Rate_Plans::set_room_assignments( $solo, array( $breakfast => null ) );
$r   = Flexo_Booking_Bookings::search( $in, $out, 2, 0 );
$map = array_column( $r['rooms'], null, 'slug' );
t_eq( 5, count( $map['double-room']['plans'] ), 'double room lists its 5 switched-on plans' );
t_ok( $map['double-room']['price_from'] && 216.0 === $map['double-room']['total'], 'room card shows "from" the cheapest plan (216)' );
t_eq( 1, count( $map['solo-plan-room']['plans'] ), 'solo room: one plan' );
t_ok( ! $map['solo-plan-room']['price_from'], '…no "from" price, the choice is skipped' );
$plan_view = $map['double-room']['plans'][4];
t_ok( 'Non-refundable' === $plan_view['rate_plan']['name'] && ! $plan_view['rate_plan']['refundable'] && 'Not refundable.' === $plan_view['rate_plan']['cancellation_policy'], 'plan view carries name, refundable flag and cancellation text' );
$sb = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'solo-plan-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2 ) ) );
t_ok( is_array( $sb ) && $sb['rate_plan_id'] === $breakfast && 228.0 === $sb['total'], 'booking a one-plan room without choosing uses that plan (180 + 48)' );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'double-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2 ) ) );
t_ok( is_wp_error( $e ) && 'flexo_rate_plan' === $e->get_error_code(), 'several plans: the guest must choose' );
$db = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'double-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan' => $nonref ) ) );
t_ok( is_array( $db ) && 216.0 === $db['total'] && 'Non-refundable' === Flexo_Booking_Admin::rate_plan_name( $db ), 'booking stores plan and price' );

Flexo_Booking_Rate_Plans::set_active( $nonref, false );
t_eq( 4, count( Flexo_Booking_Rate_Plans::for_room( $double ) ), 'switching a plan off hides it' );
Flexo_Booking_Rate_Plans::set_active( $nonref, true );
t_features( array( 'children' ) );
$off = t_quote( array( 'room' => $double, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $breakfast, 'context' => 'booking' ) );
t_ok( ! is_wp_error( $off ) && null === $off['rate_plan'] && 240.0 === $off['total'], 'feature off: plans ignored, normal price' );
t_eq( array(), Flexo_Booking_Bookings::search( $in, $out, 2, 0, 'double-room' )['rooms'][0]['plans'], 'feature off: no plans in search' );
t_features( array( 'children', 'rate_plans' ) );
t_reset_inventory();

// ---------------------------------------------------------------------------
t_section( 'Tourist tax' );
t_features( array( 'children', 'rate_plans', 'tourist_tax' ) );
t_settings( array( 'tourist_tax_amount' => '1.50', 'tourist_tax_children' => 'adult', 'tourist_tax_collect' => 'booking' ) );
$tq = static function ( $adults, $ages ) use ( $family, $breakfast, $in, $out ) {
	return t_quote( array( 'room' => $family, 'check_in' => $in, 'check_out' => $out, 'adults' => $adults, 'children' => count( $ages ), 'children_ages' => $ages, 'rate_plan_id' => $breakfast, 'context' => 'booking' ) );
};
$x = $tq( 2, array() );
t_eq( 9.0, t_line( $x, 'tourist_tax' ), 'adults: 2 × 3 nights × 1.50 = 9' );
t_eq( 300 + 72 + 9.0, $x['total'], 'included in the total' );
t_eq( 9.0, $x['tax_total'], 'tax total' );
t_eq( 18.0, t_line( $tq( 2, array( 6, 15 ) ), 'tourist_tax' ), 'children pay like adults (setting "same as adults")' );
t_settings( array( 'tourist_tax_children' => 'exempt', 'tourist_tax_exempt_under' => 16 ) );
t_eq( 13.5, t_line( $tq( 2, array( 6, 16 ) ), 'tourist_tax' ), 'child exemption under 16: 6-year-old exempt, 16-year-old pays' );
t_settings( array( 'tourist_tax_children' => 'rules' ) );
t_eq( 11.25, t_line( $tq( 2, array( 2, 6 ) ), 'tourist_tax' ), 'child rules: age 2 free, age 6 half: 9 + 2.25' );
t_settings( array( 'tourist_tax_children' => 'adult', 'tourist_tax_collect' => 'property' ) );
$x = $tq( 2, array() );
t_ok( 372.0 === $x['total'] && 9.0 === $x['due_at_property'] && 9.0 === $x['tax_total'], 'paid at the property: shown (9) but not in the total (372)' );
t_ok( false !== strpos( Flexo_Booking_Pricing::summary_text( $x ), 'Payable at the property: 9.00' ), 'summary says what is paid at the property' );
t_settings( array( 'tourist_tax_collect' => 'booking' ) );
$tb = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan' => $breakfast ) ) );
t_ok( is_array( $tb ) && 9.0 === $tb['tax_total'] && 381.0 === $tb['total'], 'booking stores the tax total' );
t_features( array( 'children', 'rate_plans' ) );
t_eq( null, t_line( $tq( 2, array() ), 'tourist_tax' ), 'feature off: no tax line' );
t_features( array( 'children', 'rate_plans', 'tourist_tax' ) );
t_reset_inventory();

// ---------------------------------------------------------------------------
t_section( 'Promo codes' );
t_features( array( 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ) );
$pc = static function ( $data ) {
	return Flexo_Booking_Promo_Codes::save( array_merge( array( 'discount_type' => 'percent', 'active' => 1 ), $data ) );
};
$summer = $pc( array( 'code' => 'summer20', 'discount_value' => 20 ) );
$fixed  = $pc( array( 'code' => 'WELCOME', 'discount_type' => 'fixed', 'discount_value' => 30 ) );
$old    = $pc( array( 'code' => 'OLD', 'discount_value' => 10, 'book_to' => wp_date( 'Y-m-d', strtotime( '-1 day' ) ) ) );
$future = $pc( array( 'code' => 'LATER', 'discount_value' => 10, 'book_from' => wp_date( 'd.m.Y', strtotime( '+5 days' ) ) ) );
$off    = $pc( array( 'code' => 'PAUSED', 'discount_value' => 10, 'active' => 0 ) );
$limit  = $pc( array( 'code' => 'ONCE', 'discount_value' => 10, 'max_uses' => 1 ) );
$roomc  = $pc( array( 'code' => 'FAMILY', 'discount_value' => 10, 'room_ids' => array( $family ) ) );
$planc  = $pc( array( 'code' => 'BREAKFAST', 'discount_value' => 10, 'rate_plan_ids' => array( $breakfast ) ) );
$minamt = $pc( array( 'code' => 'BIG', 'discount_value' => 10, 'min_amount' => 500 ) );
$minn   = $pc( array( 'code' => 'LONG', 'discount_value' => 10, 'min_nights' => 5 ) );
$stay   = $pc( array( 'code' => 'AUTUMN', 'discount_value' => 10, 'stay_from' => t_day( 100 ), 'stay_to' => t_day( 130 ) ) );
$huge   = $pc( array( 'code' => 'HUGE', 'discount_type' => 'fixed', 'discount_value' => 5000 ) );
t_ok( is_wp_error( $pc( array( 'code' => 'Summer20', 'discount_value' => 5 ) ) ), 'codes are unique regardless of case' );
t_ok( is_wp_error( $pc( array( 'code' => 'X', 'discount_value' => 120 ) ) ), 'more than 100% refused' );
t_eq( 'SUMMER20', Flexo_Booking_Promo_Codes::get( $summer )['code'], 'stored in capitals' );

t_settings( array( 'tourist_tax_amount' => '1.50', 'tourist_tax_children' => 'adult', 'tourist_tax_collect' => 'booking' ) );
$pq = static function ( $code, $extra = array() ) use ( $family, $breakfast, $in, $out ) {
	return t_quote( array_merge( array( 'room' => $family, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $breakfast, 'promo_code' => $code, 'context' => 'booking' ), $extra ) );
};
$x = $pq( 'summer20' );
t_ok( empty( $x['promo_error'] ) && 'SUMMER20' === $x['promo']['code'], 'percentage code applied, typed in small letters' );
t_eq( -74.4, t_line( $x, 'discount' ), '20% of room + rate plan (300 + 72 = 372), not of the tax' );
t_eq( 372 - 74.4 + 9, $x['total'], 'final total 306.60' );
t_ok( 74.4 === $x['discount_total'] && 372.0 === $x['subtotal'], 'subtotal and discount for the guest' );
$v = Flexo_Booking_Pricing::public_view( $x );
t_ok( '372.00 €' === $v['subtotal_formatted'] && '−74.40 €' === $v['discount_formatted'] && '306.60 €' === $v['total_formatted'], 'guest sees Subtotal 372.00 €, Discount −74.40 €, Final total 306.60 €' );
t_eq( -30.0, t_line( $pq( 'WELCOME' ), 'discount' ), 'fixed code: −30' );
$msg = static function ( $quote ) {
	return empty( $quote['promo_error'] ) ? '' : $quote['promo_error']['code'] . ': ' . $quote['promo_error']['message'];
};
t_ok( 0 === strpos( $msg( $pq( 'OLD' ) ), 'flexo_promo_expired' ), 'expired – ' . $msg( $pq( 'OLD' ) ) );
t_ok( 0 === strpos( $msg( $pq( 'LATER' ) ), 'flexo_promo_not_yet' ), 'not active yet – ' . $msg( $pq( 'LATER' ) ) );
t_ok( 0 === strpos( $msg( $pq( 'PAUSED' ) ), 'flexo_promo_invalid' ), 'switched off = not valid' );
t_ok( 0 === strpos( $msg( $pq( 'NOPE' ) ), 'flexo_promo_invalid' ), 'unknown – ' . $msg( $pq( 'NOPE' ) ) );
t_ok( null === t_line( $pq( 'NOPE' ), 'discount' ) && 381.0 === $pq( 'NOPE' )['total'], 'invalid code: no discount, price unchanged' );
t_ok( 0 === strpos( $msg( t_quote( array( 'room' => $double, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $breakfast, 'promo_code' => 'FAMILY', 'context' => 'booking' ) ) ), 'flexo_promo_room' ), 'room restriction – ' . $msg( t_quote( array( 'room' => $double, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan_id' => $breakfast, 'promo_code' => 'FAMILY', 'context' => 'booking' ) ) ) );
t_ok( '' === $msg( $pq( 'FAMILY' ) ), '…valid for the family room' );
t_ok( '' === $msg( $pq( 'BREAKFAST' ) ), 'rate-plan restriction: valid with Breakfast' );
t_ok( 0 === strpos( $msg( $pq( 'BREAKFAST', array( 'rate_plan_id' => $half ) ) ), 'flexo_promo_rate' ), 'rate-plan restriction: not with Half Board – ' . $msg( $pq( 'BREAKFAST', array( 'rate_plan_id' => $half ) ) ) );
t_ok( 0 === strpos( $msg( $pq( 'BIG' ) ), 'flexo_promo_min_amount' ), 'minimum amount not reached (372 < 500) – ' . $msg( $pq( 'BIG' ) ) );
t_ok( '' === $msg( $pq( 'BIG', array( 'rate_plan_id' => $half, 'adults' => 2, 'children' => 2, 'children_ages' => array( 12, 13 ) ) ) ), '…reached with Half Board for 4 (516)' );
t_ok( 0 === strpos( $msg( $pq( 'LONG' ) ), 'flexo_promo_min_nights' ), 'minimum nights – ' . $msg( $pq( 'LONG' ) ) );
t_ok( 0 === strpos( $msg( $pq( 'AUTUMN' ) ), 'flexo_promo_dates' ), 'stay dates – ' . $msg( $pq( 'AUTUMN' ) ) );
t_ok( '' === $msg( $pq( 'AUTUMN', array( 'check_in' => t_day( 100 ), 'check_out' => t_day( 131 ) ) ) ), '…valid when every night is inside (last night = stay_to)' );
$hq = $pq( 'HUGE' );
t_ok( -372.0 === t_line( $hq, 'discount' ) && 9.0 === $hq['total'], 'total never below zero: discount capped at 372, only the tax (9) remains' );

// Usage limit: only confirmed bookings count; cancelling gives the use back.
$p1 = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'once' ) ) );
t_ok( is_array( $p1 ) && 'pending' === $p1['status'] && 'ONCE' === $p1['promo_code'] && $p1['promo_id'] === $limit, 'booking request with the code (pending)' );
t_eq( 0, Flexo_Booking_Promo_Codes::uses( $limit ), 'a pending request doesn\'t count' );
$p2 = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'ONCE' ) ) );
t_ok( is_array( $p2 ), 'a second request can still use it' );
Flexo_Booking_Bookings::update_status( $p1['id'], 'confirmed' );
t_eq( 1, Flexo_Booking_Promo_Codes::uses( $limit ), 'confirmed: 1 use' );
t_ok( 0 === strpos( $msg( $pq( 'ONCE' ) ), 'flexo_promo_used_up' ), 'limit reached – ' . $msg( $pq( 'ONCE' ) ) );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'ONCE' ) ) );
t_ok( is_wp_error( $e ) && 'flexo_promo_used_up' === $e->get_error_code(), 'booking with a used-up code is refused (re-validated when booking)' );
Flexo_Booking_Bookings::update_status( $p2['id'], 'confirmed' );
t_ok( false !== strpos( Flexo_Booking_Promo_Codes::over_limit_warning( Flexo_Booking_Bookings::get( $p2['id'] ) ), 'above its limit of 1' ), 'staff are warned when confirming a request pushes a code over its limit' );
Flexo_Booking_Bookings::update_status( $p2['id'], 'cancelled' );
Flexo_Booking_Bookings::update_status( $p1['id'], 'cancelled' );
t_eq( 0, Flexo_Booking_Promo_Codes::uses( $limit ), 'cancelling restores the uses' );
t_ok( '' === $msg( $pq( 'ONCE' ) ), 'code usable again' );
t_settings( array( 'booking_mode' => 'instant' ) );
$p3 = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'ONCE' ) ) );
t_ok( is_array( $p3 ) && 'confirmed' === $p3['status'] && 1 === Flexo_Booking_Promo_Codes::uses( $limit ), 'instant booking uses the code at once' );
$map = Flexo_Booking_Promo_Codes::uses_map();
t_eq( 1, isset( $map[ $limit ] ) ? $map[ $limit ] : 0, 'admin list usage count' );
t_settings( array( 'booking_mode' => 'request' ) );
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'NOPE' ) ) );
t_ok( is_wp_error( $e ) && 'flexo_promo_invalid' === $e->get_error_code(), 'booking with an invalid code is refused, not silently charged more' );
t_features( array( 'children', 'rate_plans', 'tourist_tax' ) );
$nb = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'promo_code' => 'SUMMER20' ) ) );
t_ok( is_array( $nb ) && '' === $nb['promo_code'] && 381.0 === $nb['total'], 'feature off: codes are ignored' );
t_features( array( 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ) );

// Manipulated totals.
$e = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'expected_total' => 1 ) ) );
t_ok( is_wp_error( $e ) && 'flexo_price_changed' === $e->get_error_code(), 'manipulated expected total (1.00) refused – ' . ( is_wp_error( $e ) ? $e->get_error_message() : '' ) );
$ok = Flexo_Booking_Bookings::create( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'rate_plan' => $breakfast, 'expected_total' => 381 ) ) );
t_ok( is_array( $ok ) && 381.0 === $ok['total'], 'matching expected total accepted' );
t_reset_inventory();
$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
$request->set_body_params( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan' => $breakfast, 'total' => 1, 'expected_total' => 1 ) ) );
$response = rest_do_request( $request );
t_eq( 409, $response->get_status(), 'REST: client-side total 1.00 → 409' );
$request->set_body_params( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rate_plan' => $breakfast, 'total' => 1 ) ) );
$response = rest_do_request( $request );
t_ok( 201 === $response->get_status() && 381.0 === (float) Flexo_Booking_Bookings::get_by_reference( $response->get_data()['reference'] )['total'], 'REST: a "total" field is ignored; the server price (381) is stored' );
t_reset_inventory();

// ---------------------------------------------------------------------------
t_section( 'REST quote and availability' );
delete_transient( 'flexo_promo_' . md5( 'unknown' ) );
$request = new WP_REST_Request( 'GET', '/flexo-booking/v1/quote' );
$request->set_query_params( array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 1, 'children_ages' => '6', 'rate_plan' => $breakfast, 'promo_code' => 'welcome' ) );
$data = rest_do_request( $request )->get_data();
t_ok( 'WELCOME' === $data['promo']['code'] && '−30.00 €' === $data['discount_formatted'], 'quote applies the code' );
t_ok( 'Breakfast Included' === $data['rate_plan']['name'] && '' !== $data['rate_plan']['cancellation_policy'], 'quote names the plan and cancellation text' );
$request->set_param( 'promo_code', 'wrong' );
$data = rest_do_request( $request )->get_data();
t_ok( 'This promo code is not valid.' === $data['promo_error'] && ! $data['promo'], 'bad code: price still returned, with the reason' );
$request->set_param( 'children_ages', '' );
t_eq( 400, rest_do_request( $request )->get_status(), 'quote without the child\'s age → 400' );
$request = new WP_REST_Request( 'GET', '/flexo-booking/v1/availability' );
$request->set_query_params( array( 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 2, 'children_ages' => '4,11' ) );
$data = rest_do_request( $request )->get_data();
t_ok( array( 4, 11 ) === $data['children_ages'] && isset( $data['rooms'][0]['plans'] ), 'availability accepts children_ages and lists plans' );
$request = new WP_REST_Request( 'POST', '/flexo-booking/v1/bookings' );
$request->set_body_params( array_merge( $g, array( 'room' => 'family-room', 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => 1, 'children_ages' => '7', 'rate_plan' => $half, 'promo_code' => 'summer20' ) ) );
$response = rest_do_request( $request );
$stored   = Flexo_Booking_Bookings::get_by_reference( $response->get_data()['reference'] );
t_ok( 201 === $response->get_status() && '7' === $stored['children_ages'] && $half === $stored['rate_plan_id'] && 'SUMMER20' === $stored['promo_code'], 'REST booking stores ages, plan and code' );
t_ok( $stored['discount_total'] > 0 && $stored['tax_total'] > 0, 'discount and tax totals stored for reporting' );

// ---------------------------------------------------------------------------
t_section( 'Price snapshot' );
$before       = $stored;
$before_lines = Flexo_Booking_Pricing::format_lines( Flexo_Booking_Pricing::snapshot( $before ) );
$before_mail  = Flexo_Booking_Emails::placeholders( $before );
Flexo_Booking_Rate_Plans::save( array_merge( Flexo_Booking_Rate_Plans::get( $half ), array( 'adjustment_value' => 99, 'name' => 'Half Board Deluxe' ) ), $half );
Flexo_Booking_Promo_Codes::save( array_merge( Flexo_Booking_Promo_Codes::get( $summer ), array( 'discount_value' => 50 ) ), $summer );
t_settings( array( 'tourist_tax_amount' => 5, 'child_percent' => 100 ) );
$room_price = get_post_meta( $family, '_flexo_price', true );
update_post_meta( $family, '_flexo_price', 999 );
foreach ( Flexo_Booking_Seasons::for_room( $family ) as $season ) {
	Flexo_Booking_Seasons::save( array_merge( $season, array( 'price' => 500 ) ), $season['id'] );
}
$after = Flexo_Booking_Bookings::get( $stored['id'] );
t_eq( $before['total'], $after['total'], 'total unchanged after editing room, season, plan, promo, tax and child prices' );
t_eq( $before_lines, Flexo_Booking_Pricing::format_lines( Flexo_Booking_Pricing::snapshot( $after ) ), 'breakdown unchanged' );
t_eq( 'Half Board', Flexo_Booking_Admin::rate_plan_name( $after ), 'plan name as booked' );
t_eq( $before_mail['{booking_details}'], Flexo_Booking_Emails::placeholders( $after )['{booking_details}'], 'email details unchanged' );
echo '    ' . str_replace( "\n", "\n    ", $before_mail['{booking_details}'] ) . "\n";
update_post_meta( $family, '_flexo_price', $room_price );
Flexo_Booking_Rate_Plans::delete( $half );
t_eq( 'Half Board', Flexo_Booking_Admin::rate_plan_name( Flexo_Booking_Bookings::get( $stored['id'] ) ), 'deleting the plan keeps it on the booking' );

// ---------------------------------------------------------------------------
t_section( 'CSV and admin' );
wp_set_current_user( 1 );
$csv = Flexo_Booking_Pricing::summary_text( Flexo_Booking_Pricing::snapshot( $after ) );
t_ok( false !== strpos( $csv, 'Rate: Half Board' ) && false !== strpos( $csv, 'Promo code SUMMER20' ) && false !== strpos( $csv, 'Tourist tax' ), 'price details text has plan, code and tax' );
ob_start();
$_GET = array( 'booking' => $stored['id'] );
Flexo_Booking_Admin::render_list();
$html = ob_get_clean();
t_ok( false !== strpos( $html, '2 adults, 1 child (ages 7)' ) && false !== strpos( $html, 'SUMMER20' ) && false !== strpos( $html, 'Half Board' ) && false !== strpos( $html, 'Subtotal' ), 'booking details: guests with ages, plan, code, full breakdown' );
$_GET = array();

// Reset for the other test files.
t_reset_inventory();
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'rate_plans' ) );
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'promo_codes' ) );
Flexo_Booking_Rate_Plans::flush_cache();
Flexo_Booking_Rate_Plans::add_presets();
t_settings( array( 'tourist_tax_amount' => 0, 'child_percent' => 50, 'booking_mode' => 'request' ) );
t_features( array() );
t_done();
