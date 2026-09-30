# Flexo Booking – Day 6 usability review

Status: **approved and implemented (1.6.0).** The review (§1–§2), the plan
(§3) and the risks (§4) are kept as written; the answers to the open
questions and what was actually built, including where it differs from the
plan, are in §6. Details and test results: `IMPLEMENTATION_PLAN.md` §15.

## 1. How the review was done

The plugin (1.5.0) was used as three people, in a real browser (Playwright,
Chromium), with screenshots at every step:

| Person | Setup | Device |
|---|---|---|
| **Guest** booking a stay | "Full" hotel: seasons with minimum stay, 2 rooms, rate plans (Breakfast / Non-refundable), children's ages, tourist tax at the property, promo codes, privacy consent, invoice request, 30% deposit by card or bank transfer. Also a "minimal" hotel (booking requests only) and a sold-out period. | Phone 390 × 844 |
| **Owner** setting up for the first time | Fresh WordPress, plugin just activated, nothing configured | Desktop 1280, then phone |
| **Receptionist** handling today | The full hotel with arrivals/departures today, logged in as an Editor (the role most hotels give reception today) | Phone 390 × 844 |

Emails were read from the captured mail log. Bulgarian wording was spot-checked
on the ~60 most visible terms.

---

## 2. Problems found, ranked by impact

Impact = how often it happens × how badly it hurts a booking or a working day.
**H** = loses bookings, money or causes mistakes; **M** = slows people down or
confuses; **L** = polish.

### 2.1 Top of the list (all three people)

| # | Who | Problem (what we saw) | Impact |
|---|---|---|---|
| 1 | Guest | **Refresh or Back loses the booking in progress.** The URL never changes while the guest picks a room, a plan or types details; refreshing shows an empty search form; the browser's Back button leaves the booking page entirely. Even the confirmation disappears on refresh. | H |
| 2 | Guest | **Dead end when nothing is free.** Sold-out dates show every room as "Unavailable" and "No rooms are available … try different dates". No nearest free dates, no other room suggestion, no way to just ask the hotel. | H |
| 3 | Guest | **Dates are chosen blind.** Native date inputs: no sign of full or closed dates, no minimum stay until after searching ("Minimum stay 3 nights for arrivals in Summer" appears on the room card). The input format follows the browser (a Bulgarian site showed `10/15/2026` on an English phone). | H |
| 4 | Guest | **Phone checkout opens on a wall of prices.** After choosing a rate, the whole screen is the price breakdown; the first field is below the fold. When a required field is empty, the browser's bubble points at a field hidden **behind the sticky pay bar** (screenshot `g09`). | H |
| 5 | Guest | **Validation only at submit, one browser bubble at a time**, in the browser's language ("Please fill out this field", "Please enter a part following '@'"). Server errors (e.g. price changed, room taken) appear in a notice at the top of the form, away from the field. | H |
| 6 | Owner | **No guided setup.** After activation the only hint is "place the booking form … with the [flexo_booking] shortcode". The owner must find Rooms (a WordPress post editor), create a page and paste a shortcode, find the notification address and hotel phone under *Settings → Emails*, and never gets asked to make a test booking. | H |
| 7 | Owner | **New room defaults are a trap:** price **0** and max guests **1**. Saving a room without touching them makes it free, and a search for 2 adults says "Fits up to 1 guest". | H |
| 8 | Reception | **Not built around "today".** After login staff land on the WordPress Dashboard. *All bookings* is sorted by arrival, newest first, so next year's bookings come first; today's arrivals are only on the Calendar page, without actions. | H |
| 9 | Reception | **Bookings list unusable on a phone:** the table is 990 px wide on a 390 px screen; status and the Confirm/Cancel buttons are off-screen to the right. | H |
| 10 | Reception | **Bug: "Add booking" fails for rooms with several rate plans** unless a plan is picked: the default option "The room's only plan / normal price" gives "Please choose a rate for this room." | H |
| 11 | Reception / owner | **Reception can change prices.** Editors (usual reception role) can edit rooms, seasons, rate plans and promo codes, and see Posts, Pages, Templates, Elementor. There is no "staff" vs "manager" level. | H |

### 2.2 Guest

| # | Problem | Impact |
|---|---|---|
| 12 | No orientation: no steps; the search form stays on screen above the results and above "Your details" (on a phone the guest scrolls past it every time); no summary while choosing a room. | M |
| 13 | Confirmation is thin: reference, one line (room · dates · total), payment info. No guests/rate/breakdown, no "what happens next", no hotel phone/email, no "Add to calendar", no directions. | M-H |
| 14 | Room cards: no size, beds or amenities (the plugin has no fields for them); price per night is the room's base price; no "what's included"; no photo placeholder when a room has no image. | M |
| 15 | Rate plans show name + refundable ✓/✕ only; meals are only implied by the plan name; the cancellation terms appear only after choosing the plan. Hard to compare. | M |
| 16 | Phone field is free text with no country code. The hotel receives "0888 123 456" from a guest who may be abroad. | M |
| 17 | Final button doesn't say the amount ("Continue to payment"), and with card payment the guest isn't told it opens Stripe's page until after clicking. | M |
| 18 | No way for a guest to see the booking later or ask for a change/cancellation except replying to an email; no link in the emails. | M |
| 19 | Emails are one long list of lines ("Status: Awaiting payment" is developer wording); no calendar file, no directions, no link to the booking. | M |
| 20 | "Book now" room links prefill the room and dates, but the guest still has to click Select → choose plan → details, even when there is only one choice. | L-M |
| 21 | Accessibility: after "Check availability" focus stays on the button and nothing is announced except via `aria-live` on the results container; step changes move the page without moving focus; errors are not linked to their fields (`aria-describedby`); unavailable buttons have low-contrast text (light green on light green). | M |
| 22 | Promo code, invoice and consent all sit after the guest details with the same visual weight; optional fields are not marked "optional" (only required ones get `*`). | L-M |
| 23 | Layout shift: results replace the notice and push the page; room images load without reserved height. | L |

### 2.3 Owner (first setup)

| # | Problem | Impact |
|---|---|---|
| 24 | Settings are spread over 9 tabs and up to 12 menu items; related things are split (hotel phone, notification address, logo and email colour live under *Emails*; thank-you/terms pages under *General*; features on their own tab, configured elsewhere). | M |
| 25 | No "Preview booking form" and no way to see what guests see without knowing the page. | M |
| 26 | No health check: email delivery is only visible via "Send test email"; nothing tells the owner the booking page is missing, WP-Cron isn't running, an iCal feed fails, or the Stripe keys are wrong/in test mode on a live site. | M |
| 27 | Appearance is only adjustable in the Elementor widget's Style tab or with CSS. Sites without Elementor, or owners who don't use Elementor, can't change colours or fonts. | M |
| 28 | Empty screens say little about the next step (e.g. an empty bookings list shows a shortcode hint; empty seasons/rate plan lists just show an empty table). | L-M |
| 29 | Some settings have no explanation; technical ones (number format, decimals, iCal interval, email log days, data removal on uninstall) sit between essentials. | L-M |
| 30 | No help inside WordPress; the README is outside the admin. | L-M |

### 2.4 Reception (daily work)

| # | Problem | Impact |
|---|---|---|
| 31 | Booking details on a phone: every label and value on its own line with large gaps; Confirm/Cancel are at the very bottom after four cards; no one-tap Call / Email / WhatsApp-friendly phone link at the top. | M-H |
| 32 | No internal notes for staff (only the guest's "special requests"); no history of who confirmed, cancelled, recorded a payment or changed something. | M |
| 33 | No "resend email" (e.g. guest didn't get the confirmation). | M |
| 34 | List filters: status, room, "current & upcoming" and search only – no payment status, date range or source (website / staff / external calendar). | M |
| 35 | "Needs attention" items are scattered: payment conflicts and iCal conflicts at the top of the list, failed emails only in the email log, awaiting-payment deadlines only on each booking. | M |
| 36 | Confirmations use the browser's `confirm()` box; some don't state the consequence (e.g. deleting a paid booking). | L |
| 37 | Calendar on a phone works (scrolls sideways, today boxes), but the "Arriving / Leaving / Staying" boxes have no actions. | L |

### 2.5 Language (BG / EN)

| # | Problem | Impact |
|---|---|---|
| 38 | Literal or technical Bulgarian: **"Чакаща"** (Pending) → should read *Изчаква потвърждение*; **"Чака плащане"** and **"Очаква плащане"** are two different statuses that read the same; **"Блокирана (затворено)"**; **"Внос / Износ"** (sounds like goods trade); **"Анонимизирай"**; **"Плаща се в обекта"** (reads like legal text – guests say *на място*). English has similar developer words: *Pending*, *Reinstate*, *Anonymise*, *Blocked (closed)*, *Not paid (expired)*. | M |
| 39 | Terminology not consistent: *rate / rate plan / тарифа / цена*, *property / hotel / обект*, *request / booking request / заявка*. | L-M |

---

## 3. Planned changes

Principles: improve what exists, keep every Day 1–5 behaviour, everything
optional behind the feature system, server-side validation stays the source
of truth. New switches: **`guest_booking_page`** (manage-booking page, off by
default) and **`custom_appearance`** (Appearance screen, on by default – the
mode itself defaults to "Match my website").

### A. Guest experience

**A1. Steps and orientation** (fixes 1, 12, 20)
- Step indicator **Dates → Room → Details → Payment → Done**; *Room* merges room and plan choice; *Payment* only appears when guests pay online. Current step announced to screen readers.
- The search form collapses to a one-line **"15–18 Oct · 2 adults, 1 child · Change"** bar once results are shown.
- **Summary always visible:** a sidebar on desktop (≥ 1025 px) from the room step on; on phones a **collapsible sticky bar** ("Garden Room · 435 € ▾") that opens the full breakdown. The long breakdown moves out of the top of the details step, so the first field is visible straight away (fix 4).
- **State in the URL** (no personal data): dates, guests, ages, room, plan, promo code and step are written to the address with `history.pushState`, so **Back/Forward move between steps**, and **refresh restores the step** (the form re-quotes on the server). Guest details (name, email, phone, notes) are kept in memory while moving back and forward, but **not** saved in browser storage (see open question Q1).
- The confirmation survives refresh: the success step's URL carries the booking reference and the guest key (same mechanism as the card-payment return page), so reloading shows it again.
- **Prefill and skip:** search bar and "Book now" links fill everything they carry; with a room in the link and one plan (or the plan in the link), the guest goes straight to *Details*; with several plans, straight to the plan choice.

**A2. Dates and availability** (fixes 2, 3)
- An accessible **date-range picker** (vanilla JS, keyboard and screen-reader friendly, Monday first, DD.MM.YYYY for Bulgarian) replaces the native inputs; the native inputs stay underneath as a fallback when JavaScript fails and for old template overrides.
- New REST `GET /calendar?month=&room=` returns, per day: fully booked/closed, check-in not possible, minimum stay for arrivals that day. Days that can't be booked are greyed **and** crossed (not colour only); choosing an arrival shows "Minimum stay 3 nights" and disables earlier departures.
- Optional **"from" prices** in the picker (setting, **off by default**; computed per month and cached briefly).
- **No dead ends:** when nothing fits, show up to **3 nearest date ranges** of the same length (±14 days) that have rooms, **other rooms** that fit, and a **"Send an enquiry"** form (name, email, phone, message, prefilled dates) that emails the hotel and appears in the email log. It is not a booking.
- Reasons in plain language everywhere (already done for minimum stay/capacity/closures; extended to the picker and the enquiry prompt).

**A3. Room selection** (fixes 14, 15)
- New optional room fields: **size (m²)**, **beds** (e.g. "1 double or 2 single"), **amenities** (tick list of ~20 common ones, shown as icons + text, max 5 on the card) and a **short description**. Cards show photo (or a neutral placeholder), name, size, beds, max guests, amenities, **total for the stay** (big) and the average per night (small), "Only 2 left".
- Rate plans as comparable rows: **name · meals · cancellation in one line · total** (e.g. "Breakfast included · Free cancellation until 12 Oct · 435 €"). New optional rate-plan field **Meals** (none / breakfast / half board / full board / all inclusive), prefilled for the presets.

**A4. Details and checkout** (fixes 4, 5, 16, 17, 22)
- Order: guest details → how to pay → invoice (collapsed) → consent → **"Before you book" box** (cancellation policy, pay now vs at the property, hotel phone/email) → final button.
- Required fields marked with *, optional ones say "(optional)".
- **Phone with country code** selector (default +359 Bulgaria, searchable list of all countries), stored as `+359 888 123 456`; existing numbers untouched.
- **Inline validation** on leaving a field and on submit, messages under the field in the page's language with how to fix it ("Enter an email like name@example.com"); server errors mapped to their field; focus goes to the first error; `aria-invalid` + `aria-describedby`. Browser bubbles turned off (`novalidate`, already set) and replaced.
- Correct keyboards/autocomplete: `inputmode`/`type` for email, tel, numeric company ID; `autocomplete` tokens already mostly right – add `tel-national`, `country`.
- **Final button says exactly what happens:** "Send booking request" · "Confirm booking" · "Confirm and pay 130.50 € by bank transfer" · **"Continue to secure payment – 130.50 €"** for cards (see Q2). Disabled with a spinner while sending; a second click or Enter does nothing (already partly guarded; made explicit with an in-flight flag).
- The sticky pay bar never covers the field being corrected (scroll with offset).

**A5. Confirmation** (fixes 13, 19)
- Success step: reference, full summary (room, plan, dates, nights, guests, breakdown, paid now / at the property), **next steps** tailored to the case (request: "We'll confirm within 24 hours" – text configurable; bank transfer: the bank details; card: "Paid"), hotel phone/email, **Add to calendar** (.ics download from a signed URL), **Directions** (Google Maps link from the hotel address).
- Emails: same content in clear blocks (booking, payment, next steps, hotel contact), friendly status words, the `.ics` attached to confirmations, and (with the feature on) the **Manage booking** link.

**A6. Guest booking page** (feature `guest_booking_page`, fixes 18)
- A secure link (`/booking/?manage=REF&key=…`, the same hashed-key mechanism as payments) shows the booking, payments and bank details, and lets the guest **send a cancellation or change request** with a message. The hotel gets an email and the request appears on the booking and in "Needs attention"; **nothing changes automatically**. Rate-limited.

**A7. Accessibility and performance** (fixes 21, 23)
- Keyboard: every step reachable and usable with Tab/Enter/Space/Esc; visible focus ring (2 px, contrast-checked); focus moved to each new step heading; results announced ("3 rooms available").
- Contrast AA for all default colours (fix the disabled "Unavailable" button); errors announced.
- Reserved space for room images (`aspect-ratio`), `loading="lazy"`, skeleton while searching → no layout shift. Assets still load only on pages with the form; the picker code is part of `booking.js` (no library).

### B. Hotel owner / staff experience

**B1. Setup wizard** (fixes 6, 7, 3 of owner)
- Opens once after first activation **on a fresh site only** (no rooms, no bookings); always available under *Help → Setup wizard*.
- Steps: hotel name, address, phone, email → booking mode (with a plain explanation) → first room (name, photo, price, max guests – sensible defaults: 2 guests, 1 unit, price required) → booking page (**create one automatically** with the form, or choose an existing page) → notification address + **send test email** → appearance (match website / pick main colour) → **make a test booking** (opens the form in a new tab; the wizard shows when it arrives).
- Each step skippable; progress saved in an option; "Finish later".
- Room editor defaults changed for **new** rooms: max guests 2, price field required (> 0) with a warning if 0.

**B2. "Today" dashboard** (fixes 8, 35, 37)
- First screen under **Bookings**: **Arriving today / tomorrow**, **Leaving today**, **Staying now** (each row: guest, room, nights, phone button, paid/balance); **Needs your attention** (pending requests, awaiting payment with deadline, payment conflicts/failures, iCal conflicts or sync errors, failed emails, guest change/cancellation requests) with one-click actions (Confirm, Payment received, Open, Mark reviewed); **next 7 days** counts (arrivals, departures, nights sold); **occupancy this month**.
- The menu badge counts everything in "Needs your attention" (today: pending only).

**B3. Bookings list and details** (fixes 9, 10, 31–34, 36)
- Search (reference, name, email, phone – exists) + filters **status, payment status, room, date range (arrival between), source** (website / staff / each external calendar).
- Quick actions per row: Confirm, Cancel, Payment received, Open. **On phones the list becomes cards** (guest, dates, room, status + payment badge, action buttons).
- Details page in sections: **Guest** (tap to call/email), **Stay**, **Price**, **Payment**, **Invoice request**, **Notes** (guest's requests + **internal staff notes**, never shown to the guest), **History** (who did what, when). Actions bar at the **top** (sticky on phones).
- **History log** (new table): created, confirmed, cancelled, reinstated, payments/refunds recorded, emails sent/resent, notes added, guest requests, anonymised – with user and time.
- **Resend email:** pick any guest email type that applies (confirmation, payment details, reminder …) and resend; logged.
- Confirmation dialogs (in-page, accessible) stating the consequence: "Cancel booking FB-X? The guest receives a cancellation email. The deposit of 130 € is **not** refunded automatically."
- Fix the staff *Add booking* rate-plan bug: the plan list shows only the plans of the chosen room, and the default is the room's first plan.

**B4. Settings structure** (fixes 24, 25, 28, 29, 11 partly)
- Menu (hotel language, only what's switched on): **Today · Calendar · Bookings · Rooms & Prices** (rooms, seasons, closed dates, rate plans) **· Promotions** (promo codes) **· Emails · Appearance · Settings · Help**. Calendar sync moves under *Settings → Calendar sync*; Import/Export under *Settings → Import / Export*.
- *Settings* tabs regrouped: **Hotel** (name, address, phone, email, check-in/out times, pages), **Booking rules** (mode, stays, advance, guests, children), **Payments**, **Taxes & invoices**, **Privacy**, **Calendar sync**, **Tracking**, **Features**, **Health**, **Import / Export**. Every field gets a one-line explanation; technical ones behind **"Show advanced settings"**.
- Empty states with the next action ("No seasons yet – add your summer prices so guests see the right price. **Add a season**").
- **Preview booking form** button in the admin bar and on Today/Appearance (opens the booking page; creates it first if missing).
- All old admin URLs keep working (redirects), see §4.

**B5. Roles** (fix 11)
- New roles **Hotel Staff** (bookings, calendar, manual bookings and blocks, notes, resend emails, record payments received) and **Hotel Manager** (+ rooms, prices, seasons, rate plans, promotions, closed dates, emails, appearance). Built on custom capabilities (`flexo_manage_bookings`, `flexo_manage_prices`, `flexo_manage_settings`); Administrators get all; feature availability stays with the Agency level (FlexoHotels). Staff roles see a trimmed admin (no Posts/Pages/Elementor).
- **Existing Editors keep what they have today** (manager-level), so no hotel loses access on upgrade – see Q4.

**B6. Health check** (fix 26) – *Settings → Health*: booking page exists and contains the form; SMTP plugin detected and last test email result; failed emails in the last 7 days; WP-Cron last run (the hourly job records a timestamp); iCal feeds' last result; Stripe keys present, mode test/live, last webhook received and a "Check keys" button (one API call); privacy policy page set; currency and timezone set. Each item: ✓ / ⚠ / ✕ + one-line fix. **"Copy system report"** (versions, features, settings without secrets or personal data).

**B7. Mobile admin** – Today, Bookings (cards), details (sections + sticky actions), Calendar and quick actions checked at 360/390/414 px.

**B8. Help** (fix 30) – *Help* page with short guides (add a room, seasons, connect Booking.com, handle a request, block dates, bank transfer received, refunds) and the FlexoHotels support contact (set via `FLEXO_BOOKING_SUPPORT` in `wp-config.php` or the Agency screen). Small **"?"** links next to settings open the matching guide.

**B9. Language** (fixes 38, 39) – a short glossary applied to all BG and EN texts, e.g.:

| EN (new) | BG (new) | Replaces |
|---|---|---|
| Waiting for confirmation | Изчаква потвърждение | Pending / Чакаща |
| Card payment in progress | Плащане с карта в процес | Pending payment / Чака плащане |
| Waiting for bank transfer | Изчаква банков превод | Awaiting payment / Очаква плащане |
| Not paid in time | Неплатена навреме | Not paid (expired) / Неплатена (изтекла) |
| Dates blocked | Блокирани дати | Blocked (closed) / Блокирана (затворено) |
| Restore booking | Възстанови резервацията | Reinstate |
| Remove personal data | Изтрий личните данни | Anonymise / Анонимизирай |
| Import & export | Импорт и експорт | Import / Export / Внос / Износ |
| Paid on site / at the hotel | Плаща се на място | Payable at the property / Плаща се в обекта |
| Rate (everywhere) | Тарифа | rate plan / цена |

Then a full pass over all ~1 100 strings for natural wording and the same terms everywhere.

### C. Appearance (feature `custom_appearance`)

- **Appearance** screen: **Match my website** (default; current behaviour: Elementor global colours/fonts, or the theme's) or **Custom**.
- Custom: main colour, accent colour, text colour, background, button text colour (each with "Use website colour"), corners (square / slightly rounded / rounded), headings font and text font ("Website font" or one of **Inter, Roboto, Montserrat, Lora, Playfair Display, Open Sans, Manrope** – all OFL, variable, with Cyrillic, **bundled in the plugin**; never loaded from Google), base text size (small / normal / large).
- **Live preview** beside the settings using the real form markup and CSS: search bar, room card, rate choice, summary, buttons, an error message, switchable desktop/phone width.
- **Contrast warnings** (WCAG AA 4.5:1 for text/background and button text/main colour) with a suggested colour; hex inputs + colour pickers; **Reset to website style** with confirmation.
- Implementation: CSS custom properties on `.flexo-booking` in a small inline style added after `booking.css` (no global CSS, no `!important`). **Priority:** Elementor widget Style settings (`{{WRAPPER}} .flexo-booking`, more specific) **>** Appearance Custom **>** website global styles. Only the chosen font files are loaded, only on pages with the form (`@font-face` with `font-display: swap`).
- **Emails** use the Appearance main colour and logo (the *Emails → design* settings move here; existing values are kept).
- In Import/Export. Existing sites stay on **Match my website** after upgrading – nothing changes visually.

---

## 4. Backwards-compatibility risks

| Risk | How it's handled |
|---|---|
| **Admin URLs.** `admin.php?page=flexo-booking&booking=ID` is in every hotel email already sent; the list is also linked with `&s=` / `&status=`. | `page=flexo-booking` becomes *Today*, but with `booking=` it still opens the booking and with list parameters it redirects to the new list page. Every old sub-page slug (`-new`, `-calendar`, `-sync`, `-tools`, `-settings&tab=…`) keeps working or redirects. |
| **Theme template overrides** of `booking-form.php` / `search-bar.php` won't have the new step bar, summary sidebar or picker containers. | Same approach as Days 3–4: the script adds missing containers itself and falls back to today's behaviour; new markup only in the plugin's templates. Documented. |
| **Roles/capabilities.** Changing who can do what could lock people out or open things up. | New custom capabilities are *added* to Administrator (all) and Editor (manager-level = today's access); existing filter `flexo_booking_manage_capability` still respected. Rooms post type gets its own capabilities mapped so Editors keep editing rooms. Removing capabilities on uninstall. |
| **Date picker** replacing native inputs could break keyboard users, screen readers or automated tests. | Built as progressive enhancement on top of the existing inputs (same `name`s); fallback to native inputs; keyboard/screen-reader tested; existing e2e tests updated only where the UI intentionally changed. |
| **URL state** could clash with existing parameters (`check_in`, `room`, `fb_payment` …) or page caches. | Same parameter names as today's prefill + a few new (`fb_step`, `rate_plan`); cached HTML stays valid because state is applied by JavaScript. |
| **Emails** change layout; hotels that edited texts keep their texts. | Only the surrounding blocks change; placeholders unchanged; `{booking_details}` gets the clearer layout. Calendar attachment only on confirmations. |
| **Status labels** change wording (BG/EN). | Labels only; stored status keys unchanged, so CSV, filters and integrations keep working. |
| **Appearance** could change existing sites' look. | Default and upgrade mode = *Match my website* (exactly today's CSS). Email colour/logo settings are migrated as-is. |
| **Migration 7** (history table, staff notes, rate-plan meals, room fields). | Additive only, like migrations 1–6; tested from 1.0.0 and 1.5.0. |
| **Zip size**: 7 variable fonts (latin + cyrillic) ≈ 0.6 MB. | Only the selected font files are ever loaded by browsers. |

---

## 5. Open questions (answered: all approved as proposed)

- **Q1 – Refresh and personal data.** I'll keep dates/room/plan/promo/step in the URL (refresh restores them) but **not** name/email/phone (the brief says no personal data in browser storage). After a refresh the guest re-types their details (browsers often refill them anyway). OK?
- **Q2 – Card button text.** With Stripe the money is taken on Stripe's page, after our button. I propose **"Continue to secure payment – 130.50 €"** rather than "Pay 130.50 € and confirm", which would promise something our button doesn't do. OK?
- **Q3 – Enquiry.** "Send an enquiry" emails the hotel (and is logged), it is not stored as a booking and not behind a feature (it replaces a dead end). Should it be switchable?
- **Q4 – Editors.** Keep existing Editors at manager level (today's access) and use the new *Hotel Staff* role for reception accounts from now on – or downgrade Editors to staff level on upgrade (safer, but some hotels will suddenly lose access to prices)? I recommend keeping them.
- **Q5 – New room/rate fields** (size, beds, amenities, meals) are needed for the richer cards. They're optional and cards simply leave them out when empty. OK to add?
- **Q6 – Scope/order.** This is the largest day so far. I'd deliver in this order, each part tested and committed: **C Appearance → A Guest → B Owner/staff → language pass → regression**. OK?

---

## 6. What was done

Approved with Q1–Q6 as proposed and built in the agreed order (C → A → B →
language → regression), each part committed separately.

| Plan item | Done | Differences from the plan |
|---|---|---|
| **C** Appearance | Match / Custom, 5 colours with "Use website colour", corners, 7 bundled fonts (OFL, Cyrillic, self-hosted), text size, live preview (desktop/phone), contrast warnings with suggestions, reset, email colour/logo, Import/Export; upgrades stay on *Match* | – |
| **A1** Steps and orientation | Step bar, collapsed stay line, summary (sidebar / phone bar), URL state with Back/Forward/refresh, confirmation survives refresh, prefill and skip from links | The sidebar depends on the form's own width (≥ 900 px), not the viewport, because theme columns are often narrow; from 768 px the summary is an open box |
| **A2** Dates and availability | Accessible range picker over the native inputs, `/calendar` API (full, closed, no arrival, minimum stay), optional "from" prices (off), nearby dates, other rooms, enquiry | Enquiries are kept in the history table (12 months) so they show under *Needs your attention* |
| **A3** Room selection | Size, beds, amenities (max 5 + "more"), total and per-night price, rate rows with meals and cancellation | Short description = the existing excerpt |
| **A4** Details and checkout | Order, required/optional marks, phone with country code, inline validation with focus, keyboards/autocomplete, "Before you book", exact button texts (Q2), no double submit | Phone numbers are validated for new bookings (6–15 digits) |
| **A5** Confirmation | Reference, summary, next steps, contact, Add to calendar, Directions; emails in blocks, `.ics` attached, manage link | – |
| **A6** Guest booking page | Feature `guest_booking_page` (off by default), view + cancel/change request, email + Today, 3 a day | Lives on the booking page (`fb_manage`); the payment key also works |
| **A7** Accessibility and performance | Keyboard-only flow, focus management, announcements, AA contrast, reserved image space, lazy images, skeleton | Checked in Chromium's accessibility tree, not with a real screen reader |
| **B1** Setup wizard | 7 steps, skippable, saved, fresh sites only, page created, test email, test booking detected | – |
| **B2** Today | Arriving today/tomorrow, leaving, staying; Needs your attention with one-click actions; next 7 days; occupancy; menu count | – |
| **B3** List and details | Filters, quick actions, phone cards, sections, top actions, history table, internal notes, resend, dialogs with consequences, Add booking rate fix | – |
| **B4** Settings structure | Menu *Today · Calendar · All bookings · Add booking · Rooms & prices · Promo codes · Emails · Appearance · Settings · Help*; tabs Hotel / Booking rules / Payments / Taxes & invoices / Privacy / Tracking / Features / Health; advanced toggle; empty states; preview link; old URLs redirect | *Promotions* kept the name **Promo codes**; Emails is its own menu entry (for managers); Calendar sync and Import & export stay separate screens linked from the Settings tab bar; hidden screens stay registered (WordPress refuses unregistered ones) |
| **B5** Roles | Hotel Staff, Hotel Manager, custom capabilities, trimmed admin for staff, Editors keep access (Q4) | – |
| **B6** Health check | All listed checks incl. Stripe "Check the keys", copy system report | "Preview booking form" doesn't create a missing page by itself; Health and the wizard offer it |
| **B7** Mobile admin | Today, cards, details, calendar at 360/390/414 | – |
| **B8** Help | Guides, "?" links, support contact (constant or Agency screen) | – |
| **B9** Language | Glossary in EN and BG, 523 new Bulgarian strings, full pass | – |

**Tests:** Day 6 PHP tests 108/108, Day 6 browser tests 117/117, wizard on a fresh
site by hand, and the full regression of Days 1–5 (PHP suite, browser tests,
concurrency, Polylang, upgrades from 1.0.0 and 1.5.0, portability) – all pass.

