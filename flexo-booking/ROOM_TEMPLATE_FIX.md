# Room pages and the Elementor Pro single room template – diagnosis (Step 1)

Status: **diagnosis only, nothing changed.** Waiting for approval of the plan
at the end before Step 2.

**What I worked from:**
- Your template export `elementor-3270-2026-10-04.json`, i.e. template ID **3270**.
- Your two screenshots: the designed page, and the room page the plugin shows today.
- The plugin code (1.8.0).

**What I could not use:**
- **Your live site.** This environment's network policy refuses
  `azure.flexohotels.com`, so menus, footer links, pages and conditions on
  the site itself could not be read.
- **Elementor Pro.** It is not installed here, so nothing below was run
  inside Pro.

Every statement below says which of these it rests on.

---

## Summary: why the plain page appears

1. **No Theme Builder single template is assigned to Flexo Booking rooms.**
   - Template 3270 was built for the **JetEngine** post type `rooms`: its
     preview is `single/rooms`, previewing JetEngine room #3218.
   - Flexo Booking rooms are a different post type, `flexo_room`.
   - Display conditions are not part of an export, but a condition made for
     JetEngine rooms (`include/singular/rooms`) never matches a `flexo_room`
     page.
   - The plugin shows its own page **only when Elementor Pro reports that no
     single template applies** (see 3), so the plain page in your screenshot
     is exactly that fallback.
   - Extra trap: both post types are called **"Rooms"**. While JetEngine is
     active, Theme Builder's condition list and the Loop Grid / Loop
     Carousel "Source" list show *two* identical "Rooms" entries.
2. **The template would not work for Flexo rooms even with the right condition.**
   - Price, description, facts and amenities are **JetEngine tags**
     (`jet-post-custom-field`: `price_per_night`, `long_description`,
     `room_size`, `max_guests`, `beds_info`, `amenity-1` … `amenity-8`).
     Flexo rooms keep their data elsewhere, and these tags stop working
     entirely once JetEngine is switched off.
   - Only the title (`post-title`) and the hero background
     (`post-featured-image`) would already show Flexo data.
3. **The plugin yields correctly; that part is not the bug** (code review,
   not run in Pro). It hooks `template_include` at priority 99, after Pro's
   priority 11, and replaces the template only when:
   - this is a single room page, not a 404 and not a block theme, **and**
   - `Flexo_Booking_Room_Pages::pro_template_applies()` is false. That method
     asks Pro's own conditions manager (`get_documents_for_location( 'single' )`).

   **It does have one injection that breaks your rule:** the phone
   "Check availability" bar (`wp_footer`) is added to every room page, *also*
   when a Pro template applies.
4. **The header breaks only on the plugin's plain page.**
   - Your header is transparent, with white logo and links, and sits on top
     of the page. Your template leaves room for it: the hero has 17 % top
     padding (laptop 21 %, tablet 30 %, mobile 65 %) over a dark photo.
   - The plain page starts with dark text on white directly at the top. So
     the white menu lies over the gallery and the logo and "Book your stay"
     are almost invisible (your 2nd screenshot).
5. **The plugin's room data is not what your page shows today.** For Sea View
   Double Room, your 2nd screenshot shows:
   - description "Wake up to the Aegean…" and six ready-made amenities;
   - the live page shows "Wake up to the sight of endless blue…" and eight
     JetEngine amenities.

   So this room was not (or not fully) brought in from JetEngine. Once the
   template is dynamic, it shows the plugin's data. "Bring in rooms" can copy
   the JetEngine texts and amenities over (it keeps prices); see the
   questions at the end.

---

## 1. The plugin's room post type (code)

| Setting | Value |
|---|---|
| Post type | `flexo_room`, label **"Rooms"** / "Room" |
| `public`, `publicly_queryable`, `show_in_nav_menus` | true, true, true |
| `exclude_from_search` | false (hidden rooms are removed from search separately) |
| `has_archive` | false (lists are built with Loop Grid / Loop Carousel) |
| Rewrite | `/{base}/{slug}/`, base from *Settings → Room pages* (default `rooms`), `with_front` false, no feeds or paging |
| `supports` | title, editor (full description), excerpt (short description), thumbnail (main photo), page-attributes (display order) |
| `show_in_rest` | false (classic room screen) |
| Taxonomy | `flexo_room_type` (Room type), not publicly queryable |

**Theme Builder and Loop queries.** Elementor Pro offers a post type as
*Include → Rooms / All Rooms / a specific room* and as a Loop Grid / Loop
Carousel query source when it is public and `show_in_nav_menus` is true.
`flexo_room` meets both, so it should be listed as **"Rooms"**. This is from
Pro's documented behaviour; I have not seen it in Pro. The issue is the
duplicate name next to JetEngine's "Rooms" (Summary 1).

## 2. Your template (export)

| | |
|---|---|
| ID | **3270** (from the export file name `elementor-3270-…`) |
| Title | Single Room Template |
| Type | `single-post`: Theme Builder → **Single Post**, location *single* |
| Preview | `single/rooms`, preview item 3218, which is the **JetEngine** room type |
| Display conditions | **not in the export**. Very likely *Include → Rooms* (JetEngine). Please check it: Templates → Theme Builder → Single → Single Room Template → ⋯ → **Display Conditions**. |
| Plugin's own check | *Bookings → Settings → Room pages → Elementor templates for rooms* currently says "No Elementor template is set for rooms yet" when no template targets `flexo_room`. |

**Not part of this template:** the header (logo, menu, phone, "Book your
stay") and the footer ("Book Your Summer Escape Today", newsletter, footer
menus). They also appear around the plugin's page in your 2nd screenshot, so
they are your site's header and footer templates and stay untouched.

## 3. Does the plugin override the template? (code)

| Hook | What it does | When a Pro single template applies |
|---|---|---|
| `template_include` (99) | Plain room page `templates/single-room.php` | **Not used**: returns Pro's template untouched |
| `the_content` (20) | Adds the room page to the content | Only on block themes before WP 6.7, **and not** when a Pro template applies |
| `template_redirect` (1) | 404 for hidden/demo rooms (guests); redirects from an old address word to the current one | Same for all pages (no output) |
| `wp_head` (2), `pre_get_document_title` (20) | Room SEO title and description (only without Yoast/Rank Math), structured data (HotelRoom) | Runs; wanted ("SEO title/meta → room SEO fields") |
| `wp_footer` | Phone "Check availability" bar | **Runs. Injected content, against your rule. Fix in Step 2 A.** |
| `pre_get_posts` | Leaves hidden rooms out of lists and search | Lists only; the room's own page is not affected |

There is no `single_template` filter, no output buffering and no other
content filter. The decision uses the same Pro call that Pro uses for its own
templates. This was tested with a stand-in, not in Pro.

## 4. Duplicate pages and links

From the template:

| Element | Text | Link today |
|---|---|---|
| `1f1a7ebc` button (hidden on phones) | check availability | `/amenities/`, which looks like a placeholder |
| `69ccc3ba` button | make a reservation | **none** |
| `33922a2a` button, "Book Directly" banner | book now | `/contact/` (hotel-wide) |
| `d46ea4c` button (desktop, laptop) | see all rooms & villas | `/accommodation/` |
| `30db8931` button (tablet, phone) | see all rooms & villas | **`/amenities/`**, probably a mistake: same text, different page than on desktop |
| Room cards in the carousel | 5 slides | **no links**. "Garden View Double Room" appears twice; prices are typed in (€270, €350, €190, €190, €750) |

**Not readable from here (live site blocked):**
- where the header *Accommodation* menu and the footer *Accommodation* links
  (Garden View Double Room, Sea View Double Room, Boho Family Suite, Azure
  Private Villa, Deluxe Pool Villa) point;
- whether these rooms also exist as ordinary Pages;
- whether the JetEngine `rooms` post type is still active.

**What the plugin already does about clashes:**
- *Settings → Room pages* lists Pages under `/rooms/` that have the same
  address as a room. Those pages are no longer shown.
- *Settings → Health* warns while another post type (e.g. JetEngine `rooms`)
  uses the same address word.

## 5. Why the header breaks on the plain page

See Summary 4. Evidence:
- the hero padding values in the export;
- your 2nd screenshot: menu items over the gallery at the same height as on
  the designed page, the logo invisible on white, a faint "Book your stay"
  at top right.

The plain page has no dark hero and no top space.

## 6. Widget inventory of template 3270

R = room-specific (becomes dynamic), H = hotel-wide (stays exactly as is).

| ID | Widget | Content today | | Planned source |
|---|---|---|---|---|
| `29a17521` / `2d209ce7` | container / text | "☆ Available in Exclusive or add to Pro for +€240 (all rooms included)! Get that template!" | H | unchanged |
| `2634eec6` | container (hero) | background = **featured image** (core tag; static fallback image #2292), dark gradient overlay | R | already works: a room's main photo is its featured image. Keep the tag. |
| `367de26d` | dividers + heading | "Accommodation" | H | unchanged |
| `1f564878` | heading | room title = **post title** (core tag) | R | already works; keep |
| `4ad696c9` | container (price badge, decorative background) | – | H | unchanged |
| `1fef4d0f` | heading | "€270" = Jet `price_per_night` + "€" before | R | **Room price** tag → *From price*, currency **before**, no "from" word → "€270" |
| `5925a2e5` | heading | "/ night" | H | unchanged (stays its own line) |
| `41a9e85c` | Icon List, inline, 3 items, own SVG icons #1307, #1306, #1305 | "40 m²" = Jet `room_size`; "1–3 person" = Jet `max_guests` (+ " people"); "1 King bed + sofa bed" = Jet `beds_info` | R | same Icon List, same icons and styles; tags **Room size** (with unit), **Guests** (range "1–3" + "person"), **Beds** |
| `4d1437a1` | heading | "General Description" | H | unchanged |
| `1f1a7ebc` | button | check availability → `/amenities/` | R | **Room booking link** (current room) |
| `fcd3307` | Text Editor | Jet `long_description` | R | **Full description** tag |
| `a14aa61` | heading | "Room Gallery:" | H | unchanged |
| `15c1618c` | **Media Carousel**, 5 fixed photos, 3 per view (phone 2), height 200 / 120 / 170 / 150 px, corners 15 px, no arrows or dots | static photos | R | Media Carousel can't take a dynamic gallery (its slides are a fixed list), so: **Room gallery** widget with the same values (3 / 2 per view, the four heights, 15 px corners, no arrows or dots, lightbox) |
| `a5b92f7`, `7f7628d6` | heading + 2 Nested Accordions (6 questions) | Hotel Rules | H | unchanged |
| `6e9e8102` | heading | "Room Amenities:" | H | unchanged |
| `5092e119` | Icon List, 8 items, own SVG icons #1318, #1319, #1312, #1311, #1317, #1316, #1315, #1314 | Jet `amenity-1` … `amenity-8` | R | **Room amenities** widget with the same look: icon 20 / 12 / 16 / 15 px, DM Sans 300 at 18 / 11 / 14 / 13 px, line height 1.5 em, text #212B2FC2, gap 8 px (laptop 5), same widths. Each amenity uses **your SVG** (see Step 2 C). |
| `438f1e33`, `c236481` | heading + 6 Icon Boxes | Hotel Amenities | H | unchanged |
| `69ccc3ba` | button | make a reservation (no link) | R | **Room booking link** |
| `8205787` / `33922a2a` | heading + button | "Book Directly…" / book now → `/contact/` | H | **unchanged** (my earlier converted file changed this link; the new duplicate won't) |
| `5dd4ddf4` | dividers + headings | "amenities" / "Everything You Need to Feel at Ease" | H | unchanged |
| `65a570ff` | 4 × Premium Addons Banner | | H | unchanged |
| `42f2cf06` | dividers + headings | "accommodation" / "From Boutique Suites to Boho Villas" | H | unchanged |
| `d46ea4c`, `30db8931` | buttons | see all rooms & villas → `/accommodation/`, `/amenities/` | H | unchanged (please confirm the second link) |
| `3b91874a` | **Nested Carousel**, 5 hand-made slides, 2 per view (tablet 1), 20 px spacing, no arrows | static cards: photo background, price badge + "/ night", name, inline Icon List (size, persons, beds) | R | **Loop Carousel**, same carousel settings. Query: Rooms, by display order, **current room excluded**, hidden rooms out. Loop item built from slide `4e437d4d` with the same styles: Room main photo as background, Room price (€270) + "/ night", Room name linked to the room page, the same inline Icon List with Room size / Guests / Beds tags. |

**Checked against the mapping you expected:**
- Everything matches, plus three findings:
  - the gallery is a carousel without arrows, not a grid;
  - the tablet/phone "see all rooms & villas" button goes to `/amenities/`;
  - the promo bar at the top belongs to this template.
- The hero image and title are already dynamic.
- The "/ night" texts are separate headings and stay static.
- Your template also contains settings from Premium Addons, ElementsKit,
  The Plus Addons and Essential Addons. They are kept untouched. There are no
  Jet *widgets*, only JetEngine *tags*.

---

## Step 2: what the plugin already has, and what is missing

| Your requirement | Today (1.8.0) | To do |
|---|---|---|
| A. Pro template wins; plain page only as fallback | Yes (Summary 3) | Phone bar only on the plain page (or when switched on for templates). Rooms called **"Rooms (Flexo Booking)"** where Elementor lists post types, so the two "Rooms" can't be confused. Settings → Room pages names templates still aimed at JetEngine `rooms` and explains the fix. |
| A. Plain page with a working header | No | The plain page opens with a full-width hero (main photo, dark overlay, name, price). Its top space is measured from the real header when the header lies on top of the page, so transparent and normal headers both work. |
| B. Price: "from" yes/no, currency before/after, "/ night" yes/no + text, number only | from/base/weekend, with currency (site setting) or number only. Elementor's own *Advanced → Before / After* on every tag already adds texts like "from " or " / night". | Add **Currency position**: site setting / before (€270) / after (270 €), and **Show "from"** (yes/no). |
| B. Size: number or with unit, unit text editable | number / "40 m²" | Add an editable **unit** text |
| B. Guests: max ("3"), range ("1–3"), editable suffix, translatable | "Up to 3 guests…", "3 guests", numbers | Add **range "1–3"** and a **suffix** (singular/plural, e.g. person / persons) |
| B. Beds, full/short description (HTML kept), featured image as background, gallery for Pro Gallery / Basic Gallery / Image Carousel, booking link, page URL | All present | Check that full-description HTML survives in Text Editor (no double paragraphs) |
| C. Room amenities widget with full Icon List styling | Layout (list, row, columns per device), spacing, alignment per device, divider + colour, icon colour / size / gap per device, typography, text colour, "uploaded icons keep their colours" | Add the missing Icon List controls: icon vertical alignment + offset, text indent, hover colours, divider style / weight / width. Your list uses `icon_vertical_offset` −1 px and `text_indent` 2 px. |
| C. Amenity icons: built-in, uploaded SVG, Elementor icon library | Built-in (126) or uploaded SVG/PNG from the Media Library | Your template's icons are uploaded SVGs (#1311–#1319), so they can be used as they are. Add the **Elementor icon library** (Font Awesome / eicons) as a third choice only if you want it. Add a one-click helper: "use my template's icons" sets these SVGs on matching amenities. |
| D. Loop item matching the card | Starter card and the Azure card (earlier conversion) | Rebuild the Azure card from slide `4e437d4d` with only content sources changed. Query excludes the current room (plugin filter, so Loop Carousel's own "exclude current post" option is not needed). |
| Hide empty fields | Tags return nothing when empty | Headings with no text still keep their box. The empty price badge and empty icon-list rows are hidden with the plugin's CSS for its own tags. Containers can also use Pro's *Display Conditions*. |

## Step 3: what I can and can't do from here

- **I can't log in to or reach your site,** so I can't back up, duplicate,
  preview or screenshot template 3270 *on* it.
- **What I can do:**
  - Build **"Single Room – Dynamic"** as an import file from your export 3270,
    changing only content sources, links and the three widget swaps
    (gallery, amenities, carousel).
  - Prove it with an automatic comparison: every style, layout, spacing,
    responsive and animation setting must be identical to 3270, or the build
    fails.
  - Supply a matching **Room card** loop item.
  - Your original export becomes `flexo-booking/backups/room-template-original.json`.
    It contains only your settings and links to your media, no licensed
    code.
- **Importing creates a new template with no display conditions,** so your
  live pages don't change until you assign *Include → Rooms (Flexo Booking)*.
- **For the screenshot comparison (1440 / 768 / 390) one of these is needed:**
  - you preview on your staging site and send the screenshots; or
  - I get the Elementor Pro zip plus a copy of the site (or network access to
    a staging copy) and do it here.

## Step 4: links (after Step 3)

The header and footer room links and any static room pages can only be
listed once I can read the site, or from your answers below. Planned:
- old Page addresses redirect (301) to the room pages, or the rooms take the
  old slugs;
- menus point to the room posts;
- nothing is deleted or unpublished without your OK.

## Questions before Step 2

1. **Template 3270's display conditions:** please send a screenshot.
2. **Is JetEngine still active,** and is its `rooms` post type still switched on?
3. **The address of the room page in your 2nd screenshot,** and the same
   room's address before (JetEngine).
4. **Where the header *Accommodation* items and footer room links point,**
   or allow `azure.flexohotels.com` in this environment's network settings
   so I can read them.
5. **Yoast SEO or Rank Math:** is either installed?
6. **Room texts:** should the plugin rooms get the texts and amenities that
   your page shows today (JetEngine)? *Bring in rooms* copies them and keeps
   the prices.
7. **"see all rooms & villas" on tablet/phone:** keep `/amenities/`, or use
   `/accommodation/` like desktop? (It's hotel-wide, so I won't touch it
   without your answer.)
8. **Elementor Pro zip:** if you want me to test in real Pro.
