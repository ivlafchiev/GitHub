# Azure room templates for Flexo Booking

Your "Single Room Template" (exported from Elementor on 2026-10-04),
converted so every room element reads from **Flexo Booking** instead of
JetEngine. The design, texts, fonts, colours and the hotel-wide sections
(rules, hotel amenities, banners) are unchanged.

| File | What it is |
|---|---|
| `azure-single-room-flexo.json` | The single room template (Theme Builder → Single) |
| `azure-room-card-flexo.json` | A Loop item made from your "other rooms" card, for the Loop Carousel |

Made with `bin/convert-jet-room-template.py` (the same script converts other
FlexoHotels templates built on JetEngine rooms).

## What was changed

| Element | Before | Now |
|---|---|---|
| Hero background (`2634eec6`) | Post featured image | **Room main photo** tag (neutral placeholder if a room has no photo) |
| Room name (`1f564878`) | Post title | **Room name** tag |
| Price (`1fef4d0f`) | Jet field `price_per_night` with "€" before | **Room price** tag (price per night, with the currency from Settings; switch it to "From price" if you like) |
| Size / guests / beds (`41a9e85c`) | Jet fields `room_size`, `max_guests` + " people", `beds_info` | **Room size**, **Guests** ("3 guests", translated), **Beds** tags – your icons and styling kept |
| Description (`fcd3307`) | Jet field `long_description` | **Full description** tag |
| Room Gallery (`15c1618c`) | Media Carousel with 5 fixed photos | **Room gallery** widget: each room's own photos, 3 side by side (2 on phones), 200 / 120 / 170 / 150 px high, 15 px corners, no arrows or dots, lightbox – as before |
| Room Amenities (`5092e119`) | Icon List with 8 Jet fields `amenity-1…8` | **Room amenities** widget: each room's own amenities (any number) with their icons; your font, size, colour and spacing copied over |
| "check availability", "make a reservation", "book now" | Links to /amenities/, none, /contact/ | **Room booking link** tag: opens the booking page with this room chosen |
| "Other rooms" carousel (`3b91874a`) | Nested Carousel with 5 fixed room cards | **Loop Carousel** of rooms (the current room left out), using the Room card below; same slides per view and spacing |
| Room card | Fixed photo, price, name, size/persons/beds | Room main photo as background, Room price, Room name (links to the room page), Room size / Guests / Beds tags |
| Preview | JetEngine "rooms" | Flexo Booking rooms |

Not changed: "see all rooms & villas" buttons (they link to your rooms
page), the Hotel Rules accordions, Hotel Amenities icon boxes, the "Book
Directly" section and the four Premium Addons banners.

## How to import

1. Make sure the rooms are in Flexo Booking (**Rooms & prices → Bring in
   rooms** copies them from JetEngine, keeping the same addresses).
2. **Templates → Saved Templates → Import Templates**: import
   `azure-room-card-flexo.json` first, then `azure-single-room-flexo.json`.
3. Open the single room template with Elementor. Click the "other rooms"
   Loop Carousel and choose **Room card (Azure)** under *Choose a template*
   (Elementor gives imported templates new IDs, so this one link has to be
   set by hand). Check *Query → Source: Rooms*.
4. **Publish → Display conditions**: *Include → Rooms → All*. Remove the
   old JetEngine room template's condition (or the old template).
5. In *Preview settings* pick a room to see real data while editing.

Optional: drop the **Room booking box** widget (FlexoHotels category) into
the right column instead of – or next to – the "make a reservation" button,
so guests see availability and the total without leaving the room page.

## Checked here, and what still needs your Elementor Pro

Checked in this environment (Elementor 4.4 without Pro): every converted
dynamic tag and widget renders the room's data (name, price, size, guests,
beds, description, amenities, gallery, booking links, photo), for any room,
with no JetEngine tags left.

Not checked, because Elementor Pro is not available here: importing the
files into Pro's Theme Builder, the display condition, the Loop Carousel and
the Room card loop item, Pro's own widgets in the template (Nested
Carousel → Loop Carousel, Nested Accordion) and the Premium Addons banners.
