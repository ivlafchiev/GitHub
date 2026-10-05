# Flexo Booking

A room and accommodation booking system for **FlexoHotels** websites built with WordPress and Elementor Pro: hotels, guest houses, villas, resorts and campsites.

Guests pick dates and guests, see which rooms are free with the price, enter their details and get a booking reference by email. If the hotel wants, they pay a deposit or the full amount by card (Stripe) or bank transfer. The hotel manages everything under **WP Admin → Bookings**.

It's one plugin that works with any theme or template. Extra features are switched on only where a property needs them, so a simple guest house still gets just *dates → room → details → booking request*.

> Version **1.8.1**. Day 7 makes the plugin the single place for rooms: each room has its own page on the website with its photos, description, amenities and a booking box, filled into your Elementor Pro templates through dynamic tags (§3). Day 6 made the plugin easier to use for guests, owners and reception and added Appearance settings; 1.7.0 gave the admin screens their own, app-like design (§17). Development follows `ROADMAP.md`; technical design is in `IMPLEMENTATION_PLAN.md`.

---

## Contents

1. [Installing and upgrading](#1-installing-and-upgrading)
2. [Features: what the hotel can switch on](#2-features-what-the-hotel-can-switch-on)
3. [Rooms and room pages](#3-rooms-and-room-pages)
4. [Setting prices](#4-setting-prices)
5. [Seasonal prices](#5-seasonal-prices)
6. [Closed dates](#6-closed-dates)
7. [Children and child prices](#7-children-and-child-prices)
8. [Rate plans](#8-rate-plans)
9. [Tourist tax](#9-tourist-tax)
10. [Promo codes](#10-promo-codes)
11. [Payments: card, deposit and bank transfer](#11-payments-card-deposit-and-bank-transfer)
12. [Calendar sync (Booking.com, Airbnb…)](#12-calendar-sync-bookingcom-airbnb)
13. [The booking calendar](#13-the-booking-calendar)
14. [Putting the booking form on the site](#14-putting-the-booking-form-on-the-site)
15. [Appearance of the booking form](#15-appearance-of-the-booking-form)
16. [Guest booking page (manage booking)](#16-guest-booking-page-manage-booking)
17. [Daily use: managing bookings](#17-daily-use-managing-bookings)
18. [Health check and help](#18-health-check-and-help)
19. [Privacy and data retention](#19-privacy-and-data-retention)
20. [Invoice requests](#20-invoice-requests)
21. [Emails](#21-emails)
22. [Languages: Bulgarian, English, Polylang and WPML](#22-languages-bulgarian-english-polylang-and-wpml)
23. [Conversion tracking (Google Tag Manager, GA4, Meta)](#23-conversion-tracking-google-tag-manager-ga4-meta)
24. [Templates and moving between sites](#24-templates-and-moving-between-sites)
25. [Settings reference](#25-settings-reference)
26. [For developers](#26-for-developers)
27. [Before going live on a client site](#27-before-going-live-on-a-client-site)
28. [Testing](#28-testing)

---

## 1. Installing and upgrading

**Requirements:**
- WordPress 6.0+ and PHP 7.4+. MySQL or MariaDB is recommended.
- Elementor 3.5+ for the widgets; **Elementor Pro** (3.x) for room templates (Theme Builder single room page, Loop Grid / Loop Carousel of rooms). Without Elementor the shortcodes still work, and every room still gets the plugin's own room page.
- JetEngine (or another custom-field plugin) is **not** needed for rooms; rooms made with it can be brought in (§3.2).

**Install:**
1. Build the zip with `bin/build-zip.sh`, or use the provided `flexo-booking-1.8.1.zip`.
2. Go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. A **Bookings** menu appears in the admin. On a new site (no rooms, no bookings) the **setup wizard** opens once (below).
4. Check **Settings → General → Timezone**. It must be the hotel's city, because "today" and arrival dates depend on it. **Bookings → Settings → Health** warns if it isn't.

### First setup: the setup wizard

The wizard takes a new hotel from an empty site to a working test booking in seven short steps. Each step can be skipped, the progress is saved, and **Finish later** leaves it; it's always available again under **Bookings → Help → Setup wizard**.

1. **Your hotel:** name, address, phone and email (shown to guests and in emails).
2. **How guests book:** booking requests (you confirm) or instant booking, explained in one line each.
3. **First room:** name, photo, price per night (required), max guests (default 2) and how many identical rooms.
4. **Booking page:** **create one automatically** with the booking form, or choose an existing page.
5. **Emails:** where hotel notifications go, and **Send a test email**.
6. **Look:** match the website (default) or pick a main colour (§15).
7. **Test booking:** opens the booking page in a new tab; the wizard notices the booking when it arrives and shows it, then tells you what to do next.

The wizard never opens by itself on a site that already has rooms or bookings, so upgrades are not interrupted.

### Upgrading

**Upgrade from any earlier version (1.0.0–1.8.0):** upload the new zip and choose **Replace current with uploaded**.
- The database updates itself on the next page load. No reinstall is needed, and rooms, bookings and settings are kept.
- If an update step ever fails, a red notice appears in the admin and the step is retried automatically.
- On staging or in scripts you can also run `wp flexo-booking migrate`.
- After upgrading, **Seasonal prices**, **Calendar sync**, **Children & ages**, **Rate plans**, **Tourist tax**, **Promo codes**, **Privacy consent**, **Invoice request** and **Conversion tracking** are off. Switch on what the hotel needs under **Bookings → Settings → Features**. The six ready-made rate plans are added switched off, so nothing changes for guests until you use them.
- Emails keep working as before and now look like simple HTML emails. Bookings made before 1.4.0 have no stored language; their emails use the site language.
- The payment features (1.5.0) are off after upgrading. Existing bookings are unchanged and show no payment information until you record one.
- **1.6.0 (Day 6):**
  - The booking form **looks exactly as before**: Appearance starts on *Match my website* (§15).
  - The first screen under **Bookings** is now **Today** (§17). **All bookings** has its own entry; seasons, closed dates and rates are tabs under **Rooms & prices**, and Calendar sync and Import & export are linked from the Settings tabs. Old admin links (including the booking links in emails already sent) keep working.
  - **Editors keep the access they had**; two new roles, **Hotel Staff** and **Hotel Manager**, are added for reception and managers (§17).
  - The **guest booking page** is off until switched on (§16). The new room fields (size, beds, amenities) and rate-plan meals are empty/filled from the presets and simply not shown while empty.
  - Status names are clearer (e.g. *Waiting for confirmation*, *Not paid in time*); the stored statuses, CSV values and filters are unchanged.
- **1.7.0:** the plugin's admin screens have a new design (§17). No settings, data, addresses or guest-facing pages change, and there is no database update. The rest of the WordPress admin looks as before.
- **1.8.0 (Day 7):**
  - **Every published room gets a page at `/rooms/<slug>/`** and appears in sitemaps (§3). Nothing else on the site changes: existing pages, the booking form, booking links and prices stay as they are. If the site already has pages or another post type (e.g. JetEngine rooms) under `/rooms/`, **Settings → Health** says so: pages with a room's address are no longer shown (remove them), and the other post type should be switched off – or choose another word under **Settings → Room pages**.
  - Existing amenities, sizes, beds, photos and descriptions are kept and shown in the new editor; amenities get their usual icons.
  - The database update only adds the new settings and refreshes the site's addresses once. Nothing is removed, so going back to 1.7.0 is possible (room pages then stop answering).
- **1.8.1:** new **Room page design** setting: any page or template you designed in Elementor can be the design of every room page (§3). Fixed: *Settings → Room pages* named the site's header template as the room template (any template shown on the entire site was taken for it), which also kept *Add the starter room templates* from switching to the starter template. No database update.

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
| Guest emails | Emails to guests when a booking is received, confirmed or cancelled, plus an optional reminder before arrival and a review request after the stay | Ready (reminders and review requests: 1.4.0) |
| Calendar sync | iCal sync with Booking.com, Airbnb, Vrbo… | Ready (1.2.0) |
| Children & ages | Ask each child's age, "max adults" per room, children charged by age for per-person extras | Ready (1.3.0) |
| Rate plans | Room Only, Breakfast, Half Board, Non-refundable… with their own price and cancellation text | Ready (1.3.0) |
| Tourist tax | Per person per night, as its own line; in the total or paid at the property | Ready (1.3.0) |
| Promo codes | Discount codes such as DIRECT10, with dates, limits and conditions | Ready (1.3.0) |
| Privacy consent | Consent checkbox with proof, automatic removal of old guest data, "Remove personal data" button | Ready (1.4.0) |
| Invoice request | "I would like an invoice" with details for a person or a company | Ready (1.4.0) |
| Conversion tracking | Booking events for Google Tag Manager, GA4 and Meta | Ready (1.4.0) |
| Online card payment | Guests pay by card on Stripe's secure page; Instant booking only | Ready (1.5.0) |
| Deposits | A % of the total or a fixed amount when booking, the rest at the property (needs card payment or bank transfer) | Ready (1.5.0) |
| Bank transfer | Deposit or full amount by bank transfer, with deadline, reminder and automatic cancellation | Ready (1.5.0) |
| Guest booking page | A private link in the emails where guests see their booking and can ask to change or cancel it (§16) | Ready (1.6.0), off by default |
| Appearance settings | Choose the form's colours, fonts and corners, or let it match the website (§15) | Ready (1.6.0), on by default (mode *Match my website*) |

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

With nothing defined, everything is available. Feature keys: `booking_request`, `instant_booking`, `seasonal_pricing`, `calendar_sync`, `rate_plans`, `children`, `tourist_tax`, `promo_codes`, `privacy_consent`, `invoice_request`, `guest_emails`, `tracking`, `online_payment`, `deposit`, `bank_transfer`, `guest_booking_page`, `custom_appearance`.

**Packages.** A typical way to sell the booking system as FlexoHotels packages is to put one line in each client's `wp-config.php`:

```php
// Basic – a guest house that confirms requests by email:
define( 'FLEXO_BOOKING_FEATURES', 'booking_request,guest_emails,privacy_consent' );
// Standard – adds instant booking, seasons, children, tax, invoices, promo codes, translation-ready emails:
define( 'FLEXO_BOOKING_FEATURES', 'booking_request,instant_booking,guest_emails,privacy_consent,seasonal_pricing,children,tourist_tax,invoice_request,promo_codes,tracking' );
// Premium – everything, including calendar sync, rate plans and payments:
define( 'FLEXO_BOOKING_FEATURES', 'all' );
```

Upgrading a client to a bigger package only means changing that line: their settings for the new features start at the defaults, and features taken away are hidden while their settings and data are kept. The hotel still decides which of its available features to switch on.

*Note:* these switches are a product boundary for normal hotel admins, not a security boundary. Anyone who can edit `wp-config.php` or install plugins can change them.

---

## 3. Rooms and room pages

Since 1.8.0 **Flexo Booking is the only place where rooms live**: their texts, photos, amenities and facts as well as their prices. Every room has its **own page on the website**, room lists and "other rooms" carousels are filled from it, and the booking form uses the same rooms. No JetEngine (or other custom-field plugin) is needed for rooms.

Create **one room per room type** (not per physical room): **Bookings → Rooms & prices → Add room**. The editor is a set of cards, top to bottom:

| Card | What goes in it |
|---|---|
| **Room name and Full description** | The public name and the text guests read on the room page. |
| **Short description** | One or two sentences for room cards, lists and the booking form. Empty = the first words of the full description. A counter shows when it gets long. |
| **Photos** | **Main photo** (room cards and the top of the room page) and the **Gallery** (add several at once, drag to reorder, × to remove). Rooms brought in from another site can show **Download missing photos** (below). |
| **Room facts** | **Room type** (Double room, Suite… – tick one or more, or type a new one and **Add**; used to group and filter rooms), **Size** (m²), **Beds**, **View**, **Max guests**, **Max adults** (with *Children & ages* on), **Identical rooms** (how many can be booked for the same night; `0` stops bookings), **Minimum nights**. |
| **Amenities** | Click the ones the room has (42 ready-made ones: Wi-Fi, air conditioning, sea view, balcony, kitchen…) or type your own and **Add** – an icon is suggested from the words, in English or Bulgarian (e.g. "Rain shower" or "Джакузи"). Drag or use ↑ ↓ to order them; the first five also appear on the room cards in the booking form. Click an icon to choose another one from the 126 bundled icons (search by name) or **Use my own icon** (SVG or PNG from the Media Library). |
| **More details** | Anything else, as short "name: value" lines with an icon – *Floor: 2nd, with lift*, *Distance to the beach: 200 m*, *Check-in: from 14:00*. Suggestions are one click away. |
| **Prices** | Price per night, weekend price, child prices, rate plans – as before (§4, §7, §8). |
| **Search engines** | Title and description in search results, with a live preview. Hidden when Yoast SEO or Rank Math is active: use their box on the same screen. |
| **On the website** | **Show on the website** (on by default), **Demo room**, **Display order**, the room page address and booking link with **Copy** buttons, and a checklist (*Room page: 7 of 9 filled in*) of what is still missing. |

All fields are optional except the name and the price; empty ones are simply not shown on the website.

**The slug** (e.g. `deluxe-double`) is the last part of the room page address and of booking links: change it under **Screen Options → Slug**. Old addresses keep working after a change (they redirect to the new one, and old booking links still book the room).

**Room types** are renamed or deleted on their own screen: **Rename or delete room types** in the *Room facts* card.

### The room page

Every published room has a page at **`/rooms/<slug>/`**. The word `rooms` is set under **Bookings → Settings → Room pages → Room page address** (e.g. `stai`, `villas`). After a change, the old addresses redirect to the new ones and room links in menus need no update.

What the page looks like:

0. **With a page you choose** – *Settings → Room pages → Room page design*: pick any page or Elementor template you designed for one room (§3.1, *Using your own page*). Every room page uses it with that room's data. This wins over everything below.
1. **With Elementor Pro and a single room template** (Theme Builder → Single, condition *Rooms*): your design, filled by each room through the **Flexo Booking: room** dynamic tags and the room widgets (§3.1). This is the normal FlexoHotels setup.
2. **Without a template** (or before you make one): the plugin's own room page – name, type, "from" price, facts, gallery with lightbox, description, amenities, more details, rates and the **booking box** – in the theme's layout and fonts. It works on classic and block themes; block themes can edit it under **Appearance → Editor → Templates → Single room**.

**Search engines:** each room page has its title and description (or Yoast's / Rank Math's), structured data (HotelRoom, the hotel and the "from" price) and is listed in the sitemap (WordPress, Yoast or Rank Math). With Yoast or Rank Math the room data is added to *their* structured data, so nothing appears twice.

**Show on the website = off** (a room sold only by phone, or not ready yet): the page answers *not found*, and the room is left out of room lists, search results, sitemaps and the booking form's room list. Staff still see the page, guests can still book it through its **booking link**, and you can book it from the admin.

**Demo rooms:** **Rooms & prices → Add demo rooms** adds three sample rooms (double room, studio, family suite) with drawn sample photos, texts, amenities and details, so a template can be designed before the hotel's rooms exist. Only logged-in staff see them on the website, and they **can never be booked**. **Remove demo rooms** deletes them and their photos (a demo room that somehow has bookings is kept). Any room can be marked or unmarked as a demo room in its *On the website* card.

**The "from" price** ("from 95 € per night") is the lowest price per night a guest can really get in the **next 12 months** (or the booking window, if shorter), for **2 adults** (fewer if the room takes fewer): seasons, weekend prices, the minimum stay and the room's first rate plan are included; closed dates are skipped; tourist tax and promo codes are not included. The booking form always honours it. It updates itself when prices, seasons, closed dates, rates or settings change, and once a day. **Settings → Room pages → Prices on room pages** can hide it until guests choose dates. The *Your room pages* table on the same tab shows each room's address, status and "from" price (or why it has none).

**On phones**, a **Check availability** bar with the "from" price stays at the bottom of room pages; it scrolls to the booking box (and hides while the box is on screen). Switch it off under **Settings → Room pages → On phones**.

### 3.1 Room templates in Elementor Pro

Design **one room page and one room card**; every room fills them with its own data.

1. **Bookings → Settings → Room pages → Elementor templates for rooms** shows which template room pages use. **Add the starter room templates** creates a *Single room* template (with the display condition *Rooms*) and a *Room card* for Loop Grid / Loop Carousel. When you already have a room template, **Add the starter templates (keep my template in use)** adds them without switching. Both are also downloadable as `.json` to import under **Templates → Import**.
2. **Or connect your own template:** open it, click a widget's dynamic-tag icon (the stack icon next to a field) and choose from the group **Flexo Booking: room**:

| Dynamic tag | Gives | Options |
|---|---|---|
| Room name, Room type, Short description, Full description | Text | Separator between types |
| Room size, Beds, View, Guests (capacity) | Text | Size with unit (24 m²) or number only; guests as "Up to 4 guests (max. 2 adults)", "4 guests" or a number (max guests / adults / children) |
| Room price | Price per night | Base, weekend or **From price**; with currency or number only |
| Amenities | Text | Names separated by commas, one per line, or one amenity by position |
| Room detail (More details) | Text | A detail by name (e.g. *Floor*) or position; value only, or "name: value" |
| Availability for the searched dates | Text | When the page was opened from a search (dates in the address): *Available · 390 € for 2 nights* or *Not available for your dates*; empty otherwise. Optionally without the total. |
| Room main photo, Room gallery | Image / gallery | Main, 2nd, 3rd or 4th photo; a neutral placeholder when a room has none |
| Room page link | URL | For card titles and "View room" buttons |
| Room booking link | URL | Opens the booking page with the room chosen (§14) |

Every tag has a **Room** option, **Current room (automatic)** by default: in the single template and in Loop items each room shows its own data. Choose a room only to show a specific room elsewhere (e.g. on the home page). While editing a template, the first room is shown as a sample; use *Preview settings* to pick another.

3. **Room widgets** (panel category *FlexoHotels*), for things a text field can't do well:
   - **Room amenities** – the amenities with their icons, as a list, in a row or in columns; icon size and colour (uploaded icons can keep their own colours), dividers, how many.
   - **Room details** – the facts (size, beds, view, guests) and/or *More details*, with icons, name above value or value only.
   - **Room gallery** – carousel (photos side by side, height per device, arrows, dots, autoplay that stops on touch and for visitors who prefer less motion) or grid; lightbox.
   - **Room booking box** – dates and guests, then availability and the total for *this* room, and **Book now** straight to the guest details step (§14). Same Style options as the booking form.
4. **Room lists:** Pro's **Loop Grid** or **Loop Carousel** with *Query → Source: Rooms* and the *Room card* (or your own loop item using the tags). Order follows the rooms' *Display order*; hidden and demo rooms are left out for guests. For "other rooms" on a room page, exclude the current post in the query.

**Using your own page as the room design** (1.8.1). If your room design is an Elementor **page** (or a template that isn't set to show on rooms), you don't need to rebuild it:

1. **Bookings → Settings → Room pages → Room page design**: choose the page (listed under *Pages built with Elementor*; drafts are listed too, so the design page needn't be public) or the template (*Elementor templates*). Save.
2. Every room page now shows that design with the theme's header and footer, filled with each room's data wherever the design uses the **Flexo Booking: room** tags or room widgets (and core tags such as *Post Title* and *Featured Image*). Everything else in the design – hotel rules, banners, sections – stays exactly as designed and is the same on every room.
3. The setting tells you if the design still reads **JetEngine fields** (named one by one: `price_per_night`, `long_description`…) or isn't connected to room data at all. Open it with **Edit it with Elementor** and switch those parts to the matching room tags: Room price, Full description, Room size, Guests, Beds, Room gallery / Room amenities widgets, Room booking link for buttons. While you edit the design, it shows a real room (the room with the same address as the page, otherwise the first room).
4. The design page itself, if published, shows the room with the same address and tells search engines that the room page is the real one (canonical); otherwise it is kept out of search results. You can also keep it as a draft.
5. Back to normal any time: choose *Automatic*. The design's ID is not exported with the settings (it only exists on this site).

**Converting a template built on JetEngine:** `bin/convert-jet-room-template.py` rewrites an exported Elementor JSON so every JetEngine room field becomes the matching Flexo Booking tag or widget (photos, gallery, amenities, facts, prices, booking buttons, the rooms query). `elementor-templates/` holds the converted Azure single room template and room card, with an import guide.

**Without Elementor:** `[flexo_room]` prints the plugin's room page for the current (or a given) room, `[flexo_room_field field="name|type|excerpt|description|size|guests|beds|view|price|from_price|amenities|url|booking_url" room="slug"]` prints one field, and `[flexo_room_booking room="slug"]` the booking box.

### 3.2 Bringing rooms in from JetEngine (or another plugin)

**Rooms & prices → Bring in rooms** copies rooms made with another plugin into Flexo Booking:

1. Choose where the rooms are now (e.g. *Rooms (rooms) – 12*). Rooms made with JetEngine are found **even when JetEngine is switched off**.
2. Check which field goes where. Suggestions are filled in from the field names (`room_size` → Size, `price_per_night` → Price, `amenity-1…8` → Amenities, gallery fields → Gallery…), with an example value for each. The name, address (slug), main photo and order are always copied.
3. **Bring in the rooms.** Rooms keep the **same addresses**, so links and search results keep working. Running it again updates the same rooms; **prices already set in Flexo Booking are kept**. Nothing is deleted.

Then: check prices, identical rooms and amenity icons; point the single room template at Flexo Booking (§3.1); **switch off the old room post type** (or JetEngine) so it no longer answers the same addresses – the results screen and **Settings → Health** warn while it does. When everything looks right, JetEngine and its add-ons can be removed.

---

## 4. Setting prices

Every price is calculated on the server, in one place: the pricing service. The guest sees the same number as the booking, the email, the admin list and the CSV. Each booking stores its price breakdown, so later price changes never change existing bookings.

How a night is priced:

1. If **Seasonal prices** is on and the night falls in a season of that room, the **season's** price is used. On Friday and Saturday nights, the season's weekend price is used if it has one.
2. Otherwise the room's **normal price** is used, or its **weekend price** on Friday and Saturday nights.

The room price of a stay is the sum of its nights. Optional extras are then added in this order, each as its own line the guest can see:

1. **Room price** (season or normal price, night by night)
2. **Rate plan** price change, e.g. breakfast per guest per night, or −10% for non-refundable (§8)
3. **Promo code** discount on the room price and rate plan (§10)
4. **Tourist tax** (§9), never discounted
5. **Total**. Tourist tax paid at the property is shown but not part of the total.

When nights have different prices, guests see the average per night on the room card and an itemised list before they confirm, e.g. *Low season: 2 nights × 80.00 € · High season: 3 nights × 150.00 €*.

**Currency** is set under **Settings → Hotel**:
- Code (default **EUR**; Bulgaria uses the euro since 01.01.2026)
- Symbol and its position (4 options)
- Number format (e.g. `1 234,50`)
- Decimals

Changing the currency **never converts** prices; update the room and season prices yourself. Existing bookings keep their own currency, so a booking made in BGN still shows `200.00 BGN`.

---

## 5. Seasonal prices

Switch on **Settings → Features → Seasonal prices**. A **Seasonal prices** tab appears under **Bookings → Rooms & prices**.

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

**Bookings → Rooms & prices → Closed dates** is always available, because closing the property is about availability, not prices.

- **Applies to:** *Whole property* (e.g. closed for the winter) or a single room type (e.g. renovation of all apartments of one type).
- **From / To (last night)** in DD.MM.YYYY, and an optional **message for guests**.
- Guests can't book any night inside the period. They see e.g. *"We are closed from 1 November 2026 to 31 March 2027. Please choose other dates."* Guests may still check out on the first closed day.
- **Copy to next year** repeats the periods (e.g. every winter).
- Staff can still record a booking inside a closed period with **Add booking**.
- To take **one** room out of service, use **Add booking → Block dates** instead: it takes one unit, not the whole type.

---

## 7. Children and child prices

Switch on **Settings → Features → Children & ages**.

**What guests see:** the form keeps *Adults* and *Children*. For each child an **Age of child 1, 2…** selector appears (0–17), and the search waits until every age is chosen. The hero search bar asks for ages too and passes them to the booking page.

**Capacity:** children count towards each room's **Max guests**, babies included. A room can also have **Max adults** (e.g. 4 guests, at most 2 adults). Rooms that don't fit show why: *"Fits up to 4 guests."* or *"Fits up to 2 adults."*

**Child prices** (**Settings → Booking rules**) apply to per-person amounts: rate plans charged *per guest per night* (breakfast, half board…) and, if you choose, the tourist tax. The room price itself stays the same.

| Setting | Default | Meaning |
|---|---|---|
| Free for children under | 3 | Ages 0–2 pay nothing |
| Older children pay | 50 % | Ages 3–11 pay half the adult amount |
| Adult price from age | 12 | 12 and older pay like adults |

The settings screen explains the current rules in one line, e.g. *"Under 3: free · 3–11: 50% of the adult price · 12 and older: adult price"*. A room can use its own rules: edit the room → **Child prices** → *Use different child prices for this room*.

**Example:** Half Board +18 € per guest per night, 3 nights, 2 adults + children aged 2 and 6: 2 × 3 × 18 = 108 € + child 2 free + child 6: 3 × 9 = 27 € → **135 €**. The guest sees each line.

**Switched off:** the form shows *Adults* and *Children* counts exactly as before (set *Children up to* to 0 under Settings → Booking rules to hide children completely), and "max adults" isn't applied. Existing bookings keep their ages.

---

## 8. Rate plans

Switch on **Settings → Features → Rate plans**. A **Rates** tab appears under **Bookings → Rooms & prices**. Hotels are never forced to use them: a room that offers no plan is booked at its normal price, exactly as before.

A rate plan is one way of selling a room: **name**, **description for guests**, **meals** (none, breakfast, half board, full board, all inclusive – filled in for the ready-made plans), **price change**, **refundable yes/no** and **cancellation text**. Guests compare the rates in one line each: *name · meals · cancellation · total*. Create only the combinations you really sell, e.g. *Room Only*, *Breakfast Included*, *Half Board*, *Half Board – Non-refundable*. There is no meal × policy grid to fill in.

**Ready-made plans.** Six plans are waiting, switched off: Room Only, Breakfast Included (+8 per guest per night), Half Board (+18), Full Board (+28), All Inclusive (+35) and Non-refundable (−10%). Edit the amounts and texts, tick the rooms, and tick *Offer this plan to guests*. If you delete them, **Add the ready-made plans** brings back the missing ones.

**Price change types:**

| Type | Example | How it's charged |
|---|---|---|
| Fixed amount per night | Parking +10 | 10 × nights |
| Fixed amount per booking | Welcome pack +25 | Once |
| Amount per guest per night | Breakfast +8 | Adults × nights × 8, children by age (§7) |
| Percentage of the room price | Non-refundable −10 | 10 % off the **room price only** |

Use a minus sign for a lower price. Percentages never apply to other plans, discounts or taxes.

**Which rooms offer a plan:** in the plan's form, tick *Offer for this room* for each room. The optional **Different amount for this room** changes the amount for that room only (e.g. breakfast 12 in the suite). The room edit screen lists its plans.

**Example (EUR):** Standard Room 100 per night, 3 nights, 2 adults:

| Plan | Price change | Total |
|---|---|---|
| Room Only | +0 | 300.00 |
| Breakfast Included | +8 per guest per night | 348.00 |
| Half Board | +18 per guest per night | 408.00 |
| All Inclusive | +35 per guest per night | 510.00 |
| Non-refundable | −10 % | 270.00 |

**What guests see:** the room card shows the price **"from"** the cheapest plan. After *Select*, the plans are listed with their description, **✓ Refundable** / **✕ Non-refundable** and total, each with a *Choose* button. If a room offers only **one** plan, the choice is skipped. The summary then shows the room, the plan, nights, each price line, the total and the cancellation text, which is also in the guest's emails.

**Changing or deleting a plan** never changes existing bookings: each booking keeps the plan's name, price and cancellation text as they were when it was made. Switching the feature off hides the plans; rooms are then booked at their normal price.

---

## 9. Tourist tax

Switch on **Settings → Features → Tourist tax**, then set it under **Settings → Taxes & invoices**:

- **Amount per adult per night**, as set by the municipality. 0 means no tax.
- **Children:** *pay the same as adults*, or *younger than N years don't pay* (older children pay the full amount), or *use the child price rules* (§7).
- **Payment:** *Included in the booking total*, or *Paid separately at the property*. In the second case guests see the tax (*"Tourist tax (paid at the property)"* and *"Payable at the property: 9.00 €"*) but it isn't part of the booking total.

The tax is always its own line in the breakdown, emails, admin and CSV, and promo codes never reduce it.

---

## 10. Promo codes

Switch on **Settings → Features → Promo codes**. A **Bookings → Promo codes** screen appears.

Each code has:

| Field | Notes |
|---|---|
| **Code** | Letters, numbers, `-` and `_`. Stored in capitals; guests can type `direct10` or `DIRECT10`. |
| **Discount** | A percentage (`10 % off`) or a fixed amount off the booking |
| **Can be used when booking** From / Until | Optional. The day the guest books. After *Until* the code has expired. |
| **For stays** From / To (last night) | Optional. Every night of the stay must be inside. |
| Minimum booking amount | Optional. Room price with rate plan, before the discount. |
| Minimum nights | Optional |
| **Can be used … times in total** | Optional usage limit |
| Only for these rooms / rate plans | Optional. Leave unticked for all. |
| Status | *Guests can use this code* |

Rules:
- The discount applies to the **room price and rate plan**, never to the tourist tax, and the total never goes below zero.
- **One code per booking.**
- **Usage counts confirmed bookings only.** A booking request counts once you confirm it, and cancelling a booking gives its use back. Instant bookings count straight away. If confirming a request takes a code past its limit, the booking is still confirmed and you get a note.
- Everything is checked on the server when the guest applies the code **and again when the booking is saved**. Two guests can't both take a code's last use.

**What guests see:** under the price summary, a small **Have a promo code?** link opens the field. After *Apply* the summary shows **Subtotal**, **Discount** and **Final total**. If the code can't be used, the guest is told why:

| Situation | Message |
|---|---|
| Unknown or switched off | This promo code is not valid. |
| Past its *Until* date | This promo code has expired. |
| Before its *From* date | This promo code can be used from 1 June 2027. |
| Stay outside its dates | This promo code is valid for stays between … and …. |
| Wrong room / rate plan | This promo code is not valid for this room. / … for the selected rate. |
| Too short / too cheap | This promo code needs a stay of at least 5 nights. / … applies to bookings of 500.00 € or more. |
| Limit reached | This promo code has already been fully used. |

The **Promo codes** list shows each code's discount, conditions, uses (e.g. *3 of 50 confirmed bookings*) and status (Active, Switched off, Expired, Fully used). Bookings show the code as a **% DIRECT10** badge.

To stop code guessing, a visitor who tries more than 20 wrong codes in an hour has to wait.

---

## 11. Payments: card, deposit and bank transfer

Three features work together. Switch on what the hotel needs under **Settings → Features → Payments**, then set it up under **Settings → Payments**:

- **Online card payment**: the guest pays on Stripe's own secure payment page (Stripe Checkout).
- **Deposits**: the guest pays part of the price when booking and the rest at the property.
- **Bank transfer**: the guest transfers the deposit or the full amount; you mark it as received.

Amounts are always calculated by the website from the price breakdown. Nothing the guest's browser sends is ever used as an amount.

### Choosing how guests pay

| What the hotel wants | Booking mode (Features) | Payments |
|---|---|---|
| **A.** Booking requests, no payment | Booking requests | Payment features off |
| **B.** Instant booking, no online payment | Instant booking | Payment features off |
| **C.** A deposit is required | Instant booking (card and/or bank transfer), or Booking requests (bank transfer only) | *A deposit*: a % of the total or a fixed amount |
| **D.** The full amount online | Instant booking (card and/or bank transfer), or Booking requests (bank transfer only) | *The full amount* |
| **E.** Pay at the property | Either | *Nothing online*: guests see "Payment: at the property", and the emails say how much to pay there |

**Card payments only work with Instant booking.** A guest must never be charged for a booking the hotel hasn't accepted. With booking requests, card payment is paused automatically and the Payments tab and a notice explain why. Bank transfer works with both modes: with requests, the guest gets the bank details when you confirm the request.

**Deposit example:** total 800 €, deposit 30% → **240 € when booking, 560 € at the property**. A fixed deposit is never more than the total. The **tourist tax** follows its own setting (§9): if it's "paid at the property", it's not part of the total, so it isn't in the deposit and is added to what's paid at the property.

When both card and bank transfer are ready, the guest chooses. The summary on the details form shows *Deposit (30%) – to pay now* and *At the property*, and on phones the amount to pay stays visible next to the button.

### Card payments with Stripe, step by step

The hotel needs its **own Stripe account** (stripe.com). Money goes straight from Stripe to the hotel's bank account. FlexoHotels never handles it.

1. **Features:** switch on *Online card payment* (and *Deposits* if needed), and choose **Instant booking**.
2. **Settings → Payments:** choose the payment (deposit or full amount) and leave **Mode** on **Test mode**.
3. **Stripe Dashboard → Developers → API keys** (test mode): copy the **Secret key** (`sk_test_…`) into *Test keys → Secret key*. A restricted key (`rk_test_…`) with write access to *Checkout Sessions* also works.
4. **Stripe Dashboard → Developers → Webhooks → Add endpoint:**
   - paste the **Webhook** address shown on the Payments tab (`https://<site>/wp-json/flexo-booking/v1/stripe-webhook`);
   - select the events `checkout.session.completed`, `checkout.session.expired`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `payment_intent.payment_failed` and `charge.refunded`;
   - copy the endpoint's **Signing secret** (`whsec_…`) into *Test keys → Webhook signing secret*.
5. **Save.** The tab shows *Ready – test mode (no real money)*.
6. **Test** in a private window: book with card **4242 4242 4242 4242**, any future expiry date and any CVC. The booking must become **Confirmed** with *Payment received* (or *Deposit received*), and the guest and hotel emails must arrive. In Stripe, the webhook endpoint must show successful (200) deliveries. Try a declined card too: **4000 0000 0000 0002**.
7. **Go live:** repeat steps 3–4 in Stripe's **live mode** (live keys and a separate live webhook endpoint with its own signing secret) and enter them under *Live keys*. Switch **Mode** to **Live**. Make one small real booking and refund it in Stripe.

Keys are stored only on that website: they're never shown again (only the last 4 characters), and never included in Import & export.

**In Stripe → Settings → Payment methods**, keep cards (and Apple Pay / Google Pay). Avoid slow methods such as SEPA Direct Debit: they take days to confirm, and the room stays reserved (up to 7 days) until Stripe reports the result.

**What happens when a guest pays by card:**
1. The guest fills in the form and clicks **Continue to payment**. The booking is saved as **Card payment in progress** and the room is **held** for 20–30 minutes (*Hold the room for*, default 30). Nobody else can book it meanwhile.
2. The guest pays on Stripe's page. Card details go only to Stripe; the website never sees or stores them. It stores only Stripe's IDs, the amount, currency, status and time.
3. Stripe tells the website (**signed webhook**) that the payment succeeded. Only then is the booking **Confirmed**, and the guest gets *Payment received – your booking is confirmed* while the hotel gets the *New booking* email. Coming back to the website never confirms anything by itself. The page the guest returns to waits for Stripe's confirmation, then shows the result.
4. Stripe sometimes repeats a notification. Each one is processed only once, so there is never a second confirmation or email.

**If the guest doesn't pay:**
- **Back on the payment page:** the website shows "Your payment has not been completed. The room is reserved for you until 14:35", with a **Pay now** button.
- **Card declined:** the guest can try another card while the room is held. If the time runs out after a decline, the guest gets *Payment failed*.
- **Abandoned:** when the hold ends, the room is released automatically (no email), and the booking shows as **Not paid in time**.
- **Paid too late:** if a payment still arrives after the hold ended, the booking is confirmed if the room is still free. If someone else has taken it, the booking is **not** confirmed (no double booking). It's marked **⚠ Payment conflict**, you get an *Action needed* email and a box at the top of the bookings list, and the guest is told the hotel will contact them. Offer another room or dates (then confirm the booking), or refund in Stripe. Then click *Mark as resolved*.

**Refunds** are made in the Stripe Dashboard (Payments → the payment → *Refund*, full or partial). The refund appears on the booking automatically, the payment status becomes *Refunded* or *Partially refunded*, and the hotel gets an email. The booking itself isn't changed, so cancel it if the stay is cancelled. The Stripe transaction ID on the booking links straight to the payment in Stripe.

### Bank transfer

**Settings → Payments → Bank transfer:**
- **Beneficiary, IBAN, BIC/SWIFT and bank.** Bank transfer is offered only when at least the beneficiary and IBAN are filled in.
- **Payment reference:** default the booking reference. You can use `{booking_ref}`, `{guest_name}` and `{check_in}`, e.g. `Booking {booking_ref}`.
- **Payment deadline:** days after booking (or after you accept a request), until the end of that day (default 3).
- **Reminder:** days before the deadline (default 1; 0 = none). It's sent between 08:00 and 21:00.
- **Not paid in time:** cancel automatically after the deadline, with emails to the guest and to you (default on; can be switched off).

**With Instant booking:**
1. The booking is saved as **Waiting for bank transfer** and the room is kept for the guest.
2. The success page and the email *Payment details for your booking* show the amount, beneficiary, IBAN, BIC, bank, payment reference and deadline, with copy buttons on phones.
3. When the money arrives, open the booking. Under **Payments**, check the amount and click **Payment received**. The booking becomes **Confirmed** and the guest gets *Payment received*.

**With Booking requests:**
1. The guest is told the bank details follow once you confirm.
2. The button on the request reads **Confirm & ask for payment**. It moves the booking to *Waiting for bank transfer*, emails the bank details and starts the deadline.
3. **Confirm without payment** is there for trusted guests.

Reminders and automatic cancellations run once an hour through WordPress's scheduled tasks. On quiet sites, add a real server cron job (§12).

### Payment statuses

| Shown as | Meaning |
|---|---|
| Card payment in progress | The guest is paying by card; the room is held until the time shown |
| Awaiting deposit / Waiting for bank transfer | Waiting for a bank transfer until the deadline; the room is kept |
| Deposit received / Payment received | Paid (part or all) |
| Payment failed | The card payment didn't go through; the room was released |
| Not paid in time | The guest didn't pay within the hold; the room was released |
| Partially refunded / Refunded | Money was returned (in Stripe or recorded by you) |
| Pays at the property | Nothing online; everything is paid on arrival |
| ⚠ Payment conflict | Paid, but the room was taken meanwhile; see above |

Each booking page has a **Payments** box with the payment method, amount due when booking, paid, refunded and **remaining balance**, plus the full history (date, type, method, amount, transaction ID, note, who recorded it). Under *Record a payment or refund* you can record what's paid at the property (cash, card at the property, bank transfer, other) and refunds you made yourself.

### Receipts and fiscal obligations

Flexo Booking records payments so you can manage your bookings. It does **not** issue fiscal receipts or invoices. Fiscal and receipt obligations for payments received by card or online (in Bulgaria, for example, **Наредба Н-18**) remain the **hotel's responsibility**. Ask your accountant how to document online card payments and bank transfers, and whether your Stripe setup needs to be registered.

---

## 12. Calendar sync (Booking.com, Airbnb…)

Calendar sync reduces the risk of double bookings when a property also sells on Booking.com, Airbnb, Vrbo or similar sites. It uses standard **iCal links**, which every booking site supports. It is not a channel manager: prices and room details are still managed on each site separately.

Switch it on under **Settings → Features → Calendar sync**. A **Calendar sync** screen appears (linked from **Settings** and **Rooms & prices**) with one card per room. Each card has:

- **This room's calendar link**, which you give to Booking.com, Airbnb and so on
- **Calendars imported into this room**, where you paste their links

### Step by step: Booking.com

1. **Bookings → Settings → Calendar sync**, room card → **Copy link**.
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
- **one email** to the notification address (**Bookings → Emails**), naming the room, calendar, dates and overlapping booking reference

Resolve it with the guest or the booking site, then click **Mark as reviewed**. Cancelling one of the bookings clears the conflict automatically.

*Airbnb "echo":* Airbnb's own export also contains dates it imported from your website, labelled *Airbnb (Not available)*. If such a copy lands on exactly the same dates as your website booking, the conflict note says so (*"Same dates as FB-… – the other website may be repeating your own booking back"*), so you can simply mark it as reviewed.

### Switching Calendar sync off

Syncing stops, the **Calendar Sync** screen disappears, and your export links answer "not found". Imported bookings **no longer block** your website's availability. Your connections and the last imported bookings are kept, and switching it on again restores everything at the next sync.

---

## 13. The booking calendar

**Bookings → Calendar** is the hotel's overview. It shows one month, with rooms as rows and days as columns.

- **Today at a glance** (top): who is **arriving today**, **leaving today** and **staying tonight**.
- **Bars** start in the afternoon of arrival and end in the morning of departure, so a departure and an arrival on the same day sit side by side.
- **Every type has its own colour, icon and pattern**, so it's readable without relying on colour. The legend is under the calendar:

| Looks like | Meaning |
|---|---|
| ✓ green | Confirmed website booking |
| ⏳ yellow, dashed | Pending request waiting for you |
| 💳 striped yellow | Held while the guest pays by card (disappears if the hold ends unpaid) |
| 🏦 orange, dashed | Awaiting bank transfer |
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

## 14. Putting the booking form on the site

1. **Booking page (required):** edit your *Booking / Reservations* page in Elementor, search the panel for **"Flexo"** and drag in **Flexo Booking Form** (*FlexoHotels* category). Keep *Layout* set to **Full booking form**. Note the page path (default `/booking/`).
2. **Hero search bar (optional):** add the widget on the home page with *Layout* **Search bar** and *Booking page path* `/booking/`.
3. **"Book now" buttons:** on the Button widget's *Link*, choose the dynamic tag **Room booking link** (group *Flexo Booking: room*). In a room template or Loop item it books **the room being shown**, so one button serves every room; elsewhere pick the room. It opens the booking page from **Settings → Hotel** (or the page you enter), and with *Keep the dates and guests from the page address* on, a guest who came from a search lands on the rooms with their dates already chosen. Or type `/booking/?room=<slug>`; every room's link has a **Copy** button in the room editor and the Rooms list.
   - **Room booking box** (widget, or `[flexo_room_booking]`) on a room page: the guest chooses dates and guests there and sees at once whether *this* room is free and the total; **Book now** opens the booking page straight at the details step. When it isn't free, nearby free dates and a link to other rooms for the same dates are shown.
4. **Without Elementor**, use the shortcodes:
   - `[flexo_booking]`
   - `[flexo_booking room="deluxe-double"]`
   - `[flexo_booking layout="search" booking_page="/booking/"]`

5. **Tell the plugin which page it is** (optional): **Settings → Hotel → Booking page**. Left empty, the plugin finds the page with the form by itself (shortcode or Elementor widget). Links in emails (*Add to calendar*, *Manage your booking*) open this page, and **Settings → Health** warns when no published page has the form.

**Styling:** by default the form picks up the site's **Elementor Global Colors and Fonts** (or the theme's), so it matches each template automatically. To change it for the whole site, use **Bookings → Appearance** (§15). To change one widget, use its **Style** tab. To change the markup, copy `templates/booking-form.php` or `templates/search-bar.php` to `wp-content/themes/<theme>/flexo-booking/` (copies made before 1.6.0 keep working; the script adds the step bar, summary and date picker around them).

### What guests see

1. **Steps:** *Dates → Room → Your details → (Payment) → Confirmation*, shown at the top. *Payment* appears only when guests are sent to Stripe. Once rooms are shown, the search collapses to one line (*15–18 Oct · 2 adults · Change*).
2. **Date picker:** a calendar that shows which days are full or closed (greyed **and** crossed out), where arrival isn't possible, and the minimum stay once an arrival is chosen. Keyboard and screen-reader friendly, Monday first. Optionally it shows the lowest price per night under each date (**Settings → Booking rules → Show the lowest price per night under each date**, off by default).
3. **No dead ends:** when nothing is free, the guest sees up to three nearby dates of the same length that are free, other rooms that fit, and a **Send an enquiry** form. Enquiries are emailed to the hotel, appear under **Today → Needs your attention**, and are deleted automatically after 12 months (they are not bookings).
4. **Room cards:** photo, size, beds, max guests, up to five amenities, the **total for the stay** and the average per night. Rates are compared in one line each: name · meals · cancellation · total.
5. **Summary always visible:** beside the form on wide pages, and as a small bar at the bottom on phones that opens the full price breakdown.
6. **Details:** required fields are marked *, optional ones say "(optional)". The phone has a country selector (default from **Settings → Booking rules**) and is saved as `+359 888 123 456`. Mistakes are explained under the field as soon as the guest leaves it. A **Before you book** box repeats the cancellation terms, what is paid now and at the property, and the hotel's contact. The final button says exactly what happens: *Send booking request*, *Confirm booking*, *Confirm and pay 240.00 € by bank transfer* or *Continue to secure payment – 240.00 €*. It can't be pressed twice.
7. **Confirmation:** reference, full summary, what happens next (e.g. *We'll reply within 24 hours*, from **Settings → Hotel → You reply to requests within**), the hotel's contact, **Add to calendar** (.ics) and **Directions** (from the hotel address).
8. **Back, forward and refresh** work: dates, guests, room, rate, promo code and the step are kept in the address (no personal data). The confirmation shows again after a refresh.

**On phones and tablets** (up to 1024 px wide) the flow is centred, buttons are at least 44 px tall, and the amount to pay and the continue button stay visible at the bottom. Tested at 360, 390, 414, 768 and 1024 px and on desktop.

---

## 15. Appearance of the booking form

**Bookings → Appearance** (feature *Appearance settings*, on by default).

- **Match my website** (default, and what every upgraded site keeps): the form uses the website's Elementor global colours and fonts, or the theme's. Nothing is added.
- **Custom:** choose
  - **Colours:** main colour (buttons, selected options, links), accent (badges such as "Only 2 left!"), text, background, button text. Each has **Use website colour**.
  - **Corners:** square, slightly rounded or rounded.
  - **Fonts** for headings and text: *Website font* or one of **Inter, Roboto, Open Sans, Manrope, Montserrat, Lora, Playfair Display**. The fonts are part of the plugin, include Cyrillic, and are loaded from the hotel's own website – **never from Google** (GDPR). Only the chosen fonts are loaded, and only on pages with the form.
  - **Text size:** small, normal or large.
- **Live preview** beside the settings, with the real form and your site's styles, in desktop or phone width. Changes show before saving.
- **Contrast warnings** when text and background, or button text and the main colour, are hard to read (below 4.5:1), with a suggested colour.
- **Reset to website style** (after a confirmation) goes back to *Match my website*.
- **Emails:** logo and email colour are set here too (in Custom style, emails use the main colour).
- **Only the booking form and the emails change.** The settings are CSS variables on `.flexo-booking`, so the rest of the website is never affected.

**Which setting wins** (highest first):
1. The **Style tab of an Elementor Flexo Booking widget** – for that widget only.
2. **Appearance → Custom** – for every booking form on the site.
3. The **website's global colours and fonts** (Elementor Site Settings or the theme).

The Appearance settings travel with **Import & export** (§24).

---

## 16. Guest booking page (manage booking)

Feature **Guest booking page** (off by default; **Settings → Features**).

- Every guest email gets a private **Manage your booking** link (also available as `{manage_link}` in the email texts). It opens the booking page and shows the booking, what was paid, the bank details when a transfer is due, and the hotel's contact.
- The guest can send a **cancellation or change request** with a message while the booking is active and the stay hasn't ended. The hotel gets an email, and the request appears on the booking (**Open requests**) and under **Today → Needs your attention**. **Nothing changes automatically** – the hotel decides and acts as usual.
- The link contains a key that only works for that booking; the page shows the guest's email partly hidden. Up to three requests per booking per day.
- Without the feature the confirmation page and **Add to calendar** still work; only the manage link and requests are hidden.

---

## 17. Daily use: managing bookings

### How the admin looks (1.7.0)

The plugin's screens have their own clean design, so they feel like one booking app rather than a set of WordPress forms:
- **App bar** at the top of every booking screen: the Flexo Booking mark with the hotel's name, a **booking search** (reference, name, email or phone – from any screen), a **bell** with the number of things that need attention (opens Today), **open the booking form** in a new tab, **Help**, and **New booking**.
- **Page header** with the screen's title, a one-line explanation, its main actions and – for *Rooms & prices* and *Settings* – the tabs.
- **Cards** instead of long forms: settings are grouped in cards with a **Save** bar that stays in reach at the bottom of long pages; **Features** are cards with on/off switches; each **guest email** is a card showing when it is sent, with *Edit text* to open the subject and message.
- **Today** starts with four tiles (arriving, leaving, staying tonight, occupancy), then *Needs your attention* with coloured icons and one-click actions, the guest lists, and a side column with the next 7 days and shortcuts.
- **Booking details**: the actions sit in the header; stay, guest (with *Email* and *Call* buttons), price and payments on the left; notes, emails and the history timeline on the right.
- **Phones:** the app bar shows a scrollable row of section buttons (the WordPress menu is folded away there), lists become cards, and buttons and fields are at least 44 px tall.
- The design uses the bundled Inter font (Latin and Cyrillic, loaded from the hotel's own site, never from Google) and is applied **only to the plugin's own screens**. The rest of the WordPress admin and the website are not affected, and the Appearance settings (§15) change the booking form, not the admin.

### The menu

**Today · Calendar · All bookings · Add booking · Rooms & prices · Promo codes · Emails · Appearance · Settings · Help.** Only switched-on features appear. **Rooms & prices** has tabs for *Rooms*, *Seasonal prices*, *Closed dates* and *Rates*; **Settings** links *Calendar sync* and *Import & export*. The number on the menu counts what needs attention. A **Booking form** link in the admin bar opens the booking page.

### Today

The first screen under **Bookings**:
- **Arriving today**, **Arriving tomorrow**, **Leaving today** and **Staying tonight**, each with guest, room, nights, a tap-to-call phone and what is paid or still due.
- **Needs your attention**, with one-click actions: requests waiting for confirmation (**Confirm**), bank transfers waiting (with the deadline) and overdue (**Payment received**), payment conflicts, possible double bookings from external calendars, calendars that stopped updating, emails that failed, guests' change or cancellation requests and enquiries (**Mark as handled**).
- **The next 7 days** (arrivals, departures, nights sold) and **occupancy this month**.

### All bookings

Search by reference, name, email or phone, and filter by **status**, **payment status**, **room**, **arrival between** two dates and **source** (website, staff, each external calendar). Rows have quick actions (**Confirm**, **Cancel**, **Payment received**, **Open**). On a phone the list turns into cards.

Click a **reference** to open the booking. The actions are at the top; below are the **guest** (tap to call or email), **stay**, **price** with its full breakdown, **payment**, **invoice request**, the guest's **open requests**, **internal notes** and the **history**.

| Status | Meaning | Holds the room? |
|---|---|---|
| Waiting for confirmation | Request waiting for you | Yes |
| Confirmed | Confirmed | Yes |
| Cancelled | Cancelled | No |
| Dates blocked | Dates closed by staff | Yes |
| Card payment in progress | The guest is paying by card (§11) | Yes, until the hold ends |
| Waiting for bank transfer | Waiting for a bank transfer (§11) | Yes, until paid or cancelled |
| Not paid in time | The card payment wasn't completed in time | No |

With payments on, each booking also shows its **payment status** (e.g. *💳 Deposit received*, *🏦 Awaiting deposit · until 27.09.*), and bookings waiting for a transfer have a **Payment received** button (§11).

Actions on each booking (each asks first and says what will happen, e.g. that a deposit is **not** refunded automatically):
- **Confirm** emails the guest.
- **Cancel** frees the room and emails the guest.
- **Restore booking** (cancelled or not paid in time) works only if the room is still free.
- **Delete** removes the booking for good.
- **Internal notes** are for the team only and never shown to the guest.
- **Resend an email:** choose any guest email that applies (confirmation, payment details, reminder…) and send it again.
- **History:** who did what and when – created, confirmed, cancelled, restored, payments and refunds, emails sent and resent, notes, guest requests, personal data removed.

Each booking shows where it came from: **Website**, **✎ Added by staff** or **⛔ Dates blocked**. With Calendar sync on, a second tab, **⇄ From external calendars**, lists the imported bookings (read-only), and a **⚠ Conflict** box appears at the top when something needs attention (see §12).

**Add booking** records phone, walk-in or other-channel bookings, or blocks dates. It is also reachable from empty days in the **Calendar**. When the features are on, staff can enter **children's ages** (e.g. `4, 11`), choose a **rate** (only the chosen room's rates are listed; the room's first rate is used by default) and apply a **promo code**; the price is calculated automatically.

**Export CSV** includes a *Price details* column and, at the end (so existing spreadsheets keep working), *Children ages*, *Rate plan*, *Refundable*, *Promo code*, *Discount*, *Tourist tax*, *Payable at property*, the language, consent and invoice columns, and the payment columns *Payment method*, *Payment status*, *Due when booking*, *Paid*, *Refunded*, *Balance*, *Payment deadline* and *Transaction IDs*.

### Who can do what (roles)

| Role | Can |
|---|---|
| **Hotel Staff** (new) | Today, calendar, all bookings, add bookings and block dates, confirm/cancel/restore, record payments received, notes, resend emails. Sees a trimmed admin (no posts, pages or Elementor) and lands on **Today** after logging in. |
| **Hotel Manager** (new) | Everything staff can, plus rooms and prices (seasons, closed dates, rates), promo codes, emails (texts, notification addresses, hotel phone, review link) and appearance. No other settings. |
| **Editor** | As before 1.6.0: bookings, rooms and prices, promo codes, emails and appearance. |
| **Administrator** | Everything, including Settings, payments keys, Import & export and features. |

Give reception accounts **Hotel Staff** under **Users → Add New → Role**. Which features exist stays with FlexoHotels (§2). The roles are built on the capabilities `flexo_manage_bookings`, `flexo_manage_prices` and `flexo_manage_settings`, so role-editor plugins can adjust them.

**No double bookings:** availability is counted night by night against the number of rooms. The final check, price calculation and save happen under a per-room lock. When two guests try to take the last room at the same moment, one gets it and the other is told it's no longer available.

---

## 18. Health check and help

**Bookings → Settings → Health** checks, with ✓ / ⚠ / ✕ and a one-line fix for each:
- the **database update** finished;
- there are **rooms** with prices;
- a published **booking page** contains the form;
- **email sending**: an SMTP plugin is active, the last test email went out, no emails failed in the last 7 days;
- **scheduled jobs (WP-Cron)** ran in the last few hours (reminders, payment deadlines and calendar sync depend on them);
- **calendar sync**: each connected calendar is updating;
- **card payments (Stripe)**: keys and webhook secret for the chosen mode, test or live, the last notification from Stripe, and a **Check the keys** button (one call to Stripe);
- **bank transfer** details entered;
- a **privacy policy** page;
- **time zone** (a city, not a UTC offset) and currency.

**Copy system report** copies versions, features and settings (without keys, passwords or guest data) for FlexoHotels support.

**Bookings → Help** has short guides (add a room, seasonal prices, connect Booking.com, handle a request, block dates, bank transfer received, refunds), the setup wizard and the FlexoHotels support contact. Small **?** links next to settings open the matching guide. The support contact is set in `wp-config.php`:

```php
define( 'FLEXO_BOOKING_SUPPORT', 'FlexoHotels|support@flexohotels.com|+359 88 000 0000|https://flexohotels.com/support' ); // name|email|phone|web page
```

or on the **Agency** screen.

---

## 19. Privacy and data retention

**Always available** (whatever the feature switches):
- **Tools → Export Personal Data** and **Tools → Erase Personal Data** include the guest's bookings, invoice details, privacy consent and the emails sent to them. Search by the guest's email address. *Erase* anonymises the bookings (see below) and removes the emails from the email log.
- **Settings → Privacy → Policy Guide** has a suggested *Room bookings* section for your privacy policy. Copy it into your policy page and replace *[X] months* with your retention period. It includes a paragraph about card payments through Stripe; delete it if the hotel doesn't take card payments.

**Switch on Settings → Features → Privacy consent** for the rest. Settings are under **Bookings → Settings → Privacy**:

| Setting | Default | Notes |
|---|---|---|
| Consent checkbox: guests must tick it to book | On | Off = the checkbox is shown but optional |
| Text next to the checkbox | *I agree to the {privacy_policy} and consent to my information being processed for the purpose of my booking.* | `{privacy_policy}` becomes the link |
| Privacy policy page | Empty = the page chosen under **Settings → Privacy** | A path such as `/privacy/`. With Polylang/WPML the page in the guest's language is used. |
| Remove guest details … months after check-out | 0 = never | See *Data retention* |

**Consent.** The checkbox is **never ticked in advance**, and the server checks it too, so a booking can't be sent without it. For each consent the booking keeps the **date and time**, the **exact text** the guest agreed to (in their language, with the privacy page address) and a **text version** (a fingerprint of that text), which changes whenever you edit the text. You can see it on the booking's page and in the CSV.

**Data minimisation.** Under **Settings → Booking rules → Guest details form**, only name and email are always required. *Phone* can be required, optional or not asked, and *Special requests* optional or not asked. Fields that aren't asked are never stored, even if something sends them.

**Data retention.** With a number of months set, once a day every booking whose check-out is older than that is **anonymised**: the guest's name, email, phone, special requests and invoice details are removed. The dates, room, guests, prices, promo code and status stay, so your statistics keep working. The consent record stays as proof, without personal data. It's off by default. Many hotels choose **24 months**, which still covers returning guests and complaints. Invoices you issued live in your accounting software and follow accounting law. Ask your accountant or lawyer if unsure.

**Remove one guest's personal data now.** Open the booking (click its reference) → **Remove personal data**, and confirm. It can't be undone; the booking then shows *Guest (personal data removed)*, with dates, room and price kept.

**Payments and personal data.** Card details never reach the website. The payment history keeps only amounts, dates, Stripe's transaction IDs and staff notes, no personal data, so it stays when a booking is anonymised (it's needed for your accounts). The guest's email is passed to Stripe for the receipt; Stripe processes the payment under its own terms and privacy policy.

---

## 20. Invoice requests

Switch on **Settings → Features → Invoice request**. The guest details form then shows **☐ I would like an invoice**. When it's ticked, the guest chooses **A person** or **A company**:

| For | Fields (default) |
|---|---|
| A person | Full name (required), Address (required) |
| A company | Company name (required), Company ID – EIK/BULSTAT (required), VAT number (optional), Registered address (required), Contact person (optional) |

Under **Settings → Taxes & invoices** each field can be **Required**, **Optional** or **Hidden**.

**Checks are light on purpose.** A company ID made only of digits must have 9 or 13 digits, the Bulgarian EIK/BULSTAT format; spaces are removed. IDs with letters (foreign companies) and VAT numbers of any country are accepted as typed.

**Where you see it:**
- **Bookings list:** 🧾 *Invoice* badge.
- **Booking page:** an *Invoice requested* box with all details.
- **Hotel's new-booking email:** an *INVOICE REQUESTED* block.
- **CSV:** *Invoice*, *Invoice name / company*, *Company ID*, *VAT number*, *Invoice address* and *Contact person* columns.

The details are stored apart from the guest details, and are included in the WordPress personal data export and erase. This is **not** an invoicing system: it only collects the details, and you issue the invoice in your accounting software.

---

## 21. Emails

**Bookings → Emails** (Administrators, Editors and Hotel Managers).

**Your hotel:**
- **Send hotel notifications to:** one or more addresses separated by commas, e.g. `reception@hotel.bg, owner@hotel.bg`. Guests' replies go to the first one. It's empty on a new site, which means the WordPress admin email.
- **Notify the hotel about:** new booking or booking request · booking cancelled · possible double booking from an external calendar (with Calendar sync on) · refunds made in Stripe and payments received for a room that is no longer free (with card payments on). Each can be switched off. A card booking is announced to the hotel only once it is paid.
- **Hotel phone**, for `{hotel_phone}` and the email footer.
- **Email design:** colour and logo are set under **Bookings → Appearance** (§15). Leave the logo empty to use the site logo.

**Emails to guests** (with the *Guest emails* feature on), each with an editable subject and text:

| Email | When |
|---|---|
| Booking request received | A guest sends a booking request |
| Booking confirmed | Instant booking, or you click **Confirm** |
| Booking cancelled | You cancel the booking |
| Before arrival (reminder) | Optional. *N* days before arrival (default 3), to confirmed bookings. Add check-in time, directions and parking to the text. |
| After the stay (review request) | Optional. *N* days after check-out (default 1), with your **review link** (e.g. Google). Without a link, it isn't sent. |

With payments on, these are added (only for the ways of paying that are switched on):

| Email | When |
|---|---|
| Waiting for payment (bank details) | Bank transfer booked (instant) or request accepted; contains `{payment_instructions}` |
| Payment reminder | *N* days before the bank transfer deadline |
| Payment received | A card payment succeeded, or you clicked **Payment received**; it's also the confirmation |
| Payment failed | A card payment didn't go through and the room was released |
| Cancelled – payment not received | The bank transfer deadline passed (automatic cancellation) |

**Placeholders:** `{guest_name}` `{booking_ref}` `{room}` `{check_in}` `{check_out}` `{nights}` `{guests}` `{rate_plan}` `{total}` `{price_breakdown}` `{cancellation_policy}` `{promo_code}` `{status}` `{booking_details}` `{check_in_time}` `{check_out_time}` `{hotel_name}` `{hotel_phone}` `{hotel_email}` `{review_link}` `{guest_email}` `{guest_phone}` `{payment_method}` `{amount_due}` `{amount_paid}` `{balance_due}` `{payment_deadline}` `{payment_instructions}` `{manage_link}`. With payments on, `{booking_details}` also lists how the booking is paid, what was paid and what is left to pay at the property. The older `{reference}` and `{site_name}` still work.

**Scheduled emails** (reminders and review requests) are sent by WordPress's scheduled tasks once an hour, between 08:00 and 21:00 hotel time. They go **only once per booking** and **never for cancelled bookings**: the booking is checked again just before sending. A review request is sent only within 2 days of its due date, so switching it on doesn't email guests from long ago. On quiet sites, set up a real server cron job (as for Calendar sync, §12) so they go out on time.

**What the emails look like:** simple HTML that works on phones, in the hotel colour with the logo, plus a plain-text version for email programs that don't show HTML. Booking details appear as a clear table (booking, payment, next steps), a single link such as the manage link becomes a button, and the footer shows the hotel's address and contact.

**Calendar file and manage link:** *Booking confirmed* and *Payment received* carry the stay as an `.ics` attachment (add to calendar). With the **Guest booking page** on (§16), guest emails end with a **Manage your booking** link unless the text already contains `{manage_link}`.

**Resending:** any guest email that applies to a booking can be sent again from the booking's page (§17); the history records it.

**Deliverability, please read.** By default WordPress sends email from the web server without logging in to a mail server. Many providers then put booking confirmations in spam or drop them. While no SMTP plugin is detected, a yellow notice on the booking screens says so.
1. Install **WP Mail SMTP** or **FluentSMTP** (both free).
2. Connect it to the hotel's email account: its SMTP server, or Gmail/Google Workspace or Microsoft 365. Use an address on the hotel's own domain, e.g. `booking@hotel.bg`, and set up SPF and DKIM for that domain with the hosting company.
3. Go to **Bookings → Emails → Send a test email**. It sends the *Booking confirmed* email with example details.
4. Check **Recent emails** at the bottom of the same page. Every email the plugin sends is listed with recipient, type, time and **Sent** or **Failed** (with the reason). *Sent* means the website handed the email to the mail server. The log is kept for 90 days by default (configurable) and cleaned up automatically. Each booking's page also lists its emails.

---

## 22. Languages: Bulgarian, English, Polylang and WPML

- **Everything is translatable:** admin screens, booking form, messages and emails use the `flexo-booking` text domain. The plugin ships a **complete Bulgarian translation** (`languages/flexo-booking-bg_BG.*`), and English is built in. On a site set to Bulgarian (**Settings → General → Site Language**), everything is Bulgarian. `languages/flexo-booking.pot` is the template for other languages (e.g. with Loco Translate).
- **Dates:** guests see dates in their language's format: **DD.MM.YYYY** for Bulgarian (e.g. *28.10.2026*), *October 28, 2026* for English. The admin always uses DD.MM.YYYY.
- **The guest's language is stored on each booking** (shown on the booking's page and in the CSV), and **all their emails are sent in that language**, including later ones such as the confirmation, reminder and review request, whichever language the staff member's admin uses. **Hotel notifications use the site language.**
- **Email texts:** texts you haven't changed are translated automatically into the guest's language. Texts you changed are sent as you wrote them, unless you translate them with Polylang or WPML (below).

**Polylang** (tested) and **WPML** (supported through WPML's standard hooks, not tested here because WPML is a paid plugin):
1. Create the booking page in each language (e.g. `/booking/` and `/en/booking/`) with the Flexo Booking Form widget or `[flexo_booking]`, and link them as translations. For the hero search bar in each language, set *Booking page path* to that language's booking page.
2. The form follows the page's language: texts, prices, dates, messages, and which language the booking is stored in.
3. The **privacy policy**, **terms** and **thank-you** pages are opened in the guest's language when you have translated them and linked the translations.
4. Your own texts (email subjects and texts you changed, the consent text, rate plan names, descriptions and cancellation texts) appear under **Languages → Translations** (Polylang) or **WPML → String Translation**, in the group **Flexo Booking**. Translate them there.
5. **Rooms in several languages:** rooms and room types are translatable like pages. Translate a room with the language buttons in the Rooms list: the translation has its own name, descriptions, beds, view, amenities, details, search texts and **its own address** (e.g. `/en/rooms/sea-view-double/`). **Prices, guests, identical rooms, photos, seasons, closed dates and bookings always come from the room in the main language** (the translation's Prices card says so and links to it), so a translation is never an extra room, availability is never split, and a booking made in English is stored on the main room. The booking form shows each room's name and amenities in the guest's language. Rooms made before Polylang was set up are given the main language automatically. WPML reads the same rules from `wpml-config.xml`.

---

## 23. Conversion tracking (Google Tag Manager, GA4, Meta)

Switch on **Settings → Features → Conversion tracking**. The booking form then adds events to the Google Tag Manager **data layer** (`window.dataLayer`). The plugin does **not** add Google or Meta scripts itself, so your cookie/consent plugin and Tag Manager (with Consent Mode) stay in control of what is sent.

| Event | When | Data |
|---|---|---|
| `search` | The guest searches | `search_term`, `check_in`, `check_out`, `nights`, `adults`, `children` |
| `room_select` | The guest chooses a room (and rate plan) | `room`, `rate_plan`, `value`, `currency`, `ecommerce.items` |
| `begin_checkout` | The details form is shown | `value`, `currency`, `ecommerce` (GA4 format) |
| `booking_complete` | The booking or request was sent | `booking_reference`, `booking_status`, `room`, `rate_plan`, `value`, `currency`, `ecommerce.transaction_id` |

- **No personal data:** names, emails, phones and invoice details are never included.
- **No duplicates:** `booking_complete` fires once per booking reference, and reloading the page or the thank-you page doesn't repeat it. With a thank-you page, the form waits until Tag Manager has received the event (at most 1.5 s) before moving on.

**Setting up GA4 in Tag Manager:**
1. Install your Tag Manager container on the site: a GTM plugin, the theme, or the Elementor custom code.
2. In GTM, create **Triggers → Custom Event** for `search`, `begin_checkout` and `booking_complete`.
3. Create a **GA4 Event** tag for each. For `booking_complete` use the event name `purchase`, tick *Send Ecommerce data* (data layer), and add the parameter `transaction_id` = `{{DLV – booking_reference}}` (a Data Layer Variable). The value and currency come with the ecommerce data. For `begin_checkout`, send the ecommerce data the same way.
4. Mark `purchase` as a key event (conversion) in GA4. Booking **requests** are counted when sent. Filter by `booking_status` (`pending` / `confirmed`) if you only want confirmed ones.

**Meta Pixel:** in Tag Manager, add Meta Pixel tags on the same triggers (`Search`, `InitiateCheckout`, `Purchase` with value and currency). Only if the pixel is on the site **without** Tag Manager, tick **Settings → Tracking → Also send … to the Meta Pixel directly**. Don't use both, or events are counted twice.

---

## 24. Templates and moving between sites

| Part | Where it lives | How it moves |
|---|---|---|
| Plugin code (including the bundled fonts) | `wp-content/plugins/flexo-booking` | Install the zip, or it comes with a full-site clone |
| Widget placement and styling | Elementor page/template data | Elementor template/kit export |
| Rooms (**with their room page content: texts, types, amenities, details, photos**), **seasons, closed dates**, settings (incl. child prices, tourist tax, **email texts, privacy, invoice fields, tracking and payment settings, Appearance**), enabled features, **rate plans** (and which rooms offer them), **promo codes** | Posts, plugin tables, options | **Bookings → Import / Export** (JSON) or WP-CLI |
| Stripe keys and webhook secrets, the hotel's bank account, notification addresses | Options | **Never exported**: enter them on each site |
| Calendar connections (Booking.com/Airbnb links) | Plugin table | In the export file, but imported **only when ticked** (moving the same hotel) |
| Bookings | `wp_flexo_bookings` table | Full-site migration, or export with "include bookings" |

**A. Prepare a template (once):**
1. Install the plugin.
2. Add demo rooms, their seasons and any closed dates.
3. Switch on the features the template should ship with.
4. Place the widgets.
5. **Settings → Import & export → Download export file** and keep the file with the template.

**B. New client site from a template:**
1. Clone the site, or install the plugin **before** importing the Elementor kit.
2. **Settings → Import & export → Import** the template's file.
3. Adjust rooms, prices, seasons, the **notification addresses**, the **hotel phone**, the **review link** and the **privacy page**. Notification addresses are never exported, so the new site uses its own admin email until you set them.
4. **Payments:** the payment choice, deposit, hold time and bank-transfer rules come with the file, but the **Stripe keys and webhook secrets** and the **bank account** (beneficiary, IBAN, BIC, bank) never do. A copied site can't take money into the template's account. Until they're entered, guests aren't asked to pay online (bookings say "pay at the property"). Enter the hotel's own keys, create its own Stripe webhook (§11), and fill in its bank details.
5. Make a test booking (in Stripe test mode first, if card payments are used).

How the import behaves:
- Rooms are matched by **slug**, so re-importing updates them and never duplicates.
- A room's seasons in the file **replace** that room's seasons.
- Closed dates are added unless an identical one exists.
- Enabled features are applied only if they're available on the new site.
- **Rate plans** are matched by name (updated, never duplicated) and the file decides which plans each imported room offers, including room-specific amounts. Each room's own child prices and max adults come along.
- **Promo codes** are matched by code. Their room and rate-plan limits are re-linked by room slug and plan name. **Usage is not copied**: it's counted from each site's own bookings, so it starts at 0.
- Calendar connections are **not** imported unless you tick *Import calendar connections* (or use `--calendars`). A template's Booking.com links belong to the template, not to the client. Export links are never copied: every site creates its own, so paste the new links into the booking sites after moving a hotel.
- **Room pages (1.8.0):** texts, room types, amenities (with uploaded icons), details, search texts, show-on-website and display order come along. **Photos** are downloaded from the original site when *Download room images from the source site* is ticked (`--images`), never twice; otherwise the room shows **Download missing photos** in its editor, for later. Demo rooms stay demo rooms.
- Files from 1.0.0–1.4.0 still import.
- With "include bookings" (moving a live hotel), bookings keep their payment status and payment history. A card payment still in progress when the file was made arrives as *Not paid in time*.

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
wp flexo-booking import template.flexo-booking.json --images      # --skip-settings --skip-rooms --skip-seasons --skip-closures --skip-rate-plans --skip-promo-codes --calendars --bookings
```

---

## 25. Settings reference

**Settings → Hotel:** contact details shown to guests (email, address), check-in and check-out times, booking page (found automatically when empty), thank-you and terms pages as **paths** such as `/terms/`, how quickly you reply to requests, currency (code, symbol, position, number format, decimals), data removal on uninstall.

**Settings → Booking rules:** booking mode, minimum and maximum stay, how far ahead guests can book, guest selector limits (0 children hides that field), child prices (with *Children & ages* on, §7), guest details form (phone required / optional / not asked; special requests optional / not asked, §19), the phone country preselected in the form, and lowest prices in the date picker.

**Settings → Payments** (with *Online card payment* or *Bank transfer* on): what guests pay when booking (nothing online / a deposit as % or fixed amount / the full amount), ways to pay and whether each is ready, Stripe test and live keys, webhook address, hold time (20–30 minutes), bank details, payment reference, deadline, reminder and automatic cancellation (§11).

**Settings → Taxes & invoices** (with *Tourist tax* or *Invoice request* on): tourist tax amount per adult per night, children, included in the total or paid at the property (§9); required / optional / hidden for each invoice field (§20).

**Settings → Privacy** (with *Privacy consent* on): consent checkbox, its text, privacy page, retention period (§19).

**Settings → Tracking** (with *Conversion tracking* on): the events for Tag Manager and the optional direct Meta Pixel (§23).

**Settings → Room pages:** room page design (a page or template used for every room page, or automatic), room page address (`rooms`), the *All rooms* page room pages link back to (found automatically when empty), prices on room pages ("from" price or none), the **Check availability** bar on phones, the table of your room pages, and the Elementor templates for rooms (§3).

**Settings → Features:** see §2. **Settings → Health:** see §18. **Calendar sync** (§12) and **Import & export** (§24) are linked from the same tab bar.

Technical settings are hidden until **Show advanced settings** is ticked (remembered per browser).

**Bookings → Emails:** notification addresses and which notifications, hotel phone, guest email texts with the reminder and review-request schedule and review link, email log period, test email, recent emails (§21). **Bookings → Appearance:** §15. Old addresses such as `…&tab=children`, `…&tab=tourist_tax`, `…&tab=invoices` and `…&tab=emails` still open the right screen.

`{booking_details}` lists the guests (with children's ages), the rate plan, the promo code, the total with its price lines (plan, discount, tourist tax, anything payable at the property) and the cancellation text.

**Uninstalling:** deleting the plugin keeps all data, unless **Settings → Hotel → Data removal** is ticked first. The Hotel Staff and Hotel Manager roles are removed with the plugin (their users keep their accounts).

---

## 26. For developers

**REST API** (public, used by the form):

| Method | Route | Purpose |
|---|---|---|
| GET | `/wp-json/flexo-booking/v1/rooms` | Bookable rooms |
| GET | `/wp-json/flexo-booking/v1/availability?check_in=&check_out=&adults=&children=[&children_ages=4,11][&room=]` | Availability, totals, per-night average, `breakdown`, `min_nights`, `notice` (property closed); per room `quote`, `plans` (one priced view per rate plan) and `price_from` |
| GET | `/wp-json/flexo-booking/v1/quote?room=&check_in=&check_out=&adults=&children=&children_ages=&rate_plan=&promo_code=` | Price of one room, plan and code: `lines`, `subtotal`, `discount_formatted`, `total`, `due_at_property`, `rate_plan`, `promo`, `promo_error`. 429 after too many wrong codes. |
| POST | `/wp-json/flexo-booking/v1/bookings` | Create a booking (`children_ages`, `rate_plan`, `promo_code`, `privacy_consent`, `invoice` object, `locale`, optional `expected_total`). Returns 201; 409 if no longer available, closed, or the price differs from `expected_total`; 400 for an invalid code or missing ages. A `total` field is ignored: the server always calculates the price. |
| GET | `/wp-json/flexo-booking/v1/ical/{room}.ics?token=…` | The room's iCal export (Calendar sync). 403 for a wrong token, 404 while the feature is off. |
| POST | `/bookings` with `payment_method` (`stripe` / `bank_transfer`) and `return_url` | With payments on, the response has `payment`: `redirect` (the Stripe page), or `instructions` (bank details), `state`, amounts and a guest `key`. Since 1.6.0 every created booking also returns `key` and `view` (the confirmation), and validation errors carry the `field` they belong to. 502 if Stripe can't be reached (the hold is released). Amount fields sent by the browser are ignored. |
| GET | `/wp-json/flexo-booking/v1/payment?reference=&key=` | Payment state for the page the guest returns to (`held`, `confirmed`, `failed`, `expired`, `conflict`, `awaiting_transfer`, …). 404 without the right key. Releases a lapsed hold. |
| POST | `/wp-json/flexo-booking/v1/payment/retry` (`reference`, `key`, `return_url`) | A new Stripe page while the hold lasts, or a new hold if the room is still free (409 if not). |
| GET | `/wp-json/flexo-booking/v1/calendar?month=YYYY-MM&months=1–3&room=&adults=&children=` | The date picker: per room and day `free` (`1` free, `0` full or closed, `c` no arrival), the minimum stay for arrivals, and (with the setting on) the lowest price per night. Cached briefly. |
| GET | `/wp-json/flexo-booking/v1/alternatives?check_in=&check_out=&adults=&children=&children_ages=&room=` | When nothing fits: up to 3 date ranges of the same length within ±14 days, and other rooms that fit |
| POST | `/wp-json/flexo-booking/v1/enquiry` | "Send an enquiry" (name, email, phone, message, dates). Emailed to the hotel, logged; rate-limited (filter `flexo_booking_enquiry_limit`). |
| GET | `/wp-json/flexo-booking/v1/guest-booking?reference=&key=[&manage=1]` | The confirmation after a refresh, or the guest booking page (§16). 404 without the right key. |
| POST | `/wp-json/flexo-booking/v1/guest-booking/request` (`reference`, `key`, `type` = `cancel`/`change`, `message`) | A guest's request (§16): emailed and logged, the booking is not changed. 3 per booking per day. |
| GET | `/wp-json/flexo-booking/v1/booking.ics?reference=&key=` | The stay as a calendar file (`text/calendar`) |
| POST | `/wp-json/flexo-booking/v1/stripe-webhook` | Stripe webhooks. Checked against the `Stripe-Signature` header (HMAC-SHA256, 5-minute tolerance); 400 otherwise. Each event ID is processed once. Events from the other mode (test/live) are ignored. |

All routes accept `locale` (e.g. `en_US`): the answer is in that language, and a booking stores it.

**Architecture (1.7.0):**

| Class | Role |
|---|---|
| `Flexo_Booking_Pricing` | The only pricing code: `quote()` → itemised result, stored on each booking as `price_breakdown` |
| `Flexo_Booking_Inventory` | `units_available()`, closed dates, and `with_lock()` (MySQL `GET_LOCK`, with an options-row fallback) |
| `Flexo_Booking_Seasons`, `Flexo_Booking_Closures` | Repositories |
| `Flexo_Booking_Children` | Child rules (global / per room), age parsing, `factor()` |
| `Flexo_Booking_Rate_Plans` | Plans (table `flexo_rate_plans`), presets, room assignments (room meta `_flexo_rate_plans`), `adjustment()` |
| `Flexo_Booking_Promo_Codes` | Codes (table `flexo_promo_codes`), `validate()`, usage from confirmed bookings (`bookings.promo_id`) |
| `Flexo_Booking_Privacy` | Consent evidence (table `flexo_consents`), `anonymise()`, retention (daily `flexo_booking_daily`), WordPress personal data exporter/eraser, policy guide text |
| `Flexo_Booking_Invoices` | Invoice details (table `flexo_invoices`), field settings, light EIK check |
| `Flexo_Booking_Emails` | Guest and hotel emails, templates per language, HTML layout + plain text, email log (table `flexo_email_log`), scheduled emails (hourly `flexo_booking_hourly`), test email, SMTP check |
| `Flexo_Booking_I18n` | Guest language, `with_locale()`, Polylang/WPML strings and pages, date format per language |
| `Flexo_Booking_Tracking` | Settings for the dataLayer events sent by `booking.js` |
| `Flexo_Booking_Payments` | Payment modes, pricing step 95 (payment schedule), holds (`pending_payment` + `hold_expires_at`, released by a single WP-Cron event, the hourly job or lazily), recording payments/refunds (table `flexo_payments`), late-payment conflicts, bank-transfer reminders and deadlines, guest views. Keys in the separate option `flexo_booking_payment_secrets`. |
| `Flexo_Booking_Payment_Gateway` (interface) | `id()`, `label()`, `admin_label()`, `is_ready()`, `is_available()`, `is_hosted()`, `start()`. Built in: `Flexo_Booking_Gateway_Stripe` (Checkout Sessions over `wp_remote_request`, webhooks, idempotency via table `flexo_webhook_events`) and `Flexo_Booking_Gateway_Bank_Transfer`. |
| `Flexo_Booking_Features` | Available/enabled rules |
| `Flexo_Booking_Migrations` + `Flexo_Booking_Schema` | Versioned migrations; tables are only ever added to |
| `Flexo_Booking_Money` | Currency formatting |
| `Flexo_Booking_ICal` | iCal parser, fetch (`wp_safe_remote_get`), sync, conflicts, export feed, tokens, WP-Cron (`flexo_booking_ical_sync`) |
| Imported bookings | Table `flexo_calendar_events` (not bookings rows), counted by `Flexo_Booking_Inventory::nightly_usage()` with the unit rule in §12 |
| `Flexo_Booking_Guest` | Guest keys (HMAC of the booking), confirmation and guest booking page views, requests, `.ics`, date-picker calendar, alternatives, enquiries, booking-page detection |
| `Flexo_Booking_Log` | Booking history and enquiries (table `flexo_booking_log`), cleaned up daily |
| `Flexo_Booking_Phone` | Country codes and names (via `intl` when available), phone normalisation to `+359 888 123 456` |
| `Flexo_Booking_Appearance` | Custom-mode CSS variables on `.flexo-booking`, bundled `@font-face` rules, contrast checks, the preview |
| `Flexo_Booking_Roles` | Hotel Staff / Hotel Manager roles, capabilities, room capabilities, trimmed menu |
| `Flexo_Booking_Admin_UI` | The admin look (1.7.0): body class `flexo-admin-ui` on the plugin's screens only, the app bar, `page_head()` (title, intro, actions, tabs), `icon()` (inline SVG icons), on/off switches, footer line. Styles: `assets/css/admin-ui.css` (design tokens `--fx-*`, fonts, controls, cards, tables, notices) and `admin.css` (screens). |
| `Flexo_Booking_Today_Admin`, `Flexo_Booking_Health`, `Flexo_Booking_Help`, `Flexo_Booking_Wizard` | Today screen and "Needs your attention", health checks and system report, guides and support contact, setup wizard |

**Hooks:**

| Hook | Type | Use |
|---|---|---|
| `flexo_booking_pricing_steps` | filter `( $steps, $request, $room )` | Add pricing steps by priority. 10 nightly, 20 rate plan, 40 promo, 50 tourist tax, 90 totals, 95 payment schedule. |
| `flexo_booking_payment_gateways` | filter `( $gateways )` | Add or replace ways to pay (objects implementing `Flexo_Booking_Payment_Gateway`) |
| `flexo_booking_stripe_session_params` | filter `( $params, $booking )` | Change the Stripe Checkout Session request (e.g. `payment_method_types`) |
| `flexo_booking_stripe_api_base` | filter | Stripe API address (tests point it at a local mock) |
| `flexo_booking_payment_recorded` | action `( $booking, $result, $payment )` | After a payment: `confirmed`, `recorded` or `conflict` |
| `flexo_booking_refund_recorded`, `flexo_booking_hold_released` | actions | After a refund / when a hold ends |
| `flexo_booking_update_status_target` | filter `( $status, $booking, $context )` | The status a change leads to (a request paid by transfer first waits for payment) |
| `flexo_booking_payment_guest_view` | filter `( $view, $booking )` | What the guest sees about the payment |
| `flexo_booking_quote` | filter `( $quote, $request, $room )` | Final quote |
| `flexo_booking_calculate_total` | filter (1.0) | Still applied to the accommodation amount; a change shows as an "adjustment" line |
| `flexo_booking_available_features` | filter `( $keys )` | Where a licence/package module plugs in |
| `flexo_booking_occupying_statuses` | filter | Statuses that take a room |
| `flexo_booking_created`, `flexo_booking_status_changed` | actions | Integrations. `flexo_booking_status_changed` now passes a 4th argument, `$context` (`notify`, `force`, `reason`). |
| `flexo_booking_ical_timeout` | filter | Seconds to wait for a calendar download (default 15) |
| `flexo_booking_email` | filter `( $message )` | Every email just before sending: to, subject, html, text, headers, type |
| `flexo_booking_email_html` | filter `( $html, $subject, $text )` | Replace the email layout |
| `flexo_booking_email_hours_from`, `flexo_booking_email_hours_to` | filters | Hours for scheduled emails (8, 21) |
| `flexo_booking_date_format` | filter `( $format, $locale )` | Guest date format per language |
| `flexo_booking_smtp_detected` | filter | Tell the plugin a mail setup exists (hides the SMTP notice) |
| `flexo_booking_anonymised` | action `( $booking_id )` | After a booking was anonymised |
| `flexo_booking_attention` | filter `( $items )` | Items under Today → Needs your attention (and the menu count) |
| `flexo_booking_health_checks` | filter `( $checks )` | Add or change Health checks (`id`, `status` ok/warning/error, `title`, `text`, `action`) |
| `flexo_booking_help_guides` | filter `( $guides )` | Guides on the Help page |
| `flexo_booking_guest_view` | filter `( $view, $booking )` | What the confirmation / guest booking page shows |
| `flexo_booking_guest_request`, `flexo_booking_enquiry` | actions | After a guest's change/cancellation request, after an enquiry |
| `flexo_booking_before_insert`, `flexo_booking_guest_email`, `flexo_booking_admin_email`, `flexo_booking_email_placeholders`, `flexo_booking_manage_capability` (default `flexo_manage_bookings`), `flexo_booking_rate_limit`, `flexo_booking_promo_attempts`, `flexo_booking_template`, `flexo_booking_use_mysql_locks` | filters | As named |

**Rooms (1.8.0):**

| Part | Where |
|---|---|
| Post type `flexo_room` (public, address base from the setting `room_base`), taxonomy `flexo_room_type` | `Flexo_Booking_Rooms`, `Flexo_Booking_Room_Content` |
| Room meta | `_flexo_gallery` (attachment IDs), `_flexo_view`, `_flexo_amenity_items` (`key`, `label`, `icon`; a preset with an empty label follows the translation; `_flexo_amenities` keeps the preset keys in step), `_flexo_details` (`icon`, `label`, `value`), `_flexo_hidden`, `_flexo_demo`, `_flexo_seo_title`, `_flexo_seo_description`, `_flexo_from_price` (cache) |
| Icons | `name` (bundled Lucide icons, ISC licence, `includes/data/room-icons.php`) or `media:<attachment ID>`; `Flexo_Booking_Room_Icons::html()` |
| The room as data | `Flexo_Booking_Room_Content::room( $id )` (texts from the translation, booking data from the main room) and `current_id()` (explicit room → the loop's post → the queried object → `flexo_booking_current_room`) |
| Room page design (1.8.1) | `Flexo_Booking_Room_Design` (setting `room_design`): chosen page/template printed with `Elementor\Frontend::get_builder_content_for_display()` while the room is the current post (as Elementor Pro prints Theme Builder templates), CSS in the head, `elementor-page-{id}` body classes, `templates/room-design.php` (classic themes: `get_header()`/`get_footer()`; block themes: header/footer parts; Elementor Canvas: none) |
| Pages, addresses, hidden rooms, default page, block template | `Flexo_Booking_Room_Pages`; output in `Flexo_Booking_Room_Render` (`templates/single-room.php`, `templates/room-booking-box.php`, overridable like the form templates) |
| "From" prices | `Flexo_Booking_Room_Prices::get()`, cache per room, daily cron `flexo_booking_from_prices` |
| Structured data, titles | `Flexo_Booking_Room_Seo` |
| Languages | `Flexo_Booking_Room_I18n` (`canonical_id()`, `translation_id()`) |
| Elementor | `includes/elementor/class-room-tags.php`, `class-room-link-tag.php`, `class-room-widgets.php`, `class-room-box-widget.php`, `class-templates.php` (starter templates in `assets/elementor/`) |
| Admin | `includes/admin/class-room-editor.php`, `class-demo-rooms.php`, `class-room-importer.php` |

Room hooks: `flexo_booking_current_room` (filter: the room when nothing else says), `flexo_booking_amenity_presets` and `flexo_booking_amenity_icon_keywords` (filters: ready-made amenities and the words that suggest icons), `flexo_booking_room_schema` (filter `( $nodes, $room )`: structured data), `flexo_booking_room_price_options` / `flexo_booking_room_price_tag` (filters: the Room price tag), `flexo_booking_prices_changed` (action: fire it when something changes every room's price, so "from" prices are worked out again). Engine queries pass the query var `flexo_all_rooms` to include hidden rooms; the REST `rooms` route and the booking form's room list leave out hidden and demo rooms (a hidden room is still booked through `?room=<slug>`).

**Build:** `bin/build-zip.sh` → `dist/flexo-booking-<version>.zip`.

---

## 27. Before going live on a client site

1. **Setup wizard** finished (or each of its steps done by hand), and **Settings → Health** shows no ✕.
2. **Features and package:** `FLEXO_BOOKING_FEATURES` in `wp-config.php` matches what the client bought (§2); the hotel's Features tab has the right booking mode.
3. **Timezone** (Settings → General) is the hotel's city.
4. **Rooms:** prices, weekend prices, max guests (and max adults), number of identical rooms, minimum stay. Seasons, closed dates, rate plans, child prices and tourist tax as needed. **Room pages:** every room's checklist in its *On the website* card is complete (photos, description, amenities), **demo rooms removed**, the single room template and room card in place (**Settings → Room pages** names the template in use), the "from" prices in the *Your room pages* table look right, and nothing else uses `/rooms/` (**Settings → Health**). If rooms came from JetEngine, its room post type is switched off.
5. **Pages:** booking page with the widget or `[flexo_booking]`; hero search bar pointing to it; "Book now" buttons; privacy policy page (Settings → Privacy); terms and thank-you pages if used. Translated pages linked in Polylang/WPML.
6. **Emails:** notification addresses, hotel phone, logo/colour; an SMTP plugin connected to the hotel's own domain; **Send test email** arrives in the inbox (not spam).
7. **Payments** (if used):
   - Stripe in **test mode** with the hotel's own keys and webhook; a 4242 test booking becomes *Confirmed*; the webhook shows 200 in Stripe.
   - Then **live** keys, a live webhook and **Mode: Live**; one small real payment, refunded.
   - Bank details and payment reference checked on a test booking.
   - The hotel knows its fiscal obligations (§11).
8. **Calendar sync** (if used): export links pasted into Booking.com/Airbnb, their links imported here; a real server cron job for syncing, reminders and payment deadlines.
9. **Caching/CDN:** `/wp-json/*` is not cached.
10. **Tracking** (if used): Tag Manager triggers receive `booking_complete` once per booking.
11. **Accounts:** reception gets **Hotel Staff**, the manager **Hotel Manager**; only the owner/agency is Administrator.
12. **Look:** **Appearance** on *Match my website*, or checked in the preview on desktop and phone (no contrast warnings).
13. **A full test booking** on a phone and a desktop, then delete the test bookings (or cancel them in Stripe test mode).

---

## 28. Testing

The `tests/` folder in the repository is not shipped in the zip. It holds WP-CLI test scripts (including the calendar-sync engine test with Booking.com/Airbnb-style sample feeds), a concurrency test (two simultaneous bookings for the last unit), the upgrade and portability tests, a promo-code race test (two bookings for a code's last use), privacy/email/invoice tests, a Bulgarian-site language test, a Polylang test, Day 5 payment tests (`test-day5.php`, with Stripe's API faked inside WordPress and webhooks signed like Stripe's), a local **Stripe stand-in** (`tests/stripe-mock/server.php`: Checkout API, hosted payment page, signed webhooks, refunds) used by the Day 5 browser test, a payment-hold race test, Day 6 tests (`test-day6.php`: history, notes, resend, roles and capabilities, Today, health, wizard, guest keys and pages, calendar and alternatives, enquiries, phone numbers, Appearance CSS and fonts, Import & export), Day 7 tests (`test-day7.php`, `test-day7-elementor.php`, `polylang-day7.php`: room pages and addresses, hidden and demo rooms, room content, "from" prices, structured data, Elementor tags and widgets, starter templates, Import/Export of room pages, the JetEngine importer, translations), and Playwright browser tests for Days 1–7 (Day 7: room page, booking box, phone bar, availability tags, room editor at 360/390/768/1440 px; Days 5 and 6 include layout checks at 360, 390, 414, 768, 1024 and 1280 px; Day 6 also keyboard-only booking, screen-reader labels, contrast, back/refresh, double submit, no Google requests, Cyrillic fonts, Elementor priority, the wizard on a fresh site, roles and the admin on a phone). See `tests/README.md`. Run them against a throwaway site only.
