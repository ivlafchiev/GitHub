# Flexo Booking – Admin UI audit and design system proposal

Step 1 of the admin UI overhaul: **audit and proposal only, no code changed.**
Step 2 (the design system) waits for your approval and for your answers in §8.

| | |
|---|---|
| Plugin | 1.8.3 + 1.9.0 work so far (branch `claude/flexohotels-booking-system-ftjrqm`, commit `277cbbb`) |
| Test site | WordPress 7.2, Twenty Twenty-Five, Elementor 4.4 (free), PHP 8.4, MariaDB. Every feature switched on; 5 rooms (3 with photos), 20 bookings in every status, a season, a promo code, a closure, one booking request waiting. |
| Screenshots | `ui-audit/before/<screen>@<width>.jpg`: 48 screens and states at 1280, 1440 and 1920 px (including the WordPress dashboard as a reference), plus 390 px for Today, All bookings, Booking details and Calendar. `bg-*@1280.jpg`: six screens in Bulgarian. 154 files in total. Recreate them with `tests/ui/audit-seed.php` and `tests/ui/audit-shots.js`. |
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
