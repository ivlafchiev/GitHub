# Flexo Booking

A room and accommodation booking system for **FlexoHotels** websites built with WordPress and Elementor Pro.

Guests pick dates and guests, see which rooms are free with the total price, enter their details and get a booking reference. They also get an email. The hotel manages everything under **WP Admin → Bookings**.

The plugin is self-contained and doesn't depend on any one theme or template. The same zip works on every FlexoHotels template and on every client site built from one.

---

## Features

**For guests**
- Availability search by dates, adults and children, with live prices (weekday and weekend rates)
- "Only 2 left!" urgency hints, capacity and minimum-stay checks
- A compact **search bar** for hero sections that sends guests to the full booking page
- "Book now" links that preselect a room (`/booking/?room=deluxe-double`)
- Mobile-friendly and accessible, with native date pickers and no jQuery
- Confirmation emails

**For the hotel**
- Rooms with nightly price, optional Fri/Sat price, max guests, **number of identical rooms** (inventory) and an optional minimum stay
- Two modes: **Booking request** (bookings stay *pending* until you confirm) or **Instant booking**
- Bookings list with filters, search, confirm/cancel/delete, and a "pending" counter in the menu
- **Add booking** for phone, walk-in or other-channel bookings, and **Block dates** (maintenance, sold elsewhere)
- CSV export that opens correctly in Excel, including Cyrillic text
- Editable email templates with placeholders
- Front-desk staff (Editors) can manage bookings; only Admins can change settings

**For you (the agency)**
- A native **Elementor widget** ("Flexo Booking Form", in the *FlexoHotels* category) with Style controls
- An **Elementor dynamic tag**, "Room booking link", for any button's link
- It **inherits Elementor Global Colors and Fonts** automatically, so it matches every template without restyling
- **Import/Export** of settings and rooms as one JSON file, plus **WP-CLI** commands for setting up many sites
- Portable by design: links and settings use paths and room slugs, never IDs or full domain URLs
- Theme template overrides and developer hooks

---

## Requirements

- WordPress 6.0+ and PHP 7.4+
- Elementor 3.5+ for the widget (Elementor Pro works too). Without Elementor, the `[flexo_booking]` shortcode still works.

---

## Installation

1. Build the zip (see [Building the zip](#building-the-zip)) or use `flexo-booking-1.0.0.zip`.
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. A **Bookings** menu appears in the admin.

---

## Quick start (about 10 minutes per site)

1. **Add rooms:** go to **Bookings → Rooms → Add room**. Enter a title, description, featured image and, in *Booking details*: price per night, weekend price (optional), max guests, number of rooms of this type, and minimum nights (optional).
2. **Check settings:** go to **Bookings → Settings** and set the booking mode, currency, stay limits, check-in/out times, notification email, an optional thank-you page and terms page (use paths like `/terms/`), and the email texts.
3. **Booking page:** edit your existing *Booking / Reservations* page in Elementor, search the widget panel for **"Flexo Booking Form"**, drag it in and leave *Layout* set to **Full booking form**. Make sure the page slug matches the path you use elsewhere (default `/booking/`).
4. **Hero search bar (optional):** on the home page, add the same widget with *Layout* set to **Search bar** and *Booking page path* set to `/booking/`.
5. **"Book now" buttons on room pages:** set the Elementor Button's *Link* to the dynamic tag **Flexo Booking → Room booking link** and choose the room. Or type the link yourself: `/booking/?room=<room-slug>`. You can see each room's slug in the *Rooms* list.
6. Make a test booking and check you got both emails. If emails don't arrive, install an SMTP plugin (e.g. WP Mail SMTP). Most hosts' default `mail()` is unreliable.

### Shortcode (any page builder or the block editor)

```
[flexo_booking]                                        Full booking form
[flexo_booking title="Book your stay"]                 With a heading
[flexo_booking room="deluxe-double"]                   Room preselected
[flexo_booking layout="search" booking_page="/booking/" button_text="Search"]   Search bar
```

In Elementor you can also paste a shortcode into the **Shortcode** widget, but the native widget has style controls.

---

## Styling

The form uses CSS variables that fall back to the site's **Elementor Global Colors / Fonts**:

| Variable | Default |
|---|---|
| `--fb-primary` (buttons, accents) | Global *Accent* → *Primary* → `#1f6f5c` |
| `--fb-text` | Global *Text* colour |
| `--fb-font` / `--fb-heading-font` | Global *Text* / *Primary* font |
| `--fb-bg`, `--fb-field-bg`, `--fb-border`, `--fb-radius`, `--fb-gap`, `--fb-padding` | neutral defaults |

So when you change a template's Site Settings, the booking form follows. To override per widget, use the widget's **Style** tab. To override site-wide, go to **Site Settings → Custom CSS**:

```css
.flexo-booking { --fb-primary: #b08d57; --fb-radius: 0; }
```

To change the markup, copy `templates/booking-form.php` or `templates/search-bar.php` to `wp-content/themes/<your-theme>/flexo-booking/` and edit the copy.

---

## Adding it to your templates and moving it between sites

The booking system has three parts, and each one moves differently:

| Part | Where it lives | How it moves |
|---|---|---|
| Plugin code | `wp-content/plugins/flexo-booking` | Install the zip, or it travels with a full-site migration |
| Widget placement and styling | Inside the Elementor page/template data | Travels with Elementor template/kit export |
| Rooms and settings | Posts (`flexo_room`) and one option | **Bookings → Import / Export** (JSON) or WP-CLI |
| Bookings | Table `wp_flexo_bookings` | Only with a full-site migration, or the "include bookings" export |

### A. Add the booking system to an existing template (once per template)

1. Install and activate the plugin on the template site.
2. Add 3–4 **demo rooms** that match the template's style and pricing.
3. Place the widget on the Booking page (full form), in the Home hero (search bar), and set the room pages' "Book now" buttons (dynamic tag). See the Quick start.
4. **Bookings → Import / Export → Download export file.** Save it next to the template as e.g. `template-name.flexo-booking.json`.
5. Re-export the template as usual (Elementor **Kit** export or your normal template workflow). The widget is stored in the Elementor data as `flexo-booking-form`, with the room saved as a **slug** and the booking page as a **path**, so nothing points to the template's domain.

### B. Create a new client site from a template

1. Create the site from the template the way you usually do (Kit import, Duplicator/All-in-One WP Migration clone, or multisite clone).
2. Make sure **Flexo Booking** is installed and active. A full-site clone already includes it. For a Kit import, install the zip **before** importing the kit, so Elementor recognises the widget.
3. If the rooms didn't come along (Kit import), go to **Bookings → Import / Export → Import**, choose the template's JSON file, and tick *Download room images* if the template site is online.
4. Edit the rooms (names, prices, number of rooms) and **Settings → notification email** for the client. The notification email is deliberately not exported, so the new site falls back to its own admin email.
5. Make a test booking, then delete it.

Rooms are matched by **slug**, so importing again later just updates prices and descriptions. Nothing is duplicated or deleted.

### C. Offer it as an add-on to a plan

Build client sites from the template **without** the plugin by default. When a client buys the booking add-on, do steps B2–B5 above: install, import JSON, adjust rooms, and add the widget where it's missing. The pages are already designed, so drop in the widget or paste `[flexo_booking]`.

### D. Moving a live hotel site to a new domain or host

Use your normal full-site migration tool. The bookings table moves with the database. All plugin settings are stored as paths, so they keep working on the new domain. Alternatively, export with **"Also include bookings"** and import with **"Import bookings"** ticked. Bookings with the same reference are skipped.

### WP-CLI (setting up many sites)

```bash
wp flexo-booking export > template.flexo-booking.json          # rooms + settings
wp flexo-booking export --bookings > full-backup.json          # + bookings
wp flexo-booking import template.flexo-booking.json --images   # on the new site
wp flexo-booking import file.json --skip-settings              # rooms only
```

---

## Email placeholders

`{reference}` `{guest_name}` `{guest_email}` `{guest_phone}` `{room}` `{check_in}` `{check_out}` `{nights}` `{guests}` `{total}` `{status}` `{booking_details}` `{check_in_time}` `{check_out_time}` `{site_name}`

Guests are emailed when a request is received (or confirmed straight away in Instant mode), and again when you confirm or cancel. The hotel gets an alert for every website booking, with *Reply-To* set to the guest.

---

## For developers

REST API (public, used by the form):

| Method | Route | Purpose |
|---|---|---|
| GET | `/wp-json/flexo-booking/v1/rooms` | Bookable rooms |
| GET | `/wp-json/flexo-booking/v1/availability?check_in=YYYY-MM-DD&check_out=YYYY-MM-DD&adults=2&children=0[&room=slug]` | Availability and prices |
| POST | `/wp-json/flexo-booking/v1/bookings` | Create a booking (JSON body) |

Hooks:

| Hook | Type | Use |
|---|---|---|
| `flexo_booking_created` | action `( $booking )` | Payment, CRM, channel manager, analytics |
| `flexo_booking_status_changed` | action `( $booking, $old, $new )` | Sync status elsewhere |
| `flexo_booking_calculate_total` | filter `( $total, $room, $in, $out )` | Seasonal pricing, discounts, taxes |
| `flexo_booking_before_insert` | filter `( $row, $room )` | Modify the stored booking |
| `flexo_booking_guest_email` / `flexo_booking_admin_email` | filter | Change subject/body/headers (e.g. HTML emails) |
| `flexo_booking_email_placeholders` | filter | Add your own `{placeholders}` |
| `flexo_booking_manage_capability` | filter | Who can manage bookings (default `edit_others_posts`) |
| `flexo_booking_rate_limit` | filter | Booking attempts per IP per hour (default 10, 0 = off) |
| `flexo_booking_template` | filter | Swap a template file |

How it avoids double bookings: availability is counted **night by night** against the number of rooms of each type, and the final check plus insert run under a per-room database lock. Two guests can't take the last room at the same moment.

Spam protection: a honeypot field and per-IP rate limiting. No CAPTCHA is needed, and it keeps working with page caching because no nonces are embedded in the page.

**Caching/CDN:** don't let a CDN cache `/wp-json/flexo-booking/*` responses (for example, a Cloudflare "Cache Everything" rule needs a bypass for `/wp-json/*`). Normal page caching of the booking page is fine.

---

## Building the zip

```bash
bin/build-zip.sh      # → dist/flexo-booking-<version>.zip
```

## Uninstalling

Deactivating or deleting the plugin **keeps** your rooms and bookings. To remove everything on delete, tick **Settings → Uninstall → Delete all rooms, bookings and settings** first.

## Not included yet (possible add-ons)

- Online payments or deposits (Stripe/PayPal). The `flexo_booking_created` hook is the integration point.
- Seasonal rate calendars. Use the `flexo_booking_calculate_total` filter or ask for a rates add-on.
- Channel manager or iCal sync with Booking.com and Airbnb. Until then, block those dates with **Add booking → Block dates**.
