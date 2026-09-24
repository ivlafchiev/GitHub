<?php
/**
 * Import/Export of Day 3 data: child rules, max adults, rate plans (and
 * which rooms offer them), tourist tax settings and promo codes (without
 * usage counts).
 *
 *   FLEXO_PHASE=source FLEXO_EXPORT=/tmp/d3.json wp eval-file tests/portability-day3.php   (template site)
 *   FLEXO_PHASE=target FLEXO_EXPORT=/tmp/d3.json wp eval-file tests/portability-day3.php   (fresh site)
 */
require __DIR__ . '/lib.php';
global $wpdb;
$file = getenv( 'FLEXO_EXPORT' );

if ( 'source' === getenv( 'FLEXO_PHASE' ) ) {
	t_reset_inventory();
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'rate_plans' ) );
	$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'promo_codes' ) );
	Flexo_Booking_Rate_Plans::flush_cache();
	Flexo_Booking_Features::set_enabled( array( 'booking_request', 'guest_emails', 'children', 'rate_plans', 'tourist_tax', 'promo_codes' ) );
	update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'child_free_under' => 2, 'child_percent' => 40, 'child_adult_from' => 14, 'tourist_tax_amount' => '2.20', 'tourist_tax_children' => 'exempt', 'tourist_tax_exempt_under' => 7, 'tourist_tax_collect' => 'property' ) ) ) );

	$apt = t_room( 'd3-apartment', 'D3 Apartment', array( 'price' => 120, 'capacity' => 5, 'units' => 2, 'max_adults' => 3 ) );
	$std = t_room( 'd3-standard', 'D3 Standard', array( 'price' => 70, 'capacity' => 2, 'units' => 4 ) );
	Flexo_Booking_Children::save_room_rules( $apt, array( 'free_under' => 4, 'percent' => 25, 'adult_from' => 10 ) );
	Flexo_Booking_Children::save_room_rules( $std, null );
	$bb  = Flexo_Booking_Rate_Plans::save( array( 'name' => 'B&B', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 9, 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free until 3 days before.' ) );
	$nr  = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Saver', 'adjustment_type' => 'percent', 'adjustment_value' => -12, 'active' => 1, 'refundable' => 0, 'cancellation_policy' => 'No refunds.' ) );
	$off = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Winter Only', 'adjustment_type' => 'per_night', 'adjustment_value' => 5, 'active' => 0 ) );
	Flexo_Booking_Rate_Plans::set_room_assignments( $apt, array( $bb => 14.5, $nr => null ) );
	Flexo_Booking_Rate_Plans::set_room_assignments( $std, array( $bb => null, $off => null ) );
	$code = Flexo_Booking_Promo_Codes::save( array( 'code' => 'DIRECT10', 'discount_type' => 'percent', 'discount_value' => 10, 'active' => 1, 'max_uses' => 50, 'min_nights' => 2, 'room_ids' => array( $apt ), 'rate_plan_ids' => array( $bb ), 'stay_from' => '2027-05-01', 'stay_to' => '2027-09-30' ) );
	Flexo_Booking_Promo_Codes::save( array( 'code' => 'WELCOME', 'discount_type' => 'fixed', 'discount_value' => 15, 'active' => 0 ) );
	// A confirmed booking using the code: its use must not travel.
	$wpdb->insert( Flexo_Booking_Install::table(), array( 'reference' => 'FB-D3USE1', 'room_id' => $apt, 'check_in' => t_day( 60 ), 'check_out' => t_day( 62 ), 'nights' => 2, 'status' => 'confirmed', 'promo_id' => $code, 'promo_code' => 'DIRECT10', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

	$data = Flexo_Booking_Portability::export();
	file_put_contents( $file, wp_json_encode( $data ) );
	t_section( 'Export' );
	t_eq( 4, $data['schema'], 'schema 4' );
	t_eq( 3, count( $data['rate_plans'] ), 'three rate plans' );
	t_eq( 2, count( $data['promo_codes'] ), 'two promo codes' );
	t_ok( false === strpos( wp_json_encode( $data['promo_codes'] ), 'uses"' ) || ! isset( $data['promo_codes'][0]['uses'] ), 'no usage counts in the file' );
	t_done();
	return;
}

// Target site.
t_reset_inventory();
$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( 'promo_codes' ) );
Flexo_Booking_Rate_Plans::flush_cache();
$data   = json_decode( file_get_contents( $file ), true );
$before = count( Flexo_Booking_Rate_Plans::all() );
$result = Flexo_Booking_Portability::import( $data, array() );

t_section( 'Import on a fresh site' );
t_ok( ! is_wp_error( $result ) && 3 === $result['rate_plans'] && 2 === $result['promo_codes'], 'imported 3 rate plans and 2 promo codes' );
$s = Flexo_Booking_Settings::all();
t_ok( 2 === $s['child_free_under'] && 40.0 === (float) $s['child_percent'] && 14 === $s['child_adult_from'], 'child price rules' );
t_ok( 2.2 === (float) $s['tourist_tax_amount'] && 'exempt' === $s['tourist_tax_children'] && 7 === $s['tourist_tax_exempt_under'] && 'property' === $s['tourist_tax_collect'], 'tourist tax settings' );
t_ok( Flexo_Booking_Features::is_enabled( 'rate_plans' ) && Flexo_Booking_Features::is_enabled( 'promo_codes' ), 'feature switches travel' );

$apt = get_page_by_path( 'd3-apartment', OBJECT, 'flexo_room' );
$std = get_page_by_path( 'd3-standard', OBJECT, 'flexo_room' );
$apt_room = Flexo_Booking_Rooms::to_array( $apt );
t_eq( 3, $apt_room['max_adults'], 'max adults' );
t_eq( array( 'free_under' => 4, 'percent' => 25.0, 'adult_from' => 10 ), Flexo_Booking_Children::room_rules( $apt->ID ), 'room\'s own child prices' );
t_eq( null, Flexo_Booking_Children::room_rules( $std->ID ), 'other room uses the general rules' );

$bb = Flexo_Booking_Rate_Plans::get_by_name( 'B&B' );
$nr = Flexo_Booking_Rate_Plans::get_by_name( 'Saver' );
$wo = Flexo_Booking_Rate_Plans::get_by_name( 'Winter Only' );
t_ok( $bb && 'per_guest_night' === $bb['adjustment_type'] && 9.0 === $bb['adjustment_value'] && 'Free until 3 days before.' === $bb['cancellation_policy'], 'plan details' );
t_ok( $nr && ! $nr['refundable'] && -12.0 === $nr['adjustment_value'], 'non-refundable plan' );
t_ok( $wo && ! $wo['active'], 'switched-off plan stays off' );
t_eq( array( $bb['id'] => 14.5, $nr['id'] => null ), Flexo_Booking_Rate_Plans::room_assignments( $apt->ID ), 'apartment offers B&B (own amount 14.5) and Saver' );
t_eq( array( $bb['id'] => null, $wo['id'] => null ), Flexo_Booking_Rate_Plans::room_assignments( $std->ID ), 'standard room\'s plans linked by name' );

$code = Flexo_Booking_Promo_Codes::get_by_code( 'direct10' );
t_ok( $code && 50 === $code['max_uses'] && 2 === $code['min_nights'] && '2027-05-01' === $code['stay_from'] && '2027-09-30' === $code['stay_to'], 'promo code with its conditions' );
t_eq( array( $apt->ID ), $code['room_ids'], 'room restriction mapped to this site\'s room' );
t_eq( array( $bb['id'] ), $code['rate_plan_ids'], 'rate-plan restriction mapped by name' );
t_eq( 0, Flexo_Booking_Promo_Codes::uses( $code['id'] ), 'usage starts at 0 on the new site' );
t_ok( ! Flexo_Booking_Promo_Codes::get_by_code( 'WELCOME' )['active'], 'switched-off code stays off' );

$again = Flexo_Booking_Portability::import( $data, array() );
t_eq( $before + 3, count( Flexo_Booking_Rate_Plans::all() ), 're-import updates plans instead of duplicating' );
t_eq( 2, count( Flexo_Booking_Promo_Codes::all() ), 're-import updates codes instead of duplicating' );

$quote = Flexo_Booking_Pricing::quote( array( 'room' => $apt->ID, 'check_in' => t_day( 60 ), 'check_out' => t_day( 62 ), 'adults' => 2, 'children' => 1, 'children_ages' => array( 5 ), 'rate_plan_id' => $bb['id'], 'context' => 'booking' ) );
// 2 × 120 + B&B 14.5 × (2 + 0.25) × 2 = 65.25; tax 2.2 × 2 adults × 2 = 8.80 at the property (child 5 exempt under 7).
t_ok( ! is_wp_error( $quote ) && 305.25 === $quote['total'] && 8.8 === $quote['due_at_property'], 'imported setup prices a stay as on the template: 305.25 + 8.80 at the property' );

$none = Flexo_Booking_Portability::import( $data, array( 'rate_plans' => false, 'promo_codes' => false ) );
t_ok( 0 === $none['rate_plans'] && 0 === $none['promo_codes'], 'both can be left out of an import' );
t_done();
