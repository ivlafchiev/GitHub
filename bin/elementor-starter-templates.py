#!/usr/bin/env python3
"""
Builds the starter Elementor templates shipped in
flexo-booking/assets/elementor/:

  flexo-single-room.json  Theme Builder "Single" template for rooms
  flexo-room-card.json    Loop item for Loop Grid / Loop Carousel

Only Elementor (Pro) core widgets, the plugin's room widgets and the
plugin's dynamic tags are used; no fixed colours or fonts, so the
templates take the site's Global Colors and Fonts.

The single template's Loop Carousel points at "{{flexo_room_card}}";
the plugin's installer replaces it with the Room card's ID.

Usage: python3 bin/elementor-starter-templates.py
"""
import hashlib
import json
import os
from urllib.parse import quote

OUT = os.path.join(os.path.dirname(__file__), '..', 'flexo-booking', 'assets', 'elementor')
_n = [0]


def eid(seed):
    _n[0] += 1
    return hashlib.md5(f'{seed}-{_n[0]}'.encode()).hexdigest()[:7]


def tag(name, settings=None):
    s = quote(json.dumps(settings or {}, separators=(',', ':')), safe='')
    return f'[elementor-tag id="{eid(name)}" name="{name}" settings="{s}"]'


def px(size, unit='px'):
    return {'unit': unit, 'size': size, 'sizes': []}


def box(top, right=None, bottom=None, left=None, unit='px'):
    right = top if right is None else right
    bottom = top if bottom is None else bottom
    left = right if left is None else left
    return {'unit': unit, 'top': str(top), 'right': str(right), 'bottom': str(bottom), 'left': str(left), 'isLinked': top == right == bottom == left}


def widget(kind, settings, dynamic=None):
    if dynamic:
        settings = dict(settings, __dynamic__=dynamic)
    return {'id': eid(kind), 'elType': 'widget', 'widgetType': kind, 'settings': settings, 'elements': []}


def container(elements, inner=False, **settings):
    base = {'content_width': 'full' if inner else 'boxed', 'flex_direction': 'column'}
    base.update(settings)
    return {'id': eid('container'), 'elType': 'container', 'isInner': inner, 'settings': base, 'elements': elements}


def heading(title, size='h2', dynamic=None, link=None, **extra):
    s = {'title': title, 'header_size': size}
    s.update(extra)
    dyn = {'title': dynamic} if dynamic else {}
    if link:
        s['link'] = {'url': ''}
        dyn['link'] = link
    return widget('heading', s, dyn or None)


def single_room(card_placeholder='{{flexo_room_card}}'):
    head = container([
        heading('Room type', 'div', tag('flexo-room-type'), typography_typography='custom', typography_text_transform='uppercase', typography_letter_spacing=px(1.5), typography_font_size=px(13)),
        heading('Room name', 'h1', tag('flexo-room-name')),
        heading('from €95 / night', 'div', tag('flexo-room-price', {'price': 'from', 'after': ' / night'}), typography_typography='custom', typography_font_size=px(20), typography_font_weight='600'),
        widget('flexo-room-details', {'facts': ['size', 'guests', 'beds', 'view'], 'more': '', 'show': 'value', 'layout': 'inline', 'guests_format': 'summary'}),
    ], padding=box(48, 16, 16), flex_gap={'column': '10', 'row': '10', 'isLinked': True, 'unit': 'px', 'size': 10})

    gallery = container([
        widget('flexo-room-gallery', {
            'layout': 'carousel', 'per_view': '2', 'per_view_tablet': '2', 'per_view_mobile': '1',
            'height': px(440), 'height_tablet': px(320), 'height_mobile': px(260),
            'radius': px(12), 'lightbox': 'yes', 'arrows': 'yes', 'dots': 'yes',
        }),
    ], padding=box(8, 16))

    main = container([
        widget('text-editor', {'editor': 'Full description'}, {'editor': tag('flexo-room-description')}),
        heading('Amenities', 'h2'),
        widget('flexo-room-amenities', {'layout': 'grid', 'columns': '3', 'columns_tablet': '2', 'columns_mobile': '1', 'space_between': px(14)}),
        heading('Good to know', 'h2'),
        widget('flexo-room-details', {'facts': [], 'more': 'yes', 'show': 'stacked', 'layout': 'grid', 'columns': '2', 'columns_mobile': '1', 'divider': 'yes'}),
    ], inner=True, width=px(64, '%'), width_tablet=px(100, '%'), width_mobile=px(100, '%'),
        flex_gap={'column': '20', 'row': '20', 'isLinked': True, 'unit': 'px', 'size': 20})
    aside = container([
        widget('flexo-room-booking-box', {'title': 'Check availability', 'show_price': 'yes'}),
    ], inner=True, width=px(36, '%'), width_tablet=px(100, '%'), width_mobile=px(100, '%'))
    body = container([main, aside], flex_direction='row', flex_direction_tablet='column', flex_align_items='flex-start',
                     flex_gap={'column': '48', 'row': '32', 'isLinked': False, 'unit': 'px', 'size': 48}, padding=box(40, 16))

    others = container([
        heading('Other rooms', 'h2'),
        widget('loop-carousel', {
            'template_id': card_placeholder,
            'post_query_post_type': 'flexo_room',
            'post_query_exclude': ['current_post'],
            'posts_per_page': 6,
            'slides_to_show': '3', 'slides_to_show_tablet': '2', 'slides_to_show_mobile': '1',
            'slides_to_scroll': '1',
            'image_spacing_custom': px(24),
        }),
    ], padding=box(40, 16, 64), flex_gap={'column': '20', 'row': '20', 'isLinked': True, 'unit': 'px', 'size': 20})

    return {
        'version': '0.4',
        'title': 'Single room (Flexo Booking)',
        'type': 'single-post',
        'page_settings': {'preview_type': 'single/flexo_room', 'preview_id': ''},
        'content': [head, gallery, body, others],
    }


def room_card():
    image = widget('image', {'image': {'url': '', 'id': ''}, 'image_size': 'medium_large', 'link_to': 'custom', 'link': {'url': ''},
                             'height': px(240), 'object-fit': 'cover', 'width': px(100, '%')},
                   {'image': tag('flexo-room-image'), 'link': tag('flexo-room-url')})
    text = container([
        heading('Room type', 'div', tag('flexo-room-type'), typography_typography='custom', typography_text_transform='uppercase', typography_letter_spacing=px(1.2), typography_font_size=px(12)),
        heading('Room name', 'h3', tag('flexo-room-name'), link=tag('flexo-room-url')),
        heading('from €95 / night', 'div', tag('flexo-room-price', {'price': 'from', 'after': ' / night'}), typography_typography='custom', typography_font_weight='600'),
        widget('flexo-room-details', {'facts': ['size', 'guests', 'beds'], 'more': '', 'show': 'value', 'layout': 'inline', 'guests_format': 'guests'}),
        widget('text-editor', {'editor': ''}, {'editor': tag('flexo-room-availability')}),
        container([
            widget('button', {'text': 'View room', 'link': {'url': ''}, 'button_type': 'info'}, {'link': tag('flexo-room-url')}),
            widget('button', {'text': 'Book now', 'link': {'url': ''}}, {'link': tag('flexo-room-booking-link')}),
        ], inner=True, flex_direction='row', flex_wrap='wrap', flex_gap={'column': '10', 'row': '10', 'isLinked': True, 'unit': 'px', 'size': 10}),
    ], inner=True, padding=box(20), flex_gap={'column': '8', 'row': '8', 'isLinked': True, 'unit': 'px', 'size': 8})
    card = container([image, text], content_width='full', padding=box(0), border_border='solid', border_width=box(1),
                     border_color='#00000014', border_radius=box(12), flex_gap={'column': '0', 'row': '0', 'isLinked': True, 'unit': 'px', 'size': 0},
                     overflow='hidden')
    return {
        'version': '0.4',
        'title': 'Room card (Flexo Booking)',
        'type': 'loop-item',
        'page_settings': {'preview_type': 'single/flexo_room', 'preview_id': ''},
        'content': [card],
    }


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for name, data in (('flexo-single-room.json', single_room()), ('flexo-room-card.json', room_card())):
        with open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent='\t')
            f.write('\n')
        print('wrote', name)
