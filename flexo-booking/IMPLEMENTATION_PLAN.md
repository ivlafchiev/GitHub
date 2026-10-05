# Flexo Booking: implementation plan (Days 1–7, 1.9.0)

Status: **Days 1–7 done (1.8.x); admin redesign done (1.7.0, §16); Day 7 in §17; 1.9.0 system pages in §18 – Session A done (§18.13), Sessions B and C waiting for approval.** Day 1 (1.1.0) is in §9, Day 2
(1.2.0) in §10, Day 3 (1.3.0) in §11, Day 4 (1.4.0) in §12 and Day 5 (1.5.0)
in §13, each with what was built, the deviations and the test results. §14
is the status after Day 5. §15 is Day 6 (1.6.0, usability and appearance,
planned in `UX_REVIEW.md`), with the regression of Days 1–5 and the updated
known limitations. Sections 1–8 are the original plan.
Read together with `ROADMAP.md` and `UX_REVIEW.md`.

Contents:

1. Current architecture (review)
2. Database schema for all 5 days
3. Unified pricing pipeline
4. Feature system
5. Day 1 build list: reuse vs add
6. Backwards-compatibility risks
7. Decisions (approved as proposed)
8. Day 1 test plan
9. Day 1 status, deviations and test results
10. Day 2 status, deviations and test results
11. Day 3 status, deviations and test results
12. Day 4 status, deviations and test results
13. Day 5 status, deviations and test results
14. Status after Day 5
15. Day 6 status, deviations and test results
16. Admin redesign (1.7.0)
17. Day 7 plan: dynamic room pages, room listings and availability buttons

---

## 1. Current architecture (1.0.0)

A single plugin with static service classes and no external dependencies.
Everything is loaded from `flexo-booking.php` on `plugins_loaded`.

| Class | Responsibility |
|---|---|
| `Flexo_Booking_Install` | Creates `{prefix}flexo_bookings` with `dbDelta`. `maybe_upgrade()` re-runs install whenever option `flexo_booking_db_version` ≠ `FLEXO_BOOKING_DB_VERSION` (currently `'1'`). |
| `Flexo_Booking_Settings` | One option `flexo_booking_settings` (array) holding defaults, sanitising, the settings page and `format_price()`. Paths are stored instead of URLs for portability. |
| `Flexo_Booking_Rooms` | CPT `flexo_room`. The *Booking details* meta box stores `_flexo_price`, `_flexo_weekend_price`, `_flexo_capacity`, `_flexo_units` and `_flexo_min_nights`. `find()` looks up a room by ID or slug; `to_array()` normalises it. |
| `Flexo_Booking_Bookings` | The core engine. `validate_dates()`, `calculate_total()` (weekday/weekend loop, filter `flexo_booking_calculate_total`), `units_available()` (night-by-night count of `pending`/`confirmed`/`blocked`), `search()`, `create()` (validate → lock → re-check → insert → `flexo_booking_created`), `update_status()`, `query()`, and the lock: an INSERT on the unique `wp_options.option_name` key with a 30 s stale timeout. |
| `Flexo_Booking_Rest` | Public routes `rooms`, `availability` and `bookings`, plus a honeypot and per-IP rate limit. |
| `Flexo_Booking_Frontend` | Shortcode and renderer shared with Elementor. Templates are overridable (`templates/booking-form.php`, `search-bar.php`). Assets are registered globally but enqueued only when rendered. |
| `assets/js/booking.js` | Vanilla JS flow: search → rooms → details → success. It uses server-formatted money strings, and re-initialises for the Elementor editor and popups. |
| `Flexo_Booking_Emails` | Guest request/confirmed/cancelled emails and a hotel alert, driven by the `created` / `status_changed` actions. |
| `Flexo_Booking_Admin` | Bookings list, status actions, manual booking/block form and CSV export (with formula-injection guard). |
| `Flexo_Booking_Portability` | JSON export/import of settings and rooms (matched by slug), optional bookings, and WP-CLI `wp flexo-booking export/import`. |
| `Flexo_Booking_Elementor` (+ widget, dynamic tag) | *FlexoHotels* category, "Flexo Booking Form" widget and "Room booking link" tag. |

**Strengths to keep.** Availability counted night by night. One renderer for
the shortcode and the widget. Slugs and paths for portability. Every price
calculated on the server. Hooks at creation and status change.

**Weak spots found in the review** (Day 1 fixes them only where they touch
Day 1 scope):

1. **Pricing lives in one method.** It returns a single number with no
   breakdown, and nothing is stored on the booking except `total`.
2. **Lock gaps.**
   - `update_status()` ("Reinstate") re-checks availability *without* taking
     the lock.
   - The options-row lock can be stolen after 30 s by a slow request.
   - On MySQL, a connection-scoped `GET_LOCK()` would be safer and releases
     itself automatically.
3. **Migrations.** `maybe_upgrade()` just re-runs `dbDelta`. It has no ordered
   data migrations and no protection against two requests upgrading at the
   same time.
4. **Settings sanitising resets missing keys to their defaults.** That's fine
   for a single form, but it would wipe other tabs as soon as the settings
   page is split into tabs.
5. **`format_price()` uses the *current* currency symbol for every booking.**
   Changing the currency would re-label historic bookings.
6. **`search()` runs one bookings query per room.** Fine for small hotels. Day 1
   preloads seasons in one query and leaves the bookings query as it is.

---

## 2. Database schema for all 5 days

Principles:

- **Occupancy is counted in one place:** `Flexo_Booking_Inventory::nightly_usage()`.
  Guest bookings, staff blocks and payment holds (Day 5) are rows in the
  bookings table. iCal imports (Day 2) live in `flexo_calendar_events` and are
  counted by the same function (see §10.2).
- **Every booking keeps a price snapshot.** It stores the itemised pricing
  result at booking time, so later price or feature changes never alter
  existing bookings.
- **Date conventions.**
  - Bookings and iCal events use `check_in` (first night) and `check_out`
    (departure day, *exclusive*).
  - Seasons and closures use `date_from` and `date_to` as **inclusive nights**
    (01.07–31.08 includes the night of 31.08).
- **Money** is stored as `decimal(10,2)`. Percentages are `decimal(7,3)`.
  Amounts are always in the booking's stored `currency`.
- **Table names** follow `{$wpdb->prefix}flexo_<name>`. All tables are dropped
  on the existing opt-in uninstall.
- **Migrations add only.** They never drop or rename columns.

### 2.1 Migration runner

- `Flexo_Booking_Migrations` holds an ordered list: `1 → 1.0.0 initial`,
  `2 → Day 1`, `3 → Day 2`, `4 → Day 3`, `5 → Day 4`, `6 → Day 5`.
- **Full schema in one place.** `Flexo_Booking_Schema::tables()` returns the
  current `CREATE TABLE` statements. `dbDelta()` adds missing tables and
  columns idempotently. Each migration step then runs its data changes
  (backfills, option upgrades).
- **When it runs.** Automatically on `plugins_loaded`, which covers plugin
  updates, multisite sub-sites and cloned sites. The check is one cheap option
  comparison. Existing `flexo_booking_db_version = '1'` is read as integer 1.
- **Safety.**
  - A migration lock stops two requests from migrating at the same time.
  - Each step is idempotent, and the stored version is bumped *after each
    successful step*.
  - If a step fails, the runner stops, shows an admin notice ("Booking
    database update failed – retry"), and retries on the next load.
- `wp flexo-booking migrate` exists for staging and CI.

### 2.2 Existing table: `flexo_bookings` (additions by day)

| Column | Day | Purpose |
|---|---|---|
| `price_breakdown longtext NULL` | 1 | JSON snapshot of the pricing result (§3). `NULL` on old bookings, which then show a single "Accommodation" line built from `total`. |
| `rate_plan_id bigint unsigned NOT NULL DEFAULT 0` | 3 | 0 = standard rate |
| `children_ages varchar(100) NOT NULL DEFAULT ''` | 3 | e.g. `"4,11"` |
| `promo_code varchar(50) NOT NULL DEFAULT ''` | 3 | |
| `discount_total decimal(10,2) NOT NULL DEFAULT 0` | 3 | For reporting and CSV |
| `tax_total decimal(10,2) NOT NULL DEFAULT 0` | 3 | Tourist tax |
| `locale varchar(20) NOT NULL DEFAULT ''` | 4 | Guest's language, used for emails |
| `anonymized_at datetime NULL` | 4 | Set by data retention |
| `token char(32) NOT NULL DEFAULT ''` + KEY | 5 | Unguessable guest link for payment return and "view booking" |
| `payment_status varchar(20) NOT NULL DEFAULT 'none'` | 5 | `none` / `pending` / `partial` / `paid` / `refunded` |
| `payment_method varchar(20) NOT NULL DEFAULT ''` | 5 | `stripe` / `bank_transfer` / `property` |
| `deposit_amount decimal(10,2) NOT NULL DEFAULT 0` | 5 | |
| `amount_paid decimal(10,2) NOT NULL DEFAULT 0` | 5 | |
| `hold_expires_at datetime NULL` | 5 | Inventory hold (§2.8) |

**`total` keeps its meaning.** It is the grand total of the stay, including
tax. Payment fields describe *when* that total is paid.

**New statuses** (Day 5): `awaiting_payment` occupies the room while
`hold_expires_at > now`, and `expired` means a hold that ran out. The
occupancy rule moves into one function, `Flexo_Booking_Inventory::occupying_sql()`,
on Day 1, so Day 5 only has to extend it.

**Sources** (`source` column, which already exists): `website`, `admin`, plus
`ical` (Day 2).

### 2.3 Day 1: seasons and closures

```sql
flexo_seasons (
  id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
  room_id bigint unsigned NOT NULL,
  name varchar(100) NOT NULL DEFAULT '',
  date_from date NOT NULL,            -- first night (inclusive)
  date_to date NOT NULL,              -- last night (inclusive)
  price decimal(10,2) NOT NULL,       -- nightly price
  weekend_price decimal(10,2) NULL,   -- NULL = use price (Fri/Sat nights)
  min_nights smallint unsigned NULL,  -- NULL = room/global minimum
  created_at datetime NOT NULL, updated_at datetime NOT NULL,
  KEY room_dates (room_id, date_from, date_to)
)

flexo_closures (
  id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
  room_id bigint unsigned NOT NULL DEFAULT 0,   -- 0 = whole property
  date_from date NOT NULL,                      -- first closed night
  date_to date NOT NULL,                        -- last closed night (inclusive)
  label varchar(190) NOT NULL DEFAULT '',       -- optional text shown to guests
  created_at datetime NOT NULL, updated_at datetime NOT NULL,
  KEY room_dates (room_id, date_from, date_to)
)
```

**Why two tables?** They follow different rules. Seasons are per room and must
not overlap. Closures can be property-wide or per room, and may overlap
anything.

**Why not reuse "blocked" bookings for closures?** A blocked booking takes
*one unit*. A closure closes *every unit*, needs a guest-facing message, and
repeats each year.

### 2.4 Day 2: calendar sync

```sql
flexo_calendars (
  id, room_id,
  name varchar(100),               -- e.g. "Booking.com – Deluxe"
  import_url text,                 -- iCal feed to import
  active tinyint(1) DEFAULT 1,
  last_synced_at datetime NULL, last_status varchar(20) DEFAULT '',  -- ok/error
  last_error text NULL,
  created_at, updated_at
)

flexo_calendar_events (
  id, calendar_id, room_id,
  uid varchar(255) NOT NULL,       -- iCal UID
  date_from date, date_to date,    -- DTSTART / DTEND (exclusive, like bookings)
  summary varchar(255),
  booking_id bigint unsigned DEFAULT 0,  -- mirror row in flexo_bookings (status 'blocked', source 'ical')
  conflict tinyint(1) DEFAULT 0,         -- overlapped a full room when imported
  status varchar(20) DEFAULT 'active',   -- active / removed
  updated_at datetime,
  UNIQUE KEY cal_uid (calendar_id, uid(191))
)
```

- **Export feed.** Room meta `_flexo_ical_token` gives
  `/wp-json/flexo-booking/v1/ical/{room}.ics?token=…`. UIDs look like
  `flexo-{reference}@{host}`. Events carrying our own UID domain are skipped on
  import to avoid echo loops.
- ~~Imports mirror into the bookings table.~~ **Changed on Day 2 (see §10.2):**
  imported bookings stay in `flexo_calendar_events` and are counted by the
  single availability function, `Flexo_Booking_Inventory::nightly_usage()`.
- **Conflicts.** When an import overlaps a room that is already full, the event
  is still stored (it's a real external booking). It gets `conflict=1`, an
  admin notice and a hotel email.

### 2.5 Day 3: rate plans, children, tourist tax, promo codes

> **As built (1.3.0), see §11.** The Day 3 brief simplified this draft:
> child prices are one rule set (free / % / adult) in settings with an
> optional per-room override in room meta, not a `flexo_child_rules` table;
> there is no extra-adult occupancy pricing; which rooms offer a plan is
> room meta `_flexo_rate_plans`; and promo usage is counted from confirmed
> bookings (`bookings.promo_id`), so there is no `flexo_promo_usage` table.
> The draft below is kept for reference.

```sql
flexo_rate_plans (
  id, name varchar(100), description text,
  board varchar(100) DEFAULT '',                -- e.g. "Breakfast included"
  adjustment_type varchar(20) DEFAULT 'percent', -- percent / per_night / per_stay
  adjustment_value decimal(10,3) DEFAULT 0,      -- +/- (negative = discount)
  refundable tinyint(1) DEFAULT 1,
  cancellation_policy text,
  min_nights smallint unsigned NULL,
  deposit_type varchar(20) NULL, deposit_value decimal(10,3) NULL, -- Day 5 override, NULL = global
  room_ids longtext NULL,                        -- JSON array; empty = all rooms
  active tinyint(1) DEFAULT 1, sort_order int DEFAULT 0,
  created_at, updated_at
)

flexo_child_rules (
  id, room_id bigint unsigned DEFAULT 0,        -- 0 = all rooms
  age_from tinyint unsigned, age_to tinyint unsigned,  -- inclusive
  price_type varchar(20),                       -- free / fixed_per_night / percent_of_adult
  price_value decimal(10,3) DEFAULT 0,
  sort_order int DEFAULT 0
)

flexo_promo_codes (
  id, code varchar(50) NOT NULL,                -- stored uppercase, UNIQUE
  description varchar(190),
  discount_type varchar(20),                    -- percent / fixed
  discount_value decimal(10,3),
  stay_from date NULL, stay_to date NULL,       -- nights the code applies to
  book_from date NULL, book_to date NULL,       -- when the booking is made
  min_nights smallint unsigned NULL,
  room_ids longtext NULL,                       -- JSON; empty = all
  max_uses int unsigned NULL, max_uses_per_email int unsigned NULL,
  active tinyint(1) DEFAULT 1, created_at, updated_at,
  UNIQUE KEY code (code)
)

flexo_promo_usage (
  id, promo_id, booking_id, email varchar(190),
  discount_amount decimal(10,2), created_at,
  KEY promo (promo_id), KEY booking (booking_id)
)
```

- **Occupancy pricing** uses new room meta: `_flexo_base_guests` (guests
  included in the nightly price, default = capacity, so no change for existing
  rooms) and `_flexo_extra_adult_price`.
- **Tourist tax** is settings only, no table:
  - amount per person per night
  - an exempt-under-age threshold
  - "collected with booking" or "paid at property"
- **Promo usage limits** are checked and recorded inside the booking lock.

### 2.6 Day 4: consent, invoices, email log

> **As built (1.4.0), see §12.** Differences from this draft: the consent
> table has no email or IP hash (it links to the booking only, so it holds no
> personal data); the invoice table is `flexo_invoices` with separate person
> and company fields; bookings also get `emails_sent` (which scheduled emails
> went out). Invoice data is removed with the booking's personal data (the
> issued invoice lives in the hotel's accounting).

```sql
flexo_consents (
  id, booking_id bigint unsigned DEFAULT 0,
  email varchar(190),
  consent_type varchar(30),        -- privacy / terms / marketing
  granted tinyint(1),
  text_version char(40),           -- sha1 of the consent text shown
  consent_text text,               -- snapshot of the wording
  ip_hash char(64),                -- salted hash, never the raw IP
  created_at,
  KEY booking (booking_id), KEY email (email)
)

flexo_booking_invoices (
  id, booking_id bigint unsigned NOT NULL UNIQUE,
  company_name varchar(190), company_id varchar(50),   -- EIK / BULSTAT
  vat_number varchar(50), responsible_person varchar(190), -- МОЛ
  address varchar(255), city varchar(100), postcode varchar(20),
  country char(2), email varchar(190),
  created_at, updated_at
)

flexo_email_log (
  id, booking_id bigint unsigned DEFAULT 0,
  email_type varchar(40), recipient varchar(190), subject varchar(255),
  status varchar(10),              -- sent / failed
  error text NULL, created_at,
  KEY booking (booking_id), KEY created (created_at)
)
```

- **Data retention** runs daily via WP-Cron. It anonymises guest personal
  fields N days after check-out and sets `anonymized_at`. Financial fields are
  kept.
- **Invoice rows have their own retention**, because Bulgarian accounting law
  requires years of retention.
- **Translation and tracking** need no tables.

### 2.7 Day 5: payments

*The original plan is below. What was built differs: a history table plus
summary columns on the booking. See §13.1 and §13.2.*

```sql
flexo_payments (
  id, booking_id bigint unsigned NOT NULL,
  method varchar(20),              -- stripe / bank_transfer / property
  kind varchar(20),                -- deposit / full / balance / refund
  amount decimal(10,2), currency varchar(10),
  status varchar(20),              -- pending / requires_action / paid / failed / refunded / cancelled / expired
  provider_ref varchar(255) DEFAULT '',  -- Stripe Checkout Session / PaymentIntent id, transfer reference
  provider_data longtext NULL,     -- JSON (webhook payload excerpt)
  due_at datetime NULL,            -- bank-transfer deadline
  paid_at datetime NULL, created_at, updated_at,
  KEY booking (booking_id), KEY provider_ref (provider_ref(191))
)
```

### 2.8 Inventory holds (Day 5): design decision

A hold is a **booking row** with status `awaiting_payment` and
`hold_expires_at` (built as status **`pending_payment`**; `awaiting_payment`
is used for bank transfers, see §13.2). There is no separate holds table. The reasons:

- The held booking needs all the guest data anyway, for Stripe metadata and
  the webhook.
- Availability stays a single query.
- A cron job and a lazy check expire stale holds.

Stripe Checkout sessions must stay open for at least 30 minutes, so the hold
length is at least as long as the session (`expires_at` = hold end).

---

## 3. Unified pricing pipeline

A new class, **`Flexo_Booking_Pricing`**, is the only place prices are
calculated. Search, booking creation, staff bookings, REST, emails, the admin
list and the CSV all use it. Admin, emails and CSV read the **stored snapshot**
it produced, so a booking's price never changes after the fact.

### 3.1 Request and result

```php
Flexo_Booking_Pricing::quote( array(
  'room'          => 12,            // ID or slug
  'check_in'      => '2026-07-06',
  'check_out'     => '2026-07-11',
  'adults'        => 2,
  'children'      => 1,
  'children_ages' => array( 6 ),    // Day 3
  'rate_plan_id'  => 0,             // Day 3
  'promo_code'    => '',            // Day 3
  'context'       => 'search',      // search | booking | admin
) );
```

It returns an array (JSON-safe, stored as the snapshot):

```php
array(
  'version'   => 1,
  'currency'  => 'EUR',
  'room_id'   => 12, 'check_in' => '2026-07-06', 'check_out' => '2026-07-11', 'nights' => 5,
  'nights_detail' => array(          // one entry per night
    array( 'date' => '2026-07-06', 'amount' => 100.00, 'season_id' => 2, 'season' => 'Low season', 'weekend' => false ),
    ...
  ),
  'lines' => array(                  // itemised, in display order, signed amounts
    array( 'key' => 'accommodation', 'type' => 'accommodation', 'label' => 'Accommodation, 5 nights',
           'amount' => 700.00, 'collect' => 'booking',
           'groups' => array(        // readable summary of nights_detail (Mon 06.07–Sat 11.07: low season ends 07.07, high starts 08.07; Fri 10.07 is a weekend night)
             array( 'label' => 'Low season', 'nights' => 2, 'unit' => 100.00, 'amount' => 200.00 ),
             array( 'label' => 'High season', 'nights' => 2, 'unit' => 150.00, 'amount' => 300.00 ),
             array( 'label' => 'High season – weekend', 'nights' => 1, 'unit' => 200.00, 'amount' => 200.00 ) ) ),
    // Day 3: rate_plan, extra_guest, child, discount (negative), tax
  ),
  'subtotal'       => 700.00,        // lines before discount and tax
  'discount_total' => 0.00,
  'tax_total'      => 0.00,
  'total'          => 700.00,        // grand total = sum of all lines
  'payable'        => array( 'now' => 0.00, 'deposit' => 0.00, 'at_property' => 700.00 ), // Day 5 fills this in
  'min_nights'     => 3,             // arrival season → room → global
  'messages'       => array(),       // guest-facing notes/warnings
)
```

`Flexo_Booking_Pricing::format_lines( $quote_or_snapshot, $currency )` turns
this into display rows for the front end, emails (`{price_breakdown}`
placeholder), the admin and the CSV.

### 3.2 Steps (ordered, each can add lines or change nightly amounts)

| Priority | Step | Feature gate | Day |
|---|---|---|---|
| 10 | **Nightly room price.** Each night uses its season's price if one applies, otherwise the room price. Fri/Sat nights use the weekend price if one is set. The result is the `accommodation` line. The legacy filter `flexo_booking_calculate_total` is applied here to the accommodation amount; if it changes it, an "adjustment" line is added. | seasons: `seasonal_pricing` | 1 |
| 20 | **Rate-plan adjustment**: per night / per booking / per guest per night (children by age) / % of the room price | `rate_plans` | 3 |
| ~~30~~ | ~~Occupancy and children~~ – not built: the Day 3 brief applies child prices only to per-person amounts (steps 20 and 50), not to the room price | – | – |
| 40 | **Promo discount.** Applies to room + rate plan, never to tax; capped so the total never goes below zero. | `promo_codes` | 3 |
| 50 | **Tourist tax.** Per person per night; children like adults, exempt under an age, or by the child rules; `collect` = booking or property | `tourist_tax` | 3 |
| 90 | **Totals.** Round each line, then sum, so the breakdown always adds up exactly. Lines with `collect = property` are shown but kept out of `total` (in `due_at_property`). | core | 1 |
| 95 | **Payment schedule**: deposit, pay now, pay at property | `online_payment` / `deposit` / `bank_transfer` | 5 |

Steps are registered through `flexo_booking_pricing_steps` (priority →
callable). A step is registered only when its feature is **enabled**, so a
disabled feature never affects prices.

**Minimum stay** (not a price, but season-dependent) comes from
`Flexo_Booking_Seasons::min_nights( $room, $check_in )`: the season of the
arrival night, then the room's minimum, then the global minimum. The pipeline
copies it into `min_nights` so the front end can explain it.

---

## 4. Feature system

### 4.1 Registry

`Flexo_Booking_Features::definitions()` lists each feature with:

- `label` and `description` (one line, hotel language)
- `group`
- `requires` (other features it depends on)
- `default_enabled`
- `ready` (whether its functionality exists yet)

All 15 features are registered on Day 1:

| Key | Label (admin) | One-line explanation | Built on |
|---|---|---|---|
| `booking_request` | Booking requests | Guests send a request and you confirm it. | 1.0 |
| `instant_booking` | Instant booking | Bookings are confirmed immediately, without your approval. | 1.0 |
| `seasonal_pricing` | Seasonal prices | Different prices and minimum stays for high and low season. | Day 1 |
| `calendar_sync` | Calendar sync | Keep availability in sync with Booking.com, Airbnb and others (iCal). | Day 2 |
| `rate_plans` | Rate plans | Offer options such as "Breakfast included" or "Non-refundable". | Day 3 (built) |
| `children` | Children & ages | Ask for children's ages and charge by age. | Day 3 (built) |
| `tourist_tax` | Tourist tax | Add the municipal tourist tax per person per night. | Day 3 (built) |
| `promo_codes` | Promo codes | Give discounts with codes such as SUMMER10. | Day 3 (built) |
| `privacy_consent` | Privacy consent | Ask guests to accept your privacy policy and remove old guest data automatically. | Day 4 (built) |
| `invoice_request` | Invoice request | Let guests ask for an invoice with company details. | Day 4 (built) |
| `guest_emails` | Guest emails | Email guests when their booking is received, confirmed or cancelled. | 1.0 |
| `tracking` | Conversion tracking | Send booking events to Google Analytics / Tag Manager / Meta. | Day 4 (built) |
| `online_payment` | Online card payment | Guests pay by card when booking (Stripe). | Day 5 |
| `deposit` | Deposits | Take part of the price when booking, the rest at the property. Requires card payment or bank transfer. | Day 5 |
| `bank_transfer` | Bank transfer | Guests pay a deposit or the full amount by bank transfer. | Day 5 |

The admin calendar (Day 2) and closed periods are **core**, not features (see
§7).

### 4.2 Two levels

**Available** is decided by FlexoHotels. The rules are evaluated in order:

1. **Constant in `wp-config.php`** (wins if defined):
   ```php
   define( 'FLEXO_BOOKING_FEATURES', 'booking_request,guest_emails,seasonal_pricing' ); // or an array, or 'all'
   ```
2. **Otherwise, the agency option** `flexo_booking_available_features`, set on
   a hidden **Agency** screen (see below).
3. **Otherwise, all features.**
4. The result then passes through the filter
   **`flexo_booking_available_features`**. This is where a future
   licence/package module plugs in: it adjusts the set without any rewrite.

**The Agency screen** (Bookings → Agency) is registered only for users listed
in:

```php
define( 'FLEXO_BOOKING_AGENCY_USERS', 'flexoadmin,support@flexohotels.com' ); // logins or emails
```

Everyone else, including the hotel's Administrators, never sees it. If the
`FLEXO_BOOKING_FEATURES` constant is set, the screen is read-only and says so.
There is also `wp flexo-booking features available <list>` for fleet setup.

This is a product boundary, not a security boundary: anyone who can edit
`wp-config.php` can change it.

**Enabled** is chosen by the hotel admin under **Settings → Features** (a new
tab), among the available features. It is stored in the option
`flexo_booking_enabled_features`. `Flexo_Booking_Features::enabled( $key )`
returns `available && enabled`.

- **Booking requests / Instant booking** show as one plain choice, "How do
  guests book?", offering only the available options. The existing
  `booking_mode` setting keeps storing the choice. If only one is available,
  it is used automatically. If neither is, the plugin falls back to requests,
  because the core flow can never disappear.
- **Features that aren't built yet** appear on the Features screen as "Coming
  soon" (greyed out) only if they're available. The agency can hide them
  entirely by leaving them unavailable.

### 4.3 What gating means in practice

| Feature state | Hotel admin | Guest frontend | Data |
|---|---|---|---|
| Not available | Hidden everywhere: Features tab, menus, room screens, settings sections, import/export options | Hidden | Kept untouched |
| Available but disabled | Only a toggle on the Features screen | Hidden; no pricing step, no fields | Kept untouched. Re-enabling restores everything. |
| Enabled | Full UI | Active | – |

**Existing bookings are never recalculated.** Their stored `total` and
`price_breakdown` stay as they are, whatever the feature switches do.

**Import/Export** always carries feature data (e.g. seasons), so data is never
lost. The enabled list is included in the export; on import it is intersected
with the target site's *available* list.

---

## 5. Day 1 build list: reuse vs add

### 5.1 Reuse (small, targeted edits only)

- **Bookings table, Rooms CPT and meta, REST routes, templates, JS flow,
  Elementor widget and tag, emails, admin list, CSV, portability.**
- `Flexo_Booking_Bookings`:
  - `search()` and `create()` call the new services.
  - `calculate_total()` and `units_available()` stay as public wrappers with
    identical behaviour.
  - `update_status()` takes the lock.
- `Flexo_Booking_Settings`:
  - new currency-format keys
  - a Features tab
  - sanitising merges with *saved* values
  - `format_price()` delegates to the new money formatter

### 5.2 Add

| File | Purpose |
|---|---|
| `includes/class-schema.php` | All `CREATE TABLE` definitions |
| `includes/class-migrations.php` | Versioned runner. Replaces the body of `Flexo_Booking_Install::install()` / `maybe_upgrade()`; the class stays as a thin wrapper. |
| `includes/class-features.php` | Registry, available/enabled resolution, the Features tab, the Agency screen, WP-CLI |
| `includes/class-money.php` | Currency code, symbol, position, decimals, separators. `format( $amount, $currency = null )` falls back to the currency code when a booking's currency differs from the current one. |
| `includes/class-inventory.php` | `units_available()` (moved), `closed_nights()`, `with_lock( $room_id, callable )`, `occupying_sql()` |
| `includes/class-pricing.php` | The pipeline (§3) and `format_lines()` |
| `includes/class-seasons.php` | Seasons repository: validation, overlap check, `for_room_range()` (preloaded), `min_nights()`, `copy_to_year()` |
| `includes/class-closures.php` | Closures repository, validation, `copy_to_year()`, guest message |
| `includes/admin/class-seasons-admin.php` | **Bookings → Seasonal prices**: pick a room, then a table of seasons and an add/edit row. Only shown when enabled. |
| `includes/admin/class-closures-admin.php` | **Bookings → Closed dates**: property-wide and per-room periods (core) |
| `assets/js/admin-dates.js` | jQuery UI datepicker (bundled with WordPress) in `dd.mm.yy` format. Loaded only on those two screens. |
| `tests/` (repo root, not in the zip) | WP-CLI-based regression and feature tests (`tests/run.sh`), so later days can re-run everything |

### 5.3 Day 1 behaviour details

**Currency**

- Default is EUR (it already is).
- New settings: decimals (default 2), decimal separator and thousands
  separator ("Automatic from site language" by default, which is exactly
  today's output), and symbol position with four options. The two existing
  options keep their exact output.
- The settings page notes: *"Changing currency does not convert your prices."*
- New bookings store the currency code, as they already do.

**Locking** (`Flexo_Booking_Inventory::with_lock`)

- On MySQL/MariaDB it uses `GET_LOCK()`. The lock name is hashed with the
  database name and table prefix, so multisite sites don't collide. It's
  connection-scoped, so it can't go stale.
- On other databases (e.g. SQLite) it falls back to the existing
  options-row lock.
- Availability is re-checked **inside** the lock, and the price is
  re-calculated inside it too.
- It is used by booking creation, staff bookings and blocks, and "Reinstate".
  Day 2 imports and Day 5 holds will use the same function.

**Seasons**

- Per-room table columns: name, from (DD.MM.YYYY), to (DD.MM.YYYY,
  inclusive), nightly price, optional weekend price, optional minimum stay,
  and Edit/Delete.
- **Validation:** from ≤ to, price ≥ 0, no overlap with another season of the
  same room. The error names the conflicting season and its dates.
- **Copy to next year:**
  - Per room, or for all rooms in one click.
  - Shifts both dates by one year; 29.02 becomes 28.02; seasons spanning New
    Year are handled.
  - Copies that would overlap an existing season are skipped and listed in the
    result message.
- Room edit screen: a read-only summary of that room's seasons, with an "Edit
  seasonal prices" link.

**Closed periods**

- Property-wide or per room, with an optional guest-facing label.
- If any night of a stay falls in a closure, that room is unavailable with a
  clear reason, e.g. *"Closed for the season from 01.11.2026 to 31.03.2027"*.
- If the whole property is closed, the search also shows a top notice.
- Departure on the first closed day is allowed, because only nights count.
- "Copy to next year" is available here too.

**Front end**

- Room cards show the stay total. The per-night figure is labelled "average
  per night" when nightly prices vary.
- No template changes are needed, so theme overrides keep working. New text is
  rendered by JS into the existing containers.

**Import/Export**

- The export file gets `"schema": 2`, with seasons nested per room and
  closures at top level (room slug, or `""` for the whole property).
- **Import:** a room's seasons in the file *replace* that room's seasons on
  the target site. Rooms not in the file are untouched. Closures are added if
  they are not already identical. Version 1 files still import.

---

## 6. Backwards-compatibility risks and how they're handled

| Risk | Handling |
|---|---|
| DB version stored as string `'1'` | The runner casts it to int. Migration 1 is a no-op on existing sites; migration 2 adds the new tables and column. Tested by upgrading a populated 1.0.0 database. |
| Prices change after refactor | **Golden test.** For several rooms, old `calculate_total()` (kept in the test as a reference copy) is compared with the new pipeline over about 500 random stays, with no seasons. They must be identical. The legacy filter `flexo_booking_calculate_total` is still applied. |
| Historic bookings | Never recalculated. `price_breakdown = NULL` shows one "Accommodation" line from `total`. |
| Currency changed later | Prices are never converted. Each booking shows in its own stored currency, using the code (e.g. `200.00 BGN`) when it differs from the current symbol. |
| Settings tabs wiping other tabs | Sanitising merges with the saved option, not defaults. Tested by saving each tab and checking the others survive. |
| `booking_mode` vs features | The existing mode keeps working. The migration enables `booking_request` and `instant_booking` (whichever mode is in use stays selected) and `guest_emails`. Emails keep being sent. |
| REST response shape | Additive only. Existing keys (`price`, `price_formatted`, `total`, `total_formatted`, `nights_label`, `reason` …) stay. New: `breakdown`, `min_nights`, `notice`, `price_average_formatted`. |
| Theme template overrides | No required template changes on Day 1. |
| Export files | v1 files import into the new version. v2 files imported into 1.0.0 simply ignore the new keys. |
| Public static methods used by others | `Bookings::calculate_total()`, `units_available()` and `Settings::format_price()` stay, delegating to the new classes. |
| Migrations under traffic or failures | Migration lock, per-step version bump, admin notice, retry. No destructive steps. |
| Disabling seasonal pricing | Future quotes fall back to room prices. Seasons data and existing bookings are untouched. |
| Performance | Seasons and closures are loaded once per search for all rooms (2 queries). Assets are only loaded on screens that need them. |

---

## 7. Decisions (approved as proposed)

1. **Closed periods are core, not part of "Seasonal prices".** Otherwise,
   switching seasonal prices off would silently **re-open** dates the hotel
   closed. They'll get their own small screen, "Closed dates". *(Deviation from
   the brief, recommended.)*
2. **Agency access** via `FLEXO_BOOKING_AGENCY_USERS` (and/or the
   `FLEXO_BOOKING_FEATURES` constant) in `wp-config.php`.
3. **Seasonal prices is off by default,** on both upgraded and new sites. The
   hotel turns it on under Features. Templates can ship with it on via the
   import file.
4. **Season dates are inclusive nights.** "01.07.2026 – 31.08.2026" includes
   the night of 31 August (check-out 01.09). Labels: *From* / *To (last night)*.
5. **Weekend = Friday and Saturday nights,** unchanged from 1.0. It can become
   configurable later.
6. **Admin dates** use WordPress's bundled jQuery UI datepicker in DD.MM.YYYY,
   so the format is guaranteed regardless of browser language. The guest form
   keeps the native mobile date picker.
7. **Day 2 and Day 5 table choices:**
   - iCal imports are mirrored as blocked booking rows (§2.4).
   - Payment holds are booking rows with `awaiting_payment` +
     `hold_expires_at`, not a separate holds table (§2.8).
8. **Version numbers:** 1.1.0 after Day 1, then one minor version per day
   (1.5.0 after Day 5).

---

## 8. Day 1 test plan

The tests are scripts in `tests/`, run with WP-CLI against a throwaway
WordPress install. They use SQLite locally, and MySQL-specific `GET_LOCK` is
covered where available. Browser checks use Playwright.

- **Upgrade.** Install 1.0.0, create rooms, bookings, settings and an export.
  Swap in the new code, load a page, then check:
  - migrations ran (version 2)
  - all rows and settings are intact
  - the new tables exist
- **Features.**
  - The constant limits the available list.
  - Unavailable features are absent from the Features tab, menus, room screen
    and import/export.
  - Disabled features are absent from the front end and REST.
  - Existing bookings are unchanged.
  - The Agency screen is visible only to listed users.
- **Pricing parity** (golden test, §6).
- **Seasons:**
  - a stay inside one season
  - a stay crossing two seasons (2 low + 3 high)
  - a stay outside any season (falls back to the room price)
  - weekend price inside a season
  - season minimum stay taken from the arrival date
  - an overlapping season is rejected with a message
  - copy to next year, including 29.02 and seasons spanning New Year
  - a closed period blocks booking and shows the message
- **Concurrency.** Two simultaneous REST bookings for the last unit, with an
  injected delay inside the lock: exactly one 201 and one 409.
- **Regression:**
  - normal booking, booking request, instant booking
  - manual booking, blocked dates
  - overbooking prevention, CSV export
  - Elementor widget and Elementor booking link
  - shortcode, global style inheritance
  - Import/Export to a fresh site (including seasons and closures)

---

## 9. Day 1 status, deviations and test results

### 9.1 Built

| Area | Files |
|---|---|
| Versioned migrations | `class-schema.php`, `class-migrations.php` (LATEST = 2), `class-install.php` (thin wrapper). `wp flexo-booking migrate`. |
| Feature system | `class-features.php`: registry of 15 features, available/enabled, Features tab, Agency screen, `wp flexo-booking features …` |
| Currency | `class-money.php`, plus new settings `currency_decimals` and `number_format` (4 symbol positions) |
| Pricing service | `class-pricing.php`: steps 10 (nightly) and 90 (totals); snapshot stored in `flexo_bookings.price_breakdown` |
| Concurrency | `class-lock.php` (GET_LOCK / options-row fallback), `class-inventory.php` (`with_lock`, `units_available`, closures) |
| Seasonal prices | `class-seasons.php`, `admin/class-seasons-admin.php`, `assets/js/admin-dates.js` |
| Closed dates | `class-closures.php`, `admin/class-closures-admin.php` (core) |
| Wiring | `class-bookings.php` (search/create/status use the services), REST, front end (average per night, breakdown, closed notice), emails (`{price_breakdown}`, itemised `{booking_details}`), admin list and CSV (*Price details*), import/export (schema 2), uninstall |
| Tests | `tests/` in the repository root (not in the zip) |

### 9.2 Deviations from the plan, and behaviour changes to know about

1. **Minimum stay in search is now explained per room.**
   - Before: a search shorter than the *global* minimum returned one error for the whole search.
   - Now: seasons and rooms can set their own (even lower) minimum, so each room card shows its reason, e.g. "Minimum stay 3 nights for arrivals in High season".
   - The global minimum still applies to rooms without their own.
2. **Closed dates block website bookings only.** Staff can still record a booking inside a closed period with *Add booking*, consistent with other staff bookings that skip website rules.
3. **Weekends inside a season.** When a season has no weekend price, Friday and Saturday nights use the *season's* price, not the room's weekend price. The season fully defines prices for its nights.
4. **Admin date picker has no min-date restriction.** Testing showed that jQuery UI's `minDate` rewrote a typed "To" date, producing values like `26.10.202602.11.2026`. The "To" calendar now just opens at the "From" month; the server validates the date order.
5. **The booking-mode choice moved to Settings → Features**, as "How do guests book?". Settings is now split into tabs: General / Features / Emails.
6. **`guest_emails` is wired now**, since guest emails already existed in 1.0. When it's off, only the hotel alert is sent, and the *Send confirmation email* option disappears from *Add booking*.
7. **Emails for rooms with a weekend price** now list the nights under the total, e.g. *Standard rate – weekend: 2 nights × 150.00 €*. The total is unchanged.
8. **Room cards show "avg. X per night"** when nightly prices differ within the stay; before, they showed the room's base price. The REST field `price_formatted` is unchanged; `price_average_formatted` and `price_varies` were added.
9. **REST returns 409** (instead of 400) for `flexo_closed` and `flexo_busy`, the same as `flexo_unavailable`.
10. **Seasonal prices and closed dates are managed by booking managers** (Editors and Administrators, `flexo_booking_manage_capability`). Settings, Features and Import/Export stay Administrator-only.
11. **Features that aren't built yet** can be made available but not switched on. They show as "Coming soon".

### 9.3 Tests run

Run on **MariaDB 10.11**, which uses `GET_LOCK`, and on **SQLite**, which uses the fallback lock. WordPress 7.x with PHP 8.4. The code was also checked for PHP 7.4-compatible syntax.

| Test | Result |
|---|---|
| Upgrade 1.0.0 → 1.1.0 with rooms, bookings (incl. blocked) and custom settings: all rows, fields, settings and room meta unchanged; new tables/column present; old bookings still block | 17/17 on both databases |
| Pricing parity with a verbatim copy of the 1.0.0 code: 520 random stays × 2 modes (seasonal off / on without seasons), legacy filter, breakdown sums | 8/8 (1,040 stays identical) |
| Seasons: inside, crossing (2 low + 3 high), outside, weekend inside season, inclusive end, arrival-season minimum, room minimum fallback, overlap rejected (message), self-edit, adjacent, other room, invalid input, copy to next year (29.02, New Year, re-copy skipped), closures (property/room, departure on first closed day, staff override, copy), feature off keeps data and existing prices | 46/46 |
| Features: defaults, not-ready features locked, agency list, hotel choice kept, booking-mode fallback, unavailable hidden from Features tab / Import screen / guest form, licence filter, guest emails off, settings tabs keep each other's values; constant + agency users | 28/28 + 6/6 |
| Regression: validation, search, capacity, per-night inventory, overbooking, cancel/reinstate, blocked dates, manual booking, instant booking, emails, admin query, CSV text, currency formats, no conversion on currency change | 43/43 |
| Concurrency: two processes book the last unit with a 2 s pause inside the lock → exactly one booking, the other `flexo_unavailable` after waiting | Pass on both databases |
| Portability: template with rooms, seasons, closures, features → fresh site via `wp flexo-booking import`; re-import doesn't duplicate; 1.0.0 export file still imports | 11/11 + 3/3 |
| Browser (Playwright, MariaDB site with Elementor): guest booking across seasons with itemised summary, season minimum, closed notice, search bar, mobile; admin menu, Features toggle, Seasonal prices CRUD (overlap error keeps input, edit, copy, delete), Closed dates, CSV, Agency screen hides features from the hotel admin, Elementor widget/colour/global-colour inheritance/booking-link tag | 37/37 |

**Not covered here:** the Elementor *editor* UI, since the source checkout has no compiled editor scripts (the widget, controls, CSS generation and dynamic tag were tested server-side and on the front end); real email delivery (emails were captured); and PHP 7.4 at runtime (syntax checked only).

---

## 10. Day 2 status, deviations and test results

### 10.1 Built (1.2.0, migration 3)

| Area | Files |
|---|---|
| Schema | `flexo_calendars` (room, name, URL, `unit`, status, `fail_count`, `event_count`) and `flexo_calendar_events` (UID per calendar, dates with exclusive end, conflict flags). Migration 3. |
| Sync engine | `class-ical.php`, in five parts: **parser** (unfolding, VALARM, TZID/UTC/floating times, DURATION, missing DTEND, CANCELLED); **fetch** (`wp_safe_remote_get`, 15 s timeout, 5 MB limit, plain-language errors); **sync** (UID upsert, removal releases dates, a failed download keeps existing data, past bookings dropped, our own UIDs skipped); **conflicts** (flag, one email, review); **export** (tokens, no guest data, RFC 5545 folding/CRLF); **WP-Cron** (15/30/60 min). |
| Availability | `Flexo_Booking_Inventory::nightly_usage()` counts bookings plus imported bookings using the unit rule. `units_available()` uses it. |
| REST | `GET /ical/{room}.ics?token=` served as `text/calendar` |
| Admin | `admin/class-sync-admin.php` (Calendar Sync screen, notices for failures and conflicts), `admin/class-calendar-admin.php` (month timeline), `assets/js/admin-calendar.js`, `admin-sync.js`, `assets/css/admin-calendar.css` |
| Bookings list | Source badges (Website / Added by staff / Blocked dates), ⚠ conflict box and badges, "From external calendars" view, *Add booking* pre-filled from the calendar |
| Import/Export | Schema 3: `calendars` per room (name, URL, unit). Imported only with the option / `--calendars`; tokens never exported. |
| Feature | `calendar_sync` is ready. When off, syncing stops, imported bookings don't block, the screen is hidden and the export returns 404. Data is kept. |

### 10.2 Deviations and decisions

1. **Imported bookings are not copied into the bookings table.** The plan said to copy them in as blocked booking rows. Two things made a separate table better:
   - *The requested unit rule.* Bookings from calendars linked to the same unit must be merged (e.g. Airbnb repeating a Booking.com stay for apartment 2), not added up. That can't be expressed with plain booking rows.
   - *No leakage.* Copied rows would have leaked into guest emails, the CSV, "include bookings" exports, the pending counter and booking hooks.

   Availability still has one source of truth: `nightly_usage()` counts both tables. **Please confirm this is OK.**
2. **Unit rule:** each imported booking takes one unit; calendars linked to "Room no. N" take unit N once per night. Flexo bookings are never assigned to units, since there's no room assignment.
3. **Feature off → imported bookings no longer block** and the export link returns 404. The Calendar Sync screen is invisible then, so hidden, stale blocks would be confusing and would silently lose sales. Connections and data are kept, and switching back on restores blocking immediately. The Features tab says this next to the switch.
4. **Airbnb echo.** Airbnb's export repeats dates it imported from us. This can't be told apart from a genuine double booking, so it is **still flagged**. When the dates match a website booking exactly, the note says it may be an echo, and one click marks it reviewed. Nothing is silently ignored.
5. **The export feed also contains bookings imported from the room's *other* calendars.** This lets Booking.com learn about Airbnb bookings and vice versa (hub model). Events carrying this site's own UIDs are skipped on import, to avoid loops.
6. **Calendar connections are not imported by default**, because a template's OTA links would attach another property's bookings to the client's site. Tokens are never exported.
7. **Fetching refuses local/private addresses** (`wp_safe_remote_get`), as SSRF protection. Tests allow localhost through a test-only mu-plugin.
8. **The Calendar screen is core**; only Calendar Sync is behind the feature. It's placed right after *All bookings* in the menu.
9. **Weekend shading** in the calendar is Saturday/Sunday. Pricing weekends are still Friday/Saturday nights.

### 10.3 Tests run (MariaDB 10.11 and SQLite)

| Test | Result |
|---|---|
| `test-ical.php` (engine): parser (generic, Booking.com, Airbnb fixtures); import with blocked nights and a free check-out day; guest search/booking refused; re-sync (no duplicates, changed dates updated, removed released); broken feeds (timeout, 404, HTML page) with others still syncing and 3-failure notice; failed download keeps data; empty feed releases; own UIDs skipped; multi-unit (1 per event; same unit merged; full ≠ conflict); conflict flag, note, single email, review, auto-clear on cancel, echo note; export (CRLF, ≤75 octets, no guest data, all booking types, closures, other calendars, cancelled excluded); tokens (wrong/empty/regenerated); cron (30 min default, 15 after change, cron run syncs); feature off/on | 66/66 on both databases |
| HTTP: export endpoint (200 `text/calendar`, 403 wrong/missing token, plain-permalink URL); real feed fetched over HTTP; WP-Cron event listed at 30 min and run through `wp-cron.php` (synced, rescheduled about 30 min out) | Pass |
| `portability-calendars.php`: schema 3, no tokens in file, not imported by default, imported with option (unit kept), no duplicates, new token on target | 12/12 |
| Upgrade 1.0.0 → 1.2.0 (all 5 tables) and 1.1.0 → 1.2.0 (live test site) | 19/19, pass |
| Browser `e2e/day2.js`: menu order; list badges, conflict box/badge, external view; calendar (all types, 0/3 full, 2/3 with unit-linked event, closed shading, legend, cancelled toggle, month navigation); dialog details and working actions (Confirm, Remove block, Mark as reviewed); empty day → pre-filled Add booking / Block dates; phone width (page doesn't scroll, dialog fits); Calendar Sync (status OK, readable 404, unit select only for multi-unit rooms, delay note, Copy link, Reset link → old 403 / new 200, add syncs immediately, Sync now without duplicates, Remove); failure and conflict notices on the dashboard; feature off (menu, calendar, 404) and back on | 51/51 |
| Regression: pricing parity, seasons, features (+constants), regression, concurrency (CLI) and `e2e/day1.js` (guest flow, seasons, admin, Agency, CSV, Elementor widget/tag/colours) | All pass |

Bugs found and fixed during testing:
- **Dialog action links:** they were HTML-escaped (`&amp;`) when passed as JSON, which would break Confirm/Cancel/Remove/Review from the calendar.
- **Dialog width on phones:** the dialog was 8 px wider than a 390 px screen.

---

## 11. Day 3 status, deviations and test results

### 11.1 Built (1.3.0, migration 4)

| Area | Files |
|---|---|
| Schema | `flexo_rate_plans`, `flexo_promo_codes`; bookings get `children_ages`, `rate_plan_id`, `promo_id` (+ key), `promo_code`, `discount_total`, `tax_total`. Migration 4 checks them and flags the six ready-made plans to be added (switched off) on `init`, once translations are loaded. |
| Children | `class-children.php`: global rules (settings `child_free_under` 3 / `child_percent` 50 / `child_adult_from` 12), per-room override (meta `_flexo_child_rules`), `factor()`, age parsing and validation (0–17, one per child). Room meta `_flexo_max_adults`. |
| Rate plans | `class-rate-plans.php` (repository, presets, room assignments in meta `_flexo_rate_plans` with optional per-room amount, `adjustment()`), `admin/class-rate-plans-admin.php` (**Bookings → Rate plans**) |
| Promo codes | `class-promo-codes.php` (repository, `validate()` with guest messages, usage = confirmed bookings, attempt limiter), `admin/class-promo-codes-admin.php` (**Bookings → Promo codes**) |
| Pricing | Steps 20 (rate plan), 40 (promo), 50 (tourist tax); `per_person()` helper; totals exclude property-collected lines; `public_view()` for the form; rate plan and promo are part of the stored snapshot |
| Booking engine | `search()` takes ages, checks max adults, quotes every plan (room price = cheapest, `price_from`); `create()` takes ages, plan, code, `expected_total`, re-validates the code inside the room lock, and takes a second lock per limited code for confirmed bookings |
| REST | `children_ages` on availability/bookings; new `GET /quote`; `expected_total` → 409 `flexo_price_changed`; promo attempts limited per IP |
| Front end | Age selectors (full form and search bar, `children_ages[]`), plan chooser in the room card (skipped for one plan), itemised summary with subtotal/discount/final total, "Have a promo code?" field, cancellation text, price-changed recovery |
| Admin | Settings tabs *Children* and *Tourist tax*; room screen (max adults, child prices, plans summary); bookings list (ages, plan, % code badge, price lines); new booking details view (`?page=flexo-booking&booking=ID`); Add booking with ages, plan and code; calendar dialog lines; CSV columns appended at the end; warning when confirming a request takes a code over its limit |
| Emails | `{booking_details}` lists ages, plan, code, all price lines and the cancellation text; new placeholders `{rate_plan}`, `{cancellation_policy}`, `{promo_code}` |
| Import/Export | Schema 4: `rate_plans` (top level), per room `rate_plans` (by name, with amount) and `child_rules`, `promo_codes` (rooms by slug, plans by name, no usage). Options/CLI `--skip-rate-plans`, `--skip-promo-codes`. Imported bookings are re-linked to plans and codes by name/code. |

### 11.2 Deviations and decisions

1. **With the feature off, the form keeps today's *Adults* + *Children* counts.** The brief says "a single Guests count as today", but 1.0–1.2 already show Adults and Children (Children hidden when *Children up to* = 0). Nothing changes for existing sites; ages, max adults and child prices appear only with the feature on.
2. **Child prices apply to per-person amounts only**, as the brief says (per-guest rate-plan supplements, and the tourist tax when chosen). The plan's draft "extra adult price / base occupancy" was not built.
3. **Tourist tax and children:** three choices: *same as adults* (default), *exempt under age N* (older children pay in full), or *use the child price rules*. This covers both "optional child exemption by age" (C) and "child pricing rules for … tourist tax" (A).
4. **"Paid at the property" tax is not in `total`.** It's shown as its own line and in `due_at_property` / *Payable at the property*. This reverses the draft note in §2.2 ("total includes tax"); Day 5 payments will charge `total`.
5. **A room offering several plans requires a choice**; with one plan it's used automatically. A room with no plan (or the feature off) uses the normal price. Plans switched off or not offered for the room are refused with a clear message.
6. **Room assignments are edited on the rate plan's form** (tick rooms + optional room amount), and the room screen shows a summary with a link. The data still lives on the room, so it travels with it.
7. **Promo usage limit and booking requests.** Only confirmed bookings count, as asked. A pending request with a code that's since been used up can still be confirmed by staff; they get a warning, and the discount stays.
8. **An invalid or used-up code blocks the booking** with the reason, instead of silently booking without the discount.
9. **Stay-date validity means every night** must be inside the code's stay dates (like seasons: inclusive last night).
10. **"Manipulated client-side total rejected"**: the browser never sends a price the server uses. The form sends the total the guest saw as `expected_total`; if the server's price differs (tampering, or prices/codes changed meanwhile), the booking is refused with the new price and the summary refreshes. A `total` field is ignored.
11. **Admin booking details view** (`?page=flexo-booking&booking=ID`) is new: there was no single-booking screen, and the brief asks for "booking details". References in the list link to it; the calendar dialog has a *Booking details* button.
12. **CSV:** new columns are appended after the existing ones, so spreadsheets built on the old column order keep working.
13. **Ready-made plans are added on `init` after migration 4**, not inside it, so their names are in the site's language (WordPress 6.7 warns about translations loaded before `init`).
14. **Promo attempts:** more than 20 wrong codes per visitor per hour → 429 (filter `flexo_booking_promo_attempts`), against code guessing.

### 11.3 Tests run (MariaDB 10.11 and SQLite)

| Test | Result |
|---|---|
| `test-day3.php`. **Children:** feature off keeps counts; ages required and 0–17; capacity includes children and babies; max adults; free / 50 % / adult bands; per-room override; staff ages. **Rate plans:** no plan; Room Only; per night; per booking; per guest per night with children; percentage (negative); per-room amount; crossing two seasons with a per-person plan and with a percentage; one plan skips the choice; several plans require one; switched-off / not-offered plans refused; feature off. **Tourist tax:** adults; same as adults; exemption age; child rules; included vs at the property; stored total. **Promo:** percentage; fixed; expired; not yet; switched off; unknown; room and rate-plan restrictions; minimum amount; minimum nights; stay dates; total never negative; usage only on confirmation; limit reached; re-validated when booking; over-limit warning; cancellation restores; instant booking uses at once; feature off. **Manipulated totals** (engine and REST 409, `total` ignored). **REST** quote/availability/booking. **Snapshot** unchanged after editing room, season, plan, promo, tax and child prices, and after deleting the plan. Admin details view. | 122/122 on both |
| `concurrency-promo.sh`: two instant bookings in different rooms race for a code's last use | Exactly one wins, on both |
| `portability-day3.php`: export (schema 4, no usage) → fresh site: child rules, tax settings, features, max adults, room child rules, plans (details, off stays off), room offers by name incl. amounts, codes with conditions re-linked, usage 0, re-import doesn't duplicate, same price as the template, both optional | 4/4 + 21/21 |
| Upgrade 1.0.0 → 1.3.0 (MariaDB) and 1.2.0 → 1.3.0 (SQLite): data intact, all tables, presets added once, an existing booking's stored breakdown byte-identical | 21/21; pass (the one "count" difference was the extra booking added for the comparison) |
| Browser `e2e/day3.js`: ages (selectors, required), capacity, "from" price, plan chooser (order, ✕ Non-refundable text), summary lines (plan, ages, child supplement, tax with exempt child, total, cancellation), promo collapsed / expired message / applied with Subtotal–Discount–Final total, booking sent; one-plan room skips the choice; hotel changes the price meanwhile → refused with the new price, summary refreshed, rebooked; room without plans; search bar passes ages; phone 390 px (results, plans, summary, promo) without horizontal scroll; admin list/details, promo usage after confirming, Expired label, promo form validation, staff booking with ages + plan + code, room screen, settings tabs, CSV, calendar dialog; all four features off → invisible, old bookings keep their breakdown; back on | 63/63 |
| Regression: pricing parity, seasons, features (+constants), regression, iCal, concurrency, `e2e/day1.js`, `e2e/day2.js` | All pass |

Found and fixed during testing:
- The ready-made plans were created before translations loaded (WordPress notice, English names on non-English sites) → moved to `init`.
- On phones, amounts in the summary wrapped ("300.00" / "€") and the first price line was bold like the room name → CSS fixed.

---

## 12. Day 4 status, deviations and test results

### 12.1 Built (1.4.0, migration 5)

| Area | Files |
|---|---|
| Schema | `flexo_consents` (booking, type, text, text hash, language, time), `flexo_invoices` (person/company fields), `flexo_email_log`; bookings get `locale`, `emails_sent`, `anonymized_at` (+ key on `guest_email`). Migration 5. |
| Privacy | `class-privacy.php`: consent text (per language, `{privacy_policy}` link), consent record, `anonymise()`, daily retention, manual *Anonymise* (admin-post), WordPress exporter/eraser (bookings, invoice details, consent, email log), policy guide text. Guest form fields: phone required/optional/not asked, special requests optional/not asked. |
| Invoice request | `class-invoices.php`: fields with required/optional/hidden settings, person/company, light EIK check (9 or 13 digits when numeric), storage, text block, form markup |
| Emails | `class-emails.php` rewritten: guest types (request, confirmed, cancelled, pre-arrival, review; deposit/payment reserved for Day 5), hotel types (new, cancelled, iCal conflict – moved here from `class-ical.php`) with on/off, several notification addresses, per-language templates, HTML layout + plain text (PHPMailer AltBody), email log with daily cleanup, hourly scheduled sending (08–21 h, once, confirmed only, lock), test email, SMTP detection notice |
| Translation | `class-i18n.php` (guest language, `with_locale()`, site language, Polylang/WPML strings, languages and page translations, date format per language); `languages/`: `.pot`, complete `bg_BG` `.po/.mo/.l10n.php` (868 strings); form sends `locale`, REST switches to it, bookings store it |
| Tracking | `class-tracking.php` (config), `booking.js`: `search`, `room_select`, `begin_checkout`, `booking_complete` to `dataLayer` (GA4 ecommerce, cleared before each), once per reference (localStorage), redirect waits for `eventCallback` (≤ 1.5 s), optional direct Meta Pixel |
| Admin | Settings tabs *Privacy*, *Invoices*, *Emails* (rewritten), *Tracking*; General → guest form fields; booking page: invoice box, consent, language, emails sent, *Anonymise*; list: 🧾 Invoice and *Anonymised* badges; CSV columns appended (language, consent, invoice ×6, anonymised) |
| Import/Export | New settings travel automatically (templates, schedule, review link, privacy, invoice fields, tracking); notification addresses still never exported |

### 12.2 Deviations and decisions

1. **WordPress Export/Erase Personal Data and the policy-guide text are always on**, not behind *Privacy consent*. A guest's right to a copy or erasure doesn't depend on a feature switch, and they add nothing to the hotel's screens. Consent, retention and the *Anonymise* button are behind the feature.
2. **Anonymising removes the invoice details too.** The brief keeps "dates, room, prices, status"; invoice requests are personal data (names, addresses). The invoice the hotel issued lives in its accounting software, which is where accounting law applies.
3. **The consent record is kept after anonymising**, as proof of consent; it contains no personal data (booking ID, text, time, language).
4. **"Required" off still shows the consent checkbox**, as optional; only ticked consents are recorded.
5. **Built-in email texts are translated automatically; texts the hotel changed are its own.** Existing sites store the English defaults in their settings, so the plugin recognises unchanged defaults (in English or the site language) and uses the built-in text in the guest's language. Only changed texts are offered to Polylang/WPML string translation (group "Flexo Booking").
6. **Hotel emails use the site language** (Polylang/WPML default language), not the admin user's language and not the guest's.
7. **Scheduled emails:** sent between 08:00 and 21:00; reminders only for arrivals after today; review requests only within 2 days of their due date (so switching it on doesn't email old stays); a booking is marked before sending, so a failure is logged but never retried every hour. Pending requests get no reminder.
8. **HTML emails are generated from the plain-text templates** (hotel colour, logo, footer). There is no visual email editor, so the templates stay simple to edit and translate.
9. **SMTP detection** treats any plugin that takes over `wp_mail` or configures PHPMailer as "set up"; the notice shows only on the plugin's screens and can be hidden per user.
10. **Tracking goes only to `dataLayer`** (no Google/Meta scripts are added). Direct Meta Pixel calls are an opt-in setting for sites without Tag Manager, default off, because the brief says to fire only through the data layer so consent tools stay in control.
11. **`booking_complete` fires for booking requests too** (with `booking_status` = `pending`), because the request is the website conversion; GTM can filter on the status.
12. **WPML** is implemented through its public hooks (`wpml_register_single_string`, `wpml_translate_single_string`, `wpml_object_id`, `wpml_active_languages`, `wpml_default_language`) but not tested: it's a paid plugin. **Polylang** was tested with the real plugin.
13. **Bulgarian dates (DD.MM.YYYY) are also used for other languages that write dates that way** (de, ru, ro, el, …; filter `flexo_booking_date_format`).
14. **Invoice fields are not on the staff *Add booking* form** (the brief asks for them in the guest checkout); staff can put details in the notes.
15. **Polylang room translations are not needed:** rooms are shared by all languages; the booking pages are translated.

### 12.3 Tests run (MariaDB 10.11 and SQLite)

| Test | Result |
|---|---|
| `test-day4.php`. **Consent:** off → hidden; required → refused (engine and REST 400), "false" is not consent, never pre-ticked, record with time, text and hash, new text → new version, optional mode. **Form fields:** phone required/optional/not asked, fields not asked never stored. **Invoice:** off → ignored; no invoice; person; required field missing; company with EIK 12345 refused, "203 456 789" accepted, foreign ID with letters accepted, VAT not checked; configurable fields; REST 400; details page; hotel email; text block. **Retention:** off by default, 12 months anonymises only the older booking, fields removed, dates/prices kept, invoice removed, idempotent, feature off → nothing; manual anonymise; consent kept; no email to anonymised bookings. **WP Export/Erase:** registered, bookings + invoice + emails + consent exported, erase anonymises and clears the log, nothing left. **Emails:** request/confirmed/cancelled to guest, new/cancelled to hotel (two addresses), HTML in the hotel colour, text part, switching hotel notices off, edited template with `{booking_ref}`/`{hotel_phone}`, emptied template → built-in text. **Scheduled:** not at night, reminder in 2 days + review for yesterday only (not cancelled/pending/later/old), not twice, cancelled before its reminder, no link → no review, guest emails off → nothing, cron events scheduled. **Log/test email:** test email, sent entry, failure with reason, cleanup after 90 days, SMTP check, real PHPMailer gets the plain-text part and logs the failure. **Import/Export** of the new settings. | 93/93 on both |
| `test-i18n.php` (site in Bulgarian): English guest → English emails with English dates, hotel email in Bulgarian with the guest's language, later emails still English; Bulgarian guest → Bulgarian text and DD.MM.YYYY; changed text used as written; Bulgarian admin texts and plural forms; REST in the requested language | 18/18 on both |
| `polylang-test.php` (real Polylang, Bulgarian default + English): booking page per language (texts, script texts, `data-locale`, date format), consent linking to the privacy page of that language, English booking over HTTP → English answer, English thank-you page, stored language, English consent text, English guest email + Bulgarian hotel email, hotel's own subject translated in Polylang string translation, Bulgarian original for Bulgarian guests; strings listed under Languages → Translations | 21/21 + screen check |
| Browser `e2e/day4.js`: consent (shown, not ticked, link, blocks sending), invoice (hidden until ticked, person/company fields, EIK error from the server, success), tracking (4 events with correct values, GA4 ecommerce and clearing, no personal data, `booking_complete` once, reload doesn't repeat, redirect to the thank-you page after the event, Meta Pixel only when switched on), phone 390 px, admin (invoice badge, details with invoice/consent/emails, hotel email with invoice, CSV columns, anonymise), settings tabs, review request setting, test email + log, no SMTP notice with a mail plugin, privacy tab text, tracking tab, policy guide, features off → invisible and booking still works | 48/48 |
| Upgrade 1.0.0 → 1.4.0 (MariaDB) and 1.3.0 → 1.4.0 (SQLite, with a Day 3 booking: stored breakdown byte-identical) | 24/24; pass |
| Regression: pricing parity, seasons, features (+ constants), regression, iCal, Day 3, both concurrency tests, Import/Export Day 3, browser Days 1–3 | All pass |

Found and fixed during testing:
- Hotel emails followed the guest's language during a guest's request (`get_locale()` is switched) → the site language is now read from the settings / Polylang default.
- Polylang listed every unchanged built-in email text for translation → only texts the hotel changed are registered.


---

## 13. Day 5 status, deviations and test results

### 13.1 Built (1.5.0, migration 6)

| Area | Files |
|---|---|
| Schema | `flexo_payments` (history: payment / refund / failed attempt, gateway, amount, currency, transaction ID, note, non-sensitive meta, staff user, time), `flexo_webhook_events` (unique event ID → idempotency); bookings get `payment_method`, `payment_status`, `amount_due`, `amount_paid`, `amount_refunded`, `hold_expires_at`, `payment_due_at`, `payment_session`, `payment_conflict`, `access_key` (SHA-256 of the guest's link key). Migration 6 also drops the stored copies of the three 1.4.0 placeholder payment emails. |
| Statuses | `pending_payment` (card hold; occupies only while `hold_expires_at` is in the future – `Inventory::occupying_sql()` / `is_occupying()`), `awaiting_payment` (bank transfer, occupying), `expired` (not paid in time, not occupying). `update_status()` takes a `$context` (`notify`, `force`, `reason`) and runs the filter `flexo_booking_update_status_target`. |
| Payments service | `class-payments.php`: modes (property / deposit / full) × booking mode, gateway registry, pricing step 95 (payment schedule; deposit % or fixed, capped at the total; lines paid at the property always stay there), `prepare()`/`apply()` in `Bookings::create()` inside the room lock, `start()`, `complete()` (idempotent per transaction, confirms when the amount due is covered; late payment → confirmed if the room is free, else a conflict; busy lock → undone, and the webhook answers 500 so Stripe retries), `refund()`, `release_hold()`, `retry()`, an hourly job (expired holds, bank reminders 08–21 h, auto-cancel), a single WP-Cron event at each hold's end, lazy release (status endpoint, bookings list), guest views, conflict list. Keys live in the separate option `flexo_booking_payment_secrets`. |
| Gateways | `payments/interface-gateway.php`. `class-gateway-stripe.php`: Checkout Session through `wp_remote_request` to a filterable API base, idempotency key, `expires_at` ≥ 31 min, session closed when the hold ends, locale, metadata on the session and the PaymentIntent. Webhook: v1 HMAC-SHA256 signature with a 5-minute tolerance, checked against the test and live secrets; livemode check; event dedupe; dispatch of 6 event types; cumulative refunds. `class-gateway-bank-transfer.php`: instructions, reference format, IBAN grouping, text for emails. |
| Guest flow | Payment choice in the details form (`Frontend::payment_fields()`, also injected into old template overrides); summary rows *Deposit (30%) – to pay now* / *At the property* / *Payment: at the property*; submit label per method; redirect to Stripe; return page (`?fb_payment=return|cancel&fb_ref&fb_key`) polling `GET /payment`; *Pay now* retry; *Search again*; bank details with copy buttons; `booking_complete` only after a confirmed card payment |
| REST | `POST /bookings` (+ `payment_method`, `return_url`; the response has `payment`), `GET /payment`, `POST /payment/retry`, `POST /stripe-webhook` |
| Emails | Payment templates in use: waiting for payment (bank details), reminder, payment received (it is also the confirmation), payment failed, cancelled – not paid. Hotel types *Refunds made in Stripe* and *Payment conflict*. Placeholders `{payment_method}` `{amount_due}` `{amount_paid}` `{balance_due}` `{payment_deadline}` `{payment_instructions}`. Payment lines in `{booking_details}`. No emails for a card hold until it is paid. |
| Admin | Settings → Payments (modes with a live example; ways to pay and whether each is ready; Stripe test/live keys, masked; webhook address and event list; hold minutes; bank details and rules). A notice when card payment is paused by request mode. Booking page *Payments* box (summary, history with a Stripe dashboard link, *Payment received*, record payment/refund). List badges and a *Payment received* button. *Confirm & ask for payment* / *Confirm without payment*. Payment-conflict box. Calendar items 💳/🏦 with payment lines and legend. 8 CSV columns appended. |
| Portability | Export schema 5: payment settings travel; Stripe secrets (separate option) and the bank account never do, like the notification email. A bookings export carries the payment history; open holds import as expired. |
| Responsive | `booking.css`. Up to 1024 px: centred flow (container, headings, room cards in one column, prices, rate plans, summary box, promo, buttons, result, bank details); inputs and long texts stay left-aligned. Up to 767 px: 44 px tap targets, a sticky total + continue bar, full-width buttons. Desktop rules untouched. |
| Other | Features `online_payment`, `deposit` and `bank_transfer` ready (all 15 features built); privacy policy guide paragraph about Stripe; uninstall drops the 2 new tables, the secrets option and the hold events; Bulgarian translation complete (1044 strings) |

### 13.2 Deviations and decisions

1. **Stripe could not be reached from the build environment** (`api.stripe.com`, `checkout.stripe.com`, `js.stripe.com`: proxy 403). Card payments were tested against Stripe's documented API and webhook format with (a) a fake inside WordPress (`pre_http_request`) and (b) a local stand-in server with a hosted payment page and signed webhooks. **A real Stripe test-mode run is still needed** (README §27).
2. **The hold status is `pending_payment`**, not `awaiting_payment` as sketched in §2.8; `awaiting_payment` is the bank-transfer wait. A hold occupies the room only while it is valid, directly in the availability SQL, so availability is right the moment it ends. Cron only tidies up.
3. **Stripe keeps a Checkout page open for at least 30 minutes**, but the hold is 20–30 minutes (setting). When the hold ends, the plugin closes the page through the API. A payment that still slips through is the "late payment" case: confirmed if the room is free, otherwise a conflict.
4. **The payments table is a history** (one row per payment, refund or failed attempt) plus summary columns on the booking, instead of the planned one-row-per-payment-with-status design. This makes reporting and the CSV simpler, and gives idempotency by transaction ID.
5. **Modes A–E** are the booking mode plus a payment choice: *Nothing online* (E; with the payment features off it is A/B), *A deposit* (C), *The full amount* (D). "Pay at the property" is stored on the booking as payment method `property`.
6. **Bank transfer with booking requests**: the details go out when the hotel accepts (*Confirm & ask for payment*), and the deadline counts from then. *Confirm without payment* exists for trusted guests.
7. **Bank account details are not exported** (like the notification email), although the brief only excluded keys and secrets: a template's IBAN must never appear on a client's site.
8. **Card bookings send no email until paid.** The hotel's *New booking* email is sent when the payment is confirmed and serves as the payment notification. Payments recorded by staff don't email the hotel; refunds made in Stripe do.
9. **A declined card** is noted in the history but sends nothing, because the guest can retry on Stripe's page. *Payment failed* is sent when the hold ends after a decline, or when a delayed method fails. Abandoned payments send nothing.
10. **Payment conflicts don't email the guest automatically.** The return page tells them the hotel will contact them; the hotel gets the alert and a list with *Mark as resolved*.
11. **Refunds never change the booking status.** The hotel cancels separately if the stay is cancelled.
12. **Delayed payment methods** (e.g. SEPA): the hold is extended by 7 days while Stripe processes; the README recommends cards only.
13. **Two extra guest templates** (payment reminder, cancelled – not paid) beside the three reserved on Day 4, because the brief asks for reminder and auto-cancel emails.
14. **Thank-you page:** bank-transfer bookings stay on the form page so the bank details are visible; card bookings go to the thank-you page after the payment is confirmed.
15. **Responsive:** summary lines keep label left / amount right inside a centred box, for readability. Centring applies up to 1024 px; the sticky bar is on phones only.
16. **Old bookings** keep their totals; they get payment method `''` and no payment rows.
17. **Fiscal receipts** (e.g. Наредба Н-18) are not issued by the plugin; there is a README note only, as briefed.

### 13.3 Tests run (MariaDB 10.11 and SQLite)

| Test | Result |
|---|---|
| `test-day5.php`. **Configuration:** methods per booking mode, keys per mode, deposit without the feature, secrets never lost or printed. **Amounts:** full, 30% of 800 = 240/560, fixed, cap, % cap, tax at the property and with the booking, payments off. **Card, full payment:** hold, Stripe request contents, held room unavailable, key check, webhook confirms, history, emails, duplicate event, same payment in another event. **Deposit.** **Webhooks:** 6 signature/mode rejections, unknown booking, currency mismatch → retry, too-small payment. **Failures:** declined + expired (email), async failure, abandoned (released without cron, session closed, no email), lapsed hold released on the status check. **Late payment:** room free / taken (conflict, alert, no overbooking, guest view, resolve); a cancelled booking paid; room lock busy (undone, retry confirms). **Refunds:** partial, duplicate, full, cap, manual. **Retry:** new page, old key invalid, old session ignored, 409 when the room is taken. **Stripe unreachable:** 502, no hold. **Bank transfer, instant:** status, deadline, instructions, emails, occupancy, received; deposit by transfer; reminder window and hours; auto-cancel (emails, no generic ones, room free); auto-cancel off. **Requests:** details after accepting, force; card refused in request mode. **Other modes:** pay at the property, no payments (instant and request). **Manipulated amounts:** expected_total, extra amount fields, unknown method. **Admin, emails, settings:** admin card/badge/calendar, email templates, settings tab, Import/Export. | **258/258** on both |
| Browser `e2e/day5.js` (local Stripe stand-in). **Card deposit end to end:** summary, choices, labels, 240 EUR on the payment page, webhook confirmation, result page, tracking once, emails. **Payment problems:** cancel → held → pay again; declined → expired via webhook; wrong key; search again. **Bank transfer at 390 px:** sticky bar, details, copy buttons, centring. **Layout at 360/390/414/768/1024/1280** (search, results, rate plans, details): no horizontal scroll, centred headings and form, left-aligned inputs, 44 px targets, desktop card unchanged. **Modes:** request mode; minimal package (only booking requests). **Admin:** list statuses, booking page, Stripe link, *Payment received*, *Confirm & ask for payment*, calendar, settings, CSV. | **179/179** |
| `concurrency.sh` with `RACE_PAY=1`: two guests start paying for the last unit at once; one hold, one refusal | pass (both DBs) |
| `portability-full.php`: all 15 features configured → fresh site. No keys, secrets, bank account or notification email in the file; payment settings imported; payments inactive until the new site's own details are entered; identical totals, tax, discount, deposit and amount at the property. | 7/7 + 30/30 |
| Upgrade 1.0.0 → 1.5.0 (MariaDB, `upgrade-verify.php`) | 26/26, DB version 6 |
| Upgrade 1.4.0 → 1.5.0 (MariaDB, Day 4 booking with invoice + consent) | bookings, invoice, consent and features kept; placeholder payment texts dropped; payments off |

Found and fixed during testing:
- Three admin edits had not applied (booking page payment box, CSV payment values, *Payment recorded* message); the browser test caught them.
- A busy room lock during a webhook would have been treated as a payment conflict, and Stripe's retry then seen as a duplicate. The payment is now undone and the webhook answers 500, so the retry is processed.
- The tablet rate-plan panel shrank to its content inside the centred card; it is now full width (max 520 px).
- Older tests depended on state left by other tests (rate limit, phone field); they now reset or restore it.

---

## 14. Status after Day 5

### 14.1 Features (1.5.0)

| Area | What the hotel gets |
|---|---|
| Booking | Booking requests or instant booking; staff bookings and blocked dates; per-room lock (no double booking, also for payment holds and promo-code limits); availability counted night by night over identical units |
| Prices | Nightly prices with weekend prices; seasonal prices and minimum stays; closed dates; children by age with child rules (global / per room) and max adults; rate plans (6 presets, per-room amounts, cancellation text); tourist tax (in the total or at the property); promo codes (dates, limits, rooms, plans); one server-side pricing service with stored breakdowns |
| Payments | Nothing online / deposit (% or fixed) / full amount; Stripe Checkout (test/live, signed idempotent webhooks, holds, late-payment conflicts, refunds from Stripe); bank transfer (instructions, received, reminder, auto-cancel); payment history, balance, badges, CSV |
| Calendar | Admin month calendar (rooms × days, today overview, quick actions); iCal import/export with Booking.com/Airbnb, conflicts and alerts |
| Guests | Privacy consent with proof, retention and anonymising, WP export/erase; invoice requests (person/company); HTML emails with a log, reminders, review requests and payment emails; languages (full Bulgarian, Polylang tested, WPML hooks) |
| Marketing | dataLayer events for GTM/GA4/Meta without personal data |
| Platform | Feature system (made available by FlexoHotels via constant / Agency screen / WP-CLI, enabled by the hotel); versioned migrations from 1.0.0; Import/Export and WP-CLI; Elementor widget + dynamic tag + shortcode; responsive, centred mobile/tablet flow inheriting Elementor global colours and fonts |

### 14.2 Final regression (this session)

| Suite | MariaDB | SQLite |
|---|---|---|
| Pricing parity / seasons / features / regression / iCal | 8 / 46 / 30 / 43 / 66 | same |
| Day 3 / Day 4 / Day 5 / Bulgarian site / feature constants | 122 / 93 / 258 / 18 / 6 | same |
| Concurrency: last unit / payment hold / promo last use | pass / pass / pass | pass / pass / pass |
| Browser Days 1–5 | 37 / 51 / 63 / 48 / 179 | – |
| Polylang (real plugin) | 21/21 | – |
| Portability: Day 3, full configuration | 4+21, 7+30 | – |
| Upgrades 1.0.0 → 1.5.0, 1.4.0 → 1.5.0 | pass | – |

### 14.3 Known limitations and untested areas

- **Real Stripe not exercised** (the network is blocked here). A test-mode payment, the real Checkout page, real webhook delivery and refunds must be checked once per site (README §27). The Stripe API version is pinned to `2024-06-20` in the request header.
- **Elementor Pro editor UI not tested.** The Elementor source checkout used here has no compiled editor assets. The widget, dynamic tag, front-end rendering and style inheritance are tested; dragging the widget in the editor is not.
- **WPML** is supported through its public hooks but untested (paid plugin).
- **WP-Cron timing:** reminders, auto-cancel, iCal sync and scheduled emails need site traffic or a real cron job. Payment holds don't: availability ignores expired holds immediately.
- **Delayed payment methods** in Stripe hold the room for up to 7 days; cards only is recommended.
- **No fiscal receipts or invoices** are issued (the hotel's responsibility).
- **Staff bookings** don't take online payments (payments are recorded manually).
- **A payment conflict needs a person:** the plugin never moves a guest to another room by itself.

---

## 15. Day 6 status, deviations and test results

Day 6 followed `UX_REVIEW.md` (approved with Q1–Q6 as proposed) in the
agreed order **C Appearance → A Guest → B Owner/staff → language pass →
regression**, one commit per part.

### 15.1 Built (1.6.0, migration 7)

| Area | Files |
|---|---|
| Schema (migration 7, additive) | Table `flexo_booking_log` (booking history and enquiries: booking, action, details JSON, user, time); `bookings.staff_notes`; `rate_plans.meals` (filled in for the presets); feature `custom_appearance` switched on; roles installed. |
| C. Appearance | `class-appearance.php`, `admin/class-appearance-admin.php`, `assets/fonts/*` (7 OFL variable fonts, Latin + Cyrillic, self-hosted). *Match my website* (default and after upgrades) or *Custom*: CSS custom properties on `.flexo-booking` in an inline style after `booking.css`; only the chosen fonts are declared, only on pages with the form. Live preview (the real form in an iframe, desktop/phone), WCAG contrast warnings with suggestions, reset, email colour/logo moved here, Import/Export. |
| A. Guest flow | `assets/js/booking.js` (rewritten: accessible date-range picker, steps, URL state with `pushState`, summary sidebar / phone bar, inline validation, no-availability panel, enquiry, confirmation, guest booking page, search bar), `booking.css` (Day 6 section; container-width classes `.fb-wide` / `.fb-narrow`), `templates/booking-form.php`, `class-guest.php` (keys, views, `.ics`, calendar, alternatives, enquiries, requests, booking-page detection), `class-phone.php`, `class-frontend.php` (steps, config, texts). Room fields size/beds/amenities; rate-plan meals; phone saved as `+CC …`; `{manage_link}`; `.ics` attached to *Booking confirmed* / *Payment received*; emails with table blocks, a button for single links and the address in the footer. |
| B. Owner/staff | `admin/class-today-admin.php` (Today, Needs your attention, admin-bar link), `class-admin.php` (menu order, tabs, list filters and quick actions, phone cards, details with a top action bar, open requests, notes, history, resend, dialogs, old-URL redirects, Add booking rate fix), `class-booking-log.php`, `class-roles.php`, `admin/class-health.php` (+ Stripe `check_keys()`), `admin/class-help.php`, `admin/class-wizard.php`, `class-settings.php` (tabs Hotel / Booking rules / Payments / Taxes & invoices / Privacy / Tracking / Features / Health, aliases for the old tabs, advanced toggle, managers limited to email/appearance keys), `assets/js/admin-bookings.js`, `admin.css` (Day 6 section). |
| Language | Glossary applied to EN and BG (status names, *Restore booking*, *Remove personal data*, *Rates*, *Import & export*, *на място* …); 523 new Bulgarian strings; `.po`/`.mo`/`.l10n.php` regenerated. |

### 15.2 Deviations and decisions

1. **Hidden admin pages stay registered.** Removing sub-pages from `$submenu` makes WordPress refuse them ("Sorry, you are not allowed…"), including *Add room* for managers. Seasons, closed dates, rates, calendar sync, import & export, the wizard and *Add room* are therefore registered and hidden with a class (`flexo-menu-hidden`, CSS only in the admin). They are reached through the *Rooms & prices* tabs and the Settings tab bar.
2. **Menu names:** *Promotions* stayed **Promo codes** (the term hotels already know, and the feature's name). **Emails** is its own menu entry so Hotel Managers can use it without Settings access. Calendar sync and Import & export keep their own screens, linked from the Settings tab bar (and Calendar sync from the Rooms & prices tabs), instead of being rendered inside Settings.
3. **The summary placement follows the form's width, not the viewport.** Many themes put the form in a narrow column, so the sidebar appears when the form itself is at least 900 px wide. Below that the summary is a sticky bar on phones and an open, static box from 768 px.
4. **Enquiries are stored in the history table** (not as bookings) so they can appear under *Needs your attention*. They are deleted after 12 months (daily job), included in the WordPress personal data export/erase, and rate-limited. Not switchable (Q3).
5. **The guest booking page is the booking page** with `fb_manage` + `fb_key`. The key is an HMAC of the booking (nothing stored); the Day 5 payment key is accepted too, so links in payment emails keep working.
6. **Phone numbers are validated** (6–15 digits) for new bookings and saved with the country code; existing bookings are untouched. API clients that send placeholder numbers such as `1` now get `flexo_invalid_phone`; test fixtures were updated.
7. **"Preview booking form"** opens the booking page (admin bar and Today). When no page is found it isn't shown; the wizard and Health offer to create the page rather than creating it silently.
8. **Room short description** reuses the existing excerpt; no new field.
9. **Secrets:** when a logged-in non-administrator saves settings, Stripe keys and webhook secrets are left unchanged. WP-CLI and the tests are unaffected.
10. **Booking modes offered in the wizard** follow the features *available* on the site (agency level), like the Features tab.
11. **Editors** get all three new capabilities (Q4: no hotel loses access); `flexo_booking_manage_capability` is still applied.
12. **Status labels changed, keys didn't** (`pending`, `expired` …), so CSV values, filters and integrations are unchanged. The Days 1–5 browser tests were updated only where wording or navigation intentionally changed (the list's address, tab names, labels, the in-page confirmation dialog); no check was removed.

### 15.3 Tests run (MariaDB 10.11)

| Test | Result |
|---|---|
| `test-day6.php`: migration 7 (tables, columns, roles, editors keep access, preset meals); room and rate details; phone numbers (country codes, `00`/`+` prefixes, too short refused, stored with the code); confirmation, key and `.ics` (key checks, refresh, all-day event, CRLF, email table, manage link, attachment removed after sending, feature off); guest requests (message required, nothing changes, hotel emailed, 3 a day, Needs attention); date-picker calendar (full, closed, minimum stay, fitting rooms, prices on/off, invalid month); nearby dates and enquiries (validation, honeypot, email, log, no booking, Needs attention, personal data export and erase); list filters and order; Today (arrivals, departures, tomorrow, next 7 days, cancelled not counted, menu badge); history in hotel words and new status names; roles (staff, manager, editor); settings a manager may change (keys untouched); **health** (missing booking page, failing email, stopped WP-Cron, broken calendar feed, missing/invalid Stripe keys via the stand-in, report without secrets or guest data); wizard helpers (page created and set, no redirect on sites with rooms); Appearance (Match adds nothing, custom CSS scoped to the form without `!important`, only the chosen self-hosted Cyrillic font, email colour, no Google Fonts anywhere, Import/Export incl. meals) | **108/108** with the Stripe stand-in (`STRIPE_MOCK`); 105/105 without it (the three key checks are skipped) |
| Browser `e2e/day6.js`: steps, picker (focus, minimum stay, keyboard), address state, room cards and rates at 390 px, phone summary bar, mobile keyboards/autocomplete, optional marks, phone country, inline errors and focus; refresh/Back/Forward, personal data not kept; **three quick submits → one booking**; confirmation (refresh, directions, calendar, fits 390 px); guest booking page and a cancellation request reaching the hotel, wrong key refused; nothing free → nearby dates, other rooms, enquiry emailed; **keyboard-only booking**, every field named for screen readers, **contrast AA**; layout at 360/414/768/1024/1280 (no horizontal scroll, no layout shift); minimal setup (request mode, no guest page link); Today, old list address, search, filters, cancel dialog, history, notes, resend; health and system report, help links, old tab address; roles (staff lands on Today, manager adds a room); admin at 390 px (Today, cards, details); Appearance (upgrade keeps *Match*, contrast warning, preview = front end, rest of the site unchanged, **Cyrillic font ranges**, **no requests to Google**, **Elementor widget colour > Appearance > website**, reset, Import/Export) | **117/117** |
| Setup wizard on a fresh site (MariaDB, by hand in the browser): activation redirect, all seven steps, page created, test email, test booking detected | pass |
| Browser Days 1–5 | 37 / 51 / 63 / 49 / 167 – all pass |
| PHP suite: pricing parity / seasons / features / regression / iCal / Day 3 / Day 4 / Day 5 / Bulgarian site / constants | 8 / 46 / 30 / 43 / 66 / 122 / 93 / 258 / 18 / 6 – all pass |
| Concurrency: last unit / payment hold / promo code's last use | pass |
| Polylang (real plugin) | 21/21 |
| Upgrades 1.0.0 → 1.6.0 and 1.5.0 → 1.6.0 (`upgrade-verify.php`, MariaDB) | 27/27 each; DB version 7, history table, notes and meals columns, roles, Appearance on *Match*, no wizard redirect, no migration error |
| Portability into fresh sites: base (+ re-import), Day 3, full configuration | 11 + 3, 4 + 21, 7 + 30 |

Found and fixed during testing:
- The details page overflowed at 390 px (payment table); the table now scrolls inside its box and the page is one column.
- On phones the promo field was hidden in the collapsed search bar; it moved into the form.
- The summary was collapsed on desktops with narrow theme columns; it is open and static there.
- The loading skeleton reused the room card class; it has its own class.
- "Loading availability…" replaced the date prompt after a date was chosen; it now shows only before a check-in is picked.
- A leftover "(Settings → Children)" hint now points to *Booking rules*.

### 15.4 Known limitations (in addition to §14.3)

- **Screen readers** were checked through the accessibility tree and ARIA attributes in Chromium, not by hand with NVDA or VoiceOver.
- **The fonts add about 0.8 MB to the plugin** (the 1.6.0 zip is 1.3 MB); browsers download only the chosen fonts and the subsets a page needs.
- **Theme copies of `booking-form.php` made before 1.6.0** keep working; the script adds the step bar, summary and picker around them, but custom markup inside them isn't restyled.
- **The Elementor editor** (drag and drop) is still untested here, as in §14.3; the widget style priority is tested on the front end.
- **Guest requests never change bookings**, by design; the hotel acts on them.

---

## 16. Admin redesign (1.7.0)

Request after Day 6: make the plugin's admin as a whole user-friendly and
not look like basic WordPress. Same rules as before: no business features,
nothing changed for guests, scoped CSS (no global CSS, no `!important`),
no Google fonts, BG/EN texts. No database change (DB version stays 7).

### 16.1 Built

| Area | What |
|---|---|
| Scope | `admin/class-admin-ui.php` adds the body class `flexo-admin-ui` only on the plugin's screens (its pages and the room list/editor). Every new rule is under that class, so the rest of the WordPress admin, other plugins' screens and the website are untouched. WordPress's own controls inside the plugin's content also pick up the brand colour through `--wp-admin-theme-color`, set only on `#wpbody-content`. |
| Design system | `assets/css/admin-ui.css`: tokens (`--fx-*`: neutral surfaces, deep teal brand colour #0f6e6a with white text at 6:1 contrast, status colours, radii, shadows), the bundled Inter font for the content area (Latin, Latin Extended, Cyrillic; self-hosted), buttons, inputs, selects, checkboxes, radios, on/off switches, cards, settings tables as cards, a sticky save bar, list tables, notices, badges, empty states, dialog, room editor, footer and phone rules. `admin.css` and `admin-calendar.css` moved to the same tokens. |
| App bar | Printed on `in_admin_header`: brand mark and hotel name, booking search (to *All bookings*), bell with the *Needs your attention* count, booking form, help, *New booking*; on phones a scrollable row of the visible menu entries (built from the registered submenu, so permissions and hidden pages are respected). |
| Page header | `Flexo_Booking_Admin_UI::page_head()` on every screen: icon, title (+ status badges), one-line intro, actions (keeping `page-title-action` for compatibility), section tabs, then WordPress's notice marker. *Rooms & prices* and *Settings* use the section title with tabs; on the room list it replaces WordPress's own heading. |
| Screens | **Today**: KPI tiles, attention list with icons by type, guest cards with initials, next-7-days and shortcuts column, greeting. **All bookings**: filter toolbar card with search icon, list in a card, phone cards. **Booking details**: actions in the header, guest/stay summary, two-column layout (stay, guest with Email/Call, price, payments \| notes, emails, history timeline). **Features**: cards with icons and switches; booking mode as choice cards. **Emails**: one card per guest email with when it is sent, scheduling controls and an *Edit text* fold-out; placeholders folded. **Health/Help/Wizard/Appearance/Calendar/Rates/Promo codes/Import & export/room editor**: cards, steppers, segmented control, amenity chips. |
| Texts | 36 new strings (BG translated); stale hints fixed ("Settings → Tourist tax", "Awaiting payment", the SMTP notice path, the calendar legend); the email log names enquiry and guest-request emails; the SMTP notice got *Install an SMTP plugin* / *Send a test email* buttons. |

### 16.2 Decisions

1. **No duplicate navigation on desktop.** The WordPress menu stays the main navigation; the app bar's section row appears only on phones, where WordPress folds its menu away.
2. **Markup changes kept small and compatible.** Existing classes, IDs and field names stay (`.flexo-today__attention`, `.flexo-attention__item`, `#flexo-email-pre_arrival`, `features[]`, `.page-title-action`, `.flexo-actionbar` …); switches are real checkboxes and folded email texts are `<details>`, so nothing needs JavaScript.
3. **Section titles:** *Rooms & prices* and *Settings* pages share the section title; the tab says which page you are on.
4. **One `!important`** remains in `admin.css` (the jQuery UI date picker's z-index, from Day 1).

### 16.3 Tests (MariaDB 10.11)

| Test | Result |
|---|---|
| PHP suite (all files, Bulgarian site, constants, concurrency) | all pass |
| Browser Days 1–6 | 37 / 51 / 63 / 49 / 167 / 117 – all pass, no test changed |
| Upgrade 1.6.0 → 1.7.0 (`upgrade-verify.php`) | 27/27, DB 7, no migration error |
| Visual check of every admin screen at 1440 px and the main ones at 390 px, in English and Bulgarian | done; fixed during the check: app bar under the WordPress toolbar on phones (collapsing margin), filter search box height on phones, detail tables on phones, untyped inputs, narrow small inputs, empty email table on bookings without emails |

---

## 17. Day 7 plan: dynamic room pages, room listings and availability buttons

**Status: plan only – waiting for approval (with the questions in §17.8) before any code.**
Target: **1.8.0, migration 8.** Goal: the plugin is the single source of
truth for rooms; listings, room pages, "from" prices, availability and
booking all read the same room data.

### 17.1 How rooms are stored today, and what must change

Rooms are **already a custom post type**, `flexo_room`, since 1.0.0. There is
no custom table and no option holding rooms, so **no data has to move**.
Today the post type is registered as *private*:

| Today | Value |
|---|---|
| `public`, `publicly_queryable`, `show_in_rest`, rewrite | `false` – rooms have no URL |
| `show_ui`, menu | yes, under **Bookings → Rooms & prices** |
| `supports` | title, editor, excerpt, thumbnail, page-attributes |
| Capabilities | own `flexo_room` / `flexo_rooms` (Day 6 roles) |

Room data and where it lives:

| Data | Stored in |
|---|---|
| Name, description, short text, photo, order, slug | `post_title`, `post_content`, `post_excerpt`, `_thumbnail_id`, `menu_order`, `post_name` |
| Price, weekend price, max guests, max adults, identical rooms, minimum nights, size, beds, amenities | post meta `_flexo_price`, `_flexo_weekend_price`, `_flexo_capacity`, `_flexo_max_adults`, `_flexo_units`, `_flexo_min_nights`, `_flexo_size`, `_flexo_beds`, `_flexo_amenities` |
| Rates offered (+ room amounts), child prices, iCal export token | post meta `_flexo_rate_plans`, `_flexo_child_rules`, `_flexo_ical_token` |

Other data points at rooms **by post ID** – bookings, seasons, closed dates
(0 = whole property), calendar connections and imported calendar events
(`room_id` columns), promo-code room limits (`room_ids` list) and the iCal
export address `/wp-json/flexo-booking/v1/ical/{id}.ics`. Links and
templates point at rooms **by slug** – `/booking/?room=deluxe-double`,
`[flexo_booking room="…"]`, the Elementor widget and the "Room booking
link" tag, and Import/Export matching. Because the IDs and slugs stay the
same, all of these keep working untouched.

What has to change:
1. **Register the post type as public** – `public`, `publicly_queryable`,
   `show_in_nav_menus` (Elementor Pro lists post types for Theme Builder
   conditions and Loop Grid sources from these), rewrite with the
   configurable base and `has_archive` off (§17.5). Rooms are **not** made
   editable with Elementor one by one (Elementor would overwrite the
   description, which lives in `post_content`); their layout comes from a
   Theme Builder template or the default room page. The room editor stays
   the classic screen (`show_in_rest` stays off; Elementor does not need
   it).
2. **Room pages**: routing, the default room page, the hidden/demo rules.
3. **New content meta** (§17.3) and a cached "from" price.
4. **Context**: one helper that knows "the current room" in a room page,
   a Theme Builder template, a Loop item, the Elementor editor or a
   shortcode attribute.

### 17.2 Migration and compatibility (`migrate_8_day7`)

| Area | What happens |
|---|---|
| Room posts | Untouched: same IDs, slugs, status, dates. |
| New fields | Written only when the hotel fills them. Empty = sensible fallback (short description → first words of the description; gallery → featured image; no image → neutral placeholder). Hidden and demo flags are stored only when set, so every existing room stays visible. |
| Feature switch | New feature **Room pages** (`room_pages`). On for new installs; on upgraded sites it starts **off**, so nothing new becomes public until the hotel switches it on (Q1). While off, rooms stay exactly as private as today. |
| Rewrite rules | The migration, the feature switch and a change of the room URL base only set a "flush needed" flag; the next request flushes once (soft flush) after the post type is registered. Never on every load. |
| Seasons, closed dates, rates, child prices, promo limits, iCal feeds and tokens, bookings, CSV | Unchanged – same room IDs. |
| Booking links `?room=slug` | Unchanged; a room found by an **old slug** (WordPress keeps `_wp_old_slug`) still opens the right room. |
| Room page URLs | Old slugs redirect (WordPress core for public post types); an old **base path** (e.g. `/rooms/` → `/stai/`) redirects too (previous bases kept in an option). |
| Polylang / WPML | When rooms become translatable, existing rooms are assigned the default language (§17.4 g, Q6). |
| Import/Export | Export schema 4 adds the room content (§17.6 J). Files from 1.0–1.7 import as before. |
| Elementor | The booking form widget, `[flexo_booking]` and the "Room booking link" tag keep their settings. The tag's empty choice becomes **"Current room (automatic)"** – on ordinary pages there is no current room, so it still means "guest chooses", as today. |
| Tests | Upgrade fixtures from 1.0.0, 1.5.0 and 1.7.0 (bookings, seasons, rates, iCal connections, promo limits, links) verified after migration 8. |

### 17.3 Data model for room content (section A)

| Field (hotel language) | Storage | Notes |
|---|---|---|
| Public name | `post_title` (existing) | One name everywhere: website, booking form, emails, admin. |
| Short description (cards) | `post_excerpt` (existing) | Already used by the booking form's room cards. Fallback: first 25 words of the full description. |
| Full description | `post_content` (existing) | Rich text editor. |
| Featured image | `_thumbnail_id` (existing) | |
| Gallery | `_flexo_gallery` (new): ordered attachment IDs | Media library, drag to reorder; deleted images are skipped. |
| Size (m²), beds | `_flexo_size`, `_flexo_beds` (existing) | |
| View | `_flexo_view` (new, text ≤ 100) | Optional, e.g. "Sea view". |
| Max guests / max adults | `_flexo_capacity`, `_flexo_max_adults` (existing) | **Max children is not a new field**: max guests − 1 (one adult must stay), capped by the Booking rules' children limit. |
| Identical rooms, minimum nights, prices, rates, child prices | existing | Unchanged. |
| Amenities | `_flexo_amenities` (existing keys) | Each predefined amenity gets an icon (bundled inline SVG, no icon font, no CDN). |
| Custom amenities | `_flexo_amenities_custom` (new): list of texts | Up to 20, ≤ 60 characters each, Cyrillic-safe; shown with a generic check icon. |
| Display order | `menu_order` (existing "Order") | Used by listings, Loop Grid ("Order by: Menu order") and the booking form. |
| Show on website | `_flexo_hidden` = 1 only when hidden (new) | Hidden rooms stay bookable (staff, links with the slug), but get no public page and are left out of listings and sitemaps. |
| Demo room | `_flexo_demo` = 1 (new) | §17.6 J. |
| URL slug | `post_name` (existing) | Edited in the room screen; old slugs keep working. |
| SEO title / description | `_flexo_seo_title`, `_flexo_seo_description` (new) | Shown and output **only when Yoast SEO and Rank Math are not active**; with them, their own box is used and nothing is duplicated. |
| "From" price (cache) | `_flexo_from_price` (new) | §17.6 G. |

New settings (**Settings → Room pages** tab, shown when the feature is on):
room URL base (default `rooms`; a BG site can use e.g. `stai`), the
"All rooms" page (path, auto-detected – §17.5), "from" price (per night /
hidden), sticky availability bar on phones (on/off), and which parts the
default room page shows (gallery, facts, amenities, rates, booking box).

**Room editor** (Day 6 standards, still the WordPress room screen so
Polylang/WPML/Yoast boxes keep working): the default meta boxes (Excerpt,
Post attributes, Featured image) are replaced by cards in hotel language –
*Basics* (name, short description, full description), *Photos* (featured
image + gallery, drag to reorder), *Room facts* (size, beds, view, max
guests, max adults, identical rooms, minimum nights), *Prices* (existing:
price, weekend price, rates, child prices, seasons summary), *Amenities*
(icon checklist + custom amenities), *On the website* (show on website,
display order, room page link and booking link with **Copy** buttons),
*Search engines* (slug, SEO title/description, or a note pointing to
Yoast/Rank Math).

### 17.4 Elementor Pro path and the fallback without Pro

**a. Post type for Pro.** Public + `show_in_nav_menus` makes **Rooms**
appear in Theme Builder (Single → Rooms → All / specific rooms) and as a
Loop Grid / Loop Carousel query source. Ordering uses Pro's own
"Menu order" option = the room's display order.

**b. Current room.** One helper, used by every tag, widget and shortcode:
explicit room setting/attribute → the post being rendered when it is a
room (Loop item, Theme Builder single, room page) → the queried room →
Elementor's preview post for the template → in the editor only, the first
room as sample data. Outside rooms with no setting, room tags return
empty values (no PHP notices, no broken markup).

**c. Dynamic tags** (group "Flexo Booking"), each with an optional manual
room selector ("Current room" by default):

| Tag | Elementor category | Use in |
|---|---|---|
| Room name, Short description, Full description, View, Beds | Text | Heading, Text Editor |
| Size, Max guests, Max adults, Max children | Text + Number | Heading, Text Editor, Counter |
| Amenities | Text (HTML list with icons / comma list / *n*-th amenity) | Text Editor; Icon List items via "*n*-th amenity" |
| "From" price | Text (formatted with currency; empty when hidden or unknown) | Heading, Button text |
| Featured image | Image | Image, backgrounds |
| Gallery | Gallery (featured + gallery, in order) | Gallery, Image Carousel |
| Room page URL | URL | Button, any link |
| Room booking link | URL (current room automatic; booking page path from Settings → Hotel, overridable; optional dates/guests from the page address) | Button, any link |

The amenities icon list is best done with the **Room amenities** widget
(an Icon List repeater cannot be filled by one tag).

Free Elementor lists tags registered by plugins in its dynamic-tag picker
(checked in the Elementor 4.4 source), so all these tags also work
**without Pro**; only Theme Builder and Loop Grid/Carousel need Pro.

**d. Theme Builder.** If a published single template with a condition
matching the room exists, Pro renders it. The plugin's default room page
only takes over when Pro is inactive or no template matches (checked
through Pro's conditions manager, not only filter priority).

**e. Loop Grid / Loop Carousel.** Normal Pro queries with source "Rooms".
Hidden and demo rooms are removed from **front-end** room queries in
`pre_get_posts`; the booking engine's own queries set a flag so they still
see hidden rooms (they must stay bookable). Each Loop item renders with
its room as the current post, so every tag shows that room's data.

**f. Starter templates** in `assets/elementor/`: a Theme Builder **single
room** template and a **Loop item room card**, built only with dynamic tags
and the plugin's widgets, no fixed colours or fonts (they inherit the
kit's global styles). They can be imported under *Templates → Import*;
with Pro active, a button on the Rooms screen installs them in one click
(the single template gets the condition *Rooms*). Built and checked in the
real Pro editor if Pro is available (§17.7).

**g. Multilingual.** Rooms become translatable post types in Polylang and
WPML. A translation is a separate post holding only texts (name,
descriptions, view, custom amenities, SEO, slug). **The booking engine
always works on the default-language room** (prices, units, seasons,
bookings, iCal), so translations never become extra rooms in the booking
form or double the inventory. Room names shown to a guest use the
translation in the guest's language. WPML gets a `wpml-config.xml`
(booking fields copied, texts translated). Polylang is tested; WPML is
not (paid plugin).

**h. Without Pro (free Elementor, or Pro licence lapsed).**
- **Default room page** used whenever no Theme Builder template applies:
  classic themes get a plugin template (`get_header()` + room page +
  `get_footer()`, overridable as `flexo-booking/single-room.php` in the
  theme); block themes get a plugin-registered block template (header and
  footer template parts around the room page). Elementor Pro header/footer
  templates still apply around it.
- **Widgets that work in free Elementor**: Rooms list, Room booking box,
  Room gallery, Room facts, Room amenities, Room description, Room price –
  each auto-detects the current room or lets you pick one.
- **Shortcodes**: `[flexo_rooms]`, `[flexo_room_booking]`, `[flexo_room]`
  (whole default room page), `[flexo_room_gallery]`, `[flexo_room_facts]`,
  `[flexo_room_amenities]`, `[flexo_room_description]`,
  `[flexo_room_price]`, `[flexo_room_field field="size"]`,
  `[flexo_room_link type="booking|page"]` – all with an optional `room=""`.
- **Optional "room page layout"** (Q5): a normal page designed once in free
  Elementor with these widgets, used as the layout for every room – the
  no-Pro equivalent of a Theme Builder single template.
- Deactivating Pro: room pages fall back to the layout page or the default
  page; buttons using the plugin's tags keep working; Loop Grid sections
  disappear (they are Pro widgets) – documented, with `[flexo_rooms]` as the
  replacement.

### 17.5 Room pages (section D)

- **URLs**: `/{base}/{slug}/`, base from settings (default `rooms`),
  path-based so they survive moving the site. No post-type archive at the
  base: most templates already have a "Rooms" page at `/rooms/` and an
  archive would hide it. The **All rooms page** is that page (auto-detected:
  a page containing `[flexo_rooms]`, the Rooms list widget or a Loop Grid
  with source Rooms, else a page at the base path); the Room pages tab
  offers **Create the rooms page** when none exists.
- **Clashes**: if a static page exists at `/{base}/{slug}/` (old template
  child pages) and no room has that slug, the static page is still served;
  Health warns about such pages.
- **Default room page**: gallery, name, key facts (size, beds, guests),
  description, amenities, "from" price, booking box (F), rates summary
  (meals + cancellation line, Day 6 style), cancellation info, link back
  to all rooms. Uses the booking form's CSS and the Appearance settings
  (Match my website / Custom), responsive, Day 6 accessibility rules.
- **Hidden rooms**: 404 (not a redirect – documented), `noindex`, left out
  of the WordPress sitemap and of Yoast/Rank Math sitemaps. Demo rooms:
  visible only to logged-in staff (Q7).
- **Structured data**: JSON-LD `HotelRoom` with `Offer` (from price,
  currency), occupancy, bed, floor size, amenities, `containedInPlace` the
  hotel (name, address, phone from Settings → Hotel). Without an SEO plugin
  it is printed on its own; with **Yoast** or **Rank Math** it is added to
  their schema graph through their filters instead (one graph, no second
  WebPage), and skipped if Rank Math already has a HotelRoom/Product schema
  on that room.
- **SEO title/description**: output only without Yoast/Rank Math.

### 17.6 Listings, booking box, "from" price, links, templates (E–J)

**E. Room listings.** Rooms list widget and `[flexo_rooms]`: grid or list,
columns per device, all / selected rooms, order (display order, name,
price), fields to show (image, name, short description, facts, amenity
icons, from price, buttons), button texts. Cards: **View room** → room
page (hidden when room pages are off); **Check availability / Book now** →
booking page with the room preselected. Optional live availability: when
the page address carries dates (search bar set to "this page"), a small
script asks the existing `/availability` endpoint **once** and fills each
card (Rooms list cards and Loop items via an "Availability for chosen
dates" tag); cached pages keep working because the HTML stays the same.

**F. Booking box.** Compact form (Day 6 date picker, guests) bound to the
room; checks the existing `/availability` endpoint with `room=` (the same
code as the booking flow, so totals match); shows availability and the
total; **Book now** opens the booking page with room, dates, guests (and
the rate when the room has only one), and Day 6's prefill skips the
answered steps. Unavailable → nearby dates and other rooms (Day 6
`/alternatives`). On room pages, in Theme Builder templates, as a widget
and as `[flexo_room_booking room=""]`. Optional sticky "Check
availability" bar on phones.

**G. "From" price – definition.** The lowest **average price per night** a
guest can actually get for the room in the next 12 months: for every
arrival day that is not in a closed period and allows arrival, a stay of
the room's minimum length is quoted by the real pricing service (seasons,
weekend prices, base price) for **2 adults** (or the room's maximum if
lower) with the **base rate** (the first rate the room offers; none if it
offers none). Tourist tax and promo codes are not included (shown in the
booking flow). Availability is not considered (a "from" price describes
the room type). Because every candidate is a real quote, the booking flow
will honour it. A cheap scan finds the candidates; the best ones are
confirmed with the real quote. Cached per room (`_flexo_from_price`),
recalculated in the background when a room, season, closed date, rate,
child price or currency setting changes, and daily (the 12-month window
moves). Setting: show per night, or hide.

**I. Linking buttons.** Elementor: Link → dynamic tag **Room booking link**.
Plain links: `/booking/?room=deluxe-double` with optional
`&check_in=2026-07-01&check_out=2026-07-04&adults=2&children=1&children_ages=5`
(documented); room page `/rooms/deluxe-double/`. Old slugs keep working
for both. Each room has **Copy booking link** and **Copy room page link**
(room editor and Rooms list).

**J. Templates, moving sites, demo rooms.**
- README guide: converting a template's static room sections to Loop Grid
  room list + Theme Builder single room + dynamic booking buttons (with and
  without Pro).
- Import/Export (schema 4): content fields, gallery (image URLs + alt
  texts), view, custom amenities, SEO, hidden flag, display order. With
  "Download room images" ticked, featured and gallery images are
  re-downloaded; otherwise rooms show the placeholder and the room screen
  offers **Download missing images** later. Missing images never cause
  errors.
- **Create demo rooms** (Rooms screen): 3 rooms with placeholder texts and
  bundled neutral placeholder images, marked *Demo* everywhere in the
  admin, visible only to logged-in staff on the website, **never
  bookable** (the booking engine skips demo rooms) until the hotel edits
  the room and switches off "Demo room". **Remove demo rooms** deletes them
  in one click (refused for any room that has bookings).

### 17.7 Risks and how they are handled

| Risk | Handling |
|---|---|
| **Elementor Pro is not in this environment** (the brief's zip path was not filled in; downloads from wordpress.org/GitHub are blocked here) | Please send the zip path (Q8). Without it: the post-type settings, tags, widgets and shortcodes are tested with **free Elementor** (rendering, programmatically built pages and loops), and these Pro parts stay **untested in the real Pro editor**: Theme Builder conditions UI, Single template rendering, Loop Grid/Carousel with source Rooms, Pro's Menu-order ordering, starter-template import into Pro. |
| Pro installed but not licence-activated | Elementor shows "Connect & Activate" for some Pro features without a licence; if that blocks Theme Builder here, I'll say exactly which steps could not be run. |
| Free Elementor's editor can't be opened here (the test copy is a source checkout without built editor files; building needs Node 24) | I'll try building it from source; if that fails, editor UI checks (tag picker, widget panels) are done in your Pro editor and listed as untested. |
| Public URLs on upgraded sites clash with existing pages or appear in search engines unexpectedly | Room pages feature off after upgrade (Q1); static child pages still served; Health warning; no archive at the base. |
| Hidden rooms disappearing from the booking engine | Hiding only applies to front-end listing queries; engine queries are flagged; tests cover staff booking and links for hidden rooms. |
| Translations becoming extra rooms or doubling inventory | Engine uses the default-language room only; Polylang tests for the booking form, availability, calendar and iCal with translated rooms. |
| "From" price showing a price the booking flow won't give | Every value comes from a real quote of a bookable stay; recalculated on every price change; hidden when no valid value. |
| Cost of calculating "from" prices (365 quotes per room) | Background job (WP-Cron single event), cheap scan + few real quotes, cache; never on a guest's request. |
| Duplicate SEO output / schema with Yoast or Rank Math | Our SEO fields and schema step aside or join their graph. Yoast and Rank Math cannot be downloaded here – tested with stand-ins that mimic their hooks; must be checked on a real site. |
| Theme compatibility of the default room page | Classic themes (Hello, Twenty Twenty-One) and a block theme (Twenty Twenty-Five) tested; theme override file documented. |
| Rewrite rules | Flush only on activation, migration, base change or feature switch (flag + one flush). |
| Starter templates breaking with future Elementor versions | Built with the installed Pro version, only core widgets + plugin widgets + dynamic tags, no custom CSS; versions documented. |
| Scope | Delivered in parts, each tested and committed (§17.9). |

### 17.8 Questions for approval

- **Q1 – Room pages after upgrade.** New feature "Room pages": on for new
  sites, **off on upgraded sites** until the hotel switches it on (avoids
  sudden public URLs and clashes with existing static room pages). OK?
- **Q2 – "All rooms" URL.** No automatic archive at `/rooms/`; the All
  rooms page is the hotel's own page (auto-detected or created with one
  click). OK?
- **Q3 – Hidden rooms.** Public page → **404** (with `noindex`), not a
  redirect. OK?
- **Q4 – "From" price.** Definition in §17.6 G (minimum-length stay,
  2 adults, first rate, without tourist tax and promo codes, availability
  ignored, closed periods excluded). OK?
- **Q5 – Room page layout without Pro.** Add the optional "room page
  layout" (a page designed once in free Elementor, used for every room)?
  Or keep only the built-in default page?
- **Q6 – Multilingual model.** Rooms become translatable; translations
  hold texts only and the engine uses the default-language room. OK?
- **Q7 – Demo rooms** visible only to logged-in staff on the website and
  never bookable until edited. OK?
- **Q8 – Elementor Pro zip.** The brief's path was left as `[PATH TO ZIP]`
  and no zip is on this machine. Please upload it (and, if you want them
  tested for real, Yoast SEO and Rank Math zips – their downloads are
  blocked here). Is testing Pro without licence activation acceptable?

### 17.9 Order of work and tests

1. Post type, migration 8, feature + settings, routing (URLs, hidden,
   old slugs/bases), room editor (A) – commit.
2. Default room page, booking box, "from" price, schema/SEO (D, F, G) –
   commit.
3. Rooms list widget/shortcode, fallback widgets and shortcodes, live
   availability (E, H) – commit.
4. Dynamic tags, Theme Builder/Loop support, starter templates (B, C) –
   commit.
5. Links/copy buttons, demo rooms, Import/Export, Polylang/WPML (I, J) –
   commit.
6. Language pass (EN/BG), tests, regression, docs (README guides listed in
   the brief), zip 1.8.0.

Tests (`tests/test-day7.php`, `tests/e2e/day7.js`): migration from 1.0.0 /
1.5.0 / 1.7.0 fixtures; old booking links and slugs; Pro tests listed in
the brief if Pro is available (else marked untested); default room page
on a classic theme, a block theme and with free Elementor; fallback
widgets and shortcodes auto-detecting the room; Pro deactivation; booking
box totals equal the booking flow, step skipping, alternatives; "from"
price with seasons, closed periods and price changes; Rooms list layouts,
field toggles, ordering; slug and base changes; Polylang; schema
validity and no duplicates with the SEO stand-ins; phones at 360/390/768;
Import/Export to a fresh site with room content; full regression of
Days 1–6.

### 17.10 Revision after your answer: Elementor Pro only

Your answer: the plugin will only run with **Elementor Pro** (no free
version), and a **single room template already exists** in Theme Builder.
What it needs is each room's information fetched dynamically: name,
description, amenities, gallery, capacity, room type, size and the rest.

Changes to the plan:

1. **First part = what your template needs.** Part 1 of the work is now:
   the room fields (A), the public post type with room URLs (so the
   template's condition *Rooms* applies), and the **dynamic tags** for every
   field (B), tested inside a Theme Builder single template. Your existing
   template then only needs each widget pointed at the matching tag.
2. **New field "Room type"** (e.g. Double room, Suite, Apartment, Studio,
   Family room) as a **taxonomy** `flexo_room_type`: one or more types per
   room, a "Room type" dynamic tag, and usable by Pro's Loop Grid query and
   Taxonomy Filter (e.g. "show only suites"). Translatable with
   Polylang/WPML. Rooms without a type simply show nothing there.
3. **Fields and tags your template can use** (all for "the current room"
   automatically):

   | Template element | Tag | Typical widget |
   |---|---|---|
   | Room name | Room name | Heading |
   | Room type | Room type | Heading / Text Editor |
   | Short description | Short description | Text Editor |
   | Full description | Full description | Text Editor |
   | Gallery (featured photo + gallery, in your order) | Room gallery | Gallery, Image Carousel |
   | Main photo | Room featured image | Image, section background |
   | Capacity | Max guests / Max adults / Max children (text or "Up to 4 guests (max. 2 adults)") | Heading, Text Editor, Icon List item |
   | Size | Room size ("24 m²" or number only) | Heading, Icon List item |
   | Beds, view | Beds, View | Heading, Icon List item |
   | Amenities | Amenities (list with icons, comma list, or *n*-th amenity) + **Room amenities** widget (icon list filled automatically) | Text Editor, Icon List, widget |
   | "From" price | From price (with currency, per night) | Heading, Button text |
   | Links | Room page URL, Room booking link | Button, any link |
   | Availability | **Room booking box** widget (dates, guests, live price, Book now) | widget |

4. **Dropped (not needed without free Elementor):** the free-Elementor
   fallback widgets (gallery, facts, description, price), the optional
   "room page layout" (Q5 → no) and the Rooms list widget/shortcode – room
   lists are built with Pro's Loop Grid / Loop Carousel. Kept: the Room
   booking box and Room amenities widgets (your template needs them), the
   `[flexo_room_booking]` and `[flexo_room_field]` shortcodes for plain
   text areas, and a **simple default room page** only as a safety net
   when no Theme Builder template matches a room (e.g. before the
   template's condition is set).
5. **Testing needs Elementor Pro here.** I still need the Elementor Pro
   zip to test inside the real Pro editor and Theme Builder. It would also
   help to have your single room template exported (Templates → Theme
   Builder → ⋮ → Export), so I can import it on the test site and connect
   each widget to the right tag exactly as you built it.
6. Questions Q1–Q4, Q6 and Q7 (§17.8): unless you say otherwise I follow
   the proposals there.

Revised order of work: (1) room fields incl. room type, public post type,
URLs, room editor, dynamic tags, Room amenities widget – tested in a
Theme Builder single template; (2) Room booking box widget + "from" price;
(3) Loop Grid/Carousel support (hidden rooms, order, live availability
tag), starter templates; (4) links/copy buttons, old slugs, demo rooms,
Import/Export, Polylang/WPML, schema/SEO; (5) language pass, tests,
README, zip 1.8.0. One commit per part.

### 17.11 Decisions after reviewing your single room template (approved direction)

Your answers: everything included and switched on (no feature switch, no
"off after upgrade"), as user-friendly as possible for the owner and the
guest, Elementor Pro only, and the **Jet plugins can be removed** – one
plugin for all room data.

What the template showed: it is a Theme Builder single template whose
preview type is `single/rooms`, i.e. rooms are a **JetEngine post type
"rooms"** and room data comes from JetEngine custom-field tags
(`price_per_night`, `room_size`, `max_guests`, `beds_info`,
`long_description`, `amenity-1` … `amenity-8`). The gallery (Media
Carousel), the room amenity icons, the "other rooms" carousel and the
booking buttons are static. So today the same room exists twice (JetEngine
post + Flexo Booking room).

Decisions:
1. **Room pages are always on** (Q1 → no feature switch). Upgraded sites
   get room pages immediately; static pages under the room base still
   win where no room has that slug, and Health warns about clashes
   (e.g. a JetEngine "rooms" post type still registered on the same base).
2. **Amenities**: one sortable list per room, each item = text + icon.
   One-click predefined amenities (translated text + bundled icon) and
   the owner's own amenities ("Sea-facing terrace", "Rain shower with
   view"), each with an icon from the bundled set or an uploaded
   SVG/PNG. Existing Day 6 amenity ticks are converted automatically.
3. **More details**: optional rows of icon + label + value per room
   (e.g. "Floor – 2nd", "Bathroom – rain shower"), so the owner can add
   anything else; shown by a Room details widget and a "Room detail" tag.
4. **Room type** taxonomy (as in §17.10).
5. **Widgets for the template**: Room amenities (icon list with each
   amenity's own icon, styled like Elementor's Icon List), Room details,
   Room gallery (carousel or grid, slides per view, height, lightbox –
   matches the Media Carousel look) and Room booking box. The gallery tag
   also works in Elementor's Gallery and Image Carousel widgets.
6. **Moving rooms out of JetEngine**: *Rooms & prices → Bring in rooms*
   reads the old room posts (even with JetEngine deactivated), suggests
   the field mapping (`long_description` → full description, `room_size`
   → size, `max_guests` → max guests, `beds_info` → beds,
   `price_per_night` → price if the room has none, `amenity-*` →
   amenities with matching icons, gallery field, featured image, slug),
   shows a preview, then creates or updates the Flexo Booking rooms with
   the **same slugs**, so with the same base (`rooms`) the room addresses
   stay the same. Afterwards JetEngine can be removed.
7. **Your template, converted**: a copy of your JSON with every room
   element wired to Flexo Booking (tags and widgets, no Jet tags), the
   preview set to Flexo Booking rooms, buttons using the Room booking
   link, the "other rooms" nested carousel replaced by a **Loop Carousel**,
   plus a matching **Room card** loop item template. Delivered as files
   to import (not bundled in the plugin; the plugin bundles generic
   starter templates).

### 17.12 Day 7 status, deviations and test results (1.8.0, migration 8)

Built in the revised order of §17.10, one commit per part (parts 1–4),
then the language pass, tests and docs (part 5).

| Area | Files |
|---|---|
| Migration 8 (additive) | New settings `room_base`, `rooms_page`, `room_price_display`, `room_sticky_bar`; rewrite rules flushed once (option `flexo_booking_flush_rewrite`); nothing removed. Day 6 amenity ticks are read as ready-made amenities. |
| Room content (A) | `class-room-content.php` (taxonomy `flexo_room_type`, gallery, view, amenities with icons, More details, hidden/demo, SEO texts, `room()` view, `current_id()`), `class-room-icons.php` + `includes/data/room-icons.php` (126 Lucide icons, ISC), `admin/class-room-editor.php` + `assets/css/admin-room.css` / `assets/js/admin-room.js` (card editor), `class-rooms.php` (public post type, Prices card, list columns, `bookable()`). |
| Elementor Pro (B, C) | `elementor/class-room-tags.php` (15 tags, group *Flexo Booking: room*), `class-room-link-tag.php`, `class-room-widgets.php` (amenities, details, gallery), `class-room-box-widget.php`, `class-templates.php` (one-click starter templates with the condition *Rooms*), `assets/elementor/*.json`, `bin/elementor-starter-templates.py`, `bin/convert-jet-room-template.py`, `elementor-templates/` (client template converted). |
| Room pages (D) | `class-room-pages.php` (base, old bases/slugs, static-page fallback, hidden/demo 404, robots, sitemaps, default page for classic and block themes, settings tab), `class-room-render.php` + `templates/single-room.php` + `assets/css/rooms.css` / `assets/js/rooms.js` (lists, gallery, lightbox, phone bar, availability), `class-room-seo.php` (title, description, JSON-LD alone or inside Yoast / Rank Math), `class-room-i18n.php` + `wpml-config.xml`. |
| Booking box, "from" price (F, G) | `class-frontend.php` (`[flexo_room_booking]`, layout `box`), `templates/room-booking-box.php`, `booking.js` (RoomBox), `class-room-prices.php` (cache + `flexo_booking_prices_changed`). |
| Links, demo rooms, Import/Export (I, J) | Room booking link (current room, booking page from Settings, keeps searched dates), copy buttons, old slugs; `admin/class-demo-rooms.php`; `class-portability.php` (schema 6: room page content, photos downloaded once, *Download missing photos*); `admin/class-room-importer.php` (Bring in rooms from JetEngine, also with JetEngine off). |
| Health, uninstall, language | Health check for address clashes; uninstall removes the Day 7 options, cron event and room types; 457 new Bulgarian strings, `.po` / `.mo` / `.l10n.php` regenerated. |

**Deviations and decisions while building**

1. **Room gallery widget uses its own carousel** (CSS scroll-snap + a small
   script), not Elementor's Swiper: it works the same with and without
   Pro's assets, needs no extra library on the page and respects
   *prefers-reduced-motion*. The Room gallery **tag** still feeds Pro's
   Gallery / Image Carousel / Media Carousel widgets for those who prefer
   them.
2. **Default room page on block themes** is a registered block template
   (`flexo-booking//single-flexo_room`) with a dynamic block
   `flexo-booking/room`; a shortcode block was tried first but `wpautop`
   broke the booking box layout.
3. **Availability tag is filled in the browser** with one request for all
   rooms on the page, so cached pages (and Loop Grid pagination) keep
   working; without dates in the address it prints nothing.
4. **Room types are not publicly queryable** (no `/room-type/…` archive
   pages): lists are built with Loop Grid queries and taxonomy filters, so
   there is no thin archive page for search engines.
5. **Hidden rooms are left out of lists only.** Found in the regression
   run: the filter that removes hidden rooms from front-end queries also
   hit lookups by slug outside the admin (WP-CLI and REST imports), so
   re-importing a hidden room created a duplicate and imported bookings,
   closed dates and promo-code limits of a hidden room lost their room.
   Lookups of one room (`name` or `p`) now always find it, and the
   importer's queries pass `flexo_all_rooms`. Covered by `test-day7.php`.
6. **The Rooms list widget/shortcode was dropped** (§17.10): lists use
   Pro's Loop Grid / Loop Carousel with the Room card template.
7. `tests/upgrade-fixture.php` used the phone number `123`, which 1.6.0+
   rejects, so on 1.6/1.7 sites the fixture made only one of its four
   bookings; it now uses a real number (the plugin is unchanged).
8. Two older tests depended on the date or on leftovers of other tests,
   not on the plugin: `e2e/day2.js` clicked a day 20 days ahead in the
   current month's calendar (now opens that day's month), and
   `test-day6.php` expected "no booking page" while the Day 1 browser seed's
   Elementor booking page was still published (now drafts Elementor pages
   with the form too).

**Tests (MariaDB 10.11, PHP 8.x, WordPress 7.2-alpha, Elementor 4.4 free)**

| Suite | Result |
|---|---|
| `test-day7.php` | 193/193 |
| `test-day7-elementor.php` | 83/83 |
| `polylang-day7.php` (Polylang, BG main + EN) | 25/25 |
| `e2e/day7.js` (room page, booking box, phone bar, 360/390/768, availability tags, hidden room, room editor 1440/390) | 50/50 |
| Days 1–6 PHP (`run.sh`: parity 8, seasons 46, features 30 + 6, regression 43, iCal 66, Day 3 122, Day 4 93, Day 5 258 (Stripe stand-in), Day 6 108, i18n 18, three concurrency races) | all passed |
| `polylang-test.php` | 21/21 |
| Upgrade 1.0.0 / 1.5.0 / 1.7.0 → 1.8.0 | 27/27 each; room pages answer at once after the upgrade |
| Portability: base 11 + re-import 3, calendars 12, Day 3 4 + 21, full 7 + 30 | all passed |
| Browser tests Days 1–6 | Day 1 37/37, Day 2 51/51, Day 3 63/63, Day 4 49/49, Day 5 167/167 (Stripe stand-in), Day 6 117/117 |

**Not tested, because Elementor Pro is not available in this environment**
(the zip was never provided in the session): importing the starter and
converted templates into Pro's Theme Builder and their display condition;
the one-click template installer with Pro active (`save_item` + conditions);
Loop Grid / Loop Carousel with the Room card (query source *Rooms*, hidden
rooms left out, order, "exclude current post"); Pro's own widgets fed by
the room tags (Gallery, Media Carousel, Image Carousel, Nested Accordion);
`pro_template_applies()` against Pro's conditions manager; the editor's
*Preview settings* with a room; Pro's AJAX Loop pagination. All tags and
widgets were checked with Elementor 4.4 (free, from source) rendering a
template document with each room as the global post, which is what Pro
does. Yoast SEO and Rank Math were not installed either: the structured
data hand-over was tested through their filters (`wpseo_schema_graph`,
`rank_math/json_ld`) and sitemap hooks only.

---

## 18. 1.9.0 plan: system pages (Booking, Thank You, Contact)

Status: **approved; Session A done (see 18.13); Sessions B and C waiting.** Written after
auditing 1.8.3 (plugin header `Version: 1.8.3`, migrations at 8). Sections
refer to the brief's letters (A–AP).

### 18.1 What already exists (reused, not rebuilt)

| Need in the brief | Already in 1.8.3 | 1.9.0 change |
|---|---|---|
| Built-in Booking route (C) | `Flexo_Booking_Frontend::builtin_page()`: when `/booking/` (or the booking-page path) is a 404, serves the booking form in `templates/page-shell.php` (theme header/footer, block themes, canvas), own title, noindex; `/booking/` redirects to a real booking page elsewhere | Becomes the first of three system pages; gains settings, layouts, sections, SEO, cache rules |
| One booking renderer (C, L, M) | `Flexo_Booking_Frontend::render()` serves the Elementor widget `flexo-booking-form`, `[flexo_booking]`, the built-in page and the room box | Unchanged; the system page only wraps it |
| Guest field settings (D) | `field_phone` (required/optional/hidden), `field_notes` (optional/hidden), privacy consent and invoice features; fields printed by `templates/booking-form.php` | Add label / placeholder / help / order on top; existing keys stay authoritative |
| Secure guest access (E, T, L3) | `Flexo_Booking_Guest::key()` (HMAC, no storage), `check()` (signed key or payment key), `view()` (the confirmation view model incl. payment state, bank details, next steps, contact, `.ics`, manage link), REST `guest-booking`, `payment`, `booking.ics` | The Thank You page renders `Guest::view()` server-side; no second lookup |
| Custom thank-you page (E) | Setting `thank_you_url` (path): after a confirmed booking the guest is sent to `thank_you_url?booking=REF` (reference only); bank transfer stays on the booking page (details shown there) | Kept as "Custom WordPress page" mode, unchanged behaviour |
| Inline confirmation (E) | Booking page shows the confirmation and keeps `?fb_done=REF&fb_key=KEY` in the address so it survives a refresh | Kept as "Show confirmation inside booking form" (see decision 3) |
| Card return / webhook pending (S) | Stripe returns to the booking page (`fb_payment`, `fb_ref`, `fb_key`), the form polls `payment`, shows "still checking", retry, expiry, conflict | Kept; only the *last* step (confirmed) may go to the Thank You page |
| `booking_complete` once (X) | `trackComplete()` fires once per reference (localStorage) **before** any redirect | Unchanged – the Thank You page never fires it |
| REST nonces (AN, V) | Public routes are **nonce-free** (validation, honeypot `fb_website`, per-IP limits `flexo_rl_*`) – already cache-safe | Contact uses the same pattern |
| Enquiries (I–K, W, Y) | `Guest::enquiry()`: `flexo_booking_log` row (`booking_id` 0, action `enquiry`, details JSON incl. `status` open/handled), hotel email with Reply-To, honeypot, rate limit, Today → *Needs your attention* + *Mark as answered*, deleted after `retention_months` (default 12), WP personal-data export/erase by email | Contact messages are enquiries with `details.kind = contact` (availability enquiries get `kind = availability` when missing). **No new table.** |
| Owner texts in languages (U) | Untouched texts translated by gettext; changed texts registered with Polylang/WPML (`I18n::translatable_strings()` / `translate()`) | Page texts join the same list |
| Appearance (O) | CSS variables on `.flexo-booking`, Custom/Match modes, bundled + Elementor global + typed fonts, panel settings, preview | Extended in Session C |
| Overlay headers (AO) | `clearOverlayHeader()` in booking.js moves plugin pages below a header lying over them | Becomes the "auto" part of the header setting |
| Health (Z) | Checks incl. `booking_page` (warning while the built-in page is used), `room_pages` (address clashes) | New checks below |
| Page caching (AN) | **Nothing** (only admin/export call `nocache_headers()`) | New |
| SEO for plugin routes (AP) | Title + noindex on the built-in booking page only | New |

### 18.2 Architecture

**Routing (A, B) – "soft routes" on the 404 path, no rewrite rules.**
Extends the 1.8.3 mechanism. At `template_redirect` priority 0, when WordPress
found **nothing** (`is_404()`), the requested path is compared with the
enabled system pages' slugs (with an optional language prefix as today).
- **Existing content always wins:** pages, posts, CPTs and room pages are
  resolved by WordPress first.
- **Why not rewrite rules:**
  - A rule placed *above* WordPress's page rules would hide an existing page.
  - A rule placed *below* them never matches, because the page rule catches
    every path.
  - So no rewrite rule is needed, and nothing is ever flushed (no flush on
    slug change either).
- **On a match:**
  - `is_404` → false, status 200, the page template;
  - headers (cache, referrer), SEO and header-compatibility hooks;
  - WordPress's "guess a similar page" redirect is avoided because we run
    first.
- **Old slugs:** kept in a list; a 404 on an old slug gets a 301 to the
  current one. Loops are impossible because the target is always a current
  slug different from the old one.
- **Paths:** trailing slashes follow `user_trailingslashit()`.
- **Collisions:**
  - At save and in Health, a slug is checked against published pages/posts,
    other post types' rewrite slugs, the room base and the other system
    pages.
  - A clashing system page simply never activates at that path (the
    existing page wins), and the Pages screen and Health say so with the two
    fixes.

**New classes and files**

| File | Role |
|---|---|
| `includes/class-system-pages.php` – `Flexo_Booking_System_Pages` | Registry of the three pages (`booking`, `thank_you`, `contact`): mode (built-in / own page), enabled, slug, old slugs, resolution (incl. Polylang/WPML page translation via `I18n::page_url()`), routing, collisions, headers, SEO, header compatibility, rendering through `page-shell.php` |
| `includes/class-confirmation.php` – `Flexo_Booking_Confirmation` | Thank You context: one-time hand-off token, signed cookie, view model = `Guest::view()` + section config + state headings; one renderer for the built-in page, the shortcode and the widget (Session C) |
| `includes/class-forms.php` – `Flexo_Booking_Forms` | Field definitions and config: booking details (Session A) and contact fields (Session B); sanitising per type |
| `includes/class-contact.php` – `Flexo_Booking_Contact` (Session B) | Contact form renderer and submission; storage, email and rate limiting **shared with `Guest::enquiry()`** (refactored into one internal method, not copied) |
| `includes/class-page-cache.php` – `Flexo_Booking_Page_Cache` | No-cache markers and cache-plugin APIs, detection for Health |
| `includes/admin/class-pages-admin.php` | Settings → **Pages** tab (cards, Page / Content / Sections / Layout / Appearance) |
| `templates/system-page-booking.php`, `system-page-thank-you.php`, `system-page-contact.php`, `confirmation.php`, `contact-form.php`, `forms-tabs.php` | Small presentation templates fed with prepared view models; `booking-page.php` (1.8.3) is replaced by `system-page-booking.php` (theme overrides of the old name still honoured) |

Changed: `class-frontend.php` (route moves to System_Pages; render options
for title/intro/steps/summary/help), `class-rest.php` (Thank You hand-off
URL in the booking/payment responses), `class-guest.php` (enquiry storage
shared; kind), `class-settings.php` (migration of field labels; Pages tab
registered), `class-health.php`, `class-portability.php`, `class-i18n.php`,
`class-appearance.php` (Session C), `class-migrations.php`, booking.js
(redirect to Thank You hand-off; tabs and contact in B/C), booking.css,
admin assets, Elementor (Session C).

### 18.3 Data model and migration (AC)

- **No new tables, no schema change.**
- **Settings:**
  - one new option `flexo_booking_pages` holds the pages' modes, slugs, old
    slugs, layouts, sections and order, texts, header compatibility and
    per-page appearance;
  - one new option `flexo_booking_forms` holds the booking field extras and
    the contact fields.
  - Both have their own sanitiser that **merges with the saved values**, as
    `Flexo_Booking_Settings::sanitize()` already does, so saving one card
    never resets another.
- **Migration 9 (settings only, idempotent, additive)** decides the
  starting state. `Migrations::run()` remembers the version it started from
  (0 = fresh install):

| | Fresh install (from 0) | Upgrade from 1.8.x |
|---|---|---|
| Booking | Built-in, `/booking/` | Own page if `booking_page` is set or a page with the form is found (kept exactly); otherwise built-in at the same address (what 1.8.3 already does) |
| After booking | Separate Thank You page (built-in, `/thank-you/`) | `thank_you_url` set → Separate page, **own page** = that URL (unchanged behaviour); otherwise **inline** (unchanged). Built-in Thank You **disabled** |
| Contact | Built-in, `/contact/` (Session B) | **Disabled** |
| Field texts | Defaults (translated) | Current behaviour; empty label = default text |
| Appearance | Unchanged | Unchanged |

A fresh install whose slug is already taken (for example a demo-content
`/contact/` page) starts with that system page inactive at the clashing path.
Health explains the fix.

### 18.4 Thank You flow (E, S, T)

| Booking type | Built-in Thank You (separate mode) | Own Thank You page (legacy `thank_you_url`) | Inline mode |
|---|---|---|---|
| Request | REST response carries the hand-off URL; JS fires `booking_complete`, then goes there | As today (`?booking=REF`) | As today |
| Instant | Same | As today | As today |
| Bank transfer | Hand-off → Thank You shows amount, bank details, reference and deadline (from `Payments::guest_view`) | As today: stays on the booking page with bank details | As today |
| Card | Stripe still returns to the **booking page**, which keeps handling cancel, retry, expiry and conflict; only when the server reports `confirmed` does it go to the hand-off URL. If the guest lands on Thank You while the webhook is pending, they see "Your payment is being confirmed", and the page re-checks automatically every 5 s for up to 2 minutes, then explains what happens next | As today | As today |

**Hand-off and context (security, decision-free part):**
- **The hand-off token:**
  - 32 random bytes;
  - single use, stored as a transient for 10 minutes that maps it to the
    booking ID;
  - created only by the booking/payment REST response for the guest who
    just booked.
- **The guest's long-term key never appears in the Thank You URL.**
- **`/thank-you/?fb_t=TOKEN`:**
  - the token is checked and deleted;
  - the cookie `flexo_booking_ty` is set:
    - value `booking_id.expires.HMAC(wp_salt)`;
    - `HttpOnly`, `Secure` on HTTPS, `SameSite=Lax`;
    - path = the Thank You path;
    - **60 minutes**;
  - then a 303 redirect to the clean `/thank-you/`.
- **On every Thank You response:**
  - `Referrer-Policy: no-referrer`;
  - `X-Robots-Tag` / meta `noindex, nofollow`;
  - never in sitemaps;
  - no-cache (18.5).
- **No valid cookie** (expired, forged, a reference guessed or typed in the
  address): the generic thank-you text only. The emailed *Manage your
  booking* link stays the long-term way back.
- **No personal data in any generated URL.** A test asserts this for every
  link the plugin builds.
- **`booking_complete`:** fired once on the booking page before leaving,
  as now. The Thank You page never fires it, so a refresh or Back can't
  double-count it.

**Sections and texts:**
- **Sections (shown when applicable, in an owner-defined order with
  Up/Down buttons):**
  - booking status, reference, room, dates, guests, rate plan;
  - price breakdown, total, payment status, amount paid, amount remaining;
  - bank transfer, what happens next, hotel contact, address;
  - add to calendar, directions, manage booking, back to website.
- **Editable texts:** headings per state, intro, next-steps title and text,
  help title, button labels.
  - Allowed placeholders: `{hotel_name}`, `{reference}`, `{check_in}`,
    `{check_out}`.
  - Sanitised with `wp_kses_post()`; no PHP.
- **Layouts:** Card, Summary and Split. All collapse to one column below
  768 px.

### 18.5 Caching (AN)

- **Thank You and every response showing a booking:**
  - `nocache_headers()` and `DONOTCACHEPAGE` / `DONOTCACHEOBJECT`;
  - LiteSpeed `litespeed_control_set_nocache`;
  - WP Rocket `rocket_cache_reject_uri` (the Thank You path);
  - W3 Total Cache and WP Super Cache honour `DONOTCACHEPAGE`.
  - Their pages are rendered from the cookie on the server, so a cached copy
    could only ever be the generic version. The headers make sure even that
    is not stored.
- **Booking and Contact pages:** see decision 1.
- **Health:** a warning when a known cache plugin is active without an API
  we could use, with the rules to add by hand.
- **README:** Cloudflare and server rules (bypass the Thank You path and
  `?fb_` URLs).
- **REST:** already nonce-free, so expired nonces can't break submissions
  on cached pages.

### 18.6 SEO (AP)

- **Booking and Contact:**
  - title "Page title – Site name" through `pre_get_document_title`, Yoast
    `wpseo_title` and Rank Math `rank_math/frontend/title` (otherwise those
    plugins would show the home title);
  - editable meta description;
  - our own `rel=canonical`, plus Yoast and Rank Math canonical filters;
  - indexable by default, switchable;
  - added to the WordPress core sitemap through a small sitemap provider;
    for Yoast/Rank Math through their extra-URL filters where available,
    otherwise documented.
- **Thank You:** always `noindex, nofollow`, no canonical, never in
  sitemaps.

### 18.7 Header compatibility (AO)

- **Setting** in Settings → Pages: "Header style above Flexo pages".
  1. **Normal** (default). The current automatic check stays: when a header
     lying over the page is detected, a hint appears in Pages and Health.
  2. **Space for an overlay header.** Top space per device, or *Automatic*
     (the 1.8.3 measurement).
  3. **Title band behind the header.** Page title on a colour or an image,
     with a height per device.
- **Applies to:** all system pages and the plugin's plain room page.
- **Elementor Pro Theme Builder:**
  - System pages are not posts, so on them *Entire Site* header/footer
    conditions apply, and *Singular → Page* conditions don't. To make them
    targetable, a Pro condition "Flexo Booking pages" (General → Flexo
    Booking pages) would be registered through
    `elementor/theme/register_conditions`.
  - **This can't be verified here without Elementor Pro** (decision 7).

### 18.8 Admin (A, AF)

- **Where:** a new Settings tab **Pages**, next to *Room pages*.
- **Cards** for Booking, Thank You and Contact, each showing: status in
  plain words, mode, address, **Open / Preview**, **Customize**.
- **Customize opens:** Page (source, slug, enabled) · Content (title,
  intro, texts) · Sections (show/hide, order) · Layout · Appearance (Use
  global / overrides, Session C).
- **Global settings:** header style, and "After a booking" (separate page /
  inline).
- **Advanced settings** (slugs, SEO switches) sit behind the existing
  *Show advanced settings* switch.

### 18.9 Health (Z)

New checks:
- **Each system page:** OK, or a warning when its own page is missing,
  unpublished, or (for a custom Thank You page) lacks the confirmation
  component.
- **Slug collisions:** with an explanation and the fixes.
- **Cache plugin without automatic exclusion:** the rules to add by hand.
- **Overlay header:** a hint when one is detected.

### 18.10 Sessions, files and tests

**Session A**
- **Brief sections:** A, B, C, D, E, F, S, T, AN, AO, AP, Z, AA, AC.
- **Files:**
  - new: `class-system-pages.php`, `class-confirmation.php`,
    `class-forms.php` (booking part), `class-page-cache.php`,
    `admin/class-pages-admin.php`, the booking and thank-you templates;
  - changed: migration 9, REST hand-off, booking.js, CSS.
- **Tests:**
  - `tests/test-system-pages.php`: brief items 1–16, 45–49 and 73–75;
  - `tests/test-confirmation.php`: items 28–44 (incl. the two-guests
    cache test);
  - `tests/test-booking-fields.php`: items 17–27;
  - `tests/e2e/day8.js`: built-in booking page, booking → clean Thank
    You, inline mode, bank transfer, card return via the Stripe stand-in,
    Back, widths 360–desktop, overlay header;
  - the full existing suite.

**Session B**
- **Brief sections:** G, H, I, J, K, W, X, Y, plus Z/AA/AC for these parts.
- **Files:** `class-contact.php`, the forms contact part,
  `templates/contact-form.php`, the contact system page; the REST route
  `/contact`; Today labels; privacy export/erase (already by email).
- **Tests:** `tests/test-contact.php`: items 50–72; e2e contact flows.
- **Tabs:** the Booking & Contact tabs layout lands in Session B only if
  the shared tabs component is clean there, otherwise in Session C.

**Session C**
- **Brief sections:** L, M, N, O, AF, AB, U, AJ, AK, AI/AL.
- **Work:** widgets and shortcodes, tabs, Appearance extension and per-page
  overrides, import/export, translations, docs, version 1.9.0, zip.
- **Tests:** items 76–87 and the full Definition of Done run.

### 18.11 Decisions needing your answer (conflicts with the brief or the current code)

1. **Booking and Contact pages and caching (AN).** These pages contain no
   private data (the booking form is filled in by the browser, and the
   REST calls need no nonce), so serving them from a page cache is safe and
   makes them faster.
   - **Proposal:** never cache Thank You, inline confirmations and any
     manage-booking response (headers, `DONOTCACHEPAGE`, plugin APIs).
   - **Leave Booking and Contact cacheable,** with a setting to switch
     caching off if a hotel wants it.
   - The brief asks to never cache them. Which do you prefer?
2. **No rewrite rules / no flushes (B).** The 404-path routes in 18.2 give
   "existing page always wins" automatically and never need flushing. The
   brief mentions flushing on slug changes; this design doesn't need it.
   Agree?
3. **The guest key in the address in inline mode (E).** Inline mode (kept
   for upgraded sites) and the Stripe return keep `fb_key` in the address
   today.
   - **Proposal:** after the page has read it, remove it from the address
     bar (history replace) and keep it in the tab's sessionStorage, so a
     refresh still shows the confirmation.
   - Also send `Referrer-Policy: same-origin` with built-in booking pages.
   - This changes current behaviour slightly (a copied address no longer
     shows the booking). OK for Session A?
4. **Card payments (S).** Stripe keeps returning to the booking page, and
   only a server-confirmed payment moves on to Thank You. Sending Stripe
   straight to Thank You would mean re-implementing cancel, retry and
   expiry there. Agree?
5. **Booking page "Enabled/Disabled" (C).** Without a booking page every
   *Book now* breaks.
   - **Proposal:** Booking can't be disabled, only switched between
     built-in and your own page.
   - Thank You and Contact get the on/off switch.
6. **Own Thank You page (E).** The page selector stores a page ID, and the
   old `thank_you_url` path keeps working as the fallback (upgraded sites).
   On an own page, booking details appear only through the *Flexo Booking
   Confirmation* widget or shortcode (Session C), with the same cookie
   security. Until then it shows the page as designed. OK?
7. **Elementor Pro / Azure (AO, AI).**
   - **Testing on the Azure template with the Pro Theme Builder header at
     1440/1024/768/390 needs the Elementor Pro zip and a copy of the Azure
     site** (or network access to a staging copy).
   - Without them I test with Hello, a simulated overlay header and
     Elementor free, and list Pro as untested.
   - The Pro condition "Flexo Booking pages" would be written but unverified.
   - Yoast and Rank Math are also not available: tested through their
     filters only.
8. **Where the screen lives (A).** The new tab **Pages** sits next to
   *Room pages*, which stays as it is. Alternatively Room pages could become
   a fourth card in Pages. Preference?
9. **Retention (W).** Contact messages use the existing
   `retention_months` (default 12), the same as availability enquiries. OK?
10. **Translated slugs.** Out of scope as allowed: `/booking/` and
    `/bg/booking/` style only.

### 18.12 Risks

- **Other plugins acting on 404s before `template_redirect` priority 0.**
  Very rare; Redirection and Rank Math's 404 monitor act later or log only.
  Covered by a Health check that requests each system URL and reports
  anything other than 200.
- **Themes printing their own title on non-singular requests.** The page
  shell already avoids the theme's single template.
- **Cache plugins that ignore `DONOTCACHEPAGE`.** Handled by the plugin
  APIs, a Health warning and the docs.
- **Extra work:** migration 9 must decide the upgrade state exactly once.
  It is covered by upgrade tests from 1.0.0, 1.5.0, 1.7.0 and 1.8.3 fixtures
  (the 1.8.3 fixture with an own booking page, a `thank_you_url`, a
  `/contact/` page and a `/thank-you/` page).

### 18.13 Session A: status, deviations and test results

Built as planned in 18.2–18.10, with the approved answers of 18.11.
The version stays **1.8.3** until Session C (AK); migrations are at **9**.

**What already existed and was reused**
- The 1.8.3 built-in `/booking/` route: became the System Pages router.
- `Flexo_Booking_Frontend::render()`: the only booking form, on the built-in page too.
- `Flexo_Booking_Guest::view()` and `Payments::guest_view()`: the Thank You page's data.
- Payment return and polling in booking.js; `trackComplete()` (once per reference).
- The nonce-free public REST API.
- The `field_phone` / `field_notes` settings.
- `page-shell.php`, `clearOverlayHeader()`, `I18n::page_url()` / `translate()`.

**New files**

| File | Role |
|---|---|
| `includes/class-system-pages.php` | Registry, option `flexo_booking_pages` (merge sanitiser, slug history), URLs per language, collisions, routing on the 404 path, serving, booking-page view model, header styles, overlay report (REST `overlay-header`, admins only), sitemap, Yoast sitemap, Elementor Pro condition |
| `includes/class-confirmation.php` | Hand-off token, cookie, booking context, Thank You view model, sections/order/texts/layouts renderer, the hotel's preview with sample data |
| `includes/class-forms.php` | Option `flexo_booking_forms`: booking field labels, example texts, help texts and order (`flexo_booking_guest_fields` filter) |
| `includes/class-page-cache.php` | No-cache headers, `DONOTCACHEPAGE` & co., LiteSpeed action, WP Rocket rejected URIs, detection of caching plugins for Health |
| `includes/class-page-seo.php` | Titles, description, canonical, robots (core, Yoast, Rank Math filters) |
| `includes/class-sitemap-provider.php` | WordPress core sitemap of Booking (and Contact) |
| `includes/elementor/class-system-page-condition.php` | Pro Theme Builder condition *General → Flexo Booking pages* (loaded only with Pro) |
| `includes/admin/class-pages-admin.php`, `assets/js/admin-pages.js` | Settings → **Pages** tab |
| `templates/system-page-booking.php`, `system-page-thank-you.php`, `confirmation.php` | Presentation only (view models prepared by the classes) |

**Changed:**
- `class-frontend.php`: the route moved out; the search bar defaults to the Booking page; config for the overlay report and texts.
- `class-guest.php`: booking page URLs come from the system pages; `detect_booking_page_id()` (database only, for the migration).
- `class-rest.php`: hand-off URL for bookings and confirmed payments.
- `class-migrations.php`: migration 9 (remembers fresh vs upgrade).
- `class-settings.php`: Pages tab; Hotel tab points to it.
- `class-health.php`: system page checks; system report.
- `class-i18n.php`, `class-room-pages.php`.
- `admin/class-wizard.php`: the built-in page is the first choice.
- `admin/class-admin-ui.php`, `admin/class-today-admin.php`: links use the page guests get.
- `elementor/class-booking-widget.php`.
- `templates/booking-form.php`: fields come from `Flexo_Booking_Forms`, with the same ids and names.
- `booking.js`, `booking.css`, `admin.css`, `uninstall.php`, languages (+188 strings, BG complete).

**Removed:**
- `templates/booking-page.php`, replaced by `system-page-booking.php`. Theme copies of the old name are still used.
- `Flexo_Booking_Frontend::builtin_page()`, moved to `Flexo_Booking_System_Pages::route()`.

**Migration 9 (settings only, idempotent):** as in 18.3. The pages are
found in the database only, because migrations run before WordPress can
build addresses.
- Fresh install: built-in Booking and Thank You (*separate*), Contact on.
- Upgrade: an own booking page set or found is kept; `thank_you_url` gives *separate* + own page; otherwise *inline*. Thank You and Contact are off.

**Routes:**
- `/booking/` and `/thank-you/` (slugs editable; `/xx/` language prefix; `?flexo_page=` on plain permalinks).
- Old slugs → 301; the built-in slug → 302 to the own page.
- `/thank-you/?fb_t=…` → 303 to the clean address.
- REST `POST flexo-booking/v1/overlay-header` (manage_options).
- Sitemap `wp-sitemap-flexobooking-pages-1.xml`.

**Deviations from the plan and the brief (please review)**
1. **Own booking page is an explicit choice.** 1.8.3 adopted any page with the form automatically. Now a new page with the form is used once it is chosen under Settings → Pages (upgrades keep the page found at migration time; Health and the Hotel tab show the page in use and mention a page with the form that is not chosen).
2. **The built-in Booking page is indexable by default** (brief AP); in 1.8.3 it was noindex. Health shows it as OK instead of a warning.
3. **"Normal" header style keeps the 1.8.3 automatic move** below a header lying over the page (rather than literally nothing), so existing sites look as before. The overlay hint comes from an administrator's own visit (the browser reports it; there is no server-side way to know).
4. **Own (legacy) Thank You page:**
   - It also gets the one-time token: one extra 303 hop to the same `?booking=REF` address.
   - It is never cached, so the Session C widget can use the same cookie.
   - It still shows only the page as designed.
5. **The old `thank_you_url` setting alone no longer switches the mode.**
   - Upgrades are migrated, and the field moved to Pages → Thank You → own page (advanced).
   - Code that writes the raw option after the upgrade must also set `after_booking` (one browser test did this and was updated).
6. **Guest key in the address (decision 3):**
   - Removed for the inline confirmation (`fb_done`) and the Stripe return.
   - Kept in "Manage your booking" email links (T: no redesign); those pages get `no-store` + `no-referrer` instead.
7. **Contact is not routed in Session A.**
   - The option, migration and collision checks already know it.
   - Its card, route and tests come in Session B.
   - The Pages screen's Appearance part is a pointer to Bookings → Appearance (O is Session C).
8. **Rank Math sitemap:** no integration (no stable filter verified); documented as "add by hand".
9. **Logged-in editors** see a draft page at the same address instead of the built-in page. This is WordPress's normal draft preview; visitors are not affected.

**Tests (MariaDB site, Twenty Twenty-Five):**

| Suite | Result |
|---|---|
| `test-system-pages.php` (new) | 112 passed |
| `test-confirmation.php` (new, incl. two guests behind a page cache) | 70 passed |
| `test-booking-fields.php` (new) | 36 passed |
| `tests/run.sh` (all PHP suites, i18n, constants, 3 concurrency races) | see 18.13 final run below |
| `e2e/day8.js` (new) | 46 passed |
| e2e days 1–7 | see below |
| Upgrade 1.0.0 / 1.5.0 / 1.7.0 / 1.8.3 → Session A (with own booking, thank-you and contact pages) | 34 passed each |
| Fresh install | built-in Booking + Thank You (separate) + Contact on, no early-translation notices |
| Polylang site | `polylang-test.php` 21, `polylang-day7.php` 25; built-in pages in Bulgarian at `/pll-book/` and English at `/en/pll-book/` |

Updated existing tests (intended 1.9.0 behaviour):
- `test-booking-page.php`: indexable; Health OK; an own page is used once chosen.
- `test-day5.php`: runs as an upgraded site.
- `test-day6.php`: missing own page → warning; the wizard chooses the page.
- `test-day7.php`: migration ≥ 8.
- `e2e/day4.js`: the own thank-you page chosen under Pages; the redirect address has `fb_t`.
- `e2e/day6.js`: `fb_done` without `fb_key`.
- `polylang-test.php`: the redirect has `fb_t`.
- `upgrade-*.php`: pages.
- Seeds: `t_legacy_pages()`.

**Not verified here (needs the client environment)**
- Elementor Pro Theme Builder with the Azure template at 1440/1024/768/390. Elementor Pro is not available here; an overlay header was simulated in the browser test.
- The *Flexo Booking pages* condition, Yoast SEO, Rank Math, LiteSpeed Cache and WP Rocket. Their hooks and filters are called directly in the tests, without the plugins installed.

**Manual QA for Session A**
1. On a staging copy of a live site, update and open **Settings → Pages**:
   - Booking shows your page.
   - "After a booking" is unchanged.
   - Thank You is off.
   - `/contact/` and `/thank-you/` show your pages.
2. Switch to **Separate Thank You page**, choose *Flexo built-in page*, switch it on, save, and make a test booking:
   - the address is `/thank-you/` without anything after it;
   - Back and refresh are fine.
3. Open `/thank-you/` in a private window: you get only the general text.
4. With a bank transfer booking: bank details on the Thank You page.
5. With Stripe test mode: the card returns to the booking page, then Thank You with *Payment received*.
6. **Preview with a sample booking**: try every case, reorder sections, switch layouts.
7. With the Azure header, try the three header styles at 1440/1024/768/390. In Elementor Pro, check which header conditions show and try *Flexo Booking pages*.
8. With LiteSpeed / WP Rocket / Cloudflare: check that `/thank-you/` is never served from the cache (response headers) and that Health lists no manual rule, or add the listed rules.
