#!/usr/bin/env bash
# Two processes book the last unit of a room at the same time.
# Exactly one must succeed.   Usage: WPCLI=... tests/concurrency.sh <site-dir>
# With RACE_PAY=1 both guests start a card payment: only one gets the hold.
set -euo pipefail
SITE="$1"; DIR="$(cd "$(dirname "$0")" && pwd)"
WP="php $WPCLI --allow-root --path=$SITE"

$WP eval '
	global $wpdb;
	$wpdb->query( "DELETE FROM " . Flexo_Booking_Install::table() );
	$p = get_page_by_path( "race-room", OBJECT, "flexo_room" );
	$id = $p ? $p->ID : wp_insert_post( array( "post_type" => "flexo_room", "post_title" => "Race Room", "post_name" => "race-room", "post_status" => "publish" ) );
	Flexo_Booking_Rooms::save_meta_values( $id, array( "_flexo_price" => 100, "_flexo_capacity" => 2, "_flexo_units" => 1 ) );
' >/dev/null
if [ -n "${RACE_PAY:-}" ]; then
	$WP eval '
		Flexo_Booking_Features::set_enabled( array( "booking_request", "instant_booking", "online_payment" ) );
		update_option( Flexo_Booking_Settings::OPTION, array_merge( Flexo_Booking_Settings::all(), array( "booking_mode" => "instant", "payment_mode" => "full", "stripe_mode" => "test" ) ) );
		update_option( Flexo_Booking_Payments::SECRETS_OPTION, array( "stripe_test_secret_key" => "sk_test_race", "stripe_test_webhook_secret" => "whsec_race" ) );
	' >/dev/null
fi

# Other plugins may print notices; pick the values out of the output.
export RACE_IN=$($WP eval 'echo "DATE=", wp_date("Y-m-d", strtotime("+200 days"));' 2>/dev/null | grep -oE 'DATE=[0-9-]+' | cut -d= -f2)
export RACE_OUT=$($WP eval 'echo "DATE=", wp_date("Y-m-d", strtotime("+202 days"));' 2>/dev/null | grep -oE 'DATE=[0-9-]+' | cut -d= -f2)

OUT=$(mktemp)
RACER=A $WP eval-file "$DIR/concurrency-book.php" >> "$OUT" 2>&1 &
RACER=B $WP eval-file "$DIR/concurrency-book.php" >> "$OUT" 2>&1 &
wait
grep -E "^racer" "$OUT"

BOOKED=$(grep -c "BOOKED" "$OUT" || true)
REJECTED=$(grep -c "REJECTED (flexo_unavailable)" "$OUT" || true)
ROWS=$($WP eval 'global $wpdb; echo "ROWS=", $wpdb->get_var( "SELECT COUNT(*) FROM " . Flexo_Booking_Install::table() );' 2>/dev/null | grep -oE 'ROWS=[0-9]+' | cut -d= -f2)
HOLDS=$($WP eval 'global $wpdb; echo "HOLDS=", $wpdb->get_var( "SELECT COUNT(*) FROM " . Flexo_Booking_Install::table() . " WHERE status = \"pending_payment\"" );' 2>/dev/null | grep -oE 'HOLDS=[0-9]+' | cut -d= -f2)
if [ -n "${RACE_PAY:-}" ]; then
	$WP eval '
		Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
		delete_option( Flexo_Booking_Payments::SECRETS_OPTION );
		update_option( Flexo_Booking_Settings::OPTION, array_merge( Flexo_Booking_Settings::all(), array( "booking_mode" => "request" ) ) );
	' >/dev/null
	[ "$HOLDS" = "1" ] || { echo "FAIL the booked unit should be a payment hold (holds=$HOLDS)"; exit 1; }
fi
rm -f "$OUT"
echo "booked=$BOOKED rejected=$REJECTED rows=$ROWS"
if [ "$BOOKED" = "1" ] && [ "$REJECTED" = "1" ] && [ "$ROWS" = "1" ]; then
	echo "PASS only one of two simultaneous bookings got the last unit"
else
	echo "FAIL concurrency"; exit 1
fi
