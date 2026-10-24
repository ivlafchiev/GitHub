# Flexo Booking – Admin UI audit and design system proposal

Step 1 of the admin UI overhaul (sections 1–8) was the audit and proposal; you approved it with "as proposed" for every question in §8.
**Steps 2–5 are done. §9 lists what was built, the status of every defect below with before/after screenshot pairs, what was not fixed and why, and the tests.**

| | |
|---|---|
| Plugin | 1.8.3 + 1.9.0 work so far (branch `claude/flexohotels-booking-system-ftjrqm`, commit `277cbbb`) |
| Test site | WordPress 7.2, Twenty Twenty-Five, Elementor 4.4 (free), PHP 8.4, MariaDB. Every feature switched on; 5 rooms (3 with photos), 20 bookings in every status, a season, a promo code, a closure, one booking request waiting. |
| Screenshots | **After (Step 5):** `ui-audit/after/` – the same screens, states and widths, plus `appearance-unsaved` (the save bar while editing). **Before:** `ui-audit/before/<screen>@<width>.jpg`: 48 screens and states at 1280, 1440 and 1920 px (including the WordPress dashboard as a reference), plus 390 px for Today, All bookings, Booking details and Calendar. `bg-*@1280.jpg`: six screens in Bulgarian. 154 files in total. Recreate them with `tests/ui/audit-seed.php` and `tests/ui/audit-shots.js`. |
| Measurements | `ui-audit/before-metrics.json` and `before-bg-metrics.json`. For every screen and width they record horizontal overflow, elements past the right edge, the WordPress sidebar's computed padding, font sizes in use, button heights and corner radii. |
| Docs read | README.md, IMPLEMENTATION_PLAN.md (§18 is the 1.9.0 spec), UX_REVIEW.md. **`SPEC_1.9.0.md` does not exist in the repository**; if it lives elsewhere, please add it and I'll check it against this plan. |

---

## 1. Summary

The admin works, and parts of it look good: Today, Booking details, the Features cards and Health. It is not yet a coherent product. Your eight symptoms are all real; seven reproduce here, and the sidebar leak is discussed below.

They come from one root cause: **there are two design layers on top of each other and no shared components.**

| Fact | Measured |
|---|---|
| Admin stylesheets | 4 files, **5,188 lines** (`admin.css` 2,142 · `admin-ui.css` 1,726 · `admin-room.css` 878 · `admin-calendar.css` 442) |
| Design layers | `admin.css` is the day-by-day CSS from 1.0–1.6. `admin-ui.css` (1.7.0) is a design system laid **over** it, restyling the same elements a second time. |
| Distinct colours hard-coded | 52 + 36 + 32 + 3 hex values (tokens exist in `admin-ui.css` but `admin.css` and `admin-calendar.css` mostly ignore them) |
| Distinct font sizes | 14 + 13 + 10 + 8 values. A single screen uses 8–12 sizes (e.g. 12, 12.5, 13, 13.5, 14, 14.5, 15, 17, 26 px) – there is no type scale. |
| Distinct corner radii | 17 + 15 + 6 + 5 values |
| Button heights on one screen | Room editor: 24, 29, 30, 32, 34, 36, 38, 40 px. Most screens mix 32 / 38 / 40. |
| Where styles are loaded | Six different `wp_enqueue_style` calls in six classes, each with its own condition |
| Markup | Every screen writes its own HTML. The same idea (a selectable card, a settings row, a colour field, a status) has 2–3 different implementations, and they look different. |
| WordPress core components mixed in | `form-table`, `nav-tab`, `WP_List_Table`, metaboxes, raw `<details>`, native `type=date/time/file` inputs – each restyled partially, by different rules |
| Fixed / sticky elements | The save bar is `position: fixed` and reserves no space, so it covers content on every settings screen. |

Patching symptoms would add a third layer. The proposal (§5–§7) replaces the layers with **one token-based stylesheet and one set of PHP component helpers** that every screen uses.

---

## 2. Your eight reported defects – verified

| # | Defect | Status here | Evidence |
|---|---|---|---|
| 1 | Radio buttons in the "Match my website / Custom" cards are not aligned with their titles | **Confirmed.** Radio centre is 3 px above the title's line centre. The title starts at x=235 but the description at x=209 – two left edges. The same kind of card on Features and Pages *is* aligned (offset 0): a second implementation. | `appearance-*@*.jpg`, measured |
| 2 | Preview toolbar mixes three control types, overflows, "Phone" clipped | **Confirmed.** At 1280 the device switch ends 137 px past the panel edge; at 1440, 54 px. "Open page" is a bordered button sitting between the tabs and the device switch; the label "Preview" is crammed in front. In Bulgarian, "Open page" ends 256 px outside. | `appearance-custom@1280/1440.jpg`, `bg-appearance-custom@1280.jpg` |
| 3 | Colour rows: "Use website colour" shows the same greyed #1f6f5c everywhere; cramped layout | **Confirmed.** All 20+ colour rows show a disabled swatch and placeholder "#1f6f5c" (the plugin's fallback, not the website's colour). Label on the left, then checkbox, then swatch + hex underneath, then the description – three lines for one choice, with uneven row heights. | `appearance-custom@1440.jpg` |
| 4 | Sticky "Save appearance" bar overlaps the content | **Confirmed on every settings screen.** The bar is `position: fixed` with no reserved space. It covers form rows on Appearance, Settings (all tabs), Add booking (hides *Guest name* and *Email*), Emails (hides the placeholder list) and Pages (hides *Your page* help). | viewport shots; `add-booking@1440.jpg` |
| 5 | WordPress sidebar submenu lost its left padding (CSS leak) | **Not reproduced here.** On a Flexo screen, the Bookings submenu link has exactly the same computed style as a core submenu (`padding: 5px 12px`, 13 px), and no plugin rule matches any sidebar element (checked rule by rule). Most likely causes on your site: (a) the same body class is used for `background` and font tokens, and another plugin/theme admin CSS reacting to it; (b) a cached older admin.css. The new system removes the body-level styling entirely (§6.1), and a test will compare the sidebar's computed styles on a Flexo screen and a core screen so this can't regress. **Please send the screenshot and your WordPress version / active plugins** if you still see it after Step 2. | `leak` measurements; `wp-dashboard@*.jpg` vs Flexo screens |
| 6 | Top bar overflows horizontally (help icon clipped) | **Not reproduced at 1280/1440/1920 with English labels**, but the bar has no shrink rules: the search field is fixed at ~440 px and the "New booking" label never collapses. With long labels (Bulgarian "Нова резервация"), the browser zoomed, or between 960 and 1100 px, the right group is pushed out. Fix by layout rules, not a one-off. | `bg-*@1280.jpg` |
| 7 | Preview panel clipped at the bottom with a nested scrollbar | **Confirmed.** The preview is a 1100 px-tall iframe inside a sticky card shorter than the viewport → the bottom is cut off by the window and the iframe scrolls inside the page scroll. | viewport shot `app-scroll-1440` |
| 8 | Preview: labels left-aligned while title and cards are centred | **Confirmed.** The preview iframe gets the narrow column width, so the booking form switches to its compact layout (centred title and cards) while field labels stay left-aligned. The preview also shows outdated texts ("Select" – the 1.9.0 button says "Select this room →"), because the form preview uses static sample markup. | `appearance-*@*.jpg` |

---

## 3. Defects per screen

Severity: **H** = looks broken or blocks a task · **M** = clearly unprofessional or inconsistent · **L** = polish.
Types: *Align* alignment · *Space* spacing/rhythm · *Comp* inconsistent component · *Over* overflow/clipping · *Leak* CSS leak · *Word* wording · *State* missing state (hover, focus, empty, loading, error, unsaved).

### 3.1 App shell (every screen)

| ID | Sev | Type | Defect |
|---|---|---|---|
| S-01 | H | Comp | Two page-header variants: icon tile + title + description (Today, Settings), or plain title with back link (Booking details, Booking not found). Some headers have a "?" help bubble after the title (Add booking, Emails); others don't. |
| S-02 | H | Comp | **Three tab styles**: underlined (Settings, Rooms & prices sub-tabs), pill (All bookings: "Bookings on this website / From external calendars"), numbered pill stepper (Wizard). |
| S-03 | H | Over | Settings tabs: 12 tabs in one row; at 1280 "Calendar sync" and "Import & export" are cut (+136 px), in Bulgarian already at "Tracking" (+591 px). No scroll affordance. |
| S-04 | H | Comp | Save bar: fixed, covers content (see #4); label varies: "Save appearance / Save Changes / Save features / Save pages / Save booking"; no "Unsaved changes", no Discard, no leave warning, no saving state. |
| S-05 | M | Comp | Other plugins' admin notices (e.g. Elementor's survey) are restyled to look like Flexo notices and sit between the header and the content on *every* screen, pushing everything down ~150 px. |
| S-06 | M | Comp | Page description sometimes in the header (Today, Calendar), sometimes as a loose paragraph under the notices (Settings → Hotel, Features, Health, Pages). |
| S-07 | M | Over | Top bar: fixed-width search, no collapse rules (see #6). The "Open booking form" icon and "Help" icon have no visible labels/tooltips. |
| S-08 | M | Word | Date formats mixed on one screen: "11.10.2026 → 14.10.2026" (Today, details subtitle) vs "October 11, 2026" (lists, cards); native date inputs show "mm/dd/yyyy" (browser locale, not site locale). |
| S-09 | M | Comp | Status shown with emoji/glyphs in some places (✓ ⏳ 💳 🏦 ✎ ⛔ ⇄ ⚠ × in the calendar legend, "✎ Added by staff" badge, ✓/⚠/✕ in Health) and with SVG icons in others. |
| S-10 | M | State | Focus rings inconsistent: inputs have a teal ring, `.button` uses WordPress's blue focus, tabs/pills have none visible, cards (radio cards) show the native radio ring only. |
| S-11 | L | Comp | Footer "Flexo Booking 1.8.3 · Help" sits outside the content column (left edge differs from content). |
| S-12 | L | Space | Content max-width 1360 px on some screens, 880 px cards on others (Import & export, Health list 1110 px, Email test cards 650 px) – edges don't line up between sections. |
| S-13 | M | Comp | WordPress's "Screen Options" tab floats above the page header on Rooms & prices / room editor. |
| S-14 | L | Comp | Back link ("← All bookings") present on Booking details and Add booking, missing on Booking not found, room editor, promo-code edit. |

### 3.2 Today

| ID | Sev | Type | Defect |
|---|---|---|---|
| T-01 | M | Align | KPI tiles: content top-aligned, 3 of 4 tiles have empty space at the bottom (the occupancy tile has an extra line). On phones the tiles have different heights. |
| T-02 | L | Comp | One KPI tile ("Staying tonight") has a darker border than the others. |
| T-03 | M | Align | "Next 7 days": numbers right-aligned, but the rows' labels are 13 px muted and the numbers 20 px – reads as two columns not lined up; no units. |
| T-04 | L | Comp | Attention row: Confirm (32 px, filled) next to Decline/Open (32 px, outline) next to the page header buttons (38 px). |
| T-05 | M | Over | Phone: header buttons wrap 2 + 1; section pills row is cut at the right with no scroll affordance. |
| T-06 | L | Word | "Good evening, admin" uses the login name. |

### 3.3 All bookings (list, search, empty)

| ID | Sev | Type | Defect |
|---|---|---|---|
| L-01 | H | Align | Total wraps: "300.00 / €" on two lines; prices left-aligned (should be right, tabular). |
| L-02 | H | Over | Bulgarian: the table is 27 px wider than the page (horizontal scroll); the Stay column breaks one word per line ("October / 3, 2026 / → / October / 6, 2026 / 3 / нощувки"). |
| L-03 | M | Comp | Reference cell overloaded: reference + "Added by staff" badge + created date/time in one cell, 4 lines. |
| L-04 | M | Comp | Row actions wrap into two lines for pending rows (Confirm, Cancel / Delete); "Delete" as a red button next to every row is too prominent for a destructive action. |
| L-05 | M | Comp | Filter bar: 4 selects + date range + checkbox + Filter button wrap into two uneven lines; native date inputs; no "Clear filters". |
| L-06 | M | State | Empty search: a single line of text in a table; no illustration, no "Clear search" action. |
| L-07 | M | Comp | No pagination control visible when results fit; count "5 bookings" floats above the table, separate from it. |
| L-08 | L | Comp | Column headers uppercase 12 px with letter-spacing; elsewhere section labels are sentence case. |

### 3.4 Booking details (+ cancel dialog, not found)

| ID | Sev | Type | Defect |
|---|---|---|---|
| B-01 | M | Space | Three-column masonry with cards of very different heights → large gaps (under *Stay*, under *Guest*). |
| B-02 | M | Comp | Header: Confirm (primary), 2 secondary, "Cancel booking" danger-outline – four buttons of equal weight; on phones they wrap 2 + 2. |
| B-03 | M | Comp | *Record a payment or refund* is a raw `<details>` triangle, unlike every other expandable area. |
| B-04 | H | Comp | Cancel dialog: the destructive confirm button ("Cancel booking") is primary teal, not danger; "Go back" has a heavy focus ring. Title "Please confirm" is generic. |
| B-05 | M | State | Booking not found: no header description, red error notice, no next step (search, back to list). |
| B-06 | L | Word | "Payment method —" (em dash) when there's none – say "Not chosen yet" / "Pay at the property". |

### 3.5 Add booking

| ID | Sev | Type | Defect |
|---|---|---|---|
| A-01 | H | Over | Fixed save bar hides *Guest name* and *Email* while scrolling. |
| A-02 | M | Comp | Field widths: selects 216/236 px, text 350 px, notes full width, adults 88 px + children 350 px on one line with inline labels. |
| A-03 | M | Word | Two rows labelled "Email" (the guest's email, and "Send the confirmation email"). |
| A-04 | M | Comp | Native `mm/dd/yyyy` date inputs; no nights count, no availability/price feedback until saving. |
| A-05 | L | State | No required markers; validation only after submit (full-page reload). |

### 3.6 Calendar (+ dialog, phone)

| ID | Sev | Type | Defect |
|---|---|---|---|
| C-01 | H | Over | The grid is 221 px wider than the card at 1280 and 61 px at 1440 – the last day column is cut ("S…"), no visible horizontal scroll affordance. On phones only 7 days fit and room names wrap to 4 lines. |
| C-02 | M | Word | Rooms with several units show "3/3" in every empty cell – unexplained (it's "free / total"). |
| C-03 | M | Comp | Legend: 11 items in emoji chips wrapping over 3 lines; colours not shared with the status pills in the list (e.g. pending is yellow here, amber pill elsewhere). |
| C-04 | M | Comp | Booking bars truncate names ("Maria Ivan…") without a tooltip; tiny ✓ ✎ glyphs inside. |
| C-05 | L | Comp | "Arriving / Leaving / Staying" summary cards duplicate Today with a different card style. |

### 3.7 Rooms & prices (list, room editor, add room, seasons, closed dates, rates, calendar sync, promo codes, bring in rooms)

| ID | Sev | Type | Defect |
|---|---|---|---|
| R-01 | H | Comp | Room editor is WordPress's post screen: WP title ("Edit room" + "Add new room" button) instead of the Flexo header; metaboxes with WP chrome (↑ ↓ ▲ toggles) instead of cards; TinyMCE toolbar; a 280 px WP sidebar ("Publish", "On the website"). Looks like a different product. |
| R-02 | M | Comp | Rooms list is a restyled `WP_List_Table`: bulk actions + "All dates" filter (meaningless for rooms) + a search box without icon/placeholder, unlike the bookings filters; "Copy booking link \| Copy page link" pipe-separated WP row links. |
| R-03 | M | Align | Price/night left-aligned; numbers columns not tabular; thumbnail placeholders are blank grey boxes. |
| R-04 | M | Comp | Amenity chips: three chip styles (selected teal, unselected outline, "Add a detail" button chips) next to each other; ↑ ↓ × controls 24 px. |
| R-05 | M | Comp | Seasons, Closed dates, Rates, Promo codes: each a slightly different table + form; action buttons inline (Edit, Switch off, Delete – Delete red outlined in-row). |
| R-06 | L | Word | "Bring in rooms" title vs "Import rooms from JetEngine" content; "Remove demo rooms" button sits in the page header next to the primary action. |

### 3.8 Emails

| ID | Sev | Type | Defect |
|---|---|---|---|
| E-01 | M | Comp | "Notify the hotel about": 5 inline checkboxes that wrap mid-row – read as one run-on sentence. |
| E-02 | M | Space | Placeholder chips: a wall of 20 code chips above the email list; covered by the save bar. |
| E-03 | M | Comp | Three card widths on one page (full, 650 px test/log cards); "Email log – keep for 90 days" floats outside any card. |
| E-04 | L | Comp | "Edit text" is a link on the right of each card; the expanded editor has its own spacing. |

### 3.9 Appearance

All of §2 #1, #2, #3, #4, #7, #8, plus:

| ID | Sev | Type | Defect |
|---|---|---|---|
| P-01 | H | Space | One 4,400 px column of settings (Style, 5 colours, corners, 2 fonts, text size, panel, pages group 10 rows, 5 folded groups, emails, reset) – a "wall of settings". |
| P-02 | M | Comp | Three expand styles: native `<details>` triangle groups ("▸ Text"), cards, and the "Booking and Thank You pages" group with a different header. |
| P-03 | M | Comp | Corners as radio + drawn sample; fonts as selects with a long paragraph under them; text size as a 70 px select. |
| P-04 | M | Comp | "Reset to website style" is a lone button at the very bottom, after Emails. |
| P-05 | M | Word | Title "Appearance of the booking form" – it now also covers the Booking and Thank You pages and emails. |

### 3.10 Settings tabs (Hotel, Booking rules, Room pages, Pages, Payments, Taxes & invoices, Privacy, Tracking, Features, Health, Calendar sync, Import & export)

| ID | Sev | Type | Defect |
|---|---|---|---|
| G-01 | H | Over | Tab row overflow (S-03). |
| G-02 | M | Comp | Rows use `form-table` (label 240 px left), but inside a row several fields are inline with their own labels ("Number format [select] Decimals [2]") – mixed label positions. |
| G-03 | M | Comp | Native time inputs show "02:00 PM" (browser 12 h) on a 24 h site. |
| G-04 | M | Comp | "Show advanced settings" toggle in the page header (affects only some tabs). |
| G-05 | M | Align | Features: card grid rows of unequal height and half-empty rows ("Calendar sync" alone under the booking-mode cards). |
| G-06 | M | Comp | Health: status rows OK, but at 1110 px max width (other cards are full width); ✓ ⚠ ✕ glyph icons; fix actions as secondary buttons with different labels ("Open", "Connect a calendar", "Enter the keys", "Set the page"). |
| G-07 | M | Comp | Pages: "Customize" is a teal text toggle; inside, the booking fields use a third layout (3-column label / example / help grid with ↑ ↓ buttons). Status badges "OK" / "Off" use a different pill than the bookings list. |
| G-08 | M | Comp | Import & export: header still says "Settings" while the content is Import & export; native file input "Choose File · No file chosen"; 9 checkboxes in a column with long parenthetical explanations. |
| G-09 | L | Word | Mixed case: "Save Changes" (WordPress core) vs "Save features" (sentence case). |

### 3.11 Setup wizard, Help, Agency

| ID | Sev | Type | Defect |
|---|---|---|---|
| W-01 | M | Comp | Stepper as pills (a fourth tab style); the form sits in a wide card leaving 50 % empty; three button styles in a row (Save and continue / Skip this step / Finish later link). |
| W-02 | L | Comp | The sidebar highlights "Settings" while in the wizard. |
| H-01 | L | Comp | Help: long text cards; links styled differently from the rest. |
| AG-01 | – | – | Agency screen is visible only to agency users (`FLEXO_BOOKING_AGENCY_USERS`); the admin used here gets WordPress's "not allowed" page, so it wasn't audited. It will be built with the same components. |

### 3.12 Phones (390 px: Today, All bookings, Booking details, Calendar)

| ID | Sev | Type | Defect |
|---|---|---|---|
| M-01 | M | Over | Section pills are cut at the right with no fade/scroll hint. |
| M-02 | M | Comp | Page header buttons wrap into uneven rows on every screen. |
| M-03 | H | Over | Calendar unusable: 7 days visible, room names 4 lines, no sticky room column cue. |
| M-04 | L | Space | The third-party notice takes the whole first screen on every page. |

### 3.13 Bulgarian

| ID | Sev | Type | Defect |
|---|---|---|---|
| BG-01 | H | Over | Settings tabs overflow from the 8th tab; All bookings table overflows the page; Appearance toolbar 256–454 px outside. |
| BG-02 | M | Space | Labels such as "Страници за резервации и благодарност" wrap in the 240 px label column; buttons like "Възстанови резервацията" widen action columns. |
| BG-03 | – | – | Dates and "Save Changes" stay English on this test site because WordPress core's Bulgarian pack isn't installed here (not a plugin defect). |

---

## 4. What is already right (keep)

- Bundled Inter font with Cyrillic, never Google.
- Most rules scoped (`.flexo-admin-ui …`): **no leak into WordPress core was found** in this environment (§2 #5).
- The app bar idea: search, attention bell, open site, help, primary action.
- Card-based Today and Booking details; Health's status-row pattern; Features' toggle cards; the existing dialog component (focus trap, Escape).
- Plain hotel wording in most places (UX review, Day 6).

---

## 5. Proposal – Flexo Admin design system

Everything below is defined once as **CSS custom properties** on the root element of Flexo screens and used by **components**; screens only arrange components.

### 5.1 Tokens

**Colour – neutrals** (cool grey, AA-checked on white and on `--fx-gray-50`):

| Token | Value | Use |
|---|---|---|
| `--fx-gray-0` | #ffffff | cards, inputs |
| `--fx-gray-25` | #fafbfc | table header, subtle fills |
| `--fx-gray-50` | #f5f7f9 | page canvas |
| `--fx-gray-100` | #edf0f3 | hover fills, segmented track |
| `--fx-gray-200` | #e1e6eb | borders, dividers |
| `--fx-gray-300` | #c9d1d9 | input borders, strong dividers |
| `--fx-gray-400` | #98a3ae | placeholders, disabled icons (non-text) |
| `--fx-gray-500` | #687482 | muted text (4.8 : 1 on white) |
| `--fx-gray-700` | #3a4552 | secondary text |
| `--fx-gray-900` | #111a24 | primary text, headings |

**Colour – brand** (the current FlexoHotels teal, so nothing jumps): `--fx-primary-50` #e9f4f3 · `-100` #d0e8e5 · `-500` #13837c · **`-600` #0f6e6a (primary)** · `-700` #0b5854 (hover) · `-800` #08423f (active) · `--fx-primary-contrast` #ffffff.

**Colour – feedback** (text / background / border; text ≥ 4.5 : 1 on its background):

| | text | background | border | solid (icons, bars) |
|---|---|---|---|---|
| success | #0f6b43 | #e8f6ee | #a9dcc0 | #16a067 |
| warning | #7d4a00 | #fff5e1 | #f1cf8b | #d18a10 |
| danger | #a51d2a | #fdecee | #f2bcc2 | #d23a48 |
| info | #1c4f94 | #ebf2fc | #b8cff0 | #2f6fd0 |

**Booking status palette** – one mapping used by status pills, the calendar bars, the legend, Today and Health. No emoji anywhere; each status has an SVG icon *and* a label, so colour is never the only signal.

| Status | Colour family | Icon |
|---|---|---|
| Confirmed | success | check |
| Waiting for confirmation (request) | warning | clock |
| Card payment in progress (hold) | info | credit-card |
| Waiting for bank transfer | warning (striped bar in the calendar) | landmark |
| Blocked by staff | gray-700 | lock |
| From an external calendar | violet #5b3f9a / #f1ecfa | arrows |
| Cancelled / Not paid in time | gray-500, strikethrough in lists | x |
| Conflict (possible double booking) | danger | alert-triangle |

**Typography** – Inter (bundled, Latin + Cyrillic), `font-variant-numeric: tabular-nums` for numbers and prices:

| Token | Size / line | Weight | Use |
|---|---|---|---|
| `--fx-text-xs` | 12 / 16 | 500 | badges, captions, table meta |
| `--fx-text-sm` | 13 / 20 | 400 | help text, secondary lines, table cells meta |
| `--fx-text-md` | 14 / 20 | 400 / 500 | body, inputs, buttons, table cells |
| `--fx-text-lg` | 16 / 24 | 600 | card and section titles |
| `--fx-text-xl` | 20 / 28 | 600 | page sub-sections, dialog titles |
| `--fx-text-2xl` | 24 / 32 | 600 | page title |

Six sizes (today: up to 12 per screen). Sentence case everywhere; no uppercase table headers.

**Spacing** (4 px base): `--fx-space-1` 4 · `-2` 8 · `-3` 12 · `-4` 16 · `-5` 24 · `-6` 32 · `-7` 48.

**Radii**: `--fx-radius-sm` 6 (inputs, buttons, small badges) · `-md` 8 (popovers, segmented, tabs) · `-lg` 12 (cards, dialogs) · `-full` 999 (pills, switches, avatars).

**Borders**: 1 px `--fx-gray-200` (cards, dividers); 1 px `--fx-gray-300` (inputs); selected card 1 px primary + 1 px inner ring.

**Shadows**: `--fx-shadow-xs` 0 1px 2px rgba(17,26,36,.05) (cards) · `-sm` 0 4px 12px -2px rgba(17,26,36,.10) (popovers, sticky bars) · `-lg` 0 24px 48px -12px rgba(17,26,36,.28) (dialogs).

**Focus ring**: `--fx-ring` 0 0 0 3px rgba(15,110,106,.32) on inputs (with primary border); 2 px solid primary outline, 2 px offset on everything else; visible only on `:focus-visible`.

**Motion**: 150 ms ease-out for hover/press, 200 ms for panels; all off with `prefers-reduced-motion`.

**Z-index layers** (all below WordPress's admin bar 99999 and menu flyouts 9990): content 0 · sticky header/toolbar 10 · save bar 20 · dropdown/popover 30 · toast 40 · dialog backdrop 50 · dialog 51.

**Breakpoints** (aligned with WordPress): `sm` 600 · `md` 782 (WP mobile admin) · `lg` 1024 · `xl` 1280 · `2xl` 1600.

**Control sizes**: height 36 (default), 32 (compact: table rows, toolbars), 40 (primary in the save bar and wizard). Inputs 36 px. Icons 16 px in controls, 20 px in headers.

### 5.2 Components (one PHP helper + one CSS block each)

| Component | Spec |
|---|---|
| **Page header** | Optional back link (sm, muted, ← icon) · title (2xl) · one-line description (md, gray-500, max 72 ch) · actions on the right: max **1 primary + 2 secondary**, more go into a "More" menu. Wraps to two rows below `lg`, actions full width below `md`. |
| **Section card** | 1 px border, radius lg, padding 24 (16 on phones), shadow-xs. Header: title (lg) + optional description (sm) + optional right action. Optional collapsible (chevron button, `aria-expanded`). Cards in a column share one width. |
| **Settings row** | Two columns from `lg`: label column 280 px (label md/500 + description sm/gray-500 on the left), control column (max 520 px for text, controls aligned on one left edge across all rows). Stacks below `lg`. Divider between rows, 16 px vertical padding. |
| **Radio card** | Whole card is the `<label>`. Grid: 20 px indicator column + text column; indicator top aligned to the **first line of the title** (`margin-top: (line-height − 16) / 2`); title md/600 and description sm share one left edge. Selected: primary border + `--fx-primary-50` fill. Hover: gray-300 border. Keyboard: arrow keys (native radio group), focus ring on the card. Optional badge after the title (same baseline). |
| **Checkbox** | 16 px box, radius 4, label to the right with 8 px gap, aligned to the first text line; group = vertical list, never inline-wrapping. |
| **Toggle switch** | 36 × 20 track, `role="switch"`, label left or right; used for on/off settings; disabled and loading states. |
| **Text input / textarea / number** | 36 px, radius sm, gray-300 border, placeholder gray-400 (never used as the label), optional prefix/suffix (EUR, %, px, days), inline error (danger text + border + icon), required marker "*" with a visually hidden "required". Widths: `xs` 80 · `sm` 160 · `md` 320 · `lg` 520 · `full`. |
| **Select** | Same frame as the input with a chevron; same widths. |
| **Date / time field** | Text field + the plugin's own calendar popover in the site's date format and 24 h time; no native `mm/dd/yyyy` (see Q5). |
| **Colour field** | Segmented "Website colour \| Custom". *Website colour*: read-only swatch of the **actual** resolved colour + its name + hex (Elementor global "Primary – #1F6F5C"; or theme.json palette), or "Uses your website's colour" without a swatch when it can't be resolved. *Custom*: swatch button (opens the native picker) + hex input (validated) + "Reset". One row per colour: label + one-line description left, control right. |
| **Segmented control** | Track gray-100, radius md, 32 px; selected segment white with shadow-xs; icon + label; `role="radiogroup"` with arrow keys. |
| **Tabs** | One style: underlined, 14/500, 44 px tall, active primary 2 px underline, `role="tablist"`. Overflowing tabs collapse into "More ▾" (never cut). Sub-navigation in Settings: see Q1. |
| **Buttons** | Primary (filled), Secondary (white + gray-300 border), Tertiary (text, primary colour), Danger (filled red – only inside confirmations), Danger-secondary (red text, for "Delete" entries in menus), Icon button (32/36 px square with tooltip + `aria-label`). States: hover, active (pressed), focus-visible, disabled (50 % + not-allowed), loading (spinner replaces the icon, label stays, `aria-busy`). |
| **Badge / status pill** | 22 px, radius full, xs/500, icon 12 px + label; colours from the status palette. Neutral badge for counts. |
| **Notice / alert** | Inline (in a card) and page level: icon + title + text + optional action + dismiss. Four variants. Other plugins' notices stay in WordPress's notices area, shown compact (see Q6). |
| **Toast** | Bottom-centre (bottom-right on wide screens), 4 s, for "Saved", "Copied", "Email sent"; `role="status"`; errors stay until closed. |
| **Table / list** | Header gray-25, 13/500 sentence case; rows 52 px, hover gray-25; numbers and prices right-aligned tabular; status column = pill; row actions: one visible primary action + "⋯" menu (Edit, Delete…); selection checkbox column only where bulk actions exist; pagination bar (count left, pages right). **Below `md`: card list** (title, meta lines, status, actions). |
| **Filter bar** | Search (with icon) + up to 3 selects + "More filters" popover; active filters shown as removable chips; "Clear all". |
| **Empty state** | Icon (32 px, in a 64 px soft circle), title (lg), one sentence, primary next step. Variants: nothing yet / no results (with "Clear search") / error. |
| **Dialog** | Header (title xl + close ×), body, footer (secondary left of primary, primary right; destructive = danger button with a specific verb "Cancel booking"). Focus trap, Escape, return focus, `aria-modal`, scroll inside the body only. Max 520 px (confirm) / 720 px (forms); full-screen sheet on phones. |
| **Tooltip** | 12 px dark tooltip on hover/focus for icon buttons and truncated text; 400 ms delay. |
| **Sticky save bar** | `position: sticky; bottom: 0` **inside the page container** (it occupies its own space; nothing scrolls under it), white, top border + shadow-sm. Left: state text ("All changes saved" / ● "Unsaved changes"); right: Discard (secondary) + Save (primary, loading state). Hidden until the form changes, then slides in (no motion with reduced motion). `beforeunload` warning while unsaved. |
| **Toolbar** | Flex row with a left group and a right group, `gap 8`, both groups `flex-wrap: nowrap`; the left group shrinks and switches to a select ("Preview: Booking page ▾") below a container width (container queries), never wraps mid-group. |
| **Preview frame** | Isolated `<iframe>` showing the real front end; desktop = 1280 px rendered and **scaled to fit** the panel width (like Settings → Pages already does); phone = 390 px device frame centred with a subtle bezel; the frame fills the panel's height (no nested page scroll – the iframe scrolls, the panel doesn't); loading skeleton while it loads. |
| **Skeleton** | gray-100 blocks with a 1.2 s shimmer (static with reduced motion) for async content: previews, availability checks, Today numbers. Fixed heights → no layout shift. |
| **Stepper** (wizard) | Vertical step list on the left (done ✓ / current / upcoming), form card on the right; footer: Back (secondary), Skip (tertiary), Continue (primary). |
| **Status row** (Health) | Icon in a soft circle (status palette) + title + one sentence + one fix action (tertiary button "Fix …" with a consistent verb). |
| **Description list** | Label/value rows (Booking details, Payments): label sm/gray-500 160 px, value md; collapses to stacked on phones. |
| **Disclosure** | One expandable pattern (chevron button + panel) for "Record a payment", "Edit text", advanced groups – replaces raw `<details>` triangles. |
| **Icons** | One set: 24 × 24 stroke icons (Lucide-style, the existing `Flexo_Booking_Admin_UI::icon()` set extended), stroke 1.75, `currentColor`, inline SVG, 16 / 20 px. No emoji, no dashicons inside Flexo content. |

### 5.3 Layout rules

- **Root**: one class on Flexo screens' `<body>` (kept: `flexo-admin-ui`), used **only** for the canvas colour of `#wpbody`. Every other rule is `.flexo-admin-ui .fx-…` on plugin markup inside `#wpbody-content` and the app bar. **No selector ever targets** `#adminmenu`, `.wp-submenu`, `#wpadminbar`, `#wpfooter`, bare `input/button/table/select/a`, or WordPress classes outside our containers. The few WordPress-generated parts on our screens (room list table, room editor metaboxes, notices area) get a documented, contained adapter section.
- **Page container**: max-width 1280 px (data screens: lists, calendar, Today) / 960 px (single-column settings and forms), centred, gutters 32 px (≥ `lg`), 24 px (`md`), 16 px (phones). All cards on a screen share the container's width – no ad-hoc 650/880/1110 px cards.
- **Vertical rhythm**: header → 24 → content; cards 24 apart; sections inside a card 24 apart; rows 16 padding.
- **Two-column settings**: label column 280 px + control column; one left edge for all controls on the screen; descriptions under labels (not under controls); stacks below `lg`.
- **Appearance**: ≥ 1280 px: settings 45 % / preview 55 %, preview sticky at `top: admin bar + app bar + 16`, height `100vh − top − 16`. Below 1280: one column; a "Show preview" button opens the preview as a right-side drawer (≥ 782) or full-screen sheet (phones).
- **Sticky elements reserve space**: save bar sticky in flow (above); the app bar is in flow; no `position: fixed` content bars.
- **Responsive collapse**: page header actions → "More" menu; tabs → "More ▾"; tables → card lists below `md`; toolbars → selects; calendar → sticky room column + horizontal scroll with edge fades and ‹ › buttons, and an agenda list on phones (see Q4).
- **Overflow guard**: `min-width: 0` on all grid/flex children, `overflow-wrap: anywhere` for long values (emails, URLs), numbers `white-space: nowrap`.

### 5.4 Structure of the code (Step 2)

- `assets/css/flexo-admin.css` – **one** stylesheet: 1 fonts · 2 tokens · 3 base (inside containers only) · 4 layout · 5 components (one section each, in the order of §5.2) · 6 screen layouts (only genuine layout differences: calendar grid, Today grid, Appearance split) · 7 WordPress adapters (list table, metaboxes, notices) · 8 responsive. Replaces `admin.css`, `admin-ui.css`, `admin-room.css`, `admin-calendar.css` (≈ 5,200 lines → target ≈ 2,500).
- `includes/admin/class-admin-components.php` – `Flexo_Booking_UI::page_header()`, `card()`, `settings_row()`, `radio_cards()`, `checkbox()`, `toggle()`, `field()` (text/number/select/textarea/date/time with prefix/suffix, error, required), `color_field()`, `segmented()`, `tabs()`, `button()`, `status_pill()`, `badge()`, `notice()`, `table()` + `table_cards()`, `filters()`, `empty_state()`, `dialog()`, `save_bar()`, `toolbar()`, `preview_frame()`, `skeleton()`, `status_row()`, `description_list()`, `disclosure()`, `stepper()`, `icon()`. All escaping inside the helpers.
- `assets/js/flexo-admin.js` – shared behaviours: dirty tracking + save bar + `beforeunload`, toasts, dialogs, segmented/tabs keyboard, disclosure, tooltips, "More" overflow for tabs/actions, table → card switch helpers.
- **One** enqueue point (`Flexo_Booking_Admin_UI::assets()`), only on Flexo screens (`is_screen()`), screen scripts declare the shared one as a dependency.
- Settings keys, `name` attributes, option names, nonces, `admin-post` actions and capabilities stay **exactly** as they are; markup changes only the wrapping and classes. Each screen is migrated with a before/after check that the submitted form data is identical (a test posts the old and new forms and compares the saved options).
- `ui-audit/` is excluded from the plugin zip.

---

## 6. Screen-by-screen plan (Step 3)

| Screen | Main changes |
|---|---|
| App shell | One page header; app bar with shrink rules (search grows/shrinks, "New booking" label hides below `xl`, icons get tooltips); notices compact; footer aligned. |
| Today | Equal KPI tiles (value + label + optional meta at the bottom); attention list with status rows; arrivals/departures as compact lists; consistent buttons; phone header actions in "More". |
| All bookings | Filter bar component with chips; table: Reference (+ source icon), Guest (name + email), Room, Stay (two short dates + nights, `nowrap`), Guests, Total (right), Status pill, actions (primary + ⋯); pagination; empty states; card list on phones. |
| Booking details | Header (reference, status pill, guest/stay summary) with Confirm + Add note + ⋯ (Show in calendar, Cancel booking…); two-column grid with a left main column (Stay, Price, Payments) and a right column (Guest, Notes, Emails, History) – no masonry gaps; disclosure for Record payment; danger dialog. |
| Add booking | Settings-row form in cards (Stay · Guest · Options), plugin date picker with nights and live price/availability line, inline validation, required markers, sticky save bar. |
| Calendar | Sticky room column, fitted day columns (14 / 21 / 31 days by width), scroll buttons + fades, status palette bars with tooltips, legend as one compact row with a "Legend" popover, "free/total" shown as a small capacity bar with a tooltip; phone: agenda list per room (Q4). |
| Rooms list | Flexo table look for the WP list table (adapter), search like the bookings filter, price right-aligned, actions in ⋯ (Copy booking link, Copy page link, Duplicate, Trash); demo-room banner instead of a header button. |
| Room editor | Flexo page header + cards for the metaboxes (adapter), right column cards for publish/visibility/links; amenity chips one style; no WP chrome (Q3). |
| Seasons, Closed dates, Rates, Promo codes, Calendar sync, Bring in rooms | Same table + dialog/side-panel form pattern; empty states with the next step. |
| Emails | Cards: Hotel notifications (checkbox list), Guest emails (list of email rows with status + "Edit" disclosure), Placeholders (collapsible, copy on click), Test email + Log (same width). |
| Appearance | Split layout, toolbar, preview frame, colour fields, collapsible groups (Brand, Text, Buttons & forms, Booking steps, Confirmation, Tabs; Brand open), corners as segmented with previews, fonts as select + sample, save bar with Discard, reset moved into the header's ⋯ menu; title "Appearance". |
| Settings | Sub-navigation (Q1), every tab built from cards + settings rows; Health status rows; Features toggle cards in an even grid; Pages cards with radio cards and disclosure; Import & export with drop zone file field. |
| Wizard | Stepper component, one width, consistent footer. |
| Help | Card grid of topics + FAQ disclosures. |

---

## 7. Quality gates (Step 4–5)

- WCAG AA: every text/background token pair checked by a script; focus visible on every interactive element; keyboard paths for tabs, segmented controls, switches, dialogs, menus; ARIA as in §5.2.
- `prefers-reduced-motion` respected; 150–200 ms transitions only.
- No layout shift: fixed-height skeletons for previews and async numbers.
- Automated checks added to the test suite: no horizontal overflow at 1024/1280/1440/1920 (and 390 for the four phone screens) in EN and BG; WordPress sidebar/admin bar computed styles identical on a Flexo screen and a core screen; no Flexo stylesheet on core screens; every settings form posts the same fields as before.
- After screenshots into `ui-audit/after/` at the same widths, reviewed one by one, repeated until clean; the existing regression suite must stay green.

---

## 8. Decisions I need from you before Step 2

These go beyond styling (structure or behaviour of admin screens – never booking logic or data):

1. **Settings navigation.** 12 tabs don't fit in one row (and Bulgarian is longer). *Proposal:* a left sub-navigation inside Settings, grouped: **Property** (Hotel, Booking rules, Pages, Room pages) · **Money** (Payments, Taxes & invoices) · **Guests & data** (Privacy, Tracking) · **System** (Features, Health, Calendar sync, Import & export). Same URLs (`&tab=…`). Alternative: keep one row with a "More ▾" overflow.
2. **Saving without a page jump.** *Proposal:* settings forms save in the background (the same `options.php` / `admin-post` handlers, same sanitising and nonces), then show a toast and keep the scroll position; if JavaScript is off they submit normally as today. Unsaved-changes bar + leave warning on every settings screen.
3. **Room editor.** *Proposal:* keep WordPress's room edit screen (so revisions, slugs, permissions and third-party metaboxes keep working) and restyle it with a Flexo header and card-style metaboxes; hide the WP metabox chrome (arrows, drag handles). Alternative (bigger, riskier): a custom room screen.
4. **Calendar on phones and narrow screens.** *Proposal:* desktop keeps the grid (sticky room column, fitted days, scroll buttons); phones get an **agenda view** (per day: arrivals, stays, departures, free rooms) with a toggle back to the grid.
5. **Date and time fields in the admin.** *Proposal:* replace native `type=date/time` (which shows mm/dd/yyyy and 12 h by browser locale) with a text field + the plugin's own calendar popover in the site's format and 24 h time. The submitted value format stays `Y-m-d` / `H:i`.
6. **Other plugins' notices on Flexo screens** (e.g. Elementor's survey). *Proposal:* keep them in WordPress's notices area (a WordPress convention) but compact (one line, expandable), below the page header instead of above the content. Alternative: collect them behind a "Notices (2)" button.
7. **Booking details actions.** *Proposal:* header shows the main action for the status (Confirm / Record payment / Restore) + Add note; Show in calendar, Resend email, Cancel booking, Remove personal data, Delete go into a "⋯" menu. Same actions, fewer competing buttons.
8. **Font.** *Proposal:* keep the bundled Inter (already shipped, Cyrillic, consistent across Windows/macOS). Alternative: WordPress's system font stack (smaller, but Bulgarian renders differently per OS).

Once you approve (with answers to 1–8, or "as proposed"), Step 2 builds the stylesheet, the helpers and the shared JS, then Step 3 moves the screens over one by one, with the test checks above after each screen.

---

## 9. Result of Steps 2–5 (design system, screens, accessibility, visual QA)

Built as proposed, with your answers to §8 ("as proposed"). Booking logic, data, settings keys, field names, nonces and the front end are unchanged; the full regression suite passes (§9.6).

### 9.1 What replaced what

| Before | After |
|---|---|
| 4 stylesheets, 5,188 lines, two design layers, enqueued from six classes | **one** stylesheet `assets/css/flexo-admin.css` (tokens → base → layout → components → screens → WordPress markup → responsive → reduced motion), enqueued once, only on the plugin's screens, with one shared script |
| 14 + 13 + 10 + 8 font sizes, 17 + 15 radii, 52 + 36 + 32 hex colours | type scale 12/13/14/16/20/24, radii 6/8/12/pill, every colour a token (`--fx-*` on `body.flexo-admin-ui`) |
| Every screen wrote its own HTML | `Flexo_Booking_UI` helpers: page header, cards (also folding), settings rows, radio cards, segmented controls, colour field, settings navigation, save bar, "⋯" menu, status pill, empty state, skeleton, preview toolbar, date/time fields |
| Emoji and glyphs for statuses (✓ ⏳ 💳 🏦 ✎ ⛔ ⇄ ⚠ ✕ 🧾 ☎ ✉) | one stroke icon set (inline SVG; CSS masks for status icons); one status palette for pills, calendar bars, agenda, legend and Health |
| jQuery UI date picker on two screens, native `type=date/time` elsewhere | one pop-over date picker in the site's date order and a 24-hour time list everywhere; submitted values unchanged (`Y-m-d`, `H:i`, `DD.MM.YYYY`) |
| Fixed save bar covering content | a save bar in the form's flow that floats while the form scrolls, with unsaved state, **Discard changes**, a leave warning, and background saving with a toast (Settings tabs, Appearance); reload-in-place with the scroll kept (Features, Pages) |

Token changes after the AA check (§9.5): muted text `--fx-gray-500` #687482 → **#5c6876** (was 4.44 : 1 on the page background), placeholders **#6a7582** (was 2.6 : 1), and a new `--fx-border-control` **#85909c** for field, checkbox and switch edges (3 : 1, WCAG 1.4.11).

### 9.2 Your eight defects

| # | Defect | Now | Before → after |
|---|---|---|---|
| 1 | Radios not aligned in the Style cards | **Fixed.** One radio-card component everywhere: the radio sits on the title's first line, title and description share one left edge, the whole card is the label. | `before/appearance-match@1440.jpg` → `after/appearance-match@1440.jpg` |
| 2 | Preview toolbar overflowing | **Fixed.** Tabs on the left (*Booking form · Booking page · Thank You page*), device switch (desktop/phone icons with tooltips) and "Open page in a new tab" icon on the right; below 480 px of toolbar width the tabs become a select. No overflow in English or Bulgarian. | `before/appearance-custom@1280.jpg` → `after/appearance-custom@1280.jpg` |
| 3 | Colour rows: same greyed #1f6f5c everywhere | **Fixed.** Each colour is **Website colour \| Custom**. *Website colour* shows the colour really used, with its name and code (*Elementor: Accent #61CE70*, *Same as the main colour*, *The text colour, lighter*…), updated live as other colours change; *Custom* gives picker, code and **Reset**. Grouped in folding sections: Brand (open), Text, Buttons and fields, Booking steps and summary, Confirmation, Tabs, Booking and Thank You pages, "Check availability" panel. | `before/appearance-custom@1440.jpg` → `after/appearance-custom@1440.jpg` |
| 4 | Save bar overlapping content | **Fixed** on every screen. The bar is part of the form: it reserves its own space, lines up with the cards and rests after the last field. While the form scrolls, it floats 12 px above the bottom of the window and shows *You have unsaved changes* and *Discard changes*. Full-page captures show it where it rests, after the form; `after/appearance-unsaved@<width>.jpg` shows it while editing. | `before/add-booking@1440.jpg` → `after/add-booking@1440.jpg`, `after/appearance-unsaved@1440.jpg` |
| 5 | WordPress sidebar padding (leak) | **Not reproducible here (as in Step 1), now guarded.** The stylesheet has no selector for WordPress's menu, toolbar, body or footer, every rule is scoped under `body.flexo-admin-ui`, and it is not loaded on other screens. `tests/ui/ui-check.js` compares the computed styles of the menu, a menu item, a submenu link and the toolbar on every plugin screen against a core screen: identical on all 28 screens. If you still see it, please send the screenshot and the list of active plugins. | `before/wp-dashboard@1280.jpg`, `after/*` |
| 6 | Top bar overflowing | **Fixed by layout rules**: the search shrinks (140–440 px); below 960 px the site name hides; below 782 px the plugin name hides and the search gets its own row; below 600 px "New booking" becomes an icon. The three icon links have tooltips. | `after/today@1280.jpg`, `after/bg-today@1280.jpg`, `after/today@390.jpg` |
| 7 | Preview clipped, nested scroll | **Fixed.** The preview panel is as tall as the window and sticky; the page inside is drawn at a real device width (1280 px desktop scaled to fit, or a 390 px phone) and only that page scrolls; a placeholder shows while it loads. | `after/appearance-custom@1440.jpg` |
| 8 | Preview with the narrow layout and old texts | **Fixed.** Desktop preview renders the desktop layout (1280 px, scaled); the sample room shows the real "Select this room →" button and its hint. | `after/appearance-custom@1920.jpg` |

### 9.3 The per-screen list (§3)

**Fixed:**
- **App shell:** S-01 (one page header everywhere), S-02 (one tab style; segmented controls for views; a vertical stepper in the wizard), S-03 / G-01 (grouped Settings navigation at the side, a select on narrow screens), S-04, S-06, S-07 (the search shrinks; the top bar's icon links have tooltips), S-09, S-10 (one focus ring), S-12 (one page width, full-width cards), S-13 (Screen Options switched off on the plugin's screens with WordPress's own filter).
- **Today:** T-01, T-02, T-03, T-04. In a narrow card, the status and buttons of a guest row now go under the name.
- **All bookings:**
  - L-01;
  - L-02: below 1280 px the list becomes cards, and each stay date stays on one line;
  - L-04: the row has its main action plus "⋯" with *Open booking*, *Restore booking*, *Cancel* and *Delete*;
  - L-08 (sentence-case headers).
- **Booking details:**
  - B-02 (Q7);
  - B-03 (one disclosure style);
  - B-04: the destructive button is red, the dialog keeps focus, Escape closes it;
  - B-05: an empty state with *All bookings* and *Calendar*.
- **Add booking:** A-01, A-03 (*Confirmation email*).
- **Calendar:** C-01 (sticky room column, week scroll buttons, start on a whole day near today, Grid \| Agenda), C-02 (the legend explains *2/3*), C-03 (shared icons and status colours), M-03 (agenda by default on phones).
- **Emails:** E-01 (one checkbox per line), E-02 (placeholders folded), E-03 (every card full width, the email log in its own card).
- **Appearance:** P-01 (folding groups, Brand open), P-02, P-03 (corners as a segmented control), P-04 (reset in the page header, with a confirmation).
- **Settings and other screens:** G-03 (24-hour time fields), G-06, G-09 (*Save changes*), W-01, H-01.
- **Bulgarian:** BG-01, BG-02.

**Partly fixed:**
- **S-05 / M-04 (other plugins' notices):** they are compact and lose the duplicated icon. WordPress still prints them above the content; collecting them behind a button would hide notices that WordPress shows on every admin screen.
- **S-08 (date formats):** date fields use the site's date order. Dates shown in lists and in the details header keep their formats. That is data formatting, not styling, so I left it to avoid changing texts that guests and staff already know.
- **S-14 (back links):** there is a back link on Booking details, Add booking and *Booking not found*. The room editor keeps WordPress's own title row (Q3).
- **T-05 / M-02 (header buttons on phones):** they form a two-column grid, and a third button takes a full row.
- **L-03, L-05, L-06, L-07 (All bookings):**
  - same content, with the new hierarchy and the filter card;
  - *Clear filters* appears once a filter is set;
  - the empty search keeps its one-line message, now with *Show all bookings*;
  - the count still sits above the table.
- **B-01 (Booking details):** the cards line up in rows, so a short card still leaves space below it.
- **A-02, A-04 (Add booking):**
  - one field group for adults and children;
  - the plugin's date picker;
  - the price still appears only after saving, because a live price would be new behaviour.
- **C-04 (calendar bars):** the ✓ ✎ glyphs are now the shared icons. A narrow bar still shortens the name, and the full name is in the bar's tooltip, as before.
- **R-01 (room editor):** restyled with cards, without the metabox arrows, drag handles and Screen Options. It is still WordPress's edit screen, with its title field and editor toolbar, as decided in Q3.
- **R-02 – R-05 (rooms and prices):**
  - the list tables, prices (right-aligned), chips and the seasons / closed dates / rates / promo tables use the shared components;
  - WordPress's bulk actions and date filter on the rooms list stay;
  - the in-row *Delete* buttons stay, because removing them changes what staff can do;
  - rooms without a photo still show an empty thumbnail.
- **G-02, G-05, G-07, G-08 (Settings):**
  - rows and cards are aligned;
  - some rows still have small inline labels (*Number format*, *Decimals*);
  - the booking-mode radio cards stay above the feature cards;
  - the Pages field list keeps its three-column layout;
  - the file field on Import & export is WordPress's own.
- **M-01 (section buttons on phones):** they scroll sideways, without a fade at the edge.

**Not changed:**
- **S-11:** the footer line is printed in WordPress's `#wpfooter`, and restyling it would touch WordPress's chrome.
- **T-06:** the greeting uses the name that WordPress provides.
- **A-05:** validation stays on the server, after saving.
- **B-06, R-06, P-05:** wording. I kept these to avoid text changes nobody asked for.
- **C-05:** the calendar's "today" summary stays, now in the same card style.
- **E-04:** "Edit text" stays at the end of each email's subject row.
- **G-04:** "Show advanced settings" stays in the header.
- **W-02:** WordPress decides which menu item is highlighted.
- **AG-01:** the Agency screen is only for agency users and was not audited. It uses the same components.
- **BG-03:** this is about the test site, not the plugin.

### 9.4 Measurements (same screens, same widths)

Measured by `tests/ui/audit-shots.js` on the same seeded site, same screens and widths (`before-metrics.json` → `after-metrics.json`, Bulgarian in `*-bg-metrics.json`):

| Measure | Before | After |
|---|---|---|
| Screens × widths measured | 145 | 148 (plus the window-sized save-bar capture) |
| Pages that scroll sideways | 0 in English · **1** in Bulgarian (All bookings) | 0 · 0 |
| Screens with content cut at the right edge | **24** in English · **4** in Bulgarian | 1 in English (the calendar grid at 1280 px, which scrolls in its own box with *Earlier / Later days* buttons) · 0 in Bulgarian |
| Text sizes in use | **18** (10 – 28 px, including 12.5, 13.5, 14.5 and 23.8) | the scale 12 · 13 · 14 · 16 · 20 · 24, plus 15/18 px only for the room editor's ↑ ↓ × glyph buttons and the search-result preview title |
| Button heights (plugin screens, outside WordPress's own editor) | 30, 32, 36, 38, 40, 44, 57 | 28 (compact), 32, 36, 40, and 44 on touch screens; calendar day cells 53/56 |
| Button corner radii | 0, 2, 8, 10, 14 px and pill | 6 and 8 px and pill (0 for text-link buttons; WordPress's editor tabs keep their own) |
| WordPress submenu padding on plugin screens | 14 px / 13 px, same as core screens | unchanged, and now checked on every screen by `ui-check.js` |
| JavaScript errors | 0 | 0 |

### 9.5 Accessibility (Step 4)

- **Contrast (WCAG AA):** every text/background token pair was checked by script. After the token changes in §9.1: placeholders 4.69 : 1, muted text 4.97 : 1 (on the grey fill) to 5.68 : 1 (on white), status pills 5.9–7.2 : 1, primary buttons and links 6.07 : 1, white on the red button 5.47 : 1; field, checkbox and switch edges 3.25 : 1 on white (3.02 : 1 on the page grey). Disabled dates in the picker are lighter on purpose (inactive controls are exempt).
- **Keyboard:** one visible focus ring on every control; "⋯" menus (arrow keys, Home/End, Escape returns focus, Tab closes); tabs (arrow keys); radio cards and segmented controls are real radio groups; date picker (arrows, Page Up/Down, Home/End, Escape); confirmation dialogs are `<dialog>` (focus stays inside, Escape closes, focus returns to the button).
- **ARIA:** `aria-current` in the Settings navigation, `role="tablist"`/`tab` with `aria-selected`, `aria-haspopup`/`aria-expanded` on menus and pickers, `aria-pressed` on toggle buttons, labelled groups for colour fields and settings rows, `aria-live` for the save bar and toasts.
- **Motion:** 150–200 ms transitions; `prefers-reduced-motion` turns transitions and the loading shimmer off.
- **No layout shift:** the save bar is in the flow; previews have a placeholder of their final size; menus and pickers float above the page.
- **Bulgarian:** checked on Today, All bookings, Booking details, Settings → Hotel, Settings → Features and Appearance (`after/bg-*@1280.jpg`): no overflow, longer labels wrap inside their column.

### 9.6 Found in the visual check (Step 5) and fixed

The after screenshots showed these problems, which were fixed before the final captures:

- **All bookings at 1280 px:** the guest and stay columns were squeezed to one word per line.
  - Tighter cells, a minimum width for the guest column, and stay dates kept on one line.
  - Below 1280 px the list becomes cards.
  - *Restore booking* moved into the row's "⋯" menu.
- **All bookings in Bulgarian:** the "⋯" buttons were cut off. The reference column's badge may now take two lines, and the actions column stays in view at the right edge if a language is still too long.
- **Phone cards:** the card list was wider than the screen, because the scroll box's 720 px minimum still applied.
- **Touch sizes:** below 782 px, the 44 px controls and the 12 px page gutter never applied. The overrides were weaker than the token definitions. Below 1280 px, the narrower label column had the same problem. All three apply now; segmented controls follow the control height.
- **Save bar:**
  - It now lines up with the cards (before, it stuck out 12 px, and on Add booking it was shifted 12 px).
  - It is a complete bar after the form and floats 12 px above the window edge.
  - On phones it floats only while something is unsaved.
- **Today:** in a narrow card, a guest's name, room and dates were squeezed beside the status and *Call*. They now stack.
- **Calendar:**
  - The grid starts on a whole day near today.
  - Day names and free counts moved from 10/11 px to the 12 px step of the scale.
- **Smaller fixes:**
  - the top bar's icon links use the shared tooltip;
  - *Adults* / *Children's ages* and *Number format* / *Decimals* are spaced as one field group;
  - a closing note after a card's options has space above it.

### 9.7 Tests

- **PHP suites** (`tests/run.sh`, on a copy of the test site): 19 suites, 1,471 checks, all pass. The three concurrency checks pass too.
- **Browser suites** (days 1–8 and the Appearance suite, on a second copy): all pass – day 1: 37, day 2: 52, day 3: 63, day 4: 49, day 5: 167, day 6: 117, day 7: 61, day 8: 46, Appearance: 46 (638 checks).
  - They were updated only where the markup changed on purpose: the date picker, the "⋯" menus (row and booking actions), the shared confirmation dialog, background saving (the `save()` helper waits for the save instead of a page load), the colour fields, the grouped Settings navigation, and the phone calendar starting as an agenda.
  - Day 2 also opens the right month for each calendar check now. The seed places bookings 1–18 days after next Monday, which crossed into November on this run. That was a date-dependent test, not a plugin change.
- **`tests/ui/ui-check.js` (new)** – 124 checks, all passing:
  - no sideways scroll on 28 screens at 1024, 1280 and 1440 px, or on the phone screens at 390 px;
  - WordPress's menu, a menu item, a submenu link, the collapse button and the toolbar have the same computed styles as on a core screen;
  - no plugin admin styles or scripts, and no plugin body class, on four core screens;
  - the stylesheet has no `!important`, no selector for WordPress's menu, toolbar, body or footer, and every one of its 1,067 rules is scoped.
- **Screenshots:**
  - `ui-audit/after/<screen>@<width>.jpg` (same screens and widths as `before/`), `after/appearance-unsaved@<width>.jpg` and `after/bg-*@1280.jpg`;
  - measurements in `after-metrics.json` and `after-bg-metrics.json`.
  - On the room editor, WordPress's own "Connection lost" and "backup of this post" notices come from WordPress's connection check (heartbeat) on the local test server, not from the plugin.

---

## 10. Form controls: the radio dot was not centred (and every other custom control)

Images: `controls/root-cause.png` (the cause, step by step), `controls/before-after-1.png` and `-2.png` (the same controls before and after, zoomed, at 3× and 3.75× in Chromium and 3× + 125/150 % in WebKit), and one zoomed sheet per engine, area and scale in `controls/before/sheets/` and `controls/after/sheets/`. Red cross = the circle's or box's centre, green = the centre of the dot, tick or knob.

### 10.1 Cause (computed styles of the input and its `::before`, Bookings → Appearance → Style)

- **The dot was a `::before` placed by margins in normal flow:** `display: block; float: none; width: 8px; height: 8px; margin: 2.5px auto 0`, inside a 16 px radio with a `1.5px` border.
- **The border rounds differently at each zoom.** Chromium draws borders in whole device pixels, so the computed `border-top-width` is **1px** at 100 % (and 0.8 px at 125 %). The dot's centre is then 1 + 2.5 + 4 = 7.5 px from the top of a 16 px circle: **0.5 px high at 100 %, 0.7 px at 125 %** (panel A).
- **Its position depended on properties that other style sheets set on the same pseudo-element.** WordPress core (`load-styles.php`) sets:
  - on `input[type=radio]:checked::before`: `float: left; display: inline-block; vertical-align: middle; width: .5rem; height: .5rem; margin: .1875rem; line-height: 1.14285714`;
  - on the input: `display: inline-block; line-height: .71428571` (radio), `line-height: 0`, `margin: -.25rem .25rem 0 0`.
- **Our rules were the weakest possible.** They were wrapped in `:where()`, so the `::before` rule had the same specificity as core's (0,2,2) and won only by loading later. Any more specific rule from a plugin, theme or admin colour scheme (`.wp-core-ui input[type=radio]:checked::before …`) beats it. Once the `::before` is `inline-block` and the input's line box is taller than the circle, the dot drops to the bottom of the circle (panels B–E; D and E are the "dot near the bottom" look you described).
- On this test site (WordPress 6.7, no Elementor) panel A is what shipped. I could not load your site's other plugins here, so I can't name the exact rule that wins on your site. The fix doesn't depend on it: the dot no longer uses `::before` at all.
- **The same weakness was in the other controls:**
  - the checked checkbox tick (a `::before` with a background);
  - the switch knob (a `::after` positioned with `top/left` and `translateX(16px)`, rounded differently at 125 %);
  - the booking form's checkboxes and radios (native, with `accent-color`, which WebKit ignores, and which themes and the Elementor kit restyle).
  - The before run measured:
    - the admin radio up to 0.71 px off in Chromium and 0.80 px in WebKit (1.33 px with theme CSS);
    - no visible tick on a disabled checked checkbox;
    - with typical theme/plugin CSS added, dots and ticks moved by several pixels.

### 10.2 Fix: every mark is drawn by construction

**Admin (`flexo-admin.css`)**
- `appearance: none`, `box-sizing: border-box` and a fixed size from tokens:
  - `--fx-check-size` 18 px (22 px on touch screens);
  - `--fx-check-border` **2 px**, a whole pixel;
  - `--fx-check-dot` 8 px (10 px on touch screens);
  - `--fx-check-tick` 12 px (14 px on touch screens).
- **Radio dot:** a `radial-gradient` background, centred on the border box (`background-origin: border-box; background-position: center; background-size: 100% 100%`). Border rounding can't move it, because it is not inside the border.
- **Checkbox tick:** an SVG background, centred the same way, white on the filled box. A disabled checked checkbox keeps a visible grey tick.
- **No `::before` or `::after`.** Both are set to `content: none; display: none` with `:is(:checked, :not(:checked))`. That raises the rule above core's, themes' and plugins' `:checked::before` rules without `!important`.
- **Switch:**
  - the knob is a background tile of the track: a white disc in a square tile as tall as the track, with a soft edge ring;
  - it moves from `background-position: 0 0` to `100% 0`;
  - it is centred vertically by construction, with the same gap at both ends at any zoom.
- **Segmented control:** its hidden radio stays hidden (`min-width: 0; border: 0`), so the new base size can't leak into it.
- Unchanged:
  - the focus ring (`--fx-ring` on `:focus-visible`), hover (`:hover:not(:disabled)`), disabled and checked states;
  - the markup, names and settings;
  - all rules are scoped to `body.flexo-admin-ui :is(.wrap, .fx-layer)`, with no `!important`;
  - reduced motion: the `--fx-fast`/`--fx-slow` tokens are zeroed.
- The first-line offset and hanging indents use `--fx-check-offset` and `--fx-check-indent`, so text next to a control stays aligned with the larger size.

**Booking form (`booking.css`)**: the rate plan and payment choices (`.fb-choice`), the invoice, consent and terms checkboxes (`.fb-terms`), and the other checkboxes (`.fb-check`).
- The same reset under `.flexo-booking.flexo-booking …`. The doubled class beats theme and Elementor-kit rules of up to three classes.
- The radio dot is a gradient.
- The tick is a `::before` covering the inner box (`inset: 0`) in `--fb-primary-contrast`. It is masked by an SVG whose view box centres the tick and which fills the layer (`0 0 / 100% 100%`), so the tick snaps to pixels with the box itself.
- Sizes: 18 px, 20 px for choice cards, 22 px on phones.
- The border is `--fb-control-border` (text colour mixed into the field background).
- Focus outline 2 px; disabled at 50 % opacity.

### 10.3 Verification (`tests/ui/controls-check.js`)

**Every control type and state:**
- admin: radio, real Appearance radio cards, radio card, checkbox, checkbox in a paragraph, switch and segmented control;
- booking form: terms, checkbox, radio and choice card;
- states: unchecked, checked, disabled (both), hover and keyboard focus.

**Engines and scales:**
- Chromium, as Chrome and Edge: device scale 1, 1.25, 1.5, 1.5625, 1.875, 2, 2.25, 2.5, 3, 3.75, 4.5, 6. That is browser zoom 100/125/150/200 % on 1×, 1.25× and 1.5× (Windows scaling 125 %/150 %) and 3× screens.
- WebKit (Safari's engine, as WebKitGTK 2.52 under Xvfb): 1×, 2× and 3× screens, each at page zoom 100/125/150/200 %.

**Two modes:**
- normal;
- with "hostile" CSS: WordPress-core-like `.wp-core-ui … :checked::before` rules, a Twenty Twenty-One-like checkbox theme (the real TT1 stylesheet on the front end) and an Elementor-kit rule.

The geometry check measures the pixels and fails above **0.5 CSS px** for the dot, tick, knob and selected segment.

**Results** (checked controls within 0.5 CSS px / all checked controls; *worst* = largest offset of a dot, tick, knob or segment):

| Engine · area · control | Before (old CSS) | After |
|---|---|---|
| chromium admin checkbox | 48/60 · worst mark missing | 60/60 · worst 0.10 px |
| chromium admin radio | 89/96 · worst 0.71 px | 96/96 · worst 0.40 px |
| chromium admin segmented | 36/36 · worst 0.25 px | 36/36 · worst 0.25 px |
| chromium admin switch | 84/84 · worst 0.12 px | 84/84 · worst 0.17 px |
| chromium admin + hostile CSS checkbox | 0/60 · worst 4.60 px | 60/60 · worst 0.10 px |
| chromium admin + hostile CSS radio | 0/96 · worst 1.28 px | 96/96 · worst 0.40 px |
| chromium admin + hostile CSS segmented | 36/36 · worst 0.25 px | 36/36 · worst 0.25 px |
| chromium admin + hostile CSS switch | 84/84 · worst 0.12 px | 84/84 · worst 0.17 px |
| chromium front checkbox | 60/72 · worst mark missing | 72/72 · worst 0.20 px |
| chromium front radio | 72/72 · worst 0.28 px | 72/72 · worst 0.12 px |
| chromium front + hostile CSS checkbox | 0/72 · worst 13.04 px | 72/72 · worst 0.20 px |
| chromium front + hostile CSS radio | 0/72 · worst 10.44 px · 22 unchecked show a mark | 72/72 · worst 0.11 px |
| webkit admin checkbox | 48/60 · worst mark missing | 60/60 · worst 0.40 px |
| webkit admin radio | 78/87 · worst 0.80 px | 87/87 · worst 0.32 px |
| webkit admin segmented | 36/36 · worst 0.48 px | 36/36 · worst 0.48 px |
| webkit admin switch | 80/84 · worst 0.40 px | 84/84 · worst 0.06 px |
| webkit admin + hostile CSS checkbox | 0/60 · worst 4.28 px | 60/60 · worst 0.40 px |
| webkit admin + hostile CSS radio | 0/87 · worst 1.33 px | 87/87 · worst 0.42 px |
| webkit admin + hostile CSS segmented | 36/36 · worst 0.48 px | 36/36 · worst 0.48 px |
| webkit admin + hostile CSS switch | 82/84 · worst 0.44 px | 84/84 · worst 0.06 px |
| webkit front checkbox | 50/72 · worst 0.75 px | 72/72 · worst 0.20 px |
| webkit front radio | 64/72 · worst 1.51 px · 8 unchecked show a mark | 72/72 · worst 0.23 px |
| webkit front + hostile CSS checkbox | 0/72 · worst 13.03 px | 72/72 · worst 0.20 px |
| webkit front + hostile CSS radio | 0/72 · worst 10.43 px · 23 unchecked show a mark | 72/72 · worst 0.23 px |
| WordPress core screens (4 screens × 6 controls) | 24/24 identical | 24/24 identical |

Total: **before 1,512 passed, 732 failed → after 2,244 passed, 0 failed.**

**What I fixed in the measurement during this run:** WebKit's page zoom is applied as `zoom` on the root, so element rects come back zoomed but computed borders don't. The first after-run therefore measured WebKit at 125–200 % with too thin a ring and in device pixels, and reported dots that weren't there. The check now uses screen scale × page zoom, calibrated from two squares at opposite ends of the fixture. Both runs above use the corrected check.

**Then fixed in the CSS:** the booking form's terms tick under the theme CSS at WebKit 1× / 125 % was 0.47, 0.53 px off. That was the mask's own centring (`center / 86%`) rounding on top of the box's. The mask now fills the layer, and the tick is centred by the SVG's view box.

**I inspected the zoomed sheets and crops myself.** At every scale, the dots sit on the circle centres, the ticks are centred in the box, the knobs have equal gaps, and the disabled ticks are visible.

**WordPress's own screens are unchanged:** on General, Discussion and Reading settings and the Posts list, the checkboxes' and radios' computed styles and pixels match the before run. Plugin CSS is not loaded there.

**Regression:**
- `tests/ui/controls-check.js`: 2,244 / 2,244 (above). `tests/ui/ui-check.js`: 124 / 124.
- PHP suites (`tests/run.sh`): pass, except the checks that need Elementor (`test-day7-elementor`, `test-room-design`) and three in `test-day7` that need `register_block_template` (WordPress 6.7 test build). No PHP changed in this task.
- Browser suites on the final CSS: day 2: 52, day 3: 63, day 4: 49, day 5: 167, day 8: 46, Appearance: 46 – all pass. Day 6: 116 / 117 (the failing check needs an Elementor page). Day 7: two room-page checks that need Elementor fail. Day 1 can't run here: its seed needs Elementor (`ELEMENTOR_VERSION`).

**Limits of this environment:**
- **Firefox:** it can't be downloaded here (Mozilla's and Playwright's download hosts are blocked), so Firefox wasn't run. The controls use only `appearance: none`, gradients, SVG backgrounds and masks, which Firefox supports alike.
- **Safari** is represented by WebKitGTK: same engine, Linux graphics.
- **Edge** is Chromium.
- **Windows scaling** is the Chromium device scale.
- **Elementor** couldn't be installed (wordpress.org is blocked), so the Elementor kit is represented by its typical rule.
- **WordPress** is 6.7.
