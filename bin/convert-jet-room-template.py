#!/usr/bin/env python3
"""
Converts an Elementor single room template built for JetEngine rooms into
one that reads everything from Flexo Booking, keeping the design.

    python3 bin/convert-jet-room-template.py <exported-template.json> <out-dir> [--name "Azure"]

Writes two files to <out-dir>:

  <slug>-single-room-flexo.json  the single template, every room element
                                 connected to Flexo Booking
  <slug>-room-card-flexo.json    a Loop item made from the template's first
                                 room card (for the "other rooms" carousel)

What changes (everything else - texts, layout, fonts, colours, hotel-wide
sections - stays as it is):

  - post-title / post-featured-image tags      -> Room name / Room main photo
  - JetEngine fields price_per_night, room_size, max_guests, beds_info,
    long_description, short_description, room_view
                                               -> Room price, Room size,
                                                  Guests, Beds, Full
                                                  description, Short
                                                  description, View
  - an Icon List made only of amenity-N fields -> Room amenities widget
                                                  (each room's own list,
                                                  same styling)
  - a Media Carousel of fixed photos           -> Room gallery widget (same
                                                  size, columns and corners)
  - booking buttons ("check availability", "book now", "make a
    reservation", ...)                         -> Room booking link
  - a Nested Carousel of fixed room cards      -> Loop Carousel of rooms
                                                  (other rooms) + Room card
                                                  loop item
  - preview: single/rooms                      -> single/flexo_room

Import both files under Templates -> Import (Room card first), then in the
single template's Loop Carousel choose the "Room card" template if it is
not picked already, and set the display condition to Rooms.
"""
import copy
import hashlib
import json
import os
import re
import sys
from urllib.parse import quote, unquote

TAG_RE = re.compile(r'\[elementor-tag id="([^"]*)" name="([^"]*)" settings="([^"]*)"\]')
BOOK_WORDS = ('availability', 'book', 'reserv', 'резерв', 'провер', 'наличност')
_n = [0]


def eid(seed):
    _n[0] += 1
    return hashlib.md5(f'{seed}-{_n[0]}'.encode()).hexdigest()[:7]


def tag(name, settings=None):
    s = quote(json.dumps(settings or {}, separators=(',', ':'), ensure_ascii=False), safe='')
    return f'[elementor-tag id="{eid(name)}" name="{name}" settings="{s}"]'


def parse_tag(value):
    m = TAG_RE.fullmatch(value.strip()) if isinstance(value, str) else None
    if not m:
        return None, None
    try:
        settings = json.loads(unquote(m.group(3))) if m.group(3) else {}
    except ValueError:
        settings = {}
    return m.group(2), settings if isinstance(settings, dict) else {}


def keep_advanced(old, new):
    """Before / After / Fallback texts of the old tag."""
    for key in ('before', 'after', 'fallback'):
        if old.get(key):
            new[key] = old[key]
    return new


def convert_tag(value, field_key=None):
    """One dynamic tag -> the Flexo Booking tag, or the same value."""
    name, s = parse_tag(value)
    if not name:
        return value, False
    if name == 'post-title':
        return tag('flexo-room-name', keep_advanced(s, {})), True
    if name == 'post-featured-image':
        return tag('flexo-room-image'), True
    if name == 'post-excerpt':
        return tag('flexo-room-excerpt', keep_advanced(s, {})), True
    if name == 'post-content':
        return tag('flexo-room-description', keep_advanced(s, {})), True
    if name == 'post-url':
        return tag('flexo-room-url'), True
    if name.startswith('jet-') and s.get('meta_field'):
        field = s['meta_field']
        if field in ('price_per_night', 'price'):
            new = keep_advanced(s, {'price': 'base'})
            new.pop('before', None)  # The price comes with the currency.
            return tag('flexo-room-price', new), True
        if field in ('room_size', 'size'):
            return tag('flexo-room-size', keep_advanced(s, {})), True
        if field in ('max_guests', 'guests', 'capacity'):
            new = keep_advanced(s, {'format': 'guests'})
            new.pop('after', None)  # "3 guests" already says it.
            return tag('flexo-room-guests', new), True
        if field in ('beds_info', 'beds'):
            return tag('flexo-room-beds', keep_advanced(s, {})), True
        if field in ('long_description', 'description'):
            return tag('flexo-room-description', keep_advanced(s, {})), True
        if field in ('short_description', 'excerpt'):
            return tag('flexo-room-excerpt', keep_advanced(s, {})), True
        if field in ('room_view', 'view'):
            return tag('flexo-room-view', keep_advanced(s, {})), True
        m = re.fullmatch(r'amenity[-_]?(\d+)', field)
        if m:
            return tag('flexo-room-amenities', keep_advanced(s, {'format': 'nth', 'position': int(m.group(1))})), True
    return value, False


def walk(elements, fn):
    for el in elements:
        fn(el)
        walk(el.get('elements', []), fn)


def is_amenity_list(el):
    items = el.get('settings', {}).get('icon_list', [])
    if el.get('widgetType') != 'icon-list' or not items:
        return False
    for item in items:
        name, s = parse_tag(item.get('__dynamic__', {}).get('text', ''))
        if not name or not re.fullmatch(r'amenity[-_]?\d+', str(s.get('meta_field', ''))):
            return False
    return True


RESPONSIVE = ('', '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile')


def copy_responsive(src, dst, old, new):
    for suffix in RESPONSIVE:
        if old + suffix in src:
            dst[new + suffix] = src[old + suffix]


def amenity_widget(el):
    s = el['settings']
    new = {'layout': 'inline' if s.get('view') == 'inline' else 'list', 'show_icons': 'yes'}
    for key in ('icon_color', 'text_color', '_margin', '_padding', '_element_width', '_element_custom_width', '_element_vertical_align', '__globals__'):
        copy_responsive(s, new, key, key)
    copy_responsive(s, new, 'icon_size', 'icon_size')
    copy_responsive(s, new, 'space_between', 'space_between')
    copy_responsive(s, new, 'text_indent', 'icon_gap')
    for key in list(s.keys()):
        if key.startswith('icon_typography_'):
            new['text_typography_' + key[len('icon_typography_'):]] = s[key]
    if isinstance(new.get('__globals__'), dict):
        new['__globals__'] = {k.replace('icon_typography', 'text_typography'): v for k, v in new['__globals__'].items()}
    return {'id': el['id'], 'elType': 'widget', 'widgetType': 'flexo-room-amenities', 'settings': new, 'elements': []}


def gallery_widget(el):
    s = el['settings']
    new = {'layout': 'carousel', 'lightbox': 'no' if s.get('open_lightbox') == 'no' else 'yes',
           'arrows': 'yes' if s.get('show_arrows', 'yes') == 'yes' else '',
           'dots': 'yes' if s.get('pagination', 'bullets') else '', 'with_main': 'yes'}
    copy_responsive(s, new, 'slides_per_view', 'per_view')
    copy_responsive(s, new, 'height', 'height')
    copy_responsive(s, new, 'space_between', 'gap')
    copy_responsive(s, new, 'slide_border_radius', 'radius')
    for key in ('_margin', '_padding', '_element_width', '_element_custom_width'):
        copy_responsive(s, new, key, key)
    if s.get('autoplay') == 'yes' and s.get('autoplay_speed'):
        new['autoplay'] = max(1, int(int(s['autoplay_speed']) / 1000))
    return {'id': el['id'], 'elType': 'widget', 'widgetType': 'flexo-room-gallery', 'settings': new, 'elements': []}


PRICE_RE = re.compile(r'^\s*[€$£]?\s*\d[\d\s.,]*\s*(€|лв\.?|BGN|EUR)?\s*$', re.I)


def card_from_slide(slide):
    """A Loop item from one fixed room card of a Nested Carousel."""
    card = copy.deepcopy(slide)
    card.pop('isInner', None)
    card['isInner'] = False
    headings = []

    def fix(el):
        s = el.setdefault('settings', {})
        if el.get('elType') == 'container' and isinstance(s.get('background_image'), dict) and s['background_image'].get('url'):
            s.setdefault('__dynamic__', {})['background_image'] = tag('flexo-room-image')
        if el.get('widgetType') == 'heading':
            headings.append(el)
        if el.get('widgetType') == 'icon-list':
            for item in s.get('icon_list', []):
                text = str(item.get('text', '')).lower()
                if 'm²' in text or 'm2' in text or 'кв' in text:
                    item['__dynamic__'] = {'text': tag('flexo-room-size')}
                elif 'person' in text or 'guest' in text or 'people' in text or 'гост' in text or 'човек' in text:
                    item['__dynamic__'] = {'text': tag('flexo-room-guests', {'format': 'guests'})}
                elif 'bed' in text or 'легл' in text:
                    item['__dynamic__'] = {'text': tag('flexo-room-beds')}

    walk([card], fix)
    for h in headings:
        title = str(h['settings'].get('title', ''))
        if PRICE_RE.match(title):
            h['settings'].setdefault('__dynamic__', {})['title'] = tag('flexo-room-price', {'price': 'base'})
        elif title.strip().startswith('/') or len(title) < 3:
            continue
        else:
            h['settings'].setdefault('__dynamic__', {})['title'] = tag('flexo-room-name')
            h['settings']['link'] = {'url': ''}
            h['settings']['__dynamic__']['link'] = tag('flexo-room-url')
    # Fresh element IDs.
    walk([card], lambda el: el.__setitem__('id', eid('card')))
    return card


def loop_carousel(el, template_placeholder):
    s = el['settings']
    new = {
        'template_id': template_placeholder,
        'post_query_post_type': 'flexo_room',
        'post_query_exclude': ['current_post'],
        'posts_per_page': max(3, len(s.get('carousel_items', [])) or 6),
    }
    for key in ('slides_to_show', 'slides_to_scroll', 'image_spacing_custom', 'arrows', 'pagination', 'dots_gap', 'dots_size',
                'dots_pagination_spacing', 'dots_normal_color', 'dots_active_color', 'autoplay', 'autoplay_speed', 'infinite',
                'speed', '_margin', '_padding', '__globals__'):
        copy_responsive(s, new, key, key)
    if 'arrows' in s and s['arrows'] == '':
        new['arrows'] = ''
    return {'id': el['id'], 'elType': 'widget', 'widgetType': 'loop-carousel', 'settings': new, 'elements': []}


def is_room_cards(el):
    if el.get('widgetType') != 'nested-carousel' or not el.get('elements'):
        return False
    found = []
    walk(el['elements'][:1], lambda x: found.append(x) if x.get('widgetType') == 'heading' and PRICE_RE.match(str(x.get('settings', {}).get('title', ''))) else None)
    return bool(found)


def convert(data, card_placeholder):
    report = []
    card = None

    def fix(el):
        nonlocal card
        s = el.get('settings', {})
        dyn = s.get('__dynamic__')
        if isinstance(dyn, dict):
            for key, value in list(dyn.items()):
                new, changed = convert_tag(value, key)
                if changed:
                    dyn[key] = new
                    report.append(f'{el["id"]} {el.get("widgetType") or el.get("elType")}: {key} -> Flexo tag')
        for item in s.get('icon_list', []) if isinstance(s.get('icon_list'), list) else []:
            if isinstance(item.get('__dynamic__'), dict) and 'text' in item['__dynamic__']:
                new, changed = convert_tag(item['__dynamic__']['text'])
                if changed:
                    item['__dynamic__']['text'] = new
        if el.get('widgetType') == 'button':
            text = str(s.get('text', '')).lower()
            if any(w in text for w in BOOK_WORDS):
                s['link'] = {'url': '', 'is_external': '', 'nofollow': ''}
                s.setdefault('__dynamic__', {})['link'] = tag('flexo-room-booking-link')
                report.append(f'{el["id"]} button "{s.get("text")}": Room booking link')

    def replace(elements):
        nonlocal card
        for i, el in enumerate(elements):
            if is_amenity_list(el):
                elements[i] = amenity_widget(el)
                report.append(f'{el["id"]} icon-list of amenity fields -> Room amenities widget')
                continue
            if el.get('widgetType') == 'media-carousel':
                elements[i] = gallery_widget(el)
                report.append(f'{el["id"]} media-carousel -> Room gallery widget')
                continue
            if is_room_cards(el):
                card = card_from_slide(el['elements'][0])
                elements[i] = loop_carousel(el, card_placeholder)
                report.append(f'{el["id"]} nested-carousel of room cards -> Loop Carousel (Rooms) + Room card loop item')
                continue
            fix(el)
            replace(el.get('elements', []))

    out = copy.deepcopy(data)
    replace(out['content'])
    out['page_settings'] = dict(out.get('page_settings') or {}, preview_type='single/flexo_room', preview_id='')
    out['title'] = (out.get('title') or 'Single room') + ' (Flexo Booking)'
    return out, card, report


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)
    src, out_dir = sys.argv[1], sys.argv[2]
    name = sys.argv[sys.argv.index('--name') + 1] if '--name' in sys.argv else 'hotel'
    slug = re.sub(r'[^a-z0-9]+', '-', name.lower()).strip('-') or 'hotel'
    with open(src, encoding='utf-8') as f:
        data = json.load(f)
    single, card, report = convert(data, '')
    os.makedirs(out_dir, exist_ok=True)
    with open(os.path.join(out_dir, f'{slug}-single-room-flexo.json'), 'w', encoding='utf-8') as f:
        json.dump(single, f, ensure_ascii=False, indent='\t')
        f.write('\n')
    if card:
        loop = {'version': data.get('version', '0.4'), 'title': f'Room card ({name})', 'type': 'loop-item',
                'page_settings': {'preview_type': 'single/flexo_room', 'preview_id': ''}, 'content': [card]}
        with open(os.path.join(out_dir, f'{slug}-room-card-flexo.json'), 'w', encoding='utf-8') as f:
            json.dump(loop, f, ensure_ascii=False, indent='\t')
            f.write('\n')
    print('\n'.join(report))


if __name__ == '__main__':
    main()
