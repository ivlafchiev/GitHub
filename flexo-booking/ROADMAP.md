# Flexo Booking: 5-day enhancement roadmap

This file is the source of truth for the multi-session enhancement of Flexo
Booking. Every session should read it, together with `IMPLEMENTATION_PLAN.md`,
before changing code.

## Product direction

- **One plugin, one codebase.** No separate Core/Pro plugins.
- **Not** a PMS, channel manager or enterprise hotel system.
- **Optional features, hidden complexity.** A simple guest house must still get
  *dates → room → details → booking request*, with nothing extra to learn.
- Independent of any FlexoHotels template. Works with Elementor and the
  `[flexo_booking]` shortcode. Styling comes from Elementor global colours and
  fonts.
- Target customers: small and medium hotels, guest houses, villas, resorts,
  campsites.

## Already in 1.0.0 (must keep working)

- Rooms, availability and nightly pricing, including weekend pricing
- Capacity, number of identical rooms and minimum stay
- Booking requests, instant bookings and manual (staff) bookings
- Blocked dates, confirm/cancel and CSV export
- Elementor booking form widget, the Elementor "Room booking link" dynamic
  tag, and the `[flexo_booking]` shortcode
- Portable configuration (Import/Export, path-based links)
- Responsive guest booking flow

## Plan

| Day | Scope |
|---|---|
| **Day 1** | Architecture review, feature system (available/enabled), versioned migrations, currency settings (EUR default, configurable format), unified server-side pricing service, booking concurrency (locking), **seasonal pricing** (seasons per room, closed periods, copy to next year, import/export) |
| **Day 2** | **iCal calendar sync**: import and export feeds, conflict detection. **Admin booking calendar**: a month grid of rooms × days. |
| **Day 3** | **Guests with children** (ages, child pricing rules), **rate plans** (e.g. breakfast, non-refundable), **tourist tax**, **promo codes** |
| **Day 4** | **Privacy/GDPR consent** and data retention, **invoice request** (company details), improved **guest and hotel emails**, **translation** (BG/EN, Polylang/WPML), **tracking events** (dataLayer / GA4 / Meta) |
| **Day 5** | **Payments**: Stripe Checkout, bank-transfer deposit, inventory hold while paying. Responsive mobile/tablet polish, full regression test, final README. |

## Rules for every day

- Keep all existing functionality. Avoid unnecessary rewrites and don't
  refactor unrelated code.
- Don't implement features scheduled for later days. Only register them in the
  feature system.
- Availability and prices are always recalculated on the server. Never trust
  values submitted by the browser.
- Use WordPress capabilities, nonces, sanitisation, escaping and prepared
  queries.
- Load assets only where they're needed, and scope the front-end CSS.
- Write admin labels in hotel/business language, not developer language.
- If something in the plan doesn't work in practice, stop and ask.
- Every day ends with: tests run, `IMPLEMENTATION_PLAN.md` status updated,
  `README.md` updated, commits pushed, and a short summary.

## Status

| Day | Status |
|---|---|
| Day 1 | **Done** (1.1.0): feature system, migrations, currency, pricing service, locking, seasonal prices, closed dates |
| Day 2 | **Done** (1.2.0): iCal calendar sync (import/export/conflicts/cron), admin booking calendar |
| Day 3 | **Done** (1.3.0): children & ages, rate plans, tourist tax, promo codes, booking details view |
| Day 4 | Not started |
| Day 5 | Not started |
