<?php
/**
 * Import/Export with every feature configured, into a fresh site: the
 * target gives the same prices and the same payment schedule, while API
 * keys, webhook secrets, the bank account and the notification email stay
 * behind.
 *
 *   FLEXO_PHASE=source FLEXO_EXPORT=/tmp/full.json wp eval-file tests/portability-full.php   (template site)
 *   FLEXO_PHASE=target FLEXO_EXPORT=/tmp/full.json wp eval-file tests/portability-full.php   (fresh site)
 */
require __DIR__ . '/lib.php';
global $wpdb;
$file = getenv( 'FLEXO_EXPORT' );

/** The quotes both sites must agree on. */
function tf_quotes() {
	$out = array();
	foreach ( array( array( 'full-apartment', 30, 34, 2, '5,10', 'SPRING15' ), array( 'full-double', 31, 33, 2, '', '' ), array( 'full-apartment', 40, 43, 3, '1', '' ) ) as $case ) {
		$post = Flexo_Booking_Rooms::find( $case[0] );
		$plan = Flexo_Booking_Rate_Plans::for_room( $post->ID );
		$q    = Flexo_Booking_Pricing::quote(
			array(
				'room'          => $case[0],
				'check_in'      => t_day( $case[1] ),
				'check_out'     => t_day( $case[2] ),
				'adults'        => $case[3],
				'children_ages' => $case[4],
				'children'      => '' === $case[4] ? 0 : count( explode( ',', $case[4] ) ),
				'rate_plan_id'  => $plan ? $plan[0]['id'] : 0,
				'promo_code'    => $case[5],
				'context'       => 'booking',
			)
		);
		$out[] = is_wp_error( $q ) ? $q->get_error_message() : array( $q['total'], $q['tax_total'], $q['discount_total'], $q['payable']['now'], $q['payable']['at_property'] );
	}
	return $out;
}

$all = array( 'booking_request', 'instant_booking', 'seasonal_pricing', 'calendar_sync', 'rate_plans', 'children', 'tourist_tax', 'promo_codes', 'privacy_consent', 'invoice_request', 'guest_emails', 'tracking', 'online_payment', 'deposit', 'bank_transfer' );

if ( 'source' === getenv( 'FLEXO_PHASE' ) ) {
	$saved = array( get_option( Flexo_Booking_Settings::OPTION ), get_option( Flexo_Booking_Features::ENABLED_OPTION ), get_option( Flexo_Booking_Payments::SECRETS_OPTION ) );
	t_reset_inventory();
	foreach ( array( 'rate_plans', 'promo_codes' ) as $table ) {
		$wpdb->query( 'DELETE FROM ' . Flexo_Booking_Schema::table( $table ) );
	}
	Flexo_Booking_Rate_Plans::flush_cache();
	Flexo_Booking_Features::set_available( null );
	Flexo_Booking_Features::set_enabled( $all );
	update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( 'stripe_test_secret_key' => 'sk_test_template', 'stripe_test_webhook_secret' => 'whsec_template', 'stripe_live_secret_key' => 'sk_live_template', 'stripe_live_webhook_secret' => 'whsec_live_template' ) );
	update_option(
		Flexo_Booking_Settings::OPTION,
		Flexo_Booking_Settings::sanitize(
			array_merge(
				Flexo_Booking_Settings::all(),
				array(
					'booking_mode'                => 'instant',
					'notification_email'          => 'template@agency.test',
					'currency'                    => 'EUR',
					'child_free_under'            => 3,
					'child_percent'               => 50,
					'child_adult_from'            => 12,
					'tourist_tax_amount'          => '1.50',
					'tourist_tax_collect'         => 'property',
					'tourist_tax_children'        => 'exempt',
					'tourist_tax_exempt_under'    => 7,
					'privacy_consent_text'        => 'I accept the {privacy_policy} of the template hotel.',
					'retention_months'            => 24,
					'invoice_vat_number'          => 'required',
					'email_confirmed_subject'     => 'Custom confirmed {booking_ref}',
					'email_pre_arrival_enabled'   => 1,
					'tracking_meta_pixel'         => 1,
					'payment_mode'                => 'deposit',
					'deposit_type'                => 'percent',
					'deposit_value'               => 25,
					'stripe_mode'                 => 'live',
					'hold_minutes'                => 25,
					'bank_beneficiary'            => 'Template Hotel Ltd.',
					'bank_iban'                   => 'BG80BNBG96611020345678',
					'bank_bic'                    => 'BNBGBGSD',
					'bank_name'                   => 'Template Bank',
					'bank_reference'              => 'Booking {booking_ref}',
					'bank_transfer_days'          => 5,
					'bank_transfer_reminder_days' => 2,
					'bank_transfer_auto_cancel'   => 0,
				)
			)
		)
	);
	$apt = t_room( 'full-apartment', 'Full Apartment', array( 'price' => 150, 'weekend_price' => 180, 'capacity' => 5, 'units' => 2, 'max_adults' => 3 ) );
	$dbl = t_room( 'full-double', 'Full Double', array( 'price' => 90, 'capacity' => 2, 'units' => 4 ) );
	Flexo_Booking_Seasons::save( array( 'room_id' => $apt, 'name' => 'Summer', 'date_from' => t_day( 30 ), 'date_to' => t_day( 36 ), 'price' => 210, 'min_nights' => 3 ) );
	Flexo_Booking_Closures::save( array( 'room_id' => $dbl, 'date_from' => t_day( 50 ), 'date_to' => t_day( 52 ), 'reason' => 'Renovation' ) );
	$bb = Flexo_Booking_Rate_Plans::save( array( 'name' => 'Half Board', 'adjustment_type' => 'per_guest_night', 'adjustment_value' => 20, 'active' => 1, 'refundable' => 1, 'cancellation_policy' => 'Free until 7 days before.' ) );
	Flexo_Booking_Rate_Plans::set_room_assignments( $apt, array( $bb => null ) );
	Flexo_Booking_Promo_Codes::save( array( 'code' => 'SPRING15', 'discount_type' => 'percent', 'discount_value' => 15, 'active' => 1 ) );

	$data = Flexo_Booking_Portability::export();
	file_put_contents( $file, wp_json_encode( $data ) );
	file_put_contents( $file . '.quotes', wp_json_encode( tf_quotes() ) );
	// The template site goes back to how it was.
	update_option( Flexo_Booking_Settings::OPTION, $saved[0] );
	update_option( Flexo_Booking_Features::ENABLED_OPTION, $saved[1] );
	update_option( Flexo_Booking_Payments::SECRETS_OPTION, $saved[2] );
	$json = wp_json_encode( $data );
	t_section( 'Export from the template' );
	t_eq( 5, $data['schema'], 'schema 5' );
	t_eq( $all, array_values( array_intersect( $all, $data['features']['enabled'] ) ), 'all 15 features in the file' );
	t_ok( false === strpos( $json, 'sk_test_template' ) && false === strpos( $json, 'sk_live_template' ) && false === strpos( $json, 'whsec_' ), 'no Stripe keys or webhook secrets' );
	t_ok( false === strpos( $json, 'BG80BNBG' ) && false === strpos( $json, 'Template Hotel Ltd.' ) && false === strpos( $json, 'Template Bank' ), 'no bank account' );
	t_ok( false === strpos( $json, 'template@agency.test' ), 'no notification email' );
	t_eq( 'deposit', $data['settings']['payment_mode'], 'payment mode in the file' );
	t_ok( 25.0 === (float) $data['settings']['deposit_value'] && 25 === (int) $data['settings']['hold_minutes'] && 'Booking {booking_ref}' === $data['settings']['bank_reference'] && 5 === (int) $data['settings']['bank_transfer_days'], 'deposit, hold and bank transfer rules in the file' );
	t_done();
	return;
}

// Target: a fresh site with its own admin email.
t_section( 'Import into a fresh site' );
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( Flexo_Booking_Settings::all(), array( 'notification_email' => 'reception@newhotel.test', 'bank_iban' => '', 'bank_beneficiary' => '' ) ) ) );
delete_option( Flexo_Booking_Payments::SECRETS_OPTION );
$data   = json_decode( file_get_contents( $file ), true );
$result = Flexo_Booking_Portability::import( $data, array( 'settings' => true, 'rooms' => true ) );
t_ok( ! is_wp_error( $result ), 'import finished' );
Flexo_Booking_Features::reset_cache();
Flexo_Booking_Rate_Plans::flush_cache();
foreach ( $all as $feature ) {
	t_ok( Flexo_Booking_Features::is_enabled( $feature ) || in_array( $feature, array( 'booking_request' ), true ), "feature {$feature} on" );
}
$s = Flexo_Booking_Settings::all();
t_eq( 'reception@newhotel.test', $s['notification_email'], 'own notification email kept' );
t_eq( 'deposit', $s['payment_mode'], 'payment mode' );
t_eq( 25.0, (float) $s['deposit_value'], 'deposit 25%' );
t_eq( 'live', $s['stripe_mode'], 'Stripe mode' );
t_eq( 25, (int) $s['hold_minutes'], 'hold minutes' );
t_ok( 'Booking {booking_ref}' === $s['bank_reference'] && 5 === (int) $s['bank_transfer_days'] && 2 === (int) $s['bank_transfer_reminder_days'] && 0 === (int) $s['bank_transfer_auto_cancel'], 'bank transfer rules' );
t_ok( '' === $s['bank_iban'] && '' === $s['bank_beneficiary'], 'no bank account copied' );
t_eq( '', Flexo_Booking_Payments::secret( 'stripe_live_secret_key' ), 'no Stripe key copied' );
t_eq( array(), Flexo_Booking_Payments::methods(), 'until keys and bank details are entered, guests can\'t pay online' );
t_ok( ! Flexo_Booking_Payments::collects_now(), '…and bookings fall back to paying at the property' );
t_eq( 'Custom confirmed {booking_ref}', $s['email_confirmed_subject'], 'email texts' );
t_ok( 24 === (int) $s['retention_months'] && 'required' === $s['invoice_vat_number'] && 1 === (int) $s['tracking_meta_pixel'], 'privacy, invoice and tracking settings' );

t_section( 'Same prices on both sites' );
$s['payment_mode'] = 'deposit';
update_option( Flexo_Booking_Settings::OPTION, Flexo_Booking_Settings::sanitize( array_merge( $s, array( 'bank_beneficiary' => 'New Hotel Ltd.', 'bank_iban' => 'BG11 UNCR 7000 1519 5627 35' ) ) ) );
t_eq( array( 'bank_transfer' ), Flexo_Booking_Payments::methods(), 'after entering its bank details: bank transfer ready' );
t_eq( json_decode( file_get_contents( $file . '.quotes' ), true ), json_decode( wp_json_encode( tf_quotes() ), true ), 'totals, tax, discount, deposit and amount at the property identical' );
t_done();
