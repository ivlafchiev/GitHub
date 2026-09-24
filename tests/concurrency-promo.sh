#!/usr/bin/env bash
# Two instant bookings in *different* rooms race for the last use of a promo
# code. Exactly one may get it.   Usage: WPCLI=... tests/concurrency-promo.sh <site-dir>
set -euo pipefail
SITE="$1"; DIR="$(cd "$(dirname "$0")" && pwd)"
WP="php $WPCLI --allow-root --path=$SITE"

$WP eval '
	global $wpdb;
	$wpdb->query( "DELETE FROM " . Flexo_Booking_Install::table() );
	$wpdb->query( "DELETE FROM " . Flexo_Booking_Schema::table( "promo_codes" ) );
	foreach ( array( "race-a", "race-b" ) as $slug ) {
		$p  = get_page_by_path( $slug, OBJECT, "flexo_room" );
		$id = $p ? $p->ID : wp_insert_post( array( "post_type" => "flexo_room", "post_title" => $slug, "post_name" => $slug, "post_status" => "publish" ) );
		Flexo_Booking_Rooms::save_meta_values( $id, array( "_flexo_price" => 100, "_flexo_capacity" => 2, "_flexo_units" => 5 ) );
		Flexo_Booking_Rate_Plans::set_room_assignments( $id, array() );
	}
	Flexo_Booking_Features::set_enabled( array( "booking_request", "instant_booking", "guest_emails", "promo_codes" ) );
	$s = Flexo_Booking_Settings::all(); $s["booking_mode"] = "instant"; update_option( Flexo_Booking_Settings::OPTION, $s );
	Flexo_Booking_Promo_Codes::save( array( "code" => "RACE1", "discount_type" => "percent", "discount_value" => 10, "active" => 1, "max_uses" => 1 ) );
' >/dev/null

export RACE_IN=$($WP eval 'echo "DATE=", wp_date("Y-m-d", strtotime("+200 days"));' 2>/dev/null | grep -oE 'DATE=[0-9-]+' | cut -d= -f2)
export RACE_OUT=$($WP eval 'echo "DATE=", wp_date("Y-m-d", strtotime("+202 days"));' 2>/dev/null | grep -oE 'DATE=[0-9-]+' | cut -d= -f2)
export RACE_PROMO=race1

OUT=$(mktemp)
RACER=A RACE_ROOM=race-a $WP eval-file "$DIR/concurrency-book.php" >> "$OUT" 2>&1 &
RACER=B RACE_ROOM=race-b $WP eval-file "$DIR/concurrency-book.php" >> "$OUT" 2>&1 &
wait
grep -E "^racer" "$OUT"

BOOKED=$(grep -c "BOOKED" "$OUT" || true)
REJECTED=$(grep -c "REJECTED (flexo_promo_used_up)" "$OUT" || true)
USES=$($WP eval 'echo "USES=", Flexo_Booking_Promo_Codes::uses( Flexo_Booking_Promo_Codes::get_by_code( "RACE1" )["id"] );' 2>/dev/null | grep -oE 'USES=[0-9]+' | cut -d= -f2)
$WP eval '
	global $wpdb;
	$wpdb->query( "DELETE FROM " . Flexo_Booking_Install::table() );
	$wpdb->query( "DELETE FROM " . Flexo_Booking_Schema::table( "promo_codes" ) );
	$s = Flexo_Booking_Settings::all(); $s["booking_mode"] = "request"; update_option( Flexo_Booking_Settings::OPTION, $s );
	Flexo_Booking_Features::set_enabled( Flexo_Booking_Features::default_enabled() );
' >/dev/null
rm -f "$OUT"
echo "booked=$BOOKED rejected=$REJECTED uses=$USES"
if [ "$BOOKED" = "1" ] && [ "$REJECTED" = "1" ] && [ "$USES" = "1" ]; then
	echo "PASS only one of two simultaneous bookings got the promo code's last use"
else
	echo "FAIL promo concurrency"; exit 1
fi
