#!/usr/bin/env bash
# Runs the Flexo Booking test suite against an existing test site.
#
#   WPCLI=/path/wp-cli.phar tests/run.sh <site-dir>
#
# The site must have Flexo Booking active; tests create and delete their own
# rooms and bookings, so never point this at a live site.
set -uo pipefail
SITE="$1"; DIR="$(cd "$(dirname "$0")" && pwd)"
WP="php $WPCLI --allow-root --path=$SITE"
FAILED=0

run() {
	echo "### $1"
	local out
	out=$("${@:2}" 2>&1)
	local code=$?
	echo "$out" | grep -E "FAIL|Result|PASS only"
	[ "$code" = "0" ] || FAILED=1
}

# Bulgarian must be an installed WordPress language for the translation tests;
# an empty stand-in for the core language pack is enough (no download needed).
LANGDIR="$SITE/wp-content/languages"
mkdir -p "$LANGDIR"
[ -f "$LANGDIR/bg_BG.mo" ] || cp "$DIR/fixtures/empty.mo" "$LANGDIR/bg_BG.mo"

for t in test-pricing-parity test-seasons test-features test-regression test-ical test-day3 test-day4; do
	run "$t" $WP eval-file "$DIR/$t.php"
done
$WP option update WPLANG bg_BG >/dev/null 2>&1
run "test-i18n (site in Bulgarian)" $WP eval-file "$DIR/test-i18n.php"
$WP option update WPLANG '' >/dev/null 2>&1
run "test-features (wp-config constants)" env FLEXO_CONST_TEST=1 $WP --exec="define('FLEXO_BOOKING_FEATURES','booking_request,seasonal_pricing,guest_emails'); define('FLEXO_BOOKING_AGENCY_USERS','support, agency-test@flexohotels.test');" eval-file "$DIR/test-features.php"
run "concurrency" "$DIR/concurrency.sh" "$SITE"
run "concurrency (promo code limit)" "$DIR/concurrency-promo.sh" "$SITE"

[ "$FAILED" = "0" ] && echo "ALL PASSED" || { echo "SOME TESTS FAILED"; exit 1; }
