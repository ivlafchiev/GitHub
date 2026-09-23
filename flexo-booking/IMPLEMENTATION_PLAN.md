# Flexo Booking: implementation plan (Days 1–5)

Status: **Day 1 done (1.1.0).** Plan approved; implementation, tests and
docs are complete. See §9 for what was built, the deviations and the test
results. Days 2–5 follow the schema and pipeline below. Read together with
`ROADMAP.md`.

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

- **Occupancy has one source of truth: the bookings table.** Anything that
  takes a room is a row there: guest bookings, staff blocks, iCal imports
  (Day 2) and payment holds (Day 5). Availability code therefore never needs
  restructuring.
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
- **Imports mirror into the bookings table.** Each imported event becomes a
  blocked booking row, so availability, the admin calendar and conflicts work
  unchanged.
- **Conflicts.** When an import overlaps a room that is already full, the event
  is still stored (it's a real external booking). It gets `conflict=1`, an
  admin notice and a hotel email.

### 2.5 Day 3: rate plans, children, tourist tax, promo codes

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
`hold_expires_at`. There is no separate holds table. The reasons:

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
| 20 | **Rate-plan adjustment** (percent / per night / per stay) | `rate_plans` | 3 |
| 30 | **Occupancy and children.** Extra adults above the base occupancy, then child age-band rules: free, fixed per night, or % of the adult price. | `children` | 3 |
| 40 | **Promo discount.** Applies to lines 10–30, never to tax. | `promo_codes` | 3 |
| 50 | **Tourist tax.** Per person per night, age-exempt, with `collect` = booking or property. | `tourist_tax` | 3 |
| 90 | **Totals.** Round each line to 2 decimals, then sum, so the breakdown always adds up exactly. | core | 1 |
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
| `rate_plans` | Rate plans | Offer options such as "Breakfast included" or "Non-refundable". | Day 3 |
| `children` | Children & ages | Ask for children's ages and charge by age. | Day 3 |
| `tourist_tax` | Tourist tax | Add the municipal tourist tax per person per night. | Day 3 |
| `promo_codes` | Promo codes | Give discounts with codes such as SUMMER10. | Day 3 |
| `privacy_consent` | Privacy consent | Ask guests to accept your privacy policy and remove old guest data automatically. | Day 4 |
| `invoice_request` | Invoice request | Let guests ask for an invoice with company details. | Day 4 |
| `guest_emails` | Guest emails | Email guests when their booking is received, confirmed or cancelled. | 1.0 |
| `tracking` | Conversion tracking | Send booking events to Google Analytics / Tag Manager / Meta. | Day 4 |
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
