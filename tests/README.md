# Flexo Booking tests

Scripts that run inside a throwaway WordPress site through WP-CLI. They are
not part of the plugin zip.

| File | What it checks |
|---|---|
| `test-pricing-parity.php` | The pricing service gives exactly the 1.0.0 prices (520 random stays per mode), and the legacy filter still works |
| `test-seasons.php` | Seasons (inside, crossing, outside, weekend, minimum stay, overlap validation, copy to next year) and closed dates |
| `test-features.php` | Available vs enabled features, the Agency list, the wp-config constants, hidden admin UI, and settings tabs |
| `test-regression.php` | 1.0.0 behaviour: validation, inventory, overbooking, staff bookings and blocks, instant mode, emails, CSV text, currency |
| `test-ical.php` + `fixtures/*.ics` | Calendar sync: parser (Booking.com, Airbnb, generic feeds), import, re-sync, broken feeds, unit rule, conflicts and email, export feed, tokens, cron, feature switch |
| `portability-calendars.php` | Calendar connections in Import/Export (off by default, no tokens) |
| `test-day3.php` | Children & ages, rate plans, tourist tax, promo codes, manipulated totals, REST quote, price snapshots |
| `portability-day3.php` | Rate plans, promo codes, child prices and tax settings through Import/Export (`FLEXO_PHASE=source` on the template site, then `FLEXO_PHASE=target` on a fresh site, same `FLEXO_EXPORT` file) |
| `concurrency-promo.sh` | Two instant bookings in different rooms race for a promo code's last use; exactly one gets it |
| `concurrency.sh` + `concurrency-book.php` | Two processes book the last unit at the same moment; exactly one succeeds |
| `upgrade-fixture.php` / `upgrade-verify.php` | Data created on 1.0.0 survives the upgrade |
| `portability-*.php` | Export from a template site and import into a fresh site (including seasons, closed dates and features) |
| `e2e/day1.js` + `e2e/seed-day1.php` | Browser flow (Playwright): guest booking with seasons, admin screens, Agency, CSV, Elementor |
| `e2e/day3.js` + `e2e/seed-day3.php` | Guest flow with children's ages, rate plan choice, promo code and tourist tax (desktop and phone), price-changed recovery, admin screens, features off. The seed prints `D0`. |
| `e2e/day2.js` + `e2e/seed-day2.php` | Admin calendar, Calendar Sync screen, conflicts, phone width. The seed writes real `.ics` files to `<site>/feeds/` and adds a test-only mu-plugin allowing localhost fetches. |

## Running

```bash
export WP_CORE_DIR=/path/to/wordpress WPCLI=/path/to/wp-cli.phar
# MySQL/MariaDB (recommended – exercises GET_LOCK):
DB_NAME=flexo_test tests/make-site.sh /tmp/site http://localhost:8092 flexo-booking
# or SQLite:
SQLITE_PLUGIN_DIR=/path/to/sqlite-database-integration tests/make-site.sh /tmp/site http://localhost:8092 flexo-booking

tests/run.sh /tmp/site
```

Upgrade test: build the site from the **1.0.0** plugin, run
`FLEXO_FIXTURE=/tmp/f.json wp eval-file tests/upgrade-fixture.php`, replace
the plugin folder with the new version, then run `tests/upgrade-verify.php`
with the same `FLEXO_FIXTURE`.

Browser tests: serve the site (`php -S localhost:8092 -t /tmp/site router.php`),
run the seed (`wp eval-file tests/e2e/seed-day1.php`, or `seed-day2.php` /
`seed-day3.php`, which print `D0`), then
`BASE=http://localhost:8092 D0=<D0> node tests/e2e/day1.js` (or `day2.js`, `day3.js`).
Re-seed before every run.
