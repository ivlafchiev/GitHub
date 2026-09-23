# Flexo Booking

A room and accommodation booking system for **FlexoHotels** websites built with WordPress and Elementor Pro: hotels, guest houses, villas, resorts and campsites.

Guests pick dates and guests, see which rooms are free with the price, enter their details and get a booking reference by email. The hotel manages everything under **WP Admin → Bookings**.

It's one plugin that works with any theme or template. Extra features are switched on only where a property needs them, so a simple guest house still gets just *dates → room → details → booking request*.

> Version **1.2.0**. Development follows `ROADMAP.md`; technical design is in `IMPLEMENTATION_PLAN.md`.

---

## Contents

1. [Installing and upgrading](#1-installing-and-upgrading)
2. [Features: what the hotel can switch on](#2-features-what-the-hotel-can-switch-on)
3. [Creating rooms](#3-creating-rooms)
4. [Setting prices](#4-setting-prices)
5. [Seasonal prices](#5-seasonal-prices)
6. [Closed dates](#6-closed-dates)
7. [Calendar sync (Booking.com, Airbnb…)](#7-calendar-sync-bookingcom-airbnb)
8. [The booking calendar](#8-the-booking-calendar)
9. [Putting the booking form on the site](#9-putting-the-booking-form-on-the-site)
10. [Daily use: managing bookings](#10-daily-use-managing-bookings)
11. [Templates and moving between sites](#11-templates-and-moving-between-sites)
12. [Settings reference](#12-settings-reference)
13. [For developers](#13-for-developers)
14. [Testing](#14-testing)

---

## 1. Installing and upgrading

**Requirements:**
- WordPress 6.0+ and PHP 7.4+. MySQL or MariaDB is recommended.
- Elementor 3.5+ for the widget (Elementor Pro works too). Without Elementor, the `[flexo_booking]` shortcode still works.

**Install:**
1. Build the zip with `bin/build-zip.sh`, or use the provided `flexo-booking-1.2.0.zip`.
2. Go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. A **Bookings** menu appears in the admin.
4. Check **Settings → General → Timezone**. It must be the hotel's city, because "today" and arrival dates depend on it.

**Upgrade from 1.0.0 or 1.1.0:** upload the new zip and choose **Replace current with uploaded**.
- The database updates itself on the next page load. No reinstall is needed, and rooms, bookings and settings are kept.
- If an update step ever fails, a red notice appears in the admin and the step is retried automatically.
- On staging or in scripts you can also run `wp flexo-booking migrate`.
- After upgrading, **Seasonal prices** and **Calendar sync** are off. Switch it on under **Bookings → Settings → Features** if the hotel needs it.

---

## 2. Features: what the hotel can switch on

Features have two levels:

| Level | Who decides | Where |
|---|---|---|
| **Available** | FlexoHotels (you) | `wp-config.php` constant, or the hidden **Bookings → Agency** screen |
| **Enabled** | The hotel admin | **Bookings → Settings → Features** |

The hotel only ever sees features you made available. Switching a feature off hides it from guests, but never deletes its settings or data, and never changes existing bookings.

### The hotel's Features tab

**Bookings → Settings → Features** lists each feature in plain language:

| Feature | What it does | Status |
|---|---|---|
| How do guests book? | **Booking requests** (you confirm) or **Instant booking** | Ready |
| Seasonal prices | Different prices and minimum stays for high and low season | Ready (1.1.0) |
| Guest emails | Emails to guests when a booking is received, confirmed or cancelled | Ready |
| Calendar sync | iCal sync with Booking.com, Airbnb, Vrbo… | Ready (1.2.0) |
| Rate plans, Children & ages, Tourist tax, Promo codes | | Coming soon |
| Privacy consent, Invoice request, Conversion tracking | | Coming soon |
| Online card payment, Deposits, Bank transfer | | Coming soon |

"Coming soon" features are listed greyed out, so hotels can see what's planned. To hide them completely, make them unavailable.

### Controlling what a hotel can use (agency level)

**Option A: `wp-config.php`.** This is the strongest option; the hotel can't change it from the admin.

```php
// Only these features exist on this site ("all" = everything):
define( 'FLEXO_BOOKING_FEATURES', 'booking_request,guest_emails,seasonal_pricing' );
```

**Option B: the Agency screen.** List your own logins or emails in `wp-config.php`:

```php
define( 'FLEXO_BOOKING_AGENCY_USERS', 'flexoadmin,support@flexohotels.com' );
```

Those users (and nobody else, not even the hotel's Administrators) see **Bookings → Agency**. There they tick the available features, or click *Reset* to make everything available. If `FLEXO_BOOKING_FEATURES` is set, the screen is read-only.

**Option C: WP-CLI**, for setting up many sites:

```bash
wp flexo-booking features list
wp flexo-booking features available booking_request,guest_emails,seasonal_pricing   # or "all" / "reset"
wp flexo-booking features enable seasonal_pricing
wp flexo-booking features disable guest_emails
```

With nothing defined, everything is available. Feature keys: `booking_request`, `instant_booking`, `seasonal_pricing`, `calendar_sync`, `rate_plans`, `children`, `tourist_tax`, `promo_codes`, `privacy_consent`, `invoice_request`, `guest_emails`, `tracking`, `online_payment`, `deposit`, `bank_transfer`.

*Note:* these switches are a product boundary for normal hotel admins, not a security boundary. Anyone who can edit `wp-config.php` or install plugins can change them.

---

## 3. Creating rooms

Go to **Bookings → Rooms → Add room** and create one entry per **room type** (not per physical room).

| Field | Notes |
|---|---|
| Title, description, excerpt, featured image | Shown in the search results (the excerpt is the short line) |
| **Price per night** | The normal price (see §4) |
| **Weekend price per night** | Optional. Used for Friday and Saturday nights. |
| **Max guests** | Adults and children together |
| **Number of rooms of this type** | E.g. 5 identical doubles means `5`. Enter `0` to stop selling this type. |
| **Minimum nights** | Optional. Overrides the global minimum. |
| Order | Lower numbers are listed first |

Each room has a **slug** (e.g. `deluxe-double`), shown in the Rooms list. "Book now" links and the Elementor widget use it, so it stays the same when the site is copied. To change it, open the room, click **Screen Options**, tick **Slug**, and edit it.

---

## 4. Setting prices

Every price is calculated on the server, in one place: the pricing service. The guest sees the same number as the booking, the email, the admin list and the CSV. Each booking stores its price breakdown, so later price changes never change existing bookings.

How a night is priced:

1. If **Seasonal prices** is on and the night falls in a season of that room, the **season's** price is used. On Friday and Saturday nights, the season's weekend price is used if it has one.
2. Otherwise the room's **normal price** is used, or its **weekend price** on Friday and Saturday nights.

The stay total is the sum of its nights. When nights have different prices, guests see the average per night on the room card and an itemised list before they confirm, e.g. *Low season: 2 nights × 80.00 € · High season: 3 nights × 150.00 €*.

**Currency** is set under **Settings → General**:
- Code (default **EUR**; Bulgaria uses the euro since 01.01.2026)
- Symbol and its position (4 options)
- Number format (e.g. `1 234,50`)
- Decimals

Changing the currency **never converts** prices; update the room and season prices yourself. Existing bookings keep their own currency, so a booking made in BGN still shows `200.00 BGN`.

---

## 5. Seasonal prices

Switch on **Settings → Features → Seasonal prices**. A **Bookings → Seasonal prices** screen appears.

1. Choose the **room** at the top. The page shows its normal price (used on dates without a season).
2. **Add a season**:
   - Name (e.g. *High season*)
   - **From** and **To (last night)**, both as DD.MM.YYYY. A date picker opens on click.
   - Price per night
   - Optional weekend price (Fri/Sat nights)
   - Optional minimum stay
3. Seasons appear in a table with Edit and Delete. Past seasons are greyed out.

Rules:
- **Dates are nights.** 01.07.2026 – 31.08.2026 includes the night of 31 August (check-out 1 September).
- **Stays across seasons** are priced night by night, e.g. 2 nights low + 3 nights high.
- **Outside every season** the room's normal price applies.
- **Minimum stay** comes from the season of the **arrival** night. Without one, the room's minimum applies, then the global minimum. Guests see e.g. *"Minimum stay 3 nights for arrivals in High season."*
- **No overlaps.** Two seasons of the same room can't overlap; the form says which season clashes and keeps what you typed. Different rooms may use the same dates.
- **Copy to next year** (bottom of the screen) copies the seasons starting in the chosen year, for this room or all rooms. It keeps prices and minimum stays, moves 29.02 to 28.02, and handles seasons that span New Year. Seasons that would overlap an existing one are skipped and listed.

The room edit screen shows a summary of current and upcoming seasons, with an **Edit seasonal prices** button.

Switching Seasonal prices off makes new quotes use the room prices again. Seasons stay saved for later, and existing bookings keep their price.

---

## 6. Closed dates

**Bookings → Closed dates** is always available, because closing the property is about availability, not prices.

- **Applies to:** *Whole property* (e.g. closed for the winter) or a single room type (e.g. renovation of all apartments of one type).
- **From / To (last night)** in DD.MM.YYYY, and an optional **message for guests**.
- Guests can't book any night inside the period. They see e.g. *"We are closed from 1 November 2026 to 31 March 2027. Please choose other dates."* Guests may still check out on the first closed day.
- **Copy to next year** repeats the periods (e.g. every winter).
- Staff can still record a booking inside a closed period with **Add booking**.
- To take **one** room out of service, use **Add booking → Block dates** instead: it takes one unit, not the whole type.

---

## 7. Calendar sync (Booking.com, Airbnb…)

Calendar sync reduces the risk of double bookings when a property also sells on Booking.com, Airbnb, Vrbo or similar sites. It uses standard **iCal links**, which every booking site supports. It is not a channel manager: prices and room details are still managed on each site separately.

Switch it on under **Settings → Features → Calendar sync**. A **Bookings → Calendar Sync** screen appears with one card per room. Each card has:

- **This room's calendar link**, which you give to Booking.com, Airbnb and so on
- **Calendars imported into this room**, where you paste their links

### Step by step: Booking.com

1. **Bookings → Calendar Sync**, room card → **Copy link**.
2. In the Booking.com **Extranet**: **Rates & Availability → Sync calendars** (shown only for room types that allow calendar sync).
3. Choose **Import calendar**, paste the link, give it a name (e.g. "Website") and save. Booking.com now blocks dates booked on your website.
4. On the same Booking.com page, choose **Export calendar** and copy Booking.com's link.
5. Back on **Calendar Sync**, under "Calendars imported into this room": Name *Booking.com*, paste the link, **Add calendar**. It syncs immediately and shows **✓ OK** and the number of bookings.

### Step by step: Airbnb

1. **Copy link** for the room, as above.
2. In Airbnb: **Calendar →** select the listing **→ Availability → Connect to another website** (sometimes called *Import calendar*). Paste the link, name it, save.
3. On the same Airbnb screen, choose **Export calendar** and copy Airbnb's link.
4. On **Calendar Sync**: Name *Airbnb*, paste the link, **Add calendar**.

Do the same for Vrbo, Google Calendar or any site that offers an iCal (`.ics`) link. Links starting with `webcal://` work too.

### How it behaves

- **Imported bookings block availability.** Guests can't book those nights on your website. Imported bookings have no guest details; they are managed on the booking site.
- **Check-out day stays free.** A Booking.com stay from 10 to 13 October blocks the nights of 10, 11 and 12; a new guest can arrive on the 13th.
- **Changes follow automatically.** A moved booking is updated (matched by its unique ID, never duplicated). A cancelled booking disappears and **its dates are released**.
- **Automatic checks** run every 30 minutes by default (15 or 60 selectable at the top of the screen). **Sync now** works per calendar, **Sync this room now** per room, and **Sync all now** for everything. Each check waits at most 15 seconds per calendar, and **one broken link never stops the others**.
- **Errors are explained in plain words**, e.g. *"The calendar link no longer exists (HTTP 404)…"* or *"The link opened a web page, not a calendar…"*. If a calendar fails 3 times in a row, a red notice appears on every admin screen. When a download fails, the previously imported bookings are **kept**, not released.
- **Your export link contains only dates** (booked, blocked, closed). It never contains guest names, emails, phone numbers or prices. It includes website bookings, staff bookings and blocks, closed periods, and bookings imported from your *other* calendars, so every site learns about every other. **Reset link** makes the old link stop working; paste the new one into the booking sites.
- **Delay.** iCal is not instant: this website checks every 15–60 minutes, and Booking.com or Airbnb read your link on their own schedule (often every few hours). A double booking is still possible in that window. If you sell on booking sites, use **Booking requests** mode (Settings → Features) so you confirm each website booking yourself.

### Rooms with several identical units

For a room type with, say, 3 identical apartments:

- **Default: "One room of this type".** Each imported booking takes **one** of the 3 units. Three overlapping imported bookings fill the room type.
- **"Room no. 2"** (chosen when adding the calendar) is for calendars that belong to one specific apartment, e.g. its own Airbnb listing. Bookings from all calendars linked to the same number take that unit **once per night**. So if Airbnb repeats a Booking.com booking of apartment 2, it isn't counted twice.

### Conflicts (possible double bookings)

If an imported booking doesn't fit, meaning the room type is already full on those nights with website, staff or other imported bookings, it is marked as a **conflict**:

- a **⚠ Conflict** box at the top of **All bookings**, with a badge on the affected website booking
- **⚠** on both bookings in **Bookings → Calendar**, and a notice on every admin screen
- **one email** to the notification address (Settings → Emails), naming the room, calendar, dates and overlapping booking reference

Resolve it with the guest or the booking site, then click **Mark as reviewed**. Cancelling one of the bookings clears the conflict automatically.

*Airbnb "echo":* Airbnb's own export also contains dates it imported from your website, labelled *Airbnb (Not available)*. If such a copy lands on exactly the same dates as your website booking, the conflict note says so (*"Same dates as FB-… – the other website may be repeating your own booking back"*), so you can simply mark it as reviewed.

### Switching Calendar sync off

Syncing stops, the **Calendar Sync** screen disappears, and your export links answer "not found". Imported bookings **no longer block** your website's availability. Your connections and the last imported bookings are kept, and switching it on again restores everything at the next sync.

---

## 8. The booking calendar

**Bookings → Calendar** is the hotel's overview. It shows one month, with rooms as rows and days as columns.

- **Today at a glance** (top): who is **arriving today**, **leaving today** and **staying tonight**.
- **Bars** start in the afternoon of arrival and end in the morning of departure, so a departure and an arrival on the same day sit side by side.
- **Every type has its own colour, icon and pattern**, so it's readable without relying on colour. The legend is under the calendar:

| Looks like | Meaning |
|---|---|
| ✓ green | Confirmed website booking |
| ⏳ yellow, dashed | Pending request waiting for you |
| ✎ | Booking added by staff |
| ⛔ grey, hatched | Dates blocked by staff |
| ⇄ blue, striped | Booking from an external calendar (Booking.com, Airbnb…) |
| ⚠ red border | Conflict – possible double booking |
| ✕ dotted, hatched days | Closed period |
| ⊘ struck through | Cancelled (only with "Show cancelled" ticked) |

- **Rooms with several units** show "free of total" under every day (e.g. *2/3*). A full day shows *0/3* in red.
- **Click a booking** for its details: guest, dates, total and status. Pending bookings can be **confirmed**, bookings **cancelled** and blocks **removed** right there, and conflicts **marked as reviewed**.
- **Click an empty day** to see how many units are free, then **New booking** or **Block dates**. The *Add booking* form opens with the room and dates filled in.
- **‹ Today ›** moves between months.
- **On a phone** the page stays still and only the calendar scrolls sideways, starting at today. Details open in a window that fits the screen.

The calendar is for inventory and reservations only. It has no housekeeping or room assignment.

---

## 9. Putting the booking form on the site

1. **Booking page (required):** edit your *Booking / Reservations* page in Elementor, search the panel for **"Flexo"** and drag in **Flexo Booking Form** (*FlexoHotels* category). Keep *Layout* set to **Full booking form**. Note the page path (default `/booking/`).
2. **Hero search bar (optional):** add the widget on the home page with *Layout* **Search bar** and *Booking page path* `/booking/`.
3. **"Book now" buttons:** on the Button widget's *Link*, choose the dynamic tag **Flexo Booking → Room booking link**, then pick the room. Or type `/booking/?room=<slug>`.
4. **Without Elementor**, use the shortcodes:
   - `[flexo_booking]`
   - `[flexo_booking room="deluxe-double"]`
   - `[flexo_booking layout="search" booking_page="/booking/"]`

**Styling:** the form picks up the site's **Elementor Global Colors and Fonts**, so it matches each template automatically. To override:
- For one widget, use its **Style** tab.
- Site-wide, add CSS such as `.flexo-booking { --fb-primary: #b08d57; --fb-radius: 0; }`.
- To change the markup, copy `templates/booking-form.php` or `templates/search-bar.php` to `wp-content/themes/<theme>/flexo-booking/`.

**Test:** make a booking in a private window and check both emails arrive. If they don't, install an SMTP plugin such as WP Mail SMTP. If you use Cloudflare "Cache Everything", bypass `/wp-json/*`.

---

## 10. Daily use: managing bookings

**Bookings → All bookings** has filters by status and room, "Current & upcoming only", and search by reference, name, email or phone. Each booking shows its total and, when nights had different prices, the itemised breakdown.

| Status | Meaning | Holds the room? |
|---|---|---|
| Pending | Request waiting for you | Yes |
| Confirmed | Confirmed | Yes |
| Cancelled | Cancelled | No |
| Blocked | Dates closed by staff | Yes |

Actions on each booking:
- **Confirm** emails the guest.
- **Cancel** frees the room and emails the guest.
- **Reinstate** works only if the room is still free.
- **Delete** removes the booking.

Each booking shows where it came from: **Website**, **✎ Added by staff** or **⛔ Blocked dates**. With Calendar sync on, a second tab, **⇄ From external calendars**, lists the imported bookings (read-only), and a **⚠ Conflict** box appears at the top when something needs attention (see §7).

**Add booking** records phone, walk-in or other-channel bookings, or blocks dates. It is also reachable from empty days in the **Calendar**. **Export CSV** includes a *Price details* column.

**Who can do what:**
- Editors and Administrators can manage bookings, the calendar, seasonal prices, closed dates and calendar sync.
- Only Administrators can change Settings and use Import / Export.

**No double bookings:** availability is counted night by night against the number of rooms. The final check, price calculation and save happen under a per-room lock. When two guests try to take the last room at the same moment, one gets it and the other is told it's no longer available.

---

## 11. Templates and moving between sites

| Part | Where it lives | How it moves |
|---|---|---|
| Plugin code | `wp-content/plugins/flexo-booking` | Install the zip, or it comes with a full-site clone |
| Widget placement and styling | Elementor page/template data | Elementor template/kit export |
| Rooms, **seasons, closed dates**, settings, enabled features | Posts, plugin tables, options | **Bookings → Import / Export** (JSON) or WP-CLI |
| Calendar connections (Booking.com/Airbnb links) | Plugin table | In the export file, but imported **only when ticked** (moving the same hotel) |
| Bookings | `wp_flexo_bookings` table | Full-site migration, or export with "include bookings" |

**A. Prepare a template (once):**
1. Install the plugin.
2. Add demo rooms, their seasons and any closed dates.
3. Switch on the features the template should ship with.
4. Place the widgets.
5. **Import / Export → Download export file** and keep the file with the template.

**B. New client site from a template:**
1. Clone the site, or install the plugin **before** importing the Elementor kit.
2. **Import / Export → Import** the template's file.
3. Adjust rooms, prices, seasons and the **notification email**. The notification email is never exported, so the new site uses its own admin email.
4. Make a test booking.

How the import behaves:
- Rooms are matched by **slug**, so re-importing updates them and never duplicates.
- A room's seasons in the file **replace** that room's seasons.
- Closed dates are added unless an identical one exists.
- Enabled features are applied only if they're available on the new site.
- Calendar connections are **not** imported unless you tick *Import calendar connections* (or use `--calendars`). A template's Booking.com links belong to the template, not to the client. Export links are never copied: every site creates its own, so paste the new links into the booking sites after moving a hotel.
- Files from 1.0.0 and 1.1.0 still import.

**C. Add-on sale:**
1. Install the plugin on the client site.
2. Import the template file.
3. Adjust rooms and settings.
4. Check the widgets are in place.
5. Test.

**D. Moving a live site:** use your normal migration tool; the bookings table moves with the database. All links and settings are stored as paths, so they keep working on the new domain.

**WP-CLI:**

```bash
wp flexo-booking export > template.flexo-booking.json            # rooms, seasons, closed dates, settings, features
wp flexo-booking export --bookings > full-backup.json
wp flexo-booking import template.flexo-booking.json --images      # --skip-settings --skip-rooms --skip-seasons --skip-closures --calendars --bookings
```

---

## 12. Settings reference

**Settings → General:**
- Currency: code, symbol, position, number format, decimals
- Minimum and maximum stay
- How far ahead guests can book
- Guest selector limits (0 children hides that field)
- Check-in and check-out times
- Thank-you page and terms page, as **paths** such as `/terms/`
- Data removal on uninstall

**Settings → Features:** see §2.

**Settings → Emails:**
- Notification address for new-booking alerts
- Guest email texts, shown only while *Guest emails* is on

Email placeholders:

`{reference}` `{guest_name}` `{guest_email}` `{guest_phone}` `{room}` `{check_in}` `{check_out}` `{nights}` `{guests}` `{total}` `{price_breakdown}` `{status}` `{booking_details}` `{check_in_time}` `{check_out_time}` `{site_name}`

`{booking_details}` lists the itemised nights under the total when prices vary.

**Uninstalling:** deleting the plugin keeps all data, unless **Settings → General → Data removal** is ticked first.

---

## 13. For developers

**REST API** (public, used by the form):

| Method | Route | Purpose |
|---|---|---|
| GET | `/wp-json/flexo-booking/v1/rooms` | Bookable rooms |
| GET | `/wp-json/flexo-booking/v1/availability?check_in=&check_out=&adults=&children=[&room=]` | Availability, totals, per-night average, `breakdown`, `min_nights`, and `notice` (property closed) |
| POST | `/wp-json/flexo-booking/v1/bookings` | Create a booking. Returns 201; 409 if no longer available or closed. |
| GET | `/wp-json/flexo-booking/v1/ical/{room}.ics?token=…` | The room's iCal export (Calendar sync). 403 for a wrong token, 404 while the feature is off. |

**Architecture (1.2.0):**

| Class | Role |
|---|---|
| `Flexo_Booking_Pricing` | The only pricing code: `quote()` → itemised result, stored on each booking as `price_breakdown` |
| `Flexo_Booking_Inventory` | `units_available()`, closed dates, and `with_lock()` (MySQL `GET_LOCK`, with an options-row fallback) |
| `Flexo_Booking_Seasons`, `Flexo_Booking_Closures` | Repositories |
| `Flexo_Booking_Features` | Available/enabled rules |
| `Flexo_Booking_Migrations` + `Flexo_Booking_Schema` | Versioned migrations; tables are only ever added to |
| `Flexo_Booking_Money` | Currency formatting |
| `Flexo_Booking_ICal` | iCal parser, fetch (`wp_safe_remote_get`), sync, conflicts, export feed, tokens, WP-Cron (`flexo_booking_ical_sync`) |
| Imported bookings | Table `flexo_calendar_events` (not bookings rows), counted by `Flexo_Booking_Inventory::nightly_usage()` with the unit rule in §7 |

**Hooks:**

| Hook | Type | Use |
|---|---|---|
| `flexo_booking_pricing_steps` | filter `( $steps, $request, $room )` | Add pricing steps by priority. 10 nightly, 20 rate plan, 30 guests, 40 promo, 50 tax, 90 totals, 95 payments. |
| `flexo_booking_quote` | filter `( $quote, $request, $room )` | Final quote |
| `flexo_booking_calculate_total` | filter (1.0) | Still applied to the accommodation amount; a change shows as an "adjustment" line |
| `flexo_booking_available_features` | filter `( $keys )` | Where a licence/package module plugs in |
| `flexo_booking_occupying_statuses` | filter | Statuses that take a room |
| `flexo_booking_created`, `flexo_booking_status_changed` | actions | Integrations |
| `flexo_booking_ical_timeout` | filter | Seconds to wait for a calendar download (default 15) |
| `flexo_booking_before_insert`, `flexo_booking_guest_email`, `flexo_booking_admin_email`, `flexo_booking_email_placeholders`, `flexo_booking_manage_capability`, `flexo_booking_rate_limit`, `flexo_booking_template`, `flexo_booking_use_mysql_locks` | filters | As named |

**Build:** `bin/build-zip.sh` → `dist/flexo-booking-<version>.zip`.

---

## 14. Testing

The `tests/` folder in the repository is not shipped in the zip. It holds WP-CLI test scripts (including the calendar-sync engine test with Booking.com/Airbnb-style sample feeds), a concurrency test (two simultaneous bookings for the last unit), the upgrade and portability tests, and Playwright browser tests for Day 1 and Day 2. See `tests/README.md`. Run them against a throwaway site only.
