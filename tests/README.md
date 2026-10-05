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
| `test-day4.php` | Privacy consent, guest form fields, invoice requests, retention and anonymising, WordPress personal data export/erase, emails (templates, HTML/text, notifications, scheduled reminders and review requests, log, test email, SMTP check), Import/Export of the new settings |
| `test-i18n.php` | Site in Bulgarian: Bulgarian texts and plurals, emails in the guest's language, hotel emails in the site language, dates per language. `run.sh` switches the site to `bg_BG` for it and installs `fixtures/empty.mo` as a stand-in for the Bulgarian WordPress language pack. |
| `polylang-test.php` | Needs Polylang active (`FLEXO_PHASE=setup` once, then `wp rewrite flush`, then run with `BASE=<site url>`): pages, texts, consent/thank-you pages and emails per language, Polylang string translation |
| `concurrency-promo.sh` | Two instant bookings in different rooms race for a promo code's last use; exactly one gets it |
| `concurrency.sh` + `concurrency-book.php` | Two processes book the last unit at the same moment; exactly one succeeds. With `RACE_PAY=1` both start a card payment: only one gets the hold (`run.sh` runs both) |
| `test-day5.php` | Payments: modes and amounts (deposit % / fixed, tourist tax), card payment full and deposit (Stripe's API faked with `pre_http_request`), webhook signatures (tampered, wrong secret, replay, forged, missing, other mode), idempotency, too-small payments, declined + expired, abandoned hold released, late payment (room free / taken → conflict, alert, no overbooking), room lock busy, refunds (Stripe partial/full, manual), retry, Stripe unreachable, bank transfer (instant, deposit, reminder, auto-cancel), requests (accept → payment details), card blocked with requests, pay at the property, no payments, manipulated amounts, admin card/badges, emails, settings screen, Import/Export without secrets |
| `portability-full.php` | Every feature configured on a template, imported into a fresh site: same totals, tax, discount and deposit; no Stripe keys, webhook secrets, bank account or notification email copied (`FLEXO_PHASE=source` / `target`, same `FLEXO_EXPORT`) |
| `stripe-mock/server.php` | Local stand-in for Stripe (`php -S localhost:12111 tests/stripe-mock/server.php`): Checkout Session API, a hosted payment page (Pay / declined card / Back), signed webhooks to the site, `/_control/expire/{session}` and `/_control/refund/{session}`. State in `STRIPE_MOCK_DIR`. Test sites point Stripe at it with the `stripe-mock.php` mu-plugin and the option `flexo_test_stripe_api`. |
| `e2e/day5.js` + `e2e/seed-day5.php` | Card deposit through the mock Stripe page and webhook, cancel + pay again, declined card then expiry, bank transfer on a phone (details, copy buttons, sticky total), layout at 360/390/414/768/1024/1280 px (centring, no horizontal scroll, 44 px tap targets, desktop unchanged), request mode, minimal package (only booking requests), admin payments (list, booking page, *Payment received*, *Confirm & ask for payment*, calendar, settings, CSV). Needs `WP`, `MOCK` and the seed run with `STRIPE_MOCK` and `STRIPE_MOCK_DIR`. |
| `test-day6.php` | Day 6: migration 7, room/rate details and meals, phone numbers, confirmation key and `.ics`, guest requests, date-picker calendar API, nearby dates and enquiries (incl. personal data export/erase), list filters, Today, booking history, roles, what managers may change, health checks (booking page, email, WP-Cron, calendar feed, Stripe keys), wizard helpers, Appearance (scoped CSS, bundled fonts, no Google Fonts, Import/Export). With `STRIPE_MOCK` set it also checks invalid/valid Stripe keys against the stand-in. |
| `e2e/day6.js` + `e2e/seed-day6.php` | Guest flow at 360–1280 px (steps, picker, URL state, Back/Forward/refresh, double submit, confirmation, add to calendar, guest booking page and cancellation request, no availability → nearby dates / other rooms / enquiry, keyboard only, screen-reader names, contrast), minimal setup, Today, list, details, history, notes, resend, health, help, roles, admin on a phone, Appearance (preview = front end, contrast warning, reset, Cyrillic fonts, no Google requests, Elementor widget priority, Import/Export). Needs `WP` and `MAIL_LOG`; the seed creates the `staff` and `manager` users and an Elementor page. |
| `test-day7.php` | Day 7: migration 8, public room pages (base address, change of base and old addresses, old slugs, hidden and demo rooms, static-page fallback, robots/sitemaps), room content (types, gallery, amenities with icons, details, search texts), `[flexo_room]` / `[flexo_room_field]` / `[flexo_room_booking]`, the "from" price (seasons, weekends, minimum stay, closed dates, rates, cache and invalidation), structured data (alone, and handed to Yoast / Rank Math through their filters), demo rooms, Import/Export of room pages with photos, the JetEngine importer, health check for address clashes. |
| `test-day7-elementor.php` | Needs Elementor: the "Flexo Booking: room" dynamic tags and their options, the room widgets (amenities, details, gallery, booking box), sample room in the editor, the starter templates (no Jet tags left, every dynamic tag known), the template installer without Pro. |
| `test-room-design.php` | Needs Elementor; the site served over HTTP (`BASE`, else the site address). Room page design (1.8.1): only Elementor pages/templates can be chosen (not plain pages or headers), each room page prints the chosen design with its own name, price, description, amenities and background photo (dynamic CSS), static parts kept, the design's page classes on the body, theme header kept, price changes show at once, the design page itself shows the room with the same address and points search engines to it, back to automatic, design in the bin ignored, not exported/imported. Also the 1.8.0 bug where a header shown on the entire site was taken for the room template, and templates still set for JetEngine "rooms". Run once with a classic theme (Hello Elementor) and once with a block theme. |
| `polylang-day7.php` | Needs Polylang (main language Bulgarian, English second; run with `BASE=<site url>`): translatable rooms and room types, booking data kept in step, translations never become extra rooms, booking through a translation lands on the main room, one inventory, texts per language on the room pages. |
| `e2e/day7.js` + `e2e/seed-day7.php` | Room page (content, gallery, lightbox, "from" price, one HotelRoom in the structured data), booking box (picker without dates, dates from the address, total = booking page total, Book now opens the details step, sold out → nearby dates and other rooms), phone bar at 360/390 (hides at the box, focus on the dates), no sideways scrolling at 360/390/768, availability tags (Elementor), hidden room (404 for guests, bookable by link, visible to staff), room editor at 1440/390 (presets, own amenity with a guessed icon, details, save), and with Elementor the "Check availability" panel (1.8.2): a room design with a typed `#check-availability` button and a Room booking link button, calendar opens on the same page (two months on a computer), price at once after the dates, guests change re-checks, Escape order and focus, Book now to the guest details, a button for another room on an ordinary page, bottom sheet from the phone bar at 390. The seed runs `seed-day6.php` first. |
| `upgrade-fixture.php` / `upgrade-verify.php` | Data created on 1.0.0 (or any later version) survives the upgrade |
| `portability-*.php` | Export from a template site and import into a fresh site (including seasons, closed dates and features) |
| `e2e/day1.js` + `e2e/seed-day1.php` | Browser flow (Playwright): guest booking with seasons, admin screens, Agency, CSV, Elementor |
| `e2e/day4.js` + `e2e/seed-day4.php` | Consent, invoice request, dataLayer events (values, no personal data, once, redirect), Meta Pixel option, admin booking page, CSV, anonymise, emails screen, phone width, features off. Needs `WP` (the wp-cli command for the site) and `MAIL_LOG` (`wp-content/mail.log`). |
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

Portability test (base): run `portability-source.php` on the template site with
`FLEXO_EXPORT=/tmp/p.json`, set the fresh site's notification address to
`owner@client.test` (it must stay the site's own), `wp flexo-booking import /tmp/p.json`,
then `portability-verify.php`; import again and run `portability-reimport.php`.

Browser tests: serve the site (`php -S localhost:8092 -t /tmp/site router.php`),
run the seed (`wp eval-file tests/e2e/seed-day1.php`, or `seed-day2.php` /
`seed-day3.php` / `seed-day4.php` / `seed-day5.php` / `seed-day6.php` / `seed-day7.php`, which print `D0`), then
`BASE=http://localhost:8092 D0=<D0> node tests/e2e/day1.js` (or `day2.js` … `day7.js`).
For Day 5, start the Stripe stand-in first and pass its address: `STRIPE_MOCK=http://localhost:12111 STRIPE_MOCK_DIR=/tmp/stripe-mock wp eval-file tests/e2e/seed-day5.php`, then `MOCK=http://localhost:12111 WP="wp --path=<site>" … node tests/e2e/day5.js`.
Re-seed before every run.
