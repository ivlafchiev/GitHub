# Flexo Booking tests

Scripts that run inside a throwaway WordPress site through WP-CLI. They are
not part of the plugin zip.

| File | What it checks |
|---|---|
| `test-pricing-parity.php` | The pricing service gives exactly the 1.0.0 prices (520 random stays per mode), and the legacy filter still works |
| `test-seasons.php` | Seasons (inside, crossing, outside, weekend, minimum stay, overlap validation, copy to next year) and closed dates |
| `test-features.php` | Available vs enabled features, the Agency list, the wp-config constants, hidden admin UI, and settings tabs |
| `test-regression.php` | 1.0.0 behaviour: validation, inventory, overbooking, staff bookings and blocks, instant mode, emails, CSV text, currency |
| `concurrency.sh` + `concurrency-book.php` | Two processes book the last unit at the same moment; exactly one succeeds |
| `upgrade-fixture.php` / `upgrade-verify.php` | Data created on 1.0.0 survives the upgrade |
| `portability-*.php` | Export from a template site and import into a fresh site (including seasons, closed dates and features) |
| `e2e/day1.js` | Browser flow (Playwright): guest booking with seasons, admin screens, Agency, CSV, Elementor |

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

Browser test: serve the site (`php -S localhost:8092 -t /tmp/site router.php`),
seed rooms/seasons as in `e2e/day1.js`'s header, then
`BASE=http://localhost:8092 D0=<seed Monday> node tests/e2e/day1.js`.
